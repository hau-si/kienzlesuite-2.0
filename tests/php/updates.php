<?php
declare(strict_types=1);
// Updates im Webinterface (ohne echten root-Dienst): Status lesen, Anfragen ablegen, Seite und Menü.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\Updates;

function up_env(array $status = []): array {
    $env = new Env();
    Updates::$state = $env->dir . '/update'; mkdir(Updates::$state);
    Updates::$unit = $env->dir . '/kienzle-update.path'; touch(Updates::$unit);
    $status += ['state' => 'done', 'channel' => 'stabil', 'checked_at' => time(), 'local' => ['0.2.0', $env->config->version()],
        'available' => ['core' => [['version' => '9.0.0', 'prerelease' => false, 'notes' => "Neu: <script>x</script>\nZweite Zeile", 'published' => '2026-11-22', 'signed' => true]]]];
    file_put_contents(Updates::$state . '/status.json', json_encode($status));
    return [$env, new Updates($env->db, $env->config)];
}

function req(Env $env): array { return json_decode((string)file_get_contents($env->dir . '/anfragen/update.json'), true); }

function test_status_wird_bereinigt(): void {
    [, $up] = up_env(['available' => ['core' => [['version' => '9.0.0'], ['version' => '../x'], ['version' => '1.0'], 'kaputt']], 'local' => ['0.2.0', '../../etc']]);
    file_put_contents(Updates::$state . '/update.log', "Zeile 1\nZeile 2\x1b[31m\n");
    $s = $up->status();
    check(array_column($s['available'], 'version') === ['9.0.0'] && $s['local'] === ['0.2.0'], 'ungültige Versionen übernommen: ' . json_encode($s));
    check($s['log'] === ['Zeile 1', 'Zeile 2[31m'], 'Protokoll nicht bereinigt');
    check($up->count() === 1 && $s['unsigned'] === false, 'Zählung/Vorgabe falsch');
}

function test_anfragen(): void {
    [$env, $up] = up_env();
    problem(fn() => $up->install('9.9.9'), 409, 'nicht angebotene Version installierbar');
    $up->install('9.0.0');
    check(req($env) === ['action' => 'installieren', 'version' => '9.0.0'], 'Anfrage falsch');
    check($up->status()['state'] === 'requested', 'Zustand nach Anfrage');
    problem(fn() => $up->check(), 409, 'zweite Anfrage während der ersten');
    unlink($env->dir . '/anfragen/update.json');
    problem(fn() => $up->rollback('0.1.0'), 409, 'nicht aufbewahrte Version');
    problem(fn() => $up->rollback($env->config->version()), 409, 'Rücksprung auf die installierte Version');
    $up->rollback('0.2.0');
    check(req($env) === ['action' => 'zurueck', 'version' => '0.2.0'], 'Rücksprung-Anfrage falsch');
    unlink($env->dir . '/anfragen/update.json');
    problem(fn() => $up->setChannel('nacht'), 422, 'unbekannter Kanal');
    problem(fn() => $up->setChannel('stabil', true), 422, 'unsigniert im stabilen Kanal');
    $up->setChannel('beta', true);
    check(req($env) === ['action' => 'kanal', 'kanal' => 'beta', 'signatur' => 'beta'], 'Kanal-Anfrage falsch');
    unlink($env->dir . '/anfragen/update.json');
    $up->setChannel('stabil', 'aus');
    check(req($env) === ['action' => 'kanal', 'kanal' => 'stabil', 'signatur' => 'aus'], 'Signaturprüfung aus');
    problem(fn() => $up->setChannel('stabil', 'egal'), 422, 'unbekannte Stufe');
    unlink($env->dir . '/anfragen/update.json');
    file_put_contents(Updates::$state . '/status.json', json_encode(['state' => 'running', 'step' => 'installieren']));
    problem(fn() => $up->check(), 409, 'Anfrage während einer Aktualisierung');
    unlink(Updates::$unit);
    problem(fn() => (new Updates($env->db, $env->config))->setChannel('beta'), 503, 'ohne Dienst');
}

function test_seite_und_menue(): void {
    [$env, $up] = up_env();
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, $up);
    $page = $b->get('/verwaltung/updates');
    check($page->status === 200 && str_contains($page->body, 'Basis 9.0.0') && str_contains($page->body, 'Zurück auf eine frühere Version'), 'Seite unvollständig');
    check(str_contains($page->body, 'update-state new') && str_contains($page->body, 'Neue Version verfügbar: Basis 9.0.0') && !str_contains($page->body, '<h2>Verfügbar</h2>'), 'Statussymbol „neue Version“ fehlt oder eigene Karte „Verfügbar“');
    check(!str_contains($page->body, '<script>x') && str_contains($page->body, '&lt;script&gt;x'), 'Release-Notizen nicht maskiert');
    check(str_contains($b->get('/')->body, '<span class="badge" title="Neue Version verfügbar">1</span>'), 'Hinweis im Menü fehlt');
    $r = $b->post('/verwaltung/updates', ['aktion' => 'installieren', 'version' => '9.0.0']);
    check($r->status === 303 && req($env)['version'] === '9.0.0', 'Installieren über die Seite');
    $g = $b->get('/verwaltung/updates');
    check($g->header('Refresh') === '5', 'Seite lädt beim Warten nicht neu');
    check(preg_match('#<main[^>]*><p class="notice" role="status">Update gestartet#', $g->body) === 1, 'Rückmeldung nach „Jetzt installieren“ fehlt');
    check(!str_contains($b->get('/verwaltung/updates')->body, 'Update gestartet. Die Seite'), 'Rückmeldung nur einmal zeigen');
    check(str_contains(implode("\n", $env->audit->recent()), 'update-installieren 9.0.0'), 'nicht protokolliert');
    unlink($env->dir . '/anfragen/update.json');
    $b->post('/verwaltung/updates', ['aktion' => 'kanal', 'kanal' => 'beta', 'unsigniert' => '1']);
    check(req($env) === ['action' => 'kanal', 'kanal' => 'beta', 'signatur' => 'beta'] && str_contains(implode("\n", $env->audit->recent()), 'signatur-beta'), 'Freigabe unsignierter Versionen');
    unlink($env->dir . '/anfragen/update.json');
    $b->post('/verwaltung/updates', ['aktion' => 'kanal', 'kanal' => 'stabil', 'signatur' => 'aus']);
    check(req($env) === ['action' => 'kanal', 'kanal' => 'stabil', 'signatur' => 'aus'] && str_contains(implode("\n", $env->audit->recent()), 'signatur-aus'), 'Signaturprüfung abschalten');
    $env->db->query('UPDATE sessions SET confirmed_at=0');
    check(str_starts_with((string)$b->post('/verwaltung/updates', ['aktion' => 'pruefen'])->header('Location'), '/bestaetigen'), 'Update ohne Bestätigung');
    $env->user('bert');
    $m = new Browser($env); $m->web = fn() => $m->defaultWeb(null, $up);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/updates')->status === 403 && !str_contains($m->get('/')->body, 'badge'), 'Mitarbeiter sieht Updates');
}

function test_unsignierte_kennzeichnung(): void {
    [$env, $up] = up_env(['channel' => 'beta', 'unsigned' => true, 'available' => ['core' => [['version' => '9.0.0-beta.1', 'prerelease' => true, 'notes' => '', 'signed' => false]]]]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, $up);
    $page = $b->get('/verwaltung/updates')->body;
    check(str_contains($page, 'nicht signiert') && str_contains($page, 'name="signatur" value="beta" checked'), 'unsignierte Version nicht gekennzeichnet');
}

run_tests();
