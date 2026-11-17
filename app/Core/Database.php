<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Thin PDO wrapper for the local SQLite database.
 * All SQL is parameterised; identifiers passed to insert()/update() are validated.
 */
final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;
    private int $depth = 0;

    public function __construct(public readonly string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = FULL');   // durability for saved bills
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    public static function instance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database(self::defaultPath());
        }
        return self::$instance;
    }

    public static function setInstance(?Database $db): void
    {
        self::$instance = $db;
    }

    public static function defaultPath(): string
    {
        $p = Env::get('DB_PATH', 'storage/sqlite/textile_pos.sqlite');
        if (!preg_match('~^([a-zA-Z]:)?[\\\\/]~', $p)) {
            $p = APP_ROOT . '/' . $p;
        }
        return $p;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = is_int($v) ? PDO::PARAM_INT : (is_bool($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            $st->bindValue($key, is_bool($v) ? (int)$v : $v, $type);
        }
        $st->execute();
        return $st;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function insert(string $table, array $data): void
    {
        self::assertIdent($table);
        $cols = array_keys($data);
        array_walk($cols, [self::class, 'assertIdent']);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', $cols),
            implode(',', array_map(fn($c) => ':' . $c, $cols)));
        $this->run($sql, $data);
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        self::assertIdent($table);
        $sets = [];
        $params = [];
        foreach ($data as $c => $v) {
            self::assertIdent($c);
            $sets[] = "$c = :set_$c";
            $params["set_$c"] = $v;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where);
        return $this->run($sql, array_merge($params, $whereParams))->rowCount();
    }

    /**
     * Run $fn inside a transaction. Nested calls join the outer transaction.
     * Uses BEGIN IMMEDIATE so that write locks are acquired up-front (no mid-transaction SQLITE_BUSY).
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $fn($this);
            } finally {
                $this->depth--;
            }
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->depth = 1;
        try {
            $result = $fn($this);
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    private static function assertIdent(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: $name");
        }
    }
}
