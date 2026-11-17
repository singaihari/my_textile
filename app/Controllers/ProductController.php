<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\CatalogService;
use App\Services\CsvImportService;

final class ProductController extends Controller
{
    public function index(Request $r): Response
    {
        $where = ['1=1'];
        $p = ['loc' => Context::locationId()];
        $q = trim((string)$r->query('q', ''));
        if ($q !== '') {
            $where[] = "(p.name LIKE :q OR p.code LIKE :q OR EXISTS (SELECT 1 FROM product_variants v2 WHERE v2.product_id = p.id AND (v2.sku LIKE :q OR v2.barcode = :exact OR v2.design_no LIKE :q)))";
            $p['q'] = '%' . $q . '%';
            $p['exact'] = $q;
        }
        foreach (['category_id' => 'p.category_id', 'brand_id' => 'p.brand_id'] as $k => $col) {
            if ($v = $r->query($k)) {
                $where[] = "$col = :$k";
                $p[$k] = $v;
            }
        }
        $status = (string)$r->query('status', 'active');
        if (in_array($status, ['active', 'archived'], true)) {
            $where[] = 'p.status = :status';
            $p['status'] = $status;
        }
        $sort = match ($r->query('sort')) {
            'stock' => 'stock ASC',
            'new' => 'p.created_at DESC',
            'code' => 'p.code',
            default => 'p.name COLLATE NOCASE',
        };
        $sql = "SELECT p.*, c.name category, b.name brand, COUNT(v.id) variants, MIN(v.price_paise) min_price, MAX(v.price_paise) max_price,
                IFNULL(SUM(sb.qty_milli), 0) stock, SUM(CASE WHEN IFNULL(sb.qty_milli,0) <= COALESCE(v.reorder_level_milli, p.reorder_level_milli) AND COALESCE(v.reorder_level_milli, p.reorder_level_milli) > 0 THEN 1 ELSE 0 END) low
            FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN product_variants v ON v.product_id = p.id LEFT JOIN stock_balances sb ON sb.variant_id = v.id AND sb.location_id = :loc
            WHERE " . implode(' AND ', $where) . " GROUP BY p.id ORDER BY $sort";
        $page = View::paginate($this->db, $sql, $p, $this->page($r), 25);
        return $this->view('pages/products/index', 'Products', ['page' => $page, 'q' => $q, 'status' => $status,
            'categories' => $this->db->all('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name'),
            'brands' => $this->db->all('SELECT id, name FROM brands WHERE is_active = 1 ORDER BY name')]);
    }

    public function show(Request $r): Response
    {
        $cat = new CatalogService($this->db);
        $product = $cat->productSnapshot($r->params['id']);
        if (!$product || empty($product['id'])) {
            return Response::redirect('/products');
        }
        $stock = [];
        foreach ($this->db->all('SELECT sb.variant_id, l.code, sb.qty_milli, sb.avg_cost_paise FROM stock_balances sb JOIN locations l ON l.id = sb.location_id
            JOIN product_variants v ON v.id = sb.variant_id WHERE v.product_id = ?', [$product['id']]) as $s) {
            $stock[$s['variant_id']][$s['code']] = $s;
        }
        $history = $this->db->all('SELECT ph.*, v.sku, u.full_name FROM price_history ph JOIN product_variants v ON v.id = ph.variant_id LEFT JOIN users u ON u.id = ph.changed_by
            WHERE v.product_id = ? ORDER BY ph.changed_at DESC LIMIT 30', [$product['id']]);
        return $this->view('pages/products/show', $product['name'], compact('product', 'stock', 'history'));
    }

    public function form(Request $r): Response
    {
        $product = null;
        if (!empty($r->params['id'])) {
            $product = (new CatalogService($this->db))->productSnapshot($r->params['id']);
            if (empty($product['id'])) {
                return Response::redirect('/products');
            }
        }
        return $this->view('pages/products/form', $product ? 'Edit ' . $product['name'] : 'New product', [
            'product' => $product,
            'categories' => $this->db->all('SELECT c.id, CASE WHEN p.name IS NULL THEN c.name ELSE p.name || \' › \' || c.name END AS name FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.is_active = 1 ORDER BY name'),
            'brands' => $this->db->all('SELECT id, name FROM brands WHERE is_active = 1 ORDER BY name'),
            'sizes' => $this->db->all('SELECT id, size_system, label FROM sizes WHERE is_active = 1 ORDER BY size_system, sort_order, label'),
            'colors' => $this->db->all('SELECT id, name, hex FROM colors WHERE is_active = 1 ORDER BY name'),
            'taxRules' => $this->db->all('SELECT id, code, name, rate_bp, threshold_paise, above_rate_bp FROM tax_rules WHERE is_active = 1 ORDER BY code'),
        ]);
    }

    public function save(Request $r): Response
    {
        $id = $r->str('id') ?: null;
        $variants = $r->input('variants', []);
        if (!is_array($variants)) {
            $variants = [];
        }
        $variants = array_values(array_filter($variants, fn($v) => is_array($v) && (trim((string)($v['sku'] ?? '')) !== '' || trim((string)($v['price'] ?? '')) !== '' || !empty($v['id']))));
        $pid = (new CatalogService($this->db))->saveProduct($id, $r->all(), $variants);
        $img = new \App\Services\ProductImageService($this->db);
        if ($file = $r->file('image')) {
            $img->storeUpload($pid, $file);
        } elseif ($r->str('remove_image') === '1') {
            $img->setProductImage($pid, null);
        }
        flash('success', 'Product saved.');
        return Response::redirect($r->str('next') === 'new' ? '/products/new' : '/products/' . $pid);
    }

    public function archive(Request $r): Response
    {
        (new CatalogService($this->db))->archive($r->params['id'], $r->str('restore') !== '1');
        flash('success', $r->str('restore') === '1' ? 'Product restored.' : 'Product archived. It stays in old bills and reports but cannot be sold.');
        return Response::redirect('/products/' . $r->params['id']);
    }

    public function importForm(Request $r): Response
    {
        return $this->view('pages/products/import', 'Import products (CSV)', ['preview' => null]);
    }

    public function importPreview(Request $r): Response
    {
        $f = $r->file('csv') ?? throw new ValidationException('Choose a CSV file');
        if ($f['size'] > 10_000_000) {
            throw new ValidationException('File too large (max 10 MB)');
        }
        $preview = (new CsvImportService($this->db))->preview($f['tmp_name']);
        $token = bin2hex(random_bytes(8));
        $tmp = APP_ROOT . '/storage/tmp/import_' . $token . '.json';
        file_put_contents($tmp, json_encode($preview['rows']));
        $_SESSION['import_token'] = $token;
        return $this->view('pages/products/import', 'Import preview', ['preview' => $preview, 'token' => $token]);
    }

    public function importCommit(Request $r): Response
    {
        $token = $r->str('token');
        if (!preg_match('/^[a-f0-9]{16}$/', $token) || ($token !== ($_SESSION['import_token'] ?? ''))) {
            throw new ValidationException('Import session expired; upload the file again');
        }
        $file = APP_ROOT . '/storage/tmp/import_' . $token . '.json';
        $rows = json_decode((string)@file_get_contents($file), true);
        if (!is_array($rows)) {
            throw new ValidationException('Import session expired; upload the file again');
        }
        $res = (new CsvImportService($this->db))->commit($rows);
        @unlink($file);
        unset($_SESSION['import_token']);
        flash('success', "Imported {$res['products']} products: {$res['created']} new variants, {$res['updated']} price updates.");
        return Response::redirect('/products');
    }

    public function export(Request $r): Response
    {
        return Response::download((new CsvImportService($this->db))->export(), 'products_' . date('Ymd_His') . '.csv');
    }

    /** Printable barcode labels. ?v[]=variantId&n[]=copies  or ?product=id */
    public function labels(Request $r): Response
    {
        $ids = (array)$r->query('v', []);
        $copies = (array)$r->query('n', []);
        if ($pid = $r->query('product')) {
            foreach ($this->db->all('SELECT v.id, IFNULL(sb.qty_milli, 0) q FROM product_variants v LEFT JOIN stock_balances sb ON sb.variant_id = v.id AND sb.location_id = ? WHERE v.product_id = ? AND v.status = \'active\'',
                [Context::locationId(), $pid]) as $row) {
                $ids[] = $row['id'];
                $copies[] = max(1, intdiv((int)$row['q'], 1000));
            }
        }
        $variants = (new CatalogService($this->db))->variantsByIds(array_slice(array_map('strval', $ids), 0, 300));
        $labels = [];
        foreach ($ids as $i => $id) {
            if (isset($variants[$id])) {
                $labels[] = ['v' => $variants[$id], 'n' => max(1, min(200, (int)($copies[$i] ?? 1)))];
            }
        }
        return Response::html(View::render('pages/products/labels', ['title' => 'Barcode labels', 'labels' => $labels, 'shop' => Settings::all(),
            'preview' => $r->query('print') !== '1'], null));
    }
}
