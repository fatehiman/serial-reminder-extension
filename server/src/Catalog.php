<?php
declare(strict_types=1);

namespace SR;

/**
 * Runs the "catalog" part of a provider script: fetch the show's page or API and
 * return the list of episodes that exist on the site right now.
 *
 * A catalog is a list of steps. Every step fetches one or more URLs and turns the
 * response into rows. A step can loop over the rows produced by an earlier step.
 *
 *   {
 *     "catalog": {
 *       "steps": [
 *         { "id": "seasons",
 *           "url": "https://site/api/seasons/{refKey}",
 *           "type": "json",
 *           "list": "data.attributes.data",
 *           "fields": { "season": {"path":"serial_season_part","as":"int"},
 *                       "episodesUrl": "episodes_link" } },
 *
 *         { "id": "episodes",
 *           "forEach": "seasons",
 *           "url": "{item.episodesUrl}",
 *           "type": "json",
 *           "list": "included",
 *           "emit": true,
 *           "fields": { "number": {"path":"attributes.serial_part","as":"int"},
 *                       "title":  "attributes.movie_title",
 *                       "url":    "https://site/w/{attributes.uid}" },
 *           "skipWhen": [ { "path": "attributes.timeLeftToPublish", "gt": 0 } ] }
 *       ]
 *     }
 *   }
 *
 * "type": "html" instead of "json" switches the row source to a regex
 * ("list" becomes the pattern, "fields" map to named capture groups).
 *
 * Only steps with "emit": true contribute episodes to the result.
 */
final class Catalog
{
    /**
     * @param array $provider  the decoded provider script
     * @param array $vars      values the steps can use: refKey, seriesKey, seriesUrl, title...
     * @return array{episodes:array<int,array>, error:?string, requests:int}
     */
    public static function fetch(array $provider, array $vars): array
    {
        $catalog = $provider['catalog'] ?? null;
        if (!is_array($catalog) || empty($catalog['steps'])) {
            return ['episodes' => [], 'error' => 'provider has no catalog steps', 'requests' => 0];
        }

        $headers  = (array) ($catalog['headers'] ?? []);
        $maxReq   = (int) ($catalog['maxRequests'] ?? 12);
        $results  = [];   // step id => rows
        $episodes = [];
        $requests = 0;

        foreach ($catalog['steps'] as $index => $step) {
            if (!is_array($step)) {
                continue;
            }
            $stepId  = (string) ($step['id'] ?? "step$index");
            $rows    = [];

            // Which "item" contexts does this step run for?
            $contexts = [[]];
            if (!empty($step['forEach'])) {
                $source = $results[(string) $step['forEach']] ?? [];
                if ($source === []) {
                    $results[$stepId] = [];
                    continue;
                }
                $contexts = array_map(static fn ($r) => ['item' => $r], $source);
            }

            foreach ($contexts as $ctx) {
                if ($requests >= $maxReq) {
                    return [
                        'episodes' => self::finish($episodes),
                        'error'    => "stopped after $maxReq requests (catalog too large)",
                        'requests' => $requests,
                    ];
                }

                $url = Val::template((string) ($step['url'] ?? ''), $vars + $ctx);
                if ($url === '' || Val::hasHoles($url)) {
                    return [
                        'episodes' => self::finish($episodes),
                        'error'    => "step '$stepId': could not build the URL (missing value)",
                        'requests' => $requests,
                    ];
                }

                $requests++;
                $stepHeaders = $headers + (array) ($step['headers'] ?? []);
                $type = (string) ($step['type'] ?? 'json');

                if ($type === 'json') {
                    $body = Http::getJson($url, $stepHeaders);
                    if ($body === null) {
                        return [
                            'episodes' => self::finish($episodes),
                            'error'    => "step '$stepId': request failed or returned no JSON ($url)",
                            'requests' => $requests,
                        ];
                    }
                    $raw = Val::path($body, (string) ($step['list'] ?? ''), []);
                    $raw = is_array($raw) ? $raw : [];
                } else { // html
                    $res = Http::get($url, $stepHeaders);
                    if ($res['error'] !== null || $res['status'] >= 400) {
                        return [
                            'episodes' => self::finish($episodes),
                            'error'    => "step '$stepId': HTTP {$res['status']} ($url)",
                            'requests' => $requests,
                        ];
                    }
                    $raw = [];
                    if (preg_match_all((string) ($step['list'] ?? '//'), $res['body'], $m, PREG_SET_ORDER)) {
                        $raw = $m;
                    }
                }

                foreach ($raw as $item) {
                    if (!is_array($item)) {
                        $item = ['value' => $item];
                    }
                    if (self::skip($step['skipWhen'] ?? [], $item)) {
                        continue;
                    }
                    $row = [];
                    foreach ((array) ($step['fields'] ?? []) as $name => $rule) {
                        $row[$name] = Val::field($rule, $item, $vars + $ctx);
                    }
                    // Inherit values the parent step already worked out.
                    foreach (($ctx['item'] ?? []) as $k => $v) {
                        if (!isset($row[$k]) || $row[$k] === null) {
                            $row[$k] = $v;
                        }
                    }
                    $rows[] = $row;
                }
            }

            $results[$stepId] = $rows;
            if (!empty($step['emit'])) {
                foreach ($rows as $row) {
                    $episodes[] = $row;
                }
            }
        }

        return ['episodes' => self::finish($episodes), 'error' => null, 'requests' => $requests];
    }

    /**
     * skipWhen: list of conditions. The row is dropped when ANY of them is true.
     *   {"path":"x","gt":0} {"path":"x","eq":"foo"} {"path":"x","empty":true}
     *   {"path":"x","truthy":true} {"path":"x","matches":"regex"}
     */
    private static function skip(mixed $conditions, array $item): bool
    {
        foreach ((array) $conditions as $cond) {
            if (!is_array($cond) || !isset($cond['path'])) {
                continue;
            }
            $v = Val::path($item, (string) $cond['path']);

            if (array_key_exists('empty', $cond)) {
                $isEmpty = $v === null || $v === '' || $v === [];
                if ($isEmpty === (bool) $cond['empty']) {
                    return true;
                }
            }
            if (array_key_exists('truthy', $cond) && (bool) $v === (bool) $cond['truthy']) {
                return true;
            }
            if (array_key_exists('eq', $cond) && (string) $v === (string) $cond['eq']) {
                return true;
            }
            if (array_key_exists('gt', $cond)) {
                $n = Val::int($v);
                if ($n !== null && $n > (int) $cond['gt']) {
                    return true;
                }
            }
            if (array_key_exists('matches', $cond) && is_string($v)
                && preg_match((string) $cond['matches'], $v) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Drop rows without a usable episode number, de-duplicate, sort. */
    private static function finish(array $episodes): array
    {
        $byKey = [];
        foreach ($episodes as $ep) {
            $number = Val::int($ep['number'] ?? null);
            if ($number === null) {
                continue;
            }
            $season = Val::int($ep['season'] ?? null) ?? 1;
            $key    = $season . ':' . $number;
            $ep['number'] = $number;
            $ep['season'] = $season;
            $byKey[$key]  = $ep;   // later step wins
        }
        $out = array_values($byKey);
        usort($out, static fn ($a, $b) => [$a['season'], $a['number']] <=> [$b['season'], $b['number']]);
        return $out;
    }
}
