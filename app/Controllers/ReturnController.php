<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\View;
use App\Services\ReturnService;

final class ReturnController extends Controller
{
    /** Find the original bill by invoice number, date, mobile or SKU. */
    public function index(Request $r): Response
    {
        $q = trim((string)$r->query('q', ''));
        $date = (string)$r->query('date', '');
        $results = [];
        if ($q !== '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $where = ["s.status = 'completed'"];
            $p = [];
            if ($q !== '') {
                $digits = preg_replace('/\D/', '', $q);
                $where[] = '(s.invoice_no LIKE :q OR s.customer_phone LIKE :ph OR EXISTS (SELECT 1 FROM sale_items si WHERE si.sale_id = s.id AND (si.sku = :exact OR si.barcode = :exact)))';
                $p['q'] = '%' . $q . '%';
                $p['ph'] = $digits !== '' ? '%' . $digits . '%' : '__none__';
                $p['exact'] = strtoupper($q);
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $where[] = 'date(s.created_at) = :d';
                $p['d'] = $date;
            }
            $results = $this->db->all('SELECT s.*, (SELECT SUM(qty_milli - returned_qty_milli) FROM sale_items WHERE sale_id = s.id) AS returnable
                FROM sales s WHERE ' . implode(' AND ', $where) . ' ORDER BY s.created_at DESC LIMIT 50', $p);
            if (count($results) === 1 && strcasecmp($results[0]['invoice_no'], $q) === 0) {
                return Response::redirect('/returns/sale/' . $results[0]['id']);
            }
        }
        $recent = $this->db->all('SELECT r.*, s.invoice_no FROM returns r JOIN sales s ON s.id = r.original_sale_id ORDER BY r.created_at DESC LIMIT 15');
        return $this->view('pages/returns/index', 'Returns & exchanges', compact('q', 'date', 'results', 'recent'));
    }

    public function form(Request $r): Response
    {
        $elig = (new ReturnService($this->db))->eligibility($r->params['id']);
        return $this->view('pages/returns/form', 'Return / exchange — ' . $elig['sale']['invoice_no'], ['elig' => $elig,
            'config' => ['csrf' => csrf_token(), 'base' => base_path_url(), 'sale_id' => $elig['sale']['id'], 'methods' => explode(',', (string)Settings::get('payment_methods'))]]);
    }

    public function store(Request $r): array
    {
        $res = (new ReturnService($this->db))->process($r->json());
        return $this->ok($res + ['url' => url('/returns/' . $res['return_id']),
            'receipt_url' => $res['exchange'] ? url('/sales/' . $res['exchange']['sale_id'] . '/receipt', ['format' => Settings::get('printer_format')]) : null]);
    }

    public function show(Request $r): Response
    {
        $ret = (new ReturnService($this->db))->snapshot($r->params['id']);
        if (!$ret || empty($ret['id'])) {
            return Response::redirect('/returns');
        }
        if ($r->query('print') === '1') {
            return Response::html(View::render('pages/returns/credit_note', ['title' => $ret['return_no'], 'ret' => $ret, 'shop' => Settings::all()], null));
        }
        return $this->view('pages/returns/show', 'Credit note ' . $ret['return_no'], ['ret' => $ret]);
    }
}
