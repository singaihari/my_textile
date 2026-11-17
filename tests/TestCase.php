<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Core\Settings;
use App\Services\CashService;
use App\Services\CatalogService;
use App\Services\SetupService;

/**
 * Minimal dependable test base (no external dependencies).
 * Each test method gets a brand-new SQLite database file.
 */
abstract class TestCase
{
    protected Database $db;
    protected array $admin;
    public int $assertions = 0;

    public function setUp(): void
    {
        $dir = APP_ROOT . '/storage/tmp/tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $path = $dir . '/t_' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->db = new Database($path);
        Database::setInstance($this->db);
        Context::reset();
        Settings::flush();
        $_SESSION = [];
        $setup = new SetupService($this->db);
        $setup->ensureSchema();
        $setup->run(['shop_name' => 'Test Textiles', 'username' => 'admin', 'full_name' => 'Admin User',
            'password' => 'Secret123', 'password_confirm' => 'Secret123', 'device_code' => 'C1']);
        $this->admin = $this->db->one('SELECT u.*, r.code AS role_code, r.max_discount_bp FROM users u JOIN roles r ON r.id = u.role_id WHERE username = ?', ['admin']);
        Auth::actAs($this->admin);
        Settings::set('require_cash_session', '0');
    }

    public function tearDown(): void
    {
        $path = $this->db->path;
        Auth::actAs(null);
        Database::setInstance(null);
        unset($this->db);
        gc_collect_cycles();
        foreach ([$path, "$path-wal", "$path-shm"] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    protected function makeUser(string $username, string $roleCode, string $password = 'Passw0rd!'): array
    {
        $roleId = $this->db->value('SELECT id FROM roles WHERE code = ?', [$roleCode]);
        $id = uuid();
        $this->db->insert('users', ['id' => $id, 'store_id' => Context::storeId(), 'username' => $username, 'full_name' => ucfirst($username),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role_id' => $roleId, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        return $this->db->one('SELECT u.*, r.code AS role_code, r.max_discount_bp FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$id]);
    }

    protected function taxRuleId(string $code): string
    {
        return (string)$this->db->value('SELECT id FROM tax_rules WHERE code = ?', [$code]);
    }

    /** Create a product with variants; returns [productId, [sku => variantId]]. */
    protected function makeProduct(string $code, string $unit, array $variants, string $taxCode = 'FABRIC', array $extra = []): array
    {
        $cat = new CatalogService($this->db);
        $pid = $cat->saveProduct(null, array_merge(['name' => "Product $code", 'code' => $code, 'unit' => $unit,
            'tax_rule_id' => $this->taxRuleId($taxCode)], $extra), $variants);
        $map = [];
        foreach ($this->db->all('SELECT id, sku FROM product_variants WHERE product_id = ?', [$pid]) as $r) {
            $map[$r['sku']] = $r['id'];
        }
        return [$pid, $map];
    }

    protected function sizeId(string $label): string
    {
        return (string)$this->db->value('SELECT id FROM sizes WHERE label = ?', [$label]);
    }

    protected function colorId(string $name): string
    {
        return (string)$this->db->value('SELECT id FROM colors WHERE name = ?', [$name]);
    }

    protected function stock(string $variantId, string $loc = 'MAIN'): int
    {
        return (int)$this->db->value('SELECT qty_milli FROM stock_balances WHERE variant_id = ? AND location_id = ?', [$variantId, Context::locationId($loc)]);
    }

    protected function openCash(int $float = 100000): void
    {
        (new CashService($this->db))->open($float, null);
    }

    // ---------------------------------------------------------------- assertions
    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertTrue(mixed $cond, string $msg = 'Expected true'): void
    {
        $this->assertions++;
        if ($cond !== true) {
            throw new AssertionFailed($msg);
        }
    }

    protected function assertFalse(mixed $cond, string $msg = 'Expected false'): void
    {
        $this->assertTrue($cond === false, $msg);
    }

    protected function assertCount(int $n, array $arr, string $msg = ''): void
    {
        $this->assertSame($n, count($arr), $msg ?: 'count');
    }

    protected function assertContains(string $needle, string $haystack, string $msg = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(($msg ? "$msg: " : '') . "'$needle' not found in output");
        }
    }

    /** @param class-string<\Throwable> $class */
    protected function assertThrows(string $class, callable $fn, ?string $messageContains = null): \Throwable
    {
        $this->assertions++;
        try {
            $fn();
        } catch (\Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionFailed("Expected $class, got " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($messageContains !== null && !str_contains($e->getMessage(), $messageContains)) {
                throw new AssertionFailed("Exception message '{$e->getMessage()}' does not contain '$messageContains'");
            }
            return $e;
        }
        throw new AssertionFailed("Expected exception $class was not thrown");
    }
}

final class AssertionFailed extends \RuntimeException
{
}
