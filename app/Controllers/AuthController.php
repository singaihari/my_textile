<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\View;

final class AuthController extends Controller
{
    public function loginForm(Request $r): Response
    {
        if (Auth::user() && empty($_SESSION['locked'])) {
            return Response::redirect('/');
        }
        return Response::html(View::render('pages/login', ['title' => 'Sign in', 'next' => $r->query('next', '/'),
            'noAccounts' => !Context::isSetUp()], 'layouts/bare'));
    }

    public function login(Request $r): Response
    {
        if (!Context::isSetUp()) {
            throw new ValidationException('No admin account exists yet. On the shop PC run: php scripts\\create-admin.php');
        }
        return $this->doLogin($r);
    }

    private function doLogin(Request $r): Response
    {
        $u = Auth::attempt($r->str('username'), (string)$r->input('password', ''), $r->ip());
        Auth::login($u);
        $next = (string)$r->input('next', '/');
        if (!preg_match('#^/[A-Za-z0-9/_?=&.-]*$#', $next) || str_starts_with($next, '//')) {
            $next = '/';
        }
        $role = $this->db->value('SELECT code FROM roles WHERE id = ?', [$u['role_id']]);
        if ($next === '/' && $role === 'cashier') {
            $next = '/pos';
        }
        return Response::redirect($next);
    }

    public function logout(Request $r): Response
    {
        if (!empty($_SESSION['_csrf'])) {
            \App\Core\Csrf::verify($r);
        }
        Auth::logout();
        return Response::redirect('/login');
    }

    public function lockForm(Request $r): Response
    {
        if (!Auth::user()) {
            return Response::redirect('/login');
        }
        return Response::html(View::render('pages/lock', ['title' => 'Locked', 'next' => $r->query('next', '/')], 'layouts/bare'));
    }

    public function unlock(Request $r): Response|array
    {
        \App\Core\Csrf::verify($r);
        Auth::unlock((string)$r->input('password', ''));
        if ($r->wantsJson()) {
            return $this->ok();
        }
        $next = (string)$r->input('next', '/');
        return Response::redirect(preg_match('#^/[A-Za-z0-9/_-]*$#', $next) ? $next : '/');
    }

    public function lang(Request $r): Response
    {
        $_SESSION['lang'] = $r->str('lang') === 'ta' ? 'ta' : 'en';
        if (Auth::id()) {
            $this->db->update('users', ['language' => $_SESSION['lang']], 'id = :id', ['id' => Auth::id()]);
        }
        return $this->back();
    }

    public function favicon(Request $r): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#2563EB"/><text x="16" y="21" font-family="Arial" font-size="13" font-weight="700" fill="#fff" text-anchor="middle">TX</text></svg>';
        return new Response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function killServiceWorker(Request $r): Response
    {
        $js = "self.addEventListener('install', () => self.skipWaiting());\n"
            . "self.addEventListener('activate', (e) => e.waitUntil(self.registration.unregister()"
            . ".then(() => self.clients.matchAll({ type: 'window' })).then((cs) => cs.forEach((c) => c.navigate(c.url)))));\n";
        return new Response($js, 200, ['Content-Type' => 'application/javascript', 'Cache-Control' => 'no-store']);
    }

    public function ping(Request $r): array
    {
        return $this->ok(['time' => now()]);
    }

    /** Manager approval: a manager types their own credentials on the cashier's screen. */
    public function approve(Request $r): array
    {
        $perm = $r->str('permission');
        $allowed = ['pos.discount_override', 'pos.credit_sale', 'pos.oversell', 'sale.void', 'return.refund', 'return.override_policy', 'pos.save_no_print'];
        if (!in_array($perm, $allowed, true)) {
            throw new ValidationException('This action cannot be approved this way');
        }
        $a = Auth::grantApproval($r->str('username'), (string)$r->input('password', ''), $perm, $r->ip());
        return $this->ok(['token' => $a['token'], 'approver' => $a['approver'], 'max_discount_bp' => $a['max_discount_bp']]);
    }

    public function passwordForm(Request $r): Response
    {
        return $this->view('pages/password', 'Change password');
    }

    public function changePassword(Request $r): Response
    {
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [Auth::id()]);
        if (!password_verify((string)$r->input('current', ''), $u['password_hash'])) {
            throw new ValidationException('Current password is incorrect');
        }
        $new = (string)$r->input('password', '');
        if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
            throw new ValidationException('New password: at least 8 characters with letters and numbers');
        }
        if ($new !== (string)$r->input('password_confirm', '')) {
            throw new ValidationException('Passwords do not match');
        }
        $this->db->update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'password_changed_at' => now(), 'updated_at' => now()], 'id = :id', ['id' => $u['id']]);
        Audit::log('auth.password_changed', 'user', $u['id']);
        flash('success', 'Password changed.');
        return Response::redirect('/');
    }
}
