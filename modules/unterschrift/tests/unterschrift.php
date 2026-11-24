<?php
declare(strict_types=1);
// Modul unterschrift: Datenbank, Vorlagen, Platzhalter, Vorgänge, Aufbewahrung, Ansicht. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleUnterschrift\{Ansicht, Seite, Speicher};

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg): void { try { $f(); } catch (InvalidArgumentException) { return; } fwrite(STDERR, "FEHLER: $msg\n"); exit(1); }

$dir = sys_get_temp_dir() . '/us-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
Speicher::$data = $dir;
register_shutdown_function(function () use ($dir) { Speicher::zuruecksetzen(); foreach (glob("$dir/*") ?: [] as $f) @unlink($f); @rmdir($dir); });

// Erster Start: drei Mustervorlagen, Schemaversion gesetzt
$vl = Seite::vorlagen();
check(count($vl) === 3 && count(array_unique(array_column($vl, 'art'))) === 3, 'drei Mustervorlagen erwartet');
check((int)Speicher::pdo()->query('PRAGMA user_version')->fetchColumn() === Speicher::VERSION, 'Schemaversion');
check(count(Seite::vorlagen()) === 3, 'Muster dürfen nicht doppelt angelegt werden');
check(Seite::ueberblick()[0]['state'] === 'open', 'Überblick: Mustertexte offen');

// Platzhalter
Seite::pruefePlatzhalter('Name {{patient}} am {{ datum }}');
wirft(fn() => Seite::pruefePlatzhalter('Hallo {{vorname}}'), 'unbekannter Platzhalter');
wirft(fn() => Seite::pruefePlatzhalter('Hallo {{patient'), 'offene Klammer');
wirft(fn() => Seite::pruefePlatzhalter('Hallo patient}}'), 'schließende Klammer ohne Anfang');
check(Seite::einsetzen('A {{patient}} B {{betrag}}', ['patient' => 'Max Mustermann', 'betrag' => '']) === 'A Max Mustermann B …', 'Einsetzen mit Lücke');
check(Seite::einsetzen('{{patient}}', ['patient' => '{{datum}}']) === '{{datum}}', 'eingesetzter Wert wird nicht erneut ersetzt');

// Eingabeprüfung
wirft(fn() => Seite::zeile("A\x1bB", 'Name', 10), 'Steuerzeichen');
wirft(fn() => Seite::zeile(str_repeat('a', 11), 'Name', 10), 'zu lang');
wirft(fn() => Seite::zeile('  ', 'Name', 10), 'Pflichtfeld leer');
check(Seite::zeile('', 'Name', 10, false) === '', 'optional leer');
wirft(fn() => Seite::text("A\x00B", 'Text'), 'NUL im Text');
check(Seite::text("A\r\nB\tC", 'Text') === "A\nB\tC", 'Zeilenumbruch vereinheitlicht');

// Vorlage anlegen, ändern, löschen
$id = Seite::vorlageSpeichern(['id' => 0, 'art' => 'sonstige', 'titel' => 'Test', 'text' => 'Hallo {{patient}}', 'aktiv' => '1']);
check($id > 0 && (int)Seite::vorlage($id)['muster'] === 0, 'neue Vorlage ist kein Muster');
wirft(fn() => Seite::vorlageSpeichern(['id' => 0, 'art' => 'unbekannt', 'titel' => 'X', 'text' => 'Y']), 'unbekannte Art');
wirft(fn() => Seite::vorlageSpeichern(['id' => 0, 'art' => 'igel', 'titel' => 'X', 'text' => '{{nix}}']), 'unbekannter Platzhalter beim Speichern');
wirft(fn() => Seite::vorlageSpeichern(['id' => 9999, 'art' => 'igel', 'titel' => 'X', 'text' => 'Y']), 'unbekannte Vorlage ändern');
$muster = Seite::vorlagen()[0];
Seite::vorlageSpeichern(['id' => $muster['id'], 'art' => $muster['art'], 'titel' => $muster['titel'], 'text' => $muster['text'], 'aktiv' => '1']);
check((int)Seite::vorlage((int)$muster['id'])['muster'] === 0, 'bearbeitete Mustervorlage gilt als geprüft');

// Vorgang: Text wird fest eingesetzt
$praxis = ['name' => 'Praxis Dr. med. Erika Beispiel'];
$t0 = 1767225600; // fester Zeitpunkt
$vid = Seite::vorgangAnlegen(['vorlage_id' => $id, 'patient' => 'Max Mustermann', 'leistung' => 'Ultraschall', 'betrag' => '45,5', 'druck_nachher' => '1'], 'empfang', $praxis, 'kontext-abc', $t0);
$v = Seite::vorgang($vid);
check($v['status'] === 'wartet' && $v['text'] === 'Hallo Max Mustermann' && (int)$v['druck_nachher'] === 1 && (int)$v['druck_vorher'] === 0, 'Vorgang angelegt');
check((int)$v['kontext_bis'] === $t0 + 4 * 3600 && Seite::kontextGueltig($v, $t0 + 3600) && !Seite::kontextGueltig($v, $t0 + 5 * 3600), 'Kontext gilt 4 Stunden');
Seite::vorlageSpeichern(['id' => $id, 'art' => 'sonstige', 'titel' => 'Test', 'text' => 'Geändert', 'aktiv' => '1']);
check(Seite::vorgang($vid)['text'] === 'Hallo Max Mustermann', 'Vorgangstext bleibt nach Vorlagenänderung');
$igelVorlage = array_values(array_filter(Seite::vorlagen(), fn($x) => $x['art'] === 'igel'))[0];
$igel = Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => (int)$igelVorlage['id'], 'patient' => 'Erika Beispiel', 'leistung' => 'Test', 'betrag' => '45,5'], 'empfang', $praxis, '', $t0));
check(str_contains($igel['text'], '45,50') && str_contains($igel['text'], 'Praxis Dr. med. Erika Beispiel') && str_contains($igel['text'], '01.01.2026'), 'Betrag, Praxis und Datum eingesetzt');
check($igel['kontext'] === '' && (int)$igel['kontext_bis'] === 0 && !Seite::kontextGueltig($igel), 'ohne Kontext keine Frist');
wirft(fn() => Seite::vorgangAnlegen(['vorlage_id' => $id, 'patient' => '', 'leistung' => ''], 'x', $praxis), 'Patient fehlt');
wirft(fn() => Seite::vorgangAnlegen(['vorlage_id' => $id, 'patient' => 'A', 'betrag' => 'abc'], 'x', $praxis), 'Betrag ungültig');
wirft(fn() => Seite::vorgangAnlegen(['vorlage_id' => 9999, 'patient' => 'A'], 'x', $praxis), 'Vorlage unbekannt');
Seite::vorlageSpeichern(['id' => $id, 'art' => 'sonstige', 'titel' => 'Test', 'text' => 'Geändert']); // abgeschaltet
wirft(fn() => Seite::vorgangAnlegen(['vorlage_id' => $id, 'patient' => 'A'], 'x', $praxis), 'abgeschaltete Vorlage');

// Abbrechen und Aufbewahrung
Seite::abbrechen($vid);
check(Seite::vorgang($vid)['status'] === 'abgebrochen', 'abgebrochen');
wirft(fn() => Seite::abbrechen($vid), 'zweites Abbrechen');
wirft(fn() => Seite::abbrechen(9999), 'unbekannter Vorgang');
check(count(Seite::vorgaenge(true)) === 1, 'offene Vorgänge ohne abgebrochene');
check(Seite::aufraeumen($t0 + 10 * 86400) === 0, 'nach 10 Tagen noch aufbewahrt');
check(Seite::aufraeumen($t0 + 31 * 86400) === 1 && Seite::vorgang($vid) === null, 'abgebrochene Vorgänge nach 30 Tagen entfernt');
check(Seite::vorgang((int)$igel['id']) !== null, 'wartende Vorgänge werden nie aufgeräumt');

// Vorlage löschen löst den Verweis, der Vorgang bleibt
$vorl = (int)Seite::vorgang((int)$igel['id'])['vorlage_id'];
Seite::vorlageLoeschen($vorl);
check(Seite::vorlage($vorl) === null && Seite::vorgang((int)$igel['id'])['vorlage_id'] === null, 'Vorlage gelöscht, Vorgang bleibt');

// Ansicht: alles maskiert, Rechte steuern die Bereiche
Seite::vorlageSpeichern(['id' => 0, 'art' => 'igel', 'titel' => '<b>Titel</b>', 'text' => 'Text', 'aktiv' => '1']);
$aktiv = Seite::vorlagen(true);
$xss = Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => (int)$aktiv[0]['id'], 'patient' => '<script>x</script>'], 'x', $praxis));
$html = Ansicht::seite('tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, 'Gespeichert.', null);
check(!str_contains($html, '<script>x') && str_contains($html, '&lt;script&gt;x') && !str_contains($html, '<b>Titel</b>'), 'Ausgabe maskiert');
check(str_contains($html, 'name="kienzle-csrf" value="tok"') && str_contains($html, 'Neuer Vorgang') && str_contains($html, 'id="vorlagen"'), 'Seite mit allen Bereichen');
$nurVorgang = Ansicht::seite('tok', [], [], true, false, null, null);
check(!str_contains($nurVorgang, 'id="vorlagen"') && str_contains($nurVorgang, 'keine aktive Vorlage'), 'ohne Vorlagenrecht kein Vorlagenbereich');
$nurVorlage = Ansicht::seite('tok', [], Seite::vorlagen(), false, true, null, null);
check(!str_contains($nurVorlage, '<h2>Neuer Vorgang</h2>') && str_contains($nurVorlage, 'id="vorlagen"'), 'ohne Vorgangsrecht kein Formular');
check(str_contains(Ansicht::kopf('/unterschrift', 'tok'), '/unterschrift/assets/app.js'), 'Kopf bindet Skript ein');

echo "Unterschrift-Tests grün.\n";
