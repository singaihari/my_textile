<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\SaleService;

final class SalesController extends Controller
{
    public function index(Request $r): Response
    {
        $where = ['1=1'];
        $p = [];
        $q = trim((string)$r->query('q', ''));
        if ($q !== '') {
            $where[] = '(s.invoice_no LIKE :q OR s.customer_phone LIKE :q OR s.customer_name LIKE :q OR EXISTS (SELECT 1 FROM sale_items si WHERE si.sale_id = s.id AND si.sku LIKE :q))';
            $p['q'] = '%' . $q . '%';
        }
        if ($d = $r->query('date')) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $where[] = 'date(s.created_at) = :d';
                $p['d'] = $d;
            }
        }
        if (in_array($st = (string)$r->query('status', ''), ['completed', 'voided'], true)) {
            $where[] = 's.status = :st';
            $p['st'] = $st;
        }
        $sql = "SELECT s.*, u.full_name cashier, (SELECT status FROM sync_outbox o WHERE o.entity_type = 'sale' AND o.entity_id = s.id AND o.op = 'created') sync_status,
                (SELECT COUNT(*) FROM returns rt WHERE rt.original_sale_id = s.id AND rt.return_type <> 'void') returns_count
                FROM sales s JOIN users u ON u.id = s.cashier_id WHERE " . implode(' AND ', $where) . ' ORDER BY s.created_at DESC';
        $page = View::paginate($this->db, $sql, $p, $this->page($r), 30);
        return $this->view('pages/sales/index', 'Invoices', ['page' => $page, 'q' => $q]);
    }

    public function show(Request $r): Response
    {
        $sale = (new SaleService($this->db))->snapshot($r->params['id']);
        $returns = $this->db->all('SELECT r.*, u.full_name staff FROM returns r JOIN users u ON u.id = r.created_by WHERE r.original_sale_id = ? ORDER BY r.created_at', [$sale['id']]);
        $exchange = $this->db->one('SELECT e.*, s.invoice_no original_invoice FROM exchanges e JOIN sales s ON s.id = e.original_sale_id WHERE e.new_sale_id = ?', [$sale['id']]);
        $prints = $this->db->all('SELECT p.*, u.full_name FROM print_logs p LEFT JOIN users u ON u.id = p.user_id WHERE sale_id = ? ORDER BY created_at', [$sale['id']]);
        $sync = $this->db->one("SELECT status, acked_at, last_error FROM sync_outbox WHERE entity_type = 'sale' AND entity_id = ? AND op = 'created'", [$sale['id']]);
        return $this->view('pages/sales/show', 'Invoice ' . $sale['invoice_no'], compact('sale', 'returns', 'exchange', 'prints', 'sync'));
    }

    /** Printable receipt. Viewing never creates or alters a sale; print attempts are logged via printed(). */
    public function receipt(Request $r): Response
    {
        $sale = (new SaleService($this->db))->snapshot($r->params['id']);
        $format = $r->query('format') === 'a4' ? 'a4' : '80mm';
        $kind = $r->query('kind') === 'reprint' ? 'reprint' : 'initial';
        $taxSummary = [];
        foreach ($sale['items'] as $it) {
            $k = (string)$it['tax_rate_bp'];
            $taxSummary[$k] ??= ['rate_bp' => (int)$it['tax_rate_bp'], 'taxable' => 0, 'cgst' => 0, 'sgst' => 0, 'igst' => 0];
            $taxSummary[$k]['taxable'] += (int)$it['taxable_paise'];
            $taxSummary[$k]['cgst'] += (int)$it['cgst_paise'];
            $taxSummary[$k]['sgst'] += (int)$it['sgst_paise'];
            $taxSummary[$k]['igst'] += (int)$it['igst_paise'];
        }
        ksort($taxSummary, SORT_NUMERIC);
        $exchange = $this->db->one('SELECT e.*, s.invoice_no original_invoice, r.return_no FROM exchanges e JOIN sales s ON s.id = e.original_sale_id JOIN returns r ON r.id = e.return_id WHERE e.new_sale_id = ?', [$sale['id']]);
        return Response::html(View::render('pages/receipt', [
            'title' => $sale['invoice_no'], 'sale' => $sale, 'format' => $format, 'kind' => $kind,
            'isDuplicate' => (int)$sale['print_count'] > 0 && $kind === 'reprint', 'taxSummary' => $taxSummary, 'exchange' => $exchange,
            'autoprint' => $r->query('autoprint') === '1', 'shop' => Settings::all(),
        ], null));
    }

    public function printed(Request $r): array
    {
        $kind = in_array($r->str('kind'), ['initial', 'reprint'], true) ? $r->str('kind') : 'initial';
        if ($kind === 'reprint') {
            Auth::authorize('pos.reprint');
        }
        $sale = $this->find('SELECT id, print_count FROM sales WHERE id = ?', [$r->params['id']], 'Bill');
        if ($kind === 'initial' && (int)$sale['print_count'] > 0) {
            $kind = 'reprint';   // a retried "initial" print is logged as a reprint
        }
        (new SaleService($this->db))->recordPrint($sale['id'], $kind, $r->str('format') === 'a4' ? 'a4' : '80mm');
        return $this->ok(['kind' => $kind]);
    }

    public function void(Request $r): Response
    {
        if (!Auth::can('sale.void') && !$r->str('approval_token')) {
            throw new ValidationException('Voiding a bill needs manager approval');
        }
        (new SaleService($this->db))->void($r->params['id'], $r->str('reason'), $r->str('approval_token') ?: null);
        flash('success', 'Bill voided. Stock restored and refund recorded.');
        return Response::redirect('/sales/' . $r->params['id']);
    }

    public function printTest(Request $r): Response
    {
        return Response::html(View::render('pages/print_test', ['title' => 'Printer test', 'shop' => Settings::all(),
            'format' => $r->query('format') === 'a4' ? 'a4' : '80mm'], null));
    }
}
