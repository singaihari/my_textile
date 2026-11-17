<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Money;
use App\Core\Qty;
use App\Core\Uuid;
use Tests\TestCase;

final class MoneyTest extends TestCase
{
    public function testParseAndFormatWithoutFloats(): void
    {
        $this->assertSame(123450, Money::parse('1,234.5'));
        $this->assertSame(9900, Money::parse('₹99'));
        $this->assertSame(1, Money::parse('0.01'));
        $this->assertSame(-250, Money::parse('-2.50'));
        $this->assertSame('₹12,34,567.89', Money::format(123456789));
        $this->assertSame('₹0.05', Money::format(5));
        $this->assertSame('-₹1,000.00', Money::format(-100000));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Money::parse('12.345'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Money::parse('abc'));
    }

    public function testRoundingHalfUp(): void
    {
        $this->assertSame(1, Money::divRound(5, 10));
        $this->assertSame(0, Money::divRound(4, 10));
        $this->assertSame(-1, Money::divRound(-5, 10));
        $this->assertSame(5, Money::percent(100, 500));  // 5% of 1.00
    }

    public function testAllocationSumsExactly(): void
    {
        $parts = Money::allocate(1000, [1, 1, 1]);
        $this->assertSame(1000, array_sum($parts));
        $this->assertSame([334, 333, 333], array_values($parts));
        $this->assertSame([0, 0], Money::allocate(0, [5, 5]));
        $p = Money::allocate(9999, [12345, 6789, 1]);
        $this->assertSame(9999, array_sum($p));
    }

    public function testQuantityRules(): void
    {
        $this->assertSame(2750, Qty::parse('2.75', 'm'));
        $this->assertSame(3000, Qty::parse('3', 'pcs'));
        $this->assertThrows(\InvalidArgumentException::class, fn() => Qty::parse('1.5', 'pcs'), 'whole number');
        $this->assertThrows(\InvalidArgumentException::class, fn() => Qty::parse('0', 'pcs'));
        $this->assertSame('2.75', Qty::format(2750, 'm'));
        $this->assertSame('3', Qty::format(3000, 'pcs'));
        // ₹349.99 per metre × 2.75 m = 962.4725 → ₹962.47
        $this->assertSame(96247, Qty::amount(34999, 2750));
    }

    public function testUuidV7IsUniqueAndOrdered(): void
    {
        $ids = [];
        for ($i = 0; $i < 2000; $i++) {
            $ids[] = Uuid::v7();
        }
        $this->assertSame(2000, count(array_unique($ids)));
        $this->assertTrue(Uuid::isValid($ids[0]));
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($ids, $sorted, 'UUIDv7 must be time-ordered');
    }
}
