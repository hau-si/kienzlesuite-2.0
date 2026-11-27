<?php
declare(strict_types=1);
// Gemeinsame Hilfen für die PHP-Tests der Basis. Nur erfundene Testdaten, Adressen aus 192.0.2.0/24 und example.org.
require dirname(__DIR__, 2) . '/core/php/bootstrap.php';

use Kienzle\Core\{AuditLog, Auth, Config, Database, Modules, Passkeys, Problem, Secrets, Users};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

/** Erwartet Problem mit HTTP-Status; liefert die Meldung. */
function problem(callable $fn, int $http, string $message): string {
    try { $fn(); } catch (Problem $e) {
        check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')');
        return $e->getMessage();
    }
    throw new RuntimeException($message . ' (kein Fehler)');
}

/** Läuft alle Funktionen test_* einer Datei; Ausgabe wie unittest. */
function run_tests(): void {
    $fail = 0; $n = 0;
    foreach (get_defined_functions()['user'] as $f) {
        if (!str_starts_with($f, 'test_')) continue;
        $n++;
        try { $f(); echo '.'; }
        catch (Throwable $e) { $fail++; echo "\nFEHLER $f: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"; }
    }
    echo "\n$n Tests, " . ($fail ? "$fail fehlgeschlagen" : 'alle grün') . "\n";
    exit($fail ? 1 : 0);
}

function tmpdir(): string {
    $d = sys_get_temp_dir() . '/kienzle-test-' . bin2hex(random_bytes(6));
    mkdir($d, 0700, true);
    register_shutdown_function(static function () use ($d): void { exec('rm -rf ' . escapeshellarg($d)); });
    return $d;
}

/** Module für Tests: beispiel (aus dem Repository) und ein erfundenes „kasse“ mit eigenen Rechten. */
function sample_modules(): Modules {
    $json = shell_exec(escapeshellarg(dirname(__DIR__, 2) . '/core/bin/kienzle-modul-pruefen') . ' --alle --json ' . escapeshellarg(dirname(__DIR__, 2) . '/modules'));
    $data = json_decode((string)$json, true);
    check(is_array($data), 'Modulverzeichnis nicht erzeugt');
    // Nur das Beispielmodul aus dem Repository; das echte Modul kasse hat eigene Tests (modules/kasse/tests).
    $data['modules'] = array_values(array_filter($data['modules'], fn($m) => $m['module']['id'] === 'beispiel'));
    $kasse = $data['modules'][0];
    $kasse['module']['id'] = 'kasse'; $kasse['module']['name'] = 'Kasse'; $kasse['web']['path'] = '/kasse';
    $kasse['rights'] = [['id' => 'paid', 'label' => 'Bezahlt-Liste abhaken'], ['id' => 'catalog', 'label' => 'Leistungen bearbeiten']];
    $data['modules'][] = $kasse;
    return Modules::fromArray($data['modules']);
}

/** Vollständige Umgebung: Datenbank, Schlüssel, Benutzer, Anmeldung. */
final class Env {
    public Database $db; public Secrets $secrets; public Modules $modules; public Users $users; public AuditLog $audit; public Auth $auth; public Config $config;
    public Passkeys $passkeys; public string $dir;
    public function __construct(string $client = '192.0.2.10') {
        Auth::$failDelay = 0;
        $this->dir = tmpdir();
        file_put_contents($this->dir . '/secret.key', Secrets::generateKey());
        $this->config = Config::fromArray(['hostnames' => ['kienzle.example.org', '192.0.2.5'], 'data_dir' => $this->dir, 'secret_key' => $this->dir . '/secret.key',
            'modules' => $this->dir . '/modules.json', 'practice' => 'Praxis Beispiel']);
        $this->db = new Database($this->dir); $this->db->migrate();
        $this->secrets = Secrets::fromFile($this->dir . '/secret.key');
        $this->modules = sample_modules();
        $this->users = new Users($this->db, $this->secrets, $this->modules);
        $this->audit = new AuditLog($this->dir);
        $this->auth = new Auth($this->db, $this->users, $this->audit, $client);
        $this->passkeys = new Passkeys($this->db, 'kienzle.example.org', 'Praxisherz');
    }
    /** Konto mit Passwort; bei $otp mit eingerichtetem OTP. Liefert [id, secret]. */
    public function user(string $login, bool $admin = false, string $password = 'Wiesen-Blume-Fahrrad-7', bool $otp = false): array {
        $id = $this->users->create($login, ucfirst($login), $admin);
        $this->users->setPassword($id, $password);
        $secret = '';
        if ($otp) { $secret = Kienzle\Core\Totp::generateSecret(); $this->users->enableOtp($id, $secret, 0); }
        return [$id, $secret];
    }
}

/** Nachgebauter Authenticator (nur Tests): erzeugt echte Schlüssel und signiert wie ein Gerät. */
final class SoftAuthenticator {
    public string $credentialId; public int $count = 0; public string $handle = '';
    private $ec; private string $edSecret = '';
    public function __construct(public string $alg = 'es256', public string $rpId = 'kienzle.example.org', public bool $uv = true) {
        $this->credentialId = random_bytes(32);
        if ($alg === 'es256') $this->ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        elseif ($alg === 'rs256') $this->ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        else { $kp = sodium_crypto_sign_keypair(); $this->edSecret = sodium_crypto_sign_secretkey($kp); }
    }
    public static function b64(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
    public static function cbor(mixed $v): string {
        $head = static function (int $major, int $n): string {
            if ($n < 24) return chr($major << 5 | $n);
            if ($n < 256) return chr($major << 5 | 24) . chr($n);
            if ($n < 65536) return chr($major << 5 | 25) . pack('n', $n);
            return chr($major << 5 | 26) . pack('N', $n);
        };
        if ($v === true) return "\xf5";
        if ($v === false) return "\xf4";
        if ($v === null) return "\xf6";
        if (is_int($v)) return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
        if ($v instanceof Kienzle\Core\CborBytes) return $head(2, strlen($v->bytes)) . $v->bytes;
        if (is_string($v)) return $head(3, strlen($v)) . $v;
        if (is_array($v) && array_is_list($v)) return $head(4, count($v)) . implode('', array_map([self::class, 'cbor'], $v));
        if (is_array($v)) { $o = $head(5, count($v)); foreach ($v as $k => $x) $o .= self::cbor($k) . self::cbor($x); return $o; }
        throw new LogicException('cbor');
    }
    private function cose(): string {
        $B = fn($s) => new Kienzle\Core\CborBytes($s);
        if ($this->alg === 'es256') {
            $d = openssl_pkey_get_details($this->ec)['ec'];
            return self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => $B(str_pad($d['x'], 32, "\0", STR_PAD_LEFT)), -3 => $B(str_pad($d['y'], 32, "\0", STR_PAD_LEFT))]);
        }
        if ($this->alg === 'rs256') {
            $d = openssl_pkey_get_details($this->ec)['rsa'];
            return self::cbor([1 => 3, 3 => -257, -1 => $B($d['n']), -2 => $B($d['e'])]);
        }
        return self::cbor([1 => 1, 3 => -8, -1 => 6, -2 => $B(sodium_crypto_sign_publickey_from_secretkey($this->edSecret))]);
    }
    private function flags(bool $at): int { return 0x01 | ($this->uv ? 0x04 : 0) | 0x08 | 0x10 | ($at ? 0x40 : 0); }
    public function clientData(string $type, string $challenge, string $origin = ''): string {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?: 'https://' . $this->rpId, 'crossOrigin' => false]);
    }
    public function create(array $options, string $origin = ''): array {
        $this->handle = $options['user']['id'];
        $auth = hash('sha256', $this->rpId, true) . chr($this->flags(true)) . pack('N', $this->count) . str_repeat("\0", 16)
            . pack('n', strlen($this->credentialId)) . $this->credentialId . $this->cose();
        $att = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new Kienzle\Core\CborBytes($auth)]);
        return ['id' => self::b64($this->credentialId), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => self::b64($this->clientData('webauthn.create', $options['challenge'], $origin)), 'attestationObject' => self::b64($att)]];
    }
    public function get(array $options, string $origin = '', ?int $count = null): array {
        $this->count = $count ?? $this->count + 1;
        $auth = hash('sha256', $this->rpId, true) . chr($this->flags(false)) . pack('N', $this->count);
        $client = $this->clientData('webauthn.get', $options['challenge'], $origin);
        $data = $auth . hash('sha256', $client, true);
        if ($this->alg === 'ed25519') $sig = sodium_crypto_sign_detached($data, $this->edSecret);
        else openssl_sign($data, $sig, $this->ec, OPENSSL_ALGO_SHA256);
        return ['id' => self::b64($this->credentialId), 'type' => 'public-key', 'response' => [
            'clientDataJSON' => self::b64($client), 'authenticatorData' => self::b64($auth), 'signature' => self::b64($sig), 'userHandle' => $this->handle]];
    }
}
