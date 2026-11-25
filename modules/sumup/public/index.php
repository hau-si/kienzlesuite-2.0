<?php
declare(strict_types=1);
/**
 * Einstieg des Moduls „Kasse“ (nginx → php-fpm als ks-sumup). Alle Adressen unter /kasse/ kommen hier an;
 * die Basis hat die Anmeldung vorher geprüft (auth_request) und Benutzer, Rolle und Rechte als KIENZLE_* übergeben.
 * Ausnahme: POST /api.php des Arbeitsplatz-Starters (KIENZLE_OEFFENTLICH=starter), ohne Anmeldung der Suite;
 * der Starter weist sich mit dem Starter-Schlüssel aus.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\Suite;

$root = dirname(__DIR__);
if (($_SERVER['KIENZLE_OEFFENTLICH'] ?? '') === 'starter') {
    require $root . '/seiten/starter.php';
    exit;
}
Suite::syncBasis();
$base = Suite::base();
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rel = str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : '/';

/** Statische Dateien aus public/assets (nur bekannte Dateien, keine Unterordner). */
if (preg_match('#^/assets/([A-Za-z0-9][A-Za-z0-9_.-]{0,80})$#D', $rel, $m)) {
    $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'ppd' => 'application/vnd.cups-ppd', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'json' => 'application/json; charset=utf-8'];
    $file = $root . '/public/assets/' . $m[1];
    $ext = strtolower(pathinfo($m[1], PATHINFO_EXTENSION));
    if (!isset($types[$ext]) || !is_file($file) || str_contains($m[1], '..')) { http_response_code(404); exit; }
    header('Content-Type: ' . $types[$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    if ($ext === 'ppd') header('Content-Disposition: attachment; filename="' . $m[1] . '"');
    readfile($file);
    exit;
}
if (preg_match('#^/hilfe/bilder/([a-z0-9-]{1,60}\.png)$#D', $rel, $m) && is_file($root . '/docs/screenshots/' . $m[1])) {
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    readfile($root . '/docs/screenshots/' . $m[1]);
    exit;
}

$pages = ['/' => 'start.php', '/index.php' => 'start.php', '/kassieren' => 'index.php', '/api.php' => 'api.php', '/settings.php' => 'settings.php', '/einstellungen' => 'settings.php',
    '/settings-api.php' => 'settings-api.php', '/receipt.php' => 'receipt.php', '/beleg.php' => 'beleg.php', '/vorgaenge' => 'vorgaenge.php', '/vorgaenge-export.php' => 'vorgaenge-export.php',
    '/client-download.php' => 'client-download.php', '/hilfe/t2med' => 'hilfe-t2med.php'];
if (!isset($pages[$rel])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Seite nicht gefunden.\n");
}
require $root . '/seiten/' . $pages[$rel];
