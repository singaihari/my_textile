<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Quantities are integer milli-units. Piece/set units must be whole numbers;
 * metre units allow up to 3 decimals (configurable precision, default 0.001 m).
 */
final class Qty
{
    public const UNITS = ['pcs' => 'Piece', 'set' => 'Set', 'm' => 'Metre'];

    public static function isDecimalUnit(string $unit): bool
    {
        return $unit === 'm';
    }

    public static function parse(string|int|null $input, string $unit = 'pcs'): int
    {
        $s = trim((string)$input);
        if ($s === '') {
            throw new InvalidArgumentException('Quantity is required');
        }
        if (!preg_match('/^(-)?(\d+)(?:\.(\d{0,3}))?$/', $s, $m)) {
            throw new InvalidArgumentException("Invalid quantity: $input");
        }
        $milli = (int)$m[2] * 1000 + (int)str_pad($m[3] ?? '', 3, '0');
        if (($m[1] ?? '') === '-') {
            $milli = -$milli;
        }
        if ($milli === 0) {
            throw new InvalidArgumentException('Quantity cannot be zero');
        }
        self::validate($milli, $unit, true);
        return $milli;
    }

    public static function validate(int $milli, string $unit, bool $allowNegative = false): void
    {
        if (!$allowNegative && $milli <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero');
        }
        if (!self::isDecimalUnit($unit) && $milli % 1000 !== 0) {
            throw new InvalidArgumentException('Quantity for ' . (self::UNITS[$unit] ?? $unit) . ' items must be a whole number');
        }
        if (self::isDecimalUnit($unit)) {
            $precision = (int)Settings::get('qty_precision_m', 3);
            $step = (int)(10 ** (3 - max(0, min(3, $precision))));
            if ($milli % $step !== 0) {
                throw new InvalidArgumentException('Metre quantity supports at most ' . $precision . ' decimals');
            }
        }
    }

    public static function format(int $milli, string $unit = 'pcs', bool $withUnit = false): string
    {
        $neg = $milli < 0;
        $a = abs($milli);
        if (self::isDecimalUnit($unit)) {
            $s = intdiv($a, 1000) . '.' . str_pad((string)($a % 1000), 3, '0', STR_PAD_LEFT);
            $s = rtrim(rtrim($s, '0'), '.');
            if (!str_contains($s, '.')) {
                $s .= '.0';
            }
        } else {
            $s = (string)intdiv($a, 1000);
            if ($a % 1000) {
                $s .= '.' . rtrim(str_pad((string)($a % 1000), 3, '0', STR_PAD_LEFT), '0');
            }
        }
        return ($neg ? '-' : '') . $s . ($withUnit ? ' ' . $unit : '');
    }

    /** Line amount = unit price (per 1 unit) × qty, rounded half-up to paise. */
    public static function amount(int $unitPaise, int $milli): int
    {
        return Money::divRound($unitPaise * $milli, 1000);
    }
}
