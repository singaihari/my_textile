<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Context;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Settings;
use App\Core\ValidationException;

/** First-run setup: migrations, shop/store record, this device, stock locations and the first admin. */
final class SetupService
{
    public function __construct(private Database $db)
    {
    }

    public function ensureSchema(): array
    {
        return (new Migrator($this->db))->migrate();
    }

    public function run(array $in): array
    {
        if ((int)$this->db->value('SELECT COUNT(*) FROM users') > 0) {
            throw new ValidationException('Setup has already been completed.');
        }
        $shop = trim((string)($in['shop_name'] ?? ''));
        $username = trim((string)($in['username'] ?? ''));
        $fullName = trim((string)($in['full_name'] ?? ''));
        $password = (string)($in['password'] ?? '');
        $deviceCode = strtoupper(trim((string)($in['device_code'] ?? 'C1')));
        $errors = [];
        if ($shop === '') $errors['shop_name'] = 'Shop name is required';
        if (!preg_match('/^[A-Za-z0-9._@-]{3,32}$/', $username)) $errors['username'] = '3-32 letters, digits, dot, dash, underscore or @';
        if ($fullName === '') $errors['full_name'] = 'Name is required';
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $errors['password'] = 'At least 8 characters with letters and numbers';
        }
        if ($password !== (string)($in['password_confirm'] ?? '')) $errors['password_confirm'] = 'Passwords do not match';
        if (!preg_match('/^[A-Z0-9]{1,4}$/', $deviceCode)) $errors['device_code'] = '1-4 letters/digits, e.g. C1';
        if ($errors) {
            throw new ValidationException('Please correct the highlighted fields.', $errors);
        }

        return $this->db->transaction(function (Database $db) use ($in, $shop, $username, $fullName, $password, $deviceCode) {
            $storeId = $db->value('SELECT id FROM stores LIMIT 1');
            if (!$storeId) {
                $storeId = uuid();
                $db->insert('stores', ['id' => $storeId, 'code' => 'MAIN', 'name' => $shop, 'address' => $in['shop_address'] ?? null,
                    'phone' => $in['shop_phone'] ?? null, 'gstin' => $in['shop_gstin'] ?? null,
                    'state_code' => $in['shop_state_code'] ?? '33', 'state_name' => $in['shop_state_name'] ?? 'Tamil Nadu',
                    'created_at' => now(), 'updated_at' => now()]);
                $db->insert('devices', ['id' => uuid(), 'store_id' => $storeId, 'code' => $deviceCode, 'name' => 'Billing Counter ' . $deviceCode,
                    'is_this_device' => 1, 'created_at' => now()]);
                $db->insert('locations', ['id' => uuid(), 'store_id' => $storeId, 'code' => 'MAIN', 'name' => 'Shop floor', 'is_sellable' => 1]);
                $db->insert('locations', ['id' => uuid(), 'store_id' => $storeId, 'code' => 'DAMAGED', 'name' => 'Damaged goods', 'is_sellable' => 0]);
            }
            $roleId = $db->value("SELECT id FROM roles WHERE code = 'admin'");
            $userId = uuid();
            $db->insert('users', ['id' => $userId, 'store_id' => $storeId, 'username' => $username, 'full_name' => $fullName,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role_id' => $roleId, 'is_active' => 1,
                'password_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            Context::reset();
            Settings::flush();
            foreach (['shop_name' => $shop, 'shop_address' => $in['shop_address'] ?? '', 'shop_phone' => $in['shop_phone'] ?? '',
                         'shop_gstin' => $in['shop_gstin'] ?? '', 'setup_complete' => '1'] as $k => $v) {
                Settings::set($k, (string)$v, $userId);
            }
            Audit::log('setup.completed', 'store', $storeId, ['admin' => $username], $userId);
            return ['store_id' => $storeId, 'user_id' => $userId];
        });
    }
}
