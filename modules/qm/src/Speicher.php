<?php
declare(strict_types=1);
namespace KienzleQm;

/** SQLite-Datenbank des Moduls (Ordner, Dokumente, Versionen, Lesebestätigungen, Verlauf); Schema über user_version. */
final class Speicher {
    public static string $data = '/var/lib/kienzle/qm';
    private static ?\PDO $pdo = null;
    private static string $pfad = '';
    public const VERSION = 1;

    /** Ordner beim ersten Start (eigene Gliederung, frei änderbar). */
    public const ORDNER = ['1 Praxisführung und Organisation', '2 Hygiene und Medizinprodukte', '3 Notfallmanagement', '4 Arbeitsschutz',
        '5 Datenschutz und Informationssicherheit', '6 Abläufe (SOP)', '7 Formulare und Checklisten', '8 Mitarbeitende und Einarbeitung'];

    public static function pdo(): \PDO {
        $datei = self::$data . '/qm.db';
        if (self::$pdo && self::$pfad === $datei) return self::$pdo;
        self::ordner('');
        $pdo = new \PDO('sqlite:' . $datei, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        @chmod($datei, 0600);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000');
        self::migrieren($pdo);
        self::$pdo = $pdo;
        self::$pfad = $datei;
        return $pdo;
    }

    /** Unterordner des Datenordners (0700), z. B. „bilder“ oder „tmp“. */
    public static function ordner(string $name): string {
        $d = self::$data . ($name === '' ? '' : '/' . $name);
        if (!is_dir($d) && !mkdir($d, 0700, true) && !is_dir($d)) throw new \RuntimeException('Datenordner nicht anlegbar.');
        return $d;
    }

    /** Für Tests: Verbindung schließen, damit ein anderer Datenordner gilt. */
    public static function zuruecksetzen(): void { self::$pdo = null; self::$pfad = ''; }

    private static function migrieren(\PDO $pdo): void {
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v >= self::VERSION) return;
        $pdo->beginTransaction();
        if ($v < 1) {
            $pdo->exec('CREATE TABLE ordner (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                eltern INTEGER REFERENCES ordner(id),
                name TEXT NOT NULL,
                pos INTEGER NOT NULL DEFAULT 0
            )');
            $pdo->exec('CREATE TABLE dokumente (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ordner INTEGER REFERENCES ordner(id),
                titel TEXT NOT NULL,
                tags TEXT NOT NULL DEFAULT \'\',
                pruef_monate INTEGER NOT NULL DEFAULT 12,
                naechste_pruefung TEXT NOT NULL DEFAULT \'\',
                gueltig INTEGER,
                entwurf INTEGER,
                quelle TEXT NOT NULL DEFAULT \'\',
                archiviert INTEGER NOT NULL DEFAULT 0,
                erstellt INTEGER NOT NULL,
                geaendert INTEGER NOT NULL
            )');
            $pdo->exec('CREATE TABLE versionen (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dokument INTEGER NOT NULL REFERENCES dokumente(id) ON DELETE CASCADE,
                nummer INTEGER NOT NULL,
                inhalt TEXT NOT NULL,
                text TEXT NOT NULL,
                status TEXT NOT NULL,
                autor TEXT NOT NULL,
                autor_name TEXT NOT NULL,
                erstellt INTEGER NOT NULL,
                geaendert INTEGER NOT NULL,
                notiz TEXT NOT NULL DEFAULT \'\',
                eingereicht INTEGER NOT NULL DEFAULT 0,
                vidiert_von TEXT NOT NULL DEFAULT \'\',
                vidiert_name TEXT NOT NULL DEFAULT \'\',
                vidiert_kuerzel TEXT NOT NULL DEFAULT \'\',
                vidiert_am INTEGER NOT NULL DEFAULT 0,
                UNIQUE (dokument, nummer)
            )');
            $pdo->exec('CREATE TABLE lesepflicht (
                version INTEGER NOT NULL REFERENCES versionen(id) ON DELETE CASCADE,
                login TEXT NOT NULL,
                name TEXT NOT NULL,
                frist TEXT NOT NULL DEFAULT \'\',
                von TEXT NOT NULL,
                am INTEGER NOT NULL,
                gelesen INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (version, login)
            )');
            $pdo->exec('CREATE TABLE verlauf (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                dokument INTEGER NOT NULL REFERENCES dokumente(id) ON DELETE CASCADE,
                zeit INTEGER NOT NULL,
                wer TEXT NOT NULL,
                was TEXT NOT NULL
            )');
            $pdo->exec('CREATE INDEX lesepflicht_login ON lesepflicht (login, gelesen)');
            $pdo->exec('CREATE INDEX versionen_status ON versionen (status)');
            $st = $pdo->prepare('INSERT INTO ordner (eltern, name, pos) VALUES (NULL, ?, ?)');
            foreach (self::ORDNER as $i => $n) $st->execute([$n, $i]);
        }
        $pdo->exec('PRAGMA user_version = ' . self::VERSION);
        $pdo->commit();
    }
}
