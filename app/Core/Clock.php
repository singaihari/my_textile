<?php
declare(strict_types=1);

namespace App\Core;

/** Application clock. Real time in production; can be pinned for demo seeding and tests. */
final class Clock
{
    private static ?int $fixed = null;

    public static function set(?int $timestamp): void
    {
        self::$fixed = $timestamp;
    }

    public static function ts(): int
    {
        return self::$fixed ?? time();
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s', self::ts());
    }
}
