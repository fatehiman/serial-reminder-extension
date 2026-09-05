<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);

$configFile = APP_ROOT . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit("config.php is missing. Copy config.example.php to config.php and edit it.\n");
}
/** @var array $config */
$config = require $configFile;

date_default_timezone_set($config['timezone'] ?? 'UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    $prefix = 'SR' . chr(92);
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace(chr(92), '/', substr($class, strlen($prefix)));
    $path = APP_ROOT . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require APP_ROOT . '/src/helpers.php';

SR\Db::configure($config);
SR\Config::set($config);
