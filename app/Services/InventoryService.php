<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Core\Money;
use App\Core\Qty;
use App\Core\Settings;
use App\Core\ValidationException;

/**
 * The only place stock quantities change. Each call appends an immutable stock_movements row and
 * updates stock_balances in the caller's transaction.
 *
 * Costing: moving weighted-average cost per variant and location.
 *   incoming with cost:  new_avg = (old_qty × old_avg + in_qty × in_cost) / (old_qty + in_qty)
 *                        (if old_qty ≤ 0, new_avg = in_cost)
 *   outgoing:            leaves at current avg; that avg is the cost_at_sale snapshot.
 */
final class InventoryService
{
    public const TYPES = ['opening', 'purchase', 'sale', 'sale_return', 'damaged_return', 'adjustment', 'supplier_return', 'void', 'transfer_in', 'transfer_out'];

    public function __construct(private Database $db)
    {
    }

    /**
     * @param int $qtyMilli signed quantity (positive = stock in)
     * @param int|null $unitCost cost per unit for inbound stock (null = use current average)
     * @return array movement row (includes unit_cost_paise actually used)
     */
    public function move(string $variantId, string $locationCode, int $qtyMilli, string $type, ?int $unitCost,
                         ?string $refType, ?string $refId, ?string $reason = null, bool $allowNegative = false): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown movement type $type");
        }
        if ($qtyMilli === 0) {
            throw new ValidationException('Stock movement quantity cannot be zero');
        }
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Stock movements must run inside a transaction');
        }
        $variant = $this->db->one('SELECT v.id, v.sku, v.cost_paise, p.unit, p.name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?', [$variantId]);
        if (!$variant) {
            throw new ValidationException('Unknown product variant');
        }
        Qty::validate(abs($qtyMilli), $variant['unit']);
        $locationId = Context::locationId($locationCode);

        $bal = $this->db->one('SELECT * FROM stock_balances WHERE variant_id = ? AND location_id = ?', [$variantId, $locationId]);
        if (!$bal) {
            $bal = ['qty_milli' => 0, 'avg_cost_paise' => (int)$variant['cost_paise']];
            $this->db->insert('stock_balances', ['variant_id' => $variantId, 'location_id' => $locationId, 'qty_milli' => 0,
                'avg_cost_paise' => (int)$variant['cost_paise'], 'updated_at' => now()]);
        }
        $oldQty = (int)$bal['qty_milli'];
        $oldAvg = (int)$bal['avg_cost_paise'];
        $newQty = $oldQty + $qtyMilli;

        if ($qtyMilli > 0) {
            $cost = $unitCost ?? $oldAvg;
            if ($cost < 0) {
                throw new ValidationException('Unit cost cannot be negative');
            }
            $newAvg = $oldQty <= 0 ? $cost : Money::divRound($oldQty * $oldAvg + $qtyMilli * $cost, $oldQty + $qtyMilli);
        } else {
            $cost = $oldAvg;
            $newAvg = $oldAvg;
            if ($newQty < 0) {
                $policyAllows = Settings::bool('allow_negative_stock');
                if (!$allowNegative && !$policyAllows) {
                    throw new ValidationException(sprintf('Not enough stock for %s (%s): available %s, required %s',
                        $variant['name'], $variant['sku'], Qty::format(max(0, $oldQty), $variant['unit']), Qty::format(-$qtyMilli, $variant['unit'])));
                }
            }
        }

        $stamp = now();
        $this->db->update('stock_balances', [
            'qty_milli' => $newQty,
            'avg_cost_paise' => $newAvg,
            $qtyMilli > 0 ? 'last_in_at' : 'last_out_at' => $stamp,
            'updated_at' => $stamp,
        ], 'variant_id = :v AND location_id = :l', ['v' => $variantId, 'l' => $locationId]);

        $mv = [
            'id' => uuid(),
            'store_id' => Context::storeId(),
            'device_id' => Context::deviceId(),
            'variant_id' => $variantId,
            'location_id' => $locationId,
            'movement_type' => $type,
            'qty_milli' => $qtyMilli,
            'unit_cost_paise' => $cost,
            'value_paise' => Money::divRound($qtyMilli * $cost, 1000),
            'balance_after_milli' => $newQty,
            'avg_cost_after_paise' => $newAvg,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'reason' => $reason,
            'user_id' => Auth::id(),
            'created_at' => $stamp,
        ];
        $this->db->insert('stock_movements', $mv);
        $mv['location_code'] = $locationCode;   // included in sync payloads
        return $mv;
    }

    public function available(string $variantId, string $locationCode = 'MAIN'): int
    {
        return (int)$this->db->value('SELECT qty_milli FROM stock_balances WHERE variant_id = ? AND location_id = ?',
            [$variantId, Context::locationId($locationCode)]);
    }

    public function movementsFor(string $refType, string $refId): array
    {
        return $this->db->all('SELECT m.*, l.code AS location_code FROM stock_movements m JOIN locations l ON l.id = m.location_id
                               WHERE m.ref_type = ? AND m.ref_id = ? ORDER BY m.created_at, m.id', [$refType, $refId]);
    }

    /**
     * Post a stock adjustment document (opening stock, count corrections, damage, loss).
     * @param array $items each: variant_id, location (MAIN|DAMAGED), qty_change_milli (signed), unit_cost_paise (optional, inbound)
     */
    public function adjust(string $reasonCode, ?string $notes, array $items, ?string $approvedBy = null): string
    {
        $allowed = ['opening', 'count_correction', 'damaged', 'lost', 'found', 'transfer_damaged', 'other'];
        if (!in_array($reasonCode, $allowed, true)) {
            throw new ValidationException('Choose a valid adjustment reason');
        }
        if (!$items) {
            throw new ValidationException('Add at least one item to adjust');
        }
        if ($reasonCode !== 'opening' && trim((string)$notes) === '') {
            throw new ValidationException('A note explaining the adjustment is required');
        }
        return $this->db->transaction(function (Database $db) use ($reasonCode, $notes, $items, $approvedBy) {
            $id = uuid();
            $no = (new DocNumberService($db))->next('ADJ', 'ADJ');
            $db->insert('stock_adjustments', ['id' => $id, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(),
                'adj_no' => $no, 'reason_code' => $reasonCode, 'notes' => $notes, 'created_by' => Auth::id(),
                'approved_by' => $approvedBy, 'created_at' => now()]);
            $movements = [];
            foreach ($items as $it) {
                $q = (int)$it['qty_change_milli'];
                if ($q === 0) {
                    continue;
                }
                $loc = ($it['location'] ?? 'MAIN') === 'DAMAGED' ? 'DAMAGED' : 'MAIN';
                if ($reasonCode === 'transfer_damaged') {
                    // move sellable stock to the damaged location at current average cost
                    $out = $this->move($it['variant_id'], 'MAIN', -abs($q), 'transfer_out', null, 'adjustment', $id, $notes);
                    $in = $this->move($it['variant_id'], 'DAMAGED', abs($q), 'transfer_in', $out['unit_cost_paise'], 'adjustment', $id, $notes);
                    $movements[] = $out;
                    $movements[] = $in;
                    $db->insert('stock_adjustment_items', ['id' => uuid(), 'adjustment_id' => $id, 'variant_id' => $it['variant_id'],
                        'location_id' => Context::locationId('MAIN'), 'qty_change_milli' => -abs($q), 'unit_cost_paise' => $out['unit_cost_paise']]);
                    continue;
                }
                $type = $reasonCode === 'opening' ? 'opening' : 'adjustment';
                $cost = isset($it['unit_cost_paise']) && $it['unit_cost_paise'] !== '' && $it['unit_cost_paise'] !== null ? (int)$it['unit_cost_paise'] : null;
                $mv = $this->move($it['variant_id'], $loc, $q, $type, $q > 0 ? $cost : null, 'adjustment', $id, $notes);
                $movements[] = $mv;
                $db->insert('stock_adjustment_items', ['id' => uuid(), 'adjustment_id' => $id, 'variant_id' => $it['variant_id'],
                    'location_id' => $mv['location_id'], 'qty_change_milli' => $q, 'unit_cost_paise' => $mv['unit_cost_paise']]);
            }
            if (!$movements) {
                throw new ValidationException('All quantities were zero; nothing to adjust');
            }
            $header = $db->one('SELECT * FROM stock_adjustments WHERE id = ?', [$id]);
            (new OutboxService($db))->record('stock_adjustment', $id, 'posted', ['adjustment' => $header, 'movements' => $movements]);
            \App\Core\Audit::log('stock.adjusted', 'stock_adjustment', $id, ['no' => $no, 'reason' => $reasonCode, 'lines' => count($movements)]);
            return $id;
        });
    }
}
