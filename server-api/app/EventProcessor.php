<?php
declare(strict_types=1);

namespace Server;

/**
 * Applies uploaded outbox events to MySQL.
 *
 * Idempotency: each event is applied in its own transaction together with an insert into sync_receipts
 * (event_id PRIMARY KEY). A replayed event finds its receipt and is acknowledged as "duplicate" without
 * touching business tables; a concurrent replay fails on the unique key and is rolled back.
 *
 * Result statuses returned per event:
 *   applied    stored successfully
 *   duplicate  already stored earlier (safe to mark synced)
 *   conflict   stored/acknowledged, but needs admin attention (details in "conflicts")
 *   deferred   a dependency (depends_on) has not arrived yet — client keeps it pending
 *   rejected   invalid event — not stored; client marks failed with message
 *   error      unexpected server error — not stored; client retries on the next Sync Now
 */
final class EventProcessor
{
    private const SALE_COLS = ['id', 'store_id', 'device_id', 'invoice_no', 'fy', 'status', 'sale_type', 'customer_id', 'customer_name', 'customer_phone',
        'customer_gstin', 'is_interstate', 'cashier_id', 'cashier_name', 'items_count', 'qty_total_milli', 'gross_paise', 'line_discount_paise',
        'bill_discount_paise', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'tax_paise', 'round_off_paise', 'total_paise', 'paid_paise',
        'change_paise', 'credit_paise', 'cogs_paise', 'created_at', 'voided_at', 'void_reason', 'received_at'];
    private const ITEM_COLS = ['id', 'sale_id', 'line_no', 'variant_id', 'sku', 'product_name', 'variant_label', 'hsn_code', 'unit', 'qty_milli',
        'unit_price_paise', 'gross_paise', 'line_discount_paise', 'bill_discount_alloc_paise', 'taxable_paise', 'tax_rate_bp', 'cgst_paise', 'sgst_paise',
        'igst_paise', 'tax_paise', 'line_total_paise', 'cost_at_sale_paise', 'cogs_paise'];
    private const PAY_COLS = ['id', 'sale_id', 'method', 'amount_paise', 'tendered_paise', 'change_paise', 'reference', 'created_at'];
    private const RETURN_COLS = ['id', 'store_id', 'device_id', 'return_no', 'original_sale_id', 'return_type', 'reason', 'taxable_paise', 'tax_paise',
        'total_paise', 'round_off_paise', 'refund_paise', 'exchange_credit_paise', 'cogs_reversed_paise', 'created_at', 'received_at'];
    private const RETURN_ITEM_COLS = ['id', 'return_id', 'sale_item_id', 'variant_id', 'qty_milli', 'item_condition', 'amount_paise', 'taxable_paise', 'tax_paise', 'cogs_paise'];
    private const CUSTOMER_COLS = ['id', 'store_id', 'name', 'phone', 'email', 'gstin', 'address', 'state_code', 'is_active', 'anonymized_at', 'origin_device_id', 'created_at', 'updated_at'];

    private array $conflicts = [];
    private array $currentEvent = [];

    public function __construct(private Db $db, private array $device)
    {
    }

    public function processBatch(array $events): array
    {
        $results = [];
        $okInBatch = [];
        foreach ($events as $ev) {
            $r = $this->processOne(is_array($ev) ? $ev : [], $okInBatch);
            if (in_array($r['status'], ['applied', 'duplicate', 'conflict'], true)) {
                $okInBatch[$r['event_id']] = true;
            }
            $results[] = $r;
        }
        return $results;
    }

    private function processOne(array $ev, array $okInBatch): array
    {
        $id = (string)($ev['id'] ?? '');
        $res = fn(string $status, string $msg = '', array $conf = []) => ['event_id' => $id, 'status' => $status, 'message' => $msg, 'conflicts' => $conf];
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return $res('rejected', 'Invalid event id');
        }
        foreach (['entity_type', 'entity_id', 'op'] as $k) {
            if (!is_string($ev[$k] ?? null) || $ev[$k] === '' || strlen($ev[$k]) > 40) {
                return $res('rejected', "Missing or invalid $k");
            }
        }
        if (!is_array($ev['payload'] ?? null)) {
            return $res('rejected', 'Payload must be an object');
        }
        if ((int)($ev['schema_version'] ?? 0) !== 1) {
            return $res('rejected', 'Unsupported schema_version');
        }
        if (($ev['device_id'] ?? '') !== $this->device['id'] || ($ev['store_id'] ?? '') !== $this->device['store_id']) {
            return $res('rejected', 'Event device/store does not match the authenticated device');
        }
        $hash = hash('sha256', json_encode($ev['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $receipt = $this->db->one('SELECT status, payload_hash, message FROM sync_receipts WHERE event_id = ?', [$id]);
        if ($receipt) {
            if (!hash_equals($receipt['payload_hash'], $hash)) {
                return $res('rejected', 'Event id was already used with different content');
            }
            return $res('duplicate', 'Already received (' . $receipt['status'] . ')');
        }
        $dep = $ev['depends_on'] ?? null;
        if ($dep && !isset($okInBatch[$dep]) && !$this->db->value('SELECT 1 FROM sync_receipts WHERE event_id = ?', [$dep])) {
            return $res('deferred', 'Waiting for dependency ' . $dep);
        }

        $this->conflicts = [];
        $this->currentEvent = $ev;
        try {
            $status = $this->db->transaction(function (Db $db) use ($ev, $id, $hash) {
                $deferred = $this->apply($ev);
                if ($deferred) {
                    throw new DeferException($deferred);
                }
                $status = $this->conflicts ? 'conflict' : 'applied';
                $db->insert('sync_receipts', [
                    'event_id' => $id, 'store_id' => $this->device['store_id'], 'device_id' => $this->device['id'],
                    'entity_type' => $ev['entity_type'], 'entity_id' => substr((string)$ev['entity_id'], 0, 36), 'op' => $ev['op'],
                    'seq' => (int)($ev['seq'] ?? 0), 'schema_version' => 1, 'status' => $status,
                    'message' => $this->conflicts ? substr(implode('; ', array_column($this->conflicts, 'kind')), 0, 500) : null,
                    'payload_hash' => $hash, 'payload' => json_encode($ev['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'event_created_at' => $this->dt($ev['created_at'] ?? null), 'received_at' => $this->now(),
                ], ['event_id', 'store_id', 'device_id', 'entity_type', 'entity_id', 'op', 'seq', 'schema_version', 'status', 'message', 'payload_hash', 'payload', 'event_created_at', 'received_at']);
                foreach ($this->conflicts as $c) {
                    $db->insert('conflicts', ['id' => self::uuid(), 'store_id' => $this->device['store_id'], 'device_id' => $this->device['id'],
                        'event_id' => $id, 'entity_type' => $c['entity_type'], 'entity_id' => $c['entity_id'], 'kind' => $c['kind'],
                        'detail' => json_encode($c, JSON_UNESCAPED_UNICODE), 'status' => 'open', 'created_at' => $this->now()],
                        ['id', 'store_id', 'device_id', 'event_id', 'entity_type', 'entity_id', 'kind', 'detail', 'status', 'created_at']);
                }
                return $status;
            });
            return $res($status, $status === 'conflict' ? 'Stored with conflicts' : 'OK', $this->conflicts);
        } catch (DeferException $e) {
            return $res('deferred', $e->getMessage());
        } catch (RejectException $e) {
            return $res('rejected', $e->getMessage());
        } catch (\PDOException $e) {
            if (Db::isDuplicateKey($e) && $this->db->value('SELECT 1 FROM sync_receipts WHERE event_id = ?', [$id])) {
                return $res('duplicate', 'Already received (concurrent)');
            }
            error_log('Sync apply error for event ' . $id . ': ' . $e->getMessage());
            return $res('error', 'Server could not store this event; it will be retried');
        } catch (\Throwable $e) {
            error_log('Sync apply error for event ' . $id . ': ' . $e->getMessage());
            return $res('error', 'Server could not store this event; it will be retried');
        }
    }

    /** @return string|null non-null = defer with this message */
    private function apply(array $ev): ?string
    {
        $p = $ev['payload'];
        $key = $ev['entity_type'] . '.' . $ev['op'];
        return match ($key) {
            'sale.created' => $this->saleCreated($p),
            'sale.voided' => $this->saleVoided($p),
            'return.created' => $this->returnCreated($p),
            'purchase.posted' => $this->purchasePosted($p),
            'supplier_return.created', 'stock_adjustment.posted', 'expense.created', 'customer_receipt.created', 'cash_session.closed' => $this->document($ev['entity_type'], $p),
            'customer.upserted' => $this->customerUpserted($p),
            'customer.anonymized' => $this->customerAnonymized($p),
            'product.upserted' => $this->productUpserted($p),
            default => throw new RejectException("Unsupported event type $key"),
        };
    }

    private function saleCreated(array $p): ?string
    {
        $this->requireStore($p);
        if ($this->db->value('SELECT 1 FROM sales WHERE id = ?', [$p['id']])) {
            return null;   // already stored by an earlier (receipt-less) attempt
        }
        $clash = $this->db->value('SELECT id FROM sales WHERE store_id = ? AND invoice_no = ?', [$p['store_id'], $p['invoice_no']]);
        if ($clash) {
            $this->conflict('sale', $p['id'], 'duplicate_invoice_no', ['invoice_no' => $p['invoice_no'], 'existing_sale_id' => $clash,
                'note' => 'Two devices produced the same invoice number. Check device codes. Bill kept in documents table.']);
            $this->document('sale_conflict', $p);
            return null;
        }
        if (!empty($p['customer']['id']) && !$this->db->value('SELECT 1 FROM customers WHERE id = ?', [$p['customer']['id']])) {
            $c = $p['customer'] + ['store_id' => $p['store_id'], 'is_active' => 1, 'origin_device_id' => $this->device['id'],
                'created_at' => $this->dt($p['created_at']), 'updated_at' => $this->dt($p['created_at'])];
            $this->db->insert('customers', $c, self::CUSTOMER_COLS);
        }
        $sale = $p;
        $sale['received_at'] = $this->now();
        $sale['created_at'] = $this->dt($p['created_at']);
        $sale['voided_at'] = $this->dt($p['voided_at'] ?? null);
        $this->db->insert('sales', $sale, self::SALE_COLS);
        foreach ($p['items'] ?? [] as $it) {
            $this->db->insert('sale_items', $it + ['sale_id' => $p['id']], self::ITEM_COLS);
        }
        foreach ($p['payments'] ?? [] as $pay) {
            $pay['created_at'] = $this->dt($pay['created_at'] ?? $p['created_at']);
            $this->db->insert('payments', $pay + ['sale_id' => $p['id']], self::PAY_COLS);
        }
        $this->movements($p['movements'] ?? []);
        return null;
    }

    private function saleVoided(array $p): ?string
    {
        if (!$this->db->value('SELECT 1 FROM sales WHERE id = ?', [$p['sale_id'] ?? ''])) {
            return 'Original sale not on server yet';
        }
        $this->db->run("UPDATE sales SET status = 'voided', voided_at = ?, void_reason = ? WHERE id = ?",
            [$this->dt($p['voided_at'] ?? null), mb_substr((string)($p['reason'] ?? ''), 0, 300), $p['sale_id']]);
        return null;
    }

    private function returnCreated(array $p): ?string
    {
        $this->requireStore($p);
        if ($this->db->value('SELECT 1 FROM returns WHERE id = ?', [$p['id']])) {
            return null;
        }
        $r = $p;
        $r['created_at'] = $this->dt($p['created_at']);
        $r['received_at'] = $this->now();
        $this->db->insert('returns', $r, self::RETURN_COLS);
        foreach ($p['items'] ?? [] as $it) {
            $this->db->insert('return_items', $it + ['return_id' => $p['id']], self::RETURN_ITEM_COLS);
        }
        foreach ($p['refunds'] ?? [] as $f) {
            $this->db->insert('refunds', ['id' => $f['id'], 'return_id' => $p['id'], 'method' => $f['method'], 'amount_paise' => $f['amount_paise'],
                'reference' => $f['reference'] ?? null, 'created_at' => $this->dt($f['created_at'] ?? $p['created_at'])],
                ['id', 'return_id', 'method', 'amount_paise', 'reference', 'created_at']);
        }
        if (!empty($p['exchange'])) {
            $x = $p['exchange'];
            $this->db->insert('exchanges', ['id' => $x['id'], 'return_id' => $p['id'], 'new_sale_id' => $x['new_sale_id'], 'original_sale_id' => $x['original_sale_id'],
                'difference_paise' => $x['difference_paise'], 'created_at' => $this->dt($x['created_at'])],
                ['id', 'return_id', 'new_sale_id', 'original_sale_id', 'difference_paise', 'created_at']);
        }
        $this->movements($p['movements'] ?? []);
        return null;
    }

    private function purchasePosted(array $p): ?string
    {
        $this->requireStore($p);
        if ($this->db->value('SELECT 1 FROM purchases WHERE id = ?', [$p['id']])) {
            return null;
        }
        if (!empty($p['supplier']['id'])) {
            $s = $p['supplier'];
            $this->db->upsert('suppliers', ['id' => $s['id'], 'store_id' => $p['store_id'], 'name' => $s['name'], 'gstin' => $s['gstin'] ?? null,
                'phone' => $s['phone'] ?? null, 'updated_at' => $this->dt($s['updated_at'] ?? null) ?? $this->now()],
                ['id', 'store_id', 'name', 'gstin', 'phone', 'updated_at'], ['id']);
        }
        $row = $p;
        $row['posted_at'] = $this->dt($p['posted_at'] ?? null);
        $this->db->insert('purchases', $row, ['id', 'store_id', 'device_id', 'purchase_no', 'supplier_id', 'supplier_invoice_no', 'subtotal_paise',
            'tax_paise', 'freight_paise', 'total_paise', 'amount_paid_paise', 'posted_at']);
        foreach ($p['items'] ?? [] as $it) {
            $this->db->insert('purchase_items', $it, ['id', 'purchase_id', 'variant_id', 'qty_milli', 'unit_cost_paise', 'landed_unit_cost_paise', 'tax_paise']);
        }
        $this->movements($p['movements'] ?? []);
        return null;
    }

    private function document(string $type, array $p): ?string
    {
        $head = $p['adjustment'] ?? $p;   // stock adjustments are {adjustment: {...}, movements: [...]}
        $id = (string)($head['id'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            throw new RejectException('Document id missing');
        }
        if (!$this->db->value('SELECT 1 FROM documents WHERE id = ?', [$id])) {
            $no = $head['adj_no'] ?? $head['return_no'] ?? $head['invoice_no'] ?? null;
            $amount = $head['amount_paise'] ?? $head['total_paise'] ?? $head['counted_cash_paise'] ?? null;
            $date = $head['created_at'] ?? $head['closed_at'] ?? null;
            $this->db->insert('documents', ['id' => $id, 'store_id' => $this->device['store_id'], 'device_id' => $this->device['id'], 'doc_type' => $type,
                'doc_no' => $no, 'amount_paise' => $amount, 'doc_date' => $this->dt($date),
                'payload' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'received_at' => $this->now()],
                ['id', 'store_id', 'device_id', 'doc_type', 'doc_no', 'amount_paise', 'doc_date', 'payload', 'received_at']);
        }
        $this->movements($p['movements'] ?? []);
        return null;
    }

    private function customerUpserted(array $p): ?string
    {
        $this->requireStore($p);
        if (!empty($p['phone'])) {
            $other = $this->db->one('SELECT id, name FROM customers WHERE store_id = ? AND phone = ? AND id <> ?', [$p['store_id'], $p['phone'], $p['id']]);
            if ($other) {
                $this->conflict('customer', $p['id'], 'duplicate_phone', ['phone_last4' => substr($p['phone'], -4), 'existing_customer_id' => $other['id'],
                    'note' => 'Same mobile number on two customer records; merge manually.']);
            }
        }
        $row = $p + ['origin_device_id' => $this->device['id']];
        $row['created_at'] = $this->dt($p['created_at']);
        $row['updated_at'] = $this->dt($p['updated_at']);
        $existing = $this->db->one('SELECT updated_at FROM customers WHERE id = ?', [$p['id']]);
        if ($existing && $existing['updated_at'] > $row['updated_at']) {
            return null;   // older update arriving late — keep the newer one
        }
        $this->db->upsert('customers', $row, self::CUSTOMER_COLS, ['id']);
        return null;
    }

    private function customerAnonymized(array $p): ?string
    {
        $this->db->run("UPDATE customers SET name = 'Deleted customer', phone = NULL, email = NULL, address = NULL, gstin = NULL, is_active = 0, anonymized_at = ? WHERE id = ?",
            [$this->dt($p['anonymized_at'] ?? null) ?? $this->now(), $p['id'] ?? '']);
        $this->db->run("UPDATE sales SET customer_name = 'Deleted customer', customer_phone = NULL WHERE customer_id = ? AND (customer_gstin IS NULL OR customer_gstin = '')", [$p['id'] ?? '']);
        return null;
    }

    private function productUpserted(array $p): ?string
    {
        $this->requireStore($p);
        $eventTime = $this->dt($this->currentEvent['created_at'] ?? null) ?? $this->now();
        $this->db->upsert('products', ['id' => $p['id'], 'store_id' => $p['store_id'], 'code' => $p['code'], 'name' => $p['name'],
            'category_name' => $p['category_name'] ?? null, 'brand_name' => $p['brand_name'] ?? null, 'unit' => $p['unit'], 'hsn_code' => $p['hsn_code'] ?? null,
            'tax_code' => $p['tax_code'] ?? null, 'status' => $p['status'], 'origin_device_id' => $this->device['id'],
            'updated_at' => $this->dt($p['updated_at']), 'server_updated_at' => $this->now()],
            ['id', 'store_id', 'code', 'name', 'category_name', 'brand_name', 'unit', 'hsn_code', 'tax_code', 'status', 'origin_device_id', 'updated_at', 'server_updated_at'], ['id']);
        foreach ($p['variants'] ?? [] as $v) {
            $server = $this->db->one('SELECT * FROM product_variants WHERE id = ?', [$v['id']]);
            $row = ['id' => $v['id'], 'product_id' => $p['id'], 'sku' => $v['sku'], 'barcode' => $v['barcode'], 'size_label' => $v['size_label'] ?? null,
                'color_name' => $v['color_name'] ?? null, 'design_no' => $v['design_no'] ?? null, 'mrp_paise' => $v['mrp_paise'],
                'price_paise' => $v['price_paise'], 'cost_paise' => $v['cost_paise'] ?? 0, 'status' => $v['status'], 'updated_at' => $this->dt($v['updated_at'])];
            if ($server && $server['admin_price_changed_at'] && $server['admin_price_changed_at'] >= $eventTime
                && ((int)$server['price_paise'] !== (int)$v['price_paise'] || (int)$server['mrp_paise'] !== (int)$v['mrp_paise'])) {
                // Server-approved price is authoritative: keep it and report a conflict.
                $this->conflict('variant', $v['id'], 'price_changed', ['sku' => $v['sku'],
                    'local' => ['mrp_paise' => $v['mrp_paise'], 'price_paise' => $v['price_paise']],
                    'server' => ['mrp_paise' => (int)$server['mrp_paise'], 'price_paise' => (int)$server['price_paise']]]);
                $row['mrp_paise'] = $server['mrp_paise'];
                $row['price_paise'] = $server['price_paise'];
            }
            $row['admin_price_changed_at'] = $server['admin_price_changed_at'] ?? null;
            $this->db->upsert('product_variants', $row, ['id', 'product_id', 'sku', 'barcode', 'size_label', 'color_name', 'design_no', 'mrp_paise',
                'price_paise', 'cost_paise', 'status', 'admin_price_changed_at', 'updated_at'], ['id']);
        }
        return null;
    }

    private function movements(array $movements): void
    {
        foreach ($movements as $m) {
            if (empty($m['id']) || $this->db->value('SELECT 1 FROM stock_movements WHERE id = ?', [$m['id']])) {
                continue;
            }
            $loc = $m['location_code'] ?? null;
            if (!$loc) {
                $loc = ($m['movement_type'] === 'damaged_return' || ($m['movement_type'] === 'transfer_in')) ? 'DAMAGED' : 'MAIN';
            }
            $this->db->insert('stock_movements', ['id' => $m['id'], 'store_id' => $this->device['store_id'], 'device_id' => $this->device['id'],
                'variant_id' => $m['variant_id'], 'location_code' => $loc, 'movement_type' => $m['movement_type'], 'qty_milli' => (int)$m['qty_milli'],
                'unit_cost_paise' => (int)$m['unit_cost_paise'], 'value_paise' => (int)$m['value_paise'], 'ref_type' => $m['ref_type'] ?? null,
                'ref_id' => $m['ref_id'] ?? null, 'created_at' => $this->dt($m['created_at'])],
                ['id', 'store_id', 'device_id', 'variant_id', 'location_code', 'movement_type', 'qty_milli', 'unit_cost_paise', 'value_paise', 'ref_type', 'ref_id', 'created_at']);
            $cur = (int)$this->db->value('SELECT qty_milli FROM stock_balances WHERE store_id = ? AND variant_id = ? AND location_code = ?',
                [$this->device['store_id'], $m['variant_id'], $loc]);
            $new = $cur + (int)$m['qty_milli'];
            $this->db->upsert('stock_balances', ['store_id' => $this->device['store_id'], 'variant_id' => $m['variant_id'], 'location_code' => $loc,
                'qty_milli' => $new, 'updated_at' => $this->now()], ['store_id', 'variant_id', 'location_code', 'qty_milli', 'updated_at'], ['store_id', 'variant_id', 'location_code']);
            if ($new < 0 && (int)$m['qty_milli'] < 0) {
                $this->conflict('variant', $m['variant_id'], 'negative_stock', ['location' => $loc, 'server_balance_milli' => $new,
                    'note' => 'Server stock mirror went negative (counters disagree or opening stock missing). Reconcile with a stock count.']);
            }
        }
    }

    private function requireStore(array $p): void
    {
        if (($p['store_id'] ?? '') !== $this->device['store_id'] || !preg_match('/^[0-9a-f-]{36}$/i', (string)($p['id'] ?? ''))) {
            throw new RejectException('Payload store/id invalid');
        }
    }

    private function conflict(string $type, string $id, string $kind, array $detail): void
    {
        $this->conflicts[] = ['entity_type' => $type, 'entity_id' => $id, 'kind' => $kind] + $detail;
    }

    private function dt(?string $s): ?string
    {
        if (!$s) {
            return null;
        }
        $t = strtotime($s);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}

final class DeferException extends \RuntimeException
{
}

final class RejectException extends \RuntimeException
{
}
