<?php
declare(strict_types=1);
namespace KienzleHomepage;

/**
 * Automatische Mitteilungen aus Praxis → Öffnungszeiten der Basis (Verbindung „zeiten“): Schließungen, Sonderöffnungen und
 * Feiertage mit Haken „Homepage“ erscheinen von selbst, eine einstellbare Zahl von Tagen vorher bis zum letzten Tag.
 * Ort, Farbe und Symbol stellt man hier ein (wie bei den eigenen Mitteilungen). Gepflegt wird nur in der Basis.
 */
final class Zeiten {
    public const ORTE_VORGABE = ['leiste', 'kasten'];
    /** Farbe und Symbol, wenn „Passend zur Art“ gewählt ist. */
    private const ART = ['zu' => ['rot', 'urlaub'], 'feiertag' => ['ocker', 'kalender'], 'sonder' => ['ocker', 'uhr']];

    /** Paket der Basis lesen (null = Basis zu alt, Modul ohne Verbindung oder noch nichts gespeichert). Tests setzen $paket. */
    public static ?array $paket = null;

    public static function paket(?\KienzleModul $k): ?array {
        if (self::$paket !== null) return self::$paket;
        $p = $k?->verbindung('zeiten');
        return is_array($p) && ($p['format'] ?? 0) === 1 && is_array($p['ereignisse'] ?? null) ? $p : null;
    }

    /** @return array{aktiv:bool, orte:list<string>, farbe:string, symbol:string, vorlauf:int, feiertage:int} */
    public static function einstellung(): array {
        $d = json_decode(Speicher::wert('zeiten'), true);
        $d = is_array($d) ? $d : [];
        $orte = array_values(array_intersect(array_keys(Mitteilungen::ORTE), (array)($d['orte'] ?? self::ORTE_VORGABE)));
        return ['aktiv' => ($d['aktiv'] ?? true) === true, 'orte' => $orte ?: self::ORTE_VORGABE,
            'farbe' => isset(Mitteilungen::FARBEN[$d['farbe'] ?? '']) ? $d['farbe'] : 'standard', 'symbol' => isset(Mitteilungen::SYMBOLE[$d['symbol'] ?? '']) ? (string)$d['symbol'] : 'standard',
            'vorlauf' => max(0, min(60, (int)($d['vorlauf'] ?? 14))), 'feiertage' => max(0, min(14, (int)($d['feiertage'] ?? 3)))];
    }

    public static function speichern(array $e): void {
        $orte = array_values(array_intersect(array_keys(Mitteilungen::ORTE), array_map('strval', (array)($e['orte'] ?? []))));
        if (!$orte) throw new \InvalidArgumentException('Bitte mindestens einen Ort wählen.');
        $farbe = (string)($e['farbe'] ?? 'standard'); $symbol = (string)($e['symbol'] ?? 'standard');
        if (!isset(Mitteilungen::FARBEN[$farbe]) || ($symbol !== 'standard' && !isset(Mitteilungen::SYMBOLE[$symbol]))) throw new \InvalidArgumentException('Farbe oder Symbol unbekannt.');
        foreach (['vorlauf' => 60, 'feiertage' => 14] as $f => $max) {
            if (!preg_match('/^\d{1,2}$/D', (string)($e[$f] ?? '')) || (int)$e[$f] > $max) throw new \InvalidArgumentException('Tage vorher: 0 bis ' . $max . '.');
        }
        Speicher::setzen('zeiten', (string)json_encode(['aktiv' => ($e['aktiv'] ?? '') === '1', 'orte' => $orte, 'farbe' => $farbe, 'symbol' => $symbol,
            'vorlauf' => (int)$e['vorlauf'], 'feiertage' => (int)$e['feiertage']]));
    }

    private static function kurz(string $d): string { return substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.'; }

    private static function spanne(string $von, string $bis): string { return $von === $bis ? self::kurz($von) : self::kurz($von) . '–' . self::kurz($bis); }

    /** Eine Zeile je Vertretungspraxis (mit Zeitraum, wenn es mehrere Zeiträume gibt). */
    private static function punkte(array $e): array {
        $out = [];
        $mehrere = count($e['vertretung']) > 1 || ($e['vertretung'] && ($e['vertretung'][0]['von'] !== $e['von'] || $e['vertretung'][0]['bis'] !== $e['bis']));
        foreach ($e['vertretung'] as $v) foreach ($v['praxen'] as $p) {
            $zeile = implode(', ', array_filter([(string)($p['name'] ?? ''), (string)($p['strasse'] ?? ''), (string)($p['ort'] ?? ''),
                ($p['telefon'] ?? '') !== '' ? 'Tel. ' . $p['telefon'] : '', (string)($p['zusatz'] ?? '')]));
            $out[] = ($mehrere ? self::spanne($v['von'], $v['bis']) . ': ' : '') . $zeile;
        }
        return $out;
    }

    /**
     * Darstellungen wie Mitteilungen::darstellung (für Vorschau und Übertragung): nur Einträge mit Haken „Homepage“,
     * sichtbar ab „vorlauf“ (Feiertage: „feiertage“) Tage vor Beginn bis zum letzten Tag.
     */
    public static function hinweise(?\KienzleModul $k): array {
        $p = self::paket($k);
        $s = self::einstellung();
        if (!$p || !$s['aktiv']) return [];
        $out = [];
        foreach ($p['ereignisse'] as $e) {
            if (!is_array($e) || ($e['homepage'] ?? false) !== true || !is_string($e['von'] ?? null) || !is_string($e['bis'] ?? null)) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $e['von']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $e['bis'])) continue;
            $e += ['vertretung' => [], 'satz' => '', 'hinweis' => '', 'titel' => ''];
            $gruppe = $e['art'] === 'feiertag' ? 'feiertag' : ($e['art'] === 'sonder' ? 'sonder' : 'zu');
            $vor = $gruppe === 'feiertag' ? $s['feiertage'] : $s['vorlauf'];
            $ab = (new \DateTimeImmutable($e['von'] . ' 12:00'))->modify('-' . $vor . ' days')->format('Y-m-d');
            $punkte = self::punkte($e);
            $kurz = rtrim((string)$e['satz'], '.') . ($punkte ? ' Vertretung: ' . implode('; ', array_map(fn($x) => preg_replace('/, Tel\..*$/', '', $x) ?? $x, $punkte)) : '') . '.';
            // Das Plugin nimmt nur „m“ + Ziffern; feste Nummer ab 900000000 aus der Kennung (eigene Mitteilungen zählen von 1 an).
            $out[] = ['id' => 'm' . (900000000 + crc32((string)$e['id']) % 100000000), 'art' => $gruppe === 'sonder' ? 'oeffnungszeiten' : ($punkte ? 'vertretung' : 'frei'),
                'titel' => (string)$e['titel'], 'kurz' => $kurz, 'text' => trim($e['satz'] . ($e['hinweis'] !== '' ? "\n" . $e['hinweis'] : '')),
                'punkte' => $punkte, 'von' => $ab, 'bis' => $e['bis'], 'orte' => $s['orte'],
                'farbe' => $s['farbe'] !== 'standard' ? $s['farbe'] : self::ART[$gruppe][0], 'symbol' => $s['symbol'] !== 'standard' ? $s['symbol'] : self::ART[$gruppe][1],
                'automatisch' => true, 'beginn' => $e['von']];
        }
        return $out;
    }

    /** Für die Übertragung ohne interne Felder. */
    public static function fuerHomepage(?\KienzleModul $k): array {
        return array_map(fn($d) => array_diff_key($d, ['automatisch' => 1, 'beginn' => 1]), self::hinweise($k));
    }

    /**
     * Haken „zeiten“ (Dienst kienzle-homepage-zeiten, angestoßen über die Verbindungsdatei der Basis): übertragen, wenn sich der Stand
     * geändert hat und die Homepage eingerichtet ist. Liefert eine kurze Meldung (ohne Inhalte).
     */
    public static function abgleich(\KienzleModul $k): string {
        $p = self::paket($k);
        $stand = $p ? (string)($p['stand'] ?? '') : '';
        if ($stand === Speicher::wert('zeiten.stand')) return 'unverändert';
        if (!Homepage::eingerichtet()) { Speicher::setzen('zeiten.stand', $stand); return 'Homepage nicht eingerichtet'; }
        $s = Homepage::nachAenderung($k);
        if ($s && $s['ok']) Speicher::setzen('zeiten.stand', $stand);
        return $s && $s['ok'] ? 'übertragen' : 'Übertragung fehlgeschlagen';
    }
}
