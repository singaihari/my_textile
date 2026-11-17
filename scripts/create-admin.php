<?php
declare(strict_types=1);

/**
 * Creates the shop and the first administrator (there is no setup web page and no default password),
 * or adds / resets an administrator later.
 *
 * Interactive:      php scripts/create-admin.php
 * Non-interactive:  $env:TPOS_ADMIN_PASSWORD='Secret123'; php scripts/create-admin.php --shop="My Shop" --name="Owner" --username=admin --code=C1 [--demo]
 *   (--demo loads FICTIONAL training data into the new, empty shop)
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Context;
use App\Core\Database;
use App\Services\SetupService;

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) {
        $opt[$m[1]] = $m[2] ?? '1';
    }
}
$db = Database::instance();
$setup = new SetupService($db);
$setup->ensureSchema();
$ask = function (string $q, string $key, bool $secret = false) use ($opt): string {
    if (isset($opt[$key]) && $opt[$key] !== '') {
        return $opt[$key];
    }
    if ($secret && ($env = getenv('TPOS_ADMIN_PASSWORD')) !== false && $env !== '') {
        return $env;
    }
    echo $q;
    return trim((string)fgets(STDIN));
};

if (!Context::isSetUp()) {
    echo "First-time setup (creates the shop and the admin account)\n";
    $in = ['shop_name' => $ask('Shop name: ', 'shop'), 'device_code' => $ask('Counter code [C1]: ', 'code') ?: 'C1',
        'full_name' => $ask('Your name: ', 'name'), 'username' => $ask('Admin username: ', 'username')];
    $in['password'] = $ask('Password (8+ chars, letters & digits — visible while typing on Windows): ', 'password', true);
    $in['password_confirm'] = getenv('TPOS_ADMIN_PASSWORD') ?: $ask('Repeat password: ', 'confirm');
    try {
        $res = $setup->run($in);
    } catch (App\Core\ValidationException $e) {
        echo 'Error: ' . $e->getMessage() . "\n";
        foreach ($e->errors as $k => $v) {
            echo "  $k: $v\n";
        }
        exit(1);
    }
    echo "Shop and admin '{$in['username']}' created.\n";
    if (isset($opt['demo'])) {
        require APP_ROOT . '/database/seeders/DemoSeeder.php';
        Auth::actAs($db->one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$res['user_id']]));
        $r = (new \Database\Seeders\DemoSeeder($db))->run();
        echo "DEMO data loaded: {$r['products']} products, {$r['variants']} SKUs, {$r['bills']} bills (fictional).\n";
    }
    echo "Open the app and sign in.\n";
    exit(0);
}

$username = $ask('Admin username to create or reset: ', 'username');
$pw = $ask('New password: ', 'password', true);
if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
    exit("Password must be 8+ characters with letters and digits\n");
}
$role = $db->value("SELECT id FROM roles WHERE code = 'admin'");
$u = $db->one('SELECT id FROM users WHERE username = ?', [$username]);
if ($u) {
    $db->update('users', ['password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role_id' => $role, 'is_active' => 1, 'updated_at' => now()], 'id = :id', ['id' => $u['id']]);
    App\Core\Audit::log('cli.admin_reset', 'user', $u['id']);
    echo "Password reset and admin role assigned for $username.\n";
} else {
    if (!preg_match('/^[A-Za-z0-9._@-]{3,32}$/', $username)) {
        exit("Username: 3-32 letters, digits, dot, dash, underscore or @\n");
    }
    $id = uuid();
    $db->insert('users', ['id' => $id, 'store_id' => Context::storeId(), 'username' => $username, 'full_name' => $username, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
        'role_id' => $role, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    App\Core\Audit::log('cli.admin_created', 'user', $id);
    echo "Admin $username created.\n";
}
