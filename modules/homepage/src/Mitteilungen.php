<?php
declare(strict_types=1);
namespace KienzleHomepage;

/**
 * Mitteilungen für die Homepage. Jede hat eine Art, einen Schalter (an/aus), einen Zeitraum (leer = sofort bzw. ohne Ende) und
 * einen oder mehrere Orte auf der Homepage. Den Text für die Homepage setzt darstellung() zusammen; Vorschau und Plugin nutzen ihn.
 */
final class Mitteilungen {
    public const ARTEN = ['vertretung' => 'Vertretung', 'impfstoff' => 'Impfstoff lieferbar', 'oeffnungszeiten' => 'Sonderöffnungszeiten', 'frei' => 'Freier Text'];
    public const VORGABE_TITEL = ['vertretung' => 'Vertretung', 'impfstoff' => 'Impfstoff ist lieferbar', 'oeffnungszeiten' => 'Geänderte Öffnungszeiten', 'frei' => ''];
    public const ORTE = ['leiste' => 'Leiste oben (alle Seiten)', 'kasten' => 'Kasten auf der Startseite', 'fenster' => 'Fenster beim Öffnen', 'kurzcode' => 'Kurzcode-Stelle'];
    public const MAX = 50;
    public const MAX_TAGE = 31;
    private const WOCHENTAGE = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

    /** Heutiges Datum (Y-m-d, deutsche Zeit); in Tests überschreibbar. */
    public static ?string $heute = null;

    public static function heute(): string {
        return self::$heute ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    }

    /** @return list<array<string,mixed>> mit entpackten Listen (praxen, tage, orte) */
    public static function alle(): array {
        return array_map([self::class, 'entpacken'], Speicher::pdo()->query('SELECT * FROM mitteilungen ORDER BY CASE WHEN von = \'\' THEN 0 ELSE 1 END, von, id')->fetchAll());
    }

    public static function eine(int $id): ?array {
        $s = Speicher::pdo()->prepare('SELECT * FROM mitteilungen WHERE id = ?');
        $s->execute([$id]);
        $m = $s->fetch();
        return $m ? self::entpacken($m) : null;
    }

    private static function entpacken(array $m): array {
        $m['id'] = (int)$m['id'];
        $m['aktiv'] = (int)$m['aktiv'] === 1;
        foreach (['praxen', 'tage', 'orte'] as $f) $m[$f] = json_decode((string)$m[$f], true) ?: [];
        $m['praxen'] = array_map('intval', $m['praxen']);
        return $m;
    }

    /**
     * Formulardaten prüfen und vereinheitlichen. $streng = false (Vorschau beim Tippen): nichts ablehnen, nur säubern.
     * @return array{art:string,titel:string,text:string,praxen:list<int>,tage:list<array{0:string,1:string}>,von:string,bis:string,orte:list<string>}
     */
    public static function ausFormular(array $e, bool $streng = true): array {
        $fehler = static function (string $text) use ($streng): void { if ($streng) throw new \InvalidArgumentException($text); };
        $art = (string)($e['art'] ?? '');
        if (!isset(self::ARTEN[$art])) { $fehler('Bitte die Art der Mitteilung wählen.'); $art = 'frei'; }
        $titel = trim(preg_replace('/\s+/u', ' ', (string)($e['titel'] ?? '')) ?? '');
        if (mb_strlen($titel) > 120) { $fehler('Die Überschrift ist zu lang (höchstens 120 Zeichen).'); $titel = mb_substr($titel, 0, 120); }
        $text = trim(str_replace("\r", '', (string)($e['text'] ?? '')));
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        if (mb_strlen($text) > 1000) { $fehler('Der Text ist zu lang (höchstens 1000 Zeichen).'); $text = mb_substr($text, 0, 1000); }
        $von = self::datum((string)($e['von'] ?? ''), $fehler);
        $bis = self::datum((string)($e['bis'] ?? ''), $fehler);

        $praxen = [];
        if ($art === 'vertretung') {
            $bekannt = array_column(Praxen::alle(), 'id');
            foreach (is_array($e['praxen'] ?? null) ? $e['praxen'] : [] as $p) {
                if (in_array((int)$p, array_map('intval', $bekannt), true) && !in_array((int)$p, $praxen, true)) $praxen[] = (int)$p;
            }
            if (!$praxen) $fehler('Bitte mindestens eine vertretende Praxis ankreuzen.');
        }
        $tage = [];
        if ($art === 'oeffnungszeiten') {
            $daten = is_array($e['tage_datum'] ?? null) ? array_values($e['tage_datum']) : [];
            $zeiten = is_array($e['tage_zeit'] ?? null) ? array_values($e['tage_zeit']) : [];
            foreach ($daten as $i => $d) {
                $d = self::datum((string)$d, $fehler);
                $z = trim(preg_replace('/\s+/u', ' ', (string)($zeiten[$i] ?? '')) ?? '');
                if ($d === '' && $z === '') continue;
                if ($d === '') { $fehler('Bei „' . $z . '“ fehlt das Datum.'); continue; }
                if ($z === '') $z = 'geschlossen';
                if (mb_strlen($z) > 60) { $fehler('Angabe zu den Zeiten zu lang (höchstens 60 Zeichen).'); $z = mb_substr($z, 0, 60); }
                $tage[] = [$d, $z];
            }
            if (count($tage) > self::MAX_TAGE) { $fehler('Höchstens ' . self::MAX_TAGE . ' Tage.'); $tage = array_slice($tage, 0, self::MAX_TAGE); }
            usort($tage, fn($a, $b) => strcmp($a[0], $b[0]));
            if (!$tage && $text === '') $fehler('Bitte mindestens einen Tag mit Zeiten eintragen.');
            // Ohne Ende: bis zum letzten genannten Tag zeigen
            if ($bis === '' && $tage) $bis = $tage[count($tage) - 1][0];
        }
        if ($art === 'frei' && $titel === '' && $text === '') $fehler('Bitte eine Überschrift oder einen Text eintragen.');
        if ($von !== '' && $bis !== '' && $von > $bis) $fehler('„Bis“ liegt vor „Vom“.');
        $orte = [];
        foreach (is_array($e['orte'] ?? null) ? $e['orte'] : [] as $o) if (isset(self::ORTE[$o]) && !in_array($o, $orte, true)) $orte[] = (string)$o;
        if (!$orte) $fehler('Bitte wählen, wo die Mitteilung erscheinen soll.');
        return ['art' => $art, 'titel' => $titel, 'text' => $text, 'praxen' => $praxen, 'tage' => $tage, 'von' => $von, 'bis' => $bis, 'orte' => $orte];
    }

    private static function datum(string $w, callable $fehler): string {
        $w = trim($w);
        if ($w === '') return '';
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $w);
        if (!$d || $d->format('Y-m-d') !== $w || $w < '2000-01-01' || $w > '2099-12-31') { $fehler('Ungültiges Datum.'); return ''; }
        return $w;
    }

    /** Anlegen ($id = 0, dann eingeschaltet, wenn $aktiv) oder ändern; liefert die ID. */
    public static function speichern(int $id, array $e, string $wer, bool $aktiv = true): int {
        $m = self::ausFormular($e);
        $pdo = Speicher::pdo();
        $werte = [$m['art'], $m['titel'], $m['text'], json_encode($m['praxen']), json_encode($m['tage'], JSON_UNESCAPED_UNICODE), $m['von'], $m['bis'],
            json_encode($m['orte']), time(), mb_substr($wer, 0, 80)];
        if ($id > 0) {
            if (!self::eine($id)) throw new \InvalidArgumentException('Mitteilung nicht gefunden.');
            $pdo->prepare('UPDATE mitteilungen SET art = ?, titel = ?, text = ?, praxen = ?, tage = ?, von = ?, bis = ?, orte = ?, geaendert = ?, geaendert_von = ? WHERE id = ?')
                ->execute([...$werte, $id]);
            return $id;
        }
        if ((int)$pdo->query('SELECT COUNT(*) FROM mitteilungen')->fetchColumn() >= self::MAX) throw new \InvalidArgumentException('Höchstens ' . self::MAX . ' Mitteilungen. Bitte alte löschen.');
        $pdo->prepare('INSERT INTO mitteilungen (art, titel, text, praxen, tage, von, bis, orte, geaendert, geaendert_von, aktiv, erstellt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...$werte, $aktiv ? 1 : 0, time()]);
        return (int)$pdo->lastInsertId();
    }

    public static function schalten(int $id, bool $an, string $wer): void {
        if (!self::eine($id)) throw new \InvalidArgumentException('Mitteilung nicht gefunden.');
        Speicher::pdo()->prepare('UPDATE mitteilungen SET aktiv = ?, geaendert = ?, geaendert_von = ? WHERE id = ?')->execute([$an ? 1 : 0, time(), mb_substr($wer, 0, 80), $id]);
    }

    public static function loeschen(int $id): void {
        if (!self::eine($id)) throw new \InvalidArgumentException('Mitteilung nicht gefunden.');
        Speicher::pdo()->prepare('DELETE FROM mitteilungen WHERE id = ?')->execute([$id]);
    }

    /** aus | geplant | aktiv | abgelaufen (am Tag $datum, Vorgabe heute). */
    public static function zustand(array $m, ?string $datum = null): string {
        $d = $datum ?? self::heute();
        if ($m['bis'] !== '' && $m['bis'] < $d) return 'abgelaufen';
        if (!$m['aktiv']) return 'aus';
        if ($m['von'] !== '' && $m['von'] > $d) return 'geplant';
        return 'aktiv';
    }

    public static function titel(array $m): string {
        $t = $m['titel'] !== '' ? $m['titel'] : self::VORGABE_TITEL[$m['art']] ?? '';
        return $t !== '' ? $t : mb_substr(strtok($m['text'], "\n") ?: 'Mitteilung', 0, 60);
    }

    public static function datumDeutsch(string $d, bool $wochentag = false): string {
        $t = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        if (!$t) return $d;
        return ($wochentag ? self::WOCHENTAGE[(int)$t->format('w')] . ', ' : '') . $t->format('d.m.Y');
    }

    /** „vom … bis …“, „ab …“, „bis …“ oder leer. */
    public static function zeitraum(string $von, string $bis): string {
        if ($von !== '' && $bis !== '') return $von === $bis ? 'am ' . self::datumDeutsch($von) : 'vom ' . self::datumDeutsch($von) . ' bis ' . self::datumDeutsch($bis);
        if ($von !== '') return 'ab ' . self::datumDeutsch($von);
        if ($bis !== '') return 'bis ' . self::datumDeutsch($bis);
        return '';
    }

    /** Kurzer Zeitraum für Listen: „22.12. – 02.01.“, „ab 28.12.“, „ohne Ende“. */
    public static function zeitraumKurz(array $m): string {
        $k = static fn(string $d) => substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.';
        if ($m['von'] !== '' && $m['bis'] !== '') return $k($m['von']) . ' – ' . $k($m['bis']);
        if ($m['von'] !== '') return 'ab ' . $k($m['von']);
        if ($m['bis'] !== '') return 'bis ' . $k($m['bis']);
        return 'ohne Ende';
    }

    private static function aufzaehlung(array $teile): string {
        if (count($teile) <= 1) return (string)($teile[0] ?? '');
        $letzter = array_pop($teile);
        return implode(', ', $teile) . ' und ' . $letzter;
    }

    private static function satz(string $s): string { return $s === '' || preg_match('/[.!?:]$/u', $s) ? $s : $s . '.'; }

    /**
     * Fertige Texte für die Homepage: titel (Überschrift des Kastens/Fensters), kurz (eine Zeile für die Leiste), text, punkte.
     * @param array<int,array> $praxen Vertretungspraxen nach ID
     */
    public static function darstellung(array $m, array $praxen): array {
        $titel = self::titel($m);
        $punkte = [];
        $kurz = '';
        switch ($m['art']) {
            case 'vertretung':
                $liste = array_values(array_filter(array_map(fn($id) => $praxen[$id] ?? null, $m['praxen'])));
                $zr = self::zeitraum($m['von'], $m['bis']);
                $titel = trim($titel . ' ' . $zr);
                $punkte = array_map([Praxen::class, 'zeile'], $liste);
                $kurz = self::satz($titel . ($liste ? ': ' . self::aufzaehlung(array_map([Praxen::class, 'kurz'], $liste)) : ''));
                break;
            case 'oeffnungszeiten':
                $punkte = array_map(fn($t) => self::datumDeutsch($t[0], true) . ': ' . $t[1], $m['tage']);
                $kurz = self::satz($titel . ($punkte ? ': ' . implode('; ', $punkte) : ($m['text'] !== '' ? ': ' . strtok($m['text'], "\n") : '')));
                break;
            default:
                $erste = $m['text'] !== '' ? (string)strtok($m['text'], "\n") : '';
                $kurz = $m['titel'] === '' && $m['art'] === 'frei' ? $erste : trim(self::satz($titel) . ' ' . $erste);
        }
        return ['id' => 'm' . $m['id'], 'art' => $m['art'], 'titel' => $titel, 'kurz' => $kurz, 'text' => $m['text'], 'punkte' => $punkte,
            'von' => $m['von'], 'bis' => $m['bis'], 'orte' => $m['orte']];
    }

    /** @return array<int,array> */
    public static function praxenNachId(): array {
        $out = [];
        foreach (Praxen::alle() as $p) $out[(int)$p['id']] = $p;
        return $out;
    }

    /** Was an die Homepage geht: eingeschaltete, nicht abgelaufene Mitteilungen (auch geplante; das Plugin zeigt sie ab „Vom“). */
    public static function fuerHomepage(): array {
        $praxen = self::praxenNachId();
        $out = [];
        foreach (self::alle() as $m) if (in_array(self::zustand($m), ['aktiv', 'geplant'], true)) $out[] = self::darstellung($m, $praxen);
        return $out;
    }
}
