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

    /** Vorgänge eines Bereichs (offen|erledigt), neueste zuerst. Jeder Eintrag: id, zeit, typ, daten (payload) oder fehler. */
    public static function liste(\KienzleModul $k, string $bereich = 'offen'): array {
        $dir = self::ordner() . ($bereich === 'erledigt' ? '/erledigt' : '');
        $dateien = glob($dir . '/*.json.enc') ?: [];
        if (!$dateien) return [];
        $geheim = self::geheimerSchluessel($k);
        $out = [];
        foreach ($dateien as $f) {
            $id = substr(basename($f), 0, -strlen('.json.enc'));
            if (!preg_match(self::ID, $id) || is_link($f)) continue;
            try {
                $r = self::entschluesseln((string)file_get_contents($f, false, null, 0, self::MAX_DATEI), $geheim);
                $p = is_array($r['payload'] ?? null) ? $r['payload'] : [];
                $out[] = ['id' => $id, 'zeit' => (string)($p['_kienzlefon']['erstellt_am'] ?? $r['received_at'] ?? ''), 'typ' => (string)($r['typ'] ?? ''), 'daten' => $p,
                    'erledigt_am' => $bereich === 'erledigt' ? (int)filemtime($f) : 0];
            } catch (\Throwable) {
                error_log('telefon: Vorgang ' . $id . ' ließ sich nicht entschlüsseln.');
                $out[] = ['id' => $id, 'zeit' => '', 'typ' => '', 'daten' => [], 'fehler' => 'Ließ sich nicht entschlüsseln.', 'erledigt_am' => 0];
            }
        }
        usort($out, fn($a, $b) => strcmp($b['id'], $a['id']));
        return $out;
    }

    public static function anzahlOffen(): int { return count(glob(self::ordner() . '/*.json.enc') ?: []); }

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

    /** offen → erledigt (zurueck=false) bzw. erledigt → offen (zurueck=true). */
    public static function verschieben(string $id, bool $zurueck = false): void {
        if (!preg_match(self::ID, $id)) throw new \InvalidArgumentException('Unbekannter Vorgang.');
        $offen = self::ordner() . "/$id.json.enc";
        $erledigt = self::ordner() . "/erledigt/$id.json.enc";
        [$von, $nach] = $zurueck ? [$erledigt, $offen] : [$offen, $erledigt];
        if (!is_file($von) || is_link($von)) throw new \InvalidArgumentException('Unbekannter Vorgang.');
        if (!is_dir(dirname($nach))) mkdir(dirname($nach), 0700, true);
        rename($von, $nach);
        touch($nach);   // Löschfrist der erledigten Vorgänge zählt ab jetzt
    }

    public static function loeschen(string $id): void {
        if (!preg_match(self::ID, $id)) throw new \InvalidArgumentException('Unbekannter Vorgang.');
        foreach ([self::ordner() . "/$id.json.enc", self::ordner() . "/erledigt/$id.json.enc"] as $f) {
            if (is_file($f) && !is_link($f)) { unlink($f); return; }
        }
        throw new \InvalidArgumentException('Unbekannter Vorgang.');
    }
}
