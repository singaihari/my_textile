<?php
declare(strict_types=1);

namespace App\Core;

/** RFC 9562 UUID version 7 (Unix-ms timestamp + randomness): sortable, globally unique, sync-safe. */
final class Uuid
{
    private static int $lastMs = 0;
    private static int $counter = 0;

    public static function v7(): string
    {
        $ms = (int)floor(microtime(true) * 1000);
        if ($ms === self::$lastMs) {
            self::$counter++;
        } else {
            self::$lastMs = $ms;
            self::$counter = random_int(0, 0x3FF);
        }
        $time = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
        // 12-bit rand_a carries a monotonic counter for ids created in the same millisecond.
        $randA = (self::$counter & 0xFFF) | 0x7000;
        $randB = random_bytes(8);
        $randB[0] = chr((ord($randB[0]) & 0x3F) | 0x80);
        $hexB = bin2hex($randB);
        return sprintf('%s-%s-%04x-%s-%s',
            substr($time, 0, 8), substr($time, 8, 4), $randA, substr($hexB, 0, 4), substr($hexB, 4, 12));
    }

    public static function isValid(?string $s): bool
    {
        return is_string($s) && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
    }
}
