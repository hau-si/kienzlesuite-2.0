<?php
declare(strict_types=1);
namespace KienzleKasse;

/**
 * Lesbarer Text im PDF: Letterxpress liest die Anschrift aus dem Adressfenster und nimmt Rechnungen nur mit Textebene an.
 * Hat das PDF keine (reines Bild, z. B. von einem Windows-Druckertreiber), erzeugt die Texterkennung (tesseract) eine durchsuchbare Fassung.
 */
final class PdfText {
    public const MAX_PAGES = 10;
    private const MIN_CHARS = 40;

    private static function tool(string $name): ?string {
        foreach (['/usr/bin/' . $name, '/usr/local/bin/' . $name, '/opt/homebrew/bin/' . $name] as $path) if (is_executable($path)) return $path;
        return null;
    }

    /** Hat die erste Seite lesbaren Text? (Buchstaben und Ziffern, nicht nur Leerraum.) */
    public static function hasText(string $pdf): bool {
        $binary = self::tool('pdftotext');
        if (!$binary) throw new Problem('pdftotext fehlt. Bitte das Modul unter Betrieb → Module neu installieren (Paket poppler-utils).', 503);
        $result = Command::run([$binary, '-l', '1', '-enc', 'UTF-8', 'fd://0', '-'], $pdf, 20, 2 * 1024 * 1024);
        return $result['code'] === 0 && preg_match_all('/[\p{L}\p{N}]/u', $result['output']) >= self::MIN_CHARS;
    }

    /** @return array{0:string,1:bool} [PDF mit Textebene, ob Texterkennung nötig war] */
    public static function ensure(string $pdf): array {
        if (self::hasText($pdf)) return [$pdf, false];
        $tools = [];
        foreach (['pdftoppm', 'tesseract', 'qpdf'] as $name) {
            $tools[$name] = self::tool($name) ?? throw new Problem('Das PDF hat keinen lesbaren Text und die Texterkennung ist nicht eingerichtet. Bitte das Modul unter Betrieb → Module neu installieren (Pakete tesseract-ocr, tesseract-ocr-deu, poppler-utils, qpdf).', 503);
        }
        $directory = sys_get_temp_dir() . '/ks-lxp-ocr-' . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) throw new Problem('Die Texterkennung konnte nicht vorbereitet werden.', 500);
        try {
            if (file_put_contents($directory . '/in.pdf', $pdf) !== strlen($pdf)) throw new Problem('Die Texterkennung konnte nicht vorbereitet werden.', 500);
            $render = Command::run([$tools['pdftoppm'], '-r', '300', '-gray', '-png', '-l', (string)(self::MAX_PAGES + 1), $directory . '/in.pdf', $directory . '/seite'], '', 90, 65536);
            $pages = glob($directory . '/seite-*.png') ?: [];
            sort($pages);
            if ($render['code'] !== 0 || !$pages) throw new Problem('Das PDF konnte für die Texterkennung nicht gelesen werden.', 422);
            if (count($pages) > self::MAX_PAGES) throw new Problem('Das PDF hat mehr als ' . self::MAX_PAGES . ' Seiten und wird nicht automatisch per Post verschickt.', 422);
            $parts = [];
            foreach ($pages as $n => $image) {
                $base = $directory . '/ocr-' . sprintf('%02d', $n);
                $read = Command::run([$tools['tesseract'], $image, $base, '-l', 'deu', '--psm', '6', 'pdf'], '', 120, 65536);
                if ($read['code'] !== 0 || !is_file($base . '.pdf')) throw new Problem('Die Texterkennung ist fehlgeschlagen.', 422);
                $parts[] = $base . '.pdf';
            }
            $out = $directory . '/aus.pdf';
            $merge = Command::run([$tools['qpdf'], '--empty', '--pages', ...$parts, '--', $out], '', 60, 65536);
            $data = in_array($merge['code'], [0, 3], true) ? (string)@file_get_contents($out) : '';
            if (!str_starts_with($data, '%PDF-') || !self::hasText($data)) throw new Problem('Auch die Texterkennung fand im PDF keinen lesbaren Text. Bitte die Rechnung direkt aus T2med (nicht als Bild) drucken.', 422);
            return [$data, true];
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
            @rmdir($directory);
        }
    }
}
