<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Settings;
use App\Core\ValidationException;
use PDO;

/**
 * SQLite backup and restore.
 *  - Backups use "VACUUM INTO", which produces a consistent snapshot including WAL content, while the app keeps running.
 *  - Each backup is integrity-checked and gets a .json manifest (sha256, schema version, row counts).
 *  - Restore is two-phase: stage (validate + safety backup of the live DB) → apply at the start of the next request,
 *    before any connection is opened; stale -wal/-shm files are removed so they cannot corrupt the restored file.
 */
final class BackupService
{
    public function __construct(private Database $db, private ?string $dir = null)
    {
        $this->dir ??= APP_ROOT . '/storage/backups';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0770, true);
        }
    }

    public function create(string $tag = 'manual'): array
    {
        $tag = preg_replace('/[^a-z0-9-]/', '', strtolower($tag)) ?: 'manual';
        $file = $this->dir . '/textile_pos_' . date('Ymd_His') . '_' . $tag . '.sqlite';
        if (is_file($file)) {
            $file = substr($file, 0, -7) . '_' . bin2hex(random_bytes(2)) . '.sqlite';
        }
        $this->db->run('VACUUM INTO ?', [$file]);
        $info = $this->validate($file);
        file_put_contents($file . '.json', json_encode($info + ['created_at' => now(), 'tag' => $tag], JSON_PRETTY_PRINT));
        Audit::log('backup.created', 'backup', basename($file), ['size' => filesize($file)]);
        $this->rotate();
        return $info + ['file' => basename($file)];
    }

    /** Validate a candidate database file without modifying it. */
    public function validate(string $file): array
    {
        if (!is_file($file) || filesize($file) < 1024) {
            throw new ValidationException('Backup file is missing or empty');
        }
        $head = file_get_contents($file, false, null, 0, 16);
        if ($head !== "SQLite format 3\0") {
            throw new ValidationException('Not a SQLite database file');
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $ic = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($ic !== 'ok') {
            throw new ValidationException('Integrity check failed: ' . $ic);
        }
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['schema_migrations', 'users', 'sales', 'sale_items', 'stock_balances', 'sync_outbox'] as $t) {
            if (!in_array($t, $tables, true)) {
                throw new ValidationException("Not a Textile POS database (table $t missing)");
            }
        }
        $version = (string)$pdo->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn();
        $current = (new Migrator($this->db))->currentVersion();
        if ($current && strcmp($version, $current) > 0) {
            throw new ValidationException("Backup schema ($version) is newer than this application ($current). Update the app first.");
        }
        $counts = [];
        foreach (['sales', 'products', 'customers', 'sync_outbox'] as $t) {
            $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        }
        $counts['pending_sync'] = (int)$pdo->query("SELECT COUNT(*) FROM sync_outbox WHERE status IN ('pending','failed')")->fetchColumn();
        $last = $pdo->query('SELECT MAX(created_at) FROM sales')->fetchColumn();
        $pdo = null;
        return ['sha256' => hash_file('sha256', $file), 'size' => filesize($file), 'schema' => $version, 'counts' => $counts, 'last_sale_at' => $last];
    }

    public function list(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.sqlite') ?: [] as $f) {
            $meta = is_file("$f.json") ? (json_decode((string)file_get_contents("$f.json"), true) ?: []) : [];
            $out[] = ['file' => basename($f), 'size' => filesize($f), 'mtime' => date('Y-m-d H:i:s', filemtime($f)), 'meta' => $meta];
        }
        usort($out, fn($a, $b) => strcmp($b['mtime'] . $b['file'], $a['mtime'] . $a['file']));
        return $out;
    }

    public function path(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9._-]+\.sqlite$/', $name)) {
            throw new ValidationException('Invalid backup name');
        }
        $p = $this->dir . '/' . $name;
        if (!is_file($p)) {
            throw new ValidationException('Backup not found');
        }
        return $p;
    }

    public function rotate(): void
    {
        $keep = max(3, (int)Settings::get('backup_keep', 20));
        $regular = array_values(array_filter($this->list(), fn($b) => !str_contains($b['file'], '_safety') && !str_contains($b['file'], 'replaced_')));
        foreach (array_slice($regular, $keep) as $b) {
            @unlink($this->dir . '/' . $b['file']);
            @unlink($this->dir . '/' . $b['file'] . '.json');
        }
    }

    public function storeUpload(array $file): string
    {
        if ($file['size'] > 1_000_000_000) {
            throw new ValidationException('File too large');
        }
        $name = 'textile_pos_' . date('Ymd_His') . '_uploaded.sqlite';
        $dest = $this->dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest) && !rename($file['tmp_name'], $dest)) {
            throw new ValidationException('Could not store the uploaded file');
        }
        try {
            $info = $this->validate($dest);
        } catch (\Throwable $e) {
            @unlink($dest);
            throw $e;
        }
        file_put_contents($dest . '.json', json_encode($info + ['created_at' => now(), 'tag' => 'uploaded'], JSON_PRETTY_PRINT));
        return $name;
    }

    /** Phase 1: validate, take a safety backup of the live DB, copy the candidate to the pending slot. */
    public function stageRestore(string $name): array
    {
        $src = $this->path($name);
        $info = $this->validate($src);
        $safety = $this->create('safety');
        $pending = self::pendingPath($this->db->path);
        if (!copy($src, $pending)) {
            throw new ValidationException('Could not stage the restore file');
        }
        if (hash_file('sha256', $pending) !== $info['sha256']) {
            @unlink($pending);
            throw new ValidationException('Copy verification failed');
        }
        file_put_contents($pending . '.json', json_encode(['source' => $name, 'sha256' => $info['sha256'], 'safety_backup' => $safety['file'], 'staged_at' => now()]));
        Audit::log('backup.restore_staged', 'backup', $name, ['safety' => $safety['file']]);
        return ['source' => $name, 'safety_backup' => $safety['file'], 'info' => $info];
    }

    public static function pendingPath(string $dbPath): string
    {
        return dirname($dbPath) . '/restore_pending.sqlite';
    }

    /**
     * Phase 2: called from bootstrap BEFORE any connection to the live DB is opened.
     * Returns a status message or null when nothing was pending.
     */
    public static function applyPendingRestore(string $dbPath): ?string
    {
        $pending = self::pendingPath($dbPath);
        if (!is_file($pending)) {
            return null;
        }
        $meta = json_decode((string)@file_get_contents($pending . '.json'), true) ?: [];
        if (!empty($meta['sha256']) && hash_file('sha256', $pending) !== $meta['sha256']) {
            @rename($pending, $pending . '.corrupt');
            return 'Restore aborted: staged file checksum mismatch. Live database unchanged.';
        }
        $backupDir = dirname($dbPath, 2) . '/backups';   // storage/sqlite/x.sqlite → storage/backups
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0770, true);
        }
        $replaced = $backupDir . '/replaced_' . date('Ymd_His') . '.sqlite';
        if (is_file($dbPath) && !@rename($dbPath, $replaced)) {
            return 'Restore is waiting: the database is in use. Stop/restart the web server and open the app again.';
        }
        foreach (["$dbPath-wal", "$dbPath-shm"] as $f) {
            if (is_file($f)) {
                @rename($f, $replaced . substr($f, strlen($dbPath)));
            }
        }
        if (!@rename($pending, $dbPath)) {
            @rename($replaced, $dbPath);
            return 'Restore failed while moving files; the previous database was put back.';
        }
        @unlink($pending . '.json');
        $pdo = new PDO('sqlite:' . $dbPath);
        $ok = $pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok';
        $pdo = null;
        if (!$ok) {
            @rename($dbPath, $dbPath . '.failed');
            @rename($replaced, $dbPath);
            return 'Restored file failed verification; previous database put back.';
        }
        file_put_contents(APP_ROOT . '/storage/logs/restore.log', now() . ' restored ' . ($meta['source'] ?? '?') . ' (safety: ' . ($meta['safety_backup'] ?? '?') . ")\n", FILE_APPEND);
        return 'Database restored from ' . ($meta['source'] ?? 'backup') . '. Safety backup: ' . ($meta['safety_backup'] ?? 'n/a') . '. Please sign in again and verify recent bills.';
    }
}
