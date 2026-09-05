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

    // An episode counts as watched when either rule matches.
    'watched_rules' => [
        'min_ratio'   => 0.90,  // player reached 90% of the episode
        'min_seconds' => 1200,  // or it played for 20 minutes (duration unknown)
        'ratio_of_duration' => 0.85, // or watched time >= 85% of duration
    ],
];
