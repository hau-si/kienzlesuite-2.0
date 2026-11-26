<?php
declare(strict_types=1);
namespace KienzleTelefon;

/**
 * Einstellungen der Telefonanlage (anlage.json im Datenordner). Passwörter der Leitungen und Telefone liegen dort nur
 * verschlüsselt (Schlüssel des Moduls von der Basis). Prüfen und Erzeugen der Asterisk-Dateien macht lib/anlage.py;
 * PHP reicht die Einstellungen samt entschlüsselten Passwörtern nur über stdin weiter.
 */
final class Anlage {
    public static string $data = '/var/lib/kienzle/telefon';
    /** @var callable|null Nur Tests: statt python3 lib/anlage.py */
    public static $runner = null;

    public const ANBIETER = [
        '1und1' => '1&1 (UDP)', '1und1-tls' => '1&1 (TLS, verschlüsselt)', 'telekom' => 'Telekom', 'sipgate' => 'sipgate (Basic/Team)',
        'voip2gsm' => 'voip2gsm', 'fonial' => 'Fonial / Plusnet', 'sipgate-trunk' => 'sipgate trunking (nur Überlauf)', 'andere' => 'Anderer Anbieter',
    ];

    public static function leer(): array {
        return ['leitungen' => [], 'fax' => ['leitung' => '', 'nummer' => '', 'kennung' => '', 'name' => 'Fax'],
            'telefon' => ['leitung_ein' => '', 'nummer_ein' => '', 'leitung_aus' => '', 'kennung_aus' => '', 'aus_zaehlt' => true, 'ausland' => false, 'sonderrufnummern' => false],
            'ueberlauf' => ['leitung' => '', 'nummer' => ''], 'kanaele' => ['gesamt' => 4, 'telefon' => 3, 'fax' => 3, 'ueberlauf' => 2],
            'warteschlange' => ['max_wartend' => 5], 'nebenstellen' => [], 'netz' => ['lokal' => '', 'erlaubt' => '', 'extern' => ''],
            'provisionierung' => ['aktiv' => false, 'schluessel' => ''],
            'kienzlefon' => ['aktiv' => false, 'tts' => 'piper', 'google' => ['schluessel' => '', 'stimme' => '', 'tempo' => ''], 'wartemusik' => 'klingelzeichen', 'whisper_genau' => false, 'rufgruppen' => false]];
    }

    public static function laden(): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/anlage.json'), true);
        $leer = self::leer();
        if (!is_array($d)) return $leer;
        foreach ($leer as $k => $v) {
            if (!array_key_exists($k, $d) || gettype($d[$k]) !== gettype($v)) $d[$k] = $v;
            elseif (is_array($v) && !array_is_list($v) && $v !== []) $d[$k] += $v;
        }
        return $d;
    }

    public static function eingerichtet(): bool { return is_file(self::$data . '/anlage.json'); }

    /** Prüfen (lib/anlage.py), speichern, anwenden. Liefert das Ergebnis von „anwenden“. */
    public static function speichern(array $cfg, \KienzleModul $k): array {
        $klar = self::klartext($cfg, $k);
        $r = self::python('pruefen', $klar);
        if (($r['ok'] ?? false) !== true) throw new \InvalidArgumentException((string)($r['fehler'] ?? 'Einstellungen ungültig.'));
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        $tmp = self::$data . '/.anlage.json.neu';
        if (file_put_contents($tmp, $json) !== strlen($json)) throw new \RuntimeException('Einstellungen ließen sich nicht speichern.');
        chmod($tmp, 0600);
        rename($tmp, self::$data . '/anlage.json');
        return self::anwenden($k, $cfg);
    }

    /** Gespeicherte Einstellungen an Asterisk geben (nach dem Speichern und nach einem Update). */
    public static function anwenden(\KienzleModul $k, ?array $cfg = null): array {
        $cfg ??= self::laden();
        // Schlüsselpaar für die Vorgänge vor dem ersten Start des Whisper-Workers (der braucht den öffentlichen Teil).
        if ($cfg['kienzlefon']['aktiv'] ?? false) Vorgaenge::schluesselSicherstellen($k);
        $r = self::python('anwenden', self::klartext($cfg, $k));
        if (($r['ok'] ?? false) !== true) throw new \RuntimeException((string)($r['fehler'] ?? 'Die Anlage ließ sich nicht neu laden.'));
        return $r;
    }

    public static function status(): array {
        try { return self::python('status', null); } catch (\RuntimeException) { return ['laeuft' => false]; }
    }

    /** Passwörter für den Generator entschlüsseln und den Praxisnamen der Basis mitgeben. */
    public static function klartext(array $cfg, \KienzleModul $k): array {
        foreach ($cfg['leitungen'] ?? [] as $i => $l) $cfg['leitungen'][$i]['passwort'] = self::offen((string)($l['passwort'] ?? ''), $k, 'leitung');
        foreach ($cfg['nebenstellen'] ?? [] as $i => $n) $cfg['nebenstellen'][$i]['passwort'] = self::offen((string)($n['passwort'] ?? ''), $k, 'nebenstelle');
        if (isset($cfg['kienzlefon']['google']['schluessel'])) $cfg['kienzlefon']['google']['schluessel'] = self::offen((string)$cfg['kienzlefon']['google']['schluessel'], $k, 'google');
        $name = preg_replace('/[^\p{L}\p{N} .&\/-]/u', '', $k->praxis()['name']) ?? '';
        $cfg['praxis'] = mb_substr(trim($name), 0, 60);
        return $cfg;
    }

    private static function offen(string $wert, \KienzleModul $k, string $zweck): string {
        if ($wert === '') return '';
        return str_starts_with($wert, 'v1:') ? $k->entschluesseln($wert, $zweck) : $wert;
    }

    // ---- Änderungen aus den Formularen ------------------------------------------------------------------------
    public static function leitungSpeichern(array $cfg, array $p, \KienzleModul $k): array {
        $id = (string)($p['id'] ?? '');
        $alt = null;
        foreach ($cfg['leitungen'] as $i => $l) if ($l['id'] === $id) $alt = $i;
        if ($id === '' || $alt === null) {
            if (count($cfg['leitungen']) >= 10) throw new \InvalidArgumentException('Höchstens 10 Leitungen.');
            $id = 'l' . bin2hex(random_bytes(3));
        }
        $anbieter = (string)($p['anbieter'] ?? '');
        if (!isset(self::ANBIETER[$anbieter])) throw new \InvalidArgumentException('Bitte einen Anbieter wählen.');
        $pw = (string)($p['passwort'] ?? '');
        if ($pw === '' && $alt === null) throw new \InvalidArgumentException('Bitte das SIP-Passwort der Leitung eingeben.');
        $neu = ['id' => $id, 'anbieter' => $anbieter];
        foreach (['name', 'benutzer', 'domain', 'nummer', 'proxy', 'identify', 'client_uri', 'from_user'] as $f) $neu[$f] = trim((string)($p[$f] ?? ''));
        $neu['ablauf'] = (int)($p['ablauf'] ?? 0) ?: null;
        $neu['passwort'] = $pw !== '' ? $k->verschluesseln($pw, 'leitung') : (string)$cfg['leitungen'][$alt]['passwort'];
        if ($alt === null) $cfg['leitungen'][] = $neu; else $cfg['leitungen'][$alt] = $neu;
        return $cfg;
    }

    public static function leitungLoeschen(array $cfg, string $id): array {
        foreach (['fax' => ['leitung'], 'telefon' => ['leitung_ein', 'leitung_aus'], 'ueberlauf' => ['leitung']] as $zweck => $felder) {
            foreach ($felder as $f) if (($cfg[$zweck][$f] ?? '') === $id) throw new \InvalidArgumentException('Die Leitung wird noch verwendet. Erst unter „Rufnummern“ eine andere wählen.');
        }
        $cfg['leitungen'] = array_values(array_filter($cfg['leitungen'], fn($l) => $l['id'] !== $id));
        return $cfg;
    }

    public static function zweckeSpeichern(array $cfg, array $p): array {
        $cfg['fax'] = ['leitung' => (string)($p['fax_leitung'] ?? ''), 'nummer' => trim((string)($p['fax_nummer'] ?? '')),
            'kennung' => trim((string)($p['fax_kennung'] ?? '')), 'name' => trim((string)($p['fax_name'] ?? ''))];
        $cfg['telefon'] = ['leitung_ein' => (string)($p['tel_leitung_ein'] ?? ''), 'nummer_ein' => trim((string)($p['tel_nummer_ein'] ?? '')),
            'leitung_aus' => (string)($p['tel_leitung_aus'] ?? ''), 'kennung_aus' => trim((string)($p['tel_kennung_aus'] ?? '')),
            'aus_zaehlt' => !empty($p['tel_aus_zaehlt']), 'ausland' => !empty($p['tel_ausland']), 'sonderrufnummern' => !empty($p['tel_sonder'])];
        $cfg['ueberlauf'] = ['leitung' => (string)($p['ueb_leitung'] ?? ''), 'nummer' => trim((string)($p['ueb_nummer'] ?? ''))];
        return $cfg;
    }

    public static function kanaeleSpeichern(array $cfg, array $p): array {
        foreach (['gesamt', 'telefon', 'fax', 'ueberlauf'] as $f) $cfg['kanaele'][$f] = (int)($p['k_' . $f] ?? 0) ?: null;
        $cfg['warteschlange']['max_wartend'] = (int)($p['max_wartend'] ?? 0) ?: null;
        return $cfg;
    }

    public const SPRACHAUSGABE = ['piper' => 'Piper (lokal, Standard)', 'google' => 'Google (nur feste Praxistexte, Schlüssel nötig)', 'kokoro' => 'Kokoro-Thorsten (lokal, großer Download)'];

    /** Sprachmenü (Kienzlefon) ein/aus und Sprachausgabe der Ansagen. */
    public static function sprachmenueSpeichern(array $cfg, array $p, \KienzleModul $k): array {
        $tts = (string)($p['tts'] ?? 'piper');
        if (!isset(self::SPRACHAUSGABE[$tts])) throw new \InvalidArgumentException('Bitte eine Sprachausgabe wählen.');
        $alt = (array)($cfg['kienzlefon']['google'] ?? []);
        $schluessel = trim((string)($p['google_schluessel'] ?? ''));
        $google = ['schluessel' => $schluessel !== '' ? $k->verschluesseln($schluessel, 'google') : (string)($alt['schluessel'] ?? ''),
            'stimme' => trim((string)($p['google_stimme'] ?? '')) ?: (string)($alt['stimme'] ?? ''), 'tempo' => trim((string)($p['google_tempo'] ?? '')) ?: ($alt['tempo'] ?? '')];
        if (!empty($p['google_entfernen'])) $google['schluessel'] = '';
        $cfg['kienzlefon'] = ['aktiv' => !empty($p['aktiv']), 'tts' => $tts, 'google' => $google, 'rufgruppen' => !empty($p['aktiv']) && !empty($p['rufgruppen'])] + $cfg['kienzlefon'];
        return $cfg;
    }

    // ---- Seite „Rufgruppen“: wer bei Taste 1 (Normal) bzw. 9 (ROT) klingelt; Steuerungsdienst von Kienzlefon -------
    public static function rufgruppenLesen(): array {
        $r = self::python('rufgruppen-lesen', null);
        if (($r['ok'] ?? false) !== true) throw new \RuntimeException((string)($r['fehler'] ?? 'Die Rufgruppen ließen sich nicht lesen.'));
        return $r;
    }

    /** Ein Telefon jetzt an- oder abmelden (bis zum nächsten Neustart der Anlage). */
    public static function rufgruppeSchalten(array $p): string {
        $gruppe = (string)($p['gruppe'] ?? '');
        $nummer = (string)($p['nummer'] ?? '');
        if (!in_array($gruppe, ['normal', 'specialist'], true) || !preg_match('/^[0-9]{2,8}$/D', $nummer)) throw new \InvalidArgumentException('Ungültige Angaben.');
        $an = ($p['an'] ?? '') === '1';
        $r = self::python('rufgruppen-schalten', ['gruppe' => $gruppe, 'nummer' => $nummer, 'an' => $an, 'revision' => mb_substr((string)($p['revision'] ?? ''), 0, 120)]);
        if (($r['ok'] ?? false) !== true) throw new \InvalidArgumentException((string)($r['fehler'] ?? 'Die Anlage hat nicht geantwortet.'));
        return 'Telefon ' . $nummer . ($an ? ' klingelt jetzt' : ' klingelt jetzt nicht mehr') . ' in „' . ($gruppe === 'normal' ? 'Normal' : 'ROT') . '“ (bis zum nächsten Neustart der Anlage).';
    }

    /** Standards (wer nach einem Neustart klingelt) und Rufstufen für Normal speichern. */
    public static function rufgruppenSpeichern(array $p): string {
        $telefone = [];
        foreach ((array)($p['tel'] ?? []) as $nr => $g) {
            if (!is_string($nr) && !is_int($nr) || !preg_match('/^[0-9]{2,8}$/D', (string)$nr) || !is_array($g)) continue;
            $telefone[(string)$nr] = ['normal' => ['standard' => !empty($g['normal']), 'stufe' => max(0, min(1000, (int)($g['stufe'] ?? 0)))],
                'specialist' => ['standard' => !empty($g['specialist'])]];
        }
        $r = self::python('rufgruppen-speichern', ['stand' => mb_substr((string)($p['stand'] ?? ''), 0, 64), 'telefone' => $telefone]);
        if (($r['ok'] ?? false) !== true) throw new \InvalidArgumentException((string)($r['fehler'] ?? 'Die Angaben sind ungültig.'));
        return 'Standards gespeichert. Sie gelten sofort für die Telefone, deren Standard sich geändert hat, und nach jedem Neustart der Anlage.';
    }

    // ---- Seite „Sprachmenü“: Zeiten, Ansagetexte, Menütasten (in kienzlefon.toml, Prüfung durch Kienzlefon) -------
    public const MODI = ['immer' => 'immer', 'offen' => 'nur wenn geöffnet', 'zu' => 'nur wenn geschlossen', 'deaktiviert' => 'nie'];

    public static function sprachmenueLesen(): array {
        $r = self::python('sprachmenue-lesen', null);
        if (($r['ok'] ?? false) !== true) throw new \RuntimeException((string)($r['fehler'] ?? 'Das Sprachmenü ließ sich nicht lesen.'));
        return $r;
    }

    /** Formular der Seite in Angaben für lib/anlage.py umsetzen (nur Zeichenketten, Längen begrenzt; Inhalt prüft Kienzlefon). */
    public static function sprachmenueAuftrag(array $p): array {
        $kurz = static fn($v, int $max) => mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);
        $job = ['stand' => $kurz($p['stand'] ?? '', 64)];
        foreach ((array)($p['zeit'] ?? []) as $s => $tage) {
            if (!is_string($s) || !preg_match('/^[a-z]{1,30}$/D', $s) || !is_array($tage)) continue;
            foreach ($tage as $tag => $v) if (is_string($tag) && preg_match('/^[a-z]{1,12}$/D', $tag)) $job['zeiten'][$s][$tag] = $kurz($v, 200);
        }
        foreach (['ansage' => ['ansagen', 5000], 'modus' => ['modi', 20], 'taste' => ['tasten', 1]] as $feld => [$ziel, $max]) {
            foreach ((array)($p[$feld] ?? []) as $name => $v) if (is_string($name) && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) $job[$ziel][$name] = $kurz($v, $max);
        }
        return $job;
    }

    /** Speichern; danach Anlage neu anwenden (Tasten stehen auch im Wählplan) und Ansagen erzeugen, wenn eine Sprachausgabe bereitsteht. */
    public static function sprachmenueSchreiben(array $p, \KienzleModul $k): string {
        $r = self::python('sprachmenue-schreiben', self::sprachmenueAuftrag($p));
        if (($r['ok'] ?? false) !== true) throw new \InvalidArgumentException((string)($r['fehler'] ?? 'Die Angaben sind ungültig.'));
        if (!($r['geaendert'] ?? false)) return 'Keine Änderung.';
        self::anwenden($k);
        if (!self::ttsBereit()) return 'Gespeichert. Die Ansagen werden erzeugt, sobald die Sprachausgabe eingerichtet ist (Einstellungen → Sprachmenü).';
        try {
            self::auftrag('erzeugen');
            return 'Gespeichert. Die geänderten Ansagen werden jetzt erzeugt.';
        } catch (\InvalidArgumentException) {
            return 'Gespeichert. Ein anderer Auftrag läuft noch; danach bitte „Geänderte Ansagen erzeugen“ wählen.';
        }
    }

    /** Pfad einer erzeugten Ansage zum Anhören (WAV aus ansagen-master), sonst null. */
    public static function ansageDatei(string $name): ?string {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) return null;
        $f = self::$data . '/kienzlefon/ansagen-master/' . $name . '.wav';
        return is_file($f) && !is_link($f) ? $f : null;
    }

    // ---- Wartemusik: Johannes Plachetka (im Modul, MIT) oder eigene Datei; umgewandelt von lib/anlage.py ------------
    /** Höchstgröße einer hochgeladenen Datei (PHP der Module: post_max_size 8M). */
    public const WARTEMUSIK_MAX = 7 * 1024 * 1024;

    /** Eingerichtete Musik: ['bereit' => bool, 'quelle' => plachetka|eigene|'', 'dauer' => Sekunden]. */
    public static function wartemusik(): array {
        $dir = self::$data . '/kienzlefon/wartemusik';
        $info = json_decode((string)@file_get_contents("$dir/quelle.json"), true);
        $bereit = is_file("$dir/wartemusik.wav16") && is_file("$dir/wartemusik.g722");
        return ['bereit' => $bereit, 'quelle' => $bereit ? (string)($info['quelle'] ?? '') : '', 'dauer' => (int)($info['dauer'] ?? 0)];
    }

    /** Wahl auf der Seite: klingelzeichen, plachetka, eigene (bereits hochgeladen) oder entfernen. Liefert die Meldung. */
    public static function wartemusikWaehlen(array $cfg, string $wahl, \KienzleModul $k): string {
        $stand = self::wartemusik();
        if ($wahl === 'plachetka' && ($stand['quelle'] !== 'plachetka')) {
            $r = self::python('wartemusik', ['quelle' => 'plachetka']);
            if (($r['ok'] ?? false) !== true) throw new \InvalidArgumentException((string)($r['fehler'] ?? 'Die Musik ließ sich nicht einrichten.'));
        } elseif ($wahl === 'eigene' && $stand['quelle'] !== 'eigene') {
            throw new \InvalidArgumentException('Bitte zuerst eine eigene Musikdatei hochladen.');
        } elseif ($wahl === 'entfernen') {
            self::python('wartemusik-entfernen', null);
        } elseif (!in_array($wahl, ['klingelzeichen', 'plachetka', 'eigene'], true)) {
            throw new \InvalidArgumentException('Bitte Klingelzeichen oder Musik wählen.');
        }
        $cfg['kienzlefon']['wartemusik'] = in_array($wahl, ['plachetka', 'eigene'], true) ? 'musik' : 'klingelzeichen';
        $r = self::speichern($cfg, $k);
        return match ($wahl) { 'klingelzeichen' => 'Anrufer in der Warteschlange hören wieder das Klingelzeichen.', 'entfernen' => 'Wartemusik gelöscht; es klingelt wieder.',
            default => 'Wartemusik eingeschaltet.' } . (!($r['laeuft'] ?? false) ? ' Hinweis: Die Telefonanlage läuft gerade nicht.' : '');
    }

    /** Eigene Musikdatei (roh aus dem Anfragekörper): grob am Dateikopf prüfen, umwandeln lassen und einschalten. */
    public static function wartemusikHochladen(array $cfg, string $bytes, \KienzleModul $k): string {
        if ($bytes === '') throw new \InvalidArgumentException('Bitte zuerst eine Musikdatei auswählen.');
        if (strlen($bytes) > self::WARTEMUSIK_MAX) throw new \InvalidArgumentException('Die Datei darf höchstens 7 MB groß sein (als MP3 etwa 3 bis 6 Minuten).');
        $kopf = substr($bytes, 0, 12);
        $musik = str_starts_with($kopf, 'ID3') || (strlen($kopf) > 1 && ord($kopf[0]) === 0xFF && (ord($kopf[1]) & 0xE0) === 0xE0)
            || (str_starts_with($kopf, 'RIFF') && substr($kopf, 8, 4) === 'WAVE') || str_starts_with($kopf, 'OggS') || str_starts_with($kopf, 'fLaC') || substr($kopf, 4, 4) === 'ftyp';
        if (!$musik) throw new \InvalidArgumentException('Bitte eine Musikdatei wählen (MP3, WAV, OGG, FLAC oder M4A).');
        $dir = self::$data . '/kienzlefon';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $tmp = "$dir/.wartemusik-upload." . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $bytes) !== strlen($bytes)) { @unlink($tmp); throw new \RuntimeException('Die Datei ließ sich nicht speichern.'); }
        chmod($tmp, 0600);
        rename($tmp, "$dir/wartemusik-upload");
        $r = self::python('wartemusik', ['quelle' => 'eigene']);
        if (($r['ok'] ?? false) !== true) throw new \InvalidArgumentException((string)($r['fehler'] ?? 'Die Musik ließ sich nicht umwandeln.'));
        $cfg['kienzlefon']['wartemusik'] = 'musik';
        self::speichern($cfg, $k);
        return 'Eigene Wartemusik eingerichtet und eingeschaltet.';
    }

    /** „Whisper einrichten“: Wahl der Modelle merken (genauer = large-v3 für Namen); der Auftrag lädt danach. */
    public static function whisperWahl(array $cfg, array $p): array {
        if (!($cfg['kienzlefon']['aktiv'] ?? false)) throw new \InvalidArgumentException('Erst das Sprachmenü einschalten.');
        $cfg['kienzlefon']['whisper_genau'] = !empty($p['genau']);
        return $cfg;
    }

    // ---- Ansagen und Piper (optional nachgeladen, lib/ansagen-auftrag über kienzle-telefon-ansagen.path) ------------
    public const AUFTRAEGE = ['piper-einrichten', 'piper-entfernen', 'kokoro-einrichten', 'kokoro-entfernen', 'erzeugen', 'whisper-einrichten', 'whisper-entfernen'];

    /** Auftrag für den Ansagen-Dienst ablegen (einer zur Zeit). */
    public static function auftrag(string $aktion, bool $alle = false): void {
        if (!in_array($aktion, self::AUFTRAEGE, true)) throw new \InvalidArgumentException('Unbekannter Auftrag.');
        $dir = self::$data . '/kienzlefon';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $st = self::auftragStatus();
        if (is_file("$dir/auftrag.json") || ($st['stand'] ?? '') === 'laeuft' && (int)($st['zeit'] ?? 0) > time() - 3600) throw new \InvalidArgumentException('Ein Auftrag läuft noch. Bitte warten.');
        if ($aktion === 'erzeugen' && !self::ttsBereit()) throw new \InvalidArgumentException(match (self::laden()['kienzlefon']['tts'] ?? 'piper') {
            'google' => 'Erst den Google-Schlüssel eintragen und speichern.', 'kokoro' => 'Erst Kokoro einrichten.', default => 'Erst Piper einrichten.' });
        $tmp = "$dir/.auftrag." . bin2hex(random_bytes(4));
        file_put_contents($tmp, (string)json_encode(['aktion' => $aktion, 'alle' => $alle]));
        chmod($tmp, 0600);
        rename($tmp, "$dir/auftrag.json");
    }

    public static function auftragStatus(): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/kienzlefon/auftrag-status.json'), true);
        $d = is_array($d) ? $d : [];
        if (is_file(self::$data . '/kienzlefon/auftrag.json')) $d = ['aktion' => '', 'stand' => 'wartet', 'meldung' => 'Auftrag wartet auf den Dienst …', 'zeit' => time()];
        return $d;
    }

    public static function whisperBereit(): bool { return is_file(self::$data . '/whisper/bereit.json'); }

    /** Läuft der Whisper-Worker (Herzschlag von Kienzlefon höchstens 30 s alt)? */
    public static function whisperLaeuft(): bool {
        $f = self::$data . '/kienzlefon/run/whisper-health.json';
        return is_file($f) && (int)@filemtime($f) > time() - 30;
    }

    public static function piperBereit(): bool { return is_file(self::$data . '/piper/bereit.json'); }

    public static function kokoroBereit(): bool { return is_file(self::$data . '/kienzlefon/kokoro/kienzlefon-kokoro-tts-generate'); }

    public static function googleBereit(): bool { return is_file(self::$data . '/kienzlefon/google-tts.conf'); }

    /** Ist die gewählte Sprachausgabe einsatzbereit (Piper/Kokoro geladen, Google mit Schlüssel)? */
    public static function ttsBereit(): bool {
        return match (self::laden()['kienzlefon']['tts'] ?? 'piper') { 'google' => self::googleBereit(), 'kokoro' => self::kokoroBereit(), default => self::piperBereit() };
    }

    public static function ansagenAnzahl(): int { return count(glob(self::$data . '/kienzlefon/ansagen/*.g722') ?: []); }

    public static function netzSpeichern(array $cfg, array $p): array {
        $cfg['netz'] = ['lokal' => trim((string)($p['lokal'] ?? '')), 'erlaubt' => trim((string)($p['erlaubt'] ?? '')), 'extern' => trim((string)($p['extern'] ?? ''))];
        return $cfg;
    }

    public static function neuesPasswort(): string {
        $z = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $o = '';
        for ($i = 0; $i < 24; $i++) $o .= $z[random_int(0, strlen($z) - 1)];
        return $o;
    }

    /** Neue Nebenstellen anlegen: die nächsten freien Nummern ab 201 (wie Kienzlefax). */
    public static function nebenstellenAnlegen(array $cfg, int $anzahl, \KienzleModul $k): array {
        if ($anzahl < 1 || $anzahl > 20) throw new \InvalidArgumentException('Bitte 1 bis 20 Telefone auf einmal anlegen.');
        if (count($cfg['nebenstellen']) + $anzahl > 100) throw new \InvalidArgumentException('Höchstens 100 Nebenstellen.');
        $belegt = array_column($cfg['nebenstellen'], 'nummer');
        $n = 201;
        for ($i = 0; $i < $anzahl; $i++) {
            while (in_array((string)$n, $belegt, true)) $n++;
            $belegt[] = (string)$n;
            $cfg['nebenstellen'][] = ['nummer' => (string)$n, 'name' => 'Telefon ' . $n, 'passwort' => $k->verschluesseln(self::neuesPasswort(), 'nebenstelle'), 'warteschlange' => true];
        }
        return $cfg;
    }

    public static function nebenstelleSpeichern(array $cfg, array $p): array {
        $nr = (string)($p['nummer'] ?? '');
        foreach ($cfg['nebenstellen'] as $i => $n) {
            if ($n['nummer'] !== $nr) continue;
            $cfg['nebenstellen'][$i]['name'] = trim((string)($p['name'] ?? ''));
            $cfg['nebenstellen'][$i]['warteschlange'] = !empty($p['warteschlange']);
            return $cfg;
        }
        throw new \InvalidArgumentException('Nebenstelle unbekannt.');
    }

    /** MAC-Adresse eines Telefons eintragen (nur Admins: wer sie kennt, bekommt die Zugangsdaten des Telefons). Leer = keine automatische Einrichtung. */
    public static function nebenstelleMac(array $cfg, array $p): array {
        $nr = (string)($p['nummer'] ?? '');
        foreach ($cfg['nebenstellen'] as $i => $n) {
            if ($n['nummer'] !== $nr) continue;
            $mac = trim((string)($p['mac'] ?? ''));
            $roh = strtolower(preg_replace('/[\s:.\-]/', '', $mac) ?? '');
            $cfg['nebenstellen'][$i]['mac'] = preg_match('/^[0-9a-f]{12}$/D', $roh) ? $roh : $mac;
            return $cfg;
        }
        throw new \InvalidArgumentException('Nebenstelle unbekannt.');
    }

    /** Einrichtung der Telefone ein- oder ausschalten; beim ersten Einschalten entsteht der Schlüssel für die Adresse. */
    public static function provisionierungSpeichern(array $cfg, array $p): array {
        $aktiv = !empty($p['aktiv']);
        $schluessel = (string)($cfg['provisionierung']['schluessel'] ?? '');
        if ($aktiv && !preg_match('/^[0-9a-f]{32}$/D', $schluessel)) $schluessel = bin2hex(random_bytes(16));
        $cfg['provisionierung'] = ['aktiv' => $aktiv, 'schluessel' => $schluessel];
        return $cfg;
    }

    /** Neuer Schlüssel: die bisherige Adresse gilt nicht mehr, die Telefone müssen die neue Adresse bekommen. */
    public static function provisionierungSchluesselNeu(array $cfg): array {
        $cfg['provisionierung'] = ['aktiv' => (bool)($cfg['provisionierung']['aktiv'] ?? false), 'schluessel' => bin2hex(random_bytes(16))];
        return $cfg;
    }

    public static function nebenstelleLoeschen(array $cfg, string $nr): array {
        $cfg['nebenstellen'] = array_values(array_filter($cfg['nebenstellen'], fn($n) => $n['nummer'] !== $nr));
        return $cfg;
    }

    public static function nebenstellePasswortNeu(array $cfg, string $nr, \KienzleModul $k): array {
        foreach ($cfg['nebenstellen'] as $i => $n) {
            if ($n['nummer'] === $nr) { $cfg['nebenstellen'][$i]['passwort'] = $k->verschluesseln(self::neuesPasswort(), 'nebenstelle'); return $cfg; }
        }
        throw new \InvalidArgumentException('Nebenstelle unbekannt.');
    }

    public static function nebenstellePasswort(array $cfg, string $nr, \KienzleModul $k): string {
        foreach ($cfg['nebenstellen'] as $n) if ($n['nummer'] === $nr) return self::offen((string)$n['passwort'], $k, 'nebenstelle');
        throw new \InvalidArgumentException('Nebenstelle unbekannt.');
    }

    /** Einrichtungs-Überblick für die Basis. */
    public static function ueberblick(?array $status = null): array {
        $cfg = self::laden();
        $base = '/telefon/einstellungen';
        $items = [];
        $items[] = ['title' => 'Leitung des Telefonanbieters eingetragen', 'state' => $cfg['leitungen'] ? 'done' : 'open', 'hint' => $cfg['leitungen'] ? '' : 'Unter Einstellungen → Leitungen', 'link' => $base . '#leitungen'];
        $zweck = $cfg['telefon']['leitung_ein'] !== '' || $cfg['fax']['leitung'] !== '';
        $items[] = ['title' => 'Rufnummern für Telefon oder Fax zugeordnet', 'state' => $zweck ? 'done' : 'open', 'hint' => $zweck ? '' : 'Unter Einstellungen → Rufnummern', 'link' => $base . '#rufnummern'];
        $items[] = ['title' => 'Telefone (Nebenstellen) angelegt', 'state' => $cfg['nebenstellen'] ? 'done' : 'open', 'hint' => $cfg['nebenstellen'] ? '' : 'Unter Einstellungen → Telefone', 'link' => $base . '#telefone'];
        if ($status !== null && $cfg['leitungen']) {
            $nicht = array_filter($cfg['leitungen'], fn($l) => ($status['leitungen'][$l['id']] ?? '') !== 'Registered' && $l['anbieter'] !== 'andere');
            $items[] = ['title' => 'Leitungen beim Anbieter angemeldet', 'state' => !($status['laeuft'] ?? false) || $nicht ? 'error' : 'done',
                'hint' => !($status['laeuft'] ?? false) ? 'Die Telefonanlage läuft nicht.' : ($nicht ? 'Nicht angemeldet: ' . implode(', ', array_map(fn($l) => $l['name'] ?: $l['id'], $nicht)) : ''), 'link' => '/telefon/'];
        }
        return $items;
    }

    // ---- Aufruf des Generators -----------------------------------------------------------------------------------
    public static function python(string $aktion, ?array $eingabe): array {
        if (self::$runner) return (self::$runner)($aktion, $eingabe);
        $script = dirname(__DIR__) . '/lib/anlage.py';
        $env = ['KIENZLE_DATEN' => self::$data, 'PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8'];
        foreach (['KIENZLE_ASTERISK', 'KIENZLE_PROGRAMM', 'KIENZLE_AUSTAUSCH'] as $v) if (getenv($v) !== false) $env[$v] = (string)getenv($v);   // nur Tests und Haken
        $p = proc_open(['/usr/bin/python3', '-B', $script, $aktion], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::$data, $env);
        if (!is_resource($p)) throw new \RuntimeException('Die Telefonanlage ist nicht erreichbar.');
        fwrite($pipes[0], $eingabe === null ? '' : (string)json_encode($eingabe, JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 60);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        $d = json_decode($out, true);
        if (!is_array($d)) {
            error_log('telefon anlage.py ' . $aktion . ': Rückgabe ' . $code . ' ' . mb_substr($err, 0, 300));
            throw new \RuntimeException('Die Telefonanlage hat nicht geantwortet.');
        }
        return $d;
    }
}
