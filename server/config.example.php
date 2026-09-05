<?php
/**
 * Copy to config.php on the server and fill in. Never commit config.php.
 */
return [
    // SQLite file. Keep it OUTSIDE the docroot.
    'db_path'   => dirname(__DIR__) . '/data/serial-reminder.sqlite',

    // Public base URL, no trailing slash.
    'app_url'   => 'https://serial-reminder.kimiasoft.com',

    'timezone'  => 'Asia/Tehran',

    // Dashboard session lifetime.
    'session_days' => 30,

    // A show you opened but never really watched is kept, hidden, this long in
    // case you come back to it. These rows never appear on the dashboard, so
    // this is the only thing that removes them.
    'candidate_days' => 90,

    // Outgoing HTTP for the new-episode checker.
    'http' => [
        'timeout'    => 20,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                      . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        // Optional outbound proxy, e.g. 'socks5h://127.0.0.1:1080'. null = direct.
        'proxy'      => null,
        // Path to a CA bundle if the system one is missing. null = system default.
        'ca_bundle'  => null,
        // Only for a dev machine behind a TLS-inspecting proxy. Keep false live.
        'insecure_ssl' => false,
    ],

    // Some sites answer only from their own country. A provider whose catalog
    // says "fetchVia": "iran" is fetched through the relay named here instead of
    // straight from this server. {url} is replaced with the encoded target URL.
    // The key lives here and never in the provider file, which the extension
    // downloads. Leave the list empty when you do not need one.
    'relays' => [
        // 'iran' => [
        //     'url'     => 'https://sr-relay.example.ir/?u={url}',
        //     'headers' => ['X-Relay-Key' => 'the long random key on the relay'],
        // ],
    ],

    // An episode counts as watched when any of these matches. Reaching this also
    // makes the show join your list — a page you only sampled never shows up.
    'watched_rules' => [
        // Player reached 70% of the episode. Not higher: people skip the ads,
        // the recap at the start and the credits at the end.
        'min_ratio'   => 0.70,
        // Or it simply played for 20 minutes, when the length is unknown.
        'min_seconds' => 1200,
        // Or the real playing time adds up to 70% of the length, which counts
        // on its own however the position behaves.
        'ratio_of_duration' => 0.70,
        // Proof that you really played it, not just that the position is high.
        // Sites resume where you left off, so the position can read 90% two
        // seconds after opening a page; the scrub bar does the same. The player
        // rule above only counts once this much has actually played.
        'min_real_ratio'   => 0.25,   // a quarter of the episode length
        'min_real_seconds' => 300,    // and never less than five minutes
    ],
];
