<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Geheimnisse verschlüsselt speichern (libsodium secretbox, XSalsa20-Poly1305). Der Schlüssel liegt in einer eigenen
 * Datei (root:ks-core, 0640), nie in der Datenbank: Eine kopierte Datenbank allein verrät keine OTP-Geheimnisse.
 * Der Zusatz $context bindet den Wert an seinen Zweck (z. B. „totp:<benutzer-id>“), damit verschlüsselte Werte
 * nicht zwischen Datensätzen vertauscht werden können.
 */
final class Secrets {
    private const PREFIX = 'v1:';

    private function __construct(private string $key) {}

    public static function fromFile(string $path): self {
        $raw = @file_get_contents($path);
        if (!is_string($raw)) throw new \RuntimeException('Schlüsseldatei der Basis fehlt.');
        try { $key = sodium_hex2bin(trim($raw)); } catch (\SodiumException) { $key = ''; }
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \RuntimeException('Schlüsseldatei der Basis ist ungültig.');
        return new self($key);
    }

    /** Neuen Schlüssel als Text für die Schlüsseldatei (Installer). */
    public static function generateKey(): string { return sodium_bin2hex(sodium_crypto_secretbox_keygen()) . "\n"; }

    public static function fromKey(string $binary): self {
        if (strlen($binary) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new \InvalidArgumentException('Schlüssellänge falsch.');
        return new self($binary);
    }

    public function encrypt(string $plain, string $context): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::PREFIX . sodium_bin2base64($nonce . sodium_crypto_secretbox($plain, $nonce, $this->subkey($context)), SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function decrypt(string $stored, string $context): string {
        if (!str_starts_with($stored, self::PREFIX)) throw new \RuntimeException('Gespeichertes Geheimnis hat ein unbekanntes Format.');
        try {
            $raw = sodium_base642bin(substr($stored, strlen(self::PREFIX)), SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (\SodiumException) {
            throw new \RuntimeException('Gespeichertes Geheimnis ist beschädigt.');
        }
        $plain = strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->subkey($context)) : false;
        if ($plain === false) throw new \RuntimeException('Gespeichertes Geheimnis ist beschädigt oder gehört zu einem anderen Schlüssel.');
        return $plain;
    }

    /** Für Einmal-Werte, die nur verglichen werden (z. B. Wiederherstellungscodes): HMAC mit dem Schlüssel der Basis. */
    public function mac(string $value, string $context): string {
        return hash_hmac('sha256', $value, $this->subkey('mac:' . $context));
    }

    private function subkey(string $context): string {
        return sodium_crypto_generichash($context, $this->key, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
