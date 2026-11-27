<?php
declare(strict_types=1);
namespace KienzleTelefon;

/**
 * Telefonverzeichnis aus T2med: Patienten mit Nummer, Name, Geburtsdatum und Telefonnummern in <daten>/t2med/patienten.sqlite (0600).
 * Geladen wird es über den Aufruf „kienzle-telefonverzeichnis://…“ aus T2med (zentraler Starter der Basis): Der Starter legt einen
 * Ladeauftrag an (Token verschlüsselt), die Seite holt die Patienten danach schrittweise (je Anfrage einige Seiten, mit Fortschritt).
 * Erst wenn alles geladen ist, ersetzt die neue Datei die alte. Genutzt für: Suche, Namen in „Anrufe“, Anrufername am Telefon
 * (lib/anrufername.agi liest dieselbe Datei).
 */
final class Verzeichnis {
    public const GUELTIG = 4 * 3600;      // T2med-Kontext gilt höchstens 4 Stunden
    public const MAX_PATIENTEN = 200000;

    private static function ordner(): string {
        $d = Anlage::$data . '/t2med';
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d;
    }

    public static function datei(): string { return self::ordner() . '/patienten.sqlite'; }

    // ---- Einstellungen -------------------------------------------------------------------------------------------
    public static function einstellungen(): array {
        $d = json_decode((string)@file_get_contents(self::ordner() . '/einstellungen.json'), true);
        $d = is_array($d) ? $d : [];
        return ['ortsvorwahl' => preg_match('/^0[1-9][0-9]{1,5}$/D', (string)($d['ortsvorwahl'] ?? '')) ? (string)$d['ortsvorwahl'] : '', 'anrufername' => ($d['anrufername'] ?? true) !== false];
    }

    public static function einstellungenSpeichern(array $p): void {
        $vw = preg_replace('/\D/', '', (string)($p['ortsvorwahl'] ?? '')) ?? '';
        if ($vw !== '' && !preg_match('/^0[1-9][0-9]{1,5}$/D', $vw)) throw new \InvalidArgumentException('Ortsvorwahl: mit 0 beginnen, z. B. 030 oder 02331.');
        file_put_contents(self::ordner() . '/einstellungen.json', (string)json_encode(['ortsvorwahl' => $vw, 'anrufername' => !empty($p['anrufername'])]));
        @chmod(self::ordner() . '/einstellungen.json', 0644);         // Asterisk (gleicher Benutzer) liest sie im AGI
    }

    // ---- Nummern ---------------------------------------------------------------------------------------------------
    /**
     * Einheitlicher Schlüssel einer Telefonnummer (wie kienzlefon-t2med Phone::key, gleich in lib/anrufername.agi): Ziffern ohne +,
     * deutsch mit 49. 030 1234567, +49 30 1234567, 0049…, 49301234567 und „+49 (0)30 …“ ergeben 49301234567. Ohne Vorwahl nur mit
     * eingestellter Ortsvorwahl. null, wenn sich nichts Eindeutiges ergibt.
     */
    public static function schluessel(string $roh, string $ortsvorwahl = ''): ?string {
        $s = trim(str_ireplace(['tel:', '(0)'], '', $roh));
        if (!preg_match('/^\+?[0-9 ()\/.-]{3,40}$/D', $s)) return null;
        $plus = str_starts_with($s, '+');
        $d = preg_replace('/\D/', '', $s) ?? '';
        $k = match (true) {
            $plus => $d,
            str_starts_with($d, '00') => substr($d, 2),
            str_starts_with($d, '0') => '49' . substr($d, 1),
            str_starts_with($d, '49') && strlen($d) >= 10 => $d,
            $ortsvorwahl !== '' && strlen($d) >= 3 && strlen($d) <= 9 => '49' . substr($ortsvorwahl, 1) . $d,
            default => '',
        };
        return preg_match('/^[1-9][0-9]{5,14}$/D', $k) ? $k : null;
    }

    /** Anzeige: 49301234567 → 030 1234567, sonst +… */
    public static function anzeige(string $schluessel): string { return str_starts_with($schluessel, '49') ? '0' . substr($schluessel, 2) : '+' . $schluessel; }

    public static function suchname(string $vorname, string $nachname): string {
        $s = mb_strtolower($vorname . ' ' . $nachname);
        return strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a']);
    }

    // ---- Laden aus T2med -------------------------------------------------------------------------------------------
    private static function auftragDatei(): string { return self::ordner() . '/laden.json'; }

    /** Aufruf aus T2med über den Starter: Ladeauftrag anlegen; liefert den Link (#launch=…) für den Browser. */
    public static function starten(array $eingabe, T2med $t2med, \KienzleModul $k, string $host, ?int $jetzt = null): array {
        $jetzt ??= time();
        $kontext = (string)($eingabe['context_id'] ?? '');
        $token = (string)($eingabe['oauth_token'] ?? '');
        $url = rtrim((string)($eingabe['fhir_base_url'] ?? ''), '/');
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $kontext)) throw new \InvalidArgumentException('Ungültiger T2med-Kontext.');
        if ($token === '' || strlen($token) > 16000 || preg_match('/\s|\$\{/', $token)) throw new \InvalidArgumentException('T2med hat kein gültiges Zugriffstoken übergeben.');
        if (!$t2med->erlaubt($url)) throw new \InvalidArgumentException('Die von T2med übergebene FHIR-Adresse ist in der Suite nicht freigegeben (Verwaltung → Verbindungen → T2med).');
        if (!preg_match('/^[A-Za-z0-9.-]{1,100}(:\d{1,5})?$/D', $host)) throw new \InvalidArgumentException('Ungültiger Servername.');
        $code = bin2hex(random_bytes(32));
        @unlink(self::ordner() . '/neu.sqlite');
        $auftrag = ['code' => hash('sha256', $code), 'basis' => $url, 'naechste' => $url . '/Patient', 'token' => $k->verschluesseln($token, 't2med-token'),
            'seiten' => 0, 'anzahl' => 0, 'start' => $jetzt, 'bis' => $jetzt + self::GUELTIG, 'fehler' => ''];
        self::auftragSchreiben($auftrag);
        return ['url' => 'https://' . $host . '/#launch=' . $code . '&m=telefon'];
    }

    private static function auftragSchreiben(array $a): void {
        $tmp = self::auftragDatei() . '.neu';
        file_put_contents($tmp, (string)json_encode($a));
        chmod($tmp, 0600);
        rename($tmp, self::auftragDatei());
    }

    private static function auftrag(?int $jetzt = null): ?array {
        $a = json_decode((string)@file_get_contents(self::auftragDatei()), true);
        if (!is_array($a)) return null;
        if ((int)($a['bis'] ?? 0) < ($jetzt ?? time())) { self::abbrechen(); return null; }
        return $a;
    }

    public static function abbrechen(): void { @unlink(self::auftragDatei()); @unlink(self::ordner() . '/neu.sqlite'); }

    /** Läuft gerade ein Laden? [seiten, anzahl, fehler] oder null. */
    public static function laden(): ?array {
        $a = self::auftrag();
        return $a ? ['seiten' => (int)$a['seiten'], 'anzahl' => (int)$a['anzahl'], 'fehler' => (string)$a['fehler']] : null;
    }

    private static function neueDb(string $datei): \PDO {
        $pdo = new \PDO('sqlite:' . $datei, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE IF NOT EXISTS patienten (rid INTEGER PRIMARY KEY, id TEXT NOT NULL UNIQUE, nummer TEXT NOT NULL, vorname TEXT NOT NULL, nachname TEXT NOT NULL, geburt TEXT NOT NULL, suchname TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS telefone (rid INTEGER NOT NULL, original TEXT NOT NULL, schluessel TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
        @chmod($datei, 0600);
        return $pdo;
    }

    /**
     * Ein Schritt des Ladens (aufgerufen von der Seite, bis „fertig“): höchstens $maxSeiten Seiten oder $sekunden Sekunden.
     * Der Code aus dem Aufruf muss passen (nur der Browser, der aus T2med kam, lädt weiter).
     */
    public static function schritt(string $code, T2med $t2med, \KienzleModul $k, int $maxSeiten = 20, int $sekunden = 12): array {
        $a = self::auftrag();
        if (!$a || !preg_match('/^[0-9a-f]{64}$/D', $code) || !hash_equals((string)$a['code'], hash('sha256', $code))) {
            throw new \InvalidArgumentException('Kein Laden des Telefonverzeichnisses offen. Bitte frisch aus T2med starten.');
        }
        $token = $k->entschluesseln((string)$a['token'], 't2med-token');
        $neu = self::ordner() . '/neu.sqlite';
        $pdo = self::neueDb($neu);
        $start = time();
        try {
            for ($i = 0; $i < $maxSeiten && $a['naechste'] !== '' && time() - $start < $sekunden; $i++) {
                $bundle = $t2med->seite((string)$a['naechste'], $token);
                $liste = T2med::patienten($bundle);
                self::einfuegen($pdo, $liste, self::einstellungen()['ortsvorwahl']);
                $a['anzahl'] += count($liste);
                $a['seiten']++;
                $a['naechste'] = T2med::naechste($bundle, (string)$a['basis']) ?? '';
                if ($a['anzahl'] > self::MAX_PATIENTEN || $a['seiten'] > 20000) throw new \RuntimeException('Zu viele Patienten oder Seiten; Laden abgebrochen.');
            }
        } catch (\RuntimeException $e) {
            $pdo = null;
            self::abbrechen();
            throw $e;
        }
        if ($a['naechste'] === '') {
            $anzahl = (int)$pdo->query('SELECT COUNT(*) FROM patienten')->fetchColumn();
            $pdo->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')->execute(['stand', (string)time()]);
            $pdo->prepare('INSERT OR REPLACE INTO meta (k, v) VALUES (?, ?)')->execute(['anzahl', (string)$anzahl]);
            $pdo = null;
            rename($neu, self::datei());
            @unlink(self::auftragDatei());
            return ['fertig' => true, 'anzahl' => $anzahl, 'seiten' => (int)$a['seiten']];
        }
        $pdo = null;
        self::auftragSchreiben($a);
        return ['fertig' => false, 'anzahl' => (int)$a['anzahl'], 'seiten' => (int)$a['seiten']];
    }

    /** @param list<array> $liste aus T2med::patienten */
    public static function einfuegen(\PDO $pdo, array $liste, string $ortsvorwahl = ''): void {
        $pdo->beginTransaction();
        $p = $pdo->prepare('INSERT OR IGNORE INTO patienten (id, nummer, vorname, nachname, geburt, suchname) VALUES (?, ?, ?, ?, ?, ?)');
        $t = $pdo->prepare('INSERT INTO telefone (rid, original, schluessel) VALUES (?, ?, ?)');
        foreach ($liste as $x) {
            $p->execute([$x['id'], $x['nummer'], $x['vorname'], $x['nachname'], $x['geburt'], self::suchname($x['vorname'], $x['nachname'])]);
            if ($p->rowCount() === 0) continue;
            $rid = (int)$pdo->lastInsertId();
            foreach ($x['telefone'] as $tel) $t->execute([$rid, $tel, self::schluessel($tel, $ortsvorwahl) ?? '']);
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS telefone_schluessel ON telefone(schluessel)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS patienten_nummer ON patienten(nummer)');
        $pdo->commit();
    }

    // ---- Lesen -------------------------------------------------------------------------------------------------
    private static ?\PDO $lesen = null;

    private static function db(): ?\PDO {
        if (self::$lesen) return self::$lesen;
        if (!is_file(self::datei())) return null;
        return self::$lesen = new \PDO('sqlite:file:' . self::datei() . '?mode=ro', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    }

    public static function zuruecksetzen(): void { self::$lesen = null; }

    /** Stand des Verzeichnisses: anzahl, zeit (0 = keins). */
    public static function stand(): array {
        $db = self::db();
        if (!$db) return ['anzahl' => 0, 'zeit' => 0];
        $m = $db->query('SELECT k, v FROM meta')->fetchAll(\PDO::FETCH_KEY_PAIR);
        return ['anzahl' => (int)($m['anzahl'] ?? 0), 'zeit' => (int)($m['stand'] ?? 0)];
    }

    public static function loeschen(): void { self::zuruecksetzen(); @unlink(self::datei()); self::abbrechen(); }

    private static function mitTelefonen(array $zeilen): array {
        $db = self::db();
        $st = $db->prepare('SELECT original FROM telefone WHERE rid = ?');
        foreach ($zeilen as &$z) {
            $st->execute([$z['rid']]);
            $z['telefone'] = array_column($st->fetchAll(), 'original');
            unset($z['suchname']);
        }
        return $zeilen;
    }

    /**
     * Suche: nur Ziffern (bis 12) = Patientennummer genau; sieht es nach Telefonnummer aus, auch über die Nummer; sonst müssen alle
     * Wörter im Namen vorkommen (Reihenfolge egal, ä = ae). Höchstens $max Treffer, nach Nachname sortiert.
     */
    public static function suchen(string $q, int $max = 30): array {
        $db = self::db();
        $q = trim(mb_substr($q, 0, 100));
        if (!$db || $q === '') return [];
        $treffer = [];
        $digits = preg_replace('/\D/', '', $q) ?? '';
        if (preg_match('/^\+?[0-9 ()\/.-]+$/D', $q) && $digits !== '') {
            if (strlen($digits) <= 12 && ctype_digit(str_replace(' ', '', $q))) {
                $st = $db->prepare('SELECT * FROM patienten WHERE nummer = ?');
                $st->execute([$digits]);
                foreach ($st->fetchAll() as $z) $treffer[$z['rid']] = $z;
            }
            $k = self::schluessel($q, self::einstellungen()['ortsvorwahl']);
            if ($k !== null || strlen($digits) >= 5) {
                $st = $db->prepare("SELECT p.* FROM patienten p JOIN telefone t ON t.rid = p.rid WHERE t.schluessel = ? OR (? <> '' AND t.schluessel LIKE ?) LIMIT ?");
                $st->execute([$k ?? '', strlen($digits) >= 5 ? '1' : '', '%' . (str_starts_with($digits, '0') ? substr($digits, 1) : $digits), $max]);
                foreach ($st->fetchAll() as $z) $treffer[$z['rid']] = $z;
            }
        } else {
            $woerter = array_slice(array_filter(preg_split('/[\s,]+/u', self::suchname($q, '')) ?: [], fn($w) => $w !== ''), 0, 5);
            if (!$woerter) return [];
            $sql = 'SELECT * FROM patienten WHERE ' . implode(' AND ', array_fill(0, count($woerter), "suchname LIKE ? ESCAPE '\\'")) . ' ORDER BY nachname, vorname LIMIT ' . (int)$max;
            $st = $db->prepare($sql);
            $st->execute(array_map(fn($w) => '%' . addcslashes($w, '%_\\') . '%', $woerter));
            foreach ($st->fetchAll() as $z) $treffer[$z['rid']] = $z;
        }
        $liste = array_values($treffer);
        usort($liste, fn($a, $b) => [$a['nachname'], $a['vorname']] <=> [$b['nachname'], $b['vorname']]);
        return self::mitTelefonen(array_slice($liste, 0, $max));
    }

    /** Patient zu einer Anrufernummer; null, wenn keiner oder mehrere verschiedene. */
    public static function nachNummer(string $nummer): ?array {
        $db = self::db();
        $k = $db ? self::schluessel($nummer, self::einstellungen()['ortsvorwahl']) : null;
        if ($k === null) return null;
        $st = $db->prepare('SELECT DISTINCT p.* FROM patienten p JOIN telefone t ON t.rid = p.rid WHERE t.schluessel = ? LIMIT 3');
        $st->execute([$k]);
        $z = $st->fetchAll();
        return count($z) === 1 ? self::mitTelefonen($z)[0] : null;
    }

    /** Anzeigename „Nachname, Vorname“. */
    public static function name(array $p): string {
        return trim($p['nachname'] . ($p['vorname'] !== '' ? ', ' . $p['vorname'] : ''), ', ') ?: 'Patient';
    }
}
