<?php
declare(strict_types=1);

use App\Core\Lang;
use App\Core\Money;
use App\Core\Qty;
use App\Core\Request;

function now(): string
{
    return App\Core\Clock::now();
}

function today(): string
{
    return date('Y-m-d', App\Core\Clock::ts());
}

function uuid(): string
{
    return App\Core\Uuid::v7();
}

/** HTML-escape for output. */
function e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path_url(): string
{
    static $base = null;
    if ($base === null) {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base === '.' || PHP_SAPI === 'cli') {
            $base = '';
        }
    }
    return $base;
}

function url(string $path = '', array $query = []): string
{
    $u = base_path_url() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function asset(string $path): string
{
    $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr((string)filemtime($file), -6) : APP_VERSION;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}

function csrf_token(): string
{
    return App\Core\Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function money(int|string|null $paise, bool $symbol = true): string
{
    return Money::format((int)$paise, $symbol);
}

function qty(int|string|null $milli, string $unit = 'pcs'): string
{
    return Qty::format((int)$milli, $unit);
}

function __(string $key, array $replace = []): string
{
    return Lang::get($key, $replace);
}

function icon(string $name, string $class = 'icon'): string
{
    return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"><use href="' . e(asset('icons/sprite.svg')) . '#' . e($name) . '"></use></svg>';
}

/** Public URL of a product image (relative path under public/), or the neutral placeholder. */
function product_image_url(?string $path): string
{
    if ($path && preg_match('#^uploads/products/[A-Za-z0-9._-]+\.(svg|png|jpe?g|webp)$#', $path) && is_file(APP_ROOT . '/public/' . $path)) {
        return url($path) . '?v=' . substr((string)filemtime(APP_ROOT . '/public/' . $path), -6);
    }
    return asset('img/no-image.svg');
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old'][$key] ?? $default;
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function can(string $permission): bool
{
    return App\Core\Auth::can($permission);
}

function setting(string $key, mixed $default = null): mixed
{
    return App\Core\Settings::get($key, $default);
}

function fmt_date(?string $dt, bool $withTime = true): string
{
    if (!$dt) {
        return '';
    }
    $ts = strtotime($dt);
    return $ts ? date($withTime ? 'd M Y, h:i A' : 'd M Y', $ts) : $dt;
}

function bp_pct(int|string|null $bp): string
{
    $bp = (int)$bp;
    $s = rtrim(rtrim(sprintf('%d.%02d', intdiv($bp, 100), $bp % 100), '0'), '.');
    return $s . '%';
}

function request(): Request
{
    return Request::current();
}

/** Mask secrets for display: keep last 4 characters. */
function mask_secret(?string $s): string
{
    if (!$s) {
        return '';
    }
    return str_repeat('•', max(4, strlen($s) - 4)) . substr($s, -4);
}
