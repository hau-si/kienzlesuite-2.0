<?php
declare(strict_types=1);
namespace KienzleHomepage;

/**
 * Öffnungszeiten-Karte für die Homepage (Kurzcode [praxisherz_oeffnungszeiten]): reguläre Sprechzeiten, Feiertage, Schließungen und
 * Sonderöffnungen kommen aus Praxis → Öffnungszeiten der Basis (Verbindung „zeiten“), der ärztliche Bereitschaftsdienst wird hier gepflegt:
 * jede Woche gleich (Montag … Sonntag), an Feiertagen abweichend oder an einzelnen Tagen (ersetzt dann die Regeln dieses Tages).
 * Ob gerade geöffnet ist, rechnet das Skript im Browser des Besuchers (oeffnung.js), damit es auch bei Seitencache stimmt.
 */
final class Oeffnung {
    public const TAGE = ['mo' => 'Montag', 'di' => 'Dienstag', 'mi' => 'Mittwoch', 'do' => 'Donnerstag', 'fr' => 'Freitag', 'sa' => 'Samstag', 'so' => 'Sonntag'];
    public const KURZ = ['mo' => 'Mo', 'di' => 'Di', 'mi' => 'Mi', 'do' => 'Do', 'fr' => 'Fr', 'sa' => 'Sa', 'so' => 'So'];
    public const MAX_DIENSTE = 100;
    public const NOTDIENST = 'Ärztlicher Bereitschaftsdienst: 116 117';
    /** So weit im Voraus gehen Schließungen, Feiertage und Dienste an einzelnen Tagen an die Homepage. */
    public const TAGE_VORAUS = 400;

    public static function wannText(string $wann, string $datum = ''): string {
        if (isset(self::TAGE[$wann])) return 'jeden ' . self::TAGE[$wann];
        if ($wann === 'feiertag') return 'an Feiertagen';
        return $datum !== '' ? Mitteilungen::datumDeutsch($datum, true) : 'an einem Datum';
    }

    /** @return list<array> nach Wochentag (Feiertag, dann Daten) und Beginn sortiert */
    public static function dienste(): array {
        $reihe = array_flip([...array_keys(self::TAGE), 'feiertag', 'datum']);
        $l = Speicher::pdo()->query('SELECT * FROM dienste')->fetchAll();
        foreach ($l as &$d) { $d['id'] = (int)$d['id']; $d['praxis'] = (int)$d['praxis']; }
        unset($d);
        usort($l, fn($a, $b) => [$reihe[$a['wann']] ?? 99, $a['datum'], $a['von'], $a['id']] <=> [$reihe[$b['wann']] ?? 99, $b['datum'], $b['von'], $b['id']]);
        return $l;
    }

    public static function dienst(int $id): ?array {
        foreach (self::dienste() as $d) if ($d['id'] === $id) return $d;
        return null;
    }

    private static function uhrzeit(string $w, string $was): string {
        $w = trim(str_replace('.', ':', $w));
        if (preg_match('/^(\d{1,2})$/D', $w)) $w .= ':00';
        if (!preg_match('/^(\d{1,2}):([0-5]\d)$/D', $w, $m) || (int)$m[1] > 24 || ((int)$m[1] === 24 && $m[2] !== '00')) throw new \InvalidArgumentException($was . ': Uhrzeit wie 08:00 eingeben.');
        return sprintf('%02d:%s', (int)$m[1], $m[2]);
    }

    /** Anlegen ($id = 0) oder ändern; „bis“ vor „von“ = über Nacht bis zum Folgetag. */
    public static function dienstSpeichern(int $id, array $e): int {
        $wann = (string)($e['wann'] ?? '');
        if (!isset(self::TAGE[$wann]) && !in_array($wann, ['feiertag', 'datum'], true)) throw new \InvalidArgumentException('Bitte wählen, wann der Dienst ist.');
        $datum = '';
        if ($wann === 'datum') {
            $datum = trim((string)($e['datum'] ?? ''));
            $t = \DateTimeImmutable::createFromFormat('!Y-m-d', $datum);
            if (!$t || $t->format('Y-m-d') !== $datum || $datum < '2000-01-01' || $datum > '2099-12-31') throw new \InvalidArgumentException('Bitte das Datum des Dienstes eintragen.');
        }
        $von = self::uhrzeit((string)($e['von'] ?? ''), 'Beginn');
        $bis = self::uhrzeit((string)($e['bis'] ?? ''), 'Ende');
        if ($von === $bis) throw new \InvalidArgumentException('Beginn und Ende sind gleich.');
        $praxis = (int)($e['praxis'] ?? 0);
        if (!Praxen::eine($praxis)) throw new \InvalidArgumentException('Bitte die Praxis wählen (Liste unter „Vertretungspraxen“).');
        $pdo = Speicher::pdo();
        if ($id > 0) {
            if (!self::dienst($id)) throw new \InvalidArgumentException('Eintrag nicht gefunden.');
            $pdo->prepare('UPDATE dienste SET wann = ?, datum = ?, von = ?, bis = ?, praxis = ? WHERE id = ?')->execute([$wann, $datum, $von, $bis, $praxis, $id]);
            return $id;
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM dienste')->fetchColumn() >= self::MAX_DIENSTE) throw new \InvalidArgumentException('Höchstens ' . self::MAX_DIENSTE . ' Einträge. Bitte alte löschen.');
        $pdo->prepare('INSERT INTO dienste (wann, datum, von, bis, praxis, erstellt) VALUES (?, ?, ?, ?, ?, ?)')->execute([$wann, $datum, $von, $bis, $praxis, time()]);
        return (int)$pdo->lastInsertId();
    }

    public static function dienstLoeschen(int $id): void {
        if (!self::dienst($id)) throw new \InvalidArgumentException('Eintrag nicht gefunden.');
        Speicher::pdo()->prepare('DELETE FROM dienste WHERE id = ?')->execute([$id]);
    }

    /** Vergangene Dienste an einzelnen Tagen aufräumen (Haken update). */
    public static function aufraeumen(): void {
        Speicher::pdo()->prepare('DELETE FROM dienste WHERE wann = \'datum\' AND datum < ?')->execute([self::plus(Mitteilungen::heute(), -7)]);
    }

    public static function notdienst(): string {
        $w = Speicher::wert('notdienst', self::NOTDIENST);
        return $w;
    }

    public static function notdienstSpeichern(string $text): void {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($text) > 160) throw new \InvalidArgumentException('Hinweis zum Bereitschaftsdienst: höchstens 160 Zeichen.');
        Speicher::setzen('notdienst', $text);
    }

    public static function plus(string $datum, int $tage): string {
        return (new \DateTimeImmutable($datum . ' 12:00'))->modify(($tage >= 0 ? '+' : '') . $tage . ' days')->format('Y-m-d');
    }

    /** „08:00-12:00“ → ['08:00', '12:00'] (ungültige Angaben fallen weg). */
    private static function fenster(array $liste): array {
        $out = [];
        foreach ($liste as $f) if (is_string($f) && preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/D', $f, $m)) $out[] = [$m[1], $m[2]];
        return $out;
    }

    /** Wochenzeiten aus der Basis (Montag … Sonntag) oder null, wenn die Basis keine Öffnungszeiten liefert. */
    public static function woche(?\KienzleModul $k): ?array {
        $p = Zeiten::paket($k);
        if (!$p || !is_array($p['woche']['sprech'] ?? null)) return null;
        $w = [];
        foreach (self::TAGE as $t => $_) $w[$t] = self::fenster(is_array($p['woche']['sprech'][$t] ?? null) ? $p['woche']['sprech'][$t] : []);
        return $w;
    }

    /**
     * Wochentabelle: gleiche Zeiten zusammengefasst („Mo, Di, Do“), in der Reihenfolge des ersten Tages.
     * @return list<array{tage:list<string>, label:string, text:string}>
     */
    public static function gruppen(array $woche): array {
        $gruppen = [];
        foreach (self::TAGE as $t => $_) {
            $z = $woche[$t] ?? [];
            $text = $z ? implode(', ', array_map(fn($f) => $f[0] . '–' . $f[1], $z)) . ' Uhr' : 'geschlossen';
            $gruppen[$text][] = $t;
        }
        $out = [];
        foreach ($gruppen as $text => $tage) $out[] = ['tage' => $tage, 'label' => implode(', ', array_map(fn($t) => self::KURZ[$t], $tage)), 'text' => $text];
        return $out;
    }

    private static function praxisDaten(?array $p): ?array {
        return $p ? ['name' => $p['name'], 'strasse' => $p['strasse'], 'ort' => $p['ort'], 'telefon' => $p['telefon'], 'zusatz' => $p['zusatz']] : null;
    }

    /**
     * Alles für die Karte auf der Homepage; null ohne Sprechzeiten aus der Basis und ohne Dienste.
     * tage: Abweichungen je Datum (Schließung, Feiertag, Sonderöffnung) mit Grund und Vertretung; dienste: Regeln und einzelne Tage.
     */
    public static function fuerHomepage(?\KienzleModul $k): ?array {
        $woche = self::woche($k);
        $dienste = self::dienste();
        if ($woche === null && !$dienste) return null;
        $heute = Mitteilungen::heute();
        $ende = self::plus($heute, self::TAGE_VORAUS);
        $tage = [];
        foreach ((Zeiten::paket($k)['ereignisse'] ?? []) as $e) {
            if (!is_array($e) || !is_string($e['von'] ?? null) || !is_string($e['bis'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $e['von']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $e['bis'])) continue;
            $sonder = ($e['art'] ?? '') === 'sonder';
            $zeiten = $sonder ? self::fenster(is_array($e['zeiten'] ?? null) ? $e['zeiten'] : []) : [];
            $grund = (string)($e['titel'] ?? '');
            for ($d = max($e['von'], self::plus($heute, -1)); $d <= $e['bis'] && $d <= $ende; $d = self::plus($d, 1)) {
                if (isset($tage[$d]) && !$sonder) continue;   // Schließung zuerst; ein Feiertag darin ändert nichts
                $vertretung = [];
                foreach (is_array($e['vertretung'] ?? null) ? $e['vertretung'] : [] as $v) {
                    if (!is_array($v) || ($v['von'] ?? '') > $d || ($v['bis'] ?? '') < $d) continue;
                    foreach (is_array($v['praxen'] ?? null) ? $v['praxen'] : [] as $p) {
                        if (is_array($p)) $vertretung[] = ['name' => (string)($p['name'] ?? ''), 'strasse' => (string)($p['strasse'] ?? ''), 'ort' => (string)($p['ort'] ?? ''),
                            'telefon' => (string)($p['telefon'] ?? ''), 'zusatz' => (string)($p['zusatz'] ?? '')];
                    }
                }
                $tage[$d] = ['zeiten' => $zeiten, 'grund' => $grund, 'feiertag' => ($e['art'] ?? '') === 'feiertag', 'vertretung' => $vertretung];
            }
        }
        ksort($tage);
        $praxen = Mitteilungen::praxenNachId();
        $regeln = ['woche' => [], 'feiertag' => [], 'tage' => []];
        foreach ($dienste as $d) {
            $p = self::praxisDaten($praxen[$d['praxis']] ?? null);
            if (!$p) continue;
            $eintrag = ['von' => $d['von'], 'bis' => $d['bis'], 'praxis' => $p];
            if (isset(self::TAGE[$d['wann']])) $regeln['woche'][] = ['tag' => $d['wann']] + $eintrag;
            elseif ($d['wann'] === 'feiertag') $regeln['feiertag'][] = $eintrag;
            elseif ($d['datum'] >= self::plus($heute, -1) && $d['datum'] <= $ende) $regeln['tage'][$d['datum']][] = $eintrag;
        }
        ksort($regeln['tage']);
        return ['woche' => $woche ?? array_fill_keys(array_keys(self::TAGE), []), 'tage' => (object)$tage, 'dienste' => ['woche' => $regeln['woche'], 'feiertag' => $regeln['feiertag'],
            'tage' => (object)$regeln['tage']], 'notdienst' => self::notdienst()];
    }
}
