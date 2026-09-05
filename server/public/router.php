<?php
/**
 * Development only: `php -S 127.0.0.1:8099 router.php` from this directory.
 * On the real server Apache + .htaccess do this job.
 */
$file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
require __DIR__ . '/index.php';
