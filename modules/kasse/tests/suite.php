<?php
declare(strict_types=1);
// Kasse in der Suite: Adressen unter /kasse/, Rechte und CSRF-Marke der Suite, Starter ohne Anmeldung (/api.php),
// Ablage über den Netzwerkspeicher der Basis, Einrichtungs-Überblick, Haken und virtueller Drucker. Nur Beispieldaten.
require __DIR__ . '/lib.php';
use KienzleKasse\{App, Config, Database, InvoiceExport, Module, Problem, Settings, Suite};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
/** Seite aufrufen; liefert [Code, Inhalt]. */
function page(string $root, array $server, string $body = '', array $cookies = [], array $get = []): array {
    $job = tempnam(sys_get_temp_dir(), 'ks-job-');
    file_put_contents($job, json_encode(['root' => $root, 'server' => $server, 'body' => $body, 'cookies' => $cookies, 'get' => $get]));
    $out = (string)shell_exec('php ' . escapeshellarg(__DIR__ . '/page.php') . ' ' . escapeshellarg($job) . ' 2>&1');
    unlink($job);
    if (!preg_match('/\nHTTP (\d+)\n$/', $out, $m)) throw new RuntimeException('Seite ohne Statuszeile: ' . substr($out, -300));
    return [(int)$m[1], substr($out, 0, -strlen($m[0]))];
}
$dirs = [];
try {
    $t2med = ['t2med' => ['base_url' => 'https://t2med.example.org/aps/fhir/api/r4', 'launch_urls' => [], 'api_key' => 'k-beispiel', 'ca_pem' => '', 'pin' => ''],
        'letterxpress' => ['username' => 'praxis-test', 'api_key' => 'lxp-geheim', 'modus' => 'live'],
        'ablage' => ['ausgang' => '', 'ziele' => [['id' => 'steuerbuero', 'name' => 'Steuerbüro']]]];
    $dirs[] = $root = suite(admin_server(), $t2med, [['name' => 'vKasse', 'description' => 'Kienzle-Suite', 'state' => 'bereit'], ['name' => 'Praxisdrucker', 'description' => '', 'state' => 'bereit']]);

    (new Database(Suite::$data))->migrate();   // wie der Haken install

    // Grundwerte: einmal erzeugt, 0600, und Basis-Konfiguration aus den Verbindungen der Suite.
    $g = Suite::grundwerte();
    check(preg_match('/^[a-f0-9]{64}$/D', $g['launcher_key']) === 1 && (fileperms(Suite::$data . '/grundwerte.json') & 0777) === 0600, 'Grundwerte falsch angelegt');
    Suite::ensureGrundwerte();
    check(Suite::grundwerte() === $g, 'Grundwerte beim zweiten Mal ersetzt');
    $base = Suite::baseConfig();
    check($base->get('app', 'base_url') === 'https://kasse.example.org' && $base->get('app', 'state_dir') === Suite::$data, 'Adresse oder Datenordner falsch');
    check($base->get('fhir', 'launch_urls') === ['https://t2med.example.org/aps/fhir/api/r4'] && $base->get('invoice_export', 'ziel') === 'steuerbuero', 'Verbindungen nicht übernommen');
    check($base->get('letterxpress', 'username') === 'praxis-test' && $base->get('letterxpress', 'api_key') === 'lxp-geheim' && $base->get('letterxpress', 'mode') === 'live', 'Letterxpress nicht aus der Verbindung übernommen');
    check($base->get('printing', 'transport') === 'cups', 'Bondrucker nicht über die Suite');
    $_SERVER['HTTP_HOST'] = 'evil"name'; check(Suite::host() === 'localhost', 'Unsicherer Servername übernommen'); $_SERVER['HTTP_HOST'] = 'kasse.example.org';

    // Ablage: Auftrag an den Netzwerkspeicher der Basis, ohne Zugangsdaten, ohne Patientendaten im Namen.
    $v = $base->values; $v['invoice_export'] = ['enabled' => true, 'ziel' => 'steuerbuero', 'directory' => 'Rechnungen/{jahr}/{monat}', 'when' => 'paid'];
    $v['invoice_inbox']['enabled'] = true;
    $path = (new InvoiceExport(new Config($v)))->export(['invoice_number' => 'RE2026-0042', 'invoice_date' => '2026-03-15', 'amount_cents' => 4250], "%PDF-1.4\n%Beispiel\n", true);
    check($path === 'Rechnungen/2026/03/TEST_2026-03-15_RE2026-0042_42,50EUR_Karte.pdf', 'Ablagepfad falsch: ' . $path);
    $jobs = glob("$root/ausgang/*.json");
    check(count($jobs) === 1, 'Kein Ablage-Auftrag');
    $meta = json_decode((string)file_get_contents($jobs[0]), true);
    check($meta['ziel'] === 'steuerbuero' && $meta['ordner'] === 'Rechnungen/2026/03' && $meta['name'] === 'TEST_2026-03-15_RE2026-0042_42,50EUR_Karte.pdf', 'Ablage-Auftrag falsch');
    check(str_starts_with((string)file_get_contents(substr($jobs[0], 0, -5) . '.daten'), '%PDF-'), 'PDF nicht übergeben');
    $v['invoice_export']['ziel'] = 'gibtsnicht';
    try { (new InvoiceExport(new Config($v)))->check(); throw new RuntimeException('Unbekanntes Ziel geprüft'); } catch (Problem $e) { check($e->http === 409, 'Falscher Fehler bei unbekanntem Ziel'); }

    // Einrichtungs-Überblick: Format der Suite (state done/open/error, Links unter /kasse).
    $items = Module::overview(Settings::effective($base));
    check($items !== [] && count($items) <= 30, 'Überblick leer');
    foreach ($items as $i) check(in_array($i['state'], ['done', 'open', 'error'], true) && str_starts_with($i['link'], '/kasse/') && mb_strlen($i['title']) <= 120, 'Überblick-Punkt ungültig: ' . json_encode($i));
    check(in_array('T2med-Verbindung der Suite vorhanden', array_column($items, 'title'), true), 'T2med-Verbindung nicht erkannt');

    // Seiten: Kasse nur mit Recht „kassieren“, Einstellungen nur mit Rechten, unbekannte Adressen 404, Dateien aus assets.
    [$code, $html] = page($root, admin_server(['REQUEST_URI' => '/kasse/']));
    check($code === 200 && str_contains($html, '/kasse/assets/start.js') && str_contains($html, 'id="inv-search"') && str_contains($html, 'id="work"') && str_contains($html, 'name="kienzle-csrf" content="' . TEST_CSRF . '"'), 'Startseite der Kasse fehlt');
    $server = admin_server(['REQUEST_URI' => '/kasse/kassieren']);
    [$code, $html] = page($root, $server);
    check($code === 200 && str_contains($html, 'data-base="/kasse/"') && str_contains($html, 'name="kienzle-csrf" content="' . TEST_CSRF . '"') && str_contains($html, '/kasse/assets/app.js'), 'Kasse nicht unter /kasse/');
    check(str_contains($html, 'Testpatient öffnen') && !preg_match('/id="demo"[^>]*hidden/', $html), 'Testpatient im Testmodus nicht angeboten');
    [$code] = page($root, staff_server(['paid']) + ['REQUEST_URI' => '/kasse/kassieren']);
    check($code === 403, 'Kasse ohne Recht „kassieren“');
    $js = (string)file_get_contents(dirname(__DIR__) . '/public/assets/settings.js');
    check(!str_contains($js, 'renderClients') && !str_contains($js, 'CLIENT_STEPS'), 'settings.js: altes Starter-Paket der Kasse ist entfernt');
    [$code, $html] = page($root, staff_server(['inbox']) + ['REQUEST_URI' => '/kasse/settings.php']);
    check($code === 200 && str_contains($html, 'settings.js') && str_contains($html, 'id="letter-printer-help"'), 'Einstellungen für Mitarbeiter mit Recht nicht erreichbar');
    // Drucker „vBriefversand“ als Ausklappzeile mit Schild (erledigt/fehlt), eingeordnet bei „Praxis & Rechnungen“ statt unter jedem Reiter.
    check(str_contains($html, 'id="letter-printer-fold"') && str_contains($html, 'id="letter-printer-tag"'), 'vBriefversand nicht einklappbar');
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/settings.js');
    check(str_contains($js, "'s-letterxpress', 'letter-printer-help'") && str_contains($js, "done ? 'erledigt' : 'fehlt'"), 'vBriefversand nicht in der Einrichtung eingeordnet');
    [$code] = page($root, staff_server(['paid', 'export', 'periods']) + ['REQUEST_URI' => '/kasse/settings.php']);
    check($code === 403, 'Rechte der Vorgänge öffnen die Einstellungen');
    [$code] = page($root, staff_server([]) + ['REQUEST_URI' => '/kasse/settings.php']);
    check($code === 403, 'Einstellungen ohne Recht');
    [$code] = page($root, ['REQUEST_URI' => '/kasse/../etc/passwd'] + $server);
    check($code === 404, 'Unbekannte Adresse nicht abgewiesen');
    [$code, $css] = page($root, ['REQUEST_URI' => '/kasse/assets/style.css'] + $server);
    check($code === 200 && str_contains($css, '--teal'), 'Stylesheet nicht ausgeliefert');
    // Eingebettete Kassenansicht: rechte Spalte ist schmal, Zahlungsarten in zwei Spalten mit Umbruch (sonst ragt die Schrift über die Knöpfe).
    $embed = (string) file_get_contents(dirname(__DIR__) . '/public/assets/embed.css');
    check(str_contains($embed, '.embed .pay-method { margin: 6px 0; grid-template-columns: repeat(2, minmax(0, 1fr)); }') && str_contains($embed, '.embed .pay-method span { white-space: normal;'), 'Zahlungsknöpfe der eingebetteten Ansicht zu schmal');
    [$code] = page($root, ['REQUEST_URI' => '/kasse/assets/..%2fsrc%2fSuite.php'] + $server);
    check($code === 404, 'Datei außerhalb von assets ausgeliefert');
    [$code, $help] = page($root, ['REQUEST_URI' => '/kasse/hilfe/t2med'] + $server);
    check($code === 200 && str_contains($help, '/kasse/hilfe/bilder/t2med-1-suche.png') && !str_contains($help, '<script'), 'Anleitung T2med fehlt');

    // Kasse-API: Recht und CSRF-Marke der Suite.
    $post = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/kasse/api.php', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://kasse.example.org', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
    [$code, $out] = page($root, $post + $server, json_encode(['action' => 'demo']));
    check($code === 403, 'Testpatient ohne CSRF-Marke der Suite: ' . $out);
    [$code, $out] = page($root, $post + ['HTTP_X_KIENZLE_CSRF' => TEST_CSRF] + $server, json_encode(['action' => 'demo']));
    $demo = json_decode($out, true);
    check($code === 200 && preg_match('~^https://kasse\.example\.org/kasse/#launch=[0-9a-f]{64}$~D', (string)($demo['url'] ?? '')) === 1, 'Testpatient: falscher Startlink ' . $out);
    [$code, $out] = page($root, $post + ['HTTP_X_KIENZLE_CSRF' => TEST_CSRF] + staff_server(['paid']), json_encode(['action' => 'demo']));
    check($code === 403, 'Kasse-API ohne Recht');
    [$code, $out] = page($root, ['HTTP_ORIGIN' => 'https://fremd.example.org', 'HTTP_X_KIENZLE_CSRF' => TEST_CSRF] + $post + $server, json_encode(['action' => 'demo']));
    check($code === 403, 'Fremder Ursprung akzeptiert');

    // Startseite: Zähler für offene Einrichtung nur mit Einstellungsrechten; freie Eingabe und Tageswerte.
    [$code, $out] = page($root, $post + ['HTTP_X_KIENZLE_CSRF' => TEST_CSRF] + $server, json_encode(['action' => 'setup_count']));
    $count = json_decode($out, true);
    check($code === 200 && isset($count['count'], $count['red']) && $count['count'] >= $count['red'], 'Einrichtungszähler fehlt: ' . $out);
    [$code, $out] = page($root, $post + ['HTTP_X_KIENZLE_CSRF' => TEST_CSRF] + staff_server(['kassieren']), json_encode(['action' => 'setup_count']));
    check($code === 200 && (json_decode($out, true)['count'] ?? -1) === 0, 'Zähler ohne Einstellungsrecht nicht 0: ' . $out);
    // Vorgänge: Tag und Woche für jeden mit Kassenrecht, Monat und Jahr nur mit dem Recht „periods“, Excel nur mit „export“, Abhaken nur mit „paid“.
    $vg = fn(array $rights, array $in) => page($root, $post + ['HTTP_X_KIENZLE_CSRF' => TEST_CSRF] + staff_server($rights), json_encode($in));
    [$code, $out] = $vg(['kassieren'], ['action' => 'vorgaenge', 'art' => 'tag', 'date' => date('Y-m-d')]);
    check($code === 200 && (json_decode($out, true)['rights']['periods'] ?? null) === false, 'Tagesansicht ohne Zusatzrecht: ' . $out);
    check((json_decode($out, true)['rights']['storno'] ?? null) === false, 'Storno-Recht ohne Recht angezeigt: ' . $out);
    // Stornieren aus der Vorgänge-Liste: nur mit dem Recht „storno“ (ohne „kassieren“ erlaubt, mit ungültiger Zahlung 422), sonst 403.
    [$code, $out] = $vg(['storno'], ['action' => 'vorgaenge', 'art' => 'tag', 'date' => date('Y-m-d')]);
    check($code === 200 && (json_decode($out, true)['rights']['storno'] ?? null) === true, 'Vorgänge mit Recht „storno“: ' . $code);
    [$code] = $vg(['storno'], ['action' => 'storno', 'payment_id' => 'x', 'note' => '']);
    check($code === 422, 'Storno mit Recht „storno“ und ungültiger Zahlung: ' . $code);
    [$code] = $vg(['export'], ['action' => 'storno', 'payment_id' => str_repeat('a', 32), 'note' => '']);
    check($code === 403, 'Storno ohne Recht „storno“: ' . $code);
    $appJs = (string)file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
    $indexPhp = (string)file_get_contents(dirname(__DIR__) . '/seiten/index.php');
    check(str_contains($appJs, '#uebergabe=') && str_contains($appJs, 'signaturSenden') && str_contains($indexPhp, 'id="sign-send"') && str_contains($indexPhp, "=== 'signatur'"), 'Knopf „Zur Unterschrift“ (Übergabe an Signatur) fehlt');
    $js = (string)file_get_contents(dirname(__DIR__) . '/public/assets/vorgaenge.js');
    check(str_contains($js, 'sortieren(') && str_contains($js, "api('storno'") && str_contains((string)file_get_contents(dirname(__DIR__) . '/seiten/vorgaenge.php'), 'data-key="number"'), 'Sortierung und Storno in der Vorgänge-Liste');
    [$code] = $vg(['kassieren'], ['action' => 'vorgaenge', 'art' => 'woche', 'date' => date('Y-m-d')]);
    check($code === 200, 'Wochenansicht ohne Zusatzrecht');
    foreach (['monat', 'jahr'] as $art) { [$code] = $vg(['kassieren'], ['action' => 'vorgaenge', 'art' => $art, 'date' => date('Y-m-d')]); check($code === 403, $art . 'sansicht ohne Recht „periods“'); }
    [$code] = $vg(['periods'], ['action' => 'vorgaenge', 'art' => 'monat', 'date' => date('Y-m-d')]);
    check($code === 200, 'Monatsansicht mit Recht „periods“');
    [$code] = $vg(['kassieren'], ['action' => 'storno', 'payment_id' => str_repeat('0', 32)]);
    check($code === 403, 'Storno ohne Recht „storno“');
    [$code] = $vg(['kassieren', 'storno'], ['action' => 'storno', 'payment_id' => str_repeat('0', 32)]);
    check($code !== 403, 'Storno mit Recht „storno“ abgelehnt');
    [$code] = $vg(['kassieren'], ['action' => 'vorgaenge_done', 'ids' => [], 'done' => true]);
    check($code === 403, 'Abhaken ohne Recht „paid“');
    [$code] = $vg(['paid'], ['action' => 'vorgaenge_done', 'ids' => [], 'done' => true]);
    check($code === 200, 'Abhaken mit Recht „paid“');
    [$code] = page($root, ['REQUEST_URI' => '/kasse/vorgaenge-export.php', 'REQUEST_METHOD' => 'GET'] + staff_server(['kassieren']), '', [], ['art' => 'tag', 'date' => date('Y-m-d')]);
    check($code === 403, 'Excel ohne Recht „export“');
    [$code] = page($root, ['REQUEST_URI' => '/kasse/vorgaenge-export.php', 'REQUEST_METHOD' => 'GET'] + staff_server(['export']), '', [], ['art' => 'monat', 'date' => date('Y-m-d')]);
    check($code === 403, 'Monats-Excel ohne Recht „periods“');
    [$code, $out] = page($root, ['REQUEST_URI' => '/kasse/vorgaenge-export.php', 'REQUEST_METHOD' => 'GET'] + staff_server(['export', 'periods']), '', [], ['art' => 'monat', 'date' => date('Y-m-d'), 'pay' => 'cash']);
    check($code === 200 && str_starts_with($out, 'PK'), 'Monats-Excel mit Rechten: ' . $code);
    [$code] = page($root, ['REQUEST_URI' => '/kasse/vorgaenge'] + staff_server(['letter']), '', [], []);
    check($code === 403, 'Vorgänge-Seite ohne Kassenrecht erreichbar');
    [$code, $out] = page($root, $post + ['HTTP_X_KIENZLE_CSRF' => TEST_CSRF] + $server, json_encode(['action' => 'day_stats']));
    check($code === 200 && ($out !== '') && isset(json_decode($out, true)['total_cents']), 'Tageswerte fehlen: ' . $out);

    // Starter (POST /starter/kasse ohne Anmeldung der Suite): nur mit Prüfung durch die Basis, nur „launch“, Startlink auf „/“ mit Modulkennung.
    $starter = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/starter/kasse', 'CONTENT_TYPE' => 'application/json', 'KIENZLE_OEFFENTLICH' => 'starter',
        'KIENZLE_PFAD' => '/kasse', 'HTTP_HOST' => 'kasse.example.org'];
    $launch = json_encode(['action' => 'launch', 'context_id' => 'kontext-1', 'fhir_base_url' => '', 'oauth_token' => '']);
    [$code] = page($root, ['KIENZLE_STARTER_GEPRUEFT' => '1', 'HTTP_SEC_FETCH_SITE' => 'cross-site'] + $starter, $launch);
    check($code === 403, 'Starter-Adresse aus dem Browser erreichbar');
    [$code, $out] = page($root, ['KIENZLE_STARTER_GEPRUEFT' => '1'] + $starter, $launch);
    $r = json_decode($out, true);
    check($code === 200 && preg_match('~^https://kasse\.example\.org/#launch=[0-9a-f]{64}&m=kasse$~D', (string)($r['url'] ?? '')) === 1, 'Starter: Startlink mit Modulkennung: ' . $out);
    [$code] = page($root, ['KIENZLE_STARTER_GEPRUEFT' => '0'] + $starter, $launch);
    check($code === 401, 'Starter ohne Prüfung durch die Basis');
    [$code] = page($root, $starter, $launch);
    check($code === 401, 'Starter ohne Merkmal der Basis');
    // Der Startcode gilt 5 Minuten und nur einmal; Austausch nur mit Anmeldung an der Suite.
    $ticket = substr(explode('&m=', (string)$r['url'])[0], -64);
    $db = new Database(Suite::$data);
    $left = (int)$db->one('SELECT expires_at FROM launch_tickets WHERE hash=?', [hash('sha256', $ticket)])['expires_at'] - time();
    check($left > 240 && $left <= 300, 'Startcode-Laufzeit falsch: ' . $left);
    [$code, $out] = page($root, $post + ['HTTP_X_KIENZLE_CSRF' => TEST_CSRF] + $server, json_encode(['action' => 'exchange', 'ticket' => $ticket]));
    $ex = json_decode($out, true);
    check($code === 200 && preg_match('/^[0-9a-f]{32}$/', (string)($ex['visit_id'] ?? '')) === 1, 'Startcode nicht eingelöst: ' . $out);
    [$code] = page($root, $post + ['HTTP_X_KIENZLE_CSRF' => TEST_CSRF] + $server, json_encode(['action' => 'exchange', 'ticket' => $ticket]));
    check($code === 401, 'Startcode zweimal eingelöst');

    // Virtueller Drucker „vKasse“: nur PDF/PostScript, Auftrag wird danach gelöscht, ausgeschaltet abgelehnt.
    $env = 'KIENZLE_DATEN=' . escapeshellarg(Suite::$data) . ' KIENZLE_SUITE_HILFE=' . escapeshellarg(dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php');
    $job = Suite::$data . '/auftrag.bin'; file_put_contents($job, "kein PDF");
    exec($env . ' php ' . escapeshellarg(dirname(__DIR__) . '/printer/kienzle-kasse-rechnung') . ' ' . escapeshellarg($job) . ' 2>&1', $o, $rc);
    check($rc === 1 && str_contains(implode("\n", $o), 'Unbekanntes Druckformat') && !is_file($job), 'Drucker nimmt Unbekanntes an oder lässt den Auftrag liegen');

    // Virtueller Drucker „vBriefversand“: gleiche Formatprüfung, Auftrag wird gelöscht; ohne eingeschaltetes Letterxpress kein Versand.
    $job = Suite::$data . '/auftrag2.bin'; file_put_contents($job, "kein PDF");
    $o = [];
    exec($env . ' php ' . escapeshellarg(dirname(__DIR__) . '/printer/kienzle-letterxpress') . ' ' . escapeshellarg($job) . ' 2>&1', $o, $rc);
    check($rc === 1 && str_contains(implode("\n", $o), 'Unbekanntes Druckformat') && !is_file($job), 'Drucker vBriefversand nimmt Unbekanntes an oder lässt den Auftrag liegen');
    $toml = (string)file_get_contents(dirname(__DIR__) . '/module.toml');
    check(substr_count($toml, '[[printers]]') === 2 && str_contains($toml, 'command = "printer/kienzle-letterxpress"') && is_executable(dirname(__DIR__) . '/printer/kienzle-letterxpress')
        && is_file(dirname(__DIR__) . '/public/assets/vBriefversand-PDF.ppd'), 'Drucker vBriefversand nicht vollständig angelegt');

    // Haken: install legt Grundwerte und Datenbank an; busy meldet laufende Zahlungen (75); Überblick als JSON.
    $fresh = sys_get_temp_dir() . '/ks-haken-' . bin2hex(random_bytes(5)); mkdir($fresh, 0700); $dirs[] = $fresh;
    $env = 'KIENZLE_DATEN=' . escapeshellarg($fresh) . ' KIENZLE_SUITE_HILFE=' . escapeshellarg(dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php');
    $hook = fn(string $h) => [exec($env . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/' . $h) . ' 2>&1', $out2, $code2), $code2, implode("\n", $out2)];
    [, $rc, $out] = $hook('install');
    check($rc === 0 && is_file("$fresh/grundwerte.json") && is_file("$fresh/payments.sqlite"), 'Haken install: ' . $out);
    check($hook('health')[1] === 0 && $hook('busy')[1] === 0, 'Haken health/busy');
    $pdo = new PDO('sqlite:' . $fresh . '/payments.sqlite'); $pdo->exec('PRAGMA foreign_keys=OFF');
    $pdo->exec("INSERT INTO payments(id,visit_id,request_id,request_hash,reference,reader_id,amount_cents,services_json,payment_status,created_at) VALUES('p1','v1','q1','h1','r1','rdr',100,'[]','pending'," . time() . ")");
    check($hook('busy')[1] === 75, 'Haken busy meldet laufende Zahlung nicht');
    $pdo->exec("UPDATE payments SET payment_status='successful'");
    check($hook('busy')[1] === 0, 'Haken busy nach Zahlung noch beschäftigt');
    [, $rc, $out] = $hook('setup-overview');
    $ov = json_decode(substr($out, (int)strpos($out, '{')), true);
    check($rc === 0 && is_array($ov['items'] ?? null) && $ov['items'] !== [], 'Haken setup-overview: ' . $out);
    [, $rc] = $hook('backup-snapshot');
    check($rc === 0 && is_file("$fresh/sicherung/payments.sqlite"), 'Haken backup-snapshot');
    echo "OK: Kasse in der Suite: Adressen, Rechte, CSRF, Starter, Ablage, Überblick, Drucker und Haken.\n";
} finally {
    foreach ($dirs as $dir) rmtree($dir);
}
