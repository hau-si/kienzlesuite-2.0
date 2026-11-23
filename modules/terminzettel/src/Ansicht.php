<?php
declare(strict_types=1);
namespace KienzleTerminzettel;

/** HTML der Einstellungsseite (kein Inline-Skript, CSP script-src 'self'). */
final class Ansicht {
    public static function seite(string $base, array $values, string $csrf, string $queue, ?string $meldung, ?string $fehler, string $host): string {
        $h = 'tz_h';
        $form = '';
        foreach (Seite::schema() as $key => $f) {
            $v = $values[$key];
            $id = 'f-' . $key;
            $tip = isset(Seite::HILFE[$key]) ? ' <span class="hilfe" tabindex="0" role="note" aria-label="' . $h(Seite::HILFE[$key]) . '" data-tip="' . $h(Seite::HILFE[$key]) . '">i</span>' : '';
            $form .= match ($f[0]) {
                'bool' => '<label class="check"><input type="checkbox" id="' . $id . '" name="' . $key . '" value="1"' . ($v ? ' checked' : '') . '> ' . $h($f[1]) . '</label>',
                'choice' => '<label>' . $h($f[1]) . '<select id="' . $id . '" name="' . $key . '">' . implode('', array_map(
                    fn($val, $label) => '<option value="' . $h((string)$val) . '"' . ((string)$val === $v ? ' selected' : '') . '>' . $h($label) . '</option>', array_keys($f[3]), $f[3])) . '</select></label>',
                'text' => '<label>' . $h($f[1]) . '<textarea id="' . $id . '" name="' . $key . '" rows="4" maxlength="1000">' . $h($v) . '</textarea></label>',
                default => '<label>' . $h($f[1]) . $tip . '<input id="' . $id . '" name="' . $key . '" value="' . $h($v) . '" maxlength="80"></label>',
            };
        }
        $printer = 'http://' . $host . ':631/printers/Terminzettel';
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="kienzle-csrf" content="' . $h($csrf) . '"><title>Terminzettel</title><link rel="stylesheet" href="' . $h($base) . '/assets/style.css"></head><body>'
            . '<main><header class="top"><a class="brand" href="/"><span class="mark" aria-hidden="true">K</span><div><strong>Terminzettel</strong>'
            . '<span class="sub">Einstellungen · Kienzle-Suite</span></div></a></header>'
            . ($meldung ? '<p class="ok">' . $h($meldung) . '</p>' : '') . ($fehler ? '<p class="fehler">' . $h($fehler) . '</p>' : '')
            . ($queue === '' ? '<p class="fehler">Noch kein Bondrucker zugeordnet: Verwaltung → Drucker → „Wofür welcher Drucker?“.</p>'
                : '<p class="muted">Bondrucker: <strong>' . $h($queue) . '</strong> (Verwaltung → Drucker).</p>')
            . '<div class="gitter"><form method="post" action="' . $h($base) . '/" class="card" id="layout"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="speichern">'
            . '<p class="muted">Papierbreite des Bondruckers: <strong>' . Seite::bonBreite() . ' mm</strong> – zentral einzustellen unter <a href="/verwaltung/drucker">Verwaltung → Drucker</a>.</p>'
            . $form . '<p><button>Speichern</button></p></form>'
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
