-- Datenbank der Basis (core.sqlite), Version 2. Keine Patientendaten.

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at INTEGER NOT NULL
);

-- Benutzerkonten. admin = Basis-Admin (Benutzer, Firewall, Updates, Sicherung).
-- Admin-Rollen (Basis oder Modul) brauchen OTP; totp_secret ist mit dem Schlüssel der Basis verschlüsselt.
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY,
    login TEXT NOT NULL UNIQUE,
    display TEXT NOT NULL DEFAULT '',
    password_hash TEXT NOT NULL DEFAULT '',
    admin INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    totp_secret TEXT NOT NULL DEFAULT '',
    totp_last_step INTEGER NOT NULL DEFAULT 0,
    passkey_handle TEXT NOT NULL DEFAULT '',
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

-- Frühere Ablage des Zugangs je Modul (bis Basis 0.21.4-beta.28); wird beim Update in user_overrides übernommen und nicht mehr gelesen. admin = Modul-Admin, rights = JSON-Liste der Rechte aus module.toml.
CREATE TABLE IF NOT EXISTS user_modules (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    module TEXT NOT NULL,
    admin INTEGER NOT NULL DEFAULT 0,
    rights TEXT NOT NULL DEFAULT '[]',
    PRIMARY KEY (user_id, module)
);

-- Rollen (Gruppen) eines Benutzers: gelten dauerhaft; ändert sich die Gruppe, ändern sich ihre Mitglieder mit.
CREATE TABLE IF NOT EXISTS user_groups (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    grp TEXT NOT NULL,
    PRIMARY KEY (user_id, grp)
);

-- Einzeländerungen gegenüber den Rollen: perm = „modul:recht“ oder „modul:@admin“, allow 1 = einzeln erlaubt, 0 = einzeln entzogen.
CREATE TABLE IF NOT EXISTS user_overrides (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    perm TEXT NOT NULL,
    allow INTEGER NOT NULL,
    PRIMARY KEY (user_id, perm)
);

-- Wiederherstellungscodes: nur Prüfsumme, je einmal gültig.
CREATE TABLE IF NOT EXISTS recovery_codes (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    code_hash TEXT NOT NULL,
    used_at INTEGER,
    PRIMARY KEY (user_id, code_hash)
);

-- Sitzungen. stage: otp = Passwort richtig, Code fehlt; enroll = OTP muss erst eingerichtet werden; full = angemeldet.
CREATE TABLE IF NOT EXISTS sessions (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    stage TEXT NOT NULL,
    csrf TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    last_used INTEGER NOT NULL,
    confirmed_at INTEGER NOT NULL DEFAULT 0,
    remember INTEGER NOT NULL DEFAULT 0,
    net TEXT NOT NULL DEFAULT '',
    label TEXT NOT NULL DEFAULT '',
    otp_failures INTEGER NOT NULL DEFAULT 0,
    pending_secret TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS sessions_user ON sessions(user_id);
CREATE INDEX IF NOT EXISTS sessions_expiry ON sessions(expires_at);

-- Einmalige Einrichtungslinks (Installer, Konsole, Admin für Mitarbeiter). user_id NULL = ersten Admin anlegen.
CREATE TABLE IF NOT EXISTS setup_tokens (
    token_hash TEXT PRIMARY KEY,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);

-- Passkeys (WebAuthn). public_key als PEM, alg nach COSE (-7 ES256, -257 RS256, -8 EdDSA). Keine Attestierung.
CREATE TABLE IF NOT EXISTS passkeys (
    credential_id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    public_key TEXT NOT NULL,
    alg INTEGER NOT NULL,
    sign_count INTEGER NOT NULL DEFAULT 0,
    label TEXT NOT NULL DEFAULT '',
    synced INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL,
    last_used INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS passkeys_user ON passkeys(user_id);

-- Offene WebAuthn-Abfragen (Einmalwerte, 5 Minuten gültig). purpose: register, login, confirm.
CREATE TABLE IF NOT EXISTS passkey_challenges (
    challenge_hash TEXT PRIMARY KEY,
    purpose TEXT NOT NULL,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    expires_at INTEGER NOT NULL
);

-- Version 2: Basis als OpenID-Connect-Anbieter (M9) für Dienste mit eigener Anmeldung.
CREATE TABLE IF NOT EXISTS oidc_clients (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    secret_hash TEXT NOT NULL DEFAULT '',          -- leer = öffentlicher Client (nur mit PKCE)
    redirect_uris TEXT NOT NULL,                   -- JSON-Liste, genaue Übereinstimmung
    logout_uris TEXT NOT NULL DEFAULT '[]',
    admin_only INTEGER NOT NULL DEFAULT 0,
    module TEXT NOT NULL DEFAULT '',               -- Zugang zu diesem Modul nötig (leer = alle aktiven Benutzer)
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS oidc_codes (
    code_hash TEXT PRIMARY KEY,
    client_id TEXT NOT NULL REFERENCES oidc_clients(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    redirect_uri TEXT NOT NULL,
    scope TEXT NOT NULL,
    nonce TEXT NOT NULL DEFAULT '',
    challenge TEXT NOT NULL DEFAULT '',
    auth_time INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS oidc_tokens (
    token_hash TEXT PRIMARY KEY,
    client_id TEXT NOT NULL REFERENCES oidc_clients(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    scope TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS oidc_pending (
    id TEXT PRIMARY KEY,
    params TEXT NOT NULL,
    expires_at INTEGER NOT NULL
);
-- Arbeitsplätze mit Kienzle-Agent (Starter und Dienst am PC, Basis 0.22.0-beta.10): Kennung je PC, Zugang nur als Hash.
CREATE TABLE IF NOT EXISTS arbeitsplaetze (
    id TEXT PRIMARY KEY,                           -- 16 Hex-Zeichen
    name TEXT NOT NULL,                            -- Anzeige (Vorgabe: Rechnername), vom Admin änderbar
    rechner TEXT NOT NULL,
    system TEXT NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,               -- SHA-256 des Zugangs des Agents
    version TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '',
    zuletzt INTEGER NOT NULL DEFAULT 0,
    zustand TEXT NOT NULL DEFAULT '',              -- letzte Rückmeldung des Agents (Drucker, Aktualisierung), ohne Patientendaten
    erstellt INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS arbeitsplatz_auftraege (
    id TEXT PRIMARY KEY,
    platz TEXT NOT NULL REFERENCES arbeitsplaetze(id) ON DELETE CASCADE,
    typ TEXT NOT NULL,                             -- gdt
    daten TEXT NOT NULL,                           -- JSON
    erstellt INTEGER NOT NULL,
    abgeholt INTEGER NOT NULL DEFAULT 0,
    erledigt INTEGER NOT NULL DEFAULT 0,
    ergebnis TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS arbeitsplatz_auftraege_platz ON arbeitsplatz_auftraege(platz, abgeholt);
