<?php
declare(strict_types=1);
namespace KienzleTelefon;

/**
 * Anrufstatistik (Seite „Statistik“): liest <daten>/statistik/statistik.sqlite, die lib/telefon-ereignisse schreibt. Darin stehen
 * nur Zeitpunkte, Anliegen aus dem Sprachmenü und Wartezeiten der Warteschlange – keine Nummern oder Namen.
 */
final class Statistik {
    public const ROUTEN = ['prescription' => 'Rezept', 'appointment' => 'Termin', 'referral' => 'Überweisung', 'queue' => 'Praxis (persönlich)',
        'callback' => 'Rückruf', 'other' => 'Sonstiges', 'opening_hours' => 'Öffnungszeiten', 'pharmacy' => 'Apotheke', 'specialist' => 'Fachstelle',
        'ai' => 'KI-Assistent', 'ivr' => 'Sprachmenü'];
    public const ZEITRAUM = ['woche' => 7, 'monat' => 30, 'quartal' => 91];

    public static function datei(): string { return Anlage::$data . '/statistik/statistik.sqlite'; }

    private static function db(): ?\PDO {
        if (!is_file(self::datei())) return null;
        return new \PDO('sqlite:file:' . self::datei() . '?mode=ro', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    }

    private static function tag(int $t): string { return (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d'); }

    /** Kennzahlen und Verläufe für den Zeitraum (woche, monat, quartal) bis heute. */
    public static function auswerten(string $zeitraum = 'woche', ?int $jetzt = null): array {
        $jetzt ??= time();
        $tage = self::ZEITRAUM[$zeitraum] ?? 7;
        $heute = self::tag($jetzt);
        $von = self::tag($jetzt - ($tage - 1) * 86400);
        $leer = ['heute' => 0, 'zeitraum' => 0, 'angenommen' => null, 'warten' => null, 'aufgelegt' => 0, 'tage' => [], 'routen' => [], 'muster' => [], 'von' => $von, 'bis' => $heute, 'daten' => false];
        $verlauf = [];
        for ($i = 13; $i >= 0; $i--) $verlauf[self::tag($jetzt - $i * 86400)] = 0;
        $db = self::db();
        if (!$db) return $leer + ['verlauf' => $verlauf];
        $zahl = static function (string $sql, array $p) use ($db): int { $st = $db->prepare($sql); $st->execute($p); return (int)$st->fetchColumn(); };
        $out = $leer;
        $out['daten'] = true;
        $out['heute'] = $zahl('SELECT COUNT(*) FROM anrufe WHERE tag = ?', [$heute]);
        $out['zeitraum'] = $zahl('SELECT COUNT(*) FROM anrufe WHERE tag BETWEEN ? AND ?', [$von, $heute]);
        $st = $db->prepare('SELECT tag, COUNT(*) n FROM anrufe WHERE tag >= ? GROUP BY tag');
        $st->execute([array_key_first($verlauf)]);
        foreach ($st->fetchAll() as $z) if (isset($verlauf[$z['tag']])) $verlauf[$z['tag']] = (int)$z['n'];
        $out['verlauf'] = $verlauf;
        $st = $db->prepare('SELECT r.route, COUNT(*) n FROM routen r JOIN anrufe a ON a.id = r.id WHERE a.tag BETWEEN ? AND ? GROUP BY r.route ORDER BY n DESC');
        $st->execute([$von, $heute]);
        foreach ($st->fetchAll() as $z) if (isset(self::ROUTEN[$z['route']])) $out['routen'][self::ROUTEN[$z['route']]] = (int)$z['n'];
        $st = $db->prepare('SELECT ergebnis, COUNT(*) n, AVG(sekunden) s FROM warten WHERE tag BETWEEN ? AND ? GROUP BY ergebnis');
        $st->execute([$von, $heute]);
        $w = [];
        foreach ($st->fetchAll() as $z) $w[$z['ergebnis']] = $z;
        $an = (int)($w['angenommen']['n'] ?? 0);
        $out['aufgelegt'] = (int)($w['aufgelegt']['n'] ?? 0);
        if ($an + $out['aufgelegt'] > 0) $out['angenommen'] = (int)round(100 * $an / ($an + $out['aufgelegt']));
        if ($an > 0) $out['warten'] = (int)round((float)$w['angenommen']['s']);
        $st = $db->prepare('SELECT wochentag, stunde, COUNT(*) n FROM anrufe WHERE tag BETWEEN ? AND ? GROUP BY wochentag, stunde');
        $st->execute([$von, $heute]);
        foreach ($st->fetchAll() as $z) $out['muster'][(int)$z['wochentag']][(int)$z['stunde']] = (int)$z['n'];
        return $out;
    }

    /** Kurzfassung für die Leiste auf der Seite „Anrufe“: heute, die zwei häufigsten Anliegen heute, Ø Wartezeit heute. */
    public static function heute(?int $jetzt = null): array {
        $jetzt ??= time();
        $db = self::db();
        if (!$db) return ['anrufe' => 0, 'routen' => [], 'warten' => null];
        $tag = self::tag($jetzt);
        $st = $db->prepare('SELECT COUNT(*) FROM anrufe WHERE tag = ?');
        $st->execute([$tag]);
        $out = ['anrufe' => (int)$st->fetchColumn(), 'routen' => [], 'warten' => null];
        $st = $db->prepare('SELECT r.route, COUNT(*) n FROM routen r JOIN anrufe a ON a.id = r.id WHERE a.tag = ? AND r.route NOT IN (\'ivr\', \'queue\') GROUP BY r.route ORDER BY n DESC LIMIT 2');
        $st->execute([$tag]);
        foreach ($st->fetchAll() as $z) if (isset(self::ROUTEN[$z['route']])) $out['routen'][self::ROUTEN[$z['route']]] = (int)$z['n'];
        $st = $db->prepare("SELECT AVG(sekunden) FROM warten WHERE tag = ? AND ergebnis = 'angenommen'");
        $st->execute([$tag]);
        $s = $st->fetchColumn();
        if ($s !== null && $s !== false) $out['warten'] = (int)round((float)$s);
        return $out;
    }

    public static function dauer(?int $s): string { return $s === null ? '–' : sprintf('%d:%02d', intdiv($s, 60), $s % 60); }
}
