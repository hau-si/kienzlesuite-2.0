<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Einmalcodes nach RFC 6238 (TOTP) mit HMAC-SHA1, 6 Stellen, 30 Sekunden – so, wie gängige Authenticator-Apps sie
 * erwarten. Ein Code wird nur einmal angenommen: gespeichert wird der zuletzt verwendete Zeitschritt.
 */
final class Totp {
    public const PERIOD = 30;
    public const DIGITS = 6;
    public const WINDOW = 1;           // ±1 Zeitschritt (Uhrabweichung bis 30 s)
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Neues Geheimnis: 20 Zufallsbytes (160 Bit, wie in RFC 4226 empfohlen), Base32 ohne Auffüllung. */
    public static function generateSecret(): string { return self::base32Encode(random_bytes(20)); }

    /** Code für einen Zeitschritt (RFC 4226, dynamische Kürzung). $digits nur für die RFC-Testwerte änderbar. */
    public static function code(string $secret, int $step, int $digits = self::DIGITS, string $algo = 'sha1', bool $raw = false): string {
        $key = $raw ? $secret : self::base32Decode($secret);
        $hash = hash_hmac($algo, pack('J', $step), $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function step(?int $time = null): int { return intdiv($time ?? time(), self::PERIOD); }

    /**
     * Prüft $input gegen die Zeitschritte um jetzt. Liefert den getroffenen Zeitschritt oder null.
     * Schritte bis einschließlich $lastStep sind verbraucht (Schutz gegen erneute Verwendung).
     */
    public static function verify(string $secret, string $input, int $lastStep, ?int $time = null): ?int {
        $input = preg_replace('/\s+/', '', $input);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/D', (string)$input)) return null;
        $now = self::step($time);
        $hit = null;
        for ($s = $now - self::WINDOW; $s <= $now + self::WINDOW; $s++) {   // immer alle Schritte prüfen: gleiche Laufzeit
            if (hash_equals(self::code($secret, $s), $input) && $s > $lastStep && $hit === null) $hit = $s;
        }
        return $hit;
    }

    /**
     * Abweichung der Server-Uhr: Passt $input zu einem Zeitschritt innerhalb von ±24 Stunden, liefert das die Abweichung
     * in Sekunden (Zeit des Telefons minus Zeit des Servers, auf 30 s genau), sonst null. Nur nach einem Fehlschlag aufrufen.
     */
    public static function skew(string $secret, string $input, ?int $time = null, int $hours = 24): ?int {
        $input = (string)preg_replace('/\s+/', '', $input);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/D', $input)) return null;
        $now = self::step($time);
        $max = intdiv($hours * 3600, self::PERIOD);
        for ($d = 0; $d <= $max; $d++) {
            foreach ($d === 0 ? [0] : [$d, -$d] as $o) if (hash_equals(self::code($secret, $now + $o), $input)) return $o * self::PERIOD;
        }
        return null;
    }

    /** otpauth-Adresse für den QR-Code der Authenticator-App. */
    public static function uri(string $secret, string $account, string $issuer): string {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query(['secret' => $secret, 'issuer' => $issuer,
            'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC3986);
    }

    /** Geheimnis zum Abtippen in Vierergruppen. */
    public static function grouped(string $secret): string { return trim(chunk_split($secret, 4, ' ')); }

    public static function base32Encode(string $bytes): string {
        $bits = '';
        foreach (str_split($bytes) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }

    public static function base32Decode(string $text): string {
        $text = strtoupper((string)preg_replace('/[\s=-]/', '', $text));
        if ($text === '' || strspn($text, self::ALPHABET) !== strlen($text)) throw new \InvalidArgumentException('Ungültiges Base32.');
        $bits = '';
        foreach (str_split($text) as $c) $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }
}
