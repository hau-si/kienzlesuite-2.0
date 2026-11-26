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
check(count(Fax::quellenZaehler()) === 6 && Fax::quellenZaehler()['sendefehler'] === 1, 'Original zum erneuten Senden');
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
$alt = Fax::druckAblegen('vFax1', $pdf, 'alt');
touch("$dir/daten/drucker/vFax1/$alt", time() - 3 * 86400);
check(Fax::aufraeumen() >= 1 && Fax::quelle('vFax1') === [], 'alte Druckablage gelöscht');
check(Fax::ueberblick(true)[1]['state'] === 'done', 'Überblick: Faxdrucker genutzt');

// Seiten
$r = ['admin' => false, 'senden' => true, 'eingang' => true, 'telefonbuch' => false];
$basis = ['rechte' => $r, 'csrf' => 'x', 'meldung' => null, 'fehler' => 'Fehler <i>', 'telefon' => true, 'ungelesen' => 2, 'fehlerzahl' => 1, 'einstellungen' => Fax::einstellungen(), 'quelle' => 'vFax2'];
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
