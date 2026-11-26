<?php
declare(strict_types=1);
// Modul fax: Nummern, Empfänger, Druckablage, Aufträge im Kienzlefax-Format, Abbruch, Abholen der Ergebnisse,
// Berichte, Eingang, Telefonbuch, Pfadprüfung, Aufräumen, Seiten. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleFax\{Ansicht, Fax};

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool { if (!(error_reporting() & $no)) return false; throw new ErrorException($msg, 0, $no, $file, $line); });
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg, string $text = ''): void {
    try { $f(); } catch (InvalidArgumentException | RuntimeException $e) { if ($text === '' || str_contains($e->getMessage(), $text)) return; fwrite(STDERR, "FEHLER: $msg (Meldung: {$e->getMessage()})\n"); exit(1); }
    fwrite(STDERR, "FEHLER: $msg\n"); exit(1);
}

$dir = sys_get_temp_dir() . '/fx-test-' . bin2hex(random_bytes(4));
mkdir("$dir/daten", 0700, true);
register_shutdown_function(static function () use ($dir) { Fax::zuruecksetzen(); exec('rm -r ' . escapeshellarg($dir)); });
Fax::$data = "$dir/daten";
Fax::$austausch = "$dir/austausch";
$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

// Faxdrucker: ohne Telefonbuch ein Drucker „vFax“; dann die Liste dieser Tests
check(Fax::druckerNamen() === ['vFax'], 'Vorgabe: ein Faxdrucker');
wirft(fn() => Fax::druckerSpeichern([]), 'ohne Drucker', 'Mindestens ein');
wirft(fn() => Fax::druckerSpeichern([['name' => 'Drucker2']]), 'falscher Vorsatz', 'beginnen');
wirft(fn() => Fax::druckerSpeichern([['name' => 'vFaxA'], ['name' => 'vFaxa']]), 'doppelt', 'doppelt');
wirft(fn() => Fax::druckerSpeichern([['name' => 'vFax', 'nummer' => '12']]), 'Nummer ungültig', 'ungültig');
wirft(fn() => Fax::druckerSpeichern(array_map(fn($i) => ['name' => 'vFax' . $i], range(1, 11))), 'zu viele', 'Höchstens');
Fax::druckerSpeichern([['name' => 'vFax1'], ['name' => 'vFax2'], ['name' => 'vFaxLabor', 'bezeichnung' => 'Labor Beispiel', 'nummer' => '030 9876543', 'sofort' => '1'], ['name' => '']]);
check(Fax::druckerNamen() === ['vFax1', 'vFax2', 'vFaxLabor'], 'Druckerliste gespeichert');
check(Fax::druckerEintrag('vFaxLabor')['nummer'] === '49309876543' && Fax::druckerEintrag('vFaxLabor')['sofort'] && !Fax::druckerEintrag('vFax2')['sofort'], 'feste Nummer, sofort senden');
$dj = json_decode((string)file_get_contents("$dir/daten/drucker.json"), true);
check($dj === ['drucker' => [['name' => 'vFax1'], ['name' => 'vFax2'], ['name' => 'vFaxLabor']]], 'drucker.json für die Basis (nur Namen)');
check((fileperms("$dir/daten/drucker.json") & 0777) === 0644, 'drucker.json lesbar für die Basis');

// Nummern wie normalize_fax_number
check(Fax::nummer('030 / 123 45-67') === '49301234567', 'national → 49');
check(Fax::nummer('0033 1 23456789') === '33123456789', '00 → international');
check(Fax::nummer('+49 30 1234567') === '49301234567', '+49');
check(Fax::nummer('12') === '' && Fax::nummer('abc') === '', 'zu kurz/leer');
check(Fax::anzeige('49301234567') === '0301234567' && Fax::anzeige('33123456789') === '+33123456789', 'Anzeige');

// Druckablage und Quellen
$n = Fax::druckAblegen('vFax2', $pdf, "Befund\x07 Max Mustermann.pdf", 1767268800);
$q = Fax::quelle('vFax2');
check(count($q) === 1 && $q[0]['datei'] === $n && $q[0]['titel'] === 'Befund Max Mustermann.pdf', 'Druck abgelegt, Steuerzeichen entfernt');
wirft(fn() => Fax::druckAblegen('vFax9', $pdf, ''), 'unbekannter Drucker');
check(Fax::quellen() === ['vFax1', 'vFax2', 'vFaxLabor', 'Upload'], 'Quellen ohne Lauschordner');
wirft(fn() => Fax::druckAblegen('vFax1', 'kein pdf', ''), 'kein PDF');
wirft(fn() => Fax::quelle('../eingang'), 'Quelle geprüft');
wirft(fn() => Fax::quellPfad('vFax2', '../../etc/passwd'), 'Pfad mit ..');
wirft(fn() => Fax::quellPfad('vFax2', 'gibtsnicht.pdf'), 'fehlende Datei', 'nicht gefunden');
check(Fax::quellenZaehler()['vFax2'] === 1, 'Zähler je Drucker');

// Empfänger und Telefonbuch
$kid = Fax::kontaktSpeichern(0, 'Labor Beispiel', '030 9876543', 'Befunde');
check(Fax::kontakt($kid)['nummer'] === '49309876543', 'Kontakt gespeichert (normalisiert)');
$e = Fax::empfaenger([['kontakt' => (string)$kid], ['name' => 'Dr. Erika Beispiel', 'nummer' => '030 1112223', 'merken' => '1'], ['name' => '', 'nummer' => '']]);
check(count($e) === 2 && $e[0]['name'] === 'Labor Beispiel' && $e[0]['nummer'] === '49309876543' && $e[1]['merken'], 'Empfänger aus Telefonbuch und Eingabe');
wirft(fn() => Fax::empfaenger([['name' => 'X', 'nummer' => '1']]), 'ungültige Nummer', 'Faxnummer');
wirft(fn() => Fax::empfaenger([]), 'kein Empfänger', 'mindestens einen');
wirft(fn() => Fax::empfaenger([['kontakt' => '999']]), 'unbekannter Kontakt', 'nicht gefunden');

// Senden ohne Telefon-Modul
wirft(fn() => Fax::senden('vFax2', [$n], $e, 'fine', true, 'mfa1'), 'ohne Austauschordner', 'Telefon');
mkdir("$dir/austausch", 0770);
check(Fax::senden('vFax2', [$n], $e, 'fine', true, 'mfa1') === 2, 'zwei Aufträge (zwei Empfänger)');
$jobs = glob("$dir/austausch/queue/JOB-*") ?: [];
check(count($jobs) === 2 && !glob("$dir/austausch/staging/*"), 'in queue, staging leer');
$j = json_decode((string)file_get_contents($jobs[0] . '/job.json'), true);
check($j['status'] === 'queued' && $j['source']['filename_original'] === 'Befund_Max_Mustermann.pdf' && $j['options']['resolution'] === 'fine' && $j['options']['ecm'] === true, 'job.json im Kienzlefax-Format');
check(is_file($jobs[0] . '/doc.pdf') && is_file($jobs[0] . '/source.pdf') && (fileperms($jobs[0] . '/doc.pdf') & 0060) === 0060, 'doc.pdf und source.pdf, gruppenlesbar');
check(Fax::quelle('vFax2') === [], 'Quelle nach dem Beauftragen leer');
check(count(Fax::kontakte()) === 2, 'neuer Empfänger im Telefonbuch');
$aktive = Fax::aktive();
check(count($aktive) === 2 && $aktive[0]['wo'] === 'queue', 'laufende Aufträge');
Fax::abbrechen($aktive[0]['id']);
check(json_decode((string)file_get_contents("$dir/austausch/queue/{$aktive[0]['id']}/job.json"), true)['cancel']['requested'] === true, 'Abbruch angefordert');
wirft(fn() => Fax::abbrechen('JOB-../x'), 'Auftrags-ID geprüft');

// Ergebnisse des Workers (nachgestellt) abholen
mkdir("$dir/austausch/sendeberichte", 0770);
mkdir("$dir/austausch/sendefehler/berichte", 0770, true);
mkdir("$dir/austausch/sendefehler/eingang", 0770, true);
mkdir("$dir/austausch/empfang", 0770);
$ok = ['job_id' => 'JOB-20260101-120000-aaaaaa', 'status' => 'OK', 'finalized_at' => '2026-01-01T12:05:00+00:00', 'recipient' => ['name' => 'Labor Beispiel', 'number' => '49309876543'],
    'source' => ['filename_original' => 'Befund.pdf'], 'result' => ['faxpages_sent' => 2, 'reason' => 'OK'], 'attempt' => ['current' => 1]];
file_put_contents("$dir/austausch/sendeberichte/Befund__JOB-20260101-120000-aaaaaa.json", json_encode($ok));
file_put_contents("$dir/austausch/sendeberichte/Befund__JOB-20260101-120000-aaaaaa__OK.pdf", $pdf);
$f = ['job_id' => 'JOB-20260101-130000-bbbbbb', 'status' => 'FAILED', 'end_time' => '2026-01-01T13:10:00+00:00', 'recipient' => ['name' => 'Dr. Erika Beispiel', 'number' => '49301112223'],
    'source' => ['filename_original' => 'Brief.pdf'], 'result' => ['reason' => 'BUSY max attempts reached'], 'attempt' => ['current' => 15]];
file_put_contents("$dir/austausch/sendefehler/berichte/Brief__JOB-20260101-130000-bbbbbb.json", json_encode($f));
file_put_contents("$dir/austausch/sendefehler/berichte/Brief__JOB-20260101-130000-bbbbbb__FAILED.pdf", $pdf);
file_put_contents("$dir/austausch/sendefehler/eingang/Brief.pdf", $pdf);
file_put_contents("$dir/austausch/empfang/20260101-140000_49301234500_1767276000123.pdf", $pdf);
file_put_contents("$dir/austausch/empfang/20260101-150000_unbekannt_1767279600123.pdf", $pdf);   // noch ohne JSON: unvollständig
file_put_contents("$dir/austausch/empfang/20260101-140000_49301234500_1767276000123.json", json_encode(['empfangen' => '2026-01-01T14:00:00+00:00', 'absender' => '49301234500', 'seiten' => 3, 'status' => 'SUCCESS']));
$ab = Fax::abholen();
check($ab === 7, "sieben Dateien abgeholt ($ab)");
check(is_file("$dir/austausch/empfang/20260101-150000_unbekannt_1767279600123.pdf") && !glob("$dir/austausch/sendeberichte/*"), 'unvollständiger Eingang bleibt, Berichte weg');
check((fileperms("$dir/daten/protokoll/Befund__JOB-20260101-120000-aaaaaa__OK.pdf") & 0777) === 0600, 'Bericht im Datenordner 0600');
$p = Fax::berichte('protokoll');
check(count($p) === 1 && $p[0]['seiten'] === '2' && $p[0]['pdf'] !== '' && $p[0]['name'] === 'Labor Beispiel', 'Sendeprotokoll');
$fe = Fax::berichte('fehler');
check(count($fe) === 1 && $fe[0]['versuche'] === 15 && str_contains($fe[0]['grund'], 'BUSY'), 'Fehlerbericht');
check(count(Fax::quellenZaehler()) === 5 && Fax::quellenZaehler()['sendefehler'] === 1, 'Original zum erneuten Senden');
Fax::fehlerUebernehmen($fe[0]['json']);
check(Fax::berichte('fehler') === [] && count(Fax::berichte('protokoll')) === 2, 'Fehler ins Protokoll übernommen');
check(Fax::senden('sendefehler', ['Brief.pdf'], [['name' => 'Dr. Erika Beispiel', 'nummer' => '49301112223', 'merken' => false]], 'standard', false, 'mfa1') === 1, 'erneut senden');
wirft(fn() => Fax::berichtLoeschen('protokoll', '../einstellungen.json'), 'Bericht-Pfad geprüft');

// Eingang
$ein = Fax::eingang();
check(count($ein) === 1 && $ein[0]['seiten'] === 3 && !$ein[0]['gelesen'] && Fax::ungelesen() === 1, 'Faxeingang');
Fax::kontaktSpeichern(0, 'Klinik Beispiel', '030 1234500', '');
check(Fax::eingang()[0]['kontakt'] === 'Klinik Beispiel', 'Absender aus dem Telefonbuch');
Fax::alsGelesen($ein[0]['name']);
check(Fax::ungelesen() === 0, 'gelesen');
check(is_file(Fax::pdfPfad('eingang', $ein[0]['name'] . '.pdf')), 'PDF-Pfad Eingang');
wirft(fn() => Fax::pdfPfad('eingang', '../telefonbuch.sqlite'), 'PDF-Pfad geprüft');
wirft(fn() => Fax::pdfPfad('system', 'x.pdf'), 'Art geprüft');
Fax::eingangLoeschen($ein[0]['name']);
check(Fax::eingang() === [], 'Fax gelöscht');

// Einstellungen und Aufräumen
wirft(fn() => Fax::einstellungenSpeichern(['eingang_tage' => '0', 'protokoll_tage' => '90', 'drucker_tage' => '14']), 'Frist geprüft');
Fax::einstellungenSpeichern(['eingang_tage' => '30', 'protokoll_tage' => '90', 'drucker_tage' => '1']);
check(Fax::einstellungen()['eingang_tage'] === 30, 'Frist gespeichert');
check(Fax::druckerNamen() === ['vFax1', 'vFax2', 'vFaxLabor'], 'Aufbewahrung ändert die Druckerliste nicht');
$alt = Fax::druckAblegen('vFax1', $pdf, 'alt');
touch("$dir/daten/drucker/vFax1/$alt", time() - 3 * 86400);
check(Fax::aufraeumen() >= 1 && Fax::quelle('vFax1') === [], 'alte Druckablage gelöscht');
check(Fax::ueberblick(true)[1]['state'] === 'done', 'Überblick: Faxdrucker genutzt');
// unbegrenzte Aufbewahrung: nichts wird gelöscht
Fax::aufbewahrungSpeichern(['eingang_tage' => '30', 'protokoll_tage' => '90', 'drucker_tage' => '5', 'drucker_tage_unbegrenzt' => '1', 'protokoll_tage_unbegrenzt' => '1']);
check(Fax::einstellungen()['drucker_tage'] === 0 && Fax::einstellungen()['protokoll_tage'] === 0 && Fax::einstellungen()['eingang_tage'] === 30, 'unbegrenzt gespeichert (0)');
$alt = Fax::druckAblegen('vFax1', $pdf, 'uralt');
touch("$dir/daten/drucker/vFax1/$alt", time() - 4000 * 86400);
check(Fax::aufraeumen() === 0 && count(Fax::quelle('vFax1')) === 1, 'unbegrenzt: nichts gelöscht');
Fax::aufbewahrungSpeichern(['eingang_tage' => '30', 'protokoll_tage' => '90', 'drucker_tage' => '1']);
check(Fax::aufraeumen() >= 1 && Fax::quelle('vFax1') === [], 'wieder begrenzt: gelöscht');
mkdir("$dir/daten/drucker/vFaxAlt", 0700);                      // Ordner eines entfernten Druckers wird mit aufgeräumt
file_put_contents("$dir/daten/drucker/vFaxAlt/x.pdf", $pdf);
touch("$dir/daten/drucker/vFaxAlt/x.pdf", time() - 3 * 86400);
check(Fax::aufraeumen() === 1 && !is_file("$dir/daten/drucker/vFaxAlt/x.pdf"), 'Ordner entfernter Drucker aufgeräumt');

// Umwandlung in PDF: PDF bleibt, JPEG und PNG werden eine A4-Seite, anderes wird abgelehnt
check(Fax::alsPdf($pdf) === $pdf, 'PDF unverändert');
$bild = imagecreatetruecolor(40, 20);
imagefill($bild, 0, 0, 0x336699);
ob_start(); imagejpeg($bild); $jpg = (string)ob_get_clean();
ob_start(); imagepng($bild); $png = (string)ob_get_clean();
$p = Fax::alsPdf($jpg);
check(str_starts_with($p, '%PDF-1.4') && str_contains($p, '/DCTDecode') && str_contains($p, '/MediaBox [0 0 842 595]') && str_ends_with($p, "%%EOF\n"), 'JPEG (quer) als PDF');
check(str_contains(Fax::alsPdf($png), '/MediaBox [0 0 842 595]'), 'PNG als PDF');
$hoch = imagecreatetruecolor(20, 40); ob_start(); imagejpeg($hoch); $jpgHoch = (string)ob_get_clean();
check(str_contains(Fax::alsPdf($jpgHoch), '/MediaBox [0 0 595 842]'), 'JPEG (hoch) als PDF');
// xref-Verweise stimmen
preg_match('/startxref\n(\d+)/', $p, $m);
check(substr($p, (int)$m[1], 4) === 'xref', 'startxref zeigt auf xref');
preg_match_all('/(\d{10}) 00000 n/', $p, $offs);
check(count($offs[1]) === 5 && substr($p, (int)$offs[1][4], 7) === '5 0 obj', 'Objekt-Verweise');
wirft(fn() => Fax::alsPdf('PK' . str_repeat("\0", 30), 'Brief.docx'), 'Word abgelehnt', 'Word- und LibreOffice');
wirft(fn() => Fax::alsPdf(''), 'leer abgelehnt');

// Hochladen (Drag and Drop)
Fax::$testUpload = true;
file_put_contents("$dir/a.jpg", $jpg); file_put_contents("$dir/b.txt", 'Text');
$r = Fax::hochladen(['name' => ['Scan.jpg', 'Notiz.txt', 'fehlt.pdf'], 'tmp_name' => ["$dir/a.jpg", "$dir/b.txt", ''], 'error' => [0, 0, UPLOAD_ERR_NO_FILE]]);
check(count($r['dateien']) === 1 && count($r['fehler']) === 1 && str_contains($r['fehler'][0], 'Notiz.txt'), 'Upload: JPEG angenommen, Text abgelehnt');
check(Fax::quelle('Upload')[0]['titel'] === 'Scan.jpg' && Fax::quellenZaehler()['Upload'] === 1, 'Upload als Quelle „Upload“');
wirft(fn() => Fax::hochladen(['name' => ['b.txt'], 'tmp_name' => ["$dir/b.txt"], 'error' => [0]]), 'nur Fehler', 'Nur PDF');
wirft(fn() => Fax::hochladen(['name' => [''], 'tmp_name' => [''], 'error' => [UPLOAD_ERR_NO_FILE]]), 'keine Datei', 'Keine Datei');
wirft(fn() => Fax::hochladen(['name' => ['g.pdf'], 'tmp_name' => ['x'], 'error' => [UPLOAD_ERR_INI_SIZE]]), 'zu groß', '3 MB');
Fax::$testUpload = false;
Fax::quelleLoeschen('Upload', [Fax::quelle('Upload')[0]['datei']]);

// Feste Nummer mit „sofort senden“ (Zeitgeber): nur Drucker mit Nummer und Haken
$labor = Fax::druckAblegen('vFaxLabor', $pdf, 'Befund Labor');
$neu = Fax::druckAblegen('vFax2', $pdf, 'Nicht sofort');
touch("$dir/daten/drucker/vFaxLabor/$labor", time() - 60);
$vorher = count(glob("$dir/austausch/queue/JOB-*") ?: []);
check(Fax::sofortSenden() === 1 && count(glob("$dir/austausch/queue/JOB-*") ?: []) === $vorher + 1, 'sofort senden an die feste Nummer');
check(Fax::quelle('vFaxLabor') === [] && count(Fax::quelle('vFax2')) === 1, 'nur der Drucker mit Haken');
$jid = array_values(array_diff(array_map('basename', glob("$dir/austausch/queue/JOB-*") ?: []), []))[count(glob("$dir/austausch/queue/JOB-*")) - 1];
$jobs = array_map(fn($f) => json_decode((string)file_get_contents($f . '/job.json'), true), glob("$dir/austausch/queue/JOB-*") ?: []);
check(count(array_filter($jobs, fn($j) => $j['recipient']['number'] === '49309876543' && $j['recipient']['name'] === 'Labor Beispiel' && $j['source']['filename_original'] === 'Befund_Labor.pdf')) >= 1, 'Empfänger aus der Druckerliste');
Fax::quelleLoeschen('vFax2', [Fax::quelle('vFax2')[0]['datei']]);

// NAS: Export des Eingangs und Lauschordner (Verbindung „ablage“ nachgestellt)
$verb = "$dir/verb"; mkdir("$verb", 0700); mkdir("$dir/ausgang", 0770); mkdir("$dir/lauscheingang", 0770);
file_put_contents("$verb/fax.json", json_encode(['ablage' => ['ausgang' => "$dir/ausgang", 'eingang' => "$dir/lauscheingang", 'ziele' => [['id' => 'steuerbuero', 'name' => 'NAS Praxis']]]]));
$km = new KienzleModul('fax', ['KIENZLE_BENUTZER' => 'x'], $verb);
check(Fax::nasZiele($km) === ['steuerbuero' => 'NAS Praxis'], 'NAS-Ziele aus der Verbindung');
wirft(fn() => Fax::nasSpeichern($km, ['export_ziel' => 'fremd']), 'unbekanntes Ziel', 'unbekanntes Ziel');
wirft(fn() => Fax::nasSpeichern($km, ['export_ziel' => 'steuerbuero', 'export_ordner' => '../x']), 'Pfad geprüft', 'Exportordner');
wirft(fn() => Fax::nasSpeichern($km, ['export_ziel' => 'steuerbuero', 'export_ordner' => 'Fax', 'lausch_ziel' => 'steuerbuero', 'lausch_ordner' => 'fax']), 'gleicher Ordner', 'nicht derselbe');
check(Fax::exportieren($km) === 0, 'ohne Export-Ziel nichts');
Fax::nasSpeichern($km, ['export_ziel' => 'steuerbuero', 'export_ordner' => 'Fax-Eingang', 'export_jahresordner' => '1', 'lausch_ziel' => 'steuerbuero', 'lausch_ordner' => 'Fax-Ausgang']);
$an = json_decode((string)file_get_contents("$dir/ausgang/lauschen.anfrage"), true);
check($an === ['ziel' => 'steuerbuero', 'ordner' => 'Fax-Ausgang', 'endungen' => Fax::ENDUNGEN], 'Lauschordner angemeldet');
check(in_array('NAS', Fax::quellen(), true), 'Quelle NAS mit Lauschordner');
file_put_contents("$dir/daten/eingang/20260301-101500_49301234500_1772360100123.pdf", $pdf);
file_put_contents("$dir/daten/eingang/20260301-101500_49301234500_1772360100123.json", json_encode(['empfangen' => '2026-03-01T10:15:00+00:00', 'absender' => '49301234500', 'seiten' => 2, 'status' => 'SUCCESS']));
check(Fax::exportieren($km) === 1, 'ein neues Fax exportiert');
$auftrag = glob("$dir/ausgang/*.json");
$meta = json_decode((string)file_get_contents($auftrag[0]), true);
check(count($auftrag) === 1 && $meta['ziel'] === 'steuerbuero' && $meta['ordner'] === 'Fax-Eingang/{jahr}/{monat}' && $meta['jahr'] === '2026' && $meta['monat'] === '03'
    && preg_match('/^2026-03-01_\d\d-\d\d_Klinik_Beispiel_2S_\d{6}\.pdf$/', $meta['name']) === 1, 'Exportauftrag mit Datum, Absender und Ordner: ' . $meta['name']);
check(Fax::exportieren($km) === 0, 'jedes Fax nur einmal exportiert');
Fax::nasSpeichern($km, ['export_ziel' => '', 'lausch_ziel' => '']);
check(!is_file("$dir/ausgang/lauschen.anfrage") && Fax::exportieren($km) === 0, 'Lauschordner und Export wieder aus');
Fax::nasSpeichern($km, ['export_ziel' => 'steuerbuero', 'export_ordner' => 'Fax-Eingang', 'lausch_ziel' => 'steuerbuero', 'lausch_ordner' => 'Fax-Ausgang']);
file_put_contents("$dir/lauscheingang/Brief vom Labor.pdf", $pdf);
file_put_contents("$dir/lauscheingang/Scan.jpg", $jpg);
file_put_contents("$dir/lauscheingang/kaputt.pdf", 'kein pdf');
check(Fax::lauschHolen($km) === 2, 'zwei Dateien aus dem Lauschordner übernommen');
$nas = Fax::quelle('NAS');
check(count($nas) === 2 && !glob("$dir/lauscheingang/*") && count(glob("$dir/daten/lausch-fehler/*") ?: []) === 1, 'Quelle NAS gefüllt, kaputte Datei beiseite');
check(in_array('Brief vom Labor.pdf', array_column($nas, 'titel'), true), 'Dateiname als Titel');
check(Fax::quellenZaehler()['NAS'] === 2, 'Zähler NAS');
$r = Fax::pflege($km);
check(isset($r['abgeholt'], $r['sofort'], $r['lausch'], $r['export'], $r['aufgeraeumt']), 'Pflege läuft durch');


// Seiten
$r = ['admin' => false, 'senden' => true, 'eingang' => true, 'telefonbuch' => false];
$basis = ['rechte' => $r, 'csrf' => 'x', 'meldung' => null, 'fehler' => 'Fehler <i>', 'telefon' => true, 'ungelesen' => 2, 'fehlerzahl' => 1, 'einstellungen' => Fax::einstellungen(), 'quelle' => 'vFax2', 'drucker' => Fax::drucker(), 'lauschordner' => true, 'vorgewaehlt' => [], 'nasZiele' => ['steuerbuero' => 'NAS Praxis'], 'praxisname' => 'Praxis Beispiel'];
Fax::druckAblegen('vFax2', $pdf, 'Überweisung Max Mustermann');
$html = Ansicht::seite('/', $basis + ['zaehler' => Fax::quellenZaehler(), 'kontakte' => Fax::kontakte(), 'aktive' => Fax::aktive(), 'dateien' => Fax::quelle('vFax2')]);
check(str_contains($html, 'Überweisung Max Mustermann') && str_contains($html, 'Fehler &lt;i&gt;') && str_contains($html, 'empfaenger[4][nummer]') && !str_contains($html, 'href="/fax/einstellungen"'), 'Senden-Seite');
check(substr_count($html, '<form') === substr_count($html, '</form>'), 'Formulare geschlossen');
$html = Ansicht::seite('/protokoll', $basis + ['berichte' => Fax::berichte('protokoll')]);
check(str_contains($html, 'Labor Beispiel') && str_contains($html, '030 9876543') === false && str_contains($html, '0309876543'), 'Protokoll-Seite');
$html = Ansicht::seite('/telefonbuch', $basis + ['kontakte' => Fax::kontakte(), 'kontaktBearbeiten' => null]);
check(str_contains($html, 'Klinik Beispiel') && !str_contains($html, 'kontakt-loeschen'), 'Telefonbuch nur lesen ohne Recht');
check(!Ansicht::darf('/einstellungen', $r) && Ansicht::darf('/eingang', $r), 'Rechte je Seite');
$aktivHtml = Ansicht::aktiveListe(Fax::aktive(), 'x');
check(str_contains($aktivHtml, 'wird abgebrochen') && str_contains($aktivHtml, 'wartet'), 'laufende Aufträge');
echo "fax.php: alles in Ordnung\n";
