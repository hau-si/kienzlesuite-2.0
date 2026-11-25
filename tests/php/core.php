<?php
declare(strict_types=1);
// Bausteine der Basis: TOTP (RFC 6238), Verschlüsselung, Passwörter, Konten, Einrichtungslinks, Konfiguration, Modulverzeichnis.
require __DIR__ . '/lib.php';

use Kienzle\Core\{Config, Modules, Passwords, Secrets, Totp, Users};

function test_totp_rfc6238_testwerte(): void {
    // RFC 6238, Anhang B (8 Stellen; die Suite nutzt 6 Stellen mit demselben Verfahren).
    $vectors = [59 => ['94287082', '46119246', '90693936'], 1111111109 => ['07081804', '68084774', '25091201'], 1111111111 => ['14050471', '67062674', '99943326'],
        1234567890 => ['89005924', '91819424', '93441116'], 2000000000 => ['69279037', '90698825', '38618901'], 20000000000 => ['65353130', '77737706', '47863826']];
    foreach ($vectors as $t => [$sha1, $sha256, $sha512]) {
        $step = intdiv($t, 30);
        check(Totp::code('12345678901234567890', $step, 8, 'sha1', true) === $sha1, "SHA1 bei $t");
        check(Totp::code('12345678901234567890123456789012', $step, 8, 'sha256', true) === $sha256, "SHA256 bei $t");
        check(Totp::code(str_repeat('1234567890', 6) . '1234', $step, 8, 'sha512', true) === $sha512, "SHA512 bei $t");
    }
}

function test_totp_fenster_und_wiederverwendung(): void {
    $secret = Totp::generateSecret();
    $t = 1_800_000_000;
    $step = Totp::step($t);
    check(Totp::verify($secret, Totp::code($secret, $step), 0, $t) === $step, 'aktueller Code abgelehnt');
    check(Totp::verify($secret, Totp::code($secret, $step - 1), 0, $t) === $step - 1, 'Code von vor 30 s abgelehnt');
    check(Totp::verify($secret, Totp::code($secret, $step + 1), 0, $t) === $step + 1, 'Code der nächsten 30 s abgelehnt');
    check(Totp::verify($secret, Totp::code($secret, $step - 2), 0, $t) === null, 'Code von vor 60 s angenommen');
    check(Totp::verify($secret, Totp::code($secret, $step), $step, $t) === null, 'verbrauchter Code angenommen');
    check(Totp::verify($secret, ' ' . substr(Totp::code($secret, $step), 0, 3) . ' ' . substr(Totp::code($secret, $step), 3), 0, $t) === $step, 'Code mit Leerzeichen abgelehnt');
    foreach (['', '12345', '1234567', 'abcdef', '12345a'] as $bad) check(Totp::verify($secret, $bad, 0, $t) === null, "ungültige Eingabe „{$bad}“ angenommen");
}

function test_totp_base32_und_uri(): void {
    check(Totp::base32Encode('foobar') === 'MZXW6YTBOI', 'Base32 nach RFC 4648 falsch');
    check(Totp::base32Decode('mzxw 6ytb-oi') === 'foobar', 'Base32 tolerant lesen');
    $s = Totp::generateSecret();
    check(strlen($s) === 32 && Totp::base32Encode(Totp::base32Decode($s)) === $s, 'Geheimnis nicht 160 Bit');
    $uri = Totp::uri($s, 'anna', 'Kienzle-Suite Praxis Beispiel');
    check(str_starts_with($uri, 'otpauth://totp/Kienzle-Suite%20Praxis%20Beispiel:anna?secret=' . $s) && str_contains($uri, 'digits=6') && str_contains($uri, 'period=30'), 'otpauth-URI falsch: ' . $uri);
}

function test_verschluesselung_an_zweck_gebunden(): void {
    $s = Secrets::fromKey(random_bytes(32));
    $c = $s->encrypt('geheim', 'totp:1');
    check($s->decrypt($c, 'totp:1') === 'geheim', 'Entschlüsseln fehlgeschlagen');
    check($c !== $s->encrypt('geheim', 'totp:1'), 'gleicher Klartext ergibt gleichen Geheimtext');
    foreach ([fn() => $s->decrypt($c, 'totp:2'), fn() => Secrets::fromKey(random_bytes(32))->decrypt($c, 'totp:1'),
              fn() => $s->decrypt(substr($c, 0, -4) . 'AAAA', 'totp:1'), fn() => $s->decrypt('klartext', 'totp:1')] as $n => $bad) {
        try { $bad(); throw new LogicException("Fall $n entschlüsselt"); } catch (RuntimeException) {}
    }
    check($s->mac('x', 'a') !== $s->mac('x', 'b'), 'MAC nicht an Zweck gebunden');
    $dir = tmpdir();
    file_put_contents("$dir/k", Secrets::generateKey());
    check(Secrets::fromFile("$dir/k")->decrypt(Secrets::fromFile("$dir/k")->encrypt('a', 'b'), 'b') === 'a', 'Schlüsseldatei');
    file_put_contents("$dir/kaputt", "abc\n");
    try { Secrets::fromFile("$dir/kaputt"); throw new LogicException('kaputter Schlüssel angenommen'); } catch (RuntimeException) {}
}

function test_passwortregeln(): void {
    foreach (['kurz', 'aaaaaaaaaaaaa', 'Passwort123!', 'admin2024admin', '1234567890ab', 'Praxis-Kienzle'] as $bad) {
        problem(fn() => Passwords::check($bad), 422, "schwaches Passwort „{$bad}“ angenommen");
    }
    problem(fn() => Passwords::check('anna-Garten-Haus', 'anna'), 422, 'Passwort mit Anmeldenamen angenommen');
    Passwords::check('Wiesen-Blume-Fahrrad-7', 'anna');
    check(str_starts_with(Passwords::hash('x'), '$argon2id$'), 'kein Argon2id');
}

function test_anmeldenamen(): void {
    $env = new Env();
    foreach (['A', 'a', '1abc', 'anna bert', 'anna@x', str_repeat('a', 33), ''] as $bad) problem(fn() => $env->users->create($bad, '', false), 422, "Anmeldename „{$bad}“ angenommen");
    $env->users->create('Anna.Beispiel', '', false);
    check($env->users->byLogin('ANNA.beispiel') !== null, 'Anmeldename nicht unabhängig von Groß-/Kleinschreibung');
    problem(fn() => $env->users->create('anna.beispiel', '', false), 409, 'doppelter Anmeldename');
    problem(fn() => $env->users->create('carla', str_repeat('x', 81), false), 422, 'zu langer Name');
}

function test_wiederherstellungscodes(): void {
    $env = new Env();
    [$id] = $env->user('anna', true, otp: true);
    $codes = $env->users->newRecoveryCodes($id);
    check(count($codes) === 10 && count(array_unique($codes)) === 10 && preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $codes[0]), 'Codes falsch');
    check($env->users->useRecoveryCode($id, strtolower(str_replace('-', '', $codes[3]))), 'Code ohne Bindestriche/klein abgelehnt');
    check(!$env->users->useRecoveryCode($id, $codes[3]), 'Code zweimal');
    [$other] = $env->user('bert', true, otp: true);
    check(!$env->users->useRecoveryCode($other, $codes[4]), 'Code eines anderen Kontos angenommen');
    $env->users->newRecoveryCodes($id);
    check(!$env->users->useRecoveryCode($id, $codes[5]), 'alter Code nach Erneuerung gültig');
    $stored = implode(',', $env->db->query('SELECT code_hash FROM recovery_codes')->fetchAll(PDO::FETCH_COLUMN));
    check(!str_contains($stored, str_replace('-', '', $codes[5])), 'Code im Klartext gespeichert');
}

function test_einrichtungslink_ablauf_und_einmal(): void {
    $env = new Env();
    [$id] = $env->user('bert');
    $t = $env->users->setupToken($id);
    check($env->users->setupInfo($t) === ['user_id' => $id], 'Link ungültig');
    $t2 = $env->users->setupToken($id);
    check($env->users->setupInfo($t) === null && $env->users->setupInfo($t2) !== null, 'alter Link nach neuem noch gültig');
    $env->db->query('UPDATE setup_tokens SET expires_at=?', [time() - 1]);
    check($env->users->setupInfo($t2) === null, 'abgelaufener Link gültig');
    check($env->users->setupInfo('x') === null && $env->users->setupInfo(str_repeat('A', 43)) === null, 'erfundener Link gültig');
    $env->users->resetCredentials($id);
    $b = $env->users->find($id);
    check($b['password_hash'] === '' && $b['totp_secret'] === '', 'Zurücksetzen unvollständig');
}

function test_rechte_nur_installierter_module(): void {
    $env = new Env();
    [$id] = $env->user('bert');
    foreach (['kasse:paid', 'kasse:erfunden', 'entfernt:@admin'] as $perm) $env->db->query('INSERT INTO user_overrides(user_id,perm,allow) VALUES(?,?,1)', [$id, $perm]);
    check($env->users->access($id) === ['kasse' => ['admin' => false, 'rights' => ['paid']]], 'Rechte entfernter Module oder unbekannte Rechte weitergegeben');
    check(!$env->users->needsOtp($env->users->find($id)), 'Admin-Rolle eines entfernten Moduls verlangt OTP');
}

function test_rollen_folgen_der_gruppe_einzelrechte_bleiben(): void {
    $env = new Env();
    [$id] = $env->user('rita');
    $g = new Kienzle\Core\Groups($env->db, $env->modules);
    $env->users->setRoles($id, ['MFA']);
    $mfa = $env->users->access($id);
    check(isset($mfa['kasse']) && in_array('paid', $mfa['kasse']['rights'], true) && !$mfa['kasse']['admin'], 'Rolle MFA gibt kein Kasse-Recht');
    // Einzeländerung: Recht „paid“ entziehen, nur das wird als Abweichung gespeichert
    $env->users->setAccess($id, 'kasse', false, array_values(array_diff($mfa['kasse']['rights'], ['paid'])));
    check($env->users->overrides($id) === ['kasse:paid' => false], 'Abweichung nicht einzeln gespeichert: ' . json_encode($env->users->overrides($id)));
    // Gruppe ändert sich: Mitglied bekommt neues Recht, die Einzeländerung bleibt
    $rechte = array_values(array_unique(array_merge($g->get('MFA')['rechte'], ['kasse:catalog'])));
    $g->set('MFA', $g->get('MFA')['admin'], $rechte);
    $after = $env->users->access($id);
    check(!in_array('paid', $after['kasse']['rights'] ?? [], true), 'Einzelentzug nach Gruppenänderung verloren');
    check(in_array('catalog', $after['kasse']['rights'] ?? [], true), 'Gruppenänderung erreicht Mitglied nicht');
    check($env->users->members('MFA') === ['rita'], 'Mitglieder falsch');
    $env->users->resetOverrides($id);
    check(in_array('paid', $env->users->access($id)['kasse']['rights'], true), 'Zurück auf Rolle ohne Wirkung');
    $env->users->setRoles($id, []);
    check($env->users->access($id) === [], 'ohne Rolle noch Rechte');
    try { $env->users->setRoles($id, ['Erfunden']); throw new LogicException('unbekannte Rolle angenommen'); } catch (Kienzle\Core\Problem) {}
}

function test_migration_alte_rechte_werden_einzelrechte(): void {
    $env = new Env();
    [$id] = $env->user('alt');
    $env->db->query("INSERT INTO user_modules(user_id,module,admin,rights) VALUES(?,?,1,'[\"paid\"]')", [$id, 'kasse']);
    $env->db->query('PRAGMA user_version=2');
    $env->db->migrate();
    check($env->users->roles($id) === [], 'Altbenutzer hat plötzlich eine Rolle');
    check($env->users->access($id) === ['kasse' => ['admin' => true, 'rights' => ['paid']]], 'Alte Rechte nicht übernommen: ' . json_encode($env->users->access($id)));
}

function test_migration_modul_sumup_wird_kasse(): void {
    $env = new Env();
    [$id] = $env->user('alt');
    $env->db->query('INSERT INTO user_overrides(user_id,perm,allow) VALUES(?,?,1)', [$id, 'sumup:paid']);
    $env->db->query('INSERT INTO user_overrides(user_id,perm,allow) VALUES(?,?,0)', [$id, 'sumup:storno']);
    $env->db->store('erstadmin:sumup', '1');
    $env->db->store('benutzer.gruppen', json_encode(['MFA' => ['admin' => ['sumup'], 'rechte' => ['sumup:kassieren', 'andere:x']]]));
    $env->db->query('PRAGMA user_version=3');
    $env->db->migrate();
    $rows = array_column($env->db->query('SELECT perm,allow FROM user_overrides WHERE user_id=?', [$id])->fetchAll(), 'allow', 'perm');
    check(($rows['kasse:paid'] ?? null) == 1 && ($rows['kasse:storno'] ?? null) == 0 && ($rows['sumup:paid'] ?? null) == 1, 'Einzelrechte nicht kopiert: ' . json_encode($rows));
    check($env->db->setting('erstadmin:kasse') === '1', 'Erstadmin nicht kopiert');
    $g = json_decode((string)$env->db->setting('benutzer.gruppen'), true);
    check($g['MFA']['admin'] === ['sumup', 'kasse'] && $g['MFA']['rechte'] === ['sumup:kassieren', 'andere:x', 'kasse:kassieren'], 'Gruppe nicht kopiert: ' . json_encode($g));
    $env->db->query('PRAGMA user_version=3');
    $env->db->migrate();   // zweiter Lauf ändert nichts
    check(json_decode((string)$env->db->setting('benutzer.gruppen'), true) === $g, 'zweiter Lauf verändert die Gruppen');
}

function test_konfiguration(): void {
    $c = Config::fromArray(['hostnames' => ['kienzle.example.org', '192.0.2.5', '2001:db8::5']]);
    foreach (['kienzle.example.org', 'KIENZLE.example.org', 'kienzle.example.org:443', '192.0.2.5', '[2001:db8::5]', '[2001:db8::5]:443'] as $ok) check($c->ownHost($ok), "eigener Name „{$ok}“ abgelehnt");
    foreach (['evil.example', 'kienzle.example.org:8443', 'kienzle.example.org.evil.example', '', '192.0.2.6', 'kienzle.example.org/x'] as $bad) check(!$c->ownHost($bad), "fremder Name „{$bad}“ angenommen");
    foreach ([[], ['hostnames' => []], ['hostnames' => ['bad host']], ['hostnames' => ['-x.example']]] as $n => $bad) {
        try { Config::fromArray($bad); throw new LogicException("Konfiguration $n angenommen"); } catch (RuntimeException) {}
    }
}

function test_modulverzeichnis(): void {
    $dir = tmpdir();
    check((new Modules("$dir/fehlt.json"))->all() === [], 'fehlendes Verzeichnis nicht leer');
    file_put_contents("$dir/kaputt.json", '{"modules":[{"module":{"id":"../x"}}]}');
    try { new Modules("$dir/kaputt.json"); throw new LogicException('beschädigtes Verzeichnis angenommen'); } catch (RuntimeException) {}
    $m = sample_modules();
    check($m->forUri('/kasse') === 'kasse' && $m->forUri('/kasse/x?y') === 'kasse' && $m->forUri('/kassenbuch') === null && $m->forUri('/beispiel/') === 'beispiel', 'Pfadzuordnung falsch');
}

run_tests();
