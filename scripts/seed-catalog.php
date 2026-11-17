<?php
declare(strict_types=1);

// Usage: php scripts/seed-catalog.php     — adds the 50-product starter catalog with illustrations and opening stock.
// Safe to re-run: products whose code already exists are skipped. Items are tagged "sample" in the product list.
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/database/seeders/CatalogSeeder.php';

use App\Core\Auth;
use App\Core\Database;

$db = Database::instance();
$admin = $db->one("SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1 ORDER BY u.created_at LIMIT 1");
if (!$admin) {
    exit("Create the admin first: php scripts/create-admin.php\n");
}
Auth::actAs($admin);
$r = (new \Database\Seeders\CatalogSeeder($db))->run();
echo "Catalog: {$r['created']} products added ({$r['skus']} SKUs with opening stock), {$r['skipped']} already existed.\n";
