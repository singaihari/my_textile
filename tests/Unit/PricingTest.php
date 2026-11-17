<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Services\PricingService;
use Tests\TestCase;

final class PricingTest extends TestCase
{
    private function line(int $price, int $qty, int $rate = 500, array $extra = []): array
    {
        return array_merge(['variant_id' => 'v', 'unit' => 'pcs', 'qty_milli' => $qty, 'unit_price_paise' => $price, 'mrp_paise' => $price,
            'discount_allowed' => 1, 'rate_bp' => $rate, 'threshold_paise' => null, 'above_rate_bp' => null], $extra);
    }

    public function testInclusiveTaxSplit(): void
    {
        $q = PricingService::calculate([$this->line(105000, 1000)], ['inclusive' => true, 'round_off' => 'none']);
        $this->assertSame(100000, $q['taxable_paise']);
        $this->assertSame(5000, $q['tax_paise']);
        $this->assertSame(2500, $q['cgst_paise']);
        $this->assertSame(2500, $q['sgst_paise']);
        $this->assertSame(105000, $q['total_paise']);
    }

    public function testExclusiveTaxAndIgst(): void
    {
        $q = PricingService::calculate([$this->line(100000, 2000)], ['inclusive' => false, 'interstate' => true, 'round_off' => 'none']);
        $this->assertSame(200000, $q['taxable_paise']);
        $this->assertSame(10000, $q['igst_paise']);
        $this->assertSame(0, $q['cgst_paise']);
        $this->assertSame(210000, $q['total_paise']);
    }

    public function testBillDiscountAllocationAndRoundOff(): void
    {
        $lines = [$this->line(99900, 1000), $this->line(45050, 2000), $this->line(12345, 1000, 500, ['discount_allowed' => 0])];
        $q = PricingService::calculate($lines, ['inclusive' => true, 'bill_disc_type' => 'percent', 'bill_disc_value' => 1000, 'round_off' => 'rupee']);
        // eligible base = 999.00 + 901.00 = 1900.00 → 10% = 190.00 allocated to first two lines only
        $this->assertSame(19000, $q['bill_discount_paise']);
        $this->assertSame(19000, $q['lines'][0]['bill_discount_alloc_paise'] + $q['lines'][1]['bill_discount_alloc_paise']);
        $this->assertSame(0, $q['lines'][2]['bill_discount_alloc_paise']);
        $sumLines = array_sum(array_column($q['lines'], 'line_total_paise'));
        $this->assertSame($sumLines, $q['subtotal_paise']);
        $this->assertSame(0, $q['total_paise'] % 100, 'rounded to rupee');
        $this->assertSame($q['subtotal_paise'] + $q['round_off_paise'], $q['total_paise']);
        foreach ($q['lines'] as $l) {
            $this->assertSame($l['net_paise'], $l['taxable_paise'] + $l['tax_paise'], 'inclusive: net = taxable + tax');
        }
    }

    public function testThresholdSlab(): void
    {
        $rule = ['threshold_paise' => 250000, 'above_rate_bp' => 1800];
        $low = PricingService::calculate([$this->line(200000, 1000, 500, $rule)], ['inclusive' => false, 'round_off' => 'none']);
        $this->assertSame(500, $low['lines'][0]['tax_rate_bp']);
        $high = PricingService::calculate([$this->line(300000, 1000, 500, $rule)], ['inclusive' => false, 'round_off' => 'none']);
        $this->assertSame(1800, $high['lines'][0]['tax_rate_bp']);
        // a discount that takes the unit value under the threshold drops the rate
        $disc = PricingService::calculate([$this->line(300000, 1000, 500, $rule + ['disc_type' => 'amount', 'disc_value' => 60000])], ['inclusive' => false, 'round_off' => 'none']);
        $this->assertSame(500, $disc['lines'][0]['tax_rate_bp']);
    }

    public function testDiscountValidation(): void
    {
        $this->assertThrows(ValidationException::class, fn() => PricingService::calculate([$this->line(1000, 1000, 0, ['disc_type' => 'amount', 'disc_value' => 2000])], []));
        $this->assertThrows(ValidationException::class, fn() => PricingService::calculate([$this->line(1000, 1000, 0, ['disc_type' => 'percent', 'disc_value' => 10001])], []));
        $this->assertThrows(ValidationException::class, fn() => PricingService::calculate([$this->line(1000, 1000, 0, ['discount_allowed' => 0, 'disc_type' => 'percent', 'disc_value' => 100])], []), 'not allowed');
    }

    public function testMetreQuantityNoFloatErrors(): void
    {
        // Scenario E: 2.75 m × ₹349.99 with 5% inclusive tax
        $q = PricingService::calculate([$this->line(34999, 2750, 500, ['unit' => 'm'])], ['inclusive' => true, 'round_off' => 'none']);
        $this->assertSame(96247, $q['gross_paise']);
        $this->assertSame(91664, $q['taxable_paise']);   // 96247 / 1.05 = 91663.81 → 91664
        $this->assertSame(4583, $q['tax_paise']);
        $this->assertSame(2292, $q['cgst_paise']);
        $this->assertSame(2291, $q['sgst_paise']);
        $this->assertThrows(\InvalidArgumentException::class, fn() => PricingService::calculate([$this->line(100, 1500)], []));
    }
}
