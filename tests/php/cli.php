<?php
declare(strict_types=1);
// Konsole kienzle-admin: erster Admin, Zurücksetzen, Entsperren, Liste, falsche Aufrufe.
require __DIR__ . '/lib.php';

function cli(Env $env, string ...$args): array {
    file_put_contents($env->dir . '/core.json', json_encode(['hostnames' => ['kienzle.example.org'], 'data_dir' => $env->dir, 'secret_key' => $env->dir . '/secret.key',
        'modules' => $env->dir . '/modules.json']));
    $cmd = 'KIENZLE_ADMIN_TEST=1 KIENZLE_CORE_CONFIG=' . escapeshellarg($env->dir . '/core.json') . ' php ' . escapeshellarg(dirname(__DIR__, 2) . '/core/bin/kienzle-admin')
        . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    exec($cmd, $out, $code);
    return [$code, implode("\n", $out)];
}

function test_erster_admin_link(): void {
    $env = new Env();
    [$code, $out] = cli($env, 'einrichtungslink');
    check($code === 0 && preg_match('#https://kienzle\.example\.org/einrichten\?t=([A-Za-z0-9_-]{43})#', $out, $m) === 1, 'kein Link: ' . $out);
    check($env->users->setupInfo($m[1]) === ['user_id' => null], 'Link legt keinen Admin an');
    $env->user('anna', true, otp: true);
    [$code, $out] = cli($env, 'einrichtungslink');
    check($code === 1 && str_contains($out, 'schon einen Admin'), 'zweiter Erst-Admin-Link erzeugt');
}

function test_zuruecksetzen(): void {
    $env = new Env();
    [$id] = $env->user('anna', true, otp: true);
    $env->users->newRecoveryCodes($id);
    $user = $env->users->find($id);
    $device = new SoftAuthenticator();
    $env->passkeys->finishRegistration($user, $device->create($env->passkeys->registrationOptions($user, $env->passkeys->handle($user))), 'x');
    $env->auth->login('anna', 'Wiesen-Blume-Fahrrad-7', false, '');
    [$code, $out] = cli($env, 'zuruecksetzen', 'anna');
    check($code === 0 && str_contains($out, '/einrichten?t='), 'Zurücksetzen fehlgeschlagen: ' . $out);
    $u = $env->users->find($id);
    check($u['password_hash'] === '' && $u['totp_secret'] === '' && $env->users->recoveryLeft($id) === 0 && $env->passkeys->count($id) === 0, 'Zugangsdaten nicht gelöscht');
    check((int)$env->db->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === 0, 'Sitzungen nicht beendet');
    check((int)$u['admin'] === 1, 'Admin-Rolle verloren');
    check(str_contains(implode("\n", $env->audit->recent()), 'konsole zurueckgesetzt anna'), 'nicht protokolliert');
    [$code] = cli($env, 'zuruecksetzen', 'niemand');
    check($code === 1, 'unbekannter Benutzer nicht gemeldet');
}

function test_entsperren_und_liste(): void {
    $env = new Env();
    $env->user('anna', true, otp: true);
    for ($i = 0; $i < 5; $i++) { try { $env->auth->login('anna', 'falsch-falsch-falsch', false, ''); } catch (Kienzle\Core\Problem) {} }
    problem(fn() => $env->auth->login('anna', 'Wiesen-Blume-Fahrrad-7', false, ''), 429, 'keine Sperre');
    [$code] = cli($env, 'entsperren');
    check($code === 0, 'Entsperren fehlgeschlagen');
    $env->auth->login('anna', 'Wiesen-Blume-Fahrrad-7', false, '');
    [$code, $out] = cli($env, 'benutzer');
    check($code === 0 && str_contains($out, 'anna') && str_contains($out, 'Basis-Admin') && !str_contains($out, 'argon'), 'Liste falsch: ' . $out);
}

function test_falsche_aufrufe(): void {
    $env = new Env();
    foreach ([[], ['zuruecksetzen'], ['loeschen', 'anna'], ['benutzer', 'x'], ['einrichtungslink', 'a', 'b']] as $args) {
        [$code] = cli($env, ...$args);
        check($code === 2, 'falscher Aufruf ' . json_encode($args) . ' nicht abgewiesen');
    }
}

run_tests();
