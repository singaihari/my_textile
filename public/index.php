<?php
declare(strict_types=1);

/**
 * Front controller for the local (offline) shop application.
 * The web server's document root must be this "public" folder; app/, storage/ and database/ stay private.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\BackupService;

$req = Request::fromGlobals();
Auth::startSession($req);

// Phase 2 of a staged restore runs before the database is opened.
if ($msg = BackupService::applyPendingRestore(Database::defaultPath())) {
    $_SESSION = [];
    flash(str_starts_with($msg, 'Database restored') ? 'success' : 'error', $msg);
}

try {
    $fresh = !is_file(Database::defaultPath());
    $db = Database::instance();
    $migrator = new Migrator($db);
    if ($fresh) {
        $migrator->migrate();
    } elseif ($pending = $migrator->pending()) {
        (new Response(View::render('pages/maintenance', ['title' => 'Update required', 'pending' => array_keys($pending)], 'layouts/bare'), 503))->send();
        exit;
    }

    // No setup page: the first admin is created on the shop PC with "php scripts\create-admin.php".
    if (!App\Core\Context::isSetUp() && !in_array($req->path, ['/login', '/favicon.ico', '/sw.js', '/service-worker.js', '/serviceworker.js', '/serviceWorker.js', '/sw-offline.js'], true)) {
        Response::redirect('/login')->send();
        exit;
    }

    $router = new Router();
    require APP_ROOT . '/app/routes.php';
    $response = $router->dispatch($req);
} catch (ValidationException $e) {
    if ($req->wantsJson()) {
        $response = Response::json(['ok' => false, 'error' => $e->getMessage(), 'errors' => $e->errors], 422);
    } else {
        flash('error', $e->getMessage());
        $_SESSION['_old'] = array_diff_key($req->all(), array_flip(['password', 'password_confirm', '_csrf']));
        $_SESSION['_errors'] = $e->errors;
        $back = $_SERVER['HTTP_REFERER'] ?? url('/');
        $response = new Response('', 302, ['Location' => str_starts_with($back, 'http') && !str_contains($back, (string)($_SERVER['HTTP_HOST'] ?? '')) ? url('/') : $back]);
    }
} catch (InvalidArgumentException $e) {
    // input-format errors from Money/Qty parsing (e.g. "1.5 pieces") are user errors, not crashes
    if ($req->wantsJson()) {
        $response = Response::json(['ok' => false, 'error' => $e->getMessage(), 'errors' => []], 422);
    } else {
        flash('error', $e->getMessage());
        $response = new Response('', 302, ['Location' => $_SERVER['HTTP_REFERER'] ?? url('/')]);
    }
} catch (HttpException $e) {
    if ($req->wantsJson()) {
        $response = Response::json(['ok' => false, 'error' => $e->getMessage(), 'code' => $e->status], $e->status);
    } elseif ($e->status === 401) {
        $response = Response::redirect('/login', ['next' => $req->path]);
    } elseif ($e->status === 423) {
        $response = Response::redirect('/lock', ['next' => $req->path]);
    } elseif ($e->status === 419) {
        flash('error', $e->getMessage());
        $response = Response::redirect($req->path === '/login' ? '/login' : ($_SERVER['HTTP_REFERER'] ?? '/'));
    } else {
        $response = new Response(View::render('pages/error', ['title' => 'Error ' . $e->status, 'status' => $e->status, 'message' => $e->getMessage()],
            Auth::user() ? 'layouts/app' : 'layouts/bare'), $e->status);
    }
} catch (Throwable $e) {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log("[$ref] " . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    $debug = App\Core\Env::bool('APP_DEBUG');
    $message = 'Something went wrong (ref ' . $ref . '). Nothing was half-saved: every bill is saved in a single transaction.' . ($debug ? ' — ' . $e->getMessage() : '');
    if ($req->wantsJson()) {
        $response = Response::json(['ok' => false, 'error' => $message], 500);
    } else {
        try {
            $response = new Response(View::render('pages/error', ['title' => 'Error', 'status' => 500, 'message' => $message], 'layouts/bare'), 500);
        } catch (Throwable) {
            $response = Response::html('<h1>Error</h1><p>' . e($message) . '</p>', 500);
        }
    }
}

$response->send();
