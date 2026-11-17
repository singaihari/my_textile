<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Core\Money;
use App\Core\ValidationException;

final class CustomerService
{
    public function __construct(private Database $db)
    {
    }

    public static function normalizePhone(?string $p): ?string
    {
        $d = preg_replace('/\D/', '', (string)$p);
        if ($d === '') {
            return null;
        }
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        return $d;
    }

    public function findByPhone(string $phone): ?array
    {
        $p = self::normalizePhone($phone);
        return $p ? $this->db->one('SELECT * FROM customers WHERE phone = ?', [$p]) : null;
    }

    public function search(string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $digits = preg_replace('/\D/', '', $q);
        if (strlen($digits) >= 3 && strlen($digits) === strlen(str_replace(' ', '', $q))) {
            return $this->db->all("SELECT id, name, phone, gstin FROM customers WHERE is_active = 1 AND phone LIKE ? ORDER BY name LIMIT $limit", ['%' . $digits . '%']);
        }
        return $this->db->all("SELECT id, name, phone, gstin FROM customers WHERE is_active = 1 AND name LIKE ? ORDER BY name LIMIT $limit", ['%' . $q . '%']);
    }

    public function save(?string $id, array $in): string
    {
        $name = trim((string)($in['name'] ?? ''));
        $phone = self::normalizePhone($in['phone'] ?? null);
        $email = trim((string)($in['email'] ?? '')) ?: null;
        $gstin = strtoupper(trim((string)($in['gstin'] ?? ''))) ?: null;
        if ($name === '') {
            throw new ValidationException('Customer name is required', ['name' => 'Required']);
        }
        if ($phone !== null && !preg_match('/^[6-9]\d{9}$/', $phone)) {
            throw new ValidationException('Enter a valid 10-digit mobile number', ['phone' => 'Invalid']);
        }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('Invalid email', ['email' => 'Invalid']);
        }
        if ($gstin && !preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/', $gstin)) {
            throw new ValidationException('GSTIN format looks wrong (15 characters)', ['gstin' => 'Invalid']);
        }
        if ($phone) {
            $dup = $this->db->one('SELECT id, name FROM customers WHERE phone = ? AND id <> ?', [$phone, $id ?? '']);
            if ($dup) {
                throw new ValidationException("Mobile $phone already belongs to {$dup['name']}", ['phone' => 'Duplicate', 'existing_id' => $dup['id']]);
            }
        }
        $row = ['name' => mb_substr($name, 0, 100), 'phone' => $phone, 'email' => $email, 'gstin' => $gstin,
            'address' => trim((string)($in['address'] ?? '')) ?: null, 'state_code' => trim((string)($in['state_code'] ?? '')) ?: ($gstin ? substr($gstin, 0, 2) : null),
            'notes' => trim((string)($in['notes'] ?? '')) ?: null, 'consent_marketing' => !empty($in['consent_marketing']) ? 1 : 0,
            'updated_at' => now()];
        if (isset($in['credit_limit']) && Auth::can('pos.credit_sale')) {
            $row['credit_limit_paise'] = Money::parse((string)$in['credit_limit']);
        }
        return $this->db->transaction(function (Database $db) use ($id, $row) {
            if ($id) {
                if (!$db->update('customers', $row, 'id = :id', ['id' => $id])) {
                    throw new ValidationException('Customer not found');
                }
            } else {
                $id = uuid();
                $db->insert('customers', $row + ['id' => $id, 'store_id' => Context::storeId(), 'is_active' => 1, 'created_at' => now()]);
            }
            (new OutboxService($db))->record('customer', $id, 'upserted', $db->one('SELECT * FROM customers WHERE id = ?', [$id]));
            Audit::log('customer.saved', 'customer', $id);
            return $id;
        });
    }

    /** Outstanding credit = credit sales − credit refunds − receipts. */
    public function balance(string $id): int
    {
        $credit = (int)$this->db->value("SELECT IFNULL(SUM(p.amount_paise),0) FROM payments p JOIN sales s ON s.id = p.sale_id WHERE p.method = 'credit' AND s.customer_id = ?", [$id]);
        $refunded = (int)$this->db->value("SELECT IFNULL(SUM(f.amount_paise),0) FROM refunds f JOIN returns r ON r.id = f.return_id WHERE f.method = 'credit' AND r.customer_id = ?", [$id]);
        $received = (int)$this->db->value('SELECT IFNULL(SUM(amount_paise),0) FROM customer_receipts WHERE customer_id = ?', [$id]);
        return $credit - $refunded - $received;
    }

    public function receivePayment(string $id, int $amount, string $method, ?string $ref): string
    {
        if ($amount <= 0) {
            throw new ValidationException('Enter an amount');
        }
        if (!in_array($method, ['cash', 'upi', 'card'], true)) {
            throw new ValidationException('Invalid method');
        }
        return $this->db->transaction(function (Database $db) use ($id, $amount, $method, $ref) {
            $rid = uuid();
            $session = $db->value("SELECT id FROM cash_sessions WHERE device_id = ? AND status = 'open'", [Context::deviceId()]);
            $db->insert('customer_receipts', ['id' => $rid, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(), 'customer_id' => $id,
                'amount_paise' => $amount, 'method' => $method, 'reference' => $ref, 'cash_session_id' => $session ?: null,
                'received_by' => Auth::id(), 'created_at' => now()]);
            (new OutboxService($db))->record('customer_receipt', $rid, 'created', $db->one('SELECT * FROM customer_receipts WHERE id = ?', [$rid]));
            Audit::log('customer.payment_received', 'customer', $id, ['amount' => $amount, 'method' => $method]);
            return $rid;
        });
    }

    /** Privacy: remove personal data while keeping bills intact (bills keep their own name/phone snapshot unless also anonymised). */
    public function anonymize(string $id): void
    {
        $this->db->transaction(function (Database $db) use ($id) {
            $db->update('customers', ['name' => 'Deleted customer', 'phone' => null, 'email' => null, 'address' => null, 'gstin' => null,
                'notes' => null, 'is_active' => 0, 'anonymized_at' => now(), 'updated_at' => now()], 'id = :id', ['id' => $id]);
            $db->run("UPDATE sales SET customer_name = 'Deleted customer', customer_phone = NULL WHERE customer_id = ? AND (customer_gstin IS NULL OR customer_gstin = '')", [$id]);
            (new OutboxService($db))->record('customer', $id, 'anonymized', ['id' => $id, 'anonymized_at' => now()]);
            Audit::log('customer.anonymized', 'customer', $id);
        });
    }
}
