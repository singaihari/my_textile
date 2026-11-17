<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Money;
use App\Core\Qty;
use App\Core\ValidationException;

/**
 * Authoritative bill calculation (the POS screen asks the server for quotes; the same code
 * recomputes the bill at save time). Integer paise throughout.
 *
 * Order of operations per line:
 *   gross        = unit price × qty                           (half-up to paise)
 *   line disc    = fixed amount or % of gross (capped at gross)
 *   bill disc    = fixed or % of eligible lines, allocated by largest remainder in proportion to post-line-discount value
 *   net          = gross − line disc − bill disc allocation
 *   tax rate     = rule base rate, or rule above-threshold rate when per-unit taxable value at base rate > threshold
 *   inclusive:   taxable = net × 10000 / (10000 + rate) (half-up), tax = net − taxable, line total = net
 *   exclusive:   taxable = net, tax = net × rate / 10000 (half-up), line total = net + tax
 *   intra-state: CGST = ceil(tax/2), SGST = tax − CGST; inter-state: IGST = tax
 *   bill total   = Σ line totals, then optional round-off to the nearest rupee.
 */
final class PricingService
{
    /**
     * @param array $lines each: variant_id, unit, qty_milli, unit_price_paise, mrp_paise, discount_allowed,
     *                    rate_bp, threshold_paise, above_rate_bp, disc_type (amount|percent|null), disc_value (paise|bp)
     * @param array $opts inclusive(bool), interstate(bool), bill_disc_type, bill_disc_value, round_off ('rupee'|'none')
     */
    public static function calculate(array $lines, array $opts): array
    {
        $inclusive = (bool)($opts['inclusive'] ?? true);
        $interstate = (bool)($opts['interstate'] ?? false);
        $out = [];
        $gross = 0;
        $lineDiscTotal = 0;

        foreach (array_values($lines) as $i => $l) {
            $qty = (int)$l['qty_milli'];
            Qty::validate($qty, $l['unit'] ?? 'pcs');
            $price = (int)$l['unit_price_paise'];
            if ($price < 0) {
                throw new ValidationException('Price cannot be negative (line ' . ($i + 1) . ')');
            }
            $g = Qty::amount($price, $qty);
            $disc = self::discountAmount($g, $l['disc_type'] ?? null, (int)($l['disc_value'] ?? 0), 'line ' . ($i + 1));
            if ($disc > 0 && empty($l['discount_allowed'])) {
                throw new ValidationException('Discount is not allowed on ' . ($l['name'] ?? 'this item'));
            }
            $out[$i] = $l + [
                'line_no' => $i + 1,
                'gross_paise' => $g,
                'line_discount_paise' => $disc,
                'after_line_paise' => $g - $disc,
            ];
            $gross += $g;
            $lineDiscTotal += $disc;
        }

        // bill-level discount applies to lines where discount is allowed
        $eligibleWeights = [];
        foreach ($out as $i => $l) {
            $eligibleWeights[$i] = !empty($l['discount_allowed']) ? $l['after_line_paise'] : 0;
        }
        $eligibleBase = array_sum($eligibleWeights);
        $billDisc = self::discountAmount($eligibleBase, $opts['bill_disc_type'] ?? null, (int)($opts['bill_disc_value'] ?? 0), 'bill');
        $alloc = Money::allocate($billDisc, $eligibleWeights);

        $t = ['taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0, 'tax' => 0, 'total' => 0, 'qty' => 0];
        $taxSummary = [];
        foreach ($out as $i => &$l) {
            $l['bill_discount_alloc_paise'] = $alloc[$i] ?? 0;
            $net = $l['after_line_paise'] - $l['bill_discount_alloc_paise'];
            $rate = self::rateFor($l, $net, (int)$l['qty_milli'], $inclusive);
            if ($inclusive) {
                $taxable = Money::divRound($net * 10000, 10000 + $rate);
                $tax = $net - $taxable;
                $lineTotal = $net;
            } else {
                $taxable = $net;
                $tax = Money::percent($net, $rate);
                $lineTotal = $net + $tax;
            }
            if ($interstate) {
                $cgst = $sgst = 0;
                $igst = $tax;
            } else {
                $cgst = intdiv($tax + 1, 2);
                $sgst = $tax - $cgst;
                $igst = 0;
            }
            $l += ['net_paise' => $net, 'tax_rate_bp' => $rate, 'taxable_paise' => $taxable, 'tax_paise' => $tax,
                'cgst_paise' => $cgst, 'sgst_paise' => $sgst, 'igst_paise' => $igst, 'line_total_paise' => $lineTotal];
            $t['taxable'] += $taxable;
            $t['cgst'] += $cgst;
            $t['sgst'] += $sgst;
            $t['igst'] += $igst;
            $t['tax'] += $tax;
            $t['total'] += $lineTotal;
            $t['qty'] += (int)$l['qty_milli'];
            $k = (string)$rate;
            $taxSummary[$k] ??= ['rate_bp' => $rate, 'taxable_paise' => 0, 'cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 0, 'tax_paise' => 0];
            $taxSummary[$k]['taxable_paise'] += $taxable;
            $taxSummary[$k]['cgst_paise'] += $cgst;
            $taxSummary[$k]['sgst_paise'] += $sgst;
            $taxSummary[$k]['igst_paise'] += $igst;
            $taxSummary[$k]['tax_paise'] += $tax;
        }
        unset($l);

        $roundOff = 0;
        if (($opts['round_off'] ?? 'rupee') === 'rupee') {
            $roundOff = Money::divRound($t['total'], 100) * 100 - $t['total'];
        }
        ksort($taxSummary, SORT_NUMERIC);

        return [
            'lines' => array_values($out),
            'items_count' => count($out),
            'qty_total_milli' => $t['qty'],
            'gross_paise' => $gross,
            'line_discount_paise' => $lineDiscTotal,
            'bill_discount_paise' => $billDisc,
            'discount_paise' => $lineDiscTotal + $billDisc,
            'taxable_paise' => $t['taxable'],
            'cgst_paise' => $t['cgst'],
            'sgst_paise' => $t['sgst'],
            'igst_paise' => $t['igst'],
            'tax_paise' => $t['tax'],
            'subtotal_paise' => $t['total'],
            'round_off_paise' => $roundOff,
            'total_paise' => $t['total'] + $roundOff,
            'tax_summary' => array_values($taxSummary),
            'discount_bp' => $gross > 0 ? Money::divRound(($lineDiscTotal + $billDisc) * 10000, $gross) : 0,
            'inclusive' => $inclusive,
            'interstate' => $interstate,
        ];
    }

    public static function discountAmount(int $base, ?string $type, int $value, string $where): int
    {
        if (!$type || $value === 0) {
            return 0;
        }
        if ($value < 0) {
            throw new ValidationException("Discount cannot be negative ($where)");
        }
        if ($type === 'percent') {
            if ($value > 10000) {
                throw new ValidationException("Discount cannot exceed 100% ($where)");
            }
            return Money::percent($base, $value);
        }
        if ($type === 'amount') {
            if ($value > $base) {
                throw new ValidationException("Discount is larger than the amount ($where)");
            }
            return $value;
        }
        throw new ValidationException("Unknown discount type ($where)");
    }

    /** Select tax rate; threshold compares the per-unit taxable value computed at the base rate. */
    public static function rateFor(array $l, int $net, int $qtyMilli, bool $inclusive): int
    {
        $base = (int)($l['rate_bp'] ?? 0);
        $threshold = $l['threshold_paise'] ?? null;
        $above = $l['above_rate_bp'] ?? null;
        if ($threshold === null || $above === null || $qtyMilli <= 0) {
            return $base;
        }
        $taxableAtBase = $inclusive ? Money::divRound($net * 10000, 10000 + $base) : $net;
        $perUnit = Money::divRound($taxableAtBase * 1000, $qtyMilli);
        return $perUnit > (int)$threshold ? (int)$above : $base;
    }
}
