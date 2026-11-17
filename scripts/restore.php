<?php
declare(strict_types=1);

// Usage: php scripts/restore.php <backup-file-name.sqlite>
// Stop the web server first. Validates the backup, takes a safety backup, then swaps the database files.
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Database;
use App\Services\BackupService;

$name = $argv[1] ?? '';
if ($name === '') {
    echo "Available backups:\n";
    foreach ((new BackupService(Database::instance()))->list() as $b) {
        echo '  ' . $b['file'] . '  ' . $b['mtime'] . "\n";
    }
    exit("\nUsage: php scripts/restore.php <file>\n");
}
echo "This will REPLACE the live database with $name. Type RESTORE to continue: ";
if (trim((string)fgets(STDIN)) !== 'RESTORE') {
    exit("Cancelled.\n");
}
$db = Database::instance();
$res = (new BackupService($db))->stageRestore(basename($name));
echo "Safety backup: {$res['safety_backup']}\n";
$path = $db->path;
Database::setInstance(null);
unset($db);
gc_collect_cycles();
echo BackupService::applyPendingRestore($path) . "\n";
