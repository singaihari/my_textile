<?php
declare(strict_types=1);

// Usage: php scripts/backup.php [tag]      Safe while the app is running (SQLite VACUUM INTO). Use with Windows Task Scheduler.
require dirname(__DIR__) . '/app/bootstrap.php';

$tag = preg_replace('/[^a-z0-9-]/', '', strtolower($argv[1] ?? 'scheduled')) ?: 'scheduled';
try {
    $b = (new App\Services\BackupService(App\Core\Database::instance()))->create($tag);
    printf("Backup OK: %s (%.2f MB, %d bills, %d pending sync)\n", $b['file'], $b['size'] / 1048576, $b['counts']['sales'], $b['counts']['pending_sync']);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
