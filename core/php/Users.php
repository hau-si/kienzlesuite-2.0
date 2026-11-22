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
                'access' => $this->access((int)$u['id'])];
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
    }

    public function activeAdmins(): int { return (int)$this->db->query('SELECT COUNT(*) FROM users WHERE admin=1 AND active=1')->fetchColumn(); }

    public function setPassword(int $id, string $password): void {
        $user = $this->require($id);
        Passwords::check($password, $user['login']);
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
    /** @return array<string, array{admin:bool, rights:list<string>}> nur Module, die noch installiert sind */
    public function access(int $id): array {
        $out = [];
        foreach ($this->db->query('SELECT module,admin,rights FROM user_modules WHERE user_id=? ORDER BY module', [$id])->fetchAll() as $r) {
            if (!$this->modules->has($r['module'])) continue;
            $rights = json_decode($r['rights'], true);
            $out[$r['module']] = ['admin' => (int)$r['admin'] === 1,
                'rights' => array_values(array_intersect($this->modules->rightIds($r['module']), is_array($rights) ? $rights : []))];
        }
        return $out;
    }

    /** Zugang zu einem Modul setzen; ohne Admin und ohne Rechte wird er entfernt. Unbekannte Rechte sind ein Fehler. */
    public function setAccess(int $id, string $module, bool $admin, array $rights): void {
        $this->require($id);
        if (!$this->modules->has($module)) throw new Problem('Unbekanntes Modul.', 422);
        $known = $this->modules->rightIds($module);
        $rights = array_values(array_unique(array_map('strval', $rights)));
        foreach ($rights as $r) if (!in_array($r, $known, true)) throw new Problem('Unbekanntes Recht.', 422);
        if (!$admin && !$rights) { $this->db->query('DELETE FROM user_modules WHERE user_id=? AND module=?', [$id, $module]); return; }
        $this->db->query('INSERT INTO user_modules(user_id,module,admin,rights) VALUES(?,?,?,?) ON CONFLICT(user_id,module) DO UPDATE SET admin=excluded.admin, rights=excluded.rights',
            [$id, $module, $admin ? 1 : 0, json_encode(array_values(array_intersect($known, $rights)))]);
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
