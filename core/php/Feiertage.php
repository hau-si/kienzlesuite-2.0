<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Feiertage je Bundesland, ohne Internet berechnet (Ostern nach der Gauß'schen Osterformel, gregorianisch).
 * Art „gesetzlich“ gilt im ganzen Land und ist vorgewählt; „regional“ (nur in Teilen des Landes) und „zusatz“
 * (kein gesetzlicher Feiertag: Rosenmontag, Heiligabend, Silvester, Brückentage) muss die Praxis selbst anhaken.
 * Die Wahl gilt je Feiertag für alle Jahre (Kennung ohne Jahr).
 */
final class Feiertage {
    public const LAENDER = ['BW' => 'Baden-Württemberg', 'BY' => 'Bayern', 'BE' => 'Berlin', 'BB' => 'Brandenburg', 'HB' => 'Bremen', 'HH' => 'Hamburg',
        'HE' => 'Hessen', 'MV' => 'Mecklenburg-Vorpommern', 'NI' => 'Niedersachsen', 'NW' => 'Nordrhein-Westfalen', 'RP' => 'Rheinland-Pfalz',
        'SL' => 'Saarland', 'SN' => 'Sachsen', 'ST' => 'Sachsen-Anhalt', 'SH' => 'Schleswig-Holstein', 'TH' => 'Thüringen'];

    /** Kennung => [Name, Länder (leer = alle), Länder mit „regional“]. Datum aus feste() bzw. beweglich(). */
    private const TAGE = [
        'neujahr' => ['Neujahr', [], []],
        'drei-koenige' => ['Heilige Drei Könige', ['BW', 'BY', 'ST'], []],
        'frauentag' => ['Internationaler Frauentag', ['BE', 'MV'], []],
        'karfreitag' => ['Karfreitag', [], []],
        'ostermontag' => ['Ostermontag', [], []],
        'tag-der-arbeit' => ['Tag der Arbeit', [], []],
        'himmelfahrt' => ['Christi Himmelfahrt', [], []],
        'pfingstmontag' => ['Pfingstmontag', [], []],
        'fronleichnam' => ['Fronleichnam', ['BW', 'BY', 'HE', 'NW', 'RP', 'SL'], ['SN', 'TH']],
        'friedensfest' => ['Augsburger Friedensfest', [], ['BY']],
        'mariae-himmelfahrt' => ['Mariä Himmelfahrt', ['SL'], ['BY']],
        'weltkindertag' => ['Weltkindertag', ['TH'], []],
        'einheit' => ['Tag der Deutschen Einheit', [], []],
        'reformationstag' => ['Reformationstag', ['BB', 'HB', 'HH', 'MV', 'NI', 'SN', 'ST', 'SH', 'TH'], []],
        'allerheiligen' => ['Allerheiligen', ['BW', 'BY', 'NW', 'RP', 'SL'], []],
        'buss-und-bettag' => ['Buß- und Bettag', ['SN'], []],
        'weihnachten-1' => ['1. Weihnachtstag', [], []],
        'weihnachten-2' => ['2. Weihnachtstag', [], []],
    ];
    /** Überall gültige Tage, die nur im Bundesland, nicht im ganzen Land Feiertag sind, gibt es nicht; diese hier gelten bundesweit. */
    private const BUNDESWEIT = ['neujahr', 'karfreitag', 'ostermontag', 'tag-der-arbeit', 'himmelfahrt', 'pfingstmontag', 'einheit', 'weihnachten-1', 'weihnachten-2'];

    /** Ostersonntag als Y-m-d (gregorianisch, Algorithmus nach Meeus/Jones/Butcher). */
    public static function ostern(int $jahr): string {
        $a = $jahr % 19; $b = intdiv($jahr, 100); $c = $jahr % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $monat = intdiv($h + $l - 7 * $m + 114, 31); $tag = (($h + $l - 7 * $m + 114) % 31) + 1;
        return sprintf('%04d-%02d-%02d', $jahr, $monat, $tag);
    }

    private static function plus(string $datum, int $tage): string {
        return (new \DateTimeImmutable($datum . ' 12:00', new \DateTimeZone('UTC')))->modify(($tage >= 0 ? '+' : '') . $tage . ' days')->format('Y-m-d');
    }

    private static function datum(string $id, int $jahr): string {
        $o = self::ostern($jahr);
        return match ($id) {
            'neujahr' => "$jahr-01-01", 'drei-koenige' => "$jahr-01-06", 'frauentag' => "$jahr-03-08", 'karfreitag' => self::plus($o, -2), 'ostermontag' => self::plus($o, 1),
            'tag-der-arbeit' => "$jahr-05-01", 'himmelfahrt' => self::plus($o, 39), 'pfingstmontag' => self::plus($o, 50), 'fronleichnam' => self::plus($o, 60),
            'friedensfest' => "$jahr-08-08", 'mariae-himmelfahrt' => "$jahr-08-15", 'weltkindertag' => "$jahr-09-20", 'einheit' => "$jahr-10-03",
            'reformationstag' => "$jahr-10-31", 'allerheiligen' => "$jahr-11-01",
            // Buß- und Bettag: Mittwoch vor dem 23. November
            'buss-und-bettag' => self::plus("$jahr-11-23", -(((int)(new \DateTimeImmutable("$jahr-11-23"))->format('N') + 4) % 7 ?: 7)),
            'weihnachten-1' => "$jahr-12-25", 'weihnachten-2' => "$jahr-12-26",
        };
    }

    /**
     * Alle Tage eines Jahres für ein Land, nach Datum sortiert.
     * @return list<array{id:string, datum:string, name:string, art:string, hinweis:string}>
     */
    public static function liste(string $land, int $jahr): array {
        if (!isset(self::LAENDER[$land]) || $jahr < 2000 || $jahr > 2100) return [];
        $out = [];
        foreach (self::TAGE as $id => [$name, $laender, $regional]) {
            $art = in_array($id, self::BUNDESWEIT, true) || in_array($land, $laender, true) ? 'gesetzlich' : (in_array($land, $regional, true) ? 'regional' : null);
            if ($art === null) continue;
            $out[] = ['id' => $id, 'datum' => self::datum($id, $jahr), 'name' => $name, 'art' => $art,
                'hinweis' => $art === 'regional' ? 'nur in Teilen des Landes' : ''];
        }
        $o = self::ostern($jahr);
        $zusatz = [['rosenmontag', self::plus($o, -48), 'Rosenmontag'], ['heiligabend', "$jahr-12-24", 'Heiligabend'], ['silvester', "$jahr-12-31", 'Silvester']];
        // Brückentage: gesetzlicher Feiertag am Dienstag → Montag davor, am Donnerstag → Freitag danach.
        foreach ($out as $f) {
            if ($f['art'] !== 'gesetzlich') continue;
            $wt = (int)(new \DateTimeImmutable($f['datum']))->format('N');
            if ($wt === 2) $zusatz[] = ['bruecke-' . $f['id'], self::plus($f['datum'], -1), 'Brückentag vor ' . $f['name']];
            if ($wt === 4) $zusatz[] = ['bruecke-' . $f['id'], self::plus($f['datum'], 1), 'Brückentag nach ' . $f['name']];
        }
        foreach ($zusatz as [$id, $datum, $name]) $out[] = ['id' => $id, 'datum' => $datum, 'name' => $name, 'art' => 'zusatz', 'hinweis' => 'kein gesetzlicher Feiertag'];
        usort($out, fn($a, $b) => [$a['datum'], $a['id']] <=> [$b['datum'], $b['id']]);
        return $out;
    }

    /** Ist der Tag geschlossen? Vorgabe: gesetzliche ja, sonst nein; „an“/„aus“ (Kennungen) überschreiben. */
    public static function aktiv(array $tag, array $an, array $aus): bool {
        if (in_array($tag['id'], $aus, true)) return false;
        if (in_array($tag['id'], $an, true)) return true;
        return $tag['art'] === 'gesetzlich';
    }

    /** Gültige Kennungen (für die Prüfung gespeicherter Auswahl), ohne Jahr. */
    public static function gueltigeKennung(string $id): bool {
        return isset(self::TAGE[$id]) || in_array($id, ['rosenmontag', 'heiligabend', 'silvester'], true)
            || (str_starts_with($id, 'bruecke-') && isset(self::TAGE[substr($id, 8)]));
    }
}
