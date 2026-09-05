<?php
declare(strict_types=1);

namespace SR;

/**
 * Everything that touches the serials / episodes tables.
 */
final class Serials
{
    /* ------------------------------------------------------------- ingest */

    /**
     * Called by POST /api/watch. Creates the show if it is new, creates or
     * updates the episode, and decides whether that episode counts as watched.
     *
     * Expected keys: provider, seriesKey, seriesTitle, seriesUrl, poster,
     *                season, episode, episodeTitle, episodeUrl, episodeKey,
     *                position, duration, watchedDelta, ended
     *
     * @return array{serial:array, episode:array, created:bool, justCompleted:bool}
     */
    public static function recordWatch(int $userId, array $in): array
    {
        $provider = trim((string) ($in['provider'] ?? ''));
        $seriesKey = trim((string) ($in['seriesKey'] ?? ''));
        $title     = trim((string) ($in['seriesTitle'] ?? ''));
        $episode   = Val::int($in['episode'] ?? null);

        if ($provider === '' || $seriesKey === '' || $title === '') {
            throw new \InvalidArgumentException('provider, seriesKey and seriesTitle are required');
        }
        if ($episode === null) {
            throw new \InvalidArgumentException('episode number is required');
        }
        $season = Val::int($in['season'] ?? null) ?? 1;

        $wasKnown = Db::one(
            'SELECT id FROM serials WHERE user_id = ? AND provider = ? AND provider_key = ?',
            [$userId, $provider, $seriesKey]
        ) !== null;

        $serial  = self::upsertSerial($userId, $provider, $seriesKey, [
            'title'      => $title,
            'series_url' => $in['seriesUrl'] ?? null,
            'poster_url' => $in['poster'] ?? null,
        ]);
        $serialId = (int) $serial['id'];

        // First time we see this show: nobody starts a serial at episode 22, so
        // treat everything before this point as already watched.
        if (!$wasKnown) {
            Db::q('UPDATE serials SET backfill_season = ?, backfill_number = ? WHERE id = ?',
                [$season, $episode, $serialId]);
        }

        $existing = Db::one(
            'SELECT * FROM episodes WHERE serial_id = ? AND season = ? AND number = ?',
            [$serialId, $season, $episode]
        );
        $created = $existing === null;

        $duration = Val::int($in['duration'] ?? null);
        $position = Val::int($in['position'] ?? null);
        $delta    = max(0, Val::int($in['watchedDelta'] ?? 0) ?? 0);
        // A single report should never add more than an hour of watch time.
        $delta = min($delta, 3600);

        if ($created) {
            Db::q(
                'INSERT INTO episodes (serial_id, season, number, title, url, duration_seconds,
                                       position_seconds, watched_seconds, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $serialId, $season, $episode,
                    self::str($in['episodeTitle'] ?? null),
                    self::str($in['episodeUrl'] ?? null),
                    $duration, $position ?? 0, $delta, 'watch',
                ]
            );
            $episodeId = Db::insertId();
        } else {
            $episodeId = (int) $existing['id'];
            Db::q(
                "UPDATE episodes SET
                    title            = COALESCE(NULLIF(?, ''), title),
                    url              = COALESCE(NULLIF(?, ''), url),
                    duration_seconds = COALESCE(?, duration_seconds),
                    position_seconds = MAX(position_seconds, COALESCE(?, 0)),
                    watched_seconds  = watched_seconds + ?,
                    source           = 'watch',
                    updated_at       = datetime('now')
                 WHERE id = ?",
                [
                    self::str($in['episodeTitle'] ?? null),
                    self::str($in['episodeUrl'] ?? null),
                    $duration,
                    $position,
                    $delta,
                    $episodeId,
                ]
            );
        }

        $wasWatched    = (int) ($existing['watched'] ?? 0) === 1;
        $row           = Db::one('SELECT * FROM episodes WHERE id = ?', [$episodeId]) ?? [];
        $ended         = !empty($in['ended']);
        $justCompleted = self::applyWatchedRule($row, $ended) && !$wasWatched;

        Db::q(
            "UPDATE serials SET last_watched_at = datetime('now'), updated_at = datetime('now') WHERE id = ?",
            [$serialId]
        );
        self::refreshPointers($serialId);

        return [
            'serial'        => Db::one('SELECT * FROM serials WHERE id = ?', [$serialId]) ?? [],
            'episode'       => Db::one('SELECT * FROM episodes WHERE id = ?', [$episodeId]) ?? [],
            'created'       => $created,
            'justCompleted' => $justCompleted,
        ];
    }

    /**
     * The rule the user asked for: 20 minutes of real playback counts as watched,
     * and reaching (nearly) the end of the episode counts too.
     */
    private static function applyWatchedRule(array $ep, bool $ended): bool
    {
        if ((int) $ep['watched'] === 1) {
            return true;
        }
        $duration = (int) ($ep['duration_seconds'] ?? 0);
        $position = (int) ($ep['position_seconds'] ?? 0);
        $watched  = (int) ($ep['watched_seconds'] ?? 0);

        $rules     = (array) Config::get('watched_rules', []);
        $minRatio  = (float) ($rules['min_ratio'] ?? 0.90);
        $minSecs   = (int)   ($rules['min_seconds'] ?? 1200);
        $ratioOfD  = (float) ($rules['ratio_of_duration'] ?? 0.85);

        $ratio = $duration > 0 ? min(1.0, $position / $duration) : 0.0;

        $done = $ended
            || ($duration > 0 && $ratio >= $minRatio)
            || ($duration > 0 && $watched >= (int) round($duration * $ratioOfD))
            || ($duration <= 0 && $watched >= $minSecs);

        Db::q(
            "UPDATE episodes SET progress_ratio = ?, watched = ?, updated_at = datetime('now') WHERE id = ?",
            [round($ratio, 4), $done ? 1 : 0, (int) $ep['id']]
        );
        return $done;
    }

    /* ------------------------------------------------------------ catalog */

    /** Insert episodes discovered by the checker. Never overwrites watch data. */
    public static function mergeCatalog(int $serialId, array $episodes): int
    {
        $serial = Db::one('SELECT backfill_season, backfill_number FROM serials WHERE id = ?', [$serialId]) ?? [];
        $bfS = $serial['backfill_season'] !== null ? (int) $serial['backfill_season'] : null;
        $bfN = $serial['backfill_number'] !== null ? (int) $serial['backfill_number'] : null;

        $added = 0;
        foreach ($episodes as $ep) {
            $season = Val::int($ep['season'] ?? null) ?? 1;
            $number = Val::int($ep['number'] ?? null);
            if ($number === null) {
                continue;
            }
            $exists = Db::one(
                'SELECT id FROM episodes WHERE serial_id = ? AND season = ? AND number = ?',
                [$serialId, $season, $number]
            );
            if ($exists === null) {
                $alreadySeen = $bfN !== null
                    && ($season < $bfS || ($season === $bfS && $number <= $bfN));
                Db::q(
                    'INSERT INTO episodes (serial_id, season, number, title, url, duration_seconds,
                                           released_at, source, watched, progress_ratio)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $serialId, $season, $number,
                        self::str($ep['title'] ?? null),
                        self::str($ep['url'] ?? null),
                        Val::int($ep['duration'] ?? null),
                        self::str($ep['releasedAt'] ?? null),
                        'catalog',
                        $alreadySeen ? 1 : 0,
                        $alreadySeen ? 1.0 : 0.0,
                    ]
                );
                $added++;
            } else {
                // Fill in gaps only.
                Db::q(
                    "UPDATE episodes SET
                        title       = COALESCE(title, NULLIF(?, '')),
                        url         = COALESCE(url,   NULLIF(?, '')),
                        released_at = COALESCE(released_at, NULLIF(?, '')),
                        duration_seconds = COALESCE(duration_seconds, ?)
                     WHERE id = ?",
                    [
                        self::str($ep['title'] ?? null),
                        self::str($ep['url'] ?? null),
                        self::str($ep['releasedAt'] ?? null),
                        Val::int($ep['duration'] ?? null),
                        (int) $exists['id'],
                    ]
                );
            }
        }
        self::refreshPointers($serialId);
        return $added;
    }

    /** Recompute "newest watched" and "newest that exists". */
    public static function refreshPointers(int $serialId): void
    {
        $lastWatched = Db::one(
            'SELECT id FROM episodes WHERE serial_id = ? AND watched = 1
             ORDER BY season DESC, number DESC LIMIT 1',
            [$serialId]
        );
        $latest = Db::one(
            'SELECT id FROM episodes WHERE serial_id = ?
             ORDER BY season DESC, number DESC LIMIT 1',
            [$serialId]
        );
        Db::q(
            "UPDATE serials SET last_watched_episode = ?, latest_episode = ?, updated_at = datetime('now')
             WHERE id = ?",
            [$lastWatched['id'] ?? null, $latest['id'] ?? null, $serialId]
        );
    }

    /* -------------------------------------------------------------- reads */

    public static function upsertSerial(int $userId, string $provider, string $key, array $data): array
    {
        $row = Db::one(
            'SELECT * FROM serials WHERE user_id = ? AND provider = ? AND provider_key = ?',
            [$userId, $provider, $key]
        );
        if ($row === null) {
            Db::q(
                'INSERT INTO serials (user_id, provider, provider_key, title, series_url, poster_url)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $userId, $provider, $key,
                    (string) $data['title'],
                    self::str($data['series_url'] ?? null),
                    self::str($data['poster_url'] ?? null),
                ]
            );
            return Db::one('SELECT * FROM serials WHERE id = ?', [Db::insertId()]) ?? [];
        }
        Db::q(
            "UPDATE serials SET
                title      = COALESCE(NULLIF(?, ''), title),
                series_url = COALESCE(NULLIF(?, ''), series_url),
                poster_url = COALESCE(NULLIF(?, ''), poster_url),
                updated_at = datetime('now')
             WHERE id = ?",
            [
                (string) $data['title'],
                self::str($data['series_url'] ?? null),
                self::str($data['poster_url'] ?? null),
                (int) $row['id'],
            ]
        );
        return Db::one('SELECT * FROM serials WHERE id = ?', [(int) $row['id']]) ?? [];
    }

    /**
     * The dashboard / API list. Each row carries the last watched episode, the
     * newest episode on the site, how many are unseen, and where to click next.
     */
    public static function listForUser(int $userId, ?string $status = null): array
    {
        $sql    = 'SELECT * FROM serials WHERE user_id = ?';
        $params = [$userId];
        if ($status !== null && $status !== 'all') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $rows = Db::all($sql, $params);

        $out = [];
        foreach ($rows as $s) {
            $out[] = self::decorate($s);
        }

        usort($out, static function (array $a, array $b): int {
            // New episodes first, then most recently watched.
            return [$b['unwatchedCount'] > 0, $b['lastWatchedAt'] ?? '']
               <=> [$a['unwatchedCount'] > 0, $a['lastWatchedAt'] ?? ''];
        });
        return $out;
    }

    public static function decorate(array $s): array
    {
        $id = (int) $s['id'];

        $lastWatched = $s['last_watched_episode']
            ? Db::one('SELECT * FROM episodes WHERE id = ?', [(int) $s['last_watched_episode']])
            : null;
        $latest = $s['latest_episode']
            ? Db::one('SELECT * FROM episodes WHERE id = ?', [(int) $s['latest_episode']])
            : null;

        // The next thing to watch: oldest unwatched episode after the last one seen.
        $next = Db::one(
            'SELECT * FROM episodes WHERE serial_id = ? AND watched = 0
             ORDER BY season ASC, number ASC LIMIT 1',
            [$id]
        );
        $unwatched = (int) (Db::one(
            'SELECT COUNT(*) AS c FROM episodes WHERE serial_id = ? AND watched = 0',
            [$id]
        )['c'] ?? 0);

        $target = $next['url'] ?? $latest['url'] ?? $s['series_url'] ?? null;

        return [
            'id'             => $id,
            'provider'       => $s['provider'],
            'providerKey'    => $s['provider_key'],
            'title'          => $s['title'],
            'seriesUrl'      => $s['series_url'],
            'poster'         => $s['poster_url'],
            'status'         => $s['status'],
            'notes'          => $s['notes'],
            'lastWatchedAt'  => $s['last_watched_at'],
            'lastCheckedAt'  => $s['last_checked_at'],
            'checkError'     => $s['check_error'],
            'lastWatched'    => $lastWatched ? self::epOut($lastWatched) : null,
            'latestEpisode'  => $latest ? self::epOut($latest) : null,
            'nextEpisode'    => $next ? self::epOut($next) : null,
            'unwatchedCount' => $unwatched,
            'hasNew'         => $unwatched > 0,
            'watchUrl'       => $target,
        ];
    }

    public static function episodes(int $serialId): array
    {
        return array_map(
            [self::class, 'epOut'],
            Db::all('SELECT * FROM episodes WHERE serial_id = ? ORDER BY season, number', [$serialId])
        );
    }

    private static function epOut(array $e): array
    {
        return [
            'id'         => (int) $e['id'],
            'season'     => (int) $e['season'],
            'number'     => (int) $e['number'],
            'title'      => $e['title'],
            'url'        => $e['url'],
            'duration'   => $e['duration_seconds'] !== null ? (int) $e['duration_seconds'] : null,
            'position'   => (int) $e['position_seconds'],
            'watchedSeconds' => (int) $e['watched_seconds'],
            'progress'   => round((float) $e['progress_ratio'], 3),
            'watched'    => (int) $e['watched'] === 1,
            'releasedAt' => $e['released_at'],
            'updatedAt'  => $e['updated_at'],
            'label'      => 'S' . str_pad((string) $e['season'], 2, '0', STR_PAD_LEFT)
                          . 'E' . str_pad((string) $e['number'], 2, '0', STR_PAD_LEFT),
        ];
    }

    public static function setEpisodeWatched(int $serialId, int $episodeId, bool $watched): void
    {
        Db::q(
            "UPDATE episodes SET watched = ?, progress_ratio = ?, updated_at = datetime('now')
             WHERE id = ? AND serial_id = ?",
            [$watched ? 1 : 0, $watched ? 1.0 : 0.0, $episodeId, $serialId]
        );
        self::refreshPointers($serialId);
    }

    /** Mark this episode and everything before it as watched. */
    public static function markWatchedUpTo(int $serialId, int $season, int $number): int
    {
        $st = Db::q(
            "UPDATE episodes SET watched = 1, updated_at = datetime('now')
             WHERE serial_id = ? AND watched = 0
               AND (season < ? OR (season = ? AND number <= ?))",
            [$serialId, $season, $season, $number]
        );
        self::refreshPointers($serialId);
        return $st->rowCount();
    }

    public static function delete(int $userId, int $serialId): bool
    {
        return Db::q('DELETE FROM serials WHERE id = ? AND user_id = ?', [$serialId, $userId])
            ->rowCount() > 0;
    }

    public static function find(int $userId, int $serialId): ?array
    {
        return Db::one('SELECT * FROM serials WHERE id = ? AND user_id = ?', [$serialId, $userId]);
    }

    private static function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
