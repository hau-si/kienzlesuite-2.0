<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * GOÄ-Gebührenverzeichnis (amtliche Anlage der GOÄ, erzeugt von scripts/goae-verzeichnis.py nach
 * public/assets/goae-verzeichnis.json). Liefert zu einer Ziffer Punktzahl, Einfachsatz und Gebührenrahmen
 * und rechnet die Gebühr genau nach § 5 GOÄ: Punktzahl × Punktwert × Faktor, erst am Ende auf Cent gerundet.
 */
final class GoaeVerzeichnis {
    /** Punktwert in Hunderttausendstel Cent (5,82873 Cent, § 5 Abs. 1 GOÄ). */
    public const PUNKTWERT = 582873;
    private static ?array $eintraege = null;

    public static function datei(): string { return dirname(__DIR__) . '/public/assets/goae-verzeichnis.json'; }

    /** Eintrag zu einer Ziffer („250“, „250a“, „K1“) oder null. */
    public static function eintrag(string $ziffer): ?array {
        if (self::$eintraege === null) {
            self::$eintraege = [];
            $raw = @file_get_contents(self::datei());
            $data = is_string($raw) ? json_decode($raw, true, 8) : null;
            foreach (is_array($data['eintraege'] ?? null) ? $data['eintraege'] : [] as $e) {
                if (is_array($e) && count($e) >= 7) self::$eintraege[strtolower((string)$e[0])] = ['nummer' => (string)$e[0], 'abschnitt' => (string)$e[1],
                    'punkte' => (int)$e[2], 'einfachsatz_cents' => (int)$e[3], 'rahmen' => (string)$e[4], 'text' => (string)$e[5], 'nur_einfach' => (bool)$e[6]];
            }
        }
        return self::$eintraege[strtolower(str_replace(' ', '', trim($ziffer)))] ?? null;
    }

    /** Punktzahl, wenn Ziffer und Einfachsatz zum Verzeichnis passen, sonst 0 (dann gilt Einfachsatz × Faktor). */
    public static function punkte(string $ziffer, int $einfachsatzCents): int {
        $e = $ziffer !== '' && $einfachsatzCents > 0 ? self::eintrag($ziffer) : null;
        return $e !== null && $e['einfachsatz_cents'] === $einfachsatzCents ? $e['punkte'] : 0;
    }

    /** Gebühr in Cent: Punktzahl × Punktwert × Faktor (Zehntausendstel), kaufmännisch gerundet. */
    public static function gebuehr(int $punkte, int $faktorUnits): int {
        return intdiv($punkte * self::PUNKTWERT * $faktorUnits + 500000000, 1000000000);
    }
}
