<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\ValidationException;
use App\Services\ReportService;
use App\Services\ReturnService;
use App\Services\SaleService;
use Tests\TestCase;

final class ReturnsTest extends TestCase
{
    private array $v = [];
    private array $sale;

    public function setUp(): void
    {
        parent::setUp();
        [, $this->v] = $this->makeProduct('SHIRT02', 'pcs', [
            ['sku' => 'S2-L-BLUE', 'barcode' => '2100000000017', 'size_id' => $this->sizeId('L'), 'color_id' => $this->colorId('Blue'), 'mrp' => '1199', 'price' => '999', 'cost' => '500', 'opening_qty' => '5'],
            ['sku' => 'S2-XL-BLUE', 'barcode' => '2100000000024', 'size_id' => $this->sizeId('XL'), 'color_id' => $this->colorId('Blue'), 'mrp' => '1199', 'price' => '999', 'cost' => '500', 'opening_qty' => '5'],
            ['sku' => 'S2-XL-BLK', 'barcode' => '2100000000031', 'size_id' => $this->sizeId('XL'), 'color_id' => $this->colorId('Black'), 'mrp' => '1499', 'price' => '1299', 'cost' => '650', 'opening_qty' => '5'],
        ], 'APPAREL');
        $this->sale = (new SaleService($this->db))->checkout(['client_ref' => uuid(),
            'lines' => [['variant_id' => $this->v['S2-XL-BLUE'], 'qty' => '2']], 'payments' => [['method' => 'cash', 'amount_paise' => 199800]]]);
    }

    public function testScenarioDExchangeSizeLinksAndUpdatesBothSkus(): void
    {
        $si = $this->db->one('SELECT * FROM sale_items WHERE sale_id = ?', [$this->sale['sale_id']]);
        $r = (new ReturnService($this->db))->process(['sale_id' => $this->sale['sale_id'], 'reason' => 'Size too big',
            'items' => [['sale_item_id' => $si['id'], 'qty' => '1', 'condition' => 'sellable']],
            'replacements' => [['variant_id' => $this->v['S2-L-BLUE'], 'qty' => '1']], 'client_ref' => uuid()]);
        $this->assertSame(0, $r['exchange']['difference_paise'], 'same price → no balance');
        $this->assertSame(4000, $this->stock($this->v['S2-XL-BLUE']), 'XL: 5 - 2 + 1');
        $this->assertSame(4000, $this->stock($this->v['S2-L-BLUE']), 'L: 5 - 1');
        $this->assertSame(1000, (int)$this->db->value('SELECT returned_qty_milli FROM sale_items WHERE id = ?', [$si['id']]));
        $ex = $this->db->one('SELECT * FROM exchanges');
        $this->assertSame($this->sale['sale_id'], $ex['original_sale_id']);
        $this->assertSame('exchange', $this->db->value('SELECT sale_type FROM sales WHERE id = ?', [$ex['new_sale_id']]));
        $this->assertSame(99900, (int)$this->db->value("SELECT amount_paise FROM payments WHERE sale_id = ? AND method = 'exchange_credit'", [$ex['new_sale_id']]));
        $this->assertSame(0, (int)$this->db->value('SELECT COUNT(*) FROM refunds'));
        // pending sync events: return + replacement sale
        $this->assertSame(1, (int)$this->db->value("SELECT COUNT(*) FROM sync_outbox WHERE entity_type = 'return'"));
        $this->assertSame(2, (int)$this->db->value("SELECT COUNT(*) FROM sync_outbox WHERE entity_type = 'sale'"));
    }

    public function testExchangeForCostlierItemCollectsDifference(): void
    {
        $si = $this->db->one('SELECT * FROM sale_items WHERE sale_id = ?', [$this->sale['sale_id']]);
        $this->assertThrows(ValidationException::class, fn() => (new ReturnService($this->db))->process(['sale_id' => $this->sale['sale_id'], 'reason' => 'Colour',
            'items' => [['sale_item_id' => $si['id'], 'qty' => '1']], 'replacements' => [['variant_id' => $this->v['S2-XL-BLK'], 'qty' => '1']]]), 'short');
        $r = (new ReturnService($this->db))->process(['sale_id' => $this->sale['sale_id'], 'reason' => 'Colour',
            'items' => [['sale_item_id' => $si['id'], 'qty' => '1']], 'replacements' => [['variant_id' => $this->v['S2-XL-BLK'], 'qty' => '1']],
            'payments' => [['method' => 'upi', 'amount_paise' => 30000, 'reference' => 'U1']]]);
        $this->assertSame(30000, $r['exchange']['difference_paise']);
        $this->assertSame(4000, $this->stock($this->v['S2-XL-BLK']));
    }

    public function testCannotReturnMoreThanPurchased(): void
    {
        $si = $this->db->one('SELECT * FROM sale_items WHERE sale_id = ?', [$this->sale['sale_id']]);
        $svc = new ReturnService($this->db);
        $this->assertThrows(ValidationException::class, fn() => $svc->process(['sale_id' => $this->sale['sale_id'], 'reason' => 'x',
            'items' => [['sale_item_id' => $si['id'], 'qty' => '3']]]), 'only');
        $svc->process(['sale_id' => $this->sale['sale_id'], 'reason' => 'x', 'items' => [['sale_item_id' => $si['id'], 'qty' => '1']]]);
        $svc->process(['sale_id' => $this->sale['sale_id'], 'reason' => 'x', 'items' => [['sale_item_id' => $si['id'], 'qty' => '1', 'condition' => 'damaged']]]);
        $this->assertThrows(ValidationException::class, fn() => $svc->process(['sale_id' => $this->sale['sale_id'], 'reason' => 'x',
            'items' => [['sale_item_id' => $si['id'], 'qty' => '1']]]), 'only');
        // full value refunded exactly (last return takes the remainder)
        $this->assertSame(199800, (int)$this->db->value('SELECT SUM(amount_paise) FROM return_items'));
        $this->assertSame((int)$si['cogs_paise'], (int)$this->db->value('SELECT SUM(cogs_paise) FROM return_items'));
        $this->assertSame(4000, $this->stock($this->v['S2-XL-BLUE']), 'one sellable back');
        $this->assertSame(1000, $this->stock($this->v['S2-XL-BLUE'], 'DAMAGED'), 'damaged goes to DAMAGED location');
    }

    public function testCashierRefundNeedsApproval(): void
    {
        $cashier = $this->makeUser('cash1', 'cashier');
        $this->makeUser('boss', 'manager', 'Boss12345');
        Auth::actAs($cashier);
        $si = $this->db->one('SELECT * FROM sale_items WHERE sale_id = ?', [$this->sale['sale_id']]);
        $in = ['sale_id' => $this->sale['sale_id'], 'reason' => 'Defect', 'items' => [['sale_item_id' => $si['id'], 'qty' => '1']]];
        $e = $this->assertThrows(ValidationException::class, fn() => (new ReturnService($this->db))->process($in), 'approval');
        $this->assertSame('return.refund', $e->errors['approval']);
        $a = Auth::grantApproval('boss', 'Boss12345', 'return.refund', '127.0.0.1');
        $r = (new ReturnService($this->db))->process($in + ['approval_token' => $a['token']]);
        $this->assertSame(99900, $r['refund_paise']);
        $this->assertSame((string)$this->db->value("SELECT id FROM users WHERE username='boss'"), (string)$this->db->value('SELECT approved_by FROM returns'));
    }

    public function testScenarioFAndIReportsReconcile(): void
    {
        // purchase 10 more of S2-L-BLUE at a different cost → weighted average
        $supplierId = uuid();
        $this->db->insert('suppliers', ['id' => $supplierId, 'store_id' => \App\Core\Context::storeId(), 'name' => 'Erode Mills', 'created_at' => now(), 'updated_at' => now()]);
        $ps = new \App\Services\PurchaseService($this->db);
        $pid = $ps->saveDraft(null, ['supplier_id' => $supplierId, 'supplier_invoice_no' => 'EM-1', 'tax_claimable' => 1, 'freight' => '100'],
            [['variant_id' => $this->v['S2-L-BLUE'], 'qty' => '10', 'unit_cost' => '590', 'tax_pct' => '5']]);
        $this->assertSame(5000, $this->stock($this->v['S2-L-BLUE']), 'draft does not move stock');
        $ps->post($pid);
        $this->assertSame(15000, $this->stock($this->v['S2-L-BLUE']));
        // landed = 590 + 100/10 = 600 ; avg = (5×500 + 10×600)/15 = 566.67
        $this->assertSame(56667, (int)$this->db->value('SELECT avg_cost_paise FROM stock_balances WHERE variant_id = ? AND location_id = ?', [$this->v['S2-L-BLUE'], \App\Core\Context::locationId()]));
        $this->assertThrows(ValidationException::class, fn() => $ps->saveDraft(null, ['supplier_id' => $supplierId, 'supplier_invoice_no' => 'EM-1'], [['variant_id' => $this->v['S2-L-BLUE'], 'qty' => '1', 'unit_cost' => '1']]), 'already');

        $s2 = (new SaleService($this->db))->checkout(['client_ref' => uuid(), 'lines' => [['variant_id' => $this->v['S2-L-BLUE'], 'qty' => '3', 'disc_type' => 'amount', 'disc_value' => 29700]],
            'payments' => [['method' => 'card', 'amount_paise' => 270000, 'reference' => 'APPR77']]]);
        $this->assertSame(270000, (int)$this->db->value('SELECT total_paise FROM sales WHERE id = ?', [$s2['sale_id']]));
        $this->assertSame(170001, (int)$this->db->value('SELECT cogs_paise FROM sales WHERE id = ?', [$s2['sale_id']]), 'snapshot 566.67 × 3');
        // editing cost later must not change historical COGS
        $this->db->update('product_variants', ['cost_paise' => 99900], 'id = :id', ['id' => $this->v['S2-L-BLUE']]);
        $si = $this->db->one('SELECT * FROM sale_items WHERE sale_id = ?', [$this->sale['sale_id']]);
        (new ReturnService($this->db))->process(['sale_id' => $this->sale['sale_id'], 'reason' => 'x', 'items' => [['sale_item_id' => $si['id'], 'qty' => '1']]]);

        $rep = new ReportService($this->db);
        $p = $rep->profit(today() . ' 00:00:00', today() . ' 23:59:59');
        $salesTaxable = (int)$this->db->value("SELECT SUM(taxable_paise) FROM sale_items");
        $retTaxable = (int)$this->db->value('SELECT SUM(taxable_paise) FROM return_items');
        $this->assertSame($salesTaxable - $retTaxable, $p['netSales']);
        $this->assertSame(100000 + 170001 - 50000, $p['cogs'], 'COGS = 2×500 + 3×566.67 − 1×500');
        $this->assertSame($p['netSales'] - $p['cogs'], $p['gross']);

        $pay = $rep->run('payments', today(), today());
        $byMethod = array_column($pay['rows'], null, 'method');
        $this->assertSame(199800, $byMethod['Cash']['received']);
        $this->assertSame(99900, $byMethod['Cash']['refunded']);
        $this->assertSame(270000, $byMethod['Card']['received']);
        $daily = $rep->run('sales_summary', today(), today());
        $this->assertSame((int)$this->db->value("SELECT SUM(total_paise) FROM sales WHERE status='completed'"), $daily['totals']['total']);
        $this->assertSame(99900, $daily['totals']['returns']);
        // stock movements reconcile with balances
        foreach ($this->v as $vid) {
            $this->assertSame((int)$this->db->value('SELECT IFNULL(SUM(qty_milli),0) FROM stock_movements WHERE variant_id = ?', [$vid]),
                (int)$this->db->value('SELECT IFNULL(SUM(qty_milli),0) FROM stock_balances WHERE variant_id = ?', [$vid]), 'movements = balances');
        }
    }
}
