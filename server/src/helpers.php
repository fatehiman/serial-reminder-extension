<?php
declare(strict_types=1);

function sr_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function sr_token(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function sr_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sr_fail(string $message, int $status = 400, array $extra = []): never
{
    sr_json(['ok' => false, 'error' => $message] + $extra, $status);
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Seconds as MM:SS, with the minutes never wrapping into hours: 34:23, 120:37.
 * Whole minutes are what tells you "did I really watch this episode", and an
 * episode is one or two hours at most, so hours would only make it harder to
 * compare with the episode length.
 */
function sr_mmss(?int $seconds): string
{
    $s = max(0, (int) $seconds);
    return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
}

/** Human "3 hours ago" style, in plain words. */
function sr_ago(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    $diff = time() - strtotime($utc . ' UTC');
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return intdiv($diff, 60) . 'm ago';
    if ($diff < 86400)  return intdiv($diff, 3600) . 'h ago';
    if ($diff < 2592000) return intdiv($diff, 86400) . 'd ago';
    return date('Y-m-d', strtotime($utc . ' UTC'));
}
