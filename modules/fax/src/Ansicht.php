<?php
declare(strict_types=1);
namespace KienzleFax;

/** HTML der Seiten des Moduls Fax (Rahmen, Karten und Knöpfe kommen aus core.css). */
final class Ansicht {
    public const SEITEN = ['/' => 'Fax senden', '/eingang' => 'Faxeingang', '/protokoll' => 'Sendeprotokoll', '/fehler' => 'Fehlerberichte',
        '/telefonbuch' => 'Telefonbuch', '/einstellungen' => 'Einstellungen'];

    public static function kopf(string $base): string {
        $v = static fn(string $f): string => '?v=' . substr((string)@md5_file(dirname(__DIR__) . '/public/assets/' . $f), 0, 8);
        return '<link rel="stylesheet" href="' . fx_h($base) . '/assets/style.css' . $v('style.css') . '"><script src="' . fx_h($base) . '/assets/app.js' . $v('app.js') . '" defer></script>';
    }

    private static function zeit(int $t): string { return $t > 0 ? date('d.m.Y H:i', $t) : '–'; }

    private static function groesse(int $b): string { return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : max(1, (int)round($b / 1024)) . ' KB'; }

    private static function tag(string $art, string $text): string { return '<span class="tag ' . $art . '">' . fx_h($text) . '</span>'; }

    private static function csrf(string $csrf): string { return '<input type="hidden" name="kienzle-csrf" value="' . fx_h($csrf) . '">'; }

    /** Seitenkopf mit Unterseiten; Zähler für ungelesene Faxe und Fehlerberichte. */
    private static function kopfzeile(string $route, array $rechte, int $ungelesen, int $fehler): string {
        $b = fx_h(fx_basis());
        $links = '';
        foreach (self::SEITEN as $r => $t) {
            if (!self::darf($r, $rechte)) continue;
            $zahl = $r === '/eingang' && $ungelesen > 0 ? ' <span class="fx-zahl">' . $ungelesen . '</span>' : ($r === '/fehler' && $fehler > 0 ? ' <span class="fx-zahl fehler-zahl">' . $fehler . '</span>' : '');
            $links .= '<a class="button ' . ($route === $r ? 'primary' : 'secondary') . ' small" href="' . $b . $r . '"' . ($route === $r ? ' aria-current="page"' : '') . '>' . fx_h($t) . $zahl . '</a>';
        }
        return '<header class="seitenkopf"><div><p class="eyebrow">Fax</p><h1>' . fx_h(self::SEITEN[$route]) . '</h1></div><nav class="start-tools" aria-label="Fax">' . $links . '</nav></header>';
    }

    public static function darf(string $route, array $r): bool {
        return match ($route) {
            '/', '/protokoll', '/fehler' => $r['senden'], '/eingang' => $r['eingang'], '/telefonbuch' => $r['telefonbuch'] || $r['senden'],
            '/einstellungen' => $r['admin'], default => false,
        };
    }

    public static function seite(string $route, array $d): string {
        $o = '<div class="fx">' . self::kopfzeile($route, $d['rechte'], $d['ungelesen'], $d['fehlerzahl']);
        if ($d['meldung']) $o .= '<p class="ok" role="status">' . fx_h($d['meldung']) . '</p>';
        if ($d['fehler']) $o .= '<p class="fehler" role="alert">' . fx_h($d['fehler']) . '</p>';
        if (!$d['telefon'] && in_array($route, ['/', '/eingang'], true)) {
            $o .= '<p class="fehler">Das Modul <strong>Telefon</strong> fehlt oder ist noch nicht verbunden. Ohne es kann die Suite keine Faxe senden oder empfangen (Verwaltung → Module).</p>';
        }
        return $o . match ($route) {
            '/' => self::senden($d), '/eingang' => self::eingang($d), '/protokoll' => self::berichte($d, 'protokoll'), '/fehler' => self::berichte($d, 'fehler'),
            '/telefonbuch' => self::telefonbuch($d), '/einstellungen' => self::einstellungen($d),
        } . '</div>';
    }

    public static function aktiveListe(array $aktive, string $csrf): string {
        if (!$aktive) return '<p class="muted">Keine laufenden Aufträge.</p>';
        $o = '<ul class="fx-aktiv">';
        foreach ($aktive as $a) {
            $stand = match (true) {
                $a['abbruch'] => 'wird abgebrochen …',
                $a['wo'] === 'queue' && $a['naechster'] !== '' => 'wartet auf neuen Versuch (' . date('H:i', strtotime($a['naechster']) ?: time()) . ')',
                $a['wo'] === 'queue' => 'wartet',
                $a['seite'] !== null && $a['seiten'] !== null => 'sendet Seite ' . (int)$a['seite'] . ' von ' . (int)$a['seiten'],
                default => 'wählt …',
            };
            $versuch = $a['versuch'] > 0 ? ' · Versuch ' . $a['versuch'] . ($a['max'] > 0 ? ' von ' . $a['max'] : '') : '';
            $o .= '<li><div><strong>' . fx_h($a['name']) . '</strong> <span class="muted">' . fx_h(Fax::anzeige($a['nummer'])) . '</span><div class="muted klein">' . fx_h($a['datei'])
                . ' · ' . fx_h($stand . $versuch) . '</div></div>' . ($a['abbruch'] ? '' : '<form method="post" data-confirm="Diesen Faxauftrag abbrechen?">' . self::csrf($csrf)
                . '<input type="hidden" name="aktion" value="abbrechen"><input type="hidden" name="id" value="' . fx_h($a['id']) . '"><button class="small danger" type="submit">Abbrechen</button></form>') . '</li>';
        }
        return $o . '</ul>';
    }

    private static function senden(array $d): string {
        $b = fx_h(fx_basis());
        $q = $d['quelle'];
        $tabs = '';
        foreach ([...Fax::DRUCKER, 'sendefehler'] as $name) {
            $z = $d['zaehler'][$name] ?? 0;
            $tabs .= '<a class="button small ' . ($name === $q ? 'primary' : 'secondary') . '" href="' . $b . '/?quelle=' . fx_h($name) . '">' . fx_h($name === 'sendefehler' ? 'Sendefehler' : $name)
                . ($z ? ' <span class="fx-zahl">' . $z . '</span>' : '') . '</a>';
        }
        $liste = '';
        foreach ($d['dateien'] as $f) {
            $titel = $f['titel'] !== '' ? $f['titel'] : $f['datei'];
            $liste .= '<li><label class="check"><input type="checkbox" name="dateien[]" value="' . fx_h($f['datei']) . '"' . (count($d['dateien']) === 1 ? ' checked' : '') . '> <span><strong>'
                . fx_h($titel) . '</strong><span class="muted klein"> · ' . self::zeit($f['zeit']) . ' · ' . self::groesse($f['groesse']) . '</span></span></label>'
                . ' <a class="klein" href="' . $b . '/pdf?art=quelle&amp;quelle=' . fx_h($q) . '&amp;datei=' . fx_h($f['datei']) . '" target="_blank" rel="noopener">ansehen</a></li>';
        }
        $kontakte = '<option value="0">– aus dem Telefonbuch wählen –</option>';
        foreach ($d['kontakte'] as $k) $kontakte .= '<option value="' . (int)$k['id'] . '">' . fx_h($k['name'] . ' · ' . Fax::anzeige($k['nummer'])) . '</option>';
        $empf = '';
        for ($i = 0; $i < Fax::MAX_EMPFAENGER; $i++) {
            $empf .= '<fieldset class="fx-empfaenger"' . ($i > 0 ? ' data-weitere hidden' : '') . '><legend>Empfänger ' . ($i + 1) . '</legend>'
                . '<label>Telefonbuch<select name="empfaenger[' . $i . '][kontakt]">' . $kontakte . '</select></label>'
                . '<div class="fx-zwei"><label>Name<input name="empfaenger[' . $i . '][name]" maxlength="80" autocomplete="off"></label>'
                . '<label>Faxnummer<input name="empfaenger[' . $i . '][nummer]" maxlength="30" inputmode="tel" autocomplete="off" placeholder="030 1234567"></label></div>'
                . '<label class="check"><input type="checkbox" name="empfaenger[' . $i . '][merken]" value="1"> Im Telefonbuch speichern</label></fieldset>';
        }
        $o = '<form method="post" id="fx-senden" class="fx-raster">' . self::csrf($d['csrf']) . '<input type="hidden" name="quelle" value="' . fx_h($q) . '">'
            . '<section class="card"><h2>1. Dokument wählen</h2><p class="muted">Am PC auf einen der Faxdrucker (vFax1 bis vFax5) drucken; das Dokument erscheint dann hier.</p>'
            . '<div class="fx-tabs">' . $tabs . '</div>';
        $o .= $liste !== '' ? '<ul class="fx-dateien">' . $liste . '</ul><button class="small secondary" type="submit" name="aktion" value="quelle-loeschen" formnovalidate data-frage="Ausgewählte Dokumente löschen?">Ausgewählte löschen</button>'
            : '<p class="muted">' . ($q === 'sendefehler' ? 'Keine fehlgeschlagenen Dokumente.' : 'Noch nichts auf ' . fx_h($q) . ' gedruckt.') . '</p>';
        $o .= '</section><section class="card"><h2>2. Empfänger</h2>' . $empf . '<button class="small secondary" type="button" data-mehr>Weiteren Empfänger hinzufügen</button>'
            . '<h2>3. Senden</h2><div class="fx-zwei"><label>Auflösung<select name="aufloesung"><option value="fine">fein (empfohlen)</option><option value="standard">normal</option></select></label>'
            . '<label class="check"><input type="checkbox" name="ecm" value="1" checked> Fehlerkorrektur (ECM)</label></div>'
            . '<button class="primary" type="submit" name="aktion" value="senden"' . ($d['telefon'] ? '' : ' disabled') . '>Fax senden</button></section></form>';
        $o .= '<section class="card"><h2>Laufende Aufträge</h2><div id="fx-aktiv" data-status="' . $b . '/status">' . self::aktiveListe($d['aktive'], $d['csrf']) . '</div></section>';
        return $o;
    }

    private static function berichte(array $d, string $art): string {
        $b = fx_h(fx_basis());
        $liste = $d['berichte'];
        if (!$liste) return '<section class="card"><p class="muted">' . ($art === 'fehler' ? 'Keine Fehlerberichte.' : 'Noch keine gesendeten Faxe.') . '</p></section>';
        $o = '<section class="card">' . ($art === 'fehler' ? '<p class="muted">Das Original eines fehlgeschlagenen Faxes liegt unter „Fax senden“ → Sendefehler und lässt sich dort erneut senden.</p>' : '')
            . '<table class="fx-tabelle"><tr><th>Zeit</th><th>Empfänger</th><th>Dokument</th><th>Seiten</th><th>' . ($art === 'fehler' ? 'Grund' : '') . '</th><th></th></tr>';
        foreach ($liste as $r) {
            $grund = $r['abgebrochen'] ? self::tag('warn', 'abgebrochen') : ($art === 'fehler' ? fx_h(self::grund($r['grund'])) : self::tag('ok', 'gesendet'));
            $o .= '<tr><td>' . self::zeit($r['zeit']) . '</td><td>' . fx_h($r['name']) . '<div class="muted klein">' . fx_h(Fax::anzeige($r['nummer'])) . '</div></td><td>' . fx_h($r['datei'])
                . '</td><td>' . fx_h($r['seiten']) . '</td><td>' . $grund . ($r['versuche'] > 1 ? '<div class="muted klein">' . $r['versuche'] . ' Versuche</div>' : '') . '</td><td class="fx-knoepfe">'
                . ($r['pdf'] !== '' ? '<a class="button small secondary" href="' . $b . '/pdf?art=' . $art . '&amp;datei=' . fx_h($r['pdf']) . '" target="_blank" rel="noopener">Bericht</a>' : '')
                . ($art === 'fehler' ? '<form method="post">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="fehler-uebernehmen"><input type="hidden" name="json" value="' . fx_h($r['json']) . '"><button class="small" type="submit" title="Als erledigt ins Sendeprotokoll">Erledigt</button></form>' : '')
                . '<form method="post" data-confirm="Diesen Bericht löschen?">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="bericht-loeschen"><input type="hidden" name="art" value="' . $art . '"><input type="hidden" name="json" value="' . fx_h($r['json']) . '"><button class="small danger" type="submit">Löschen</button></form></td></tr>';
        }
        return $o . '</table></section>';
    }

    private static function grund(string $g): string {
        return match (true) {
            $g === 'cancelled' => 'abgebrochen', str_contains($g, 'max attempts') => 'zu viele Versuche', str_starts_with($g, 'BUSY') => 'besetzt',
            str_starts_with($g, 'NOANSWER') => 'keine Antwort', str_starts_with($g, 'NOFAX') => 'kein Faxgerät', str_starts_with($g, 'CONGESTION') => 'Netz überlastet',
            str_starts_with($g, 'CHANUNAVAIL') => 'Leitung nicht verfügbar', str_starts_with($g, 'ami_originate_failed') => 'Telefonanlage nicht erreichbar',
            $g === '' => 'fehlgeschlagen', default => mb_substr($g, 0, 60),
        };
    }

    private static function eingang(array $d): string {
        $b = fx_h(fx_basis());
        if (!$d['eingang']) return '<section class="card"><p class="muted">Noch keine Faxe empfangen.</p></section>';
        $o = '<section class="card"><table class="fx-tabelle"><tr><th>Empfangen</th><th>Absender</th><th>Seiten</th><th></th></tr>';
        foreach ($d['eingang'] as $e) {
            $abs = $e['kontakt'] !== '' ? fx_h($e['kontakt']) . '<div class="muted klein">' . fx_h(Fax::anzeige(Fax::nummer($e['absender']))) . '</div>'
                : ($e['absender'] !== '' ? fx_h(Fax::anzeige(Fax::nummer($e['absender']) ?: $e['absender'])) : '<span class="muted">unbekannt</span>');
            $o .= '<tr class="' . ($e['gelesen'] ? '' : 'fx-neu') . '"><td>' . self::zeit($e['zeit']) . ($e['gelesen'] ? '' : ' ' . self::tag('ok', 'neu')) . '</td><td>' . $abs . '</td><td>'
                . fx_h((string)($e['seiten'] ?? '')) . ($e['status'] !== '' && $e['status'] !== 'SUCCESS' ? ' ' . self::tag('warn', 'unvollständig') : '') . '</td><td class="fx-knoepfe">'
                . '<a class="button small primary" href="' . $b . '/pdf?art=eingang&amp;datei=' . fx_h($e['name']) . '.pdf" target="_blank" rel="noopener">Öffnen</a>'
                . '<form method="post" data-confirm="Dieses Fax endgültig löschen?">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="eingang-loeschen"><input type="hidden" name="name" value="' . fx_h($e['name']) . '"><button class="small danger" type="submit">Löschen</button></form></td></tr>';
        }
        return $o . '</table><p class="muted klein">Empfangene Faxe werden nach ' . (int)$d['einstellungen']['eingang_tage'] . ' Tagen gelöscht.</p></section>';
    }

    private static function telefonbuch(array $d): string {
        $edit = $d['rechte']['telefonbuch'];
        $e = $d['kontaktBearbeiten'] ?? ['id' => 0, 'name' => '', 'nummer' => '', 'notiz' => ''];
        $o = '';
        if ($edit) {
            $o .= '<section class="card"><h2>' . ((int)$e['id'] > 0 ? 'Eintrag bearbeiten' : 'Neuer Eintrag') . '</h2><form method="post" class="fx-form">' . self::csrf($d['csrf'])
                . '<input type="hidden" name="aktion" value="kontakt-speichern"><input type="hidden" name="id" value="' . (int)$e['id'] . '">'
                . '<div class="fx-zwei"><label>Name<input name="name" maxlength="80" required value="' . fx_h((string)$e['name']) . '"></label><label>Faxnummer<input name="nummer" maxlength="30" required inputmode="tel" value="' . fx_h((string)($e['nummer'] !== '' ? Fax::anzeige((string)$e['nummer']) : '')) . '"></label></div>'
                . '<label>Notiz<input name="notiz" maxlength="200" value="' . fx_h((string)$e['notiz']) . '"></label><button class="primary" type="submit">Speichern</button></form></section>';
        }
        if (!$d['kontakte']) return $o . '<section class="card"><p class="muted">Das Telefonbuch ist leer.</p></section>';
        $o .= '<section class="card"><label class="fx-suche">Suchen <input type="search" data-filter="#fx-kontakte" autocomplete="off"></label><table class="fx-tabelle" id="fx-kontakte"><tr><th>Name</th><th>Faxnummer</th><th>Notiz</th><th></th></tr>';
        foreach ($d['kontakte'] as $k) {
            $o .= '<tr data-text="' . fx_h(mb_strtolower($k['name'] . ' ' . $k['nummer'] . ' ' . $k['notiz'])) . '"><td>' . fx_h($k['name']) . '</td><td>' . fx_h(Fax::anzeige($k['nummer'])) . '</td><td>' . fx_h($k['notiz']) . '</td><td class="fx-knoepfe">'
                . ($edit ? '<a class="button small secondary" href="' . fx_h(fx_basis()) . '/telefonbuch?kontakt=' . (int)$k['id'] . '">Bearbeiten</a><form method="post" data-confirm="Eintrag löschen?">' . self::csrf($d['csrf'])
                    . '<input type="hidden" name="aktion" value="kontakt-loeschen"><input type="hidden" name="id" value="' . (int)$k['id'] . '"><button class="small danger" type="submit">Löschen</button></form>' : '') . '</td></tr>';
        }
        return $o . '</table></section>';
    }

    private static function einstellungen(array $d): string {
        $e = $d['einstellungen'];
        $feld = static fn(string $n, string $l, int $v) => '<label>' . fx_h($l) . '<input type="number" name="' . $n . '" min="1" max="3650" value="' . $v . '"></label>';
        return '<section class="card"><h2>Aufbewahrung</h2><p class="muted">Faxe enthalten Gesundheitsdaten. Ältere Dateien löscht das Modul selbst.</p><form method="post" class="fx-form fx-schmal">' . self::csrf($d['csrf'])
            . '<input type="hidden" name="aktion" value="einstellungen-speichern">' . $feld('eingang_tage', 'Faxeingang (Tage)', $e['eingang_tage'])
            . $feld('protokoll_tage', 'Sendeprotokoll und Fehlerberichte (Tage)', $e['protokoll_tage']) . $feld('drucker_tage', 'Gedruckte, nicht gesendete Dokumente (Tage)', $e['drucker_tage'])
            . '<button class="primary" type="submit">Speichern</button></form></section>'
            . '<section class="card"><h2>Faxleitung</h2><p>Leitung, Faxnummer und Absenderkennung stellt das Modul <a href="/telefon/einstellungen#rufnummern">Telefon</a> ein. Der Kopf jeder Faxseite trägt den Praxisnamen aus Verwaltung → Praxis.</p></section>';
    }
}
