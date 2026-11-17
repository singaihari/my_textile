<?php
declare(strict_types=1);

namespace Server;

use PDO;
use Throwable;

/**
 * PDO wrapper for the server database.
 * Production driver: MySQL/MariaDB (DB_DRIVER=mysql).
 * DB_DRIVER=sqlite exists ONLY for automated tests and the local mock server; it loads the same
 * MySQL migration after stripping MySQL-only table options.
 */
final class Db
{
    private static ?Db $instance = null;
    public readonly string $driver;
    private PDO $pdo;

    public function __construct(?array $cfg = null)
    {
        $cfg ??= [
            'driver' => Config::get('DB_DRIVER', 'mysql'),
            'host' => Config::get('DB_HOST', '127.0.0.1'),
            'port' => Config::get('DB_PORT', '3306'),
            'name' => Config::get('DB_NAME', 'textile_pos'),
            'user' => Config::get('DB_USER', ''),
            'pass' => Config::get('DB_PASS', ''),
            'path' => Config::get('DB_SQLITE_PATH', SERVER_ROOT . '/storage/mock-server.sqlite'),
        ];
        $this->driver = $cfg['driver'];
        $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        if ($this->driver === 'sqlite') {
            $this->pdo = new PDO('sqlite:' . $cfg['path'], null, null, $opts);
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        } elseif ($this->driver === 'mysql') {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['name']);
            $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $opts);
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO', time_zone = '+05:30'");
        } else {
            throw new \RuntimeException('Unsupported DB_DRIVER');
        }
    }

    public static function instance(): Db
    {
        return self::$instance ??= new Db();
    }

    public static function setInstance(?Db $db): void
    {
        self::$instance = $db;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function one(string $sql, array $p = []): ?array
    {
        $r = $this->run($sql, $p)->fetch();
        return $r === false ? null : $r;
    }

    public function all(string $sql, array $p = []): array
    {
        return $this->run($sql, $p)->fetchAll();
    }

    public function value(string $sql, array $p = []): mixed
    {
        $v = $this->run($sql, $p)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Insert a row, filtering keys to an allow-list of columns. */
    public function insert(string $table, array $row, array $columns): void
    {
        $data = array_intersect_key($row, array_flip($columns));
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', $cols), implode(',', array_fill(0, count($cols), '?')));
        $this->run($sql, array_values($data));
    }

    /** Insert or update by primary key column(s). */
    public function upsert(string $table, array $row, array $columns, array $pk): void
    {
        $data = array_intersect_key($row, array_flip($columns));
        $cols = array_keys($data);
        $place = implode(',', array_fill(0, count($cols), '?'));
        $updates = array_diff($cols, $pk);
        if ($this->driver === 'mysql') {
            $set = implode(', ', array_map(fn($c) => "$c = VALUES($c)", $updates));
            $sql = sprintf('INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s', $table, implode(',', $cols), $place, $set);
        } else {
            $set = implode(', ', array_map(fn($c) => "$c = excluded.$c", $updates));
            $sql = sprintf('INSERT INTO %s (%s) VALUES (%s) ON CONFLICT(%s) DO UPDATE SET %s', $table, implode(',', $cols), $place, implode(',', $pk), $set);
        }
        $this->run($sql, array_values($data));
    }

    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function migrate(string $dir): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)');
        $done = array_column($this->all('SELECT version FROM schema_migrations'), 'version');
        $files = glob($dir . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $f) {
            $v = basename($f, '.sql');
            if (in_array($v, $done, true)) {
                continue;
            }
            $sql = file_get_contents($f);
            if ($this->driver === 'sqlite') {
                $sql = preg_replace('/\)\s*ENGINE=InnoDB[^;]*;/i', ');', $sql);
                $sql = str_replace('BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            }
            // MySQL DDL auto-commits, so statements run one by one.
            foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $stmt) {
                $clean = trim(preg_replace('/^\s*--.*$/m', '', $stmt));
                if ($clean !== '') {
                    $this->pdo->exec($clean);
                }
            }
            $this->run('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$v, date('Y-m-d H:i:s')]);
            $applied[] = $v;
        }
        return $applied;
    }

    public static function isDuplicateKey(\PDOException $e): bool
    {
        return in_array((string)$e->getCode(), ['23000', '23505'], true) || str_contains($e->getMessage(), 'UNIQUE constraint');
    }
}
