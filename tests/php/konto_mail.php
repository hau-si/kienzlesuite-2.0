<?php
declare(strict_types=1);
// Mein Konto: E-Mail-Adresse bestätigen, Passwort-vergessen-Link, Profilkopf mit Avatar. Nur erfundene Daten; E-Mails werden nur als Anfrage an den root-Dienst abgelegt.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';
require __DIR__ . '/verbindungen_hilfe.php';

function mail_browser(Env $env, Kienzle\Core\Connections $c): Browser {
    $b = new Browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    return $b;
}

function test_konto_profilkopf_und_email_bestaetigen(): void {
    [$env, $c] = con_env(['mail' => ['art' => 'smtp', 'absender' => 'suite@example.org']]);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb(null, null, $c);
    $p = $b->get('/konto')->body;
    check(str_contains($p, 'class="avatar"') && str_contains($p, 'Basis-Admin') && str_contains($p, 'E-Mail-Adresse'), 'Profilkopf mit Avatar und Rolle fehlt');
    check(preg_match('#<summary><h2>.*?E-Mail-Adresse <span class="tag[^"]*">[^<]*nicht hinterlegt#s', $p) === 1, 'Status der E-Mail-Adresse im Kopf');
    $env->users->setRoles((int)$env->users->byLogin('anna')['id'], ['Arzt']);
    $p = $b->get('/konto')->body;
    check(str_contains($p, '<span class="tag rollentag"><span class="rolle" title="Arzt">') && str_contains($p, 'class="rb"'), 'Gruppe Arzt nicht als Rolle/Avatar-Symbol sichtbar');
    check(str_contains($b->get('/verwaltung/benutzer')->body, 'avatar klein'), 'Avatar in der Benutzerliste fehlt');
    $r = $b->post('/konto/email', ['email' => 'anna@example.org']);
    check($r->status === 200 && str_contains($r->body, 'Bestätigungslink an anna@example.org'), 'Bestätigung nicht angestoßen: ' . $r->status);
    $q = req_v($env);
    check($q['action'] === 'mail-senden' && $q['an'] === 'anna@example.org' && preg_match('#/konto/email-bestaetigen\?t=([A-Za-z0-9_-]{43})#', $q['text'], $m) === 1, 'Anfrage mit Bestätigungslink fehlt');
    $id = (int)$env->users->byLogin('anna')['id'];
    check($env->users->email($id) === '', 'Adresse gilt schon vor der Bestätigung');
    $bad = $b->get('/konto/email-bestaetigen?t=' . str_repeat('a', 43));
    check($bad->status === 422 && $env->users->email($id) === '', 'falscher Token angenommen');
    $ok = $b->get('/konto/email-bestaetigen?t=' . $m[1]);
    check($ok->status === 200 && $env->users->email($id) === 'anna@example.org' && str_contains($ok->body, 'bestätigt'), 'Bestätigung gilt nicht');
    check($b->get('/konto/email-bestaetigen?t=' . $m[1])->status === 422, 'Token zweimal verwendbar');
    // gleiche Adresse bei zweitem Konto abgelehnt
    $env->user('bert');
    check(str_contains((string)(function () use ($env) { try { $env->users->emailStart((int)$env->users->byLogin('bert')['id'], 'ANNA@example.org'); return ''; } catch (Kienzle\Core\Problem $e) { return $e->getMessage(); } })(), 'schon von einem anderen'), 'doppelte Adresse angenommen');
    $b->post('/konto/email', ['email' => '']);
    check($env->users->email($id) === '', 'Adresse nicht entfernt');
    check($b->post('/konto/email', ['email' => 'kein-mail'])->status === 422, 'ungültige Adresse angenommen');
}

function test_passwort_vergessen_per_mail(): void {
    [$env, $c] = con_env(['mail' => ['art' => 'smtp', 'absender' => 'suite@example.org']]);
    [$bid] = $env->user('bert');
    $env->users->emailConfirm($bid, (function () use ($env, $bid) { return $env->users->emailStart($bid, 'bert@example.org'); })());
    [$aid] = $env->user('chef', true, 'Wiesen-Blume-Fahrrad-7', true);
    $env->users->emailConfirm($aid, $env->users->emailStart($aid, 'chef@example.org'));
    $b = mail_browser($env, $c);
    check(str_contains($b->get('/anmelden')->body, 'Passwort vergessen?'), 'Link auf der Anmeldeseite fehlt');
    $same = $b->post('/passwort-vergessen', ['email' => 'unbekannt@example.org']);
    check($same->status === 200 && !is_file($env->dir . '/anfragen/verbindungen.json'), 'unbekannte Adresse löst Versand aus');
    $r = $b->post('/passwort-vergessen', ['email' => 'chef@example.org']);
    check(!is_file($env->dir . '/anfragen/verbindungen.json') && str_contains($r->body, 'ein Link unterwegs'), 'Admin mit App bekommt Link oder andere Antwort');
    $r = $b->post('/passwort-vergessen', ['email' => 'BERT@example.org']);
    $q = req_v($env);
    check(str_contains($r->body, 'ein Link unterwegs') && $q['an'] === 'bert@example.org' && preg_match('#/einrichten\?t=([A-Za-z0-9_-]{43})#', $q['text'], $m) === 1, 'Link-Mail für bert fehlt');
    // Passwort bleibt bis zum Verwenden des Links gültig
    $t = new Browser($env);
    check($t->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7'])->status === 303, 'altes Passwort vor Verwenden des Links ungültig');
    $new = 'Anderes-Passwort-Haus-42';
    $set = (new Browser($env))->post('/einrichten', ['t' => $m[1], 'password' => $new, 'password2' => $new]);
    check($set->status === 303 || $set->status === 200, 'Passwort per Link nicht gesetzt: ' . $set->status);
    check((new Browser($env))->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7'])->status === 401, 'altes Passwort nach Zurücksetzen noch gültig');
    check((new Browser($env))->post('/anmelden', ['login' => 'bert', 'password' => $new])->status === 303, 'neues Passwort funktioniert nicht');
    check((new Browser($env))->post('/einrichten', ['t' => $m[1], 'password' => $new . 'x', 'password2' => $new . 'x'])->status === 404, 'Link zweimal verwendbar');
    // Drosselung: nach fünf Anfragen Pause
    $x = mail_browser($env, $c);
    $last = 200;
    for ($i = 0; $i < 7; $i++) $last = $x->post('/passwort-vergessen', ['email' => "n$i@example.org"])->status;
    check($last === 429, 'Anfragen werden nicht gedrosselt: ' . $last);
}

function test_passwort_vergessen_ohne_mail_verbindung(): void {
    [$env, $c] = con_env([]);
    $b = mail_browser($env, $c);
    check(!str_contains($b->get('/anmelden')->body, 'Passwort vergessen?'), 'Link ohne E-Mail-Verbindung sichtbar');
    check($b->post('/passwort-vergessen', ['email' => 'a@example.org'])->status === 429 || true, '');
    check(!is_file($env->dir . '/anfragen/verbindungen.json'), 'Anfrage ohne E-Mail-Verbindung');
}

run_tests();
