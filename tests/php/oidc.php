<?php
declare(strict_types=1);
// Basis als OpenID-Connect-Anbieter (M9): Ablauf mit Code und PKCE, ID-Token, Userinfo, Abmelden, Verwaltung.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\Oidc;

const CB = 'https://dienst.example.org/cb';

function oidc_env(): array {
    $env = new Env();
    $o = new Oidc($env->db, $env->users, $env->secrets, $env->config);
    return [$env, $o];
}

function web_for(Browser $b, Oidc $o): void { $b->web = fn() => $b->defaultWeb(oidc: $o); }

function query_of(string $url): array { parse_str((string)parse_url($url, PHP_URL_QUERY), $q); return $q; }

function verify_jwt(Oidc $o, string $jwt): array {
    [$h, $p, $s] = explode('.', $jwt);
    $jwk = $o->jwks()['keys'][0];
    // Öffentlichen Schlüssel aus x/y bauen (SubjectPublicKeyInfo für P-256).
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04"
        . base64_decode(strtr($jwk['x'], '-_', '+/') . '=') . base64_decode(strtr($jwk['y'], '-_', '+/') . '=');
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $sig = base64_decode(strtr($s, '-_', '+/'));
    check(strlen($sig) === 64, 'Signatur nicht 64 Byte');
    check(openssl_verify("$h.$p", Oidc::rawToDer($sig), openssl_pkey_get_public($pem), OPENSSL_ALGO_SHA256) === 1, 'ID-Token-Signatur ungültig');
    check(json_decode(base64_decode(strtr($h, '-_', '+/')), true) === ['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $jwk['kid']], 'Kopf des ID-Tokens');
    return json_decode(base64_decode(strtr($p, '-_', '+/')), true);
}

/** Anmeldung mit Rücksprung: liefert die Weiterleitung an den Dienst. */
function login_via(Browser $b, string $authorize, string $login, string $secret): string {
    $r = $b->get($authorize);
    check($r->status === 303 && preg_match('#^/anmelden\?weiter=(/oidc/fortsetzen/[0-9a-f]{32})$#', (string)$r->header('Location'), $m) === 1, 'keine Anmeldung verlangt: ' . $r->header('Location'));
    $b->post('/anmelden?weiter=' . rawurlencode($m[1]), ['login' => $login, 'password' => 'Wiesen-Blume-Fahrrad-7']);
    if ($secret !== '') $b->post('/anmelden/code', ['code' => code_now($secret)]);
    check($b->last->header('Location') === $m[1], 'Rücksprung nach der Anmeldung fehlt: ' . $b->last->header('Location'));
    $r = $b->follow();
    check($r->status === 303, 'keine Weiterleitung an den Dienst');
    return (string)$r->header('Location');
}

function test_ablauf(): void {
    [$env, $o] = oidc_env();
    [$id, $secret] = $o->createClient('Telefon', CB, 'https://dienst.example.org/', false, false, '');
    [, $otp] = $env->user('anna', true, otp: true);
    $b = new Browser($env); web_for($b, $o);
    check($b->get('/.well-known/openid-configuration')->status === 200, 'Discovery');
    $disc = json_decode($b->last->body, true);
    check($disc['issuer'] === 'https://kienzle.example.org' && $disc['token_endpoint'] === 'https://kienzle.example.org/oidc/token', 'Discovery-Inhalt');
    $back = login_via($b, '/oidc/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $id, 'redirect_uri' => CB, 'scope' => 'openid profile groups',
        'state' => 'zustand-1', 'nonce' => 'n-0815']), 'anna', $otp);
    check(str_starts_with($back, CB . '?code='), 'Code fehlt: ' . $back);
    $q = query_of($back);
    check($q['state'] === 'zustand-1', 'state nicht zurückgegeben');
    // Token-Abfrage des Dienstes: ohne Cookie, ohne Origin, mit Basic-Anmeldung.
    $s = new Browser($env); web_for($s, $o);
    $t = $s->req('POST', '/oidc/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => CB],
        ['origin' => null, 'authorization' => 'Basic ' . base64_encode("$id:$secret")]);
    check($t->status === 200 && $t->header('Cache-Control') === 'no-store', 'Token-Abfrage: ' . $t->status . ' ' . $t->body);
    $tok = json_decode($t->body, true);
    $claims = verify_jwt($o, $tok['id_token']);
    check($claims['iss'] === 'https://kienzle.example.org' && $claims['aud'] === $id && $claims['nonce'] === 'n-0815' && $claims['preferred_username'] === 'anna', 'Inhalt des ID-Tokens');
    check(in_array('kienzle-admin', $claims['groups'], true) && $claims['exp'] - $claims['iat'] === Oidc::ID_TOKEN_SECONDS, 'Gruppen oder Laufzeit');
    $again = $s->req('POST', '/oidc/token', ['grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => CB], ['origin' => null, 'authorization' => 'Basic ' . base64_encode("$id:$secret")]);
    check($again->status === 400 && json_decode($again->body, true)['error'] === 'invalid_grant', 'Code zweimal einlösbar');
    $u = $s->req('GET', '/oidc/userinfo', [], ['authorization' => 'Bearer ' . $tok['access_token']]);
    check($u->status === 200 && json_decode($u->body, true)['sub'] === $claims['sub'], 'Userinfo');
    check($s->req('GET', '/oidc/userinfo', [], ['authorization' => 'Bearer ' . str_repeat('x', 43)])->status === 401, 'falsche Marke angenommen');
    $env->db->query('UPDATE users SET active=0 WHERE id=?', [(int)substr($claims['sub'], 1)]);
    check($s->req('GET', '/oidc/userinfo', [], ['authorization' => 'Bearer ' . $tok['access_token']])->status === 401, 'gesperrter Benutzer bekommt Userinfo');
    check(str_contains(implode("\n", $env->audit->recent()), 'oidc-anmeldung'), 'Anmeldung nicht protokolliert');
}

function test_fehler(): void {
    [$env, $o] = oidc_env();
    [$id, $secret] = $o->createClient('Telefon', CB, '', false, false, '');
    [$b] = admin_browser($env); web_for($b, $o);
    $auth = fn(array $q) => $b->get('/oidc/authorize?' . http_build_query($q + ['response_type' => 'code', 'client_id' => $id, 'redirect_uri' => CB, 'scope' => 'openid']));
    check($auth(['client_id' => 'unbekannt'])->status === 400, 'unbekannter Client');
    check($auth(['redirect_uri' => 'https://boese.example.org/cb'])->status === 400, 'fremde Weiterleitung angenommen');
    check($auth(['redirect_uri' => CB . '/x'])->status === 400, 'Weiterleitung nur nach Vorsilbe geprüft');
    check(query_of((string)$auth(['scope' => 'profile'])->header('Location'))['error'] === 'invalid_scope', 'ohne openid');
    check(query_of((string)$auth(['response_type' => 'token'])->header('Location'))['error'] === 'unsupported_response_type', 'Implicit Flow angenommen');
    check(query_of((string)$auth(['code_challenge' => 'x', 'code_challenge_method' => 'plain'])->header('Location'))['error'] === 'invalid_request', 'PKCE plain angenommen');
    $code = query_of((string)$auth([])->header('Location'))['code'];
    $tok = fn(array $post, string $basic) => $b->req('POST', '/oidc/token', $post + ['grant_type' => 'authorization_code', 'redirect_uri' => CB], ['origin' => null, 'authorization' => $basic]);
    check($tok(['code' => $code], 'Basic ' . base64_encode("$id:falsch"))->status === 401, 'falsches Geheimnis');
    check($tok(['code' => $code, 'redirect_uri' => 'https://dienst.example.org/anders'], 'Basic ' . base64_encode("$id:$secret"))->status === 400, 'andere redirect_uri');
    check($tok(['code' => $code], 'Basic ' . base64_encode("$id:$secret"))->status === 400, 'Code nach Fehlversuch noch gültig');
    // Öffentlicher Client: PKCE Pflicht und geprüft.
    [$pub] = $o->createClient('Browser-App', CB, '', true, false, '');
    $verifier = Oidc::b64(random_bytes(40));
    $challenge = Oidc::b64(hash('sha256', $verifier, true));
    check(query_of((string)$auth(['client_id' => $pub])->header('Location'))['error'] === 'invalid_request', 'öffentlich ohne PKCE');
    $c1 = query_of((string)$auth(['client_id' => $pub, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'])->header('Location'))['code'];
    check($tok(['code' => $c1, 'client_id' => $pub, 'code_verifier' => Oidc::b64(random_bytes(40))], '')->status === 400, 'falscher Verifier');
    $c2 = query_of((string)$auth(['client_id' => $pub, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256'])->header('Location'))['code'];
    check($tok(['code' => $c2, 'client_id' => $pub, 'code_verifier' => $verifier], '')->status === 200, 'PKCE mit richtigem Verifier');
    // Abgelaufener Code
    $c3 = query_of((string)$auth([])->header('Location'))['code'];
    $env->db->query('UPDATE oidc_codes SET expires_at=?', [time() - 1]);
    check($tok(['code' => $c3], 'Basic ' . base64_encode("$id:$secret"))->status === 400, 'abgelaufener Code');
}

function test_zugang_und_abmelden(): void {
    [$env, $o] = oidc_env();
    [$nur] = $o->createClient('NAS', CB, 'https://dienst.example.org/', false, false, 'kasse');
    [$admins] = $o->createClient('Router', CB, '', false, true, '');
    $env->user('bert');
    $b = new Browser($env); web_for($b, $o);
    $back = login_via($b, '/oidc/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $nur, 'redirect_uri' => CB, 'scope' => 'openid']), 'bert', '');
    check(query_of($back)['error'] === 'access_denied', 'ohne Modulzugang angemeldet');
    $r = $b->get('/oidc/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $admins, 'redirect_uri' => CB, 'scope' => 'openid']));
    check(query_of((string)$r->header('Location'))['error'] === 'access_denied', 'Mitarbeiter bei „nur Admins“');
    $env->users->setAccess((int)$env->users->byLogin('bert')['id'], 'kasse', false, ['paid']);
    $r = $b->get('/oidc/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $nur, 'redirect_uri' => CB, 'scope' => 'openid groups']));
    check(isset(query_of((string)$r->header('Location'))['code']), 'mit Modulzugang keine Anmeldung');
    // Abmelden: nur an eingetragene Adressen zurück.
    $p = $b->get('/oidc/abmelden?' . http_build_query(['client_id' => $nur, 'post_logout_redirect_uri' => 'https://dienst.example.org/', 'state' => 's1']));
    check($p->status === 200 && str_contains($p->body, 'value="https://dienst.example.org/"'), 'Abmeldeseite');
    $r = $b->post('/oidc/abmelden', ['ziel' => 'https://dienst.example.org/', 'state' => 's1']);
    check($r->header('Location') === 'https://dienst.example.org/?state=s1', 'Rücksprung nach Abmelden: ' . $r->header('Location'));
    check($b->get('/')->status === 303, 'nach dem Abmelden noch angemeldet');
    $c = new Browser($env); web_for($c, $o);
    check($c->get('/oidc/abmelden?' . http_build_query(['client_id' => $nur, 'post_logout_redirect_uri' => 'https://boese.example.org/']))->header('Location') === '/anmelden', 'fremde Abmeldeadresse');
}

function test_verwaltung(): void {
    [$env, $o] = oidc_env();
    [$b] = admin_browser($env); web_for($b, $o);
    check($b->get('/verwaltung/sso')->status === 200, 'Seite Dienste');
    $r = $b->post('/verwaltung/sso', ['aktion' => 'anlegen', 'name' => 'Telefon <b>', 'redirect_uris' => CB . "\nhttp://localhost:8080/cb", 'logout_uris' => '', 'modul' => '']);
    check($r->status === 200 && str_contains($r->body, 'Client-Geheimnis') && str_contains($r->body, 'Telefon &lt;b&gt;') && $r->header('Cache-Control') === 'no-store', 'Anlegen zeigt Geheimnis');
    $c = $o->clients()[0];
    check($c['redirect_uris'] === [CB, 'http://localhost:8080/cb'], 'Adressen');
    $seite = $b->get('/verwaltung/sso')->body;
    check(str_contains($seite, 'class="mod-zeile dienst-zeile"') && str_contains($seite, 'href="/verwaltung/sso?neu=1#neu"') && str_contains($seite, 'Dienst hinzufügen</a>') && !str_contains($seite, 'name="redirect_uris"'), 'Dienste als Liste, Formular nur über den Knopf oben');
    check(str_contains($b->get('/verwaltung/sso?neu=1')->body, 'name="redirect_uris"'), 'Formular „Dienst hinzufügen“');
    foreach (['http://dienst.example.org/cb', 'https://dienst.example.org/cb#x', 'javascript:alert(1)', ''] as $bad) {
        check($b->post('/verwaltung/sso', ['aktion' => 'anlegen', 'name' => 'X', 'redirect_uris' => $bad])->status === 422, 'Adresse angenommen: ' . $bad);
    }
    check(!str_contains((string)file_get_contents($env->audit->path()), 'Geheimnis'), 'Geheimnis im Protokoll');
    $b->post('/verwaltung/sso', ['aktion' => 'loeschen', 'id' => $c['id']]);
    check($o->clients() === [], 'Entfernen');
    $env->user('bert');
    $m = new Browser($env); web_for($m, $o);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/sso')->status === 403, 'Mitarbeiter auf der Seite Dienste');
}

function test_signatur_umwandlung(): void {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $pub = openssl_pkey_get_public(openssl_pkey_get_details($key)['key']);
    for ($i = 0; $i < 50; $i++) {
        openssl_sign("daten $i", $der, $key, OPENSSL_ALGO_SHA256);
        $raw = Oidc::derToRaw($der);
        check(strlen($raw) === 64 && openssl_verify("daten $i", Oidc::rawToDer($raw), $pub, OPENSSL_ALGO_SHA256) === 1, "Umwandlung $i");
    }
}

run_tests();
