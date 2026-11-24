<?php
declare(strict_types=1);
// Erzeugt die Seiten der Basis für die Bildschirmfotos in der README als eigenständiges HTML (Stylesheet eingebettet) aus der Testumgebung mit
// erfundenen Daten. Aufruf: php scripts/bildschirmfotos.php <Zielordner>; Aufnahme: scripts/bildschirmfotos.sh. Nie Daten einer echten Installation verwenden.
require dirname(__DIR__) . '/tests/php/lib.php';
require dirname(__DIR__) . '/tests/php/browser.php';
require dirname(__DIR__) . '/tests/php/verbindungen_hilfe.php';

use Kienzle\Core\{Connections, Updates};

$out = $argv[1] ?? sys_get_temp_dir() . '/bilder';
if (!is_dir($out)) mkdir($out, 0755, true);
$css = (string)file_get_contents(dirname(__DIR__) . '/core/public/assets/core.css');

$env = new Env();
[$admin] = admin_browser($env, 'anna');
[$bert] = $env->user('bert'); $env->users->setRoles($bert, ['Arzt']);
[$clara] = $env->user('clara'); $env->users->setRoles($clara, ['MFA']);
$env->user('dora');

function seite(string $name, string $html, string $out, string $css): void {
    $html = preg_replace('#<link rel="stylesheet" href="/assets/core.css[^"]*">#', '<style>' . $css . '</style>', $html);
    $html = preg_replace('#<script[^>]*></script>#', '', (string)$html);
    file_put_contents($out . '/' . $name . '.html', $html);
}
$cases = ['start' => '/', 'benutzer' => '/verwaltung/benutzer', 'gruppen' => '/verwaltung/gruppen', 'konto' => '/konto', 'einrichtung' => '/verwaltung/einrichtung',
    ];
foreach ($cases as $name => $url) {
    $r = $admin->get($url);
    echo str_pad($name, 14) . $r->status . "\n";
    if ($r->status === 200) seite($name, $r->body, $out, $css);
}
// Verbindungen und Updates brauchen eigene Dienste (hier mit erfundenem Status statt der root-Dienste).
[$cenv, $con] = con_env(['mail' => ['art' => 'smtp', 'absender' => 'suite@example.org', 'host' => 'smtp.example.org', 'port' => 587, 'benutzer' => 'suite@example.org', 'passwort_gesetzt' => true],
    'ablage' => [['id' => 'steuerbuero', 'name' => 'Steuerbüro', 'art' => 'sftp', 'host' => 'nas.example.org', 'freigabe' => '', 'ordner' => 'kienzle', 'passwort_gesetzt' => false]], 'letzte' => null]);
[$cb] = admin_browser($cenv, 'anna'); $cb->web = fn() => $cb->defaultWeb(null, null, $con);
$r = $cb->get('/verwaltung/verbindungen'); echo str_pad('verbindungen', 14) . $r->status . "\n"; if ($r->status === 200) seite('verbindungen', $r->body, $out, $css);
Updates::$state = $env->dir . '/update'; mkdir(Updates::$state); Updates::$unit = $env->dir . '/kienzle-update.path'; touch(Updates::$unit);
file_put_contents(Updates::$state . '/status.json', json_encode(['state' => 'done', 'channel' => 'beta', 'checked_at' => time(), 'local' => ['2.3.4-beta.14', $env->config->version()],
    'available' => ['core' => [['version' => '0.21.4-beta.34', 'prerelease' => true, 'notes' => "Neu: Startseite der Kasse und Einrichtung als Ausklappzeilen.", 'published' => '2026-11-24', 'signed' => true]]]]));
$up = new Updates($env->db, $env->config); $admin->web = fn() => $admin->defaultWeb(null, $up);
$r = $admin->get('/verwaltung/updates'); echo str_pad('updates', 14) . $r->status . "\n"; if ($r->status === 200) seite('updates', $r->body, $out, $css);
$anon = new Browser($env);
seite('anmelden', $anon->get('/anmelden')->body, $out, $css);
echo "Seiten in $out\n";
