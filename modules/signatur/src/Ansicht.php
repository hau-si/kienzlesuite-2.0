<?php
declare(strict_types=1);
namespace KienzleSignatur;

/** HTML der Seite „Signatur“ (Basis-Rahmen, Karten und Knöpfe kommen von core.css). */
final class Ansicht {
    public static function kopf(string $base, string $csrf): string {
        $v = static fn(string $f): string => '?v=' . substr((string)@md5_file(dirname(__DIR__) . '/public/assets/' . $f), 0, 8);
        return '<meta name="kienzle-csrf" content="' . sg_h($csrf) . '"><link rel="stylesheet" href="' . sg_h($base) . '/assets/style.css' . $v('style.css') . '">'
            . '<script src="' . sg_h($base) . '/assets/app.js' . $v('app.js') . '" defer></script><script src="' . sg_h($base) . '/assets/editor.js' . $v('editor.js') . '" defer></script>';
    }

    private static function datum(int $t): string { return $t > 0 ? date('d.m.Y H:i', $t) : '–'; }

    /** Seitenkopf wie in der Kasse: Reiter Start, Vorgänge, Editor; rechts ein kleines Zahnrad für die Einstellungen. */
    private static function kopfzeile(string $route, bool $darfVorlage, bool $admin, bool $darfVorgang = true): string {
        $link = static fn(string $ziel, string $text, string $r, string $icon = ''): string => '<a class="button ' . ($route === $r ? 'primary' : 'secondary') . ' mit-icon" href="' . sg_h(sg_basis()) . $ziel . '"' . ($route === $r ? ' aria-current="page"' : '') . '>' . $icon . $text . '</a>';
        $titel = ['/' => 'Unterschriften', '/vorgaenge' => 'Vorgänge', '/editor' => 'Editor', '/einstellungen' => 'Einstellungen'][$route];
        $o = '<header class="seitenkopf"><div><p class="eyebrow">Signatur</p><h1>' . $titel . '</h1></div><span class="start-tools">';
        if ($darfVorgang) $o .= $link('/', 'Start', '/', self::HAUS) . $link('/vorgaenge', 'Vorgänge', '/vorgaenge', self::LISTE);
        if ($darfVorlage) $o .= $link('/editor', 'Editor', '/editor', self::EDITOR);
        if ($darfVorlage || $admin) $o .= '<a class="button ' . ($route === '/einstellungen' ? 'primary' : 'secondary') . ' us-zahnrad" href="' . sg_h(sg_basis()) . '/einstellungen" title="Einstellungen" aria-label="Einstellungen"'
            . ($route === '/einstellungen' ? ' aria-current="page"' : '') . '>' . self::ZAHNRAD . '</a>';
        return $o . '</span></header>';
    }

    /** Eine Zeile der Vorgangsliste mit Daten für die Filter (Art, Status, Suchtext). */
    private static function zeile(array $v, array $padNamen, string $csrfFeld, bool $darfVorgang): string {
        $h = 'sg_h';
        $abbrechbar = !in_array($v['status'], ['uebertragen', 'abgebrochen'], true);
        $stat = '<span class="us-status s-' . $h($v['status']) . '">' . $h(Seite::STATI[$v['status']] ?? $v['status']) . '</span>';
        if ($v['meldung'] !== '') $stat .= '<div class="muted klein-text">' . $h($v['meldung']) . '</div>';
        if ($v['status'] === 'unterschrieben' && isset(Ablage::STATI[$v['ablage'] ?? ''])) {
            $stat .= '<div class="muted klein-text">' . $h(Ablage::STATI[$v['ablage']]) . '</div>';
        }
        if ($v['status'] === 'wartet' && $v['kontext'] !== '' && !Seite::kontextGueltig($v)) $stat .= '<div class="muted klein-text">T2med-Verbindung abgelaufen: Das Ablegen braucht einen neuen Aufruf aus T2med.</div>';
        if (($v['kontakt_status'] ?? '') !== '') {
            $k = json_decode((string)$v['kontakt_neu'], true) ?: [];
            $was = implode(' und ', array_filter([($k['telefon'] ?? '') !== '' ? 'Telefon ' . $k['telefon'] : '', ($k['email'] ?? '') !== '' ? 'E-Mail ' . $k['email'] : '']));
            $stat .= '<div class="muted klein-text">' . ($v['kontakt_status'] === 'geschrieben' ? 'In T2med übernommen: ' : 'Für T2med vorgemerkt: ') . $h($was) . ($v['kontakt_status'] === 'fehler' ? ' – ' . $h((string)$v['kontakt_meldung']) : '') . '</div>';
        }
        $suche = mb_strtolower($v['patient'] . ' ' . $v['titel'] . ' ' . (Seite::ARTEN[$v['art']] ?? $v['art']));
        return '<tr data-art="' . $h($v['art']) . '" data-status="' . $h($v['status']) . '" data-archiv="' . ((int)($v['archiviert'] ?? 0) ? '1' : '0') . '" data-suche="' . $h($suche) . '"><td>' . $h(self::datum((int)$v['erstellt'])) . '</td><td>' . $h($v['patient']) . '</td><td>' . $h($v['titel']) . ((int)($v['vorlage_version'] ?? 0) > 0 ? '<div class="muted klein-text">Fassung ' . (int)$v['vorlage_version'] . '</div>' : '') . '</td><td>'
            . $h($padNamen[(int)($v['pad_id'] ?? 0)] ?? '–') . '</td><td>' . $stat . '</td><td class="us-aktionen">' . self::aktionen($v, $csrfFeld, $darfVorgang) . '</td></tr>';
    }

    /** Ansicht eines Vorgangs ohne PDF: eingesetzter Text, wie er am Pad erscheint. */
    public static function dokument(array $v): string {
        $h = 'sg_h';
        $hinweis = in_array($v['status'], ['unterschrieben', 'uebertragen'], true)
            ? 'Unterschrieben am ' . self::datum((int)$v['unterschrieben']) . '. Das PDF wird gerade erzeugt; bitte gleich erneut öffnen.'
            : 'Noch nicht unterschrieben (' . (Seite::STATI[$v['status']] ?? $v['status']) . ').';
        return '<div class="us"><header class="seitenkopf"><div><p class="eyebrow">' . $h($v['patient']) . '</p><h1>' . $h($v['titel']) . '</h1></div></header>'
            . '<p class="muted">' . $h($hinweis) . '</p><div class="card us-dokument">' . $h($v['text']) . '</div></div>';
    }

    /** Knöpfe zu einem Vorgang je nach Status. */
    private static function aktionen(array $v, string $csrfFeld, bool $darfVorgang, bool $links = false): string {
        if (!$darfVorgang) return '';
        // Ansehen: unterschrieben = das PDF, sonst der Dokumenttext (neues Fenster).
        $o = '<a class="button secondary small" href="' . sg_h(sg_basis() . '/ansehen/' . (int)$v['id']) . '" target="_blank" rel="noopener">'
            . (in_array($v['status'], ['unterschrieben', 'uebertragen'], true) ? 'PDF ansehen' : 'Ansehen') . '</a> ';
        if (in_array($v['status'], ['wartet', 'fehler'], true) && (int)($v['pad_id'] ?? 0) > 0) $o .= self::knopf($csrfFeld, 'am-pad', (int)$v['id'], 'Zum Pad senden', 'primary small');
        if ($v['status'] === 'am_pad') $o .= self::knopf($csrfFeld, 'vom-pad', (int)$v['id'], 'Vom Pad nehmen', 'small');
        if (in_array($v['kontakt_status'] ?? '', ['offen', 'fehler'], true) && in_array($v['status'], ['unterschrieben', 'uebertragen'], true)) $o .= self::knopf($csrfFeld, 'kontakt-uebernehmen', (int)$v['id'], 'Kontaktdaten in T2med übernehmen', 'primary small', 'Telefon und E-Mail dieses Patienten in T2med ändern? Alle anderen Angaben bleiben unverändert.');
        if ($v['status'] === 'unterschrieben' && (($v['ablage'] ?? '') === 'fehler' || (($v['ablage'] ?? '') === 'manuell' && ($v['quelle'] === 'kasse' || $v['kontext'] !== ''))))
            $o .= self::knopf($csrfFeld, 'ablage-erneut', (int)$v['id'], ($v['ablage'] === 'fehler' ? 'Erneut in T2med ablegen' : 'In T2med ablegen'), 'primary small');
        if ($v['status'] === 'abgebrochen') $o .= self::knopf($csrfFeld, 'wiederaufnehmen', (int)$v['id'], 'Wieder aufnehmen', 'primary small');
        if ($v['status'] === 'am_pad') return $o . self::knopf($csrfFeld, 'abbrechen', (int)$v['id'], 'Abbrechen', 'danger small');
        if ($links) {
            // Linke Spalte der Startseite: kleine Symbole Archivieren und Löschen (rot).
            return $o . self::symbolKnopf($csrfFeld, 'archivieren', (int)$v['id'], self::ARCHIV, 'Archivieren (bleibt unter „Vorgänge“)', '')
                . self::symbolKnopf($csrfFeld, 'vorgang-loeschen', (int)$v['id'], self::MUELL, 'Löschen', 'Diesen Vorgang endgültig löschen?', true);
        }
        if ((int)($v['archiviert'] ?? 0)) $o .= self::knopf($csrfFeld, 'zurueckholen', (int)$v['id'], 'Zurückholen', 'small');
        else $o .= self::knopf($csrfFeld, 'archivieren', (int)$v['id'], 'Archivieren', 'small');
        return $o . self::knopf($csrfFeld, 'vorgang-loeschen', (int)$v['id'], 'Löschen', 'danger small', 'Diesen Vorgang endgültig löschen?');
    }

    public static function seite(string $route, string $csrf, array $vorgaenge, array $vorlagen, bool $darfVorgang, bool $darfVorlage, ?string $meldung, ?string $fehler, ?array $bearbeite = null, ?array $pad = null, array $pads = [], ?array $aktuell = null): string {
        $h = 'sg_h';
        $csrfFeld = '<input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '">';
        $admin = $pad !== null;
        $o = '<div class="us">' . self::kopfzeile($route, $darfVorlage, $admin, $darfVorgang);
        if ($meldung) $o .= '<p class="ok" role="status">' . $h($meldung) . '</p>';
        if ($fehler) $o .= '<p class="fehler" role="alert">' . $h($fehler) . '</p>';
        $padNamen = [];
        foreach ($pads as $p) $padNamen[(int)$p['id']] = $p['name'];

        if ($route === '/vorgaenge') return $o . self::vorgangsliste($csrfFeld, $vorgaenge, $padNamen, $darfVorgang) . '</div>';
        if ($route === '/editor') return $o . ($darfVorlage ? self::editor($csrfFeld, $vorlagen) : '') . '</div>';
        if ($route === '/einstellungen') {
            if ($admin) $o .= self::pads($csrfFeld, $pads) . self::pad($csrfFeld, $pad);
            else $o .= '<section class="card"><p class="muted">Vorlagen bearbeiten Sie unter „Editor“. Pads richtet ein Admin ein.</p></section>';
            return $o . '</div>';
        }

        // Linke Spalte: offene Vorgänge (wie die Liste der Kasse), rechts groß Patient und Dokumentauswahl.
        // Wie in der Kasse: die letzten Tage (7), neuester oben, unabhängig vom Status.
        $grenze = time() - 7 * 86400;
        $offene = array_values(array_filter($vorgaenge, fn($v) => (int)$v['erstellt'] >= $grenze && !(int)($v['archiviert'] ?? 0)));
        usort($offene, fn($x, $y) => ((int)$y['erstellt'] <=> (int)$x['erstellt']) ?: ((int)$y['id'] <=> (int)$x['id']));
        $links = '<aside class="card us-links"><div class="us-kopf"><h2>Vorgänge</h2><a class="muted klein-text" href="' . $h(sg_basis()) . '/vorgaenge">Alle</a></div>';
        if (!$offene) $links .= '<p class="muted">Keine Vorgänge in den letzten Tagen. Rechts einen neuen anlegen.</p>';
        foreach (array_slice($offene, 0, 30) as $v) {
            $links .= '<div class="us-vz' . ($v['status'] === 'am_pad' ? ' am-pad' : '') . '" data-status="' . $h($v['status']) . '"' . ($v['quelle'] === 'kasse' ? ' data-ref="' . $h((string)$v['quelle_ref']) . '"' : '') . '><div class="us-vz-kopf"><div><strong>' . $h($v['patient']) . '</strong><div class="muted klein-text">' . $h($v['titel']) . ' · ' . $h(self::datum((int)$v['erstellt'])) . '</div></div>'
                . '<span class="us-status s-' . $h($v['status']) . '">' . $h(Seite::STATI[$v['status']] ?? $v['status']) . '</span></div>'
                . ($v['meldung'] !== '' ? '<div class="muted klein-text">' . $h($v['meldung']) . '</div>' : '')
                // Live-Vorschau des Pads (Vorschlag A): app.js lädt Bild und Stand alle 1,5 s.
                . ($v['status'] === 'am_pad' ? '<div class="us-padvorschau" data-vorschau="' . $h(sg_basis() . '/pad-vorschau/' . (int)$v['id']) . '"><img alt="Was der Patient gerade am Pad sieht" hidden><div class="us-pv-stand muted klein-text"><span class="us-pv-punkt"></span><span class="us-pv-text">Pad wird verbunden …</span></div></div>' : '')
                . '<div class="aktionen">' . self::aktionen($v, $csrfFeld, $darfVorgang, true) . '</div></div>';
        }
        $links .= '</aside>';
        // Patient aus T2med oder der Kasse: Kasten oben rechts (JS blendet ihn ein und das Patientenfeld im Formular aus).
        $rechts = '';
        // Patientenkarte (Entwurf B3, Bauteil der Basis, docs/MODUL-GESTALTUNG.md): ohne Patient mit Namensfeld, sonst Name groß,
        // Etikett „T2med“ mit Herz (bei Patienten aus T2med) und die Knöpfe „Zur Kasse“ (Übergabe aus der Kasse) und „Entfernen“.
        $kasse = function_exists('sg_modulPfad') && Uebergabe::kasseBereit() ? sg_modulPfad('kasse') : null;
        if ($darfVorgang) $rechts .= '<section class="patient-karte us-gewaehlt" id="n-gewaehlt"' . ($kasse !== null ? ' data-kasse="' . $h($kasse) . '"' : '') . '><div class="pk-marke neutral" id="n-gewaehlt-marke"><svg class="pk-herz" viewBox="0 0 24 24" width="26" height="26" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 5 6.4 5c2 0 3.4 1.1 4.1 2.3h3C14.2 6.1 15.6 5 17.6 5 21 5 23.1 8.4 21.6 11.8 19.5 16.4 12 21 12 21z"/></svg><svg class="pk-person" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-7 8-7s8 3 8 7"/></svg><span id="n-gewaehlt-quelle">Patient</span></div>'
            . '<div class="pk-text"><div id="n-gewaehlt-anzeige" class="us-pk-anzeige" hidden><strong id="n-gewaehlt-name"></strong><span id="n-gewaehlt-info"></span></div>'
            . '<div id="n-patient-feld"><label for="n-patient" class="sr-only">Patient</label><input id="n-patient" name="patient" form="n-form" maxlength="80" required placeholder="Name des Patienten, z. B. Max Mustermann"></div></div>'
            . '<div class="pk-knoepfe"><a class="button primary pk-knopf" id="n-zur-kasse" href="#" hidden><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg> Zur Kasse</a><button type="button" class="pk-knopf pk-weg" id="n-gewaehlt-weg" hidden><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg> Entfernen</button></div></section>';

        $aktive = array_values(array_filter($vorlagen, fn($v) => (int)$v['aktiv']));
        if ($darfVorgang) {
            $opt = '';
            foreach ($aktive as $i => $v) $opt .= '<label class="us-dok"><input type="radio" name="vorlage_id" value="' . (int)$v['id'] . '" data-art="' . $h($v['art']) . '"' . ($i === 0 ? ' checked' : '') . '><span><strong>' . $h($v['titel']) . '</strong><span class="muted klein-text">' . $h(Seite::ARTEN[$v['art']] ?? $v['art']) . '</span></span></label>';
            $padOpt = '';
            $aktivePads = array_values(array_filter($pads, fn($p) => (int)$p['aktiv']));
            if (count($aktivePads) > 1) {
                $padOpt = '<label for="n-pad">Pad (Arbeitsplatz)</label><select id="n-pad" name="pad_id" required><option value="">Bitte wählen</option>';
                foreach ($aktivePads as $p) $padOpt .= '<option value="' . (int)$p['id'] . '">' . $h($p['name'] . ($p['arbeitsplatz'] !== '' ? ' (' . $p['arbeitsplatz'] . ')' : '')) . '</option>';
                $padOpt .= '</select>';
            }
            $rechts .= '<section class="card"><h2>Dokument wählen</h2>';
            if (!$aktive) {
                $rechts .= '<p class="muted">Es gibt keine aktive Vorlage. Bitte unter Einstellungen eine Vorlage anlegen oder aktivieren.</p>';
            } else {
                $rechts .= '<form method="post" class="us-form" id="n-form" autocomplete="off">' . $csrfFeld . '<input type="hidden" name="aktion" value="neu"><input type="hidden" name="kontext" id="n-kontext" value=""><input type="hidden" name="leistungen_json" id="n-leistungen" value=""><input type="hidden" name="quelle" id="n-quelle" value=""><input type="hidden" name="quelle_ref" id="n-quelle-ref" value="">'
                    . '<div class="us-dokliste" role="radiogroup" aria-label="Dokument">' . $opt . '</div>'
                    . $padOpt
                    . '<div id="n-liste" class="us-liste" hidden><strong>Leistungen aus der Kasse</strong><ul id="n-liste-eintraege"></ul><p class="muted" id="n-liste-summe"></p></div>'
                    . '<details class="fold us-kontakt" id="n-kontakt"><summary>Weitere Patientendaten (für Platzhalter im Dokument)</summary><div class="us-body">'
                    . '<input type="hidden" name="patient_id" id="n-patient-id" value=""><input type="hidden" name="telefon_alt" id="n-telefon-alt" value=""><input type="hidden" name="email_alt" id="n-email-alt" value="">'
                    . '<label for="n-vorname">Vorname</label><input id="n-vorname" name="vorname" maxlength="80">'
                    . '<label for="n-nachname">Nachname</label><input id="n-nachname" name="nachname" maxlength="80">'
                    . '<label for="n-geschlecht">Anrede (aus T2med)</label><select id="n-geschlecht" name="geschlecht"><option value="">Keine Angabe (Guten Tag …)</option><option value="male">Herr</option><option value="female">Frau</option><option value="other">Divers oder unbestimmt (Guten Tag …)</option></select>'
                    . '<label for="n-geburtsdatum">Geburtsdatum</label><input id="n-geburtsdatum" name="geburtsdatum" maxlength="20" placeholder="TT.MM.JJJJ">'
                    . '<label for="n-telefon">Telefon</label><input id="n-telefon" name="telefon" maxlength="40" inputmode="tel">'
                    . '<label for="n-email">E-Mail</label><input id="n-email" name="email" type="email" maxlength="254">'
                    . '<label for="n-adresse">Anschrift</label><input id="n-adresse" name="adresse" maxlength="200">'
                    . '<label class="check" id="n-kontakt-box" hidden><input type="checkbox" name="kontakt_uebernehmen" value="1"> Geänderte Telefonnummer und E-Mail nach der Unterschrift in T2med übernehmen (Knopf in der Vorgangsliste)</label>'
                    . '</div></details>'
                    // Leistung und Betrag nur noch aus der Kasse (Übergabe); eigene Texte gehören in die Vorlage (Editor).
                    . '<input type="hidden" id="n-leistung" name="leistung" value=""><input type="hidden" id="n-betrag" name="betrag" value="">'
                    . '<label class="check"><input type="checkbox" name="druck_vorher" value="1"> Dokument vor der Unterschrift für den Patienten drucken</label>'
                    . '<label class="check"><input type="checkbox" name="druck_nachher" value="1"> Unterschriebenes Dokument danach drucken</label>'
                    . '<button class="primary" type="submit">Vorgang anlegen und ans Pad senden</button></form>';
            }
            $rechts .= '</section>';
        }
        return $o . '<div class="us-grid">' . $links . '<div class="us-rechts">' . $rechts . '</div></div></div>';
    }

    /** Vorgangsliste mit Suche (Patient, Vorgang, Dokumentart) und Filtern nach Dokumentart und Status; die Filter wirken im Browser (app.js). */
    private static function vorgangsliste(string $csrfFeld, array $vorgaenge, array $padNamen, bool $darfVorgang): string {
        $h = 'sg_h';
        $o = '<section class="card" id="vorgaenge"><div class="us-filter">'
            . '<input type="search" id="vg-suche" placeholder="Suchen: Patient, Vorgang, Dokument" aria-label="Vorgänge durchsuchen" autocomplete="off">'
            . '<select id="vg-art" aria-label="Dokumentart"><option value="">Alle Dokumentarten</option>';
        foreach (Seite::ARTEN as $k => $l) $o .= '<option value="' . $h($k) . '">' . $h($l) . '</option>';
        $o .= '</select><select id="vg-status" aria-label="Status"><option value="">Alle Status</option>';
        foreach (Seite::STATI as $k => $l) $o .= '<option value="' . $h($k) . '">' . $h($l) . '</option>';
        $o .= '</select><select id="vg-archiv" aria-label="Archiv"><option value="0">Aktuell</option><option value="1">Archiv</option><option value="">Alle</option></select></div><p class="muted" id="vg-zahl" role="status" aria-live="polite"></p>';
        if (!$vorgaenge) return $o . '<p class="muted">Noch keine Vorgänge.</p></section>';
        $o .= '<table class="us-tabelle" id="vg-tabelle"><thead><tr><th>Angelegt</th><th>Patient</th><th>Dokument</th><th>Pad</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($vorgaenge as $v) $o .= self::zeile($v, $padNamen, $csrfFeld, $darfVorgang);
        return $o . '</tbody></table><p class="muted" id="vg-leer" hidden>Kein Vorgang passt zu Suche und Filtern.</p></section>';
    }

    private static function symbolKnopf(string $csrfFeld, string $aktion, int $id, string $symbol, string $titel, string $frage, bool $rot = false): string {
        return '<form method="post" class="inline">' . $csrfFeld . '<input type="hidden" name="aktion" value="' . $aktion . '"><input type="hidden" name="id" value="' . $id . '"><button class="us-symbol' . ($rot ? ' rot' : '') . '" type="submit" title="' . sg_h($titel) . '" aria-label="' . sg_h($titel) . '"'
            . ($frage !== '' ? ' data-bestaetigen="' . sg_h($frage) . '"' : '') . '>' . $symbol . '</button></form> ';
    }

    private static function knopf(string $csrfFeld, string $aktion, int $id, string $text, string $klasse, string $frage = ''): string {
        return '<form method="post" class="inline">' . $csrfFeld . '<input type="hidden" name="aktion" value="' . $aktion . '"><input type="hidden" name="id" value="' . $id . '"><button class="' . $klasse . '" type="submit"' . ($frage !== '' ? ' data-bestaetigen="' . sg_h($frage) . '"' : '') . '>' . $text . '</button></form> ';
    }

    /** Gewählte Vorlage im Editor (?vorlage=<id> oder „neu“); von public/index.php gesetzt. */
    public static string $editorVorlage = '';

    /**
     * Editor (Entwurf C): links Bausteine und Vorlagen, Mitte das Dokument als Zeilen, rechts Eigenschaften der gewählten
     * Zeile und Vorschau am Pad. Die Daten stehen als JSON in der Seite (kein ausführbares Skript); editor.js baut daraus die
     * Oberfläche und speichert per fetch an /editor/speichern.
     */
    private static function editor(string $csrfFeld, array $vorlagen): string {
        $h = 'sg_h';
        $neu = self::$editorVorlage === 'neu';
        $wahl = null;
        if (!$neu) {
            foreach ($vorlagen as $v) if ((string)$v['id'] === self::$editorVorlage) $wahl = $v;
            $wahl ??= $vorlagen[0] ?? null;
        }
        $fest = Seite::festeVorlageId();
        $daten = [
            'basis' => sg_basis(),
            'vorlage' => $wahl ? ['id' => (int)$wahl['id'], 'art' => $wahl['art'], 'titel' => $wahl['titel'], 'aktiv' => (bool)(int)$wahl['aktiv'], 'version' => (int)$wahl['version'], 'kuerzel' => (string)$wahl['kuerzel'],
                'geaendert' => (int)$wahl['geaendert'] > 0 ? date('d.m.Y', (int)$wahl['geaendert']) : '', 'fest' => (int)$wahl['id'] === $fest]
                : ['id' => 0, 'art' => 'aufklaerung', 'titel' => '', 'aktiv' => true, 'version' => 0, 'kuerzel' => '', 'geaendert' => '', 'fest' => false],
            'bloecke' => $wahl ? Seite::bloeckeVon($wahl) : [['id' => 'b1', 'typ' => 'text', 'text' => "# Titel\n\nText mit **fett** und *kursiv*."]],
            'arten' => Seite::ARTEN, 'typen' => Bausteine::TYPEN, 'felder' => Bausteine::FELDER, 'platzhalter' => Seite::PLATZHALTER,
        ];
        $liste = '';
        foreach ($vorlagen as $v) {
            $an = $wahl && (int)$v['id'] === (int)$wahl['id'];
            $liste .= '<a class="ed-vorlage' . ($an ? ' an' : '') . ((int)$v['aktiv'] ? '' : ' aus') . '" href="' . $h(sg_basis() . '/editor?vorlage=' . (int)$v['id']) . '"' . ($an ? ' aria-current="page"' : '') . '>'
                . $h($v['titel']) . '<small>' . $h(Seite::ARTEN[$v['art']] ?? $v['art']) . ((int)$v['aktiv'] ? '' : ' · aus') . '</small></a>';
        }
        $liste .= '<a class="ed-vorlage neu' . ($neu ? ' an' : '') . '" href="' . $h(sg_basis() . '/editor?vorlage=neu') . '">+ Neue Vorlage</a>';
        $palette = '';
        foreach (Bausteine::TYPEN as $typ => $name) $palette .= '<button type="button" class="ed-baustein" draggable="true" data-typ="' . $h($typ) . '">' . self::GRIFF . $h($name) . '</button>';
        $loeschen = $wahl && (int)$wahl['id'] !== $fest
            ? '<form method="post" class="inline" action="' . $h(sg_basis() . '/editor') . '">' . $csrfFeld . '<input type="hidden" name="aktion" value="vorlage-loeschen"><input type="hidden" name="id" value="' . (int)$wahl['id'] . '">'
                . '<button type="submit" class="us-symbol rot" title="Vorlage löschen" aria-label="Vorlage löschen" data-bestaetigen="Diese Vorlage löschen? Bestehende Vorgänge behalten ihren Text.">' . self::MUELL . '</button></form>'
            : '';
        return '<script type="application/json" id="ed-daten">' . json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>'
            . '<div class="ed-grid">'
            . '<aside class="card ed-links"><h2>Bausteine</h2><p class="muted klein-text">Anklicken oder ins Dokument ziehen.</p><div class="ed-palette">' . $palette . '</div>'
            . '<h2 class="ed-abstand">Vorlagen</h2><nav class="ed-vorlagen">' . $liste . '</nav></aside>'
            . '<section class="card ed-mitte"><div class="ed-kopf"><div class="ed-dok"><input id="ed-titel" maxlength="80" placeholder="Titel der Vorlage" aria-label="Titel der Vorlage">'
            . '<select id="ed-art" aria-label="Dokumentart"></select><input id="ed-kuerzel" maxlength="12" placeholder="Kürzel" title="Kürzel des Akteneintrags in T2med (z. B. EINW). Leer: kein Akteneintrag, nur das PDF." aria-label="Kürzel des Akteneintrags in T2med">'
            . '<label class="check"><input type="checkbox" id="ed-aktiv"> aktiv</label></div>'
            . '<div class="ed-aktionen"><span class="muted klein-text" id="ed-fassung"></span>' . $loeschen . '<button type="button" class="primary" id="ed-speichern">Speichern</button></div></div>'
            . '<p class="ed-meldung" id="ed-meldung" role="status" aria-live="polite" hidden></p>'
            . '<div class="ed-dokument" id="ed-dokument" aria-label="Dokument"></div></section>'
            . '<aside class="ed-rechts"><section class="card"><h2>Eigenschaften</h2><div id="ed-eigenschaften"><p class="muted">Eine Zeile im Dokument anklicken.</p></div></section>'
            . '<section class="card"><h2>Vorschau am Pad</h2><div class="ed-pad"><div class="ed-padscreen" id="ed-vorschau"></div></div>'
            . '<p class="muted klein-text">Beispielwerte für die Platzhalter. Tippt der Patient bei E-Mail oder Telefon auf „Ändern“, erscheint am Pad eine Tastatur.</p></section></aside>'
            . '</div>';
    }

    private const GRIFF = '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M9 5h.01M9 12h.01M9 19h.01M15 5h.01M15 12h.01M15 19h.01"/></svg>';

    /** Ergebnis der Netzwerksuche (Adressen) und das Pad, das gerade bearbeitet wird (id); von public/index.php gesetzt. */
    public static ?array $padSuche = null;
    public static int $padBearbeiten = 0;

    private const HAUS = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10"/></svg>';
    private const LISTE = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/></svg>';
    private const ZAHNRAD = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/></svg>';
    private const STIFT = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13.5 6.5l4 4"/></svg>';
    private const ARCHIV = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h18v4H3zM5 8v12h14V8M10 12h4"/></svg>';
    private const EDITOR = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>';
    private const MUELL = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>';

    /** Bereich „Unterschriftenpads“ nur für Admins: eingerichtete Pads als feste Zeilen (Stift, Mülleimer), darunter Anlegen und Suche im Netz. */
    private static function pads(string $csrfFeld, array $pads): string {
        $h = 'sg_h';
        $o = '<details class="fold" id="pads"' . ($pads || self::$padSuche !== null || self::$padBearbeiten ? ' open' : '') . '><summary><h2>Unterschriftenpads</h2>'
            . ($pads ? '<span class="tag ok">' . count($pads) . ' eingerichtet</span>' : '') . '</summary><div class="us-body">'
            . '<p class="muted">Je Arbeitsplatz mit Pad einen Eintrag. Das Pad muss im Ethernet-Modus laufen (siehe „Pad ins Netzwerk bringen“); Adresse und Port stehen im Servicemenü. Bei mehreren aktiven Pads wählt man beim Anlegen eines Vorgangs, an welchem unterschrieben wird.</p>';
        $feld = static function (array $p, string $knopf) use ($h, $csrfFeld): string {
            $i = (int)($p['id'] ?? 0);
            return '<form method="post" class="us-pad-zeile" autocomplete="off">' . $csrfFeld . '<input type="hidden" name="aktion" value="pad-speichern"><input type="hidden" name="id" value="' . $i . '">'
                . '<label>Name<input name="name" maxlength="40" required value="' . $h($p['name'] ?? '') . '" placeholder="z. B. Pad Empfang"></label>'
                . '<label>Arbeitsplatz<input name="arbeitsplatz" maxlength="60" value="' . $h($p['arbeitsplatz'] ?? '') . '" placeholder="z. B. Anmeldung"></label>'
                . '<label>Adresse<input name="adresse" maxlength="100" required value="' . $h($p['adresse'] ?? '') . '" placeholder="z. B. 192.0.2.10"></label>'
                . '<label>Port<input name="port" inputmode="numeric" maxlength="5" value="' . $h((string)($p['port'] ?? 1002)) . '"></label>'
                . '<label class="check"><input type="checkbox" name="aktiv" value="1"' . (!isset($p['aktiv']) || (int)$p['aktiv'] ? ' checked' : '') . '> aktiv</label>'
                . '<button class="primary small" type="submit">' . $knopf . '</button>'
                . ($i > 0 ? '<a class="button secondary small" href="?#pads">Abbrechen</a>' : '') . '</form>';
        };
        foreach ($pads as $p) {
            $i = (int)$p['id'];
            if (self::$padBearbeiten === $i) { $o .= $feld($p, 'Speichern'); continue; }
            $o .= '<div class="us-pad-fest"><div><strong>' . $h($p['name']) . '</strong><div class="muted">' . ($p['arbeitsplatz'] !== '' ? $h($p['arbeitsplatz']) : 'kein Arbeitsplatz angegeben') . '</div></div>'
                . '<div><code>' . $h($p['adresse']) . ':' . (int)$p['port'] . '</code></div>'
                . '<div>' . ((int)$p['aktiv'] ? '<span class="tag ok">aktiv</span>' : '<span class="tag">pausiert</span>') . '</div>'
                . '<div class="us-pad-aktion"><a class="icon-knopf" href="?pad=' . $i . '#pads" title="Bearbeiten" aria-label="Pad ' . $h($p['name']) . ' bearbeiten">' . self::STIFT . '</a>'
                . '<form method="post" class="inline">' . $csrfFeld . '<input type="hidden" name="aktion" value="pad-loeschen"><input type="hidden" name="id" value="' . $i . '">'
                . '<button class="icon-knopf" type="submit" title="Löschen" aria-label="Pad ' . $h($p['name']) . ' löschen" data-bestaetigen="Dieses Pad wirklich löschen?">' . self::MUELL . '</button></form></div></div>';
        }
        $o .= '<h3>Neues Pad</h3>' . $feld([], 'Pad hinzufügen');
        $o .= '<h3>Pad im Netzwerk suchen</h3><p class="muted">Sucht in Ihrem Netz nach Geräten, die auf Port 1002 antworten (Ethernet-Modus von Delta und Alpha). Das gilt für feste Adressen und für DHCP. Gefunden wird, was gerade eingeschaltet ist; ob es ein Pad ist, zeigt erst der Verbindungstest.</p>';
        $netze = self::$padSuche['netze'] ?? Pad::eigeneNetze();
        $o .= '<form method="post" class="us-suche" autocomplete="off">' . $csrfFeld . '<input type="hidden" name="aktion" value="pad-suchen">'
            . '<label>Netz (die ersten drei Zahlen)<input name="netz" maxlength="15" required pattern="\d{1,3}\.\d{1,3}\.\d{1,3}" value="' . $h((string)($netze[0] ?? '')) . '" placeholder="z. B. 192.168.1"></label>'
            . '<button class="primary small" type="submit">Suchen</button></form>';
        if (self::$padSuche !== null) {
            $bekannt = array_column($pads, 'adresse');
            $o .= '<div class="us-treffer" role="status">';
            if (!self::$padSuche['treffer']) $o .= '<p class="muted">Im Netz ' . $h(self::$padSuche['netz']) . ' wurde nichts auf Port 1002 gefunden. Läuft das Pad im Ethernet-Modus, ist es eingeschaltet und im selben Netz?</p>';
            foreach (self::$padSuche['treffer'] as $a) {
                if (in_array($a, $bekannt, true)) { $o .= '<div class="us-pad-fest"><code>' . $h($a) . '</code><span class="tag ok">schon eingerichtet</span></div>'; continue; }
                $o .= '<form method="post" class="us-pad-zeile us-treffer-zeile" autocomplete="off">' . $csrfFeld . '<input type="hidden" name="aktion" value="pad-speichern"><input type="hidden" name="id" value="0">'
                    . '<input type="hidden" name="adresse" value="' . $h($a) . '"><input type="hidden" name="port" value="1002"><input type="hidden" name="aktiv" value="1">'
                    . '<div><code>' . $h($a) . ':1002</code><div class="muted">antwortet auf Port 1002</div></div>'
                    . '<label>Name<input name="name" maxlength="40" required placeholder="z. B. Pad Empfang"></label>'
                    . '<label>Arbeitsplatz<input name="arbeitsplatz" maxlength="60" placeholder="z. B. Anmeldung"></label>'
                    . '<button class="primary small" type="submit">Hinzufügen</button></form>';
            }
            $o .= '</div>';
        }
        $o .= '<h3 id="netz">Pad ins Netzwerk bringen (Servicemenü)</h3>'
            . '<p class="muted">Gilt für <strong>Delta</strong> und <strong>Alpha</strong> mit Netzwerkanschluss (nur diese beiden werden über die Adresse angesprochen). Angaben nach signotec; an diesem Pad nicht von uns geprüft.</p>'
            . '<ol class="us-schritte">'
            . '<li><strong>Pad ausschalten:</strong> das Pad vom Strom trennen, also Netzteil, USB-Kabel oder bei Power over Ethernet das Netzwerkkabel abziehen.</li>'
            . '<li><strong>Stift ansetzen:</strong> mit dem Stift die <strong>obere rechte Ecke des Displays</strong> berühren und den Stift dort gedrückt halten.</li>'
            . '<li><strong>Einschalten:</strong> bei gehaltenem Stift das Pad wieder mit Strom verbinden. Beim Hochfahren leuchtet die LED (Alpha: rot); sobald das <strong>Servicemenü</strong> auf dem Display erscheint, den Stift loslassen.</li>'
            . '<li><strong>Verbindungsart:</strong> im Menü <strong>Ethernet</strong> wählen (Voreinstellung ist USB).</li>'
            . '<li><strong>Adresse:</strong> unter „IP-Konfiguration“ entweder eine <strong>feste Adresse</strong> eintragen (weißes Feld antippen, Zahlen eingeben) oder den Haken bei <strong>DHCP</strong> setzen. Mit dem grünen Haken bestätigen; das Pad startet neu.</li>'
            . '<li><strong>DHCP:</strong> Beim Delta (ab Firmware 1.42) erscheint nach dem Neustart die vom Router vergebene Adresse auf dem Display und muss bestätigt werden. Sie ist später im Servicemenü wieder abrufbar. Damit sie sich nicht ändert, im Router für das Pad eine feste Zuordnung (Reservierung) einrichten.</li>'
            . '<li><strong>Hier eintragen:</strong> die Adresse oben bei „Neues Pad“ eingeben (Port <code>1002</code>) oder mit „Pad im Netzwerk suchen“ finden lassen.</li></ol>';
        return $o . '</div></details>';
    }

    /** Bereich „Pad-Software“ nur für Admins: Anleitung, Lizenzhinweis, Upload der ZIP-Datei von signotec. */
    private static function pad(string $csrfFeld, array $pad): string {
        $h = 'sg_h';
        $stand = $pad['installiert']
            ? '<p class="ok" role="status">Installiert' . ($pad['version'] !== '' ? ': signoPAD-API Linux ' . $h($pad['version']) : '') . ($pad['zeit'] > 0 ? ', hochgeladen am ' . $h(date('d.m.Y H:i', (int)$pad['zeit'])) : '') . ($pad['von'] !== '' ? ' von ' . $h($pad['von']) : '') . '.</p>'
            : '<p class="muted">Noch nicht hochgeladen. Ohne diese Software kann das Pad nicht angesprochen werden.</p>';
        $o = '<details class="fold" id="pad"' . ($pad['installiert'] ? '' : ' open') . '><summary><h2>Pad-Software von signotec</h2>'
            . ($pad['installiert'] ? '<span class="tag ok">✓ erledigt</span>' : '<span class="tag warn">offen</span>') . '</summary><div class="us-body">' . $stand
            . '<p>Die Software zum Ansprechen des Unterschriftenpads liefern wir nicht mit. Sie laden sie bei signotec herunter und hier hoch. So geht es:</p>'
            . '<ol class="us-schritte">'
            . '<li><strong>Herunterladen:</strong> Auf Ihrem Computer bei signotec das Paket <strong>signoPAD-API Linux</strong> herunterladen, eine ZIP-Datei mit dem Namen <code>signoPAD-API_Linux_9.0.1.zip</code> (die Versionsnummer kann neuer sein, rund 7 MB). Direkt: <a href="https://backend.signotec.com/wp-content/uploads/2025/11/signoPAD-API_Linux_9.0.1.zip" target="_blank" rel="noopener noreferrer">Version 9.0.1</a>; neuere Versionen auf <a href="https://www.signotec.com/en/help-centre/signopad-api/" target="_blank" rel="noopener noreferrer">signotec: signoPAD-API</a> unter Download für Linux. Es muss die Linux-Fassung sein, nicht Windows, Java oder Web.</li>'
            . '<li><strong>Nicht entpacken:</strong> Die ZIP-Datei bleibt, wie sie ist. Das Modul sucht sich daraus selbst die zwei nötigen Bibliotheken <code>libSTPadLib.so</code> und <code>libSTCPImageEngine.so</code> (Linux, 64 Bit) und den Lizenztext des Herstellers; alles andere wird verworfen.</li>'
            . '<li><strong>Lizenz lesen:</strong> Laut signotec ist die Nutzung kostenlos, solange nicht mehr als 50 Unterschriftenpads eingesetzt werden. Den Lizenzvertrag finden Sie in der ZIP-Datei im Ordner <code>licenses</code> (<code>signotec endnutzer lizenzvertrag.txt</code>).</li>'
            . '<li><strong>Hochladen:</strong> Unten auf „Datei auswählen“ klicken, die ZIP-Datei wählen, den Haken zum Lizenzvertrag setzen und auf „Hochladen“ klicken. Die Datei darf höchstens 8 MB groß sein.</li>'
            . '<li><strong>Ergebnis:</strong> Unter dem Knopf erscheint „Wird hochgeladen und geprüft“, dann lädt sich die Seite neu und oben steht grün „Installiert: signoPAD-API Linux 9.0.1“ mit Datum und Ihrem Namen. Bei einem Fehler steht der Grund unter dem Knopf (z. B. „keine ZIP-Datei“ oder „In der ZIP-Datei fehlt …“); es wird dann nichts übernommen.</li>'
            . '<li><strong>Neue Version:</strong> Dieselben Schritte noch einmal; die alte Fassung wird ersetzt. „Pad-Software entfernen“ löscht die Bibliotheken wieder.</li>'
            . '</ol>'
            . '<div class="us-upload"><label for="pad-datei">ZIP-Datei von signotec</label><input type="file" id="pad-datei" accept=".zip,application/zip">'
            . '<label class="check"><input type="checkbox" id="pad-lizenz"> Ich habe den Lizenzvertrag von signotec gelesen und akzeptiere ihn.</label>'
            . '<div class="aktionen"><button class="primary" type="button" id="pad-los" data-ziel="pad-hochladen">Hochladen</button></div>'
            . '<p id="pad-status" class="muted" role="status" aria-live="polite"></p></div>';
        if ($pad['installiert']) $o .= '<form method="post" class="inline us-loeschen">' . $csrfFeld . '<input type="hidden" name="aktion" value="pad-entfernen"><button class="danger small" type="submit" data-bestaetigen="Pad-Software entfernen? Das Pad lässt sich danach nicht mehr ansprechen.">Pad-Software entfernen</button></form>';
        return $o . '</div></details>';
    }

    private static function vorlagen(string $csrfFeld, array $vorlagen, ?array $bearbeite): string {
        $h = 'sg_h';
        $o = '<details class="fold" id="vorlagen"' . ($bearbeite ? ' open' : '') . '><summary><h2>Vorlagen und Vertragstexte</h2></summary><div class="us-body">'
            . '<p class="muted">Platzhalter wie {{patient}} lassen sich unter dem Textfeld per Klick an der Cursorstelle einfügen. Sie werden beim Anlegen des Vorgangs eingesetzt; der Text bleibt danach unverändert. '
            . 'Die mitgelieferten Mustertexte sind nur Beispiele und müssen für Ihre Praxis geprüft werden.</p>';
        foreach ($vorlagen as $v) {
            $offen = $bearbeite && (int)$bearbeite['id'] === (int)$v['id'];
            $o .= '<details class="fold us-vorlage"' . ($offen ? ' open' : '') . '><summary><strong>' . $h($v['titel']) . '</strong> <span class="muted">' . $h(Seite::ARTEN[$v['art']] ?? $v['art'])
                . ((int)$v['aktiv'] ? '' : ', abgeschaltet') . ((int)$v['muster'] ? ', Mustertext' : '') . '</span></summary>' . self::vorlagenForm($csrfFeld, $offen ? $bearbeite : $v) . '</details>';
        }
        $neu = $bearbeite && (int)$bearbeite['id'] === 0 ? $bearbeite : ['id' => 0, 'art' => 'igel', 'titel' => '', 'text' => '', 'aktiv' => 1];
        $o .= '<details class="fold us-vorlage"' . ($bearbeite && (int)$bearbeite['id'] === 0 ? ' open' : '') . '><summary><strong>Neue Vorlage</strong></summary>' . self::vorlagenForm($csrfFeld, $neu) . '</details>';
        return $o . '</div></details>';
    }

    /** Knöpfe, die einen Platzhalter an der Cursorstelle des Textfelds einfügen (app.js, data-platzhalter). */
    private static function platzhalterKnoepfe(string $ziel): string {
        $h = 'sg_h'; $o = '';
        foreach (Seite::PLATZHALTER as $k => $beschreibung) $o .= '<button type="button" class="small" data-platzhalter="{{' . $h($k) . '}}" data-ziel="' . $h($ziel) . '" title="' . $h($beschreibung) . '">' . $h($k) . '</button>';
        return $o;
    }

    private static function vorlagenForm(string $csrfFeld, array $v): string {
        $h = 'sg_h';
        $id = (int)$v['id'];
        $art = '';
        foreach (Seite::ARTEN as $k => $l) $art .= '<option value="' . $h($k) . '"' . ($k === $v['art'] ? ' selected' : '') . '>' . $h($l) . '</option>';
        $o = '<form method="post" class="us-form">' . $csrfFeld . '<input type="hidden" name="aktion" value="vorlage-speichern"><input type="hidden" name="id" value="' . $id . '">'
            . '<label for="v' . $id . '-art">Art</label><select id="v' . $id . '-art" name="art">' . $art . '</select>'
            . '<label for="v' . $id . '-titel">Titel</label><input id="v' . $id . '-titel" name="titel" maxlength="80" required value="' . $h((string)$v['titel']) . '">'
            . '<label for="v' . $id . '-text">Text</label><textarea id="v' . $id . '-text" name="text" rows="12" maxlength="' . Seite::MAX_TEXT . '" required>' . $h((string)$v['text']) . '</textarea>'
            . '<div class="us-platz" role="group" aria-label="Platzhalter einfügen"><span class="muted">Platzhalter einfügen:</span>' . self::platzhalterKnoepfe('v' . $id . '-text') . '</div>'
            . '<label class="check"><input type="checkbox" name="aktiv" value="1"' . ((int)$v['aktiv'] ? ' checked' : '') . '> Aktiv (in „Neuer Vorgang“ wählbar)</label>'
            . '<div class="aktionen"><button class="primary" type="submit">Speichern</button></div></form>';
        if ($id > 0 && $id === Seite::festeVorlageId()) $o .= '<p class="muted klein-text">Dies ist der IGeL-Vertrag der Kasse: Er bleibt immer erhalten (Art und „Aktiv“ sind fest), der Text ist frei änderbar.</p>';
        elseif ($id > 0) $o .= '<form method="post" class="inline us-loeschen">' . $csrfFeld . '<input type="hidden" name="aktion" value="vorlage-loeschen"><input type="hidden" name="id" value="' . $id . '"><button class="danger small" type="submit" data-bestaetigen="Vorlage endgültig löschen? Bestehende Vorgänge behalten ihren Text.">Vorlage löschen</button></form>';
        return $o;
    }
}

