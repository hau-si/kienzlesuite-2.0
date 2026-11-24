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

    public static function seite(string $csrf, array $vorgaenge, array $vorlagen, bool $darfVorgang, bool $darfVorlage, ?string $meldung, ?string $fehler, ?array $bearbeite = null, ?array $pad = null): string {
        $h = 'sg_h';
        $csrfFeld = '<input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '">';
        $o = '<div class="us"><div class="seitenkopf"><div><p class="eyebrow">Signatur</p><h1>Unterschriften</h1></div></div>'
            . '<p class="us-intro muted">Dokumente am Unterschriftenpad unterschreiben lassen: Vorgang anlegen, der Patient unterschreibt sofort oder später, das PDF kommt in die T2med-Akte. '
            . 'Die Anbindung des Pads und das Ablegen in T2med sind noch nicht eingebaut; Vorlagen und Vorgänge können schon vorbereitet werden.</p>';
        if ($meldung) $o .= '<p class="ok" role="status">' . $h($meldung) . '</p>';
        if ($fehler) $o .= '<p class="fehler" role="alert">' . $h($fehler) . '</p>';

        $aktive = array_values(array_filter($vorlagen, fn($v) => (int)$v['aktiv']));
        if ($darfVorgang) {
            $opt = '';
            foreach ($aktive as $v) $opt .= '<option value="' . (int)$v['id'] . '">' . $h(Seite::ARTEN[$v['art']] ?? $v['art']) . ': ' . $h($v['titel']) . '</option>';
            $o .= '<section class="card"><h2>Neuer Vorgang</h2>';
            if (!$aktive) {
                $o .= '<p class="muted">Es gibt keine aktive Vorlage. Bitte unten eine Vorlage anlegen oder aktivieren.</p>';
            } else {
                $o .= '<form method="post" class="us-form" autocomplete="off">' . $csrfFeld . '<input type="hidden" name="aktion" value="neu">'
                    . '<label for="n-vorlage">Dokument</label><select id="n-vorlage" name="vorlage_id">' . $opt . '</select>'
                    . '<label for="n-patient">Patient</label><input id="n-patient" name="patient" maxlength="80" required placeholder="z. B. Max Mustermann">'
                    . '<label for="n-leistung">Leistung oder Thema</label><input id="n-leistung" name="leistung" maxlength="120" placeholder="z. B. Ultraschall der Schilddrüse">'
                    . '<label for="n-betrag">Betrag in Euro</label><input id="n-betrag" name="betrag" inputmode="decimal" maxlength="12" placeholder="z. B. 45,00">'
                    . '<label class="check"><input type="checkbox" name="druck_vorher" value="1"> Dokument vor der Unterschrift für den Patienten drucken</label>'
                    . '<label class="check"><input type="checkbox" name="druck_nachher" value="1"> Unterschriebenes Dokument danach drucken</label>'
                    . '<button class="primary" type="submit">Vorgang anlegen</button></form>';
            }
            $o .= '</section>';
        }

        $o .= '<section class="card" id="vorgaenge"><h2>Vorgänge</h2>';
        if (!$vorgaenge) {
            $o .= '<p class="muted">Noch keine Vorgänge.</p>';
        } else {
            $o .= '<table class="us-tabelle"><thead><tr><th>Angelegt</th><th>Patient</th><th>Dokument</th><th>Status</th><th></th></tr></thead><tbody>';
            foreach ($vorgaenge as $v) {
                $abbrechbar = !in_array($v['status'], ['uebertragen', 'abgebrochen'], true);
                $stat = '<span class="us-status s-' . $h($v['status']) . '">' . $h(Seite::STATI[$v['status']] ?? $v['status']) . '</span>';
                if ($v['meldung'] !== '') $stat .= '<div class="muted klein-text">' . $h($v['meldung']) . '</div>';
                if ($v['status'] === 'wartet' && $v['kontext'] !== '' && !Seite::kontextGueltig($v)) $stat .= '<div class="muted klein-text">T2med-Verbindung abgelaufen: Das Ablegen braucht einen neuen Aufruf aus T2med.</div>';
                $o .= '<tr><td>' . $h(self::datum((int)$v['erstellt'])) . '</td><td>' . $h($v['patient']) . '</td><td>' . $h($v['titel']) . '</td><td>' . $stat . '</td><td>'
                    . ($darfVorgang && $abbrechbar ? '<form method="post" class="inline">' . $csrfFeld . '<input type="hidden" name="aktion" value="abbrechen"><input type="hidden" name="id" value="' . (int)$v['id'] . '"><button class="danger small" type="submit">Abbrechen</button></form>' : '')
                    . '</td></tr>';
            }
            $o .= '</tbody></table>';
        }
        $o .= '</section>';

        if ($darfVorlage) $o .= self::vorlagen($csrfFeld, $vorlagen, $bearbeite);
        if ($pad !== null) $o .= self::pad($csrfFeld, $pad);
        return $o . '</div>';
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
