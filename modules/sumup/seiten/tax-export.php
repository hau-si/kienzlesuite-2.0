<?php
declare(strict_types=1);
// Excel-Liste der Rechnungen (Jahr oder Monat) für die Steuerberatung. Anmeldung und Rechte über die Suite.
use KienzleSumup\{Problem, SettingsPage};

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Problem('GET erforderlich.', 405);
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '' && $site !== 'same-origin' && $site !== 'none') throw new Problem('Unzulässiger Ursprung.', 403);
    (new SettingsPage(ks_base_config()))->taxExport($_GET, 'header', function (string $bytes): void { echo $bytes; });
} catch (Problem $e) {
    http_response_code($e->http); header('Content-Type: text/plain; charset=utf-8'); echo $e->getMessage(), "\n";
} catch (\Throwable $e) {
    error_log('kienzle-sumup tax-export: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500); header('Content-Type: text/plain; charset=utf-8');
    echo "Interner Fehler. Details im Systemprotokoll: journalctl -t kienzle-sumup-php.\n";
}
