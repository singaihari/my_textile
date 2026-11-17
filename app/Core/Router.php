<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Small router. Routes: [METHOD, '/path/{id}', [Controller::class, 'method'], options]
 * options: 'auth' => bool (default true), 'perm' => permission code, 'csrf' => bool (default true for non-GET)
 */
final class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, array $handler, array $opts = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_-]+)', rtrim($pattern, '/') ?: '/') . '$#';
        $this->routes[] = compact('method', 'pattern', 'handler', 'opts', 'regex');
    }

    public function get(string $p, array $h, array $o = []): void { $this->add('GET', $p, $h, $o); }
    public function post(string $p, array $h, array $o = []): void { $this->add('POST', $p, $h, $o); }

    public function dispatch(Request $req): Response
    {
        $allowed = false;
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $req->path, $m)) {
                continue;
            }
            $allowed = true;
            if ($r['method'] !== $req->method) {
                continue;
            }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            $opts = $r['opts'];

            if ($req->method !== 'GET' && ($opts['csrf'] ?? true)) {
                Csrf::verify($req);
            }
            if ($opts['auth'] ?? true) {
                Auth::requireLogin($req);
                if (!empty($opts['perm'])) {
                    Auth::authorize($opts['perm']);
                }
            }
            [$class, $method] = $r['handler'];
            $result = (new $class())->$method($req);
            if ($result instanceof Response) {
                return $result;
            }
            if (is_array($result)) {
                return Response::json($result);
            }
            return Response::html((string)$result);
        }
        throw new HttpException($allowed ? 405 : 404, $allowed ? 'Method not allowed' : 'Page not found');
    }
}
