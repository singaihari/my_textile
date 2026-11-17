<?php
declare(strict_types=1);

namespace Server;

/**
 * Server-approved master-data changes (prices, variant status, tax rules). They are recorded in
 * master_changes and delivered to shop devices ONLY when a user there clicks "Sync Now" (pull phase).
 */
final class MasterData
{
    public function __construct(private Db $db)
    {
    }

    public function publish(?array $device, array $json): array
    {
        $changes = $json['changes'] ?? [];
        if (!is_array($changes) || !$changes || count($changes) > 500) {
            throw new ApiException(422, 'invalid_changes', 'changes must be a non-empty array (max 500)');
        }
        $storeId = $device['store_id'] ?? (string)($json['store_id'] ?? '');
        $ids = [];
        $this->db->transaction(function (Db $db) use ($changes, $storeId, &$ids) {
            foreach ($changes as $c) {
                $type = $c['type'] ?? '';
                $now = date('Y-m-d H:i:s');
                if ($type === 'variant_price') {
                    $v = $db->one('SELECT v.* FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ? AND p.store_id = ?', [$c['variant_id'] ?? '', $storeId]);
                    $mrp = (int)($c['mrp_paise'] ?? -1);
                    $price = (int)($c['price_paise'] ?? -1);
                    if (!$v || $mrp < 0 || $price <= 0 || $price > $mrp) {
                        throw new ApiException(422, 'invalid_change', 'Unknown variant or invalid price');
                    }
                    $db->run('UPDATE product_variants SET mrp_paise = ?, price_paise = ?, admin_price_changed_at = ?, updated_at = ? WHERE id = ?', [$mrp, $price, $now, $now, $v['id']]);
                    $payload = ['variant_id' => $v['id'], 'sku' => $v['sku'], 'mrp_paise' => $mrp, 'price_paise' => $price];
                    $entity = $v['id'];
                } elseif ($type === 'variant_status') {
                    $st = $c['status'] ?? '';
                    if (!in_array($st, ['active', 'archived'], true) || !$db->value('SELECT 1 FROM product_variants WHERE id = ?', [$c['variant_id'] ?? ''])) {
                        throw new ApiException(422, 'invalid_change', 'Unknown variant or status');
                    }
                    $db->run('UPDATE product_variants SET status = ?, updated_at = ? WHERE id = ?', [$st, $now, $c['variant_id']]);
                    $payload = ['variant_id' => $c['variant_id'], 'status' => $st];
                    $entity = $c['variant_id'];
                } elseif ($type === 'tax_rule') {
                    $code = strtoupper((string)($c['code'] ?? ''));
                    $rate = (int)($c['rate_bp'] ?? -1);
                    if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $code) || $rate < 0 || $rate > 10000) {
                        throw new ApiException(422, 'invalid_change', 'Invalid tax rule');
                    }
                    $payload = ['code' => $code, 'name' => (string)($c['name'] ?? $code), 'rate_bp' => $rate,
                        'threshold_paise' => isset($c['threshold_paise']) ? (int)$c['threshold_paise'] : null,
                        'above_rate_bp' => isset($c['above_rate_bp']) ? (int)$c['above_rate_bp'] : null];
                    $db->upsert('tax_rules', $payload + ['updated_at' => $now], ['code', 'name', 'rate_bp', 'threshold_paise', 'above_rate_bp', 'updated_at'], ['code']);
                    $entity = $code;
                } else {
                    throw new ApiException(422, 'invalid_change', 'Unknown change type');
                }
                $db->run('INSERT INTO master_changes (store_id, origin_device_id, entity_type, entity_id, payload, created_at) VALUES (?, NULL, ?, ?, ?, ?)',
                    [$storeId, $type, $entity, json_encode($payload, JSON_UNESCAPED_UNICODE), $now]);
                $ids[] = (int)$db->pdo()->lastInsertId();
            }
        });
        return ['published' => count($ids), 'change_ids' => $ids];
    }
}
