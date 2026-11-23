<?php
declare(strict_types=1);
// Einrichtungs-Überblick (Verwaltung → Einrichtung).
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\{Backup, Overview, Server};

function test_ueberblick(): void {
    $env = new Env();
    Overview::$state = $env->dir . '/ueberblick'; mkdir(Overview::$state);
    file_put_contents(Overview::$state . '/beispiel.json', json_encode(['zeit' => time(), 'fehler' => '', 'items' => [
        ['title' => 'Beispiel <b>eingerichtet', 'state' => 'done', 'hint' => 'Nichts zu tun.', 'link' => '/beispiel'],
        ['title' => 'Fremder Link', 'state' => 'open', 'hint' => '', 'link' => '/verwaltung/benutzer'],
        ['title' => 'Kaputt', 'state' => 'unbekannt']]]));
    Backup::$state = $env->dir . '/sicherung'; mkdir(Backup::$state);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, null, new Backup($env->db, $env->modules));
    $p = $b->get('/verwaltung/einrichtung');
    check($p->status === 200 && str_contains($p->body, 'Admin mit Authenticator-App') && str_contains($p->body, '1 von 1 Admins mit OTP'), 'Punkte der Basis fehlen');
    check(str_contains($p->body, 'Mindestens zwei Admins') && str_contains($p->body, 'Datensicherung eingerichtet'), 'Vertretung oder Sicherung fehlt');
    check(str_contains($p->body, '1 offen') && str_contains($p->body, 'Fremder Link') && !str_contains($p->body, 'Kaputt') && !str_contains($p->body, 'Beispiel &lt;b&gt;eingerichtet'), 'Überblick des Moduls falsch (Link-Karte mit Zähler erwartet)');
    check(!str_contains($p->body, '<a href="/verwaltung/benutzer">Fremder Link'), 'fremder Link aus dem Modul übernommen');
    check(str_contains($p->body, 'Noch nicht gesammelt'), 'fehlender Überblick nicht gemeldet');
    $r = $b->post('/verwaltung/einrichtung', ['aktion' => 'bestaetigen', 'punkt' => 'ca', 'erledigt' => '1']);
    check($r->status === 303, 'Bestätigen');
    $o = new Overview($env->db, $env->users, $env->modules);
    $ca = array_values(array_filter($o->core(), fn($i) => $i['manuell'] === 'ca'))[0];
    check($ca['state'] === 'done', 'Bestätigung nicht gespeichert');
    check(str_contains(implode("\n", $env->audit->recent()), 'einrichtung-erledigt ca'), 'nicht protokolliert');
    check($b->post('/verwaltung/einrichtung', ['aktion' => 'bestaetigen', 'punkt' => 'egal', 'erledigt' => '1'])->status === 422, 'unbekannter Punkt');
    $b->post('/verwaltung/einrichtung', ['aktion' => 'neu']);
    check(is_file($env->dir . '/anfragen/ueberblick.json'), 'Neu prüfen ohne Anfrage');
    $env->user('bert');
    $m = new Browser($env);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/einrichtung')->status === 403, 'Mitarbeiter auf der Einrichtungsseite');
}

function test_namensaenderung_setzt_punkte_rot(): void {
    $env = new Env();
    Server::$state = $env->dir . '/servername'; mkdir(Server::$state);
    Server::$unit = $env->dir . '/kienzle-servername.path'; touch(Server::$unit);
    Backup::$state = $env->dir . '/sicherung'; mkdir(Backup::$state);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, null, new Backup($env->db, $env->modules));
    foreach (['ca', 'codes', 'name', 'anmeldung', 'passkeys'] as $k) $b->post('/verwaltung/einrichtung', ['aktion' => 'bestaetigen', 'punkt' => $k, 'erledigt' => '1']);
    $o = new Overview($env->db, $env->users, $env->modules, null, null, null, null, $env->config);
    $state = fn(string $k) => array_values(array_filter($o->core(), fn($i) => $i['manuell'] === $k))[0]['state'];
    check($state('name') === 'done' && $state('anmeldung') === 'done', 'Ausgangslage nicht erledigt');
    // Praxisname ändern ist keine Namensänderung des Servers
    $b->post('/verwaltung/praxis', ['aktion' => 'name', 'praxis' => 'Praxis Neu']);
    @unlink($env->dir . '/anfragen/servername.json');
    check($state('name') === 'done', 'Praxisname setzt Servername-Punkte zurück');
    // Servername ändern
    $b->post('/verwaltung/server', ['aktion' => 'speichern', 'kurzname' => 'neuername', 'endung' => '.praxis.internal', 'weitere' => '']);
    check(is_file($env->dir . '/anfragen/ueberblick.json'), 'Module werden nicht neu geprüft');
    foreach (['name', 'anmeldung', 'passkeys'] as $k) check($state($k) === 'error', "Punkt $k nicht rot nach Namensänderung");
    check($state('ca') === 'done' && $state('codes') === 'done', 'CA oder Codes zu Unrecht zurückgesetzt');
    $titel = array_column(array_filter($o->core(), fn($i) => str_starts_with($i['title'], 'Servername festgelegt')), 'state');
    check($titel === ['error'], 'Servername festgelegt nicht rot: ' . json_encode($titel));
    $seite = $b->get('/verwaltung/einrichtung')->body;
    check(str_contains($seite, 'Der Servername wurde geändert') && str_contains($seite, 'Erneut nötig, weil der Servername') && str_contains($seite, 'class="card group"')
        && str_contains($seite, 'Servername und Arbeitsplätze') && str_contains($seite, 'Zugang und Konten'), 'Seite zeigt Hinweis oder Gruppen nicht');
    // erneut bestätigen
    $b->post('/verwaltung/einrichtung', ['aktion' => 'bestaetigen', 'punkt' => 'name', 'erledigt' => '1']);
    check($state('name') === 'done' && $state('anmeldung') === 'error', 'erneutes Bestätigen wirkt nicht einzeln');
    // neue Zertifizierungsstelle: auch die CA ist erneut nötig
    @unlink($env->dir . '/anfragen/servername.json');
    $b->post('/verwaltung/server', ['aktion' => 'speichern', 'kurzname' => 'neuername', 'endung' => '.praxis.internal', 'weitere' => '', 'neue_ca' => '1']);
    check($state('ca') === 'error', 'neue CA lässt Zertifizierungsstellen-Punkt nicht rot werden');
}

run_tests();
