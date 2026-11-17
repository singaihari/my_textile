<?php
declare(strict_types=1);

/**
 * Application bootstrap: autoloader, environment, timezone, error handling.
 * Shared by the web front controller (public/index.php), CLI scripts and tests.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = APP_ROOT . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require APP_ROOT . '/app/Core/helpers.php';

App\Core\Env::load(APP_ROOT . '/.env');
date_default_timezone_set(App\Core\Env::get('APP_TIMEZONE', 'Asia/Kolkata'));
mb_internal_encoding('UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', App\Core\Env::bool('APP_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
