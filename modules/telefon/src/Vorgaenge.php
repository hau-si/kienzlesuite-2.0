<?php
declare(strict_types=1);
namespace KienzleTelefon;

/**
 * Aufgesprochene Anliegen (Rezept, Überweisung, Termin, Rückruf, Sonstiges) aus dem Whisper-Worker von Kienzlefon.
 *
 * Der Worker legt jeden Vorgang verschlüsselt ab (Format von Kienzlefon: AES-256-CBC, Schlüssel per RSA mit dem öffentlichen
 * Schlüssel des Moduls) unter <daten>/kienzlefon/vorgaenge/<id>.json.enc. Den geheimen RSA-Schlüssel erzeugt diese Klasse einmal;
 * er liegt nur verschlüsselt mit dem Modulschlüssel der Basis vor. Der Worker kann Vorgänge also schreiben, aber nicht lesen.
 * „Erledigt“ verschiebt nach vorgaenge/erledigt/; dort löscht die Pflege nach 7 Tagen (lib/anlage.py, ERLEDIGT_TAGE).
 * Inhalte von Vorgängen kommen nie ins Protokoll.
 */
final class Vorgaenge {
    public const TYPEN = ['rezeptbestellung' => 'Rezept', 'ueb_req' => 'Überweisung', 'termin' => 'Termin', 'rueckruf_details' => 'Rückruf',
        'rueckruf_tel_grund' => 'Rückruf', 'sonstiges' => 'Sonstiges', 'mehrere_anliegen' => 'Mehrere Anliegen', 'kienzlefon_error' => 'Technischer Fehler'];
    public const FELDER = ['vorname' => 'Vorname', 'nachname' => 'Nachname', 'geburtsdatum' => 'Geburtsdatum', 'telefon' => 'Rückrufnummer',
        'medikamente' => 'Medikamente', 'fachrichtung' => 'Fachrichtung', 'grund' => 'Grund', 'anliegen' => 'Anliegen'];
    private const ID = '/^[A-Za-z0-9_-]{1,80}$/D';
    private const MAX_DATEI = 2 * 1024 * 1024;

    private static function ordner(): string { return Anlage::$data . '/kienzlefon/vorgaenge'; }
    private static function oeffentlich(): string { return Anlage::$data . '/kienzlefon/telepraxis-public.pem'; }
    private static function geheim(): string { return Anlage::$data . '/vorgaenge-schluessel'; }

    /** Schlüsselpaar anlegen, falls es fehlt (vor dem Start des Workers, aus Anlage::anwenden). */
    public static function schluesselSicherstellen(\KienzleModul $k): void {
        if (is_file(self::oeffentlich()) && is_file(self::geheim())) return;
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $pem)) throw new \RuntimeException('Schlüssel für die Vorgänge ließ sich nicht erzeugen.');
        $pub = (string)(openssl_pkey_get_details($key)['key'] ?? '');
        if (!str_contains($pub, 'BEGIN PUBLIC KEY')) throw new \RuntimeException('Schlüssel für die Vorgänge ließ sich nicht erzeugen.');
        // Erst den geheimen Teil (verschlüsselt), dann den öffentlichen: ohne geheimen Teil darf der Worker nichts ablegen.
        self::schreiben(self::geheim(), $k->verschluesseln($pem, 'vorgaenge'), 0600);
        if (!is_dir(dirname(self::oeffentlich()))) mkdir(dirname(self::oeffentlich()), 0700, true);
        self::schreiben(self::oeffentlich(), $pub, 0644);
    }

    private static function schreiben(string $ziel, string $inhalt, int $modus): void {
        $tmp = $ziel . '.neu.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $inhalt) !== strlen($inhalt)) { @unlink($tmp); throw new \RuntimeException('Datei ließ sich nicht schreiben.'); }
        chmod($tmp, $modus);
        rename($tmp, $ziel);
    }

    public const BEREICHE = ['neu' => 'Neu', 'bearbeitung' => 'In Bearbeitung', 'erledigt' => 'Erledigt', 'papierkorb' => 'Papierkorb'];
    private const ORTE = ['offen' => '', 'erledigt' => '/erledigt', 'papierkorb' => '/papierkorb'];

    private static function pfad(string $id, string $ort, string $endung = '.json.enc'): string { return self::ordner() . self::ORTE[$ort] . '/' . $id . $endung; }

    /** Wo liegt der Vorgang (offen, erledigt, papierkorb)? */
    public static function ort(string $id): ?string {
        if (!preg_match(self::ID, $id)) return null;
        foreach (array_keys(self::ORTE) as $ort) if (is_file(self::pfad($id, $ort)) && !is_link(self::pfad($id, $ort))) return $ort;
        return null;
    }

    private static function ortOderFehler(string $id): string {
        $ort = self::ort($id);
        if ($ort === null) throw new \InvalidArgumentException('Unbekannter Vorgang.');
        return $ort;
    }

    /** Bearbeitungsstand ohne Patientendaten (Klartext): status neu|bearbeitung, bearbeiter, name, seit, dringend, anliegen (abgehakt). */
    public static function stand(string $id, ?string $ort = null): array {
        $ort ??= self::ort($id) ?? 'offen';
        $d = json_decode((string)@file_get_contents(self::pfad($id, $ort, '.stand.json')), true);
        $d = is_array($d) ? $d : [];
        return ['status' => ($d['status'] ?? '') === 'bearbeitung' ? 'bearbeitung' : 'neu', 'bearbeiter' => (string)($d['bearbeiter'] ?? ''), 'name' => (string)($d['name'] ?? ''),
            'seit' => (int)($d['seit'] ?? 0), 'dringend' => (bool)($d['dringend'] ?? false), 'anliegen' => is_array($d['anliegen'] ?? null) ? $d['anliegen'] : []];
    }

    private static function standSchreiben(string $id, string $ort, array $stand): void {
        self::schreiben(self::pfad($id, $ort, '.stand.json'), (string)json_encode($stand, JSON_UNESCAPED_UNICODE), 0600);
    }

    /** Kommentare (können Patientendaten enthalten): verschlüsselt mit dem Modulschlüssel. */
    public static function kommentare(\KienzleModul $k, string $id, ?string $ort = null): array {
        $ort ??= self::ort($id) ?? 'offen';
        $wert = @file_get_contents(self::pfad($id, $ort, '.kommentare'));
        if ($wert === false || trim($wert) === '') return [];
        try {
            $d = json_decode($k->entschluesseln(trim($wert), 'vorgaenge-kommentare'), true);
        } catch (\Throwable) {
            return [['von' => '', 'name' => '', 'zeit' => 0, 'text' => '(Kommentare ließen sich nicht entschlüsseln.)']];
        }
        return is_array($d) ? $d : [];
    }

    /** Vorgänge eines Bereichs (neu, bearbeitung, erledigt, papierkorb; „offen“ = neu und bearbeitung), dringende zuerst, sonst neueste zuerst. */
    public static function liste(\KienzleModul $k, string $bereich = 'neu'): array {
        $ort = in_array($bereich, ['erledigt', 'papierkorb'], true) ? $bereich : 'offen';
        $dateien = glob(self::ordner() . self::ORTE[$ort] . '/*.json.enc') ?: [];
        if (!$dateien) return [];
        $geheim = self::geheimerSchluessel($k);
        $out = [];
        foreach ($dateien as $f) {
            $id = substr(basename($f), 0, -strlen('.json.enc'));
            if (!preg_match(self::ID, $id) || is_link($f)) continue;
            $stand = self::stand($id, $ort);
            if ($ort === 'offen' && in_array($bereich, ['neu', 'bearbeitung'], true) && $stand['status'] !== $bereich) continue;
            $eintrag = ['id' => $id, 'zeit' => '', 'typ' => '', 'daten' => [], 'ort' => $ort, 'stand' => $stand, 'kommentare' => self::kommentare($k, $id, $ort),
                'geaendert' => (int)filemtime($f)];
            try {
                $r = self::entschluesseln((string)file_get_contents($f, false, null, 0, self::MAX_DATEI), $geheim);
                $p = is_array($r['payload'] ?? null) ? $r['payload'] : [];
                $out[] = ['zeit' => (string)($p['_kienzlefon']['erstellt_am'] ?? $r['received_at'] ?? ''), 'typ' => (string)($r['typ'] ?? ''), 'daten' => $p] + $eintrag;
            } catch (\Throwable) {
                error_log('telefon: Vorgang ' . $id . ' ließ sich nicht entschlüsseln.');
                $out[] = ['fehler' => 'Ließ sich nicht entschlüsseln.'] + $eintrag;
            }
        }
        usort($out, fn($a, $b) => [$b['stand']['dringend'], $b['id']] <=> [$a['stand']['dringend'], $a['id']]);
        return $out;
    }

    /** Anzahl je Bereich (ohne Schlüssel, nur aus dem Bearbeitungsstand). */
    public static function anzahl(): array {
        $n = ['neu' => 0, 'bearbeitung' => 0, 'erledigt' => count(glob(self::ordner() . '/erledigt/*.json.enc') ?: []), 'papierkorb' => count(glob(self::ordner() . '/papierkorb/*.json.enc') ?: [])];
        foreach (glob(self::ordner() . '/*.json.enc') ?: [] as $f) $n[self::stand(substr(basename($f), 0, -strlen('.json.enc')), 'offen')['status']]++;
        return $n;
    }

    public static function anzahlOffen(): int { return count(glob(self::ordner() . '/*.json.enc') ?: []); }

    /** Vorgang samt Stand und Kommentaren an einen anderen Ort legen (offen, erledigt, papierkorb). */
    public static function verschieben(string $id, string|bool $nach = 'erledigt'): void {
        if (is_bool($nach)) $nach = $nach ? 'offen' : 'erledigt';   // alte Aufrufe: verschieben($id, true) = wieder öffnen
        if (!isset(self::ORTE[$nach])) throw new \InvalidArgumentException('Unbekanntes Ziel.');
        $von = self::ortOderFehler($id);
        if ($von === $nach) return;
        if (!is_dir(self::ordner() . self::ORTE[$nach])) mkdir(self::ordner() . self::ORTE[$nach], 0700, true);
        foreach (['.json.enc', '.stand.json', '.kommentare'] as $e) {
            if (!is_file(self::pfad($id, $von, $e))) continue;
            rename(self::pfad($id, $von, $e), self::pfad($id, $nach, $e));
            touch(self::pfad($id, $nach, $e));   // Löschfrist (erledigt, Papierkorb) zählt ab jetzt
        }
    }

    /** „Löschen“: erst in den Papierkorb (7 Tage, wiederherstellbar), aus dem Papierkorb endgültig. */
    public static function loeschen(string $id): bool {
        $ort = self::ortOderFehler($id);
        if ($ort !== 'papierkorb') { self::verschieben($id, 'papierkorb'); return false; }
        foreach (['.json.enc', '.stand.json', '.kommentare'] as $e) if (is_file(self::pfad($id, $ort, $e))) unlink(self::pfad($id, $ort, $e));
        return true;
    }

    /** Übernehmen (auch von einem anderen Benutzer), zurück zu „Neu“, dringend an/aus, Teilanliegen abhaken. */
    public static function uebernehmen(\KienzleModul $k, string $id): void {
        if (self::ortOderFehler($id) !== 'offen') throw new \InvalidArgumentException('Nur offene Vorgänge lassen sich übernehmen.');
        $s = self::stand($id, 'offen');
        self::standSchreiben($id, 'offen', ['status' => 'bearbeitung', 'bearbeiter' => $k->benutzer(), 'name' => $k->name() ?: $k->benutzer(), 'seit' => time()] + $s);
    }

    public static function freigeben(string $id): void {
        if (self::ortOderFehler($id) !== 'offen') throw new \InvalidArgumentException('Nur offene Vorgänge.');
        self::standSchreiben($id, 'offen', ['status' => 'neu', 'bearbeiter' => '', 'name' => '', 'seit' => 0] + self::stand($id, 'offen'));
    }

    public static function dringend(string $id, bool $an): void {
        $ort = self::ortOderFehler($id);
        self::standSchreiben($id, $ort, ['dringend' => $an] + self::stand($id, $ort));
    }

    public static function anliegenAbhaken(\KienzleModul $k, string $id, int $nr, bool $an): void {
        $ort = self::ortOderFehler($id);
        if ($nr < 0 || $nr > 50) throw new \InvalidArgumentException('Unbekanntes Anliegen.');
        $s = self::stand($id, $ort);
        if ($an) $s['anliegen'][(string)$nr] = ['von' => $k->name() ?: $k->benutzer(), 'zeit' => time()];
        else unset($s['anliegen'][(string)$nr]);
        self::standSchreiben($id, $ort, $s);
    }

    public static function kommentieren(\KienzleModul $k, string $id, string $text): void {
        $ort = self::ortOderFehler($id);
        $text = trim(str_replace("\r", '', $text));
        if ($text === '' || mb_strlen($text) > 2000) throw new \InvalidArgumentException('Kommentar fehlt oder ist länger als 2000 Zeichen.');
        $liste = self::kommentare($k, $id, $ort);
        if (count($liste) >= 100) throw new \InvalidArgumentException('Höchstens 100 Kommentare je Vorgang.');
        $liste[] = ['von' => $k->benutzer(), 'name' => $k->name() ?: $k->benutzer(), 'zeit' => time(), 'text' => $text];
        self::schreiben(self::pfad($id, $ort, '.kommentare'), $k->verschluesseln((string)json_encode($liste, JSON_UNESCAPED_UNICODE), 'vorgaenge-kommentare'), 0600);
    }

    private static function geheimerSchluessel(\KienzleModul $k): \OpenSSLAsymmetricKey {
        $wert = @file_get_contents(self::geheim());
        if ($wert === false) throw new \RuntimeException('Der Schlüssel für die Vorgänge fehlt.');
        $key = openssl_pkey_get_private($k->entschluesseln(trim($wert), 'vorgaenge'));
        if ($key === false) throw new \RuntimeException('Der Schlüssel für die Vorgänge ist beschädigt.');
        return $key;
    }

    /** Format von kienzlefon/crypto.py (TelepraxisEncryptor.encrypt): {v:1, cipher:AES-256-CBC, sha256, ek, iv, ct}. */
    public static function entschluesseln(string $json, \OpenSSLAsymmetricKey $geheim): array {
        $w = json_decode($json, true);
        if (!is_array($w) || ($w['v'] ?? null) !== 1 || ($w['cipher'] ?? '') !== 'AES-256-CBC') throw new \RuntimeException('Unbekanntes Format.');
        $ek = base64_decode((string)($w['ek'] ?? ''), true);
        $iv = base64_decode((string)($w['iv'] ?? ''), true);
        $ct = base64_decode((string)($w['ct'] ?? ''), true);
        if ($ek === false || $iv === false || $ct === false || strlen($iv) !== 16) throw new \RuntimeException('Unbekanntes Format.');
        if (!openssl_private_decrypt($ek, $schluessel, $geheim, OPENSSL_PKCS1_PADDING) || strlen($schluessel) !== 32) throw new \RuntimeException('Schlüssel passt nicht.');
        $plain = openssl_decrypt($ct, 'aes-256-cbc', $schluessel, OPENSSL_RAW_DATA, $iv);
        if ($plain === false || !hash_equals((string)($w['sha256'] ?? ''), hash('sha256', $plain))) throw new \RuntimeException('Inhalt beschädigt.');
        $r = json_decode($plain, true);
        if (!is_array($r)) throw new \RuntimeException('Inhalt beschädigt.');
        return $r;
    }

    // ---- SMS an Anrufer (Nachrichten-Ausgang der Basis) ---------------------------------------------------------------
    /** Anrufernummer als internationale Nummer (+49…), wenn sie wie eine Handynummer aussieht; sonst ''. */
    public static function handynummer(string $nr): string {
        $nr = preg_replace('/[\s\/()-]/', '', $nr) ?? '';
        if (str_starts_with($nr, '00')) $nr = '+' . substr($nr, 2);
        elseif (str_starts_with($nr, '0')) $nr = '+49' . substr($nr, 1);
        elseif (preg_match('/^49\d{9,}$/D', $nr)) $nr = '+' . $nr;
        return preg_match('/^\+491[5-7]\d{7,10}$/D', $nr) ? $nr : '';
    }

    /** Vorschlag für den SMS-Text je Art (ohne Gesundheitsangaben; vor dem Senden änderbar). */
    public static function smsVorlage(string $typ, string $praxis): string {
        $gruss = $praxis !== '' ? ' Ihre ' . (preg_match('/^praxis\b/i', $praxis) ? '' : 'Praxis ') . $praxis : ' Ihre Praxis';
        return match ($typ) {
            'rezeptbestellung' => 'Ihr Rezept liegt zur Abholung bereit.',
            'ueb_req' => 'Ihre Überweisung liegt zur Abholung bereit.',
            'termin' => 'Bitte rufen Sie uns wegen Ihres Terminwunsches zurück.',
            default => 'Wir haben Ihr Anliegen erhalten und bearbeitet.',
        } . $gruss;
    }

    /** SMS-Aufträge je Vorgang (nur Auftragskennung und Zeit, keine Nummer, kein Text). */
    public static function smsListe(): array {
        $d = json_decode((string)@file_get_contents(self::ordner() . '/sms.json'), true);
        return is_array($d) ? $d : [];
    }

    public static function smsSenden(\KienzleModul $k, string $id, string $an, string $text): void {
        self::ortOderFehler($id);
        $an = preg_replace('/[\s\/()-]/', '', $an) ?? '';
        if (str_starts_with($an, '0') && !str_starts_with($an, '00')) $an = '+49' . substr($an, 1);
        $job = $k->smsSenden($an, trim($text));
        $liste = self::smsListe();
        $liste[$id][] = ['job' => $job, 'zeit' => time()];
        $liste = array_filter($liste, fn($x, $vid) => self::ort((string)$vid) !== null, ARRAY_FILTER_USE_BOTH);
        self::schreiben(self::ordner() . '/sms.json', (string)json_encode($liste), 0600);
    }
}
