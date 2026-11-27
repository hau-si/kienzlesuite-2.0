<?php
declare(strict_types=1);
// Modul signatur: Datenbank, Vorlagen, Platzhalter, Vorgänge, Aufbewahrung, Ansicht. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSignatur\{Ansicht, Bausteine, Fhir, Pad, Seite, Speicher, Start};

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg): void { try { $f(); } catch (InvalidArgumentException) { return; } fwrite(STDERR, "FEHLER: $msg\n"); exit(1); }

$dir = sys_get_temp_dir() . '/sg-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
Speicher::$data = $dir;
register_shutdown_function(function () use ($dir) { Speicher::zuruecksetzen(); foreach (array_merge(glob("$dir/unterschriften/*") ?: [], glob("$dir/*") ?: []) as $f) @unlink($f); @rmdir("$dir/unterschriften"); @rmdir($dir); });

// Erster Start: drei Mustervorlagen, Schemaversion gesetzt
$vl = Seite::vorlagen();
check(count($vl) === 3 && count(array_unique(array_column($vl, 'art'))) === 3, 'drei Mustervorlagen erwartet');
check((int)Speicher::pdo()->query('PRAGMA user_version')->fetchColumn() === Speicher::VERSION, 'Schemaversion');
check(count(Seite::vorlagen()) === 3, 'Muster dürfen nicht doppelt angelegt werden');
check(Seite::ueberblick()[2]['state'] === 'open', 'Überblick: Mustertexte offen');

// Platzhalter
Seite::pruefePlatzhalter('Name {{patient}} am {{ datum }}');
wirft(fn() => Seite::pruefePlatzhalter('Hallo {{nix}}'), 'unbekannter Platzhalter');
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
wirft(fn() => Seite::vorlageLoeschen($vorl), 'fester IGeL-Vertrag nicht löschbar');
\KienzleSignatur\Speicher::pdo()->exec("UPDATE vorlagen SET art='sonstige' WHERE id=" . $vorl);
Seite::vorlageLoeschen($vorl);
check(Seite::vorlage($vorl) === null && Seite::vorgang((int)$igel['id'])['vorlage_id'] === null, 'Vorlage gelöscht, Vorgang bleibt');

// Ansicht: alles maskiert, Rechte steuern die Bereiche
Seite::vorlageSpeichern(['id' => 0, 'art' => 'igel', 'titel' => '<b>Titel</b>', 'text' => 'Text', 'aktiv' => '1']);
$aktiv = Seite::vorlagen(true);
$xss = Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => (int)$aktiv[0]['id'], 'patient' => '<script>x</script>'], 'x', $praxis));
$liste = Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, 'Gespeichert.', null);
$einst = Ansicht::seite('/editor', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null);
$start = Ansicht::seite('/', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null, null, null, [], Seite::aktuellerVorgang());
check(!str_contains($liste, '<script>x') && str_contains($liste, '&lt;script&gt;x') && !str_contains($einst, '<b>Titel</b>') && str_contains($einst, '&lt;b&gt;Titel'), 'Ausgabe maskiert');
check(str_contains($start, 'name="kienzle-csrf" value="tok"') && str_contains($start, 'Dokument wählen') && str_contains($start, 'us-links') && str_contains($start, 'name="vorlage_id"') && str_contains($start, 'class="patient-karte us-gewaehlt"') && str_contains($start, 'id="n-zur-kasse"') && str_contains($einst, 'id="ed-daten"'), 'Start und Einstellungen');
check(str_contains($liste, 'id="vg-suche"') && str_contains($liste, 'data-suche="') && str_contains($liste, 'id="vg-art"'), 'Liste mit Suche und Filtern');
check(!str_contains($start, 'id="vorlagen"') && !str_contains($liste, 'id="vorlagen"'), 'Vorlagen nur unter Einstellungen');
$nurVorgang = Ansicht::seite('/', 'tok', [], [], true, false, null, null);
check(str_contains($nurVorgang, 'keine aktive Vorlage'), 'ohne aktive Vorlage Hinweis');
check(!str_contains($start, 'Leistung oder Thema') && !str_contains($start, 'Betrag in Euro') && str_contains($start, 'type="hidden" id="n-leistung"'), 'Formular ohne Leistung/Betrag (kommen aus der Kasse)');
// Archiv: aus der linken Spalte nehmen, bleibt in der Liste (Filter „Archiv“), zurückholen; Kopf mit Reitern und Zahnrad
check(str_contains($start, 'title="Archivieren (bleibt unter „Vorgänge“)"') && str_contains($start, 'class="us-symbol rot"') && str_contains($start, 'aria-label="Einstellungen"') && str_contains($start, '/editor"'), 'Start: Symbole Archivieren/Löschen, Reiter Editor, Zahnrad');
Seite::archivieren((int)$xss['id']);
$startA = Ansicht::seite('/', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null);
$listeA = Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null);
check(!str_contains($startA, '&lt;script&gt;x') && str_contains($listeA, 'data-archiv="1"') && str_contains($listeA, 'id="vg-archiv"') && str_contains($listeA, 'value="zurueckholen"'), 'archiviert: nicht links, in der Liste mit Zurückholen');
Seite::archivieren((int)$xss['id'], false);
check(!(int)Seite::vorgang((int)$xss['id'])['archiviert'], 'zurückgeholt');
$nurVorlage = Ansicht::seite('/editor', 'tok', [], Seite::vorlagen(), false, true, null, null);
check(!str_contains($nurVorlage, '<h2>Neuer Vorgang</h2>') && str_contains($nurVorlage, 'id="ed-daten"') && !str_contains($nurVorlage, 'href="/signatur/"'), 'ohne Vorgangsrecht kein Formular');
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
check(Seite::ueberblick()[1]['state'] === 'done', 'Überblick: Pad-Software erledigt');
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
check(str_contains($html, 'Vom Pad nehmen') && str_contains($html, 'Zum Pad senden') && str_contains($html, 's-am_pad'), 'Knöpfe und Status in der Liste');
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
Seite::amPadZeigen($lv);
wirft(fn() => Seite::vorgangLoeschen($lv), 'am Pad liegender Vorgang nicht löschbar');
Seite::abbrechen($lv);
Seite::vorgangLoeschen($lv);
check(Seite::vorgang($lv) === null, 'abgebrochener Vorgang gelöscht');
wirft(fn() => Seite::vorgangLoeschen($lv), 'schon gelöscht');
check(str_contains(Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null), 'value="vorgang-loeschen"'), 'Löschknopf in der Liste');
// Neuer Vorgang gleich ans Pad; ist das Pad belegt, wartet er
$g1 = Seite::vorgangAnlegen($neu + ['pad_id' => $pa], 'x');
check(Seite::gleichAnsPad($g1) === 'Vorgang angelegt und an das Pad geschickt.' && Seite::vorgang($g1)['status'] === 'am_pad', 'gleich am Pad');
$g2 = Seite::vorgangAnlegen($neu + ['pad_id' => $pa], 'x');
check(str_contains(Seite::gleichAnsPad($g2), 'anderer Vorgang') && Seite::vorgang($g2)['status'] === 'wartet', 'Pad belegt: wartet');
Seite::abbrechen($g1); Seite::abbrechen($g2);

// Ansehen: PDF unterschriebener Vorgänge, sonst der Text; Geburtsdatum und Praxis für das PDF; Löschen entfernt die Dateien
$pv = Seite::vorgangAnlegen($neu + ['pad_id' => $pa, 'geburtsdatum' => '1970-02-01'], 'x', ['name' => 'Praxis Dr. med. Erika Beispiel', 'city' => '12345 Musterstadt', 'fremd' => 'x']);
$row = Seite::vorgang($pv);
check($row['geburtsdatum'] === '01.02.1970' && json_decode($row['praxis'], true) === ['name' => 'Praxis Dr. med. Erika Beispiel', 'city' => '12345 Musterstadt'], 'Geburtsdatum und Praxis am Vorgang');
check(Seite::pdfPfad($pv) === null, 'ohne PDF kein Pfad');
$dok = Ansicht::dokument(['patient' => '<Max>', 'titel' => 'T', 'text' => "Zeile <b>1</b>", 'status' => 'wartet', 'unterschrieben' => 0]);
check(str_contains($dok, '&lt;b&gt;1') && str_contains($dok, 'Noch nicht unterschrieben') && !str_contains($dok, '<b>1'), 'Dokumentansicht maskiert den Text');
check(str_contains(Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null), '/signatur/ansehen/' . $pv . '"'), 'Ansehen in der Liste');
\KienzleSignatur\Speicher::pdo()->prepare('UPDATE vorgaenge SET status=\'unterschrieben\', unterschrieben=? WHERE id=?')->execute([time(), $pv]);
@mkdir($dir . '/unterschriften', 0700);
foreach (['png', 'sdb', 'pdf'] as $e) file_put_contents("$dir/unterschriften/$pv.$e", 'x');
check(Seite::pdfPfad($pv) === "$dir/unterschriften/$pv.pdf", 'PDF gefunden');
check(str_contains(Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null), 'PDF ansehen'), 'PDF ansehen bei unterschriebenen');
Seite::vorgangLoeschen($pv);
check(!is_file("$dir/unterschriften/$pv.png") && !is_file("$dir/unterschriften/$pv.sdb") && !is_file("$dir/unterschriften/$pv.pdf"), 'Löschen entfernt Unterschrift und PDF');

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
    public function patient(string $kontext, string $token): array { $this->aufrufe[] = [$kontext, $token]; return ['id' => 'p1', 'name' => 'Max Mustermann', 'geburtsdatum' => '1980-04-12', 'telefon' => '0711 1234', 'email' => 'max@example.invalid', 'adresse' => 'Hauptstr. 1, 70173 Stuttgart', 'vorname' => 'Max', 'nachname' => 'Mustermann', 'geschlecht' => 'male']; }
};
check($fhir->erlaubt('https://192.0.2.7/aps/fhir/api/r4') && $fhir->erlaubt('https://t2med.example.org/aps/fhir/api/r4/') && !$fhir->erlaubt('https://boese.example.org/') && !$fhir->erlaubt(''), 'FHIR-Adresse nur aus der Basis');
$eingabe = ['context_id' => 'kontext-1', 'fhir_base_url' => 'https://192.0.2.7/aps/fhir/api/r4', 'oauth_token' => 'tok'];
$a = Start::anlegen($eingabe, $fhir, 'kienzle.example.org', 1000);
check(preg_match('~^https://kienzle\.example\.org/#launch=([0-9a-f]{64})&m=signatur$~D', $a['url'], $mm) === 1 && $fhir->aufrufe === [['kontext-1', 'tok']], 'Startlink');
check(Start::einloesen($mm[1], 1000 + Start::GUELTIG + 1) === null, 'abgelaufener Code');
$a = Start::anlegen($eingabe, $fhir, 'kienzle.example.org', 2000); preg_match('~launch=([0-9a-f]{64})~', $a['url'], $mm);
check(Start::einloesen($mm[1], 2100) === ['patient' => 'Max Mustermann', 'geburtsdatum' => '1980-04-12', 'kontext' => 'kontext-1', 'telefon' => '0711 1234', 'email' => 'max@example.invalid', 'adresse' => 'Hauptstr. 1, 70173 Stuttgart', 'vorname' => 'Max', 'nachname' => 'Mustermann', 'geschlecht' => 'male', 'patient_id' => 'p1'], 'Code einlösen');
check(Speicher::tokenHolen('kontext-1', 2100) === 'tok' && Speicher::tokenHolen('kontext-1', 2000 + 4 * 3600 + 1) === null, 'Token verschlüsselt gemerkt und nach 4 Stunden weg');
check(Start::einloesen($mm[1], 2100) === null && Start::einloesen('abc') === null, 'Code nur einmal');
wirft(fn() => Start::anlegen(['fhir_base_url' => 'https://boese.example.org'] + $eingabe, $fhir, 'kienzle.example.org'), 'fremde FHIR-Adresse');
wirft(fn() => Start::anlegen(['context_id' => '../x'] + $eingabe, $fhir, 'kienzle.example.org'), 'ungültiger Kontext');
wirft(fn() => Start::anlegen(['oauth_token' => ''] + $eingabe, $fhir, 'kienzle.example.org'), 'ohne Token');
wirft(fn() => Start::anlegen($eingabe, $fhir, 'evil.example.org/x'), 'ungültiger Servername');
$vid = Seite::vorgangAnlegen(['vorlage_id' => Seite::vorlagen()[0]['id'], 'patient' => 'Max Mustermann', 'pad_id' => Seite::pads(true)[0]['id'] ?? 0], 'admin', [], 'kontext-1');
check(Seite::vorgang($vid)['kontext'] === 'kontext-1', 'Kontext am Vorgang');

// Leistungsliste aus der Kasse: Summe und Texte kommen aus der Liste, nicht aus dem Formular
$padT = (int)(Seite::pads(true)[0]['id'] ?? 0);
$kvl = Seite::vorlageSpeichern(['art' => 'igel', 'titel' => 'Kasse-Test', 'text' => "Leistungen:\n{{leistungen}}\nSumme {{betrag}} Euro", 'aktiv' => 1]);
$liste = json_encode([['code' => '410', 'label' => 'Ultraschall Oberbauch', 'cent' => 3000], ['code' => '', 'label' => 'Beratung', 'cent' => 1550]]);
$kid = Seite::vorgangAnlegen(['vorlage_id' => $kvl, 'pad_id' => $padT, 'patient' => 'Kasse Beispiel', 'leistung' => 'ignoriert', 'betrag' => '1,00', 'leistungen_json' => $liste, 'quelle' => 'kasse', 'quelle_ref' => 'abc123'], 'empfang', $praxis, '', $t0);
$kv = Seite::vorgang($kid);
check($kv['quelle'] === 'kasse' && $kv['quelle_ref'] === 'abc123' && count(json_decode($kv['leistungen'], true)) === 2, 'Herkunft und Liste gespeichert');
check(str_contains($kv['text'], 'GOÄ 410: Ultraschall Oberbauch – 30,00 €') && str_contains($kv['text'], '– Beratung – 15,50 €') && str_contains($kv['text'], '45,50'), 'Text mit Leistungsliste und Summe: ' . $kv['text']);
check(!str_contains($kv['text'], 'ignoriert') && !str_contains($kv['text'], '1,00'), 'Formularwerte dürfen die Liste nicht überschreiben');
wirft(fn() => Seite::leistungenPruefen('kein json'), 'Liste kein JSON');
wirft(fn() => Seite::leistungenPruefen(json_encode([['label' => 'X', 'cent' => -5]])), 'negativer Preis');
wirft(fn() => Seite::leistungenPruefen(json_encode([['label' => 'X', 'cent' => '30']])), 'Preis kein Ganzzahl');
wirft(fn() => Seite::leistungenPruefen(json_encode(array_fill(0, 41, ['label' => 'X', 'cent' => 1]))), 'zu viele Positionen');
wirft(fn() => Seite::vorgangAnlegen(['vorlage_id' => $kvl, 'pad_id' => $padT, 'patient' => 'A', 'leistungen_json' => $liste, 'quelle' => 'kasse', 'quelle_ref' => '../x'], 'x', $praxis), 'ungültiger Verweis');
check(Seite::leistungenPruefen('') === [], 'leere Liste');
$ohne = Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => $kvl, 'pad_id' => $padT, 'patient' => 'Ohne Liste', 'leistung' => 'Einzelne Leistung', 'betrag' => '20'], 'empfang', $praxis, '', $t0));
check($ohne['quelle'] === '' && str_contains($ohne['text'], 'Einzelne Leistung'), 'Ohne Liste bleibt es bei der Leistung');

// Kontaktdaten als Platzhalter
$kt = Seite::vorlageSpeichern(['art' => 'sonstige', 'titel' => 'Kontakt-Test', 'text' => 'Tel {{telefon}} Mail {{email}} Geb {{geburtsdatum}} Adr {{adresse}}', 'aktiv' => 1]);
$kd = Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => $kt, 'pad_id' => $padT, 'patient' => 'K', 'telefon' => '0711 1234', 'email' => 'max@example.invalid', 'geburtsdatum' => '1980-04-12', 'adresse' => 'Hauptstr. 1, 70173 Stuttgart'], 'x', $praxis));
check($kd['text'] === 'Tel 0711 1234 Mail max@example.invalid Geb 12.04.1980 Adr Hauptstr. 1, 70173 Stuttgart', 'Kontaktdaten als Platzhalter');
$kl = Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => $kt, 'pad_id' => $padT, 'patient' => 'K'], 'x', $praxis));
check($kl['text'] === 'Tel … Mail … Geb … Adr …', 'Ohne Kontaktdaten bleiben Auslassungspunkte');
wirft(fn() => Seite::vorgangAnlegen(['vorlage_id' => $kt, 'pad_id' => $padT, 'patient' => 'K', 'email' => 'kein-mail'], 'x', $praxis), 'ungültige E-Mail');
wirft(fn() => Seite::vorgangAnlegen(['vorlage_id' => $kt, 'pad_id' => $padT, 'patient' => 'K', 'telefon' => 'abc<script>'], 'x', $praxis), 'ungültiges Telefon');

// API-Schlüssel: leer = klare Meldung, kein Ersatzschlüssel; 403 mit Hinweis
$kopfTest = new class(['base_url' => 'https://t2med.example.org/aps/fhir/api/r4', 'api_key' => '']) extends Fhir {
    public array $kopf = []; public int $status = 403;
    protected function anfrage(string $url, string $token, string $methode = 'GET', ?array $body = null, array $mehr = [], string $profil = ''): array { return ['status' => $this->status, 'body' => null]; }
};
try { $kopfTest->patient('k1', 'tok'); check(false, '403 ohne Fehler'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'HTTP 403') && str_contains($e->getMessage(), 'API-Schlüssel'), '403 mit Hinweis auf den API-Schlüssel'); }
$rm = new ReflectionMethod(Fhir::class, 'anfrage');
$ohne = new Fhir(['base_url' => 'https://t2med.example.org/aps/fhir/api/r4', 'api_key' => '']);
try { $ohne->patient('k1', 'tok'); check(false, 'ohne Schlüssel kein Fehler'); } catch (RuntimeException $e) { check($e->getMessage() === Fhir::OHNE_SCHLUESSEL, 'ohne Schlüssel klare Meldung'); }
check(!defined(Fhir::class . '::DEMO_SCHLUESSEL'), 'kein Demo-Schlüssel im Quelltext');

// Editor C: Bausteine prüfen, Klartext, sichere Formatierung, Fassungen, fester Stand am Vorgang, Bilder
$bl = [
    ['typ' => 'text', 'text' => "# Kopf\n**fett** und *kursiv* <script>x</script>\n- eins\n1. zwei"],
    ['typ' => 'patientendaten', 'felder' => ['email', 'telefon', 'boese'], 'aenderbar' => 1],
    ['typ' => 'ankreuz', 'text' => 'SMS an {{telefon}}', 'pflicht' => 1],
    ['typ' => 'einzel', 'frage' => 'Befund?', 'optionen' => ['Post', '', 'E-Mail'], 'pflicht' => 1],
    ['typ' => 'mehrfach', 'frage' => 'Wer?', 'optionen' => ['A', 'B']],
    ['typ' => 'liste', 'eintraege' => ['x', 'y'], 'nummeriert' => 1],
    ['typ' => 'trenner'], ['typ' => 'seite'],
];
$gp = Bausteine::pruefen(json_encode($bl));
check(count($gp) === 8 && $gp[1]['felder'] === ['email', 'telefon'] && $gp[3]['optionen'] === ['Post', 'E-Mail'] && $gp[2]['pflicht'] === true && !$gp[4]['pflicht'], 'Bausteine vereinheitlicht');
check(count(array_unique(array_column($gp, 'id'))) === 8, 'jede Zeile hat eine eigene Kennung');
wirft(fn() => Bausteine::pruefen('[]'), 'leere Vorlage');
wirft(fn() => Bausteine::pruefen([['typ' => 'skript']]), 'unbekannte Art');
wirft(fn() => Bausteine::pruefen([['typ' => 'einzel', 'frage' => 'F', 'optionen' => ['nur eine']]]), 'Auswahl mit einer Möglichkeit');
wirft(fn() => Bausteine::pruefen([['typ' => 'text', 'text' => 'Hallo {{nix}}']]), 'unbekannter Platzhalter im Baustein');
wirft(fn() => Bausteine::pruefen([['typ' => 'bild', 'datei' => '../../etc/passwd']]), 'Bild mit Pfad');
wirft(fn() => Bausteine::pruefen([['typ' => 'patientendaten', 'felder' => []]]), 'Patientendaten ohne Feld');
$ak = Bausteine::pruefen([['typ' => 'ankreuz', 'text' => 'Fotos', 'akte' => 'Fotos für {{patient}}: {{antwort}}'], ['typ' => 'trenner', 'akte' => 'x']]);
check($ak[0]['akte'] === 'Fotos für {{patient}}: {{antwort}}' && !isset($ak[1]['akte']), 'Akteneintrag je Baustein ({{antwort}} erlaubt, Trenner ohne)');
wirft(fn() => Bausteine::pruefen([['typ' => 'text', 'text' => 'x', 'akte' => '{{nix}}']]), 'unbekannter Platzhalter im Akteneintrag');
check(Bausteine::einsetzen($ak, ['patient' => 'Max Mustermann'])[0]['akte'] === 'Fotos für Max Mustermann: {{antwort}}', 'Akteneintrag: Platzhalter eingesetzt, {{antwort}} bleibt fürs Pad');
wirft(fn() => Seite::vorlageSpeichern(['art' => 'sonstige', 'titel' => 'K', 'kuerzel' => 'mit Leer', 'bloecke' => json_encode($bl)]), 'ungültiges Kürzel');
$h = Bausteine::html($gp[0]['text']);
check(str_contains($h, '<h3>Kopf</h3>') && str_contains($h, '<strong>fett</strong>') && str_contains($h, '<em>kursiv</em>') && str_contains($h, '&lt;script&gt;') && !str_contains($h, '<script>')
    && str_contains($h, '<ul><li>eins</li></ul><ol><li>zwei</li></ol>'), 'Formatierung sicher als HTML: ' . $h);
$txt = Bausteine::alsText(Bausteine::einsetzen($gp, ['telefon' => '030 1', 'email' => 'm@example.org']));
check(str_contains($txt, "Kopf\nfett und kursiv") && str_contains($txt, '[ ] SMS an 030 1 *') && str_contains($txt, "( ) Post") && str_contains($txt, 'E-Mail: m@example.org') && str_contains($txt, '1. x'), 'Klartext für Pad und PDF: ' . $txt);
$vid = Seite::vorlageSpeichern(['art' => 'datenschutz', 'titel' => 'Bausteine', 'aktiv' => 1, 'bloecke' => json_encode($bl)]);
check((int)Seite::vorlage($vid)['version'] === 1, 'neue Vorlage: Fassung 1');
Seite::vorlageSpeichern(['id' => $vid, 'art' => 'datenschutz', 'titel' => 'Bausteine', 'aktiv' => 1, 'bloecke' => json_encode(Seite::bloeckeVon(Seite::vorlage($vid)))]);
check((int)Seite::vorlage($vid)['version'] === 1, 'unverändert gespeichert: gleiche Fassung');
$geaendert = Seite::bloeckeVon(Seite::vorlage($vid)); $geaendert[2]['text'] = 'SMS bitte';
Seite::vorlageSpeichern(['id' => $vid, 'art' => 'datenschutz', 'titel' => 'Bausteine', 'aktiv' => 1, 'bloecke' => json_encode($geaendert)]);
check((int)Seite::vorlage($vid)['version'] === 2, 'geändert: Fassung 2');
$vg = Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => $vid, 'patient' => 'Max Mustermann', 'telefon' => '030 99', 'email' => 'max@example.org', 'pad_id' => (int)(Seite::pads(true)[0]['id'] ?? 0)], 'x', $praxis));
$vb = json_decode($vg['bloecke'], true);
check((int)$vg['vorlage_version'] === 2 && $vb[1]['werte'] === ['email' => 'max@example.org', 'telefon' => '030 99'] && str_contains($vg['text'], '[ ] SMS bitte *'), 'Vorgang: Fassung und fester Stand der Bausteine');
check(Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => (int)Seite::vorlagen(true)[0]['id'], 'patient' => 'K', 'pad_id' => (int)(Seite::pads(true)[0]['id'] ?? 0)], 'x', $praxis))['bloecke'] !== '', 'alte Textvorlage als Text-Baustein');
// Bilder: nur PNG/JPEG, Name = Prüfsumme, im Datenordner
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$bn = Bausteine::bildSpeichern($png);
check(preg_match('/^[0-9a-f]{64}\.png$/D', $bn) === 1 && Bausteine::bildPfad($bn) !== null && Bausteine::bildPfad('../x.png') === null, 'Bild gespeichert');
wirft(fn() => Bausteine::bildSpeichern('<svg onload=x>'), 'kein PNG/JPEG');
check(Bausteine::pruefen([['typ' => 'bild', 'datei' => $bn, 'breite' => 500]])[0]['breite'] === 100, 'Bildbreite begrenzt');
foreach (glob(Speicher::$data . '/bilder/*') ?: [] as $f) @unlink($f); @rmdir(Speicher::$data . '/bilder');
// Editor-Seite: Daten als JSON ohne ausführbares Skript, Palette, Vorlagenliste
Ansicht::$editorVorlage = (string)$vid;
$ed = Ansicht::seite('/editor', 'tok', [], Seite::vorlagen(), true, true, null, null);
check(str_contains($ed, '<script type="application/json" id="ed-daten">') && !str_contains($ed, '<script>x') && str_contains($ed, 'data-typ="mehrfach"') && str_contains($ed, 'id="ed-speichern"')
    && str_contains($ed, 'editor?vorlage=neu'), 'Editor-Seite');
$daten = json_decode(html_entity_decode(preg_replace('#^.*<script type="application/json" id="ed-daten">(.*?)</script>.*$#s', '$1', $ed)), true);
check(($daten['vorlage']['id'] ?? 0) === $vid && ($daten['vorlage']['version'] ?? 0) === 2 && count($daten['bloecke'] ?? []) === 8, 'Editor-Daten der gewählten Vorlage');
Ansicht::$editorVorlage = '';
// Vorlagenliste: Gruppen, Favoriten, zuletzt gewählt, Suche
check(Seite::gruppenVon(Seite::vorlage($vid)) === ['Administratives'], 'Gruppe aus der Dokumentart vorbelegt');
check(Seite::gruppenPruefen(' IGeL ,igel, Aufklärungen,, Haut & Haar') === 'IGeL, Aufklärungen, Haut & Haar', 'Gruppen bereinigt, doppelte entfernt');
wirft(fn() => Seite::gruppenPruefen('<b>'), 'Gruppe mit Sonderzeichen');
wirft(fn() => Seite::gruppenPruefen('a,b,c,d,e,f,g'), 'zu viele Gruppen');
Seite::vorlageSpeichern(['id' => $vid, 'art' => 'datenschutz', 'titel' => 'Bausteine', 'aktiv' => 1, 'gruppen' => 'Administratives, Hautarzt', 'bloecke' => json_encode(Seite::bloeckeVon(Seite::vorlage($vid)))]);
check(Seite::vorlage($vid)['gruppen'] === 'Administratives, Hautarzt' && (int)Seite::vorlage($vid)['version'] === 2, 'Gruppen gespeichert, Fassung bleibt');
Seite::favorit($vid, true);
check(Seite::zuletzt()[0] === (int)$vg['vorlage_id'] || Seite::zuletzt() !== [], 'zuletzt gewählte Vorlagen');
$st = Ansicht::seite('/', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null);
check(str_contains($st, 'id="dok-suche"') && str_contains($st, 'data-filter="g:hautarzt"') && str_contains($st, 'data-favorit="' . $vid . '" aria-pressed="true"') && str_contains($st, 'class="us-chips us-zuletzt"')
    && strpos($st, 'data-favorit="' . $vid . '"') < strpos($st, 'data-favorit="', strpos($st, 'data-favorit="' . $vid . '"') + 5), 'Startseite: Suche, Gruppen, Favorit oben, Zuletzt');
Ansicht::$editorVorlage = (string)$vid;
$ed2 = Ansicht::seite('/editor', 'tok', [], Seite::vorlagen(), true, true, null, null);
check(str_contains($ed2, 'id="ed-vsuche"') && str_contains($ed2, 'class="ed-vgkopf">Administratives') && str_contains($ed2, 'id="ed-gruppen"') && str_contains($ed2, '<option value="Hautarzt">'), 'Editor: Suche, Gruppen');
Ansicht::$editorVorlage = '';
Seite::favorit($vid, false);
wirft(fn() => Seite::favorit(999999, true), 'Favorit unbekannter Vorlage');
// Export und Import: Datei mit Bildern, Import legt neue Vorlagen an (Titel mit „(Import)“), alles geprüft
$bildName = Bausteine::bildSpeichern($png);
$mitBild = Seite::vorlageSpeichern(['art' => 'aufklaerung', 'titel' => 'Export-Test', 'aktiv' => 1, 'gruppen' => 'Aufklärungen', 'kuerzel' => 'AUFK',
    'bloecke' => json_encode([['typ' => 'text', 'text' => 'Hallo {{patient}}'], ['typ' => 'bild', 'datei' => $bildName], ['typ' => 'ankreuz', 'text' => 'Ja', 'akte' => 'OK: {{antwort}}']])]);
$exp = Seite::vorlagenExport([$mitBild]);
$ed = json_decode($exp, true);
check($ed['format'] === Seite::EXPORT_FORMAT && count($ed['vorlagen']) === 1 && isset($ed['bilder'][$bildName]) && !str_contains($exp, 'Max Mustermann'), 'Export mit Bild, ohne Patientendaten');
check(count(json_decode(Seite::vorlagenExport(), true)['vorlagen']) === count(Seite::vorlagen()), 'alle exportieren');
foreach (glob(Speicher::$data . '/bilder/*') ?: [] as $f) @unlink($f);
$anz = count(Seite::vorlagen());
$neuTitel = Seite::vorlagenImport($exp);
check($neuTitel === ['Export-Test (Import)'] && count(Seite::vorlagen()) === $anz + 1, 'Import legt neue Vorlage an');
$imp = null; foreach (Seite::vorlagen() as $x) if ($x['titel'] === 'Export-Test (Import)') $imp = $x;
$ib = Seite::bloeckeVon($imp);
check($imp['gruppen'] === 'Aufklärungen' && $imp['kuerzel'] === 'AUFK' && Bausteine::bildPfad($ib[1]['datei']) !== null && $ib[2]['akte'] === 'OK: {{antwort}}', 'Import: Gruppen, Kürzel, Bild, Akteneintrag');
check(Seite::vorlagenImport($exp) === ['Export-Test (Import 2)'], 'zweiter Import: eigener Titel');
$kaputt = $ed; $kaputt['vorlagen'][] = ['titel' => 'Böse', 'art' => 'sonstige', 'bloecke' => [['typ' => 'text', 'text' => '{{geheim}}']]];
$anz = count(Seite::vorlagen());
wirft(fn() => Seite::vorlagenImport(json_encode($kaputt)), 'Import mit ungültigem Baustein');
check(count(Seite::vorlagen()) === $anz, 'Import: alles oder nichts');
wirft(fn() => Seite::vorlagenImport('{"format":"anders"}'), 'fremde Datei');
$ohneBild = $ed; $ohneBild['bilder'] = [];
wirft(fn() => Seite::vorlagenImport(json_encode($ohneBild)), 'Bild fehlt');
foreach (glob(Speicher::$data . '/bilder/*') ?: [] as $f) @unlink($f); @rmdir(Speicher::$data . '/bilder');
// Signatur 4: Vorlage je IGeL-Leistung, Einwilligungen mit Ablauf, zweite Unterschrift
$fest4 = Seite::festeVorlageId();
$reise = Seite::vorlageSpeichern(['art' => 'igel', 'titel' => 'Reiseimpfung', 'aktiv' => 1, 'leistungen' => ' 5855 , Reiseberatung,5855', 'bloecke' => json_encode([['typ' => 'text', 'text' => 'Reise {{patient}}']])]);
check(Seite::vorlage($reise)['leistungen'] === '5855, Reiseberatung', 'IGeL-Leistungen bereinigt');
check(Seite::vorlageFuerLeistungen([['code' => '5855', 'label' => 'x']]) === $reise && Seite::vorlageFuerLeistungen([['code' => '', 'label' => 'reiseberatung']]) === $reise
    && Seite::vorlageFuerLeistungen([['code' => '1', 'label' => 'Ultraschall']]) === $fest4, 'Vorlage je Leistung, sonst Standard');
$stIgel = Ansicht::seite('/', 'tok', [], Seite::vorlagen(), true, true, null, null);
check(str_contains($stIgel, 'data-leistungen="5855|reiseberatung"') && str_contains($stIgel, 'value="' . $fest4 . '" data-art="igel"') && str_contains($stIgel, 'data-standard="1"'), 'Startseite: Leistungen und Standard für die Auswahl');
wirft(fn() => Seite::leistungenListe(str_repeat('a', 81)), 'zu lange Leistung');
// Einwilligung mit Ablauf
check(Seite::einwilligungMonate() === 24, 'Standard 24 Monate');
Seite::einwilligungMonateSetzen('12');
check(Seite::einwilligungMonate() === 12, 'Monate gespeichert');
wirft(fn() => Seite::einwilligungMonateSetzen('0'), 'Monate 0');
$ds = Seite::vorlageSpeichern(['art' => 'datenschutz', 'titel' => 'Datenschutz neu', 'aktiv' => 1, 'bloecke' => json_encode([['typ' => 'text', 'text' => 'DS']])]);
check((int)Seite::vorlage($ds)['ablauf'] === 1, 'Datenschutz: Einwilligung mit Ablauf vorbelegt');
$padE = (int)(Seite::pads(true)[0]['id'] ?? 0);
$ew = Seite::vorgangAnlegen(['vorlage_id' => $ds, 'patient' => 'Max  Mustermann', 'geburtsdatum' => '01.02.1970', 'pad_id' => $padE], 'x', $praxis);
check(str_starts_with(Seite::vorgang($ew)['patient_schluessel'], 'h:') && !str_contains(Seite::vorgang($ew)['patient_schluessel'], 'Muster'), 'Schlüssel ohne Klartext');
Speicher::pdo()->prepare("UPDATE vorgaenge SET status='unterschrieben', unterschrieben=? WHERE id=?")->execute([strtotime('2026-01-15 10:00'), $ew]);
Seite::einwilligungMerken(Seite::vorgang($ew));
Seite::einwilligungMerken(Seite::vorgang($ew));
$liste = Seite::einwilligungen(Seite::patientSchluessel('', 'max mustermann', '01.02.1970'), strtotime('2026-06-01'));
check(count($liste) === 1 && $liste[0]['vorlage_id'] === $ds && $liste[0]['gueltig'] && date('Y-m-d', $liste[0]['bis']) === '2027-01-15', 'Einwilligung gültig bis (12 Monate)');
check(!Seite::einwilligungen(Seite::patientSchluessel('', 'max mustermann', '01.02.1970'), strtotime('2027-02-01'))[0]['gueltig'], 'danach abgelaufen');
check(Seite::patientSchluessel('', 'Max', '') === '' && Seite::patientSchluessel('p-7', 'x', '') === 'p:p-7', 'Schlüssel nur mit Geburtsdatum oder T2med-ID');
$einst = Ansicht::seite('/einstellungen', 'tok', [], Seite::vorlagen(), true, true, null, null, null, null);
check(str_contains($einst, 'value="einwilligung-zeit"') && str_contains($einst, 'value="12"'), 'Einstellung Gültigkeit');
Seite::aufraeumen(strtotime('2028-02-01'));
check(Seite::einwilligungen(Seite::patientSchluessel('', 'max mustermann', '01.02.1970')) === [], 'ein Jahr nach Ablauf gelöscht');
// Zweite Unterschrift: Fassung steigt, Vorgang übernimmt die Bezeichnung
$auf = Seite::vorlageSpeichern(['art' => 'aufklaerung', 'titel' => 'Aufklärung OP', 'aktiv' => 1, 'bloecke' => json_encode([['typ' => 'text', 'text' => 'OP']])]);
Seite::vorlageSpeichern(['id' => $auf, 'art' => 'aufklaerung', 'titel' => 'Aufklärung OP', 'aktiv' => 1, 'zweite' => 'Ärztin/Arzt', 'bloecke' => json_encode(Seite::bloeckeVon(Seite::vorlage($auf)))]);
check(Seite::vorlage($auf)['zweite'] === 'Ärztin/Arzt' && (int)Seite::vorlage($auf)['version'] === 2, 'zweite Unterschrift: neue Fassung');
check(Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => $auf, 'patient' => 'K', 'pad_id' => $padE], 'x', $praxis))['zweite'] === 'Ärztin/Arzt', 'Vorgang mit zweiter Unterschrift');
$ex4 = json_decode(Seite::vorlagenExport([$reise]), true)['vorlagen'][0];
check($ex4['leistungen'] === '5855, Reiseberatung' && $ex4['sprache'] === 'de', 'Export mit Leistungen und Sprache');
// Übersetzung (DeepL, nachgebaut): Platzhalter und Auszeichnung geschützt, Zeilenanfänge bleiben, Ergebnis inaktiv zum Prüfen
check(\KienzleSignatur\Uebersetzung::schuetzen('Hallo {{patient}}, **bitte** *lesen* & __jetzt__ <x>') === 'Hallo <ph>{{patient}}</ph>, <b>bitte</b> <i>lesen</i> &amp; <u>jetzt</u> &lt;x&gt;', 'Text geschützt');
check(\KienzleSignatur\Uebersetzung::zurueck('Hello <ph>{{patient}}</ph>, <b>please</b> <i>read</i> &amp; <u>now</u> &lt;x&gt;') === 'Hello {{patient}}, **please** *read* & __now__ <x>', 'zurück in Klartext');
$deepl = new class(['api_key' => '0000-beispiel:fx', 'url' => 'https://api-free.deepl.example.org/v2']) extends \KienzleSignatur\Uebersetzung {
    public array $koerper = []; public int $status = 200;
    protected function anfrage(string $koerper): array {
        $this->koerper[] = $koerper;
        preg_match_all('/(?:^|&)text=([^&]*)/', $koerper, $m);
        return ['status' => $this->status, 'body' => ['translations' => array_map(fn($t) => ['text' => 'EN:' . rawurldecode($t)], $m[1])]];
    }
};
$quelle = Seite::vorlageSpeichern(['art' => 'aufklaerung', 'titel' => 'Aufklärung Haut', 'aktiv' => 1, 'kuerzel' => 'HAUT', 'akte' => '', 'bloecke' => json_encode([
    ['typ' => 'text', 'text' => "# Kopf\n- Punkt für {{patient}}\nNormal **fett**"], ['typ' => 'einzel', 'frage' => 'Befund?', 'optionen' => ['Post', 'Mail'], 'akte' => 'Befund: {{antwort}}'],
    ['typ' => 'ankreuz', 'text' => 'Ja, einverstanden']])]);
$enId = $deepl->vorlage($quelle, 'en');
$en = Seite::vorlage($enId); $eb = Seite::bloeckeVon($en);
check($en['sprache'] === 'en' && (int)$en['original'] === $quelle && (int)$en['aktiv'] === 0 && $en['titel'] === 'EN:Aufklärung Haut (Englisch)' && $en['kuerzel'] === 'HAUT', 'Sprachfassung inaktiv mit Verweis');
check($eb[0]['text'] === "# EN:Kopf\n- EN:Punkt für {{patient}}\nEN:Normal **fett**" && $eb[1]['optionen'] === ['EN:Post', 'EN:Mail'] && $eb[1]['akte'] === 'Befund: {{antwort}}' && $eb[2]['text'] === 'EN:Ja, einverstanden', 'Bausteine übersetzt, Akte deutsch');
check(str_contains($deepl->koerper[0], 'target_lang=EN-GB') && str_contains($deepl->koerper[0], 'tag_handling=xml') && str_contains($deepl->koerper[0], 'ignore_tags=ph') && !str_contains($deepl->koerper[0], 'Mustermann'), 'Anfrage an DeepL ohne Patientendaten');
check($deepl->vorlage($quelle, 'en') === $enId, 'erneutes Übersetzen erneuert dieselbe Fassung');
wirft(fn() => $deepl->vorlage($enId, 'tr'), 'Sprachfassung nicht weiter übersetzen');
wirft(fn() => $deepl->vorlage($quelle, 'xx'), 'unbekannte Sprache');
$deepl->status = 456;
$lauf = function (callable $f): string { try { $f(); } catch (RuntimeException $e) { return $e->getMessage(); } return ''; };
check(str_contains($lauf(fn() => $deepl->vorlage($quelle, 'tr')), 'Zeichenkontingent'), 'Kontingent aufgebraucht');
check(str_contains($lauf(fn() => (new \KienzleSignatur\Uebersetzung([]))->texte(['a'], 'en')), 'nicht eingerichtet'), 'ohne Verbindung');
Seite::vorlageSpeichern(['id' => $enId, 'art' => 'aufklaerung', 'titel' => $en['titel'], 'aktiv' => 1, 'bloecke' => json_encode($eb)]);
$stSp = Ansicht::seite('/', 'tok', [], Seite::vorlagen(), true, true, null, null);
check(str_contains($stSp, 'class="us-sprache" data-fuer="' . $quelle . '"') && str_contains($stSp, '<option value="' . $enId . '">Englisch</option>') && !str_contains($stSp, 'name="vorlage_id" value="' . $enId . '"'), 'Startseite: Sprachfassung als Auswahl an der deutschen Vorlage');
$vgEn = Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => $enId, 'patient' => 'Max Mustermann', 'pad_id' => $padE], 'x', $praxis));
check($vgEn['sprache'] === 'en' && str_contains($vgEn['text'], 'EN:Punkt für Max Mustermann'), 'Vorgang in der Sprachfassung');
Ansicht::$editorVorlage = (string)$enId;
$edEn = json_decode(html_entity_decode(preg_replace('#^.*<script type="application/json" id="ed-daten">(.*?)</script>.*$#s', '$1', Ansicht::seite('/editor', 'tok', [], Seite::vorlagen(), true, true, null, null))), true);
check($edEn['vorlage']['sprache'] === 'en' && count($edEn['fassungen']) === 2, 'Editor kennt die Fassungen');
Ansicht::$editorVorlage = '';

// Anrede-Platzhalter
$at = Seite::vorlageSpeichern(['art' => 'sonstige', 'titel' => 'Anrede-Test', 'text' => '{{briefanrede}}|{{anrede}}|{{vorname}}|{{nachname}}', 'aktiv' => 1]);
$an = fn(array $x) => Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => $at, 'pad_id' => $padT, 'patient' => 'Max Mustermann'] + $x, 'x', $praxis))['text'];
check($an(['geschlecht' => 'male', 'vorname' => 'Max', 'nachname' => 'Mustermann']) === 'Sehr geehrter Herr Mustermann|Herr|Max|Mustermann', 'Briefanrede Herr');
check($an(['geschlecht' => 'female', 'vorname' => 'Erika', 'nachname' => 'Musterfrau']) === 'Sehr geehrte Frau Musterfrau|Frau|Erika|Musterfrau', 'Briefanrede Frau');
check($an(['geschlecht' => 'other', 'vorname' => 'Alex', 'nachname' => 'Beispiel']) === 'Guten Tag Alex Beispiel|…|Alex|Beispiel', 'Briefanrede neutral');
check($an([]) === 'Guten Tag Max Mustermann|…|Max|Mustermann', 'Namen aus dem Gesamtnamen');
wirft(fn() => $an(['geschlecht' => 'x']), 'ungültiges Geschlecht');

// In T2med übernehmen (nur Telefon/E-Mail, nur auf Knopfdruck, nach der Unterschrift)
$fake = new class(['base_url' => 'https://t2med.example.org/aps/fhir/api/r4']) extends Fhir {
    public array $aufrufe = []; public int $putStatus = 200;
    public int $postStatus = 201; public ?array $postAntwort = null;
    protected function anfrage(string $url, string $token, string $methode = 'GET', ?array $body = null, array $mehr = [], string $profil = 'https://fhir.t2med.de/StructureDefinition/FhirApiPatient|1.0.0'): array {
        $this->aufrufe[] = [$methode, $url, $token, $mehr, $body, $profil];
        if ($methode === 'POST') return ['status' => $this->postStatus, 'body' => $this->postAntwort];
        if ($methode === 'GET') return ['status' => 200, 'body' => ['resourceType' => 'Patient', 'id' => 'p1', 'meta' => ['versionId' => 'abc/_history/4'], 'address' => [['city' => 'Stuttgart']],
            'telecom' => [['system' => 'phone', 'value' => '0711 1', 'use' => 'home'], ['system' => 'email', 'value' => 'alt@example.invalid', 'use' => 'work']]]];
        return ['status' => $this->putStatus, 'body' => null];
    }
};
Start::anlegen($eingabe, $fhir, 'kienzle.example.org', time());
$kid2 = Seite::vorgangAnlegen(['vorlage_id' => $at, 'pad_id' => $padT, 'patient' => 'Max Mustermann', 'patient_id' => 'p1', 'telefon_alt' => '0711 1234', 'email_alt' => 'max@example.invalid',
    'telefon' => '0711 9999', 'email' => 'max@example.invalid', 'kontakt_uebernehmen' => '1'], 'x', $praxis, 'kontext-1');
$k2 = Seite::vorgang($kid2);
check($k2['kontakt_status'] === 'offen' && json_decode($k2['kontakt_neu'], true) === ['telefon' => '0711 9999', 'email' => ''], 'Nur geänderte Angaben vorgemerkt');
wirft(fn() => Seite::kontaktUebernehmen($kid2, $fake), 'vor der Unterschrift keine Übernahme');
Speicher::pdo()->prepare("UPDATE vorgaenge SET status='unterschrieben' WHERE id=?")->execute([$kid2]);
check(Seite::kontaktUebernehmen($kid2, $fake) === 'Telefon in T2med übernommen.', 'Übernahme meldet Telefon');
$put = $fake->aufrufe[1];
check($put[0] === 'PUT' && $put[2] === 'tok' && in_array('If-Match: W/"4"', $put[3], true) && $put[4]['address'] === [['city' => 'Stuttgart']] && $put[4]['telecom'][0]['value'] === '0711 9999' && $put[4]['telecom'][1]['value'] === 'alt@example.invalid', 'PUT mit If-Match, Rest unverändert');
check(Seite::vorgang($kid2)['kontakt_status'] === 'geschrieben', 'Status geschrieben');
$ohneHaken = Seite::vorgang(Seite::vorgangAnlegen(['vorlage_id' => $at, 'pad_id' => $padT, 'patient' => 'A B', 'patient_id' => 'p1', 'telefon' => '0711 5', 'telefon_alt' => '0711 6'], 'x', $praxis, 'kontext-1'));
check($ohneHaken['kontakt_status'] === '', 'Ohne Häkchen nichts vorgemerkt');
$kid3 = Seite::vorgangAnlegen(['vorlage_id' => $at, 'pad_id' => $padT, 'patient' => 'A B', 'patient_id' => 'p1', 'email' => 'neu@example.invalid', 'email_alt' => 'x@example.invalid', 'kontakt_uebernehmen' => '1'], 'x', $praxis, 'kontext-1');
Speicher::pdo()->prepare("UPDATE vorgaenge SET status='unterschrieben' WHERE id=?")->execute([$kid3]);
$fake->putStatus = 409;
wirft(fn() => Seite::kontaktUebernehmen($kid3, $fake), 'Versionskonflikt');
check(Seite::vorgang($kid3)['kontakt_status'] === 'fehler' && str_contains(Seite::vorgang($kid3)['kontakt_meldung'], 'geändert'), 'Fehler gemerkt');
wirft(fn() => $fake->kontaktSchreiben('../x', 'tok', '0711 1', ''), 'ungültige Patienten-ID');
$fake->aufrufe = [];
check($fake->kontaktSchreiben('p1', 'tok', '0711 1', 'alt@example.invalid') === ['telefon' => false, 'email' => false] && count($fake->aufrufe) === 1, 'Unveränderte Werte: kein PUT');

// Fester IGeL-Vertrag: Art/Aktiv fest, Text änderbar.
$fest = Seite::festeVorlageId();
check($fest > 0, 'fester IGeL-Vertrag vorhanden');
Seite::vorlageSpeichern(['id' => $fest, 'art' => 'sonstige', 'titel' => 'IGeL neu', 'text' => 'Neuer Text {{patient}}', 'aktiv' => '']);
$fv = Seite::vorlage($fest);
check($fv['art'] === 'igel' && (int)$fv['aktiv'] === 1 && $fv['text'] === 'Neuer Text {{patient}}', 'fester IGeL-Vertrag: Text änderbar, Art und Aktiv bleiben');

// Ablage in T2med: direkt (Start aus T2med) oder über die Kasse (Austauschordner); ohne Weg „manuell“.
\KienzleSignatur\Ablage::$austausch = $dir . '/austausch';
mkdir($dir . '/austausch', 0770);
@mkdir($dir . '/unterschriften', 0700);
$neuerSignierter = function (string $kontext, string $quelle = '', string $ref = '') use ($at, $padT, $praxis, $dir): int {
    $id = Seite::vorgangAnlegen(['vorlage_id' => $at, 'pad_id' => $padT, 'patient' => 'Max Mustermann'], 'x', $praxis, $kontext);
    Speicher::pdo()->prepare("UPDATE vorgaenge SET status='unterschrieben', unterschrieben=?, quelle=?, quelle_ref=?, ablage='' WHERE id=?")->execute([1795000000, $quelle, $ref, $id]);
    file_put_contents("$dir/unterschriften/$id.pdf", "%PDF-1.4 erfunden $id");
    return $id;
};
$direkt = $neuerSignierter('kontext-1');
$ueberKasse = $neuerSignierter('', 'kasse', 'visit_abc-1');
$ohne = $neuerSignierter('');
$ohnePdf = Seite::vorgangAnlegen(['vorlage_id' => $at, 'pad_id' => $padT, 'patient' => 'Max Mustermann'], 'x', $praxis, 'kontext-1');
Speicher::pdo()->prepare("UPDATE vorgaenge SET status='unterschrieben', ablage='' WHERE id=?")->execute([$ohnePdf]);
check(\KienzleSignatur\Ablage::offen(), 'Ablage offen');
$fake->aufrufe = [];
\KienzleSignatur\Ablage::lauf(fn() => $fake);
$post = array_values(array_filter($fake->aufrufe, fn($a) => $a[0] === 'POST'));
check(count($post) === 1 && str_ends_with($post[0][1], '/DocumentReference') && $post[0][5] === '' && $post[0][2] === 'tok', 'Direkt: ein POST DocumentReference mit dem Token');
$res = $post[0][4];
check($res['resourceType'] === 'DocumentReference' && $res['meta']['profile'] === [Fhir::ANHANG] && $res['identifier'][0] === ['system' => Fhir::KONTEXT, 'value' => 'kontext-1']
    && $res['content'][0]['attachment']['contentType'] === 'application/pdf' && base64_decode($res['content'][0]['attachment']['data']) === "%PDF-1.4 erfunden $direkt"
    && $res['description'] === 'Anrede-Test (unterschrieben)' && $res['content'][0]['attachment']['title'] === 'Anrede-Test.pdf', 'Aufbau der DocumentReference');
check(Seite::vorgang($direkt)['status'] === 'uebertragen' && Seite::vorgang($direkt)['ablage'] === 'erledigt', 'direkt abgelegt');
check(Seite::vorgang($ueberKasse)['ablage'] === 'an_kasse' && is_file("$dir/austausch/an-kasse/$ueberKasse.pdf"), 'an die Kasse übergeben');
$auftrag = json_decode((string)file_get_contents("$dir/austausch/an-kasse/$ueberKasse.json"), true);
check($auftrag['vorgang'] === $ueberKasse && $auftrag['ref'] === 'visit_abc-1' && $auftrag['zeit'] === 1795000000 && (fileperms("$dir/austausch/an-kasse/$ueberKasse.json") & 0777) === 0660, 'Auftrag für die Kasse (0660)');
check(Seite::vorgang($ohne)['ablage'] === 'manuell' && Seite::vorgang($ohne)['status'] === 'unterschrieben', 'ohne Weg: manuell');
check(Seite::vorgang($ohnePdf)['ablage'] === '', 'ohne PDF noch warten');
// Akteneintrag: direkt nach dem PDF als Freitext mit Kürzel; über die Kasse im Auftrag
$mitAkte = $neuerSignierter('kontext-1');
Speicher::pdo()->prepare("UPDATE vorgaenge SET akte='Einwilligung: ja', kuerzel='EINW' WHERE id=?")->execute([$mitAkte]);
$fake->aufrufe = [];
\KienzleSignatur\Ablage::lauf(fn() => $fake);
$posts = array_values(array_filter($fake->aufrufe, fn($a) => $a[0] === 'POST'));
$obs = $posts[1][4]['entry'][0]['resource'] ?? [];
check(count($posts) === 2 && str_ends_with($posts[0][1], '/DocumentReference') && str_ends_with($posts[1][1], '/r4') && $posts[1][4]['type'] === 'transaction'
    && $obs['extension'][0]['valueString'] === 'EINW' && $obs['valueString'] === "Anrede-Test:\nEinwilligung: ja" && $obs['identifier'][0]['value'] === 'kontext-1', 'Akteneintrag nach dem PDF');
check(Seite::vorgang($mitAkte)['ablage'] === 'erledigt' && str_contains(Seite::vorgang($mitAkte)['meldung'], 'Akteneintrag nicht'), 'Eintrag ohne Bestätigung: PDF bleibt abgelegt, Hinweis');
$kasseAkte = $neuerSignierter('', 'kasse', 'visit_abc-2');
Speicher::pdo()->prepare("UPDATE vorgaenge SET akte='Befund per Post' WHERE id=?")->execute([$kasseAkte]);
\KienzleSignatur\Ablage::lauf(fn() => $fake);
$ka = json_decode((string)file_get_contents("$dir/austausch/an-kasse/$kasseAkte.json"), true);
check($ka['kuerzel'] === 'SIGN' && $ka['akte'] === "Anrede-Test:\nBefund per Post", 'Auftrag an die Kasse mit Akteneintrag (Kürzel SIGN ohne Angabe)');
@unlink("$dir/austausch/an-kasse/$kasseAkte.json"); @unlink("$dir/austausch/an-kasse/$kasseAkte.pdf");
Speicher::pdo()->prepare("UPDATE vorgaenge SET ablage='erledigt' WHERE id=?")->execute([$kasseAkte]);
mkdir("$dir/austausch/von-kasse", 0770);
file_put_contents("$dir/austausch/von-kasse/$ueberKasse.json", json_encode(['vorgang' => $ueberKasse, 'ok' => true, 'meldung' => '']));
\KienzleSignatur\Ablage::lauf(fn() => $fake);
check(Seite::vorgang($ueberKasse)['status'] === 'uebertragen' && Seite::vorgang($ueberKasse)['ablage'] === 'erledigt' && !is_file("$dir/austausch/von-kasse/$ueberKasse.json"), 'Antwort der Kasse übernommen');
// Fehler von T2med: Meldung mit Auskunft, Knopf „Erneut“ setzt zurück
$fehlerhaft = $neuerSignierter('kontext-1');
$fake->postStatus = 422; $fake->postAntwort = ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'diagnostics' => 'description fehlt']]];
\KienzleSignatur\Ablage::lauf(fn() => $fake);
$fv = Seite::vorgang($fehlerhaft);
check($fv['ablage'] === 'fehler' && str_contains($fv['meldung'], 'HTTP 422') && str_contains($fv['meldung'], 'description fehlt'), 'Fehler mit Auskunft von T2med');
check(str_contains(Ansicht::seite('/vorgaenge', 'tok', Seite::vorgaenge(), Seite::vorlagen(), true, true, null, null), 'value="ablage-erneut"'), 'Knopf Erneut ablegen');
\KienzleSignatur\Ablage::erneut($fehlerhaft);
$fake->postStatus = 201; $fake->postAntwort = ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'information']]];
\KienzleSignatur\Ablage::lauf(fn() => $fake);
check(Seite::vorgang($fehlerhaft)['status'] === 'uebertragen', 'erneut abgelegt');
wirft(fn() => \KienzleSignatur\Ablage::erneut($fehlerhaft), 'abgelegte Vorgänge nicht erneut');
// Fehlermeldung der Kasse; abgelaufener Token
$k2v = $neuerSignierter('', 'kasse', 'visit_x');
\KienzleSignatur\Ablage::lauf(fn() => $fake);
file_put_contents("$dir/austausch/von-kasse/$k2v.json", json_encode(['vorgang' => $k2v, 'ok' => false, 'meldung' => 'Zugang abgelaufen']));
\KienzleSignatur\Ablage::lauf(fn() => $fake);
check(Seite::vorgang($k2v)['ablage'] === 'fehler' && Seite::vorgang($k2v)['meldung'] === 'Kasse: Zugang abgelaufen', 'Fehler der Kasse übernommen');
$alt = $neuerSignierter('kontext-unbekannt');
\KienzleSignatur\Ablage::lauf(fn() => $fake);
check(Seite::vorgang($alt)['ablage'] === 'fehler' && str_contains(Seite::vorgang($alt)['meldung'], 'abgelaufen'), 'ohne gültigen Token: Fehler mit Hinweis');
// Patientenübergabe an die Kasse mit dem T2med-Zugang
Speicher::tokenMerken('k-ueb', 'tok-ueb', time() + 3600);
$code = \KienzleSignatur\Uebergabe::anKasse('k-ueb');
$ue = "$dir/austausch/uebergabe-kasse/$code.json";
$ud = json_decode((string)file_get_contents($ue), true);
check(preg_match('/^[0-9a-f]{32}$/D', $code) === 1 && $ud['kontext'] === 'k-ueb' && $ud['token'] === 'tok-ueb' && $ud['bis'] > time() && (fileperms($ue) & 0777) === 0660, 'Übergabe mit Token für die Kasse (0660)');
wirft(fn() => \KienzleSignatur\Uebergabe::anKasse('k-unbekannt'), 'ohne gültigen Token keine Übergabe');
wirft(fn() => \KienzleSignatur\Uebergabe::anKasse('../x'), 'ungültiger Kontext');
touch($ue, time() - 600);
\KienzleSignatur\Uebergabe::anKasse('k-ueb');
check(!is_file($ue), 'alte Übergaben werden weggeräumt');
foreach (array_merge(glob("$dir/austausch/*/*") ?: [], glob("$dir/austausch/*/.*") ?: []) as $f) if (is_file($f)) unlink($f);
foreach (glob("$dir/austausch/*") ?: [] as $d) rmdir($d);
rmdir("$dir/austausch");

echo "Signatur-Tests grün.\n";
