<?php
declare(strict_types=1);

/**
 * Server administration CLI (run on the server, never exposed over HTTP).
 *
 *   php scripts/server-cli.php migrate
 *   php scripts/server-cli.php register-device --store-id=UUID --store-name="My Shop" --device-id=UUID --code=C1 --name="Counter 1"
 *   php scripts/server-cli.php set-price --store-id=UUID --variant-id=UUID --mrp=1299.00 --price=1199.00
 *   php scripts/server-cli.php set-tax --store-id=UUID --code=APPAREL --name="Apparel" --rate=5 [--threshold=2500 --above=18]
 *   php scripts/server-cli.php find-sale --store-id=UUID --invoice=TX-C1-2627-00001
 *   php scripts/server-cli.php conflicts --store-id=UUID
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
require dirname(__DIR__) . '/app/bootstrap.php';

$cmd = $argv[1] ?? 'help';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $a, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$rupees = function (string $s): int {
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $s, $m)) {
        fwrite(STDERR, "Invalid amount $s\n");
        exit(1);
    }
    return (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
};
$pct = function (string $s) use ($rupees): int {     // "5" → 500 bp, "12.5" → 1250 bp (same integer parsing as amounts)
    return $rupees($s);
};
$db = Server\Db::instance();

switch ($cmd) {
    case 'migrate':
        $done = $db->migrate(SERVER_ROOT . '/database/migrations/mysql');
        echo $done ? 'Applied: ' . implode(', ', $done) . "\n" : "Database is up to date.\n";
        break;
    case 'register-device':
        foreach (['store-id', 'device-id', 'code'] as $k) {
            if (empty($opt[$k])) {
                exit("Missing --$k\n");
            }
        }
        $token = Server\Kernel::registerDevice($db, $opt['store-id'], $opt['store-name'] ?? 'Shop', $opt['device-id'], strtoupper($opt['code']), $opt['name'] ?? 'Counter', isset($opt['admin']));
        echo "Device registered. Copy this token into the shop's Sync Center (it is shown only once):\n\n  $token\n\n";
        break;
    case 'set-price':
        $r = (new Server\MasterData($db))->publish(null, ['store_id' => $opt['store-id'] ?? '', 'changes' => [[
            'type' => 'variant_price', 'variant_id' => $opt['variant-id'] ?? '', 'mrp_paise' => $rupees($opt['mrp'] ?? ''), 'price_paise' => $rupees($opt['price'] ?? '')]]]);
        echo "Published {$r['published']} change(s). Shops receive it on their next manual Sync Now.\n";
        break;
    case 'set-tax':
        $c = ['type' => 'tax_rule', 'code' => $opt['code'] ?? '', 'name' => $opt['name'] ?? ($opt['code'] ?? ''), 'rate_bp' => $pct($opt['rate'] ?? '-1')];
        if (isset($opt['threshold'])) {
            $c['threshold_paise'] = $rupees($opt['threshold']);
            $c['above_rate_bp'] = $pct($opt['above'] ?? '0');
        }
        $r = (new Server\MasterData($db))->publish(null, ['store_id' => $opt['store-id'] ?? '', 'changes' => [$c]]);
        echo "Published tax rule change. Verify rates with your accountant.\n";
        break;
    case 'find-sale':
        $s = $db->one('SELECT id, invoice_no, status, total_paise, created_at, received_at FROM sales WHERE store_id = ? AND invoice_no = ?', [$opt['store-id'] ?? '', $opt['invoice'] ?? '']);
        echo $s ? json_encode($s, JSON_PRETTY_PRINT) . "\n" : "Not found\n";
        echo 'Copies of this invoice on server: ' . (int)$db->value('SELECT COUNT(*) FROM sales WHERE store_id = ? AND invoice_no = ?', [$opt['store-id'] ?? '', $opt['invoice'] ?? '']) . "\n";
        break;
    case 'conflicts':
        foreach ($db->all("SELECT created_at, kind, entity_type, entity_id, detail FROM conflicts WHERE store_id = ? AND status = 'open' ORDER BY created_at DESC LIMIT 100", [$opt['store-id'] ?? '']) as $c) {
            echo "{$c['created_at']}  {$c['kind']}  {$c['entity_type']} {$c['entity_id']}\n    {$c['detail']}\n";
        }
        break;
    default:
        echo file_get_contents(__FILE__, false, null, 0, 1200);
}
