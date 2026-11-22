<?php
declare(strict_types=1);
// Passkeys (WebAuthn): Registrieren, Anmelden, Bestätigen und die Fälle, die abgewiesen werden müssen.
require __DIR__ . '/lib.php';

use Kienzle\Core\{Cbor, CborBytes, Passkeys};

function setup_key(string $alg = 'es256'): array {
    $env = new Env();
    [$id] = $env->user('anna', true, otp: true);
    $user = $env->users->find($id);
    $device = new SoftAuthenticator($alg);
    $options = $env->passkeys->registrationOptions($user, $env->passkeys->handle($user));
    $env->passkeys->finishRegistration($user, $device->create($options), 'Testgerät');
    return [$env, $id, $device];
}

function test_registrieren_und_anmelden_alle_algorithmen(): void {
    foreach (['es256', 'ed25519', 'rs256'] as $alg) {
        [$env, $id, $device] = setup_key($alg);
        check($env->passkeys->count($id) === 1, "$alg: nicht gespeichert");
        check($env->passkeys->list($id)[0]['synced'] === true, "$alg: Synchronisiert-Kennzeichen fehlt");
        $uid = $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null)), null);
        check($uid === $id, "$alg: falscher Benutzer");
    }
}

function test_optionen(): void {
    $env = new Env();
    [$id] = $env->user('anna', true);
    $user = $env->users->find($id);
    $o = $env->passkeys->registrationOptions($user, $env->passkeys->handle($user));
    check($o['authenticatorSelection']['userVerification'] === 'required' && $o['authenticatorSelection']['residentKey'] === 'required', 'Nutzerprüfung nicht verlangt');
    check($o['rp']['id'] === 'kienzle.example.org' && $o['attestation'] === 'none', 'RP falsch');
    check($o['user']['id'] === $env->passkeys->handle($env->users->find($id)) && strlen($o['user']['id']) === 22, 'Benutzer-Schlüssel nicht fest');
    check(Passkeys::rpIdFrom($env->config) === 'kienzle.example.org', 'RP-ID nicht aus der Konfiguration');
    check(Passkeys::rpIdFrom(Kienzle\Core\Config::fromArray(['hostnames' => ['192.0.2.5']])) === null, 'IP-Adresse als RP-ID');
}

function test_abfrage_nur_einmal_und_zweckgebunden(): void {
    [$env, $id, $device] = setup_key();
    $o = $env->passkeys->assertionOptions(null);
    $answer = $device->get($o);
    $env->passkeys->finishAssertion($answer, null);
    problem(fn() => $env->passkeys->finishAssertion($answer, null), 401, 'Antwort zweimal angenommen');
    // Abfrage für „Bestätigen“ darf nicht zum Anmelden taugen und umgekehrt.
    $c = $env->passkeys->assertionOptions($id);
    problem(fn() => $env->passkeys->finishAssertion($device->get($c), null), 401, 'Bestätigungs-Abfrage zum Anmelden genutzt');
    $l = $env->passkeys->assertionOptions(null);
    problem(fn() => $env->passkeys->finishAssertion($device->get($l), $id), 401, 'Anmelde-Abfrage zum Bestätigen genutzt');
    problem(fn() => $env->passkeys->finishAssertion($device->get(['challenge' => SoftAuthenticator::b64(random_bytes(32))]), null), 401, 'erfundene Abfrage angenommen');
}

function test_abgelaufene_abfrage(): void {
    [$env, , $device] = setup_key();
    $o = $env->passkeys->assertionOptions(null);
    $env->db->query('UPDATE passkey_challenges SET expires_at=?', [time() - 1]);
    problem(fn() => $env->passkeys->finishAssertion($device->get($o), null), 401, 'abgelaufene Abfrage angenommen');
}

function test_fremde_herkunft_und_rp(): void {
    [$env, , $device] = setup_key();
    problem(fn() => $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null), 'https://kienzle.example.org.evil.example'), null), 401, 'fremde Herkunft');
    problem(fn() => $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null), 'http://kienzle.example.org'), null), 401, 'http statt https');
    $other = new SoftAuthenticator('es256', 'evil.example');
    $other->credentialId = $device->credentialId;
    problem(fn() => $env->passkeys->finishAssertion($other->get($env->passkeys->assertionOptions(null)), null), 401, 'andere RP-ID');
}

function test_ohne_nutzerpruefung(): void {
    $env = new Env();
    [$id] = $env->user('anna', true);
    $user = $env->users->find($id);
    $device = new SoftAuthenticator('es256', uv: false);
    $msg = problem(fn() => $env->passkeys->finishRegistration($user, $device->create($env->passkeys->registrationOptions($user, $env->passkeys->handle($user))), 'x'), 401, 'ohne UV registriert');
    check(str_contains($msg, 'nicht geprüft'), 'Meldung ohne Hinweis auf Nutzerprüfung');
}

function test_falsche_signatur_und_fremder_schluessel(): void {
    [$env, , $device] = setup_key();
    $answer = $device->get($env->passkeys->assertionOptions(null));
    $sig = base64_decode(strtr($answer['response']['signature'], '-_', '+/'));
    $sig[10] = chr(ord($sig[10]) ^ 1);
    $answer['response']['signature'] = SoftAuthenticator::b64($sig);
    problem(fn() => $env->passkeys->finishAssertion($answer, null), 401, 'veränderte Signatur angenommen');
    // Gleiche Credential-ID, aber anderer Schlüssel (Angreifer kennt nur die ID).
    $fake = new SoftAuthenticator();
    $fake->credentialId = $device->credentialId; $fake->handle = $device->handle; $fake->count = 50;
    problem(fn() => $env->passkeys->finishAssertion($fake->get($env->passkeys->assertionOptions(null)), null), 401, 'fremder Schlüssel angenommen');
    // Unbekannte Credential-ID.
    $unknown = new SoftAuthenticator();
    problem(fn() => $env->passkeys->finishAssertion($unknown->get($env->passkeys->assertionOptions(null)), null), 401, 'unbekannter Passkey angenommen');
}

function test_falscher_benutzer_schluessel(): void {
    [$env, , $device] = setup_key();
    $device->handle = SoftAuthenticator::b64(random_bytes(16));
    problem(fn() => $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null)), null), 400, 'fremder userHandle angenommen');
}

function test_zaehler_kopierter_schluessel(): void {
    [$env, , $device] = setup_key();
    $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null), '', 5), null);
    problem(fn() => $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null), '', 5), null), 401, 'gleicher Zählerstand angenommen');
    problem(fn() => $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null), '', 3), null), 401, 'kleinerer Zählerstand angenommen');
    $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null), '', 6), null);
}

function test_bestaetigen_nur_eigenes_konto(): void {
    [$env, $id, $device] = setup_key();
    [$bob] = $env->user('bert', true, otp: true);
    check($env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions($id)), $id) === $id, 'Bestätigung fehlgeschlagen');
    problem(fn() => $env->passkeys->assertionOptions($bob), 409, 'Bestätigung ohne eigenen Passkey angeboten');
    $user = $env->users->find($bob);
    $bobDevice = new SoftAuthenticator();
    $env->passkeys->finishRegistration($user, $bobDevice->create($env->passkeys->registrationOptions($user, $env->passkeys->handle($user))), 'Bert');
    problem(fn() => $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions($bob)), $bob), 401, 'Annas Passkey bestätigt für Bert');
}

function test_inaktives_konto(): void {
    [$env, $id, $device] = setup_key();
    $env->user('bert', true);
    $env->users->update($id, 'Anna', true, false);
    problem(fn() => $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null)), null), 401, 'inaktives Konto angemeldet');
}

function test_doppelt_und_hoechstzahl(): void {
    [$env, $id, $device] = setup_key();
    $user = $env->users->find($id);
    $o = $env->passkeys->registrationOptions($user, $env->passkeys->handle($user));
    check(count($o['excludeCredentials']) === 1, 'vorhandener Passkey nicht ausgeschlossen');
    problem(fn() => $env->passkeys->finishRegistration($user, $device->create($o), 'nochmal'), 409, 'gleicher Passkey zweimal');
    for ($i = 1; $i < Passkeys::MAX_PER_USER; $i++) {
        $d = new SoftAuthenticator();
        $env->passkeys->finishRegistration($user, $d->create($env->passkeys->registrationOptions($user, $env->passkeys->handle($user))), "Gerät $i");
    }
    problem(fn() => $env->passkeys->registrationOptions($user, $env->passkeys->handle($user)), 409, 'mehr als 10 Passkeys');
}

function test_registrierung_fremdes_konto(): void {
    $env = new Env();
    [$a] = $env->user('anna', true); [$b] = $env->user('bert', true);
    $anna = $env->users->find($a); $bert = $env->users->find($b);
    $device = new SoftAuthenticator();
    $answer = $device->create($env->passkeys->registrationOptions($anna, $env->passkeys->handle($anna)));
    problem(fn() => $env->passkeys->finishRegistration($bert, $answer, 'x'), 401, 'Abfrage für Anna bei Bert eingelöst');
}

function test_entfernen(): void {
    [$env, $id, $device] = setup_key();
    check($env->passkeys->remove($id, SoftAuthenticator::b64($device->credentialId)), 'nicht entfernt');
    problem(fn() => $env->passkeys->finishAssertion($device->get($env->passkeys->assertionOptions(null)), null), 401, 'entfernter Passkey angenommen');
}

function test_kaputte_eingaben(): void {
    [$env, $id, $device] = setup_key();
    $user = $env->users->find($id);
    foreach ([[], ['response' => 'x'], ['response' => ['clientDataJSON' => '!!!']], ['response' => ['clientDataJSON' => SoftAuthenticator::b64('{"type":1}')]],
              ['response' => ['clientDataJSON' => SoftAuthenticator::b64(str_repeat('a', 5000))]]] as $n => $bad) {
        try { $env->passkeys->finishAssertion($bad, null); throw new RuntimeException("kaputte Eingabe $n angenommen"); }
        catch (Kienzle\Core\Problem) {}
        try { $env->passkeys->finishRegistration($user, $bad, 'x'); throw new RuntimeException("kaputte Registrierung $n angenommen"); }
        catch (Kienzle\Core\Problem) {}
    }
}

function test_cbor(): void {
    check(Cbor::decodeAll(SoftAuthenticator::cbor([1 => 2, -1 => 'a', 'k' => [true, false, null]])) === [1 => 2, -1 => 'a', 'k' => [true, false, null]], 'CBOR einfach');
    check(Cbor::decodeAll("\x19\x01\x00") === 256 && Cbor::decodeAll("\x38\x63") === -100, 'CBOR Zahlen');
    check(Cbor::decodeAll("\x43abc") instanceof CborBytes, 'CBOR Bytes');
    foreach (["\x5f", "\xa2\x01\x01\x01\x02", "\xc0\x01", "\xf9\x00\x00", "\x43ab", "\x01\x02", str_repeat("\x81", 20) . "\x01", "\x63\xff\xff\xff"] as $n => $bad) {
        try { Cbor::decodeAll($bad); throw new RuntimeException("CBOR-Fehler $n angenommen"); } catch (UnexpectedValueException) {}
    }
}

run_tests();
