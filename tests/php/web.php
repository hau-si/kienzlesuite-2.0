<?php
declare(strict_types=1);
// Webinterface der Basis über simulierte Anfragen: Einrichtung, Anmeldung mit OTP und Passkey, Rechte, /_auth, Schutz.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';
require_once dirname(__DIR__, 2) . '/core/php/modul/Kienzle.php';

use Kienzle\Core\{Auth, Connections, Totp};

function test_ersteinrichtung_mit_link_und_otp(): void {
    $env = new Env();
    $token = $env->users->setupToken(null);
    $b = new Browser($env);
    check($b->get('/einrichten?t=' . $token)->status === 200, 'Einrichtungsseite fehlt');
    $b->post('/einrichten', ['t' => $token, 'login' => 'anna', 'display' => 'Anna Beispiel', 'password' => 'Kurz-1', 'password2' => 'Kurz-1']);
    check($b->last->status === 422 && str_contains($b->last->body, 'mindestens 10'), 'zu kurzes Passwort angenommen');
    $b->post('/einrichten', ['t' => $token, 'login' => 'anna', 'display' => 'Anna Beispiel', 'password' => 'Wiesen-Blume-Fahrrad-7', 'password2' => 'Wiesen-Blume-Fahrrad-7']);
    check($b->last->header('Location') === '/konto/otp', 'Erster Admin nicht zur OTP-Einrichtung geschickt');
    // Ohne OTP ist nichts anderes erreichbar.
    check($b->get('/')->header('Location') === '/konto/otp', 'Startseite ohne OTP erreichbar');
    check($b->get('/verwaltung/benutzer')->header('Location') === '/konto/otp', 'Verwaltung ohne OTP erreichbar');
    $page = $b->get('/konto/otp');
    check(preg_match('/<code class="secret">([A-Z2-7 ]+)<\/code>/', $page->body, $m) === 1, 'Schlüssel nicht angezeigt');
    $secret = str_replace(' ', '', $m[1]);
    $b->post('/konto/otp', ['code' => '000000']);
    check($b->last->status === 422, 'falscher Einrichtungscode angenommen');
    $b->get('/konto/otp');
    preg_match('/<code class="secret">([A-Z2-7 ]+)<\/code>/', $b->last->body, $m);
    $secret = str_replace(' ', '', $m[1]);
    $r = $b->post('/konto/otp', ['code' => code_now($secret)]);
    check($r->status === 200 && substr_count($r->body, '<li><code>') === 10, 'Wiederherstellungscodes nicht angezeigt');
    check($b->get('/')->status === 200 && str_contains($b->last->body, 'Anna Beispiel'), 'nach Einrichtung nicht angemeldet');
    // Link ist verbraucht, und es gibt jetzt einen Admin.
    check($b->get('/einrichten?t=' . $token)->status === 404, 'Einrichtungslink zweimal nutzbar');
    problem(fn() => $env->users->setupToken(null), 409, 'zweiter Erst-Admin-Link möglich');
}

function test_anmelden_mit_code_und_wiederherstellungscode(): void {
    $env = new Env();
    [$id, $secret] = $env->user('anna', true, otp: true);
    $codes = $env->users->newRecoveryCodes($id);
    $b = new Browser($env);
    $b->post('/anmelden', ['login' => 'anna', 'password' => 'falsch-falsch-falsch']);
    check($b->last->status === 401 && $b->cookie === null, 'falsches Passwort angenommen');
    $b->post('/anmelden', ['login' => 'Anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($b->last->header('Location') === '/anmelden/code', 'Code-Schritt fehlt');
    check($b->get('/konto')->header('Location') === '/anmelden/code', 'Konto ohne Code erreichbar');
    check($b->get('/_auth/kasse')->status === 401, '/_auth ohne Code erfolgreich');
    $b->post('/anmelden/code', ['code' => $codes[0]]);
    check($b->last->header('Location') === '/', 'Wiederherstellungscode nicht angenommen');
    check($env->users->recoveryLeft($id) === 9, 'Wiederherstellungscode nicht verbraucht');
    // Derselbe Code ein zweites Mal: nein.
    $c = new Browser($env);
    $c->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $c->post('/anmelden/code', ['code' => $codes[0]]);
    check($c->last->status === 401, 'Wiederherstellungscode zweimal angenommen');
    $c->post('/anmelden/code', ['code' => code_now($secret)]);
    check($c->last->header('Location') === '/', 'gültiger Code nach Fehlversuch abgelehnt');
}

function test_otp_wiederverwendung_und_fehlversuche(): void {
    $env = new Env();
    [, $secret] = $env->user('anna', true, otp: true);
    $a = new Browser($env);
    $a->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $code = code_now($secret);
    $a->post('/anmelden/code', ['code' => $code]);
    $b = new Browser($env, '192.0.2.11');
    $b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $b->post('/anmelden/code', ['code' => $code]);
    check($b->last->status === 401, 'OTP-Code zweimal angenommen');
    $c = new Browser($env, '192.0.2.12');
    $c->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    for ($i = 0; $i < Auth::OTP_TRIES; $i++) $c->post('/anmelden/code', ['code' => '123456']);
    check($c->cookie === null && $c->last->header('Location') === '/anmelden', 'Sitzung nach 5 falschen Codes nicht beendet');
}

function test_sperre_nach_fehlversuchen(): void {
    $env = new Env();
    $env->user('anna', true, otp: true);
    $b = new Browser($env, '192.0.2.20');
    for ($i = 0; $i < 5; $i++) $b->post('/anmelden', ['login' => 'anna', 'password' => 'falsch-falsch-' . $i]);
    $b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($b->last->status === 401 && str_contains($b->last->body, 'Zu viele'), 'keine Sperre nach 5 Fehlversuchen');
    $other = new Browser($env, '192.0.2.21');
    $other->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($other->last->header('Location') === '/anmelden/code', 'Sperre trifft auch andere Absender');
    check(str_contains(implode("\n", $env->audit->recent()), 'anmelden-fehlgeschlagen'), 'Fehlversuch nicht protokolliert');
    check(!str_contains(implode("\n", $env->audit->recent()), 'falsch-falsch'), 'Passwort im Protokoll');
}

function test_unbekanntes_konto_gleiche_meldung(): void {
    $env = new Env();
    $env->user('anna');
    $b = new Browser($env);
    $x = $b->post('/anmelden', ['login' => 'niemand', 'password' => 'Wiesen-Blume-Fahrrad-7'])->body;
    $y = $b->post('/anmelden', ['login' => 'anna', 'password' => 'falsch-falsch-falsch'])->body;
    check(str_contains($x, 'Anmeldename oder Passwort falsch') && str_contains($y, 'Anmeldename oder Passwort falsch'), 'Meldungen verraten gültige Konten');
}

function test_mitarbeiter_ohne_otp_und_rechte_je_modul(): void {
    $env = new Env();
    [$id] = $env->user('bert');
    $env->users->setAccess($id, 'kasse', false, ['paid']);
    $b = new Browser($env);
    $b->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($b->last->header('Location') === '/', 'Mitarbeiter braucht kein OTP');
    $auth = $b->get('/_auth/kasse');
    check($auth->status === 204 && $auth->header('X-Kienzle-Benutzer') === 'bert' && $auth->header('X-Kienzle-Rolle') === 'mitarbeiter'
        && $auth->header('X-Kienzle-Rechte') === 'paid', 'Weitergabe an Modul falsch');
    check(strlen((string)$auth->header('X-Kienzle-Csrf')) === 64, 'Modul-CSRF fehlt');
    check(is_array(json_decode(rawurldecode((string)$auth->header('X-Kienzle-Praxis')), true)), 'Praxisdaten fehlen in der Weitergabe');
    // Menü für die Seitenleiste in Modulseiten: schlank, ohne Sitzungs-CSRF, nur Module mit Zugang
    $menu = json_decode(rawurldecode((string)$auth->header('X-Kienzle-Menue')), true);
    check(is_array($menu) && array_keys($menu['apps']) === ['/kasse/'] && $menu['login'] === 'bert' && $menu['admin'] === false, 'Menü für Modulseiten falsch');
    $k = new KienzleModul('kasse', ['KIENZLE_MENUE' => $auth->header('X-Kienzle-Menue'), 'KIENZLE_PRAXIS' => $auth->header('X-Kienzle-Praxis'), 'KIENZLE_BENUTZER' => 'bert', 'REQUEST_URI' => '/kasse/settings.php']);
    $seite = $k->rahmen('Kasse', '<p id="inhalt">Hallo</p>', ['roh' => true, 'kopf' => '<link rel="stylesheet" href="/kasse/assets/x.css">']);
    check(str_contains($seite, '<aside class="side">') && str_contains($seite, 'href="/kasse/" class="current"') && str_contains($seite, '<p id="inhalt">Hallo</p>') && str_contains($seite, '/kasse/assets/x.css')
        && !str_contains($seite, '/verwaltung/server') && !str_contains($seite, 'action="/abmelden"') && str_contains($seite, 'class="modul"'), 'Rahmen für Modulseiten falsch');
    $start = $b->get('/')->body;
    check(str_contains($start, '<summary class="menu-title">Anwendungen</summary>') && str_contains($start, 'data-menu="anwendungen"') && !str_contains($start, '<span class="menu-title">Einstellungen</span>'), 'Anwendungen fehlen oder Einstellungen stehen doppelt in der Seitenleiste');
    check($b->get('/_auth/beispiel')->status === 403, 'Zugang zu nicht freigegebenem Modul');
    $env->users->setAccess($id, 'kasse', false, []);
    $env->db->store('erstadmin:kasse', (string)$id);
    $ra = $b->get('/_auth/kasse');
    check($ra->header('X-Kienzle-Rolle') === 'admin' && $env->db->setting('erstadmin:kasse') === null, 'Installierender wird nicht Modul-Admin: ' . $ra->status . ' ' . $ra->header('X-Kienzle-Rolle') . ' ' . var_export($env->db->setting('erstadmin:kasse'), true));
    check($b->get('/_auth/unbekannt')->status === 404, 'unbekanntes Modul nicht abgewiesen');
    check($b->get('/verwaltung/benutzer')->status === 403, 'Mitarbeiter in der Verwaltung');
    check(str_contains($b->get('/')->body, 'href="/kasse/"') && !str_contains($b->last->body, 'href="/beispiel/"'), 'Menü zeigt falsche Module');
}

function test_basis_admin_darf_in_modulen_alles(): void {
    $env = new Env();
    [$b, $id] = admin_browser($env);
    $r = $b->get('/_auth/kasse');
    check($r->status === 204 && $r->header('X-Kienzle-Rolle') === 'admin', 'Basis-Admin ist nicht automatisch Modul-Admin');
    check($r->header('X-Kienzle-Rechte') !== '' && count(explode(',', $r->header('X-Kienzle-Rechte'))) === count($env->modules->rightIds('kasse')), 'Basis-Admin hat nicht alle Rechte');
    [$uid] = $env->user('bert');
    check($b->get('/_auth/kasse')->status === 204 && $env->users->access($uid) === [], 'Mitarbeiter ohne Zugang bekommt Zugang');
    // Seitenleiste in Modulen wie in der Basis: Admins bekommen Zähler und offene Punkte, zwischengespeichert; Mitarbeiter nichts davon
    $menu = json_decode(rawurldecode((string)$r->header('X-Kienzle-Menue')), true);
    check(is_array($menu) && $menu['admin'] === true && isset($menu['zaehler']['updates'], $menu['zaehler']['einrichtung']) && is_array($menu['offen']) && is_array($menu['todo']), 'Menü der Module ohne Zähler für Admins');
    $env->db->store('menue.hinweise', json_encode(['t' => time(), 'zaehler' => ['updates' => 2, 'module' => 1, 'sicherung' => 0, 'einrichtung' => 3], 'offen' => ['/kasse/']]));
    $menu = json_decode(rawurldecode((string)$b->get('/_auth/kasse')->header('X-Kienzle-Menue')), true);
    check($menu['zaehler'] === ['updates' => 2, 'module' => 1, 'sicherung' => 0, 'einrichtung' => 3], 'Zähler nicht aus dem Zwischenspeicher');
    $k = new KienzleModul('kasse', ['KIENZLE_MENUE' => $b->last->header('X-Kienzle-Menue'), 'KIENZLE_BENUTZER' => 'admin', 'REQUEST_URI' => '/kasse/']);
    $seite = $k->rahmen('Kasse', '<p>x</p>', ['roh' => true]);
    check(str_contains($seite, 'class="badge" title="Neue Version verfügbar">2<') && str_contains($seite, 'class="logo-dot"'), 'Zähler fehlen in der Seitenleiste des Moduls');
}

function test_anzeigename_im_konto_aendern(): void {
    $env = new Env();
    [$b, $id] = admin_browser($env);
    $seite = $b->get('/konto')->body;
    check(str_contains($seite, 'action="/konto/name"') && str_contains($seite, '<details class="fold">') && str_contains($seite, 'class="name-edit"') && str_contains($seite, 'aria-label="Namen ändern"'), 'Konto: Namensfeld (Stift) oder Ausklappzeilen fehlen');
    $b->post('/konto/name', ['display' => "  Anna Beispiel\x01 "]);
    check($env->users->find($id)['display'] === 'Anna Beispiel' && str_contains($b->last->body, 'Name gespeichert.') && str_contains($b->last->body, 'value="Anna Beispiel"'), 'Anzeigename nicht gespeichert');
    $login = $env->users->find($id)['login'];
    check($login !== 'Anna Beispiel', 'Benutzername darf sich nicht ändern');
    $b->post('/konto/name', ['display' => str_repeat('a', 81)]);
    check($b->last->status === 422 && $env->users->find($id)['display'] === 'Anna Beispiel', 'Zu langer Name wird angenommen');
    $b->post('/konto/name', ['display' => '']);
    check($env->users->find($id)['display'] === '', 'Name lässt sich nicht leeren');
    $b->get('/konto');
    check(str_contains($b->last->body, 'Benutzername ' . $login), 'Benutzername wird nicht angezeigt');
}

function test_modul_admin_braucht_otp(): void {
    $env = new Env();
    [$id] = $env->user('bert');
    $env->users->setAccess($id, 'kasse', true, []);
    $b = new Browser($env);
    $b->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($b->last->header('Location') === '/konto/otp', 'Modul-Admin ohne OTP angemeldet');
    check($b->get('/_auth/kasse')->status === 401, 'Modul-Admin ohne OTP an Modul weitergereicht');
}

function test_verwaltung_braucht_bestaetigung_und_csrf(): void {
    $env = new Env();
    [$b, , $secret] = admin_browser($env);
    check($b->get('/verwaltung/benutzer')->status === 200, 'Verwaltung direkt nach Anmeldung nicht erreichbar');
    $page = $b->get('/verwaltung/benutzer')->body;
    check(str_contains($page, 'Gruppen</h2>') && str_contains($page, 'Benutzer</h2>'), 'Benutzer und Gruppen nicht auf einer Seite');
    check(str_contains($b->get('/verwaltung/praxis')->body, 'Kontodaten für Überweisungen'), 'Seite Praxis fehlt');
    check($b->post('/verwaltung/praxis', ['aktion' => 'konto', 'holder' => 'Praxis Beispiel', 'iban' => 'DE02 1203 0000 0000 2020 52'])->status === 422, 'falsche IBAN angenommen');
    check($b->post('/verwaltung/praxis', ['aktion' => 'konto', 'holder' => 'Praxis Beispiel', 'iban' => 'DE02 1203 0000 0000 2020 51'])->status === 200 && str_contains($b->get('/verwaltung/praxis')->body, 'DE02 1203 0000 0000 2020 51'), 'Kontodaten nicht gespeichert');
    check($b->post('/verwaltung/praxis', ['aktion' => 'steuer', 'tax_id' => '12/345/67890', 'small_business' => '1'])->status === 200 && str_contains($b->get('/verwaltung/praxis')->body, '12/345/67890'), 'Steuerangaben nicht gespeichert');
    check(Connections::phone('0151 123 456 78') === '+4915112345678' && Connections::phone('0049 151 12345678') === '+4915112345678' && Connections::phone('+43 660 1234567') === '+436601234567', 'Handynummer nicht normalisiert');
    try { Connections::phone('12345'); check(false, 'zu kurze Nummer angenommen'); } catch (\Kienzle\Core\Problem $e) { }
    $fake = ['token_hash' => 'sms-test'];
    $code = $env->auth->smsCodeIssue($fake);
    check(preg_match('/^\d{6}$/D', $code) === 1, 'SMS-Code ohne 6 Ziffern');
    try { $env->auth->smsCodeIssue($fake); check(false, 'zweiter Code binnen einer Minute'); } catch (\Kienzle\Core\Problem $e) { }
    check(str_contains($b->get('/verwaltung/benutzer')->body, 'Server neu starten'), 'Neustart nicht im Menü');
    check(str_contains($b->get('/verwaltung/neustart?art=server')->body, 'Server jetzt neu starten'), 'Neustart-Seite fehlt');
    check(str_contains($b->post('/verwaltung/neustart', ['art' => 'dienste'])->body, 'nicht eingerichtet'), 'Neustart ohne Dienst nicht abgelehnt');
    \Kienzle\Core\Web::$restartUnit = __FILE__;
    check($b->post('/verwaltung/neustart', ['art' => 'wasanderes'])->status === 422, 'unbekannte Art angenommen');
    check($b->post('/verwaltung/neustart', ['art' => 'dienste'])->status === 200 && is_file($env->dir . '/anfragen/neustart.json'), 'Neustart-Anfrage nicht geschrieben');
    check(str_contains(implode("\n", $env->audit->recent()), 'neustart dienste'), 'Neustart nicht protokolliert');
    unlink($env->dir . '/anfragen/neustart.json');
    // Update-Seite: jede Karte muss geschlossen sein (ein h2 im Update-Kasten zerriss früher den Aufbau und schob die Seitenleiste weg).
    $upd = $b->get('/verwaltung/updates')->body;
    check(substr_count($upd, '<div') === substr_count($upd, '</div') && substr_count($upd, '<section') === substr_count($upd, '</section'), 'Updates-Seite: ungleich viele öffnende und schließende Tags');
    $home = $b->get('/')->body;
    check(str_contains($home, 'todo-dot'), 'Keine blaue Markierung für offene Schritte im Menü');
    check(str_contains($b->get('/verwaltung/drucker')->body, '<summary><h2>Drucker suchen</h2></summary>') || !str_contains($b->get('/verwaltung/drucker')->body, 'Drucker suchen'), 'Drucker suchen nicht eingeklappt');
    check(str_contains($home, 'setup-card') && str_contains($home, 'nötigen Schritten erledigt') && !str_contains($home, 'href="/verwaltung/einrichtung"><span>'), 'Einrichtungskarte auf der Startseite fehlt oder Menüpunkt noch da');
    check(str_contains($home, '<svg class="ico"'), 'Menüsymbole fehlen');
    $setup = $b->get('/verwaltung/einrichtung')->body;
    check(strpos($setup, 'Admin mit Authenticator-App') < strpos($setup, 'Mindestens zwei Admins') && strpos($setup, 'Mindestens zwei Admins') < strpos($setup, 'Servername festgelegt') && str_contains($setup, 'tag opt">optional'), 'Einrichtung nicht geordnet oder ohne optional-Kennzeichnung');
    // Update-Seite: Status als Symbol in einer einzigen Karte; roter Punkt am Logo; Einrichtungspunkte ignorieren.
    check(str_contains($home, 'logo-dot'), 'Roter Punkt am Logo fehlt bei offener Einrichtung');
    check(preg_match('/name="aktion" value="ignorieren"><input type="hidden" name="punkt" value="([0-9a-f]{12})"/', $setup, $mi) === 1, 'Ignorieren-Knopf fehlt');
    $openBefore = $b->get('/')->body; preg_match('/(\\d+) von (\\d+) nötigen/', $openBefore, $c0);
    $b->post('/verwaltung/einrichtung', ['aktion' => 'ignorieren', 'punkt' => $mi[1], 'an' => '1']);
    preg_match('/(\\d+) von (\\d+) nötigen/', $b->get('/')->body, $c1);
    check($c1[2] === (string)((int)$c0[2] - 1), 'Ignorierter Punkt zählt weiter als nötig');
    check(str_contains($b->get('/verwaltung/einrichtung')->body, 'Wieder beachten'), '„Wieder beachten“ fehlt');
    $b->post('/verwaltung/einrichtung', ['aktion' => 'ignorieren', 'punkt' => $mi[1], 'an' => '0']);
    preg_match('/(\\d+) von (\\d+) nötigen/', $b->get('/')->body, $c2);
    check($c2[2] === $c0[2], 'Wieder beachten wirkt nicht');
    $prax = $b->get('/verwaltung/praxis')->body;
    check(str_contains($prax, 'class="h-ico"') && str_contains($prax, 'Anschrift <span class="tag opt">nicht ausgefüllt</span>') && str_contains($prax, 'Kontodaten für Überweisungen <span class="tag ok">✓ ausgefüllt</span>'), 'Praxis und Daten: Symbole oder Statusschilder fehlen');
    check(str_contains($b->get('/konto')->body, 'Authenticator-App</h2><span class="tag ok fold-status">eingerichtet</span>'), 'Konto: Statusschild der Authenticator-App fehlt');
    $page = $b->get('/verwaltung/sicherheit')->body;
    check(str_contains($page, 'Firewall <span class="tag') && str_contains($page, 'Zugang (Abmeldezeiten)') && str_contains($page, 'Protokoll</h2>') && str_contains($page, 'name="idle_einheit"'), 'Sicherheit unvollständig');
    check($b->post('/verwaltung/zugang', ['idle' => '2', 'idle_einheit' => 'd', 'max' => '3', 'max_einheit' => 'd'])->status === 200 && $env->auth->idleSeconds() === 172800, 'Zugang in Tagen nicht gespeichert');
    check($b->post('/verwaltung/zugang', ['idle' => '400', 'idle_einheit' => 'd', 'max' => '400', 'max_einheit' => 'd'])->status === 422, 'mehr als ein Jahr angenommen');
    check($b->post('/verwaltung/zugang', ['idle' => '365', 'idle_einheit' => 'd', 'max' => '365', 'max_einheit' => 'd'])->status === 200, 'ein Jahr abgelehnt');
    $env->db->query('UPDATE sessions SET confirmed_at=?', [time() - Auth::CONFIRM_SECONDS - 1]);
    check(str_starts_with((string)$b->get('/verwaltung/benutzer')->header('Location'), '/bestaetigen'), 'Verwaltung ohne erneute Bestätigung');
    $b->get('/bestaetigen?weiter=/verwaltung/benutzer');
    $b->post('/bestaetigen', ['password' => 'Wiesen-Blume-Fahrrad-7', 'code' => '000000', 'weiter' => '/verwaltung/benutzer']);
    check($b->last->status === 403, 'Bestätigung mit falschem Code');
    $b->post('/bestaetigen', ['password' => 'Wiesen-Blume-Fahrrad-7', 'code' => code_now($secret, 1), 'weiter' => '/verwaltung/benutzer']);
    check($b->last->header('Location') === '/verwaltung/benutzer', 'Bestätigung nicht angenommen: ' . $b->last->status);
    $b->get('/verwaltung/benutzer/neu');
    $r = $b->req('POST', '/verwaltung/benutzer', ['login' => 'carla', 'csrf' => 'falsch']);
    check($r->status === 403 && !$env->users->byLogin('carla'), 'Anlegen ohne gültige CSRF-Marke');
}

function test_rollen_matrix_speichern(): void {
    $env = new Env();
    [$b] = admin_browser($env);
    $b->get('/verwaltung/benutzer/neu');   // die Sitzungsmarke steht im Formular (die Seitenleiste hat kein Abmelden-Formular mehr)
    $b->post('/verwaltung/benutzer', ['login' => 'dora', 'display' => 'Dora']);
    $id = (int)$env->users->byLogin('dora')['id'];
    // Rolle MFA, aber „paid“ einzeln abgewählt und „catalog“ einzeln dazu
    $b->post('/verwaltung/benutzer/' . $id, ['display' => 'Dora', 'active' => '1', 'rollen_gesendet' => '1', 'rolle' => ['MFA'], 'recht' => ['kasse:catalog']]);
    check($env->users->roles($id) === ['MFA'], 'Rolle nicht gespeichert');
    check($env->users->overrides($id) === ['kasse:catalog' => true, 'kasse:paid' => false], 'Einzeländerungen falsch: ' . json_encode($env->users->overrides($id)));
    $b->get('/verwaltung/benutzer/' . $id);
    check(str_contains($b->last->body, 'class="matrix"') && str_contains($b->last->body, 'recht abw') && str_contains($b->last->body, 'data-rolle checked'), 'Matrix zeigt Rolle/Abweichung nicht');
    $b->get('/verwaltung/benutzer');
    check(str_contains($b->last->body, 'title="MFA"'), 'Rollen-Symbol fehlt in der Benutzerliste');
    $b->post('/verwaltung/benutzer/' . $id, ['display' => 'Dora', 'active' => '1', 'rollen_gesendet' => '1', 'rolle' => ['Gibtesnicht']]);
    check($b->last->status === 422, 'unbekannte Rolle angenommen');
}

function test_benutzer_anlegen_rechte_und_link(): void {
    $env = new Env();
    [$b] = admin_browser($env);
    $b->get('/verwaltung/benutzer/neu');
    $r = $b->post('/verwaltung/benutzer', ['login' => 'carla', 'display' => 'Carla']);
    check(preg_match('#/einrichten\?t=([A-Za-z0-9_-]{43})#', $r->body, $m) === 1, 'Einrichtungslink nicht angezeigt');
    $carla = $env->users->byLogin('carla');
    $b->post('/verwaltung/benutzer/' . $carla['id'], ['display' => 'Carla', 'active' => '1', 'recht' => ['kasse:paid', 'kasse:catalog', 'beispiel:ansehen']]);
    check($env->users->access((int)$carla['id']) === ['beispiel' => ['admin' => false, 'rights' => ['ansehen']], 'kasse' => ['admin' => false, 'rights' => ['paid', 'catalog']]],
        'Rechte nicht gespeichert: ' . json_encode($env->users->access((int)$carla['id'])));
    $b->post('/verwaltung/benutzer/' . $carla['id'], ['display' => 'Carla', 'active' => '1', 'recht' => ['kasse:loeschen']]);
    check($b->last->status === 422, 'unbekanntes Recht angenommen');
    $b->post('/verwaltung/benutzer/' . $carla['id'], ['display' => 'Carla', 'active' => '1', 'recht' => ['fremd:x']]);
    check($b->last->status === 422, 'unbekanntes Modul angenommen');
    // Gruppen: Vorgaben Admin/Arzt/MFA, Anwenden beim Speichern, eigene Änderung
    $b->get('/verwaltung/gruppen');
    check($b->last->status === 200 && str_contains($b->last->body, ' MFA</h2>') && str_contains($b->last->body, ' Arzt</h2>') && str_contains($b->last->body, ' Admin</h2>'), 'Vorgabe-Gruppen fehlen');
    $b->post('/verwaltung/benutzer/' . $carla['id'], ['display' => 'Carla', 'active' => '1', 'gruppe' => 'Arzt']);
    $acc = $env->users->access((int)$carla['id']);
    check($acc['kasse']['admin'] === false && $acc['kasse']['rights'] === ['paid', 'catalog'] && $acc['beispiel']['rights'] === ['ansehen', 'bearbeiten'], 'Gruppe Arzt nicht angewendet: ' . json_encode($acc));
    $b->post('/verwaltung/gruppen', ['aktion' => 'speichern', 'name' => 'Empfang', 'recht' => ['kasse:paid']]);
    $b->post('/verwaltung/benutzer/' . $carla['id'], ['display' => 'Carla', 'active' => '1', 'gruppe' => 'Empfang']);
    check(array_keys($env->users->access((int)$carla['id'])) === ['kasse'] && $env->users->access((int)$carla['id'])['kasse']['rights'] === ['paid'], 'eigene Gruppe nicht angewendet');
    $b->post('/verwaltung/benutzer/' . $carla['id'], ['display' => 'Carla', 'active' => '1', 'gruppe' => 'Gibtsnicht']);
    check($b->last->status === 422, 'unbekannte Gruppe angenommen');
    $b->post('/verwaltung/gruppen', ['aktion' => 'speichern', 'name' => '<b>x</b>']);
    check($b->last->status === 422, 'ungültiger Gruppenname angenommen');
    $b->post('/verwaltung/gruppen', ['aktion' => 'zuruecksetzen', 'name' => 'Empfang']);
    $b->post('/verwaltung/benutzer/' . $carla['id'], ['display' => 'Carla', 'active' => '1', 'recht' => ['kasse:paid', 'kasse:catalog', 'beispiel:ansehen']]);
    // Carla richtet ihr Passwort über den Link ein.
    $c = new Browser($env, '192.0.2.30');
    $c->post('/einrichten', ['t' => $m[1], 'password' => 'Sonnen-Uhr-Garten-3', 'password2' => 'Sonnen-Uhr-Garten-3']);
    check($c->last->header('Location') === '/', 'Mitarbeiterin nach Einrichtung nicht angemeldet');
    check(str_contains(implode("\n", $env->audit->recent()), 'benutzer-geaendert carla'), 'Rechteänderung nicht protokolliert');
}

function test_admin_konten_nur_an_der_konsole_zuruecksetzen(): void {
    $env = new Env();
    [$b] = admin_browser($env);
    [$bert] = $env->user('bert', true, otp: true);
    $b->get('/verwaltung/benutzer/' . $bert);
    check(!str_contains($b->last->body, '/link"'), 'Link-Knopf für Admin-Konto angeboten');
    check($b->post('/verwaltung/benutzer/' . $bert . '/link')->status === 403, 'Admin-Konto über das Webinterface zurückgesetzt');
}

function test_letzter_admin_und_eigenes_konto(): void {
    $env = new Env();
    [$b, $id] = admin_browser($env);
    $b->get('/verwaltung/benutzer/' . $id);
    $b->post('/verwaltung/benutzer/' . $id, ['display' => 'Anna']);   // ohne admin/active: bleibt trotzdem Admin
    $me = $env->users->find($id);
    check((int)$me['admin'] === 1 && (int)$me['active'] === 1, 'eigenes Konto entmachtet');
    check($b->post('/verwaltung/benutzer/' . $id . '/loeschen')->status === 409, 'eigenes Konto gelöscht');
    problem(fn() => $env->users->delete($id), 409, 'letzter Admin löschbar');
    problem(fn() => $env->users->update($id, 'Anna', false, true), 409, 'letztem Admin die Rolle entzogen');
}

function test_herkunft_und_servername(): void {
    $env = new Env();
    $env->user('anna');
    $b = new Browser($env);
    check($b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7'], ['origin' => 'https://evil.example'])->status === 403, 'fremde Herkunft angenommen');
    check($b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7'], ['origin' => null])->status === 403, 'POST ohne Herkunft angenommen');
    check($b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7'], ['origin' => 'null'])->status === 403, 'Origin null ohne Sec-Fetch-Site angenommen');
    check($b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7'], ['origin' => 'null', 'sec-fetch-site' => 'cross-site'])->status === 403, 'Origin null von fremder Seite angenommen');
    check($b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7'], ['origin' => 'null', 'sec-fetch-site' => 'same-origin'])->status === 303, 'Origin null bei gleicher Seite abgelehnt (Browser nach Weiterleitung)');
    check($b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7'], ['origin' => null, 'sec-fetch-site' => 'same-origin'])->status === 303, 'Sec-Fetch-Site same-origin abgelehnt');
    check($b->get('/anmelden', ['host' => 'evil.example'])->status === 421, 'fremder Servername angenommen');
    $ip = new Browser($env); $ip->host = '192.0.2.5';
    check($ip->get('/anmelden')->status === 200, 'Zugriff über die IP-Adresse aus der Konfiguration gesperrt');
}

function test_weiterleitung_nur_auf_eigene_pfade(): void {
    $env = new Env();
    $env->user('bert');
    foreach (['https://evil.example/', '//evil.example', '/\\evil.example', '/_auth/kasse', '/../x', 'javascript:alert(1)'] as $bad) {
        $b = new Browser($env);
        $b->req('POST', '/anmelden?weiter=' . rawurlencode($bad), ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
        check($b->last->header('Location') === '/', "Weiterleitung auf $bad");
    }
    $b = new Browser($env);
    $b->req('POST', '/anmelden?weiter=' . rawurlencode('/kasse/liste'), ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($b->last->header('Location') === '/kasse/liste', 'eigener Pfad nicht übernommen');
}

function test_schutz_kopfzeilen_und_cookie(): void {
    $env = new Env();
    $env->user('bert');
    $b = new Browser($env);
    $r = $b->get('/anmelden');
    check(str_contains((string)$r->header('Content-Security-Policy'), "frame-ancestors 'none'") && $r->header('Cache-Control') === 'no-store'
        && $r->header('X-Content-Type-Options') === 'nosniff', 'Schutz-Kopfzeilen fehlen');
    $r = $b->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $cookie = (string)$r->header('Set-Cookie');
    check(str_contains($cookie, 'Secure') && str_contains($cookie, 'HttpOnly') && str_contains($cookie, 'SameSite=Strict') && !str_contains($cookie, 'Max-Age'), 'Cookie unsicher: ' . $cookie);
    $r = (new Browser($env))->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7', 'remember' => '1']);
    check(str_contains((string)$r->header('Set-Cookie'), 'Max-Age=15811200'), 'gemerktes Gerät ohne Laufzeit');
}

function test_gemerktes_geraet_nur_aus_dem_netz(): void {
    $env = new Env();
    $env->user('bert');
    $b = new Browser($env, '192.0.2.40');
    $b->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7', 'remember' => '1']);
    check($b->get('/')->status === 200, 'gemerktes Gerät nicht angemeldet');
    $b->ip = '198.51.100.7';
    check($b->get('/')->status === 303, 'gemerktes Cookie aus fremdem Netz gültig');
    $b->ip = '192.0.2.99';
    check($b->get('/')->status === 200, 'gleiches /24-Netz abgelehnt');
}

function test_sitzung_laeuft_ab(): void {
    $env = new Env();
    $env->user('bert');
    $b = new Browser($env);
    $b->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $env->db->query('UPDATE sessions SET expires_at=?', [time() - 1]);
    check($b->get('/')->status === 303, 'abgelaufene Sitzung gültig');
    $b->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $env->db->query('UPDATE sessions SET created_at=?', [time() - 13 * 3600]);
    check($b->get('/')->status === 303, 'Höchstdauer überschritten, Sitzung gültig');
}

function test_neuer_sitzungsschluessel_nach_code(): void {
    $env = new Env();
    [, $secret] = $env->user('anna', true, otp: true);
    $b = new Browser($env);
    $b->post('/anmelden', ['login' => 'anna', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $before = $b->cookie;
    $b->post('/anmelden/code', ['code' => code_now($secret)]);
    check($b->cookie !== null && $b->cookie !== $before, 'Sitzungsschlüssel nach Code nicht erneuert');
    $old = new Browser($env); $old->cookie = $before;
    check($old->get('/')->status === 303, 'alter Schlüssel nach Code noch gültig');
}

function test_passwort_aendern_meldet_andere_geraete_ab(): void {
    $env = new Env();
    $env->user('bert');
    $a = new Browser($env); $a->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']); $a->get('/konto');
    $c = new Browser($env, '192.0.2.50'); $c->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $a->post('/konto/passwort', ['current' => 'falsch-falsch-falsch', 'password' => 'Sonnen-Uhr-Garten-3', 'password2' => 'Sonnen-Uhr-Garten-3']);
    check($a->last->status === 422, 'falsches bisheriges Passwort angenommen');
    $a->post('/konto/passwort', ['current' => 'Wiesen-Blume-Fahrrad-7', 'password' => 'Sonnen-Uhr-Garten-3', 'password2' => 'Sonnen-Uhr-Garten-3']);
    check($a->last->status === 200 && $a->get('/')->status === 200, 'eigene Sitzung nach Passwortwechsel weg');
    check($c->get('/')->status === 303, 'anderes Gerät nach Passwortwechsel noch angemeldet');
}

function test_passkey_anmelden_ersetzt_passwort_und_code(): void {
    $env = new Env();
    [$b, $id] = admin_browser($env);
    $b->get('/konto');
    $opt = $b->post('/konto/passkey/optionen', [], ['x-kienzle-csrf' => $b->csrf]);
    check($opt->status === 200, 'Passkey-Optionen: ' . $opt->body);
    $device = new SoftAuthenticator();
    $reg = $b->post('/konto/passkey', ['json' => json_encode($device->create(json_decode($opt->body, true)) + ['label' => 'Laptop Empfang'])]);
    check($reg->status === 200 && $env->passkeys->count($id) === 1, 'Passkey nicht registriert: ' . $reg->body);
    // Neuer Browser: nur mit Passkey, ohne Passwort und Code.
    $n = new Browser($env, '192.0.2.60');
    $o = json_decode($n->post('/anmelden/passkey/optionen')->body, true);
    $r = $n->post('/anmelden/passkey', ['json' => json_encode($device->get($o) + ['weiter' => '/konto'])]);
    check($r->status === 200 && json_decode($r->body, true)['weiter'] === '/konto' && $n->cookie !== null, 'Passkey-Anmeldung fehlgeschlagen: ' . $r->body);
    check($n->get('/konto')->status === 200, 'nach Passkey nicht angemeldet');
    check(str_contains(implode("\n", $env->audit->recent()), 'passkey-ok'), 'Passkey-Anmeldung nicht protokolliert');
}

function test_passkey_nur_unter_dem_servernamen(): void {
    $env = new Env();
    $env->user('bert');
    $b = new Browser($env); $b->host = '192.0.2.5';
    $r = $b->post('/anmelden/passkey/optionen');
    check($r->status === 409 && str_contains($r->body, 'https://kienzle.example.org'), 'Passkey über IP-Adresse angeboten');
}

function test_passkey_hinzufuegen_braucht_bestaetigung(): void {
    $env = new Env();
    [$b] = admin_browser($env);
    $b->get('/konto');
    $env->db->query('UPDATE sessions SET confirmed_at=0');
    check($b->post('/konto/passkey/optionen', [], ['x-kienzle-csrf' => $b->csrf])->status === 403, 'Passkey ohne frische Bestätigung hinzufügbar');
    check($b->req('POST', '/konto/passkey/optionen', [], ['x-kienzle-csrf' => 'falsch'])->status === 403, 'Passkey-Optionen ohne CSRF');
}

function test_passkey_bestaetigt_heikle_aktion(): void {
    $env = new Env();
    [$b, $id] = admin_browser($env);
    $b->get('/konto');
    $device = new SoftAuthenticator();
    $opt = json_decode($b->post('/konto/passkey/optionen', [], ['x-kienzle-csrf' => $b->csrf])->body, true);
    $b->post('/konto/passkey', ['json' => json_encode($device->create($opt))]);
    $env->db->query('UPDATE sessions SET confirmed_at=0');
    $b->get('/bestaetigen?weiter=/verwaltung/benutzer');
    check(str_contains($b->last->body, 'data-passkey-confirm'), 'Passkey-Bestätigung nicht angeboten');
    $o = json_decode($b->post('/bestaetigen/passkey/optionen', [], ['x-kienzle-csrf' => $b->csrf])->body, true);
    $r = $b->post('/bestaetigen/passkey', ['json' => json_encode($device->get($o) + ['weiter' => '/verwaltung/benutzer'])], ['x-kienzle-csrf' => $b->csrf]);
    check($r->status === 200 && $b->get('/verwaltung/benutzer')->status === 200, 'Passkey-Bestätigung wirkt nicht: ' . $r->body);
}

function test_admin_mit_passkey_ohne_otp_muss_otp_einrichten(): void {
    $env = new Env();
    [$id] = $env->user('dora', true);
    $user = $env->users->find($id);
    $device = new SoftAuthenticator();
    $env->passkeys->finishRegistration($user, $device->create($env->passkeys->registrationOptions($user, $env->passkeys->handle($user))), 'x');
    $b = new Browser($env);
    $o = json_decode($b->post('/anmelden/passkey/optionen')->body, true);
    $r = $b->post('/anmelden/passkey', ['json' => json_encode($device->get($o))]);
    check(json_decode($r->body, true)['weiter'] === '/konto/otp', 'Admin ohne OTP per Passkey voll angemeldet');
}

function test_health(): void {
    $env = new Env();
    $r = (new Browser($env))->get('/_health', ['host' => 'localhost']);
    check($r->status === 200 && json_decode($r->body, true)['status'] === 'ok', 'Gesundheitsprüfung fehlgeschlagen');
}

function test_keine_geheimnisse_in_seiten_und_protokoll(): void {
    $env = new Env();
    [$b, $id, $secret] = admin_browser($env);
    $env->users->newRecoveryCodes($id);
    $pages = '';
    foreach (['/', '/konto', '/verwaltung/benutzer', '/verwaltung/benutzer/' . $id, '/verwaltung/protokoll'] as $p) $pages .= $b->get($p)->body;
    $hash = $env->users->find($id)['password_hash'];
    check(!str_contains($pages, $secret) && !str_contains($pages, $hash) && !str_contains($pages, 'Wiesen-Blume-Fahrrad-7'), 'Geheimnis in einer Seite');
    $log = (string)file_get_contents($env->audit->path());
    check(!str_contains($log, 'Wiesen') && !str_contains($log, $secret), 'Geheimnis im Protokoll');
    $stored = $env->users->find($id)['totp_secret'];
    check(str_starts_with($stored, 'v1:') && !str_contains($stored, $secret), 'OTP-Geheimnis unverschlüsselt gespeichert');
    check(substr(sprintf('%o', fileperms($env->audit->path())), -3) === '600', 'Protokoll nicht 0600');
}

run_tests();
