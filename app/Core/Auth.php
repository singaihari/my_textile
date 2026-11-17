<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Session authentication, role permissions, inactivity lock, login rate limiting
 * and one-time manager approvals (for discount overrides, refunds, etc.).
 */
final class Auth
{
    public const MAX_FAILED = 5;
    public const LOCKOUT_MINUTES = 15;

    private static ?array $user = null;
    private static ?array $perms = null;

    public static function startSession(Request $req): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        $path = APP_ROOT . '/storage/sessions';
        if (!is_dir($path)) {
            mkdir($path, 0770, true);
        }
        session_save_path($path);
        session_name('TPOSSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => base_path_url() ?: '/',
            'secure' => $req->isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '43200');
        session_start();
    }

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $u = Database::instance()->one(
            'SELECT u.*, r.code AS role_code, r.name AS role_name, r.max_discount_bp
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1', [$id]);
        if (!$u) {
            unset($_SESSION['user_id']);
            return null;
        }
        unset($u['password_hash']);
        return self::$user = $u;
    }

    public static function id(): ?string
    {
        return self::user()['id'] ?? null;
    }

    /** For CLI scripts and tests. */
    public static function actAs(?array $user): void
    {
        self::$user = $user;
        self::$perms = null;
        if ($user) {
            unset(self::$user['password_hash']);
        }
    }

    public static function permissions(): array
    {
        if (self::$perms === null) {
            $u = self::user();
            self::$perms = $u ? self::permissionsForRole($u['role_id']) : [];
        }
        return self::$perms;
    }

    public static function permissionsForRole(string $roleId): array
    {
        return array_column(Database::instance()->all(
            'SELECT permission_code FROM role_permissions WHERE role_id = ?', [$roleId]), 'permission_code');
    }

    public static function can(string $perm): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['role_code'] === 'admin') {
            return true;
        }
        return in_array($perm, self::permissions(), true);
    }

    public static function userCan(array $user, string $perm): bool
    {
        $role = Database::instance()->one('SELECT id, code FROM roles WHERE id = ?', [$user['role_id']]);
        if (!$role) {
            return false;
        }
        return $role['code'] === 'admin' || in_array($perm, self::permissionsForRole($role['id']), true);
    }

    public static function authorize(string $perm): void
    {
        if (!self::can($perm)) {
            Audit::log('permission.denied', 'permission', $perm);
            throw new HttpException(403, 'You do not have permission for this action (' . $perm . ').');
        }
    }

    public static function requireLogin(Request $req): void
    {
        if (!self::user()) {
            throw new HttpException(401, 'Please sign in.');
        }
        if (PHP_SAPI === 'cli') {
            return;
        }
        // Automatic inactivity lock removed at the shop's request; an explicitly locked session still requires the password.
        if (!empty($_SESSION['locked'])) {
            throw new HttpException(423, 'Screen locked. Enter your password to continue.');
        }
        if (!in_array($req->path, ['/api/ping', '/api/pos/status'], true)) {   // background status polls are not "activity"
            $_SESSION['last_activity'] = time();
        }
    }

    /**
     * Verify credentials with rate limiting. Returns the user row or throws ValidationException.
     */
    public static function attempt(string $username, string $password, string $ip): array
    {
        $db = Database::instance();
        $since = date('Y-m-d H:i:s', time() - self::LOCKOUT_MINUTES * 60);
        $fails = (int)$db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE (username = ? OR ip = ?) AND success = 0 AND attempted_at >= ?',
            [$username, $ip, $since]);
        if ($fails >= self::MAX_FAILED * 2 || (int)$db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at >= ?',
            [$username, $since]) >= self::MAX_FAILED) {
            throw new ValidationException('Too many failed attempts. Try again in ' . self::LOCKOUT_MINUTES . ' minutes or ask an admin.');
        }
        $u = $db->one('SELECT * FROM users WHERE username = ?', [$username]);
        $ok = $u && (int)$u['is_active'] === 1 && password_verify($password, $u['password_hash']);
        if (!$u) {
            // constant-ish time: still run a hash verification
            password_verify($password, '$2y$10$usesomesillystringforsaltqhK2WkNDpm1C9.V2Y9VgEXEr6C7HXO');
        }
        $db->insert('login_attempts', ['id' => uuid(), 'username' => $username, 'ip' => $ip, 'success' => $ok ? 1 : 0, 'attempted_at' => now()]);
        if (!$ok) {
            Audit::log('auth.login_failed', 'user', $u['id'] ?? null, ['username' => $username], $u['id'] ?? null);
            throw new ValidationException('Invalid username or password.');
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            $db->update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $u['id']]);
        }
        return $u;
    }

    public static function login(array $user): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['last_activity'] = time();
        $_SESSION['locked'] = false;
        unset($_SESSION['_csrf']);
        self::$user = null;
        self::$perms = null;
        Database::instance()->update('users', ['last_login_at' => now()], 'id = :id', ['id' => $user['id']]);
        Audit::log('auth.login', 'user', $user['id']);
    }

    public static function logout(): void
    {
        if (self::id()) {
            Audit::log('auth.logout', 'user', self::id());
        }
        $_SESSION = [];
        self::$user = null;
        self::$perms = null;
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function unlock(string $password): void
    {
        $u = Database::instance()->one('SELECT * FROM users WHERE id = ?', [$_SESSION['user_id'] ?? '']);
        if (!$u || !password_verify($password, $u['password_hash'])) {
            Audit::log('auth.unlock_failed', 'user', $u['id'] ?? null);
            throw new ValidationException('Incorrect password.');
        }
        $_SESSION['locked'] = false;
        $_SESSION['last_activity'] = time();
    }

    // ------------------------------------------------------------------ approvals

    /** A manager/admin enters their credentials to approve a restricted action. Returns a one-time token. */
    public static function grantApproval(string $username, string $password, string $perm, string $ip): array
    {
        $u = self::attempt($username, $password, $ip);
        if (!self::userCan($u, $perm)) {
            throw new ValidationException($u['full_name'] . ' is not allowed to approve this action.');
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['_approvals'][$token] = ['perm' => $perm, 'user_id' => $u['id'], 'name' => $u['full_name'], 'expires' => time() + 300];
        Audit::log('approval.granted', 'permission', $perm, ['approver' => $u['username']]);
        $role = Database::instance()->one('SELECT max_discount_bp FROM roles WHERE id = ?', [$u['role_id']]);
        return ['token' => $token, 'approver' => $u['full_name'], 'approver_id' => $u['id'], 'max_discount_bp' => (int)$role['max_discount_bp']];
    }

    /** Returns approver user id when the token is valid and the approver holds $perm; the token is consumed. */
    public static function consumeApproval(?string $token, string $perm): ?string
    {
        $id = self::peekApproval($token, $perm);
        if ($token) {
            unset($_SESSION['_approvals'][$token]);
        }
        return $id;
    }

    /** Validate an approval token for $perm without consuming it. */
    public static function peekApproval(?string $token, string $perm): ?string
    {
        if (!$token) {
            return null;
        }
        $a = $_SESSION['_approvals'][$token] ?? null;
        if (!$a || $a['expires'] < time()) {
            return null;
        }
        $u = Database::instance()->one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$a['user_id']]);
        return ($u && self::userCan($u, $perm)) ? $u['id'] : null;
    }

    public static function maxDiscountBp(?string $userId = null): int
    {
        if ($userId === null) {
            return (int)(self::user()['max_discount_bp'] ?? 0);
        }
        return (int)Database::instance()->value(
            'SELECT r.max_discount_bp FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$userId]);
    }
}
