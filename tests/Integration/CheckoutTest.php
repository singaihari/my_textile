<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Services\SaleService;
use Tests\TestCase;

final class CheckoutTest extends TestCase
{
    private array $v = [];

    public function setUp(): void
    {
        parent::setUp();
        [, $shirts] = $this->makeProduct('SHIRT01', 'pcs', [
            ['sku' => 'SH-L-BLUE', 'barcode' => '2000000000011', 'size_id' => $this->sizeId('L'), 'color_id' => $this->colorId('Blue'), 'mrp' => '999', 'price' => '899', 'cost' => '450', 'opening_qty' => '10'],
            ['sku' => 'SH-XL-BLUE', 'barcode' => '2000000000028', 'size_id' => $this->sizeId('XL'), 'color_id' => $this->colorId('Blue'), 'mrp' => '999', 'price' => '899', 'cost' => '450', 'opening_qty' => '5'],
        ], 'APPAREL');
        [, $saree] = $this->makeProduct('SAREE01', 'pcs', [['sku' => 'SAREE-RED', 'barcode' => 'SAR001', 'mrp' => '3500', 'price' => '3200', 'cost' => '2000', 'opening_qty' => '2']], 'APPAREL');
        [, $fabric] = $this->makeProduct('FAB01', 'm', [['sku' => 'FAB-COT', 'barcode' => 'FAB001', 'mrp' => '349.99', 'price' => '349.99', 'cost' => '200', 'opening_qty' => '50.5']], 'FABRIC');
        $this->v = $shirts + $saree + $fabric;
    }

    private function sale(array $lines, array $payments, array $extra = []): array
    {
        return (new SaleService($this->db))->checkout(array_merge(['client_ref' => uuid(), 'lines' => $lines, 'payments' => $payments], $extra));
    }

    public function testScenarioAStandardSaleIsAtomicAndComplete(): void
    {
        $quote = (new SaleService($this->db))->quote(['lines' => [['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1'], ['variant_id' => $this->v['SAREE-RED'], 'qty' => '1']]]);
        $this->assertSame(409900, $quote['total_paise']);
        $r = $this->sale([['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1'], ['variant_id' => $this->v['SAREE-RED'], 'qty' => '1']],
            [['method' => 'cash', 'amount_paise' => 500000]], ['expected_total_paise' => 409900]);
        $this->assertSame('TX-C1-' . \App\Core\Context::fyCode() . '-00001', $r['invoice_no']);
        $this->assertSame(90100, $r['change_paise']);
        $this->assertSame(9000, $this->stock($this->v['SH-L-BLUE']));
        $this->assertSame(1000, $this->stock($this->v['SAREE-RED']));
        $sale = $this->db->one('SELECT * FROM sales WHERE id = ?', [$r['sale_id']]);
        $this->assertSame(409900, (int)$sale['total_paise']);
        $this->assertSame(245000, (int)$sale['cogs_paise'], 'cost snapshot 450 + 2000');
        $this->assertSame(409900, (int)$this->db->value('SELECT SUM(amount_paise) FROM payments WHERE sale_id = ?', [$r['sale_id']]));
        $this->assertSame(2, (int)$this->db->value("SELECT COUNT(*) FROM stock_movements WHERE ref_id = ? AND movement_type = 'sale'", [$r['sale_id']]));
        $ev = $this->db->one("SELECT * FROM sync_outbox WHERE entity_type = 'sale' AND entity_id = ?", [$r['sale_id']]);
        $this->assertSame('pending', $ev['status']);
        $payload = json_decode($ev['payload'], true);
        $this->assertSame(2, count($payload['items']));
        $this->assertSame($r['invoice_no'], $payload['invoice_no']);
    }

    public function testDoubleSubmitReturnsSameBill(): void
    {
        $ref = uuid();
        $in = ['client_ref' => $ref, 'lines' => [['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1']], 'payments' => [['method' => 'upi', 'amount_paise' => 89900, 'reference' => 'UPI123']]];
        $a = (new SaleService($this->db))->checkout($in);
        $b = (new SaleService($this->db))->checkout($in);
        $this->assertSame($a['sale_id'], $b['sale_id']);
        $this->assertTrue($b['duplicate']);
        $this->assertSame(1, (int)$this->db->value('SELECT COUNT(*) FROM sales'));
        $this->assertSame(9000, $this->stock($this->v['SH-L-BLUE']));
        $this->assertSame(1, (int)$this->db->value("SELECT COUNT(*) FROM sync_outbox WHERE entity_type = 'sale'"));
    }

    public function testNegativeStockBlockedAndRolledBack(): void
    {
        $before = (int)$this->db->value('SELECT COUNT(*) FROM stock_movements');
        $this->assertThrows(ValidationException::class, fn() => $this->sale(
            [['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1'], ['variant_id' => $this->v['SAREE-RED'], 'qty' => '3']],
            [['method' => 'cash', 'amount_paise' => 2000000]]), 'Not enough stock');
        $this->assertSame(10000, $this->stock($this->v['SH-L-BLUE']), 'first line rolled back');
        $this->assertSame(0, (int)$this->db->value('SELECT COUNT(*) FROM sales'));
        $this->assertSame($before, (int)$this->db->value('SELECT COUNT(*) FROM stock_movements'));
        $this->assertSame(0, (int)$this->db->value("SELECT COUNT(*) FROM sync_outbox WHERE entity_type = 'sale'"));
        $this->assertSame(1, (int)$this->db->value("SELECT next_no FROM doc_sequences WHERE series = 'INV'") ?: 1, 'no invoice number consumed');
    }

    public function testScenarioEMetreSale(): void
    {
        $r = $this->sale([['variant_id' => $this->v['FAB-COT'], 'qty' => '2.75']], [['method' => 'cash', 'amount_paise' => 100000]]);
        $item = $this->db->one('SELECT * FROM sale_items WHERE sale_id = ?', [$r['sale_id']]);
        $this->assertSame(2750, (int)$item['qty_milli']);
        $this->assertSame(96247, (int)$item['line_total_paise']);
        $this->assertSame(96200, (int)$this->db->value('SELECT total_paise FROM sales WHERE id = ?', [$r['sale_id']]), 'rounded to rupee');
        $this->assertSame(47750, $this->stock($this->v['FAB-COT']), '50.5 - 2.75 = 47.75 m');
        $this->assertSame(55000, (int)$item['cogs_paise'], '200 × 2.75');
    }

    public function testPaymentGuards(): void
    {
        $line = [['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1']];
        $this->assertThrows(ValidationException::class, fn() => $this->sale($line, [['method' => 'cash', 'amount_paise' => 50000]]), 'short');
        $this->assertThrows(ValidationException::class, fn() => $this->sale($line, [['method' => 'card', 'amount_paise' => 100000]]), 'exceed');
        $this->assertThrows(ValidationException::class, fn() => $this->sale($line, [['method' => 'card', 'amount_paise' => 89900, 'reference' => '4111 1111 1111 1111']]), 'card numbers');
        $this->assertThrows(ValidationException::class, fn() => $this->sale($line, [['method' => 'credit', 'amount_paise' => 89900]]), 'customer');
        // split payment
        $r = $this->sale($line, [['method' => 'upi', 'amount_paise' => 50000], ['method' => 'cash', 'amount_paise' => 50000]]);
        $this->assertSame(10100, $r['change_paise']);
        $this->assertSame(39900, (int)$this->db->value("SELECT amount_paise FROM payments WHERE sale_id = ? AND method = 'cash'", [$r['sale_id']]));
    }

    public function testCashierDiscountCeilingNeedsApproval(): void
    {
        $cashier = $this->makeUser('cashier1', 'cashier');
        $this->makeUser('manager1', 'manager', 'Manager123');
        Auth::actAs($cashier);
        $line = [['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1', 'disc_type' => 'percent', 'disc_value' => 2000]];
        $e = $this->assertThrows(ValidationException::class, fn() => $this->sale($line, [['method' => 'cash', 'amount_paise' => 100000]]), 'approval');
        $this->assertSame('pos.discount_override', $e->errors['approval']);
        // 5% is within cashier limit
        $ok = $this->sale([['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1', 'disc_type' => 'percent', 'disc_value' => 500]], [['method' => 'cash', 'amount_paise' => 100000]]);
        $this->assertTrue(!empty($ok['sale_id']));
        // manager approval unlocks 20%
        $approval = Auth::grantApproval('manager1', 'Manager123', 'pos.discount_override', '127.0.0.1');
        $r = $this->sale($line, [['method' => 'cash', 'amount_paise' => 100000]], ['approval_token' => $approval['token']]);
        $this->assertSame((string)$this->db->value("SELECT id FROM users WHERE username = 'manager1'"), (string)$this->db->value('SELECT approved_by FROM sales WHERE id = ?', [$r['sale_id']]));
        // token is single-use
        $this->assertThrows(ValidationException::class, fn() => $this->sale($line, [['method' => 'cash', 'amount_paise' => 100000]], ['approval_token' => $approval['token']]));
    }

    public function testCashierCannotOverridePriceOrSkipPermission(): void
    {
        $cashier = $this->makeUser('cashier2', 'cashier');
        Auth::actAs($cashier);
        $this->assertThrows(ValidationException::class, fn() => $this->sale([['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1', 'unit_price_paise' => 50000]], [['method' => 'cash', 'amount_paise' => 100000]]), 'approval');
        $inv = $this->makeUser('stock1', 'inventory');
        Auth::actAs($inv);
        $this->assertThrows(HttpException::class, fn() => $this->sale([['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1']], [['method' => 'cash', 'amount_paise' => 100000]]));
    }

    public function testCashSessionRequiredWhenConfigured(): void
    {
        Settings::set('require_cash_session', '1');
        $line = [['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1']];
        $this->assertThrows(ValidationException::class, fn() => $this->sale($line, [['method' => 'cash', 'amount_paise' => 89900]]), 'cash counter');
        $this->openCash(50000);
        $r = $this->sale($line, [['method' => 'cash', 'amount_paise' => 100000]]);
        $sum = (new \App\Services\CashService($this->db))->summary((string)$this->db->value('SELECT cash_session_id FROM sales WHERE id = ?', [$r['sale_id']]));
        $this->assertSame(50000 + 89900, $sum['expected']);
    }

    public function testExpectedTotalMismatchRejected(): void
    {
        $this->assertThrows(ValidationException::class, fn() => $this->sale([['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1']],
            [['method' => 'cash', 'amount_paise' => 100000]], ['expected_total_paise' => 80000]), 'total changed');
    }

    public function testReprintDoesNotCreateSale(): void
    {
        $r = $this->sale([['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1']], [['method' => 'cash', 'amount_paise' => 89900]]);
        $svc = new SaleService($this->db);
        $svc->recordPrint($r['sale_id'], 'initial', '80mm');
        $svc->recordPrint($r['sale_id'], 'reprint', '80mm');
        $svc->recordPrint($r['sale_id'], 'reprint', 'a4');
        $this->assertSame(1, (int)$this->db->value('SELECT COUNT(*) FROM sales'));
        $this->assertSame(3, (int)$this->db->value('SELECT print_count FROM sales WHERE id = ?', [$r['sale_id']]));
        $this->assertSame(2, (int)$this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'sale.reprinted'"));
    }

    public function testVoidRestoresStockAndRefunds(): void
    {
        $r = $this->sale([['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '2']], [['method' => 'cash', 'amount_paise' => 200000]]);
        $this->assertSame(8000, $this->stock($this->v['SH-L-BLUE']));
        (new SaleService($this->db))->void($r['sale_id'], 'Customer changed mind');
        $this->assertSame(10000, $this->stock($this->v['SH-L-BLUE']));
        $this->assertSame('voided', $this->db->value('SELECT status FROM sales WHERE id = ?', [$r['sale_id']]));
        $this->assertSame(179800, (int)$this->db->value("SELECT SUM(amount_paise) FROM refunds"));
        $this->assertThrows(ValidationException::class, fn() => (new SaleService($this->db))->void($r['sale_id'], 'again'));
    }

    public function testAppendOnlyTables(): void
    {
        $this->sale([['variant_id' => $this->v['SH-L-BLUE'], 'qty' => '1']], [['method' => 'cash', 'amount_paise' => 89900]]);
        $this->assertThrows(\PDOException::class, fn() => $this->db->exec('DELETE FROM stock_movements'), 'append-only');
        $this->assertThrows(\PDOException::class, fn() => $this->db->exec("UPDATE audit_logs SET action = 'x'"), 'append-only');
        $this->assertThrows(\PDOException::class, fn() => $this->db->exec("UPDATE sync_outbox SET payload = '{}'"), 'immutable');
        $this->assertThrows(\PDOException::class, fn() => $this->db->exec('DELETE FROM sync_outbox'), 'unacknowledged');
    }
}
