<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database;
use App\Services\CatalogService;
use App\Services\ProductArt;
use App\Services\ProductImageService;

/**
 * Adds a 50-product starter catalog (sample items, flagged "sample" in the product list) with size/colour variants,
 * opening stock and an original illustration per colour. Brands are fictional. Prices, stock, HSN codes and tax rules
 * are starting values — review them (and the tax rules with your accountant) before real billing.
 * Products whose code already exists are skipped, so running it twice is safe.
 */
final class CatalogSeeder
{
    private const A = ['S', 'M', 'L', 'XL', 'XXL'];
    private const W = ['30', '32', '34', '36', '38'];
    private const K = ['2-3Y', '4-5Y', '6-7Y', '8-9Y'];
    private const F = ['Free Size'];

    /** code, name, category, parent, brand, unit, hsn, tax, gender, fabric, sizes, colours, mrp, price, cost, art, pattern */
    public static function items(): array
    {
        return [
            // ---- Men
            ['MSH-101', "Men's Cotton Formal Shirt", 'Shirts', 'Men', 'UrbanLoom', 'pcs', '6205', 'APPAREL', 'men', 'Cotton', self::A, ['White', 'Sky Blue', 'Grey'], 1299, 999, 520, 'shirt', ''],
            ['MSH-102', "Men's Linen Casual Shirt", 'Shirts', 'Men', 'Marina Threads', 'pcs', '6205', 'APPAREL', 'men', 'Linen', ['M', 'L', 'XL'], ['Beige', 'Olive', 'White'], 1899, 1599, 860, 'shirt', ''],
            ['MSH-103', "Men's Check Shirt", 'Shirts', 'Men', 'UrbanLoom', 'pcs', '6205', 'APPAREL', 'men', 'Cotton', self::A, ['Blue', 'Red', 'Green'], 1199, 949, 470, 'shirt', 'checks'],
            ['MSH-104', "Men's Striped Office Shirt", 'Shirts', 'Men', 'Nila Fashions', 'pcs', '6205', 'APPAREL', 'men', 'Cotton blend', self::A, ['Sky Blue', 'White'], 1399, 1149, 560, 'shirt', 'stripes'],
            ['MTS-201', 'Round Neck Cotton T-Shirt', 'T-shirts', 'Men', 'Cotton Kural', 'pcs', '6109', 'APPAREL', 'men', 'Cotton', ['S', 'M', 'L', 'XL'], ['Black', 'White', 'Maroon', 'Grey'], 499, 399, 180, 'tshirt', ''],
            ['MTS-202', 'Polo T-Shirt', 'T-shirts', 'Men', 'UrbanLoom', 'pcs', '6109', 'APPAREL', 'men', 'Pique cotton', ['M', 'L', 'XL'], ['Navy', 'Red', 'White'], 799, 649, 310, 'polo', ''],
            ['MTS-203', 'Printed Graphic T-Shirt', 'T-shirts', 'Men', 'Cotton Kural', 'pcs', '6109', 'APPAREL', 'men', 'Cotton', ['S', 'M', 'L', 'XL'], ['Mustard', 'Olive', 'Black'], 599, 499, 220, 'tshirt', 'dots'],
            ['MJN-301', "Men's Slim Fit Jeans", 'Jeans & Trousers', 'Men', 'UrbanLoom', 'pcs', '6203', 'APPAREL', 'men', 'Denim', self::W, ['Navy', 'Black'], 1999, 1699, 900, 'jeans', ''],
            ['MJN-302', "Men's Regular Fit Jeans", 'Jeans & Trousers', 'Men', 'Nila Fashions', 'pcs', '6203', 'APPAREL', 'men', 'Denim', self::W, ['Blue', 'Grey'], 1799, 1499, 780, 'jeans', ''],
            ['MTR-303', "Men's Formal Trousers", 'Jeans & Trousers', 'Men', 'Marina Threads', 'pcs', '6203', 'APPAREL', 'men', 'Poly viscose', self::W, ['Black', 'Grey', 'Navy'], 1499, 1299, 650, 'trousers', ''],
            ['MTR-304', "Men's Cotton Chinos", 'Jeans & Trousers', 'Men', 'UrbanLoom', 'pcs', '6203', 'APPAREL', 'men', 'Cotton twill', self::W, ['Beige', 'Olive', 'Brown'], 1599, 1349, 690, 'trousers', ''],
            ['MTR-305', 'Track Pants', 'Jeans & Trousers', 'Men', 'Cotton Kural', 'pcs', '6103', 'APPAREL', 'men', 'Polyester', ['M', 'L', 'XL'], ['Black', 'Navy'], 799, 699, 330, 'leggings', ''],
            ['MET-401', 'Cotton Veshti with Gold Border', 'Ethnic Wear', 'Men', 'Kaveri Weaves', 'pcs', '6211', 'APPAREL', 'men', 'Cotton', self::F, ['Cream', 'White'], 699, 599, 280, 'veshti', ''],
            ['MET-402', 'Silk Veshti & Angavastram Set', 'Ethnic Wear', 'Men', 'Mylapore Silks', 'set', '6211', 'APPAREL', 'men', 'Art silk', self::F, ['Cream', 'Gold'], 2499, 2199, 1150, 'veshti', ''],
            ['MET-403', 'Checked Lungi', 'Ethnic Wear', 'Men', 'Cotton Kural', 'pcs', '6211', 'APPAREL', 'men', 'Cotton', self::F, ['Blue', 'Green', 'Maroon'], 399, 349, 160, 'veshti', 'checks'],
            ['MIW-501', "Men's Cotton Vest (Pack of 2)", 'Innerwear', 'Men', 'Cotton Kural', 'pcs', '6109', 'APPAREL', 'men', 'Cotton', ['S', 'M', 'L', 'XL'], ['White', 'Grey'], 349, 299, 140, 'vest', ''],
            // ---- Women
            ['WSR-601', 'Kanchipuram Pure Silk Saree', 'Sarees', 'Women', 'Mylapore Silks', 'pcs', '5007', 'FABRIC', 'women', 'Pure silk', self::F, ['Maroon', 'Green', 'Purple'], 12999, 10999, 7200, 'saree', 'zari'],
            ['WSR-602', 'Soft Silk Saree', 'Sarees', 'Women', 'Kaveri Weaves', 'pcs', '5007', 'FABRIC', 'women', 'Soft silk', self::F, ['Pink', 'Orange', 'Navy'], 4999, 4299, 2600, 'saree', 'zari'],
            ['WSR-603', 'Chettinad Cotton Saree', 'Sarees', 'Women', 'Thenral', 'pcs', '5208', 'FABRIC', 'women', 'Cotton', self::F, ['Mustard', 'Red', 'Green'], 1999, 1699, 950, 'saree', 'checks'],
            ['WSR-604', 'Printed Cotton Saree', 'Sarees', 'Women', 'Thenral', 'pcs', '5208', 'FABRIC', 'women', 'Cotton', self::F, ['Pink', 'Yellow', 'Sky Blue'], 1499, 1199, 640, 'saree', 'floral'],
            ['WSR-605', 'Georgette Party Wear Saree', 'Sarees', 'Women', 'Nila Fashions', 'pcs', '5407', 'FABRIC', 'women', 'Georgette', self::F, ['Black', 'Purple', 'Maroon'], 2999, 2499, 1400, 'saree', 'dots'],
            ['WKT-701', 'Rayon Printed Kurti', 'Kurtis & Sets', 'Women', 'Thenral', 'pcs', '6204', 'APPAREL', 'women', 'Rayon', ['S', 'M', 'L', 'XL', 'XXL'], ['Mustard', 'Navy', 'Red'], 999, 849, 420, 'kurti', 'floral'],
            ['WKT-702', 'Cotton Straight Kurti', 'Kurtis & Sets', 'Women', 'Nila Fashions', 'pcs', '6204', 'APPAREL', 'women', 'Cotton', ['S', 'M', 'L', 'XL'], ['Sky Blue', 'Pink', 'White'], 899, 749, 360, 'kurti', ''],
            ['WKT-703', 'Anarkali Kurti', 'Kurtis & Sets', 'Women', 'Marina Threads', 'pcs', '6204', 'APPAREL', 'women', 'Georgette', ['M', 'L', 'XL'], ['Maroon', 'Green', 'Black'], 2499, 2199, 1180, 'lehenga', 'zari'],
            ['WKT-704', 'Churidar Set (3 pc)', 'Kurtis & Sets', 'Women', 'Marina Threads', 'set', '6204', 'APPAREL', 'women', 'Cotton silk', ['M', 'L', 'XL'], ['Purple', 'Green', 'Pink'], 2499, 2199, 1250, 'kurti', 'zari'],
            ['WKT-705', 'Kurti with Palazzo Set', 'Kurtis & Sets', 'Women', 'Thenral', 'set', '6204', 'APPAREL', 'women', 'Rayon', ['S', 'M', 'L', 'XL'], ['Yellow', 'Sky Blue', 'Orange'], 1799, 1499, 780, 'kurti', 'dots'],
            ['WLG-801', 'Cotton Lycra Leggings', 'Leggings', 'Women', 'Cotton Kural', 'pcs', '6104', 'APPAREL', 'women', 'Cotton lycra', self::F, ['Black', 'White', 'Maroon', 'Navy'], 399, 349, 150, 'leggings', ''],
            ['WLG-802', 'Ankle Length Leggings', 'Leggings', 'Women', 'Nila Fashions', 'pcs', '6104', 'APPAREL', 'women', 'Viscose lycra', self::F, ['Beige', 'Green', 'Pink'], 449, 399, 175, 'leggings', ''],
            ['WBP-901', 'Silk Blouse Piece (per metre)', 'Blouse Pieces', 'Women', 'Mylapore Silks', 'm', '5007', 'FABRIC', 'women', 'Art silk', [], ['Maroon', 'Gold', 'Green'], 299, 269, 140, 'blouse', ''],
            ['WBP-902', 'Cotton Blouse Material (per metre)', 'Blouse Pieces', 'Women', 'Kaveri Weaves', 'm', '5208', 'FABRIC', 'women', 'Cotton', [], ['Red', 'Navy', 'Black'], 159, 149, 65, 'blouse', ''],
            ['WNW-911', 'Cotton Nighty', 'Nightwear', 'Women', 'Cotton Kural', 'pcs', '6208', 'APPAREL', 'women', 'Cotton', ['L', 'XL', 'XXL'], ['Purple', 'Sky Blue', 'Pink'], 599, 499, 230, 'nighty', 'floral'],
            ['WDP-921', 'Chiffon Dupatta', 'Dupattas', 'Women', 'Nila Fashions', 'pcs', '6214', 'APPAREL', 'women', 'Chiffon', self::F, ['Orange', 'Pink', 'Green', 'Yellow'], 699, 599, 260, 'dupatta', ''],
            ['WDP-922', 'Cotton Printed Stole', 'Dupattas', 'Women', 'Thenral', 'pcs', '6214', 'APPAREL', 'women', 'Cotton', self::F, ['Navy', 'Maroon'], 499, 429, 190, 'dupatta', 'floral'],
            // ---- Kids
            ['KFR-1001', 'Girls Party Frock', 'Kids Wear', 'Kids', 'Little Sprout', 'pcs', '6209', 'APPAREL', 'kids', 'Net & satin', self::K, ['Pink', 'Yellow', 'Purple'], 1199, 999, 480, 'frock', 'dots'],
            ['KFR-1002', 'Girls Cotton Frock', 'Kids Wear', 'Kids', 'Little Sprout', 'pcs', '6209', 'APPAREL', 'kids', 'Cotton', self::K, ['Sky Blue', 'Pink'], 799, 699, 330, 'frock', 'floral'],
            ['KLH-1003', 'Girls Pattu Pavadai (Lehenga)', 'Kids Wear', 'Kids', 'Mylapore Silks', 'set', '6209', 'APPAREL', 'kids', 'Art silk', self::K, ['Red', 'Green', 'Maroon'], 1999, 1699, 900, 'lehenga', 'zari'],
            ['KST-1004', 'Boys Shirt & Shorts Set', 'Kids Wear', 'Kids', 'Little Sprout', 'set', '6209', 'APPAREL', 'kids', 'Cotton', self::K, ['Blue', 'Green', 'Orange'], 899, 749, 360, 'kidset', ''],
            ['KTS-1005', 'Kids Round Neck T-Shirt', 'Kids Wear', 'Kids', 'Cotton Kural', 'pcs', '6111', 'APPAREL', 'kids', 'Cotton', self::K, ['Red', 'Yellow', 'Navy', 'Green'], 349, 299, 130, 'tshirt', ''],
            ['KRM-1006', 'Baby Cotton Romper', 'Kids Wear', 'Kids', 'Little Sprout', 'pcs', '6111', 'APPAREL', 'kids', 'Cotton', ['0-1Y', '1-2Y'], ['Yellow', 'Sky Blue', 'Pink'], 499, 449, 200, 'romper', ''],
            ['KJN-1007', 'Boys Denim Jeans', 'Kids Wear', 'Kids', 'UrbanLoom', 'pcs', '6203', 'APPAREL', 'kids', 'Denim', self::K, ['Blue', 'Black'], 899, 799, 390, 'jeans', ''],
            // ---- Home textiles
            ['HTW-1101', 'Cotton Bath Towel', 'Towels', 'Home Textiles', 'Cotton Kural', 'pcs', '6302', 'APPAREL', 'unisex', 'Terry cotton', [], ['White', 'Sky Blue', 'Pink', 'Beige'], 449, 399, 190, 'towel', ''],
            ['HTW-1102', 'Hand Towel', 'Towels', 'Home Textiles', 'Cotton Kural', 'pcs', '6302', 'APPAREL', 'unisex', 'Terry cotton', [], ['White', 'Yellow', 'Green'], 149, 129, 55, 'towel', ''],
            ['HTW-1103', 'Thorthu Cotton Towel (Pack of 2)', 'Towels', 'Home Textiles', 'Kaveri Weaves', 'pcs', '6302', 'APPAREL', 'unisex', 'Cotton', [], ['White', 'Red'], 199, 179, 80, 'towel', 'checks'],
            ['HBS-1201', 'Double Bedsheet with 2 Pillow Covers', 'Bedsheets', 'Home Textiles', 'Kaveri Weaves', 'set', '6302', 'APPAREL', 'unisex', 'Cotton', [], ['Purple', 'Sky Blue', 'Multi'], 1299, 1099, 560, 'bedsheet', 'floral'],
            ['HBS-1202', 'Single Bedsheet', 'Bedsheets', 'Home Textiles', 'Cotton Kural', 'pcs', '6302', 'APPAREL', 'unisex', 'Cotton', [], ['Green', 'Pink'], 799, 699, 330, 'bedsheet', 'checks'],
            ['HPC-1203', 'Pillow Covers (Pair)', 'Bedsheets', 'Home Textiles', 'Cotton Kural', 'set', '6302', 'APPAREL', 'unisex', 'Cotton', [], ['Cream', 'Sky Blue'], 349, 299, 130, 'pillow', ''],
            // ---- Fabrics (sold per metre)
            ['FAB-1301', 'Cotton Poplin Fabric (per metre)', 'Dress Material & Fabric', 'Fabrics', 'Cotton Kural', 'm', '5208', 'FABRIC', 'unisex', 'Cotton poplin', [], ['White', 'Blue', 'Cream'], 189, 169, 82, 'fabric', ''],
            ['FAB-1302', 'Printed Rayon Fabric (per metre)', 'Dress Material & Fabric', 'Fabrics', 'Thenral', 'm', '5407', 'FABRIC', 'women', 'Rayon', [], ['Red', 'Navy', 'Mustard'], 249, 229, 110, 'fabric', 'floral'],
            ['FAB-1303', 'Premium Shirting Fabric (per metre)', 'Dress Material & Fabric', 'Fabrics', 'Marina Threads', 'm', '5208', 'FABRIC', 'men', 'Giza cotton', [], ['Sky Blue', 'White', 'Grey'], 349, 299, 150, 'fabric', 'stripes'],
            ['FAB-1304', 'Suiting Fabric (per metre)', 'Dress Material & Fabric', 'Fabrics', 'Marina Threads', 'm', '5515', 'FABRIC', 'men', 'Poly wool', [], ['Navy', 'Grey', 'Black'], 599, 549, 290, 'fabric', ''],
        ];
    }

    public function __construct(private Database $db)
    {
    }

    public function run(): array
    {
        mt_srand(4242);
        $cat = new CatalogService($this->db);
        $created = 0;
        $skipped = 0;
        $skus = 0;
        foreach (self::items() as [$code, $name, $category, $parent, $brand, $unit, $hsn, $tax, $gender, $fabric, $sizes, $colors, $mrp, $price, $cost, $art, $pattern]) {
            if ($this->db->value('SELECT 1 FROM products WHERE code = ?', [$code])) {
                $skipped++;
                continue;
            }
            $parentId = $this->master('categories', $parent);
            $catId = $this->master('categories', $category, ['parent_id' => $parentId]);
            $brandId = $this->master('brands', $brand);
            $rows = [];
            foreach ($sizes ?: [null] as $s) {
                foreach ($colors as $c) {
                    $qty = $unit === 'm' ? (string)mt_rand(30, 90) : (string)mt_rand(3, 12);
                    if (in_array($art, ['saree'], true) && $mrp > 4000) {
                        $qty = (string)mt_rand(2, 5);
                    }
                    $rows[] = ['size_id' => $s ? $this->sizeId($s) : null, 'color_id' => $this->colorId($c), 'mrp' => (string)$mrp, 'price' => (string)$price,
                        'cost' => (string)$cost, 'opening_qty' => $qty];
                }
            }
            $pid = $cat->saveProduct(null, ['name' => $name, 'code' => $code, 'unit' => $unit, 'category_id' => $catId, 'brand_id' => $brandId,
                'hsn_code' => $hsn, 'tax_rule_id' => $this->db->value('SELECT id FROM tax_rules WHERE code = ?', [$tax]), 'gender' => $gender,
                'fabric' => $fabric, 'reorder_level' => $unit === 'm' ? '10' : '2',
                'description' => "$name — $fabric. Sample catalog item: review price, stock and tax before billing."], $rows);
            // illustrations: one per colour; the product shows its first colour
            $first = null;
            foreach ($colors as $c) {
                $path = ProductImageService::writeArt($code . '-' . $c, ProductArt::svg($art, ProductArt::hexFor($c), $pattern));
                $first ??= $path;
                $this->db->run('UPDATE product_variants SET image_path = ? WHERE product_id = ? AND color_id = ?', [$path, $pid, $this->colorId($c)]);
            }
            $this->db->update('products', ['image_path' => $first, 'is_demo' => 1], 'id = :id', ['id' => $pid]);
            $created++;
            $skus += count($rows);
        }
        return ['created' => $created, 'skipped' => $skipped, 'skus' => $skus];
    }

    private function master(string $table, string $name, array $extra = []): string
    {
        $parent = $extra['parent_id'] ?? null;
        $id = $table === 'categories'
            ? $this->db->value('SELECT id FROM categories WHERE name = ? AND IFNULL(parent_id, \'\') = ?', [$name, $parent ?? ''])
            : $this->db->value("SELECT id FROM $table WHERE name = ?", [$name]);
        if ($id) {
            return (string)$id;
        }
        $id = uuid();
        $this->db->insert($table, ['id' => $id, 'name' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()] + $extra);
        return $id;
    }

    private function sizeId(string $label): string
    {
        $id = $this->db->value("SELECT id FROM sizes WHERE label = ? ORDER BY CASE size_system WHEN 'apparel' THEN 0 WHEN 'waist' THEN 1 WHEN 'age' THEN 2 ELSE 3 END LIMIT 1", [$label]);
        if (!$id) {
            $id = uuid();
            $this->db->insert('sizes', ['id' => $id, 'size_system' => 'other', 'label' => $label, 'sort_order' => 50, 'is_active' => 1]);
        }
        return (string)$id;
    }

    private function colorId(string $name): string
    {
        $id = $this->db->value('SELECT id FROM colors WHERE name = ?', [$name]);
        if (!$id) {
            $id = uuid();
            $this->db->insert('colors', ['id' => $id, 'name' => $name, 'hex' => ProductArt::hexFor($name), 'is_active' => 1]);
        }
        return (string)$id;
    }
}
