<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** SQLite-Datenbank der Basis. Aufbau nach kienzle-suite-beta (src/Database.php). */
final class Database {
    public const VERSION = 2;
    public readonly \PDO $pdo;

    public function __construct(public readonly string $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Datenverzeichnis nicht verfügbar.');
        }
        $this->pdo = new \PDO('sqlite:' . $directory . '/core.sqlite', null, null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL;');
    }

    public function migrate(): void {
        $version = (int)$this->pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version > self::VERSION) throw new \RuntimeException('Datenbank ist neuer als die Basis.');
        if ($version === self::VERSION) return;
        $this->transaction(function (): void {
            $this->pdo->exec((string)file_get_contents(__DIR__ . '/schema.sql'));
            $this->pdo->exec('PRAGMA user_version=' . self::VERSION);
        });
    }

    public function query(string $sql, array $params = []): \PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array { return $this->query($sql, $params)->fetch() ?: null; }

    public function transaction(callable $action): mixed {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $result = $action(); $this->pdo->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }

    public function setting(string $key): ?string {
        $row = $this->one('SELECT value FROM settings WHERE key=?', [$key]);
        return $row ? $row['value'] : null;
    }

    public function store(string $key, string $value): void {
        $this->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',
            [$key, $value, time()]);
    }
}
