<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** SQLite-Datenbank der Basis. Aufbau nach kienzle-suite-beta (src/Database.php). */
final class Database {
    public const VERSION = 4;
    public readonly \PDO $pdo;

    public function __construct(public readonly string $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Datenverzeichnis nicht verfügbar.');
        }
        $file = $directory . '/core.sqlite';
        foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
            if (file_exists($f) && !is_writable($f)) {
                throw new \RuntimeException('Die Datenbankdatei ' . basename($f) . ' ist für den Dienst nicht beschreibbar (Besitzer oder Rechte falsch). Reparatur: sudo chown -R ks-core:ks-core ' . $directory . ' && sudo chmod 700 ' . $directory);
            }
        }
        $this->pdo = new \PDO('sqlite:' . $directory . '/core.sqlite', null, null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL;');
    }

    public function migrate(): void {
        $version = (int)$this->pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version > self::VERSION) throw new \RuntimeException('Datenbank ist neuer als die Basis.');
        if ($version === self::VERSION) return;
        $this->transaction(function () use ($version): void {
            $this->pdo->exec((string)file_get_contents(__DIR__ . '/schema.sql'));
            // Bis Version 2 gab es nur kopierte Rechte je Modul (user_modules): sie gelten jetzt als Einzelrechte ohne Rolle, nichts ändert sich für die Benutzer.
            if ($version > 0 && $version < 3) {
                foreach ($this->pdo->query('SELECT user_id,module,admin,rights FROM user_modules')->fetchAll() as $r) {
                    $ins = $this->pdo->prepare('INSERT OR IGNORE INTO user_overrides(user_id,perm,allow) VALUES(?,?,1)');
                    if ((int)$r['admin'] === 1) $ins->execute([$r['user_id'], $r['module'] . ':@admin']);
                    $rights = json_decode((string)$r['rights'], true);
                    foreach (is_array($rights) ? $rights : [] as $right) if (is_string($right)) $ins->execute([$r['user_id'], $r['module'] . ':' . $right]);
                }
            }
            // Version 4: Das Modul „sumup“ heißt jetzt „kasse“. Rechte, Gruppen und Erstadmin werden kopiert (die alten Einträge bleiben, bis das alte Modul ersetzt ist).
            if ($version > 0 && $version < 4) $this->moduleKopieren('sumup', 'kasse');
            $this->pdo->exec('PRAGMA user_version=' . self::VERSION);
        });
    }

    /** Rechte und Einstellungen eines umbenannten Moduls unter der neuen ID anlegen (nur Fehlendes; mehrfach ausführbar). */
    private function moduleKopieren(string $alt, string $neu): void {
        $this->pdo->prepare('INSERT OR IGNORE INTO user_overrides(user_id,perm,allow) SELECT user_id, ? || substr(perm, ?), allow FROM user_overrides WHERE perm LIKE ?')
            ->execute([$neu . ':', strlen($alt) + 1 + 1, $alt . ':%']);
        $this->pdo->prepare('UPDATE oidc_clients SET module=? WHERE module=?')->execute([$neu, $alt]);
        $this->pdo->prepare('INSERT OR IGNORE INTO settings(key,value,updated_at) SELECT ?, value, updated_at FROM settings WHERE key=?')->execute(['erstadmin:' . $neu, 'erstadmin:' . $alt]);
        $row = $this->pdo->query("SELECT value FROM settings WHERE key='benutzer.gruppen'")->fetch();
        $groups = $row ? json_decode((string)$row['value'], true) : null;
        if (!is_array($groups)) return;
        foreach ($groups as $name => $g) {
            if (!is_array($g)) continue;
            $admin = array_values(array_filter((array)($g['admin'] ?? []), 'is_string'));
            $rechte = array_values(array_filter((array)($g['rechte'] ?? []), 'is_string'));
            if (in_array($alt, $admin, true) && !in_array($neu, $admin, true)) $admin[] = $neu;
            foreach ($rechte as $r) if (str_starts_with($r, $alt . ':') && !in_array($neu . substr($r, strlen($alt)), $rechte, true)) $rechte[] = $neu . substr($r, strlen($alt));
            $groups[$name] = ['admin' => $admin, 'rechte' => $rechte] + $g;
        }
        $this->pdo->prepare('UPDATE settings SET value=? WHERE key=?')->execute([json_encode($groups, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'benutzer.gruppen']);
    }

    public function query(string $sql, array $params = []): \PDOStatement {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function one(string $sql, array $params = []): ?array { return $this->query($sql, $params)->fetch() ?: null; }

    private int $depth = 0;

    /** Verschachtelte Aufrufe laufen in der äußeren Transaktion mit (ein Fehler macht dann alles rückgängig). */
    public function transaction(callable $action): mixed {
        if ($this->depth > 0) { $this->depth++; try { return $action(); } finally { $this->depth--; } }
        $this->depth = 1;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $result = $action(); $this->pdo->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
        finally { $this->depth = 0; }
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
