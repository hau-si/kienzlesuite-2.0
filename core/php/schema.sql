-- Datenbank der Basis (core.sqlite), Version 1. Keine Patientendaten.

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

-- Zugang je Modul: admin = Modul-Admin, rights = JSON-Liste der Rechte aus module.toml.
CREATE TABLE IF NOT EXISTS user_modules (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    module TEXT NOT NULL,
    admin INTEGER NOT NULL DEFAULT 0,
    rights TEXT NOT NULL DEFAULT '[]',
    PRIMARY KEY (user_id, module)
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
