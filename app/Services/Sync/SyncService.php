<?php
declare(strict_types=1);

namespace App\Services\Sync;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Services\CatalogService;
use App\Services\OutboxService;

/**
 * MANUAL synchronisation. This class is only ever invoked from the "Sync Now" action (or the explicit
 * CLI script). Nothing in the application schedules it: no timers, no reconnect hooks, no service worker.
 *
 * Flow: handshake → upload pending events in seq order in bounded batches → mark each event synced only
 * after the server acknowledges it (applied / duplicate / conflict) → pull server-approved master changes
 * → summarise. Any interruption leaves unacknowledged events pending, so re-running is always safe
 * (the server de-duplicates by event id).
 */
final class SyncService
{
    public function __construct(private Database $db, private Transport $transport)
    {
    }

    public static function fromSettings(Database $db): SyncService
    {
        $endpoint = trim((string)Settings::get('sync_endpoint'));
        $token = trim((string)Settings::get('sync_device_token'));
        if ($endpoint === '' || $token === '') {
            throw new ValidationException('Sync is not configured. Enter the server URL and device token in Sync Center → Settings.');
        }
        return new SyncService($db, new CurlTransport($endpoint, Context::deviceId(), $token));
    }

    public function testConnection(): array
    {
        [$st, $body] = $this->transport->request('POST', '/api/v1/sync/handshake', []);
        if ($st !== 200 || empty($body['ok'])) {
            throw new ValidationException('Server refused: ' . ($body['error']['message'] ?? "HTTP $st"));
        }
        if (($body['store']['id'] ?? '') !== Context::storeId()) {
            throw new ValidationException('This token belongs to a different store on the server.');
        }
        return $body;
    }

    public function run(?int $maxBatches = null): array
    {
        if ($this->db->value("SELECT 1 FROM sync_runs WHERE status = 'running' AND started_at > ?", [date('Y-m-d H:i:s', time() - 600)])) {
            throw new ValidationException('Another sync is already running. Wait for it to finish.');
        }
        $runId = uuid();
        $this->db->insert('sync_runs', ['id' => $runId, 'user_id' => Auth::id(), 'endpoint' => (string)Settings::get('sync_endpoint'),
            'status' => 'running', 'started_at' => now()]);
        Audit::log('sync.started', 'sync_run', $runId);
        $stats = ['uploaded' => 0, 'acked' => 0, 'duplicates' => 0, 'failed' => 0, 'conflicts' => 0, 'deferred' => 0, 'pulled' => 0];
        $messages = [];
        $status = 'success';
        try {
            $this->testConnection();
            $batchSize = max(1, min(200, (int)Settings::get('sync_batch_size', 50)));
            $batches = 0;
            $lastSeq = 0;
            while ($maxBatches === null || $batches < $maxBatches) {
                $events = $this->db->all("SELECT * FROM sync_outbox WHERE status IN ('pending','failed') AND seq > ? ORDER BY seq LIMIT $batchSize", [$lastSeq]);
                if (!$events) {
                    break;
                }
                $batches++;
                $lastSeq = (int)end($events)['seq'];
                $this->db->run('UPDATE sync_outbox SET attempts = attempts + 1, last_attempt_at = ? WHERE seq BETWEEN ? AND ? AND status IN (\'pending\',\'failed\')',
                    [now(), (int)$events[0]['seq'], $lastSeq]);
                $payload = ['batch_id' => $runId . '-' . $batches, 'events' => array_map(fn($e) => [
                    'id' => $e['id'], 'seq' => (int)$e['seq'], 'store_id' => $e['store_id'], 'device_id' => $e['device_id'],
                    'entity_type' => $e['entity_type'], 'entity_id' => $e['entity_id'], 'op' => $e['op'],
                    'schema_version' => (int)$e['schema_version'], 'payload' => json_decode($e['payload'], true),
                    'depends_on' => $e['depends_on'], 'created_at' => date(DATE_ATOM, strtotime($e['created_at'])),
                ], $events)];
                $stats['uploaded'] += count($events);
                [$st, $body] = $this->transport->request('POST', '/api/v1/sync/push', $payload);
                if ($st !== 200 || !isset($body['results']) || !is_array($body['results'])) {
                    throw new TransportException('Upload refused: ' . ($body['error']['message'] ?? "HTTP $st"));
                }
                $this->applyResults($runId, $events, $body['results'], $stats);
            }
            if ($stats['deferred'] > 0) {
                // a deferred event whose dependency was acknowledged later in this run can go now
                $this->retryDeferred($runId, $stats);
            }
            $stats['pulled'] = $this->pull($runId, $stats);
            if ($stats['failed'] || $stats['conflicts'] || $stats['deferred']) {
                $status = 'partial';
            }
            Settings::set('sync_last_success_at', now());
        } catch (TransportException $e) {
            $status = $stats['acked'] + $stats['duplicates'] > 0 ? 'partial' : 'failed';
            $messages[] = $e->getMessage() . ' — unsent bills stay pending; click Sync Now again when the connection is back.';
            $this->db->run("UPDATE sync_outbox SET last_error = ? WHERE status IN ('pending','failed') AND last_attempt_at >= (SELECT started_at FROM sync_runs WHERE id = ?)",
                [mb_substr($e->getMessage(), 0, 300), $runId]);
        } catch (ValidationException $e) {
            $status = 'failed';
            $messages[] = $e->getMessage();
        }
        $pending = (new OutboxService($this->db))->pendingCount();
        $msg = implode(' ', $messages) ?: sprintf('Uploaded %d, acknowledged %d (%d already on server), %d failed, %d conflicts, %d waiting; pulled %d updates. %d still pending.',
            $stats['uploaded'], $stats['acked'], $stats['duplicates'], $stats['failed'], $stats['conflicts'], $stats['deferred'], $stats['pulled'], $pending);
        $this->db->update('sync_runs', ['status' => $status, 'uploaded' => $stats['uploaded'], 'acked' => $stats['acked'], 'duplicates' => $stats['duplicates'],
            'failed' => $stats['failed'], 'conflicts' => $stats['conflicts'], 'pulled' => $stats['pulled'], 'message' => mb_substr($msg, 0, 1000),
            'finished_at' => now()], 'id = :id', ['id' => $runId]);
        Audit::log('sync.finished', 'sync_run', $runId, $stats + ['status' => $status]);
        return ['run_id' => $runId, 'status' => $status, 'message' => $msg, 'pending' => $pending] + $stats;
    }

    private function applyResults(string $runId, array $events, array $results, array &$stats): void
    {
        $known = array_column($events, null, 'id');
        $this->db->transaction(function (Database $db) use ($runId, $known, $results, &$stats) {
            foreach ($results as $r) {
                $id = (string)($r['event_id'] ?? '');
                if (!isset($known[$id])) {
                    continue;   // never trust acknowledgements for events we did not send
                }
                $st = (string)($r['status'] ?? '');
                $msg = mb_substr((string)($r['message'] ?? ''), 0, 300);
                if (in_array($st, ['applied', 'duplicate', 'conflict'], true)) {
                    $db->update('sync_outbox', ['status' => $st === 'conflict' ? 'conflict' : 'acked', 'acked_at' => now(), 'server_status' => $st,
                        'last_error' => $st === 'conflict' ? $msg : null], 'id = :id', ['id' => $id]);
                    $db->run('INSERT OR REPLACE INTO sync_receipts (event_id, sync_run_id, server_status, message, received_at) VALUES (?,?,?,?,?)',
                        [$id, $runId, $st, $msg, now()]);
                    $stats['acked']++;
                    if ($st === 'duplicate') {
                        $stats['duplicates']++;
                    }
                    if ($st === 'conflict') {
                        $stats['conflicts']++;
                        foreach ($r['conflicts'] ?? [] as $c) {
                            $db->insert('sync_conflicts', ['id' => uuid(), 'sync_run_id' => $runId, 'event_id' => $id,
                                'entity_type' => (string)($c['entity_type'] ?? $known[$id]['entity_type']), 'entity_id' => (string)($c['entity_id'] ?? $known[$id]['entity_id']),
                                'kind' => (string)($c['kind'] ?? 'server_conflict'), 'local_value' => isset($c['local']) ? json_encode($c['local']) : null,
                                'server_value' => json_encode($c, JSON_UNESCAPED_UNICODE), 'status' => 'open', 'created_at' => now()]);
                        }
                    }
                } elseif ($st === 'deferred') {
                    $stats['deferred']++;
                    $db->update('sync_outbox', ['last_error' => $msg], 'id = :id', ['id' => $id]);
                } else {   // rejected | error
                    $stats['failed']++;
                    $db->update('sync_outbox', ['status' => 'failed', 'last_error' => $msg ?: $st], 'id = :id', ['id' => $id]);
                }
            }
        });
    }

    private function retryDeferred(string $runId, array &$stats): void
    {
        for ($round = 0; $round < 3; $round++) {
            $events = $this->db->all("SELECT * FROM sync_outbox WHERE status IN ('pending','failed') AND depends_on IS NOT NULL
                AND depends_on IN (SELECT id FROM sync_outbox WHERE status IN ('acked','conflict')) ORDER BY seq LIMIT 200");
            if (!$events) {
                return;
            }
            $stats['deferred'] = max(0, $stats['deferred'] - count($events));
            $payload = ['batch_id' => $runId . '-retry' . $round, 'events' => array_map(fn($e) => [
                'id' => $e['id'], 'seq' => (int)$e['seq'], 'store_id' => $e['store_id'], 'device_id' => $e['device_id'],
                'entity_type' => $e['entity_type'], 'entity_id' => $e['entity_id'], 'op' => $e['op'], 'schema_version' => (int)$e['schema_version'],
                'payload' => json_decode($e['payload'], true), 'depends_on' => $e['depends_on'], 'created_at' => date(DATE_ATOM, strtotime($e['created_at'])),
            ], $events)];
            [$st, $body] = $this->transport->request('POST', '/api/v1/sync/push', $payload);
            if ($st !== 200 || !isset($body['results'])) {
                throw new TransportException('Upload refused: ' . ($body['error']['message'] ?? "HTTP $st"));
            }
            $this->applyResults($runId, $events, $body['results'], $stats);
        }
    }

    /** Pull server-approved master changes (part of the same manual session). */
    private function pull(string $runId, array &$stats): int
    {
        $count = 0;
        $cursor = (int)Settings::get('sync_last_pull_cursor', 0);
        for ($page = 0; $page < 50; $page++) {
            [$st, $body] = $this->transport->request('GET', '/api/v1/sync/pull', null, ['cursor' => $cursor, 'limit' => 200]);
            if ($st !== 200 || !isset($body['changes'])) {
                throw new TransportException('Download refused: ' . ($body['error']['message'] ?? "HTTP $st"));
            }
            $changes = $body['changes'];
            $this->db->transaction(function (Database $db) use ($changes, $runId, &$stats, &$count, $body) {
                foreach ($changes as $c) {
                    if ($this->applyMasterChange($runId, $c)) {
                        $count++;
                    } else {
                        $stats['conflicts']++;
                    }
                }
                Settings::set('sync_last_pull_cursor', (string)(int)$body['next_cursor']);
            });
            $cursor = (int)$body['next_cursor'];
            if (empty($body['has_more'])) {
                break;
            }
        }
        return $count;
    }

    /** @return bool true when applied, false when recorded as a conflict */
    private function applyMasterChange(string $runId, array $c): bool
    {
        $p = $c['payload'] ?? [];
        $type = $c['entity_type'] ?? '';
        $conflict = function (string $entityType, string $entityId, string $kind, $local, $server) use ($runId) {
            $this->db->insert('sync_conflicts', ['id' => uuid(), 'sync_run_id' => $runId, 'event_id' => null, 'entity_type' => $entityType,
                'entity_id' => $entityId, 'kind' => $kind, 'local_value' => $local !== null ? json_encode($local) : null,
                'server_value' => json_encode($server, JSON_UNESCAPED_UNICODE), 'status' => 'open', 'created_at' => now()]);
            return false;
        };
        if ($type === 'variant_price' || $type === 'variant_status') {
            $v = $this->db->one('SELECT * FROM product_variants WHERE id = ?', [$p['variant_id'] ?? '']);
            if (!$v) {
                return $conflict('variant', (string)($p['variant_id'] ?? ''), 'unknown_variant', null, $p);
            }
            $unsynced = (int)$this->db->value("SELECT COUNT(*) FROM sync_outbox WHERE entity_type = 'product' AND entity_id = ? AND status IN ('pending','failed')", [$v['product_id']]);
            if ($type === 'variant_price') {
                if ($unsynced && ((int)$v['price_paise'] !== (int)$p['price_paise'] || (int)$v['mrp_paise'] !== (int)$p['mrp_paise'])) {
                    return $conflict('variant', $v['id'], 'price_changed', ['sku' => $v['sku'], 'mrp_paise' => (int)$v['mrp_paise'], 'price_paise' => (int)$v['price_paise']], $p);
                }
                if ((int)$p['price_paise'] > (int)$p['mrp_paise'] || (int)$p['price_paise'] <= 0) {
                    return $conflict('variant', $v['id'], 'invalid_server_price', null, $p);
                }
                (new CatalogService($this->db))->recordPriceHistory($v['id'], $v, (int)$p['mrp_paise'], (int)$p['price_paise'], (int)$v['cost_paise'], 'sync');
                $this->db->update('product_variants', ['mrp_paise' => (int)$p['mrp_paise'], 'price_paise' => (int)$p['price_paise'], 'updated_at' => now()], 'id = :id', ['id' => $v['id']]);
                Audit::log('sync.price_applied', 'variant', $v['id'], ['sku' => $v['sku'], 'price' => (int)$p['price_paise']]);
            } else {
                $status = in_array($p['status'] ?? '', ['active', 'archived'], true) ? $p['status'] : null;
                if (!$status) {
                    return $conflict('variant', $v['id'], 'invalid_status', null, $p);
                }
                $this->db->update('product_variants', ['status' => $status, 'updated_at' => now()], 'id = :id', ['id' => $v['id']]);
                Audit::log('sync.status_applied', 'variant', $v['id'], ['status' => $status]);
            }
            return true;
        }
        if ($type === 'tax_rule') {
            $code = strtoupper((string)($p['code'] ?? ''));
            $local = $this->db->one('SELECT * FROM tax_rules WHERE code = ?', [$code]);
            $lastSync = (string)Settings::get('sync_last_success_at', '');
            if ($local && $lastSync !== '' && $local['updated_at'] > $lastSync
                && ((int)$local['rate_bp'] !== (int)$p['rate_bp'] || $local['threshold_paise'] != ($p['threshold_paise'] ?? null))) {
                return $conflict('tax_rule', $code, 'stale_tax_config', $local, $p);
            }
            $row = ['name' => (string)($p['name'] ?? $code), 'rate_bp' => (int)$p['rate_bp'], 'threshold_paise' => $p['threshold_paise'] ?? null,
                'above_rate_bp' => $p['above_rate_bp'] ?? null, 'updated_at' => now()];
            if ($local) {
                $this->db->update('tax_rules', $row, 'id = :id', ['id' => $local['id']]);
            } else {
                $this->db->insert('tax_rules', $row + ['id' => uuid(), 'code' => $code, 'is_active' => 1, 'notes' => 'Received from server', 'created_at' => now()]);
            }
            Audit::log('sync.tax_applied', 'tax_rule', $code, $p);
            return true;
        }
        return $conflict('unknown', (string)($c['entity_id'] ?? ''), 'unsupported_change', null, $c);
    }

    /** Admin resolution from Sync Center. */
    public static function resolveConflict(Database $db, string $id, string $action, string $note): void
    {
        $c = $db->one("SELECT * FROM sync_conflicts WHERE id = ? AND status = 'open'", [$id]) ?? throw new ValidationException('Conflict not found or already resolved');
        $db->transaction(function (Database $db) use ($c, $action, $note) {
            if ($action === 'apply_server' && $c['kind'] === 'price_changed') {
                $server = json_decode((string)$c['server_value'], true);
                $vals = $server['server'] ?? $server;
                $v = $db->one('SELECT * FROM product_variants WHERE id = ?', [$c['entity_id']]);
                if ($v && isset($vals['price_paise'], $vals['mrp_paise'])) {
                    (new CatalogService($db))->recordPriceHistory($v['id'], $v, (int)$vals['mrp_paise'], (int)$vals['price_paise'], (int)$v['cost_paise'], 'sync');
                    $db->update('product_variants', ['mrp_paise' => (int)$vals['mrp_paise'], 'price_paise' => (int)$vals['price_paise'], 'updated_at' => now()], 'id = :id', ['id' => $v['id']]);
                }
            } elseif ($action === 'keep_local' && $c['entity_type'] === 'variant') {
                $pid = $db->value('SELECT product_id FROM product_variants WHERE id = ?', [$c['entity_id']]);
                if ($pid) {
                    (new OutboxService($db))->record('product', $pid, 'upserted', (new CatalogService($db))->productSnapshot($pid));
                }
            }
            $db->update('sync_conflicts', ['status' => $action === 'ignore' ? 'ignored' : 'resolved', 'resolution' => mb_substr($action . ': ' . $note, 0, 300),
                'resolved_by' => Auth::id(), 'resolved_at' => now()], 'id = :id', ['id' => $c['id']]);
            Audit::log('sync.conflict_resolved', 'sync_conflict', $c['id'], ['action' => $action]);
        });
    }
}
