<?php
declare(strict_types=1);
// Praxis → Öffnungszeiten: Feiertage, Prüfung der Eingaben, Texte, Paket für die Module, Seite mit Recht „core:zeiten“ (MFA). Nur erfundene Daten.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';
require __DIR__ . '/verbindungen_hilfe.php';

use Kienzle\Core\{Feiertage, Groups, Problem, Zeiten};

function test_feiertage(): void {
    check(Feiertage::ostern(2026) === '2026-04-05' && Feiertage::ostern(2027) === '2027-03-28' && Feiertage::ostern(2030) === '2030-04-21', 'Ostern falsch');
    $bw = array_column(Feiertage::liste('BW', 2027), null, 'id');
    check($bw['fronleichnam']['datum'] === '2027-05-27' && $bw['fronleichnam']['art'] === 'gesetzlich', 'Fronleichnam BW');
    check($bw['drei-koenige']['art'] === 'gesetzlich' && !isset($bw['reformationstag']), 'Länderliste BW');
    check($bw['bruecke-himmelfahrt']['datum'] === '2027-05-07' && $bw['bruecke-himmelfahrt']['art'] === 'zusatz', 'Brückentag nach Himmelfahrt');
    check($bw['rosenmontag']['datum'] === '2027-02-08', 'Rosenmontag');
    $sn = array_column(Feiertage::liste('SN', 2026), null, 'id');
    check($sn['buss-und-bettag']['datum'] === '2026-11-18' && $sn['fronleichnam']['art'] === 'regional', 'Sachsen');
    check(array_column(Feiertage::liste('SN', 2027), null, 'id')['buss-und-bettag']['datum'] === '2027-11-17', 'Buß- und Bettag 2027');
    check(Feiertage::liste('XX', 2027) === [], 'unbekanntes Land');
    check(Feiertage::aktiv(['id' => 'neujahr', 'art' => 'gesetzlich'], [], []) && !Feiertage::aktiv(['id' => 'silvester', 'art' => 'zusatz'], [], []), 'Vorgaben');
    check(!Feiertage::aktiv(['id' => 'neujahr', 'art' => 'gesetzlich'], [], ['neujahr']) && Feiertage::aktiv(['id' => 'silvester', 'art' => 'zusatz'], ['silvester'], []), 'Auswahl');
}

function test_eingaben_und_texte(): void {
    $env = new Env();
    Zeiten::$heute = '2026-11-28';
    $z = new Zeiten($env->db);
    check(Zeiten::fenster('8-12, 14:00 – 18:30', 'x') === ['08:00-12:00', '14:00-18:30'], 'Zeiten einheitlich');
    problem(fn() => Zeiten::fenster('14-12', 'x'), 422, 'Ende vor Beginn');
    problem(fn() => Zeiten::fenster('08:00-12:00, 11:00-13:00', 'x'), 422, 'Überschneidung');
    problem(fn() => Zeiten::fenster('abends', 'x'), 422, 'Unsinn');
    $z->wocheSpeichern(['mo' => '08:00-12:00, 14:00-18:00', 'sa' => ''], ['mo' => '08:00-11:30']);
    $w = $z->woche();
    check($w['sprech']['mo'] === '08:00-12:00, 14:00-18:00' && $w['telefon']['mo'] === '08:00-11:30' && $w['sprech']['sa'] === '', 'Woche gespeichert');
    $a = $z->praxisSpeichern(['name' => 'Praxis Dr. med. Erika Beispiel', 'strasse' => 'Musterstraße 1', 'ort' => '12345 Musterstadt', 'telefon' => '01234 56789']);
    $b = $z->praxisSpeichern(['name' => 'Hausarztpraxis Max Mustermann', 'telefon' => '01234/98765']);
    problem(fn() => $z->praxisSpeichern(['name' => '<script>']), 422, 'Zeichen im Namen');
    problem(fn() => $z->praxisSpeichern(['name' => 'X', 'telefon' => 'ruf an']), 422, 'Telefon');
    $id = $z->ereignisSpeichern(['art' => 'urlaub', 'titel' => 'Weihnachtsurlaub', 'von' => '23.12.2026', 'bis' => '2027-01-03', 'homepage' => '1', 'telefon' => '1',
        'v_von' => ['2026-12-23', '', ''], 'v_bis' => ['2026-12-31', '', ''], 'v_praxis' => [$a, '', '']]);
    $z->ereignisSpeichern(['id' => $id, 'art' => 'urlaub', 'titel' => 'Weihnachtsurlaub', 'von' => '2026-12-23', 'bis' => '2027-01-03', 'homepage' => '1', 'telefon' => '1',
        'v_von' => ['2026-12-23', '2027-01-02'], 'v_bis' => ['2026-12-31', '2027-01-03'], 'v_praxis' => [$a, $b]]);
    $e = $z->ereignis($id);
    check(count($z->ereignisse()) === 1 && count($e['vertretung']) === 2 && $e['vertretung'][1]['praxen'] === [$b], 'Vertretung je Zeitraum');
    problem(fn() => $z->ereignisSpeichern(['art' => 'urlaub', 'von' => '2026-12-23', 'bis' => '2026-12-20']), 422, 'Bis vor Von');
    problem(fn() => $z->ereignisSpeichern(['art' => 'urlaub', 'von' => '2026-12-23', 'v_von' => ['2026-12-01'], 'v_bis' => [''], 'v_praxis' => [$a]]), 422, 'Vertretung außerhalb');
    problem(fn() => $z->ereignisSpeichern(['art' => 'sonder', 'von' => '2026-12-31']), 422, 'Sonderöffnung ohne Zeiten');
    problem(fn() => $z->ereignisSpeichern(['art' => 'urlaub', 'von' => '31.02.2026']), 422, 'ungültiges Datum');
    problem(fn() => $z->praxisLoeschen($a), 409, 'Praxis in Benutzung gelöscht');
    $ansage = $z->ansage($e, [$a => $z->praxis($a), $b => $z->praxis($b)]);
    check(str_contains($ansage, 'vom 23. Dezember bis 3. Januar geschlossen') && str_contains($ansage, 'Vom 23. Dezember bis 31. Dezember vertritt uns Praxis Dr. med. Erika Beispiel, Telefon 0 1 2 3 4, 5 6 7 8 9')
        && str_contains($ansage, 'Hausarztpraxis Max Mustermann, Telefon 0 1 2 3 4, 9 8 7 6 5') && str_contains($ansage, '1 1 6, 1 1 7'), 'Ansage: ' . $ansage);
    check(Zeiten::satz($e) === 'Die Praxis ist vom 23.12. bis 03.01. geschlossen.', 'Satz Homepage');
    $s = $z->ereignisSpeichern(['art' => 'sonder', 'von' => '2026-12-30', 'zeiten' => '8-12']);
    check(str_contains($z->ansage($z->ereignis($s), []), 'nur von 8 Uhr bis 12 Uhr geöffnet') && !str_contains($z->ansage($z->ereignis($s), []), '116'), 'Ansage Sonderöffnung');
    // Feiertage: Land wählen, Rosenmontag dazu, Neujahr ab
    $z->feiertageSpeichern('BW', [], [], true, true);
    $z->feiertageSpeichern('BW', ['rosenmontag', 'neujahr', 'heiligabend'], ['rosenmontag', 'heiligabend'], true, false);
    $f = $z->feiertage();
    check($f['land'] === 'BW' && $f['an'] === ['rosenmontag', 'heiligabend'] && $f['aus'] === ['neujahr'] && !$f['homepage'], 'Feiertagsauswahl: ' . json_encode($f));
    $p = $z->paket('Praxis Beispiel');
    $ids = array_column($p['ereignisse'], 'id');
    check($p['format'] === 1 && preg_match('/^[0-9a-f]{32}$/', $p['stand']) && $p['woche']['telefon']['mo'] === ['08:00-11:30'] && $p['woche']['telefon']['di'] === [], 'Paket Woche');
    check(in_array('f-rosenmontag-2027', $ids, true) && !in_array('f-neujahr-2027', $ids, true), 'Feiertage im Paket nach Auswahl');
    check(!in_array('f-weihnachten-1-2026', $ids, true) && !in_array('f-heiligabend-2026', $ids, true), 'Feiertag im Urlaub doppelt');
    check(in_array('f-weihnachten-1-2028', $ids, true), 'Feiertage der nächsten Jahre fehlen');
    $u = $p['ereignisse'][array_search($id, $ids, true)];
    check($u['vertretung'][0]['praxen'][0]['name'] === 'Praxis Dr. med. Erika Beispiel' && !isset($u['vertretung'][0]['praxen'][0]['id']) && $u['homepage'] && $u['ansage'] !== '', 'Vertretung im Paket');
    check(strlen(json_encode($p, JSON_UNESCAPED_UNICODE)) < 60000, 'Paket zu groß');
    // Vergangenes fällt heraus
    Zeiten::$heute = '2027-02-01';
    check(!in_array($id, array_column($z->paket()['ereignisse'], 'id'), true), 'Vergangenes im Paket');
    Zeiten::$heute = null;
}

function test_seite_und_recht(): void {
    [$env, $c] = con_env();
    Zeiten::$heute = '2026-11-28';
    $mods = [];
    foreach ($env->modules->all() as $id => $m) { if ($id === 'kasse') $m['connections'][] = ['id' => 'zeiten', 'purpose' => 'Test', 'required' => false]; $mods[] = $m; }
    $env->modules = Kienzle\Core\Modules::fromArray($mods);
    $env->users = new Kienzle\Core\Users($env->db, $env->secrets, $env->modules);
    check(in_array('core:zeiten', (new Groups($env->db, $env->modules))->get('MFA')['rechte'], true), 'MFA ohne Recht Öffnungszeiten');
    // MFA (ohne Basis-Admin): sieht nur Öffnungszeiten unter Praxis
    [$cid] = $env->user('carla');
    $env->users->setRoles($cid, ['MFA']);
    $m = new Browser($env);
    $m->web = fn() => $m->defaultWeb(null, null, $c);
    $m->post('/anmelden', ['login' => 'carla', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $m->get('/konto');
    $p = $m->get('/verwaltung/zeiten');
    check($p->status === 200 && str_contains($p->body, 'Öffnungszeiten') && str_contains($p->body, 'href="/verwaltung/zeiten"') && !str_contains($p->body, 'href="/verwaltung/benutzer"'), 'MFA: Seite/Menü ' . $p->status);
    check($m->get('/verwaltung/praxis')->status === 403, 'MFA in der Verwaltung');
    $r = $m->post('/verwaltung/zeiten', ['aktion' => 'praxis', 'name' => 'Praxis Dr. med. Erika Beispiel', 'telefon' => '01234 56789']);
    check($r->status === 303, 'Praxis anlegen: ' . $r->status . substr($r->body, 0, 300));
    $q = req_v($env);
    check($q['action'] === 'zeiten' && $q['daten']['format'] === 1, 'Verteilung nicht angestoßen');
    $pid = (new Zeiten($env->db))->praxen()[0]['id'];
    $r = $m->post('/verwaltung/zeiten', ['aktion' => 'ereignis', 'art' => 'urlaub', 'von' => '2026-12-23', 'bis' => '2027-01-03', 'titel' => 'Weihnachtsurlaub', 'homepage' => '1', 'telefon' => '1',
        'v_von' => ['', ''], 'v_bis' => ['', ''], 'v_praxis' => [$pid, '']]);
    check($r->status === 303 && str_starts_with((string)$r->header('Location'), '/verwaltung/zeiten?e='), 'Schließung speichern');
    req_v($env);
    $p = $m->follow();
    check(str_contains($p->body, 'So hört es der Anrufer') && str_contains($p->body, 'In dieser Zeit vertritt uns Praxis Dr. med. Erika Beispiel') && str_contains($p->body, 'class="t u'), 'Seite nach dem Speichern');
    $f = $m->post('/verwaltung/zeiten', ['aktion' => 'ereignis', 'art' => 'urlaub', 'von' => '2026-12-23', 'bis' => '2026-12-01', 'titel' => '<b>x']);
    check($f->status === 422 && str_contains($f->body, 'liegt vor') && str_contains($f->body, '&lt;b&gt;x'), 'Fehler mit Eingabe zurück');
    $w = $m->post('/verwaltung/zeiten', ['aktion' => 'woche', 'sprech' => ['08:00-12:00', '08-12', '', '', '', '', ''], 'telefon' => array_fill(0, 7, '')]);
    check($w->status === 303 && (new Zeiten($env->db))->woche()['sprech']['di'] === '08:00-12:00', 'Woche über die Seite');
    req_v($env);
    $ft = $m->post('/verwaltung/zeiten', ['aktion' => 'feiertage', 'land' => 'BY', 'jahr' => '2027', 'f_telefon' => '1']);
    check($ft->status === 303 && (new Zeiten($env->db))->feiertage()['land'] === 'BY', 'Feiertage über die Seite');
    req_v($env);
    $fs = $m->get('/verwaltung/zeiten?ansicht=feiertage&jahr=2027')->body;
    check(str_contains($fs, 'Mariä Himmelfahrt') && str_contains($fs, 'nur in Teilen des Landes') && str_contains($fs, 'name="haken[]" value="neujahr" checked'), 'Feiertagsliste Bayern');
    check(str_contains($m->get('/verwaltung/zeiten?ansicht=praxen')->body, 'Praxis Dr. med. Erika Beispiel'), 'Praxenliste');
    check(str_contains($m->get('/verwaltung/zeiten?ansicht=woche')->body, 'value="08:00-12:00"'), 'Wochenansicht');
    // Recht einzeln entziehen
    $env->users->setBasisRechte($cid, []);
    check($m->get('/verwaltung/zeiten')->status === 403, 'ohne Recht erreichbar');
    // Admin: Matrix zeigt das Basis-Recht, Speichern übernimmt es
    [$admin] = admin_browser($env);
    $admin->web = fn() => $admin->defaultWeb(null, null, $c);
    $e = $admin->get('/verwaltung/benutzer/' . $cid)->body;
    check(str_contains($e, 'Praxisherz (Basis)') && str_contains($e, 'value="core:zeiten"'), 'Matrix ohne Basis-Recht');
    $admin->post('/verwaltung/benutzer/' . $cid, ['display' => 'Carla', 'active' => '1', 'rollen_gesendet' => '1', 'rolle' => ['MFA'], 'recht' => ['core:zeiten']]);
    check($env->users->basisRechte($cid) === ['zeiten'], 'Recht über die Matrix nicht gesetzt');
    check(str_contains($admin->get('/verwaltung/zeiten')->body, 'href="/verwaltung/benutzer"'), 'Admin: volles Menü');
    Zeiten::$heute = null;
}

run_tests();
