<?php
declare(strict_types=1);
// Verbindungen im Webinterface (ohne root-Dienst) und Hilfsdatei für Module (core/php/modul/Kienzle.php).
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';
require dirname(__DIR__, 2) . '/core/php/modul/Kienzle.php';
require __DIR__ . '/verbindungen_hilfe.php';

use Kienzle\Core\Connections;

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
    check(preg_match('#<details class="fold"><summary><h2><svg class="h-ico"[^>]*>.*?</svg>T2med \(FHIR\) <span class="tag ok">✓ eingerichtet</span></h2></summary>#s', $p->body) === 1 && str_contains($p->body, '<details class="fold"><summary><h2><svg class="h-ico"'), 'Verbindungen: Abschnitte nicht einklappbar, ohne Symbol oder ohne Status');
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
    foreach ([fn() => $k->entschluesseln($enc, 'kasse'), fn() => $zeit->entschluesseln($enc, 'letterxpress')] as $n => $bad) {
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

function test_letterxpress(): void {
    [$env, $c] = con_env(['letterxpress' => ['username' => 'praxis-test', 'modus' => 'live', 'schluessel_gesetzt' => true]]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    $page = $b->get('/verwaltung/verbindungen')->body;
    check(str_contains($page, 'Letterxpress (Briefversand)') && str_contains($page, 'value="praxis-test"') && str_contains($page, 'gespeichert – leer lassen') && str_contains($page, 'Zugang prüfen')
        && str_contains($page, '<option value="live" selected>'), 'Letterxpress-Abschnitt unvollständig');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'letterxpress', 'username' => ' praxis-test ', 'api_key' => ' lxp-1 ', 'modus' => 'live']);
    check($r->status === 303 && req_v($env) === ['action' => 'letterxpress', 'daten' => ['username' => 'praxis-test', 'api_key' => 'lxp-1', 'modus' => 'live']], 'Speicher-Anfrage falsch');
    check(str_contains($page, 'Guthaben noch nicht abgefragt') && str_contains($page, 'Guthaben abfragen') && str_contains($page, '<span class="tag ok">Live</span>'), 'Guthaben-Knopf oder Live-Schild fehlt');
    $b->post('/verwaltung/verbindungen', ['aktion' => 'pruefen', 'art' => 'guthaben']);
    check(req_v($env) === ['action' => 'pruefen', 'art' => 'guthaben', 'id' => ''], 'Guthaben-Anfrage falsch');
    $b->post('/verwaltung/verbindungen', ['aktion' => 'pruefen', 'art' => 'letterxpress']);
    check(req_v($env) === ['action' => 'pruefen', 'art' => 'letterxpress', 'id' => ''], 'Prüf-Anfrage falsch');
    $b->post('/verwaltung/verbindungen', ['aktion' => 'entfernen', 'was' => 'letterxpress']);
    check(req_v($env) === ['action' => 'letterxpress', 'loeschen' => true], 'Entfernen-Anfrage falsch');
    [$env2, $c2] = con_env(['letterxpress' => ['username' => 'praxis-test', 'modus' => 'test', 'schluessel_gesetzt' => true], 'guthaben' => ['letterxpress' => ['wert' => 12.5, 'zeit' => time()]]]);
    [$b2] = admin_browser($env2);
    $b2->web = fn() => $b2->defaultWeb(null, null, $c2);
    $page2 = $b2->get('/verwaltung/verbindungen')->body;
    check(str_contains($page2, '<strong>12,50 €</strong>') && str_contains($page2, '(abgefragt ') && str_contains($page2, '<span class="tag warn">Testbetrieb</span>'), 'Guthaben mit Zeit oder gelbes Testbetrieb-Schild fehlt');
}

function test_gmail(): void {
    [$env, $c] = con_env([]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'gmail-start', 'client_id' => 'kurz', 'absender' => 'praxis@gmail.example']);
    check($r->status === 422, 'Ungültige Client-ID angenommen');
    $b->post('/verwaltung/verbindungen', ['aktion' => 'gmail-start', 'client_id' => '1234567890-abc.apps.googleusercontent.com', 'absender' => 'praxis@gmail.example']);
    $page = $b->get('/verwaltung/verbindungen')->body;
    check(preg_match('#href="(https://accounts\.google\.com/o/oauth2/v2/auth\?[^"]+)"#', $page, $m) === 1, 'Anmelde-Link fehlt');
    $url = html_entity_decode($m[1]);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    check($q['scope'] === 'https://www.googleapis.com/auth/gmail.send' && $q['redirect_uri'] === 'http://localhost' && $q['access_type'] === 'offline' && strlen($q['state']) === 32, 'Anmelde-Adresse falsch');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'gmail', 'client_secret' => 'GOCSPX-geheim', 'adresse' => 'http://localhost/?state=falsch&code=4/abc']);
    check($r->status === 422 && !file_exists($env->dir . '/anfragen/verbindungen.json'), 'Falscher Zustandswert angenommen');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'gmail', 'client_secret' => 'GOCSPX-geheim', 'adresse' => 'http://localhost/?state=' . $q['state'] . '&code=4%2Fabc&scope=x']);
    check($r->status === 303 && req_v($env) === ['action' => 'gmail', 'daten' => ['client_id' => '1234567890-abc.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-geheim', 'absender' => 'praxis@gmail.example', 'code' => '4/abc']], 'Gmail-Anfrage falsch');
    check(!str_contains($b->get('/verwaltung/verbindungen')->body, 'accounts.google.com'), 'Anmeldung nach Abschluss noch offen');
    [$env2, $c2] = con_env(['mail' => ['art' => 'gmail', 'client_id' => '1234567890-abc.apps.googleusercontent.com', 'absender' => 'praxis@gmail.example', 'secret_gesetzt' => true, 'verbunden' => true]]);
    [$b2] = admin_browser($env2);
    $b2->web = fn() => $b2->defaultWeb(null, null, $c2);
    $page2 = $b2->get('/verwaltung/verbindungen')->body;
    check(str_contains($page2, 'status-ok">Mit Google verbunden: praxis@gmail.example') && str_contains($page2, 'Gmail-Konto (OAuth, ohne Passwort im Klartext) <span class="tag ok">✓ eingerichtet</span>') && str_contains($page2, 'Erneut einrichten oder anderes Konto verbinden') && !str_contains($page2, 'GOCSPX'), 'Gmail-Status falsch');
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
