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

    private const SVG = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';

    /** Symbole der Menüpunkte (dieselbe Strichstärke und Größe wie in den Modulen Signatur und Kasse). */
    public static function symbol(string $route): string {
        return self::SVG . match ($route) {
            '/' => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>',
            '/eingang' => '<path d="M22 12h-6l-2 3h-4l-2-3H2M5.5 5.1L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.7 4H7.3a2 2 0 0 0-1.8 1.1z"/>',
            '/protokoll' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
            '/fehler' => '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01"/>',
            '/telefonbuch' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5v14zM20 17v4H6.5A2.5 2.5 0 0 1 4 18.5"/>',
            '/einstellungen' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
            default => '',
        } . '</svg>';
    }

    /** Seitenkopf mit Unterseiten und Symbolen; Zähler für ungelesene Faxe und Fehlerberichte. */
    private static function kopfzeile(string $route, array $rechte, int $ungelesen, int $fehler): string {
        $b = fx_h(fx_basis());
        $links = '';
        foreach (self::SEITEN as $r => $t) {
            if (!self::darf($r, $rechte)) continue;
            $zahl = $r === '/eingang' && $ungelesen > 0 ? ' <span class="fx-zahl">' . $ungelesen . '</span>' : ($r === '/fehler' && $fehler > 0 ? ' <span class="fx-zahl fehler-zahl">' . $fehler . '</span>' : '');
            $links .= '<a class="button ' . ($route === $r ? 'primary' : 'secondary') . ' small mit-icon" href="' . $b . $r . '"' . ($route === $r ? ' aria-current="page"' : '') . '>' . self::symbol($r) . fx_h($t) . $zahl . '</a>';
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
        $druckerInfo = [];
        foreach ($d['drucker'] as $x) $druckerInfo[$x['name']] = $x;
        foreach (array_keys($d['zaehler']) as $name) {
            $z = $d['zaehler'][$name] ?? 0;
            if ($name === Fax::UPLOAD && $z === 0 && $q !== $name) continue;      // „Hochgeladen“ nur, wenn etwas drin liegt
            $titel = match (true) { $name === 'sendefehler' => 'Sendefehler', $name === Fax::UPLOAD => 'Hochgeladen', $name === Fax::NAS => 'NAS-Ordner',
                default => $name . (($druckerInfo[$name]['bezeichnung'] ?? '') !== '' ? ' · ' . $druckerInfo[$name]['bezeichnung'] : '') };
            $tabs .= '<a class="button small ' . ($name === $q ? 'primary' : 'secondary') . '" href="' . $b . '/?quelle=' . fx_h($name) . '">' . fx_h($titel)
                . ($z ? ' <span class="fx-zahl">' . $z . '</span>' : '') . '</a>';
        }
        $fest = $druckerInfo[$q] ?? null;
        $fest = $fest && $fest['nummer'] !== '' ? $fest : null;
        $liste = '';
        foreach ($d['dateien'] as $f) {
            $titel = $f['titel'] !== '' ? $f['titel'] : $f['datei'];
            $liste .= '<li><label class="check"><input type="checkbox" name="dateien[]" value="' . fx_h($f['datei']) . '"' . (count($d['dateien']) === 1 || in_array($f['datei'], $d['vorgewaehlt'], true) ? ' checked' : '') . '> <span><strong>'
                . fx_h($titel) . '</strong><span class="muted klein"> · ' . self::zeit($f['zeit']) . ' · ' . self::groesse($f['groesse']) . '</span></span></label>'
                . ' <a class="klein" href="' . $b . '/pdf?art=quelle&amp;quelle=' . fx_h($q) . '&amp;datei=' . fx_h($f['datei']) . '" target="_blank" rel="noopener">ansehen</a></li>';
        }
        $kontakte = '<option value="0">– aus dem Telefonbuch wählen –</option>';
        foreach ($d['kontakte'] as $k) $kontakte .= '<option value="' . (int)$k['id'] . '">' . fx_h($k['name'] . ' · ' . Fax::anzeige($k['nummer'])) . '</option>';
        $empf = '';
        for ($i = 0; $i < Fax::MAX_EMPFAENGER; $i++) {
            $vorName = $i === 0 && $fest ? ($fest['bezeichnung'] !== '' ? $fest['bezeichnung'] : $fest['name']) : '';
            $vorNr = $i === 0 && $fest ? Fax::anzeige($fest['nummer']) : '';
            $empf .= '<fieldset class="fx-empfaenger"' . ($i > 0 ? ' data-weitere hidden' : '') . '><legend>Empfänger ' . ($i + 1) . '</legend>'
                . ($i === 0 && $fest ? '<p class="muted klein">Feste Faxnummer des Druckers ' . fx_h($fest['name']) . ' (änderbar).</p>' : '')
                . '<label>Telefonbuch<select name="empfaenger[' . $i . '][kontakt]">' . $kontakte . '</select></label>'
                . '<div class="fx-zwei"><label>Name<input name="empfaenger[' . $i . '][name]" maxlength="80" autocomplete="off" value="' . fx_h($vorName) . '"></label>'
                . '<label>Faxnummer<input name="empfaenger[' . $i . '][nummer]" maxlength="30" inputmode="tel" autocomplete="off" placeholder="030 1234567" value="' . fx_h($vorNr) . '"></label></div>'
                . '<label class="check"><input type="checkbox" name="empfaenger[' . $i . '][merken]" value="1"> Im Telefonbuch speichern</label></fieldset>';
        }
        $o = '<form method="post" id="fx-senden" class="fx-raster">' . self::csrf($d['csrf']) . '<input type="hidden" name="quelle" value="' . fx_h($q) . '">'
            . '<section class="card"><h2>1. Dokument wählen</h2><p class="muted">Am PC auf einen Faxdrucker drucken (' . fx_h(implode(', ', array_column($d['drucker'], 'name'))) . '), eine Datei hierher ziehen'
            . ($d['lauschordner'] ? ' oder in den NAS-Ordner legen' : '') . '. Das Dokument erscheint dann hier.</p>'
            . '<div id="fx-drop" class="fx-drop" data-csrf="' . fx_h($d['csrf']) . '" data-ziel="' . $b . '/" tabindex="0"><strong>Dateien hier ablegen</strong><span class="muted klein">PDF, Bilder (JPEG, PNG, GIF, BMP, WebP) oder PostScript, bis 3 MB je Datei. '
            . 'Word- und LibreOffice-Dokumente bitte zuerst als PDF speichern oder auf den Faxdrucker drucken.</span>'
            . '<label class="button small secondary fx-datei">Dateien wählen<input type="file" id="fx-datei" multiple accept=".pdf,.ps,.jpg,.jpeg,.png,.gif,.bmp,.webp,application/pdf,image/*" hidden></label></div>'
            . '<div class="fx-tabs">' . $tabs . '</div>';
        $o .= $liste !== '' ? '<ul class="fx-dateien">' . $liste . '</ul><button class="small secondary" type="submit" name="aktion" value="quelle-loeschen" formnovalidate data-frage="Ausgewählte Dokumente löschen?">Ausgewählte löschen</button>'
            : '<p class="muted">' . ($q === 'sendefehler' ? 'Keine fehlgeschlagenen Dokumente.' : ($q === Fax::NAS ? 'Der NAS-Ordner ist leer.' : ($q === Fax::UPLOAD ? 'Noch nichts hochgeladen.' : 'Noch nichts auf ' . fx_h($q) . ' gedruckt.'))) . '</p>';
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
        return $o . '</table><p class="muted klein">' . ((int)$d['einstellungen']['eingang_tage'] > 0 ? 'Empfangene Faxe werden nach ' . (int)$d['einstellungen']['eingang_tage'] . ' Tagen gelöscht.' : 'Empfangene Faxe werden unbegrenzt aufbewahrt.')
            . ($d['einstellungen']['export']['ziel'] !== '' ? ' Neue Faxe werden zusätzlich auf das NAS exportiert.' : '') . '</p></section>';
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
        $tage = static function (string $n, string $l, int $v): string {
            return '<div class="fx-tage"><label>' . fx_h($l) . '<input type="number" name="' . $n . '" min="1" max="3650" value="' . ($v > 0 ? $v : 90) . '"' . ($v === 0 ? ' disabled' : '') . '></label>'
                . '<label class="check"><input type="checkbox" name="' . $n . '_unbegrenzt" value="1" data-schalter="' . $n . '"' . ($v === 0 ? ' checked' : '') . '> unbegrenzt</label></div>';
        };
        $o = '<section class="card"><h2>Faxdrucker</h2><p class="muted">An den PCs einmal einrichten (Verwaltung → Drucker zeigt die Adresse). Jeder Drucker kann eine <strong>feste Faxnummer</strong> bekommen: '
            . 'Dokumente von diesem Drucker tragen die Nummer schon als Empfänger ein; mit „sofort senden“ gehen sie ohne Rückfrage hinaus. Namen beginnen mit <code>' . fx_h(Fax::VORSATZ) . '</code>.</p>'
            . '<form method="post" class="fx-form">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="drucker-speichern"><table class="fx-tabelle fx-drucker"><tr><th>Name</th><th>Bezeichnung</th><th>Feste Faxnummer</th><th>Sofort senden</th></tr>';
        $zeilen = $d['drucker'];
        while (count($zeilen) < min(Fax::MAX_DRUCKER, count($d['drucker']) + 2)) $zeilen[] = ['name' => '', 'bezeichnung' => '', 'nummer' => '', 'sofort' => false];
        foreach ($zeilen as $i => $x) {
            $o .= '<tr><td><input name="drucker[' . $i . '][name]" maxlength="40" value="' . fx_h($x['name']) . '" placeholder="' . fx_h(Fax::VORSATZ) . '2" aria-label="Name"></td>'
                . '<td><input name="drucker[' . $i . '][bezeichnung]" maxlength="60" value="' . fx_h($x['bezeichnung']) . '" placeholder="z. B. Labor" aria-label="Bezeichnung"></td>'
                . '<td><input name="drucker[' . $i . '][nummer]" maxlength="30" inputmode="tel" value="' . fx_h($x['nummer'] !== '' ? Fax::anzeige($x['nummer']) : '') . '" placeholder="leer = beliebig" aria-label="Feste Faxnummer"></td>'
                . '<td><input type="checkbox" name="drucker[' . $i . '][sofort]" value="1"' . ($x['sofort'] ? ' checked' : '') . ' aria-label="Sofort senden"></td></tr>';
        }
        $o .= '</table><p class="muted klein">Einen Drucker entfernen: Namen löschen. Zum Hinzufügen eine leere Zeile ausfüllen (höchstens ' . Fax::MAX_DRUCKER . ').</p>'
            . '<p class="fx-hinweis"><strong>Nach jeder Änderung der Druckerliste:</strong> Verwaltung → Drucker → „Virtuelle Drucker der Module abgleichen“ (oder das Modul Fax unter Verwaltung → Module neu installieren bzw. aktualisieren). '
            . 'Erst danach erscheinen neue Drucker, und entfernte verschwinden. Am PC neue Drucker einmal hinzufügen.</p>'
            . '<button class="primary" type="submit">Druckerliste speichern</button></form></section>';

        $o .= '<section class="card"><h2>Netzwerkspeicher (NAS)</h2>';
        if (!$d['nasZiele']) {
            $o .= '<p class="muted">Dafür braucht die Suite einen Netzwerkspeicher: Verwaltung → Verbindungen → Netzwerkspeicher einrichten und für das Modul Fax freigeben. Danach lässt sich hier der Export und ein Lauschordner einstellen.</p></section>';
        } else {
            $opt = static function (array $ziele, string $gewaehlt): string {
                $r = '<option value="">– aus –</option>';
                foreach ($ziele as $id => $name) $r .= '<option value="' . fx_h($id) . '"' . ($id === $gewaehlt ? ' selected' : '') . '>' . fx_h($name) . '</option>';
                return $r;
            };
            $o .= '<form method="post" class="fx-form">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="nas-speichern">'
                . '<h3>Faxeingang exportieren</h3><p class="muted">Jedes neu empfangene Fax wird zusätzlich als PDF in diesen Ordner gelegt (Dateiname mit Datum und Absender). Das Fax bleibt auch im Modul.</p>'
                . '<div class="fx-zwei"><label>Ziel<select name="export_ziel">' . $opt($d['nasZiele'], $e['export']['ziel']) . '</select></label>'
                . '<label>Ordner auf dem NAS<input name="export_ordner" maxlength="120" value="' . fx_h($e['export']['ordner']) . '"></label></div>'
                . '<label class="check"><input type="checkbox" name="export_jahresordner" value="1"' . ($e['export']['jahresordner'] ? ' checked' : '') . '> Unterordner je Jahr und Monat (z. B. 2026/11)</label>'
                . '<h3>Lauschordner zum Senden</h3><p class="muted">Dateien (PDF, Bilder), die in diesem NAS-Ordner landen, holt das Modul etwa jede Minute ab und bietet sie unter „Fax senden“ → „NAS-Ordner“ an. '
                . 'Auf dem NAS werden sie danach gelöscht. Der Ordner muss ein anderer sein als der Exportordner.</p>'
                . '<div class="fx-zwei"><label>Ziel<select name="lausch_ziel">' . $opt($d['nasZiele'], $e['lausch']['ziel']) . '</select></label>'
                . '<label>Ordner auf dem NAS<input name="lausch_ordner" maxlength="120" value="' . fx_h($e['lausch']['ordner']) . '"></label></div>'
                . '<button class="primary" type="submit">Speichern</button></form></section>';
        }

        $bereit = (bool)($d['nachrichten']['mail'] ?? false);
        $o .= '<section class="card"><h2>Benachrichtigung bei Faxeingang</h2><p class="muted">Bei neuen Faxen eine kurze E-Mail an diese Adressen schicken (höchstens fünf, durch Komma getrennt; leer = aus). '
            . 'Die E-Mail nennt nur Anzahl und Seiten, nie Absender oder Inhalt, und geht über die E-Mail-Verbindung der Basis.</p>'
            . ($bereit ? '' : '<p class="fx-hinweis">Dafür muss unter Verwaltung → Verbindungen → E-Mail-Versand eingerichtet sein.</p>')
            . '<form method="post" class="fx-form fx-schmal">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="benachrichtigung-speichern">'
            . '<label>E-Mail-Adressen<input name="mail_eingang" maxlength="400" value="' . fx_h(implode(', ', $e['mail_eingang'])) . '" placeholder="empfang@example.org"></label>'
            . '<button class="primary" type="submit">Speichern</button></form></section>';

        $o .= '<section class="card"><h2>Aufbewahrung</h2><p class="muted">Faxe enthalten Gesundheitsdaten. Ältere Dateien löscht das Modul selbst, es sei denn, „unbegrenzt“ ist gewählt (dann bleibt alles, bis es von Hand gelöscht wird).</p>'
            . '<form method="post" class="fx-form fx-schmal">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="aufbewahrung-speichern">'
            . $tage('eingang_tage', 'Faxeingang (Tage)', $e['eingang_tage']) . $tage('protokoll_tage', 'Sendeprotokoll und Fehlerberichte (Tage)', $e['protokoll_tage'])
            . $tage('drucker_tage', 'Gedruckte, nicht gesendete Dokumente (Tage)', $e['drucker_tage']) . '<button class="primary" type="submit">Speichern</button></form></section>';

        $o .= '<section class="card"><h2>Faxleitung und Absenderkennung</h2><p>Leitung, Faxnummer und die <strong>Faxkennung</strong> (Name im Kopf jeder gesendeten Faxseite, beim Empfänger zu sehen) stellt das Modul '
            . '<a href="/telefon/einstellungen#rufnummern">Telefon</a> ein: Einstellungen → Rufnummern → Fax. Bleibt die Faxkennung dort leer, gilt der Praxisname aus Verwaltung → Praxis'
            . ($d['praxisname'] !== '' ? ' (aktuell „' . fx_h($d['praxisname']) . '“)' : '') . ', gekürzt auf 20 Zeichen ohne Umlaute.</p></section>';
        return $o;
    }
}
