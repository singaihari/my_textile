<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Core\Money;
use App\Core\Qty;
use App\Core\ValidationException;

/** Products, variants, barcodes, POS lookups and price history. */
final class CatalogService
{
    public const VARIANT_SELECT = "
        SELECT v.id, v.product_id, v.sku, v.barcode, v.mrp_paise, v.price_paise, v.cost_paise, v.status AS variant_status,
               v.pattern, v.design_no, v.batch_no, v.size_id, v.color_id,
               p.name, p.code AS product_code, p.unit, p.hsn_code, p.discount_allowed, p.status AS product_status, p.is_unique_piece,
               s.label AS size_label, c.name AS color_name, c.hex AS color_hex, b.name AS brand_name, cat.name AS category_name,
               t.code AS tax_code, IFNULL(t.rate_bp, 0) AS rate_bp, t.threshold_paise, t.above_rate_bp,
               IFNULL(sb.qty_milli, 0) AS stock_milli, COALESCE(v.image_path, p.image_path) AS image_path
        FROM product_variants v
        JOIN products p ON p.id = v.product_id
        LEFT JOIN sizes s ON s.id = v.size_id
        LEFT JOIN colors c ON c.id = v.color_id
        LEFT JOIN brands b ON b.id = p.brand_id
        LEFT JOIN categories cat ON cat.id = p.category_id
        LEFT JOIN tax_rules t ON t.id = p.tax_rule_id
        LEFT JOIN stock_balances sb ON sb.variant_id = v.id AND sb.location_id = :loc";

    public function __construct(private Database $db)
    {
    }

    public static function variantLabel(array $v): string
    {
        return implode(' / ', array_filter([$v['size_label'] ?? null, $v['color_name'] ?? null, $v['pattern'] ?? null,
            !empty($v['design_no']) ? 'D#' . $v['design_no'] : null, !empty($v['batch_no']) ? 'Lot ' . $v['batch_no'] : null]));
    }

    /** Exact lookup by barcode (primary or alternate) or SKU. Returns variants (normally one). */
    public function lookupCode(string $code): array
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 64) {
            return [];
        }
        $loc = Context::locationId('MAIN');
        $rows = $this->db->all(self::VARIANT_SELECT . ' WHERE (v.barcode = :c OR v.sku = :c) ', ['loc' => $loc, 'c' => $code]);
        if (!$rows) {
            $rows = $this->db->all(self::VARIANT_SELECT . ' JOIN product_barcodes pb ON pb.variant_id = v.id WHERE pb.barcode = :c', ['loc' => $loc, 'c' => $code]);
        }
        return array_map([$this, 'present'], $rows);
    }

    /** Bounded search by name, product code, SKU prefix, design no, colour or size. */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $loc = Context::locationId('MAIN');
        $terms = array_slice(array_filter(preg_split('/\s+/', $q)), 0, 5);
        $where = ["p.status = 'active'", "v.status = 'active'"];
        $params = ['loc' => $loc];
        foreach ($terms as $i => $t) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $t) . '%';
            $params["t$i"] = $like;
            $params["p$i"] = str_replace(['%', '_'], ['\\%', '\\_'], $t) . '%';
            $where[] = "(p.name LIKE :t$i ESCAPE '\\' OR p.code LIKE :p$i ESCAPE '\\' OR v.sku LIKE :p$i ESCAPE '\\' OR v.barcode LIKE :p$i ESCAPE '\\'
                        OR v.design_no LIKE :p$i ESCAPE '\\' OR c.name LIKE :p$i ESCAPE '\\' OR s.label = :e$i OR b.name LIKE :p$i ESCAPE '\\')";
            $params["e$i"] = $t;
        }
        $sql = self::VARIANT_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.name, s.sort_order, c.name LIMIT ' . max(1, min(50, $limit));
        return array_map([$this, 'present'], $this->db->all($sql, $params));
    }

    /** Fetch variants for pricing, keyed by id. */
    public function variantsByIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }
        $ph = [];
        $params = ['loc' => Context::locationId('MAIN')];
        foreach ($ids as $i => $id) {
            $ph[] = ":id$i";
            $params["id$i"] = $id;
        }
        $rows = $this->db->all(self::VARIANT_SELECT . ' WHERE v.id IN (' . implode(',', $ph) . ')', $params);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['id']] = $this->present($r);
        }
        return $out;
    }

    public function present(array $r): array
    {
        $r['label'] = self::variantLabel($r);
        $r['stock'] = Qty::format((int)$r['stock_milli'], $r['unit']);
        $r['price'] = Money::format((int)$r['price_paise']);
        $r['mrp'] = Money::format((int)$r['mrp_paise']);
        $r['sellable'] = $r['product_status'] === 'active' && $r['variant_status'] === 'active';
        $r['image_url'] = product_image_url($r['image_path'] ?? null);
        if (!Auth::can('product.view_cost')) {
            unset($r['cost_paise']);
        }
        return $r;
    }

    // ---------------------------------------------------------------- product CRUD

    /**
     * Create or update a product family with its variants.
     * $data: product fields; $variants: list of [id?, sku, barcode, size_id, color_id, pattern, design_no, batch_no,
     *        mrp, price, cost, reorder_level, status, opening_qty]
     */
    public function saveProduct(?string $id, array $data, array $variants): string
    {
        $name = trim((string)($data['name'] ?? ''));
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        $unit = (string)($data['unit'] ?? 'pcs');
        $errors = [];
        if ($name === '') $errors['name'] = 'Product name is required';
        if (!preg_match('/^[A-Z0-9._-]{2,30}$/', $code)) $errors['code'] = 'Code: 2-30 letters/digits/dash';
        if (!array_key_exists($unit, Qty::UNITS)) $errors['unit'] = 'Choose a unit';
        if (!$variants) $errors['variants'] = 'Add at least one variant (size/colour) row';
        if ($errors) {
            throw new ValidationException('Please correct the highlighted fields.', $errors);
        }
        $dupCode = $this->db->value('SELECT id FROM products WHERE code = ? AND id <> ?', [$code, $id ?? '']);
        if ($dupCode) {
            throw new ValidationException("Product code $code already exists", ['code' => 'Already used']);
        }
        $canPrice = Auth::can('product.edit_price');
        $canCost = Auth::can('product.edit_cost');

        return $this->db->transaction(function (Database $db) use ($id, $data, $variants, $name, $code, $unit, $canPrice, $canCost) {
            $isNew = $id === null;
            $existing = $isNew ? null : $db->one('SELECT * FROM products WHERE id = ?', [$id]);
            if (!$isNew && !$existing) {
                throw new ValidationException('Product not found');
            }
            if (!$isNew && $existing['unit'] !== $unit && (int)$db->value('SELECT COUNT(*) FROM stock_movements m JOIN product_variants v ON v.id = m.variant_id WHERE v.product_id = ?', [$id]) > 0) {
                throw new ValidationException('Unit cannot be changed after stock movements exist');
            }
            $row = [
                'name' => $name, 'code' => $code, 'unit' => $unit,
                'category_id' => ($data['category_id'] ?? '') ?: null,
                'brand_id' => ($data['brand_id'] ?? '') ?: null,
                'gender' => ($data['gender'] ?? '') ?: null,
                'age_group' => ($data['age_group'] ?? '') ?: null,
                'fabric' => trim((string)($data['fabric'] ?? '')) ?: null,
                'collection' => trim((string)($data['collection'] ?? '')) ?: null,
                'style' => trim((string)($data['style'] ?? '')) ?: null,
                'description' => trim((string)($data['description'] ?? '')) ?: null,
                'hsn_code' => trim((string)($data['hsn_code'] ?? '')) ?: null,
                'tax_rule_id' => ($data['tax_rule_id'] ?? '') ?: null,
                'reorder_level_milli' => self::optionalQty($data['reorder_level'] ?? null, $unit, 'Reorder level') ?? 0,
                'is_unique_piece' => !empty($data['is_unique_piece']) ? 1 : 0,
                'discount_allowed' => array_key_exists('discount_allowed', $data) ? (!empty($data['discount_allowed']) ? 1 : 0) : 1,
                'status' => in_array($data['status'] ?? 'active', ['active', 'archived'], true) ? ($data['status'] ?? 'active') : 'active',
                'updated_at' => now(),
            ];
            if ($row['hsn_code'] !== null && !preg_match('/^\d{4,8}$/', $row['hsn_code'])) {
                throw new ValidationException('HSN code must be 4-8 digits', ['hsn_code' => '4-8 digits']);
            }
            if ($isNew) {
                $id = uuid();
                $db->insert('products', $row + ['id' => $id, 'store_id' => Context::storeId(), 'created_by' => Auth::id(), 'created_at' => now()]);
            } else {
                $db->update('products', $row, 'id = :id', ['id' => $id]);
            }

            $seenSku = [];
            $opening = [];
            foreach ($variants as $i => $v) {
                $n = $i + 1;
                $sku = strtoupper(trim((string)($v['sku'] ?? '')));
                $barcode = trim((string)($v['barcode'] ?? ''));
                if ($sku === '') {
                    $sku = $this->suggestSku($code, $v);
                }
                if ($barcode === '') {
                    $barcode = $this->generateBarcode();
                }
                if (!preg_match('/^[A-Z0-9._\/-]{2,40}$/', $sku)) {
                    throw new ValidationException("Row $n: SKU may only contain letters, digits and . _ - /");
                }
                if (!preg_match('/^[\x21-\x7E]{3,40}$/', $barcode)) {
                    throw new ValidationException("Row $n: barcode must be 3-40 printable characters without spaces");
                }
                if (isset($seenSku[$sku])) {
                    throw new ValidationException("Row $n: duplicate SKU $sku in this form");
                }
                $seenSku[$sku] = true;
                $vid = ($v['id'] ?? '') ?: null;
                $old = $vid ? $db->one('SELECT * FROM product_variants WHERE id = ? AND product_id = ?', [$vid, $id]) : null;
                if ($vid && !$old) {
                    throw new ValidationException("Row $n: variant does not belong to this product");
                }
                $mrp = $this->amountField($v, 'mrp', $old['mrp_paise'] ?? null, $canPrice || !$old, $n);
                $price = $this->amountField($v, 'price', $old['price_paise'] ?? null, $canPrice || !$old, $n);
                $cost = $this->amountField($v, 'cost', $old['cost_paise'] ?? 0, $canCost || !$old, $n, true);
                if ($price > $mrp) {
                    throw new ValidationException("Row $n: selling price cannot exceed MRP");
                }
                if ($price === 0) {
                    throw new ValidationException("Row $n: selling price must be greater than zero");
                }
                if ($cost > $price) {
                    // allowed (clearance), but recorded in audit
                    Audit::log('product.cost_above_price', 'variant', $vid, ['sku' => $sku]);
                }
                if ($db->value('SELECT id FROM product_variants WHERE sku = ? AND id <> ?', [$sku, $vid ?? ''])) {
                    throw new ValidationException("Row $n: SKU $sku is already used by another product");
                }
                if ($db->value('SELECT id FROM product_variants WHERE barcode = ? AND id <> ?', [$barcode, $vid ?? ''])
                    || $db->value('SELECT id FROM product_barcodes WHERE barcode = ? AND variant_id <> ?', [$barcode, $vid ?? ''])) {
                    throw new ValidationException("Row $n: barcode $barcode is already used");
                }
                $vrow = [
                    'sku' => $sku, 'barcode' => $barcode,
                    'size_id' => ($v['size_id'] ?? '') ?: null,
                    'color_id' => ($v['color_id'] ?? '') ?: null,
                    'pattern' => trim((string)($v['pattern'] ?? '')) ?: null,
                    'design_no' => trim((string)($v['design_no'] ?? '')) ?: null,
                    'batch_no' => trim((string)($v['batch_no'] ?? '')) ?: null,
                    'mrp_paise' => $mrp, 'price_paise' => $price, 'cost_paise' => $cost,
                    'reorder_level_milli' => self::optionalQty($v['reorder_level'] ?? null, $unit, "Row $n reorder level"),
                    'status' => in_array($v['status'] ?? 'active', ['active', 'archived'], true) ? ($v['status'] ?? 'active') : 'active',
                    'updated_at' => now(),
                ];
                try {
                    if ($old) {
                        $db->update('product_variants', $vrow, 'id = :id', ['id' => $vid]);
                        if ($old['mrp_paise'] != $mrp || $old['price_paise'] != $price || $old['cost_paise'] != $cost) {
                            $this->recordPriceHistory($vid, $old, $mrp, $price, $cost, 'manual');
                        }
                    } else {
                        $vid = uuid();
                        $db->insert('product_variants', $vrow + ['id' => $vid, 'product_id' => $id, 'created_at' => now()]);
                        $this->recordPriceHistory($vid, null, $mrp, $price, $cost, 'manual');
                    }
                } catch (\PDOException $e) {
                    if (str_contains($e->getMessage(), 'ux_variant_combo') || str_contains($e->getMessage(), 'UNIQUE')) {
                        throw new ValidationException("Row $n: the same size/colour/design combination already exists for this product");
                    }
                    throw $e;
                }
                foreach (array_filter(array_map('trim', explode(',', (string)($v['alt_barcodes'] ?? '')))) as $alt) {
                    if (!$db->value('SELECT 1 FROM product_barcodes WHERE barcode = ?', [$alt])) {
                        if ($db->value('SELECT 1 FROM product_variants WHERE barcode = ?', [$alt])) {
                            throw new ValidationException("Row $n: alternate barcode $alt is already a primary barcode");
                        }
                        $db->insert('product_barcodes', ['id' => uuid(), 'variant_id' => $vid, 'barcode' => $alt, 'kind' => 'supplier', 'created_at' => now()]);
                    }
                }
                $oq = trim((string)($v['opening_qty'] ?? ''));
                $oqMilli = self::optionalQty($oq, $unit, "Row $n opening qty");
                if (!$old && $oqMilli) {
                    if ($oqMilli < 0) {
                        throw new ValidationException("Row $n: opening quantity cannot be negative");
                    }
                    $opening[] = ['variant_id' => $vid, 'qty_change_milli' => $oqMilli, 'unit_cost_paise' => $cost, 'location' => 'MAIN'];
                }
            }
            if ($opening) {
                Auth::authorize('stock.adjust');
                (new InventoryService($db))->adjust('opening', 'Opening stock on product creation', $opening);
            }
            (new OutboxService($db))->record('product', $id, 'upserted', $this->productSnapshot($id));
            Audit::log($isNew ? 'product.created' : 'product.updated', 'product', $id, ['code' => $code]);
            return $id;
        });
    }

    /** Parse an optional quantity field ('' → null, '0' → 0) and turn format errors into user-facing validation errors. */
    private static function optionalQty(mixed $raw, string $unit, string $label): ?int
    {
        $s = trim((string)($raw ?? ''));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^0+(\.0*)?$/', $s)) {
            return 0;
        }
        try {
            return Qty::parse($s, $unit);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException("$label: " . $e->getMessage());
        }
    }

    private function amountField(array $v, string $key, ?int $oldValue, bool $editable, int $n, bool $allowZero = false): int
    {
        if (!$editable) {
            return (int)$oldValue;
        }
        $raw = trim((string)($v[$key] ?? ''));
        if ($raw === '') {
            if ($oldValue !== null) {
                return (int)$oldValue;
            }
            if ($allowZero) {
                return 0;
            }
            throw new ValidationException("Row $n: " . strtoupper($key) . ' is required');
        }
        try {
            $p = Money::parse($raw);
        } catch (\InvalidArgumentException) {
            throw new ValidationException("Row $n: invalid " . $key . " amount '$raw'");
        }
        if ($p < 0 || $p > 100_000_000) {
            throw new ValidationException("Row $n: " . $key . ' must be between 0 and 10,00,000');
        }
        return $p;
    }

    public function recordPriceHistory(string $variantId, ?array $old, int $mrp, int $price, int $cost, string $source): void
    {
        $this->db->insert('price_history', ['id' => uuid(), 'variant_id' => $variantId,
            'old_mrp_paise' => $old['mrp_paise'] ?? null, 'new_mrp_paise' => $mrp,
            'old_price_paise' => $old['price_paise'] ?? null, 'new_price_paise' => $price,
            'old_cost_paise' => $old['cost_paise'] ?? null, 'new_cost_paise' => $cost,
            'source' => $source, 'changed_by' => Auth::id(), 'changed_at' => now()]);
    }

    public function suggestSku(string $code, array $v): string
    {
        $parts = [$code];
        if (!empty($v['size_id'])) {
            $parts[] = (string)$this->db->value('SELECT label FROM sizes WHERE id = ?', [$v['size_id']]);
        }
        if (!empty($v['color_id'])) {
            $parts[] = substr((string)$this->db->value('SELECT name FROM colors WHERE id = ?', [$v['color_id']]), 0, 4);
        }
        if (!empty($v['design_no'])) {
            $parts[] = $v['design_no'];
        }
        $base = strtoupper(preg_replace('/[^A-Z0-9-]/i', '', implode('-', $parts)));
        $sku = $base;
        $i = 2;
        while ($this->db->value('SELECT 1 FROM product_variants WHERE sku = ?', [$sku])) {
            $sku = $base . '-' . $i++;
        }
        return $sku;
    }

    /** Internal EAN-13 style barcode in the "in-store" 20-29 prefix range with a valid check digit. */
    public function generateBarcode(): string
    {
        do {
            $body = '2' . (string)random_int(1, 9) . str_pad((string)random_int(0, 9_999_999_999), 10, '0', STR_PAD_LEFT);
            $code = $body . self::ean13CheckDigit($body);
        } while ($this->db->value('SELECT 1 FROM product_variants WHERE barcode = ? UNION SELECT 1 FROM product_barcodes WHERE barcode = ?', [$code, $code]));
        return $code;
    }

    public static function ean13CheckDigit(string $twelve): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$twelve[$i] * ($i % 2 ? 3 : 1);
        }
        return (10 - $sum % 10) % 10;
    }

    public function productSnapshot(string $id): array
    {
        $p = $this->db->one('SELECT p.*, c.name AS category_name, b.name AS brand_name, t.code AS tax_code FROM products p
            LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id LEFT JOIN tax_rules t ON t.id = p.tax_rule_id
            WHERE p.id = ?', [$id]);
        $p['variants'] = $this->db->all('SELECT v.*, s.label AS size_label, c.name AS color_name FROM product_variants v
            LEFT JOIN sizes s ON s.id = v.size_id LEFT JOIN colors c ON c.id = v.color_id WHERE v.product_id = ? ORDER BY v.created_at', [$id]);
        return $p;
    }

    public function archive(string $id, bool $archive = true): void
    {
        $this->db->transaction(function (Database $db) use ($id, $archive) {
            $n = $db->update('products', ['status' => $archive ? 'archived' : 'active', 'updated_at' => now()], 'id = :id', ['id' => $id]);
            if (!$n) {
                throw new ValidationException('Product not found');
            }
            (new OutboxService($db))->record('product', $id, 'upserted', $this->productSnapshot($id));
            Audit::log($archive ? 'product.archived' : 'product.restored', 'product', $id);
        });
    }
}
