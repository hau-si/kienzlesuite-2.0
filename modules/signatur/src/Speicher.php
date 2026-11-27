<?php
declare(strict_types=1);
namespace KienzleSignatur;

/** SQLite-Datenbank des Moduls (Vorlagen und Vorgänge); Schema über user_version. */
final class Speicher {
    public static string $data = '/var/lib/kienzle/signatur';
    private static ?\PDO $pdo = null;
    private static string $pfad = '';
    public const VERSION = 12;

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

    /** Schlüssel für die Zugriffstoken: eigene Datei im Datenordner, nur für dieses Modul lesbar. */
    private static function schluessel(): string {
        $datei = self::$data . '/token.key';
        if (!is_file($datei)) {
            if (!is_dir(self::$data) && !mkdir(self::$data, 0700, true) && !is_dir(self::$data)) throw new \RuntimeException('Datenordner nicht anlegbar.');
            $fd = fopen($datei, 'xb');
            if ($fd) { fwrite($fd, bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES))); fclose($fd); }
            @chmod($datei, 0600);
        }
        $hex = trim((string)@file_get_contents($datei));
        $key = ctype_xdigit($hex) && strlen($hex) === 64 ? hex2bin($hex) : false;
        if ($key === false) throw new \RuntimeException('Der Schlüssel für Zugriffstoken ist nicht lesbar.');
        return $key;
    }

    /** Token zu einem T2med-Kontext ablegen (verschlüsselt, bis $bis); überschreibt einen älteren Eintrag. */
    public static function tokenMerken(string $kontext, string $token, int $bis): void {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = base64_encode($nonce . sodium_crypto_secretbox($token, $nonce, self::schluessel()));
        self::pdo()->prepare('INSERT OR REPLACE INTO tokens (kontext, cipher, bis) VALUES (?, ?, ?)')->execute([$kontext, $cipher, $bis]);
    }

    /** Ablauf des Tokens zu einem Kontext (Unix-Zeit, 0 = keins). */
    public static function tokenBis(string $kontext): int {
        $st = self::pdo()->prepare('SELECT bis FROM tokens WHERE kontext = ?');
        $st->execute([$kontext]);
        return (int)$st->fetchColumn();
    }

    /** Token zu einem Kontext; null, wenn es keines gibt oder es abgelaufen ist. */
    public static function tokenHolen(string $kontext, ?int $jetzt = null): ?string {
        $jetzt ??= time();
        self::pdo()->prepare('DELETE FROM tokens WHERE bis < ?')->execute([$jetzt]);
        $st = self::pdo()->prepare('SELECT cipher FROM tokens WHERE kontext = ? AND bis >= ?');
        $st->execute([$kontext, $jetzt]);
        $c = $st->fetchColumn();
        $roh = is_string($c) ? base64_decode($c, true) : false;
        if ($roh === false || strlen($roh) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 16) return null;
        $t = sodium_crypto_secretbox_open(substr($roh, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($roh, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::schluessel());
        return $t === false ? null : $t;
    }

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
            // Startcodes aus T2med (praxisherz-signatur://): einmalig, 5 Minuten gültig; enthalten den Patienten für die Startseite.
            $pdo->exec('CREATE TABLE starts (
                hash TEXT PRIMARY KEY,
                patient TEXT NOT NULL,
                geburtsdatum TEXT NOT NULL DEFAULT "",
                kontext TEXT NOT NULL,
                bis INTEGER NOT NULL
            )');
        }
        if ($v < 4) {
            // Vorgänge aus der Kasse: Herkunft, Verweis auf den Kassenvorgang und die übergebene Leistungsliste (JSON).
            $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN quelle TEXT NOT NULL DEFAULT ""');
            $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN quelle_ref TEXT NOT NULL DEFAULT ""');
            $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN leistungen TEXT NOT NULL DEFAULT ""');
        }
        if ($v < 5) {
            // Kontaktdaten des Patienten aus T2med für die Platzhalter {{telefon}}, {{email}}, {{adresse}}.
            foreach (['telefon', 'email', 'adresse'] as $spalte) $pdo->exec('ALTER TABLE starts ADD COLUMN ' . $spalte . ' TEXT NOT NULL DEFAULT ""');
        }
        if ($v < 6) {
            // Namen und Geschlecht für Anrede-Platzhalter; Patienten-ID und Zugriffstoken (verschlüsselt, höchstens 4 h) für „In T2med übernehmen“.
            foreach (['vorname', 'nachname', 'geschlecht', 'pid'] as $spalte) $pdo->exec('ALTER TABLE starts ADD COLUMN ' . $spalte . ' TEXT NOT NULL DEFAULT ""');
            $pdo->exec('CREATE TABLE tokens (kontext TEXT PRIMARY KEY, cipher TEXT NOT NULL, bis INTEGER NOT NULL)');
            foreach (['patient_id', 'kontakt_alt', 'kontakt_neu', 'kontakt_status', 'kontakt_meldung'] as $spalte) $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN ' . $spalte . ' TEXT NOT NULL DEFAULT ""');
        }
        if ($v < 7) {
            // Für das PDF (Entwurf C): Geburtsdatum des Patienten und Praxisangaben (Name, Anschrift) zum Zeitpunkt des Anlegens.
            foreach (['geburtsdatum', 'praxis'] as $spalte) $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN ' . $spalte . ' TEXT NOT NULL DEFAULT ""');
        }
        if ($v < 8) {
            // Ablage des PDFs in T2med (direkt oder über die Kasse): '', an_kasse, erledigt, fehler, manuell.
            $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN ablage TEXT NOT NULL DEFAULT ""');
            // Schon übertragene Vorgänge gelten als erledigt, schon unterschriebene ältere nicht automatisch (kein Nachholen ohne Wunsch).
            $pdo->exec("UPDATE vorgaenge SET ablage='erledigt' WHERE status='uebertragen'");
            $pdo->exec("UPDATE vorgaenge SET ablage='manuell' WHERE status='unterschrieben'");
        }
        if ($v < 9) {
            // Archiv (Startseite: Vorgang aus der linken Spalte nehmen, bleibt unter „Vorgänge“ mit Filter „Archiv“).
            $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN archiviert INTEGER NOT NULL DEFAULT 0');
        }
        if ($v < 10) {
            // Baustein-Editor (Editor C): Bausteine als JSON, Fassung je Vorlage; der Vorgang merkt sich Fassung, Bausteine und Antworten.
            $pdo->exec('ALTER TABLE vorlagen ADD COLUMN bloecke TEXT NOT NULL DEFAULT ""');
            $pdo->exec('ALTER TABLE vorlagen ADD COLUMN version INTEGER NOT NULL DEFAULT 1');
            foreach (['bloecke', 'antworten'] as $spalte) $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN ' . $spalte . ' TEXT NOT NULL DEFAULT ""');
            $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN vorlage_version INTEGER NOT NULL DEFAULT 0');
            $st = $pdo->prepare('UPDATE vorlagen SET bloecke=? WHERE id=?');
            foreach ($pdo->query('SELECT id, text FROM vorlagen')->fetchAll() as $r) $st->execute([json_encode(Bausteine::ausAltemText((string)$r['text']), JSON_UNESCAPED_UNICODE), (int)$r['id']]);
        }
        if ($v < 11) {
            // Akteneintrag je Baustein (Wunsch des Nutzers): Kürzel der Vorlage in T2med; der Vorgang merkt sich Kürzel und Eintragstext.
            $pdo->exec('ALTER TABLE vorlagen ADD COLUMN kuerzel TEXT NOT NULL DEFAULT ""');
            foreach (['kuerzel', 'akte'] as $spalte) $pdo->exec('ALTER TABLE vorgaenge ADD COLUMN ' . $spalte . ' TEXT NOT NULL DEFAULT ""');
        }
        if ($v < 12) {
            // Vorlagenliste mit Favoriten und Gruppen (Wunsch des Nutzers, wie im Fax); Gruppen aus der Dokumentart vorbelegt.
            $pdo->exec('ALTER TABLE vorlagen ADD COLUMN favorit INTEGER NOT NULL DEFAULT 0');
            $pdo->exec('ALTER TABLE vorlagen ADD COLUMN gruppen TEXT NOT NULL DEFAULT ""');
            $st = $pdo->prepare('UPDATE vorlagen SET gruppen=? WHERE id=?');
            foreach ($pdo->query('SELECT id, art FROM vorlagen')->fetchAll() as $r) $st->execute([Seite::gruppeZurArt((string)$r['art']), (int)$r['id']]);
        }
        $pdo->exec('PRAGMA user_version=' . self::VERSION);
        $pdo->commit();
    }

    private static function musterAnlegen(\PDO $pdo): void {
        $st = $pdo->prepare('INSERT INTO vorlagen (art, titel, text, aktiv, muster, geaendert) VALUES (?, ?, ?, 1, 1, ?)');
        foreach (Seite::muster() as [$art, $titel, $text]) $st->execute([$art, $titel, $text, time()]);
    }
}
