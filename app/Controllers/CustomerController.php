<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\CustomerService;

final class CustomerController extends Controller
{
    public function index(Request $r): Response
    {
        $q = trim((string)$r->query('q', ''));
        $p = [];
        $where = 'c.is_active = 1';
        if ($q !== '') {
            $where .= ' AND (c.name LIKE :q OR c.phone LIKE :q OR c.gstin LIKE :q)';
            $p['q'] = "%$q%";
        }
        $page = View::paginate($this->db, "SELECT c.*, (SELECT COUNT(*) FROM sales WHERE customer_id = c.id AND status = 'completed') bills,
            (SELECT IFNULL(SUM(total_paise),0) FROM sales WHERE customer_id = c.id AND status = 'completed') spent,
            (SELECT MAX(created_at) FROM sales WHERE customer_id = c.id) last_visit FROM customers c WHERE $where ORDER BY c.name", $p, $this->page($r), 30);
        return $this->view('pages/customers/index', 'Customers', ['page' => $page, 'q' => $q]);
    }

    public function show(Request $r): Response
    {
        $c = $this->find('SELECT * FROM customers WHERE id = ?', [$r->params['id']], 'Customer');
        $history = Auth::can('customer.history') ? $this->db->all('SELECT id, invoice_no, created_at, total_paise, items_count, status FROM sales WHERE customer_id = ? ORDER BY created_at DESC LIMIT 100', [$c['id']]) : null;
        $returns = Auth::can('customer.history') ? $this->db->all('SELECT r.*, s.invoice_no FROM returns r JOIN sales s ON s.id = r.original_sale_id WHERE r.customer_id = ? ORDER BY r.created_at DESC LIMIT 50', [$c['id']]) : null;
        $receipts = $this->db->all('SELECT * FROM customer_receipts WHERE customer_id = ? ORDER BY created_at DESC LIMIT 50', [$c['id']]);
        $balance = (new CustomerService($this->db))->balance($c['id']);
        return $this->view('pages/customers/show', $c['name'], compact('c', 'history', 'returns', 'receipts', 'balance'));
    }

    public function save(Request $r): Response
    {
        $id = (new CustomerService($this->db))->save($r->str('id') ?: null, $r->all());
        flash('success', 'Customer saved.');
        return Response::redirect('/customers/' . $id);
    }

    public function payment(Request $r): Response
    {
        try {
            $amt = Money::parse($r->str('amount'));
        } catch (\InvalidArgumentException) {
            throw new ValidationException('Invalid amount');
        }
        (new CustomerService($this->db))->receivePayment($r->params['id'], $amt, $r->str('method', 'cash'), $r->str('reference') ?: null);
        flash('success', 'Payment received.');
        return Response::redirect('/customers/' . $r->params['id']);
    }

    public function anonymize(Request $r): Response
    {
        if ($r->str('confirm') !== 'DELETE') {
            throw new ValidationException('Type DELETE to confirm removing personal data');
        }
        (new CustomerService($this->db))->anonymize($r->params['id']);
        flash('success', 'Personal data removed. Bills are kept for accounting.');
        return Response::redirect('/customers');
    }

    public function search(Request $r): array
    {
        $svc = new CustomerService($this->db);
        $q = (string)$r->query('q', '');
        $exact = $svc->findByPhone($q);
        $rows = $svc->search($q);
        foreach ($rows as &$c) {
            $c['balance_paise'] = $svc->balance($c['id']);
            if (Auth::can('customer.history')) {
                $c['recent'] = $this->db->all("SELECT invoice_no, created_at, total_paise FROM sales WHERE customer_id = ? AND status = 'completed' ORDER BY created_at DESC LIMIT 3", [$c['id']]);
            }
        }
        unset($c);
        return $this->ok(['items' => $rows, 'exact_id' => $exact['id'] ?? null]);
    }

    public function quickCreate(Request $r): array
    {
        $svc = new CustomerService($this->db);
        $id = $svc->save(null, $r->json());
        return $this->ok(['customer' => $this->db->one('SELECT id, name, phone, gstin FROM customers WHERE id = ?', [$id])]);
    }
}
