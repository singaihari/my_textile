<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Core\ValidationException;

/**
 * Cash counter sessions. Expected cash = opening float + cash received on bills (net of change)
 * + cash credit receipts + pay-ins − cash refunds − pay-outs − cash expenses.
 */
final class CashService
{
    public function __construct(private Database $db)
    {
    }

    public function current(): ?array
    {
        return $this->db->one("SELECT cs.*, u.full_name AS opened_by_name FROM cash_sessions cs JOIN users u ON u.id = cs.opened_by
                               WHERE cs.device_id = ? AND cs.status = 'open'", [Context::deviceId()]);
    }

    public function open(int $float, ?string $notes): string
    {
        if ($float < 0) {
            throw new ValidationException('Opening cash cannot be negative');
        }
        if ($this->current()) {
            throw new ValidationException('A cash session is already open on this counter');
        }
        return $this->db->transaction(function (Database $db) use ($float, $notes) {
            $id = uuid();
            $db->insert('cash_sessions', ['id' => $id, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(),
                'opened_by' => Auth::id(), 'opened_at' => now(), 'opening_float_paise' => $float, 'status' => 'open', 'notes' => $notes]);
            Audit::log('cash.opened', 'cash_session', $id, ['float' => $float]);
            return $id;
        });
    }

    public function movement(string $type, int $amount, string $reason): void
    {
        $s = $this->current() ?? throw new ValidationException('No open cash session');
        if (!in_array($type, ['pay_in', 'pay_out'], true) || $amount <= 0 || trim($reason) === '') {
            throw new ValidationException('Enter amount and reason');
        }
        $this->db->transaction(function (Database $db) use ($s, $type, $amount, $reason) {
            $db->insert('cash_movements', ['id' => uuid(), 'cash_session_id' => $s['id'], 'store_id' => Context::storeId(), 'type' => $type,
                'amount_paise' => $amount, 'reason' => mb_substr($reason, 0, 200), 'user_id' => Auth::id(), 'created_at' => now()]);
            Audit::log('cash.' . $type, 'cash_session', $s['id'], ['amount' => $amount, 'reason' => $reason]);
        });
    }

    public function summary(string $sessionId): array
    {
        $s = $this->db->one('SELECT * FROM cash_sessions WHERE id = ?', [$sessionId]);
        $q = fn(string $sql) => (int)$this->db->value($sql, [$sessionId]);
        $cashSales = $q("SELECT IFNULL(SUM(amount_paise),0) FROM payments WHERE cash_session_id = ? AND method = 'cash'");
        $cashRefunds = $q("SELECT IFNULL(SUM(amount_paise),0) FROM refunds WHERE cash_session_id = ? AND method = 'cash'");
        $receipts = $q("SELECT IFNULL(SUM(amount_paise),0) FROM customer_receipts WHERE cash_session_id = ? AND method = 'cash'");
        $payIn = $q("SELECT IFNULL(SUM(amount_paise),0) FROM cash_movements WHERE cash_session_id = ? AND type = 'pay_in'");
        $payOut = $q("SELECT IFNULL(SUM(amount_paise),0) FROM cash_movements WHERE cash_session_id = ? AND type = 'pay_out'");
        $expenses = $q("SELECT IFNULL(SUM(amount_paise),0) FROM expenses WHERE cash_session_id = ? AND payment_method = 'cash' AND status = 'approved'");
        $byMethod = $this->db->all('SELECT method, COUNT(DISTINCT sale_id) bills, SUM(amount_paise) amount FROM payments WHERE cash_session_id = ? GROUP BY method', [$sessionId]);
        $bills = $q("SELECT COUNT(DISTINCT sale_id) FROM payments WHERE cash_session_id = ?");
        $expected = (int)$s['opening_float_paise'] + $cashSales + $receipts + $payIn - $cashRefunds - $payOut - $expenses;
        return compact('s', 'cashSales', 'cashRefunds', 'receipts', 'payIn', 'payOut', 'expenses', 'expected', 'byMethod', 'bills');
    }

    public function close(int $counted, ?string $notes): array
    {
        $s = $this->current() ?? throw new ValidationException('No open cash session');
        if ($counted < 0) {
            throw new ValidationException('Counted cash cannot be negative');
        }
        $sum = $this->summary($s['id']);
        $this->db->transaction(function (Database $db) use ($s, $sum, $counted, $notes) {
            $db->update('cash_sessions', ['status' => 'closed', 'closed_by' => Auth::id(), 'closed_at' => now(),
                'expected_cash_paise' => $sum['expected'], 'counted_cash_paise' => $counted, 'difference_paise' => $counted - $sum['expected'],
                'notes' => trim(($s['notes'] ? $s['notes'] . "\n" : '') . (string)$notes) ?: null], 'id = :id', ['id' => $s['id']]);
            $row = $db->one('SELECT * FROM cash_sessions WHERE id = ?', [$s['id']]);
            $row['movements'] = $db->all('SELECT * FROM cash_movements WHERE cash_session_id = ?', [$s['id']]);
            (new OutboxService($db))->record('cash_session', $s['id'], 'closed', $row);
            Audit::log('cash.closed', 'cash_session', $s['id'], ['expected' => $sum['expected'], 'counted' => $counted]);
        });
        return $this->summary($s['id']);
    }
}
