<?php
declare(strict_types=1);

// Front controller for the online sync API. Deploy with THIS folder as the web document root.
require dirname(__DIR__) . '/app/bootstrap.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$host = $_SERVER['SERVER_NAME'] ?? '';
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (!$https && !$isLocal && Server\Config::get('ALLOW_HTTP', '0') !== '1') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => ['code' => 'https_required', 'message' => 'HTTPS is required']]);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base));
}
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[str_replace('_', '-', substr($k, 5))] = $v;
    }
}
if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $headers['AUTHORIZATION'] = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}
$body = (string)file_get_contents('php://input', false, null, 0, Server\Kernel::MAX_BODY + 1);

try {
    [$status, $out] = (new Server\Kernel(Server\Db::instance()))->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $headers, $body, $_GET);
} catch (Throwable $e) {
    error_log('Fatal: ' . $e->getMessage());
    [$status, $out] = [500, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Database unavailable']]];
}
http_response_code($status);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
