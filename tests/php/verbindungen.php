<?php
declare(strict_types=1);
// Verbindungen im Webinterface (ohne root-Dienst) und Hilfsdatei für Module (core/php/modul/Kienzle.php).
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';
require dirname(__DIR__, 2) . '/core/php/modul/Kienzle.php';
require __DIR__ . '/verbindungen_hilfe.php';

use Kienzle\Core\Connections;

/** Seit Basis beta.61 zeigt /verwaltung/verbindungen Kacheln; jede Verbindung steht unter ?v=… . Für die Prüfungen alles zusammen. */
function verb_alle(Browser $b): string {
    $o = $b->get('/verwaltung/verbindungen')->body;
    foreach (['mail', 'sms', 'letterxpress', 'uebersetzung', 'ablage', 'nutzung'] as $v) $o .= $b->get('/verwaltung/verbindungen?v=' . $v)->body;
    return $o;
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
    $mods = [];
    foreach ($env->modules->all() as $id => $m) {
        if ($id === 'kasse') $m['starters'] = [['schema' => 'praxisherz-kasse', 'label' => 'Kasse'], ['schema' => 'praxisherz-igel', 'label' => 'IGeL <b>']];
        $mods[] = $m;
    }
    $env->modules = Kienzle\Core\Modules::fromArray($mods);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    $p = $b->get('/verwaltung/verbindungen');
    check(str_contains($p->body, 'class="card verb-kachel" href="/verwaltung/verbindungen?v=mail"') && str_contains($p->body, 'href="/verwaltung/verbindungen?v=ablage"') && !str_contains($p->body, 'name="host"'), 'Verbindungen als Kacheln');
    check(str_contains($b->get('/verwaltung/verbindungen?v=mail')->body, 'name="host"') && str_contains($b->get('/verwaltung/verbindungen?v=mail')->body, 'Alle Verbindungen</a>'), 'Verbindung öffnet sich allein');
    $p->body = verb_alle($b);
    check($p->status === 200 && !str_contains($p->body, 'name="base_url"') && str_contains($p->body, 'href="/verwaltung/t2med"'), 'Verbindungen: T2med steht jetzt unter Praxis → T2med');
    check(!str_contains($p->body, '<script>') && !str_contains($p->body, 'Steuer<b>') && str_contains($p->body, 'Steuer&lt;b&gt;büro'), 'Inhalte nicht maskiert');
    check(str_contains($p->body, 'Patient lesen') && str_contains($p->body, 'ssh-ed25519 AAAATEST'), 'Nutzung oder SFTP-Schlüssel fehlen');
    check(str_contains($p->body, '<section class="card"><h2><svg class="h-ico"'), 'Verbindungen: gewählte Verbindung ohne Karte oder Symbol');
    // Praxis → T2med: Reiter FHIR-Verbindung, GDT, Funktionsleiste
    $p = $b->get('/verwaltung/t2med');
    check($p->status === 200 && str_contains($p->body, 'class="basis-reiter"') && substr_count($p->body, 'class="button ') === 3, 'T2med: Reiterleiste fehlt');
    check(preg_match('#<a class="button primary mit-icon" href="/verwaltung/t2med" aria-current="page">#', $p->body) === 1, 'T2med: Reiter FHIR nicht gewählt');
    check(str_contains($p->body, 'value="https://t2med.example.org:16567/fhir"') && str_contains($p->body, 'gespeichert – leer lassen') && str_contains($p->body, 'name="zurueck" value="t2med"'), 'T2med: Formular unvollständig');
    check(str_contains($p->body, 'FHIR-Server antwortet') && preg_match('#<h2><svg class="h-ico"[^>]*>.*?</svg>Verbindung zu T2med \(FHIR\) <span class="tag ok">✓ eingerichtet</span></h2>#s', $p->body) === 1, 'T2med: Prüfung oder Status fehlt');
    check(!str_contains($p->body, '<script>') && str_contains($p->body, 'data-menu="praxis"') && !str_contains($p->body, 'data-menu="einrichten"'), 'T2med: Seitenleiste ohne Gruppe Einrichten');
    $p = $b->get('/verwaltung/t2med?reiter=funktionsleiste');
    check(str_contains($p->body, 'id="t2med"') && str_contains($p->body, 'praxisherz-kasse://?kontextId=') && str_contains($p->body, 'praxisherz-igel://'), 'Funktionsleiste: alle Aufrufadressen');
    check(!str_contains($p->body, 'kienzle-') && !str_contains($p->body, 'name="base_url"'), 'Funktionsleiste: keine früheren Adressen, kein Formular');
    check(!str_contains($p->body, 'IGeL <b>') && str_contains($p->body, 'IGeL &lt;b&gt;'), 'Kontext nicht maskiert');
    $p = $b->get('/verwaltung/t2med?reiter=gdt');
    check(str_contains($p->body, 'C:\\Praxisherz\\GDT\\AUFRUFi.gdt') && str_contains($p->body, 'T2MED_PX') && str_contains($p->body, 'Noch kein Arbeitsplatz'), 'GDT: Anleitung fehlt');
    check($b->get('/verwaltung/t2med?reiter=<x>')->status === 200, 'unbekannter Reiter → FHIR');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'pruefen', 'art' => 't2med', 'zurueck' => 't2med']);
    check($r->status === 303 && $r->header('Location') === '/verwaltung/t2med', 'Rückweg zur Seite T2med');
    @unlink($env->dir . '/anfragen/verbindungen.json');
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

function test_nachrichten(): void {
    $dir = tmpdir();
    mkdir("$dir/ausgang");
    file_put_contents("$dir/telefon.json", json_encode(['nachrichten' => ['ausgang' => "$dir/ausgang", 'sms' => true, 'mail' => false]]));
    $k = new KienzleModul('telefon', ['X' => '1'], $dir, $dir);
    check($k->nachrichtenBereit() === ['sms' => true, 'mail' => false], 'Bereitschaft');
    $job = $k->smsSenden('+491701234567', 'Ihr Rezept liegt bereit.');
    check(json_decode((string)file_get_contents("$dir/ausgang/$job.json"), true) === ['art' => 'sms', 'an' => '+491701234567', 'text' => 'Ihr Rezept liegt bereit.'], 'SMS-Auftrag');
    check(decoct(fileperms("$dir/ausgang/$job.json") & 0777) === '640' && !glob("$dir/ausgang/.*.neu"), 'Rechte oder halbfertiger Auftrag');
    check($k->nachricht($job)['stand'] === 'wartet', 'wartet');
    unlink("$dir/ausgang/$job.json");
    file_put_contents("$dir/ausgang/$job.ergebnis", json_encode(['stand' => 'gesendet', 'meldung' => 'SMS verschickt.', 'zeit' => 1]));
    check($k->nachricht($job) === ['stand' => 'gesendet', 'meldung' => 'SMS verschickt.'], 'Ergebnis');
    check($k->nachricht('../x')['stand'] === 'unbekannt', 'Pfad im Auftrag');
    foreach ([fn() => $k->smsSenden('0170123', 'x'), fn() => $k->smsSenden('+491701234567', str_repeat('x', 321)), fn() => $k->mailSenden('keine', 'a', 'b')] as $f) {
        try { $f(); throw new LogicException('ungültig angenommen'); } catch (InvalidArgumentException) {}
    }
    try { $k->mailSenden('fax@example.org', 'Neues Fax', 'Text'); throw new LogicException('Mail ohne Zugang angenommen'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'E-Mail ist in der Basis nicht eingerichtet'), 'Meldung'); }
    try { (new KienzleModul('fax', ['X' => '1'], $dir, $dir))->smsSenden('+491701234567', 'x'); throw new LogicException('ohne Verbindung angenommen'); } catch (RuntimeException) {}
}

function test_letterxpress(): void {
    [$env, $c] = con_env(['letterxpress' => ['username' => 'praxis-test', 'modus' => 'live', 'schluessel_gesetzt' => true]]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    $page = verb_alle($b);
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
    $page2 = verb_alle($b2);
    check(str_contains($page2, '<strong>12,50 €</strong>') && str_contains($page2, '(abgefragt ') && str_contains($page2, '<span class="tag warn">Testbetrieb</span>'), 'Guthaben mit Zeit oder gelbes Testbetrieb-Schild fehlt');
}

function test_uebersetzung(): void {
    [$env, $c] = con_env(['uebersetzung' => ['anbieter' => 'deepl', 'gratis' => true, 'schluessel_gesetzt' => true]]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    $page = verb_alle($b);
    check(str_contains($page, 'Übersetzung (DeepL)') && str_contains($page, 'DeepL API Free') && str_contains($page, 'gespeichert – leer lassen') && str_contains($page, 'Schlüssel prüfen'), 'DeepL-Abschnitt unvollständig');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'uebersetzung', 'api_key' => ' 0000-beispiel:fx ']);
    check($r->status === 303 && req_v($env) === ['action' => 'uebersetzung', 'daten' => ['api_key' => '0000-beispiel:fx']], 'DeepL: Speicher-Anfrage falsch');
    $b->post('/verwaltung/verbindungen', ['aktion' => 'pruefen', 'art' => 'uebersetzung']);
    check(req_v($env) === ['action' => 'pruefen', 'art' => 'uebersetzung', 'id' => ''], 'DeepL: Prüf-Anfrage falsch');
    $b->post('/verwaltung/verbindungen', ['aktion' => 'entfernen', 'was' => 'uebersetzung']);
    check(req_v($env) === ['action' => 'uebersetzung', 'loeschen' => true], 'DeepL: Entfernen-Anfrage falsch');
}

function test_gmail(): void {
    [$env, $c] = con_env([]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'gmail-start', 'client_id' => 'kurz', 'absender' => 'praxis@gmail.example']);
    check($r->status === 422, 'Ungültige Client-ID angenommen');
    $b->post('/verwaltung/verbindungen', ['aktion' => 'gmail-start', 'client_id' => '1234567890-abc.apps.googleusercontent.com', 'absender' => 'praxis@gmail.example']);
    $page = verb_alle($b);
    check(preg_match('#href="(https://accounts\.google\.com/o/oauth2/v2/auth\?[^"]+)"#', $page, $m) === 1, 'Anmelde-Link fehlt');
    $url = html_entity_decode($m[1]);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    check($q['scope'] === 'https://www.googleapis.com/auth/gmail.send' && $q['redirect_uri'] === 'http://localhost' && $q['access_type'] === 'offline' && strlen($q['state']) === 32, 'Anmelde-Adresse falsch');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'gmail', 'client_secret' => 'GOCSPX-geheim', 'adresse' => 'http://localhost/?state=falsch&code=4/abc']);
    check($r->status === 422 && !file_exists($env->dir . '/anfragen/verbindungen.json'), 'Falscher Zustandswert angenommen');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'gmail', 'client_secret' => 'GOCSPX-geheim', 'adresse' => 'http://localhost/?state=' . $q['state'] . '&code=4%2Fabc&scope=x']);
    check($r->status === 303 && req_v($env) === ['action' => 'gmail', 'daten' => ['client_id' => '1234567890-abc.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-geheim', 'absender' => 'praxis@gmail.example', 'code' => '4/abc']], 'Gmail-Anfrage falsch');
    check(!str_contains(verb_alle($b), 'accounts.google.com'), 'Anmeldung nach Abschluss noch offen');
    [$env2, $c2] = con_env(['mail' => ['art' => 'gmail', 'client_id' => '1234567890-abc.apps.googleusercontent.com', 'absender' => 'praxis@gmail.example', 'secret_gesetzt' => true, 'verbunden' => true]]);
    [$b2] = admin_browser($env2);
    $b2->web = fn() => $b2->defaultWeb(null, null, $c2);
    $page2 = verb_alle($b2);
    check(str_contains($page2, 'status-ok">Mit Google verbunden: praxis@gmail.example') && str_contains($page2, 'Gmail-Konto (OAuth, ohne Passwort im Klartext) <span class="tag ok">✓ eingerichtet</span>') && str_contains($page2, 'Erneut einrichten oder anderes Konto verbinden') && !str_contains($page2, 'GOCSPX'), 'Gmail-Status falsch');
}

function test_testnachricht(): void {
    [$env, $c] = con_env(['mail' => ['host' => 'smtp.example.org', 'port' => 587, 'sicherheit' => 'starttls', 'benutzer' => 'p@example.org', 'absender' => 'p@example.org', 'passwort_gesetzt' => true]]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    check(str_contains(verb_alle($b), 'Testnachricht senden'), 'Testnachricht fehlt');
    $r = $b->post('/verwaltung/verbindungen', ['aktion' => 'testmail', 'an' => 'it@example.org']);
    check($r->status === 303 && req_v($env) === ['action' => 'pruefen', 'art' => 'testmail', 'an' => 'it@example.org'], 'Testnachricht-Anfrage');
    $page = $b->post('/verwaltung/verbindungen', ['aktion' => 'testmail', 'an' => 'kein-mail']);
    check(str_contains($page->body, 'gültige Empfängeradresse'), 'ungültiger Empfänger');
}

run_tests();
