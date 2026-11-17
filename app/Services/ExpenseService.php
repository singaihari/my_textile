<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Core\Money;
use App\Core\ValidationException;

final class ExpenseService
{
    public function __construct(private Database $db)
    {
    }

    public function create(array $in, ?array $file = null): string
    {
        $date = (string)($in['expense_date'] ?? today());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new ValidationException('Invalid date');
        }
        if (!$this->db->value('SELECT 1 FROM expense_categories WHERE id = ?', [$in['category_id'] ?? ''])) {
            throw new ValidationException('Choose a category');
        }
        $desc = trim((string)($in['description'] ?? ''));
        if ($desc === '') {
            throw new ValidationException('Enter a description');
        }
        try {
            $amount = Money::parse((string)($in['amount'] ?? ''));
        } catch (\InvalidArgumentException) {
            throw new ValidationException('Invalid amount');
        }
        if ($amount <= 0) {
            throw new ValidationException('Amount must be greater than zero');
        }
        $method = in_array($in['payment_method'] ?? 'cash', ['cash', 'upi', 'card', 'bank'], true) ? $in['payment_method'] : 'cash';
        $attachment = null;
        if ($file) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true) || $file['size'] > 5_000_000) {
                throw new ValidationException('Attachment must be JPG/PNG/PDF under 5 MB');
            }
            $dir = APP_ROOT . '/storage/attachments';
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            $attachment = uuid() . '.' . $ext;
            move_uploaded_file($file['tmp_name'], "$dir/$attachment");
        }
        return $this->db->transaction(function (Database $db) use ($date, $in, $desc, $amount, $method, $attachment) {
            $id = uuid();
            $session = $method === 'cash' ? $db->value("SELECT id FROM cash_sessions WHERE device_id = ? AND status = 'open'", [Context::deviceId()]) : null;
            $db->insert('expenses', ['id' => $id, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(), 'expense_date' => $date,
                'category_id' => $in['category_id'], 'description' => mb_substr($desc, 0, 300), 'amount_paise' => $amount,
                'payment_method' => $method, 'reference' => trim((string)($in['reference'] ?? '')) ?: null, 'attachment_path' => $attachment,
                'status' => 'approved', 'cash_session_id' => $session ?: null, 'created_by' => Auth::id(), 'approved_by' => Auth::id(),
                'created_at' => now(), 'updated_at' => now()]);
            $row = $db->one('SELECT e.*, c.name AS category_name FROM expenses e JOIN expense_categories c ON c.id = e.category_id WHERE e.id = ?', [$id]);
            (new OutboxService($db))->record('expense', $id, 'created', $row);
            Audit::log('expense.created', 'expense', $id, ['amount' => $amount]);
            return $id;
        });
    }
}
