<?php
declare(strict_types=1);

/**
 * Serial Reminder — country relay.
 *
 * Some movie sites answer only from inside their own country. namava.ir is one:
 * from a German IP it still returns HTTP 200 and succeeded:true, but with an
 * empty result, so the hourly check on ger1 cannot tell "no new episodes" from
 * "you are in the wrong country".
 *
 * This file runs on a box that IS in that country. It fetches one URL on the
 * checker's behalf and hands the answer back untouched.
 *
 *   GET /?u=<url-encoded target>       header: X-Relay-Key: <key>
 *
 * It is deliberately dull:
 *   - only GET, only https,
 *   - only the hosts and path prefixes in ALLOW below, so it can never be used
 *     as a general open proxy,
 *   - no cookies, no redirects off the allowed host, no request body,
 *   - the key is a file on disk, never in git and never in a provider script.
 *
 * Deploy: see DEPLOYMENT.md section 13.
 */

const KEY_FILE = '/etc/serial-reminder-relay.key';
const MAX_BYTES = 4 * 1024 * 1024;
const TIMEOUT   = 25;

/** host => list of allowed path prefixes */
const ALLOW = [
    'www.namava.ir' => ['/api/'],
];

function fail(int $status, string $why): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['relayError' => $why], JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail(405, 'only GET');
}

$expected = is_readable(KEY_FILE) ? trim((string) file_get_contents(KEY_FILE)) : '';
$given    = trim((string) ($_SERVER['HTTP_X_RELAY_KEY'] ?? ''));
if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
    fail(403, 'bad or missing X-Relay-Key');
}

$target = (string) ($_GET['u'] ?? '');
if ($target === '') {
    fail(400, 'no u parameter');
}

$parts = parse_url($target);
$host  = strtolower((string) ($parts['host'] ?? ''));
$path  = (string) ($parts['path'] ?? '');
if (($parts['scheme'] ?? '') !== 'https' || !isset(ALLOW[$host])) {
    fail(403, 'host not allowed');
}
$allowed = false;
foreach (ALLOW[$host] as $prefix) {
    if (str_starts_with($path, $prefix)) {
        $allowed = true;
        break;
    }
}
if (!$allowed) {
    fail(403, 'path not allowed');
}

$ch = curl_init($target);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT        => TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_ENCODING       => '',
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Accept-Language: fa-IR,fa;q=0.9',
        'Referer: https://' . $host . '/',
    ],
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                            . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
]);
$body   = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err    = curl_errno($ch) !== 0 ? curl_error($ch) : null;
curl_close($ch);

if ($err !== null) {
    fail(502, 'upstream: ' . $err);
}
if (!is_string($body) || strlen($body) > MAX_BYTES) {
    fail(502, 'upstream answer was empty or too large');
}

http_response_code($status === 0 ? 502 : $status);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo $body;
