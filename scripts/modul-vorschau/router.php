<?php
// Terminzettel, Signatur, QM, Fax, Telefon und Homepage lokal ansehen (nur für Bildschirmfotos, erfundene Daten): php -S 127.0.0.1:8098 scripts/modul-vorschau/router.php
// Daten je Modul in $VORSCHAU_DATEN/<modul> (Standard /tmp/modul-vorschau), angelegt mit scripts/modul-vorschau/init.php.
$root = dirname(__DIR__, 2);
$daten = rtrim(getenv('VORSCHAU_DATEN') ?: '/tmp/modul-vorschau', '/');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) {
    $f = $root . '/core/public' . $path;
    if (is_file($f)) { header('Content-Type: ' . (str_ends_with($f, '.css') ? 'text/css' : (str_ends_with($f, '.svg') ? 'image/svg+xml' : 'text/javascript'))); readfile($f); return true; }
}
if (!preg_match('#^/(terminzettel|signatur|qm|fax|telefon|homepage)(/|$)#', $path, $m)) { http_response_code(404); echo 'nur /terminzettel, /signatur, /qm, /fax, /telefon und /homepage'; return true; }
$modul = $m[1];
$menu = rawurlencode(json_encode(['apps' => ['/kasse/' => 'Kasse', '/terminzettel/' => 'Terminzettel', '/signatur/' => 'Signatur', '/qm/' => 'Qualitätsmanagement', '/fax/' => 'Fax', '/telefon/' => 'Telefon', '/homepage/' => 'Homepage'],
    'ids' => ['/kasse/' => 'kasse', '/terminzettel/' => 'terminzettel', '/signatur/' => 'signatur', '/qm/' => 'qm', '/fax/' => 'fax', '/telefon/' => 'telefon', '/homepage/' => 'homepage'], 'admin' => true, 'login' => 'anna']));
// Erfundene Personen für „Vorlegen“ (QM); Anmeldung als „anna“ bzw. mit ?als=carla als MFA ohne Schreibrechte.
$_SERVER['KIENZLE_PERSONEN'] = rawurlencode(json_encode([['l' => 'anna', 'n' => 'Dr. med. Erika Beispiel', 'g' => ['Arzt'], 'a' => true, 'r' => []],
    ['l' => 'ben', 'n' => 'Ben Beispiel', 'g' => ['MFA'], 'a' => false, 'r' => ['lesen', 'schreiben']], ['l' => 'carla', 'n' => 'Carla Muster', 'g' => ['MFA'], 'a' => false, 'r' => ['lesen']],
    ['l' => 'dana', 'n' => 'Dana Beispiel', 'g' => ['MFA'], 'a' => false, 'r' => ['lesen']], ['l' => 'emil', 'n' => 'Emil Muster', 'g' => ['Auszubildende'], 'a' => false, 'r' => ['lesen']]]));
if ($m[1] === 'qm' && ($_GET['als'] ?? '') === 'carla') $_SERVER += ['KIENZLE_BENUTZER' => 'carla', 'KIENZLE_NAME' => 'Carla%20Muster', 'KIENZLE_ROLLE' => 'mitarbeiter', 'KIENZLE_RECHTE' => 'lesen'];
$_SERVER += ['KIENZLE_BENUTZER' => 'anna', 'KIENZLE_NAME' => $modul === 'qm' ? 'Dr.%20med.%20Erika%20Beispiel' : 'Anna Beispiel', 'KIENZLE_ROLLE' => 'admin', 'KIENZLE_RECHTE' => '', 'KIENZLE_CSRF' => 'x', 'KIENZLE_PFAD' => '/' . $modul,
    'KIENZLE_MENUE' => $menu, 'KIENZLE_PRAXIS' => rawurlencode(json_encode(['name' => 'Praxis Dr. Beispiel', 'street' => 'Musterweg 1', 'city' => '12345 Musterstadt']))];
putenv('KIENZLE_DATEN=' . $daten . '/' . $modul);
putenv('KIENZLE_SUITE_HILFE=' . $root . '/core/php/modul/Kienzle.php');
// Fax und Telefon: gemeinsamer Austauschordner, Telefon ohne laufendes Asterisk und mit eigenem Modulschlüssel (nur Vorschau).
putenv('KIENZLE_AUSTAUSCH=' . $daten . '/austausch');
putenv('KIENZLE_ASTERISK=/bin/false');
putenv('KIENZLE_PROGRAMM=' . $root . '/modules/telefon');
if ($modul === 'telefon') {
    require_once $root . '/core/php/modul/Kienzle.php';
    $GLOBALS['tf_test_modul'] = new KienzleModul('telefon', $_SERVER, $daten . '/verbindungen', $daten . '/schluessel');
}
// Die Terminzettel-Hilfe nimmt den Pfad von Praxisherz-Hilfe nur in der CLI aus der Umgebung: hier (php -S) von Hand setzen.
require_once $root . '/modules/' . $modul . '/src/bootstrap.php';
if ($modul === 'terminzettel') KienzleTerminzettel\Seite::$helper = $root . '/core/php/modul/Kienzle.php';
require $root . '/modules/' . $modul . '/public/index.php';
