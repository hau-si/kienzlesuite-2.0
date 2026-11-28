<?php
declare(strict_types=1);
namespace KienzleHomepage;

/** HTML der Seiten (Entwurf B2: Mitteilungen links als Ausklappzeilen, Vorschau rechts; Vertretungspraxen als eigene Liste). */
final class Ansicht {
    /** @var array{mitteilungen:bool,praxen:bool,admin:bool} */
    public static array $r = ['mitteilungen' => false, 'praxen' => false, 'admin' => false];
    public static string $csrf = '';
    public static string $praxisName = '';

    private const MELDUNGEN = [
        'angelegt' => 'Mitteilung angelegt.', 'gespeichert' => 'Gespeichert.', 'an' => 'Eingeschaltet.', 'aus' => 'Ausgeschaltet.', 'geloescht' => 'Mitteilung gelöscht.',
        'uebertragen' => 'An die Homepage übertragen.', 'praxis' => 'Praxis gespeichert.', 'praxis-weg' => 'Praxis gelöscht.',
        'eingerichtet' => 'Verbindung gespeichert.', 'getrennt' => 'Verbindung zur Homepage getrennt.',
    ];
    private const TAG = ['vertretung' => 'hp-vert', 'impfstoff' => 'hp-impf', 'oeffnungszeiten' => 'hp-zeit', 'frei' => ''];
    private const SVG = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';

    private static function h(string $s): string { return hp_h($s); }

    private static function u(string $pfad, array $q = []): string {
        $q = array_filter($q, fn($w) => $w !== '' && $w !== null && $w !== 0);
        return hp_basis() . $pfad . ($q ? '?' . http_build_query($q) : '');
    }

    private static function csrfFeld(): string { return '<input type="hidden" name="kienzle-csrf" value="' . self::h(self::$csrf) . '">'; }

    public static function kopf(string $base, string $csrf): string {
        $v = static fn(string $f): string => '?v=' . substr((string)@md5_file(dirname(__DIR__) . '/public/assets/' . $f), 0, 8);
        return '<meta name="kienzle-csrf" content="' . self::h($csrf) . '"><meta name="hp-basis" content="' . self::h($base) . '">'
            . '<link rel="stylesheet" href="' . self::h($base) . '/assets/homepage.css' . $v('homepage.css') . '"><script src="' . self::h($base) . '/assets/homepage.js' . $v('homepage.js') . '" defer></script>';
    }

    private static function symbol(string $name): string {
        return self::SVG . match ($name) {
            'liste' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
            'praxen' => '<path d="M3 21h18M5 21V7l7-4 7 4v14M10 9h4M12 7v4M9 21v-5h6v5"/>',
            'einrichtung' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
            default => '',
        } . '</svg>';
    }

    private static function seitenkopf(string $route, string $titel, string $extra = ''): string {
        $link = static function (string $ziel, string $text, string $r, string $sym) use ($route): string {
            $an = $route === $r;
            return '<a class="button ' . ($an ? 'primary' : 'secondary') . ' hp-mit-symbol" href="' . self::h(self::u($ziel)) . '"' . ($an ? ' aria-current="page"' : '') . '>' . self::symbol($sym) . self::h($text) . '</a>';
        };
        $o = '<header class="hp-kopf"><div><p class="eyebrow">Homepage</p><h1>' . self::h($titel) . '</h1></div><nav class="hp-werkzeuge" aria-label="Homepage">' . $extra
            . $link('/', 'Mitteilungen', 'start', 'liste') . $link('/praxen', 'Vertretungspraxen', 'praxen', 'praxen');
        if (self::$r['admin']) $o .= $link('/einrichtung', 'Einrichtung', 'einrichtung', 'einrichtung');
        return $o . '</nav></header>';
    }

    public static function meldung(?string $ok, ?string $fehler): string {
        $o = '';
        if ($ok !== null && isset(self::MELDUNGEN[$ok])) $o .= '<p class="notice" role="status">' . self::h(self::MELDUNGEN[$ok]) . '</p>';
        if ($fehler !== null && $fehler !== '') $o .= '<p class="error" role="alert">' . self::h($fehler) . '</p>';
        return $o;
    }

    /** Zustand der Übertragung als Marke (mit Knopf zum erneuten Übertragen). */
    private static function status(bool $verweis = true): string {
        if (!Homepage::eingerichtet()) {
            return '<p class="hp-status"><span class="tag warn">Homepage noch nicht verbunden</span> '
                . (!$verweis ? '' : (self::$r['admin'] ? '<a href="' . self::h(self::u('/einrichtung')) . '">Jetzt einrichten</a>' : '<span class="muted">Bitte den Admin fragen (Homepage → Einrichtung).</span>')) . '</p>';
        }
        $s = Homepage::stand();
        $o = '<div class="hp-status">';
        if ($s && $s['ok'] && !Homepage::ausstehend()) $o .= '<span class="tag ok">Homepage aktuell · übertragen ' . self::h(date('d.m. H:i', (int)$s['zeit'])) . '</span>';
        else {
            $o .= '<span class="tag err">' . self::h($s && !$s['ok'] ? 'Übertragung fehlgeschlagen' : 'Noch nicht übertragen') . '</span>'
                . ($s && !$s['ok'] ? ' <span class="hp-fehlertext">' . self::h($s['meldung']) . '</span>' : '');
            if (self::$r['mitteilungen']) $o .= '<form method="post" class="inline">' . self::csrfFeld() . '<button name="aktion" value="uebertragen">Erneut übertragen</button></form>';
        }
        return $o . '</div>';
    }

    // ---- Mitteilungen (Startseite) ------------------------------------------------------------------------------------

    public static function start(array $get, string $meldung): string {
        $alle = Mitteilungen::alle();
        $jetzt = $rest = [];
        foreach ($alle as $m) {
            if (Mitteilungen::zustand($m) === 'aktiv') $jetzt[] = $m; else $rest[] = $m;
        }
        $neuOffen = self::$r['mitteilungen'] && (!empty($get['neu']) || !$alle);
        $o = '<div class="hp">' . self::seitenkopf('start', 'Mitteilungen', self::$r['mitteilungen'] ? '<a class="button primary" href="' . self::h(self::u('/', ['neu' => 1])) . '#neu" data-neu>+ Neue Mitteilung</a>' : '')
            . $meldung . self::status() . '<div class="hp-zwei"><div class="hp-links">';
        if (self::$r['mitteilungen']) {
            $o .= '<details class="card hp-neu" id="neu"' . ($neuOffen ? ' open' : '') . '><summary><h2>Neue Mitteilung</h2></summary>' . self::formular(null) . '</details>';
        }
        $o .= '<section class="card"><h2>Jetzt auf der Homepage</h2>';
        if (!$jetzt) $o .= '<p class="muted">Gerade erscheint keine Mitteilung auf der Homepage.</p>';
        foreach ($jetzt as $m) $o .= self::zeile($m, (int)($get['id'] ?? 0));
        $o .= '</section><section class="card"><h2>Geplant und ausgeschaltet</h2>';
        if (!$rest) $o .= '<p class="muted">Keine.</p>';
        foreach ($rest as $m) $o .= self::zeile($m, (int)($get['id'] ?? 0));
        $o .= '</section></div>';
        $datum = is_string($get['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $get['datum']) ? $get['datum'] : Mitteilungen::heute();
        $o .= '<aside class="card hp-vorschau-karte" aria-label="Vorschau"><h2>Vorschau</h2><div id="hp-vorschau" aria-live="polite">' . self::vorschau(self::darstellungen($alle, $datum), $datum) . '</div>'
            . '<form class="hp-datum" method="get" action="' . self::h(self::u('/')) . '"><label for="hp-datum">Ansehen am</label><input type="date" id="hp-datum" name="datum" value="' . self::h($datum) . '">'
            . '<button type="button" class="secondary" data-tage="0">Heute</button><button type="button" class="secondary" data-tage="7">+1 Woche</button><button class="secondary hp-ohne-js">Zeigen</button></form>'
            . '<p class="muted hp-klein">Die Vorschau zeigt Änderungen schon beim Tippen, noch vor dem Speichern. Die echte Homepage sieht je nach Theme etwas anders aus.</p></aside>';
        return $o . '</div></div>';
    }

    /** Darstellungen der Mitteilungen, die am Tag $datum zu sehen sind; $bearbeitet: diese ID immer zeigen (Vorschau beim Bearbeiten). */
    public static function darstellungen(array $alle, string $datum, ?int $bearbeitet = null): array {
        $praxen = Mitteilungen::praxenNachId();
        $out = [];
        foreach ($alle as $m) {
            $istEs = $bearbeitet !== null && $m['id'] === $bearbeitet;
            if (Mitteilungen::zustand($istEs ? ['aktiv' => true] + $m : $m, $datum) !== 'aktiv') continue;
            $out[] = Mitteilungen::darstellung($m, $praxen) + ['bearbeitet' => $istEs];
        }
        return $out;
    }

    private static function zeile(array $m, int $offen): string {
        $zustand = Mitteilungen::zustand($m);
        $marke = match ($zustand) {
            'geplant' => '<span class="tag warn">geplant</span>', 'abgelaufen' => '<span class="tag">abgelaufen</span>', 'aus' => '<span class="tag">aus</span>', default => '',
        };
        $o = '';
        $schalter = '';
        if (self::$r['mitteilungen']) {
            $fid = 'hp-s-' . $m['id'];
            $o .= '<form id="' . $fid . '" method="post" hidden>' . self::csrfFeld() . '<input type="hidden" name="aktion" value="schalten"><input type="hidden" name="id" value="' . $m['id'] . '">'
                . '<input type="hidden" name="an" value="' . ($m['aktiv'] ? '0' : '1') . '"></form>';
            $schalter = '<button class="hp-schalter' . ($m['aktiv'] ? ' an' : '') . '" form="' . $fid . '" role="switch" aria-checked="' . ($m['aktiv'] ? 'true' : 'false') . '" title="'
                . ($m['aktiv'] ? 'Ausschalten' : 'Einschalten') . '"><span class="hp-unsichtbar">' . ($m['aktiv'] ? 'Ausschalten' : 'Einschalten') . '</span></button>';
        }
        $o .= '<details class="fold hp-zeile" id="m' . $m['id'] . '" data-id="' . $m['id'] . '"' . ($offen === $m['id'] ? ' open' : '') . '><summary>' . $schalter
            . '<span class="hp-punkt hp-f-' . Mitteilungen::farbe($m) . '">' . Mitteilungen::svg(Mitteilungen::symbol($m)) . '</span>'
            . '<b class="hp-zeilentitel">' . self::h(Mitteilungen::titel($m)) . '</b>' . $marke . '<span class="tag ' . self::TAG[$m['art']] . '">' . self::h(Mitteilungen::ARTEN[$m['art']]) . '</span>'
            . '<span class="muted hp-zeitraum">' . self::h(Mitteilungen::zeitraumKurz($m)) . '</span></summary><div class="box">';
        if (self::$r['mitteilungen']) $o .= self::formular($m);
        else {
            $d = Mitteilungen::darstellung($m, Mitteilungen::praxenNachId());
            $o .= '<p>' . self::h($d['kurz']) . '</p><p class="muted">Erscheint: ' . self::h(implode(', ', array_map(fn($x) => Mitteilungen::ORTE[$x], $m['orte']))) . '</p>';
        }
        return $o . '</div></details>';
    }

    private static function formular(?array $m): string {
        $neu = $m === null;
        $m ??= ['id' => 0, 'art' => 'vertretung', 'titel' => '', 'text' => '', 'praxen' => [], 'tage' => [], 'von' => '', 'bis' => '', 'orte' => ['leiste', 'kasten'], 'aktiv' => true, 'farbe' => 'standard', 'symbol' => 'standard'];
        $p = 'f' . $m['id'] . '-';
        $o = '<form method="post" class="hp-form" data-id="' . ($neu ? 'neu' : $m['id']) . '">' . self::csrfFeld() . '<input type="hidden" name="id" value="' . $m['id'] . '">';
        $o .= '<fieldset class="hp-wahl"><legend>Art</legend><div class="hp-chips">';
        foreach (Mitteilungen::ARTEN as $a => $text) {
            $o .= '<label class="hp-chip"><input type="radio" name="art" value="' . $a . '"' . ($m['art'] === $a ? ' checked' : '') . ' data-vorgabe="' . self::h(Mitteilungen::VORGABE_TITEL[$a]) . '">' . self::h($text) . '</label>';
        }
        $o .= '</div></fieldset>';
        $o .= '<label for="' . $p . 'titel">Überschrift <span class="muted hp-vorgabe">' . (Mitteilungen::VORGABE_TITEL[$m['art']] !== '' ? '(leer = „' . self::h(Mitteilungen::VORGABE_TITEL[$m['art']]) . '“)' : '(optional)') . '</span></label>'
            . '<input id="' . $p . 'titel" name="titel" maxlength="120" value="' . self::h($m['titel']) . '" placeholder="' . self::h(Mitteilungen::VORGABE_TITEL[$m['art']] ?: 'z. B. Praxis am Freitag geschlossen') . '">';

        // Vertretung: mehrere Praxen ankreuzen
        $o .= '<fieldset class="hp-wahl" data-art="vertretung"><legend>Vertretende Praxen (mehrere möglich)</legend>';
        $praxen = Praxen::alle();
        if (!$praxen) $o .= '<p class="muted">Noch keine Praxen angelegt. <a href="' . self::h(self::u('/praxen', ['neu' => 1])) . '">Praxis anlegen</a></p>';
        foreach ($praxen as $pr) {
            $an = in_array((int)$pr['id'], $m['praxen'], true);
            $o .= '<label class="hp-praxis"><input type="checkbox" name="praxen[]" value="' . (int)$pr['id'] . '"' . ($an ? ' checked' : '') . '><span><b>' . self::h($pr['name']) . '</b>'
                . '<small>' . self::h(implode(' · ', array_filter([trim($pr['strasse'] . ', ' . $pr['ort'], ', '), $pr['telefon']]))) . '</small></span></label>';
        }
        $o .= '</fieldset>';

        // Sonderöffnungszeiten: Tage mit Zeiten
        $o .= '<fieldset class="hp-wahl" data-art="oeffnungszeiten"><legend>Tage und Zeiten</legend><div class="hp-tage">';
        $tage = $m['tage'];
        $ziel = max(2, count($tage) + 1); // mindestens eine leere Zeile zum Ergänzen
        while (count($tage) < $ziel) $tage[] = ['', ''];
        foreach ($tage as [$d, $z]) {
            $o .= '<div class="hp-tag"><input type="date" name="tage_datum[]" value="' . self::h($d) . '" aria-label="Datum"><input name="tage_zeit[]" maxlength="60" value="' . self::h($z)
                . '" placeholder="z. B. 8–12 Uhr oder geschlossen" aria-label="Zeiten"></div>';
        }
        $o .= '</div><button type="button" class="secondary hp-klein-knopf" data-tag-dazu>+ Tag</button></fieldset>';

        $o .= '<div class="hp-reihe"><div><label for="' . $p . 'von">Vom <span class="muted">(leer = sofort)</span></label><input type="date" id="' . $p . 'von" name="von" value="' . self::h($m['von']) . '"></div>'
            . '<div><label for="' . $p . 'bis">Bis <span class="muted">(leer = ohne Ende)</span></label><input type="date" id="' . $p . 'bis" name="bis" value="' . self::h($m['bis']) . '"></div></div>';
        $o .= '<label for="' . $p . 'text">Text <span class="muted">(optional)</span></label><textarea id="' . $p . 'text" name="text" maxlength="1000" rows="3" placeholder="z. B. Bitte vereinbaren Sie einen Termin.">'
            . self::h($m['text']) . '</textarea>';
        $farbe = (string)($m['farbe'] ?? 'standard');
        $o .= '<fieldset class="hp-wahl"><legend>Farbe</legend><div class="hp-chips">';
        foreach (Mitteilungen::FARBEN as $f => $text) {
            $o .= '<label class="hp-chip hp-farbwahl"><input type="radio" name="farbe" value="' . $f . '"' . ($farbe === $f ? ' checked' : '') . '><span class="hp-farbfleck hp-f-' . $f . '"></span>' . self::h($text) . '</label>';
        }
        $symbol = (string)($m['symbol'] ?? 'standard');
        $o .= '</div></fieldset><fieldset class="hp-wahl"><legend>Symbol</legend><div class="hp-chips">'
            . '<label class="hp-chip"><input type="radio" name="symbol" value="standard"' . ($symbol === 'standard' ? ' checked' : '') . '>Passend zur Art</label>';
        foreach (Mitteilungen::SYMBOLE as $sy => [$text]) {
            $o .= '<label class="hp-chip hp-symbolwahl" title="' . self::h($text) . '"><input type="radio" name="symbol" value="' . $sy . '"' . ($symbol === $sy ? ' checked' : '') . '>'
                . ($sy === 'keins' ? self::h($text) : Mitteilungen::svg($sy) . '<span class="hp-unsichtbar">' . self::h($text) . '</span>') . '</label>';
        }
        $o .= '</div></fieldset>';
        $o .= '<fieldset class="hp-wahl"><legend>Wo erscheinen?</legend><div class="hp-chips">';
        foreach (Mitteilungen::ORTE as $ort => $text) {
            $o .= '<label class="hp-chip"><input type="checkbox" name="orte[]" value="' . $ort . '"' . (in_array($ort, $m['orte'], true) ? ' checked' : '') . '>' . self::h($text) . '</label>';
        }
        $o .= '</div></fieldset>';
        if ($neu) $o .= '<label class="check"><input type="checkbox" name="sofort" value="1" checked> Gleich einschalten</label>';
        $o .= '<div class="row-buttons"><button name="aktion" value="' . ($neu ? 'anlegen' : 'speichern') . '">' . ($neu ? 'Anlegen' : 'Speichern') . '</button>';
        if (!$neu) $o .= '<button name="aktion" value="loeschen" class="danger" formnovalidate data-frage="Diese Mitteilung löschen?">Löschen</button>';
        return $o . '</div></form>';
    }

    /** Nachgebaute Homepage mit allen sichtbaren Mitteilungen an ihren Orten. */
    public static function vorschau(array $liste, string $datum): string {
        $nach = static fn(string $ort) => array_values(array_filter($liste, fn($d) => in_array($ort, $d['orte'], true)));
        $klasse = static fn(array $d, string $ort) => 'hp-v-' . $ort . ' hp-f-' . $d['farbe'] . (!empty($d['bearbeitet']) ? ' hp-v-bearbeitet' : '');
        $block = static function (array $d, string $ort) use ($klasse): string {
            $o = '<div class="' . $klasse($d, $ort) . '">' . ($d['titel'] !== '' ? '<strong>' . Mitteilungen::svg($d['symbol'], 'hp-v-symbol') . hp_h($d['titel']) . '</strong>' : '');
            if ($d['punkte']) $o .= '<ul><li>' . implode('</li><li>', array_map('hp_h', $d['punkte'])) . '</li></ul>';
            if ($d['text'] !== '') $o .= '<p>' . nl2br(hp_h($d['text'])) . '</p>';
            return $o . '</div>';
        };
        $o = '<div class="hp-seite">';
        foreach ($nach('leiste') as $d) $o .= '<div class="' . $klasse($d, 'leiste') . '">' . Mitteilungen::svg($d['symbol'], 'hp-v-symbol') . hp_h($d['kurz']) . '</div>';
        $o .= '<div class="hp-v-menue">Start · Praxis · Leistungen · Kontakt</div><div class="hp-v-inhalt"><b class="hp-v-gruss">Willkommen' . (self::$praxisName !== '' ? ' in der ' . hp_h(self::$praxisName) : ' in unserer Praxis') . '</b>';
        foreach ($nach('kasten') as $d) $o .= $block($d, 'kasten');
        $o .= '<p class="hp-v-blind">Unsere Sprechzeiten …</p>';
        $kurz = $nach('kurzcode');
        if ($kurz) {
            $o .= '<div class="hp-v-kurzstelle"><span class="hp-v-marke">Kurzcode-Stelle</span>';
            foreach ($kurz as $d) $o .= $block($d, 'kurzcode');
            $o .= '</div>';
        }
        $o .= '</div>';
        $fenster = $nach('fenster');
        if ($fenster) {
            $o .= '<div class="hp-v-schleier"><div class="hp-v-fensterbox hp-f-' . $fenster[0]['farbe'] . '"><span class="hp-v-zu" aria-hidden="true">×</span>' . $block($fenster[0], 'fenster')
                . (count($fenster) > 1 ? '<p class="hp-v-mehr">danach ' . (count($fenster) - 1) . ' weiteres Fenster</p>' : '') . '</div></div>';
        }
        $o .= '</div>';
        if (!$liste) $o .= '<p class="muted hp-klein">Am ' . hp_h(Mitteilungen::datumDeutsch($datum)) . ' erscheint keine Mitteilung.</p>';
        return $o;
    }

    // ---- Vertretungspraxen ---------------------------------------------------------------------------------------------

    public static function praxen(array $get, string $meldung): string {
        $bearbeiten = self::$r['praxen'] && (!empty($get['neu']) || !empty($get['id']));
        $p = !empty($get['id']) ? Praxen::eine((int)$get['id']) : null;
        $o = '<div class="hp">' . self::seitenkopf('praxen', 'Vertretungspraxen') . $meldung . '<section class="card hp-liste">'
            . '<div class="hp-listenkopf"><div><h2>Vertretungspraxen</h2><p class="muted hp-klein">Einmal anlegen, in Mitteilungen „Vertretung“ nur noch ankreuzen.</p></div>'
            . (self::$r['praxen'] ? '<a class="button primary" href="' . self::h(self::u('/praxen', ['neu' => 1])) . '">+ Praxis anlegen</a>' : '') . '</div>';
        if ($bearbeiten) {
            $p ??= ['id' => 0, 'name' => '', 'strasse' => '', 'ort' => '', 'telefon' => '', 'zusatz' => ''];
            $o .= '<form method="post" class="box hp-praxisform" action="' . self::h(self::u('/praxen')) . '">' . self::csrfFeld() . '<input type="hidden" name="id" value="' . (int)$p['id'] . '">'
                . '<h3>' . ($p['id'] ? 'Praxis ändern' : 'Neue Praxis') . '</h3><div class="hp-raster">'
                . '<div><label for="p-name">Name der Praxis</label><input id="p-name" name="name" required maxlength="120" value="' . self::h($p['name']) . '" placeholder="z. B. Praxis Dr. med. Erika Beispiel"></div>'
                . '<div><label for="p-tel">Telefon</label><input id="p-tel" name="telefon" maxlength="40" inputmode="tel" value="' . self::h($p['telefon']) . '" placeholder="01234 56789"></div>'
                . '<div><label for="p-str">Straße und Hausnummer</label><input id="p-str" name="strasse" maxlength="120" value="' . self::h($p['strasse']) . '"></div>'
                . '<div><label for="p-ort">PLZ und Ort</label><input id="p-ort" name="ort" maxlength="80" value="' . self::h($p['ort']) . '"></div></div>'
                . '<label for="p-zusatz">Zusatz <span class="muted">(optional, z. B. Sprechzeiten)</span></label><input id="p-zusatz" name="zusatz" maxlength="200" value="' . self::h($p['zusatz']) . '">'
                . '<div class="row-buttons"><button name="aktion" value="praxis-speichern">Speichern</button><a class="button secondary" href="' . self::h(self::u('/praxen')) . '">Abbrechen</a></div></form>';
        }
        $liste = Praxen::alle();
        if (!$liste) $o .= '<p class="muted">Noch keine Praxen angelegt.</p>';
        else {
            $o .= '<table class="hp-tabelle"><thead><tr><th>Name</th><th>Anschrift</th><th>Telefon</th><th>Zusatz</th>' . (self::$r['praxen'] ? '<th></th>' : '') . '</tr></thead><tbody>';
            foreach ($liste as $pr) {
                $o .= '<tr><td><b>' . self::h($pr['name']) . '</b></td><td>' . self::h(trim($pr['strasse'] . ', ' . $pr['ort'], ', ')) . '</td><td>' . self::h($pr['telefon']) . '</td><td>' . self::h($pr['zusatz']) . '</td>';
                if (self::$r['praxen']) {
                    $o .= '<td class="hp-aktionen"><a class="button secondary" href="' . self::h(self::u('/praxen', ['id' => (int)$pr['id']])) . '">Bearbeiten</a>'
                        . '<form method="post" class="inline" action="' . self::h(self::u('/praxen')) . '">' . self::csrfFeld() . '<input type="hidden" name="id" value="' . (int)$pr['id'] . '">'
                        . '<button name="aktion" value="praxis-loeschen" class="danger" data-frage="' . self::h('„' . $pr['name'] . '“ löschen?') . '">Löschen</button></form></td>';
                }
                $o .= '</tr>';
            }
            $o .= '</tbody></table>';
        }
        return $o . '</section></div>';
    }

    // ---- Einrichtung (nur Admin) ---------------------------------------------------------------------------------------

    public static function einrichtung(string $meldung): string {
        $url = Homepage::adresse();
        $hatSchluessel = Speicher::wert('schluessel') !== '';
        $o = '<div class="hp">' . self::seitenkopf('einrichtung', 'Einrichtung') . $meldung . self::status(false);
        $o .= '<div class="hp-zwei hp-gleich"><section class="card"><h2>Verbindung zur Homepage</h2>'
            . '<form method="post" action="' . self::h(self::u('/einrichtung')) . '" autocomplete="off">' . self::csrfFeld()
            . '<label for="e-url">Adresse der Homepage</label><input id="e-url" name="url" inputmode="url" maxlength="200" value="' . self::h($url) . '" placeholder="https://www.praxis-beispiel.example.org" required>'
            . '<label for="e-schluessel">Schlüssel aus WordPress <span class="muted">(Einstellungen → Praxisherz-Hinweise)</span></label>'
            . '<input id="e-schluessel" name="schluessel" type="password" maxlength="64" autocomplete="new-password" spellcheck="false" placeholder="' . ($hatSchluessel ? 'gespeichert – leer lassen, um ihn zu behalten' : '64 Zeichen') . '"' . ($hatSchluessel ? '' : ' required') . '>'
            . '<div class="row-buttons"><button name="aktion" value="einrichten">Speichern und prüfen</button>';
        if (Homepage::eingerichtet()) {
            $o .= '<button name="aktion" value="uebertragen" class="secondary" formnovalidate>Jetzt übertragen</button>'
                . '<button name="aktion" value="trennen" class="danger" formnovalidate data-frage="Verbindung zur Homepage trennen? Die Mitteilungen bleiben hier gespeichert.">Verbindung trennen</button>';
        }
        $o .= '</div></form><p class="muted hp-klein">Der Praxisserver schickt die Mitteilungen an die Homepage (unterschrieben mit dem Schlüssel). Die Homepage muss den Praxisserver nicht erreichen. '
            . 'Der Schlüssel wird verschlüsselt gespeichert und nicht wieder angezeigt.</p></section>';
        $o .= '<section class="card"><h2>WordPress einrichten</h2><ol class="steps">'
            . '<li><a class="button secondary" href="' . self::h(self::u('/plugin.zip')) . '" download>Plugin herunterladen</a> <span class="muted">praxisherz-hinweise.zip, Version ' . self::h(Homepage::pluginVersion()) . '</span></li>'
            . '<li>In WordPress: <b>Plugins → Installieren → Plugin hochladen</b> → Datei wählen → <b>Jetzt installieren</b> → <b>Aktivieren</b>.</li>'
            . '<li>In WordPress: <b>Einstellungen → Praxisherz-Hinweise</b> → Schlüssel markieren und kopieren.</li>'
            . '<li>Hier links die Adresse der Homepage und den Schlüssel eintragen → <b>Speichern und prüfen</b>.</li></ol>'
            . '<h3>Orte auf der Homepage</h3><ul class="hp-orte">'
            . '<li><b>Leiste oben:</b> auf allen Seiten ganz oben.</li><li><b>Kasten auf der Startseite:</b> über dem Inhalt der Startseite.</li>'
            . '<li><b>Fenster beim Öffnen:</b> erscheint einmal je Besuch und lässt sich schließen.</li>'
            . '<li><b>Kurzcode-Stelle:</b> dort, wo in WordPress <code>[praxisherz_hinweise]</code> in einer Seite oder einem Widget steht.</li></ul>'
            . '<p class="muted hp-klein">Neue Versionen des Plugins kommen mit Praxisherz; dann erneut herunterladen und in WordPress hochladen („Ersetzen“).</p></section></div>';
        return $o . '</div>';
    }
}
