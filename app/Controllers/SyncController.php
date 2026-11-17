<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Context;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Core\View;
use App\Services\OutboxService;
use App\Services\Sync\SyncService;

/**
 * Sync Center. Uploads/downloads ONLY happen in run(), which requires an explicit POST with
 * confirm=true from an authorised user clicking "Sync Now". Viewing this page performs no network calls.
 */
final class SyncController extends Controller
{
    public function index(Request $r): Response
    {
        $status = in_array($r->query('status'), ['pending', 'failed', 'acked', 'conflict'], true) ? $r->query('status') : null;
        $page = View::paginate($this->db, 'SELECT id, seq, entity_type, entity_id, op, status, attempts, last_error, created_at, acked_at, server_status, depends_on
            FROM sync_outbox' . ($status ? ' WHERE status = :s' : '') . ' ORDER BY seq DESC', $status ? ['s' => $status] : [], $this->page($r), 30);
        return $this->view('pages/sync', 'Sync Center', [
            'counts' => (new OutboxService($this->db))->counts(),
            'page' => $page, 'status' => $status,
            'runs' => $this->db->all('SELECT r.*, u.full_name FROM sync_runs r LEFT JOIN users u ON u.id = r.user_id ORDER BY started_at DESC LIMIT 15'),
            'conflicts' => $this->db->all("SELECT * FROM sync_conflicts WHERE status = 'open' ORDER BY created_at DESC LIMIT 50"),
            'endpoint' => Settings::get('sync_endpoint'), 'tokenMasked' => mask_secret((string)Settings::get('sync_device_token')),
            'store' => Context::store(), 'device' => Context::device(), 'lastSuccess' => Settings::get('sync_last_success_at'),
            'config' => ['csrf' => csrf_token(), 'base' => base_path_url()],
        ]);
    }

    public function run(Request $r): array
    {
        $in = $r->json();
        if (($in['confirm'] ?? false) !== true) {
            throw new ValidationException('Sync must be confirmed by the user');
        }
        session_write_close();   // keep other tabs (e.g. POS) responsive during the upload
        return $this->ok(['result' => SyncService::fromSettings($this->db)->run()]);
    }

    public function test(Request $r): array
    {
        $res = SyncService::fromSettings($this->db)->testConnection();
        return $this->ok(['message' => 'Connected as device ' . ($res['device']['code'] ?? '?') . '. Server time ' . ($res['server_time'] ?? '') . '. No data was sent.']);
    }

    public function config(Request $r): Response
    {
        $endpoint = rtrim($r->str('endpoint'), '/');
        if ($endpoint !== '') {
            $parts = parse_url($endpoint);
            $local = in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
            if (!$parts || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || (($parts['scheme'] ?? '') === 'http' && !$local)) {
                throw new ValidationException('Server URL must start with https:// (http:// only for localhost testing)');
            }
        }
        Settings::set('sync_endpoint', $endpoint, Auth::id());
        $token = $r->str('token');
        if ($token !== '') {
            if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $token)) {
                throw new ValidationException('Device token format is invalid');
            }
            Settings::set('sync_device_token', $token, Auth::id());
        }
        if ($r->str('batch_size') !== '') {
            Settings::set('sync_batch_size', (string)max(1, min(200, $r->int('batch_size'))), Auth::id());
        }
        Audit::log('sync.config_changed', 'settings', 'sync', ['endpoint' => $endpoint, 'token_changed' => $token !== '']);
        flash('success', 'Sync settings saved. Nothing has been uploaded — use Sync Now when ready.');
        return Response::redirect('/sync');
    }

    public function resolve(Request $r): Response
    {
        $action = in_array($r->str('action'), ['apply_server', 'keep_local', 'ignore', 'resolved'], true) ? $r->str('action') : 'resolved';
        SyncService::resolveConflict($this->db, $r->params['id'], $action, $r->str('note'));
        flash('success', 'Conflict marked ' . ($action === 'ignore' ? 'ignored' : 'resolved') . '.');
        return Response::redirect('/sync');
    }
}
