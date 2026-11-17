<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Core\Money;
use App\Core\Qty;
use App\Core\Settings;
use App\Core\ValidationException;

/**
 * Returns, exchanges and voids. Original bills are never edited except for the returned-quantity counter;
 * every return creates a credit-note document (return_no), return_items linked to the original sale_items,
 * stock movements (sellable → MAIN, damaged → DAMAGED) and refund rows, all in one transaction.
 * An exchange = return + replacement sale in the SAME transaction, linked by the exchanges table.
 */
final class ReturnService
{
    public const REFUND_METHODS = ['cash', 'upi', 'card', 'credit'];

    public function __construct(private Database $db)
    {
    }

    /** Lines of a sale with the quantity still returnable. */
    public function eligibility(string $saleId): array
    {
        $sale = $this->db->one('SELECT * FROM sales WHERE id = ?', [$saleId]);
        if (!$sale) {
            throw new ValidationException('Bill not found');
        }
        $items = $this->db->all('SELECT si.*, (si.qty_milli - si.returned_qty_milli) AS returnable_milli FROM sale_items si WHERE si.sale_id = ? ORDER BY line_no', [$saleId]);
        $days = (int)Settings::get('return_window_days', 7);
        $withinWindow = $days <= 0 || strtotime($sale['created_at']) >= strtotime("-$days days");
        $prior = $this->db->all('SELECT r.*, u.full_name AS created_by_name FROM returns r JOIN users u ON u.id = r.created_by WHERE r.original_sale_id = ? ORDER BY r.created_at', [$saleId]);
        return ['sale' => $sale, 'items' => $items, 'within_window' => $withinWindow, 'window_days' => $days, 'prior_returns' => $prior];
    }

    /**
     * @param array $in sale_id, items[{sale_item_id, qty, condition}], reason, refund_method, refund_reference,
     *                  replacements[] (POS cart lines), payments[] (extra due on exchange), approval_token, client_ref
     */
    public function process(array $in): array
    {
        Auth::authorize('return.create');
        $sale = $this->db->one('SELECT * FROM sales WHERE id = ?', [$in['sale_id'] ?? '']);
        if (!$sale) {
            throw new ValidationException('Original bill not found');
        }
        if ($sale['status'] !== 'completed') {
            throw new ValidationException('This bill is ' . $sale['status'] . ' and cannot be returned');
        }
        $reason = trim((string)($in['reason'] ?? ''));
        if ($reason === '') {
            throw new ValidationException('Enter a reason for the return');
        }
        $token = $in['approval_token'] ?? null;
        $approver = null;

        $elig = $this->eligibility($sale['id']);
        if (!$elig['within_window']) {
            if (!Auth::can('return.override_policy')) {
                $approver = Auth::peekApproval($token, 'return.override_policy');
                if (!$approver) {
                    throw new ValidationException("Bill is older than the {$elig['window_days']}-day return window; manager approval needed", ['approval' => 'return.override_policy']);
                }
            }
        }

        $byId = [];
        foreach ($elig['items'] as $it) {
            $byId[$it['id']] = $it;
        }
        $items = [];
        foreach ($in['items'] ?? [] as $r) {
            $si = $byId[$r['sale_item_id'] ?? ''] ?? null;
            if (!$si) {
                throw new ValidationException('Returned item does not belong to this bill');
            }
            $raw = trim((string)($r['qty'] ?? ''));
            if ($raw === '' || $raw === '0') {
                continue;
            }
            $q = Qty::parse($raw, $si['unit']);
            $items[] = ['sale_item_id' => $si['id'], 'qty_milli' => $q, 'condition' => ($r['condition'] ?? 'sellable') === 'damaged' ? 'damaged' : 'sellable'];
        }
        if (!$items) {
            throw new ValidationException('Enter the quantity to return for at least one item');
        }

        $replacements = $in['replacements'] ?? [];
        $refundMethod = (string)($in['refund_method'] ?? 'cash');
        if (!in_array($refundMethod, self::REFUND_METHODS, true)) {
            throw new ValidationException('Choose a valid refund method');
        }
        if ($refundMethod === 'credit' && !$sale['customer_id']) {
            throw new ValidationException('Store credit refund needs a customer on the original bill');
        }

        // Preview the credit value to decide whether a money refund (needing permission) will happen.
        $preview = $this->computeItems($byId, $items);
        $credit = $this->rounded(array_sum(array_column($preview, 'amount_paise')))[0];
        $newTotal = 0;
        if ($replacements) {
            $newTotal = (new SaleService($this->db))->quote(['lines' => $replacements, 'bill_discount' => $in['bill_discount'] ?? [],
                'interstate' => (bool)$sale['is_interstate']])['total_paise'];
        }
        $refundDue = max(0, $credit - $newTotal);
        if ($refundDue > 0 && !Auth::can('return.refund')) {
            $refundApprover = Auth::peekApproval($token, 'return.refund');
            if (!$refundApprover) {
                throw new ValidationException('Refund of ' . Money::format($refundDue) . ' needs manager approval', ['approval' => 'return.refund']);
            }
            $approver = $refundApprover;
        }

        $result = $this->db->transaction(function (Database $db) use ($sale, $items, $reason, $refundMethod, $in, $replacements, $approver) {
            if (!$replacements) {
                $retId = $this->processInTransaction($sale, $items, 'return', $reason, null, $approver, false, $refundMethod, $in['refund_reference'] ?? null);
                $ret = $db->one('SELECT * FROM returns WHERE id = ?', [$retId]);
                return ['return_id' => $retId, 'return_no' => $ret['return_no'], 'refund_paise' => (int)$ret['refund_paise'], 'exchange' => null];
            }
            // exchange: return first (no refund yet), then replacement sale consuming the credit
            $retId = $this->processInTransaction($sale, $items, 'exchange', $reason, [], $approver, false);
            $ret = $db->one('SELECT * FROM returns WHERE id = ?', [$retId]);
            $credit = (int)$ret['total_paise'];
            $sales = new SaleService($db);
            $newQuote = $sales->quote(['lines' => $replacements, 'bill_discount' => $in['bill_discount'] ?? [], 'interstate' => (bool)$sale['is_interstate']]);
            $applied = min($credit, $newQuote['total_paise']);
            $newSale = $sales->checkout([
                'client_ref' => $in['client_ref'] ?? null,
                'lines' => $replacements,
                'bill_discount' => $in['bill_discount'] ?? [],
                'customer_id' => $sale['customer_id'],
                'interstate' => (bool)$sale['is_interstate'],
                'payments' => $in['payments'] ?? [],
                'approval_token' => $in['approval_token'] ?? null,
                'sale_type' => 'exchange',
                'notes' => 'Exchange against ' . $sale['invoice_no'] . ' / ' . $ret['return_no'],
            ], ['exchange_credit_paise' => $applied, 'no_transaction' => true]);

            $refund = $credit - $applied;
            $session = $db->one("SELECT id FROM cash_sessions WHERE device_id = ? AND status = 'open'", [Context::deviceId()]);
            if ($refund > 0) {
                $db->insert('refunds', ['id' => uuid(), 'store_id' => Context::storeId(), 'return_id' => $retId, 'method' => $refundMethod,
                    'amount_paise' => $refund, 'reference' => $in['refund_reference'] ?? null, 'cash_session_id' => $session['id'] ?? null,
                    'approved_by' => $approver ?? Auth::id(), 'created_by' => Auth::id(), 'created_at' => now()]);
            }
            $db->update('returns', ['refund_paise' => $refund, 'exchange_credit_paise' => $applied], 'id = :id', ['id' => $retId]);
            $exId = uuid();
            $db->insert('exchanges', ['id' => $exId, 'store_id' => Context::storeId(), 'return_id' => $retId, 'new_sale_id' => $newSale['sale_id'],
                'original_sale_id' => $sale['id'], 'return_credit_paise' => $credit, 'new_sale_total_paise' => $newQuote['total_paise'],
                'difference_paise' => $newQuote['total_paise'] - $credit, 'created_by' => Auth::id(), 'created_at' => now()]);
            $this->recordOutbox($retId);
            Audit::log('exchange.created', 'exchange', $exId, ['original' => $sale['invoice_no'], 'new_invoice' => $newSale['invoice_no'], 'difference' => $newQuote['total_paise'] - $credit]);
            return ['return_id' => $retId, 'return_no' => $ret['return_no'], 'refund_paise' => $refund,
                'exchange' => ['sale_id' => $newSale['sale_id'], 'invoice_no' => $newSale['invoice_no'], 'difference_paise' => $newQuote['total_paise'] - $credit]];
        });
        if ($token) {
            unset($_SESSION['_approvals'][$token]);   // one-time approval
        }
        return $result;
    }

    /** Proportional amounts for each returned line; the final return of a line takes the exact remainder. */
    private function computeItems(array $byId, array $items): array
    {
        $out = [];
        $pending = [];
        foreach ($items as $r) {
            $si = $byId[$r['sale_item_id']];
            $already = ($pending[$si['id']] ?? 0) + (int)$si['returned_qty_milli'];
            $q = $r['qty_milli'];
            $sold = (int)$si['qty_milli'];
            if ($q + $already > $sold) {
                throw new ValidationException(sprintf('%s: cannot return %s, only %s left on this bill', $si['product_name'],
                    Qty::format($q, $si['unit']), Qty::format($sold - $already, $si['unit'])));
            }
            $pending[$si['id']] = ($pending[$si['id']] ?? 0) + $q;
            if ($q + $already === $sold) {
                $prev = $this->db->one('SELECT IFNULL(SUM(amount_paise),0) a, IFNULL(SUM(taxable_paise),0) t, IFNULL(SUM(cogs_paise),0) c,
                    IFNULL(SUM(cgst_paise),0) cg, IFNULL(SUM(sgst_paise),0) sg, IFNULL(SUM(igst_paise),0) ig FROM return_items WHERE sale_item_id = ?', [$si['id']]);
                $partialSoFar = array_filter($out, fn($o) => $o['sale_item_id'] === $si['id']);
                $pa = array_sum(array_column($partialSoFar, 'amount_paise'));
                $pt = array_sum(array_column($partialSoFar, 'taxable_paise'));
                $pc = array_sum(array_column($partialSoFar, 'cogs_paise'));
                $amount = (int)$si['line_total_paise'] - (int)$prev['a'] - $pa;
                $taxable = (int)$si['taxable_paise'] - (int)$prev['t'] - $pt;
                $cogs = (int)$si['cogs_paise'] - (int)$prev['c'] - $pc;
            } else {
                $amount = Money::divRound((int)$si['line_total_paise'] * $q, $sold);
                $taxable = Money::divRound((int)$si['taxable_paise'] * $q, $sold);
                $cogs = Money::divRound((int)$si['cogs_paise'] * $q, $sold);
            }
            $tax = $amount - $taxable;
            $isIgst = (int)$si['igst_paise'] > 0;
            $out[] = ['sale_item_id' => $si['id'], 'variant_id' => $si['variant_id'], 'qty_milli' => $q, 'condition' => $r['condition'],
                'amount_paise' => $amount, 'taxable_paise' => $taxable, 'tax_paise' => $tax,
                'cgst_paise' => $isIgst ? 0 : intdiv($tax + 1, 2), 'sgst_paise' => $isIgst ? 0 : $tax - intdiv($tax + 1, 2),
                'igst_paise' => $isIgst ? $tax : 0, 'unit_cost_paise' => (int)$si['cost_at_sale_paise'], 'cogs_paise' => $cogs,
                'invoice_item' => $si];
        }
        return $out;
    }

    private function rounded(int $amount): array
    {
        if (Settings::get('round_off', 'rupee') === 'rupee') {
            $r = Money::divRound($amount, 100) * 100;
            return [$r, $r - $amount];
        }
        return [$amount, 0];
    }

    /**
     * Must be called inside a transaction.
     * @param array|null $refunds explicit refund rows [{method, amount_paise}] (void), [] for none (exchange), null = single refund of full value
     */
    public function processInTransaction(array $sale, array $items, string $type, string $reason, ?array $refunds, ?string $approver,
                                         bool $isVoid, string $refundMethod = 'cash', ?string $refundRef = null): string
    {
        $db = $this->db;
        $byId = [];
        foreach ($db->all('SELECT * FROM sale_items WHERE sale_id = ?', [$sale['id']]) as $si) {
            $byId[$si['id']] = $si;
        }
        $lines = $this->computeItems($byId, $items);
        $retId = uuid();
        $retNo = (new DocNumberService($db))->next('CN', (string)Settings::get('return_prefix', 'CN'));
        $session = $db->one("SELECT id FROM cash_sessions WHERE device_id = ? AND status = 'open'", [Context::deviceId()]);
        $sum = fn($k) => array_sum(array_column($lines, $k));
        $exact = $sum('amount_paise');
        [$total, $roundOff] = $isVoid ? [(int)$sale['total_paise'], (int)$sale['total_paise'] - $exact] : $this->rounded($exact);
        $damagedCost = array_sum(array_map(fn($l) => $l['condition'] === 'damaged' ? $l['cogs_paise'] : 0, $lines));

        $db->insert('returns', [
            'id' => $retId, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(), 'return_no' => $retNo,
            'original_sale_id' => $sale['id'], 'customer_id' => $sale['customer_id'], 'return_type' => $type, 'reason' => mb_substr($reason, 0, 300),
            'taxable_paise' => $sum('taxable_paise'), 'cgst_paise' => $sum('cgst_paise'), 'sgst_paise' => $sum('sgst_paise'),
            'igst_paise' => $sum('igst_paise'), 'tax_paise' => $sum('tax_paise'), 'total_paise' => $total, 'round_off_paise' => $roundOff,
            'refund_paise' => 0, 'exchange_credit_paise' => 0, 'cogs_reversed_paise' => $sum('cogs_paise'), 'damaged_cost_paise' => $damagedCost,
            'approved_by' => $approver, 'created_by' => Auth::id(), 'cash_session_id' => $session['id'] ?? null, 'created_at' => now(),
        ]);
        $inv = new InventoryService($db);
        foreach ($lines as $l) {
            $loc = $l['condition'] === 'damaged' ? 'DAMAGED' : 'MAIN';
            $mvType = $isVoid ? 'void' : ($l['condition'] === 'damaged' ? 'damaged_return' : 'sale_return');
            $mv = $inv->move($l['variant_id'], $loc, $l['qty_milli'], $mvType, $l['unit_cost_paise'], 'return', $retId, $retNo);
            $db->insert('return_items', ['id' => uuid(), 'return_id' => $retId, 'sale_item_id' => $l['sale_item_id'], 'variant_id' => $l['variant_id'],
                'qty_milli' => $l['qty_milli'], 'item_condition' => $l['condition'], 'amount_paise' => $l['amount_paise'],
                'taxable_paise' => $l['taxable_paise'], 'tax_paise' => $l['tax_paise'], 'cgst_paise' => $l['cgst_paise'],
                'sgst_paise' => $l['sgst_paise'], 'igst_paise' => $l['igst_paise'], 'unit_cost_paise' => $l['unit_cost_paise'],
                'cogs_paise' => $l['cogs_paise'], 'location_id' => $mv['location_id']]);
            $db->run('UPDATE sale_items SET returned_qty_milli = returned_qty_milli + ? WHERE id = ? AND returned_qty_milli + ? <= qty_milli',
                [$l['qty_milli'], $l['sale_item_id'], $l['qty_milli']]);
        }
        if ($refunds === null) {
            $refunds = $total > 0 ? [['method' => $refundMethod, 'amount_paise' => $total, 'reference' => $refundRef]] : [];
        }
        $refundTotal = 0;
        foreach ($refunds as $r) {
            if ((int)$r['amount_paise'] <= 0) {
                continue;
            }
            $db->insert('refunds', ['id' => uuid(), 'store_id' => Context::storeId(), 'return_id' => $retId, 'method' => $r['method'],
                'amount_paise' => (int)$r['amount_paise'], 'reference' => $r['reference'] ?? null, 'cash_session_id' => $session['id'] ?? null,
                'approved_by' => $approver ?? Auth::id(), 'created_by' => Auth::id(), 'created_at' => now()]);
            $refundTotal += (int)$r['amount_paise'];
        }
        $db->update('returns', ['refund_paise' => $refundTotal], 'id = :id', ['id' => $retId]);
        if ($type === 'return') {
            $this->recordOutbox($retId);
        } elseif ($type === 'void') {
            $this->recordOutbox($retId);
        }
        Audit::log('return.created', 'return', $retId, ['credit_note' => $retNo, 'invoice' => $sale['invoice_no'], 'type' => $type, 'total' => $total]);
        return $retId;
    }

    public function snapshot(string $retId): array
    {
        $r = $this->db->one('SELECT r.*, s.invoice_no AS original_invoice_no, u.full_name AS created_by_name FROM returns r JOIN sales s ON s.id = r.original_sale_id
            JOIN users u ON u.id = r.created_by WHERE r.id = ?', [$retId]) ?? throw new \App\Core\HttpException(404, 'Credit note not found');
        $r['items'] = $this->db->all('SELECT ri.*, si.sku, si.product_name, si.variant_label, si.unit, si.hsn_code, si.tax_rate_bp
            FROM return_items ri JOIN sale_items si ON si.id = ri.sale_item_id WHERE ri.return_id = ?', [$retId]);
        $r['refunds'] = $this->db->all('SELECT * FROM refunds WHERE return_id = ?', [$retId]);
        $r['movements'] = (new InventoryService($this->db))->movementsFor('return', $retId);
        $r['exchange'] = $this->db->one('SELECT * FROM exchanges WHERE return_id = ?', [$retId]);
        return $r;
    }

    private function recordOutbox(string $retId): void
    {
        $snap = $this->snapshot($retId);
        $dep = $this->db->value("SELECT id FROM sync_outbox WHERE entity_type = 'sale' AND entity_id = ? AND op = 'created'", [$snap['original_sale_id']]);
        (new OutboxService($this->db))->record('return', $retId, 'created', $snap, $dep ?: null);
    }
}
