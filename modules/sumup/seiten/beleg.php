<?php
declare(strict_types=1);
// PDF einer verarbeiteten Rechnung (?p=<Zahlung>) oder eines Stornobelegs (?s=<Storno>) für die Startseite der Kasse.
use KienzleSumup\{App, Problem, Suite};

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Problem('GET erforderlich.', 405);
    if (!Suite::can('kassieren')) throw new Problem('Für die Kasse fehlt das Recht „Kassieren“.', 403);
    $app = ks_app();
    $pdf = isset($_GET['s']) ? $app->stornoPdf(App::string($_GET, 's', 32)) : $app->processedPdf(App::string($_GET, 'p', 32));
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline') . '; filename="' . (isset($_GET['s']) ? 'Storno' : 'Rechnung') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (Problem $e) {
    http_response_code($e->http); header('Content-Type: text/plain; charset=utf-8'); echo $e->getMessage();
} catch (Throwable $e) {
    error_log('kienzle-sumup beleg: ' . get_class($e));
    http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo 'Der Beleg konnte nicht erzeugt werden.';
}
