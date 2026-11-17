<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Context;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\PurchaseService;

final class PurchaseController extends Controller
{
    public function index(Request $r): Response
    {
        $sql = 'SELECT p.*, s.name supplier, (SELECT COUNT(*) FROM purchase_items WHERE purchase_id = p.id) lines FROM purchases p JOIN suppliers s ON s.id = p.supplier_id ORDER BY p.created_at DESC';
        $page = View::paginate($this->db, $sql, [], $this->page($r), 30);
        $returns = $this->db->all('SELECT sr.*, s.name supplier FROM supplier_returns sr JOIN suppliers s ON s.id = sr.supplier_id ORDER BY sr.created_at DESC LIMIT 10');
        return $this->view('pages/purchases/index', 'Purchases', compact('page', 'returns'));
    }

    public function form(Request $r): Response
    {
        $purchase = null;
        $items = [];
        if (!empty($r->params['id'])) {
            $purchase = $this->find('SELECT p.*, s.name supplier_name FROM purchases p JOIN suppliers s ON s.id = p.supplier_id WHERE p.id = ?', [$r->params['id']], 'Purchase');
            $items = $this->db->all('SELECT pi.*, v.sku, pr.name, pr.unit, v.barcode FROM purchase_items pi JOIN product_variants v ON v.id = pi.variant_id JOIN products pr ON pr.id = v.product_id WHERE purchase_id = ?', [$purchase['id']]);
        }
        $suppliers = $this->db->all('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');
        return $this->view('pages/purchases/form', $purchase ? 'Purchase ' . $purchase['purchase_no'] : 'New purchase (GRN)', [
            'purchase' => $purchase, 'items' => $items, 'suppliers' => $suppliers,
        ]);
    }

    public function save(Request $r): Response
    {
        $svc = new PurchaseService($this->db);
        $id = $svc->saveDraft($r->str('id') ?: null, $r->all(), (array)$r->input('items', []));
        if ($r->str('action') === 'post') {
            $svc->post($id);
            flash('success', 'Purchase posted. Stock and average cost updated.');
        } else {
            flash('success', 'Draft saved. Stock changes only when you post it.');
        }
        return Response::redirect('/purchases/' . $id);
    }

    public function post(Request $r): Response
    {
        (new PurchaseService($this->db))->post($r->params['id']);
        flash('success', 'Purchase posted. Stock and average cost updated.');
        return Response::redirect('/purchases/' . $r->params['id']);
    }

    public function cancel(Request $r): Response
    {
        (new PurchaseService($this->db))->cancelDraft($r->params['id']);
        flash('success', 'Draft cancelled.');
        return Response::redirect('/purchases');
    }

    public function supplierReturnForm(Request $r): Response
    {
        return $this->view('pages/purchases/supplier_return', 'Return to supplier', [
            'suppliers' => $this->db->all('SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name'),
        ]);
    }

    public function supplierReturn(Request $r): Response
    {
        $id = (new PurchaseService($this->db))->supplierReturn($r->str('supplier_id'), $r->str('purchase_id') ?: null, $r->str('reason'), (array)$r->input('items', []));
        flash('success', 'Supplier return ' . $this->db->value('SELECT return_no FROM supplier_returns WHERE id = ?', [$id]) . ' saved; stock reduced.');
        return Response::redirect('/purchases');
    }

    public function suppliers(Request $r): Response
    {
        $q = trim((string)$r->query('q', ''));
        $p = [];
        $where = '1=1';
        if ($q !== '') {
            $where = '(s.name LIKE :q OR s.phone LIKE :q OR s.gstin LIKE :q)';
            $p['q'] = "%$q%";
        }
        $rows = View::paginate($this->db, "SELECT s.*, (SELECT COUNT(*) FROM purchases WHERE supplier_id = s.id AND status = 'posted') invoices,
            (SELECT IFNULL(SUM(total_paise - amount_paid_paise),0) FROM purchases WHERE supplier_id = s.id AND status = 'posted') + s.opening_balance_paise balance,
            (SELECT MAX(posted_at) FROM purchases WHERE supplier_id = s.id) last_purchase FROM suppliers s WHERE $where ORDER BY s.name", $p, $this->page($r), 30);
        $edit = $r->query('edit') ? $this->db->one('SELECT * FROM suppliers WHERE id = ?', [$r->query('edit')]) : null;
        return $this->view('pages/purchases/suppliers', 'Suppliers', ['page' => $rows, 'q' => $q, 'edit' => $edit]);
    }

    public function saveSupplier(Request $r): Response
    {
        $name = $r->str('name');
        if ($name === '') {
            throw new ValidationException('Supplier name is required');
        }
        $gstin = strtoupper($r->str('gstin')) ?: null;
        if ($gstin && !preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/', $gstin)) {
            throw new ValidationException('GSTIN format looks wrong');
        }
        try {
            $ob = Money::parse($r->str('opening_balance', '0'));
        } catch (\InvalidArgumentException) {
            throw new ValidationException('Invalid opening balance');
        }
        $row = ['name' => $name, 'contact_person' => $r->str('contact_person') ?: null, 'phone' => $r->str('phone') ?: null, 'email' => $r->str('email') ?: null,
            'gstin' => $gstin, 'address' => $r->str('address') ?: null, 'state_code' => $gstin ? substr($gstin, 0, 2) : ($r->str('state_code') ?: null),
            'opening_balance_paise' => $ob, 'notes' => $r->str('notes') ?: null, 'is_active' => $r->str('is_active', '1') === '1' ? 1 : 0, 'updated_at' => now()];
        $id = $r->str('id');
        if ($id) {
            $this->db->update('suppliers', $row, 'id = :id', ['id' => $id]);
        } else {
            $id = uuid();
            $this->db->insert('suppliers', $row + ['id' => $id, 'store_id' => Context::storeId(), 'created_at' => now()]);
        }
        Audit::log('supplier.saved', 'supplier', $id);
        flash('success', 'Supplier saved.');
        return Response::redirect('/suppliers');
    }
}
