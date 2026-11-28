<?php
declare(strict_types=1);
namespace Kienzle\Core;

use Kienzle\Core\View as V;

/**
 * Oberfläche „Praxis → Öffnungszeiten“ (Entwurf B mit dem Feiertagsteil aus A, Wahl des Nutzers am 28.11.2026):
 * links Monatskalender und „Als Nächstes“, rechts Bearbeiten der gewählten Schließung mit Vertretung je Zeitraum,
 * „Wo erscheint es?“ und „So hört es der Anrufer“. Regelmäßige Zeiten, Feiertage und Vertretungspraxen über Knöpfe oben.
 * Nur Ausgabe; Prüfen und Speichern in Zeiten, Routen in Web::zeiten().
 */
final class ZeitenSeite {
    private const PFAD = '/verwaltung/zeiten';
    private const MONATE = [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    /** @param array<string,string> $nutzer Modul-ID → Name der Module, die die Zeiten bekommen */
    /** @param ?bool $verteilt true = Module haben den aktuellen Stand, false = Verteilung läuft, null = unbekannt (kein Dienst) */
    public function __construct(private Zeiten $z, private string $csrf, private array $nutzer = [], private ?bool $verteilt = null) {}

    private function form(string $aktion, string $inhalt, string $klasse = ''): string {
        return '<form method="post" action="' . self::PFAD . '"' . ($klasse !== '' ? ' class="' . $klasse . '"' : '') . '>' . V::csrf($this->csrf)
            . '<input type="hidden" name="aktion" value="' . V::h($aktion) . '">' . $inhalt . '</form>';
    }

    private static function knopf(string $href, string $text, bool $an = false): string {
        return '<a class="button' . ($an ? '' : ' secondary') . '" href="' . V::h($href) . '">' . V::h($text) . '</a>';
    }

    /** Kopf mit den Knöpfen und dem Stand der Verteilung. */
    private function kopf(string $ansicht): string {
        $k = '<div class="zt-kopf">' . self::knopf(self::PFAD, 'Kalender', $ansicht === '') . self::knopf(self::PFAD . '?ansicht=woche', 'Regelmäßige Zeiten', $ansicht === 'woche')
            . self::knopf(self::PFAD . '?ansicht=feiertage', 'Feiertage', $ansicht === 'feiertage') . self::knopf(self::PFAD . '?ansicht=praxen', 'Vertretungspraxen', $ansicht === 'praxen')
            . self::knopf(self::PFAD . '?neu=1', '+ Schließung', false) . '</div>';
        $stand = '';
        if ($this->nutzer) {
            $stand = '<p class="muted small">Die Angaben gehen automatisch an: ' . V::h(implode(', ', $this->nutzer)) . '. '
                . ($this->verteilt === true ? '<span class="tag ok">✓ verteilt</span>' : ($this->verteilt === false ? '<span class="tag warn">wird verteilt …</span>' : '')) . '</p>';
        } else {
            $stand = '<p class="muted small">Noch nutzt kein installiertes Modul die Öffnungszeiten (z. B. Homepage oder Telefon).</p>';
        }
        return $k . $stand;
    }

    // ---- Kalender ---------------------------------------------------------------------------------------------------

    /** @return array<string,string> Datum → Klasse (u = geschlossen, f = Feiertag, s = Sonderöffnung) */
    private function tage(int $jahr): array {
        $out = [];
        foreach ($this->z->feiertagsliste($jahr) as $t) if ($t['aktiv']) $out[$t['datum']] = 'f';
        foreach ($this->z->ereignisse() as $e) {
            for ($d = $e['von']; $d <= $e['bis']; $d = Zeiten::plus($d, 1)) if (!isset($out[$d]) || $e['art'] !== 'sonder') $out[$d] = $e['art'] === 'sonder' ? 's' : 'u';
        }
        return $out;
    }

    private function kalender(string $monat, ?array $gewaehlt): string {
        [$j, $m] = array_map('intval', explode('-', $monat));
        $erster = new \DateTimeImmutable(sprintf('%04d-%02d-01', $j, $m));
        $vor = $erster->modify('-1 month')->format('Y-m'); $nach = $erster->modify('+1 month')->format('Y-m');
        $tage = $this->tage($j);
        $heute = Zeiten::heute();
        $o = '<section class="card"><div class="zt-monat"><a class="button secondary small" href="' . self::PFAD . '?monat=' . $vor . '" aria-label="Voriger Monat">‹</a>'
            . '<h2>' . self::MONATE[$m] . ' ' . $j . '</h2><a class="button secondary small" href="' . self::PFAD . '?monat=' . $nach . '" aria-label="Nächster Monat">›</a></div><div class="zt-kal">';
        foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $t) $o .= '<span class="h">' . $t . '</span>';
        $o .= str_repeat('<span class="leer"></span>', (int)$erster->format('N') - 1);
        for ($t = 1; $t <= (int)$erster->format('t'); $t++) {
            $d = sprintf('%04d-%02d-%02d', $j, $m, $t);
            $k = ['t'];
            if (isset($tage[$d])) $k[] = $tage[$d];
            if ((int)(new \DateTimeImmutable($d))->format('N') >= 6) $k[] = 'we';
            if ($d === $heute) $k[] = 'heute';
            if ($gewaehlt && $d >= $gewaehlt['von'] && $d <= $gewaehlt['bis']) $k[] = 'sel';
            $o .= '<a class="' . implode(' ', $k) . '" href="' . self::PFAD . '?monat=' . $monat . '&amp;tag=' . $d . '" title="' . Zeiten::lang($d) . '">' . $t . '</a>';
        }
        $o .= '</div><p class="zt-leg"><span><i class="u"></i>geschlossen</span><span><i class="f"></i>Feiertag</span><span><i class="s"></i>Sonderöffnung</span></p>'
            . '<p class="muted small">Tag anklicken: vorhandenen Eintrag öffnen oder neue Schließung für diesen Tag.</p></section>';
        return $o;
    }

    private static function artTag(string $art): string {
        return match ($art) {
            'feiertag' => '<span class="tag warn">Feiertag</span>', 'sonder' => '<span class="tag zt-sonder">Sonderöffnung</span>',
            default => '<span class="tag zt-zu">' . V::h(Zeiten::ARTEN[$art] ?? $art) . '</span>',
        };
    }

    private function naechste(): string {
        $p = $this->z->paket();
        $o = '<section class="card"><h2>Als Nächstes</h2>';
        if (!$p['ereignisse']) return $o . '<p class="muted">Keine Schließungen eingetragen.' . ($this->z->feiertage()['land'] === '' ? ' Für Feiertage unter „Feiertage“ das Bundesland wählen.' : '') . '</p></section>';
        $o .= '<ul class="zt-liste">';
        foreach (array_slice($p['ereignisse'], 0, 8) as $e) {
            $href = $e['art'] === 'feiertag' ? self::PFAD . '?ansicht=feiertage' : self::PFAD . '?e=' . rawurlencode($e['id']);
            $o .= '<li>' . self::artTag($e['art']) . ' <a href="' . V::h($href) . '">' . V::h($e['titel']) . '</a> <span class="muted">' . V::h(Zeiten::spanne($e['von'], $e['bis'])) . '</span></li>';
        }
        return $o . '</ul></section>';
    }

    // ---- Bearbeiten -------------------------------------------------------------------------------------------------

    private function praxisAuswahl(string $name, string $wert): string {
        $o = '<select name="' . $name . '"><option value="">– keine –</option>';
        foreach ($this->z->praxen() as $p) $o .= '<option value="' . V::h($p['id']) . '"' . ($p['id'] === $wert ? ' selected' : '') . '>' . V::h($p['name']) . '</option>';
        return $o . '</select>';
    }

    /** Formular für eine Schließung/Sonderöffnung. $e = gespeicherter Eintrag oder Vorgabe für einen neuen. */
    private function bearbeiten(array $e, bool $neu): string {
        $praxen = [];
        foreach ($this->z->praxen() as $p) $praxen[$p['id']] = $p;
        $art = '<label>Art<select name="art">';
        foreach (Zeiten::ARTEN as $k => $v) $art .= '<option value="' . $k . '"' . ($e['art'] === $k ? ' selected' : '') . '>' . V::h($v) . '</option>';
        $art .= '</select></label>';
        $zeilen = [];
        foreach ($e['vertretung'] as $v) foreach ($v['praxen'] as $pid) $zeilen[] = [$v['von'], $v['bis'], $pid];
        $zeilen[] = ['', '', ''];
        if (count($zeilen) === 1) $zeilen[] = ['', '', ''];   // neu: zwei leere Zeilen, sonst eine
        $vt = '<div class="zt-vt">';
        foreach ($zeilen as $i => [$a, $b, $pid]) {
            $vt .= '<div class="zt-vzeile"><label class="zt-vp">Vertretungspraxis ' . ($i + 1) . $this->praxisAuswahl('v_praxis[]', $pid) . '</label>'
                . '<label>von<input type="date" name="v_von[]" value="' . V::h($a) . '"></label><label>bis<input type="date" name="v_bis[]" value="' . V::h($b) . '"></label></div>';
        }
        $vt .= '</div><p class="muted small">Von/Bis leer = ganzer Zeitraum. Für verschiedene Vertretungen je Tag einfach mehrere Zeilen ausfüllen; nach dem Speichern kommt eine neue leere Zeile dazu. '
            . (count($praxen) ? '' : '<strong>Noch keine Vertretungspraxis angelegt.</strong> ') . '<a href="' . self::PFAD . '?ansicht=praxen">Liste der Vertretungspraxen</a></p>';
        $hp = isset($this->nutzer['homepage']); $tel = isset($this->nutzer['telefon']);
        $wo = '<h3>Wo erscheint es?</h3>'
            . V::checkbox('homepage', '1', 'Homepage (Mitteilung; Ort, Farbe und Symbol stellt man im Modul Homepage ein)' . ($hp ? '' : ' – Modul nicht installiert'), $e['homepage'])
            . V::checkbox('telefon', '1', 'Telefonansage statt Begrüßung' . ($e['art'] === 'sonder' ? '' : ', Anrufer werden nicht durchgestellt') . ($tel ? '' : ' – Modul nicht installiert'), $e['telefon']);
        $titel = $neu ? 'Neue Schließung' : Zeiten::titel($e);
        $o = '<section class="card"><div class="zt-titel"><h2>' . V::h($titel) . '</h2>' . ($neu ? '' : self::artTag($e['art'])) . '</div>'
            . $this->form('ereignis', ($neu ? '' : '<input type="hidden" name="id" value="' . V::h($e['id']) . '">')
                . '<div class="zt-reihe">' . $art . V::input('von', 'Von', 'date', $e['von'], 'required') . V::input('bis', 'Bis', 'date', $e['bis']) . '</div>'
                . V::input('titel', 'Bezeichnung (optional, z. B. Weihnachtsurlaub)', 'text', $e['titel'], 'maxlength="80"')
                . V::input('zeiten', 'Geöffnet (nur bei Sonderöffnung, z. B. 08:00-12:00)', 'text', $e['zeiten'], 'maxlength="60"')
                . '<h3>Vertretung</h3>' . $vt
                . V::input('hinweis', 'Zusätzlicher Hinweis für Homepage und Ansage (optional)', 'text', $e['hinweis'], 'maxlength="200"')
                . $wo . '<p><button>Speichern</button></p>');
        if (!$neu) {
            $o .= '<h3>So hört es der Anrufer</h3><blockquote class="zt-ansage">„' . V::h($this->z->ansage($e, $praxen)) . '“</blockquote>'
                . '<h3>So steht es auf der Homepage</h3><p><strong>' . V::h(Zeiten::titel($e)) . ':</strong> ' . V::h(Zeiten::satz($e)) . '</p>'
                . $this->form('ereignis-loeschen', '<input type="hidden" name="id" value="' . V::h($e['id']) . '"><button class="danger">Eintrag löschen</button>', 'inline');
        }
        return $o . '</section>';
    }

    public function kalenderSeite(array $q, ?string $fehler, ?array $eingabe): string {
        $heute = Zeiten::heute();
        $gewaehlt = null; $neu = false;
        $tag = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string)($q['tag'] ?? '')) ? (string)$q['tag'] : '';
        if (($q['e'] ?? '') !== '') $gewaehlt = $this->z->ereignis((string)$q['e']);
        elseif ($tag !== '') foreach ($this->z->ereignisse() as $e) if ($tag >= $e['von'] && $tag <= $e['bis']) { $gewaehlt = $e; break; }
        if (!$gewaehlt && ($tag !== '' || ($q['neu'] ?? '') === '1')) $neu = true;
        if (!$gewaehlt && !$neu) foreach ($this->z->ereignisse() as $e) if ($e['bis'] >= $heute) { $gewaehlt = $e; break; }
        if (!$gewaehlt) $neu = true;
        $leer = ['id' => '', 'art' => 'urlaub', 'titel' => '', 'von' => $tag !== '' ? $tag : $heute, 'bis' => $tag !== '' ? $tag : '', 'zeiten' => '', 'hinweis' => '',
            'homepage' => true, 'telefon' => true, 'vertretung' => []];
        $e = $eingabe ?? ($neu ? $leer : $gewaehlt);
        $monat = preg_match('/^\d{4}-\d{2}$/D', (string)($q['monat'] ?? '')) ? (string)$q['monat'] : substr($tag !== '' ? $tag : ($gewaehlt['von'] ?? $heute), 0, 7);
        return $this->kopf('') . V::error($fehler) . '<div class="zt-grid"><div>' . $this->kalender($monat, $neu ? null : $gewaehlt) . $this->naechste() . '</div><div>'
            . $this->bearbeiten($e, $neu || ($eingabe !== null && ($eingabe['id'] ?? '') === '')) . '</div></div>';
    }

    // ---- Regelmäßige Zeiten -----------------------------------------------------------------------------------------

    public function wocheSeite(?string $fehler, ?array $eingabe): string {
        $w = $eingabe ?? $this->z->woche();
        $t = '<table class="zt-tab"><tr><th>Tag</th><th>Sprechzeiten</th><th>Telefonzeiten</th></tr>';
        foreach (Zeiten::TAGE as $k => $name) {
            $t .= '<tr><th scope="row">' . V::h($name) . '</th><td><input name="sprech[' . $k . ']" value="' . V::h($w['sprech'][$k] ?? '') . '" placeholder="geschlossen" aria-label="Sprechzeiten ' . V::h($name) . '"></td>'
                . '<td><input name="telefon[' . $k . ']" value="' . V::h($w['telefon'][$k] ?? '') . '" placeholder="wie Sprechzeiten" aria-label="Telefonzeiten ' . V::h($name) . '"></td></tr>';
        }
        $t .= '</table>';
        return $this->kopf('woche') . V::error($fehler) . '<section class="card"><h2>Regelmäßige Zeiten</h2><p class="muted">Mehrere Zeiträume mit Komma trennen, z. B. 08:00-12:00, 14:00-18:00. '
            . 'Sprechzeiten leer = geschlossen. Telefonzeiten leer = wie Sprechzeiten; außerhalb der Telefonzeiten nimmt das Sprachmenü nur Anliegen auf.</p>'
            . $this->form('woche', $t . '<p><button>Speichern</button></p>') . '</section>';
    }

    // ---- Feiertage (Teil aus Entwurf A) -----------------------------------------------------------------------------

    public function feiertageSeite(array $q, ?string $fehler): string {
        $f = $this->z->feiertage();
        $jahrJetzt = (int)substr(Zeiten::heute(), 0, 4);
        $jahr = (int)($q['jahr'] ?? $jahrJetzt);
        if ($jahr < $jahrJetzt || $jahr > $jahrJetzt + Zeiten::JAHRE - 1) $jahr = $jahrJetzt;
        $land = '<label>Bundesland<select name="land"><option value="">– bitte wählen –</option>';
        foreach (Feiertage::LAENDER as $k => $v) $land .= '<option value="' . $k . '"' . ($f['land'] === $k ? ' selected' : '') . '>' . V::h($v) . '</option>';
        $land .= '</select></label>';
        $jahre = '<p class="zt-jahre">';
        for ($j = $jahrJetzt; $j < $jahrJetzt + Zeiten::JAHRE; $j++) $jahre .= self::knopf(self::PFAD . '?ansicht=feiertage&jahr=' . $j, (string)$j, $j === $jahr) . ' ';
        $jahre .= '</p>';
        $liste = '';
        if ($f['land'] !== '') {
            $liste = '<ul class="zt-feiertage">';
            foreach ($this->z->feiertagsliste($jahr) as $t) {
                $liste .= '<li class="' . ($t['aktiv'] ? '' : 'aus') . '"><input type="hidden" name="gezeigt[]" value="' . V::h($t['id']) . '"><label class="check"><input type="checkbox" name="haken[]" value="' . V::h($t['id']) . '"'
                    . ($t['aktiv'] ? ' checked' : '') . '> <span class="d">' . Zeiten::lang($t['datum']) . '</span> <span class="n">' . V::h($t['name']) . '</span>'
                    . ($t['hinweis'] !== '' ? ' <small class="muted">(' . V::h($t['hinweis']) . ')</small>' : '') . '</label></li>';
            }
            $liste .= '</ul>';
        }
        $optionen = V::checkbox('f_telefon', '1', 'An Feiertagen Ansage am Telefon (statt Begrüßung, mit Bereitschaftsdienst 116 117)', $f['telefon'])
            . V::checkbox('f_homepage', '1', 'Feiertage auf der Homepage ankündigen', $f['homepage']);
        return $this->kopf('feiertage') . V::error($fehler) . '<section class="card"><h2>Feiertage</h2><p class="muted">Haken = Praxis an diesem Tag geschlossen. Die Wahl gilt für jedes Jahr '
            . '(z. B. Rosenmontag immer zu). Gesetzliche Feiertage sind vorgewählt; regionale Feiertage, Brückentage, Heiligabend und Silvester bitte selbst anhaken. '
            . 'Liegt ein Feiertag in einem Urlaub, gilt die Ansage des Urlaubs.</p>' . $jahre
            . $this->form('feiertage', '<input type="hidden" name="jahr" value="' . $jahr . '">' . $land . ($f['land'] === '' ? '<p class="muted">Nach dem Speichern erscheinen die Feiertage des Landes.</p>' : '')
                . $liste . $optionen . '<p><button>Speichern</button></p>') . '</section>';
    }

    // ---- Vertretungspraxen ------------------------------------------------------------------------------------------

    public function praxenSeite(array $q, ?string $fehler, ?array $eingabe): string {
        $bearb = ($q['p'] ?? '') !== '' ? $this->z->praxis((string)$q['p']) : null;
        $p = $eingabe ?? $bearb ?? ['id' => '', 'name' => '', 'strasse' => '', 'ort' => '', 'telefon' => '', 'zusatz' => ''];
        $neu = ($p['id'] ?? '') === '';
        $o = $this->kopf('praxen') . V::error($fehler) . '<div class="zt-grid"><div><section class="card"><h2>Vertretungspraxen</h2>';
        $alle = $this->z->praxen();
        if (!$alle) $o .= '<p class="muted">Noch keine Praxis angelegt.</p>';
        else {
            $o .= '<ul class="zt-liste">';
            foreach ($alle as $x) $o .= '<li><a href="' . self::PFAD . '?ansicht=praxen&amp;p=' . rawurlencode($x['id']) . '">' . V::h($x['name']) . '</a> <span class="muted">' . V::h(implode(', ', array_filter([$x['ort'], $x['telefon']]))) . '</span></li>';
            $o .= '</ul>';
        }
        $o .= '<p><a class="button secondary" href="' . self::PFAD . '?ansicht=praxen">+ Praxis anlegen</a></p></section></div><div><section class="card"><h2>' . ($neu ? 'Praxis anlegen' : V::h($p['name'])) . '</h2>'
            . $this->form('praxis', ($neu ? '' : '<input type="hidden" name="id" value="' . V::h($p['id']) . '">')
                . V::input('name', 'Name', 'text', $p['name'], 'maxlength="100" required placeholder="Praxis Dr. med. Erika Beispiel"')
                . V::input('strasse', 'Straße und Hausnummer', 'text', $p['strasse'], 'maxlength="80"') . V::input('ort', 'PLZ und Ort', 'text', $p['ort'], 'maxlength="80"')
                . V::input('telefon', 'Telefon (wird angesagt)', 'tel', $p['telefon'], 'maxlength="40"')
                . V::input('zusatz', 'Zusatz für die Homepage (z. B. Sprechzeiten)', 'text', $p['zusatz'], 'maxlength="120"') . '<p><button>Speichern</button></p>');
        if (!$neu) $o .= $this->form('praxis-loeschen', '<input type="hidden" name="id" value="' . V::h($p['id']) . '"><button class="danger">Praxis löschen</button>', 'inline');
        return $o . '</section></div></div>';
    }
}
