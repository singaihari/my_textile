<?php
declare(strict_types=1);

// Usage: php scripts/run-migrations.php      (takes a verified backup first when the database already exists)
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Core\Migrator;
use App\Services\BackupService;

$path = Database::defaultPath();
$exists = is_file($path);
$db = Database::instance();
$m = new Migrator($db);
$pending = array_keys($m->pending());
if (!$pending) {
    echo "Database is up to date (" . ($m->currentVersion() ?? 'none') . ").\n";
    exit(0);
}
if ($exists && (int)$db->value("SELECT COUNT(*) FROM sqlite_master WHERE name = 'sales'") > 0) {
    $b = (new BackupService($db))->create('pre-migration');
    echo "Backup taken: {$b['file']}\n";
}
foreach ($m->migrate() as $v) {
    echo "Applied $v\n";
}
echo "Done. Database: $path\n";
