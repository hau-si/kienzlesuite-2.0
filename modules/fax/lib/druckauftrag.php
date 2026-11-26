<?php
declare(strict_types=1);
// Virtueller Faxdrucker (Namen aus den Einstellungen), gestartet von ippeveprinter der Basis als ks-fax: php druckauftrag.php <Drucker> <Datei>.
// PDF wird abgelegt, PostScript vorher mit Ghostscript in PDF gewandelt (Fax::alsPdf). Meldungen nur feste Texte (keine Patientendaten).
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleFax\Fax;

$drucker = (string)($argv[1] ?? '');
$datei = (string)($argv[2] ?? '');
try {
    if (!in_array($drucker, Fax::druckerNamen(), true) || !is_file($datei)) throw new InvalidArgumentException('Aufruf ungültig.');
    if (filesize($datei) > Fax::MAX_PDF) throw new InvalidArgumentException('Dokument zu groß (höchstens 30 MB).');
    $inhalt = Fax::alsPdf((string)file_get_contents($datei));
    Fax::druckAblegen($drucker, $inhalt, (string)(getenv('IPP_JOB_NAME') ?: ''));
    fwrite(STDERR, "INFO: Dokument für das Fax abgelegt.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
