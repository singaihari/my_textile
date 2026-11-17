<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/**
 * Money helpers. All amounts are integer paise. Parsing never goes through floats.
 */
final class Money
{
    /** Parse a user-entered rupee string ("1,234.5", "₹99", "12") into paise. */
    public static function parse(string|int|null $input): int
    {
        if (is_int($input)) {
            return $input * 100;
        }
        $s = trim((string)$input);
        $s = str_replace([',', '₹', ' ', 'Rs.', 'Rs', 'INR'], '', $s);
        if ($s === '') {
            return 0;
        }
        if (!preg_match('/^(-)?(\d+)(?:\.(\d{0,2}))?$/', $s, $m)) {
            throw new InvalidArgumentException("Invalid amount: $input");
        }
        $paise = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0');
        return ($m[1] ?? '') === '-' ? -$paise : $paise;
    }

    /** Format paise with Indian digit grouping: 123456789 → ₹12,34,567.89 */
    public static function format(int $paise, bool $symbol = true): string
    {
        $neg = $paise < 0;
        $paise = abs($paise);
        $rupees = (string)intdiv($paise, 100);
        $p = str_pad((string)($paise % 100), 2, '0', STR_PAD_LEFT);
        if (strlen($rupees) > 3) {
            $last3 = substr($rupees, -3);
            $rest = substr($rupees, 0, -3);
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $rupees = $rest . ',' . $last3;
        }
        return ($neg ? '-' : '') . ($symbol ? '₹' : '') . $rupees . '.' . $p;
    }

    /** Plain decimal string for inputs/CSV: 12345 → "123.45" */
    public static function toDecimal(int $paise): string
    {
        $neg = $paise < 0;
        $paise = abs($paise);
        return ($neg ? '-' : '') . intdiv($paise, 100) . '.' . str_pad((string)($paise % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Integer division rounded half away from zero. */
    public static function divRound(int $num, int $den): int
    {
        if ($den === 0) {
            throw new InvalidArgumentException('Division by zero');
        }
        if ($den < 0) {
            $num = -$num;
            $den = -$den;
        }
        $q = intdiv(abs($num) * 2 + $den, 2 * $den);
        return $num < 0 ? -$q : $q;
    }

    /** Percentage in basis points of an amount, rounded half-up. */
    public static function percent(int $paise, int $bp): int
    {
        return self::divRound($paise * $bp, 10000);
    }

    /**
     * Allocate $total across $weights proportionally using the largest-remainder method,
     * so the parts always sum exactly to $total.
     * @param int[] $weights
     * @return int[]
     */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0 || $total === 0) {
            return array_map(fn() => 0, $weights);
        }
        $parts = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $k => $w) {
            $exact = $total * $w;
            $parts[$k] = intdiv($exact, $sum);
            $remainders[$k] = $exact % $sum;
            $allocated += $parts[$k];
        }
        $left = $total - $allocated;
        arsort($remainders);
        foreach (array_keys($remainders) as $k) {
            if ($left <= 0) {
                break;
            }
            $parts[$k]++;
            $left--;
        }
        return $parts;
    }

    /** Parse "12.5" (percent) into basis points (1250). */
    public static function parsePercentBp(string|int|null $input): int
    {
        $s = trim(str_replace('%', '', (string)$input));
        if ($s === '') {
            return 0;
        }
        if (!preg_match('/^(\d+)(?:\.(\d{0,2}))?$/', $s, $m)) {
            throw new InvalidArgumentException("Invalid percentage: $input");
        }
        return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
    }
}
