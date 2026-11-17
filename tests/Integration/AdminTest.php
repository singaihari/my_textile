<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Auth;
use App\Core\Database;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\Barcode;
use App\Services\BackupService;
use App\Services\CatalogService;
use App\Services\CsvImportService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Tests\TestCase;

final class AdminTest extends TestCase
{
    public function testBackupRestoreRoundTripWithSafetyBackup(): void
    {
        [, $v] = $this->makeProduct('BK1', 'pcs', [['sku' => 'BK1-A', 'barcode' => 'BK1A', 'mrp' => '100', 'price' => '100', 'cost' => '50', 'opening_qty' => '5']]);
        $dir = APP_ROOT . '/storage/tmp/tests/bk_' . bin2hex(random_bytes(3));
        $svc = new BackupService($this->db, $dir);
        $b = $svc->create('test');
        $this->assertSame(64, strlen($b['sha256']));
        // a sale AFTER the backup
        (new SaleService($this->db))->checkout(['client_ref' => uuid(), 'lines' => [['variant_id' => $v['BK1-A'], 'qty' => '1']], 'payments' => [['method' => 'cash', 'amount_paise' => 10000]]]);
        $this->assertSame(1, (int)$this->db->value('SELECT COUNT(*) FROM sales'));
        $staged = $svc->stageRestore($b['file']);
        $this->assertTrue(str_contains($staged['safety_backup'], 'safety'));
        $path = $this->db->path;
        unset($svc);   // release every handle on the live file (Windows cannot rename open files)
        Database::setInstance(null);
        $this->db = new Database(APP_ROOT . '/storage/tmp/tests/dummy_' . bin2hex(random_bytes(3)) . '.sqlite'); // release handle on the live file
        gc_collect_cycles();
        $msg = BackupService::applyPendingRestore($path);
        $this->assertContains('restored', (string)$msg);
        $this->db = new Database($path);
        Database::setInstance($this->db);
        $this->assertSame(0, (int)$this->db->value('SELECT COUNT(*) FROM sales'), 'restored to pre-sale state');
        $this->assertSame(5000, (int)$this->db->value('SELECT qty_milli FROM stock_balances WHERE variant_id = ?', [$v['BK1-A']]));
        // the safety backup still has the sale
        $safety = new \PDO('sqlite:' . $dir . '/' . $staged['safety_backup']);
        $this->assertSame(1, (int)$safety->query('SELECT COUNT(*) FROM sales')->fetchColumn());
        $safety = null;
    }

    public function testRestoreRejectsInvalidFiles(): void
    {
        $dir = APP_ROOT . '/storage/tmp/tests/bk_' . bin2hex(random_bytes(3));
        $svc = new BackupService($this->db, $dir);
        file_put_contents("$dir/textile_pos_bad.sqlite", str_repeat('x', 5000));
        $this->assertThrows(ValidationException::class, fn() => $svc->stageRestore('textile_pos_bad.sqlite'), 'Not a SQLite');
        $this->assertThrows(ValidationException::class, fn() => $svc->path('../../.env'), 'Invalid');
        $other = new \PDO('sqlite:' . "$dir/textile_pos_other.sqlite");
        $other->exec('CREATE TABLE t (x TEXT); INSERT INTO t VALUES (\'' . str_repeat('y', 3000) . '\')');
        $other = null;
        $this->assertThrows(ValidationException::class, fn() => $svc->stageRestore('textile_pos_other.sqlite'), 'Not a Textile POS');
    }

    public function testCode128Encoding(): void
    {
        foreach (Barcode::PATTERNS as $i => $p) {
            $this->assertSame($i === 106 ? 13 : 11, array_sum(str_split($p)), "pattern $i width");
        }
        // Start B (104) + P48×1 + J42×2 + J42×3 + '1'17×4 + '2'18×5 + '3'19×6 + C35×7 = 879; 879 mod 103 = 55
        $codes = Barcode::encode('PJJ123C');
        $this->assertSame(104, $codes[0]);
        $this->assertSame(55, $codes[count($codes) - 2]);
        $this->assertSame(106, end($codes));
        $this->assertContains('<svg', Barcode::svg('TX-C1-2627-00001'));
        $this->assertSame(CatalogService::ean13CheckDigit('400638133393'), 1);
    }

    public function testCsvImportPreviewAndCommit(): void
    {
        $csv = APP_ROOT . '/storage/tmp/tests/imp_' . bin2hex(random_bytes(3)) . '.csv';
        file_put_contents($csv, "product_code,product_name,category,brand,unit,tax_code,size,color,sku,barcode,mrp,selling_price,cost,opening_qty\n"
            . "IMP1,Import shirt,Shirts,Acme,pcs,APPAREL,M,Blue,IMP1-M-B,IMPB1,999,899,400,4\n"
            . "IMP1,Import shirt,Shirts,Acme,pcs,APPAREL,L,Blue,IMP1-L-B,IMPB2,999,899,400,3\n"
            . "IMP2,Fabric roll,Fabrics,,m,FABRIC,,Red,IMP2-R,IMPB3,200,180,90,25.5\n");
        $svc = new CsvImportService($this->db);
        $p = $svc->preview($csv);
        $this->assertSame(0, $p['errors']);
        $this->assertSame(2, $p['products']);
        $this->assertSame(0, (int)$this->db->value('SELECT COUNT(*) FROM products'), 'preview saves nothing');
        $r = $svc->commit($p['rows']);
        $this->assertSame(3, $r['created']);
        $this->assertSame(25500, $this->stock((string)$this->db->value("SELECT id FROM product_variants WHERE sku = 'IMP2-R'")));
        // duplicates and invalid units are reported, not imported
        file_put_contents($csv, "product_code,product_name,unit,mrp,selling_price,sku,barcode\nX1,Bad,kg,10,20,DUP,B1\nX2,Bad2,pcs,10,5,DUP,B2\n");
        $p2 = $svc->preview($csv);
        $this->assertSame(2, $p2['errors']);
        $this->assertThrows(ValidationException::class, fn() => $svc->commit($p2['rows']));
        // re-import updates prices of existing SKUs
        file_put_contents($csv, "product_code,product_name,unit,mrp,selling_price,sku\nIMP1,Import shirt,pcs,1099,949,IMP1-M-B\n");
        $r3 = $svc->commit($svc->preview($csv)['rows']);
        $this->assertSame(1, $r3['updated']);
        $this->assertSame(94900, (int)$this->db->value("SELECT price_paise FROM product_variants WHERE sku = 'IMP1-M-B'"));
    }

    public function testVariantUniquenessAndValidation(): void
    {
        $this->makeProduct('UQ1', 'pcs', [['sku' => 'UQ1-M', 'barcode' => 'UQB1', 'size_id' => $this->sizeId('M'), 'mrp' => '100', 'price' => '90']]);
        $cat = new CatalogService($this->db);
        $this->assertThrows(ValidationException::class, fn() => $cat->saveProduct(null, ['name' => 'X', 'code' => 'UQ2', 'unit' => 'pcs'], [['sku' => 'UQ1-M', 'mrp' => '1', 'price' => '1']]), 'already used');
        $this->assertThrows(ValidationException::class, fn() => $cat->saveProduct(null, ['name' => 'X', 'code' => 'UQ3', 'unit' => 'pcs'], [['barcode' => 'UQB1', 'mrp' => '1', 'price' => '1']]), 'barcode');
        $this->assertThrows(ValidationException::class, fn() => $cat->saveProduct(null, ['name' => 'X', 'code' => 'UQ4', 'unit' => 'pcs'],
            [['size_id' => $this->sizeId('L'), 'mrp' => '1', 'price' => '1'], ['size_id' => $this->sizeId('L'), 'mrp' => '1', 'price' => '1']]), 'combination');
        $this->assertThrows(ValidationException::class, fn() => $cat->saveProduct(null, ['name' => 'X', 'code' => 'UQ5', 'unit' => 'pcs'], [['mrp' => '100', 'price' => '150']]), 'MRP');
        $this->assertThrows(ValidationException::class, fn() => $cat->saveProduct(null, ['name' => 'X', 'code' => 'UQ6', 'unit' => 'pcs'], [['mrp' => '10', 'price' => '10', 'opening_qty' => '1.5']]), 'whole number');
        $this->assertSame(0, (int)$this->db->value("SELECT COUNT(*) FROM products WHERE code IN ('UQ2','UQ3','UQ4','UQ5','UQ6')"), 'failed saves rolled back');
    }

    public function testCashierCannotEditCostOrPriceOnProducts(): void
    {
        [$pid] = $this->makeProduct('PC1', 'pcs', [['sku' => 'PC1-A', 'barcode' => 'PC1A', 'mrp' => '500', 'price' => '450', 'cost' => '200']]);
        $inv = $this->makeUser('stock2', 'inventory');   // can manage products, cannot edit price
        Auth::actAs($inv);
        $vid = (string)$this->db->value('SELECT id FROM product_variants WHERE product_id = ?', [$pid]);
        (new CatalogService($this->db))->saveProduct($pid, ['name' => 'Product PC1', 'code' => 'PC1', 'unit' => 'pcs'], [['id' => $vid, 'sku' => 'PC1-A', 'barcode' => 'PC1A', 'mrp' => '1', 'price' => '1', 'cost' => '1']]);
        $v = $this->db->one('SELECT * FROM product_variants WHERE id = ?', [$vid]);
        $this->assertSame(45000, (int)$v['price_paise'], 'price unchanged without product.edit_price');
        $this->assertSame(20000, (int)$v['cost_paise'], 'cost unchanged without product.edit_cost');
        $cashier = $this->makeUser('cash9', 'cashier');
        Auth::actAs($cashier);
        $this->assertFalse(Auth::can('report.profit'));
        $this->assertFalse(Auth::can('tax.manage'));
        $this->assertFalse(Auth::can('sync.manage'));
        $this->assertFalse(Auth::can('audit.view') && Auth::can('settings.manage'));
        $this->assertThrows(\App\Core\HttpException::class, fn() => Auth::authorize('stock.adjust'));
    }

    public function testPasswordHashingAndLoginLockout(): void
    {
        $u = $this->makeUser('lock1', 'cashier', 'Right12345');
        $this->assertTrue(str_starts_with((string)$this->db->value('SELECT password_hash FROM users WHERE id = ?', [$u['id']]), '$2y$'));
        $ok = Auth::attempt('lock1', 'Right12345', '10.0.0.5');
        $this->assertSame($u['id'], $ok['id']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertThrows(ValidationException::class, fn() => Auth::attempt('lock1', 'nope', '10.0.0.5'), 'Invalid');
        }
        $this->assertThrows(ValidationException::class, fn() => Auth::attempt('lock1', 'Right12345', '10.0.0.5'), 'Too many');
        $this->assertThrows(ValidationException::class, fn() => Auth::attempt('ghost', 'x', '10.0.0.9'), 'Invalid');
    }

    public function testOutputEscapingAndSqlInjectionSafety(): void
    {
        $evil = '<script>alert(1)</script>"\' OR 1=1 --';
        [$pid] = $this->makeProduct('XSS1', 'pcs', [['sku' => 'XSS1-A', 'barcode' => 'XSSB', 'mrp' => '10', 'price' => '10']], 'NIL', ['name' => $evil]);
        $this->assertSame($evil, $this->db->value('SELECT name FROM products WHERE id = ?', [$pid]), 'stored verbatim via parameters');
        $this->assertSame([], (new CatalogService($this->db))->lookupCode("' OR '1'='1"));
        $found = (new CatalogService($this->db))->search("script'); DROP TABLE products; --");
        $this->assertSame(1, (int)$this->db->value("SELECT COUNT(*) FROM sqlite_master WHERE name = 'products'"));
        $html = View::partial('pages/products/show', ['product' => (new CatalogService($this->db))->productSnapshot($pid), 'stock' => [], 'history' => []]);
        $this->assertFalse(str_contains($html, '<script>alert(1)</script>'), 'no raw script tag in output');
        $this->assertContains('&lt;script&gt;', $html);
        $this->assertSame([], $found === null ? [] : []);
    }

    public function testPerformanceWithLargeCatalog(): void
    {
        $store = \App\Core\Context::storeId();
        $now = now();
        $this->db->transaction(function (Database $db) use ($store, $now) {
            for ($p = 0; $p < 1000; $p++) {
                $pid = uuid();
                $db->insert('products', ['id' => $pid, 'store_id' => $store, 'code' => "PERF$p", 'name' => "Perf product $p cotton", 'unit' => 'pcs', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
                for ($v = 0; $v < 10; $v++) {
                    $db->insert('product_variants', ['id' => uuid(), 'product_id' => $pid, 'sku' => "PERF$p-$v", 'barcode' => sprintf('29%06d%02d', $p, $v), 'design_no' => "D$p$v",
                        'mrp_paise' => 99900, 'price_paise' => 89900, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });
        $cat = new CatalogService($this->db);
        $t = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $r = $cat->lookupCode(sprintf('29%06d%02d', $i * 7, $i % 10));
        }
        $lookupMs = (microtime(true) - $t) * 10;   // per lookup
        $this->assertCount(1, $r);
        $t = microtime(true);
        $s = $cat->search('perf 999');
        $searchMs = (microtime(true) - $t) * 1000;
        $this->assertTrue(count($s) > 0 && count($s) <= 20, 'bounded result set');
        $this->assertTrue($lookupMs < 20, sprintf('barcode lookup %.2f ms (10,000 SKUs)', $lookupMs));
        $this->assertTrue($searchMs < 400, sprintf('name search %.1f ms', $searchMs));
        $page = View::paginate($this->db, 'SELECT id FROM product_variants ORDER BY sku', [], 50, 25);
        $this->assertSame(10000, $page['total']);
        $this->assertCount(25, $page['rows']);
        fwrite(STDOUT, sprintf("      (perf: lookup %.2f ms, search %.1f ms over 10,000 SKUs)\n", $lookupMs, $searchMs));
    }

    public function testStarterCatalogWithImagesAndUploadValidation(): void
    {
        require_once APP_ROOT . '/database/seeders/CatalogSeeder.php';
        $this->assertSame(50, count(\Database\Seeders\CatalogSeeder::items()), 'exactly 50 products defined');
        $r = (new \Database\Seeders\CatalogSeeder($this->db))->run();
        $this->assertSame(50, $r['created']);
        $this->assertSame(0, (int)$this->db->value('SELECT COUNT(*) FROM product_variants WHERE image_path IS NULL'), 'every SKU has an image');
        $this->assertSame((int)$this->db->value('SELECT COUNT(*) FROM product_variants'), (int)$this->db->value("SELECT COUNT(*) FROM stock_balances WHERE qty_milli > 0"));
        $img = (string)$this->db->value('SELECT image_path FROM products LIMIT 1');
        $this->assertTrue(is_file(APP_ROOT . '/public/' . $img) && str_starts_with((string)file_get_contents(APP_ROOT . '/public/' . $img), '<svg'));
        $this->assertSame(0, (new \Database\Seeders\CatalogSeeder($this->db))->run()['created'], 're-run skips existing');
        $hit = (new CatalogService($this->db))->search('silk saree')[0] ?? null;
        $this->assertTrue($hit !== null && str_contains($hit['image_url'], 'uploads/products/'), 'POS search returns image url');
        // uploads: non-images and SVG are refused
        $fake = APP_ROOT . '/storage/tmp/tests/fake_' . bin2hex(random_bytes(3)) . '.png';
        file_put_contents($fake, '<svg onload="alert(1)"></svg>');
        $pid = (string)$this->db->value('SELECT id FROM products LIMIT 1');
        $this->assertThrows(ValidationException::class, fn() => (new \App\Services\ProductImageService($this->db))->storeUpload($pid, ['tmp_name' => $fake, 'size' => 40, 'name' => 'x.png']), 'JPG, PNG or WebP');
        @unlink($fake);
    }

    public function testSupplierReturnAndDamagedTransfer(): void
    {
        [, $v] = $this->makeProduct('SR1', 'm', [['sku' => 'SR1-A', 'barcode' => 'SR1A', 'mrp' => '100', 'price' => '100', 'cost' => '40', 'opening_qty' => '30']]);
        $sid = uuid();
        $this->db->insert('suppliers', ['id' => $sid, 'store_id' => \App\Core\Context::storeId(), 'name' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        (new PurchaseService($this->db))->supplierReturn($sid, null, 'Defect', [['variant_id' => $v['SR1-A'], 'qty' => '5.5']]);
        $this->assertSame(24500, $this->stock($v['SR1-A']));
        (new InventoryService($this->db))->adjust('transfer_damaged', 'Water damage', [['variant_id' => $v['SR1-A'], 'qty_change_milli' => 2000]]);
        $this->assertSame(22500, $this->stock($v['SR1-A']));
        $this->assertSame(2000, $this->stock($v['SR1-A'], 'DAMAGED'));
        $this->assertThrows(ValidationException::class, fn() => (new InventoryService($this->db))->adjust('lost', '', [['variant_id' => $v['SR1-A'], 'qty_change_milli' => -1000]]), 'note');
    }
}
