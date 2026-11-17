<?php
declare(strict_types=1);

namespace App\Core;

/** Applies ordered SQL migrations from database/migrations/sqlite in a transaction each. */
final class Migrator
{
    public function __construct(private Database $db, private ?string $dir = null)
    {
        $this->dir ??= APP_ROOT . '/database/migrations/sqlite';
    }

    public function pending(): array
    {
        $this->ensureTable();
        $applied = array_column($this->db->all('SELECT version FROM schema_migrations'), 'version');
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $f) {
            $v = basename($f, '.sql');
            if (!in_array($v, $applied, true)) {
                $out[$v] = $f;
            }
        }
        return $out;
    }

    /** @return string[] applied versions */
    public function migrate(): array
    {
        $done = [];
        foreach ($this->pending() as $version => $file) {
            $sql = file_get_contents($file);
            $this->db->transaction(function (Database $db) use ($sql, $version) {
                $db->pdo()->exec($sql);
                $db->insert('schema_migrations', ['version' => $version, 'applied_at' => now()]);
            });
            $done[] = $version;
        }
        if ($done) {
            (new Seeder($this->db))->seedReferenceData();
        }
        return $done;
    }

    public function currentVersion(): ?string
    {
        $this->ensureTable();
        return $this->db->value('SELECT MAX(version) FROM schema_migrations');
    }

    private function ensureTable(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
    }
}
