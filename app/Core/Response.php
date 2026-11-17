<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function json(mixed $data, int $status = 200): Response
    {
        return new Response(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']
        );
    }

    public static function redirect(string $path, array $query = []): Response
    {
        $loc = str_starts_with($path, 'http') ? $path : url($path, $query);
        return new Response('', 302, ['Location' => $loc]);
    }

    public static function html(string $html, int $status = 200): Response
    {
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function download(string $content, string $filename, string $type = 'text/csv; charset=utf-8'): Response
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
        return new Response($content, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            $defaults = [
                'X-Content-Type-Options' => 'nosniff',
                'X-Frame-Options' => 'SAMEORIGIN',
                'Referrer-Policy' => 'same-origin',
                'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'",
            ];
            foreach (array_merge($defaults, $this->headers) as $k => $v) {
                header("$k: $v");
            }
        }
        echo $this->body;
    }
}
