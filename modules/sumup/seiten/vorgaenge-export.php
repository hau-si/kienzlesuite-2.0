<?php
declare(strict_types=1);
// Excel-Liste der Vorgänge (Tag, Woche, Monat, Jahr). Recht „Excel-Listen“ (export).
use KienzleSumup\{App, Problem, Suite, Vorgaenge};

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') throw new Problem('GET erforderlich.', 405);
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($site !== '' && $site !== 'same-origin' && $site !== 'none') throw new Problem('Unzulässiger Ursprung.', 403);
    if (!Suite::can('export')) throw new Problem('Für die Excel-Liste fehlt das Recht „Excel-Listen“. Bitte den Admin fragen.', 403);
    $app = ks_app();
    $v = new Vorgaenge($app->db, $app->config);
    $art = App::string($_GET, 'art', 8); $date = App::string($_GET, 'date', 10); $kind = in_array($_GET['kind'] ?? '', ['t2med', 'igel'], true) ? $_GET['kind'] : 'alle';
    $data = $v->workbook($art, $date, $kind);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $v->filename($art, $date) . '"');
    header('Content-Length: ' . strlen($data));
    echo $data;
} catch (Problem $e) {
    http_response_code($e->http); header('Content-Type: text/plain; charset=utf-8'); echo $e->getMessage(), "\n";
} catch (\Throwable $e) {
    error_log('kienzle-sumup vorgaenge-export: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); echo "Interner Fehler.\n";
}
