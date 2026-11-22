<?php
declare(strict_types=1);
// Uhr des Servers geht falsch: Erkennung über den Code der App und Angebot, die Uhr zu stellen.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\{Server, Totp};

function test_einrichtung_mit_falscher_uhr(): void {
    $env = new Env();
    Server::$state = $env->dir . '/servername'; mkdir(Server::$state);
    Server::$unit = $env->dir . '/kienzle-servername.path'; touch(Server::$unit);
    $env->user('anna', true);
    $b = new Browser($env);
    $b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $page = $b->get('/konto/otp');
    check(preg_match('#<code class="secret">([A-Z2-7 ]+)</code>#', $page->body, $m) === 1, 'Schlüssel nicht angezeigt');
    $secret = str_replace(' ', '', $m[1]);
    // Telefon geht richtig, Server eine Stunde nach: Code des Telefons entspricht Serverzeit + 3600 s.
    $r = $b->post('/konto/otp', ['code' => Totp::code($secret, Totp::step(time() + 3600))]);
    check($r->status === 422 && str_contains($r->body, 'Uhr des Servers geht um etwa 60 Minuten nach') && str_contains($r->body, 'Uhrzeit des Servers korrigieren'), 'Abweichung nicht erkannt');
    check(str_contains($r->body, $m[1]), 'nach dem Fehlschlag neuer Schlüssel (neu scannen nötig)');
    $fix = $b->post('/uhrzeit/korrigieren', []);
    check($fix->status === 200, 'Korrektur');
    $req = json_decode((string)file_get_contents($env->dir . '/anfragen/servername.json'), true);
    check($req['aktion'] === 'uhrzeit' && abs($req['delta'] - 3600) <= 30, 'Anfrage an den Dienst: ' . json_encode($req));
    check(str_contains(implode("\n", $env->audit->recent()), 'uhrzeit-korrigiert'), 'nicht protokolliert');
    unlink($env->dir . '/anfragen/servername.json');
    // Abweichung gilt einmal: zweites Absenden ohne neuen Code tut nichts.
    $b->post('/uhrzeit/korrigieren', []);
    check(!is_file($env->dir . '/anfragen/servername.json'), 'Korrektur ohne erneuten Code');
    // Völlig falscher Code: keine Korrektur angeboten.
    $r = $b->post('/konto/otp', ['code' => '000000']);
    check(!str_contains($r->body, 'Uhrzeit des Servers korrigieren') || Totp::skew($secret, '000000') !== null, 'Korrektur ohne passenden Code angeboten');
    // Fremde Seite kann die Uhr nicht stellen.
    check($b->post('/uhrzeit/korrigieren', [], ['origin' => 'https://evil.example'])->status === 403, 'fremde Herkunft');
}

function test_anmeldung_mit_falscher_uhr(): void {
    $env = new Env();
    Server::$state = $env->dir . '/servername'; mkdir(Server::$state);
    Server::$unit = $env->dir . '/kienzle-servername.path'; touch(Server::$unit);
    [, $secret] = $env->user('anna', true, otp: true);
    $b = new Browser($env);
    $b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $r = $b->post('/anmelden/code', ['code' => Totp::code($secret, Totp::step(time() - 600))]);
    check(str_contains($r->body, 'Uhr des Servers geht um etwa 10 Minuten vor'), 'Abweichung bei der Anmeldung nicht erkannt');
    $b->post('/uhrzeit/korrigieren', []);
    $req = json_decode((string)file_get_contents($env->dir . '/anfragen/servername.json'), true);
    check(abs($req['delta'] + 600) <= 30, 'Anfrage bei der Anmeldung');
}

run_tests();
