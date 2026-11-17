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
 * Checkout. A completed bill is written in ONE SQLite transaction:
 *   sales + sale_items (with cost_at_sale snapshot) + payments + stock_movements/balances + sync_outbox event + audit.
 * Printing happens only after commit; reprints never create a new sale.
 * The POS sends a client_ref (UUID per bill attempt) so a double-click / retry returns the same saved bill.
 */
final class SaleService
{
    public const PAYMENT_METHODS = ['cash', 'upi', 'card', 'credit', 'other'];

    public function __construct(private Database $db)
    {
    }

    /** Resolve POS cart lines into pricing input (prices from DB unless an authorised override is supplied). */
    public function buildLines(array $cartLines, bool $allowOverride): array
    {
        if (!$cartLines) {
            throw new ValidationException('The bill has no items');
        }
        if (count($cartLines) > 300) {
            throw new ValidationException('Too many lines on one bill');
        }
        $variants = (new CatalogService($this->db))->variantsByIds(array_column($cartLines, 'variant_id'));
        $lines = [];
        foreach (array_values($cartLines) as $i => $c) {
            $v = $variants[$c['variant_id'] ?? ''] ?? null;
            if (!$v) {
                throw new ValidationException('Line ' . ($i + 1) . ': product not found');
            }
            if (!$v['sellable']) {
                throw new ValidationException("{$v['name']} ({$v['sku']}) is archived and cannot be sold");
            }
            try {
                $qty = is_int($c['qty_milli'] ?? null) ? $c['qty_milli'] : Qty::parse((string)($c['qty'] ?? ''), $v['unit']);
                Qty::validate($qty, $v['unit']);
            } catch (\InvalidArgumentException $e) {
                throw new ValidationException("{$v['name']}: " . $e->getMessage());
            }
            $price = (int)$v['price_paise'];
            $overridden = false;
            if (isset($c['unit_price_paise']) && $c['unit_price_paise'] !== null && (int)$c['unit_price_paise'] !== $price) {
                if (!$allowOverride) {
                    throw new ValidationException('Changing the price needs manager approval');
                }
                $price = (int)$c['unit_price_paise'];
                if ($price > (int)$v['mrp_paise']) {
                    throw new ValidationException("{$v['name']}: price cannot be above MRP");
                }
                $overridden = true;
            }
            $dt = $c['disc_type'] ?? null;
            $lines[] = [
                'variant_id' => $v['id'], 'product_id' => $v['product_id'], 'sku' => $v['sku'], 'barcode' => $v['barcode'],
                'name' => $v['name'], 'label' => $v['label'], 'hsn_code' => $v['hsn_code'], 'unit' => $v['unit'],
                'qty_milli' => $qty, 'unit_price_paise' => $price, 'mrp_paise' => (int)$v['mrp_paise'],
                'discount_allowed' => (int)$v['discount_allowed'], 'rate_bp' => (int)$v['rate_bp'],
                'threshold_paise' => $v['threshold_paise'] !== null ? (int)$v['threshold_paise'] : null,
                'above_rate_bp' => $v['above_rate_bp'] !== null ? (int)$v['above_rate_bp'] : null,
                'disc_type' => in_array($dt, ['amount', 'percent'], true) ? $dt : null,
                'disc_value' => (int)($c['disc_value'] ?? 0),
                'stock_milli' => (int)$v['stock_milli'], 'price_overridden' => $overridden,
            ];
        }
        return $lines;
    }

    public function quote(array $input): array
    {
        $lines = $this->buildLines($input['lines'] ?? [], true);
        $q = PricingService::calculate($lines, $this->pricingOpts($input));
        $q['needs_approval'] = $q['discount_bp'] > Auth::maxDiscountBp() && !Auth::can('pos.discount_override');
        $q['price_override_needs_approval'] = !Auth::can('pos.price_override') && (bool)array_filter($lines, fn($l) => $l['price_overridden']);
        return $q;
    }

    private function pricingOpts(array $input): array
    {
        $bd = $input['bill_discount'] ?? [];
        return [
            'inclusive' => Settings::bool('prices_include_tax'),
            'interstate' => !empty($input['interstate']),
            'bill_disc_type' => in_array($bd['type'] ?? null, ['amount', 'percent'], true) ? $bd['type'] : null,
            'bill_disc_value' => (int)($bd['value'] ?? 0),
            'round_off' => Settings::get('round_off', 'rupee'),
        ];
    }

    /**
     * @param array $input client_ref, lines[], bill_discount{type,value}, customer_id, interstate, payments[{method, amount_paise, reference}],
     *                     approval_token, expected_total_paise, notes, sale_type
     * @param array $extra internal options: exchange_credit_paise (from ReturnService), no_transaction
     */
    public function checkout(array $input, array $extra = []): array
    {
        Auth::authorize('pos.sell');
        $clientRef = isset($input['client_ref']) ? substr((string)$input['client_ref'], 0, 64) : null;
        if ($clientRef) {
            $existing = $this->db->one('SELECT id, invoice_no FROM sales WHERE client_ref = ?', [$clientRef]);
            if ($existing) {
                return ['sale_id' => $existing['id'], 'invoice_no' => $existing['invoice_no'], 'duplicate' => true];
            }
        }
        $approvalToken = $input['approval_token'] ?? null;
        $approverId = null;
        $needsApproval = [];

        $canOverride = Auth::can('pos.price_override');
        if (!$canOverride && Auth::peekApproval($approvalToken, 'pos.discount_override')) {
            $canOverride = true;
            $needsApproval['price'] = true;
        }
        $lines = $this->buildLines($input['lines'] ?? [], $canOverride);
        $q = PricingService::calculate($lines, $this->pricingOpts($input));

        if ($q['discount_paise'] > 0 && !Auth::can('pos.discount')) {
            $needsApproval['discount'] = true;
        }
        if ($q['discount_bp'] > Auth::maxDiscountBp() && !Auth::can('pos.discount_override')) {
            $needsApproval['discount'] = true;
        }
        if ($needsApproval) {
            $approverId = Auth::peekApproval($approvalToken, 'pos.discount_override');
            if (!$approverId) {
                throw new ValidationException('This discount/price needs manager approval', ['approval' => 'pos.discount_override']);
            }
            if ($q['discount_bp'] > Auth::maxDiscountBp($approverId)) {
                throw new ValidationException('Discount exceeds the approver\'s limit of ' . bp_pct(Auth::maxDiscountBp($approverId)));
            }
        }
        if (isset($input['expected_total_paise']) && (int)$input['expected_total_paise'] !== $q['total_paise']) {
            throw new ValidationException('Bill total changed (prices updated?). Please review the bill: new total ' . Money::format($q['total_paise']));
        }

        // customer
        $customer = null;
        if (!empty($input['customer_id'])) {
            $customer = $this->db->one('SELECT * FROM customers WHERE id = ? AND is_active = 1', [$input['customer_id']]);
            if (!$customer) {
                throw new ValidationException('Customer not found');
            }
        } elseif (Settings::bool('require_customer')) {
            throw new ValidationException('Customer mobile number is required for every bill (shop setting)');
        }

        $exchangeCredit = (int)($extra['exchange_credit_paise'] ?? 0);
        $pay = $this->settlePayments($input['payments'] ?? [], $q['total_paise'], $exchangeCredit, $customer, $approvalToken);

        $session = $this->db->one("SELECT * FROM cash_sessions WHERE device_id = ? AND status = 'open'", [Context::deviceId()]);
        if (!$session && Settings::bool('require_cash_session')) {
            throw new ValidationException('Open the cash counter (opening cash) before billing', ['cash_session' => 'required']);
        }

        // Overselling requires both the permission AND an explicit per-bill confirmation from the cashier screen.
        $allowNegative = !empty($input['allow_oversell']) && Auth::can('pos.oversell');

        $work = function (Database $db) use ($q, $input, $customer, $pay, $session, $approverId, $needsApproval, $clientRef, $allowNegative, $approvalToken) {
            if ($needsApproval) {
                Auth::consumeApproval($approvalToken, 'pos.discount_override');
            }
            $saleId = uuid();
            $invoiceNo = (new DocNumberService($db))->next('INV', (string)Settings::get('invoice_prefix', 'TX'));
            $stamp = now();
            $inv = new InventoryService($db);
            $cogs = 0;
            $items = [];
            $movements = [];

            // header first (FK target), totals that depend on cost are updated after lines
            $sale = [
                'id' => $saleId, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(),
                'invoice_no' => $invoiceNo, 'fy' => Context::fyCode(), 'status' => 'completed',
                'sale_type' => ($input['sale_type'] ?? 'sale') === 'exchange' ? 'exchange' : 'sale',
                'customer_id' => $customer['id'] ?? null, 'customer_name' => $customer['name'] ?? null,
                'customer_phone' => $customer['phone'] ?? null, 'customer_gstin' => $customer['gstin'] ?? null,
                'is_interstate' => $q['interstate'] ? 1 : 0, 'prices_include_tax' => $q['inclusive'] ? 1 : 0,
                'cashier_id' => Auth::id(), 'cash_session_id' => $session['id'] ?? null,
                'items_count' => $q['items_count'], 'qty_total_milli' => $q['qty_total_milli'],
                'gross_paise' => $q['gross_paise'], 'line_discount_paise' => $q['line_discount_paise'],
                'bill_discount_type' => $input['bill_discount']['type'] ?? null,
                'bill_discount_value' => isset($input['bill_discount']['value']) ? (int)$input['bill_discount']['value'] : null,
                'bill_discount_paise' => $q['bill_discount_paise'], 'taxable_paise' => $q['taxable_paise'],
                'cgst_paise' => $q['cgst_paise'], 'sgst_paise' => $q['sgst_paise'], 'igst_paise' => $q['igst_paise'],
                'tax_paise' => $q['tax_paise'], 'round_off_paise' => $q['round_off_paise'], 'total_paise' => $q['total_paise'],
                'paid_paise' => $pay['paid'], 'change_paise' => $pay['change'], 'credit_paise' => $pay['credit'],
                'cogs_paise' => 0, 'approved_by' => $approverId,
                'notes' => isset($input['notes']) ? mb_substr((string)$input['notes'], 0, 500) : null,
                'client_ref' => $clientRef, 'print_count' => 0, 'created_at' => $stamp, 'updated_at' => $stamp,
            ];
            $db->insert('sales', $sale);

            foreach ($q['lines'] as $l) {
                $itemId = uuid();
                $mv = $inv->move($l['variant_id'], 'MAIN', -$l['qty_milli'], 'sale', null, 'sale', $saleId, $invoiceNo, $allowNegative);
                if ($mv['balance_after_milli'] < 0) {
                    Audit::log('stock.oversold', 'variant', $l['variant_id'], ['invoice' => $invoiceNo, 'balance' => $mv['balance_after_milli']]);
                }
                $movements[] = $mv;
                $lineCogs = Qty::amount((int)$mv['unit_cost_paise'], $l['qty_milli']);
                $cogs += $lineCogs;
                $item = [
                    'id' => $itemId, 'sale_id' => $saleId, 'line_no' => $l['line_no'], 'variant_id' => $l['variant_id'],
                    'product_id' => $l['product_id'], 'sku' => $l['sku'], 'barcode' => $l['barcode'], 'product_name' => $l['name'],
                    'variant_label' => $l['label'], 'hsn_code' => $l['hsn_code'], 'unit' => $l['unit'], 'qty_milli' => $l['qty_milli'],
                    'mrp_paise' => $l['mrp_paise'], 'unit_price_paise' => $l['unit_price_paise'], 'gross_paise' => $l['gross_paise'],
                    'line_discount_type' => $l['disc_type'], 'line_discount_value' => $l['disc_type'] ? $l['disc_value'] : null,
                    'line_discount_paise' => $l['line_discount_paise'], 'bill_discount_alloc_paise' => $l['bill_discount_alloc_paise'],
                    'net_paise' => $l['net_paise'], 'taxable_paise' => $l['taxable_paise'], 'tax_rate_bp' => $l['tax_rate_bp'],
                    'cgst_paise' => $l['cgst_paise'], 'sgst_paise' => $l['sgst_paise'], 'igst_paise' => $l['igst_paise'],
                    'tax_paise' => $l['tax_paise'], 'line_total_paise' => $l['line_total_paise'],
                    'cost_at_sale_paise' => (int)$mv['unit_cost_paise'], 'cogs_paise' => $lineCogs, 'returned_qty_milli' => 0,
                ];
                $db->insert('sale_items', $item);
                $items[] = $item;
            }
            $db->update('sales', ['cogs_paise' => $cogs], 'id = :id', ['id' => $saleId]);

            $payRows = [];
            foreach ($pay['rows'] as $p) {
                $row = ['id' => uuid(), 'store_id' => Context::storeId(), 'sale_id' => $saleId, 'method' => $p['method'],
                    'amount_paise' => $p['amount_paise'], 'tendered_paise' => $p['tendered_paise'] ?? null,
                    'change_paise' => $p['change_paise'] ?? 0, 'reference' => $p['reference'] ?? null,
                    'cash_session_id' => $session['id'] ?? null, 'user_id' => Auth::id(), 'created_at' => $stamp];
                $db->insert('payments', $row);
                $payRows[] = $row;
            }

            $snapshot = $this->snapshot($saleId);
            $depends = null;
            if ($customer) {
                $depends = $db->value("SELECT id FROM sync_outbox WHERE entity_type = 'customer' AND entity_id = ? ORDER BY seq DESC LIMIT 1", [$customer['id']]);
            }
            (new OutboxService($db))->record('sale', $saleId, 'created', $snapshot, $depends ?: null);
            Audit::log('sale.created', 'sale', $saleId, ['invoice' => $invoiceNo, 'total' => $q['total_paise'], 'approved_by' => $approverId]);
            return ['sale_id' => $saleId, 'invoice_no' => $invoiceNo, 'total_paise' => $q['total_paise'],
                'change_paise' => $pay['change'], 'duplicate' => false];
        };

        try {
            return !empty($extra['no_transaction']) ? $work($this->db) : $this->db->transaction($work);
        } catch (\PDOException $e) {
            if ($clientRef && str_contains($e->getMessage(), 'client_ref')) {
                $existing = $this->db->one('SELECT id, invoice_no FROM sales WHERE client_ref = ?', [$clientRef]);
                if ($existing) {
                    return ['sale_id' => $existing['id'], 'invoice_no' => $existing['invoice_no'], 'duplicate' => true];
                }
            }
            throw $e;
        }
    }

    /**
     * Validate tender. Non-cash methods may not exceed what is due; cash may exceed (change is returned).
     * Returns rows with amount_paise = amount applied to the bill.
     */
    public function settlePayments(array $payments, int $total, int $exchangeCredit, ?array $customer, ?string $approvalToken): array
    {
        $enabled = array_filter(array_map('trim', explode(',', (string)Settings::get('payment_methods', 'cash,upi,card,credit'))));
        $due = $total - $exchangeCredit;
        $rows = [];
        if ($exchangeCredit > 0) {
            $rows[] = ['method' => 'exchange_credit', 'amount_paise' => min($exchangeCredit, $total), 'reference' => null];
        }
        $nonCash = 0;
        $cashTendered = 0;
        $credit = 0;
        foreach ($payments as $p) {
            $m = (string)($p['method'] ?? '');
            $amt = (int)($p['amount_paise'] ?? 0);
            if ($amt <= 0) {
                continue;
            }
            if (!in_array($m, self::PAYMENT_METHODS, true) || !in_array($m, $enabled, true)) {
                throw new ValidationException("Payment method '$m' is not enabled");
            }
            $ref = isset($p['reference']) ? substr(preg_replace('/[^A-Za-z0-9 \/._-]/', '', (string)$p['reference']), 0, 40) : null;
            if ($m === 'card' && $ref && preg_match('/\d{12,19}/', str_replace(' ', '', $ref))) {
                throw new ValidationException('Do not enter card numbers. Enter only the approval/transaction reference.');
            }
            if ($m === 'cash') {
                $cashTendered += $amt;
                continue;
            }
            if ($m === 'credit') {
                if (!$customer) {
                    throw new ValidationException('Credit sale needs a customer');
                }
                if (!Auth::can('pos.credit_sale') && !Auth::peekApproval($approvalToken, 'pos.credit_sale')) {
                    throw new ValidationException('Credit sale needs manager approval', ['approval' => 'pos.credit_sale']);
                }
                $credit += $amt;
            }
            $nonCash += $amt;
            $rows[] = ['method' => $m, 'amount_paise' => $amt, 'reference' => $ref ?: null];
        }
        if ($due < 0) {
            throw new ValidationException('Exchange credit exceeds the bill; use a refund instead');
        }
        if ($nonCash > $due) {
            throw new ValidationException('Card/UPI/credit amounts exceed the amount due (' . Money::format($due) . ')');
        }
        $cashDue = $due - $nonCash;
        if ($cashTendered < $cashDue) {
            throw new ValidationException('Payment is short by ' . Money::format($cashDue - $cashTendered));
        }
        $change = 0;
        if ($cashTendered > 0) {
            if ($cashDue === 0) {
                throw new ValidationException('Cash is not needed — the bill is already fully paid');
            }
            $change = $cashTendered - $cashDue;
            $rows[] = ['method' => 'cash', 'amount_paise' => $cashDue, 'tendered_paise' => $cashTendered, 'change_paise' => $change, 'reference' => null];
        }
        return ['rows' => $rows, 'paid' => $total, 'change' => $change, 'credit' => $credit];
    }

    /** Full sale document used for receipts, API and sync payloads. */
    public function snapshot(string $saleId): array
    {
        $s = $this->db->one('SELECT s.*, u.full_name AS cashier_name FROM sales s JOIN users u ON u.id = s.cashier_id WHERE s.id = ?', [$saleId]);
        if (!$s) {
            throw new ValidationException('Bill not found');
        }
        $s['items'] = $this->db->all('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY line_no', [$saleId]);
        $s['payments'] = $this->db->all('SELECT * FROM payments WHERE sale_id = ? ORDER BY created_at, method', [$saleId]);
        $s['movements'] = (new InventoryService($this->db))->movementsFor('sale', $saleId);
        $s['customer'] = $s['customer_id'] ? $this->db->one('SELECT id, name, phone, email, gstin, state_code FROM customers WHERE id = ?', [$s['customer_id']]) : null;
        return $s;
    }

    public function recordPrint(string $saleId, string $kind, string $format): void
    {
        $this->db->transaction(function (Database $db) use ($saleId, $kind, $format) {
            $db->insert('print_logs', ['id' => uuid(), 'sale_id' => $saleId, 'kind' => $kind, 'format' => $format, 'user_id' => Auth::id(), 'created_at' => now()]);
            $db->run('UPDATE sales SET print_count = print_count + 1, last_printed_at = ? WHERE id = ?', [now(), $saleId]);
            if ($kind === 'reprint') {
                Audit::log('sale.reprinted', 'sale', $saleId, ['format' => $format]);
            }
        });
    }

    /** Void a completed bill (full reversal). Implemented as a 'void' return so cash and stock stay auditable. */
    public function void(string $saleId, string $reason, ?string $approvalToken = null): string
    {
        $approver = Auth::can('sale.void') ? Auth::id() : Auth::consumeApproval($approvalToken, 'sale.void');
        if (!$approver) {
            throw new ValidationException('Voiding a bill needs manager approval', ['approval' => 'sale.void']);
        }
        if (trim($reason) === '') {
            throw new ValidationException('Enter a reason for voiding');
        }
        $sale = $this->db->one('SELECT * FROM sales WHERE id = ?', [$saleId]);
        if (!$sale || $sale['status'] !== 'completed') {
            throw new ValidationException('Only completed bills can be voided');
        }
        if ((int)$this->db->value('SELECT IFNULL(SUM(returned_qty_milli),0) FROM sale_items WHERE sale_id = ?', [$saleId]) > 0) {
            throw new ValidationException('This bill already has returns. Use Returns for the remaining items.');
        }
        if ($this->db->value('SELECT 1 FROM exchanges WHERE new_sale_id = ? OR original_sale_id = ?', [$saleId, $saleId])) {
            throw new ValidationException('Bills involved in an exchange cannot be voided; process a return instead.');
        }
        $items = $this->db->all('SELECT id, qty_milli FROM sale_items WHERE sale_id = ?', [$saleId]);
        $payments = $this->db->all('SELECT method, SUM(amount_paise) amt FROM payments WHERE sale_id = ? GROUP BY method', [$saleId]);
        return $this->db->transaction(function (Database $db) use ($saleId, $reason, $items, $payments, $approver, $sale) {
            $refunds = [];
            foreach ($payments as $p) {
                $refunds[] = ['method' => $p['method'] === 'exchange_credit' ? 'cash' : $p['method'], 'amount_paise' => (int)$p['amt']];
            }
            $retId = (new ReturnService($db))->processInTransaction($sale, array_map(fn($i) => [
                'sale_item_id' => $i['id'], 'qty_milli' => (int)$i['qty_milli'], 'condition' => 'sellable'], $items),
                'void', $reason, $refunds, $approver, true);
            $db->update('sales', ['status' => 'voided', 'voided_at' => now(), 'voided_by' => Auth::id(), 'void_reason' => $reason,
                'updated_at' => now()], 'id = :id', ['id' => $saleId]);
            (new OutboxService($db))->record('sale', $saleId, 'voided', ['sale_id' => $saleId, 'invoice_no' => $sale['invoice_no'],
                'reason' => $reason, 'return_id' => $retId, 'voided_at' => now()]);
            Audit::log('sale.voided', 'sale', $saleId, ['invoice' => $sale['invoice_no'], 'reason' => $reason, 'approved_by' => $approver]);
            return $retId;
        });
    }
}
