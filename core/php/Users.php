<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Benutzerkonten, Rollen und Rechte.
 *
 * Rollen: Basis-Admin (users.admin), Modul-Admin (user_modules.admin) und Mitarbeiter (nur Rechte aus module.toml).
 * Jede Admin-Rolle braucht OTP. Ein Basis-Admin bekommt keinen Zugang zu Modulen, solange ihm keiner zugewiesen ist:
 * wer die Kasse verwaltet, sieht nicht automatisch Arbeitszeiten. Jede Zuweisung steht im Änderungsprotokoll.
 */
final class Users {
    public const LOGIN = '/^[a-z][a-z0-9._-]{1,31}$/D';
    public const RECOVERY_COUNT = 10;
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // ohne 0/O, 1/I
    public const SETUP_SECONDS = 259200;     // Einrichtungslinks gelten 3 Tage

    public function __construct(private Database $db, private Secrets $secrets, private Modules $modules) {}

    // ---- Konten ---------------------------------------------------------------------------------------------------
    public function find(int $id): ?array { return $this->db->one('SELECT * FROM users WHERE id=?', [$id]); }

    public function byLogin(string $login): ?array { return $this->db->one('SELECT * FROM users WHERE login=?', [mb_strtolower(trim($login))]); }

    /** @return list<array> alle Konten ohne Geheimnisse, mit Modul-Zugängen */
    public function all(): array {
        $out = [];
        foreach ($this->db->query('SELECT id,login,display,admin,active,totp_secret,password_hash FROM users ORDER BY login')->fetchAll() as $u) {
            $out[] = ['id' => (int)$u['id'], 'login' => $u['login'], 'display' => $u['display'], 'admin' => (int)$u['admin'] === 1,
                'active' => (int)$u['active'] === 1, 'otp' => $u['totp_secret'] !== '', 'password' => $u['password_hash'] !== '',
                'access' => $this->access((int)$u['id']), 'roles' => $this->roles((int)$u['id'])];
        }
        return $out;
    }

    public function count(): int { return (int)$this->db->query('SELECT COUNT(*) FROM users')->fetchColumn(); }

    public function create(string $login, string $display, bool $admin): int {
        $login = mb_strtolower(trim($login));
        if (!preg_match(self::LOGIN, $login)) throw new Problem('Anmeldename: 2 bis 32 Zeichen, Kleinbuchstaben, Ziffern, Punkt, Binde- oder Unterstrich, beginnt mit einem Buchstaben.', 422);
        if ($this->byLogin($login)) throw new Problem('Diesen Anmeldenamen gibt es schon.', 409);
        $display = self::display($display);
        $this->db->query('INSERT INTO users(login,display,admin,created_at,updated_at) VALUES(?,?,?,?,?)', [$login, $display, $admin ? 1 : 0, time(), time()]);
        return (int)$this->db->pdo->lastInsertId();
    }

    /** Anzeigename ändern (das eigene Konto); der Benutzername zum Anmelden bleibt. Leer = Benutzername wird angezeigt. */
    public function rename(int $id, string $display): void {
        $this->require($id);
        $this->db->query('UPDATE users SET display=?, updated_at=? WHERE id=?', [self::display($display), time(), $id]);
    }

    public function update(int $id, string $display, bool $admin, bool $active): void {
        $user = $this->require($id);
        if ((!$admin || !$active) && (int)$user['admin'] === 1 && (int)$user['active'] === 1 && $this->activeAdmins() <= 1) {
            throw new Problem('Es muss mindestens einen aktiven Basis-Admin geben.', 409);
        }
        $this->db->query('UPDATE users SET display=?, admin=?, active=?, updated_at=? WHERE id=?', [self::display($display), $admin ? 1 : 0, $active ? 1 : 0, time(), $id]);
        if (!$active) $this->db->query('DELETE FROM sessions WHERE user_id=?', [$id]);
    }

    public function delete(int $id): void {
        $user = $this->require($id);
        if ((int)$user['admin'] === 1 && (int)$user['active'] === 1 && $this->activeAdmins() <= 1) throw new Problem('Der letzte Basis-Admin kann nicht gelöscht werden.', 409);
        $this->db->query('DELETE FROM users WHERE id=?', [$id]);
        $this->db->query('DELETE FROM settings WHERE key IN (?,?)', ['mail.adresse.' . $id, 'mail.offen.' . $id]);
    }

    // ---- E-Mail-Adresse (für den Link „Passwort vergessen“) -------------------------------------------------------
    public const MAIL_SECONDS = 3600;
    public const MAIL_VERSUCHE = 5;

    /** Bestätigte E-Mail-Adresse oder ''. */
    public function email(int $id): string { return (string)$this->db->setting('mail.adresse.' . $id); }

    /** Konto zu einer bestätigten Adresse (Groß-/Kleinschreibung egal), sonst null. */
    public function byEmail(string $address): ?array {
        $row = $this->db->one("SELECT key FROM settings WHERE key LIKE 'mail.adresse.%' AND lower(value)=lower(?)", [trim($address)]);
        return $row ? $this->find((int)substr($row['key'], strlen('mail.adresse.'))) : null;
    }

    /** Neue Adresse vormerken; liefert den Bestätigungscode (16 Ziffern, in Vierergruppen) für die E-Mail. Ein Link wäre lokal nicht erreichbar. */
    public function emailStart(int $id, string $address): string {
        $this->require($id);
        $address = trim($address);
        if (!filter_var($address, FILTER_VALIDATE_EMAIL) || strlen($address) > 254) throw new Problem('Bitte eine gültige E-Mail-Adresse eingeben.', 422);
        $other = $this->byEmail($address);
        if ($other && (int)$other['id'] !== $id) throw new Problem('Diese Adresse wird schon von einem anderen Konto verwendet.', 409);
        $digits = '';
        for ($i = 0; $i < 16; $i++) $digits .= (string)random_int(0, 9);
        $this->db->store('mail.offen.' . $id, json_encode(['a' => $address, 'h' => hash('sha256', $digits), 'e' => time() + self::MAIL_SECONDS, 'v' => 0], JSON_THROW_ON_ERROR));
        return implode('-', str_split($digits, 4));
    }

    /** Code bestätigt → Adresse gilt; liefert sie, sonst null. Nach fünf falschen Eingaben ist der Code verbraucht. */
    public function emailConfirm(int $id, string $code): ?string {
        $p = json_decode((string)$this->db->setting('mail.offen.' . $id), true);
        if (!is_array($p) || (int)($p['e'] ?? 0) < time()) return null;
        $digits = preg_replace('/\D+/', '', $code);
        if (!hash_equals((string)($p['h'] ?? ''), hash('sha256', (string)$digits))) {
            $p['v'] = (int)($p['v'] ?? 0) + 1;
            if ($p['v'] >= self::MAIL_VERSUCHE) $this->db->query('DELETE FROM settings WHERE key=?', ['mail.offen.' . $id]);
            else $this->db->store('mail.offen.' . $id, json_encode($p, JSON_THROW_ON_ERROR));
            return null;
        }
        $other = $this->byEmail((string)$p['a']);
        if ($other && (int)$other['id'] !== $id) return null;
        $this->db->store('mail.adresse.' . $id, (string)$p['a']);
        $this->db->query('DELETE FROM settings WHERE key=?', ['mail.offen.' . $id]);
        return (string)$p['a'];
    }

    public function emailRemove(int $id): void {
        $this->db->query('DELETE FROM settings WHERE key IN (?,?)', ['mail.adresse.' . $id, 'mail.offen.' . $id]);
    }

    /** Ausstehende (noch nicht bestätigte) Adresse oder ''. */
    public function emailPending(int $id): string {
        $p = json_decode((string)$this->db->setting('mail.offen.' . $id), true);
        return is_array($p) && (int)($p['e'] ?? 0) >= time() ? (string)($p['a'] ?? '') : '';
    }

    public function activeAdmins(): int { return (int)$this->db->query('SELECT COUNT(*) FROM users WHERE admin=1 AND active=1')->fetchColumn(); }

    public function setPassword(int $id, string $password): void {
        $user = $this->require($id);
        Passwords::check($password, $user['login'], PasswordPolicy::load($this->db));
        $this->db->query('UPDATE users SET password_hash=?, updated_at=? WHERE id=?', [Passwords::hash($password), time(), $id]);
    }

    public function verifyPassword(array $user, string $password): bool {
        if ($user['password_hash'] === '' || !password_verify($password, $user['password_hash'])) return false;
        if (Passwords::needsRehash($user['password_hash'])) {
            $this->db->query('UPDATE users SET password_hash=? WHERE id=?', [Passwords::hash($password), $user['id']]);
        }
        return true;
    }

    // ---- Rollen und Rechte ----------------------------------------------------------------------------------------
    // Zugang = Rollen (Gruppen, dauerhaft) und Einzeländerungen (erlaubt oder entzogen). Die Rollen kommen aus Groups und gelten
    // sofort für alle Mitglieder; die Einzeländerungen bleiben bei jeder Änderung der Rollen oder Gruppen erhalten.
    private ?Groups $groups = null;

    private function groups(): Groups { return $this->groups ??= new Groups($this->db, $this->modules); }

    /** Rollen des Benutzers (nur Gruppen, die es noch gibt). @return list<string> */
    public function roles(int $id): array {
        $known = array_keys($this->groups()->all());
        $out = [];
        foreach ($this->db->query('SELECT grp FROM user_groups WHERE user_id=? ORDER BY grp', [$id])->fetchAll() as $r) if (in_array($r['grp'], $known, true)) $out[] = $r['grp'];
        return $out;
    }

    /** Rollen ersetzen. @param list<string> $names @throws Problem bei unbekannter Gruppe */
    public function setRoles(int $id, array $names): void {
        $this->require($id);
        $known = array_keys($this->groups()->all());
        $names = array_values(array_unique(array_map('strval', $names)));
        foreach ($names as $n) if (!in_array($n, $known, true)) throw new Problem('Unbekannte Gruppe.', 422);
        $this->db->query('DELETE FROM user_groups WHERE user_id=?', [$id]);
        foreach ($names as $n) $this->db->query('INSERT INTO user_groups(user_id,grp) VALUES(?,?)', [$id, $n]);
    }

    /** Mitglieder einer Gruppe (Anmeldenamen). @return list<string> */
    public function members(string $group): array {
        return array_column($this->db->query('SELECT u.login FROM user_groups g JOIN users u ON u.id=g.user_id WHERE g.grp=? ORDER BY u.login', [$group])->fetchAll(), 'login');
    }

    /** Einzeländerungen: „modul:recht“ oder „modul:@admin“ => true (einzeln erlaubt) / false (einzeln entzogen). @return array<string,bool> */
    public function overrides(int $id): array {
        $out = [];
        foreach ($this->db->query('SELECT perm,allow FROM user_overrides WHERE user_id=? ORDER BY perm', [$id])->fetchAll() as $r) $out[$r['perm']] = (int)$r['allow'] === 1;
        return $out;
    }

    public function resetOverrides(int $id): void { $this->db->query('DELETE FROM user_overrides WHERE user_id=?', [$id]); }

    /** Was die Rollen allein ergeben (ohne Einzeländerungen). @param list<string>|null $roles null = gespeicherte Rollen @return array<string, array{admin:bool, rights:list<string>}> alle installierten Module */
    public function baseAccess(int $id, ?array $roles = null): array {
        $groups = $this->groups()->all();
        $out = [];
        foreach (array_keys($this->modules->all()) as $m) {
            $admin = false;
            $has = [];
            foreach ($roles ?? $this->roles($id) as $name) {
                if (!isset($groups[$name])) continue;
                if (in_array($m, $groups[$name]['admin'], true)) $admin = true;
                foreach ($groups[$name]['rechte'] as $e) if (str_starts_with($e, $m . ':')) $has[substr($e, strlen($m) + 1)] = true;
            }
            $out[$m] = ['admin' => $admin, 'rights' => array_values(array_filter($this->modules->rightIds($m), fn($r) => isset($has[$r])))];
        }
        return $out;
    }

    /** @return array<string, array{admin:bool, rights:list<string>}> nur Module, die noch installiert sind und in denen etwas erlaubt ist */
    public function access(int $id): array {
        $out = [];
        // Basis-Admins dürfen in jedem installierten Modul alles (Modul-Admin mit allen Rechten).
        $base = $this->db->one('SELECT admin FROM users WHERE id=?', [$id]);
        if ($base && (int)$base['admin'] === 1) {
            foreach (array_keys($this->modules->all()) as $m) $out[$m] = ['admin' => true, 'rights' => array_values($this->modules->rightIds($m))];
            return $out;
        }
        $over = $this->overrides($id);
        foreach ($this->baseAccess($id) as $m => $b) {
            $admin = $over[$m . ':@admin'] ?? $b['admin'];
            $rights = array_values(array_filter($this->modules->rightIds($m), fn($r) => $over[$m . ':' . $r] ?? in_array($r, $b['rights'], true)));
            if ($admin || $rights) $out[$m] = ['admin' => $admin, 'rights' => $rights];
        }
        ksort($out);
        return $out;
    }

    /** Rechte der Basis (Groups::BASIS_RECHTE): aus den Rollen, mit Einzeländerungen „core:<recht>“; Basis-Admins haben alle. @return list<string> */
    public function basisRechte(int $id): array {
        $base = $this->db->one('SELECT admin FROM users WHERE id=?', [$id]);
        if ($base && (int)$base['admin'] === 1) return array_keys(Groups::BASIS_RECHTE);
        return array_values(array_filter(array_keys(Groups::BASIS_RECHTE), fn($r) => $this->overrides($id)['core:' . $r] ?? in_array($r, $this->basisVonRollen($id), true)));
    }

    /** Rechte der Basis, die die Rollen allein ergeben. @param list<string>|null $roles @return list<string> */
    public function basisVonRollen(int $id, ?array $roles = null): array {
        $groups = $this->groups()->all();
        $out = [];
        foreach ($roles ?? $this->roles($id) as $name) foreach ($groups[$name]['rechte'] ?? [] as $e) if (str_starts_with($e, 'core:')) $out[] = substr($e, 5);
        return array_values(array_intersect(array_keys(Groups::BASIS_RECHTE), $out));
    }

    /** Rechte der Basis setzen; abgelegt werden nur Abweichungen von den Rollen. */
    public function setBasisRechte(int $id, array $rights): void {
        $this->require($id);
        $rights = array_values(array_unique(array_map('strval', $rights)));
        foreach ($rights as $r) if (!isset(Groups::BASIS_RECHTE[$r])) throw new Problem('Unbekanntes Recht.', 422);
        $base = $this->basisVonRollen($id);
        $this->db->query("DELETE FROM user_overrides WHERE user_id=? AND substr(perm,1,5)='core:'", [$id]);
        foreach (array_keys(Groups::BASIS_RECHTE) as $r) {
            if (in_array($r, $rights, true) !== in_array($r, $base, true)) $this->db->query('INSERT INTO user_overrides(user_id,perm,allow) VALUES(?,?,?)', [$id, 'core:' . $r, in_array($r, $rights, true) ? 1 : 0]);
        }
    }

    /** Gewünschten Zugang zu einem Modul setzen; abgelegt werden nur die Abweichungen von den Rollen. Unbekannte Rechte sind ein Fehler. */
    public function setAccess(int $id, string $module, bool $admin, array $rights): void {
        $this->require($id);
        if (!$this->modules->has($module)) throw new Problem('Unbekanntes Modul.', 422);
        $known = $this->modules->rightIds($module);
        $rights = array_values(array_unique(array_map('strval', $rights)));
        foreach ($rights as $r) if (!in_array($r, $known, true)) throw new Problem('Unbekanntes Recht.', 422);
        $base = $this->baseAccess($id)[$module];
        $this->db->query('DELETE FROM user_overrides WHERE user_id=? AND substr(perm,1,?)=?', [$id, strlen($module) + 1, $module . ':']);
        $ins = fn(string $perm, bool $allow) => $this->db->query('INSERT INTO user_overrides(user_id,perm,allow) VALUES(?,?,?)', [$id, $perm, $allow ? 1 : 0]);
        if ($admin !== $base['admin']) $ins($module . ':@admin', $admin);
        foreach ($known as $r) if (in_array($r, $rights, true) !== in_array($r, $base['rights'], true)) $ins($module . ':' . $r, in_array($r, $rights, true));
    }

    /** Braucht dieses Konto OTP? Ja, sobald es irgendeine Admin-Rolle hat. */
    public function needsOtp(array $user): bool {
        if ((int)$user['admin'] === 1) return true;
        foreach ($this->access((int)$user['id']) as $a) if ($a['admin']) return true;
        return false;
    }

    // ---- OTP ------------------------------------------------------------------------------------------------------
    public function hasOtp(array $user): bool { return $user['totp_secret'] !== ''; }

    public function encryptPending(int $id, string $secret): string { return $this->secrets->encrypt($secret, 'totp-pending:' . $id); }

    public function decryptPending(int $id, string $stored): string { return $this->secrets->decrypt($stored, 'totp-pending:' . $id); }

    /** Code prüfen und verbrauchen. true nur bei einem gültigen, noch nicht verwendeten Code. */
    public function secret(array $user): string { return $this->secrets->decrypt($user['totp_secret'], 'totp:' . $user['id']); }

    public function verifyOtp(array $user, string $code, ?int $time = null): bool {
        if (!$this->hasOtp($user)) return false;
        $secret = $this->secrets->decrypt($user['totp_secret'], 'totp:' . $user['id']);
        $step = Totp::verify($secret, $code, (int)$user['totp_last_step'], $time);
        if ($step === null) return false;
        // Nur übernehmen, wenn kein paralleler Aufruf denselben Schritt schon verbraucht hat.
        return $this->db->query('UPDATE users SET totp_last_step=? WHERE id=? AND totp_last_step<?', [$step, $user['id'], $step])->rowCount() === 1;
    }

    /** OTP einrichten (nach bestätigtem Code). Liefert neue Wiederherstellungscodes zum einmaligen Anzeigen. */
    public function enableOtp(int $id, string $secret, int $step): array {
        $this->db->query('UPDATE users SET totp_secret=?, totp_last_step=?, updated_at=? WHERE id=?', [$this->secrets->encrypt($secret, 'totp:' . $id), $step, time(), $id]);
        return $this->newRecoveryCodes($id);
    }

    // ---- Wiederherstellungscodes ----------------------------------------------------------------------------------
    /** Ersetzt alle Codes. Gespeichert wird nur ein HMAC; die Codes selbst werden nur einmal angezeigt. */
    public function newRecoveryCodes(int $id): array {
        $codes = [];
        while (count($codes) < self::RECOVERY_COUNT) {
            $c = '';
            for ($i = 0; $i < 12; $i++) $c .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            $codes[$c] = implode('-', str_split($c, 4));
        }
        $this->db->transaction(function () use ($id, $codes): void {
            $this->db->query('DELETE FROM recovery_codes WHERE user_id=?', [$id]);
            foreach (array_keys($codes) as $c) $this->db->query('INSERT INTO recovery_codes(user_id,code_hash) VALUES(?,?)', [$id, $this->secrets->mac($c, 'recovery:' . $id)]);
        });
        return array_values($codes);
    }

    public function recoveryLeft(int $id): int { return (int)$this->db->query('SELECT COUNT(*) FROM recovery_codes WHERE user_id=? AND used_at IS NULL', [$id])->fetchColumn(); }

    /** Wiederherstellungscode einlösen (Groß-/Kleinschreibung und Bindestriche egal). */
    public function useRecoveryCode(int $id, string $input): bool {
        $c = strtoupper((string)preg_replace('/[\s-]/', '', $input));
        if (!preg_match('/^[A-Z0-9]{12}$/D', $c)) return false;
        return $this->db->query('UPDATE recovery_codes SET used_at=? WHERE user_id=? AND code_hash=? AND used_at IS NULL',
            [time(), $id, $this->secrets->mac($c, 'recovery:' . $id)])->rowCount() === 1;
    }

    // ---- Einrichtungslinks und Zurücksetzen -----------------------------------------------------------------------
    /** Einmaliger Einrichtungslink. $id null = ersten Basis-Admin anlegen (nur solange es keinen gibt). */
    public function setupToken(?int $id): string {
        if ($id === null && $this->activeAdmins() > 0) throw new Problem('Es gibt schon einen Basis-Admin.', 409);
        if ($id !== null) $this->require($id);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->query('DELETE FROM setup_tokens WHERE expires_at<? OR user_id IS ?', [time(), $id]);
        $this->db->query('INSERT INTO setup_tokens(token_hash,user_id,created_at,expires_at) VALUES(?,?,?,?)', [hash('sha256', $token), $id, time(), time() + self::SETUP_SECONDS]);
        return $token;
    }

    /** Gültiger Einrichtungslink → ['user_id' => ?int]; sonst null. Wird erst mit redeemSetup verbraucht. */
    public function setupInfo(string $token): ?array {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) return null;
        $row = $this->db->one('SELECT user_id FROM setup_tokens WHERE token_hash=? AND expires_at>?', [hash('sha256', $token), time()]);
        if (!$row) return null;
        if ($row['user_id'] === null && $this->activeAdmins() > 0) return null;
        return ['user_id' => $row['user_id'] === null ? null : (int)$row['user_id']];
    }

    public function redeemSetup(string $token): void {
        $this->db->query('DELETE FROM setup_tokens WHERE token_hash=?', [hash('sha256', $token)]);
    }

    /** Passwort, OTP, Codes, Passkeys und Sitzungen löschen (Konsole bzw. Admin für Mitarbeiter); Konto bleibt mit Rechten erhalten. */
    public function resetCredentials(int $id): void {
        $this->require($id);
        $this->db->transaction(function () use ($id): void {
            $this->db->query("UPDATE users SET password_hash='', totp_secret='', totp_last_step=0, updated_at=? WHERE id=?", [time(), $id]);
            $this->db->query('DELETE FROM recovery_codes WHERE user_id=?', [$id]);
            $this->db->query('DELETE FROM passkeys WHERE user_id=?', [$id]);
            $this->db->query('DELETE FROM sessions WHERE user_id=?', [$id]);
        });
    }

    private function require(int $id): array {
        $user = $this->find($id);
        if (!$user) throw new Problem('Benutzer nicht gefunden.', 404);
        return $user;
    }

    private static function display(string $display): string {
        $display = trim((string)preg_replace('/[\x00-\x1f\x7f]/u', '', $display));
        if (mb_strlen($display) > 80) throw new Problem('Der Name ist zu lang (höchstens 80 Zeichen).', 422);
        return $display;
    }
}
