<?php
// Terminzettel und Signatur lokal ansehen (nur für Bildschirmfotos, erfundene Daten): php -S 127.0.0.1:8098 scripts/modul-vorschau/router.php
// Daten je Modul in $VORSCHAU_DATEN/<modul> (Standard /tmp/modul-vorschau), angelegt mit scripts/modul-vorschau/init.php.
$root = dirname(__DIR__, 2);
$daten = rtrim(getenv('VORSCHAU_DATEN') ?: '/tmp/modul-vorschau', '/');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) {
    $f = $root . '/core/public' . $path;
    if (is_file($f)) { header('Content-Type: ' . (str_ends_with($f, '.css') ? 'text/css' : (str_ends_with($f, '.svg') ? 'image/svg+xml' : 'text/javascript'))); readfile($f); return true; }
}
if (!preg_match('#^/(terminzettel|signatur)(/|$)#', $path, $m)) { http_response_code(404); echo 'nur /terminzettel und /signatur'; return true; }
$modul = $m[1];
$menu = rawurlencode(json_encode(['apps' => ['/kasse/' => 'Kasse', '/terminzettel/' => 'Terminzettel', '/signatur/' => 'Signatur'], 'ids' => ['/kasse/' => 'sumup', '/terminzettel/' => 'terminzettel', '/signatur/' => 'signatur'], 'admin' => true, 'login' => 'anna']));
$_SERVER += ['KIENZLE_BENUTZER' => 'anna', 'KIENZLE_NAME' => 'Anna Beispiel', 'KIENZLE_ROLLE' => 'admin', 'KIENZLE_RECHTE' => '', 'KIENZLE_CSRF' => 'x', 'KIENZLE_PFAD' => '/' . $modul,
    'KIENZLE_MENUE' => $menu, 'KIENZLE_PRAXIS' => rawurlencode(json_encode(['name' => 'Praxis Dr. Beispiel', 'street' => 'Musterweg 1', 'city' => '12345 Musterstadt']))];
putenv('KIENZLE_DATEN=' . $daten . '/' . $modul);
putenv('KIENZLE_SUITE_HILFE=' . $root . '/core/php/modul/Kienzle.php');
// Die Terminzettel-Hilfe nimmt den Pfad der Suite-Hilfe nur in der CLI aus der Umgebung: hier (php -S) von Hand setzen.
require_once $root . '/modules/' . $modul . '/src/bootstrap.php';
if ($modul === 'terminzettel') KienzleTerminzettel\Seite::$helper = $root . '/core/php/modul/Kienzle.php';
require $root . '/modules/' . $modul . '/public/index.php';
