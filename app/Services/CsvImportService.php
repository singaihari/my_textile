<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Money;
use App\Core\Qty;
use App\Core\ValidationException;

/**
 * Bulk product CSV import/export. Two steps: preview (parse + validate, nothing saved) then commit.
 * Columns: product_code,product_name,category,brand,unit,hsn_code,tax_code,gender,fabric,size,color,design_no,
 *          sku,barcode,mrp,selling_price,cost,opening_qty,reorder_level
 */
final class CsvImportService
{
    public const COLUMNS = ['product_code', 'product_name', 'category', 'brand', 'unit', 'hsn_code', 'tax_code', 'gender', 'fabric',
        'size', 'color', 'design_no', 'sku', 'barcode', 'mrp', 'selling_price', 'cost', 'opening_qty', 'reorder_level'];

    public function __construct(private Database $db)
    {
    }

    public function preview(string $csvPath): array
    {
        $fh = fopen($csvPath, 'r');
        if (!$fh) {
            throw new ValidationException('Cannot read file');
        }
        $header = fgetcsv($fh);
        if (!$header) {
            throw new ValidationException('Empty CSV');
        }
        $header = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h))), $header);
        $missing = array_diff(['product_code', 'product_name', 'unit', 'mrp', 'selling_price'], $header);
        if ($missing) {
            throw new ValidationException('Missing required columns: ' . implode(', ', $missing));
        }
        $rows = [];
        $line = 1;
        $seenSku = [];
        $seenBarcode = [];
        while (($cells = fgetcsv($fh)) !== false) {
            $line++;
            if (count(array_filter($cells, fn($c) => trim((string)$c) !== '')) === 0) {
                continue;
            }
            if ($line > 20001) {
                throw new ValidationException('Maximum 20,000 rows per import');
            }
            $r = [];
            foreach (self::COLUMNS as $c) {
                $i = array_search($c, $header, true);
                $r[$c] = $i === false ? '' : trim((string)($cells[$i] ?? ''));
            }
            $errors = [];
            $r['unit'] = strtolower($r['unit'] ?: 'pcs');
            if (!array_key_exists($r['unit'], Qty::UNITS)) $errors[] = 'unit must be pcs, set or m';
            if ($r['product_code'] === '') $errors[] = 'product_code required';
            if ($r['product_name'] === '') $errors[] = 'product_name required';
            foreach (['mrp', 'selling_price', 'cost'] as $m) {
                if ($r[$m] !== '') {
                    try {
                        Money::parse($r[$m]);
                    } catch (\InvalidArgumentException) {
                        $errors[] = "$m invalid";
                    }
                }
            }
            if (!$errors && $r['selling_price'] !== '' && $r['mrp'] !== '' && Money::parse($r['selling_price']) > Money::parse($r['mrp'])) $errors[] = 'selling_price above mrp';
            if ($r['opening_qty'] !== '' && !$errors) {
                try {
                    Qty::parse($r['opening_qty'], $r['unit']);
                } catch (\InvalidArgumentException $e) {
                    if ($r['opening_qty'] !== '0') $errors[] = 'opening_qty: ' . $e->getMessage();
                }
            }
            $skuU = strtoupper($r['sku']);
            if ($skuU !== '') {
                if (isset($seenSku[$skuU])) $errors[] = "duplicate SKU in file (line {$seenSku[$skuU]})";
                $seenSku[$skuU] = $line;
            }
            if ($r['barcode'] !== '') {
                if (isset($seenBarcode[$r['barcode']])) $errors[] = "duplicate barcode in file (line {$seenBarcode[$r['barcode']]})";
                $seenBarcode[$r['barcode']] = $line;
                $owner = $this->db->value('SELECT sku FROM product_variants WHERE barcode = ?', [$r['barcode']]);
                if ($owner && strtoupper($owner) !== $skuU) $errors[] = "barcode already used by $owner";
            }
            $existing = $skuU !== '' ? $this->db->value('SELECT id FROM product_variants WHERE sku = ?', [$skuU]) : null;
            $r['_action'] = $existing ? 'update prices' : 'create';
            $r['_line'] = $line;
            $r['_errors'] = $errors;
            $rows[] = $r;
        }
        fclose($fh);
        $bad = count(array_filter($rows, fn($r) => $r['_errors']));
        return ['rows' => $rows, 'total' => count($rows), 'errors' => $bad, 'products' => count(array_unique(array_column($rows, 'product_code')))];
    }

    public function commit(array $rows): array
    {
        if (array_filter($rows, fn($r) => $r['_errors'])) {
            throw new ValidationException('Fix the errors in the file and preview again before importing');
        }
        $cat = new CatalogService($this->db);
        $groups = [];
        foreach ($rows as $r) {
            $groups[strtoupper($r['product_code'])][] = $r;
        }
        $created = 0;
        $updated = 0;
        $this->db->transaction(function (Database $db) use ($groups, $cat, &$created, &$updated) {
            foreach ($groups as $code => $rs) {
                $first = $rs[0];
                $pid = $db->value('SELECT id FROM products WHERE code = ?', [$code]);
                $productData = [
                    'name' => $first['product_name'], 'code' => $code, 'unit' => $first['unit'],
                    'category_id' => $this->masterId('categories', $first['category']),
                    'brand_id' => $this->masterId('brands', $first['brand']),
                    'hsn_code' => $first['hsn_code'], 'gender' => $first['gender'], 'fabric' => $first['fabric'],
                    'tax_rule_id' => $first['tax_code'] !== '' ? $db->value('SELECT id FROM tax_rules WHERE code = ?', [$first['tax_code']]) : null,
                    'reorder_level' => $first['reorder_level'],
                ];
                $variants = [];
                if ($pid) {
                    $snap = $cat->productSnapshot($pid);
                    foreach ($snap['variants'] as $v) {
                        $variants[strtoupper($v['sku'])] = ['id' => $v['id'], 'sku' => $v['sku'], 'barcode' => $v['barcode'], 'size_id' => $v['size_id'],
                            'color_id' => $v['color_id'], 'pattern' => $v['pattern'], 'design_no' => $v['design_no'], 'batch_no' => $v['batch_no'],
                            'mrp' => Money::toDecimal((int)$v['mrp_paise']), 'price' => Money::toDecimal((int)$v['price_paise']),
                            'cost' => Money::toDecimal((int)$v['cost_paise']), 'status' => $v['status']];
                    }
                    $productData = array_merge(array_intersect_key($snap, array_flip(['category_id', 'brand_id', 'gender', 'fabric', 'hsn_code', 'tax_rule_id'])), array_filter($productData, fn($x) => $x !== '' && $x !== null));
                    $productData['reorder_level'] = $first['reorder_level'] !== '' ? $first['reorder_level'] : Qty::format((int)$snap['reorder_level_milli'], $snap['unit']);
                }
                foreach ($rs as $r) {
                    $sku = strtoupper($r['sku']);
                    $row = ['sku' => $sku, 'barcode' => $r['barcode'], 'size_id' => $this->sizeId($r['size']), 'color_id' => $this->colorId($r['color']),
                        'design_no' => $r['design_no'], 'mrp' => $r['mrp'], 'price' => $r['selling_price'], 'cost' => $r['cost'], 'opening_qty' => $r['opening_qty']];
                    if ($sku !== '' && isset($variants[$sku])) {
                        $variants[$sku] = array_merge($variants[$sku], array_filter(['mrp' => $r['mrp'], 'price' => $r['selling_price'], 'cost' => $r['cost']], fn($x) => $x !== ''));
                        $updated++;
                    } else {
                        $variants[$sku !== '' ? $sku : uniqid('new', true)] = $row;
                        $created++;
                    }
                }
                $cat->saveProduct($pid ?: null, $productData, array_values($variants));
            }
        });
        \App\Core\Audit::log('product.imported', 'product', null, ['created' => $created, 'updated' => $updated]);
        return ['created' => $created, 'updated' => $updated, 'products' => count($groups)];
    }

    private function masterId(string $table, string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $id = $this->db->value("SELECT id FROM $table WHERE name = ?", [$name]);
        if (!$id) {
            $id = uuid();
            $row = ['id' => $id, 'name' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()];
            $this->db->insert($table, $row);
        }
        return $id;
    }

    private function sizeId(string $label): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        $id = $this->db->value('SELECT id FROM sizes WHERE label = ? ORDER BY size_system LIMIT 1', [$label]);
        if (!$id) {
            $id = uuid();
            $this->db->insert('sizes', ['id' => $id, 'size_system' => 'other', 'label' => $label, 'sort_order' => 99, 'is_active' => 1]);
        }
        return $id;
    }

    private function colorId(string $name): ?string
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $id = $this->db->value('SELECT id FROM colors WHERE name = ?', [$name]);
        if (!$id) {
            $id = uuid();
            $this->db->insert('colors', ['id' => $id, 'name' => $name, 'hex' => null, 'is_active' => 1]);
        }
        return $id;
    }

    public function export(): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, self::COLUMNS);
        $loc = \App\Core\Context::locationId('MAIN');
        foreach ($this->db->all("SELECT p.code, p.name, c.name cat, b.name brand, p.unit, p.hsn_code, t.code tax, p.gender, p.fabric, s.label size, co.name color,
                v.design_no, v.sku, v.barcode, v.mrp_paise, v.price_paise, v.cost_paise, IFNULL(sb.qty_milli,0) qty, COALESCE(v.reorder_level_milli, p.reorder_level_milli) ro
            FROM product_variants v JOIN products p ON p.id = v.product_id LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN tax_rules t ON t.id = p.tax_rule_id LEFT JOIN sizes s ON s.id = v.size_id LEFT JOIN colors co ON co.id = v.color_id
            LEFT JOIN stock_balances sb ON sb.variant_id = v.id AND sb.location_id = ? ORDER BY p.code, v.sku", [$loc]) as $r) {
            fputcsv($out, [$r['code'], $r['name'], $r['cat'], $r['brand'], $r['unit'], $r['hsn_code'], $r['tax'], $r['gender'], $r['fabric'], $r['size'], $r['color'],
                $r['design_no'], $r['sku'], $r['barcode'], Money::toDecimal((int)$r['mrp_paise']), Money::toDecimal((int)$r['price_paise']),
                \App\Core\Auth::can('product.view_cost') ? Money::toDecimal((int)$r['cost_paise']) : '', Qty::format((int)$r['qty'], $r['unit']), Qty::format((int)$r['ro'], $r['unit'])]);
        }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }
}
