<?php
declare(strict_types=1);

// Usage: php scripts/seed-demo.php     — loads FICTIONAL demo data into an EMPTY, already set-up shop (training only).
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/database/seeders/DemoSeeder.php';

use App\Core\Auth;
use App\Core\Database;

$db = Database::instance();
$admin = $db->one("SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1 ORDER BY u.created_at LIMIT 1");
if (!$admin) {
    exit("Run setup first (php scripts/create-admin.php or open the app in the browser).\n");
}
echo "Load DEMO data (fictional products and bills) into " . $db->path . "? Type DEMO: ";
if (trim((string)fgets(STDIN)) !== 'DEMO') {
    exit("Cancelled.\n");
}
Auth::actAs($admin);
$r = (new Database\Seeders\DemoSeeder($db))->run();
echo "Demo data loaded: {$r['products']} products, {$r['variants']} SKUs, {$r['bills']} bills.\n";
