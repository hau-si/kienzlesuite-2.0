<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Basis als OpenID-Connect-Anbieter (M9): Dienste mit eigener Anmeldung (z. B. Oberfläche der Telefonanlage, NAS) melden
 * Benutzer über die Basis an – mit derselben Anmeldung inklusive OTP bzw. Passkey.
 *
 * Umfang (bewusst klein): Authorization Code Flow, PKCE S256 (Pflicht für öffentliche Clients), ID-Token ES256,
 * Discovery, JWKS, Userinfo, Abmelden (RP-initiated). Clients legt nur ein Basis-Admin an; Weiterleitungsadressen
 * müssen genau übereinstimmen. Codes gelten 60 Sekunden und einmal, Zugangsmarken eine Stunde. Gespeichert werden
 * nur Hashes von Codes, Marken und Client-Geheimnissen; der Signaturschlüssel liegt verschlüsselt in der Datenbank.
 */
final class Oidc {
    public const CODE_SECONDS = 60;
    public const TOKEN_SECONDS = 3600;
    public const ID_TOKEN_SECONDS = 600;
    private const KEY = 'oidc_signing_key';
    private const SCOPES = ['openid', 'profile', 'groups'];

    public function __construct(private Database $db, private Users $users, private Secrets $secrets, private Config $config) {}

    public function issuer(): string { return 'https://' . $this->config->hostnames[0]; }

    public function discovery(): array {
        $i = $this->issuer();
        return ['issuer' => $i, 'authorization_endpoint' => "$i/oidc/authorize", 'token_endpoint' => "$i/oidc/token", 'userinfo_endpoint' => "$i/oidc/userinfo",
            'jwks_uri' => "$i/oidc/jwks", 'end_session_endpoint' => "$i/oidc/abmelden", 'response_types_supported' => ['code'], 'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code'], 'subject_types_supported' => ['public'], 'id_token_signing_alg_values_supported' => ['ES256'],
            'scopes_supported' => self::SCOPES, 'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'], 'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'name', 'preferred_username', 'groups']];
    }

    // ---- Schlüssel ----------------------------------------------------------------------------------------------
    private function privateKey(): \OpenSSLAsymmetricKey {
        $stored = $this->db->setting(self::KEY);
        if ($stored === null) {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            if ($key === false || !openssl_pkey_export($key, $pem)) throw new Problem('Signaturschlüssel konnte nicht erzeugt werden.', 500);
            $this->db->store(self::KEY, $this->secrets->encrypt($pem, 'oidc-key'));
            return $key;
        }
        $key = openssl_pkey_get_private($this->secrets->decrypt($stored, 'oidc-key'));
        if ($key === false) throw new Problem('Signaturschlüssel unlesbar.', 500);
        return $key;
    }

    public function jwks(): array {
        $d = openssl_pkey_get_details($this->privateKey())['ec'];
        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'x' => self::b64(str_pad($d['x'], 32, "\0", STR_PAD_LEFT)), 'y' => self::b64(str_pad($d['y'], 32, "\0", STR_PAD_LEFT))];
        return ['keys' => [$jwk + ['kid' => self::kid($jwk), 'use' => 'sig', 'alg' => 'ES256']]];
    }

    private static function kid(array $jwk): string {
        return self::b64(hash('sha256', json_encode(['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x'], 'y' => $jwk['y']], JSON_UNESCAPED_SLASHES), true));
    }

    public static function b64(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }

    public function sign(array $claims): string {
        $kid = $this->jwks()['keys'][0]['kid'];
        $input = self::b64(json_encode(['alg' => 'ES256', 'typ' => 'JWT', 'kid' => $kid], JSON_UNESCAPED_SLASHES))
            . '.' . self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!openssl_sign($input, $der, $this->privateKey(), OPENSSL_ALGO_SHA256)) throw new Problem('Signieren fehlgeschlagen.', 500);
        return $input . '.' . self::b64(self::derToRaw($der));
    }

    /** ECDSA-Signatur von DER (SEQUENCE{r,s}) nach r||s mit je 32 Byte (JWS). */
    public static function derToRaw(string $der): string {
        $pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $int = ltrim(substr($der, $pos + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $pos += 2 + $len;
        }
        return $out;
    }

    // ---- Clients ------------------------------------------------------------------------------------------------
    public function clients(): array {
        return array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'public' => $c['secret_hash'] === '', 'redirect_uris' => json_decode($c['redirect_uris'], true) ?: [],
            'logout_uris' => json_decode($c['logout_uris'], true) ?: [], 'admin_only' => (int)$c['admin_only'] === 1, 'module' => $c['module'], 'created_at' => (int)$c['created_at']],
            $this->db->query('SELECT * FROM oidc_clients ORDER BY name')->fetchAll());
    }

    public function client(string $id): ?array {
        foreach ($this->clients() as $c) if (hash_equals($c['id'], $id)) return $c;
        return null;
    }

    private static function uris(string $text, bool $required): array {
        $out = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $u = trim($line);
            if ($u === '') continue;
            $p = parse_url($u);
            $localhost = in_array($p['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
            if (!$p || !isset($p['scheme'], $p['host']) || !($p['scheme'] === 'https' || ($p['scheme'] === 'http' && $localhost)) || isset($p['fragment']) || isset($p['user'])
                || strlen($u) > 300 || preg_match('/[\s<>"]/', $u)) {
                throw new Problem('Adresse ungültig (https, ohne #): ' . mb_substr($u, 0, 80), 422);
            }
            $out[] = $u;
        }
        if ($required && !$out) throw new Problem('Mindestens eine Weiterleitungsadresse angeben.', 422);
        if (count($out) > 10) throw new Problem('Höchstens 10 Adressen.', 422);
        return array_values(array_unique($out));
    }

    /** Client anlegen; liefert [id, Geheimnis (nur jetzt sichtbar, leer bei öffentlichen Clients)]. */
    public function createClient(string $name, string $redirects, string $logouts, bool $public, bool $adminOnly, string $module): array {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60 || preg_match('/[\x00-\x1f]/', $name)) throw new Problem('Bitte einen Namen angeben (höchstens 60 Zeichen).', 422);
        if ($module !== '' && !preg_match('/^[a-z][a-z0-9-]{1,28}$/D', $module)) throw new Problem('Modul ungültig.', 422);
        $id = 'ks-' . bin2hex(random_bytes(8));
        $secret = $public ? '' : self::b64(random_bytes(32));
        $this->db->query('INSERT INTO oidc_clients(id,name,secret_hash,redirect_uris,logout_uris,admin_only,module,created_at) VALUES(?,?,?,?,?,?,?,?)',
            [$id, $name, $public ? '' : hash('sha256', $secret), json_encode(self::uris($redirects, true)), json_encode(self::uris($logouts, false)), $adminOnly ? 1 : 0, $module, time()]);
        return [$id, $secret];
    }

    public function newSecret(string $id): string {
        $c = $this->client($id) ?? throw new Problem('Client nicht gefunden.', 404);
        if ($c['public']) throw new Problem('Öffentliche Clients haben kein Geheimnis.', 409);
        $secret = self::b64(random_bytes(32));
        $this->db->query('UPDATE oidc_clients SET secret_hash=? WHERE id=?', [hash('sha256', $secret), $id]);
        $this->db->query('DELETE FROM oidc_tokens WHERE client_id=?', [$id]);
        return $secret;
    }

    public function deleteClient(string $id): void {
        if (!$this->client($id)) throw new Problem('Client nicht gefunden.', 404);
        $this->db->query('DELETE FROM oidc_clients WHERE id=?', [$id]);
    }

    // ---- Anmeldung ----------------------------------------------------------------------------------------------
    /** Anfrage ohne vollständige Anmeldung merken (Rückkehr nach der Anmeldung über /oidc/fortsetzen/<id>). */
    public function park(array $query): string {
        $this->db->query('DELETE FROM oidc_pending WHERE expires_at < ?', [time()]);
        $id = bin2hex(random_bytes(16));
        $keep = array_intersect_key($query, array_flip(['response_type', 'client_id', 'redirect_uri', 'scope', 'state', 'nonce', 'code_challenge', 'code_challenge_method', 'prompt']));
        $this->db->query('INSERT INTO oidc_pending(id,params,expires_at) VALUES(?,?,?)', [$id, json_encode(array_map(fn($v) => is_string($v) ? mb_substr($v, 0, 500) : '', $keep)), time() + 600]);
        return $id;
    }

    public function unpark(string $id): array {
        $row = $this->db->one('SELECT params FROM oidc_pending WHERE id=? AND expires_at>=?', [$id, time()]);
        if (!$row) throw new Problem('Die Anmeldung für den Dienst ist abgelaufen. Bitte im Dienst erneut „Anmelden“ wählen.', 410);
        $this->db->query('DELETE FROM oidc_pending WHERE id=?', [$id]);
        return json_decode($row['params'], true) ?: [];
    }

    /**
     * Prüft die Anfrage. Fehler vor geprüfter Weiterleitungsadresse → Problem (Seite in der Basis),
     * danach → Weiterleitung mit error=… an den Dienst (RFC 6749, 4.1.2.1).
     */
    public function authorize(array $q, array $session): string {
        $c = $this->client((string)($q['client_id'] ?? '')) ?? throw new Problem('Unbekannter Dienst (client_id).', 400);
        $redirect = (string)($q['redirect_uri'] ?? '');
        if (!in_array($redirect, $c['redirect_uris'], true)) throw new Problem('Weiterleitungsadresse ist für diesen Dienst nicht eingetragen.', 400);
        $state = mb_substr((string)($q['state'] ?? ''), 0, 500);
        $fail = fn(string $code, string $text) => self::url($redirect, ['error' => $code, 'error_description' => $text] + ($state !== '' ? ['state' => $state] : []));
        if (($q['response_type'] ?? '') !== 'code') return $fail('unsupported_response_type', 'Nur response_type=code.');
        $scopes = array_values(array_unique(array_filter(explode(' ', (string)($q['scope'] ?? '')))));
        if (!in_array('openid', $scopes, true)) return $fail('invalid_scope', 'scope muss openid enthalten.');
        $scopes = array_values(array_intersect($scopes, self::SCOPES));
        $challenge = (string)($q['code_challenge'] ?? '');
        if ($challenge !== '' && (($q['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge))) return $fail('invalid_request', 'PKCE nur mit S256.');
        if ($c['public'] && $challenge === '') return $fail('invalid_request', 'Öffentliche Dienste brauchen PKCE (S256).');
        $user = $this->users->find((int)$session['user_id']);
        if (!$user || (int)$user['active'] !== 1 || !$this->allowed($c, $user)) return $fail('access_denied', 'Kein Zugang zu diesem Dienst.');
        $code = self::b64(random_bytes(32));
        $this->db->query('DELETE FROM oidc_codes WHERE expires_at < ?', [time()]);
        $this->db->query('INSERT INTO oidc_codes(code_hash,client_id,user_id,redirect_uri,scope,nonce,challenge,auth_time,expires_at) VALUES(?,?,?,?,?,?,?,?,?)',
            [hash('sha256', $code), $c['id'], (int)$user['id'], $redirect, implode(' ', $scopes), mb_substr((string)($q['nonce'] ?? ''), 0, 500), $challenge,
                (int)$session['created_at'], time() + self::CODE_SECONDS]);
        return self::url($redirect, ['code' => $code] + ($state !== '' ? ['state' => $state] : []));
    }

    private function allowed(array $client, array $user): bool {
        if ($client['admin_only'] && (int)$user['admin'] !== 1) return false;
        if ($client['module'] !== '' && !isset($this->users->access((int)$user['id'])[$client['module']])) return false;
        return true;
    }

    private static function url(string $base, array $params): string {
        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    // ---- Token und Userinfo -------------------------------------------------------------------------------------
    /** Token-Endpunkt. Liefert [HTTP-Status, Antwort]. */
    public function token(array $post, string $authorization): array {
        $err = fn(string $code, string $text, int $http = 400) => [$http, ['error' => $code, 'error_description' => $text]];
        [$id, $secret] = ['', ''];
        if (preg_match('/^Basic ([A-Za-z0-9+\/=]{1,600})$/D', $authorization, $m)) {
            $pair = explode(':', (string)base64_decode($m[1], true), 2);
            [$id, $secret] = [rawurldecode($pair[0]), rawurldecode($pair[1] ?? '')];
        } else {
            [$id, $secret] = [(string)($post['client_id'] ?? ''), (string)($post['client_secret'] ?? '')];
        }
        $c = $this->client($id);
        if (!$c) return $err('invalid_client', 'Unbekannter Dienst.', 401);
        if (!$c['public']) {
            $row = $this->db->one('SELECT secret_hash FROM oidc_clients WHERE id=?', [$c['id']]);
            if ($secret === '' || !hash_equals((string)$row['secret_hash'], hash('sha256', $secret))) return $err('invalid_client', 'Anmeldung des Dienstes fehlgeschlagen.', 401);
        }
        if (($post['grant_type'] ?? '') !== 'authorization_code') return $err('unsupported_grant_type', 'Nur authorization_code.');
        $hash = hash('sha256', (string)($post['code'] ?? ''));
        $code = $this->db->transaction(function () use ($hash) {
            $row = $this->db->one('SELECT * FROM oidc_codes WHERE code_hash=?', [$hash]);
            $this->db->query('DELETE FROM oidc_codes WHERE code_hash=?', [$hash]);     // einmalig, auch bei Fehlern
            return $row;
        });
        if (!$code || (int)$code['expires_at'] < time() || !hash_equals($code['client_id'], $c['id'])) return $err('invalid_grant', 'Code ungültig oder abgelaufen.');
        if (!hash_equals($code['redirect_uri'], (string)($post['redirect_uri'] ?? ''))) return $err('invalid_grant', 'redirect_uri passt nicht.');
        if ($code['challenge'] !== '') {
            $verifier = (string)($post['code_verifier'] ?? '');
            if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) || !hash_equals($code['challenge'], self::b64(hash('sha256', $verifier, true)))) {
                return $err('invalid_grant', 'PKCE-Prüfung fehlgeschlagen.');
            }
        }
        $user = $this->users->find((int)$code['user_id']);
        if (!$user || (int)$user['active'] !== 1 || !$this->allowed($c, $user)) return $err('invalid_grant', 'Kein Zugang.');
        $access = self::b64(random_bytes(32));
        $this->db->query('DELETE FROM oidc_tokens WHERE expires_at < ?', [time()]);
        $this->db->query('INSERT INTO oidc_tokens(token_hash,client_id,user_id,scope,expires_at) VALUES(?,?,?,?,?)',
            [hash('sha256', $access), $c['id'], (int)$user['id'], $code['scope'], time() + self::TOKEN_SECONDS]);
        $now = time();
        $claims = ['iss' => $this->issuer(), 'sub' => 'u' . $user['id'], 'aud' => $c['id'], 'exp' => $now + self::ID_TOKEN_SECONDS, 'iat' => $now,
            'auth_time' => (int)$code['auth_time']] + ($code['nonce'] !== '' ? ['nonce' => $code['nonce']] : []) + $this->claims($user, $code['scope']);
        return [200, ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => self::TOKEN_SECONDS, 'id_token' => $this->sign($claims), 'scope' => $code['scope']]];
    }

    private function claims(array $user, string $scope): array {
        $scopes = explode(' ', $scope);
        $out = [];
        if (in_array('profile', $scopes, true)) $out += ['preferred_username' => $user['login'], 'name' => $user['display'] !== '' ? $user['display'] : $user['login']];
        if (in_array('groups', $scopes, true)) {
            $groups = (int)$user['admin'] === 1 ? ['kienzle-admin'] : [];
            foreach ($this->users->access((int)$user['id']) as $m => $a) { $groups[] = 'modul:' . $m; if ($a['admin']) $groups[] = 'modul:' . $m . ':admin'; }
            $out['groups'] = $groups;
        }
        return $out;
    }

    /** Userinfo-Endpunkt. Liefert [HTTP-Status, Antwort]. */
    public function userinfo(string $authorization): array {
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{20,100})$/D', $authorization, $m)) return [401, ['error' => 'invalid_token']];
        $t = $this->db->one('SELECT * FROM oidc_tokens WHERE token_hash=? AND expires_at>=?', [hash('sha256', $m[1]), time()]);
        $user = $t ? $this->users->find((int)$t['user_id']) : null;
        $c = $t ? $this->client($t['client_id']) : null;
        if (!$t || !$user || !$c || (int)$user['active'] !== 1 || !$this->allowed($c, $user)) return [401, ['error' => 'invalid_token']];
        return [200, ['sub' => 'u' . $user['id']] + $this->claims($user, $t['scope'])];
    }

    /** Erlaubte Rücksprungadresse nach dem Abmelden (nur eingetragene Adressen des Dienstes). */
    public function logoutTarget(string $clientId, string $uri): ?string {
        $c = $clientId !== '' ? $this->client($clientId) : null;
        return $c && in_array($uri, $c['logout_uris'], true) ? $uri : null;
    }

    /** client_id aus einem (auch abgelaufenen) ID-Token dieser Basis; Signatur wird geprüft. */
    public function clientFromIdToken(string $jwt): string {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3 || strlen($jwt) > 4000) return '';
        $sig = base64_decode(strtr($parts[2], '-_', '+/'), true);
        if (!is_string($sig) || strlen($sig) !== 64) return '';
        $der = self::rawToDer($sig);
        $pub = openssl_pkey_get_public(openssl_pkey_get_details($this->privateKey())['key']);
        if (openssl_verify($parts[0] . '.' . $parts[1], $der, $pub, OPENSSL_ALGO_SHA256) !== 1) return '';
        $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        return is_array($claims) && ($claims['iss'] ?? '') === $this->issuer() ? (string)($claims['aud'] ?? '') : '';
    }

    public static function rawToDer(string $raw): string {
        $int = function (string $x): string {
            $x = ltrim($x, "\0");
            if ($x === '' || ord($x[0]) & 0x80) $x = "\0" . $x;
            return "\x02" . chr(strlen($x)) . $x;
        };
        $body = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
        return "\x30" . chr(strlen($body)) . $body;
    }
}
