<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Context;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\CashService;
use App\Services\ExpenseService;

final class FinanceController extends Controller
{
    public function expenses(Request $r): Response
    {
        [$from, $to] = \App\Services\ReportService::range($r->query('from'), $r->query('to'));
        $page = View::paginate($this->db, 'SELECT e.*, c.name category, u.full_name user FROM expenses e JOIN expense_categories c ON c.id = e.category_id JOIN users u ON u.id = e.created_by
            WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC, e.created_at DESC', [$from, $to], $this->page($r), 30);
        $total = (int)$this->db->value("SELECT IFNULL(SUM(amount_paise),0) FROM expenses WHERE status = 'approved' AND expense_date BETWEEN ? AND ?", [$from, $to]);
        return $this->view('pages/expenses', 'Expenses', ['page' => $page, 'from' => $from, 'to' => $to, 'total' => $total,
            'categories' => $this->db->all('SELECT id, name FROM expense_categories WHERE is_active = 1 ORDER BY name')]);
    }

    public function saveExpense(Request $r): Response
    {
        (new ExpenseService($this->db))->create($r->all(), $r->file('attachment'));
        flash('success', 'Expense recorded.');
        return Response::redirect('/expenses');
    }

    public function cash(Request $r): Response
    {
        $svc = new CashService($this->db);
        $current = $svc->current();
        $summary = $current ? $svc->summary($current['id']) : null;
        $movements = $current ? $this->db->all('SELECT m.*, u.full_name FROM cash_movements m JOIN users u ON u.id = m.user_id WHERE cash_session_id = ? ORDER BY created_at DESC', [$current['id']]) : [];
        $history = $this->db->all('SELECT cs.*, a.full_name opened_name, b.full_name closed_name FROM cash_sessions cs JOIN users a ON a.id = cs.opened_by LEFT JOIN users b ON b.id = cs.closed_by
            WHERE cs.device_id = ? ORDER BY cs.opened_at DESC LIMIT 20', [Context::deviceId()]);
        $lastSummary = null;
        if (!$current && ($sid = $r->query('closed'))) {
            $lastSummary = $svc->summary($sid);
        }
        return $this->view('pages/cash', 'Cash counter', compact('current', 'summary', 'movements', 'history', 'lastSummary'));
    }

    private function amount(Request $r, string $k): int
    {
        try {
            return Money::parse($r->str($k));
        } catch (\InvalidArgumentException) {
            throw new ValidationException('Enter a valid amount');
        }
    }

    public function openCash(Request $r): Response
    {
        (new CashService($this->db))->open($this->amount($r, 'opening'), $r->str('notes') ?: null);
        flash('success', 'Cash counter opened.');
        return Response::redirect($r->str('next') === 'pos' ? '/pos' : '/cash');
    }

    public function cashMovement(Request $r): Response
    {
        (new CashService($this->db))->movement($r->str('type'), $this->amount($r, 'amount'), $r->str('reason'));
        flash('success', 'Cash movement recorded.');
        return Response::redirect('/cash');
    }

    public function closeCash(Request $r): Response
    {
        $sum = (new CashService($this->db))->close($this->amount($r, 'counted'), $r->str('notes') ?: null);
        $diff = (int)$sum['s']['difference_paise'];
        flash($diff === 0 ? 'success' : 'warning', 'Counter closed. Expected ' . Money::format($sum['expected']) . ', counted ' . Money::format((int)$sum['s']['counted_cash_paise'])
            . ($diff ? ' — difference ' . Money::format($diff) : ' — matched.'));
        return Response::redirect('/cash', ['closed' => $sum['s']['id']]);
    }
}
