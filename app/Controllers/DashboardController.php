<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Services\OutboxService;
use App\Services\ReportService;

final class DashboardController extends Controller
{
    public function index(Request $r): Response
    {
        if (!Auth::can('report.sales')) {
            return Auth::can('pos.sell') ? Response::redirect('/pos') : Response::redirect('/products');
        }
        [$from, $to, $fs, $ts] = ReportService::range($r->query('from', today()), $r->query('to', today()));
        $q = fn(string $sql, array $p = []) => (int)$this->db->value($sql, $p);
        $stats = [
            'sales' => $q("SELECT IFNULL(SUM(total_paise),0) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$fs, $ts]),
            'bills' => $q("SELECT COUNT(*) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$fs, $ts]),
            'pieces' => $q("SELECT IFNULL(SUM(si.qty_milli),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status = 'completed' AND si.unit <> 'm' AND s.created_at BETWEEN ? AND ?", [$fs, $ts]),
            'metres' => $q("SELECT IFNULL(SUM(si.qty_milli),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE s.status = 'completed' AND si.unit = 'm' AND s.created_at BETWEEN ? AND ?", [$fs, $ts]),
            'discounts' => $q("SELECT IFNULL(SUM(line_discount_paise + bill_discount_paise),0) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$fs, $ts]),
            'returns' => $q("SELECT IFNULL(SUM(total_paise),0) FROM returns WHERE return_type <> 'void' AND created_at BETWEEN ? AND ?", [$fs, $ts]),
            'returns_count' => $q("SELECT COUNT(*) FROM returns WHERE return_type <> 'void' AND created_at BETWEEN ? AND ?", [$fs, $ts]),
            'low_stock' => $q("SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN stock_balances sb ON sb.variant_id = v.id AND sb.location_id = ?
                WHERE p.status = 'active' AND v.status = 'active' AND COALESCE(v.reorder_level_milli, p.reorder_level_milli) > 0 AND IFNULL(sb.qty_milli,0) <= COALESCE(v.reorder_level_milli, p.reorder_level_milli)", [Context::locationId()]),
        ];
        $payments = $this->db->all("SELECT p.method, SUM(p.amount_paise) amount FROM payments p JOIN sales s ON s.id = p.sale_id
            WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY p.method", [$fs, $ts]);
        $profit = Auth::can('report.profit') ? (new ReportService($this->db))->profit($fs, $ts) : null;
        $latest = $this->db->all("SELECT s.id, s.invoice_no, s.created_at, s.total_paise, s.items_count, s.status, s.customer_name, u.full_name cashier,
                (SELECT status FROM sync_outbox o WHERE o.entity_type = 'sale' AND o.entity_id = s.id AND o.op = 'created') AS sync_status
            FROM sales s JOIN users u ON u.id = s.cashier_id ORDER BY s.created_at DESC LIMIT 8");
        $lowItems = (new ReportService($this->db))->run('low_stock', $from, $to)['rows'];
        return $this->view('pages/dashboard', 'Dashboard', compact('stats', 'payments', 'profit', 'latest', 'from', 'to') + [
            'sync' => (new OutboxService($this->db))->counts(), 'lowItems' => array_slice($lowItems, 0, 6),
        ]);
    }

    /** Chart datasets — all computed from saved transactions. */
    public function data(Request $r): array
    {
        Auth::authorize('report.sales');
        $days = max(7, min(366, (int)$r->query('days', 30)));
        $fs = date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $ts = today() . ' 23:59:59';
        $daily = $this->db->all("SELECT date(created_at) d, SUM(total_paise) total, COUNT(*) bills FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ? GROUP BY d ORDER BY d", [$fs, $ts]);
        $map = array_column($daily, null, 'd');
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $series[] = ['d' => $d, 'total' => (int)($map[$d]['total'] ?? 0), 'bills' => (int)($map[$d]['bills'] ?? 0)];
        }
        $hourly = $this->db->all("SELECT CAST(strftime('%H', created_at) AS INTEGER) h, SUM(total_paise) total FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ? GROUP BY h ORDER BY h", [$fs, $ts]);
        $category = $this->db->all("SELECT IFNULL(c.name, 'Other') name, SUM(si.taxable_paise) total FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id
            LEFT JOIN categories c ON c.id = p.category_id WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY c.name ORDER BY total DESC LIMIT 8", [$fs, $ts]);
        $top = $this->db->all("SELECT si.product_name name, SUM(si.qty_milli) qty, SUM(si.taxable_paise) total FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY si.product_id ORDER BY total DESC LIMIT 8", [$fs, $ts]);
        $brands = $this->db->all("SELECT IFNULL(b.name, 'No brand') name, SUM(si.taxable_paise) total FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id
            LEFT JOIN brands b ON b.id = p.brand_id WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY b.name ORDER BY total DESC LIMIT 8", [$fs, $ts]);
        $profit = [];
        if (Auth::can('report.profit')) {
            $profit = $this->db->all("SELECT strftime('%Y-%m', created_at) m, SUM(taxable_paise) net, SUM(cogs_paise) cogs FROM sales WHERE status = 'completed' AND created_at >= ?
                GROUP BY m ORDER BY m", [date('Y-m-01 00:00:00', strtotime('-11 months'))]);
        }
        return $this->ok(compact('series', 'hourly', 'category', 'top', 'brands', 'profit'));
    }
}
