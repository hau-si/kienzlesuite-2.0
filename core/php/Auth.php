<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Anmeldung und Sitzungen.
 *
 * Ablauf: Passwort → (bei Admin-Rollen) OTP-Code oder Wiederherstellungscode → angemeldet. Hat ein Konto mit
 * Admin-Rolle noch kein OTP, darf die Sitzung nur die OTP-Einrichtung aufrufen. Nach jedem Schritt bekommt die
 * Sitzung einen neuen Schlüssel (kein Übernehmen eines vorher bekannten Cookies).
 *
 * Aus kienzle-suite-beta (src/Admin.php) übernommen: gleitende Sitzungen mit Höchstdauer, „Gerät merken“ nur aus
 * dem Netz der Anmeldung, erneute Bestätigung heikler Aktionen, Sperre je Absender plus Gesamtbremse.
 */
final class Auth {
    public const COOKIE = 'kienzle_sitzung';
    public const IDLE_DEFAULT = 30;              // Minuten ohne Aktion
    public const MAX_DEFAULT = 12;               // Stunden Höchstdauer
    public const MAX_IDLE_MINUTES = 525600;      // 1 Jahr
    public const MAX_HOURS = 8760;               // 1 Jahr
    public const STEP_SECONDS = 600;             // 10 Minuten für OTP-Eingabe bzw. OTP-Einrichtung
    public const REMEMBER_IDLE = 2592000;        // gemerkte Geräte: 30 Tage ohne Nutzung …
    public const REMEMBER_MAX = 15811200;        // … höchstens 6 Monate
    public const CONFIRM_SECONDS = 86400;        // heikle Aktionen: Bestätigung gilt 24 Stunden
    public const OTP_TRIES = 5;                  // Fehlversuche bei der Code-Eingabe je Sitzung
    private const GLOBAL_LIMIT = 200;            // Fehlversuche aller Absender je Stunde vor einer kurzen Pause

    /** Wartezeit nach falschem Passwort in Mikrosekunden (bremst Raten; Tests setzen 0). */
    public static int $failDelay = 800000;
    /** Feste Uhrzeit für Tests (null = echte Zeit). */
    public static ?int $now = null;

    public readonly Throttle $throttle;
    private string $dummyHash;

    public function __construct(private Database $db, private Users $users, private AuditLog $audit, private string $client) {
        $this->throttle = new Throttle($db, Throttle::normalize($client !== '' ? $client : 'lokal'));
        $this->dummyHash = Passwords::hash('kein-echtes-passwort-' . bin2hex(random_bytes(4)));
    }

    private static function time(): int { return self::$now ?? time(); }

    // ---- Anmelden -------------------------------------------------------------------------------------------------
    /** @return array{token:string, stage:string, user:array} */
    public function login(string $login, string $password, bool $remember, string $agent): array {
        $this->checkLock();
        $user = $this->users->byLogin($login);
        // Unbekannte Konten kosten dieselbe Rechenzeit wie bekannte (kein Erraten gültiger Namen über die Laufzeit).
        $ok = $user ? $this->users->verifyPassword($user, $password) : password_verify($password, $this->dummyHash) && false;
        if (!$ok || (int)$user['active'] !== 1) {
            $this->failed('anmelden-fehlgeschlagen', $user ? $user['login'] : '');
            usleep(self::$failDelay);
            throw new Problem('Anmeldename oder Passwort falsch.', 401);
        }
        $this->throttle->clear('login');
        $stage = !$this->users->needsOtp($user) ? 'full' : ($this->users->hasOtp($user) ? 'otp' : 'enroll');
        $this->audit->write($user['login'], 'passwort-ok', $stage === 'full' ? 'angemeldet' : 'weiter: ' . $stage);
        return ['token' => $this->open((int)$user['id'], $stage, $remember, $agent), 'stage' => $stage, 'user' => $user];
    }

    /** Zweiter Schritt: OTP-Code oder Wiederherstellungscode. Liefert den neuen Sitzungsschlüssel. */
    public function otp(array $session, string $code): string {
        if ($session['stage'] !== 'otp') throw new Problem('Dieser Schritt ist nicht vorgesehen.', 409);
        $this->checkLock();
        $user = $this->users->find((int)$session['user_id']);
        $recovery = preg_match('/^[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}-?[A-Za-z0-9]{4}$/D', trim($code)) === 1;
        $ok = $user && ($recovery ? $this->users->useRecoveryCode((int)$user['id'], $code) : $this->users->verifyOtp($user, $code, self::time()));
        if (!$ok) {
            $failures = (int)$session['otp_failures'] + 1;
            $this->failed('code-fehlgeschlagen', $user['login'] ?? '');
            if ($failures >= self::OTP_TRIES) {
                $this->db->query('DELETE FROM sessions WHERE token_hash=?', [$session['token_hash']]);
                throw new Problem('Zu viele falsche Codes. Bitte neu anmelden.', 401);
            }
            $this->db->query('UPDATE sessions SET otp_failures=? WHERE token_hash=?', [$failures, $session['token_hash']]);
            if ($user && !$recovery && $this->users->hasOtp($user)) $this->clockCheck($session, $this->users->secret($user), $code);
            usleep(self::$failDelay);
            throw new Problem('Code falsch oder schon verwendet.', 401);
        }
        $this->throttle->clear('login');
        $this->audit->write($user['login'], $recovery ? 'wiederherstellungscode-verwendet' : 'code-ok', 'angemeldet; uebrig ' . $this->users->recoveryLeft((int)$user['id']));
        return $this->promote($session, 'full');
    }

    /**
     * Anmelden mit Passkey (bereits von Passkeys::finishAssertion geprüft, mit Nutzerprüfung am Gerät).
     * Ersetzt Passwort und Code. Admin-Konten ohne OTP müssen es trotzdem einrichten (Rückfall bei Verlust).
     * @return array{token:string, stage:string, user:array}
     */
    public function loginWithPasskey(int $userId, bool $remember, string $agent): array {
        $this->checkLock();
        $user = $this->users->find($userId);
        if (!$user || (int)$user['active'] !== 1) throw new Problem('Dieses Konto ist nicht aktiv.', 401);
        $this->throttle->clear('login');
        $stage = $this->users->needsOtp($user) && !$this->users->hasOtp($user) ? 'enroll' : 'full';
        $this->audit->write($user['login'], 'passkey-ok', $stage === 'full' ? 'angemeldet' : 'weiter: ' . $stage);
        return ['token' => $this->open($userId, $stage, $remember, $agent), 'stage' => $stage, 'user' => $user];
    }

    /** Heikle Aktion mit Passkey bestätigen (bereits geprüft, für genau dieses Konto). */
    public function confirmWithPasskey(array $session): void {
        $this->db->query('UPDATE sessions SET confirmed_at=? WHERE token_hash=?', [self::time(), $session['token_hash']]);
        $this->audit->write((string)$session['login'], 'bestaetigt', 'passkey');
    }

    // ---- OTP einrichten -------------------------------------------------------------------------------------------
    /** Neues Geheimnis für diese Sitzung erzeugen (noch nicht aktiv). @return array{secret:string, uri:string} */
    public function enrollStart(array $session, string $issuer): array {
        if (!in_array($session['stage'], ['enroll', 'full'], true)) throw new Problem('Dieser Schritt ist nicht vorgesehen.', 409);
        $user = $this->users->find((int)$session['user_id']);
        // Begonnene Einrichtung behalten: Nach einem falschen Code muss niemand neu scannen.
        $secret = $session['pending_secret'] !== '' ? $this->users->decryptPending((int)$user['id'], $session['pending_secret']) : Totp::generateSecret();
        $this->db->query('UPDATE sessions SET pending_secret=? WHERE token_hash=?', [$this->users->encryptPending((int)$user['id'], $secret), $session['token_hash']]);
        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user['login'], $issuer)];
    }

    /** Ersten Code bestätigen: OTP wird aktiv. @return array{token:string, codes:list<string>} */
    public function enrollConfirm(array $session, string $code): array {
        if (!in_array($session['stage'], ['enroll', 'full'], true) || $session['pending_secret'] === '') throw new Problem('Bitte die Einrichtung neu beginnen.', 409);
        $this->checkLock();
        $uid = (int)$session['user_id'];
        $secret = $this->users->decryptPending($uid, $session['pending_secret']);
        $step = Totp::verify($secret, $code, 0, self::time());
        if ($step === null) {
            $this->failed('otp-einrichtung-fehlgeschlagen', '');
            $this->clockCheck($session, $secret, $code);
            throw new Problem('Code stimmt nicht. Uhrzeit von Telefon und Server prüfen und den aktuellen Code eingeben.', 422);
        }
        $codes = $this->users->enableOtp($uid, $secret, $step);
        $this->db->query("UPDATE sessions SET pending_secret='' WHERE token_hash=?", [$session['token_hash']]);
        $this->db->query('DELETE FROM sessions WHERE user_id=? AND token_hash<>?', [$uid, $session['token_hash']]);   // andere Geräte abmelden
        $this->audit->write($this->users->find($uid)['login'], 'otp-eingerichtet');
        return ['token' => $this->promote($session, 'full'), 'codes' => $codes];
    }

    // ---- Sitzungen ------------------------------------------------------------------------------------------------
    /** Sitzung zum Cookie-Wert oder null. Verlängert gleitend; $stage = verlangte Stufe (null = jede). */
    public function session(?string $token, ?string $stage = 'full'): ?array {
        if (!$token || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) return null;
        $now = self::time();
        $row = $this->db->one('SELECT s.*, u.login, u.display, u.admin AS user_admin, u.active FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token_hash=? AND s.expires_at>?',
            [hash('sha256', $token), $now]);
        if (!$row || (int)$row['active'] !== 1) return null;
        if ($stage !== null && $row['stage'] !== $stage) return null;
        $remember = (int)$row['remember'] === 1;
        if ($remember && !hash_equals((string)$row['net'], self::net($this->client))) return null;   // gestohlenes Cookie nützt von außen nichts
        $max = $row['stage'] !== 'full' ? self::STEP_SECONDS : ($remember ? self::REMEMBER_MAX : $this->maxSeconds());
        if ((int)$row['created_at'] < $now - $max) {
            $this->db->query('DELETE FROM sessions WHERE token_hash=?', [$row['token_hash']]);
            return null;
        }
        if ($row['stage'] === 'full') {
            $expires = min($now + ($remember ? self::REMEMBER_IDLE : $this->idleSeconds()), (int)$row['created_at'] + $max);
            $this->db->query('UPDATE sessions SET expires_at=?, last_used=? WHERE token_hash=?', [$expires, $now, $row['token_hash']]);
        }
        return $row;
    }

    public function logout(?string $token): void {
        if ($token) $this->db->query('DELETE FROM sessions WHERE token_hash=?', [hash('sha256', $token)]);
    }

    /** Lebensdauer des Cookies in Sekunden (null = bis zum Schließen des Browsers). */
    public function cookieTtl(array $session): ?int { return (int)$session['remember'] === 1 ? self::REMEMBER_MAX : null; }

    public static function cookie(string $value, ?int $ttl): string {
        return self::COOKIE . '=' . $value . '; Path=/; Secure; HttpOnly; SameSite=Strict' . ($ttl === null ? '' : '; Max-Age=' . $ttl);
    }

    public static function clearCookie(): string { return self::COOKIE . '=; Path=/; Secure; HttpOnly; SameSite=Strict; Max-Age=0'; }

    // ---- Heikle Aktionen ------------------------------------------------------------------------------------------
    public function confirmedRecently(array $session): bool { return (int)$session['confirmed_at'] > self::time() - self::CONFIRM_SECONDS; }

    /** Passwort und (bei Admin-Rollen) Code erneut prüfen; danach gilt die Bestätigung 24 Stunden. */
    public function confirm(array $session, string $password, string $code): void {
        $this->checkLock();
        $user = $this->users->find((int)$session['user_id']);
        $ok = $user && $this->users->verifyPassword($user, $password)
            && (!$this->users->needsOtp($user) || $this->users->verifyOtp($user, $code, self::time()));
        if (!$ok) {
            $this->failed('bestaetigung-fehlgeschlagen', $user['login'] ?? '');
            usleep(self::$failDelay);
            throw new Problem('Passwort oder Code falsch.', 403);
        }
        $this->throttle->clear('login');
        $this->db->query('UPDATE sessions SET confirmed_at=? WHERE token_hash=?', [self::time(), $session['token_hash']]);
        $this->audit->write($user['login'], 'bestaetigt');
    }

    // ---- Gemerkte Geräte ------------------------------------------------------------------------------------------
    /** Gemerkte Geräte eines Kontos (ohne Sitzungswerte). */
    public function devices(int $userId, ?string $current): array {
        $own = $current ? hash('sha256', $current) : '';
        $out = [];
        foreach ($this->db->query('SELECT token_hash,label,created_at,last_used,net FROM sessions WHERE user_id=? AND remember=1 AND stage=? AND expires_at>? ORDER BY last_used DESC',
            [$userId, 'full', self::time()])->fetchAll() as $r) {
            $out[] = ['id' => substr($r['token_hash'], 0, 16), 'label' => $r['label'], 'created_at' => (int)$r['created_at'], 'last_used' => (int)$r['last_used'],
                'net' => $r['net'], 'current' => $r['token_hash'] === $own];
        }
        return $out;
    }

    /** Gemerktes Gerät eines Kontos abmelden; „all“ = alle gemerkten Geräte dieses Kontos. */
    public function revoke(int $userId, string $id): int {
        if ($id === 'all') $n = $this->db->query('DELETE FROM sessions WHERE user_id=? AND remember=1', [$userId])->rowCount();
        elseif (preg_match('/^[a-f0-9]{16}$/D', $id)) $n = $this->db->query('DELETE FROM sessions WHERE user_id=? AND remember=1 AND substr(token_hash,1,16)=?', [$userId, $id])->rowCount();
        else throw new Problem('Ungültiges Gerät.', 422);
        return $n;
    }

    // ---- Abmeldezeiten --------------------------------------------------------------------------------------------
    public function idleSeconds(): int { return max(5, min(self::MAX_IDLE_MINUTES, (int)($this->db->setting('session_idle_minutes') ?? self::IDLE_DEFAULT))) * 60; }

    public function maxSeconds(): int { return max(1, min(self::MAX_HOURS, (int)($this->db->setting('session_max_hours') ?? self::MAX_DEFAULT))) * 3600; }

    public function saveTimes(int $idleMinutes, int $maxHours): void {
        if ($idleMinutes < 5 || $idleMinutes > self::MAX_IDLE_MINUTES) throw new Problem('Die Abmeldung bei Inaktivität muss zwischen 5 Minuten und 1 Jahr liegen.', 422);
        if ($maxHours < 1 || $maxHours > self::MAX_HOURS) throw new Problem('Die Höchstdauer einer Sitzung muss zwischen 1 Stunde und 1 Jahr liegen.', 422);
        if ($idleMinutes * 60 > $maxHours * 3600) throw new Problem('Die Abmeldung bei Inaktivität darf nicht länger sein als die Höchstdauer der Sitzung.', 422);
        $this->db->store('session_idle_minutes', (string)$idleMinutes);
        $this->db->store('session_max_hours', (string)$maxHours);
    }

    // ---- Hilfen ---------------------------------------------------------------------------------------------------
    /** Netz des Geräts: IPv4 als /24, IPv6 als /64. */
    public static function net(string $address): string {
        if (str_contains($address, ':') && ($packed = @inet_pton($address)) !== false && strlen($packed) === 16) return bin2hex(substr($packed, 0, 8)) . '/64';
        if (preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$/D', $address, $m)) return $m[1] . '.0/24';
        return $address;
    }

    /** Kurze Gerätebeschreibung aus dem Browser-Kennzeichen, z. B. „Chrome auf Windows“. */
    public static function describeAgent(string $agent): string {
        $browser = preg_match('/Edg\//', $agent) ? 'Edge' : (preg_match('/Firefox\//', $agent) ? 'Firefox' : (preg_match('/Chrome\//', $agent) ? 'Chrome' : (preg_match('/Safari\//', $agent) ? 'Safari' : 'Browser')));
        $system = preg_match('/Windows/', $agent) ? 'Windows' : (preg_match('/iPhone|iPad/', $agent) ? 'iOS' : (preg_match('/Android/', $agent) ? 'Android' : (preg_match('/Mac OS X/', $agent) ? 'macOS' : (preg_match('/Linux/', $agent) ? 'Linux' : '?'))));
        return $browser . ' auf ' . $system;
    }

    private function open(int $userId, string $stage, bool $remember, string $agent): string {
        $now = self::time();
        $this->db->query('DELETE FROM sessions WHERE expires_at<?', [$now]);
        $token = self::token();
        $ttl = $stage !== 'full' ? self::STEP_SECONDS : ($remember ? self::REMEMBER_IDLE : $this->idleSeconds());
        $this->db->query('INSERT INTO sessions(token_hash,user_id,stage,csrf,created_at,expires_at,last_used,confirmed_at,remember,net,label) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [hash('sha256', $token), $userId, $stage, bin2hex(random_bytes(24)), $now, $now + $ttl, $now, $stage === 'full' ? $now : 0,
             $remember ? 1 : 0, $remember ? self::net($this->client) : '', $remember ? self::describeAgent($agent) : '']);
        return $token;
    }

    /** Sitzung eine Stufe weiter: neuer Schlüssel, neue CSRF-Marke, Zeit ab jetzt. */
    private function promote(array $session, string $stage): string {
        $now = self::time();
        $token = self::token();
        $remember = (int)$session['remember'] === 1;
        $this->db->query("UPDATE sessions SET token_hash=?, stage=?, csrf=?, created_at=?, expires_at=?, last_used=?, confirmed_at=?, otp_failures=0, pending_secret='' WHERE token_hash=?",
            [hash('sha256', $token), $stage, bin2hex(random_bytes(24)), $now, $now + ($remember ? self::REMEMBER_IDLE : $this->idleSeconds()), $now, $now, $session['token_hash']]);
        return $token;
    }

    private static function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }

    private function checkLock(): void {
        $this->throttle->check('login');
        $until = (int)(json_decode((string)$this->db->setting('login_global_lock'), true)['until'] ?? 0);
        if ($until > time()) throw new Problem('Zu viele Anmeldeversuche. Bitte ' . max(1, (int)ceil(($until - time()) / 60)) . ' Minuten warten.', 429);
    }

    public const CLOCK_KEY = 'uhr_abweichung:';

    /** Nach einem falschen Code: Passt er zu einer anderen Uhrzeit, Abweichung für diese Sitzung merken (5 Minuten). */
    private function clockCheck(array $session, string $secret, string $code): void {
        $skew = Totp::skew($secret, $code, self::time());
        if ($skew === null || abs($skew) <= Totp::PERIOD * Totp::WINDOW) return;
        $this->db->store(self::CLOCK_KEY . $session['token_hash'], json_encode(['delta' => $skew, 'bis' => self::time() + 300]));
        throw new ClockProblem($skew);
    }

    /** Gemerkte Abweichung dieser Sitzung (Sekunden) oder null; wird beim Abholen gelöscht. */
    public function takeClockSkew(array $session): ?int {
        $key = self::CLOCK_KEY . $session['token_hash'];
        $v = json_decode((string)$this->db->setting($key), true);
        $this->db->query('DELETE FROM settings WHERE key=?', [$key]);
        return is_array($v) && (int)($v['bis'] ?? 0) >= self::time() ? (int)$v['delta'] : null;
    }

    private function failed(string $event, string $login): void {
        // Je Absender: 5 Fehlversuche → 5 Minuten Sperre, bei Wiederholung doppelt so lang (höchstens 1 Stunde).
        $this->throttle->fail('login');
        $this->audit->write($login !== '' ? $login : '-', $event);
        // Gesamtbremse gegen verteilte Angriffe aus vielen Adressen: nur kurze Pause für alle.
        $state = json_decode((string)$this->db->setting('login_global_failures'), true) ?: ['count' => 0, 'since' => time()];
        if ($state['since'] < time() - 3600) $state = ['count' => 0, 'since' => time()];
        if (++$state['count'] >= self::GLOBAL_LIMIT) {
            $this->db->store('login_global_lock', json_encode(['until' => time() + 900]));
            $state = ['count' => 0, 'since' => time()];
        }
        $this->db->store('login_global_failures', json_encode($state));
    }
}
