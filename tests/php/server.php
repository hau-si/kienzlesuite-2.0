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
    check($p->status === 200 && str_contains($p->body, '<option value="">ohne Endung (empfohlen)') && !str_contains($p->body, 'name="praxis"'), 'Seite Server (ohne Praxisname)');
    check(str_contains($p->body, 'name="kurzname" value="kienzle"') && str_contains($p->body, '<option value=".example.org" selected>.example.org (bisher)') && str_contains($p->body, 'über 192.0.2.5 erreichbar'), 'heutiger Name mit eigener Endung fehlt');
    $r = $b->post('/verwaltung/server', ['aktion' => 'speichern', 'kurzname' => ' Kienzle ', 'endung' => '.praxis.internal', 'weitere' => '']);
    check($r->status === 303, 'Speichern');
    $f = $env->dir . '/anfragen/servername.json';
    check(json_decode((string)file_get_contents($f), true) === ['namen' => ['kienzle.praxis.internal', '192.0.2.5'], 'praxis' => 'Praxis Beispiel'], 'Anfrage (Praxisname bleibt unverändert): ' . file_get_contents($f));
    $ok = ['aktion' => 'speichern', 'kurzname' => 'kienzle', 'endung' => '.fritz.box', 'weitere' => ''];
    check($b->post('/verwaltung/server', $ok)->status === 422, 'zweite Anfrage, solange die erste wartet');
    unlink($f);
    check($b->post('/verwaltung/server', ['aktion' => 'speichern', 'kurzname' => 'praxisherz', 'endung' => '', 'weitere' => ''])->status === 303
        && json_decode((string)file_get_contents($f), true)['namen'] === ['praxisherz', '192.0.2.5'], 'Name ohne Endung: ' . @file_get_contents($f));
    unlink($f);
    $seite = $b->get('/verwaltung/praxis')->body;
    check(str_contains($seite, 'name="praxis" value="Praxis Beispiel"') && str_contains($seite, 'Name der Praxis <span class="tag ok">✓ eingetragen</span>'), 'Praxisname steht unter Praxis');
    $r = $b->post('/verwaltung/praxis', ['aktion' => 'name', 'praxis' => ' Praxis Dr. Beispiel ']);
    check($r->status === 200 && json_decode((string)file_get_contents($f), true) === ['namen' => ['kienzle.example.org', '192.0.2.5'], 'praxis' => 'Praxis Dr. Beispiel'], 'Praxisname: Anfrage mit unveränderten Servernamen');
    unlink($f);
    check($b->post('/verwaltung/praxis', ['aktion' => 'name', 'praxis' => '<b>'])->status === 422 && !is_file($f), 'Praxisname mit < abgelehnt');
    $r = $b->post('/verwaltung/praxis', ['aktion' => 'anschrift', 'street' => 'Musterweg 1', 'city' => '12345 Musterstadt', 'contact' => 'Telefon 01234 56789']);
    check($r->status === 200 && str_contains($b->get('/verwaltung/praxis')->body, 'value="Musterweg 1"'), 'Anschrift speichern');
    check($b->post('/verwaltung/praxis', ['aktion' => 'anschrift', 'street' => '<b>', 'city' => '', 'contact' => ''])->status === 422, 'Anschrift mit < abgelehnt');
    foreach ([['kurzname' => ''], ['kurzname' => 'a b'], ['kurzname' => '-x'], ['endung' => '.example.net'], ['weitere' => 'a b!'],
              ['weitere' => implode("\n", array_map(fn($i) => "n$i.example.org", range(1, 11)))]] as $bad) {
        check($b->post('/verwaltung/server', $bad + $ok)->status === 422, 'ungültig angenommen: ' . json_encode($bad));
    }
    $b->post('/verwaltung/server', ['ohne_ip' => '1', 'weitere' => "kienzle.example.org"] + $ok);
    check(json_decode((string)file_get_contents($f), true)['namen'] === ['kienzle.fritz.box', 'kienzle.example.org'], 'ohne IP / weitere Namen');
    unlink($f);
    check(str_contains(implode("\n", $env->audit->recent()), 'servername kienzle.praxis.internal'), 'nicht protokolliert');
    $h = (new Browser($env))->get('/hilfe/arbeitsplatz');
    check($h->status === 200 && str_contains($h->body, 'id="zertifikat"') && str_contains($h->body, '192.0.2.5   kienzle.example.org'), 'Hilfe ohne Anmeldung');
    // Paket des Praxisherz-Agent: nur wenn ein installiertes Modul aus T2med gestartet wird
    check((bool)$env->modules->starters() === str_contains($h->body, 'id="paket"'), 'Hilfe Arbeitsplätze: Paket-Hinweis');
    $json = json_decode((string)shell_exec(escapeshellarg(dirname(__DIR__, 2) . '/core/bin/kienzle-modul-pruefen') . ' --alle --json ' . escapeshellarg(dirname(__DIR__, 2) . '/modules')), true);
    $kasse = array_values(array_filter($json['modules'], fn($x) => $x['module']['id'] === 'kasse'))[0];
    $env->modules = \Kienzle\Core\Modules::fromArray([$kasse]);
    $mitKasse = (new Browser($env))->get('/hilfe/arbeitsplatz')->body;
    check(str_contains($mitKasse, 'id="paket"') && str_contains($mitKasse, 'Als Admin anmelden'), 'Hilfe Arbeitsplätze: Paketabschnitt für die Kasse fehlt');
    check(!str_contains($b->get('/verwaltung/server')->body, 'Name, Anschrift, Konto und Steuer der Praxis'), 'Server: Verweis auf Praxis noch da');
    $env->user('bert');
    $m = new Browser($env);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/server')->status === 403, 'Mitarbeiter auf der Seite Server');
}

/** Name ohne Endung (z. B. „praxisherz“ wie bei der Erstinstallation): stand bis Basis beta.56 als leeres Feld da. */
function test_server_name_ohne_endung(): void {
    $env = new Env();
    $env->config = \Kienzle\Core\Config::fromArray(['hostnames' => ['192.0.2.20', 'praxisherz', 'kienzle.example.org'], 'data_dir' => $env->dir, 'secret_key' => $env->dir . '/secret.key',
        'modules' => $env->dir . '/modules.json', 'practice' => 'Praxis Beispiel']);
    [$b] = admin_browser($env);
    $p = $b->get('/verwaltung/server');
    check(str_contains($p->body, 'name="kurzname" value="praxisherz"') && str_contains($p->body, '<option value="" selected>'), 'Name ohne Endung wird nicht angezeigt');
    check(str_contains($p->body, 'erreichbar unter <strong>https://192.0.2.20/</strong>, <strong>https://praxisherz/</strong>'), 'aktuelle Adressen fehlen');
}

run_tests();
