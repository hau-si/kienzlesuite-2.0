<?php
declare(strict_types=1);
namespace KienzleHomepage;

/** SQLite-Datenbank des Moduls (Vertretungspraxen, Mitteilungen, Einstellungen); Schema über user_version. Keine Patientendaten. */
final class Speicher {
    public static string $data = '/var/lib/kienzle/homepage';
    private static ?\PDO $pdo = null;
    private static string $pfad = '';
    public const VERSION = 1;

    public static function pdo(): \PDO {
        $datei = self::$data . '/homepage.db';
        if (self::$pdo && self::$pfad === $datei) return self::$pdo;
        if (!is_dir(self::$data) && !mkdir(self::$data, 0700, true) && !is_dir(self::$data)) throw new \RuntimeException('Datenordner nicht anlegbar.');
        $pdo = new \PDO('sqlite:' . $datei, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        @chmod($datei, 0600);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
        self::migrieren($pdo);
        self::$pdo = $pdo;
        self::$pfad = $datei;
        return $pdo;
    }

    /** Für Tests: Verbindung schließen, damit ein anderer Datenordner gilt. */
    public static function zuruecksetzen(): void { self::$pdo = null; self::$pfad = ''; }

    public static function wert(string $name, string $vorgabe = ''): string {
        $s = self::pdo()->prepare('SELECT wert FROM einstellungen WHERE name = ?');
        $s->execute([$name]);
        $w = $s->fetchColumn();
        return is_string($w) ? $w : $vorgabe;
    }

    public static function setzen(string $name, string $wert): void {
        self::pdo()->prepare('INSERT INTO einstellungen (name, wert) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET wert = excluded.wert')->execute([$name, $wert]);
    }

    private static function migrieren(\PDO $pdo): void {
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v >= self::VERSION) return;
        $pdo->beginTransaction();
        if ($v < 1) {
            $pdo->exec('CREATE TABLE praxen (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                strasse TEXT NOT NULL DEFAULT \'\',
                ort TEXT NOT NULL DEFAULT \'\',
                telefon TEXT NOT NULL DEFAULT \'\',
                zusatz TEXT NOT NULL DEFAULT \'\',
                erstellt INTEGER NOT NULL,
                geaendert INTEGER NOT NULL
            )');
            $pdo->exec('CREATE TABLE mitteilungen (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                art TEXT NOT NULL,
                titel TEXT NOT NULL DEFAULT \'\',
                text TEXT NOT NULL DEFAULT \'\',
                praxen TEXT NOT NULL DEFAULT \'[]\',
                tage TEXT NOT NULL DEFAULT \'[]\',
                von TEXT NOT NULL DEFAULT \'\',
                bis TEXT NOT NULL DEFAULT \'\',
                orte TEXT NOT NULL DEFAULT \'[]\',
                aktiv INTEGER NOT NULL DEFAULT 0,
                erstellt INTEGER NOT NULL,
                geaendert INTEGER NOT NULL,
                geaendert_von TEXT NOT NULL DEFAULT \'\'
            )');
            $pdo->exec('CREATE TABLE einstellungen (name TEXT PRIMARY KEY, wert TEXT NOT NULL)');
        }
        $pdo->exec('PRAGMA user_version = ' . self::VERSION);
        $pdo->commit();
    }
}
