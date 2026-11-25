<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Nachbearbeitung einer nicht erkannten T2med-Rechnung: Sucht die Beschriftungen (Nummer, Datum, Betrag, Patient) mit ihren Positionen
 * auf der Seite, schneidet die Stellen als Bildausschnitte aus und schlägt Werte vor. Die Werte trägt am Ende ein Mensch ein; geprüft
 * wird wie bei der automatischen Erkennung (Datum, Betrag, Bereich). Nichts davon wird gespeichert außer der PDF-Datei selbst (InboxLog).
 */
final class InvoiceReview {
    public const FIELDS = ['number' => 'Rechnungsnummer', 'date' => 'Rechnungsdatum', 'total' => 'Gesamtbetrag', 'patient' => 'Patient und Geburtsdatum'];
    private const MAX_PAGES = 4;
    private const DPI = 150;

    /** @return array{source:string,pages:int,fields:array<string,array>} */
    public static function analyze(string $pdfFile, array $labels): array {
        $pdf = (string)@file_get_contents($pdfFile);
        if (!str_starts_with($pdf, '%PDF-')) throw new Problem('Die gemerkte Rechnung liegt nicht mehr vor.', 404);
        $lines = self::linesFromText($pdfFile); $source = 'Textebene';
        if (!self::hasLabels($lines, $labels)) {
            $ocr = self::linesFromOcr($pdfFile);
            if ($ocr !== null && self::hasLabels($ocr, $labels)) { $lines = $ocr; $source = 'Texterkennung'; }
            elseif ($ocr !== null && !$lines) { $lines = $ocr; $source = 'Texterkennung'; }
        }
        $fields = [];
        foreach (self::FIELDS as $key => $title) $fields[$key] = ['title' => $title] + self::field($key, $lines, $labels);
        // Zahlbetrag als zusätzlicher Vorschlag und zweiter Ausschnitt beim Gesamtbetrag.
        $transfer = self::field('transfer', $lines, $labels);
        foreach ($transfer['values'] as $v) if (!in_array($v, $fields['total']['values'], true)) $fields['total']['values'][] = $v;
        $fields['total']['regions'] = array_merge($fields['total']['regions'], $transfer['regions']);
        foreach ($fields as $key => &$f) {
            $f['snippets'] = [];
            foreach (array_slice($f['regions'], 0, 3) as $region) {
                $image = self::crop($pdfFile, $region);
                if ($image !== null) $f['snippets'][] = $image;
            }
            unset($f['regions']);
            $f['status'] = self::status($key, $f['values']);
            $f['guess'] = $f['values'][0] ?? '';
        }
        unset($f);
        return ['source' => $source, 'pages' => max(self::pageCount($pdf), (int)max([1, ...array_column($lines, 'page')])), 'fields' => $fields];
    }

    /** Seitenansicht (ganze Seite) als Bild für die Anzeige. */
    public static function page(string $pdfFile, int $page): string {
        if ($page < 1 || $page > self::MAX_PAGES) throw new Problem('Diese Seite gibt es nicht.', 422);
        $image = self::render($pdfFile, $page, ['-r', '100'], 'page');
        if ($image === null) throw new Problem('Die Seite konnte nicht dargestellt werden.', 500);
        return $image;
    }

    /** Eingegebene Werte prüfen und in die Form der Rechnungsablage bringen. */
    public static function validate(array $in, int $maxCents, array $prefixes = []): array {
        $number = preg_replace('/\s+/', '', trim((string)($in['number'] ?? ''))) ?? '';
        if (!preg_match('/^(?=[A-Za-z0-9\/._-]*\d)[A-Za-z0-9][A-Za-z0-9\/._-]{0,39}$/D', $number)) throw new Problem('Die Rechnungsnummer ist ungültig (Buchstaben, Ziffern, / . _ -, mit mindestens einer Ziffer).', 422);
        $number = T2medInvoice::canonicalNumber($number, $prefixes);
        $date = T2medInvoice::date(trim((string)($in['date'] ?? '')));
        $cents = self::money((string)($in['total'] ?? ''));
        if ($cents < 1 || $cents > $maxCents) throw new Problem('Der Rechnungsbetrag liegt außerhalb des erlaubten Bereichs.', 422);
        $name = trim((string)preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 160 || !preg_match('/^[\p{L}\p{M}][\p{L}\p{M} .\'’-]*$/u', $name)) throw new Problem('Bitte den Patientennamen eintragen (nur Buchstaben).', 422);
        $birth = T2medInvoice::date(trim((string)($in['birth'] ?? '')));
        return ['number' => $number, 'invoice_date' => $date, 'amount_cents' => $cents, 'patient_name' => $name, 'patient_birthdate' => $birth];
    }

    /** „84“, „84,5“, „84,50“, „1.084,50 €“, „84.50“ → Cent. */
    public static function money(string $value): int {
        $v = trim(str_replace(['€', 'EUR', "\u{a0}", ' '], '', $value));
        if (preg_match('/^\d+$/D', $v)) $v .= ',00';
        elseif (preg_match('/^\d+[.,]\d$/D', $v)) $v = str_replace('.', ',', $v) . '0';
        elseif (preg_match('/^\d+\.\d{2}$/D', $v)) $v = str_replace('.', ',', $v);
        if (!preg_match('/^(\d{1,3}(?:\.\d{3})*|\d+),\d{2}$/D', $v)) throw new Problem('Der Betrag ist ungültig (zum Beispiel 84,00).', 422);
        return T2medInvoice::cents($v);
    }

    // ---------------------------------------------------------------------------------------------------------------------------------

    private static function status(string $key, array $values): string {
        if (!$values) return 'fehlt';
        return count($values) === 1 ? 'gelesen' : 'unsicher';
    }

    /** Alle Zeilen mit Beschriftungen eines Felds: Wert-Vorschläge und die Bereiche der Seite, aus denen sie stammen. */
    private static function field(string $key, array $lines, array $labels): array {
        $values = []; $regions = [];
        $prefixes = (array)($labels[T2medInvoice::LABEL_KEYS['prefix']] ?? []);
        foreach (T2medInvoice::labelPatterns($key === 'transfer' ? 'transfer' : $key, $labels) as $pattern) {
            foreach ($lines as $i => $line) {
                if (!preg_match($pattern, $line['text'], $m, PREG_OFFSET_CAPTURE)) continue;
                $rest = substr($line['text'], $m[0][1] + strlen($m[0][0]));
                $used = [$line];
                // Steht der Wert erst in der nächsten Zeile (Lesereihenfolge, Texterkennung), diese mitnehmen.
                if (trim($rest) === '' && isset($lines[$i + 1]) && $lines[$i + 1]['page'] === $line['page']) { $rest = $lines[$i + 1]['text']; $used[] = $lines[$i + 1]; }
                foreach (self::extract($key, $rest, $prefixes) as $v) if (!in_array($v, $values, true)) $values[] = $v;
                $regions[] = self::region($used);
            }
        }
        return ['values' => array_slice($values, 0, 6), 'regions' => self::mergeRegions($regions)];
    }

    private static function extract(string $key, string $rest, array $prefixes): array {
        $out = [];
        if ($key === 'number') {
            if (preg_match('/^[ \t:]*((?=[A-Za-z0-9\/._-]*\d)[A-Za-z0-9][A-Za-z0-9\/._-]{0,39})/', $rest, $m)) $out[] = T2medInvoice::canonicalNumber($m[1], $prefixes);
        } elseif ($key === 'date') {
            if (preg_match('/(\d{2}\.\d{2}\.\d{4})/', $rest, $m)) $out[] = $m[1];
        } elseif ($key === 'total' || $key === 'transfer') {
            if (preg_match('/(-?[\d.]+,\d{2})/', $rest, $m)) $out[] = $m[1];
        } elseif ($key === 'patient') {
            if (preg_match('/^[ \t:]*([^\n,;(]{1,160}?)[ \t]*[,;(][ \t]*(?:geb(?:oren)?\.?[ \t]*(?:am)?[ \t]*)?(\d{2}\.\d{2}\.\d{4})/u', $rest, $m)) $out[] = trim($m[1]) . ' | ' . $m[2];
            elseif (preg_match('/^[ \t:]*([^\n,;(]{2,160})/u', $rest, $m)) $out[] = trim($m[1]) . ' | ';
        }
        return $out;
    }

    private static function region(array $lines): array {
        $x0 = min(array_column($lines, 'x0')); $x1 = max(array_column($lines, 'x1'));
        $y0 = min(array_column($lines, 'y0')); $y1 = max(array_column($lines, 'y1'));
        return ['page' => $lines[0]['page'], 'x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1];
    }
    private static function mergeRegions(array $regions): array {
        $out = [];
        foreach ($regions as $r) { $key = $r['page'] . ':' . round($r['y0']) . ':' . round($r['x0']); $out[$key] = $r; }
        return array_values($out);
    }

    private static function hasLabels(array $lines, array $labels): bool {
        foreach (['number', 'total'] as $field) {
            $found = false;
            foreach (T2medInvoice::labelPatterns($field, $labels) as $pattern) foreach ($lines as $line) if (preg_match($pattern, $line['text'])) { $found = true; break 2; }
            if (!$found) return false;
        }
        return true;
    }

    private static function tool(string $name): ?string {
        foreach (['/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/'] as $dir) if (is_executable($dir . $name)) return $dir . $name;
        return null;
    }

    /** Zeilen mit Position (in Punkt) aus der Textebene. */
    private static function linesFromText(string $file): array {
        $bin = self::tool('pdftotext');
        if (!$bin) return [];
        $run = Command::run([$bin, '-bbox-layout', '-l', (string)self::MAX_PAGES, '-enc', 'UTF-8', $file, '-'], '', 30, 8 * 1024 * 1024);
        if ($run['code'] !== 0 || $run['output'] === '') return [];
        $dom = new \DOMDocument();
        if (!@$dom->loadHTML('<?xml encoding="UTF-8">' . $run['output'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return [];
        $lines = []; $page = 0;
        foreach ($dom->getElementsByTagName('page') as $pageNode) {
            $page++;
            foreach ($pageNode->getElementsByTagName('line') as $node) {
                $words = [];
                foreach ($node->getElementsByTagName('word') as $w) $words[] = $w->textContent;
                $text = trim(implode(' ', $words));
                if ($text === '') continue;
                $lines[] = ['page' => $page, 'text' => $text, 'x0' => (float)$node->getAttribute('xmin'), 'y0' => (float)$node->getAttribute('ymin'), 'x1' => (float)$node->getAttribute('xmax'), 'y1' => (float)$node->getAttribute('ymax')];
            }
        }
        return $lines;
    }

    /** Zeilen mit Position (in Punkt) per Texterkennung; null, wenn die Werkzeuge fehlen. */
    private static function linesFromOcr(string $file): ?array {
        $pdftoppm = self::tool('pdftoppm'); $tesseract = self::tool('tesseract');
        if (!$pdftoppm || !$tesseract) return null;
        $dir = sys_get_temp_dir() . '/ks-review-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) return null;
        try {
            $render = Command::run([$pdftoppm, '-r', '300', '-gray', '-png', '-l', (string)self::MAX_PAGES, $file, $dir . '/p'], '', 90, 65536);
            if ($render['code'] !== 0) return null;
            $pages = glob($dir . '/p-*.png') ?: []; sort($pages);
            $lines = [];
            foreach (array_slice($pages, 0, self::MAX_PAGES) as $index => $image) {
                $read = Command::run([$tesseract, $image, 'stdout', '-l', 'deu', '--psm', '6', 'tsv'], '', 120, 8 * 1024 * 1024);
                if ($read['code'] !== 0) return null;
                $groups = [];
                foreach (explode("\n", $read['output']) as $row) {
                    $c = explode("\t", $row);
                    if (count($c) < 12 || $c[0] !== '5' || trim($c[11]) === '') continue;
                    $k = $c[2] . '.' . $c[3] . '.' . $c[4];
                    $g = &$groups[$k];
                    $g['words'][] = trim($c[11]);
                    $x0 = (float)$c[6]; $y0 = (float)$c[7]; $x1 = $x0 + (float)$c[8]; $y1 = $y0 + (float)$c[9];
                    $g['x0'] = isset($g['x0']) ? min($g['x0'], $x0) : $x0; $g['y0'] = isset($g['y0']) ? min($g['y0'], $y0) : $y0;
                    $g['x1'] = isset($g['x1']) ? max($g['x1'], $x1) : $x1; $g['y1'] = isset($g['y1']) ? max($g['y1'], $y1) : $y1;
                    unset($g);
                }
                $f = 72 / 300;
                foreach ($groups as $g) $lines[] = ['page' => $index + 1, 'text' => implode(' ', $g['words']), 'x0' => $g['x0'] * $f, 'y0' => $g['y0'] * $f, 'x1' => $g['x1'] * $f, 'y1' => $g['y1'] * $f];
            }
            return $lines;
        } catch (Problem) {
            return null;
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    private static function pageCount(string $pdf): int {
        return max(1, min(self::MAX_PAGES, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf)));
    }

    /** Ausschnitt einer Seite als PNG (Data-URL); Position in Punkt. */
    private static function crop(string $file, array $r): ?string {
        $k = self::DPI / 72;
        $pad = 5;
        $x = max(0, (int)floor(($r['x0'] - $pad) * $k)); $y = max(0, (int)floor(($r['y0'] - $pad) * $k));
        $w = min(1500, (int)ceil(($r['x1'] - $r['x0'] + 2 * $pad) * $k)); $h = min(260, (int)ceil(($r['y1'] - $r['y0'] + 2 * $pad) * $k));
        return self::render($file, (int)$r['page'], ['-r', (string)self::DPI, '-x', (string)$x, '-y', (string)$y, '-W', (string)$w, '-H', (string)$h], 'crop');
    }

    private static function render(string $file, int $page, array $options, string $kind): ?string {
        $bin = self::tool('pdftoppm');
        if (!$bin) return null;
        $dir = sys_get_temp_dir() . '/ks-snip-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) return null;
        try {
            $run = Command::run([$bin, ...$options, '-png', '-singlefile', '-f', (string)$page, '-l', (string)$page, $file, $dir . '/' . $kind], '', 30, 65536);
            $png = $run['code'] === 0 ? @file_get_contents($dir . '/' . $kind . '.png') : false;
            return $png ? 'data:image/png;base64,' . base64_encode($png) : null;
        } catch (Problem) {
            return null;
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }
}
