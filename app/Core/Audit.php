<?php
declare(strict_types=1);

namespace App\Core;

/** Append-only staff action log (protected by SQLite triggers against update/delete). */
final class Audit
{
    public static function log(string $action, ?string $entityType = null, ?string $entityId = null, array $details = [], ?string $userId = null): void
    {
        try {
            $db = Database::instance();
            $storeId = $db->value('SELECT id FROM stores LIMIT 1');
            $db->insert('audit_logs', [
                'id' => uuid(),
                'store_id' => $storeId,
                'user_id' => $userId ?? Auth::id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                'ip' => PHP_SAPI === 'cli' ? 'cli' : ($_SERVER['REMOTE_ADDR'] ?? null),
                'created_at' => now(),
            ]);
        } catch (\PDOException $e) {
            // In a failed outer transaction the insert may fail; never mask the original error.
            error_log('Audit log failure: ' . $e->getMessage());
        }
    }
}
