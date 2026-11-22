<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** Passwortregeln und Hashing. Regeln übernommen aus kienzle-suite-beta (src/Admin.php: checkPassword, weak). */
final class Passwords {
    public const MIN = 10;
    public const MAX = 200;

    public static function hash(string $password): string { return password_hash($password, self::algorithm()); }

    public static function needsRehash(string $hash): bool { return password_needs_rehash($hash, self::algorithm()); }

    /** Wirft Problem mit verständlicher Meldung, wenn das Passwort nicht genügt. */
    public static function check(string $password, string $login = ''): void {
        if (mb_strlen($password) < self::MIN) throw new Problem('Das Passwort muss mindestens ' . self::MIN . ' Zeichen lang sein.', 422);
        if (mb_strlen($password) > self::MAX) throw new Problem('Das Passwort ist zu lang.', 422);
        if (self::weak($password)) throw new Problem('Dieses Passwort ist zu einfach zu erraten. Bitte mehrere Wörter oder eine längere Zeichenfolge wählen, die nicht „passwort“, „admin“ oder eine Zahlenreihe enthält.', 422);
        if ($login !== '' && str_contains(mb_strtolower($password), mb_strtolower($login))) {
            throw new Problem('Das Passwort darf den Anmeldenamen nicht enthalten.', 422);
        }
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
