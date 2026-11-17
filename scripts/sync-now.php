<?php
declare(strict_types=1);

// Manual, explicit sync from the command line (same as clicking "Sync Now"). Never schedule this:
// the business rule is that synchronisation only happens when a person decides to run it.
// Usage: php scripts/sync-now.php <admin-or-manager-username>      (asks for confirmation)
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\OutboxService;
use App\Services\Sync\SyncService;

$db = Database::instance();
$u = $db->one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.username = ? AND u.is_active = 1', [$argv[1] ?? '']);
if (!$u) {
    exit("Usage: php scripts/sync-now.php <username>  (user must have the 'sync.run' permission)\n");
}
Auth::actAs($u);
if (!Auth::can('sync.run')) {
    exit("{$u['username']} is not allowed to run sync.\n");
}
$pending = (new OutboxService($db))->pendingCount();
if (!in_array('--yes', $argv, true)) {
    echo "$pending pending events will be uploaded to " . App\Core\Settings::get('sync_endpoint') . ". Type SYNC to continue: ";
    if (trim((string)fgets(STDIN)) !== 'SYNC') {
        exit("Cancelled.\n");
    }
}
$r = SyncService::fromSettings($db)->run();
echo strtoupper($r['status']) . ': ' . $r['message'] . "\n";
exit($r['status'] === 'failed' ? 1 : 0);
