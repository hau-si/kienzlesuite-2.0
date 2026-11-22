<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Passkeys (WebAuthn Level 2) ohne Fremdbibliothek.
 *
 * - Nur mit Nutzerprüfung am Gerät (UV: Fingerabdruck, Gesicht oder PIN). Dann ersetzt ein Passkey Passwort und OTP.
 * - Auffindbare Schlüssel (residentKey required): Anmelden ohne Eingabe des Anmeldenamens.
 * - Keine Attestierung (attestation "none"): die Herkunft des Geräts wird nicht geprüft, der Schlüssel schon.
 * - Algorithmen: ES256 (-7), EdDSA/Ed25519 (-8), RS256 (-257).
 * - Abfragen (challenge) sind Einmalwerte, 5 Minuten gültig, an Zweck und ggf. Konto gebunden.
 * - Passkeys brauchen einen Servernamen (keine IP-Adresse) und ein gültiges Zertifikat im Browser.
 */
final class Passkeys {
    public const CHALLENGE_SECONDS = 300;
    public const MAX_PER_USER = 10;
    private const ALGS = [-7, -8, -257];
    private const FLAG_UP = 0x01, FLAG_UV = 0x04, FLAG_BE = 0x08, FLAG_BS = 0x10, FLAG_AT = 0x40, FLAG_ED = 0x80;

    public function __construct(private Database $db, public readonly string $rpId, private string $rpName) {}

    /** RP-ID aus der Konfiguration: der erste Servername, der keine IP-Adresse ist; sonst null (Passkeys aus). */
    public static function rpIdFrom(Config $config): ?string {
        foreach ($config->hostnames as $h) if (!filter_var($h, FILTER_VALIDATE_IP)) return $h;
        return null;
    }

    public function origin(): string { return 'https://' . $this->rpId; }

    // ---- Registrieren ---------------------------------------------------------------------------------------------
    /** Optionen für navigator.credentials.create (Byte-Werte als base64url). */
    public function registrationOptions(array $user, string $handle): array {
        $exclude = [];
        foreach ($this->db->query('SELECT credential_id FROM passkeys WHERE user_id=?', [$user['id']])->fetchAll() as $r) {
            $exclude[] = ['type' => 'public-key', 'id' => $r['credential_id']];
        }
        if (count($exclude) >= self::MAX_PER_USER) throw new Problem('Höchstens ' . self::MAX_PER_USER . ' Passkeys je Konto.', 409);
        return ['challenge' => $this->challenge('register', (int)$user['id']),
            'rp' => ['id' => $this->rpId, 'name' => $this->rpName],
            'user' => ['id' => $handle, 'name' => $user['login'], 'displayName' => $user['display'] !== '' ? $user['display'] : $user['login']],
            'pubKeyCredParams' => array_map(static fn($a) => ['type' => 'public-key', 'alg' => $a], self::ALGS),
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'attestation' => 'none', 'timeout' => self::CHALLENGE_SECONDS * 1000, 'excludeCredentials' => $exclude];
    }

    /** Antwort von navigator.credentials.create prüfen und speichern. */
    public function finishRegistration(array $user, array $client, string $label): void {
        $response = is_array($client['response'] ?? null) ? $client['response'] : [];
        $clientJson = self::b64($response['clientDataJSON'] ?? null, 4096);
        $this->clientData($clientJson, 'webauthn.create', 'register', (int)$user['id']);
        $att = Cbor::decodeAll(self::b64($response['attestationObject'] ?? null, 65536));
        if (!is_array($att) || !($att['authData'] ?? null) instanceof CborBytes || !is_string($att['fmt'] ?? null)) throw self::invalid();
        $auth = $this->authData($att['authData']->bytes, true);
        [$credentialId, $publicKey, $alg] = $auth['credential'];
        $id = self::enc($credentialId);
        if (isset($client['id']) && $client['id'] !== $id) throw self::invalid();
        if ($this->db->one('SELECT 1 FROM passkeys WHERE credential_id=?', [$id])) throw new Problem('Dieser Passkey ist schon eingetragen.', 409);
        $label = trim((string)preg_replace('/[\x00-\x1f\x7f]/u', '', $label));
        $this->db->query('INSERT INTO passkeys(credential_id,user_id,public_key,alg,sign_count,label,synced,created_at) VALUES(?,?,?,?,?,?,?,?)',
            [$id, $user['id'], $publicKey, $alg, $auth['count'], mb_substr($label !== '' ? $label : 'Passkey', 0, 60), $auth['synced'] ? 1 : 0, time()]);
    }

    // ---- Anmelden und Bestätigen ----------------------------------------------------------------------------------
    /** Optionen für navigator.credentials.get. Ohne $userId: Anmelden ohne Anmeldenamen; mit: Bestätigung für dieses Konto. */
    public function assertionOptions(?int $userId): array {
        $allow = [];
        if ($userId !== null) {
            foreach ($this->db->query('SELECT credential_id FROM passkeys WHERE user_id=?', [$userId])->fetchAll() as $r) {
                $allow[] = ['type' => 'public-key', 'id' => $r['credential_id']];
            }
            if (!$allow) throw new Problem('Für dieses Konto ist kein Passkey eingetragen.', 409);
        }
        return ['challenge' => $this->challenge($userId === null ? 'login' : 'confirm', $userId), 'rpId' => $this->rpId,
            'userVerification' => 'required', 'timeout' => self::CHALLENGE_SECONDS * 1000, 'allowCredentials' => $allow];
    }

    /** Antwort von navigator.credentials.get prüfen. Liefert die Benutzer-ID. $userId gesetzt = Bestätigung für dieses Konto. */
    public function finishAssertion(array $client, ?int $userId): int {
        $response = is_array($client['response'] ?? null) ? $client['response'] : [];
        $id = is_string($client['id'] ?? null) ? $client['id'] : '';
        $row = preg_match('/^[A-Za-z0-9_-]{16,1400}$/D', $id) ? $this->db->one('SELECT p.*, u.passkey_handle, u.active FROM passkeys p JOIN users u ON u.id=p.user_id WHERE p.credential_id=?', [$id]) : null;
        $clientJson = self::b64($response['clientDataJSON'] ?? null, 4096);
        // Abfrage erst nach dem Nachschlagen verbrauchen, aber immer: auch eine abgelehnte Antwort macht sie ungültig.
        $this->clientData($clientJson, 'webauthn.get', $userId === null ? 'login' : 'confirm', $userId);
        if (!$row || (int)$row['active'] !== 1 || ($userId !== null && (int)$row['user_id'] !== $userId)) throw new Problem('Dieser Passkey ist hier nicht eingetragen.', 401);
        if (isset($response['userHandle']) && $response['userHandle'] !== null && $response['userHandle'] !== '' && !hash_equals($row['passkey_handle'], (string)$response['userHandle'])) throw self::invalid();
        $authRaw = self::b64($response['authenticatorData'] ?? null, 4096);
        $auth = $this->authData($authRaw, false);
        $signature = self::b64($response['signature'] ?? null, 1024);
        if (!self::verify((int)$row['alg'], $row['public_key'], $authRaw . hash('sha256', $clientJson, true), $signature)) throw new Problem('Passkey-Signatur ungültig.', 401);
        // Zähler: steigt er nicht, obwohl das Gerät zählt, ist der Schlüssel möglicherweise kopiert worden.
        if (($auth['count'] > 0 || (int)$row['sign_count'] > 0) && $auth['count'] <= (int)$row['sign_count']) {
            throw new Problem('Der Passkey meldet einen veralteten Zählerstand (möglicherweise kopiert). Bitte den Admin informieren.', 401);
        }
        $this->db->query('UPDATE passkeys SET sign_count=?, last_used=?, synced=? WHERE credential_id=?', [$auth['count'], time(), $auth['synced'] ? 1 : 0, $id]);
        return (int)$row['user_id'];
    }

    // ---- Verwaltung -----------------------------------------------------------------------------------------------
    public function list(int $userId): array {
        return array_map(static fn($r) => ['id' => $r['credential_id'], 'label' => $r['label'], 'synced' => (int)$r['synced'] === 1,
            'created_at' => (int)$r['created_at'], 'last_used' => (int)$r['last_used']],
            $this->db->query('SELECT credential_id,label,synced,created_at,last_used FROM passkeys WHERE user_id=? ORDER BY created_at', [$userId])->fetchAll());
    }

    public function count(int $userId): int { return (int)$this->db->query('SELECT COUNT(*) FROM passkeys WHERE user_id=?', [$userId])->fetchColumn(); }

    public function remove(int $userId, string $credentialId): bool {
        return $this->db->query('DELETE FROM passkeys WHERE user_id=? AND credential_id=?', [$userId, $credentialId])->rowCount() === 1;
    }

    /** Fester, zufälliger Benutzer-Schlüssel für WebAuthn (verrät weder Namen noch Datenbank-ID). */
    public function handle(array $user): string {
        if ($user['passkey_handle'] !== '') return $user['passkey_handle'];
        $handle = self::enc(random_bytes(16));
        $this->db->query("UPDATE users SET passkey_handle=? WHERE id=? AND passkey_handle=''", [$handle, $user['id']]);
        return (string)$this->db->one('SELECT passkey_handle FROM users WHERE id=?', [$user['id']])['passkey_handle'];
    }

    // ---- Prüfungen ------------------------------------------------------------------------------------------------
    private function challenge(string $purpose, ?int $userId): string {
        $this->db->query('DELETE FROM passkey_challenges WHERE expires_at<?', [time()]);
        $value = self::enc(random_bytes(32));
        $this->db->query('INSERT INTO passkey_challenges(challenge_hash,purpose,user_id,expires_at) VALUES(?,?,?,?)',
            [hash('sha256', $value), $purpose, $userId, time() + self::CHALLENGE_SECONDS]);
        return $value;
    }

    /** clientDataJSON: Typ, Einmalwert (wird verbraucht), Herkunft. */
    private function clientData(string $json, string $type, string $purpose, ?int $userId): void {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['type'] ?? null) !== $type || !is_string($data['challenge'] ?? null)) throw self::invalid();
        $consumed = $this->db->query('DELETE FROM passkey_challenges WHERE challenge_hash=? AND purpose=? AND user_id IS ? AND expires_at>=?',
            [hash('sha256', $data['challenge']), $purpose, $userId, time()])->rowCount();
        if ($consumed !== 1) throw new Problem('Die Passkey-Abfrage ist abgelaufen oder ungültig. Bitte erneut versuchen.', 401);
        if (($data['origin'] ?? null) !== $this->origin()) throw new Problem('Passkey von einer fremden Adresse.', 401);
        if (($data['crossOrigin'] ?? false) === true) throw self::invalid();
    }

    /** authenticatorData zerlegen und prüfen. Bei $attested zusätzlich Schlüssel aus attestedCredentialData. */
    private function authData(string $raw, bool $attested): array {
        if (strlen($raw) < 37) throw self::invalid();
        if (!hash_equals(hash('sha256', $this->rpId, true), substr($raw, 0, 32))) throw new Problem('Passkey gehört zu einer anderen Adresse.', 401);
        $flags = ord($raw[32]);
        if (!($flags & self::FLAG_UP)) throw self::invalid();
        if (!($flags & self::FLAG_UV)) throw new Problem('Das Gerät hat den Nutzer nicht geprüft (Fingerabdruck, Gesicht oder PIN nötig).', 401);
        $count = unpack('N', substr($raw, 33, 4))[1];
        $out = ['count' => $count, 'synced' => ($flags & self::FLAG_BE) && ($flags & self::FLAG_BS)];
        if ($attested) {
            if (!($flags & self::FLAG_AT) || strlen($raw) < 55) throw self::invalid();
            $len = unpack('n', substr($raw, 53, 2))[1];
            if ($len < 16 || $len > 1023 || strlen($raw) < 55 + $len) throw self::invalid();
            $credentialId = substr($raw, 55, $len);
            [$cose, $end] = Cbor::decode($raw, 55 + $len);
            if ($end !== strlen($raw) && !($flags & self::FLAG_ED)) throw self::invalid();
            $out['credential'] = [$credentialId, ...self::coseKey($cose)];
        }
        return $out;
    }

    /** COSE-Schlüssel → [gespeicherte Form, Algorithmus]. */
    private static function coseKey(mixed $k): array {
        if (!is_array($k)) throw self::invalid();
        $bytes = static fn($v) => $v instanceof CborBytes ? $v->bytes : throw self::invalid();
        $kty = $k[1] ?? null;
        $alg = $k[3] ?? null;
        if ($kty === 2 && $alg === -7 && ($k[-1] ?? null) === 1) {                 // EC2, P-256
            $x = $bytes($k[-2] ?? null); $y = $bytes($k[-3] ?? null);
            if (strlen($x) !== 32 || strlen($y) !== 32) throw self::invalid();
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
            if (openssl_pkey_get_public(self::pem($der)) === false) throw self::invalid();   // Punkt liegt auf der Kurve
            return [self::pem($der), -7];
        }
        if ($kty === 1 && $alg === -8 && ($k[-1] ?? null) === 6) {                 // OKP, Ed25519
            $x = $bytes($k[-2] ?? null);
            if (strlen($x) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) throw self::invalid();
            return ['ed25519:' . base64_encode($x), -8];
        }
        if ($kty === 3 && $alg === -257) {                                          // RSA
            $n = ltrim($bytes($k[-1] ?? null), "\0"); $e = ltrim($bytes($k[-2] ?? null), "\0");
            if (strlen($n) < 256 || strlen($n) > 512 || $e === '' || strlen($e) > 4) throw self::invalid();   // 2048–4096 Bit
            $int = static fn(string $v) => "\x02" . self::derLen(strlen((ord($v[0]) & 0x80 ? "\0" : '') . $v)) . (ord($v[0]) & 0x80 ? "\0" : '') . $v;
            $rsa = $int($n) . $int($e);
            $bits = "\0" . "\x30" . self::derLen(strlen($rsa)) . $rsa;
            $body = hex2bin('300d06092a864886f70d0101010500') . "\x03" . self::derLen(strlen($bits)) . $bits;
            return [self::pem("\x30" . self::derLen(strlen($body)) . $body), -257];
        }
        throw new Problem('Dieser Passkey-Typ wird nicht unterstützt.', 422);
    }

    private static function verify(int $alg, string $key, string $data, string $signature): bool {
        if ($alg === -8) {
            if (!str_starts_with($key, 'ed25519:') || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) return false;
            return sodium_crypto_sign_verify_detached($signature, $data, base64_decode(substr($key, 8), true) ?: '');
        }
        if ($alg !== -7 && $alg !== -257) return false;
        $public = openssl_pkey_get_public($key);
        return $public !== false && openssl_verify($data, $signature, $public, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function derLen(int $n): string {
        if ($n < 128) return chr($n);
        $b = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function pem(string $der): string { return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n"; }

    public static function enc(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }

    private static function b64(mixed $value, int $max): string {
        if (!is_string($value) || $value === '' || strlen($value) > $max * 4 / 3 + 4 || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) throw self::invalid();
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        if ($raw === false || strlen($raw) > $max) throw self::invalid();
        return $raw;
    }

    private static function invalid(): Problem { return new Problem('Antwort des Passkeys ist ungültig.', 400); }
}
