<?php
declare(strict_types=1);
// Seite „Drucker“ (ohne root-Dienst) und Druck-Hilfen für Module.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';
require dirname(__DIR__, 2) . '/core/php/modul/Kienzle.php';

use Kienzle\Core\Printers;

function pr_env(array $status = []): array {
    $env = new Env();
    Printers::$state = $env->dir . '/drucker'; mkdir(Printers::$state);
    Printers::$unit = $env->dir . '/kienzle-drucker.path'; touch(Printers::$unit);
    file_put_contents(Printers::$state . '/status.json', json_encode($status + ['warteschlangen' => [
        ['name' => 'Bon', 'address' => '192.0.2.80', 'state' => 'bereit', 'description' => 'Kienzle-Suite Bondrucker', 'managed' => true, 'modul' => '', 'share' => ['samba']],
        ['name' => 'Beispiel-Drucker', 'address' => 'auf diesem Server', 'state' => 'bereit', 'description' => 'Kienzle-Suite Modul beispiel: x', 'managed' => true, 'modul' => 'beispiel', 'share' => []],
        ['name' => 'Fremd', 'address' => '192.0.2.7', 'state' => 'bereit', 'description' => 'Von <b>Hand', 'managed' => false, 'modul' => '', 'share' => []],
        ['name' => '../boese', 'address' => '', 'state' => '', 'description' => '', 'managed' => true]],
        'auftraege' => [['id' => 'Bon-12', 'drucker' => 'Bon', 'benutzer' => 'ks-kasse', 'groesse' => 2048, 'zeit' => 'Sun 22 Nov'], ['id' => 'x; rm', 'drucker' => 'Bon']],
        'netz' => ['arbeitsgruppe' => 'WORKGROUP'],
        'gefunden' => [['uri' => 'ipp://192.0.2.90/ipp/print', 'name' => 'A4 <i>Empfang', 'kind' => 'office', 'connection' => 'Netzwerk'], ['uri' => 'file:///etc/passwd', 'name' => 'x', 'kind' => null]]]));
    return [$env, new Printers($env->db)];
}

function req_p(Env $env): array {
    $f = $env->dir . '/anfragen/drucker.json';
    $r = json_decode((string)file_get_contents($f), true);
    unlink($f);
    return $r;
}

function test_anfragen(): void {
    [$env, $p] = pr_env();
    check(array_column($p->status()['queues'], 'name') === ['Bon', 'Beispiel-Drucker', 'Fremd'], 'ungültiger Name aus dem Status übernommen');
    check(count($p->status()['found']) === 1, 'ungültige Adresse aus der Suche übernommen');
    $p->add('office', ' A4 ', 'ipp://192.0.2.90/ipp/print', ['ipp', 'nfs']);
    check(req_p($env) === ['action' => 'anlegen', 'art' => 'office', 'name' => 'A4', 'adresse' => 'ipp://192.0.2.90/ipp/print', 'freigabe' => ['ipp']], 'Anlegen');
    problem(fn() => $p->add('laser', '', '192.0.2.1', []), 422, 'unbekannte Art');
    problem(fn() => $p->add('bon', 'mit leer', '192.0.2.1', []), 422, 'Name mit Leerzeichen');
    problem(fn() => $p->add('bon', '', "192.0.2.1\n-x", []), 422, 'Adresse mit Zeilenumbruch');
    problem(fn() => $p->remove('Unbekannt'), 404, 'unbekannter Drucker');
    $p->testPage('Bon');
    problem(fn() => $p->search(), 409, 'zweite Anfrage');
    check(req_p($env) === ['action' => 'testseite', 'name' => 'Bon'], 'Testseite');
}

function test_seite(): void {
    [$env, $p] = pr_env(['letzte' => ['ok' => false, 'meldung' => 'Fehler <script>']]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, null, null, $p);
    $page = $b->get('/verwaltung/drucker');
    check($page->status === 200 && str_contains($page->body, 'Virtuelle Drucker') && str_contains($page->body, 'Modul Beispielmodul') && str_contains($page->body, 'Anleitung: virtuelle Drucker an den Arbeitsplätzen') && str_contains($page->body, ':631/printers/'), 'Seite unvollständig');
    check(str_contains($page->body, 'Wofür welcher Drucker?'), 'Zuordnung fehlt');
    check($b->post('/verwaltung/drucker', ['aktion' => 'rollen', 'buero' => 'Gibtsnicht', 'bon' => ''])->status === 422, 'Unbekannter Drucker in der Zuordnung');
    check($b->post('/verwaltung/drucker', ['aktion' => 'rollen', 'buero' => 'Fremd', 'bon' => 'Bon'])->status === 303, 'Zuordnung speichern');
    check(json_decode((string)$env->db->setting('drucker.rollen'), true) === ['buero' => 'Fremd', 'bon' => 'Bon'], 'Zuordnung nicht gespeichert');
    check($b->post('/verwaltung/drucker', ['aktion' => 'rollen', 'buero' => 'Fremd', 'bon' => 'Bon', 'bonbreite' => '70'])->status === 422, 'Ungültige Papierbreite');
    check($b->post('/verwaltung/drucker', ['aktion' => 'rollen', 'buero' => 'Fremd', 'bon' => 'Bon', 'bonbreite' => '80'])->status === 303 && $env->db->setting('drucker.bonbreite') === '80', 'Papierbreite nicht gespeichert');
    check(str_contains($b->get('/verwaltung/drucker')->body, 'Papierbreite des Bondruckers') && str_contains($b->get('/verwaltung/drucker')->body, '<option value="80" selected>'), 'Papierbreite nicht angezeigt');
    check(!str_contains($page->body, '<script>') && !str_contains($page->body, 'Von <b>') && !str_contains($page->body, 'A4 <i>'), 'nicht maskiert');
    check(substr_count($page->body, '>Entfernen<') === 1, 'Entfernen nur für eigene Drucker der Suite');
    $r = $b->post('/verwaltung/drucker', ['aktion' => 'freigabe', 'name' => 'Bon', 'samba' => '1', 'ipp' => '1']);
    check($r->status === 303 && req_p($env) === ['action' => 'freigabe', 'name' => 'Bon', 'freigabe' => ['samba', 'ipp']], 'Freigabe');
    check(str_contains(implode("\n", $env->audit->recent()), 'drucker-freigabe Bon'), 'nicht protokolliert');
    // Virtuelle Drucker: Karte mit IPP-Adresse zum Kopieren, IPP immer an, Samba als Schalter mit Erläuterung; IPP wird auch ohne Feld erzwungen
    $page = $b->get('/verwaltung/drucker')->body;
    check(str_contains($page, 'class="vp-karte"') && str_contains($page, 'data-copy="http://') && str_contains($page, ':631/printers/Beispiel-Drucker') && str_contains($page, 'Freigabe per IPP: immer an')
        && str_contains($page, 'Windows-Freigabe (Samba, alt)') && str_contains($page, 'class="inline vp-samba" data-auto') && str_contains($page, 'nicht mehr unterstützt') && str_contains($page, 'Standardweg für alle Betriebssysteme')
        && !str_contains($page, 'Freigabe speichern') && !str_contains($page, 'für macOS und andere freigeben'), 'Karte der virtuellen Drucker: ');
    check(substr_count($page, 'name="samba" value="1"') === 1 && substr_count($page, 'name="samba" value="1" checked') === 0, 'Samba beim virtuellen Drucker ohne Freigabe ist angehakt');
    $r = $b->post('/verwaltung/drucker', ['aktion' => 'freigabe', 'name' => 'Beispiel-Drucker', 'samba' => '1']);
    check($r->status === 303, 'Freigabe virtuell: ' . $r->status);
    check(req_p($env) === ['action' => 'freigabe', 'name' => 'Beispiel-Drucker', 'freigabe' => ['samba', 'ipp']], 'IPP wird bei virtuellen Druckern nicht erzwungen');
    $b->post('/verwaltung/drucker', ['aktion' => 'freigabe', 'name' => 'Beispiel-Drucker']);
    check(req_p($env) === ['action' => 'freigabe', 'name' => 'Beispiel-Drucker', 'freigabe' => ['ipp']], 'Ohne Samba muss IPP bleiben');
    check($b->post('/verwaltung/drucker', ['aktion' => 'anlegen', 'art' => 'bon', 'adresse' => ''])->status === 422, 'leere Adresse');
    $env->user('bert');
    $m = new Browser($env); $m->web = fn() => $m->defaultWeb(null, null, null, null, $p);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/drucker')->status === 403, 'Mitarbeiter auf der Druckerseite');
}

function test_modul_drucken(): void {
    $dir = tmpdir();
    file_put_contents("$dir/status.json", json_encode(['warteschlangen' => [['name' => 'A4', 'description' => 'Kienzle-Suite Praxisdrucker', 'state' => 'bereit'], ['name' => 'x y']]]));
    $calls = [];
    $k = new KienzleModul('kasse', ['X' => '1'], drucker: "$dir/status.json", lp: function (array $args, string $data) use (&$calls) {
        $calls[] = [$args, strlen($data)];
        return ['code' => 0, 'output' => 'request id is A4-7 (1 file(s))', 'stderr' => ''];
    });
    check(array_column($k->drucker(), 'name') === ['A4'], 'Druckerliste für Module');
    check($k->drucken('A4', '%PDF-1.4 erfunden', 'Rechnung RE-42 <x>', ['media=A4']) === 'A4-7', 'Auftragsnummer');
    check($calls[0][0] === ['/usr/bin/lp', '-d', 'A4', '-t', 'Rechnung RE-42 x', '-o', 'media=A4'], 'lp-Aufruf: ' . json_encode($calls[0][0]));
    try { $k->drucken('Fremd', 'x', 't'); throw new LogicException('unbekannter Drucker angenommen'); } catch (RuntimeException) {}
    try { $k->drucken('A4', 'x', 't', ['-h evil']); throw new LogicException('Option angenommen'); } catch (RuntimeException) {}
    $f = new KienzleModul('kasse', ['X' => '1'], drucker: "$dir/status.json", lp: fn() => ['code' => 1, 'output' => '', 'stderr' => 'lp: The printer or class does not exist.']);
    try { $f->drucken('A4', 'x', 't'); throw new LogicException('Fehler von lp verschluckt'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'does not exist'), 'Meldung von lp fehlt'); }
}

function test_auftraege_und_netz(): void {
    [$env, $p] = pr_env();
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, null, null, $p);
    $page = $b->get('/verwaltung/drucker')->body;
    check(str_contains($page, '<code>Bon-12</code>') && !str_contains($page, 'x; rm') && str_contains($page, 'Alle Aufträge von Bon abbrechen'), 'Aufträge');
    check(str_contains($page, 'Arbeitsgruppe: <strong>WORKGROUP') && !str_contains($page, 'Domäne'), 'Windows-Netz');
    $b->post('/verwaltung/drucker', ['aktion' => 'abbrechen', 'auftrag' => 'Bon-12']);
    check(req_p($env) === ['action' => 'abbrechen', 'auftrag' => 'Bon-12'], 'Abbrechen');
    check($b->post('/verwaltung/drucker', ['aktion' => 'abbrechen', 'auftrag' => 'Bon-99'])->status === 422, 'unbekannter Auftrag');
    $b->post('/verwaltung/drucker', ['aktion' => 'arbeitsgruppe', 'arbeitsgruppe' => 'PRAXIS']);
    check(req_p($env) === ['action' => 'arbeitsgruppe', 'arbeitsgruppe' => 'PRAXIS'], 'Arbeitsgruppe');
    check(str_contains(implode("\n", $env->audit->recent()), 'drucker-arbeitsgruppe PRAXIS'), 'Arbeitsgruppe nicht protokolliert');
}

function test_dateifreigabe(): void {
    [$env, $p] = pr_env(['dateifreigaben' => [['key' => 'fax/ablage', 'name' => 'Fax-Ausgang', 'label' => 'Faxe <b>ablegen', 'modul' => 'fax', 'an' => false], ['key' => '../x', 'name' => 'Boese']]]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, null, null, $p);
    $page = $b->get('/verwaltung/drucker')->body;
    check(str_contains($page, 'Fax-Ausgang') && !str_contains($page, 'Boese') && !str_contains($page, '<b>ablegen') && !str_contains($page, 'checked'), 'Freigabe angeboten, aus');
    $b->post('/verwaltung/drucker', ['aktion' => 'dateifreigabe', 'key' => 'fax/ablage', 'an' => '1', 'kennwort' => 'geheim-1234']);
    check(req_p($env) === ['action' => 'dateifreigabe', 'key' => 'fax/ablage', 'an' => true, 'kennwort' => 'geheim-1234'], 'Freigabe einschalten');
    check(!str_contains(implode("\n", $env->audit->recent()), 'geheim-1234'), 'Kennwort nicht im Protokoll');
    $b->post('/verwaltung/drucker', ['aktion' => 'dateifreigabe', 'key' => 'fax/ablage']);
    check(req_p($env) === ['action' => 'dateifreigabe', 'key' => 'fax/ablage', 'an' => false], 'Freigabe ausschalten');
    check($b->post('/verwaltung/drucker', ['aktion' => 'dateifreigabe', 'key' => 'fax/anderes', 'an' => '1'])->status === 422, 'unbekannte Freigabe');
    check(str_contains(implode("\n", $env->audit->recent()), 'drucker-dateifreigabe fax/ablage an'), 'Freigabe protokolliert');
}

run_tests();
