<?php
declare(strict_types=1);

namespace SR;

/**
 * The cron job. For every followed show it asks the site which episodes exist
 * and stores the ones we did not know about yet.
 */
final class Checker
{
    /** @return array{checked:int, added:int, errors:int, details:array} */
    public static function runAll(?int $onlySerialId = null, int $minAgeMinutes = 55, bool $verbose = false): array
    {
        $providers = Providers::all();

        // confirmed = 0 means the show was opened but never really watched, so
        // there is nothing to keep up to date. See migration 003.
        $sql    = 'SELECT * FROM serials WHERE confirmed = 1 AND status != ?';
        $params = ['finished'];
        if ($onlySerialId !== null) {
            $sql    = 'SELECT * FROM serials WHERE id = ?';
            $params = [$onlySerialId];
        } elseif ($minAgeMinutes > 0) {
            $sql .= " AND (last_checked_at IS NULL OR last_checked_at < datetime('now', '-$minAgeMinutes minutes'))";
        }

        $stats = ['checked' => 0, 'added' => 0, 'errors' => 0, 'pruned' => 0, 'notified' => 0, 'details' => []];

        if ($onlySerialId === null) {
            $stats['pruned'] = self::pruneCandidates();
        }

        foreach (Db::all($sql, $params) as $serial) {
            $result = self::checkOne($serial, $providers);
            $stats['checked']++;
            $stats['added'] += $result['added'];
            $stats['notified'] += $result['notified'] ?? 0;
            if ($result['error'] !== null) {
                $stats['errors']++;
            }
            $stats['details'][] = $result;
            if ($verbose) {
                $note = $result['notifyError'] !== null
                    ? ' NOTIFY FAILED: ' . $result['notifyError']
                    : (($result['notified'] ?? 0) > 0 ? ' told you about ' . $result['notified'] : '');
                fwrite(STDOUT, sprintf(
                    "[%s] %-40s added=%d %s%s\n",
                    $serial['provider'],
                    mb_strimwidth((string) $serial['title'], 0, 40, '…'),
                    $result['added'],
                    $result['error'] !== null ? 'ERROR: ' . $result['error'] : 'ok',
                    $note
                ));
            }
        }

        return $stats;
    }

    /**
     * Throw away shows that were opened once, never watched, and never came back.
     * Without this every page you glance at leaves a row behind forever.
     *
     * These rows were never shown on the dashboard, so nobody could have removed
     * them by hand — this is the only thing that cleans them up. A show you can
     * actually see is never touched here; you remove that one yourself.
     */
    public static function pruneCandidates(?int $olderThanDays = null): int
    {
        $olderThanDays ??= (int) Config::get('candidate_days', 90);

        return Db::q(
            "DELETE FROM serials
             WHERE confirmed = 0
               AND updated_at < datetime('now', ?)
               AND id NOT IN (SELECT serial_id FROM episodes WHERE watched = 1)",
            ["-$olderThanDays days"]
        )->rowCount();
    }

    /** @return array{serialId:int, title:string, added:int, error:?string} */
    public static function checkOne(array $serial, ?array $providers = null): array
    {
        $providers ??= Providers::all();
        $serialId   = (int) $serial['id'];
        $provider   = $providers[$serial['provider']] ?? null;

        if ($provider === null) {
            return self::finishCheck($serialId, $serial, 0, "no provider script named '{$serial['provider']}'");
        }

        // Values the catalog steps may need. refKey is any known episode key on
        // the site — some APIs want an episode id rather than a series id.
        $refEpisode = Db::one(
            'SELECT url, title FROM episodes WHERE serial_id = ? AND url IS NOT NULL
             ORDER BY season DESC, number DESC LIMIT 1',
            [$serialId]
        );

        $vars = [
            'seriesKey'  => $serial['provider_key'],
            'seriesUrl'  => $serial['series_url'] ?? '',
            'title'      => $serial['title'],
            'refKey'     => self::refKey($provider, $serial, $refEpisode),
        ];

        $res = Catalog::fetch($provider, $vars);
        if ($res['episodes'] === [] && $res['error'] !== null) {
            return self::finishCheck($serialId, $serial, 0, $res['error']);
        }

        $added = Serials::mergeCatalog($serialId, $res['episodes']);

        // Announce what the site just published. This has to happen before
        // finishCheck stamps last_checked_at: "never checked before" is what
        // tells a new episode apart from a back catalogue being imported.
        $notified = Notify::afterCheck($serial, $serial['last_checked_at'] === null);

        return self::finishCheck($serialId, $serial, $added, $res['error'], $notified);
    }

    /**
     * Work out the "reference key" a catalog step may need. The provider script
     * says where to get it from with "refKeyFrom": "seriesKey" | "episodeUrl".
     */
    private static function refKey(array $provider, array $serial, ?array $refEpisode): string
    {
        $from = (string) ($provider['catalog']['refKeyFrom'] ?? 'seriesKey');

        if ($from === 'seriesKey') {
            return (string) $serial['provider_key'];
        }
        if ($from === 'episodeUrl') {
            $url     = (string) ($refEpisode['url'] ?? '');
            $pattern = (string) ($provider['catalog']['refKeyPattern'] ?? '');
            if ($url !== '' && $pattern !== '' && preg_match($pattern, $url, $m)) {
                return (string) ($m[1] ?? '');
            }
            return '';
        }
        return (string) $serial['provider_key'];
    }

    /** @param array{sent:int,silenced:int,error:?string}|null $notified */
    private static function finishCheck(
        int $serialId,
        array $serial,
        int $added,
        ?string $error,
        ?array $notified = null
    ): array {
        Db::q(
            "UPDATE serials SET last_checked_at = datetime('now'), check_error = ? WHERE id = ?",
            [$error, $serialId]
        );
        return [
            'serialId' => $serialId,
            'title'    => (string) $serial['title'],
            'added'    => $added,
            'error'    => $error,
            'notified' => $notified['sent'] ?? 0,
            'notifyError' => $notified['error'] ?? null,
        ];
    }
}
