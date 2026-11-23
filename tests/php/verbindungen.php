<?php
declare(strict_types=1);
// Verbindungen im Webinterface (ohne root-Dienst) und Hilfsdatei für Module (core/php/modul/Kienzle.php).
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';
require dirname(__DIR__, 2) . '/core/php/modul/Kienzle.php';

use Kienzle\Core\Connections;

function con_env(array $status = []): array {
    $env = new Env();
    Connections::$state = $env->dir . '/verb'; mkdir(Connections::$state);
    Connections::$unit = $env->dir . '/kienzle-verbindungen.path'; touch(Connections::$unit);
    file_put_contents(Connections::$state . '/status.json', json_encode($status + [
        't2med' => ['base_url' => 'https://t2med.example.org:16567/fhir', 'launch_urls' => ['https://t2med.example.org:16567/fhir'], 'pin' => false, 'api_key_gesetzt' => true, 'zertifikat' => ''],
        'mail' => null, 'ablage' => [['id' => 'steuerbuero', 'name' => 'Steuer<b>büro', 'art' => 'smb', 'host' => 'nas.example.org', 'freigabe' => 'praxis', 'ordner' => 'Kienzle', 'passwort_gesetzt' => true]],
        'tests' => ['t2med' => ['ok' => true, 'meldung' => 'FHIR-Server antwortet', 'zeit' => time()]],
        'nutzung' => [['modul' => 'beispiel', 'name' => 'Beispielmodul', 'verbindung' => 't2med', 'zweck' => 'Patient lesen', 'pflicht' => true]],
        'letzte' => ['ok' => false, 'meldung' => 'Fehler <script>', 'zeit' => time()], 'sftp_schluessel' => 'ssh-ed25519 AAAATEST ks-ablage']));
    return [$env, new Connections($env->db)];
}

function req_v(Env $env): array {
    $f = $env->dir . '/anfragen/verbindungen.json';
    $r = json_decode((string)file_get_contents($f), true);
    check(substr(sprintf('%o', fileperms($f)), -3) === '600', 'Anfrage mit Passwörtern nicht 0600');
    unlink($f);
    return $r;
}

function test_anfragen(): void {
    [$env, $c] = con_env();
    $c->saveT2med(['base_url' => ' https://t2med.example.org:16567/fhir ', 'launch_urls' => "https://a.example.org/x\n\n", 'api_key' => '', 'pin' => false]);
    check(req_v($env) === ['action' => 't2med', 'daten' => ['base_url' => 'https://t2med.example.org:16567/fhir', 'launch_urls' => ['https://a.example.org/x'], 'api_key' => '', 'pin' => false]],
        'T2med-Anfrage falsch (ohne ca_pem = Zertifikat unverändert)');
    $c->saveT2med(['base_url' => 'https://t2med.example.org/fhir', 'ca_entfernen' => true]);
    check(array_key_exists('ca_pem', req_v($env)['daten']), 'Zertifikat entfernen nicht übermittelt');
    problem(fn() => $c->saveT2med(['base_url' => 'http://t2med.example.org']), 422, 'http angenommen');
    $c->fetchCertificate();
    check(req_v($env)['action'] === 'zertifikat-holen', 'Zertifikat abrufen nicht übermittelt');
    $c->acceptCertificate(str_repeat('ab', 32));
    check(req_v($env)['fingerprint'] === str_repeat('ab', 32), 'Fingerabdruck beim Übernehmen nicht übermittelt');
    $c->saveMail(['host' => 'smtp.example.org', 'port' => '587', 'sicherheit' => 'starttls', 'benutzer' => 'p@example.org', 'passwort' => 'x', 'absender' => 'p@example.org']);
    check(req_v($env)['daten']['port'] === 587, 'Port nicht als Zahl');
    problem(fn() => $c->saveMail(['port' => '58a']), 422, 'Port ohne Zahl');
    $c->saveTarget(['id' => 'sicherung', 'name' => 'NAS', 'art' => 'sftp', 'host' => 'nas.example.org', 'port' => '2222', 'ordner' => 'kienzle', 'benutzer' => 'k']);
    $t = req_v($env)['daten'];
    check($t['port'] === 2222 && !isset($t['passwort']) && !isset($t['hostkey']), 'SFTP-Ziel: Passwort oder Serverschlüssel mitgeschickt');
    problem(fn() => $c->saveTarget(['art' => 'ftp']), 422, 'unbekannte Art');
    problem(fn() => $c->test('alles'), 422, 'unbekannte Prüfung');
    problem(fn() => $c->remove('ablage'), 422, 'unbekanntes Entfernen');
    $c->test('ziel', 'sicherung');
    problem(fn() => $c->test('t2med'), 409, 'zweite Anfrage, solange die erste wartet');
    check($c->status()['pending'], 'wartende Anfrage nicht angezeigt');
}

function test_seite(): void {
    [$env, $c] = con_env();
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    $p = $b->get('/verwaltung/verbindungen');
    check($p->status === 200 && str_contains($p->body, 'value="https://t2med.example.org:16567/fhir"') && str_contains($p->body, 'gespeichert – leer lassen'), 'Seite unvollständig');
    check(!str_contains($p->body, '<script>') && !str_contains($p->body, 'Steuer<b>') && str_contains($p->body, 'Steuer&lt;b&gt;büro'), 'Inhalte nicht maskiert');
    check(str_contains($p->body, 'FHIR-Server antwortet') && str_contains($p->body, 'Patient lesen') && str_contains($p->body, 'ssh-ed25519 AAAATEST'), 'Prüfung, Nutzung oder SFTP-Schlüssel fehlen');
    check(str_contains($p->body, '<details class="fold"><summary><h2>T2med (FHIR)</h2> <span class="tag ok">✓ eingerichtet</span>') && str_contains($p->body, '<details class="fold"><summary><h2>Wer nutzt was</h2>'), 'Verbindungen: Abschnitte nicht einklappbar oder ohne Status');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'ziel', 'id' => 'steuerbuero', 'name' => 'Steuerbüro', 'art' => 'smb', 'host' => 'nas.example.org', 'freigabe' => 'praxis',
        'ordner' => 'Kienzle', 'benutzer' => 'k', 'passwort' => '']);
    check($r->status === 303 && req_v($env)['daten']['passwort'] === '', 'Ziel speichern über die Seite');
    check(str_contains(implode("\n", $env->audit->recent()), 'verbindung ziel steuerbuero'), 'nicht protokolliert');
    check(!str_contains((string)file_get_contents($env->audit->path()), 'Passwort'), 'Passwort im Protokoll');
    $env->user('bert');
    $m = new Browser($env); $m->web = fn() => $m->defaultWeb(null, null, $c);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/verbindungen')->status === 403, 'Mitarbeiter auf der Verbindungsseite');
}

function test_modul_hilfen(): void {
    $dir = tmpdir();
    mkdir("$dir/verb"); mkdir("$dir/keys");
    file_put_contents("$dir/keys/kasse.key", bin2hex(random_bytes(32)));
    file_put_contents("$dir/keys/zeit.key", bin2hex(random_bytes(32)));
    file_put_contents("$dir/verb/kasse.json", json_encode(['t2med' => ['base_url' => 'https://t2med.example.org/fhir']]));
    $server = ['KIENZLE_BENUTZER' => 'bert', 'KIENZLE_NAME' => 'Bert%20Beispiel', 'KIENZLE_ROLLE' => 'mitarbeiter', 'KIENZLE_RECHTE' => 'paid,inbox',
        'KIENZLE_CSRF' => str_repeat('a', 64), 'KIENZLE_BESTAETIGT' => (string)(time() - 100), 'HTTP_X_KIENZLE_BENUTZER' => 'anna'];
    $k = new KienzleModul('kasse', $server, "$dir/verb", "$dir/keys");
    check($k->benutzer() === 'bert' && $k->name() === 'Bert Beispiel' && !$k->istAdmin() && $k->recht('paid') && !$k->recht('catalog'), 'Benutzer/Rechte falsch');
    check($k->csrfGueltig(str_repeat('a', 64)) && !$k->csrfGueltig('b') && !$k->csrfGueltig(null), 'CSRF-Prüfung falsch');
    check(!(new KienzleModul('kasse', ['X' => '1']))->csrfGueltig(''), 'leere CSRF-Marke gültig');
    check($k->frischBestaetigt() && !$k->frischBestaetigt(50), 'Bestätigung falsch');
    check((new KienzleModul('kasse', ['KIENZLE_ROLLE' => 'admin']))->recht('catalog'), 'Admin hat nicht alle Rechte');
    check($k->verbindung('t2med')['base_url'] === 'https://t2med.example.org/fhir' && $k->verbindung('mail') === null, 'Verbindung lesen falsch');
    $enc = $k->verschluesseln('letterxpress-schluessel', 'letterxpress');
    check($k->entschluesseln($enc, 'letterxpress') === 'letterxpress-schluessel' && !str_contains($enc, 'letterxpress'), 'Ver-/Entschlüsseln');
    $zeit = new KienzleModul('zeit', ['X' => '1'], "$dir/verb", "$dir/keys");
    foreach ([fn() => $k->entschluesseln($enc, 'sumup'), fn() => $zeit->entschluesseln($enc, 'letterxpress')] as $n => $bad) {
        try { $bad(); throw new LogicException("Fall {$n} entschlüsselt"); } catch (RuntimeException) {}
    }
    try { new KienzleModul('../x'); throw new LogicException('ungültige ID'); } catch (InvalidArgumentException) {}
}

function test_ablegen(): void {
    $dir = tmpdir();
    mkdir("$dir/spool");
    file_put_contents("$dir/kasse.json", json_encode(['ablage' => ['ausgang' => "$dir/spool", 'ziele' => [['id' => 'steuerbuero', 'name' => 'Steuerbüro']]]]));
    $k = new KienzleModul('kasse', ['X' => '1'], $dir, $dir);
    $job = $k->ablegen('steuerbuero', '%PDF-1.4 erfunden', '2026-03-15_RE-42.pdf', 'Rechnungen/{jahr}/{monat}', new DateTimeImmutable('2026-03-15'));
    $meta = json_decode((string)file_get_contents("$dir/spool/$job.json"), true);
    check($meta === ['ziel' => 'steuerbuero', 'ordner' => 'Rechnungen/{jahr}/{monat}', 'name' => '2026-03-15_RE-42.pdf', 'jahr' => '2026', 'monat' => '03'], 'Auftrag falsch');
    check(file_get_contents("$dir/spool/$job.daten") === '%PDF-1.4 erfunden' && preg_match('/^[A-Za-z0-9_-]{8,64}$/', $job) === 1, 'Datei fehlt oder Kennung ungültig');
    check(!glob("$dir/spool/.*.json"), 'halbfertiger Auftrag liegt herum');
    try { $k->ablegen('unbekannt', 'x', 'a.pdf'); throw new LogicException('unbekanntes Ziel angenommen'); } catch (RuntimeException) {}
    try { (new KienzleModul('zeit', ['X' => '1'], $dir, $dir))->ablegen('steuerbuero', 'x', 'a.pdf'); throw new LogicException('ohne Verbindung abgelegt'); } catch (RuntimeException) {}
}

function test_testnachricht(): void {
    [$env, $c] = con_env(['mail' => ['host' => 'smtp.example.org', 'port' => 587, 'sicherheit' => 'starttls', 'benutzer' => 'p@example.org', 'absender' => 'p@example.org', 'passwort_gesetzt' => true]]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    check(str_contains($b->get('/verwaltung/verbindungen')->body, 'Testnachricht senden'), 'Testnachricht fehlt');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'testmail', 'an' => 'it@example.org']);
    check($r->status === 303 && req_v($env) === ['action' => 'pruefen', 'art' => 'testmail', 'an' => 'it@example.org'], 'Testnachricht-Anfrage');
    $page = $b->post('/verwaltung/verbindungen', ['aktion' => 'testmail', 'an' => 'kein-mail']);
    check(str_contains($page->body, 'gültige Empfängeradresse'), 'ungültiger Empfänger');
}

run_tests();
