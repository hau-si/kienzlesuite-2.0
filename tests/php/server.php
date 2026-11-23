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
    check($p->status === 200 && str_contains($p->body, 'name="kurzname" value="kienzle"') && str_contains($p->body, 'value="Praxis Beispiel"'), 'Seite Server');
    check(str_contains($p->body, 'kienzle.example.org</textarea>') && str_contains($p->body, 'über 192.0.2.5 erreichbar'), 'weitere Namen oder IP fehlen');
    $r = $b->post('/verwaltung/server', ['aktion' => 'speichern', 'kurzname' => ' Kienzle ', 'endung' => '.praxis.internal', 'weitere' => '', 'praxis' => 'Praxis Dr. Beispiel']);
    check($r->status === 303, 'Speichern');
    $f = $env->dir . '/anfragen/servername.json';
    check(json_decode((string)file_get_contents($f), true) === ['namen' => ['kienzle.praxis.internal', '192.0.2.5'], 'praxis' => 'Praxis Dr. Beispiel'], 'Anfrage: ' . file_get_contents($f));
    $ok = ['aktion' => 'speichern', 'kurzname' => 'kienzle', 'endung' => '.fritz.box', 'weitere' => '', 'praxis' => ''];
    check($b->post('/verwaltung/server', $ok)->status === 422, 'zweite Anfrage, solange die erste wartet');
    unlink($f);
    $r = $b->post('/verwaltung/praxis', ['aktion' => 'anschrift', 'street' => 'Musterweg 1', 'city' => '12345 Musterstadt', 'contact' => 'Telefon 01234 56789']);
    check($r->status === 200 && str_contains($b->get('/verwaltung/praxis')->body, 'value="Musterweg 1"'), 'Anschrift speichern');
    check($b->post('/verwaltung/praxis', ['aktion' => 'anschrift', 'street' => '<b>', 'city' => '', 'contact' => ''])->status === 422, 'Anschrift mit < abgelehnt');
    foreach ([['kurzname' => ''], ['kurzname' => 'a b'], ['kurzname' => '-x'], ['endung' => '.example.org'], ['weitere' => 'a b!'],
              ['weitere' => implode("\n", array_map(fn($i) => "n$i.example.org", range(1, 11)))], ['praxis' => '<b>']] as $bad) {
        check($b->post('/verwaltung/server', $bad + $ok)->status === 422, 'ungültig angenommen: ' . json_encode($bad));
    }
    $b->post('/verwaltung/server', ['ohne_ip' => '1', 'weitere' => "kienzle.example.org"] + $ok);
    check(json_decode((string)file_get_contents($f), true)['namen'] === ['kienzle.fritz.box', 'kienzle.example.org'], 'ohne IP / weitere Namen');
    unlink($f);
    check(str_contains(implode("\n", $env->audit->recent()), 'servername kienzle.praxis.internal'), 'nicht protokolliert');
    $h = (new Browser($env))->get('/hilfe/arbeitsplatz');
    check($h->status === 200 && str_contains($h->body, 'id="zertifikat"') && str_contains($h->body, '192.0.2.5   kienzle.example.org'), 'Hilfe ohne Anmeldung');
    $env->user('bert');
    $m = new Browser($env);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/server')->status === 403, 'Mitarbeiter auf der Seite Server');
}

run_tests();
