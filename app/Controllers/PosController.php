<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Money;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\CashService;
use App\Services\CatalogService;
use App\Services\OutboxService;
use App\Services\SaleService;

final class PosController extends Controller
{
    public function index(Request $r): Response
    {
        $config = [
            'csrf' => csrf_token(),
            'base' => base_path_url(),
            'user' => ['name' => Auth::user()['full_name'], 'max_discount_bp' => Auth::maxDiscountBp()],
            'perms' => array_values(array_filter(['pos.discount', 'pos.discount_override', 'pos.price_override', 'pos.save_no_print', 'pos.credit_sale',
                'pos.oversell', 'customer.manage', 'customer.history', 'return.create', 'cash.manage'], fn($p) => Auth::can($p))),
            'payment_methods' => array_values(array_filter(explode(',', (string)Settings::get('payment_methods')))),
            'merge_scans' => Settings::bool('merge_duplicate_scans'),
            'require_customer' => Settings::bool('require_customer'),
            'require_cash_session' => Settings::bool('require_cash_session'),
            'auto_print' => Settings::bool('auto_print'),
            'printer_format' => Settings::get('printer_format', '80mm'),
            'lock_minutes' => (int)Settings::get('session_lock_minutes', 15),
            'shortcuts' => self::shortcuts(),
            'device_code' => Context::device()['code'],
            'invoice_prefix' => Settings::get('invoice_prefix'),
            'categories' => $this->chipCategories(),
            'can_reprint' => Auth::can('pos.reprint'),
        ];
        return Response::html(View::render('pages/pos', ['title' => 'New Sale', 'config' => $config], 'layouts/pos'));
    }

    /** Category chips for the billing grid: top-level groups, then the busiest sub-categories. */
    private function chipCategories(): array
    {
        $top = $this->db->all("SELECT c.id, c.name, COUNT(DISTINCT p.id) n FROM categories c
            JOIN categories ch ON (ch.id = c.id OR ch.parent_id = c.id) JOIN products p ON p.category_id = ch.id AND p.status = 'active'
            WHERE c.parent_id IS NULL AND c.is_active = 1 GROUP BY c.id ORDER BY c.sort_order, n DESC");
        $subs = $this->db->all("SELECT c.id, c.name, COUNT(p.id) n FROM categories c JOIN products p ON p.category_id = c.id AND p.status = 'active'
            WHERE c.parent_id IS NOT NULL AND c.is_active = 1 GROUP BY c.id ORDER BY n DESC, c.name LIMIT 12");
        $seen = [];
        $out = [];
        foreach (array_merge($top, $subs) as $c) {
            $key = mb_strtolower($c['name']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = ['id' => $c['id'], 'name' => $c['name'], 'count' => (int)$c['n']];
            }
        }
        return $out;
    }

    /** Product families for the image grid (one card per product; variants chosen in a quick-pick dialog). */
    public function catalog(Request $r): array
    {
        $loc = Context::locationId('MAIN');
        $where = ["p.status = 'active'"];
        $params = ['loc' => $loc];
        $cat = (string)$r->query('cat', '');
        if ($cat !== '' && $cat !== 'offers') {
            $where[] = '(p.category_id = :cat OR c.parent_id = :cat)';
            $params['cat'] = $cat;
        }
        $q = trim((string)$r->query('q', ''));
        foreach (array_slice(array_filter(preg_split('/\s+/', $q)), 0, 5) as $i => $t) {
            $like = str_replace(['%', '_'], ['\\%', '\\_'], $t);
            $params["l$i"] = "%$like%";
            $params["s$i"] = "$like%";
            $params["x$i"] = $t;
            $where[] = "(p.name LIKE :l$i ESCAPE '\\' OR p.code LIKE :s$i ESCAPE '\\' OR c.name LIKE :l$i ESCAPE '\\' OR b.name LIKE :s$i ESCAPE '\\' OR p.fabric LIKE :l$i ESCAPE '\\'
                OR p.id IN (SELECT v2.product_id FROM product_variants v2 LEFT JOIN colors c2 ON c2.id = v2.color_id LEFT JOIN sizes s2 ON s2.id = v2.size_id
                    WHERE v2.sku LIKE :s$i ESCAPE '\\' OR v2.barcode = :x$i OR v2.design_no LIKE :s$i ESCAPE '\\' OR c2.name LIKE :s$i ESCAPE '\\' OR s2.label = :x$i))";
        }
        $having = $cat === 'offers' ? 'HAVING max_off_bp > 0' : '';
        $limit = max(1, min(120, (int)$r->query('limit', 48)));
        $offset = max(0, (int)$r->query('offset', 0));
        $rows = $this->db->all("SELECT p.id, p.name, p.code, p.unit, p.image_path, p.is_unique_piece, c.name AS category, b.name AS brand,
                COUNT(v.id) AS variants, COUNT(DISTINCT v.color_id) AS colors, COUNT(DISTINCT v.size_id) AS sizes,
                MIN(v.price_paise) AS min_price, MAX(v.price_paise) AS max_price, MAX(v.mrp_paise) AS max_mrp,
                MAX(CASE WHEN v.mrp_paise > v.price_paise THEN (v.mrp_paise - v.price_paise) * 10000 / v.mrp_paise ELSE 0 END) AS max_off_bp,
                SUM(CASE WHEN IFNULL(sb.qty_milli, 0) > 0 THEN sb.qty_milli ELSE 0 END) AS stock_milli,
                SUM(CASE WHEN IFNULL(sb.qty_milli, 0) <= 0 THEN 1 ELSE 0 END) AS out_variants,
                SUM(CASE WHEN IFNULL(sb.qty_milli, 0) > 0 AND IFNULL(sb.qty_milli, 0) <= COALESCE(v.reorder_level_milli, p.reorder_level_milli) THEN 1 ELSE 0 END) AS low_variants,
                MIN(v.id) AS first_variant_id, GROUP_CONCAT(DISTINCT co.hex) AS hexes, MIN(v.design_no) AS design_no
            FROM products p
            JOIN product_variants v ON v.product_id = p.id AND v.status = 'active'
            LEFT JOIN stock_balances sb ON sb.variant_id = v.id AND sb.location_id = :loc
            LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN colors co ON co.id = v.color_id
            WHERE " . implode(' AND ', $where) . " GROUP BY p.id $having
            ORDER BY (SUM(CASE WHEN IFNULL(sb.qty_milli, 0) > 0 THEN 1 ELSE 0 END) > 0) DESC, p.name COLLATE NOCASE
            LIMIT " . ($limit + 1) . " OFFSET $offset", $params);
        $more = count($rows) > $limit;
        $items = array_map(function ($p) {
            $p['image_url'] = product_image_url($p['image_path']);
            unset($p['image_path']);
            $p['hexes'] = array_slice(array_values(array_filter(explode(',', (string)$p['hexes']))), 0, 6);
            foreach (['variants', 'colors', 'sizes', 'min_price', 'max_price', 'max_mrp', 'max_off_bp', 'stock_milli', 'out_variants', 'low_variants', 'is_unique_piece'] as $k) {
                $p[$k] = (int)$p[$k];
            }
            return $p;
        }, array_slice($rows, 0, $limit));
        return $this->ok(['items' => $items, 'more' => $more, 'offset' => $offset]);
    }

    /** All sellable variants of one product (for the size / colour / design quick-pick dialog). */
    public function product(Request $r): array
    {
        $cat = new CatalogService($this->db);
        $rows = $this->db->all(CatalogService::VARIANT_SELECT . " WHERE v.product_id = :p AND v.status = 'active' AND p.status = 'active'
            ORDER BY s.sort_order, s.label, c.name, v.design_no", ['loc' => Context::locationId('MAIN'), 'p' => $r->params['id']]);
        if (!$rows) {
            throw new ValidationException('This product has no sellable variants');
        }
        $p = $this->db->one('SELECT id, name, code, unit, image_path, is_unique_piece FROM products WHERE id = ?', [$r->params['id']]);
        $p['image_url'] = product_image_url($p['image_path']);
        unset($p['image_path']);
        return $this->ok(['product' => $p, 'variants' => array_map([$cat, 'present'], $rows)]);
    }

    /** Recent bills from this counter (History panel). */
    public function recent(Request $r): array
    {
        return $this->ok(['items' => $this->db->all("SELECT s.id, s.invoice_no, s.created_at, s.total_paise, s.items_count, s.status, s.customer_name,
                (SELECT status FROM sync_outbox o WHERE o.entity_type = 'sale' AND o.entity_id = s.id AND o.op = 'created') AS sync_status
            FROM sales s WHERE s.device_id = ? ORDER BY s.created_at DESC LIMIT 30", [Context::deviceId()])]);
    }

    public static function shortcuts(): array
    {
        $defaults = ['help' => ['F1', 'Alt+1'], 'new' => ['F2', 'Alt+2'], 'search' => ['F3', 'Ctrl+K'], 'customer' => ['F4', 'Alt+4'],
            'discount' => ['F5', 'Ctrl+D', 'Alt+5'], 'payment' => ['F6', 'Alt+6'], 'hold' => ['F7', 'Alt+7'], 'resume' => ['F8', 'Alt+8'],
            'returns' => ['F9', 'Ctrl+R', 'Alt+9'], 'save' => ['F10', 'Ctrl+P', 'Alt+0', 'Ctrl+Enter'], 'history' => ['Ctrl+H']];
        $custom = json_decode((string)Settings::get('shortcuts', ''), true);
        if (is_array($custom)) {
            foreach ($custom as $k => $keys) {
                if (isset($defaults[$k]) && is_array($keys)) {
                    $defaults[$k] = array_values(array_filter(array_map('strval', $keys)));
                }
            }
        }
        return $defaults;
    }

    public function lookup(Request $r): array
    {
        $code = (string)$r->query('code', '');
        $rows = (new CatalogService($this->db))->lookupCode($code);
        if (!$rows) {
            Audit::log('pos.unknown_barcode', 'barcode', null, ['code' => mb_substr($code, 0, 40)]);
        }
        return $this->ok(['items' => $rows]);
    }

    public function search(Request $r): array
    {
        if (!Auth::can('pos.sell') && !Auth::can('product.view') && !Auth::can('purchase.manage')) {
            Auth::authorize('pos.sell');
        }
        return $this->ok(['items' => (new CatalogService($this->db))->search((string)$r->query('q', ''), (int)$r->query('limit', 20))]);
    }

    public function quote(Request $r): array
    {
        $q = (new SaleService($this->db))->quote($r->json());
        return $this->ok(['quote' => $this->publicQuote($q)]);
    }

    private function publicQuote(array $q): array
    {
        $keep = ['line_no', 'variant_id', 'sku', 'name', 'label', 'unit', 'qty_milli', 'unit_price_paise', 'mrp_paise', 'gross_paise', 'line_discount_paise',
            'bill_discount_alloc_paise', 'net_paise', 'tax_rate_bp', 'taxable_paise', 'tax_paise', 'line_total_paise', 'stock_milli', 'disc_type', 'disc_value', 'discount_allowed'];
        $q['lines'] = array_map(fn($l) => array_intersect_key($l, array_flip($keep)), $q['lines']);
        return $q;
    }

    public function checkout(Request $r): array
    {
        $in = $r->json();
        if (empty($in['print']) && !Auth::can('pos.save_no_print') && !Auth::peekApproval($in['approval_token'] ?? null, 'pos.save_no_print')) {
            throw new ValidationException('Saving without printing needs manager approval', ['approval' => 'pos.save_no_print']);
        }
        $res = (new SaleService($this->db))->checkout($in);
        return $this->ok($res + ['receipt_url' => url('/sales/' . $res['sale_id'] . '/receipt', ['format' => Settings::get('printer_format', '80mm')]),
            'pending_sync' => (new OutboxService($this->db))->pendingCount()]);
    }

    public function hold(Request $r): array
    {
        $in = $r->json();
        $lines = $in['lines'] ?? [];
        if (!$lines || !is_array($lines)) {
            throw new ValidationException('Nothing to hold');
        }
        $id = uuid();
        $cart = json_encode(['lines' => array_slice($lines, 0, 300), 'bill_discount' => $in['bill_discount'] ?? null,
            'customer' => $in['customer'] ?? null, 'interstate' => !empty($in['interstate'])], JSON_UNESCAPED_UNICODE);
        if (strlen($cart) > 500_000) {
            throw new ValidationException('Bill too large to hold');
        }
        $this->db->insert('held_sales', ['id' => $id, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(),
            'label' => mb_substr(trim((string)($in['label'] ?? '')), 0, 60) ?: null, 'customer_id' => $in['customer']['id'] ?? null,
            'cart_json' => $cart, 'items_count' => count($lines), 'total_estimate_paise' => (int)($in['total_paise'] ?? 0),
            'held_by' => Auth::id(), 'created_at' => now()]);
        Audit::log('pos.held', 'held_sale', $id, ['items' => count($lines)]);
        return $this->ok(['id' => $id]);
    }

    public function held(Request $r): array
    {
        return $this->ok(['items' => $this->db->all('SELECT h.id, h.label, h.items_count, h.total_estimate_paise, h.created_at, u.full_name held_by
            FROM held_sales h JOIN users u ON u.id = h.held_by WHERE h.device_id = ? ORDER BY h.created_at DESC LIMIT 50', [Context::deviceId()])]);
    }

    public function resume(Request $r): array
    {
        $h = $this->find('SELECT * FROM held_sales WHERE id = ? AND device_id = ?', [$r->params['id'], Context::deviceId()], 'Held bill');
        $this->db->run('DELETE FROM held_sales WHERE id = ?', [$h['id']]);
        Audit::log('pos.resumed', 'held_sale', $h['id']);
        return $this->ok(['cart' => json_decode($h['cart_json'], true)]);
    }

    public function deleteHeld(Request $r): array
    {
        $this->db->run('DELETE FROM held_sales WHERE id = ? AND device_id = ?', [$r->params['id'], Context::deviceId()]);
        Audit::log('pos.held_discarded', 'held_sale', $r->params['id']);
        return $this->ok();
    }

    /** Local status only — never contacts the internet. */
    public function status(Request $r): array
    {
        $cash = (new CashService($this->db))->current();
        return $this->ok([
            'pending_sync' => (new OutboxService($this->db))->pendingCount(),
            'open_conflicts' => (int)$this->db->value("SELECT COUNT(*) FROM sync_conflicts WHERE status = 'open'"),
            'cash_session' => $cash ? ['id' => $cash['id'], 'opened_at' => $cash['opened_at'], 'opened_by' => $cash['opened_by_name']] : null,
            'held' => (int)$this->db->value('SELECT COUNT(*) FROM held_sales WHERE device_id = ?', [Context::deviceId()]),
            'last_sync' => Settings::get('sync_last_success_at'),
            'next_invoice_hint' => Settings::get('invoice_prefix') . '-' . Context::device()['code'] . '-' . Context::fyCode(),
            'next_invoice' => (new \App\Services\DocNumberService($this->db))->peek('INV', (string)Settings::get('invoice_prefix', 'TX')),
        ]);
    }

    public function openCash(Request $r): array
    {
        $in = $r->json();
        try {
            $float = Money::parse((string)($in['opening'] ?? '0'));
        } catch (\InvalidArgumentException) {
            throw new ValidationException('Enter a valid opening cash amount');
        }
        $id = (new CashService($this->db))->open($float, null);
        return $this->ok(['id' => $id]);
    }
}
