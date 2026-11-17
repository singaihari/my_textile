<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Money;
use App\Core\Qty;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\InventoryService;

final class StockController extends Controller
{
    public function index(Request $r): Response
    {
        $loc = $r->query('loc') === 'DAMAGED' ? 'DAMAGED' : 'MAIN';
        $p = ['loc' => Context::locationId($loc)];
        $where = ["p.status = 'active'"];
        $q = trim((string)$r->query('q', ''));
        if ($q !== '') {
            $where[] = '(p.name LIKE :q OR v.sku LIKE :q OR v.barcode = :exact OR p.code LIKE :q)';
            $p['q'] = '%' . $q . '%';
            $p['exact'] = $q;
        }
        $filter = (string)$r->query('filter', '');
        if ($filter === 'low') {
            $where[] = 'COALESCE(v.reorder_level_milli, p.reorder_level_milli) > 0 AND IFNULL(sb.qty_milli,0) <= COALESCE(v.reorder_level_milli, p.reorder_level_milli)';
        } elseif ($filter === 'zero') {
            $where[] = 'IFNULL(sb.qty_milli,0) <= 0';
        } elseif ($filter === 'instock') {
            $where[] = 'IFNULL(sb.qty_milli,0) > 0';
        }
        $sql = "SELECT v.id, v.sku, v.barcode, p.id product_id, p.name, p.unit, s.label size, c.name color, v.design_no, v.price_paise,
                IFNULL(sb.qty_milli,0) qty, IFNULL(sb.avg_cost_paise, v.cost_paise) avg_cost, COALESCE(v.reorder_level_milli, p.reorder_level_milli) reorder, sb.last_in_at, sb.last_out_at
            FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN sizes s ON s.id = v.size_id LEFT JOIN colors c ON c.id = v.color_id
            LEFT JOIN stock_balances sb ON sb.variant_id = v.id AND sb.location_id = :loc
            WHERE " . implode(' AND ', $where) . ' ORDER BY p.name, s.sort_order, c.name';
        $page = View::paginate($this->db, $sql, $p, $this->page($r), 40);
        $totals = $this->db->one('SELECT SUM(sb.qty_milli * sb.avg_cost_paise / 1000) cost, SUM(sb.qty_milli * v.price_paise / 1000) retail
            FROM stock_balances sb JOIN product_variants v ON v.id = sb.variant_id WHERE sb.location_id = ? AND sb.qty_milli > 0', [Context::locationId($loc)]);
        return $this->view('pages/stock/index', 'Stock', compact('page', 'q', 'loc', 'filter', 'totals'));
    }

    public function movements(Request $r): Response
    {
        $p = [];
        $where = ['1=1'];
        if ($v = $r->query('variant')) {
            $where[] = 'm.variant_id = :v';
            $p['v'] = $v;
        }
        if ($t = $r->query('type')) {
            $where[] = 'm.movement_type = :t';
            $p['t'] = $t;
        }
        $sql = 'SELECT m.*, v.sku, pr.name, pr.unit, l.code location, u.full_name user FROM stock_movements m JOIN product_variants v ON v.id = m.variant_id
            JOIN products pr ON pr.id = v.product_id JOIN locations l ON l.id = m.location_id LEFT JOIN users u ON u.id = m.user_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY m.created_at DESC, m.id DESC';
        $page = View::paginate($this->db, $sql, $p, $this->page($r), 50);
        $variant = !empty($p['v']) ? $this->db->one('SELECT v.sku, p.name FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?', [$p['v']]) : null;
        return $this->view('pages/stock/movements', 'Stock movements', compact('page', 'variant'));
    }

    public function adjustForm(Request $r): Response
    {
        $recent = $this->db->all('SELECT a.*, u.full_name, (SELECT COUNT(*) FROM stock_adjustment_items WHERE adjustment_id = a.id) lines FROM stock_adjustments a JOIN users u ON u.id = a.created_by ORDER BY a.created_at DESC LIMIT 15');
        return $this->view('pages/stock/adjust', 'Stock adjustment', ['recent' => $recent, 'csrf' => csrf_token()]);
    }

    public function adjust(Request $r): Response
    {
        $items = [];
        foreach ((array)$r->input('items', []) as $it) {
            if (empty($it['variant_id']) || trim((string)($it['qty'] ?? '')) === '') {
                continue;
            }
            $unit = (string)$this->db->value('SELECT p.unit FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?', [$it['variant_id']]);
            if ($unit === '') {
                throw new ValidationException('Unknown item in adjustment');
            }
            try {
                $q = Qty::parse((string)$it['qty'], $unit);
                $cost = trim((string)($it['cost'] ?? '')) !== '' ? Money::parse((string)$it['cost']) : null;
            } catch (\InvalidArgumentException $e) {
                throw new ValidationException($e->getMessage());
            }
            if ($cost !== null && !Auth::can('product.view_cost')) {
                $cost = null;
            }
            $items[] = ['variant_id' => $it['variant_id'], 'qty_change_milli' => $q, 'unit_cost_paise' => $cost, 'location' => ($it['location'] ?? 'MAIN') === 'DAMAGED' ? 'DAMAGED' : 'MAIN'];
        }
        $id = (new InventoryService($this->db))->adjust($r->str('reason_code'), $r->str('notes'), $items, Auth::id());
        flash('success', 'Stock adjustment ' . $this->db->value('SELECT adj_no FROM stock_adjustments WHERE id = ?', [$id]) . ' posted.');
        return Response::redirect('/stock/adjust');
    }
}
