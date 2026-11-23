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
    private const SIZES = ['1x1' => 'Normal (1×1)', '1x2' => 'Hoch (1×2)', '2x1' => 'Breit (2×1)', '2x2' => 'Groß (2×2)', '2x3' => 'Groß und hoch (2×3)', '3x3' => 'Sehr groß (3×3)', '4x4' => 'Riesig (4×4)'];
    /** Erklärungen als Tooltip neben der Beschriftung. */
    public const HILFE = [
        'qr_summary' => 'Dieser Text steht im Kalendereintrag, der beim Scannen des QR-Codes entsteht, vor dem Termintyp (z. B. „Termin Arztpraxis: Lufu“). Keine Patientennamen eintragen.',
    ];
    /** Formularfelder: Typ, Beschriftung, Vorgabe (und Auswahl). */
    public static function schema(): array {
        return [
            'enabled' => ['bool', 'Terminzettel aus T2med auf dem Bondrucker drucken', true],
            'header_text' => ['text', 'Kopf: erste Zeile Praxisname, darunter Adressbereich (leer = keiner)', ''],
            'header_first_size' => ['choice', 'Erste Zeile (Praxisname): Schriftgröße', '2x2', ['same' => 'Wie der Adressbereich'] + self::SIZES],
            'header_first_bold' => ['choice', 'Erste Zeile (Praxisname): fett', 'same', ['same' => 'Wie der Adressbereich', 'yes' => 'Fett', 'no' => 'Nicht fett']],
            'header_size' => ['choice', 'Adressbereich (übrige Zeilen): Schriftgröße', '1x1', self::SIZES],
            'header_bold' => ['bool', 'Adressbereich (übrige Zeilen): fett', true],
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
            'cut' => ['bool', 'Bon abschneiden', true],
        ];
    }

    /** Kopf aus den Praxisdaten der Basis: Name, dann Straße, Ort, Kontakt (nur ausgefüllte Zeilen). */
    public static function kopfAusPraxis(array $praxis): string {
        $zeilen = [];
        foreach (['name', 'street', 'city', 'contact'] as $k) {
            $z = trim(preg_replace('/[\x00-\x1f\x7f-\x9f]+/u', ' ', (string)($praxis[$k] ?? '')) ?? '');
            if ($z !== '') $zeilen[] = $z;
        }
        return mb_substr(implode("\n", $zeilen), 0, 1000);
    }

    /** Gespeicherte Werte, ergänzt um die Vorgaben; der Kopf ist mit den Praxisdaten der Basis vorbelegt, solange er nicht gespeichert wurde. */
    public static function werte(array $praxis = []): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/werte.json'), true);
        $out = [];
        foreach (self::schema() as $k => $f) $out[$k] = is_array($d) && array_key_exists($k, $d) && self::passt($f, $d[$k]) ? $d[$k] : $f[2];
        if (!is_array($d) || !array_key_exists('header_text', $d)) $out['header_text'] = self::kopfAusPraxis($praxis);
        return $out;
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
        return $out;
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
            'header_text' => ['header', 'text'], 'header_size' => ['header', 'size'], 'header_bold' => ['header', 'bold'],
            'heading' => ['heading', 'text'], 'heading_size' => ['heading', 'size'],
            'show_patient' => ['appointments', 'show_patient'], 'appointment_size' => ['appointments', 'size'],
            'footer_text' => ['footer', 'text'], 'footer_size' => ['footer', 'size'],
            'qr_enabled' => ['qr', 'enabled'], 'qr_position' => ['qr', 'position'], 'qr_summary' => ['qr', 'summary'],
        ];
        foreach ($map as $key => [$block, $field]) $o[$block][$field] = $values[$key];
        if ($values['header_first_size'] !== 'same') $o['header']['first_size'] = $values['header_first_size'];
        if (in_array($values['header_first_bold'], ['yes', 'no'], true)) $o['header']['first_bold'] = $values['header_first_bold'] === 'yes';
        $o['header']['enabled'] = trim($values['header_text']) !== '';
        $o['footer']['enabled'] = trim($values['footer_text']) !== '';
        $o['qr']['width_dots'] = self::QR_SIZES[$values['qr_size']];
        return $o;
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

    private static function schreiben(string $name, array $data): void {
        if (!is_dir(self::$data)) @mkdir(self::$data, 0700, true);
        $path = self::$data . '/' . $name;
        $tmp = $path . '.' . bin2hex(random_bytes(4));
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
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
