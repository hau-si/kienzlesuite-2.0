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
            . $form . '<p><button>Speichern</button></p></form>'
            . '<aside class="card"><h2>Vorschau (Beispieldaten)</h2><div id="vorschau" class="vorschau"><img id="bild" alt="Vorschau des Bons" hidden><p id="hinweis" class="muted">Wird geladen …</p></div>'
            . '<form method="post" action="' . $h($base) . '/"><input type="hidden" name="kienzle-csrf" value="' . $h($csrf) . '"><input type="hidden" name="aktion" value="testdruck">'
            . '<button class="zweit"' . ($queue === '' ? ' disabled' : '') . '>Beispiel-Terminzettel drucken</button></form></aside></div>'
            . '<details><summary>Drucker „Terminzettel“ auf den Arbeitsplätzen einrichten</summary><ol>'
            . '<li>Windows: Drucker hinzufügen → „Der gewünschte Drucker ist nicht aufgelistet“ → Freigegebenen Drucker über Namen auswählen: <code>' . $h($printer) . '</code>; Treiber „Microsoft IPP Class Driver“ oder „Generic / Text Only“ genügt.</li>'
            . '<li>Mac: Drucker hinzufügen → <strong>Terminzettel</strong> wählen, als Treiber <a href="' . $h($base) . '/assets/Terminzettel-PDF.ppd">Terminzettel-PDF.ppd</a> (heruntergeladen) verwenden.</li>'
            . '<li>In T2med unter Einstellungen → Kalender → Terminliste (DIN A6) den Drucker „Terminzettel“ wählen.</li>'
            . '<li>Voraussetzung: Die <a href="/verwaltung/firewall">Firewall</a> der Basis lässt Drucken (IPP, Port 631) aus dem Praxisnetz zu.</li></ol></details>'
            . '</main><script src="' . $h($base) . '/assets/app.js"></script></body></html>';
    }
}
