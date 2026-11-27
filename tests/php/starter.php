<?php
declare(strict_types=1);
// Zentraler Arbeitsplatz-Starter: Schlüssel, Prüfpfad für nginx, Seite „Arbeitsplätze“, Paket. Nur erfundene Testdaten.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\{Modules, Starter, Web};

function st_env(): array {
    $env = new Env();
    $mods = [];
    foreach ($env->modules->all() as $id => $m) {
        if ($id === 'kasse') $m['starters'] = [['schema' => 'praxisherz-kasse', 'label' => 'Kasse'], ['schema' => 'kienzle-igel', 'label' => 'IGeL <b>']];
        $mods[] = $m;
    }
    $env->modules = Modules::fromArray($mods);
    $crt = $env->dir . '/server.crt';
    exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg($env->dir . '/k.key') . ' -out ' . escapeshellarg($crt) . ' -subj /CN=kienzle.example.org -days 2 2>/dev/null', $o, $code);
    check($code === 0 && is_file($crt), 'Testzertifikat nicht erzeugt');
    copy($crt, $env->dir . '/ca.crt');   // Zertifizierungsstelle neben server.crt (im Test dasselbe selbstsignierte Zertifikat)
    $st = new Starter($env->db, $env->secrets, $env->config, dirname(__DIR__, 2) . '/core', $crt);
    return [$env, $st];
}

function st_web(Env $env, Starter $st, $b): void {
    $b->web = fn() => new Web($env->config, $env->db, $env->users, new Kienzle\Core\Auth($env->db, $env->users, $env->audit, $b->ip), $env->passkeys, $env->modules, $env->audit,
        static fn(string $uri) => '<svg/>', null, null, null, null, null, null, null, null, null, $st);
}

function test_schluessel(): void {
    [$env, $st] = st_env();
    check(!$st->exists() && !$st->verify('Bearer x'), 'ohne Schlüssel darf nichts durch');
    $k = $st->key();
    check(preg_match('/^[0-9a-f]{64}$/D', $k) === 1 && $st->key() === $k, 'Schlüssel nicht stabil');
    check(!str_contains((string)$env->db->setting('starter:schluessel'), $k), 'Schlüssel im Klartext gespeichert');
    check($st->verify('Bearer ' . $k) && !$st->verify($k) && !$st->verify('Bearer ' . substr($k, 1)) && !$st->verify('Bearer '), 'Prüfung');
    $neu = $st->renew();
    check($neu !== $k && !$st->verify('Bearer ' . $k) && $st->verify('Bearer ' . $neu), 'Erneuern');
}

function test_pruefpfad(): void {
    [$env, $st] = st_env();
    $b = admin_browser($env)[0]; st_web($env, $st, $b);
    $k = $st->key();
    check($b->get('/_starter-pruefen', ['authorization' => 'Bearer ' . $k])->status === 204, 'richtiger Schlüssel');
    check($b->get('/_starter-pruefen', ['authorization' => 'Bearer falsch'])->status === 401, 'falscher Schlüssel');
    check($b->get('/_starter-pruefen')->status === 401, 'ohne Kopf');
    for ($i = 0; $i < 12; $i++) $b->get('/_starter-pruefen', ['authorization' => 'Bearer falsch' . $i]);
    check($b->get('/_starter-pruefen', ['authorization' => 'Bearer ' . $k])->status === 429, 'Bremse nach Fehlversuchen');
}

function test_seite_und_paket(): void {
    [$env, $st] = st_env();
    [$b] = admin_browser($env); st_web($env, $st, $b);
    check($b->get('/verwaltung/arbeitsplaetze')->status === 303, 'alte Starter-Seite leitet zu den Arbeitsplätzen');
    $page = $b->get('/hilfe/arbeitsplatz');
    check($page->status === 200 && str_contains($page->body, 'id="paket"') && str_contains($page->body, 'praxisherz-kasse://?kontextId=') && str_contains($page->body, 'kienzle-igel://'), 'Startkontexte fehlen');
    check(str_contains($page->body, 'Frühere Adresse <code>kienzle-kasse://…</code>') && substr_count($page->body, 'Frühere Adresse') === 1, 'Hinweis auf die frühere Adresse');
    check(!str_contains($page->body, 'IGeL <b>') && str_contains($page->body, 'IGeL &lt;b&gt;'), 'nicht maskiert');
    check(str_contains($page->body, '/verwaltung/arbeitsplaetze/windows.zip'), 'Paket-Link fehlt');
    $zip = $b->get('/verwaltung/arbeitsplaetze/linux.zip');
    check($zip->status === 200 && $zip->header('Content-Type') === 'application/zip' && str_starts_with($zip->body, "PK\x03\x04"), 'ZIP');
    check(str_contains($zip->body, 'install-linux.sh') && str_contains($zip->body, 'schemas.txt') && str_contains($zip->body, 'kienzle-igel') && str_contains($zip->body, '"/starter/kasse"'), 'Paketinhalt');
    check(str_contains($zip->body, '"praxisherz-kasse": "/starter/kasse"') && str_contains($zip->body, '"kienzle-kasse": "/starter/kasse"'), 'Paket richtet neue und frühere Adresse ein');
    check(str_contains($zip->body, $st->key()) && str_contains($zip->body, '"server_url": "https://kienzle.example.org"'), 'client.json');
    check(str_contains($zip->body, 'ca.crt'), 'Paket enthält die Zertifizierungsstelle');
    check($b->get('/verwaltung/arbeitsplaetze/amiga.zip')->status === 404, 'unbekanntes System');
    $mitDrucker = $st->files('windows', ['kienzle-kasse' => '/starter/kasse'], 'https://kienzle.example.org', [['name' => 'vKasse', 'url' => 'http://kienzle.example.org:631/printers/vKasse', 'papier' => 'A4']]);
    check(($mitDrucker['drucker.txt']['data'] ?? '') === "vKasse|http://kienzle.example.org:631/printers/vKasse|A4\n" && isset($mitDrucker['install-drucker.ps1']), 'Paket ohne Druckerliste');
    check(!isset($st->files('windows', ['kienzle-kasse' => '/starter/kasse'], 'https://kienzle.example.org')['drucker.txt']), 'drucker.txt ohne Drucker');
    $alt = $st->key();
    $vorher = $st->key();
    check($b->post('/verwaltung/arbeitsplaetze', ['aktion' => 'erneuern'])->status >= 400 && $st->key() === $vorher, 'Erneuern ohne Bestätigung darf nichts ändern');
    check($b->post('/verwaltung/arbeitsplaetze', ['aktion' => 'erneuern', 'bestaetigt' => '1'])->status === 303 && $st->key() !== $alt, 'Erneuern über die Seite');
    check($b->post('/verwaltung/arbeitsplaetze', ['aktion' => 'boese'])->status === 400, 'unbekannte Aktion');
    $map = $env->modules->starterMap();
    check($map === ['kasse' => '/kasse'], 'starterMap');
}

function test_ohne_startkontexte(): void {
    $env = new Env();
    $st = new Starter($env->db, $env->secrets, $env->config, dirname(__DIR__, 2) . '/core');
    [$b] = admin_browser($env); st_web($env, $st, $b);
    $page = $b->get('/hilfe/arbeitsplatz');
    check($page->status === 200 && !str_contains($page->body, 'id="paket"'), 'Paketabschnitt ohne Startkontexte');
    problem(fn() => $st->files('linux', [], 'https://kienzle.example.org'), 404, 'Paket ohne Kontexte');
}

run_tests();
