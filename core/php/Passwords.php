<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** Passwortregeln und Hashing. Regeln übernommen aus kienzle-suite-beta (src/Admin.php: checkPassword, weak). */
final class Passwords {
    public const MIN = 10;
    public const MAX = 200;

    public static function hash(string $password): string { return password_hash($password, self::algorithm()); }

    public static function needsRehash(string $hash): bool { return password_needs_rehash($hash, self::algorithm()); }

    /** Wirft Problem mit verständlicher Meldung, wenn das Passwort nicht genügt. $policy: Regeln aus PasswordPolicy (null = Standard). */
    public static function check(string $password, string $login = '', ?array $policy = null): void {
        $p = $policy ?? PasswordPolicy::DEFAULTS;
        if (mb_strlen($password) < $p['min']) throw new Problem('Das Passwort muss mindestens ' . $p['min'] . ' Zeichen lang sein.', 422);
        if (mb_strlen($password) > self::MAX) throw new Problem('Das Passwort ist zu lang.', 422);
        if (self::classes($password) < $p['klassen']) throw new Problem('Das Passwort braucht mindestens ' . $p['klassen'] . ' Zeichenarten (Kleinbuchstaben, Großbuchstaben, Ziffern, Sonderzeichen).', 422);
        if ($p['schwach'] && self::weak($password)) throw new Problem('Dieses Passwort ist zu einfach zu erraten. Bitte mehrere Wörter oder eine längere Zeichenfolge wählen, die nicht „passwort“, „admin“ oder Ähnliches enthält.', 422);
        if ($p['login'] && $login !== '' && str_contains(mb_strtolower($password), mb_strtolower($login))) {
            throw new Problem('Das Passwort darf den Anmeldenamen nicht enthalten.', 422);
        }
        if ($p['staerke'] > 0 && self::score($password, $p['login'] ? $login : '', $p['schwach']) < $p['staerke']) {
            throw new Problem('Das Passwort ist nicht stark genug (verlangt: Stufe ' . $p['staerke'] . '). Längere Wortfolgen oder mehr verschiedene Zeichen helfen.', 422);
        }
    }

    /** Anzahl der Zeichenarten: Klein, Groß, Ziffern, alles andere. */
    public static function classes(string $password): int {
        return (preg_match('/\p{Ll}/u', $password) ? 1 : 0) + (preg_match('/\p{Lu}/u', $password) ? 1 : 0) + (preg_match('/\d/', $password) ? 1 : 0) + (preg_match('/[^\p{L}\d]/u', $password) ? 1 : 0);
    }

    /**
     * Stärke 0 (sehr schwach) bis 4 (sehr gut), grob nach geschätzten Bits: Zeichenvorrat hoch Länge, wiederholte und aufeinanderfolgende
     * Zeichen zählen nicht mit, bekannte Muster und der Anmeldename deckeln. Dieselbe Rechnung steht in core/public/assets/passwort.js.
     */
    public static function score(string $password, string $login = '', bool $checkWeak = true): int {
        $chars = mb_str_split($password);
        if (!$chars) return 0;
        $pool = (preg_match('/[a-z]/', $password) ? 26 : 0) + (preg_match('/[A-Z]/', $password) ? 26 : 0) + (preg_match('/\d/', $password) ? 10 : 0)
            + (preg_match('/[\x20-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e]/', $password) ? 33 : 0) + (preg_match('/[^\x20-\x7e]/u', $password) ? 30 : 0);
        $eff = 0; $prev = null;
        foreach ($chars as $c) {
            $o = mb_ord($c);
            if ($prev === null || ($o !== $prev && abs($o - $prev) !== 1)) $eff++;
            $prev = $o;
        }
        $bits = $pool > 1 ? $eff * log($pool, 2) : 0;
        if (count(array_unique($chars)) < 5) $bits = min($bits, 15);
        if (($checkWeak && self::weak($password)) || ($login !== '' && str_contains(mb_strtolower($password), mb_strtolower($login)))) $bits = min($bits, 20);
        return $bits < 28 ? 0 : ($bits < 40 ? 1 : ($bits < 56 ? 2 : ($bits < 72 ? 3 : 4)));
    }

    /** Häufige und offensichtliche Passwörter ablehnen (Länge prüft check). */
    public static function weak(string $password): bool {
        $plain = mb_strtolower($password);
        if (count(array_unique(mb_str_split($plain))) < 5) return true;            // z. B. „aaaaaaaaaaaa“
        $squeezed = (string)preg_replace('/[^a-z0-9]/', '', strtr($plain, ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 's']));
        $rest = $squeezed;
        foreach (['passwort', 'password', 'admin', 'kienzle', 'sumup', 'qwertz', 'qwert', 'asdf', 'letmein', 'welcome', 'willkommen',
                  'praxis', 't2med', '1234567', '0123456', 'abcdefg', '1111111', '0000000'] as $bad) $rest = str_replace($bad, '', $rest);
        return $rest !== $squeezed && strlen($rest) < 6;   // fast nur aus solchen Mustern (plus ein paar Ziffern)
    }

    private static function algorithm(): string|int { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT; }
}
