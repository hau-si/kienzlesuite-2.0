<?php
declare(strict_types=1);
// Einrichtungs-Überblick (Verwaltung → Einrichtung).
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\{Backup, Overview};

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

run_tests();
