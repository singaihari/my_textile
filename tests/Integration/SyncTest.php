<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Context;
use App\Core\Settings;
use App\Services\CustomerService;
use App\Services\OutboxService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Services\Sync\SyncService;
use Server\Db as ServerDb;
use Server\Kernel;
use Server\MasterData;
use Tests\Support\InProcessTransport;
use Tests\TestCase;

final class SyncTest extends TestCase
{
    private ServerDb $server;
    private string $serverPath;
    private InProcessTransport $transport;
    private array $v;

    public function setUp(): void
    {
        parent::setUp();
        $this->serverPath = APP_ROOT . '/storage/tmp/tests/srv_' . bin2hex(random_bytes(5)) . '.sqlite';
        if (getenv('TEST_SERVER_DB') === 'mysql') {
            // e.g. TEST_SERVER_DB=mysql TEST_MYSQL_PORT=3307 TEST_MYSQL_DB=tpos_test (database is wiped per test!)
            $this->server = new ServerDb(['driver' => 'mysql', 'host' => getenv('TEST_MYSQL_HOST') ?: '127.0.0.1', 'port' => getenv('TEST_MYSQL_PORT') ?: '3306',
                'name' => getenv('TEST_MYSQL_DB') ?: 'tpos_test', 'user' => getenv('TEST_MYSQL_USER') ?: 'root', 'pass' => getenv('TEST_MYSQL_PASS') ?: '', 'path' => '']);
            $pdo = $this->server->pdo();
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
                $pdo->exec("DROP TABLE `$t`");
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            $this->server = new ServerDb(['driver' => 'sqlite', 'path' => $this->serverPath]);
        }
        $this->server->migrate(APP_ROOT . '/server-api/database/migrations/mysql');
        $token = Kernel::registerDevice($this->server, Context::storeId(), 'Test Textiles', Context::deviceId(), 'C1', 'Counter 1');
        $this->transport = new InProcessTransport(new Kernel($this->server), Context::deviceId(), $token);
        Settings::set('sync_endpoint', 'https://example.invalid');
        Settings::set('sync_batch_size', '3');
        [, $this->v] = $this->makeProduct('KURTI1', 'pcs', [
            ['sku' => 'KU-M-RED', 'barcode' => '2200000000016', 'size_id' => $this->sizeId('M'), 'color_id' => $this->colorId('Red'), 'mrp' => '799', 'price' => '699', 'cost' => '350', 'opening_qty' => '20'],
        ], 'APPAREL');
    }

    public function tearDown(): void
    {
        unset($this->transport, $this->server);
        gc_collect_cycles();
        foreach ([$this->serverPath, $this->serverPath . '-wal', $this->serverPath . '-shm'] as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function sell(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = (new SaleService($this->db))->checkout(['client_ref' => uuid(), 'lines' => [['variant_id' => $this->v['KU-M-RED'], 'qty' => '1']],
                'payments' => [['method' => 'cash', 'amount_paise' => 69900]]]);
        }
        return $ids;
    }

    private function sync(): array
    {
        return (new SyncService($this->db, $this->transport))->run();
    }

    public function testScenarioBAndCOfflineSalesUploadOnlyOnManualSyncAndNeverDuplicate(): void
    {
        $sales = $this->sell(4);   // "offline": no transport is ever called by checkout
        $this->assertSame(0, $this->transport->calls, 'billing never touches the network');
        $pending = (new OutboxService($this->db))->pendingCount();
        $this->assertTrue($pending >= 5, 'product + opening stock + 4 sales pending');

        $r = $this->sync();
        $this->assertSame('success', $r['status'], $r['message']);
        $this->assertSame(0, $r['pending']);
        $this->assertSame(4, (int)$this->server->value('SELECT COUNT(*) FROM sales'));
        $this->assertSame(1, (int)$this->server->value('SELECT COUNT(*) FROM sales WHERE invoice_no = ?', [$sales[0]['invoice_no']]));
        $this->assertSame(4 * 69900, (int)$this->server->value("SELECT SUM(amount_paise) FROM payments WHERE method = 'cash'"));
        $this->assertSame(16000, (int)$this->server->value("SELECT qty_milli FROM stock_balances WHERE variant_id = ? AND location_code = 'MAIN'", [$this->v['KU-M-RED']]));

        // Repeat click: nothing new, nothing duplicated
        $r2 = $this->sync();
        $this->assertSame(0, $r2['uploaded']);
        $this->assertSame(4, (int)$this->server->value('SELECT COUNT(*) FROM sales'));

        // Force a re-send of already-acknowledged events (e.g. local DB restored from an old backup)
        $this->db->exec("UPDATE sync_outbox SET status = 'pending'");
        $r3 = $this->sync();
        $this->assertSame($r3['uploaded'], $r3['duplicates'], 'all replays recognised as duplicates');
        $this->assertSame(4, (int)$this->server->value('SELECT COUNT(*) FROM sales'));
        $this->assertSame(4, (int)$this->server->value('SELECT COUNT(*) FROM payments'));
        $this->assertSame(16000, (int)$this->server->value("SELECT qty_milli FROM stock_balances WHERE variant_id = ? AND location_code = 'MAIN'", [$this->v['KU-M-RED']]), 'stock not double-applied');
    }

    public function testConnectionLossMidBatchIsSafeToRerun(): void
    {
        $this->sell(7);
        // 1st call = handshake, 2nd = first push OK, 3rd push: server processes but response is lost
        $this->transport->fault = fn(int $n, string $path) => ($n === 3 && str_contains($path, 'push')) ? 'after' : null;
        $r = $this->sync();
        $this->assertSame('partial', $r['status']);
        $this->assertTrue($r['pending'] > 0, 'unacknowledged events stay pending');
        $serverSalesAfterDrop = (int)$this->server->value('SELECT COUNT(*) FROM sales');
        $this->assertTrue($serverSalesAfterDrop > 0);

        $this->transport->fault = null;
        $r2 = $this->sync();
        $this->assertSame('success', $r2['status'], $r2['message']);
        $this->assertTrue($r2['duplicates'] > 0, 'events the server already stored are acknowledged as duplicates');
        $this->assertSame(7, (int)$this->server->value('SELECT COUNT(*) FROM sales'));
        $this->assertSame(0, (int)$this->server->value('SELECT COUNT(*) FROM (SELECT invoice_no FROM sales GROUP BY invoice_no HAVING COUNT(*) > 1) d'));
    }

    public function testNetworkDownBeforeAnythingSent(): void
    {
        $this->sell(2);
        $this->transport->fault = fn() => 'before';
        $r = $this->sync();
        $this->assertSame('failed', $r['status']);
        $this->assertSame(0, (int)$this->server->value('SELECT COUNT(*) FROM sales'));
        $this->assertSame(0, (int)$this->db->value("SELECT COUNT(*) FROM sync_outbox WHERE status = 'acked'"));
        $this->assertSame(1, (int)$this->db->value("SELECT COUNT(*) FROM sync_runs WHERE status = 'failed'"));
    }

    public function testPartialBatchAcceptanceWithRejectedEvent(): void
    {
        $this->sell(2);
        // Inject a malformed event (simulates a corrupted/unsupported entry) between valid ones
        $this->db->transaction(fn() => (new OutboxService($this->db))->record('mystery', uuid(), 'created', ['id' => uuid()]));
        $this->sell(1);
        $r = $this->sync();
        $this->assertSame('partial', $r['status']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(3, (int)$this->server->value('SELECT COUNT(*) FROM sales'), 'valid events around the bad one are accepted');
        $this->assertSame('failed', $this->db->value("SELECT status FROM sync_outbox WHERE entity_type = 'mystery'"));
        $this->assertContains('Unsupported', (string)$this->db->value("SELECT last_error FROM sync_outbox WHERE entity_type = 'mystery'"));
    }

    public function testReturnsAndCustomersSyncWithDependencies(): void
    {
        $cid = (new CustomerService($this->db))->save(null, ['name' => 'Lakshmi', 'phone' => '9876543210']);
        $sale = (new SaleService($this->db))->checkout(['client_ref' => uuid(), 'customer_id' => $cid,
            'lines' => [['variant_id' => $this->v['KU-M-RED'], 'qty' => '2']], 'payments' => [['method' => 'upi', 'amount_paise' => 139800, 'reference' => 'X1']]]);
        $si = $this->db->one('SELECT id FROM sale_items WHERE sale_id = ?', [$sale['sale_id']]);
        (new ReturnService($this->db))->process(['sale_id' => $sale['sale_id'], 'reason' => 'Fit', 'items' => [['sale_item_id' => $si['id'], 'qty' => '1']], 'refund_method' => 'upi']);
        $r = $this->sync();
        $this->assertSame('success', $r['status'], $r['message']);
        $this->assertSame(1, (int)$this->server->value('SELECT COUNT(*) FROM customers'));
        $this->assertSame(1, (int)$this->server->value('SELECT COUNT(*) FROM returns'));
        $this->assertSame(69900, (int)$this->server->value('SELECT SUM(amount_paise) FROM refunds'));
        $this->assertSame(19000, (int)$this->server->value("SELECT qty_milli FROM stock_balances WHERE variant_id = ? AND location_code = 'MAIN'", [$this->v['KU-M-RED']]));
    }

    public function testServerPriceChangePulledOnlyDuringSyncAndConflictsDetected(): void
    {
        $this->sync();   // push product
        (new MasterData($this->server))->publish(null, ['store_id' => Context::storeId(), 'changes' => [
            ['type' => 'variant_price', 'variant_id' => $this->v['KU-M-RED'], 'mrp_paise' => 89900, 'price_paise' => 79900],
            ['type' => 'tax_rule', 'code' => 'FABRIC', 'name' => 'Fabric (server)', 'rate_bp' => 500]]]);
        $this->assertSame(69900, (int)$this->db->value('SELECT price_paise FROM product_variants WHERE id = ?', [$this->v['KU-M-RED']]), 'nothing changes until Sync Now');
        $r = $this->sync();
        $this->assertSame(2, $r['pulled']);
        $this->assertSame(79900, (int)$this->db->value('SELECT price_paise FROM product_variants WHERE id = ?', [$this->v['KU-M-RED']]));
        $this->assertSame('sync', $this->db->value("SELECT source FROM price_history WHERE variant_id = ? ORDER BY changed_at DESC, rowid DESC LIMIT 1", [$this->v['KU-M-RED']]));

        // local unsynced edit + server edit → conflict, local value kept until admin resolves
        $pid = (string)$this->db->value('SELECT product_id FROM product_variants WHERE id = ?', [$this->v['KU-M-RED']]);
        $snap = (new \App\Services\CatalogService($this->db))->productSnapshot($pid);
        $vrow = $snap['variants'][0];
        (new \App\Services\CatalogService($this->db))->saveProduct($pid, $snap + ['reorder_level' => ''], [['id' => $vrow['id'], 'sku' => $vrow['sku'], 'barcode' => $vrow['barcode'],
            'size_id' => $vrow['size_id'], 'color_id' => $vrow['color_id'], 'mrp' => '899', 'price' => '749', 'cost' => '350']]);
        (new MasterData($this->server))->publish(null, ['store_id' => Context::storeId(), 'changes' => [
            ['type' => 'variant_price', 'variant_id' => $this->v['KU-M-RED'], 'mrp_paise' => 89900, 'price_paise' => 77700]]]);
        $this->transport->fault = null;
        $r2 = (new SyncService($this->db, $this->transport))->run();
        $this->assertTrue($r2['conflicts'] >= 1, 'conflict reported');
        $this->assertTrue((int)$this->db->value("SELECT COUNT(*) FROM sync_conflicts WHERE kind = 'price_changed' AND status = 'open'") >= 1);
        $conf = $this->db->one("SELECT * FROM sync_conflicts WHERE kind = 'price_changed' AND status = 'open' LIMIT 1");
        SyncService::resolveConflict($this->db, $conf['id'], 'apply_server', 'Owner approved server price');
        $this->assertSame(77700, (int)$this->db->value('SELECT price_paise FROM product_variants WHERE id = ?', [$this->v['KU-M-RED']]));
    }

    public function testServerRejectsBadTokenAndForeignDevice(): void
    {
        $bad = new InProcessTransport(new Kernel($this->server), Context::deviceId(), 'tpd_' . str_repeat('0', 48));
        $r = (new SyncService($this->db, $bad))->run();
        $this->assertSame('failed', $r['status']);
        $this->assertContains('Invalid', $r['message']);
        [$st] = (new Kernel($this->server))->handle('POST', '/api/v1/sync/push', [], '{}');
        $this->assertSame(401, $st);
        // event claiming another device is rejected
        $token = Kernel::registerDevice($this->server, Context::storeId(), 'x', '01900000-0000-7000-8000-000000000002', 'C2', 'Counter 2');
        $k = new Kernel($this->server);
        [$st, $body] = $k->handle('POST', '/api/v1/sync/push', ['Authorization' => "Bearer $token", 'X-Device-Id' => '01900000-0000-7000-8000-000000000002'],
            json_encode(['events' => [['id' => uuid(), 'seq' => 1, 'store_id' => Context::storeId(), 'device_id' => Context::deviceId(), 'entity_type' => 'sale',
                'entity_id' => uuid(), 'op' => 'created', 'schema_version' => 1, 'payload' => ['id' => uuid()]]]]));
        $this->assertSame(200, $st);
        $this->assertSame('rejected', $body['results'][0]['status']);
    }
}
