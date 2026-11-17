<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Core\Money;
use App\Core\Qty;
use App\Core\ValidationException;

/**
 * Purchase invoices / GRN. Drafts can be edited; posting is atomic and increases stock at landed cost
 * (unit cost + freight allocated by value, + input tax when it is NOT claimable), updating moving-average cost.
 */
final class PurchaseService
{
    public function __construct(private Database $db)
    {
    }

    /** @param array $items [{variant_id, qty, unit_cost, tax_pct}] */
    public function saveDraft(?string $id, array $h, array $items): string
    {
        $supplierId = (string)($h['supplier_id'] ?? '');
        if (!$this->db->value('SELECT 1 FROM suppliers WHERE id = ?', [$supplierId])) {
            throw new ValidationException('Choose a supplier', ['supplier_id' => 'Required']);
        }
        $lines = [];
        foreach ($items as $i => $it) {
            if (empty($it['variant_id'])) {
                continue;
            }
            $v = $this->db->one('SELECT v.id, p.unit, p.name, v.sku FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?', [$it['variant_id']]);
            if (!$v) {
                throw new ValidationException('Line ' . ($i + 1) . ': unknown item');
            }
            try {
                $qty = Qty::parse((string)($it['qty'] ?? ''), $v['unit']);
                $cost = Money::parse((string)($it['unit_cost'] ?? ''));
                $taxBp = Money::parsePercentBp((string)($it['tax_pct'] ?? '0'));
            } catch (\InvalidArgumentException $e) {
                throw new ValidationException("{$v['sku']}: " . $e->getMessage());
            }
            if ($cost < 0 || $taxBp > 10000) {
                throw new ValidationException("{$v['sku']}: invalid cost or tax");
            }
            $sub = Qty::amount($cost, $qty);
            $lines[] = ['variant_id' => $v['id'], 'qty_milli' => $qty, 'unit_cost_paise' => $cost, 'tax_bp' => $taxBp,
                'line_subtotal_paise' => $sub, 'tax_paise' => Money::percent($sub, $taxBp)];
        }
        if (!$lines) {
            throw new ValidationException('Add at least one item');
        }
        $freight = Money::parse((string)($h['freight'] ?? '0'));
        $paid = Money::parse((string)($h['amount_paid'] ?? '0'));
        $sub = array_sum(array_column($lines, 'line_subtotal_paise'));
        $tax = array_sum(array_column($lines, 'tax_paise'));
        $row = [
            'supplier_id' => $supplierId,
            'supplier_invoice_no' => trim((string)($h['supplier_invoice_no'] ?? '')) ?: null,
            'supplier_invoice_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($h['supplier_invoice_date'] ?? '')) ? $h['supplier_invoice_date'] : null,
            'tax_claimable' => !empty($h['tax_claimable']) ? 1 : 0,
            'subtotal_paise' => $sub, 'tax_paise' => $tax, 'freight_paise' => $freight, 'total_paise' => $sub + $tax + $freight,
            'amount_paid_paise' => $paid, 'notes' => trim((string)($h['notes'] ?? '')) ?: null, 'updated_at' => now(),
        ];
        return $this->db->transaction(function (Database $db) use ($id, $row, $lines) {
            if ($id) {
                $st = $db->value('SELECT status FROM purchases WHERE id = ?', [$id]);
                if ($st !== 'draft') {
                    throw new ValidationException('Only draft purchases can be edited');
                }
                $db->update('purchases', $row, 'id = :id', ['id' => $id]);
                $db->run('DELETE FROM purchase_items WHERE purchase_id = ?', [$id]);
            } else {
                $id = uuid();
                try {
                    $db->insert('purchases', $row + ['id' => $id, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(),
                        'purchase_no' => (new DocNumberService($db))->next('PO', 'PO'), 'status' => 'draft', 'created_by' => Auth::id(), 'created_at' => now()]);
                } catch (\PDOException $e) {
                    if (str_contains($e->getMessage(), 'UNIQUE')) {
                        throw new ValidationException('This supplier invoice number is already entered');
                    }
                    throw $e;
                }
            }
            foreach ($lines as $l) {
                $db->insert('purchase_items', $l + ['id' => uuid(), 'purchase_id' => $id, 'landed_unit_cost_paise' => 0]);
            }
            Audit::log('purchase.saved', 'purchase', $id);
            return $id;
        });
    }

    public function post(string $id): void
    {
        $this->db->transaction(function (Database $db) use ($id) {
            $p = $db->one('SELECT * FROM purchases WHERE id = ?', [$id]);
            if (!$p || $p['status'] !== 'draft') {
                throw new ValidationException('Only draft purchases can be posted');
            }
            $items = $db->all('SELECT pi.*, v.cost_paise, v.mrp_paise, v.price_paise FROM purchase_items pi JOIN product_variants v ON v.id = pi.variant_id WHERE purchase_id = ?', [$id]);
            $alloc = Money::allocate((int)$p['freight_paise'], array_map(fn($i) => (int)$i['line_subtotal_paise'], $items));
            $inv = new InventoryService($db);
            $cat = new CatalogService($db);
            $movements = [];
            foreach ($items as $k => $it) {
                $lineCost = (int)$it['line_subtotal_paise'] + $alloc[$k] + ((int)$p['tax_claimable'] ? 0 : (int)$it['tax_paise']);
                $landed = Money::divRound($lineCost * 1000, (int)$it['qty_milli']);
                $db->update('purchase_items', ['landed_unit_cost_paise' => $landed], 'id = :id', ['id' => $it['id']]);
                $movements[] = $inv->move($it['variant_id'], 'MAIN', (int)$it['qty_milli'], 'purchase', $landed, 'purchase', $id, $p['purchase_no']);
                if ($landed !== (int)$it['cost_paise']) {
                    $cat->recordPriceHistory($it['variant_id'], $it, (int)$it['mrp_paise'], (int)$it['price_paise'], $landed, 'purchase');
                    $db->update('product_variants', ['cost_paise' => $landed, 'updated_at' => now()], 'id = :id', ['id' => $it['variant_id']]);
                }
            }
            $db->update('purchases', ['status' => 'posted', 'posted_by' => Auth::id(), 'posted_at' => now(), 'updated_at' => now()], 'id = :id', ['id' => $id]);
            $snap = $db->one('SELECT * FROM purchases WHERE id = ?', [$id]);
            $snap['items'] = $db->all('SELECT * FROM purchase_items WHERE purchase_id = ?', [$id]);
            $snap['movements'] = $movements;
            $snap['supplier'] = $db->one('SELECT * FROM suppliers WHERE id = ?', [$p['supplier_id']]);
            (new OutboxService($db))->record('purchase', $id, 'posted', $snap);
            Audit::log('purchase.posted', 'purchase', $id, ['no' => $p['purchase_no'], 'total' => $p['total_paise']]);
        });
    }

    public function cancelDraft(string $id): void
    {
        $n = $this->db->update('purchases', ['status' => 'cancelled', 'updated_at' => now()], "id = :id AND status = 'draft'", ['id' => $id]);
        if (!$n) {
            throw new ValidationException('Only drafts can be cancelled');
        }
        Audit::log('purchase.cancelled', 'purchase', $id);
    }

    /** Return goods to a supplier (reduces stock at current average cost). */
    public function supplierReturn(string $supplierId, ?string $purchaseId, string $reason, array $items): string
    {
        if (!$this->db->value('SELECT 1 FROM suppliers WHERE id = ?', [$supplierId])) {
            throw new ValidationException('Choose a supplier');
        }
        if (trim($reason) === '') {
            throw new ValidationException('Enter a reason');
        }
        return $this->db->transaction(function (Database $db) use ($supplierId, $purchaseId, $reason, $items) {
            $id = uuid();
            $no = (new DocNumberService($db))->next('SR', 'SR');
            $db->insert('supplier_returns', ['id' => $id, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(), 'return_no' => $no,
                'supplier_id' => $supplierId, 'purchase_id' => $purchaseId ?: null, 'reason' => $reason, 'total_paise' => 0,
                'created_by' => Auth::id(), 'created_at' => now()]);
            $inv = new InventoryService($db);
            $total = 0;
            $movements = [];
            foreach ($items as $it) {
                if (empty($it['variant_id']) || trim((string)($it['qty'] ?? '')) === '') {
                    continue;
                }
                $unit = (string)$db->value('SELECT p.unit FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?', [$it['variant_id']]);
                $q = Qty::parse((string)$it['qty'], $unit ?: 'pcs');
                $loc = ($it['location'] ?? 'MAIN') === 'DAMAGED' ? 'DAMAGED' : 'MAIN';
                $mv = $inv->move($it['variant_id'], $loc, -$q, 'supplier_return', null, 'supplier_return', $id, $no);
                $movements[] = $mv;
                $line = -(int)$mv['value_paise'];
                $total += $line;
                $db->insert('supplier_return_items', ['id' => uuid(), 'supplier_return_id' => $id, 'variant_id' => $it['variant_id'],
                    'location_id' => $mv['location_id'], 'qty_milli' => $q, 'unit_cost_paise' => $mv['unit_cost_paise'], 'line_total_paise' => $line]);
            }
            if (!$movements) {
                throw new ValidationException('Add at least one item to return');
            }
            $db->update('supplier_returns', ['total_paise' => $total], 'id = :id', ['id' => $id]);
            $snap = $db->one('SELECT * FROM supplier_returns WHERE id = ?', [$id]);
            $snap['items'] = $db->all('SELECT * FROM supplier_return_items WHERE supplier_return_id = ?', [$id]);
            $snap['movements'] = $movements;
            (new OutboxService($db))->record('supplier_return', $id, 'created', $snap);
            Audit::log('supplier_return.created', 'supplier_return', $id, ['no' => $no, 'total' => $total]);
            return $id;
        });
    }
}
