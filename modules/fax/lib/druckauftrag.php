<?php
declare(strict_types=1);
// Virtueller Faxdrucker (vFax1 … vFax5), gestartet von ippeveprinter der Basis als ks-fax: php druckauftrag.php <Drucker> <Datei>.
// PDF wird abgelegt, PostScript vorher mit Ghostscript in PDF gewandelt. Meldungen nur feste Texte (keine Patientendaten).
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleFax\Fax;

$drucker = (string)($argv[1] ?? '');
$datei = (string)($argv[2] ?? '');
try {
    if (!in_array($drucker, Fax::DRUCKER, true) || !is_file($datei)) throw new InvalidArgumentException('Aufruf ungültig.');
    if (filesize($datei) > Fax::MAX_PDF) throw new InvalidArgumentException('Dokument zu groß (höchstens 30 MB).');
    $inhalt = (string)file_get_contents($datei);
    if (!str_starts_with($inhalt, '%PDF-')) {
        if (!str_starts_with($inhalt, '%!')) throw new InvalidArgumentException('Nur PDF oder PostScript.');
        $tmp = Fax::$data . '/.druck-' . bin2hex(random_bytes(4)) . '.pdf';
        $p = proc_open(['/usr/bin/gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-sPAPERSIZE=a4', '-o', $tmp, $datei], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) throw new RuntimeException('Ghostscript fehlt.');
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        $inhalt = $code === 0 && is_file($tmp) ? (string)file_get_contents($tmp) : '';
        @unlink($tmp);
        if (!str_starts_with($inhalt, '%PDF-')) throw new RuntimeException('Umwandlung in PDF fehlgeschlagen.');
    }
    Fax::druckAblegen($drucker, $inhalt, (string)(getenv('IPP_JOB_NAME') ?: ''));
    fwrite(STDERR, "INFO: Dokument für das Fax abgelegt.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
