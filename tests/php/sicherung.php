<?php
declare(strict_types=1);
// Seite „Sicherung“ (ohne root-Dienst): Anfragen, Anzeige, Schlüsselblatt, Rechte.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\Backup;

function bk_env(array $status = [], bool $sheet = false): array {
    $env = new Env();
    Backup::$state = $env->dir . '/sicherung'; mkdir(Backup::$state);
    Backup::$unit = $env->dir . '/kienzle-sicherung.path'; touch(Backup::$unit);
    file_put_contents(Backup::$state . '/status.json', json_encode($status));
    if ($sheet) file_put_contents(Backup::$state . '/blatt.html', '<!doctype html><html><body><code>abcde-fghij</code></body></html>');
    return [$env, new Backup($env->db, $env->modules)];
}

function req_b(Env $env): array {
    $f = $env->dir . '/anfragen/sicherung.json';
    $r = json_decode((string)file_get_contents($f), true);
    check(substr(sprintf('%o', fileperms($f)), -3) === '600', 'Anfrage nicht 0600');
    unlink($f);
    return $r;
}

function test_anfragen(): void {
    [$env, $b] = bk_env();
    check(!$b->status()['eingerichtet'] && $b->count() === 1, 'ohne Status: nicht eingerichtet mit einer Warnung');
    $b->setup(['art' => 'ziel', 'id' => 'nas', 'ordner' => ''], 'Praxis Beispiel');
    check(req_b($env) === ['action' => 'einrichten', 'ziel' => ['art' => 'ziel', 'id' => 'nas', 'ordner' => 'kienzle-sicherung'], 'praxis' => 'Praxis Beispiel'], 'Einrichten falsch');
    $b->setup(['art' => 'rest', 'url' => ' https://nas.example.org:8000/k ', 'benutzer' => 'k', 'passwort' => 'p'], 'P');
    check(req_b($env)['ziel']['url'] === 'https://nas.example.org:8000/k', 'REST-Adresse nicht bereinigt');
    problem(fn() => $b->setup(['art' => 'ftp'], 'P'), 422, 'unbekannte Art');
    problem(fn() => $b->action('loeschen'), 422, 'unbekannte Aktion');
    problem(fn() => $b->action('wiederherstellen', ['teil' => 'gibtsnicht', 'snapshot' => 'abcdef12']), 422, 'unbekannter Teil');
    problem(fn() => $b->action('wiederherstellen', ['teil' => 'core', 'snapshot' => '../x']), 422, 'ungültige Sicherung');
    $b->action('wiederherstellen', ['teil' => 'core', 'snapshot' => 'abcdef12']);
    problem(fn() => $b->action('jetzt'), 409, 'zweiter Auftrag, solange der erste wartet');
    check(req_b($env) === ['action' => 'wiederherstellen', 'teil' => 'core', 'snapshot' => 'abcdef12'], 'Wiederherstellen falsch');
    unlink(Backup::$unit);
    problem(fn() => $b->action('jetzt'), 503, 'ohne Dienst');
}

function test_seite(): void {
    $ok = ['ok' => true, 'zeit' => time() - 3600];
    [$env, $bk] = bk_env(['eingerichtet' => time(), 'warnungen' => ['Hinweis <script>'], 'letzte_sicherung' => $ok + ['bytes' => 5 * 1048576, 'neu' => 1048576],
        'letzte_pruefung' => ['ok' => false, 'zeit' => time(), 'meldung' => 'pack <b>kaputt'], 'schnappschuesse' => [['id' => 'abcdef12', 'zeit' => '2026-11-22T02:30:00'], ['id' => '<x>', 'zeit' => '']]], true);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, null, $bk);
    $p = $b->get('/verwaltung/sicherung');
    check($p->status === 200 && str_contains($p->body, 'Schlüsselblatt jetzt drucken') && str_contains($p->body, '5 MB geprüft, 1 MB neu'), 'Seite unvollständig');
    check(!str_contains($p->body, '<script>') && !str_contains($p->body, '<b>kaputt') && str_contains($p->body, 'pack &lt;b&gt;kaputt'), 'nicht maskiert');
    check(str_contains($p->body, '<code>abcdef12</code>') && !str_contains($p->body, '&lt;x&gt;'), 'Liste der Sicherungen falsch gefiltert');
    check(!str_contains($p->body, 'abcde-fghij'), 'Passwort auf der Übersichtsseite');
    $s = $b->get('/verwaltung/sicherung/blatt');
    check($s->status === 200 && str_contains($s->body, 'abcde-fghij') && $s->header('Cache-Control') === 'no-store', 'Blatt fehlt oder wird zwischengespeichert');
    check(str_contains((string)$s->header('Content-Security-Policy'), "default-src 'none'"), 'Blatt ohne strenge CSP');
    check(str_contains(implode("\n", $env->audit->recent()), 'sicherung-blatt-angezeigt'), 'Anzeige des Blatts nicht protokolliert');
    $r = $b->post('/verwaltung/sicherung', ['aktion' => 'aufbewahren', 'daily' => '14', 'weekly' => '4', 'monthly' => '12', 'yearly' => '3']);
    check($r->status === 303 && req_b($env)['aufbewahren'] === ['daily' => 14, 'weekly' => 4, 'monthly' => 12, 'yearly' => 3], 'Aufbewahrung');
    check($b->post('/verwaltung/sicherung', ['aktion' => 'aufbewahren', 'daily' => '-1', 'weekly' => '4', 'monthly' => '12', 'yearly' => '3'])->status === 422, 'negative Zahl angenommen');
    $b->post('/verwaltung/sicherung', ['aktion' => 'wiederherstellen', 'snapshot' => 'abcdef12', 'teil' => 'core']);
    check(req_b($env)['teil'] === 'core', 'Wiederherstellen über die Seite');
    check(str_contains(implode("\n", $env->audit->recent()), 'sicherung-wiederherstellen core abcdef12'), 'Wiederherstellen nicht protokolliert');
    $b->post('/verwaltung/sicherung', ['aktion' => 'pruefen', 'lesen' => '1']);
    check(req_b($env) === ['action' => 'pruefen', 'lesen' => true], 'Prüfen mit Lesen');
    unlink(Backup::$state . '/blatt.html');
    check($b->get('/verwaltung/sicherung/blatt')->status === 404, 'Blatt nach dem Löschen noch abrufbar');
    $env->user('bert');
    $m = new Browser($env); $m->web = fn() => $m->defaultWeb(null, null, null, $bk);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/sicherung')->status === 403 && $m->get('/verwaltung/sicherung/blatt')->status === 403, 'Mitarbeiter auf der Sicherungsseite');
}

function test_einrichtungsformular(): void {
    [$env, $bk] = bk_env();
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, null, $bk);
    $p = $b->get('/verwaltung/sicherung');
    check($p->status === 200 && str_contains($p->body, 'Sicherung einrichten') && str_contains($p->body, 'Noch kein Ziel'), 'Einrichtungsformular');
    $r = $b->post('/verwaltung/sicherung', ['aktion' => 'einrichten', 'art' => 'lokal', 'pfad' => '/media/sicherung']);
    check($r->status === 303 && req_b($env)['ziel'] === ['art' => 'lokal', 'pfad' => '/media/sicherung'], 'Einrichten über die Seite');
}

function test_export(): void {
    [$env, $bk] = bk_env(['eingerichtet' => time()]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, null, $bk);
    check(str_contains($b->get('/verwaltung/sicherung')->body, 'Einstellungen exportieren'), 'Export-Abschnitt fehlt');
    check($b->post('/verwaltung/sicherung', ['aktion' => 'export', 'export_passwort' => 'kurz', 'export_wiederholung' => 'kurz'])->status === 422, 'kurzes Passwort angenommen');
    check($b->post('/verwaltung/sicherung', ['aktion' => 'export', 'export_passwort' => 'Ein-langes-Passwort-1', 'export_wiederholung' => 'Ein-langes-Passwort-2'])->status === 422, 'ungleiche Passwörter');
    $r = $b->post('/verwaltung/sicherung', ['aktion' => 'export', 'export_passwort' => 'Ein-langes-Passwort-1', 'export_wiederholung' => 'Ein-langes-Passwort-1', 'mit_sicherungspasswort' => '1']);
    check($r->status === 303 && req_b($env) === ['action' => 'export', 'passwort' => 'Ein-langes-Passwort-1', 'mit_sicherungspasswort' => true, 'mit_benutzern' => false], 'Export-Anfrage');
    check(!str_contains((string)file_get_contents($env->audit->path()), 'Ein-langes-Passwort'), 'Passwort im Protokoll');
    check($b->get('/verwaltung/sicherung/export')->status === 404, 'Download ohne Export');
    mkdir(Backup::$state . '/export');
    file_put_contents(Backup::$state . '/export/kienzle-einstellungen-20261122-170000.kse', 'KIENZLE-EINSTELLUNGEN 1 {}');
    file_put_contents(Backup::$state . '/status.json', json_encode(['eingerichtet' => time(), 'export' => ['datei' => 'kienzle-einstellungen-20261122-170000.kse', 'zeit' => time(), 'groesse' => 30, 'dateien' => 7]]));
    check(str_contains($b->get('/verwaltung/sicherung')->body, 'Herunterladen'), 'Download-Knopf fehlt');
    $d = $b->get('/verwaltung/sicherung/export');
    check($d->status === 200 && $d->body === 'KIENZLE-EINSTELLUNGEN 1 {}' && str_contains((string)$d->header('Content-Disposition'), 'kienzle-einstellungen-20261122-170000.kse'), 'Download');
    check(str_contains(implode("\n", $env->audit->recent()), 'einstellungen-export-heruntergeladen'), 'Download nicht protokolliert');
    file_put_contents(Backup::$state . '/status.json', json_encode(['export' => ['datei' => '../../etc/passwd']]));
    check($b->get('/verwaltung/sicherung/export')->status === 404, 'Pfad aus dem Status nicht geprüft');
}

function test_zeitplan_und_mail(): void {
    [$env, $bk] = bk_env(['eingerichtet' => time(), 'zeitplan' => ['haeufigkeit' => 'woechentlich', 'uhrzeit' => '23:15', 'wochentag' => 2, 'tag' => 1], 'mail_an' => ['it@example.org']]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, null, $bk);
    $p = $b->get('/verwaltung/sicherung')->body;
    check(str_contains($p, '<option value="woechentlich" selected>') && str_contains($p, 'value="23:15"') && str_contains($p, '<option value="2" selected>Mittwoch'), 'Zeitplan nicht angezeigt');
    check(str_contains($p, 'value="it@example.org"') && str_contains($p, 'Schlüsselblatt neu erzeugen'), 'Empfänger oder Blatt fehlt');
    $b->post('/verwaltung/sicherung', ['aktion' => 'zeitplan', 'haeufigkeit' => 'monatlich', 'uhrzeit' => '01:05', 'wochentag' => '6', 'tag' => '15']);
    check(req_b($env) === ['action' => 'zeitplan', 'zeitplan' => ['haeufigkeit' => 'monatlich', 'uhrzeit' => '01:05', 'wochentag' => 6, 'tag' => 15]], 'Zeitplan-Anfrage');
    foreach ([['haeufigkeit' => 'stuendlich', 'uhrzeit' => '01:00'], ['haeufigkeit' => 'taeglich', 'uhrzeit' => '25:00'], ['haeufigkeit' => 'monatlich', 'uhrzeit' => '01:00', 'tag' => '31']] as $bad) {
        check($b->post('/verwaltung/sicherung', ['aktion' => 'zeitplan'] + $bad)->status === 422, 'ungültiger Zeitplan: ' . json_encode($bad));
    }
    $b->post('/verwaltung/sicherung', ['aktion' => 'mail-an', 'an' => 'a@example.org; b@example.org']);
    check(req_b($env) === ['action' => 'mail-an', 'an' => ['a@example.org', 'b@example.org']], 'Empfänger-Anfrage');
    check($b->post('/verwaltung/sicherung', ['aktion' => 'mail-an', 'an' => 'kein-mail'])->status === 422, 'ungültige Adresse');
    $b->post('/verwaltung/sicherung', ['aktion' => 'blatt-neu']);
    check(req_b($env) === ['action' => 'blatt-neu', 'praxis' => 'Praxis Beispiel'], 'Blatt-Anfrage');
}

run_tests();
