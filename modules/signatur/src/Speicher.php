<?php
declare(strict_types=1);
namespace KienzleSignatur;

/** SQLite-Datenbank des Moduls (Vorlagen und Vorgänge); Schema über user_version. */
final class Speicher {
    public static string $data = '/var/lib/kienzle/signatur';
    private static ?\PDO $pdo = null;
    private static string $pfad = '';
    public const VERSION = 3;

    public static function pdo(): \PDO {
        $datei = self::$data . '/signatur.db';
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

    private static function migrieren(\PDO $pdo): void {
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v >= self::VERSION) return;
        $pdo->beginTransaction();
        if ($v < 1) {
            $pdo->exec('CREATE TABLE vorlagen (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                art TEXT NOT NULL,
                titel TEXT NOT NULL,
                text TEXT NOT NULL,
                aktiv INTEGER NOT NULL DEFAULT 1,
                muster INTEGER NOT NULL DEFAULT 0,
                geaendert INTEGER NOT NULL
            )');
            $pdo->exec('CREATE TABLE vorgaenge (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vorlage_id INTEGER,
                art TEXT NOT NULL,
                titel TEXT NOT NULL,
                text TEXT NOT NULL,
                patient TEXT NOT NULL,
                kontext TEXT NOT NULL DEFAULT "",
                kontext_bis INTEGER NOT NULL DEFAULT 0,
                status TEXT NOT NULL DEFAULT "wartet",
                druck_vorher INTEGER NOT NULL DEFAULT 0,
                druck_nachher INTEGER NOT NULL DEFAULT 0,
                erstellt INTEGER NOT NULL,
                erstellt_von TEXT NOT NULL DEFAULT "",
                unterschrieben INTEGER NOT NULL DEFAULT 0,
                meldung TEXT NOT NULL DEFAULT ""
            )');
            $pdo->exec('CREATE INDEX vorgaenge_status ON vorgaenge(status, erstellt)');
            self::musterAnlegen($pdo);
        }
        if ($v < 2) {
            // Mehrere Pads (je Arbeitsplatz eines); der Vorgang merkt sich, an welchem Pad er unterschrieben wird.
            $pdo->exec('CREATE TABLE pads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                arbeitsplatz TEXT NOT NULL DEFAULT "",
                adresse TEXT NOT NULL,
                port INTEGER NOT NULL DEFAULT 1002,
                aktiv INTEGER NOT NULL DEFAULT 1
            )');
            $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN pad_id INTEGER NOT NULL DEFAULT 0');
        }
        if ($v < 3) {
            // Startcodes aus T2med (kienzle-signatur://): einmalig, 5 Minuten gültig; enthalten den Patienten für die Startseite.
            $pdo->exec('CREATE TABLE starts (
                hash TEXT PRIMARY KEY,
                patient TEXT NOT NULL,
                geburtsdatum TEXT NOT NULL DEFAULT "",
                kontext TEXT NOT NULL,
                bis INTEGER NOT NULL
            )');
        }
        $pdo->exec('PRAGMA user_version=' . self::VERSION);
        $pdo->commit();
    }

    private static function musterAnlegen(\PDO $pdo): void {
        $st = $pdo->prepare('INSERT INTO vorlagen (art, titel, text, aktiv, muster, geaendert) VALUES (?, ?, ?, 1, 1, ?)');
        foreach (Seite::muster() as [$art, $titel, $text]) $st->execute([$art, $titel, $text, time()]);
    }
}
