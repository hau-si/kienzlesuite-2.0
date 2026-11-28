<?php
declare(strict_types=1);
namespace KienzleTerminzettel;

/** HTML der Einstellungsseite (kein Inline-Skript, CSP script-src 'self'). */
final class Ansicht {
    /** Zusätzliche Tags im Kopf der Seite im Rahmen von Praxisherz: Stil, Skript und CSRF-Marke. Dateien tragen eine Versionskennung (sonst zeigt der Browser nach einem Update eine Stunde lang das alte Skript). */
    public static function kopf(string $base, string $csrf): string {
        $v = static fn(string $f): string => '?v=' . substr((string)@md5_file(dirname(__DIR__) . '/public/assets/' . $f), 0, 8);
        return '<meta name="kienzle-csrf" content="' . tz_h($csrf) . '"><link rel="stylesheet" href="' . tz_h($base) . '/assets/style.css' . $v('style.css') . '">'
            . '<script src="' . tz_h($base) . '/assets/app.js' . $v('app.js') . '" defer></script>';
    }

    public static function seite(string $base, array $values, string $csrf, string $queue, ?string $meldung, ?string $fehler, string $host): string {
        $h = 'tz_h';
        $tip = static fn(string $key): string => isset(Seite::HILFE[$key]) ? ' <span class="hilfe" tabindex="0" role="note" aria-label="' . tz_h(Seite::HILFE[$key]) . '" data-tip="' . tz_h(Seite::HILFE[$key]) . '">i</span>' : '';
        $text = fn(string $key, string $label, int $max = 80): string => '<label for="f-' . $key . '">' . $h($label) . $tip($key) . '</label><input id="f-' . $key . '" name="' . $key . '" value="' . $h((string)$values[$key]) . '" maxlength="' . $max . '">';
        $area = fn(string $key, string $label, int $rows): string => '<label for="f-' . $key . '">' . $h($label) . '</label><textarea id="f-' . $key . '" name="' . $key . '" rows="' . $rows . '" maxlength="1000">' . $h((string)$values[$key]) . '</textarea>';
        $check = fn(string $key, string $label): string => '<label class="check"><input type="checkbox" id="f-' . $key . '" name="' . $key . '" value="1"' . ($values[$key] ? ' checked' : '') . '> ' . $h($label) . '</label>';
        // Kleine Auswahlleiste statt Aufklappliste: ein Klick, alle Möglichkeiten sichtbar.
        $seg = function (string $key, array $options, string $name) use ($values, $h): string {
            $o = '';
            foreach ($options as $val => $label) {
                [$kurz, $lang] = is_array($label) ? $label : [$label, $label];
                $o .= '<label title="' . $h($lang) . '"><input type="radio" name="' . $key . '" value="' . $h((string)$val) . '"' . ((string)$val === (string)$values[$key] ? ' checked' : '') . '><span>' . $h($kurz) . '</span></label>';
            }
            return '<span class="seg" role="radiogroup" aria-label="' . $h($name) . '">' . $o . '</span>';
        };
        $groessen = [];
        foreach (Seite::SIZES as $val => $label) $groessen[$val] = [str_replace('x', '×', $val), $label];
        $fett = fn(string $key): string => '<label class="tog" title="Fett drucken"><input type="checkbox" name="' . $key . '" value="1"' . ($values[$key] ? ' checked' : '') . '><span><b>F</b></span></label>';
        $format = fn(string $sizeKey, string $boldKey, string $name): string => '<div class="fmt"><span class="sm">Schrift</span>' . $seg($sizeKey, $groessen, $name . ': Schriftgröße') . $fett($boldKey) . '</div>';
        // Tabler-Symbole (Strichsymbole, wie in der Basis), inline wegen CSP.
        $sym = static fn(string $d): string => '<span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $d . '</svg></span>';
        $box = '<path d="M4 4m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z"/>';
        $iKopf = $sym($box . '<path d="M4 9l16 0"/>');
        $iTermine = $sym('<path d="M4 5m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z"/><path d="M16 3l0 4"/><path d="M8 3l0 4"/><path d="M4 11l16 0"/><path d="M8 15h2v2h-2z"/>');
        $iEnde = $sym($box . '<path d="M4 15l16 0"/>');
        $iQr = $sym('<path d="M4 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z"/><path d="M7 17l0 .01"/><path d="M14 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z"/><path d="M7 7l0 .01"/><path d="M4 14m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z"/><path d="M17 7l0 .01"/><path d="M14 14l3 0"/><path d="M20 14l0 .01"/><path d="M14 14l0 3"/><path d="M14 20l3 0"/><path d="M17 17l3 0"/><path d="M20 17l0 3"/>');
        $iLog = $sym('<path d="M13 5h8"/><path d="M13 9h5"/><path d="M13 15h8"/><path d="M13 19h5"/><path d="M3 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z"/><path d="M3 14m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z"/>');
        $iDrucker = $sym('<path d="M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2"/><path d="M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4"/><path d="M7 13m0 2a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2z"/>');
        $logo = Seite::logoDaten();
        $logoZeile = '<div class="logo"><div class="logo-bild">' . ($logo !== '' ? '<img src="' . $h($logo) . '" alt="Aktuelles Logo">' : '<span class="muted">Kein Logo</span>') . '</div><div>'
            . '<input type="file" id="logo-datei" accept="image/png,image/jpeg,image/gif" aria-label="Logo-Datei">'
            . '<div class="aktionen"><button type="button" class="zweit klein" id="logo-los" data-ziel="' . $h($base) . '/logo">Hochladen</button>'
            . ($logo !== '' ? '<button class="zweit klein" name="aktion" value="logo-entfernen" form="logoform">Entfernen</button>' : '') . '</div>'
            . '<p class="muted klein-text" id="logo-status" role="status"></p><p class="muted klein-text">PNG, JPEG oder GIF bis 2 MB. Wird in Schwarz-Weiß umgerechnet; einfache, kontrastreiche Logos drucken am besten. Beim Hochladen wird die Seite neu geladen: ungespeicherte Änderungen vorher speichern.</p></div></div>';
        $form = '<details class="fold" id="kopf"><summary><h2>' . $iKopf . ' Kopf</h2></summary><div class="tz-body">'
            . '<div class="teil">' . $logoZeile . '<div class="fmt">' . $check('logo_enabled', 'Logo drucken') . '<span class="sm">Breite</span>' . $seg('logo_size', Seite::LOGO_SIZES, 'Logo: Breite') . '<span class="sm">Lage</span>' . $seg('logo_align', Seite::LOGO_ALIGNS, 'Logo: Ausrichtung') . '</div></div>'
            . '<div class="teil">' . $text('name_text', 'Praxisname (erste Zeile)') . $format('name_size', 'name_bold', 'Praxisname') . '</div>'
            . '<div class="teil">' . $area('address_text', 'Adressblock (Straße, Ort, Kontakt, eine Angabe je Zeile)', 3) . $format('address_size', 'address_bold', 'Adressblock') . '</div></div></details>'
            . '<details class="fold"><summary><h2>' . $iTermine . ' Termine</h2></summary><div class="tz-body">'
            . '<div class="teil">' . $text('heading', 'Überschrift') . '<div class="fmt"><span class="sm">Schrift</span>' . $seg('heading_size', $groessen, 'Überschrift: Schriftgröße') . '</div></div>'
            . '<div class="teil">' . $check('show_patient', 'Patientennamen drucken') . '<div class="fmt"><span class="sm">Terminliste</span>' . $seg('appointment_size', $groessen, 'Termine: Schriftgröße') . '</div></div></div></details>'
            . '<details class="fold"><summary><h2>' . $iEnde . ' Endtext</h2></summary><div class="tz-body">' . $area('footer_text', 'Text unter den Terminen (leer = keiner)', 3)
            . '<div class="fmt"><span class="sm">Schrift</span>' . $seg('footer_size', $groessen, 'Endtext: Schriftgröße') . '</div></div></details>'
            . '<details class="fold"><summary><h2>' . $iQr . ' Kalender-QR</h2></summary><div class="tz-body">' . $check('qr_enabled', 'Kalender-QR je Termin drucken')
            . '<div class="fmt"><span class="sm">Position</span>' . $seg('qr_position', ['below' => ['Unter', 'Unter dem Termin (mittig)'], 'right' => ['Rechts', 'Rechts neben dem Termin'], 'left' => ['Links', 'Links neben dem Termin']], 'Position des QR-Codes')
            . '<span class="sm">Größe</span>' . $seg('qr_size', ['small' => 'Klein', 'medium' => 'Mittel', 'large' => 'Groß'], 'Größe des QR-Codes') . '</div>'
            . $text('qr_summary', 'Kalender-Titel (Präfix)') . '</div></details>'
            . '<details class="fold"><summary><h2>' . $iQr . ' Absage per E-Mail</h2></summary><div class="tz-body">'
            . '<p class="muted klein-text">Unten auf dem Bon steht ein QR-Code. Wer ihn mit dem Handy scannt, bekommt eine vorbereitete E-Mail an die Praxis (Empfänger, Betreff und Text wie unten) und kann den Termin damit absagen. Keine Patientendaten eintragen.</p>'
            . $check('cancel_enabled', 'Absage-QR unten auf dem Bon drucken')
            . $text('cancel_to', 'Empfänger (E-Mail-Adresse der Praxis)') . $text('cancel_subject', 'Betreff', 100)
            . $area('cancel_body', 'Nachrichtentext (höchstens 400 Zeichen)', 5)
            . '<div class="fmt" role="group" aria-label="Platzhalter einfügen"><span class="sm">Einfügen</span>'
            . '<button type="button" class="zweit klein" data-platzhalter="{{patient}}" title="Name des Patienten">Patient</button>'
            . '<button type="button" class="zweit klein" data-platzhalter="{{termine}}" title="Alle Termine der Liste mit Art">Alle Termine</button>'
            . '<button type="button" class="zweit klein" data-platzhalter="{{termine_kurz}}" title="Alle Termine nur mit Tag und Uhrzeit">Termine kurz</button>'
            . '<button type="button" class="zweit klein" data-platzhalter="{{erster_termin}}" title="Nur der erste Termin">Erster Termin</button></div>'
            . '<p class="muted klein-text">Die Knöpfe fügen den Platzhalter in das zuletzt angeklickte Feld (Betreff oder Text) ein. Im Betreff ist nur der Patient sinnvoll. Passt die Terminliste nicht mehr in den QR-Code, wird sie automatisch gekürzt, zuletzt weggelassen.</p>' . $text('cancel_caption', 'Aufforderung im Balken über dem QR-Code')
            . '<div class="fmt"><span class="sm">Größe</span>' . $seg('cancel_size', ['small' => 'Klein', 'medium' => 'Mittel', 'large' => 'Groß'], 'Größe des Absage-QR-Codes') . '</div></div></details>'
            . '<div class="tz-zeile">' . $check('cut', 'Bon abschneiden') . '</div>';
        $printer = 'http://' . $host . ':631/printers/vTermine';
        return '<header class="seitenkopf"><div><p class="eyebrow">Terminzettel</p><h1>Einstellungen</h1></div></header><div class="tz">'
            . '<p class="muted tz-intro">In T2med wird die Terminliste (A6) an den virtuellen Drucker „vTermine“ geschickt. Das Ergebnis wird hier verarbeitet und über den Bondrucker gut lesbar ausgedruckt. Der Bondruck ist mit dem Modul automatisch aktiv. Den Drucker „vTermine“ richtet der Praxisherz-Agent auf jedem Windows-PC selbst ein (<a href="/hilfe/arbeitsplatz">Praxis → Arbeitsplätze</a>).</p>'
            . ($meldung ? '<p class="ok">' . $h($meldung) . '</p>' : '') . ($fehler ? '<p class="fehler">' . $h($fehler) . '</p>' : '')
            . ($queue === '' ? '<p class="fehler">Noch kein Bondrucker zugeordnet: <a href="/verwaltung/drucker">Praxis → Drucker</a> → „Wofür welcher Drucker?“.</p>'
                : '<p class="muted">Bondrucker: <strong>' . $h($queue) . '</strong> (Praxis → Drucker).</p>')
            . '<div class="tz-gitter"><div class="tz-links"><form method="post" action="' . $h($base) . '/" class="stack" id="layout"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="speichern">'
            . '<p class="muted">Papierbreite des Bondruckers: <strong>' . Seite::bonBreite() . ' mm</strong> – zentral einzustellen unter <a href="/verwaltung/drucker">Praxis → Drucker</a>.</p>'
            . $form . '<div class="tz-speichern"><button>Speichern</button><span class="muted">Gedruckt wird erst nach dem Speichern.</span></div></form>'
            . '<form method="post" action="' . $h($base) . '/" id="logoform"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"></form></div>'
            . '<aside class="tz-rechts"><h2>Vorschau (Beispieldaten)</h2><div id="vorschau" class="vorschau"><img id="bild" alt="Vorschau des Bons" hidden><p id="hinweis" class="muted">Wird geladen …</p></div><p id="ungespeichert" class="fehler" hidden>Ungespeicherte Änderungen: Die Vorschau zeigt den Stand des Formulars. Gedruckt wird erst nach „Speichern“.</p>'
            . '<form method="post" action="' . $h($base) . '/"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="testdruck">'
            . '<button class="zweit"' . ($queue === '' ? ' disabled' : '') . '>Beispiel-Terminzettel drucken</button></form></aside></div>'
            . (static function () use ($h, $iLog): string {
                $p = Seite::druckauftraege();
                $inhalt = ($p['letzter'] ? '<h3>Letzter Auftrag</h3><pre>' . $h(implode("\n", $p['letzter'])) . '</pre>' : '')
                    . ($p['frueher'] ? '<h3>' . ($p['letzter'] ? 'Davor' : 'Protokoll') . '</h3><pre>' . $h(implode("\n", $p['frueher'])) . '</pre>' : '')
                    ?: '<p class="muted">Noch kein Auftrag angekommen. Kommt nach dem Drucken aus T2med hier nichts an, erreicht der Auftrag den virtuellen Drucker „vTermine“ nicht (Arbeitsplatz, Treiber, Firewall). Am Windows-PC hilft im Praxisherz-Symbol der Taskleiste „Drucker prüfen“.</p>';
                return '<details class="fold"><summary><h2>' . $iLog . ' Druckaufträge und Dauer der Schritte</h2></summary><div class="tz-body">' . $inhalt . '</div></details>';
            })()
            . '<details class="fold" id="drucker"><summary><h2>' . $iDrucker . ' Drucker „vTermine“ auf den Arbeitsplätzen einrichten</h2></summary><div class="tz-body">'
            . '<p>Diesen Drucker richtet der Praxisherz-Agent auf jedem Windows-PC selbst ein (<a href="/hilfe/arbeitsplatz">Praxis → Arbeitsplätze</a>). In T2med unter Einstellungen → Kalender → Terminliste (DIN A6) den Drucker „vTermine“ wählen.</p>'
            . '<details class="how"><summary>Von Hand einrichten (ohne Agent, Mac/Linux oder wenn etwas schiefgeht)</summary><ol>'
            . '<li>Windows: Drucker hinzufügen → „Freigegebenen Drucker über den Namen auswählen“: <code>' . $h($printer) . '</code> – Treiber „Microsoft IPP Class Driver“, Papier A6. Per IP-Adresse (z. B. aus dem VPN) statt des Namens: <code>http://&lt;IP-Adresse&gt;:631/printers/vTermine</code>.</li>'
            . '<li>Windows 11: Fehlt der Treiber „Microsoft IPP Class Driver“ oder akzeptiert Windows die Adresse nicht, PowerShell als Administrator öffnen und ausführen:<pre>' . $h("Enable-WindowsOptionalFeature -Online -FeatureName Printing-Foundation-InternetPrinting-Client -NoRestart | Out-Null\n# Druckspooler neu starten und auf den laufenden Dienst warten; alten Drucker entfernen (behebt „Das Handle ist ungültig“)\nRestart-Service Spooler -Force\n(Get-Service Spooler).WaitForStatus('Running', '00:00:30')\nStart-Sleep 3\nGet-Printer -Name \"Terminzettel\" -ErrorAction SilentlyContinue | Remove-Printer\nAdd-PrinterDriver -Name \"Microsoft IPP Class Driver\"\n\$url = \"" . $printer . "\"\n# Anschluss und Drucker anlegen, bis zu viermal (direkt nach dem Neustart des Spoolers ist der Anschluss manchmal noch nicht nutzbar)\nfor (\$i = 1; \$i -le 4 -and -not (Get-Printer -Name \"Terminzettel\" -ErrorAction SilentlyContinue); \$i++) {\n  Add-PrinterPort -Name \$url -ErrorAction SilentlyContinue\n  Start-Sleep 3\n  Add-Printer -Name \"Terminzettel\" -DriverName \"Microsoft IPP Class Driver\" -PortName \$url -ErrorAction SilentlyContinue\n}\nGet-Printer -Name \"Terminzettel\" | Select-Object Name, DriverName, PortName") . '</pre></li>'
            . '<li>Druckereigenschaften lassen sich nicht öffnen („Das Handle ist ungültig“) oder der Druck bleibt aus: Die Windows-Einrichtung ist veraltet, z. B. nach Neueinrichtung des Servers. Drucker entfernen und mit dem PowerShell-Skript oben neu einrichten (es startet den Druckspooler neu und entfernt den alten Drucker). Eine Meldung beim Löschen alter Anschlüsse („Anschluss unbekannt“) ist harmlos. Möglichst die IP-Adresse statt des Namens verwenden.</li>'
            . '<li>Mac: Drucker hinzufügen → <strong>vTermine</strong> wählen, als Treiber die Datei <a href="' . $h($base) . '/assets/vTermine-PDF.ppd" download>vTermine-PDF.ppd</a> verwenden.</li>'
            . '<li>In T2med unter Einstellungen → Kalender → Terminliste (DIN A6) den Drucker „vTermine“ wählen.</li>'
            . '<li>Voraussetzung: Die <a href="/verwaltung/firewall">Firewall</a> der Basis (Betrieb → Sicherheit) lässt Drucken (IPP, Port 631) aus dem Praxisnetz zu; der virtuelle Drucker ist per IPP immer freigegeben (Samba ist nur optional unter Praxis → Drucker).</li></ol></details></div></details>'
            . '</div>';
    }
}
