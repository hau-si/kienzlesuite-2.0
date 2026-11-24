<?php
// Kasse lokal ansehen (nur für Bildschirmfotos, erfundene Daten): php -S 127.0.0.1:8099 scripts/kasse-vorschau/router.php  (Daten in $VORSCHAU_DATEN, Standard /tmp/kasse-vorschau)
$root = dirname(__DIR__, 2);
$daten = getenv('VORSCHAU_DATEN') ?: '/tmp/kasse-vorschau';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) {
    $f = $root . '/core/public' . $path;
    if (is_file($f)) { header('Content-Type: ' . (str_ends_with($f, '.css') ? 'text/css' : 'text/javascript')); readfile($f); return true; }
}
if (!str_starts_with($path, '/kasse')) { http_response_code(404); echo 'nur /kasse'; return true; }
$menu = rawurlencode(json_encode(['apps' => ['/kasse/' => 'Kasse', '/terminzettel/' => 'Terminzettel'], 'ids' => ['/kasse/' => 'sumup', '/terminzettel/' => 'terminzettel'], 'admin' => true, 'login' => 'anna']));
$_SERVER += ['KIENZLE_BENUTZER' => 'anna', 'KIENZLE_NAME' => 'Anna Beispiel', 'KIENZLE_ROLLE' => 'admin', 'KIENZLE_RECHTE' => '', 'KIENZLE_CSRF' => 'x', 'KIENZLE_PFAD' => '/kasse',
    'KIENZLE_MENUE' => $menu, 'KIENZLE_PRAXIS' => rawurlencode(json_encode(['name' => 'Praxis Dr. Beispiel', 'street' => 'Musterweg 1', 'city' => '12345 Musterstadt']))];
if (isset($_SERVER['HTTP_ORIGIN'])) $_SERVER['HTTP_ORIGIN'] = 'https://localhost';
require $root . '/modules/sumup/src/bootstrap.php';
\KienzleSumup\Suite::$helper = $root . '/core/php/modul/Kienzle.php';
\KienzleSumup\Suite::$data = $daten;
require $root . '/modules/sumup/public/index.php';
