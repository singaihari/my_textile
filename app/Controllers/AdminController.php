<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Seeder;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\BackupService;
use App\Services\OutboxService;

final class AdminController extends Controller
{
    private const EDITABLE = ['shop_name', 'shop_address', 'shop_phone', 'shop_email', 'shop_gstin', 'shop_state_code', 'shop_state_name',
        'invoice_prefix', 'return_prefix', 'fy_start_month', 'prices_include_tax', 'round_off', 'receipt_footer', 'return_policy_text',
        'printer_format', 'auto_print', 'show_tax_breakup', 'label_width_mm', 'label_height_mm', 'label_columns', 'language',
        'allow_negative_stock', 'merge_duplicate_scans', 'require_customer', 'require_cash_session', 'return_window_days',
        'qty_precision_m', 'session_lock_minutes', 'payment_methods', 'upi_vpa', 'backup_keep', 'shortcuts'];
    private const BOOLEANS = ['prices_include_tax', 'auto_print', 'show_tax_breakup', 'allow_negative_stock', 'merge_duplicate_scans', 'require_customer', 'require_cash_session'];

    public function settings(Request $r): Response
    {
        return $this->view('pages/settings', 'Settings', ['s' => Settings::all(), 'shortcuts' => PosController::shortcuts()]);
    }

    public function saveSettings(Request $r): Response
    {
        $in = $r->all();
        $section = $r->str('section');
        $changed = [];
        foreach (self::EDITABLE as $k) {
            if (in_array($k, self::BOOLEANS, true)) {
                if (!array_key_exists('_has_' . $k, $in)) {
                    continue;
                }
                $v = !empty($in[$k]) ? '1' : '0';
            } elseif ($k === 'payment_methods') {
                if (!isset($in['_has_payment_methods'])) {
                    continue;
                }
                $v = implode(',', array_intersect(['cash', 'upi', 'card', 'credit', 'other'], (array)($in['payment_methods'] ?? [])));
                if (!str_contains($v, 'cash')) {
                    $v = 'cash' . ($v ? ',' . $v : '');
                }
            } elseif ($k === 'shortcuts') {
                if (!isset($in['shortcut'])) {
                    continue;
                }
                $map = [];
                foreach ((array)$in['shortcut'] as $action => $keys) {
                    $list = array_values(array_filter(array_map('trim', explode(',', (string)$keys)), fn($x) => preg_match('/^(Ctrl\+|Alt\+|Shift\+)*[A-Za-z0-9]+$|^F\d{1,2}$/', $x)));
                    $map[preg_replace('/[^a-z]/', '', (string)$action)] = $list;
                }
                $v = json_encode($map);
            } else {
                if (!array_key_exists($k, $in)) {
                    continue;
                }
                $v = trim((string)$in[$k]);
            }
            $this->validateSetting($k, $v);
            if ((string)Settings::get($k) !== $v) {
                $changed[] = $k;
                Settings::set($k, $v, Auth::id());
            }
        }
        if ($changed) {
            $this->db->update('stores', ['name' => Settings::get('shop_name'), 'gstin' => Settings::get('shop_gstin') ?: null, 'updated_at' => now()], 'id = :id', ['id' => Context::storeId()]);
            if (array_intersect($changed, ['prices_include_tax'])) {
                Auth::authorize('tax.manage');
            }
            Audit::log('settings.changed', 'settings', $section ?: null, ['keys' => $changed]);
        }
        flash('success', $changed ? 'Settings saved (' . count($changed) . ' changed).' : 'No changes.');
        return Response::redirect('/settings', ['tab' => $section ?: 'shop']);
    }

    private function validateSetting(string $k, string $v): void
    {
        $err = match ($k) {
            'invoice_prefix', 'return_prefix' => preg_match('/^[A-Z0-9]{1,4}$/', $v) ? null : 'Prefix: 1-4 capital letters/digits',
            'shop_gstin' => $v === '' || preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/', $v) ? null : 'GSTIN format looks wrong',
            'fy_start_month' => ((int)$v >= 1 && (int)$v <= 12) ? null : 'Month 1-12',
            'return_window_days' => ((int)$v >= 0 && (int)$v <= 365) ? null : '0-365 days',
            'session_lock_minutes' => ((int)$v >= 1 && (int)$v <= 240) ? null : '1-240 minutes',
            'qty_precision_m' => in_array($v, ['0', '1', '2', '3'], true) ? null : '0-3 decimals',
            'round_off' => in_array($v, ['rupee', 'none'], true) ? null : 'Invalid',
            'printer_format' => in_array($v, ['80mm', 'a4'], true) ? null : 'Invalid',
            'language' => in_array($v, ['en', 'ta'], true) ? null : 'Invalid',
            'upi_vpa' => $v === '' || preg_match('/^[A-Za-z0-9._-]{2,256}@[A-Za-z]{2,64}$/', $v) ? null : 'UPI ID looks wrong (name@bank)',
            'label_width_mm', 'label_height_mm' => ((int)$v >= 15 && (int)$v <= 120) ? null : '15-120 mm',
            'label_columns' => ((int)$v >= 1 && (int)$v <= 6) ? null : '1-6',
            'backup_keep' => ((int)$v >= 3 && (int)$v <= 365) ? null : '3-365',
            default => null,
        };
        if ($err) {
            throw new ValidationException("$k: $err", [$k => $err]);
        }
    }

    public function staff(Request $r): Response
    {
        $users = $this->db->all('SELECT u.*, r.name role_name, r.code role_code FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.is_active DESC, u.full_name');
        $roles = $this->db->all('SELECT * FROM roles ORDER BY is_system DESC, name');
        $perms = $this->db->all('SELECT * FROM permissions ORDER BY grp, code');
        $rp = [];
        foreach ($this->db->all('SELECT * FROM role_permissions') as $x) {
            $rp[$x['role_id']][$x['permission_code']] = true;
        }
        $edit = $r->query('edit') ? $this->db->one('SELECT * FROM users WHERE id = ?', [$r->query('edit')]) : null;
        return $this->view('pages/staff', 'Staff & roles', compact('users', 'roles', 'perms', 'rp', 'edit') + [
            'devices' => $this->db->all('SELECT * FROM devices ORDER BY code'),
            'activity' => $this->db->all('SELECT a.created_at, a.action, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.action LIKE \'auth.%\' ORDER BY a.created_at DESC LIMIT 20'),
        ]);
    }

    public function saveStaff(Request $r): Response
    {
        $id = $r->str('id') ?: null;
        $username = $r->str('username');
        $name = $r->str('full_name');
        $roleId = $r->str('role_id');
        $pw = (string)$r->input('password', '');
        if (!preg_match('/^[A-Za-z0-9._@-]{3,32}$/', $username)) {
            throw new ValidationException('Username: 3-32 letters, digits, dot, dash, underscore or @');
        }
        if ($name === '' || !$this->db->value('SELECT 1 FROM roles WHERE id = ?', [$roleId])) {
            throw new ValidationException('Name and role are required');
        }
        if ((!$id || $pw !== '') && (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw))) {
            throw new ValidationException('Password: at least 8 characters with letters and numbers');
        }
        $active = $r->str('is_active', '1') === '1' ? 1 : 0;
        if ($id === Auth::id() && !$active) {
            throw new ValidationException('You cannot disable your own account');
        }
        $adminRole = $this->db->value("SELECT id FROM roles WHERE code = 'admin'");
        if ($id && ($this->db->value('SELECT role_id FROM users WHERE id = ?', [$id]) === $adminRole) && ($roleId !== $adminRole || !$active)
            && (int)$this->db->value('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1', [$adminRole]) <= 1) {
            throw new ValidationException('At least one active admin is required');
        }
        $row = ['username' => $username, 'full_name' => $name, 'role_id' => $roleId, 'is_active' => $active,
            'counter_device_id' => $r->str('counter_device_id') ?: null, 'updated_at' => now()];
        if ($pw !== '') {
            $row['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            $row['password_changed_at'] = now();
        }
        try {
            if ($id) {
                $this->db->update('users', $row, 'id = :id', ['id' => $id]);
            } else {
                $id = uuid();
                $this->db->insert('users', $row + ['id' => $id, 'store_id' => Context::storeId(), 'created_at' => now()]);
            }
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                throw new ValidationException('Username already taken');
            }
            throw $e;
        }
        Audit::log('staff.saved', 'user', $id, ['username' => $username, 'active' => $active, 'password_changed' => $pw !== '']);
        flash('success', 'Staff account saved.');
        return Response::redirect('/staff');
    }

    public function saveRole(Request $r): Response
    {
        $id = $r->str('id') ?: null;
        $role = $id ? $this->find('SELECT * FROM roles WHERE id = ?', [$id], 'Role') : null;
        if ($role && $role['code'] === 'admin') {
            throw new ValidationException('The Admin role always has every permission');
        }
        $name = $r->str('name');
        if ($name === '') {
            throw new ValidationException('Role name required');
        }
        $maxBp = max(0, min(10000, (int)round(((int)$r->str('max_discount_pct', '0')) * 100)));
        $perms = array_intersect(array_column(Seeder::PERMISSIONS, 1), (array)$r->input('perms', []));
        $this->db->transaction(function () use ($id, $name, $maxBp, $perms, &$role) {
            if (!$id) {
                $id = uuid();
                $code = 'custom_' . substr(md5($name . microtime()), 0, 6);
                $this->db->insert('roles', ['id' => $id, 'code' => $code, 'name' => $name, 'max_discount_bp' => $maxBp, 'is_system' => 0, 'created_at' => now()]);
            } else {
                $this->db->update('roles', ['name' => $name, 'max_discount_bp' => $maxBp], 'id = :id', ['id' => $id]);
                $this->db->run('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            }
            foreach ($perms as $p) {
                $this->db->insert('role_permissions', ['role_id' => $id, 'permission_code' => $p]);
            }
            Audit::log('role.saved', 'role', $id, ['name' => $name, 'perms' => count($perms), 'max_discount_bp' => $maxBp]);
        });
        flash('success', 'Role saved.');
        return Response::redirect('/staff', ['tab' => 'roles']);
    }

    public function backup(Request $r): Response
    {
        return $this->view('pages/backup', 'Backup & restore', ['backups' => (new BackupService($this->db))->list(),
            'dbPath' => $this->db->path, 'dbSize' => is_file($this->db->path) ? filesize($this->db->path) : 0]);
    }

    public function createBackup(Request $r): Response
    {
        $b = (new BackupService($this->db))->create('manual');
        flash('success', 'Backup created and verified: ' . $b['file']);
        return Response::redirect('/backup');
    }

    public function uploadBackup(Request $r): Response
    {
        $f = $r->file('backup') ?? throw new ValidationException('Choose a .sqlite backup file');
        $name = (new BackupService($this->db))->storeUpload($f);
        flash('success', "Uploaded and validated $name. Use Restore to apply it.");
        return Response::redirect('/backup');
    }

    public function restoreBackup(Request $r): Response
    {
        if ($r->str('confirm') !== 'RESTORE') {
            throw new ValidationException('Type RESTORE to confirm');
        }
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        if (!password_verify((string)$r->input('password', ''), $u['password_hash'])) {
            throw new ValidationException('Your password is required to restore');
        }
        $pendingSync = (new OutboxService($this->db))->pendingCount();
        $res = (new BackupService($this->db))->stageRestore($r->str('file'));
        Auth::logout();
        flash('warning', "Restore staged from {$res['source']}. A safety backup ({$res['safety_backup']}) was taken first"
            . ($pendingSync ? " — note: $pendingSync bills were not yet synced before restore; they are kept in the safety backup." : '.')
            . ' The restore is applied on the next page load. Sign in again to verify.');
        return Response::redirect('/login');
    }

    public function downloadBackup(Request $r): Response
    {
        $path = (new BackupService($this->db))->path((string)$r->query('f', ''));
        Audit::log('backup.downloaded', 'backup', basename($path));
        return Response::download((string)file_get_contents($path), basename($path), 'application/vnd.sqlite3');
    }

    public function audit(Request $r): Response
    {
        $p = [];
        $where = '1=1';
        if ($a = $r->query('action')) {
            $where .= ' AND a.action LIKE :a';
            $p['a'] = $a . '%';
        }
        $page = View::paginate($this->db, "SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.created_at DESC, a.id DESC", $p, $this->page($r), 50);
        return $this->view('pages/audit', 'Audit log', ['page' => $page]);
    }

    public function system(Request $r): Response
    {
        $db = $this->db;
        $checks = [
            ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>=')],
            ['pdo_sqlite', extension_loaded('pdo_sqlite') ? 'loaded' : 'missing', extension_loaded('pdo_sqlite')],
            ['SQLite version', (string)$db->value('SELECT sqlite_version()'), version_compare((string)$db->value('SELECT sqlite_version()'), '3.27.0', '>=')],
            ['Journal mode', (string)$db->value('PRAGMA journal_mode'), $db->value('PRAGMA journal_mode') === 'wal'],
            ['Integrity check (quick)', (string)$db->value('PRAGMA quick_check'), $db->value('PRAGMA quick_check') === 'ok'],
            ['Schema version', (string)(new Migrator($db))->currentVersion(), true],
            ['curl (for Sync)', extension_loaded('curl') ? 'loaded' : 'missing', extension_loaded('curl')],
            ['Database file outside web root', str_starts_with(realpath($db->path) ?: '', realpath(APP_ROOT . '/public') ?: '###') ? 'NO' : 'yes',
                !str_starts_with(realpath($db->path) ?: '', realpath(APP_ROOT . '/public') ?: '###')],
            ['Storage writable', is_writable(APP_ROOT . '/storage') ? 'yes' : 'no', is_writable(APP_ROOT . '/storage')],
            ['Server software', (string)($_SERVER['SERVER_SOFTWARE'] ?? 'cli'), !str_contains((string)($_SERVER['SERVER_SOFTWARE'] ?? ''), 'Development Server')],
        ];
        $lastBackup = (new BackupService($db))->list()[0] ?? null;
        return $this->view('pages/system', 'System health', ['checks' => $checks, 'lastBackup' => $lastBackup, 'sync' => (new OutboxService($db))->counts(),
            'device' => Context::device(), 'store' => Context::store()]);
    }

    public function help(Request $r): Response
    {
        return $this->view('pages/help', 'Keyboard shortcuts & help', ['shortcuts' => PosController::shortcuts()]);
    }
}
