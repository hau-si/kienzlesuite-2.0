<?php
declare(strict_types=1);
// Startseite: Server-Zustand (Speicher, CPU, Temperatur), Sicherung, Guthaben mit Aktualisieren-Knopf, Symbole. Nur erfundene Daten.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';
require __DIR__ . '/verbindungen_hilfe.php';

use Kienzle\Core\{Backup, Connections, SystemStatus};

/** Nachgebildetes /proc und /sys in einem Verzeichnis. */
function sys_fixture(string $dir, array $o = []): SystemStatus {
    foreach (['proc', 'sys/class/thermal/thermal_zone0', 'sys/class/hwmon/hwmon1'] as $d) mkdir("$dir/$d", 0777, true);
    file_put_contents("$dir/proc/meminfo", "MemTotal:        8000000 kB\nMemFree: 100 kB\nMemAvailable:    " . ($o['ram'] ?? 6000000) . " kB\n");
    file_put_contents("$dir/proc/loadavg", ($o['last'] ?? '0.50') . " 0.40 0.30 1/200 999\n");
    file_put_contents("$dir/proc/cpuinfo", "processor\t: 0\nmodel name\t: Test\n\nprocessor\t: 1\nmodel name\t: Test\n");
    file_put_contents("$dir/proc/uptime", "190000.55 3000.00\n");
    if (isset($o['temp'])) file_put_contents("$dir/sys/class/thermal/thermal_zone0/temp", (string)$o['temp']);
    return new SystemStatus("$dir/proc", "$dir/sys", $dir);
}

function test_systemwerte(): void {
    $dir = tmpdir();
    $v = sys_fixture($dir, ['temp' => 61000, 'last' => '1.00'])->read();
    check($v['ram']['gesamt'] === 8000000.0 * 1024 && $v['ram']['frei'] === 6000000.0 * 1024, 'Arbeitsspeicher');
    check($v['cpu'] === ['last' => 1.0, 'kerne' => 2, 'prozent' => 50], 'Prozessor: ' . json_encode($v['cpu']));
    check($v['temp'] === 61.0 && $v['laufzeit'] === 190000 && $v['disk'] !== null && $v['disk']['gesamt'] > 0, 'Temperatur, Laufzeit oder Speicher');
    $leer = (new SystemStatus("$dir/gibtsnicht", "$dir/gibtsnicht", "$dir/gibtsnicht"))->read();
    check($leer['ram'] === null && $leer['cpu'] === null && $leer['temp'] === null && $leer['laufzeit'] === null, 'fehlende Quellen sind keine Fehler');
    file_put_contents("$dir/sys/class/hwmon/hwmon1/temp1_input", '9999999');
    check(sys_fixture(tmpdir(), [])->read()['temp'] === null && (new SystemStatus("$dir/proc", "$dir/sys", $dir))->read()['temp'] === 61.0, 'unplausible Temperatur ignoriert, ohne Sensor keine Angabe');
    check(SystemStatus::bytes(1073741824 * 250.0) === '250 GB' && SystemStatus::bytes(1073741824 * 4.25) === '4,3 GB' && SystemStatus::bytes(52428800.0) === '50 MB', 'Größenangabe');
    check(SystemStatus::dauer(190000) === '2 Tage 4 Std.' && SystemStatus::dauer(90000) === '1 Tag 1 Std.' && SystemStatus::dauer(7300) === '2 Std.' && SystemStatus::dauer(30) === '1 Min.', 'Laufzeit-Text');
}

function test_startseite_kacheln_und_guthaben(): void {
    [$env, $c] = con_env(['sms' => ['anbieter' => 'seven', 'absender' => 'Praxis', 'schluessel_gesetzt' => true],
        'letterxpress' => ['username' => 'praxis-test', 'modus' => 'test', 'schluessel_gesetzt' => true],
        'guthaben' => ['letterxpress' => ['wert' => 12.5, 'zeit' => time()], 'sms' => ['wert' => 3.2, 'zeit' => time()]]]);
    Backup::$state = $env->dir . '/sicherung'; mkdir(Backup::$state);
    file_put_contents(Backup::$state . '/status.json', json_encode(['eingerichtet' => 1, 'letzte_sicherung' => ['ok' => true, 'zeit' => time() - 3600, 'meldung' => 'ok']]));
    $sys = sys_fixture($env->dir . '/fx', ['temp' => 90000, 'last' => '0.20']);
    [$b] = admin_browser($env);
    $b->web = function () use ($b, $c, $env, $sys) { $w = $b->defaultWeb(null, null, $c, new Backup($env->db, $env->modules)); $w->system = $sys; return $w; };
    $p = $b->get('/')->body;
    check(str_contains($p, 'Server-Zustand') && str_contains($p, 'Speicherplatz') && str_contains($p, 'Arbeitsspeicher') && str_contains($p, '5 % ausgelastet') === false && str_contains($p, '10 % ausgelastet'), 'Kacheln für Speicher, RAM und CPU');
    check(str_contains($p, '<div class="tile bad"><span class="t-ico">') && str_contains($p, '90 °C'), 'Hohe Temperatur nicht als Warnung');
    check(str_contains($p, 'Letzte Sicherung') && str_contains($p, 'in Ordnung') && str_contains($p, 'href="/verwaltung/sicherung"'), 'Sicherungs-Kachel');
    check(str_contains($p, '12,50 €') && str_contains($p, 'Guthaben Letterxpress') && str_contains($p, 'Guthaben seven.io') && str_contains($p, '3,20 €') && str_contains($p, '<div class="tile warn">'), 'Guthaben (unter 5 € = gelb)');
    check(substr_count($p, 'title="Guthaben jetzt abfragen"') === 2 && str_contains($p, 'name="zurueck" value="start"') && str_contains($p, 'name="id" value="sms"'), 'Aktualisieren-Knöpfe');
    check(substr_count($p, "class=\"h-ico\"") === 3, "Symbole vor den Überschriften: " . substr_count($p, "class=\"h-ico\""));
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'pruefen', 'art' => 'guthaben', 'id' => 'sms', 'zurueck' => 'start']);
    check($r->status === 303 && $r->header('Location') === '/' && req_v($env) === ['action' => 'pruefen', 'art' => 'guthaben', 'id' => 'sms'], 'Abfrage des seven.io-Guthabens mit Rücksprung zur Startseite');
    $b->post('/verwaltung/verbindungen', ['aktion' => 'pruefen', 'art' => 'guthaben', 'id' => '']);
    check(req_v($env) === ['action' => 'pruefen', 'art' => 'guthaben', 'id' => ''], 'Letterxpress-Abfrage');
    $b->post('/verwaltung/verbindungen', ['aktion' => 'pruefen', 'art' => 'guthaben', 'id' => 'sms']);
    $w = $b->get('/');
    check($w->header('Refresh') === '3; url=/' && str_contains($w->body, 'wird abgefragt'), 'Während der Abfrage lädt die Seite neu');
}

function test_startseite_ohne_einrichtung(): void {
    [$env, $c] = con_env(['sms' => null, 'letterxpress' => null]);
    Backup::$state = $env->dir . '/sicherung'; mkdir(Backup::$state);
    file_put_contents(Backup::$state . '/status.json', json_encode(['eingerichtet' => 0]));
    [$b] = admin_browser($env);
    $b->web = function () use ($b, $c, $env) { $w = $b->defaultWeb(null, null, $c, new Backup($env->db, $env->modules)); $w->system = new SystemStatus('/gibt/es/nicht', '/gibt/es/nicht', '/gibt/es/nicht'); return $w; };
    $p = $b->get('/')->body;
    check(str_contains($p, 'nicht eingerichtet') && !str_contains($p, 'Guthaben') && !str_contains($p, 'Temperatur') && $b->get('/')->status === 200, 'Ohne Verbindungen keine Guthaben-Kacheln, Sicherung als nicht eingerichtet');
    $env->user('bert');
    $m = new Browser($env);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $ps = $m->get('/')->body;
    check(!str_contains($ps, 'Server-Zustand') && !str_contains($ps, 'Speicherplatz'), 'Mitarbeiter sehen keine Serverwerte');
}

function test_seitenleiste_und_konto_einklappbar(): void {
    [$env, $c] = con_env([]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    $p = $b->get('/')->body;
    check(substr_count($p, '<span class="menu-title">Einstellungen</span>') === 0 && str_contains($p, '<details class="menu-group menu-fold" open><summary class="menu-title">Anwendungen</summary>'), 'Seitenleiste: eine Gruppe Anwendungen, einklappbar');
    check(preg_match('#<a href="/[a-z]+/"[^>]*><span><svg class="ico"#', $p) === 1, 'Symbol vor dem Modul in der Seitenleiste');
    $k = $b->get('/konto')->body;
    foreach (['Passwort ändern', 'Authenticator-App', 'Gemerkte Geräte'] as $titel) {
        check(preg_match('#<details class="fold"><summary><h2>[^<]*(?:<svg.*?</svg>)?' . preg_quote($titel, '#') . '#s', $k) === 1, 'Konto: „' . $titel . '“ nicht einklappbar');
    }
    check(preg_match('#<summary><h2>.*?Authenticator-App</h2><span class="tag ok fold-status">eingerichtet</span>#s', $k) === 1, 'Konto: Status im eingeklappten Kopf');
}

run_tests();
