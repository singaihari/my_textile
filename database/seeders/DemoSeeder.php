<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Auth;
use App\Core\Clock;
use App\Core\Context;
use App\Core\Database;
use App\Core\Settings;
use App\Services\CashService;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\ExpenseService;
use App\Services\PurchaseService;
use App\Services\ReturnService;
use App\Services\SaleService;

/**
 * OPT-IN demo data with FICTIONAL brands, suppliers and customers. Products are flagged is_demo = 1 and the
 * whole app shows a "DEMO DATA" ribbon. Sales go through the real SaleService (same transactions as live billing),
 * with the application clock moved back so the dashboard has 30 days of history.
 * Never run this on a live shop database.
 */
final class DemoSeeder
{
    public function __construct(private Database $db)
    {
    }

    public function run(int $days = 30, int $billsPerDay = 6): array
    {
        if ((int)$this->db->value('SELECT COUNT(*) FROM sales') > 0) {
            throw new \RuntimeException('Demo data can only be loaded into an empty shop (bills already exist).');
        }
        mt_srand(20261114);
        $start = time() - $days * 86400;
        Clock::set($start - 3600);
        $db = $this->db;
        $id = fn(string $table, string $name, array $extra = []) => $this->master($table, $name, $extra);

        $men = $id('categories', 'Men');
        $women = $id('categories', 'Women');
        $kids = $id('categories', 'Kids');
        $home = $id('categories', 'Home textiles');
        $fab = $id('categories', 'Fabrics');
        $cat = [
            'shirts' => $id('categories', 'Shirts', ['parent_id' => $men]), 'jeans' => $id('categories', 'Jeans & trousers', ['parent_id' => $men]),
            'tees' => $id('categories', 'T-shirts', ['parent_id' => $men]), 'sarees' => $id('categories', 'Sarees', ['parent_id' => $women]),
            'kurtis' => $id('categories', 'Kurtis & churidars', ['parent_id' => $women]), 'leggings' => $id('categories', 'Leggings', ['parent_id' => $women]),
            'blouse' => $id('categories', 'Blouse pieces', ['parent_id' => $women]), 'kids' => $id('categories', 'Kids wear', ['parent_id' => $kids]),
            'towels' => $id('categories', 'Towels', ['parent_id' => $home]), 'bed' => $id('categories', 'Bedsheets', ['parent_id' => $home]),
            'fabric' => $id('categories', 'Dress material & fabric', ['parent_id' => $fab]),
        ];
        $brand = [];
        foreach (['Kaveri Weaves', 'Marina Threads', 'Thenral', 'UrbanLoom', 'Little Sprout', 'Cotton Kural'] as $b) {
            $brand[$b] = $id('brands', $b);
        }
        $tax = fn($c) => (string)$db->value('SELECT id FROM tax_rules WHERE code = ?', [$c]);
        $size = fn($l) => (string)$db->value('SELECT id FROM sizes WHERE label = ?', [$l]);
        $color = fn($n) => (string)$db->value('SELECT id FROM colors WHERE name = ?', [$n]);

        $catalog = new CatalogService($db);
        $defs = [
            ['DM-SH101', "Men's cotton formal shirt", 'shirts', 'UrbanLoom', 'pcs', '6205', 'APPAREL', ['S', 'M', 'L', 'XL', 'XXL'], ['White', 'Blue', 'Sky Blue'], 1299, 999, 520],
            ['DM-SH205', "Men's linen casual shirt", 'shirts', 'Marina Threads', 'pcs', '6205', 'APPAREL', ['M', 'L', 'XL'], ['Beige', 'Olive'], 1899, 1599, 860],
            ['DM-JN310', "Men's slim fit jeans", 'jeans', 'UrbanLoom', 'pcs', '6203', 'APPAREL', ['30', '32', '34', '36'], ['Navy', 'Black'], 1999, 1699, 900],
            ['DM-TS120', 'Round neck cotton T-shirt', 'tees', 'Cotton Kural', 'pcs', '6109', 'APPAREL', ['S', 'M', 'L', 'XL'], ['Black', 'White', 'Maroon', 'Grey'], 499, 399, 180],
            ['DM-SR501', 'Kanchipuram style silk saree', 'sarees', 'Kaveri Weaves', 'pcs', '5007', 'APPAREL', ['Free Size'], ['Maroon', 'Green', 'Gold'], 8999, 7999, 5200],
            ['DM-SR220', 'Printed cotton saree', 'sarees', 'Thenral', 'pcs', '5208', 'APPAREL', ['Free Size'], ['Pink', 'Yellow', 'Blue'], 1499, 1199, 640],
            ['DM-KT330', 'Rayon printed kurti', 'kurtis', 'Thenral', 'pcs', '6204', 'APPAREL', ['S', 'M', 'L', 'XL'], ['Mustard', 'Navy', 'Red'], 999, 849, 420],
            ['DM-CH410', 'Churidar set (3 pc)', 'kurtis', 'Marina Threads', 'set', '6204', 'APPAREL', ['M', 'L', 'XL'], ['Purple', 'Green'], 2499, 2199, 1250],
            ['DM-LG105', 'Cotton lycra leggings', 'leggings', 'Cotton Kural', 'pcs', '6104', 'APPAREL', ['Free Size'], ['Black', 'White', 'Maroon', 'Navy'], 399, 349, 150],
            ['DM-KD150', 'Girls frock', 'kids', 'Little Sprout', 'pcs', '6209', 'APPAREL', ['2-3Y', '4-5Y', '6-7Y'], ['Pink', 'Yellow'], 799, 699, 330],
            ['DM-KD160', 'Boys shirt & shorts set', 'kids', 'Little Sprout', 'set', '6209', 'APPAREL', ['2-3Y', '4-5Y', '6-7Y'], ['Blue', 'Green'], 899, 749, 360],
            ['DM-TW200', 'Cotton bath towel', 'towels', 'Cotton Kural', 'pcs', '6302', 'APPAREL', [], ['White', 'Blue', 'Pink'], 449, 399, 190],
            ['DM-BS300', 'Double bedsheet with 2 pillow covers', 'bed', 'Kaveri Weaves', 'set', '6302', 'APPAREL', [], ['Multi'], 1299, 1099, 560],
        ];
        $variants = [];
        foreach ($defs as [$code, $name, $c, $b, $unit, $hsn, $t, $sizes, $colors, $mrp, $price, $cost]) {
            $rows = [];
            foreach ($sizes ?: [null] as $s) {
                foreach ($colors as $col) {
                    $rows[] = ['size_id' => $s ? $size($s) : null, 'color_id' => $color($col), 'mrp' => (string)$mrp, 'price' => (string)$price, 'cost' => (string)$cost];
                }
            }
            $pid = $catalog->saveProduct(null, ['name' => $name, 'code' => $code, 'unit' => $unit, 'category_id' => $cat[$c], 'brand_id' => $brand[$b],
                'hsn_code' => $hsn, 'tax_rule_id' => $tax($t), 'reorder_level' => '3', 'fabric' => null], $rows);
            $db->update('products', ['is_demo' => 1], 'id = :id', ['id' => $pid]);
            foreach ($db->all('SELECT id, product_id FROM product_variants WHERE product_id = ?', [$pid]) as $v) {
                $variants[] = $v['id'];
            }
        }
        // metre-wise fabrics
        $fabrics = [
            ['DM-FB100', 'Cotton poplin fabric (per metre)', ['White', 'Blue', 'Cream'], 189, 169, 82],
            ['DM-FB200', 'Printed rayon fabric (per metre)', ['Multi'], 249, 229, 110],
            ['DM-BP050', 'Blouse piece fabric (per metre)', ['Maroon', 'Gold', 'Green'], 159, 149, 65],
        ];
        $metreVariants = [];
        foreach ($fabrics as [$code, $name, $colors, $mrp, $price, $cost]) {
            $rows = array_map(fn($col) => ['color_id' => $color($col), 'mrp' => (string)$mrp, 'price' => (string)$price, 'cost' => (string)$cost], $colors);
            $pid = $catalog->saveProduct(null, ['name' => $name, 'code' => $code, 'unit' => 'm', 'category_id' => $cat['fabric'], 'brand_id' => $brand['Cotton Kural'],
                'hsn_code' => '5208', 'tax_rule_id' => $tax('FABRIC'), 'reorder_level' => '10'], $rows);
            $db->update('products', ['is_demo' => 1], 'id = :id', ['id' => $pid]);
            foreach ($db->all('SELECT id FROM product_variants WHERE product_id = ?', [$pid]) as $v) {
                $metreVariants[] = $v['id'];
            }
        }

        // suppliers + posted purchases (stock-in)
        $sup = [];
        foreach ([['Erode Handloom Traders (demo)', '33AAAAA0000A1Z5'], ['Tiruppur Knit Mills (demo)', null], ['Surat Fabric House (demo)', null]] as [$n, $g]) {
            $sid = uuid();
            $db->insert('suppliers', ['id' => $sid, 'store_id' => Context::storeId(), 'name' => $n, 'gstin' => $g, 'phone' => '90000' . mt_rand(10000, 99999), 'created_at' => now(), 'updated_at' => now()]);
            $sup[] = $sid;
        }
        $ps = new PurchaseService($db);
        foreach (array_chunk($variants, 12) as $k => $chunk) {
            $items = [];
            foreach ($chunk as $vid) {
                $cost = (int)$db->value('SELECT cost_paise FROM product_variants WHERE id = ?', [$vid]);
                $items[] = ['variant_id' => $vid, 'qty' => (string)mt_rand(6, 14), 'unit_cost' => number_format($cost / 100, 2, '.', ''), 'tax_pct' => '5'];
            }
            $pid = $ps->saveDraft(null, ['supplier_id' => $sup[$k % 3], 'supplier_invoice_no' => 'DEMO-' . (100 + $k), 'supplier_invoice_date' => today(), 'tax_claimable' => 1, 'freight' => '150', 'amount_paid' => '0'], $items);
            $ps->post($pid);
        }
        $items = array_map(fn($vid) => ['variant_id' => $vid, 'qty' => (string)mt_rand(40, 80), 'unit_cost' => number_format((int)$db->value('SELECT cost_paise FROM product_variants WHERE id = ?', [$vid]) / 100, 2, '.', ''), 'tax_pct' => '5'], $metreVariants);
        $ps->post($ps->saveDraft(null, ['supplier_id' => $sup[2], 'supplier_invoice_no' => 'DEMO-FAB', 'tax_claimable' => 1, 'freight' => '0'], $items));

        // customers
        $cs = new CustomerService($db);
        $customers = [];
        foreach ([['Priya (demo)', '9800000001'], ['Karthik (demo)', '9800000002'], ['Meena (demo)', '9800000003'], ['Arun (demo)', '9800000004']] as [$n, $p]) {
            $customers[] = $cs->save(null, ['name' => $n, 'phone' => $p]);
        }

        // 30 days of bills through the real checkout
        $sales = new SaleService($db);
        $cash = new CashService($db);
        $made = 0;
        $saleIds = [];
        for ($d = 0; $d < $days; $d++) {
            $dayStart = strtotime(date('Y-m-d 09:30:00', $start + $d * 86400));
            Clock::set($dayStart);
            if (!$cash->current()) {
                $cash->open(200000, 'Demo day start');
            }
            $n = $billsPerDay + mt_rand(-2, 4) + (in_array((int)date('w', $dayStart), [0, 6], true) ? 4 : 0);
            for ($b = 0; $b < $n; $b++) {
                $hour = [10, 11, 11, 12, 13, 16, 17, 18, 18, 19, 19, 20][mt_rand(0, 11)];
                Clock::set(strtotime(date('Y-m-d', $dayStart) . sprintf(' %02d:%02d:%02d', $hour, mt_rand(0, 59), mt_rand(0, 59))));
                $lines = [];
                $count = mt_rand(1, 3);
                for ($i = 0; $i < $count; $i++) {
                    if (mt_rand(1, 8) === 1) {
                        $lines[] = ['variant_id' => $metreVariants[array_rand($metreVariants)], 'qty' => ['1.5', '2', '2.25', '2.75', '3'][mt_rand(0, 4)]];
                    } else {
                        $lines[] = ['variant_id' => $variants[array_rand($variants)], 'qty' => '1'];
                    }
                }
                $lines = array_values(array_reduce($lines, function ($c, $l) { $c[$l['variant_id']] ??= $l; return $c; }, []));
                try {
                    $quote = $sales->quote(['lines' => $lines]);
                    $method = ['cash', 'cash', 'upi', 'upi', 'card'][mt_rand(0, 4)];
                    $disc = mt_rand(1, 6) === 1 ? ['type' => 'percent', 'value' => 500] : null;
                    if ($disc) {
                        $quote = $sales->quote(['lines' => $lines, 'bill_discount' => $disc]);
                    }
                    $total = $quote['total_paise'];
                    $pay = $method === 'cash' ? intdiv($total + 9999, 10000) * 10000 : $total;   // customer hands over next ₹100 note
                    $r = $sales->checkout(['client_ref' => uuid(), 'lines' => $lines, 'bill_discount' => $disc,
                        'customer_id' => mt_rand(1, 4) === 1 ? $customers[array_rand($customers)] : null,
                        'payments' => [['method' => $method, 'amount_paise' => $pay, 'reference' => $method === 'cash' ? null : 'DEMO' . mt_rand(1000, 9999)]]]);
                    $saleIds[] = $r['sale_id'];
                    $made++;
                } catch (\Throwable) {
                    // out of stock on a random pick — skip (same rule as live billing)
                }
            }
            if ($d % 7 === 3) {
                Clock::set($dayStart + 7200);
                (new ExpenseService($db))->create(['expense_date' => today(), 'category_id' => $db->value("SELECT id FROM expense_categories WHERE name = 'Electricity'"),
                    'description' => 'Demo electricity bill', 'amount' => '1850', 'payment_method' => 'upi']);
            }
            Clock::set($dayStart + 11 * 3600);
            $sum = $cash->summary($cash->current()['id']);
            $cash->close($sum['expected'], 'Demo day end');
        }
        // a few returns
        foreach (array_slice($saleIds, 5, 3) as $sid) {
            $si = $db->one('SELECT id, unit FROM sale_items WHERE sale_id = ? LIMIT 1', [$sid]);
            Clock::set(strtotime($db->value('SELECT created_at FROM sales WHERE id = ?', [$sid])) + 86400);
            try {
                (new ReturnService($db))->process(['sale_id' => $sid, 'reason' => 'Demo: size issue', 'items' => [['sale_item_id' => $si['id'], 'qty' => $si['unit'] === 'm' ? '0.5' : '1', 'condition' => 'sellable']], 'refund_method' => 'cash']);
            } catch (\Throwable) {
            }
        }
        $rent = $db->value("SELECT id FROM expense_categories WHERE name = 'Rent'");
        Clock::set($start + 86400);
        (new ExpenseService($db))->create(['expense_date' => today(), 'category_id' => $rent, 'description' => 'Demo shop rent', 'amount' => '25000', 'payment_method' => 'bank']);
        Clock::set(null);
        Settings::set('demo_mode', '1');
        return ['products' => count($defs) + count($fabrics), 'variants' => count($variants) + count($metreVariants), 'bills' => $made];
    }

    private function master(string $table, string $name, array $extra = []): string
    {
        $id = $this->db->value("SELECT id FROM $table WHERE name = ?", [$name]);
        if ($id) {
            return (string)$id;
        }
        $id = uuid();
        $this->db->insert($table, ['id' => $id, 'name' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()] + $extra);
        return $id;
    }
}
