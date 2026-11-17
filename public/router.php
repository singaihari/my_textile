<?php
// DEVELOPMENT ONLY router for PHP's built-in server:
//   php -S 127.0.0.1:8000 -t public public/router.php
// The built-in server is single-threaded and not hardened; use Apache/Nginx + PHP for daily shop use (docs/DEPLOY_WINDOWS.md).
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;   // let the built-in server send static assets
}
require __DIR__ . '/index.php';
