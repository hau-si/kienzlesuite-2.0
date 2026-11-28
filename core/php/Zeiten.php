<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Öffnungszeiten der Praxis (Praxis → Öffnungszeiten): regelmäßige Sprech- und Telefonzeiten, Schließungen (Urlaub, Fortbildung,
 * geschlossen) und Sonderöffnungen mit Vertretung je Zeitraum, Feiertage je Bundesland mit eigener Auswahl und die Liste der
 * Vertretungspraxen. Gespeichert in settings (JSON); an die Module geht eine ausgerechnete Fassung (paket()) über die zentrale
 * Verbindung „zeiten“ (root-Dienst kienzle-verbindungen). Keine Patientendaten.
 */
final class Zeiten {
    public const TAGE = ['mo' => 'Montag', 'di' => 'Dienstag', 'mi' => 'Mittwoch', 'do' => 'Donnerstag', 'fr' => 'Freitag', 'sa' => 'Samstag', 'so' => 'Sonntag'];
    public const ARTEN = ['urlaub' => 'Urlaub', 'fortbildung' => 'Fortbildung', 'geschlossen' => 'Geschlossen', 'sonder' => 'Sonderöffnung'];
    public const MAX_EREIGNISSE = 60;
    public const MAX_PRAXEN = 30;
    public const MAX_ABSCHNITTE = 8;
    /** Feiertage gehen für dieses und die nächsten zwei Jahre an die Module (Ansagen auch dann, wenn lange niemand die Seite öffnet). */
    public const JAHRE = 3;
    private const MONATE = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    private const ZIFFERN = ['null', 'eins', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun'];

    /** Heutiges Datum (Y-m-d, deutsche Zeit); in Tests überschreibbar. */
    public static ?string $heute = null;

    public function __construct(private Database $db) {}

    public static function heute(): string { return self::$heute ?? (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d'); }

    private function lesen(string $key, array $vorgabe): array {
        $d = json_decode((string)$this->db->setting('zeiten.' . $key), true);
        return is_array($d) ? $d : $vorgabe;
    }

    private function schreiben(string $key, array $d): void {
        $this->db->store('zeiten.' . $key, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    // ---- Lesen ------------------------------------------------------------------------------------------------------

    /** @return array{sprech: array<string,string>, telefon: array<string,string>} Zeiten je Tag als Text („08:00-12:00, 14:00-18:00“), leer = geschlossen bzw. wie Sprechzeiten */
    public function woche(): array {
        $d = $this->lesen('woche', []);
        $out = ['sprech' => [], 'telefon' => []];
        foreach (['sprech', 'telefon'] as $s) foreach (self::TAGE as $t => $_) $out[$s][$t] = is_string($d[$s][$t] ?? null) ? $d[$s][$t] : '';
        return $out;
    }

    /** @return list<array> Vertretungspraxen (id, name, strasse, ort, telefon, zusatz), nach Name */
    public function praxen(): array {
        $out = array_values(array_filter($this->lesen('praxen', []), 'is_array'));
        usort($out, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
        return $out;
    }

    public function praxis(string $id): ?array {
        foreach ($this->praxen() as $p) if ($p['id'] === $id) return $p;
        return null;
    }

    /** @return list<array> Schließungen und Sonderöffnungen, nach Beginn */
    public function ereignisse(): array {
        $out = array_values(array_filter($this->lesen('ereignisse', []), 'is_array'));
        usort($out, fn($a, $b) => [$a['von'], $a['bis']] <=> [$b['von'], $b['bis']]);
        return $out;
    }

    public function ereignis(string $id): ?array {
        foreach ($this->ereignisse() as $e) if ($e['id'] === $id) return $e;
        return null;
    }

    /** @return array{land:string, an:list<string>, aus:list<string>, telefon:bool, homepage:bool} */
    public function feiertage(): array {
        $d = $this->lesen('feiertage', []);
        return ['land' => is_string($d['land'] ?? null) && isset(Feiertage::LAENDER[$d['land']]) ? $d['land'] : '',
            'an' => array_values(array_filter((array)($d['an'] ?? []), 'is_string')), 'aus' => array_values(array_filter((array)($d['aus'] ?? []), 'is_string')),
            'telefon' => ($d['telefon'] ?? true) === true, 'homepage' => ($d['homepage'] ?? true) === true];
    }

    /** Feiertage eines Jahres mit Feld „aktiv“ (geschlossen). */
    public function feiertagsliste(int $jahr): array {
        $f = $this->feiertage();
        return array_map(fn($t) => $t + ['aktiv' => Feiertage::aktiv($t, $f['an'], $f['aus'])], Feiertage::liste($f['land'], $jahr));
    }

    // ---- Prüfen und Speichern ---------------------------------------------------------------------------------------

    private static function text(mixed $v, string $was, int $max, bool $pflicht = false): string {
        $v = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
        if ($pflicht && $v === '') throw new Problem($was . ': bitte ausfüllen.', 422);
        if (mb_strlen($v) > $max) throw new Problem($was . ': höchstens ' . $max . ' Zeichen.', 422);
        if (preg_match('/[\x00-\x1f\x7f<>]/u', $v)) throw new Problem($was . ': unerlaubte Zeichen.', 422);
        return $v;
    }

    /** Datum aus „2026-12-23“ oder „23.12.2026“ als Y-m-d. */
    public static function datum(mixed $v, string $was): string {
        $v = trim((string)$v);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/D', $v, $m)) $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) {
            throw new Problem($was . ': bitte ein Datum wie 23.12.2026 eingeben.', 422);
        }
        return $v;
    }

    /**
     * Zeitangabe „08:00-12:00, 14:00-18:00“ prüfen und einheitlich schreiben (Bindestrich, zweistellig). Leer = geschlossen.
     * @return list<string>
     */
    public static function fenster(string $text, string $was): array {
        $out = [];
        $letztes = -1;
        foreach (preg_split('/[,;]+/', $text) ?: [] as $teil) {
            $teil = str_replace(['–', '—', ' ', 'Uhr', 'bis'], ['-', '-', '', '', '-'], trim($teil));
            if ($teil === '') continue;
            if (!preg_match('/^(\d{1,2})(?:[:.](\d{2}))?-(\d{1,2})(?:[:.](\d{2}))?$/D', $teil, $m)) throw new Problem($was . ': bitte so eingeben: 08:00-12:00, 14:00-18:00.', 422);
            $a = (int)$m[1] * 60 + (int)($m[2] ?? 0); $b = (int)$m[3] * 60 + (int)($m[4] ?? 0);
            if ((int)$m[1] > 23 || (int)$m[3] > 24 || (int)($m[2] ?? 0) > 59 || (int)($m[4] ?? 0) > 59 || $b > 1440 || $a >= $b) throw new Problem($was . ': Uhrzeit ungültig (' . $teil . ').', 422);
            if ($a < $letztes) throw new Problem($was . ': Zeiträume bitte der Reihe nach und ohne Überschneidung.', 422);
            $letztes = $b;
            $out[] = sprintf('%02d:%02d-%02d:%02d', intdiv($a, 60), $a % 60, intdiv($b, 60), $b % 60);
        }
        if (count($out) > 4) throw new Problem($was . ': höchstens vier Zeiträume.', 422);
        return $out;
    }

    public function wocheSpeichern(array $sprech, array $telefon): void {
        $d = ['sprech' => [], 'telefon' => []];
        foreach (self::TAGE as $t => $name) {
            $d['sprech'][$t] = implode(', ', self::fenster((string)($sprech[$t] ?? ''), 'Sprechzeiten ' . $name));
            $d['telefon'][$t] = implode(', ', self::fenster((string)($telefon[$t] ?? ''), 'Telefonzeiten ' . $name));
        }
        $this->schreiben('woche', $d);
    }

    public function praxisSpeichern(array $in): string {
        $id = (string)($in['id'] ?? '');
        $alle = $this->praxen();
        if ($id !== '' && !$this->praxis($id)) throw new Problem('Praxis nicht gefunden.', 404);
        if ($id === '' && count($alle) >= self::MAX_PRAXEN) throw new Problem('Höchstens ' . self::MAX_PRAXEN . ' Vertretungspraxen.', 422);
        $p = ['id' => $id !== '' ? $id : bin2hex(random_bytes(8)), 'name' => self::text($in['name'] ?? '', 'Name', 100, true), 'strasse' => self::text($in['strasse'] ?? '', 'Straße', 80),
            'ort' => self::text($in['ort'] ?? '', 'PLZ und Ort', 80), 'telefon' => self::text($in['telefon'] ?? '', 'Telefon', 40), 'zusatz' => self::text($in['zusatz'] ?? '', 'Zusatz', 120)];
        if ($p['telefon'] !== '' && !preg_match('#^[0-9+ /()-]{3,40}$#D', $p['telefon'])) throw new Problem('Telefon: nur Ziffern, Leerzeichen, +, /, ( ) und -.', 422);
        $this->schreiben('praxen', array_values(array_merge(array_filter($alle, fn($x) => $x['id'] !== $p['id']), [$p])));
        return $p['id'];
    }

    public function praxisLoeschen(string $id): void {
        foreach ($this->ereignisse() as $e) {
            if ($e['bis'] < self::heute()) continue;
            foreach ($e['vertretung'] as $v) if (in_array($id, $v['praxen'], true)) throw new Problem('Die Praxis steht noch als Vertretung bei „' . $e['titel'] . '“. Bitte dort zuerst entfernen.', 409);
        }
        $this->schreiben('praxen', array_values(array_filter($this->praxen(), fn($x) => $x['id'] !== $id)));
    }

    /**
     * Schließung oder Sonderöffnung speichern. Eingabe wie das Formular: art, titel, von, bis, zeiten (Sonderöffnung), hinweis,
     * homepage, telefon, v_von[], v_bis[], v_praxis[] (je Zeile eine Praxis; gleiche Zeiträume werden zusammengefasst).
     */
    public function ereignisSpeichern(array $in): string {
        $id = (string)($in['id'] ?? '');
        if ($id !== '' && !$this->ereignis($id)) throw new Problem('Eintrag nicht gefunden.', 404);
        $art = (string)($in['art'] ?? '');
        if (!isset(self::ARTEN[$art])) throw new Problem('Art: bitte wählen.', 422);
        $von = self::datum($in['von'] ?? '', 'Von');
        $bis = trim((string)($in['bis'] ?? '')) === '' ? $von : self::datum($in['bis'], 'Bis');
        if ($bis < $von) throw new Problem('„Bis“ liegt vor „Von“.', 422);
        if ((new \DateTimeImmutable($von))->diff(new \DateTimeImmutable($bis))->days > 120) throw new Problem('Höchstens 120 Tage am Stück.', 422);
        $e = ['id' => $id !== '' ? $id : bin2hex(random_bytes(8)), 'art' => $art, 'titel' => self::text($in['titel'] ?? '', 'Bezeichnung', 80), 'von' => $von, 'bis' => $bis,
            'zeiten' => $art === 'sonder' ? implode(', ', self::fenster((string)($in['zeiten'] ?? ''), 'Geöffnet')) : '',
            'hinweis' => self::text($in['hinweis'] ?? '', 'Zusätzlicher Hinweis', 200), 'homepage' => ($in['homepage'] ?? '') === '1', 'telefon' => ($in['telefon'] ?? '') === '1',
            'vertretung' => []];
        if ($art === 'sonder' && $e['zeiten'] === '') throw new Problem('Sonderöffnung: bitte die Zeiten eintragen.', 422);
        $abschnitte = [];
        $vv = (array)($in['v_von'] ?? []); $vb = (array)($in['v_bis'] ?? []); $vp = (array)($in['v_praxis'] ?? []);
        foreach ($vp as $i => $pid) {
            $pid = (string)$pid;
            if ($pid === '') continue;
            if (!$this->praxis($pid)) throw new Problem('Vertretung: Praxis nicht gefunden.', 422);
            $a = trim((string)($vv[$i] ?? '')) === '' ? $von : self::datum($vv[$i], 'Vertretung von');
            $b = trim((string)($vb[$i] ?? '')) === '' ? $bis : self::datum($vb[$i], 'Vertretung bis');
            if ($a < $von || $b > $bis || $b < $a) throw new Problem('Vertretung: Zeitraum muss innerhalb von ' . self::kurz($von) . ' bis ' . self::kurz($bis) . ' liegen.', 422);
            $k = $a . '|' . $b;
            $abschnitte[$k] ??= ['von' => $a, 'bis' => $b, 'praxen' => []];
            if (!in_array($pid, $abschnitte[$k]['praxen'], true)) $abschnitte[$k]['praxen'][] = $pid;
        }
        if (count($abschnitte) > self::MAX_ABSCHNITTE) throw new Problem('Höchstens ' . self::MAX_ABSCHNITTE . ' Vertretungszeiträume.', 422);
        ksort($abschnitte);
        $e['vertretung'] = array_values($abschnitte);
        $alle = array_values(array_filter($this->ereignisse(), fn($x) => $x['id'] !== $e['id'] && $x['bis'] >= self::plus(self::heute(), -60)));   // alte Einträge nach 60 Tagen weg
        if (count($alle) >= self::MAX_EREIGNISSE) throw new Problem('Höchstens ' . self::MAX_EREIGNISSE . ' Einträge.', 422);
        $alle[] = $e;
        $this->schreiben('ereignisse', $alle);
        return $e['id'];
    }

    public function ereignisLoeschen(string $id): void {
        if (!$this->ereignis($id)) throw new Problem('Eintrag nicht gefunden.', 404);
        $this->schreiben('ereignisse', array_values(array_filter($this->ereignisse(), fn($x) => $x['id'] !== $id)));
    }

    /** Bundesland und Auswahl speichern. $haken = Kennungen, die im angezeigten Jahr angehakt sind; $gezeigt = alle angezeigten Kennungen. */
    public function feiertageSpeichern(string $land, array $gezeigt, array $haken, bool $telefon, bool $homepage): void {
        if ($land !== '' && !isset(Feiertage::LAENDER[$land])) throw new Problem('Bundesland: bitte wählen.', 422);
        $f = $this->feiertage();
        $an = $f['an']; $aus = $f['aus'];
        if ($land === $f['land']) {
            $jahr = (int)substr(self::heute(), 0, 4);
            $vorgaben = [];
            foreach (array_merge(Feiertage::liste($land, $jahr), Feiertage::liste($land, $jahr + 1)) as $t) $vorgaben[$t['id']] = $t['art'] === 'gesetzlich';
            foreach ($gezeigt as $id) {
                $id = (string)$id;
                if (!isset($vorgaben[$id])) continue;
                $an = array_values(array_diff($an, [$id]));
                $aus = array_values(array_diff($aus, [$id]));
                $gewaehlt = in_array($id, array_map('strval', $haken), true);
                if ($gewaehlt && !$vorgaben[$id]) $an[] = $id;
                if (!$gewaehlt && $vorgaben[$id]) $aus[] = $id;
            }
        } else {
            $an = []; $aus = [];   // anderes Land: Vorgaben des Landes
        }
        $this->schreiben('feiertage', ['land' => $land, 'an' => array_values(array_unique($an)), 'aus' => array_values(array_unique($aus)), 'telefon' => $telefon, 'homepage' => $homepage]);
    }

    // ---- Texte ------------------------------------------------------------------------------------------------------

    public static function plus(string $datum, int $tage): string {
        return (new \DateTimeImmutable($datum . ' 12:00'))->modify(($tage >= 0 ? '+' : '') . $tage . ' days')->format('Y-m-d');
    }

    /** „23.12.“ */
    public static function kurz(string $d): string { return substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.'; }

    /** „23.12.2026“ */
    public static function lang(string $d): string { return substr($d, 8, 2) . '.' . substr($d, 5, 2) . '.' . substr($d, 0, 4); }

    /** Zeitraum kurz: „23.12. – 03.01.“ oder „05.02.“ */
    public static function spanne(string $von, string $bis): string { return $von === $bis ? self::kurz($von) : self::kurz($von) . ' – ' . self::kurz($bis); }

    /** Gesprochenes Datum: „23. Dezember“, mit Wochentag „Montag, den 5. Februar“. */
    public static function gesprochen(string $d, bool $wochentag = false): string {
        $t = new \DateTimeImmutable($d . ' 12:00');
        $s = (int)$t->format('j') . '. ' . self::MONATE[(int)$t->format('n')];
        return $wochentag ? array_values(self::TAGE)[(int)$t->format('N') - 1] . ', den ' . $s : $s;
    }

    /** Telefonnummer zum Vorlesen: Ziffern einzeln, Gruppen durch Komma („0 1 2 3 4, 5 6 7 8 9“). */
    public static function nummerSprechen(string $nr): string {
        $gruppen = preg_split('#[\s/()-]+#', trim(str_replace('+49', '0', $nr))) ?: [];
        $out = [];
        foreach ($gruppen as $g) if (($g = preg_replace('/\D/', '', $g)) !== '') $out[] = implode(' ', str_split($g));
        return implode(', ', $out);
    }

    private static function praxisText(array $p, bool $sprechen): string {
        if ($sprechen) return $p['name'] . ($p['telefon'] !== '' ? ', Telefon ' . self::nummerSprechen($p['telefon']) : '');
        return implode(', ', array_filter([$p['name'], $p['strasse'], $p['ort'], $p['telefon'] !== '' ? 'Tel. ' . $p['telefon'] : '', $p['zusatz']]));
    }

    /** Überschrift für Homepage und Listen. */
    public static function titel(array $e): string {
        if (($e['titel'] ?? '') !== '') return $e['titel'];
        return match ($e['art']) { 'sonder' => 'Geänderte Öffnungszeiten', 'feiertag' => 'Feiertag', default => 'Praxis geschlossen' };
    }

    /** Ein Satz für die Homepage, z. B. „Die Praxis ist vom 23.12. bis 03.01. geschlossen.“ */
    public static function satz(array $e): string {
        $wann = $e['von'] === $e['bis'] ? 'am ' . self::kurz($e['von']) : 'vom ' . self::kurz($e['von']) . ' bis ' . self::kurz($e['bis']);
        if ($e['art'] === 'sonder') return 'Die Praxis ist ' . $wann . ' nur ' . str_replace('-', '–', $e['zeiten']) . ' Uhr geöffnet.';
        if ($e['art'] === 'feiertag') return 'Die Praxis ist am ' . self::kurz($e['von']) . ' (' . $e['titel'] . ') geschlossen.';
        return 'Die Praxis ist ' . $wann . ' geschlossen.';
    }

    /** Ansage für das Telefon (Sprachausgabe): Schließung, Vertretung je Zeitraum, Bereitschaftsdienst. */
    public function ansage(array $e, array $praxen): string {
        $s = 'Liebe Patientinnen und Patienten, ';
        if ($e['art'] === 'feiertag') $s .= 'am ' . $e['titel'] . ', ' . self::gesprochen($e['von']) . ', ist unsere Praxis geschlossen.';
        elseif ($e['art'] === 'sonder') $s .= ($e['von'] === $e['bis'] ? 'am ' . self::gesprochen($e['von'], true) : 'vom ' . self::gesprochen($e['von']) . ' bis ' . self::gesprochen($e['bis']))
            . ' ist unsere Praxis nur von ' . self::uhrSprechen($e['zeiten']) . ' geöffnet.';
        else $s .= 'unsere Praxis ist ' . ($e['von'] === $e['bis'] ? 'am ' . self::gesprochen($e['von'], true) : 'vom ' . self::gesprochen($e['von']) . ' bis ' . self::gesprochen($e['bis'])) . ' geschlossen.';
        foreach ($e['vertretung'] ?? [] as $v) {
            $namen = array_values(array_filter(array_map(fn($id) => $praxen[$id] ?? null, $v['praxen'])));
            if (!$namen) continue;
            $ganz = $v['von'] === $e['von'] && $v['bis'] === $e['bis'];
            $wann = $ganz ? 'In dieser Zeit' : ($v['von'] === $v['bis'] ? 'Am ' . self::gesprochen($v['von']) : 'Vom ' . self::gesprochen($v['von']) . ' bis ' . self::gesprochen($v['bis']));
            $s .= ' ' . $wann . ' vertritt uns ' . implode(' oder ', array_map(fn($p) => self::praxisText($p, true), $namen)) . '.';
        }
        if (($e['hinweis'] ?? '') !== '') $s .= ' ' . rtrim($e['hinweis'], '.') . '.';
        if ($e['art'] !== 'sonder') $s .= ' In dringenden Fällen erreichen Sie den ärztlichen Bereitschaftsdienst unter 1 1 6, 1 1 7. Bei Lebensgefahr wählen Sie 1 1 2.';
        return $s;
    }

    private static function uhrSprechen(string $zeiten): string {
        $teile = [];
        foreach (array_filter(array_map('trim', explode(',', $zeiten))) as $f) {
            [$a, $b] = explode('-', $f);
            $teile[] = self::uhr($a) . ' bis ' . self::uhr($b);
        }
        return implode(' und von ', $teile);
    }

    private static function uhr(string $hm): string {
        [$h, $m] = array_map('intval', explode(':', $hm));
        return $h . ' Uhr' . ($m ? ' ' . $m : '');
    }

    // ---- Paket für die Module ---------------------------------------------------------------------------------------

    /**
     * Alles, was die Module brauchen (Verbindung „zeiten“): Wochenzeiten als Listen (Telefonzeiten leer = wie Sprechzeiten),
     * kommende Schließungen/Sonderöffnungen und aktive Feiertage der nächsten Jahre mit fertigen Texten, Vertretungspraxen ausgeschrieben.
     */
    public function paket(string $praxisName = ''): array {
        $w = $this->woche();
        $woche = ['sprech' => [], 'telefon' => []];
        foreach (self::TAGE as $t => $_) {
            $woche['sprech'][$t] = self::fenster($w['sprech'][$t], 'Sprechzeiten');
            $woche['telefon'][$t] = $w['telefon'][$t] !== '' ? self::fenster($w['telefon'][$t], 'Telefonzeiten') : $woche['sprech'][$t];
        }
        $praxen = [];
        foreach ($this->praxen() as $p) $praxen[$p['id']] = $p;
        $heute = self::heute();
        $liste = [];
        $geschlossen = [];   // Tage, die eine Schließung schon abdeckt (Feiertag darin nicht doppelt ansagen)
        foreach ($this->ereignisse() as $e) {
            if ($e['bis'] < $heute) continue;
            if ($e['art'] !== 'sonder') for ($d = $e['von']; $d <= $e['bis']; $d = self::plus($d, 1)) $geschlossen[$d] = true;
            $liste[] = $e;
        }
        $f = $this->feiertage();
        $jahr = (int)substr($heute, 0, 4);
        if ($f['land'] !== '') {
            for ($j = $jahr; $j < $jahr + self::JAHRE; $j++) foreach ($this->feiertagsliste($j) as $t) {
                if (!$t['aktiv'] || $t['datum'] < $heute || isset($geschlossen[$t['datum']])) continue;
                $liste[] = ['id' => 'f-' . $t['id'] . '-' . $j, 'art' => 'feiertag', 'titel' => $t['name'], 'von' => $t['datum'], 'bis' => $t['datum'], 'zeiten' => '', 'hinweis' => '',
                    'homepage' => $f['homepage'], 'telefon' => $f['telefon'], 'vertretung' => []];
            }
        }
        usort($liste, fn($a, $b) => [$a['von'], $a['id']] <=> [$b['von'], $b['id']]);
        $out = [];
        foreach ($liste as $e) {
            $out[] = ['id' => $e['id'], 'art' => $e['art'], 'titel' => self::titel($e), 'von' => $e['von'], 'bis' => $e['bis'],
                'zeiten' => $e['zeiten'] !== '' ? explode(', ', $e['zeiten']) : [], 'homepage' => $e['homepage'], 'telefon' => $e['telefon'],
                'satz' => self::satz($e), 'hinweis' => $e['hinweis'], 'ansage' => $this->ansage($e, $praxen),
                'vertretung' => array_map(fn($v) => ['von' => $v['von'], 'bis' => $v['bis'], 'praxen' => array_values(array_map(
                    fn($id) => array_intersect_key($praxen[$id], array_flip(['name', 'strasse', 'ort', 'telefon', 'zusatz'])), array_filter($v['praxen'], fn($id) => isset($praxen[$id]))))], $e['vertretung'])];
        }
        $d = ['format' => 1, 'praxis' => $praxisName, 'woche' => $woche, 'ereignisse' => $out];
        $d['stand'] = substr(hash('sha256', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 32);
        return $d;
    }

    /** Paket an den root-Dienst geben. Liefert false, wenn gerade eine andere Änderung übernommen wird (später erneut, siehe ausstehend()). */
    public function verteilen(Connections $c, string $praxisName = ''): bool {
        $p = $this->paket($praxisName);
        try {
            $c->saveZeiten($p);
        } catch (Problem $e) {
            if ($e->http !== 409 && $e->http !== 503) throw $e;
            $this->db->store('zeiten.ausstehend', '1');
            return false;
        }
        $this->db->store('zeiten.ausstehend', '');
        $this->db->store('zeiten.verteilt', $p['stand'] . '|' . self::heute());
        return true;
    }

    /** Muss neu verteilt werden? (Änderung wartet oder seit dem letzten Verteilen ist ein Tag vergangen – vergangene Einträge fallen heraus.) */
    public function ausstehend(): bool {
        if ($this->db->setting('zeiten.ausstehend') === '1') return true;
        $v = (string)$this->db->setting('zeiten.verteilt');
        return $v !== '' && !str_ends_with($v, '|' . self::heute());
    }
}
