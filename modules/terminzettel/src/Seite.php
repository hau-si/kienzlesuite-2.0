<?php
declare(strict_types=1);
namespace KienzleTerminzettel;

/**
 * Einstellungsseite des Moduls terminzettel: Layout des Bons mit Vorschau. Anmeldung, Rolle und Rechte kommen von der
 * Basis (KienzleModul). Gespeichert werden die Formularwerte (werte.json) und das daraus gebildete Layout für den
 * Druckdienst (layout.json); der Bondrucker kommt aus Verwaltung → Drucker und wird als basis.json abgelegt.
 */
final class Seite {
    public static string $data = '/var/lib/kienzle/terminzettel';
    public static string $helper = '/opt/kienzle/core/current/php/modul/Kienzle.php';
    public const QR_SIZES = ['small' => 224, 'medium' => 292, 'large' => 384];
    /** Logobreite in Druckpunkten (Papier 58 mm: 420 Punkte, 80 mm: 576). */
    public const LOGO_DOTS = ['small' => 160, 'medium' => 256, 'large' => 384];
    public const LOGO_SIZES = ['small' => 'Klein', 'medium' => 'Mittel', 'large' => 'Groß'];
    public const LOGO_ALIGNS = ['left' => 'Links', 'center' => 'Mitte', 'right' => 'Rechts'];
    public const LOGO_MAX_BYTES = 2097152;
    public const SIZES = ['1x1' => 'Normal (1×1)', '1x2' => 'Hoch (1×2)', '2x1' => 'Breit (2×1)', '2x2' => 'Groß (2×2)', '2x3' => 'Groß und hoch (2×3)', '3x3' => 'Sehr groß (3×3)', '4x4' => 'Riesig (4×4)'];
    /** Erklärungen als Tooltip neben der Beschriftung. */
    public const HILFE = [
        'qr_summary' => 'Dieser Text steht im Kalendereintrag, der beim Scannen des QR-Codes entsteht, vor dem Termintyp (z. B. „Termin Arztpraxis: Lufu“). Keine Patientennamen eintragen.',
    ];
    /** Formularfelder: Typ, Beschriftung, Vorgabe (und Auswahl). */
    public static function schema(): array {
        return [
            'enabled' => ['bool', 'Terminzettel aus T2med auf dem Bondrucker drucken', true],
            'logo_enabled' => ['bool', 'Logo drucken (wenn eines hochgeladen ist)', true],
            'logo_size' => ['choice', 'Logo: Breite', 'medium', self::LOGO_SIZES],
            'logo_align' => ['choice', 'Logo: Ausrichtung', 'center', self::LOGO_ALIGNS],
            'name_text' => ['string', 'Praxisname (erste Zeile)', ''],
            'name_size' => ['choice', 'Praxisname: Schriftgröße', '2x2', self::SIZES],
            'name_bold' => ['bool', 'Praxisname: fett', true],
            'address_text' => ['text', 'Adressblock (Straße, Ort, Kontakt; leer = keiner)', ''],
            'address_size' => ['choice', 'Adressblock: Schriftgröße', '1x1', self::SIZES],
            'address_bold' => ['bool', 'Adressblock: fett', true],
            'heading' => ['string', 'Überschrift', 'IHRE TERMINE'],
            'heading_size' => ['choice', 'Schriftgröße Überschrift', '2x2', self::SIZES],
            'show_patient' => ['bool', 'Patientennamen drucken', true],
            'appointment_size' => ['choice', 'Schriftgröße Termine', '2x2', self::SIZES],
            'footer_text' => ['text', 'Endtext (leer = keiner)', 'Können Sie einen Termin nicht wahrnehmen, sagen Sie bitte unbedingt Bescheid.'],
            'footer_size' => ['choice', 'Schriftgröße Endtext', '1x1', self::SIZES],
            'qr_enabled' => ['bool', 'Kalender-QR je Termin drucken', true],
            'qr_position' => ['choice', 'Position des QR-Codes', 'below', ['right' => 'Rechts neben dem Termin', 'left' => 'Links neben dem Termin', 'below' => 'Unter dem Termin (mittig)']],
            'qr_size' => ['choice', 'Größe des QR-Codes', 'medium', ['small' => 'Klein', 'medium' => 'Mittel', 'large' => 'Groß']],
            'qr_summary' => ['string', 'Kalender-Titel (Präfix)', 'Termin Arztpraxis'],
            'cancel_enabled' => ['bool', 'Absage-QR unten auf dem Bon drucken', false],
            'cancel_to' => ['string', 'Empfänger (E-Mail-Adresse der Praxis)', ''],
            'cancel_subject' => ['string', 'Betreff', 'Terminabsage'],
            'cancel_body' => ['text', 'Nachrichtentext', "Guten Tag,\n\nich kann meinen Termin leider nicht wahrnehmen und sage ihn hiermit ab.\n\nMit freundlichen Grüßen"],
            'cancel_caption' => ['string', 'Aufforderung im Balken über dem QR-Code', 'Verhindert? Bitte absagen.'],
            'cancel_size' => ['choice', 'Größe des Absage-QR-Codes', 'medium', ['small' => 'Klein', 'medium' => 'Mittel', 'large' => 'Groß']],
            'cut' => ['bool', 'Bon abschneiden', true],
        ];
    }

    /** Praxisname und Adressblock aus den Praxisdaten der Basis: Name, dann Straße, Ort, Kontakt (nur ausgefüllte Zeilen). @return array{name:string,address:string} */
    public static function kopfAusPraxis(array $praxis): array {
        $z = static fn(string $k): string => trim(preg_replace('/[\x00-\x1f\x7f-\x9f]+/u', ' ', (string)($praxis[$k] ?? '')) ?? '');
        $adresse = array_values(array_filter([$z('street'), $z('city'), $z('contact')], static fn($t) => $t !== ''));
        return ['name' => mb_substr($z('name'), 0, 80), 'address' => mb_substr(implode("\n", $adresse), 0, 1000)];
    }

    /** Gespeicherte Werte, ergänzt um die Vorgaben. Name und Adresse sind mit den Praxisdaten der Basis vorbelegt, solange nichts gespeichert wurde; der alte gemeinsame Kopf (header_text) wird aufgeteilt. */
    public static function werte(array $praxis = []): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/werte.json'), true);
        if (is_array($d) && !array_key_exists('name_text', $d) && !array_key_exists('address_text', $d) && is_string($d['header_text'] ?? null)) $d = self::ausAltemKopf($d);
        $out = [];
        foreach (self::schema() as $k => $f) $out[$k] = is_array($d) && array_key_exists($k, $d) && self::passt($f, $d[$k]) ? $d[$k] : $f[2];
        if (($out['cancel_caption'] ?? '') === 'Termin absagen: Code mit dem Handy scannen') $out['cancel_caption'] = 'Verhindert? Bitte absagen.';   // alter Standardtext
        $out['enabled'] = true; // Bondruck ist mit dem Modul immer aktiv
        if (!is_array($d) || (!array_key_exists('name_text', $d) && !array_key_exists('address_text', $d))) {
            $kopf = self::kopfAusPraxis($praxis);
            $out['name_text'] = $kopf['name'];
            $out['address_text'] = $kopf['address'];
        }
        return $out;
    }

    /** Älteres Format (ein Textfeld „Kopf“ mit Praxisname in der ersten Zeile) in Name und Adressblock zerlegen. */
    private static function ausAltemKopf(array $d): array {
        $zeilen = explode("\n", trim(str_replace("\r", '', $d['header_text']), "\n"));
        $name = trim((string)array_shift($zeilen));
        $d['name_text'] = mb_substr($name, 0, 80);
        $d['address_text'] = trim(implode("\n", $zeilen), "\n ");
        $adresseGross = is_string($d['header_size'] ?? null) && isset(self::SIZES[$d['header_size']]) ? $d['header_size'] : '1x1';
        $d['address_size'] = $adresseGross;
        $d['address_bold'] = is_bool($d['header_bold'] ?? null) ? $d['header_bold'] : true;
        $d['name_size'] = is_string($d['header_first_size'] ?? null) && isset(self::SIZES[$d['header_first_size']]) ? $d['header_first_size'] : $adresseGross;
        $fett = $d['header_first_bold'] ?? 'same';
        $d['name_bold'] = $fett === 'yes' ? true : ($fett === 'no' ? false : $d['address_bold']);
        return $d;
    }

    private static function passt(array $f, mixed $v): bool {
        return match ($f[0]) {
            'bool' => is_bool($v),
            'choice' => is_string($v) && isset($f[3][$v]),
            default => is_string($v) && mb_strlen($v) <= 1000,
        };
    }

    /** Eingabe aus dem Formular prüfen. @throws \InvalidArgumentException mit Meldung für den Benutzer */
    public static function pruefen(array $in): array {
        $out = [];
        foreach (self::schema() as $k => $f) {
            $raw = $in[$k] ?? null;
            if ($k === 'enabled') { $out[$k] = true; continue; }
            if ($f[0] === 'bool') { $out[$k] = $raw === '1' || $raw === true || $raw === 'on'; continue; }
            $v = is_string($raw) ? str_replace(["\r\n", "\r"], "\n", $raw) : '';
            $v = trim($v, "\n ");
            if ($f[0] === 'choice') {
                if (!isset($f[3][$v])) throw new \InvalidArgumentException($f[1] . ': ungültige Auswahl.');
            } else {
                $max = $f[0] === 'text' ? 1000 : 80;
                if (mb_strlen($v) > $max || !mb_check_encoding($v, 'UTF-8') || preg_match($f[0] === 'text' ? '/[\x00-\x09\x0b-\x1f\x7f-\x9f]/u' : '/[\x00-\x1f\x7f-\x9f]/u', $v)) {
                    throw new \InvalidArgumentException($f[1] . ': höchstens ' . $max . ' Zeichen, nur normaler Text.');
                }
            }
            $out[$k] = $v;
        }
        if ($out['cancel_enabled'] && !preg_match('/^[^\s@<>,;]{1,64}@[^\s@<>,;]{1,60}\.[^\s@<>,;]{2,20}$/D', $out['cancel_to'])) {
            throw new \InvalidArgumentException('Absage-QR: Bitte eine gültige E-Mail-Adresse als Empfänger eintragen.');
        }
        if (mb_strlen($out['cancel_body']) > 400) throw new \InvalidArgumentException('Absage-QR: Der Nachrichtentext darf höchstens 400 Zeichen haben (sonst wird der QR-Code zu dicht).');
        return $out;
    }

    /** Zeilen des letzten Auftrags (ab „Auftrag erhalten“ bis zum Ende) mit den Zeiten je Schritt, jede Zeit in einer eigenen Zeile. */
    public static function letzterAuftrag(): array {
        return self::druckauftraege()['letzter'];
    }

    /**
     * Druckprotokoll für die Seite: der letzte Auftrag (Zeiten je Schritt in eigenen Zeilen) und davor bis zu $frueher Zeilen
     * der vorherigen Aufträge. Ohne erkannten Auftrag stehen die letzten Zeilen unter „frueher“.
     * @return array{letzter:list<string>,frueher:list<string>}
     */
    public static function druckauftraege(int $frueher = 12): array {
        $all = self::protokoll(40 + $frueher);
        $start = null;
        foreach ($all as $i => $l) if (str_contains($l, 'Auftrag erhalten')) $start = $i;
        if ($start === null) return ['letzter' => [], 'frueher' => array_slice($all, -$frueher)];
        $out = [];
        foreach (array_slice($all, $start) as $l) {
            if (($pos = strpos($l, 'Zeiten: ')) !== false) {
                $out[] = substr($l, 0, $pos) . 'Zeiten:';
                foreach (explode(' · ', substr($l, $pos + 8)) as $z) $out[] = '    ' . $z;
            } else $out[] = $l;
        }
        return ['letzter' => $out, 'frueher' => array_slice(array_slice($all, 0, $start), -$frueher)];
    }

    /** Die letzten Zeilen des Druckprotokolls (Zeit, Art des Auftrags, Ergebnis; keine Patientendaten). */
    public static function protokoll(int $zeilen = 12): array {
        $text = (string)@file_get_contents(self::$data . '/druckprotokoll.txt', false, null, 0, 20000);
        $all = $text === '' ? [] : array_values(array_filter(explode("\n", $text), static fn($l) => $l !== ''));
        return array_slice($all, -$zeilen);
    }

    /**
     * Einrichtungs-Überblick für die Suite (docs/MODULE-TOML.md, [setup] overview). Gelesen werden nur die abgelegten Dateien:
     * basis.json (Bondrucker, Praxisdaten) und das Druckprotokoll (ein Terminzettel aus T2med ist angekommen).
     * @return list<array{title:string,state:string,hint:string,link:string,optional?:bool}>
     */
    public static function ueberblick(): array {
        $basis = json_decode((string)@file_get_contents(self::$data . '/basis.json'), true);
        $bon = is_array($basis) ? (string)($basis['drucker']['bon'] ?? '') : '';
        $werte = self::werte(is_array($basis) ? (array)($basis['praxis'] ?? []) : []);
        $name = trim($werte['name_text']) !== '';
        $angekommen = array_filter(self::protokoll(50), static fn($l) => str_contains($l, 'Bon an die Warteschlange')) !== [];
        return [
            ['title' => 'Bondrucker für den Terminzettel zugeordnet', 'state' => $bon !== '' ? 'done' : 'open',
                'hint' => 'Verwaltung → Drucker → „Wofür welcher Drucker?“ (Bondrucker wählen)', 'link' => '/terminzettel/'],
            ['title' => 'Terminzettel: Praxisname im Kopf', 'state' => $name ? 'done' : 'open', 'optional' => true,
                'hint' => 'Einstellungen → Kopf: Praxisname, Adresse und Logo für den Bon', 'link' => '/terminzettel/#kopf'],
            ['title' => 'Terminzettel aus T2med am Bondrucker angekommen', 'state' => $angekommen ? 'done' : 'open',
                'hint' => 'Am Arbeitsplatz den Drucker „vTermine“ einrichten (Anleitung: Arbeitsplatz einrichten → Virtuelle Drucker) und in T2med unter Einstellungen → Kalender → Terminliste (DIN A6) wählen; dann einen Terminzettel drucken',
                'link' => '/terminzettel/#drucker'],
        ];
    }

    /** Papierbreite des Bondruckers in mm (58 oder 80) laut Basis (Verwaltung → Drucker), aus basis.json. */
    public static function bonBreite(): int {
        $d = json_decode((string)@file_get_contents(self::$data . '/basis.json'), true);
        return is_array($d) && ($d['bonbreite'] ?? null) === 80 ? 80 : 58;
    }
    public static function modell(): string { return self::bonBreite() === 80 ? 'tm-m30iii' : 'tm-m10'; }

    /** Layout im Format von lib/terminzettel.py (apply_overlay). Der Drucker steht nicht darin, er kommt aus basis.json. */
    public static function overlay(array $values): array {
        $o = ['enabled' => $values['enabled'], 'model' => self::modell(), 'cut' => $values['cut']];
        $map = [
            'heading' => ['heading', 'text'], 'heading_size' => ['heading', 'size'],
            'show_patient' => ['appointments', 'show_patient'], 'appointment_size' => ['appointments', 'size'],
            'footer_text' => ['footer', 'text'], 'footer_size' => ['footer', 'size'],
            'qr_enabled' => ['qr', 'enabled'], 'qr_position' => ['qr', 'position'], 'qr_summary' => ['qr', 'summary'],
        ];
        foreach ($map as $key => [$block, $field]) $o[$block][$field] = $values[$key];
        // Kopf für den Druckdienst: erste Zeile = Praxisname (eigene Größe, eigenes Fett), darunter der Adressblock.
        $name = trim($values['name_text']);
        $adresse = trim($values['address_text'], "\n ");
        $o['header'] = ['text' => $name !== '' && $adresse !== '' ? $name . "\n" . $adresse : $name . $adresse, 'size' => $values['address_size'], 'bold' => $values['address_bold']];
        if ($name !== '') { $o['header']['first_size'] = $values['name_size']; $o['header']['first_bold'] = $values['name_bold']; }
        $o['header']['enabled'] = $name !== '' || $adresse !== '';
        $o['logo'] = ['enabled' => $values['logo_enabled'] && self::logoVorhanden(), 'width_dots' => self::LOGO_DOTS[$values['logo_size']], 'align' => $values['logo_align']];
        $o['cancel'] = ['enabled' => $values['cancel_enabled'], 'to' => trim($values['cancel_to']), 'subject' => $values['cancel_subject'], 'body' => $values['cancel_body'],
            'caption' => $values['cancel_caption'], 'width_dots' => self::QR_SIZES[$values['cancel_size']]];
        $o['footer']['enabled'] = trim($values['footer_text']) !== '';
        $o['qr']['width_dots'] = self::QR_SIZES[$values['qr_size']];
        return $o;
    }

    public static function logoPfad(): string { return self::$data . '/logo.png'; }
    public static function logoVorhanden(): bool { return is_file(self::logoPfad()); }

    /** Das gespeicherte Logo als data:-URL für die Anzeige auf der Einstellungsseite (leer ohne Logo). */
    public static function logoDaten(): string {
        $b = self::logoVorhanden() ? @file_get_contents(self::logoPfad(), false, null, 0, 600000) : false;
        return is_string($b) && $b !== '' ? 'data:image/png;base64,' . base64_encode($b) : '';
    }

    /** Hochgeladene Datei ($_FILES-Eintrag) lesen und grob prüfen. @throws \InvalidArgumentException */
    public static function uploadBytes(mixed $f, bool $echterUpload = true): string {
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new \InvalidArgumentException('Bitte zuerst ein Bild auswählen.');
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new \InvalidArgumentException('Das Logo darf höchstens 2 MB groß sein.');
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($f['error'] !== UPLOAD_ERR_OK || $tmp === '' || ($echterUpload && !is_uploaded_file($tmp))) throw new \InvalidArgumentException('Das Hochladen hat nicht geklappt. Bitte noch einmal versuchen.');
        $bytes = (string)@file_get_contents($tmp, false, null, 0, self::LOGO_MAX_BYTES + 1);
        if ($bytes === '' || strlen($bytes) > self::LOGO_MAX_BYTES) throw new \InvalidArgumentException('Das Logo darf höchstens 2 MB groß sein.');
        return $bytes;
    }

    /** Logo als rohe Anfrage (fetch, kein Formular-Upload: Modul-Dienste nehmen keine Multipart-Uploads an): Größe prüfen. */
    public static function rohBytes(string $bytes): string {
        if ($bytes === '') throw new \InvalidArgumentException('Bitte zuerst ein Bild auswählen.');
        if (strlen($bytes) > self::LOGO_MAX_BYTES) throw new \InvalidArgumentException('Das Logo darf höchstens 2 MB groß sein.');
        return $bytes;
    }

    /** Logo prüfen, verkleinern (lib/logo.py) und ablegen; das Layout für den Druckdienst wird neu geschrieben. @throws \InvalidArgumentException mit Meldung für den Benutzer */
    public static function logoSpeichern(string $bytes, ?callable $run = null): void {
        $script = dirname(__DIR__) . '/lib/logo.py';
        $r = $run ? $run($script, $bytes) : self::starten(['/usr/bin/python3', '-B', $script], $bytes);
        if ($r['code'] !== 0 || strncmp($r['out'], "\x89PNG", 4) !== 0) {
            $err = trim($r['stderr']);
            throw new \InvalidArgumentException($err !== '' ? substr($err, 0, 200) : 'Das Logo konnte nicht verarbeitet werden.');
        }
        self::schreiben('logo.png', $r['out'], true);
        self::layoutNeu();
    }

    public static function logoEntfernen(): void {
        @unlink(self::logoPfad());
        self::layoutNeu();
    }

    /** layout.json aus den gespeicherten Werten neu bilden (z. B. nach Logo-Änderung); ohne gespeicherte Werte nichts tun. */
    private static function layoutNeu(): void {
        if (is_file(self::$data . '/werte.json')) self::schreiben('layout.json', self::overlay(self::werte()));
    }

    public static function speichern(array $values): void {
        self::schreiben('werte.json', $values);
        self::schreiben('layout.json', self::overlay($values));
    }

    /** Bondrucker und Praxisdaten der Basis als basis.json übernehmen, damit der Druckdienst (ohne Web-Anfrage) sie kennt. */
    public static function syncBasis(\KienzleModul $k): void {
        $neu = ['drucker' => $k->druckerRollen(), 'praxis' => $k->praxis(), 'bonbreite' => $k->bonBreite()];
        $alt = json_decode((string)@file_get_contents(self::$data . '/basis.json'), true);
        if ($alt !== $neu) {
            self::schreiben('basis.json', $neu);
            // Andere Papierbreite: das gespeicherte Layout für den Druckdienst neu erzeugen.
            if (is_file(self::$data . '/werte.json')) self::schreiben('layout.json', self::overlay(self::werte($neu['praxis'])));
        }
        // Erster Besuch: Layout mit den Praxisdaten im Kopf anlegen, damit schon ohne Speichern gedruckt wird (danach frei änderbar).
        if (!is_file(self::$data . '/werte.json')) self::speichern(self::werte($neu['praxis']));
    }

    private static function schreiben(string $name, array|string $data, bool $roh = false): void {
        if (!is_dir(self::$data)) @mkdir(self::$data, 0700, true);
        $path = self::$data . '/' . $name;
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        $json = $roh ? (string)$data : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        if (file_put_contents($tmp, $json) === false || !chmod($tmp, 0600) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Einstellungen konnten nicht gespeichert werden.');
        }
    }

    /** Bon mit Beispieldaten: als PNG (Vorschau) oder ESC/POS (Testdruck). @return array{notes:array,data:string} */
    public static function beispiel(array $overlay, string $format = 'png', ?callable $run = null): array {
        $script = dirname(__DIR__) . '/lib/preview.py';
        $input = json_encode(['overlay' => $overlay, 'format' => $format], JSON_THROW_ON_ERROR);
        $r = $run ? $run($script, $input) : self::starten(['/usr/bin/python3', '-B', $script], $input);
        if ($r['code'] !== 0) {
            $err = trim($r['stderr']);
            if (str_contains($err, 'No module named')) $err = 'Für die Vorschau fehlen Python-Pakete (python3-pil, python3-qrcode). Bitte das Modul erneut installieren.';
            throw new \RuntimeException($err !== '' ? substr($err, 0, 300) : 'Vorschau fehlgeschlagen.');
        }
        $split = strpos($r['out'], "\n");
        if ($split === false) throw new \RuntimeException('Vorschau fehlgeschlagen.');
        $meta = json_decode(substr($r['out'], 0, $split), true);
        return ['notes' => is_array($meta['notes'] ?? null) ? $meta['notes'] : [], 'data' => substr($r['out'], $split + 1)];
    }

    /** @return array{code:int,out:string,stderr:string} */
    private static function starten(array $cmd, string $input): array {
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => '/usr/bin:/bin', 'LC_ALL' => 'C.UTF-8', 'HOME' => '/nonexistent']);
        if (!is_resource($p)) throw new \RuntimeException('Vorschau konnte nicht gestartet werden.');
        fwrite($pipes[0], $input); fclose($pipes[0]);
        stream_set_timeout($pipes[1], 30);
        $out = (string)stream_get_contents($pipes[1], 16777216); $err = (string)stream_get_contents($pipes[2], 65536);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'stderr' => $err];
    }

    /** Testdruck mit Beispieldaten auf den zugeordneten Bondrucker. */
    public static function testdruck(\KienzleModul $k, array $values): string {
        $queue = $k->druckerRollen()['bon'];
        if ($queue === '') throw new \InvalidArgumentException('Kein Bondrucker zugeordnet: Verwaltung → Drucker → „Wofür welcher Drucker?“.');
        $bytes = self::beispiel(self::overlay($values), 'escpos')['data'];
        return $k->drucken($queue, $bytes, 'Terminzettel Test', ['raw']);
    }
}
