<?php
declare(strict_types=1);

namespace App\Core;

/** Simple key-based translations (app/Lang/{en,ta}.php). Missing keys fall back to English, then the key. */
final class Lang
{
    private static array $lines = [];
    private static ?string $locale = null;

    public static function locale(): string
    {
        if (self::$locale === null) {
            $l = $_SESSION['lang'] ?? (Auth::user()['language'] ?? null) ?? Settings::get('language', 'en');
            self::$locale = in_array($l, ['en', 'ta'], true) ? $l : 'en';
        }
        return self::$locale;
    }

    public static function setLocale(string $l): void
    {
        self::$locale = in_array($l, ['en', 'ta'], true) ? $l : 'en';
    }

    public static function get(string $key, array $replace = []): string
    {
        $loc = self::locale();
        $s = self::lines($loc)[$key] ?? self::lines('en')[$key] ?? $key;
        foreach ($replace as $k => $v) {
            $s = str_replace(':' . $k, (string)$v, $s);
        }
        return $s;
    }

    public static function lines(string $loc): array
    {
        if (!isset(self::$lines[$loc])) {
            $f = APP_ROOT . "/app/Lang/$loc.php";
            self::$lines[$loc] = is_file($f) ? require $f : [];
        }
        return self::$lines[$loc];
    }
}
