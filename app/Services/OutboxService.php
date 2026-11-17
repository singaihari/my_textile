<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Context;
use App\Core\Database;

/**
 * Transactional outbox. Every business mutation calls record() inside its own SQLite transaction,
 * so the event exists if and only if the business data was committed. Events are immutable
 * (enforced by triggers) and are only uploaded when a user clicks "Sync Now".
 */
final class OutboxService
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private Database $db)
    {
    }

    public function record(string $entityType, string $entityId, string $op, array $payload, ?string $dependsOn = null): string
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Outbox events must be recorded inside the business transaction');
        }
        $id = uuid();
        $seq = (int)$this->db->value('SELECT IFNULL(MAX(seq), 0) + 1 FROM sync_outbox');
        $this->db->insert('sync_outbox', [
            'id' => $id,
            'seq' => $seq,
            'store_id' => Context::storeId(),
            'device_id' => Context::deviceId(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'op' => $op,
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'depends_on' => $dependsOn,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => now(),
        ]);
        return $id;
    }

    public function pendingCount(): int
    {
        return (int)$this->db->value("SELECT COUNT(*) FROM sync_outbox WHERE status IN ('pending','failed')");
    }

    public function counts(): array
    {
        $out = ['pending' => 0, 'failed' => 0, 'acked' => 0, 'conflict' => 0];
        foreach ($this->db->all('SELECT status, COUNT(*) c FROM sync_outbox GROUP BY status') as $r) {
            $out[$r['status']] = (int)$r['c'];
        }
        $out['open_conflicts'] = (int)$this->db->value("SELECT COUNT(*) FROM sync_conflicts WHERE status = 'open'");
        return $out;
    }
}
