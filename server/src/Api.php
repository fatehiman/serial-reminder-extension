<?php
declare(strict_types=1);

namespace SR;

/**
 * The JSON API the Chrome extension talks to.
 * Auth on every endpoint: Authorization: Bearer <api key>  (or X-Api-Key).
 */
final class Api
{
    public static function dispatch(string $method, string $path): void
    {
        // /api/... -> ...
        $route = '/' . ltrim(substr($path, strlen('/api')), '/');
        $route = rtrim($route, '/');
        if ($route === '') {
            $route = '/';
        }

        switch (true) {

            case $route === '/' || $route === '/ping':
                $user = Auth::requireApiUser();
                sr_json([
                    'ok'       => true,
                    'user'     => ['id' => (int) $user['id'], 'username' => $user['username']],
                    'server'   => Config::get('app_url'),
                    'providers'=> Providers::bundleHash(),
                    'time'     => sr_now(),
                ]);

            // Provider scripts. The extension refreshes these on a schedule, so
            // a new site is supported by uploading one JSON file to the server.
            case $route === '/providers' && $method === 'GET':
                Auth::requireApiUser();
                sr_json([
                    'ok'        => true,
                    'hash'      => Providers::bundleHash(),
                    'providers' => array_values(Providers::enabled()),
                ]);

            case (bool) preg_match('~^/providers/([A-Za-z0-9_.-]+)$~', $route, $m) && $method === 'GET':
                Auth::requireApiUser();
                $p = Providers::get($m[1]);
                $p === null ? sr_fail('No such provider', 404) : sr_json(['ok' => true, 'provider' => $p]);

            // The main ingest call from the extension while you are watching.
            case $route === '/watch' && $method === 'POST':
                $user = Auth::requireApiUser();
                $in   = self::body();
                try {
                    $res = Serials::recordWatch((int) $user['id'], $in);
                } catch (\InvalidArgumentException $e) {
                    sr_fail($e->getMessage(), 422);
                }
                sr_json([
                    'ok'            => true,
                    'serialId'      => (int) $res['serial']['id'],
                    'episodeId'     => (int) $res['episode']['id'],
                    'watched'       => (int) $res['episode']['watched'] === 1,
                    'justCompleted' => $res['justCompleted'],
                    'newSerial'     => $res['newSerial'],
                ]);

            // Which account is logged in on each site. The extension sends this
            // only while something is playing, which proves the subscription works.
            case $route === '/account' && $method === 'POST':
                $user = Auth::requireApiUser();
                $in   = self::body();
                try {
                    $res = Accounts::record((int) $user['id'], (string) ($in['provider'] ?? ''), $in);
                } catch (\InvalidArgumentException $e) {
                    sr_fail($e->getMessage(), 422);
                }
                sr_json(['ok' => true] + $res);

            case $route === '/accounts' && $method === 'GET':
                $user = Auth::requireApiUser();
                sr_json(['ok' => true, 'accounts' => Accounts::forUser((int) $user['id'])]);

            case (bool) preg_match('~^/accounts/([A-Za-z0-9_.-]+)$~', $route, $m) && $method === 'DELETE':
                $user = Auth::requireApiUser();
                sr_json(['ok' => Accounts::forget((int) $user['id'], $m[1])]);

            case $route === '/serials' && $method === 'GET':
                $user = Auth::requireApiUser();
                sr_json([
                    'ok'       => true,
                    'serials'  => Serials::listForUser((int) $user['id'], (string) ($_GET['status'] ?? 'all')),
                    'accounts' => Accounts::forUser((int) $user['id']),
                ]);

            case (bool) preg_match('~^/serials/(\d+)$~', $route, $m) && $method === 'GET':
                $user   = Auth::requireApiUser();
                $serial = Serials::find((int) $user['id'], (int) $m[1]) ?? sr_fail('Not found', 404);
                sr_json([
                    'ok'       => true,
                    'serial'   => Serials::decorate($serial),
                    'episodes' => Serials::episodes((int) $serial['id']),
                ]);

            case (bool) preg_match('~^/serials/(\d+)$~', $route, $m)
                 && in_array($method, ['PATCH', 'POST'], true):
                $user   = Auth::requireApiUser();
                $serial = Serials::find((int) $user['id'], (int) $m[1]) ?? sr_fail('Not found', 404);
                self::updateSerial((int) $serial['id'], self::body());
                sr_json([
                    'ok'     => true,
                    'serial' => Serials::decorate(Serials::find((int) $user['id'], (int) $serial['id']) ?? []),
                ]);

            case (bool) preg_match('~^/serials/(\d+)$~', $route, $m) && $method === 'DELETE':
                $user = Auth::requireApiUser();
                sr_json(['ok' => Serials::delete((int) $user['id'], (int) $m[1])]);

            // Mark one episode (and everything before it) as seen / not seen.
            case (bool) preg_match('~^/serials/(\d+)/episodes/(\d+)$~', $route, $m) && $method === 'POST':
                $user   = Auth::requireApiUser();
                $serial = Serials::find((int) $user['id'], (int) $m[1]) ?? sr_fail('Not found', 404);
                $in     = self::body();
                Serials::setEpisodeWatched((int) $serial['id'], (int) $m[2], (bool) ($in['watched'] ?? true));
                sr_json(['ok' => true, 'episodes' => Serials::episodes((int) $serial['id'])]);

            case (bool) preg_match('~^/serials/(\d+)/mark-up-to$~', $route, $m) && $method === 'POST':
                $user   = Auth::requireApiUser();
                $serial = Serials::find((int) $user['id'], (int) $m[1]) ?? sr_fail('Not found', 404);
                $in     = self::body();
                $n      = Serials::markWatchedUpTo(
                    (int) $serial['id'],
                    Val::int($in['season'] ?? 1) ?? 1,
                    Val::int($in['episode'] ?? null) ?? sr_fail('episode is required', 422)
                );
                sr_json(['ok' => true, 'marked' => $n]);

            // Ask the server to look for new episodes right now.
            case $route === '/check' && $method === 'POST':
                $user = Auth::requireApiUser();
                $in   = self::body();
                $id   = Val::int($in['serialId'] ?? null);
                if ($id !== null) {
                    $serial = Serials::find((int) $user['id'], $id) ?? sr_fail('Not found', 404);
                    sr_json(['ok' => true, 'result' => Checker::checkOne($serial)]);
                }
                sr_json(['ok' => true, 'result' => Checker::runAll(null, 0)]);

            // Trade the API key for a 60-second, single-use dashboard login link.
            case $route === '/session-ticket' && $method === 'POST':
                $user   = Auth::requireApiUser();
                $ticket = Auth::makeTicket((int) $user['id']);
                sr_json([
                    'ok'  => true,
                    'url' => rtrim((string) Config::get('app_url'), '/') . '/auth/t/' . $ticket,
                    'expiresIn' => 60,
                ]);
        }
        // Falls through to the 404 in index.php.
    }

    private static function updateSerial(int $serialId, array $in): void
    {
        $sets = [];
        $args = [];
        if (isset($in['status']) && in_array($in['status'], ['watching', 'paused', 'finished'], true)) {
            $sets[] = 'status = ?';
            $args[] = $in['status'];
        }
        foreach (['title' => 'title', 'notes' => 'notes', 'seriesUrl' => 'series_url', 'poster' => 'poster_url'] as $k => $col) {
            if (array_key_exists($k, $in)) {
                $sets[] = "$col = ?";
                $args[] = $in[$k] === null ? null : (string) $in[$k];
            }
        }
        if ($sets === []) {
            return;
        }
        $args[] = $serialId;
        Db::q('UPDATE serials SET ' . implode(', ', $sets) . ", updated_at = datetime('now') WHERE id = ?", $args);
    }

    /** JSON body, or form fields when something posts the old-fashioned way. */
    private static function body(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return $_POST;
    }
}
