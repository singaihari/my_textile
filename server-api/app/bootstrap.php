<?php
declare(strict_types=1);

/**
 * Server API bootstrap. This application is deployed separately from the shop POS
 * (only server-api/public is web-accessible). Configuration comes from server-api/.env
 * or real environment variables.
 */

define('SERVER_ROOT', dirname(__DIR__));
define('SERVER_VERSION', '1.0.0');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Server\\')) {
        $file = SERVER_ROOT . '/app/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

Server\Config::load(SERVER_ROOT . '/.env');
date_default_timezone_set(Server\Config::get('APP_TIMEZONE', 'Asia/Kolkata'));
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = SERVER_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0770, true);
}
ini_set('error_log', $logDir . '/server-error.log');
