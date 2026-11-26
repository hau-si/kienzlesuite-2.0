<?php
declare(strict_types=1);
namespace KienzleKasse;

/**
 * Liest Rechnungsnummer, Datum, Gesamtbetrag und Patient aus einer T2med-Liquidation.
 * Nur eindeutige Angaben werden akzeptiert; jede Abweichung führt zur Ablehnung,
 * damit nie ein falscher Betrag am Terminal erscheint.
 */
final class T2medInvoice {
    public const MAX_PDF = 5 * 1024 * 1024;
    /** Zuletzt gelesener Text (nur für die Fehlerübersicht; nie in Antworten oder Protokolle ausgeben). */
    public static string $lastText = '';
    public static string $lastSource = '';

    /** Eingebaute Beschriftungen (Regex, mit Doppelpunkt) je Feld. Eigene Textschnipsel kommen dazu. */
    private const BUILTIN = [
        'number' => ['Re\.-Nr\.:', 'Rechnungsnummer:', 'Rechnungs-Nr\.:', 'Rechnungsnr\.:', 'Rechnung Nr\.:'],
        'date' => ['Re\.-Datum:', 'Rechnungsdatum:'],
        'total' => ['Gesamtsumme:', 'Gesamtbetrag:', 'Rechnungsbetrag:'],
        'transfer' => ['Betrag von', 'Zahlbetrag:'],
        'patient' => ['Patientin:', 'Patient:'],
    ];
    public const LABEL_KEYS = ['number' => 'label_number', 'date' => 'label_date', 'total' => 'label_total', 'transfer' => 'label_transfer', 'patient' => 'label_patient', 'prefix' => 'number_prefix'];

    public static function fromPdf(string $pdf, int $maxCents, array $labels = []): array {
        if (strlen($pdf) > self::MAX_PDF) throw new Problem('Die Rechnung ist zu groß.', 413);
        if (!str_starts_with($pdf, '%PDF-')) throw new Problem('Bitte die Rechnung als PDF übergeben.', 415);
        self::$lastText = ''; self::$lastSource = '';
        $binary = null;
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext'] as $path) {
            if (is_executable($path)) { $binary = $path; break; }
        }
        if (!$binary) throw new Problem('pdftotext fehlt. Bitte das Modul unter Verwaltung → Module neu installieren (Paket poppler-utils).', 503);
        $first = null; $text = '';
        // 1. Spaltenlayout, 2. Lesereihenfolge (andere Vorlagen), 3. Texterkennung (Bild-PDF).
        foreach (['layout' => ['-layout'], 'raw' => []] as $source => $flags) {
            $result = Command::run([$binary, ...$flags, '-enc', 'UTF-8', 'fd://0', '-'], $pdf, 20, 2 * 1024 * 1024);
            if ($result['code'] !== 0) throw new Problem('Die Rechnung konnte nicht gelesen werden.', 422);
            $text = $result['output'];
            if ($first === null) { $first = $text; self::$lastText = $text; self::$lastSource = 'Textebene'; }
            try {
                return self::parse($text, $maxCents, false, $labels, $source === 'raw');
            } catch (Problem $e) {
                $error ??= $e;
            }
        }
        if (!self::needsOcr($first, $labels)) throw $error;
        $ocr = self::ocr($pdf);
        if ($ocr === null) {
            if (trim($first) === '') throw new Problem('Die Rechnung enthält keinen Text und die Texterkennung ist nicht eingerichtet. Bitte das Modul unter Verwaltung → Module neu installieren (Pakete tesseract-ocr, tesseract-ocr-deu).', 422);
            throw $error;
        }
        self::$lastText = $ocr; self::$lastSource = 'Texterkennung';
        try {
            // Erkannter Text ist fehleranfälliger: Zahlbetrag-Gegenprobe ist hier Pflicht.
            return self::parse($ocr, $maxCents, true, $labels, true, true);
        } catch (Problem $e) {
            throw new Problem($e->getMessage() . ' (Texterkennung)', $e->http);
        }
    }

    /** Ohne Textebene oder ohne die erwarteten Beschriftungen lohnt sich die Texterkennung. */
    private static function needsOcr(string $text, array $labels): bool {
        return trim($text) === '' || !self::all('number', $labels, '', $text) || !self::all('total', $labels, '', $text);
    }

    /** Alternativen für ein Feld: eingebaute plus eigene Textschnipsel (wörtlich, Leerraum flexibel, Doppelpunkt optional). */
    private static function labelRegex(string $field, array $labels, string $glue = '|'): string {
        $parts = self::BUILTIN[$field];
        foreach ((array)($labels[self::LABEL_KEYS[$field]] ?? []) as $custom) {
            $custom = trim(rtrim(trim((string)$custom), ':'));
            if ($custom === '' || mb_strlen($custom) > 60) continue;
            $parts[] = implode('[ \t]+', array_map(fn($w) => preg_quote($w, '/'), preg_split('/\s+/u', $custom))) . ':?';
        }
        return '/(?:' . implode($glue, $parts) . ')/u';
    }
    /** Beschriftungen eines Felds als fertige Ausdrücke (für die Nachbearbeitung abgelehnter Rechnungen). */
    public static function labelPatterns(string $field, array $labels): array {
        return array_map(fn($part) => '/' . $part . '/u', self::labelParts($field, $labels));
    }
    /** Beschriftungen eines Felds einzeln (eingebaute und eigene), als Regex-Teile ohne Begrenzer. */
    private static function labelParts(string $field, array $labels): array {
        return explode("\x00", substr(self::labelRegex($field, $labels, "\x00"), 4, -3));
    }

    /**
     * Alle Treffer von „Beschriftung + $suffix“, je Beschriftung ein eigener Ausdruck. Eine Alternation (a|b) vor dem
     * Wert findet mit PCRE2 10.46 (Ubuntu 26.04, PHP 8.5) Werte hinter der zweiten Beschriftung nicht immer.
     * PREG_PATTERN_ORDER: Liste der ersten Gruppe; PREG_SET_ORDER: Liste der Treffer.
     */
    private static function all(string $field, array $labels, string $suffix, string $text, int $flags = PREG_PATTERN_ORDER): array {
        $out = [];
        foreach (self::labelParts($field, $labels) as $part) {
            if (preg_match_all('/' . $part . $suffix . '/u', $text, $m, $flags) === false) throw new Problem('Auswertung der Rechnung fehlgeschlagen (' . preg_last_error_msg() . ').', 422);
            if ($flags === PREG_SET_ORDER) array_push($out, ...$m);
            else array_push($out, ...($suffix === '' ? $m[0] : $m[1]));
        }
        return $out;
    }

    /** Texterkennung der ersten Seiten; null, wenn die Werkzeuge fehlen. */
    private static function ocr(string $pdf): ?string {
        $tools = [];
        foreach (['pdftoppm' => '/usr/bin/pdftoppm', 'tesseract' => '/usr/bin/tesseract'] as $name => $default) {
            foreach ([$default, '/usr/local/bin/' . $name, '/opt/homebrew/bin/' . $name] as $path) {
                if (is_executable($path)) { $tools[$name] = $path; break; }
            }
            if (!isset($tools[$name])) return null;
        }
        $directory = sys_get_temp_dir() . '/ks-ocr-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) return null;
        try {
            if (file_put_contents($directory . '/in.pdf', $pdf) !== strlen($pdf)) return null;
            $render = Command::run([$tools['pdftoppm'], '-r', '300', '-gray', '-png', '-l', '4', $directory . '/in.pdf', $directory . '/page'], '', 60, 65536);
            if ($render['code'] !== 0) return null;
            $pages = glob($directory . '/page-*.png') ?: [];
            sort($pages);
            $text = [];
            foreach (array_slice($pages, 0, 4) as $image) {
                $read = Command::run([$tools['tesseract'], $image, 'stdout', '-l', 'deu', '--psm', '6'], '', 90, 2 * 1024 * 1024);
                if ($read['code'] !== 0) return null;
                $text[] = $read['output'];
            }
            return $text ? implode("\f", $text) : null;
        } catch (Problem) {
            return null;
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
            @rmdir($directory);
        }
    }

    /** $loose: Wert darf nach einem Zeilenumbruch hinter der Beschriftung stehen (Lesereihenfolge, Texterkennung). */
    public static function parse(string $text, int $maxCents, bool $requireTransfer = false, array $labels = [], bool $loose = false, bool $ocr = false): array {
        if (!mb_check_encoding($text, 'UTF-8')) throw new Problem('Die Rechnung enthält keinen lesbaren Text.', 422);
        $text = str_replace(["\u{00a0}", "\r"], [' ', ''], $text);
        if (trim($text) === '') throw new Problem('Die Rechnung enthält keinen Text. Bitte als Text-PDF aus T2med drucken.', 422);
        $gap = $loose ? '[ \t]*\n?[ \t]*' : '[ \t]*';
        $eur = '[ \t]*(?:€|EUR)';

        $numbers = self::all('number', $labels, $gap . '((?=[A-Za-z0-9\/._-]*\d)[A-Za-z0-9][A-Za-z0-9\/._-]{0,39})', $text);
        $numbers = array_map(fn($n) => self::canonicalNumber($n, (array)($labels[self::LABEL_KEYS['prefix']] ?? [])), $numbers);
        $number = self::single($numbers, 'Rechnungsnummer', 'Re.-Nr.:', $ocr);
        $date = self::date(self::single(self::all('date', $labels, $gap . '(\d{2}\.\d{2}\.\d{4})', $text), 'Rechnungsdatum', 'Re.-Datum:'));
        $total = self::cents(self::single(self::all('total', $labels, $gap . '(-?[\d.]+,\d{2})' . $eur, $text), 'Gesamtsumme', 'Gesamtsumme: … €'));
        // Gegenprobe mit dem Überweisungssatz, falls vorhanden.
        $transfers = self::all('transfer', $labels, $gap . '(-?[\d.]+,\d{2})' . $eur, $text);
        $found = $transfers !== [];
        if ($requireTransfer && !$found) throw new Problem('Der Zahlbetrag der Rechnung konnte nicht bestätigt werden.', 422);
        if ($found) {
            foreach ($transfers as $value) if (self::cents($value) !== $total) throw new Problem('Gesamtsumme und Zahlbetrag der Rechnung widersprechen sich.', 422);
        }
        if ($total < 1 || $total > $maxCents) throw new Problem('Der Rechnungsbetrag liegt außerhalb des erlaubten Bereichs.', 422);

        // Erste Seite: „Patient: Vorname Nachname , TT.MM.JJJJ“ (auch „(geb. TT.MM.JJJJ)“). Folgeseiten ohne Geburtsdatum.
        $p = self::all('patient', $labels, '[ \t]*([^\n,;(]{1,160}?)[ \t]*[,;(][ \t]*(?:geb(?:oren)?\.?[ \t]*(?:am)?[ \t]*)?(\d{2}\.\d{2}\.\d{4})', $text, PREG_SET_ORDER);
        $patients = [];
        foreach ($p as $match) $patients[self::name($match[1]) . '|' . $match[2]] = [trim($match[1]), $match[2]];
        if (count($patients) !== 1) throw new Problem('Patient und Geburtsdatum der Rechnung sind nicht eindeutig.', 422);
        [$name, $birth] = array_values($patients)[0];
        if (self::name($name) === '') throw new Problem('Der Patientenname der Rechnung fehlt.', 422);

        return ['number' => $number, 'invoice_date' => $date, 'amount_cents' => $total,
            'patient_name' => mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 160), 'patient_birthdate' => self::date($birth)];
    }

    /** Beschriftungs-Zeilen ohne Namen und Daten: nur Stichwörter, Beträge und Platzhalter. Für die Fehlersuche. */
    public static function overview(string $text, int $maxLines = 14): array {
        $keep = '/^(?:[^\s]*(?:nr|datum|summe|betrag|patient|rechnung|re\.|geb|€|eur)[^\s]*|bitte|überweisen|sie|den|von|ohne|abzug|bis|zum|auf|das|unten|aufgeführte|konto|seite|:|,|\/)$/iu';
        $lines = [];
        foreach (preg_split('/\n/u', $text) as $line) {
            if (!preg_match('/(nr\.?|datum|summe|betrag|patient|rechnung)/iu', $line)) continue;
            $out = [];
            foreach (preg_split('/\s+/u', trim($line), -1, PREG_SPLIT_NO_EMPTY) as $token) {
                if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $token)) $out[] = 'TT.MM.JJJJ';
                elseif (preg_match('/^-?[\d.]+,\d{2}$/', $token)) $out[] = $token;
                elseif (preg_match($keep, $token) && !preg_match('/\d{3,}/', $token)) $out[] = $token;
                else $out[] = '▪';
            }
            $lines[] = mb_substr(implode(' ', $out), 0, 140);
            if (count($lines) >= $maxLines) break;
        }
        return $lines;
    }

    /** Vergleichsform für Namen: Kleinschreibung, einfache Leerzeichen. */
    public static function name(string $value): string {
        return mb_strtolower(trim(preg_replace('/[\s,]+/u', ' ', $value)));
    }

    /** Passt die Rechnung zum Patienten aus dem T2med-Kontext? */
    public static function matches(array $invoice, string $patientName, string $patientBirthdate): bool {
        if ($patientBirthdate === '' || $invoice['patient_birthdate'] !== $patientBirthdate) return false;
        $pdf = self::name($invoice['patient_name']); $fhir = self::name($patientName);
        // Ein vorangestellter Titel (z. B. „Dr.“) auf der Rechnung ist erlaubt.
        return $fhir !== '' && ($pdf === $fhir || str_ends_with($pdf, ' ' . $fhir));
    }

    /**
     * Rechnungsnummern der Form „Kürzel + Jahr + Bindestrich + laufende Nummer“ (z. B. IG2026-309): Das von der Texterkennung gelesene Kürzel
     * (z. B. „1IG“, „16G“, „162“) wird dem eingestellten Kürzel zugeordnet, das ihm nach Verwechslungstabelle (I/1, G/6, O/0 …) am nächsten ist
     * (höchstens ein Zeichen Unterschied). Gibt es mehrere gleich nahe Kürzel oder keins, bleibt die gelesene Nummer unverändert.
     */
    public static function canonicalNumber(string $number, array $prefixes): string {
        if (!$prefixes || !preg_match('/^(.*?)(20\d{2}[-\/.]\d+)$/u', $number, $m)) return $number;
        $read = self::ocrKey($m[1]);
        $best = null; $bestDistance = 2; $tie = false;
        foreach ($prefixes as $prefix) {
            $prefix = trim((string)$prefix);
            if ($prefix === '') continue;
            $distance = levenshtein($read, self::ocrKey($prefix));
            if ($distance < $bestDistance) { $best = $prefix; $bestDistance = $distance; $tie = false; }
            elseif ($distance === $bestDistance && $best !== $prefix) $tie = true;
        }
        return ($best === null || $tie) ? $number : $best . $m[2];
    }

    /** Vergleichsform für erkannte Rechnungsnummern: typische Verwechslungen der Texterkennung (O/0, I/l/1, S/5, B/8, Z/2) werden gleichgesetzt. */
    private static function ocrKey(string $value): string {
        return strtr(strtoupper($value), ['O' => '0', 'Q' => '0', 'I' => '1', 'L' => '1', 'S' => '5', 'B' => '8', 'Z' => '2', 'G' => '6']);
    }

    private static function single(array $found, string $label, string $hint, bool $fuzzy = false): string {
        $values = array_values(array_unique($found));
        if ($fuzzy && count($values) > 1) {
            // Texterkennung liest dieselbe Nummer (Kopf, Fuß, Folgeseiten) manchmal verschieden. Abweichungen um höchstens ein Zeichen
            // gelten als dieselbe Nummer; es gilt die häufigste, bei Gleichstand die zuerst gelesene. Betrag und Patient werden ohnehin gegengeprüft.
            $keys = array_map([self::class, 'ocrKey'], $values);
            $same = true;
            foreach ($keys as $a) foreach ($keys as $b) if (levenshtein($a, $b) > 1) $same = false;
            if ($same) {
                $counts = array_count_values($found);
                arsort($counts);
                $best = max($counts);
                foreach ($found as $value) if ($counts[$value] === $best) return $value;
            }
        }
        if (count($values) === 0) throw new Problem($label . ' der Rechnung nicht gefunden (im PDF-Text fehlt die Bezeichnung „' . $hint . '“).', 422);
        if (count($values) > 1) throw new Problem($label . ' der Rechnung ist nicht eindeutig (' . count($values) . ' verschiedene Werte).', 422);
        return $values[0];
    }
    public static function cents(string $value): int {
        if (!preg_match('/^(-?)(\d{1,3}(?:\.\d{3})*|\d+),(\d{2})$/D', $value, $m)) throw new Problem('Betrag der Rechnung ist nicht lesbar.', 422);
        $cents = (int)str_replace('.', '', $m[2]) * 100 + (int)$m[3];
        return $m[1] === '-' ? -$cents : $cents;
    }
    public static function date(string $value): string {
        $d = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        if (!$d || $d->format('d.m.Y') !== $value) throw new Problem('Datum der Rechnung ist ungültig.', 422);
        return $d->format('Y-m-d');
    }
}
