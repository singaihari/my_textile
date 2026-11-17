<?php
declare(strict_types=1);

namespace App\Core;

/** Key/value shop settings stored in SQLite with typed defaults. */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'shop_name' => 'My Textile Shop',
        'shop_address' => '',
        'shop_phone' => '',
        'shop_email' => '',
        'shop_gstin' => '',
        'shop_state_code' => '33',
        'shop_state_name' => 'Tamil Nadu',
        'invoice_prefix' => 'TX',
        'return_prefix' => 'CN',
        'fy_start_month' => '4',
        'prices_include_tax' => '1',
        'round_off' => 'rupee',          // rupee | none
        'receipt_footer' => 'Thank you! Visit again.',
        'return_policy_text' => 'Exchange within 7 days with bill. No exchange on innerwear and altered items.',
        'printer_format' => '80mm',      // 80mm | a4
        'receipt_width_mm' => '80',
        'auto_print' => '1',
        'show_tax_breakup' => '1',
        'label_width_mm' => '50',
        'label_height_mm' => '25',
        'label_columns' => '3',
        'language' => 'en',
        'allow_negative_stock' => '0',
        'merge_duplicate_scans' => '1',
        'require_customer' => '0',
        'require_cash_session' => '1',
        'return_window_days' => '7',
        'qty_precision_m' => '3',
        'session_lock_minutes' => '15',
        'payment_methods' => 'cash,upi,card,credit',
        'upi_vpa' => '',
        'sync_endpoint' => '',
        'sync_device_token' => '',
        'sync_batch_size' => '50',
        'sync_last_pull_cursor' => '0',
        'sync_last_success_at' => '',
        'backup_keep' => '20',
        'shortcuts' => '',
        'demo_mode' => '0',
        'setup_complete' => '0',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        return $default ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function bool(string $key): bool
    {
        return (string)self::get($key) === '1';
    }

    public static function int(string $key): int
    {
        return (int)self::get($key);
    }

    public static function set(string $key, ?string $value, ?string $userId = null): void
    {
        Database::instance()->run(
            'INSERT INTO settings (key, value, updated_at, updated_by) VALUES (:k, :v, :t, :u)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at, updated_by = excluded.updated_by',
            ['k' => $key, 'v' => $value, 't' => now(), 'u' => $userId]
        );
        self::load();
        self::$cache[$key] = $value;
    }

    public static function all(): array
    {
        self::load();
        return array_merge(self::DEFAULTS, self::$cache);
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        self::$cache = [];
        try {
            foreach (Database::instance()->all('SELECT key, value FROM settings') as $r) {
                self::$cache[$r['key']] = $r['value'];
            }
        } catch (\PDOException) {
            // before migrations: fall back to defaults
        }
    }
}
