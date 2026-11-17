<?php
declare(strict_types=1);

/**
 * HTTP smoke test against a running local server with a THROWAWAY database:
 *   $env:DB_PATH='storage/tmp/smoke.sqlite'; php -S 127.0.0.1:8099 -t public public/router.php
 *   php tests/E2E/http_smoke.php http://127.0.0.1:8099
 * Runs first-time setup (+ demo data), signs in, exercises POS JSON APIs (quote → checkout → reprint → hold/resume),
 * then GETs every page and fails on non-2xx/3xx, PHP warnings, or external (CDN) asset references.
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/');
$jar = tempnam(sys_get_temp_dir(), 'tpos');
$fail = 0;
$csrf = '';

function req(string $method, string $url, $body = null, array $headers = []): array
{
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = curl_exec($ch);
    $st = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$st, (string)$out, $loc];
}
function token(string $html): string
{
    return preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $m) ? $m[1] : (preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '');
}
function check(string $label, bool $ok, string $extra = ''): void
{
    global $fail;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . ($ok ? '' : "  $extra") . "\n";
    if (!$ok) {
        $fail++;
    }
}
function api(string $method, string $path, ?array $json = null): array
{
    global $base, $csrf;
    [$st, $out] = req($method, $base . $path, $json === null ? null : json_encode($json), ['Content-Type: application/json', 'Accept: application/json', 'X-CSRF-Token: ' . $csrf]);
    return [$st, json_decode($out, true) ?? ['raw' => substr($out, 0, 500)]];
}

[$st, $html, $loc] = req('GET', "$base/");
check('fresh install redirects to /login (no setup page)', $st === 302 && str_contains((string)$loc, '/login'), "$st $loc");
[$st, $html] = req('GET', "$base/login");
check('login page tells how to create the first admin', str_contains($html, 'create-admin.php'));
[$st] = req('GET', "$base/setup");
check('setup page no longer exists', $st === 302 || $st === 404, (string)$st);
// first admin via CLI (same DB_PATH env as the server)
putenv('TPOS_ADMIN_PASSWORD=Smoke12345');
exec('php ' . escapeshellarg(dirname(__DIR__, 2) . '/scripts/create-admin.php') . ' --shop="Smoke Textiles" --name=Owner --username=owner --code=C1 --demo 2>&1', $cliOut, $cliCode);
check('CLI create-admin + demo data', $cliCode === 0, implode("\n", $cliOut));
[$st, $html] = req('GET', "$base/login");
$csrf = token($html);
[$st, $html, $loc] = req('POST', "$base/login", http_build_query(['_csrf' => $csrf, 'username' => 'owner', 'password' => 'Smoke12345']));
check('admin can sign in', $st === 302 && !str_contains((string)$loc, 'login'), "$st $loc");
[$st, $html] = req('GET', "$base/");
check('dashboard renders after setup', $st === 200 && str_contains($html, 'Sales'), (string)$st);
$csrf = token($html);

// POS APIs
[$st, $pos] = req('GET', "$base/pos");
check('POS page', $st === 200 && str_contains($pos, 'id="scan"'));
[$st, $j] = api('GET', '/api/pos/search?q=shirt');
check('search finds demo shirts', $st === 200 && count($j['items'] ?? []) > 0, json_encode($j));
$item = null;
foreach ($j['items'] ?? [] as $it) {
    if ((int)$it['stock_milli'] >= 2000) { $item = $it; break; }
}
[$st, $j] = api('GET', '/api/pos/lookup?code=' . urlencode($item['barcode']));
check('barcode lookup exact', $st === 200 && count($j['items']) === 1 && $j['items'][0]['id'] === $item['id']);
[$st, $j] = api('GET', '/api/pos/lookup?code=NOPE-0000');
check('unknown barcode → empty', $st === 200 && $j['items'] === []);
$lines = [['variant_id' => $item['id'], 'qty_milli' => 2000]];
[$st, $j] = api('POST', '/api/pos/quote', ['lines' => $lines]);
check('quote', $st === 200 && $j['quote']['total_paise'] > 0, json_encode($j));
$total = $j['quote']['total_paise'];
[$st, $s] = api('GET', '/api/pos/status');
$pendingBefore = $s['pending_sync'] ?? -1;
if (!$s['cash_session']) {
    api('POST', '/api/cash/open', ['opening' => '1000']);
}
$ref = 'smoke-' . bin2hex(random_bytes(4));
$payload = ['client_ref' => $ref, 'lines' => $lines, 'payments' => [['method' => 'cash', 'amount_paise' => $total + 5000]], 'expected_total_paise' => $total, 'print' => true];
[$st, $j] = api('POST', '/api/pos/checkout', $payload);
check('checkout saves bill', $st === 200 && !empty($j['invoice_no']) && $j['change_paise'] === 5000, json_encode($j));
$saleId = $j['sale_id'] ?? '';
[$st, $j2] = api('POST', '/api/pos/checkout', $payload);
check('double submit returns same bill', $st === 200 && ($j2['sale_id'] ?? '') === $saleId && $j2['duplicate'] === true);
[$st, $s] = api('GET', '/api/pos/status');
check('pending sync increased by one bill event', ($s['pending_sync'] ?? 0) === $pendingBefore + 1, "$pendingBefore → " . ($s['pending_sync'] ?? '?'));
[$st, $rc] = req('GET', "$base/sales/$saleId/receipt?format=80mm");
check('receipt 80mm', $st === 200 && str_contains($rc, 'TOTAL') && str_contains($rc, '<svg'));
[$st, $rc] = req('GET', "$base/sales/$saleId/receipt?format=a4&kind=reprint");
check('receipt A4', $st === 200 && str_contains($rc, 'HSN'));
api('POST', "/api/sales/$saleId/printed", ['kind' => 'initial', 'format' => '80mm']);
[$st, $j] = api('POST', "/api/sales/$saleId/printed", ['kind' => 'initial', 'format' => '80mm']);
check('retry print logged as reprint, no new sale', $st === 200 && $j['kind'] === 'reprint');
[$st, $j] = api('POST', '/api/pos/hold', ['lines' => [['variant_id' => $item['id'], 'qty_milli' => 1000, 'sku' => $item['sku'], 'name' => $item['name']]], 'total_paise' => 1, 'label' => 'smoke']);
check('hold', $st === 200 && !empty($j['id']));
[$st, $h] = api('GET', '/api/pos/held');
[$st, $j] = api('POST', '/api/pos/held/' . $h['items'][0]['id'] . '/resume', []);
check('resume', $st === 200 && count($j['cart']['lines']) === 1);
[$st, $j] = api('POST', '/api/pos/checkout', ['client_ref' => 'x', 'lines' => $lines, 'payments' => [['method' => 'cash', 'amount_paise' => 100]], 'print' => true]);
check('underpayment rejected (422)', $st === 422 && str_contains($j['error'] ?? '', 'short'), json_encode($j));
[$st] = req('POST', "$base/api/pos/quote", json_encode(['lines' => $lines]), ['Content-Type: application/json']);
check('CSRF enforced on JSON POST', $st === 419);

// every page
$pages = ['/', '/pos', '/sales', "/sales/$saleId", '/returns', "/returns/sale/$saleId", '/products', '/products/new', '/masters', '/masters?tab=tax', '/masters?tab=sizes',
    '/import', '/stock', '/stock?loc=DAMAGED', '/stock/movements', '/stock/adjust', '/purchases', '/purchases/new', '/supplier-returns/new', '/suppliers', '/customers', '/expenses', '/cash',
    '/reports', '/sync', '/settings', '/settings?tab=billing', '/settings?tab=printing', '/settings?tab=shortcuts', '/staff', '/staff?tab=roles', '/backup', '/audit', '/system', '/help',
    '/print-test', '/account/password', '/export/products'];
foreach (array_keys(App_reports()) as $k) {
    $pages[] = "/reports/$k";
    $pages[] = "/reports/$k?format=csv";
}
[$st, $html] = req('GET', "$base/products");
if (preg_match('#/products/([0-9a-f-]{36})"#', $html, $m)) {
    $pages[] = '/products/' . $m[1];
    $pages[] = '/products/' . $m[1] . '/edit';
    $pages[] = '/labels?product=' . $m[1];
}
[$st, $html] = req('GET', "$base/returns");
if (preg_match('#/returns/([0-9a-f-]{36})"#', $html, $m)) {
    $pages[] = '/returns/' . $m[1];
    $pages[] = '/returns/' . $m[1] . '?print=1';
}
[$st, $html] = req('GET', "$base/customers");
if (preg_match('#/customers/([0-9a-f-]{36})"#', $html, $m)) {
    $pages[] = '/customers/' . $m[1];
}
foreach ($pages as $p) {
    [$st, $html] = req('GET', $base . $p);
    $bad = preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)\b.*on line/i', $html) || preg_match('#(src|href)="https?://#i', $html);
    check("GET $p → $st", $st >= 200 && $st < 400 && !$bad, $bad ? 'PHP warning or external asset in output: ' . substr(strip_tags($html), 0, 200) : '');
}
// permissions: cashier cannot see profit / settings
$csrf = token(req('GET', "$base/staff")[1]);
[$st, $html] = req('GET', "$base/staff");
preg_match('/name="role_id"><option value="([^"]+)"[^>]*>Admin/', $html, $m0);
preg_match_all('/<option value="([0-9a-f-]{36})"[^>]*>Cashier</', $html, $mm);
$cashierRole = $mm[1][0] ?? '';
req('POST', "$base/staff/save", http_build_query(['_csrf' => $csrf, 'full_name' => 'Cash Test', 'username' => 'cashtest', 'role_id' => $cashierRole, 'password' => 'Cashier123', 'is_active' => '1']));
req('POST', "$base/logout", http_build_query(['_csrf' => $csrf]));
[$st, $html] = req('GET', "$base/login");
$csrf = token($html);
[$st, , $loc] = req('POST', "$base/login", http_build_query(['_csrf' => $csrf, 'username' => 'cashtest', 'password' => 'Cashier123']));
check('cashier login lands on POS', $st === 302 && str_contains((string)$loc, '/pos'), (string)$loc);
foreach (['/reports/profit' => 403, '/settings' => 403, '/sync' => 403, '/staff' => 403, '/backup' => 403, '/audit' => 403, '/pos' => 200, '/sales' => 200] as $p => $want) {
    [$st] = req('GET', $base . $p);
    check("cashier GET $p → $want", $st === $want, (string)$st);
}
[$st, $html] = req('GET', "$base/pos");
$csrf = token($html);
[$st, $j] = api('POST', '/api/approvals', ['username' => 'cashtest', 'password' => 'Cashier123', 'permission' => 'return.refund']);
check('cashier cannot self-approve refund', $st === 422);
[$st, $j] = api('POST', '/api/sync/run', ['confirm' => true]);
check('cashier cannot run sync', $st === 403);

// brute force lockout
req('POST', "$base/logout", http_build_query(['_csrf' => $csrf]));
[$st, $html] = req('GET', "$base/login");
$csrf = token($html);
for ($i = 0; $i < 6; $i++) {
    [$st, $html] = req('POST', "$base/login", http_build_query(['_csrf' => $csrf, 'username' => 'owner', 'password' => 'wrong' . $i]));
}
[$st, $html] = req('GET', "$base/login");
check('login rate limit after repeated failures', str_contains($html, 'Too many failed attempts'));

@unlink($jar);
echo $fail ? "\n$fail checks FAILED\n" : "\nAll HTTP smoke checks passed\n";
exit($fail ? 1 : 0);

function App_reports(): array
{
    return ['sales_summary' => 1, 'hourly' => 1, 'weekday' => 1, 'by_product' => 1, 'by_variant' => 1, 'by_size' => 1, 'by_color' => 1, 'by_category' => 1, 'by_brand' => 1,
        'by_cashier' => 1, 'payments' => 1, 'discounts' => 1, 'returns' => 1, 'slow_movers' => 1, 'purchases' => 1, 'stock_movements' => 1, 'low_stock' => 1,
        'valuation' => 1, 'expenses' => 1, 'profit' => 1];
}
