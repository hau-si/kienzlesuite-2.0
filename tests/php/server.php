<?php
declare(strict_types=1);
// Verwaltung → Server: Servernamen und Praxisname (ohne root-Dienst).
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\Server;

function test_server(): void {
    $env = new Env();
    Server::$state = $env->dir . '/servername'; mkdir(Server::$state);
    Server::$unit = $env->dir . '/kienzle-servername.path'; touch(Server::$unit);
    [$b] = admin_browser($env);
    $p = $b->get('/verwaltung/server');
    check($p->status === 200 && str_contains($p->body, "kienzle.example.org\n192.0.2.5") && str_contains($p->body, 'value="Praxis Beispiel"'), 'Seite Server');
    check(!str_contains($p->body, 'Noch kein Servername'), 'Name gilt als nicht festgelegt');
    $r = $b->post('/verwaltung/server', ['aktion' => 'speichern', 'namen' => " Kienzle.Praxis.Internal \n192.0.2.5\n", 'praxis' => 'Praxis Dr. Beispiel']);
    check($r->status === 303, 'Speichern');
    $f = $env->dir . '/anfragen/servername.json';
    check(json_decode((string)file_get_contents($f), true) === ['namen' => ['kienzle.praxis.internal', '192.0.2.5'], 'praxis' => 'Praxis Dr. Beispiel'], 'Anfrage');
    check($b->post('/verwaltung/server', ['aktion' => 'speichern', 'namen' => 'b.example.org', 'praxis' => ''])->status === 422, 'zweite Anfrage, solange die erste wartet');
    unlink($f);
    foreach (['', 'a b!', str_repeat('x', 254), "ok.example.org\n" . str_repeat("b.example.org\n", 0) . implode("\n", array_map(fn($i) => "n$i.example.org", range(1, 11)))] as $bad) {
        check($b->post('/verwaltung/server', ['aktion' => 'speichern', 'namen' => $bad, 'praxis' => ''])->status === 422, 'ungültige Namen: ' . mb_substr($bad, 0, 30));
    }
    check($b->post('/verwaltung/server', ['aktion' => 'speichern', 'namen' => 'ok.example.org', 'praxis' => '<b>'])->status === 422, 'Praxisname mit <');
    check(str_contains(implode("\n", $env->audit->recent()), 'servername kienzle.praxis.internal'), 'nicht protokolliert');
    $env->user('bert');
    $m = new Browser($env);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/server')->status === 403, 'Mitarbeiter auf der Seite Server');
}

run_tests();
