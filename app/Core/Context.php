<?php
declare(strict_types=1);

namespace App\Core;

/** Resolves the current store, this device and stock locations (cached per request). */
final class Context
{
    private static ?array $store = null;
    private static ?array $device = null;
    private static array $locations = [];

    public static function store(): array
    {
        if (self::$store === null) {
            self::$store = Database::instance()->one('SELECT * FROM stores ORDER BY created_at LIMIT 1')
                ?? throw new HttpException(503, 'Shop is not set up yet.');
        }
        return self::$store;
    }

    public static function storeId(): string
    {
        return self::store()['id'];
    }

    public static function device(): array
    {
        if (self::$device === null) {
            self::$device = Database::instance()->one('SELECT * FROM devices WHERE is_this_device = 1 LIMIT 1')
                ?? throw new HttpException(503, 'This device is not registered.');
        }
        return self::$device;
    }

    public static function deviceId(): string
    {
        return self::device()['id'];
    }

    public static function locationId(string $code = 'MAIN'): string
    {
        if (!isset(self::$locations[$code])) {
            $id = Database::instance()->value('SELECT id FROM locations WHERE store_id = ? AND code = ?', [self::storeId(), $code]);
            if (!$id) {
                throw new HttpException(500, "Stock location $code missing");
            }
            self::$locations[$code] = $id;
        }
        return self::$locations[$code];
    }

    public static function isSetUp(): bool
    {
        try {
            return (bool)Database::instance()->value('SELECT COUNT(*) FROM users');
        } catch (\PDOException) {
            return false;
        }
    }

    public static function reset(): void
    {
        self::$store = null;
        self::$device = null;
        self::$locations = [];
    }

    /** Financial year code, e.g. 2026-11-14 with April start → "2627". */
    public static function fyCode(?string $date = null): string
    {
        $ts = $date ? strtotime($date) : time();
        $y = (int)date('Y', $ts);
        $m = (int)date('n', $ts);
        $start = (int)Settings::get('fy_start_month', 4);
        $fyStart = $m >= $start ? $y : $y - 1;
        if ($start === 1) {
            return (string)$fyStart;
        }
        return substr((string)$fyStart, -2) . substr((string)($fyStart + 1), -2);
    }
}
