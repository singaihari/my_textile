<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(Request $req): void
    {
        $sent = $req->header('X-CSRF-Token') ?? (string)$req->input('_csrf', '');
        $expected = $_SESSION['_csrf'] ?? '';
        if ($expected === '' || !is_string($sent) || !hash_equals($expected, $sent)) {
            throw new HttpException(419, 'Your session form token expired. Please reload the page and try again.');
        }
    }
}
