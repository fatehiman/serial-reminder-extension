<?php
declare(strict_types=1);

namespace SR;

/**
 * Tells you on Telegram when a show gets a new episode.
 *
 * The hourly check calls this after it has stored what a site currently lists.
 * One message per show per run, listing the episodes found in that run, and an
 * episode is never mentioned twice: `episodes.notified_at` records the moment
 * it was announced, so a restart, a second cron run or a manual "check now"
 * cannot repeat it.
 *
 * Sending is a plain GET to the URL in config.php, with {msg} replaced by the
 * url-encoded text. The service has no newline, so the line break is whatever
 * `notify.line_break` says (`_C` for the one we use).
 *
 * Two things are deliberately never announced:
 *   - anything found on a show's very first check. That run imports the whole
 *     back catalogue, and "12 new episodes" for a show you just started is
 *     noise, not news.
 *   - episodes you have already watched, which is how the back catalogue of a
 *     show you joined at episode 20 is stored.
 */
final class Notify
{
    /**
     * Announce whatever this check found for one show.
     *
     * @param array $serial     the serials row, read BEFORE the check stamped
     *                          last_checked_at
     * @param bool  $firstCheck true when the show had never been checked, so
     *                          everything found is back catalogue
     * @return array{sent:int, silenced:int, error:?string}
     */
    public static function afterCheck(array $serial, bool $firstCheck): array
    {
        $serialId = (int) $serial['id'];
        $pending  = self::pending($serialId);

        if ($pending === []) {
            return ['sent' => 0, 'silenced' => 0, 'error' => null];
        }
        if ($firstCheck || !self::enabled()) {
            // Still stamp them. A show whose back catalogue arrived while the
            // notifier was switched off must not announce all of it later.
            self::stamp($pending);
            return ['sent' => 0, 'silenced' => count($pending), 'error' => null];
        }

        $error = self::send(self::message($serial, $pending));
        if ($error !== null) {
            // Not stamped, so the next hourly run tries again. Only a message
            // that really went out counts as "announced".
            return ['sent' => 0, 'silenced' => 0, 'error' => $error];
        }
        self::stamp($pending);
        return ['sent' => count($pending), 'silenced' => 0, 'error' => null];
    }

    /** Episodes the site published that we have not announced yet. */
    public static function pending(int $serialId): array
    {
        return Db::all(
            "SELECT * FROM episodes
              WHERE serial_id = ? AND notified_at IS NULL AND watched = 0 AND source = 'catalog'
              ORDER BY season, number",
            [$serialId]
        );
    }

    /** @param array<int,array> $episodes */
    public static function stamp(array $episodes): void
    {
        foreach ($episodes as $ep) {
            Db::q("UPDATE episodes SET notified_at = datetime('now') WHERE id = ?", [(int) $ep['id']]);
        }
    }

    public static function enabled(): bool
    {
        return self::urlTemplate() !== '';
    }

    /**
     * The text of the message. Kept short: what, where, and one link to click.
     *
     * @param array<int,array> $episodes
     */
    public static function message(array $serial, array $episodes): string
    {
        $max   = max(1, (int) Config::get('notify.max_episodes', 6));
        $count = count($episodes);
        $shown = array_slice($episodes, 0, $max);

        $lines = ['🎬 ' . (string) $serial['title']];
        $lines[] = $count === 1
            ? 'A new episode on ' . (string) $serial['provider'] . ':'
            : $count . ' new episodes on ' . (string) $serial['provider'] . ':';

        foreach ($shown as $ep) {
            $label = 'S' . str_pad((string) $ep['season'], 2, '0', STR_PAD_LEFT)
                   . 'E' . str_pad((string) $ep['number'], 2, '0', STR_PAD_LEFT);
            $title = trim((string) ($ep['title'] ?? ''));
            $lines[] = $title !== '' ? $label . ' — ' . $title : $label;
        }
        if ($count > $max) {
            $lines[] = '… and ' . ($count - $max) . ' more';
        }

        // One episode: link straight to it. More: the dashboard sorts them out.
        $link = $count === 1 ? trim((string) ($episodes[0]['url'] ?? '')) : '';
        if ($link === '') {
            $link = rtrim((string) Config::get('app_url', ''), '/') . '/dashboard';
        }
        $lines[] = $link;

        return implode("\n", array_filter($lines, static fn ($l) => trim($l) !== ''));
    }

    /**
     * Send one message. Returns null when it went out, or why it did not.
     *
     * A 2xx answer counts as sent even if the body is not what we expect. The
     * alternative is retrying every hour against a service that already
     * delivered the message, which would be worse than one lost notification.
     */
    public static function send(string $text): ?string
    {
        $template = self::urlTemplate();
        if ($template === '') {
            return 'notify.url is not set in config.php';
        }
        $brk = (string) Config::get('notify.line_break', '_C');
        $msg = str_replace(["\r\n", "\n"], $brk, $text);

        $url = str_replace('{msg}', rawurlencode($msg), $template);
        $res = Http::get($url);

        if ($res['error'] !== null) {
            return 'notifier unreachable: ' . $res['error'];
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return 'notifier answered HTTP ' . $res['status'];
        }
        return null;
    }

    private static function urlTemplate(): string
    {
        $url = Config::get('notify.url', '');
        return is_string($url) ? trim($url) : '';
    }
}
