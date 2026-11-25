<?php
declare(strict_types=1);
namespace KienzleSignatur;

/** HTML der Seite „Signatur“ (Basis-Rahmen, Karten und Knöpfe kommen von core.css). */
final class Ansicht {
    public static function kopf(string $base, string $csrf): string {
        $v = static fn(string $f): string => '?v=' . substr((string)@md5_file(dirname(__DIR__) . '/public/assets/' . $f), 0, 8);
        return '<meta name="kienzle-csrf" content="' . sg_h($csrf) . '"><link rel="stylesheet" href="' . sg_h($base) . '/assets/style.css' . $v('style.css') . '">'
            . '<script src="' . sg_h($base) . '/assets/app.js' . $v('app.js') . '" defer></script>';
    }

    private static function datum(int $t): string { return $t > 0 ? date('d.m.Y H:i', $t) : '–'; }

    /** Seitenkopf mit Unterseiten wie in der Kasse: Start, Vorgänge, Einstellungen. */
    private static function kopfzeile(string $route, bool $darfVorlage, bool $admin): string {
        $link = static fn(string $ziel, string $text, string $r): string => '<a class="button ' . ($route === $r ? 'primary' : 'secondary') . '" href="' . sg_h(sg_basis()) . $ziel . '"' . ($route === $r ? ' aria-current="page"' : '') . '>' . $text . '</a>';
        return '<header class="seitenkopf"><div><p class="eyebrow">Signatur</p><h1>' . ['/' => 'Unterschriften', '/vorgaenge' => 'Vorgänge', '/einstellungen' => 'Einstellungen'][$route] . '</h1></div>'
            . '<span class="start-tools">' . ($route === '/' ? '' : $link('/', '← Start', '')) . ($route !== '/vorgaenge' ? $link('/vorgaenge', 'Alle Vorgänge', '/vorgaenge') : '')
            . ($route !== '/einstellungen' && ($darfVorlage || $admin) ? $link('/einstellungen', 'Einstellungen', '/einstellungen') : '') . '</span></header>';
    }

    /** Eine Zeile der Vorgangsliste mit Daten für die Filter (Art, Status, Suchtext). */
    private static function zeile(array $v, array $padNamen, string $csrfFeld, bool $darfVorgang): string {
        $h = 'sg_h';
        $abbrechbar = !in_array($v['status'], ['uebertragen', 'abgebrochen'], true);
        $stat = '<span class="us-status s-' . $h($v['status']) . '">' . $h(Seite::STATI[$v['status']] ?? $v['status']) . '</span>';
        if ($v['meldung'] !== '') $stat .= '<div class="muted klein-text">' . $h($v['meldung']) . '</div>';
        if ($v['status'] === 'wartet' && $v['kontext'] !== '' && !Seite::kontextGueltig($v)) $stat .= '<div class="muted klein-text">T2med-Verbindung abgelaufen: Das Ablegen braucht einen neuen Aufruf aus T2med.</div>';
        $suche = mb_strtolower($v['patient'] . ' ' . $v['titel'] . ' ' . (Seite::ARTEN[$v['art']] ?? $v['art']));
        return '<tr data-art="' . $h($v['art']) . '" data-status="' . $h($v['status']) . '" data-suche="' . $h($suche) . '"><td>' . $h(self::datum((int)$v['erstellt'])) . '</td><td>' . $h($v['patient']) . '</td><td>' . $h($v['titel']) . '</td><td>'
            . $h($padNamen[(int)($v['pad_id'] ?? 0)] ?? '–') . '</td><td>' . $stat . '</td><td class="us-aktionen">' . self::aktionen($v, $csrfFeld, $darfVorgang) . '</td></tr>';
    }

    /** Knöpfe zu einem Vorgang je nach Status. */
    private static function aktionen(array $v, string $csrfFeld, bool $darfVorgang): string {
        if (!$darfVorgang) return '';
        $o = '';
        if (in_array($v['status'], ['wartet', 'fehler'], true) && (int)($v['pad_id'] ?? 0) > 0) $o .= self::knopf($csrfFeld, 'am-pad', (int)$v['id'], 'Am Pad zeigen', 'primary small');
        if ($v['status'] === 'am_pad') $o .= self::knopf($csrfFeld, 'vom-pad', (int)$v['id'], 'Vom Pad nehmen', 'small');
        if (!in_array($v['status'], ['uebertragen', 'abgebrochen'], true)) $o .= self::knopf($csrfFeld, 'abbrechen', (int)$v['id'], 'Abbrechen', 'danger small');
        if ($v['status'] === 'abgebrochen') $o .= self::knopf($csrfFeld, 'vorgang-loeschen', (int)$v['id'], 'Löschen', 'danger small', 'Diesen abgebrochenen Vorgang endgültig löschen?');
        return $o;
    }

    public static function seite(string $route, string $csrf, array $vorgaenge, array $vorlagen, bool $darfVorgang, bool $darfVorlage, ?string $meldung, ?string $fehler, ?array $bearbeite = null, ?array $pad = null, array $pads = [], ?array $aktuell = null): string {
        $h = 'sg_h';
        $csrfFeld = '<input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '">';
        $admin = $pad !== null;
        $o = '<div class="us">' . self::kopfzeile($route, $darfVorlage, $admin);
        if ($meldung) $o .= '<p class="ok" role="status">' . $h($meldung) . '</p>';
        if ($fehler) $o .= '<p class="fehler" role="alert">' . $h($fehler) . '</p>';
        $padNamen = [];
        foreach ($pads as $p) $padNamen[(int)$p['id']] = $p['name'];

        if ($route === '/vorgaenge') return $o . self::vorgangsliste($csrfFeld, $vorgaenge, $padNamen, $darfVorgang) . '</div>';
        if ($route === '/einstellungen') {
            if ($darfVorlage) $o .= self::vorlagen($csrfFeld, $vorlagen, $bearbeite);
            if ($admin) $o .= self::pads($csrfFeld, $pads) . self::pad($csrfFeld, $pad);
            return $o . '</div>';
        }

        // Start: der Vorgang, der gerade am Pad ist (sonst der zuletzt angelegte offene), dann „Neuer Vorgang“.
        $o .= '<section class="card us-aktuell"><h2>' . ($aktuell && $aktuell['status'] === 'am_pad' ? 'Gerade am Pad' : 'Zuletzt angelegter Vorgang') . '</h2>';
        if (!$aktuell) {
            $o .= '<p class="muted">Kein offener Vorgang. Unten einen neuen anlegen.</p>';
        } else {
            $stat = '<span class="us-status s-' . $h($aktuell['status']) . '">' . $h(Seite::STATI[$aktuell['status']] ?? $aktuell['status']) . '</span>';
            $o .= '<div class="us-patient"><div><strong class="us-name">' . $h($aktuell['patient']) . '</strong><div class="muted">' . $h($aktuell['titel']) . ' · angelegt ' . $h(self::datum((int)$aktuell['erstellt'])) . '</div>'
                . '<div class="muted">Pad: ' . $h($padNamen[(int)($aktuell['pad_id'] ?? 0)] ?? 'nicht zugeordnet') . '</div></div><div>' . $stat
                . ($aktuell['meldung'] !== '' ? '<div class="muted klein-text">' . $h($aktuell['meldung']) . '</div>' : '') . '</div></div>'
                . '<div class="aktionen">' . self::aktionen($aktuell, $csrfFeld, $darfVorgang) . '</div>';
        }
        $o .= '</section>';

        $aktive = array_values(array_filter($vorlagen, fn($v) => (int)$v['aktiv']));
        if ($darfVorgang) {
            $opt = '';
            foreach ($aktive as $v) $opt .= '<option value="' . (int)$v['id'] . '">' . $h(Seite::ARTEN[$v['art']] ?? $v['art']) . ': ' . $h($v['titel']) . '</option>';
            $padOpt = '';
            $aktivePads = array_values(array_filter($pads, fn($p) => (int)$p['aktiv']));
            if (count($aktivePads) > 1) {
                $padOpt = '<label for="n-pad">Pad (Arbeitsplatz)</label><select id="n-pad" name="pad_id" required><option value="">Bitte wählen</option>';
                foreach ($aktivePads as $p) $padOpt .= '<option value="' . (int)$p['id'] . '">' . $h($p['name'] . ($p['arbeitsplatz'] !== '' ? ' (' . $p['arbeitsplatz'] . ')' : '')) . '</option>';
                $padOpt .= '</select>';
            }
            $o .= '<section class="card"><h2>Neuer Vorgang</h2>';
            if (!$aktive) {
                $o .= '<p class="muted">Es gibt keine aktive Vorlage. Bitte unter Einstellungen eine Vorlage anlegen oder aktivieren.</p>';
            } else {
                $o .= '<form method="post" class="us-form" autocomplete="off">' . $csrfFeld . '<input type="hidden" name="aktion" value="neu"><input type="hidden" name="kontext" id="n-kontext" value="">'
                    . '<label for="n-vorlage">Dokument</label><select id="n-vorlage" name="vorlage_id">' . $opt . '</select>'
                    . $padOpt
                    . '<label for="n-patient">Patient</label><input id="n-patient" name="patient" maxlength="80" required placeholder="z. B. Max Mustermann">'
                    . '<label for="n-leistung">Leistung oder Thema</label><input id="n-leistung" name="leistung" maxlength="120" placeholder="z. B. Ultraschall der Schilddrüse">'
                    . '<label for="n-betrag">Betrag in Euro</label><input id="n-betrag" name="betrag" inputmode="decimal" maxlength="12" placeholder="z. B. 45,00">'
                    . '<label class="check"><input type="checkbox" name="druck_vorher" value="1"> Dokument vor der Unterschrift für den Patienten drucken</label>'
                    . '<label class="check"><input type="checkbox" name="druck_nachher" value="1"> Unterschriebenes Dokument danach drucken</label>'
                    . '<button class="primary" type="submit">Vorgang anlegen</button></form>';
            }
            $o .= '</section>';
        }
        return $o . '</div>';
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
        $o .= '</select></div><p class="muted" id="vg-zahl" role="status" aria-live="polite"></p>';
        if (!$vorgaenge) return $o . '<p class="muted">Noch keine Vorgänge.</p></section>';
        $o .= '<table class="us-tabelle" id="vg-tabelle"><thead><tr><th>Angelegt</th><th>Patient</th><th>Dokument</th><th>Pad</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($vorgaenge as $v) $o .= self::zeile($v, $padNamen, $csrfFeld, $darfVorgang);
        return $o . '</tbody></table><p class="muted" id="vg-leer" hidden>Kein Vorgang passt zu Suche und Filtern.</p></section>';
    }

    private static function knopf(string $csrfFeld, string $aktion, int $id, string $text, string $klasse, string $frage = ''): string {
        return '<form method="post" class="inline">' . $csrfFeld . '<input type="hidden" name="aktion" value="' . $aktion . '"><input type="hidden" name="id" value="' . $id . '"><button class="' . $klasse . '" type="submit"' . ($frage !== '' ? ' data-bestaetigen="' . sg_h($frage) . '"' : '') . '>' . $text . '</button></form> ';
    }

    /** Bereich „Unterschriftenpads“ nur für Admins: ein Pad je Arbeitsplatz mit Name, Adresse und Port. */
    private static function pads(string $csrfFeld, array $pads): string {
        $h = 'sg_h';
        $o = '<details class="fold" id="pads"' . ($pads ? '' : ' open') . '><summary><h2>Unterschriftenpads</h2></summary><div class="us-body">'
            . '<p class="muted">Je Arbeitsplatz mit Pad einen Eintrag. Das Delta muss im Ethernet-Modus laufen (Servicemenü am Pad); Adresse und Port stehen dort. Bei mehreren aktiven Pads wählt man beim Anlegen eines Vorgangs, an welchem unterschrieben wird.</p>';
        $feld = static function (array $p, string $csrfFeld, string $knopf) use ($h): string {
            $i = (int)($p['id'] ?? 0);
            return '<form method="post" class="us-pad-zeile" autocomplete="off">' . $csrfFeld . '<input type="hidden" name="aktion" value="pad-speichern"><input type="hidden" name="id" value="' . $i . '">'
                . '<label>Name<input name="name" maxlength="40" required value="' . $h($p['name'] ?? '') . '" placeholder="z. B. Pad Empfang"></label>'
                . '<label>Arbeitsplatz<input name="arbeitsplatz" maxlength="60" value="' . $h($p['arbeitsplatz'] ?? '') . '" placeholder="z. B. Anmeldung"></label>'
                . '<label>Adresse<input name="adresse" maxlength="100" required value="' . $h($p['adresse'] ?? '') . '" placeholder="z. B. 192.0.2.10"></label>'
                . '<label>Port<input name="port" inputmode="numeric" maxlength="5" value="' . $h((string)($p['port'] ?? 1002)) . '"></label>'
                . '<label class="check"><input type="checkbox" name="aktiv" value="1"' . (!isset($p['aktiv']) || (int)$p['aktiv'] ? ' checked' : '') . '> aktiv</label>'
                . '<button class="primary small" type="submit">' . $knopf . '</button>'
                . ($i > 0 ? '<button class="danger small" type="submit" name="aktion" value="pad-loeschen" formnovalidate data-bestaetigen="Dieses Pad wirklich löschen?">Löschen</button>' : '') . '</form>';
        };
        foreach ($pads as $p) $o .= $feld($p, $csrfFeld, 'Speichern');
        $o .= '<h3>Neues Pad</h3>' . $feld([], $csrfFeld, 'Pad hinzufügen');
        return $o . '</div></details>';
    }

    /** Bereich „Pad-Software“ nur für Admins: Anleitung, Lizenzhinweis, Upload der ZIP-Datei von signotec. */
    private static function pad(string $csrfFeld, array $pad): string {
        $h = 'sg_h';
        $stand = $pad['installiert']
            ? '<p class="ok" role="status">Installiert' . ($pad['version'] !== '' ? ': signoPAD-API Linux ' . $h($pad['version']) : '') . ($pad['zeit'] > 0 ? ', hochgeladen am ' . $h(date('d.m.Y H:i', (int)$pad['zeit'])) : '') . ($pad['von'] !== '' ? ' von ' . $h($pad['von']) : '') . '.</p>'
            : '<p class="muted">Noch nicht hochgeladen. Ohne diese Software kann das Pad nicht angesprochen werden.</p>';
        $o = '<details class="fold" id="pad"><summary><h2>Pad-Software von signotec</h2></summary><div class="us-body">' . $stand
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
        $platz = implode(' ', array_map(fn($k) => '<code>{{' . $h($k) . '}}</code>', array_keys(Seite::PLATZHALTER)));
        $o = '<details class="fold" id="vorlagen"' . ($bearbeite ? ' open' : '') . '><summary><h2>Vorlagen und Vertragstexte</h2></summary><div class="us-body">'
            . '<p class="muted">Platzhalter im Text: ' . $platz . '. Sie werden beim Anlegen des Vorgangs eingesetzt; der Text bleibt danach unverändert. '
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

    private static function vorlagenForm(string $csrfFeld, array $v): string {
        $h = 'sg_h';
        $id = (int)$v['id'];
        $art = '';
        foreach (Seite::ARTEN as $k => $l) $art .= '<option value="' . $h($k) . '"' . ($k === $v['art'] ? ' selected' : '') . '>' . $h($l) . '</option>';
        $o = '<form method="post" class="us-form">' . $csrfFeld . '<input type="hidden" name="aktion" value="vorlage-speichern"><input type="hidden" name="id" value="' . $id . '">'
            . '<label for="v' . $id . '-art">Art</label><select id="v' . $id . '-art" name="art">' . $art . '</select>'
            . '<label for="v' . $id . '-titel">Titel</label><input id="v' . $id . '-titel" name="titel" maxlength="80" required value="' . $h((string)$v['titel']) . '">'
            . '<label for="v' . $id . '-text">Text</label><textarea id="v' . $id . '-text" name="text" rows="12" maxlength="' . Seite::MAX_TEXT . '" required>' . $h((string)$v['text']) . '</textarea>'
            . '<label class="check"><input type="checkbox" name="aktiv" value="1"' . ((int)$v['aktiv'] ? ' checked' : '') . '> Aktiv (in „Neuer Vorgang“ wählbar)</label>'
            . '<div class="aktionen"><button class="primary" type="submit">Speichern</button></div></form>';
        if ($id > 0) $o .= '<form method="post" class="inline us-loeschen">' . $csrfFeld . '<input type="hidden" name="aktion" value="vorlage-loeschen"><input type="hidden" name="id" value="' . $id . '"><button class="danger small" type="submit" data-bestaetigen="Vorlage endgültig löschen? Bestehende Vorgänge behalten ihren Text.">Vorlage löschen</button></form>';
        return $o;
    }
}
