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

        $sql    = 'SELECT * FROM serials WHERE status != ?';
        $params = ['finished'];
        if ($onlySerialId !== null) {
            $sql    = 'SELECT * FROM serials WHERE id = ?';
            $params = [$onlySerialId];
        } elseif ($minAgeMinutes > 0) {
            $sql .= " AND (last_checked_at IS NULL OR last_checked_at < datetime('now', '-$minAgeMinutes minutes'))";
        }

        $stats = ['checked' => 0, 'added' => 0, 'errors' => 0, 'details' => []];

        foreach (Db::all($sql, $params) as $serial) {
            $result = self::checkOne($serial, $providers);
            $stats['checked']++;
            $stats['added'] += $result['added'];
            if ($result['error'] !== null) {
                $stats['errors']++;
            }
            $stats['details'][] = $result;
            if ($verbose) {
                fwrite(STDOUT, sprintf(
                    "[%s] %-40s added=%d %s\n",
                    $serial['provider'],
                    mb_strimwidth((string) $serial['title'], 0, 40, '…'),
                    $result['added'],
                    $result['error'] !== null ? 'ERROR: ' . $result['error'] : 'ok'
                ));
            }
        }

        return $stats;
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
        return self::finishCheck($serialId, $serial, $added, $res['error']);
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

    private static function finishCheck(int $serialId, array $serial, int $added, ?string $error): array
    {
        Db::q(
            "UPDATE serials SET last_checked_at = datetime('now'), check_error = ? WHERE id = ?",
            [$error, $serialId]
        );
        return [
            'serialId' => $serialId,
            'title'    => (string) $serial['title'],
            'added'    => $added,
            'error'    => $error,
        ];
    }
}
