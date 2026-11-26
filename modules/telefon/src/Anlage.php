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
            'kienzlefon' => ['aktiv' => false, 'tts' => 'piper']];
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
        $r = self::python('anwenden', self::klartext($cfg ?? self::laden(), $k));
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
    public static function sprachmenueSpeichern(array $cfg, array $p): array {
        $tts = (string)($p['tts'] ?? 'piper');
        if (!isset(self::SPRACHAUSGABE[$tts])) throw new \InvalidArgumentException('Bitte eine Sprachausgabe wählen.');
        $cfg['kienzlefon'] = ['aktiv' => !empty($p['aktiv']), 'tts' => $tts];
        return $cfg;
    }

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
