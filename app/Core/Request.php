<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?Request $current = null;
    private ?array $json = null;
    public array $params = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $query,
        private array $post,
        private array $server,
        private array $files = [],
        private ?string $rawBody = null,
    ) {
    }

    public static function fromGlobals(): Request
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = base_path_url();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }
        $uri = '/' . trim($uri, '/');
        if ($uri === '/index.php') {
            $uri = '/';
        }
        $r = new Request(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), $uri, $_GET, $_POST, $_SERVER, $_FILES);
        self::$current = $r;
        return $r;
    }

    public static function current(): Request
    {
        return self::$current ??= new Request('GET', '/', [], [], []);
    }

    public static function setCurrent(Request $r): void
    {
        self::$current = $r;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if ($this->isJson()) {
            return $this->json()[$key] ?? $default;
        }
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        return is_numeric($v) ? (int)$v : $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? trim($v) : $v;
    }

    public function allQuery(): array
    {
        return $this->query;
    }

    public function all(): array
    {
        return $this->isJson() ? $this->json() : array_merge($this->query, $this->post);
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) ? $f : null;
    }

    public function json(): array
    {
        if ($this->json === null) {
            $raw = $this->rawBody ?? (string)file_get_contents('php://input');
            if (strlen($raw) > 2_000_000) {
                throw new HttpException(413, 'Request too large');
            }
            $d = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($d)) {
                throw new HttpException(400, 'Invalid JSON body');
            }
            $this->json = $d;
        }
        return $this->json;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->server['CONTENT_TYPE'] ?? ''), 'application/json');
    }

    public function wantsJson(): bool
    {
        return $this->isJson()
            || str_contains(strtolower($this->server['HTTP_ACCEPT'] ?? ''), 'application/json')
            || str_starts_with($this->path, '/api/');
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? null;
    }

    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    public function isHttps(): bool
    {
        return (!empty($this->server['HTTPS']) && $this->server['HTTPS'] !== 'off');
    }
}
