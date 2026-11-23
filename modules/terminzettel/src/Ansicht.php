<?php
declare(strict_types=1);
namespace KienzleTerminzettel;

/** HTML der Einstellungsseite (kein Inline-Skript, CSP script-src 'self'). */
final class Ansicht {
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
        $fett = fn(string $key): string => '<label class="tog" title="Fett drucken"><input type="checkbox" name="' . $key . '" value="1"' . ($values[$key] ? ' checked' : '') . '><span><b>F</b>ett</span></label>';
        $format = fn(string $sizeKey, string $boldKey, string $name): string => '<div class="fmt"><span class="sm">Schrift</span>' . $seg($sizeKey, $groessen, $name . ': Schriftgröße') . $fett($boldKey) . '</div>';
        $logo = Seite::logoDaten();
        $logoZeile = '<div class="logo"><div class="logo-bild">' . ($logo !== '' ? '<img src="' . $h($logo) . '" alt="Aktuelles Logo">' : '<span class="muted">Kein Logo</span>') . '</div><div>'
            . '<input type="file" name="logo" accept="image/png,image/jpeg,image/gif" form="logoform" aria-label="Logo-Datei">'
            . '<div class="aktionen"><button class="zweit klein" name="aktion" value="logo" form="logoform">Hochladen</button>'
            . ($logo !== '' ? '<button class="zweit klein" name="aktion" value="logo-entfernen" form="logoform">Entfernen</button>' : '') . '</div>'
            . '<p class="muted klein-text">PNG, JPEG oder GIF bis 2 MB. Wird in Schwarz-Weiß umgerechnet; einfache, kontrastreiche Logos drucken am besten. Beim Hochladen wird die Seite neu geladen: ungespeicherte Änderungen vorher speichern.</p></div></div>';
        $form = '<section class="card">' . $check('enabled', 'Terminzettel aus T2med auf dem Bondrucker drucken') . '</section>'
            . '<section class="card"><h2><span class="ico" aria-hidden="true">▣</span> Kopf</h2>'
            . '<div class="teil"><h3>Logo</h3>' . $logoZeile . '<div class="fmt">' . $check('logo_enabled', 'Logo drucken') . '<span class="sm">Breite</span>' . $seg('logo_size', Seite::LOGO_SIZES, 'Logo: Breite') . '</div></div>'
            . '<div class="teil"><h3>Praxisname</h3>' . $text('name_text', 'Erste Zeile') . $format('name_size', 'name_bold', 'Praxisname') . '</div>'
            . '<div class="teil"><h3>Adressblock</h3>' . $area('address_text', 'Straße, Ort, Kontakt (eine Angabe je Zeile; leer = keiner)', 3) . $format('address_size', 'address_bold', 'Adressblock') . '</div></section>'
            . '<section class="card"><h2><span class="ico" aria-hidden="true">▤</span> Termine</h2>'
            . '<div class="teil"><h3>Überschrift</h3>' . $text('heading', 'Text') . '<div class="fmt"><span class="sm">Schrift</span>' . $seg('heading_size', $groessen, 'Überschrift: Schriftgröße') . '</div></div>'
            . '<div class="teil"><h3>Terminliste</h3>' . $check('show_patient', 'Patientennamen drucken') . '<div class="fmt"><span class="sm">Schrift</span>' . $seg('appointment_size', $groessen, 'Termine: Schriftgröße') . '</div></div></section>'
            . '<section class="card"><h2><span class="ico" aria-hidden="true">▥</span> Endtext</h2>' . $area('footer_text', 'Text unter den Terminen (leer = keiner)', 3)
            . '<div class="fmt"><span class="sm">Schrift</span>' . $seg('footer_size', $groessen, 'Endtext: Schriftgröße') . '</div></section>'
            . '<section class="card"><h2><span class="ico" aria-hidden="true">▦</span> Kalender-QR</h2>' . $check('qr_enabled', 'Kalender-QR je Termin drucken')
            . '<div class="fmt"><span class="sm">Position</span>' . $seg('qr_position', ['below' => ['Unter', 'Unter dem Termin (mittig)'], 'right' => ['Rechts', 'Rechts neben dem Termin'], 'left' => ['Links', 'Links neben dem Termin']], 'Position des QR-Codes')
            . '<span class="sm">Größe</span>' . $seg('qr_size', ['small' => 'Klein', 'medium' => 'Mittel', 'large' => 'Groß'], 'Größe des QR-Codes') . '</div>'
            . $text('qr_summary', 'Kalender-Titel (Präfix)') . '</section>'
            . '<section class="card">' . $check('cut', 'Bon abschneiden') . '<p><button>Speichern</button></p></section>';
        $printer = 'http://' . $host . ':631/printers/Terminzettel';
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="kienzle-csrf" content="' . $h($csrf) . '"><title>Terminzettel</title><link rel="stylesheet" href="' . $h($base) . '/assets/style.css"></head><body>'
            . '<main><header class="top"><a class="brand" href="/"><span class="mark" aria-hidden="true">K</span><div><strong>Terminzettel</strong>'
            . '<span class="sub">Einstellungen · Kienzle-Suite</span></div></a></header>'
            . ($meldung ? '<p class="ok">' . $h($meldung) . '</p>' : '') . ($fehler ? '<p class="fehler">' . $h($fehler) . '</p>' : '')
            . ($queue === '' ? '<p class="fehler">Noch kein Bondrucker zugeordnet: Verwaltung → Drucker → „Wofür welcher Drucker?“.</p>'
                : '<p class="muted">Bondrucker: <strong>' . $h($queue) . '</strong> (Verwaltung → Drucker).</p>')
            . '<div class="gitter"><div><form method="post" action="' . $h($base) . '/" class="stack" id="layout"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="speichern">'
            . '<p class="muted">Papierbreite des Bondruckers: <strong>' . Seite::bonBreite() . ' mm</strong> – zentral einzustellen unter <a href="/verwaltung/drucker">Verwaltung → Drucker</a>.</p>'
            . $form . '</form>'
            . '<form method="post" action="' . $h($base) . '/" enctype="multipart/form-data" id="logoform"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"></form></div>'
            . '<aside class="card"><h2>Vorschau (Beispieldaten)</h2><div id="vorschau" class="vorschau"><img id="bild" alt="Vorschau des Bons" hidden><p id="hinweis" class="muted">Wird geladen …</p></div><p id="ungespeichert" class="fehler" hidden>Ungespeicherte Änderungen: Die Vorschau zeigt den Stand des Formulars. Gedruckt wird erst nach „Speichern“.</p>'
            . '<form method="post" action="' . $h($base) . '/"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="testdruck">'
            . '<button class="zweit"' . ($queue === '' ? ' disabled' : '') . '>Beispiel-Terminzettel drucken</button></form></aside></div>'
            . '<details open><summary>Letzte Druckaufträge</summary>' . (($log = Seite::protokoll()) ? '<pre>' . $h(implode("\n", $log)) . '</pre>'
                : '<p class="muted">Noch kein Auftrag angekommen. Kommt nach dem Drucken aus T2med hier nichts an, erreicht der Auftrag den virtuellen Drucker „Terminzettel“ nicht (Arbeitsplatz, Treiber, Firewall).</p>') . '</details>'
            . '<details><summary>Drucker „Terminzettel“ auf den Arbeitsplätzen einrichten</summary><ol>'
            . '<li>Windows: Drucker hinzufügen → „Freigegebenen Drucker über den Namen auswählen“: <code>' . $h($printer) . '</code> – Treiber „Microsoft IPP Class Driver“, Papier A6. Per IP-Adresse (z. B. aus dem VPN) statt des Namens: <code>http://&lt;IP-Adresse&gt;:631/printers/Terminzettel</code>.</li>'
            . '<li>Windows 11: Fehlt der Treiber „Microsoft IPP Class Driver“ oder akzeptiert Windows die Adresse nicht, PowerShell als Administrator öffnen und ausführen:<pre>' . $h("Enable-WindowsOptionalFeature -Online -FeatureName Printing-Foundation-InternetPrinting-Client -NoRestart | Out-Null\n# Druckspooler neu starten und auf den laufenden Dienst warten; alten Drucker entfernen (behebt „Das Handle ist ungültig“)\nRestart-Service Spooler -Force\n(Get-Service Spooler).WaitForStatus('Running', '00:00:30')\nStart-Sleep 3\nGet-Printer -Name \"Terminzettel\" -ErrorAction SilentlyContinue | Remove-Printer\nAdd-PrinterDriver -Name \"Microsoft IPP Class Driver\"\n\$url = \"" . $printer . "\"\n# Anschluss und Drucker anlegen, bis zu viermal (direkt nach dem Neustart des Spoolers ist der Anschluss manchmal noch nicht nutzbar)\nfor (\$i = 1; \$i -le 4 -and -not (Get-Printer -Name \"Terminzettel\" -ErrorAction SilentlyContinue); \$i++) {\n  Add-PrinterPort -Name \$url -ErrorAction SilentlyContinue\n  Start-Sleep 3\n  Add-Printer -Name \"Terminzettel\" -DriverName \"Microsoft IPP Class Driver\" -PortName \$url -ErrorAction SilentlyContinue\n}\nGet-Printer -Name \"Terminzettel\" | Select-Object Name, DriverName, PortName") . '</pre></li>'
            . '<li>Druckereigenschaften lassen sich nicht öffnen („Das Handle ist ungültig“) oder der Druck bleibt aus: Die Windows-Einrichtung ist veraltet, z. B. nach Neueinrichtung des Servers. Drucker entfernen und mit dem PowerShell-Skript oben neu einrichten (es startet den Druckspooler neu und entfernt den alten Drucker). Eine Meldung beim Löschen alter Anschlüsse („Anschluss unbekannt“) ist harmlos. Möglichst die IP-Adresse statt des Namens verwenden.</li>'
            . '<li>Mac: Drucker hinzufügen → <strong>Terminzettel</strong> wählen, als Treiber die Datei <a href="' . $h($base) . '/assets/Terminzettel-PDF.ppd" download>Terminzettel-PDF.ppd</a> verwenden.</li>'
            . '<li>In T2med unter Einstellungen → Kalender → Terminliste (DIN A6) den Drucker „Terminzettel“ wählen.</li>'
            . '<li>Voraussetzung: Die <a href="/verwaltung/firewall">Firewall</a> der Basis lässt Drucken (IPP, Port 631) aus dem Praxisnetz zu; beim virtuellen Drucker ist unter Verwaltung → Drucker die Freigabe (IPP) angehakt.</li></ol></details>'
            . '</main><script src="' . $h($base) . '/assets/app.js"></script></body></html>';
    }
}
