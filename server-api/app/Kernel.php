<?php
declare(strict_types=1);

namespace Server;

/**
 * HTTP kernel for the sync API. handle() is pure (no globals) so it can be exercised in-process by tests.
 *
 * Endpoints (all JSON):
 *   GET  /api/v1/health                       public liveness check (no data)
 *   POST /api/v1/sync/handshake               verify device credentials, returns limits
 *   POST /api/v1/sync/push                    upload a batch of outbox events (idempotent per event id)
 *   GET  /api/v1/sync/pull?cursor=N&limit=M   server-approved master changes for this store
 *   GET  /api/v1/sales/{invoice_no}           look up one synced bill (verification)
 *   POST /api/v1/admin/master                 (admin devices only) publish price / status / tax changes
 */
final class Kernel
{
    public const MAX_BATCH = 200;
    public const MAX_BODY = 5_000_000;
    public const RATE_PER_MINUTE = 120;

    public function __construct(private Db $db)
    {
    }

    /** @return array{0:int,1:array} [status, body] */
    public function handle(string $method, string $path, array $headers, string $body, array $query = []): array
    {
        $requestId = bin2hex(random_bytes(8));
        try {
            $path = '/' . trim($path, '/');
            if ($method === 'GET' && $path === '/api/v1/health') {
                return [200, ['ok' => true, 'service' => 'textile-pos-sync', 'version' => SERVER_VERSION, 'time' => date(DATE_ATOM)]];
            }
            if (strlen($body) > self::MAX_BODY) {
                throw new ApiException(413, 'payload_too_large', 'Request body too large');
            }
            $device = $this->authenticate($headers);
            $this->rateLimit($device['id']);
            $json = [];
            if ($method === 'POST') {
                $json = $body === '' ? [] : json_decode($body, true);
                if (!is_array($json)) {
                    throw new ApiException(400, 'invalid_json', 'Body must be a JSON object');
                }
            }
            $this->db->run('UPDATE devices SET last_seen_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $device['id']]);

            if ($method === 'POST' && $path === '/api/v1/sync/handshake') {
                return [200, ['ok' => true, 'device' => ['id' => $device['id'], 'code' => $device['code'], 'name' => $device['name']],
                    'store' => ['id' => $device['store_id']], 'server_time' => date(DATE_ATOM), 'max_batch' => self::MAX_BATCH,
                    'schema_versions' => [1], 'request_id' => $requestId]];
            }
            if ($method === 'POST' && $path === '/api/v1/sync/push') {
                $events = $json['events'] ?? null;
                if (!is_array($events) || count($events) > self::MAX_BATCH) {
                    throw new ApiException(422, 'invalid_batch', 'events must be an array of at most ' . self::MAX_BATCH);
                }
                $results = (new EventProcessor($this->db, $device))->processBatch($events);
                return [200, ['ok' => true, 'batch_id' => substr((string)($json['batch_id'] ?? ''), 0, 64), 'results' => $results, 'request_id' => $requestId]];
            }
            if ($method === 'GET' && $path === '/api/v1/sync/pull') {
                $cursor = max(0, (int)($query['cursor'] ?? 0));
                $limit = max(1, min(500, (int)($query['limit'] ?? 200)));
                $rows = $this->db->all('SELECT id, entity_type, entity_id, payload, created_at FROM master_changes
                    WHERE store_id = ? AND id > ? AND (origin_device_id IS NULL OR origin_device_id <> ?) ORDER BY id LIMIT ' . ($limit + 1),
                    [$device['store_id'], $cursor, $device['id']]);
                $hasMore = count($rows) > $limit;
                $rows = array_slice($rows, 0, $limit);
                foreach ($rows as &$r) {
                    $r['id'] = (int)$r['id'];
                    $r['payload'] = json_decode($r['payload'], true);
                }
                unset($r);
                $next = $rows ? end($rows)['id'] : $cursor;
                return [200, ['ok' => true, 'changes' => $rows, 'next_cursor' => $next, 'has_more' => $hasMore, 'request_id' => $requestId]];
            }
            if ($method === 'GET' && preg_match('#^/api/v1/sales/([A-Za-z0-9/_-]{3,40})$#', $path, $m)) {
                $sale = $this->db->one('SELECT * FROM sales WHERE store_id = ? AND invoice_no = ?', [$device['store_id'], urldecode($m[1])]);
                if (!$sale) {
                    throw new ApiException(404, 'not_found', 'Bill not found on server');
                }
                $sale['items'] = $this->db->all('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY line_no', [$sale['id']]);
                $sale['payments'] = $this->db->all('SELECT * FROM payments WHERE sale_id = ?', [$sale['id']]);
                unset($sale['customer_phone']);   // minimise PII in API responses
                return [200, ['ok' => true, 'sale' => $sale]];
            }
            if ($method === 'POST' && $path === '/api/v1/admin/master') {
                if (!(int)$device['can_admin']) {
                    throw new ApiException(403, 'forbidden', 'This device is not allowed to publish master data');
                }
                return [200, ['ok' => true] + (new MasterData($this->db))->publish($device, $json)];
            }
            throw new ApiException(404, 'not_found', 'Unknown endpoint');
        } catch (ApiException $e) {
            return [$e->status, ['ok' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()], 'request_id' => $requestId]];
        } catch (\Throwable $e) {
            // log without payloads/PII
            error_log(sprintf('[%s] %s in %s:%d', $requestId, get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine()));
            return [500, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Internal server error'], 'request_id' => $requestId]];
        }
    }

    private function authenticate(array $headers): array
    {
        $h = array_change_key_case($headers, CASE_LOWER);
        $auth = $h['authorization'] ?? '';
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{20,128})$/', $auth, $m)) {
            throw new ApiException(401, 'unauthenticated', 'Missing or malformed device token');
        }
        $device = $this->db->one('SELECT * FROM devices WHERE token_hash = ?', [hash('sha256', $m[1])]);
        if (!$device || !(int)$device['is_active']) {
            throw new ApiException(401, 'unauthenticated', 'Invalid or disabled device token');
        }
        $claimed = $h['x-device-id'] ?? '';
        if (!hash_equals($device['id'], (string)$claimed)) {
            throw new ApiException(401, 'device_mismatch', 'X-Device-Id does not match the token');
        }
        return $device;
    }

    private function rateLimit(string $deviceId): void
    {
        $window = intdiv(time(), 60);
        $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [$window - 5]);
        try {
            $this->db->run('INSERT INTO rate_limits (device_key, window_start, hits) VALUES (?, ?, 1)', [$deviceId, $window]);
        } catch (\PDOException $e) {
            if (!Db::isDuplicateKey($e)) {
                throw $e;
            }
            $this->db->run('UPDATE rate_limits SET hits = hits + 1 WHERE device_key = ? AND window_start = ?', [$deviceId, $window]);
        }
        $hits = (int)$this->db->value('SELECT hits FROM rate_limits WHERE device_key = ? AND window_start = ?', [$deviceId, $window]);
        if ($hits > self::RATE_PER_MINUTE) {
            throw new ApiException(429, 'rate_limited', 'Too many requests; wait a minute and retry');
        }
    }

    /** Create a device and return its plaintext token (shown once). */
    public static function registerDevice(Db $db, string $storeId, string $storeName, string $deviceId, string $code, string $name, bool $admin = false): string
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $storeId) || !preg_match('/^[0-9a-f-]{36}$/i', $deviceId)) {
            throw new \InvalidArgumentException('store and device ids must be UUIDs (copy them from the shop Sync Center)');
        }
        $token = 'tpd_' . bin2hex(random_bytes(24));
        $db->transaction(function (Db $db) use ($storeId, $storeName, $deviceId, $code, $name, $admin, $token) {
            if (!$db->value('SELECT 1 FROM stores WHERE id = ?', [$storeId])) {
                $db->run('INSERT INTO stores (id, code, name, created_at) VALUES (?, ?, ?, ?)', [$storeId, 'MAIN', $storeName, date('Y-m-d H:i:s')]);
            }
            if ($db->value('SELECT 1 FROM devices WHERE id = ?', [$deviceId])) {
                $db->run('UPDATE devices SET token_hash = ?, is_active = 1, can_admin = ? WHERE id = ?', [hash('sha256', $token), $admin ? 1 : 0, $deviceId]);
            } else {
                $db->run('INSERT INTO devices (id, store_id, code, name, token_hash, can_admin, is_active, created_at) VALUES (?,?,?,?,?,?,1,?)',
                    [$deviceId, $storeId, $code, $name, hash('sha256', $token), $admin ? 1 : 0, date('Y-m-d H:i:s')]);
            }
        });
        return $token;
    }
}
