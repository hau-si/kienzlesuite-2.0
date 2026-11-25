<?php
declare(strict_types=1);
// Modul signatur: Datenbank, Vorlagen, Platzhalter, Vorgänge, Aufbewahrung, Ansicht. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSignatur\{Ansicht, Fhir, Pad, Seite, Speicher, Start};

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg): void { try { $f(); } catch (InvalidArgumentException) { return; } fwrite(STDERR, "FEHLER: $msg\n"); exit(1); }

$dir = sys_get_temp_dir() . '/sg-test-' . bin2hex(random_bytes(4));
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
$liste = Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, 'Gespeichert.', null);
$einst = Ansicht::seite('/einstellungen', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null);
$start = Ansicht::seite('/', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null, null, null, [], Seite::aktuellerVorgang());
check(!str_contains($liste, '<script>x') && str_contains($liste, '&lt;script&gt;x') && !str_contains($einst, '<b>Titel</b>') && str_contains($einst, '&lt;b&gt;Titel'), 'Ausgabe maskiert');
check(str_contains($start, 'name="kienzle-csrf" value="tok"') && str_contains($start, 'Neuer Vorgang') && str_contains($start, 'Zuletzt angelegter Vorgang') && str_contains($einst, 'id="vorlagen"'), 'Start und Einstellungen');
check(str_contains($liste, 'id="vg-suche"') && str_contains($liste, 'data-suche="') && str_contains($liste, 'id="vg-art"'), 'Liste mit Suche und Filtern');
check(!str_contains($start, 'id="vorlagen"') && !str_contains($liste, 'id="vorlagen"'), 'Vorlagen nur unter Einstellungen');
$nurVorgang = Ansicht::seite('/', 'tok', [], [], true, false, null, null);
check(str_contains($nurVorgang, 'keine aktive Vorlage'), 'ohne aktive Vorlage Hinweis');
$nurVorlage = Ansicht::seite('/einstellungen', 'tok', [], Seite::vorlagen(), false, true, null, null);
check(!str_contains($nurVorlage, '<h2>Neuer Vorgang</h2>') && str_contains($nurVorlage, 'id="vorlagen"'), 'ohne Vorgangsrecht kein Formular');
check(str_contains(Ansicht::kopf('/signatur', 'tok'), '/signatur/assets/app.js'), 'Kopf bindet Skript ein');

// Pad-Software: Upload der signotec-ZIP (Prüfung, feste Dateinamen, nur ELF x86-64)
$elf = "\x7fELF\x02\x01\x01" . str_repeat("\0", 9) . "\x03\x00\x3e\x00" . str_repeat('x', 2000);
$baue = function (array $dateien) use ($dir): string {
    $f = $dir . '/t-' . bin2hex(random_bytes(3)) . '.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE);
    foreach ($dateien as $n => $inhalt) $z->addFromString($n, $inhalt);
    $z->close();
    $b = (string)file_get_contents($f);
    unlink($f);
    return $b;
};
$gut = ['libSTPadLib.so/x64/libSTPadLib.so' => $elf, 'Demo/x64/libSTCPImageEngine.so' => $elf, 'ReadMe.txt' => "signotec signoPAD-API Linux Release 9.0.1:\n", 'licenses/signotec endnutzer lizenzvertrag.txt' => 'Vertrag', '../evil.so' => $elf];
check(!Pad::status()['installiert'] && Seite::ueberblick()[0]['state'] === 'open', 'Pad-Software zunächst nicht da');
wirft(fn() => Pad::installieren($baue($gut), 'admin', false), 'ohne Lizenzbestätigung');
wirft(fn() => Pad::installieren('', 'admin', true), 'leer');
wirft(fn() => Pad::installieren('kein zip', 'admin', true), 'keine ZIP-Datei');
wirft(fn() => Pad::installieren(str_repeat('P', Pad::MAX_ZIP + 1), 'admin', true), 'zu groß');
wirft(fn() => Pad::installieren($baue(['ReadMe.txt' => 'x']), 'admin', true), 'Bibliotheken fehlen');
wirft(fn() => Pad::installieren($baue(['Demo/x64/libSTPadLib.so' => str_repeat('A', 3000), 'Demo/x64/libSTCPImageEngine.so' => $elf]), 'admin', true), 'keine ELF-Datei');
wirft(fn() => Pad::installieren($baue(['Demo/x64/libSTPadLib.so' => substr_replace($elf, "\x28\x00", 18, 2), 'Demo/x64/libSTCPImageEngine.so' => $elf]), 'admin', true), 'falsche Architektur (ARM)');
check(!is_dir(Pad::ordnerPfad()) || !is_file(Pad::ordnerPfad() . '/libSTPadLib.so'), 'bei Fehlern wird nichts abgelegt');
$stand = Pad::installieren($baue($gut), 'admin', true, $t0);
check($stand['installiert'] && $stand['version'] === '9.0.1' && $stand['von'] === 'admin' && $stand['zeit'] === $t0, 'Pad-Software installiert');
check(is_file(Pad::ordnerPfad() . '/libSTPadLib.so') && is_file(Pad::ordnerPfad() . '/libSTCPImageEngine.so') && is_file(Pad::ordnerPfad() . '/Lizenzvertrag-signotec.txt'), 'Dateien abgelegt');
check(!file_exists($dir . '/evil.so') && !file_exists(dirname($dir) . '/evil.so') && count(glob($dir . '/signotec/*')) === 4, 'nur feste Dateinamen, nichts außerhalb');
check(decoct(fileperms(Pad::ordnerPfad() . '/libSTPadLib.so') & 0777) === '640', 'Dateirechte 0640');
check(Seite::ueberblick()[0]['state'] === 'done', 'Überblick: Pad-Software erledigt');
$htmlAdmin = Ansicht::seite('/einstellungen', 'tok', [], [], true, true, null, null, null, Pad::status());
check(str_contains($htmlAdmin, 'id="pad-los"') && str_contains($htmlAdmin, 'signoPAD-API Linux 9.0.1') && str_contains($htmlAdmin, 'Pad-Software entfernen'), 'Admin sieht Upload und Stand');
check(!str_contains(Ansicht::seite('/einstellungen', 'tok', [], [], true, true, null, null), 'id="pad-los"'), 'ohne Admin kein Upload');
Pad::entfernen();
check(!Pad::status()['installiert'] && !is_dir(Pad::ordnerPfad()), 'Pad-Software entfernt');
// Echte signotec-Datei, falls im Arbeitsverzeichnis vorhanden (nicht im Repository)
$echt = glob('/mnt/user-data/uploads/*signoPAD-API_Linux*.zip');
if ($echt) {
    $st = Pad::installieren((string)file_get_contents($echt[0]), 'admin', true);
    check($st['installiert'] && preg_match('/^\d+\.\d+/', $st['version']) === 1, 'echte signotec-ZIP wird akzeptiert');
    Pad::entfernen();
}

// Pads: anlegen, prüfen, ändern, auswählen, löschen
check(Seite::pads() === [], 'anfangs keine Pads');
$vorl = (int)Seite::vorlagen(true)[0]['id'];
$neu = ['vorlage_id' => $vorl, 'patient' => 'Max Mustermann'];
$v0 = Seite::vorgangAnlegen($neu, 'Dr. med. Erika Beispiel');
check((int)Seite::vorgang($v0)['pad_id'] === 0, 'ohne Pads bleibt die Zuordnung offen');
foreach ([['name' => '', 'adresse' => '192.0.2.10'], ['name' => 'A', 'adresse' => 'nicht gültig!'], ['name' => 'A', 'adresse' => '192.0.2.10', 'port' => '0'], ['name' => 'A', 'adresse' => '192.0.2.10', 'port' => '99999'], ['name' => 'A', 'adresse' => '192.0.2.10', 'port' => 'abc']] as $bad)
    wirft(fn() => Seite::padSpeichern($bad), 'ungültiges Pad ' . json_encode($bad));
$p1 = Seite::padSpeichern(['name' => 'Pad Empfang', 'arbeitsplatz' => 'Anmeldung', 'adresse' => '192.0.2.10', 'aktiv' => '1']);
check(Seite::pad($p1)['port'] === 1002 || (int)Seite::pad($p1)['port'] === 1002, 'Vorgabeport 1002');
$v1 = Seite::vorgangAnlegen($neu, 'x');
check((int)Seite::vorgang($v1)['pad_id'] === $p1, 'ein aktives Pad wird automatisch zugeordnet');
$p2 = Seite::padSpeichern(['name' => 'Pad Labor', 'adresse' => 'pad-labor.example.org', 'port' => '1002', 'aktiv' => '1']);
wirft(fn() => Seite::vorgangAnlegen($neu, 'x'), 'bei mehreren Pads muss gewählt werden');
wirft(fn() => Seite::vorgangAnlegen($neu + ['pad_id' => 9999], 'x'), 'unbekanntes Pad');
$v2 = Seite::vorgangAnlegen($neu + ['pad_id' => $p2], 'x');
check((int)Seite::vorgang($v2)['pad_id'] === $p2, 'gewähltes Pad gespeichert');
Seite::padSpeichern(['id' => $p2, 'name' => 'Pad Labor', 'adresse' => '192.0.2.11', 'port' => '1002']);
check(Seite::pad($p2)['adresse'] === '192.0.2.11' && (int)Seite::pad($p2)['aktiv'] === 0, 'ändern; ohne Haken inaktiv');
$v3 = Seite::vorgangAnlegen($neu, 'x');
check((int)Seite::vorgang($v3)['pad_id'] === $p1, 'inaktive Pads zählen nicht');
$html = Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null, null, null, Seite::pads());
check(!str_contains($html, 'name="pad_id"'), 'kein Auswahlfeld bei einem aktiven Pad');
check(!str_contains($html, 'Unterschriftenpads'), 'Pad-Verwaltung nur für Admins');
Seite::padSpeichern(['id' => $p2, 'name' => 'Pad Labor', 'adresse' => '192.0.2.11', 'aktiv' => '1']);
$html = Ansicht::seite('/', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null, null, Pad::status(), Seite::pads()) . Ansicht::seite('/einstellungen', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null, null, Pad::status(), Seite::pads());
check(str_contains($html, 'name="pad_id"') && str_contains($html, 'Pad Empfang (Anmeldung)') && str_contains($html, 'Unterschriftenpads'), 'Auswahl und Verwaltung sichtbar');
Seite::padLoeschen($p1);
check((int)Seite::vorgang($v1)['pad_id'] === 0 && (int)Seite::vorgang($v2)['pad_id'] === $p2, 'Löschen entzieht nur wartenden Vorgängen die Zuordnung dieses Pads');

// Am Pad zeigen
$pa = Seite::padSpeichern(['name' => 'Pad A', 'adresse' => '192.0.2.20', 'aktiv' => '1']);
$vp = Seite::vorgangAnlegen($neu + ['pad_id' => $pa], 'x');
$ohnePad = Seite::vorgangAnlegen($neu + ['pad_id' => $pa], 'x');
Speicher::pdo()->prepare('UPDATE vorgaenge SET pad_id=0 WHERE id=?')->execute([$ohnePad]);
wirft(fn() => Seite::amPadZeigen($ohnePad), 'ohne Pad nicht am Pad zeigen');
wirft(fn() => Seite::amPadZeigen(99999), 'unbekannter Vorgang');
Seite::amPadZeigen($vp);
check(Seite::vorgang($vp)['status'] === 'am_pad', 'Status am_pad');
$zweiter = Seite::vorgangAnlegen($neu + ['pad_id' => $pa], 'x');
wirft(fn() => Seite::amPadZeigen($zweiter), 'ein Pad zeigt nur einen Vorgang');
wirft(fn() => Seite::amPadZeigen($vp), 'schon am Pad');
$html = Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null, null, null, Seite::pads());
check(str_contains($html, 'Vom Pad nehmen') && str_contains($html, 'Am Pad zeigen') && str_contains($html, 's-am_pad'), 'Knöpfe und Status in der Liste');
Seite::vomPadNehmen($vp);
check(Seite::vorgang($vp)['status'] === 'wartet', 'vom Pad genommen');
wirft(fn() => Seite::vomPadNehmen($vp), 'nicht am Pad');
Seite::amPadZeigen($vp);
Seite::abbrechen($vp);
check(Seite::vorgang($vp)['status'] === 'abgebrochen', 'am Pad abbrechbar');
check(in_array((int)$vp, array_map(fn($v) => (int)$v['id'], Seite::vorgaenge(true)), true) === false, 'abgebrochen nicht in offenen');

// Wieder aufnehmen abgebrochener Vorgänge
check(str_contains(Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null), 'value="wiederaufnehmen"'), 'Knopf Wieder aufnehmen in der Liste');
Seite::wiederaufnehmen($vp);
check(Seite::vorgang($vp)['status'] === 'wartet' && Seite::vorgang($vp)['meldung'] === 'Wieder aufgenommen.', 'wieder aufgenommen: wartet');
check(in_array((int)$vp, array_map(fn($v) => (int)$v['id'], Seite::vorgaenge(true)), true), 'wieder in offenen');
wirft(fn() => Seite::wiederaufnehmen($vp), 'nur abgebrochene wieder aufnehmbar');
wirft(fn() => Seite::wiederaufnehmen(9999), 'unbekannter Vorgang nicht aufnehmbar');
\KienzleSignatur\Speicher::pdo()->prepare('UPDATE vorgaenge SET unterschrieben=? WHERE id=?')->execute([time(), $vp]);
Seite::abbrechen($vp);
Seite::wiederaufnehmen($vp);
check(Seite::vorgang($vp)['status'] === 'unterschrieben', 'schon unterschrieben: zurück auf unterschrieben');
Seite::abbrechen($vp);

// Löschen abgebrochener Vorgänge
$lv = Seite::vorgangAnlegen($neu + ['pad_id' => $pa], 'x');
wirft(fn() => Seite::vorgangLoeschen($lv), 'nur abgebrochene löschbar');
Seite::abbrechen($lv);
Seite::vorgangLoeschen($lv);
check(Seite::vorgang($lv) === null, 'abgebrochener Vorgang gelöscht');
wirft(fn() => Seite::vorgangLoeschen($lv), 'schon gelöscht');
check(str_contains(Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null), 'value="vorgang-loeschen"'), 'Löschknopf in der Liste');
$gelb = Seite::aktuellerVorgang();
check($gelb !== null && $gelb['status'] !== 'abgebrochen', 'aktueller Vorgang ist offen');

// Pad-Ansicht: feste Zeilen mit Stift und Mülleimer, Bearbeiten nur auf Wunsch, Erledigt-Zeichen, Suche im Netz
$px = Seite::padSpeichern(['name' => 'Pad <Test>', 'arbeitsplatz' => 'Anmeldung', 'adresse' => '192.0.2.31', 'aktiv' => '1']);
Ansicht::$padSuche = null; Ansicht::$padBearbeiten = 0;
$admin = Ansicht::seite('/einstellungen', 'tok', [], [], true, true, null, null, null, Pad::status(), Seite::pads());
check(str_contains($admin, 'class="us-pad-fest"') && str_contains($admin, 'aria-label="Pad Pad &lt;Test&gt; bearbeiten"') && str_contains($admin, 'aria-label="Pad Pad &lt;Test&gt; löschen"'), 'feste Zeile mit Stift und Mülleimer');
check(!str_contains($admin, 'value="Pad &lt;Test&gt;"'), 'eingerichtete Pads zeigen keine Eingabefelder');
check(str_contains($admin, 'Servicemenü') && str_contains($admin, 'obere rechte Ecke') && str_contains($admin, 'Delta</strong> und <strong>Alpha'), 'Anleitung Servicemenü');
Ansicht::$padBearbeiten = $px;
check(str_contains(Ansicht::seite('/einstellungen', 'tok', [], [], true, true, null, null, null, Pad::status(), Seite::pads()), 'value="Pad &lt;Test&gt;"'), 'Bearbeiten zeigt Felder');
Ansicht::$padBearbeiten = 0;
check(str_contains($admin, '✓ erledigt') === Pad::status()['installiert'] && (Pad::status()['installiert'] || str_contains($admin, 'tag warn')), 'Erledigt-Zeichen nur mit Software');
$netz = tmpfile(); $pfad = stream_get_meta_data($netz)['uri'];
file_put_contents($pfad, "Main:\n  +-- 192.168.77.0/24 2 0 2\n     |-- 192.168.77.0\n        /32 link BROADCAST\n     |-- 192.168.77.5\n        /32 host LOCAL\n     |-- 127.0.0.1\n        /32 host LOCAL\n     |-- 203.0.113.9\n        /32 host LOCAL\n");
check(Pad::eigeneNetze($pfad) === ['192.168.77'], 'eigene private Netze');
check(Pad::netzGueltig('192.168.1') && Pad::netzGueltig('10.1.2') && Pad::netzGueltig('172.20.5') && !Pad::netzGueltig('172.32.1') && !Pad::netzGueltig('8.8.8') && !Pad::netzGueltig('192.168.1.5') && !Pad::netzGueltig('192.168.999'), 'nur private Netze');
wirft(fn() => Pad::suchen('8.8.8'), 'kein öffentliches Netz durchsuchen');
check(Pad::suchen('192.168.77', 1002, fn($a) => in_array($a, ['192.168.77.20', '192.168.77.82'], true)) === ['192.168.77.20', '192.168.77.82'], 'Treffer der Suche');
Ansicht::$padSuche = ['netz' => '192.168.77', 'netze' => ['192.168.77'], 'treffer' => ['192.168.77.20', '192.0.2.31']];
$treffer = Ansicht::seite('/einstellungen', 'tok', [], [], true, true, null, null, null, Pad::status(), Seite::pads());
check(str_contains($treffer, 'value="192.168.77.20"') && str_contains($treffer, 'Hinzufügen') && str_contains($treffer, 'schon eingerichtet'), 'Treffer zum Hinzufügen');
Ansicht::$padSuche = ['netz' => '192.168.77', 'netze' => ['192.168.77'], 'treffer' => []];
check(str_contains(Ansicht::seite('/einstellungen', 'tok', [], [], true, true, null, null, null, Pad::status(), Seite::pads()), 'nichts auf Port 1002 gefunden'), 'leere Suche');
Ansicht::$padSuche = null;
Seite::padLoeschen($px);

// Start aus T2med: Patient per FHIR (hier ersetzt), einmaliger Startcode, 5 Minuten gültig
$fhir = new class(['base_url' => 'https://t2med.example.org/aps/fhir/api/r4', 'launch_urls' => ['https://192.0.2.7/aps/fhir/api/r4/']]) extends Fhir {
    public array $aufrufe = [];
    public function patient(string $kontext, string $token): array { $this->aufrufe[] = [$kontext, $token]; return ['id' => 'p1', 'name' => 'Max Mustermann', 'geburtsdatum' => '1980-04-12']; }
};
check($fhir->erlaubt('https://192.0.2.7/aps/fhir/api/r4') && $fhir->erlaubt('https://t2med.example.org/aps/fhir/api/r4/') && !$fhir->erlaubt('https://boese.example.org/') && !$fhir->erlaubt(''), 'FHIR-Adresse nur aus der Basis');
$eingabe = ['context_id' => 'kontext-1', 'fhir_base_url' => 'https://192.0.2.7/aps/fhir/api/r4', 'oauth_token' => 'tok'];
$a = Start::anlegen($eingabe, $fhir, 'kienzle.example.org', 1000);
check(preg_match('~^https://kienzle\.example\.org/#launch=([0-9a-f]{64})&m=signatur$~D', $a['url'], $mm) === 1 && $fhir->aufrufe === [['kontext-1', 'tok']], 'Startlink');
check(Start::einloesen($mm[1], 1000 + Start::GUELTIG + 1) === null, 'abgelaufener Code');
$a = Start::anlegen($eingabe, $fhir, 'kienzle.example.org', 2000); preg_match('~launch=([0-9a-f]{64})~', $a['url'], $mm);
check(Start::einloesen($mm[1], 2100) === ['patient' => 'Max Mustermann', 'geburtsdatum' => '1980-04-12', 'kontext' => 'kontext-1'], 'Code einlösen');
check(Start::einloesen($mm[1], 2100) === null && Start::einloesen('abc') === null, 'Code nur einmal');
wirft(fn() => Start::anlegen(['fhir_base_url' => 'https://boese.example.org'] + $eingabe, $fhir, 'kienzle.example.org'), 'fremde FHIR-Adresse');
wirft(fn() => Start::anlegen(['context_id' => '../x'] + $eingabe, $fhir, 'kienzle.example.org'), 'ungültiger Kontext');
wirft(fn() => Start::anlegen(['oauth_token' => ''] + $eingabe, $fhir, 'kienzle.example.org'), 'ohne Token');
wirft(fn() => Start::anlegen($eingabe, $fhir, 'evil.example.org/x'), 'ungültiger Servername');
$vid = Seite::vorgangAnlegen(['vorlage_id' => Seite::vorlagen()[0]['id'], 'patient' => 'Max Mustermann', 'pad_id' => Seite::pads(true)[0]['id'] ?? 0], 'admin', [], 'kontext-1');
check(Seite::vorgang($vid)['kontext'] === 'kontext-1', 'Kontext am Vorgang');

echo "Signatur-Tests grün.\n";
