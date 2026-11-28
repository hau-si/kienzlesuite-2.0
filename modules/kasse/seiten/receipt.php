<?php
declare(strict_types=1);
use KienzleKasse\{App, Problem, Suite};

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$receipt = null; $error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Problem('GET erforderlich.', 405);
    if (!Suite::can('kassieren')) throw new Problem('Für die Kasse fehlt das Recht „Kartenzahlung und Barzahlung aus T2med“.', 403);
    $app = ks_app();
    $browser = $app->browser($_COOKIE['ks_browser'] ?? null);
    $visit = $app->visit($browser, App::string($_GET, 'v', 32));
    $id = App::string($_GET, 'p', 32);
    $receipt = $app->receipt($visit, $id);
    header('Content-Type: application/pdf');
    $disposition = ($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline';
    header('Content-Disposition: ' . $disposition . '; filename="Zahlungsbeleg-' . preg_replace('/[^a-f0-9]/', '', $id) . '.pdf"');
    header('Content-Length: ' . strlen($receipt));
    echo $receipt;
    exit;
} catch (Problem $e) {
    http_response_code($e->http); $error = $e->getMessage();
} catch (Throwable $e) {
    error_log('kienzle-kasse receipt: ' . get_class($e));
    http_response_code(500); $error = 'Der Beleg konnte nicht geladen werden. Bitte erneut versuchen.';
}
$base = ks_h(Suite::base() . '/');
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zahlungsbeleg · Kasse</title><link rel="stylesheet" href="<?= $base ?>assets/receipt.css"><script src="<?= $base ?>assets/receipt.js" defer></script></head>
<body><main class="receipt-page"><div class="receipt-toolbar"><button id="receipt-retry" type="button">Erneut versuchen</button><button id="receipt-close" type="button">Fenster schließen</button></div>
<section class="receipt-error" role="alert"><h1>Beleg nicht verfügbar</h1><p><?= ks_h($error) ?></p></section>
</main></body></html>
