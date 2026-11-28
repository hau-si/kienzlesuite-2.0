<?php
declare(strict_types=1);
// Praxisherz-Agent: Anmeldung der PCs, Abholen, Sollzustand, „Akte öffnen“ (GDT), Zuordnung Browser → PC, Seiten. Nur erfundene Daten.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\{Arbeitsplaetze, Auth, Modules, Request, Response, Starter, Web};

function st_env(): array {
    $env = new Env();
    $mods = [];
    foreach ($env->modules->all() as $id => $m) {
        if ($id === 'kasse') $m['starters'] = [['schema' => 'praxisherz-kasse', 'label' => 'Kasse']];
        $mods[] = $m;
    }
    $env->modules = Modules::fromArray($mods);
    $crt = $env->dir . '/server.crt';
    exec('openssl req -x509 -newkey rsa:2048 -nodes -keyout ' . escapeshellarg($env->dir . '/k.key') . ' -out ' . escapeshellarg($crt) . ' -subj /CN=kienzle.example.org -days 2 2>/dev/null', $o, $code);
    check($code === 0 && is_file($crt), 'Testzertifikat nicht erzeugt');
    copy($crt, $env->dir . '/ca.crt');   // Zertifizierungsstelle neben server.crt (im Test dasselbe selbstsignierte Zertifikat)
    return [$env, new Starter($env->db, $env->secrets, $env->config, dirname(__DIR__, 2) . '/core', $crt)];
}

function st_web(Env $env, Starter $st, Browser $b): void {
    $b->web = fn() => new Web($env->config, $env->db, $env->users, new Auth($env->db, $env->users, $env->audit, $b->ip), $env->passkeys, $env->modules, $env->audit,
        static fn(string $uri) => '<svg/>', null, null, null, null, null, null, null, null, null, $st);
}

function ap_req(Browser $b, string $method, string $path, array $json = [], array $headers = [], string $ip = '192.0.2.50', array $cookies = []): Response {
    $h = ['host' => 'kienzle.example.org', 'accept' => 'application/json'] + $headers;
    parse_str((string)parse_url($path, PHP_URL_QUERY), $query);
    return ($b->web)()->handle(new Request($method, (string)parse_url($path, PHP_URL_PATH), $query, [], $cookies, $h, $ip, $json ? (string)json_encode($json) : ''));
}

function test_gdt(): void {
    $g = Arbeitsplaetze::gdt('12345');
    // Gleicher Aufbau wie kienzlefon-t2med (Satz 6301, Gesamtlänge 97 Byte bei fünfstelliger Nummer)
    check($g === "01380006301\r\n014810000097\r\n0178315T2MED_PX\r\n0158316AUFRUF\r\n01092062\r\n014921802.10\r\n014300012345\r\n" && strlen($g) === 97, 'GDT-Satz');
    foreach (['0', '0123', 'abc', '1234567890123', '12 3'] as $falsch) {
        try { Arbeitsplaetze::gdt($falsch); check(false, 'GDT ungültige Nummer angenommen: ' . $falsch); } catch (Kienzle\Core\Problem) {}
    }
}

function test_agent(): void {
    [$env, $st] = st_env();
    [$b] = admin_browser($env); st_web($env, $st, $b);
    Web::$clientDist = $env->dir . '/dist';
    mkdir(Web::$clientDist);
    file_put_contents(Web::$clientDist . '/praxisherz-starter-windows-amd64.exe', 'MZ-Testprogramm');
    file_put_contents(Web::$clientDist . '/SHA256SUMS', hash('sha256', 'MZ-Testprogramm') . "  praxisherz-starter-windows-amd64.exe\n" . str_repeat('a', 64) . "  praxisherz-starter-gibtsnicht.exe\n");
    // Selbstentpackendes Setup: Programm + angehängtes Paket mit Einrichtungs- und Deinstallationsskript
    $setup = $b->get('/verwaltung/arbeitsplaetze/' . Web::SETUP_DATEI);
    check($setup->status === 200 && str_starts_with($setup->body, 'MZ-Testprogramm' . "PK\x03\x04") && str_contains($setup->body, 'install-windows.ps1')
        && str_contains($setup->body, 'deinstallieren.ps1') && str_contains($setup->body, 'client.json') && str_contains((string)$setup->header('Content-Disposition'), 'Praxisherz-Setup.exe'), 'Setup-Datei');
    $hilfe = $b->get('/hilfe/arbeitsplatz')->body;
    check(str_contains($hilfe, '/verwaltung/arbeitsplaetze/Praxisherz-Setup.exe') && str_contains($hilfe, 'Deinstallieren'), 'Setup auf der Seite angeboten');
    // Entwurf B: Liste der Arbeitsplätze zuerst, Einrichtung eingeklappt, „Arbeitsplatz einrichten“ oben rechts.
    check(str_contains($hilfe, 'id="plaetze"') && str_contains($hilfe, '<details class="fold" id="paket">') && str_contains($hilfe, 'href="/hilfe/arbeitsplatz?einrichten=1#paket"')
        && strpos($hilfe, 'id="plaetze"') < strpos($hilfe, 'id="paket"'), 'Arbeitsplätze: Liste zuerst, Paket eingeklappt');
    check(str_contains($b->get('/hilfe/arbeitsplatz?einrichten=1')->body, '<details class="fold" id="paket" open>'), 'Arbeitsplatz einrichten klappt das Paket auf');
    check((new Browser($env))->get('/verwaltung/arbeitsplaetze/' . Web::SETUP_DATEI)->status !== 200, 'Setup nur für Admins');
    $k = $st->key();
    check(ap_req($b, 'POST', '/agent/anmelden', ['rechner' => 'EMPFANG-PC'], ['authorization' => 'Bearer falsch'])->status === 401, 'Anmelden mit falschem Schlüssel');
    $r = ap_req($b, 'POST', '/agent/anmelden', ['rechner' => 'EMPFANG-PC<script>', 'system' => 'windows'], ['authorization' => 'Bearer ' . $k]);
    $a = json_decode($r->body, true);
    check($r->status === 200 && preg_match('/^[0-9a-f]{16}$/D', $a['id']) && preg_match('/^[0-9a-f]{64}$/D', $a['token']) && $a['name'] === 'EMPFANG-PCscript', 'Agent angemeldet: ' . $r->body);
    check(!str_contains((string)json_encode($env->db->query('SELECT * FROM arbeitsplaetze')->fetchAll()), $a['token']), 'Zugang nur als Hash gespeichert');
    $auth = ['authorization' => 'Bearer ' . $a['token']];
    check(ap_req($b, 'POST', '/agent/abholen', [], ['authorization' => 'Bearer ' . str_repeat('0', 64)])->status === 401, 'unbekannter Agent');
    $r = json_decode(ap_req($b, 'POST', '/agent/abholen', ['version' => '1.0.0'], $auth)->body, true);
    check($r['auftraege'] === [] && $r['name'] === 'EMPFANG-PCscript' && $r['soll']['kontexte'] === ['praxisherz-kasse' => '/starter/kasse'], 'erste Abfrage mit Sollzustand: ' . json_encode($r['soll']['kontexte'] ?? null));
    check($r['soll']['programme'] === ['praxisherz-starter-windows-amd64.exe' => hash('sha256', 'MZ-Testprogramm')] && $r['soll']['gdt']['ordner'] === 'C:\\Praxisherz\\GDT', 'Programme nur mit Datei, GDT-Ordner fest');
    $r2 = json_decode(ap_req($b, 'POST', '/agent/abholen', ['version' => '1.0.0', 'stand' => $r['stand']], $auth)->body, true);
    check(!isset($r2['soll']) && $r2['stand'] === $r['stand'], 'Sollzustand nur bei Änderung');
    $prog = ap_req($b, 'GET', '/agent/programm/praxisherz-starter-windows-amd64.exe', [], $auth);
    check($prog->status === 200 && $prog->body === 'MZ-Testprogramm', 'Programm laden');
    check(ap_req($b, 'GET', '/agent/programm/kienzle-starter-windows-amd64.exe', [], $auth)->status === 404, 'früherer Programmname gibt es nicht mehr');
    check(ap_req($b, 'GET', '/agent/programm/praxisherz-starter-gibtsnicht.exe', [], $auth)->status === 404 && ap_req($b, 'GET', '/agent/programm/praxisherz-starter-windows-amd64.exe')->status === 401, 'Programm nur vorhanden und mit Zugang');
    // Neuinstallation am selben PC: gleicher Eintrag, neuer Zugang
    $a2 = json_decode(ap_req($b, 'POST', '/agent/anmelden', ['rechner' => 'EMPFANG-PC<script>'], ['authorization' => 'Bearer ' . $k])->body, true);
    check($a2['id'] === $a['id'] && $a2['token'] !== $a['token'] && ap_req($b, 'POST', '/agent/abholen', [], $auth)->status === 401, 'Neuinstallation ersetzt den Zugang');
    $auth = ['authorization' => 'Bearer ' . $a2['token']];
    ap_req($b, 'POST', '/agent/abholen', ['version' => '1.0.0'], $auth);

    // „Akte öffnen“ aus einer Modulseite: Browser mit derselben Adresse wie der Agent
    $csrfModul = Web::moduleCsrf($b->csrf, 'kasse');
    $sitzung = [Auth::COOKIE => $b->cookie];
    $o = ['origin' => 'https://kienzle.example.org'];
    $r = ap_req($b, 'POST', '/arbeitsplatz/akte', ['modul' => 'kasse', 'csrf' => 'falsch', 'patientennummer' => '4711'], $o, '192.0.2.50', $sitzung);
    check($r->status === 403, 'Akte: falsche CSRF-Marke');
    $r = ap_req($b, 'POST', '/arbeitsplatz/akte', ['modul' => 'kasse', 'csrf' => $csrfModul, 'patientennummer' => '4711'], $o, '192.0.2.99', $sitzung);
    check($r->status === 409 && str_contains($r->body, 'Mein Arbeitsplatz'), 'Akte: anderer PC ohne Agent wird nicht erkannt');
    $r = ap_req($b, 'POST', '/arbeitsplatz/akte', ['modul' => 'kasse', 'csrf' => $csrfModul, 'patientennummer' => '4711'], [], '192.0.2.50', $sitzung);
    check($r->status === 403, 'Akte: ohne Origin abgelehnt');
    $r = ap_req($b, 'POST', '/arbeitsplatz/akte', ['modul' => 'kasse', 'csrf' => $csrfModul, 'patientennummer' => '4711'], $o, '192.0.2.50', $sitzung);
    $j = json_decode($r->body, true);
    check($r->status === 200 && $j['ok'] && $j['platz'] === 'EMPFANG-PCscript', 'Akte: Auftrag an den PC: ' . $r->body);
    check(!str_contains((string)file_get_contents($env->dir . '/audit.log'), '4711'), 'Patientennummer nicht im Protokoll');
    $r = json_decode(ap_req($b, 'POST', '/agent/abholen', ['stand' => 'x'], $auth)->body, true);
    check(count($r['auftraege']) === 1 && $r['auftraege'][0]['typ'] === 'gdt' && $r['auftraege'][0]['daten']['datei'] === 'AUFRUFi.gdt'
        && str_contains($r['auftraege'][0]['daten']['inhalt'], "30004711\r\n"), 'Agent holt den GDT-Auftrag');
    check(json_decode(ap_req($b, 'POST', '/agent/abholen', [], $auth)->body, true)['auftraege'] === [], 'Auftrag nur einmal');
    ap_req($b, 'POST', '/agent/ergebnis', ['auftrag' => $j['auftrag'], 'ok' => true, 'text' => 'geschrieben'], $auth);
    check(json_decode(ap_req($b, 'GET', '/arbeitsplatz/stand?id=' . $j['auftrag'], [], [], '192.0.2.50', $sitzung)->body, true)['stand'] === 'ok: geschrieben', 'Stand abfragbar');
    // Verfallene Aufrufe werden nicht mehr ausgeliefert
    $env->db->query('INSERT INTO arbeitsplatz_auftraege(id,platz,typ,daten,erstellt) VALUES(?,?,?,?,?)', ['alt0000000000000', $a['id'], 'gdt', '{}', time() - 300]);
    check(json_decode(ap_req($b, 'POST', '/agent/abholen', [], $auth)->body, true)['auftraege'] === [], 'alter Aufruf verfällt');

    // Zweiter Agent mit derselben Adresse (Terminalserver): nicht eindeutig → Wahl
    $a3 = json_decode(ap_req($b, 'POST', '/agent/anmelden', ['rechner' => 'TS-2'], ['authorization' => 'Bearer ' . $k])->body, true);
    ap_req($b, 'POST', '/agent/abholen', [], ['authorization' => 'Bearer ' . $a3['token']]);
    $r = ap_req($b, 'POST', '/arbeitsplatz/akte', ['modul' => 'kasse', 'csrf' => $csrfModul, 'patientennummer' => '4711'], $o, '192.0.2.50', $sitzung);
    check($r->status === 409, 'zwei Agents gleicher Adresse: nicht eindeutig');
    $r = ap_req($b, 'POST', '/arbeitsplatz/akte', ['modul' => 'kasse', 'csrf' => $csrfModul, 'patientennummer' => '4711'], $o, '192.0.2.50', $sitzung + [Arbeitsplaetze::COOKIE => $a3['id']]);
    check($r->status === 200 && json_decode($r->body, true)['platz'] === 'TS-2', 'gewählter Arbeitsplatz (Cookie) gilt');

    // Mein Konto: Auswahl und Cookie
    $konto = $b->get('/konto');
    check(str_contains($konto->body, 'Mein Arbeitsplatz') && str_contains($konto->body, 'value="' . $a3['id'] . '"'), 'Mein Konto zeigt die Arbeitsplätze');
    $r = $b->post('/konto/arbeitsplatz', ['platz' => $a3['id']]);
    check($r->status === 303 && str_contains((string)$r->header('Set-Cookie'), Arbeitsplaetze::COOKIE . '=' . $a3['id']) && str_contains((string)$r->header('Set-Cookie'), 'HttpOnly'), 'Wahl als Cookie');
    check($b->post('/konto/arbeitsplatz', ['platz' => 'ffffffffffffffff'])->status === 404, 'unbekannter Arbeitsplatz');

    // Seite Arbeitsplätze: Liste, Umbenennen, Entfernen
    $seite = $b->get('/hilfe/arbeitsplatz');
    check(str_contains($seite->body, 'id="plaetze"') && str_contains($seite->body, 'EMPFANG-PCscript') && str_contains($seite->body, 'verbunden'), 'Liste der Arbeitsplätze');
    $b->post('/verwaltung/arbeitsplaetze', ['aktion' => 'platz-umbenennen', 'id' => $a['id'], 'name' => 'Empfang']);
    check($b->last->status === 303 && (new Arbeitsplaetze($env->db))->holen($a['id'])['name'] === 'Empfang', 'umbenannt');
    $b->post('/verwaltung/arbeitsplaetze', ['aktion' => 'platz-umbenennen', 'id' => $a['id'], 'name' => '<b>']);
    check($b->last->status === 400, 'Name geprüft');
    $b->post('/verwaltung/arbeitsplaetze', ['aktion' => 'platz-entfernen', 'id' => $a3['id']]);
    check(ap_req($b, 'POST', '/agent/abholen', [], ['authorization' => 'Bearer ' . $a3['token']])->status === 401, 'entfernter Agent hat keinen Zugang');
    // Bremse
    for ($i = 0; $i < 22; $i++) ap_req($b, 'POST', '/agent/abholen', [], ['authorization' => 'Bearer ' . str_repeat('1', 64)], '192.0.2.77');
    check(ap_req($b, 'POST', '/agent/abholen', [], $auth, '192.0.2.77')->status === 429, 'Bremse nach Fehlversuchen');
    Web::$clientDist = '';
}

test_gdt();
test_agent();
echo "arbeitsplaetze.php: alles in Ordnung\n";
