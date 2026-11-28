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
            $o .= '<p class="fehler">Das Modul <strong>Telefon</strong> fehlt oder ist noch nicht verbunden. Ohne es kann Praxisherz keine Faxe senden oder empfangen (Betrieb → Module).</p>';
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

    /** Anzeigename einer Quelle (Faxdrucker mit Bezeichnung, Upload, NAS, Sendefehler). */
    private static function quellName(string $q, array $drucker): string {
        return match (true) { $q === 'sendefehler' => 'Sendefehler', $q === Fax::UPLOAD => 'Hochgeladen', $q === Fax::NAS => 'NAS-Ordner',
            default => $q . (($drucker[$q]['bezeichnung'] ?? '') !== '' ? ' · ' . $drucker[$q]['bezeichnung'] : '') };
    }

    private static function wann(int $t): string {
        if ($t <= 0) return '–';
        $tage = (int)((strtotime('today') - strtotime(date('Y-m-d', $t))) / 86400);
        return match (true) { $tage <= 0 => 'heute ' . date('H:i', $t), $tage === 1 => 'gestern ' . date('H:i', $t), default => date('d.m. H:i', $t) };
    }

    /**
     * „Fax senden“ (Entwurf A): oben alle Dokumente aller Quellen in einer Liste mit Häkchen (neueste vorgewählt, Reihenfolge wie
     * angehakt, Vorschau rechts), darunter „An“ mit Favoriten, „Zuletzt“, Suchfeld je Empfänger und „+ weiterer Empfänger“.
     */
    private static function senden(array $d): string {
        $b = fx_h(fx_basis());
        $drucker = [];
        foreach ($d['drucker'] as $x) $drucker[$x['name']] = $x;
        $doks = $d['dokumente'];
        $vor = $d['auswahl'] ?: array_map(fn($x) => Fax::UPLOAD . '/' . $x, $d['vorgewaehlt']);
        $vor = array_values(array_intersect($vor, array_column($doks, 'schluessel')));
        if (!$vor && $doks) $vor = [$doks[0]['schluessel']];
        $liste = '';
        foreach ($doks as $f) {
            $nr = array_search($f['schluessel'], $vor, true);
            $fest = isset($drucker[$f['quelle']]) && $drucker[$f['quelle']]['nummer'] !== '' ? $drucker[$f['quelle']] : null;
            $titel = $f['titel'] !== '' ? $f['titel'] : $f['datei'];
            $liste .= '<li data-vorschau="' . $b . '/vorschau?dok=' . fx_h(rawurlencode($f['schluessel'])) . '" data-seiten="' . (int)$f['seiten'] . '"'
                . ($fest ? ' data-fest-name="' . fx_h($fest['bezeichnung'] !== '' ? $fest['bezeichnung'] : $fest['name']) . '" data-fest-nummer="' . fx_h(Fax::anzeige($fest['nummer'])) . '"' : '') . '>'
                . '<label class="fx-dok"><input type="checkbox" name="auswahl[]" value="' . fx_h($f['schluessel']) . '"' . ($nr !== false ? ' checked' : '') . '>'
                . '<span class="fx-nr" aria-hidden="true">' . ($nr !== false ? $nr + 1 : '') . '</span><span class="fx-dok-text"><strong>' . fx_h($titel) . '</strong>'
                . '<span class="muted klein">' . fx_h(self::quellName($f['quelle'], $drucker)) . ' · ' . self::wann($f['zeit']) . ($f['seiten'] ? ' · ' . (int)$f['seiten'] . ' S.' : '') . '</span></span></label>'
                . '<a class="klein" href="' . $b . '/pdf?art=quelle&amp;quelle=' . fx_h($f['quelle']) . '&amp;datei=' . fx_h($f['datei']) . '" target="_blank" rel="noopener">ansehen</a></li>';
        }
        $erstes = $vor ? $vor[0] : '';
        $o = '<form method="post" id="fx-senden" class="fx-senden" data-telefonbuch="' . fx_h((string)json_encode(array_map(fn($k) => ['id' => (int)$k['id'], 'name' => $k['name'],
                'nummer' => Fax::anzeige($k['nummer']), 'n' => $k['nummer']], $d['kontakte']), JSON_UNESCAPED_UNICODE)) . '">' . self::csrf($d['csrf'])
            . '<input type="hidden" name="reihenfolge" value="' . fx_h(implode("\n", $vor)) . '">'
            . '<section class="card fx-doks"><div class="fx-titel"><h2>Dokumente</h2><span class="muted klein">Neueste oben · mehrere werden in der Reihenfolge des Anhakens zu einem Fax</span></div>'
            . '<div class="fx-doks-raster"><div>';
        $o .= $liste !== '' ? '<ul class="fx-dateien" id="fx-dateien">' . $liste . '</ul>'
            : '<p class="muted">Noch keine Dokumente. Am PC auf einen Faxdrucker drucken (' . fx_h(implode(', ', array_column($d['drucker'], 'name'))) . '), eine Datei hierher ziehen'
                . ($d['lauschordner'] ? ' oder in den NAS-Ordner legen' : '') . '.</p>';
        $o .= '<div id="fx-drop" class="fx-drop" data-csrf="' . fx_h($d['csrf']) . '" data-ziel="' . $b . '/" data-hochladen="' . $b . '/hochladen" tabindex="0">'
            . '<span><strong>+ Datei hierher ziehen</strong> <span class="muted klein">oder</span> <label class="fx-datei">wählen<input type="file" id="fx-datei" multiple accept=".pdf,.ps,.jpg,.jpeg,.png,.gif,.bmp,.webp,application/pdf,image/*" hidden></label></span>'
            . '<span class="muted klein">PDF, Bilder oder PostScript bis 7 MB. Word-Dokumente bitte auf den Faxdrucker drucken.</span></div>'
            . ($liste !== '' ? '<button class="small secondary" type="submit" name="aktion" value="quelle-loeschen" formnovalidate data-frage="Die angehakten Dokumente löschen?">Angehakte löschen</button>' : '')
            . '</div><figure class="fx-vorschau" id="fx-vorschau">' . ($erstes !== '' ? '<img src="' . $b . '/vorschau?dok=' . fx_h(rawurlencode($erstes)) . '" alt="Vorschau: oberer Teil der ersten Seite">' : '<div class="fx-leer muted klein">Vorschau</div>')
            . '<figcaption class="muted klein">Oberer Teil der ersten Seite</figcaption></figure></div>'
            . '<p class="fx-summe" id="fx-summe" aria-live="polite"></p></section>';

        // Empfänger
        $chips = '';
        $favoriten = array_values(array_filter($d['kontakte'], fn($k) => !empty($k['favorit'])));
        foreach ($favoriten as $k) $chips .= '<button type="button" class="fx-chip fx-fav" data-wahl data-kontakt="' . (int)$k['id'] . '" data-name="' . fx_h($k['name']) . '" data-nummer="' . fx_h(Fax::anzeige($k['nummer'])) . '" title="' . fx_h(Fax::anzeige($k['nummer'])) . '">★ ' . fx_h($k['name']) . '</button>';
        $favNummern = array_column($favoriten, 'nummer');
        $zuletzt = '';
        foreach (array_slice(array_filter($d['zuletzt'], fn($z) => !in_array($z['nummer'], $favNummern, true)), 0, 5) as $z) {
            $zuletzt .= '<button type="button" class="fx-chip" data-wahl data-name="' . fx_h($z['name']) . '" data-nummer="' . fx_h(Fax::anzeige($z['nummer'])) . '" title="Zuletzt gefaxt an ' . fx_h(Fax::anzeige($z['nummer'])) . '">⟲ ' . fx_h($z['name']) . '</button>';
        }
        $post = (array)($d['post']['empfaenger'] ?? []);
        if (!$post && $erstes !== '') {          // Dokument von einem Faxdrucker mit fester Nummer: Empfänger vorbelegen
            $q = substr($erstes, 0, (int)strpos($erstes, '/'));
            if (($drucker[$q]['nummer'] ?? '') !== '') $post = [['name' => $drucker[$q]['bezeichnung'] !== '' ? $drucker[$q]['bezeichnung'] : $q, 'nummer' => Fax::anzeige($drucker[$q]['nummer'])]];
        }
        $empf = '';
        for ($i = 0; $i < Fax::MAX_EMPFAENGER; $i++) {
            $p = is_array($post[$i] ?? null) ? $post[$i] : [];
            $sichtbar = $i === 0 || trim((string)($p['name'] ?? '') . (string)($p['nummer'] ?? '')) !== '';
            $empf .= '<div class="fx-empfaenger"' . ($sichtbar ? '' : ' hidden') . ($i > 0 ? ' data-weitere' : '') . '>'
                . '<div class="fx-suchfeld"><input name="empfaenger[' . $i . '][name]" maxlength="80" autocomplete="off" aria-label="Empfänger ' . ($i + 1) . ': Name oder Faxnummer" placeholder="Name, Teil des Namens oder Faxnummer …" value="' . fx_h((string)($p['name'] ?? '')) . '">'
                . '<ul class="fx-vorschlaege" hidden></ul></div>'
                . '<input name="empfaenger[' . $i . '][nummer]" maxlength="30" inputmode="tel" autocomplete="off" aria-label="Empfänger ' . ($i + 1) . ': Faxnummer" placeholder="Faxnummer" value="' . fx_h((string)($p['nummer'] ?? '')) . '">'
                . '<input type="hidden" name="empfaenger[' . $i . '][kontakt]" value="' . (int)($p['kontakt'] ?? 0) . '">'
                . ($i > 0 ? '<button type="button" class="small secondary fx-weg" data-entfernen title="Empfänger entfernen" aria-label="Empfänger entfernen">×</button>' : '<span></span>')
                . '<label class="check klein fx-merken"><input type="checkbox" name="empfaenger[' . $i . '][merken]" value="1"' . (!empty($p['merken']) ? ' checked' : '') . '> Im Telefonbuch speichern</label></div>';
        }
        $ecm = !$d['post'] || !empty($d['post']['ecm']);
        $o .= '<section class="card fx-an"><h2>An</h2>'
            . ($chips . $zuletzt !== '' ? '<div class="fx-chips">' . $chips . $zuletzt . '</div>' : '<p class="muted klein">Tipp: Im Telefonbuch mit ★ Favoriten markieren, sie stehen dann hier zur Auswahl.</p>')
            . $empf . '<button class="small secondary fx-plus" type="button" data-mehr>+ weiterer Empfänger</button>'
            . '<details class="fold fx-erweitert"><summary>Erweitert</summary><div class="fx-zwei"><label>Auflösung<select name="aufloesung"><option value="fine">fein (empfohlen)</option><option value="standard"'
            . (($d['post']['aufloesung'] ?? '') === 'standard' ? ' selected' : '') . '>normal</option></select></label>'
            . '<label class="check"><input type="checkbox" name="ecm" value="1"' . ($ecm ? ' checked' : '') . '> Fehlerkorrektur (ECM)</label></div>'
            . '<label class="check"><input type="checkbox" name="einzeln" value="1" id="fx-einzeln"' . (!empty($d['post']['einzeln']) ? ' checked' : '') . '> Jedes Dokument als eigenes Fax</label></details>'
            . '<p class="fx-hinweis klein" id="fx-viele" hidden>Mehr als 15 Seiten: Als einzelne Faxe ist das Senden oft zuverlässiger – bricht die Leitung ab, wird nur ein Dokument wiederholt (Erweitert → „Jedes Dokument als eigenes Fax“).</p>'
            . '<button class="primary fx-los" id="fx-los" type="submit" name="aktion" value="senden"' . ($d['telefon'] ? '' : ' disabled') . '>Fax senden</button></section></form>';
        $o .= '<section class="card"><h2>Laufende Aufträge</h2><div id="fx-aktiv" data-status="' . $b . '/status">' . self::aktiveListe($d['aktive'], $d['csrf']) . '</div></section>';
        return $o;
    }

    private static function berichte(array $d, string $art): string {
        $b = fx_h(fx_basis());
        $liste = $d['berichte'];
        if (!$liste) return '<section class="card"><p class="muted">' . ($art === 'fehler' ? 'Keine Fehlerberichte.' : 'Noch keine gesendeten Faxe.') . '</p></section>';
        $o = '<section class="card">' . ($art === 'protokoll' ? '<p class="muted klein">Das Original eines erfolgreich gesendeten Faxes lässt sich hier ' . Fax::PROTOKOLL_ORIGINAL_TAGE . ' Tage lang erneut senden, danach bleibt nur noch der Bericht.</p>' : '')
            . '<table class="fx-tabelle"><tr><th>Zeit</th><th>Empfänger</th><th>Dokument</th><th>Seiten</th><th>' . ($art === 'fehler' ? 'Grund' : '') . '</th><th></th></tr>';
        foreach ($liste as $r) {
            $grund = $r['abgebrochen'] ? self::tag('warn', 'abgebrochen') : ($art === 'fehler' ? fx_h(self::grund($r['grund'])) : self::tag('ok', 'gesendet'));
            $o .= '<tr><td>' . self::zeit($r['zeit']) . '</td><td>' . fx_h($r['name']) . '<div class="muted klein">' . fx_h(Fax::anzeige($r['nummer'])) . '</div></td><td>' . fx_h($r['datei'])
                . '</td><td>' . fx_h($r['seiten']) . '</td><td>' . $grund . ($r['versuche'] > 1 ? '<div class="muted klein">' . $r['versuche'] . ' Versuche</div>' : '') . '</td><td class="fx-knoepfe">'
                . ($r['pdf'] !== '' ? '<a class="button small secondary" href="' . $b . '/pdf?art=' . $art . '&amp;datei=' . fx_h($r['pdf']) . '" target="_blank" rel="noopener">Bericht</a>' : '')
                . ($r['original'] ? '<form method="post" data-confirm="Fax an ' . fx_h($r['name'] !== '' ? $r['name'] : Fax::anzeige($r['nummer'])) . ' erneut senden?">' . self::csrf($d['csrf'])
                    . '<input type="hidden" name="aktion" value="bericht-erneut-senden"><input type="hidden" name="art" value="' . $art . '"><input type="hidden" name="json" value="' . fx_h($r['json']) . '"><button class="small primary" type="submit">Erneut senden</button></form>' : '')
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
        $suche = (string)($d['suche'] ?? '');
        $ocr = (bool)($d['einstellungen']['ocr'] ?? false);
        $kopf = '<form method="get" class="fx-suchzeile" role="search"><input type="search" name="suche" value="' . fx_h($suche) . '" maxlength="100" placeholder="'
            . ($ocr ? 'Suchen in Absender und Faxtext …' : 'Suchen nach Absender …') . '" aria-label="Faxe durchsuchen"><button class="secondary" type="submit">Suchen</button>'
            . ($suche !== '' ? '<a class="button secondary" href="' . $b . '/eingang">Alle zeigen</a>' : '') . '</form>';
        if (!$d['eingang']) return '<section class="card">' . $kopf . '<p class="muted">' . ($suche !== '' ? 'Kein Fax gefunden.' : 'Noch keine Faxe empfangen.') . '</p></section>';
        $o = '<section class="card">' . $kopf . ($suche !== '' ? '<p class="muted klein">' . count($d['eingang']) . ' Treffer</p>' : '')
            . '<table class="fx-tabelle"><tr><th>Empfangen</th><th>Absender</th><th>Seiten</th><th></th></tr>';
        foreach ($d['eingang'] as $e) {
            $abs = $e['kontakt'] !== '' ? fx_h($e['kontakt']) . '<div class="muted klein">' . fx_h(Fax::anzeige(Fax::nummer($e['absender']))) . '</div>'
                : ($e['absender'] !== '' ? fx_h(Fax::anzeige(Fax::nummer($e['absender']) ?: $e['absender'])) : '<span class="muted">unbekannt</span>');
            if (($e['treffer'] ?? '') !== '') $abs .= '<div class="fx-treffer klein">' . fx_h($e['treffer']) . '</div>';
            elseif ($ocr && !($e['erkannt'] ?? true)) $abs .= '<div class="muted klein">Text wird erkannt …</div>';
            $o .= '<tr class="' . ($e['gelesen'] ? '' : 'fx-neu') . '" data-vorschau="' . $b . '/vorschau?art=eingang&amp;datei=' . fx_h($e['name']) . '.pdf"><td>' . self::zeit($e['zeit']) . ($e['gelesen'] ? '' : ' ' . self::tag('ok', 'neu')) . '</td><td>' . $abs . '</td><td>'
                . fx_h((string)($e['seiten'] ?? '')) . ($e['status'] !== '' && $e['status'] !== 'SUCCESS' ? ' ' . self::tag('warn', 'unvollständig') : '') . '</td><td class="fx-knoepfe">'
                . '<a class="button small primary" href="' . $b . '/pdf?art=eingang&amp;datei=' . fx_h($e['name']) . '.pdf" target="_blank" rel="noopener">Öffnen</a>'
                . '<form method="post" data-confirm="Dieses Fax endgültig löschen?">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="eingang-loeschen"><input type="hidden" name="name" value="' . fx_h($e['name']) . '"><button class="small danger" type="submit">Löschen</button></form></td></tr>';
        }
        return $o . '</table><p class="muted klein">' . ((int)$d['einstellungen']['eingang_tage'] > 0 ? 'Empfangene Faxe werden nach ' . (int)$d['einstellungen']['eingang_tage'] . ' Tagen gelöscht.' : 'Empfangene Faxe werden unbegrenzt aufbewahrt.')
            . ($d['einstellungen']['export']['ziel'] !== '' ? ' Neue Faxe werden zusätzlich auf das NAS exportiert.' : '') . '</p></section>';
    }

    private static function telefonbuch(array $d): string {
        $edit = $d['rechte']['telefonbuch'];
        $e = $d['kontaktBearbeiten'] ?? ['id' => 0, 'name' => '', 'nummer' => '', 'notiz' => '', 'favorit' => 0];
        $o = '';
        if ($edit) {
            $o .= '<section class="card"><h2>' . ((int)$e['id'] > 0 ? 'Eintrag bearbeiten' : 'Neuer Eintrag') . '</h2><form method="post" class="fx-form">' . self::csrf($d['csrf'])
                . '<input type="hidden" name="aktion" value="kontakt-speichern"><input type="hidden" name="id" value="' . (int)$e['id'] . '">'
                . '<div class="fx-zwei"><label>Name<input name="name" maxlength="80" required value="' . fx_h((string)$e['name']) . '"></label><label>Faxnummer<input name="nummer" maxlength="30" required inputmode="tel" value="' . fx_h((string)($e['nummer'] !== '' ? Fax::anzeige((string)$e['nummer']) : '')) . '"></label></div>'
                . '<label>Notiz<input name="notiz" maxlength="200" value="' . fx_h((string)$e['notiz']) . '"></label>'
                . '<label class="check"><input type="checkbox" name="favorit" value="1"' . (!empty($e['favorit']) ? ' checked' : '') . '> ★ Favorit (steht bei „Fax senden“ direkt zur Auswahl)</label><button class="primary" type="submit">Speichern</button></form></section>';
        }
        if (!$d['kontakte']) return $o . '<section class="card"><p class="muted">Das Telefonbuch ist leer.</p></section>';
        $o .= '<section class="card"><label class="fx-suche">Suchen <input type="search" data-filter="#fx-kontakte" autocomplete="off"></label><table class="fx-tabelle" id="fx-kontakte"><tr><th class="fx-stern-spalte" title="Favorit">★</th><th>Name</th><th>Faxnummer</th><th>Notiz</th><th></th></tr>';
        foreach ($d['kontakte'] as $k) {
            $fav = !empty($k['favorit']);
            $stern = $edit ? '<form method="post">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="kontakt-favorit"><input type="hidden" name="id" value="' . (int)$k['id'] . '">'
                . '<input type="hidden" name="an" value="' . ($fav ? '0' : '1') . '"><button type="submit" class="fx-stern' . ($fav ? ' an' : '') . '" title="' . ($fav ? 'Favorit entfernen' : 'Als Favorit markieren') . '" aria-pressed="' . ($fav ? 'true' : 'false') . '">' . ($fav ? '★' : '☆') . '</button></form>'
                : ($fav ? '<span class="fx-stern an">★</span>' : '');
            $o .= '<tr data-text="' . fx_h(mb_strtolower($k['name'] . ' ' . $k['nummer'] . ' ' . $k['notiz'])) . '"><td>' . $stern . '</td><td>' . fx_h($k['name']) . '</td><td>' . fx_h(Fax::anzeige($k['nummer'])) . '</td><td>' . fx_h($k['notiz']) . '</td><td class="fx-knoepfe">'
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
        $o = '<section class="card"><h2>Faxdrucker</h2><p>Diese Drucker richtet der Praxisherz-Agent auf jedem Windows-PC selbst ein (<a href="/hilfe/arbeitsplatz">Einrichten → Arbeitsplätze</a>).</p>'
            . '<details class="how"><summary>Von Hand einrichten (ohne Agent, Mac/Linux oder wenn etwas schiefgeht)</summary><p>Die Adresse jedes Faxdruckers steht unter <a href="/verwaltung/drucker">Praxis → Drucker</a>. Am PC einen Drucker über diese Adresse hinzufügen (Windows: „Freigegebenen Drucker über den Namen auswählen“, Treiber „Microsoft IPP Class Driver“). Voraussetzung: Die Firewall (Betrieb → Sicherheit) lässt Drucken (IPP, Port 631) aus dem Praxisnetz zu.</p></details>'
            . '<p class="muted">Jeder Drucker kann eine <strong>feste Faxnummer</strong> bekommen: '
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
            . '<p class="fx-hinweis"><strong>Nach jeder Änderung der Druckerliste:</strong> <a href="/verwaltung/drucker">Praxis → Drucker</a> → „Virtuelle Drucker der Module abgleichen“ (oder das Modul Fax unter Betrieb → Module neu installieren bzw. aktualisieren). '
            . 'Erst danach erscheinen neue Drucker, und entfernte verschwinden. Der Praxisherz-Agent übernimmt neue Drucker an den PCs selbst.</p>'
            . '<button class="primary" type="submit">Druckerliste speichern</button></form></section>';

        $o .= '<section class="card"><h2>Netzwerkspeicher (NAS)</h2>';
        if (!$d['nasZiele']) {
            $o .= '<p class="muted">Dafür braucht Praxisherz einen Netzwerkspeicher: Praxis → Verbindungen → Netzwerkspeicher einrichten und für das Modul Fax freigeben. Danach lässt sich hier der Export und ein Lauschordner einstellen.</p></section>';
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
            . ($bereit ? '' : '<p class="fx-hinweis">Dafür muss unter Praxis → Verbindungen → E-Mail-Versand eingerichtet sein.</p>')
            . '<form method="post" class="fx-form fx-schmal">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="benachrichtigung-speichern">'
            . '<label>E-Mail-Adressen<input name="mail_eingang" maxlength="400" value="' . fx_h(implode(', ', $e['mail_eingang'])) . '" placeholder="empfang@example.org"></label>'
            . '<button class="primary" type="submit">Speichern</button></form></section>';

        $o .= '<section class="card"><h2>Texterkennung</h2><p class="muted">Empfangene Faxe werden im Hintergrund gelesen (Tesseract, auf diesem Server, nichts geht nach außen). '
            . 'Dann findet die Suche im Faxeingang auch Wörter im Fax, z. B. einen Patientennamen. Der Text liegt geschützt neben dem Fax und wird mit ihm gelöscht.</p>'
            . '<form method="post" class="fx-form fx-schmal">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="ocr-speichern">'
            . '<label class="check"><input type="checkbox" name="ocr" value="1"' . ($e['ocr'] ? ' checked' : '') . '> Texterkennung für empfangene Faxe</label>'
            . '<button class="primary" type="submit">Speichern</button></form></section>';

        $o .= '<section class="card"><h2>Aufbewahrung</h2><p class="muted">Faxe enthalten Gesundheitsdaten. Ältere Dateien löscht das Modul selbst, es sei denn, „unbegrenzt“ ist gewählt (dann bleibt alles, bis es von Hand gelöscht wird).</p>'
            . '<form method="post" class="fx-form fx-schmal">' . self::csrf($d['csrf']) . '<input type="hidden" name="aktion" value="aufbewahrung-speichern">'
            . $tage('eingang_tage', 'Faxeingang (Tage)', $e['eingang_tage']) . $tage('protokoll_tage', 'Sendeprotokoll und Fehlerberichte (Tage)', $e['protokoll_tage'])
            . $tage('drucker_tage', 'Gedruckte, nicht gesendete Dokumente (Tage)', $e['drucker_tage']) . '<button class="primary" type="submit">Speichern</button></form></section>';

        $o .= '<section class="card"><h2>Faxleitung und Absenderkennung</h2><p>Leitung, Faxnummer und die <strong>Faxkennung</strong> (Name im Kopf jeder gesendeten Faxseite, beim Empfänger zu sehen) stellt das Modul '
            . '<a href="/telefon/einstellungen#rufnummern">Telefon</a> ein: Einstellungen → Rufnummern → Fax. Bleibt die Faxkennung dort leer, gilt der Praxisname aus Praxis → Praxis'
            . ($d['praxisname'] !== '' ? ' (aktuell „' . fx_h($d['praxisname']) . '“)' : '') . ', gekürzt auf 20 Zeichen ohne Umlaute.</p></section>';
        return $o;
    }
}
