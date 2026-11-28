<?php
declare(strict_types=1);
// Erzeugt die Seiten der Basis für die Bildschirmfotos in der README als eigenständiges HTML (Stylesheet eingebettet) aus der Testumgebung mit
// erfundenen Daten. Aufruf: php scripts/bildschirmfotos.php <Zielordner>; Aufnahme: scripts/bildschirmfotos.sh. Nie Daten einer echten Installation verwenden.
require dirname(__DIR__) . '/tests/php/lib.php';
require dirname(__DIR__) . '/tests/php/browser.php';
require dirname(__DIR__) . '/tests/php/verbindungen_hilfe.php';

use Kienzle\Core\{Connections, Modules, Starter, Updates, Web};

$out = $argv[1] ?? sys_get_temp_dir() . '/bilder';
if (!is_dir($out)) mkdir($out, 0755, true);
$css = (string)file_get_contents(dirname(__DIR__) . '/core/public/assets/core.css');

$env = new Env();
// Alle echten Module aus modules/ (ohne Beispielmodul), damit Menü, Rechte und Einrichtung wie auf einem Server mit allen Modulen aussehen.
$alle = json_decode((string)shell_exec(escapeshellarg(dirname(__DIR__) . '/core/bin/kienzle-modul-pruefen') . ' --alle --json ' . escapeshellarg(dirname(__DIR__) . '/modules')), true);
$reihe = ['kasse', 'terminzettel', 'signatur', 'qm', 'telefon', 'fax', 'homepage'];
$echte = array_values(array_filter($alle['modules'] ?? [], fn($m) => in_array($m['module']['id'], $reihe, true)));
usort($echte, fn($a, $b) => array_search($a['module']['id'], $reihe, true) <=> array_search($b['module']['id'], $reihe, true));
if (count($echte) === count($reihe)) {
    $env->modules = Modules::fromArray($echte);
    $env->users = new Kienzle\Core\Users($env->db, $env->secrets, $env->modules);
    $env->auth = new Kienzle\Core\Auth($env->db, $env->users, $env->audit, '192.0.2.10');
}
[$admin, $annaId] = admin_browser($env, 'anna');
foreach (array_keys($env->modules->all()) as $id) $env->users->setAccess($annaId, $id, true, []);   // Admin sieht alle Module im Menü
[$bert] = $env->user('bert'); $env->users->setRoles($bert, ['Arzt']);
[$clara] = $env->user('clara'); $env->users->setRoles($clara, ['MFA']);
// Erfundene Zugänge: Arzt alle Module, MFA die Module am Empfang
foreach (['kasse', 'signatur', 'qm', 'telefon', 'fax', 'terminzettel', 'homepage'] as $id) if ($env->modules->has($id)) $env->users->setAccess($bert, $id, false, $env->modules->rightIds($id));
foreach (['kasse', 'signatur', 'telefon', 'fax'] as $id) if ($env->modules->has($id)) $env->users->setAccess($clara, $id, false, $env->modules->rightIds($id));
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
file_put_contents(Updates::$state . '/status.json', json_encode(['state' => 'done', 'channel' => 'beta', 'checked_at' => time(), 'local' => ['0.21.4-beta.1', $env->config->version()],
    'available' => ['core' => [['version' => '99.0.0-beta.1', 'prerelease' => true, 'notes' => "Beispiel für eine Versionsmitteilung (erfundene Daten).", 'published' => '2026-11-24', 'signed' => true]]]]));
$up = new Updates($env->db, $env->config); $admin->web = fn() => $admin->defaultWeb(null, $up);
$r = $admin->get('/verwaltung/updates'); echo str_pad('updates', 14) . $r->status . "\n"; if ($r->status === 200) seite('updates', $r->body, $out, $css);
// Arbeitsplatz-Starter: Startkontexte kommen aus den echten Modulen (Kasse, Signatur, Telefon); ein Zertifikat der erfundenen Suite.
$crt = $env->dir . '/server.crt';
exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg($env->dir . '/k.key') . ' -out ' . escapeshellarg($crt) . ' -subj /CN=kienzle.example.org -days 2 2>/dev/null');
$st = new Starter($env->db, $env->secrets, $env->config, dirname(__DIR__) . '/core', $crt);
$admin->web = fn() => new Web($env->config, $env->db, $env->users, new Kienzle\Core\Auth($env->db, $env->users, $env->audit, $admin->ip), $env->passkeys, $env->modules, $env->audit,
    static fn(string $uri) => '<svg/>', null, null, null, null, null, null, null, null, null, $st);
$r = $admin->get('/hilfe/arbeitsplatz'); echo str_pad('starter', 14) . $r->status . "\n"; if ($r->status === 200) seite('starter', $r->body, $out, $css);
// Öffnungszeiten: erfundene Vertretungspraxen, Weihnachtsurlaub mit zwei Vertretungen, Feiertage Baden-Württemberg
Kienzle\Core\Zeiten::$heute = '2026-12-01';
$zt = new Kienzle\Core\Zeiten($env->db);
$zt->wocheSpeichern(['mo' => '08:00-12:00, 14:00-18:00', 'di' => '08:00-12:00, 14:00-18:00', 'mi' => '08:00-12:00', 'do' => '08:00-12:00, 14:00-19:00', 'fr' => '08:00-13:00'],
    ['mo' => '08:00-11:30, 14:00-17:00', 'di' => '08:00-11:30, 14:00-17:00', 'mi' => '08:00-11:30', 'do' => '08:00-11:30, 14:00-18:00', 'fr' => '08:00-12:00']);
$pa = $zt->praxisSpeichern(['name' => 'Praxis Dr. med. Erika Beispiel', 'strasse' => 'Musterstraße 1', 'ort' => '12345 Musterstadt', 'telefon' => '01234 56789']);
$pb = $zt->praxisSpeichern(['name' => 'Hausarztpraxis Max Mustermann', 'strasse' => 'Beispielweg 7', 'ort' => '12345 Musterstadt', 'telefon' => '01234 98765']);
$zid = $zt->ereignisSpeichern(['art' => 'urlaub', 'titel' => 'Weihnachtsurlaub', 'von' => '2026-12-23', 'bis' => '2027-01-03', 'homepage' => '1', 'telefon' => '1',
    'v_von' => ['2026-12-23', '2027-01-02'], 'v_bis' => ['2026-12-31', '2027-01-03'], 'v_praxis' => [$pa, $pb]]);
$zt->ereignisSpeichern(['art' => 'fortbildung', 'titel' => 'Fortbildung Dr. Beispiel', 'von' => '2026-12-11', 'homepage' => '1', 'telefon' => '1', 'v_praxis' => [$pb]]);
$zt->feiertageSpeichern('BW', [], [], true, true);
$admin->web = null;
$r = $admin->get('/verwaltung/zeiten?e=' . $zid); echo str_pad('zeiten', 14) . $r->status . "\n"; if ($r->status === 200) seite('zeiten', $r->body, $out, $css);
$r = $admin->get('/verwaltung/zeiten?ansicht=feiertage&jahr=2027'); echo str_pad('feiertage', 14) . $r->status . "\n"; if ($r->status === 200) seite('feiertage', $r->body, $out, $css);
$anon = new Browser($env);
seite('anmelden', $anon->get('/anmelden')->body, $out, $css);
echo "Seiten in $out\n";
