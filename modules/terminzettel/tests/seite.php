<?php
declare(strict_types=1);
// Einstellungsseite des Moduls terminzettel: Prüfung, Layout für den Druckdienst, Ablage, Basisdaten, Vorschau, Rechte. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleTerminzettel\{Ansicht, Seite};

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg): void { try { $f(); } catch (InvalidArgumentException) { return; } fwrite(STDERR, "FEHLER: $msg\n"); exit(1); }

$dir = sys_get_temp_dir() . '/tz-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
Seite::$data = $dir;
register_shutdown_function(fn() => array_map('unlink', glob("$dir/*") ?: []) && @rmdir($dir));

// Vorgaben und Eingabeprüfung
$defaults = Seite::werte();
check($defaults['qr_size'] === 'medium' && $defaults['enabled'] === true && $defaults['heading'] === 'IHRE TERMINE', 'Vorgaben');
$in = ['enabled' => '1', 'logo_enabled' => '1', 'logo_size' => 'large', 'logo_align' => 'right', 'cancel_enabled' => '1', 'cancel_to' => 'praxis@example.org', 'cancel_subject' => 'Terminabsage', 'cancel_body' => "Guten Tag\r\nich sage ab", 'cancel_caption' => 'Absagen', 'cancel_size' => 'small', 'name_text' => 'Praxis Beispiel', 'name_size' => '2x2', 'name_bold' => '1',
    'address_text' => "Musterweg 1\r\n12345 Beispielstadt", 'address_size' => '1x1',
    'heading' => 'TERMINE', 'heading_size' => '2x2', 'show_patient' => '1', 'appointment_size' => '1x2', 'footer_text' => '', 'footer_size' => '1x1',
    'qr_enabled' => '1', 'qr_position' => 'below', 'qr_size' => 'large', 'qr_summary' => 'Termin Praxis', 'cut' => ''];
$v = Seite::pruefen($in);
check($v['name_text'] === 'Praxis Beispiel' && $v['address_text'] === "Musterweg 1\n12345 Beispielstadt" && $v['address_bold'] === false && $v['name_bold'] === true && $v['cut'] === false && $v['show_patient'] === true, 'Eingabe falsch übernommen');
wirft(fn() => Seite::pruefen(['heading' => "A\x1bB"] + $in), 'Steuerzeichen in der Überschrift');
wirft(fn() => Seite::pruefen(['address_text' => str_repeat('a', 1001)] + $in), 'zu langer Text');
wirft(fn() => Seite::pruefen(['name_text' => str_repeat('a', 81)] + $in), 'zu langer Praxisname');
wirft(fn() => Seite::pruefen(['name_size' => 'same'] + $in), 'alte Auswahl „same“ beim Praxisnamen');
wirft(fn() => Seite::pruefen(['logo_size' => 'riesig'] + $in), 'ungültige Logobreite');
wirft(fn() => Seite::pruefen(['qr_size' => 'riesig'] + $in), 'ungültige QR-Größe');
wirft(fn() => Seite::pruefen(['logo_align' => 'oben'] + $in), 'ungültige Logo-Lage');
wirft(fn() => Seite::pruefen(['cancel_to' => 'keine Adresse'] + $in), 'ungültiger Empfänger beim Absage-QR');
wirft(fn() => Seite::pruefen(['cancel_body' => str_repeat('a', 401)] + $in), 'zu langer Absagetext');
check($v['cancel_body'] === "Guten Tag\nich sage ab" && $v['logo_align'] === 'right', 'Absage-QR und Logo-Lage übernommen');
$ov = Seite::overlay($v);
check($ov['logo']['align'] === 'right' && $ov['cancel']['enabled'] === true && $ov['cancel']['to'] === 'praxis@example.org' && $ov['cancel']['width_dots'] === Seite::QR_SIZES['small'], 'Absage-QR im Layout');
check(Seite::pruefen(['cancel_enabled' => '', 'cancel_to' => ''] + $in)['cancel_enabled'] === false, 'ohne Absage-QR ist kein Empfänger nötig');

// Layout für den Druckdienst: ohne Drucker, mit abgeschaltetem Endtext; Papierbreite kommt zentral aus der Basis
file_put_contents("$dir/basis.json", '{"bonbreite":80}');
check(Seite::bonBreite() === 80 && Seite::modell() === 'tm-m30iii', 'Papierbreite aus basis.json');
$o = Seite::overlay($v);
check(!array_key_exists('queue', $o) && $o['model'] === 'tm-m30iii' && $o['cut'] === false, 'Overlay: Kopf');
check($o['header']['enabled'] === true && $o['header']['text'] === "Praxis Beispiel\nMusterweg 1\n12345 Beispielstadt" && $o['header']['first_size'] === '2x2' && $o['header']['first_bold'] === true
    && $o['header']['size'] === '1x1' && $o['header']['bold'] === false && $o['footer']['enabled'] === false, 'Overlay: Kopf und Fuß');
check($o['logo'] === ['enabled' => false, 'width_dots' => 384, 'align' => 'right'], 'Overlay: Logo ohne Datei ist aus, Breite folgt der Auswahl');
$nurAdresse = Seite::overlay(['name_text' => ''] + $v);
check($nurAdresse['header']['text'] === "Musterweg 1\n12345 Beispielstadt" && !isset($nurAdresse['header']['first_size']), 'Overlay: ohne Praxisname keine Sonderzeile');
$leer = Seite::overlay(['name_text' => '', 'address_text' => ''] + $v);
check($leer['header']['enabled'] === false, 'Overlay: leerer Kopf ist aus');
check($o['qr']['width_dots'] === 384 && $o['qr']['position'] === 'below' && $o['appointments']['size'] === '1x2', 'Overlay: QR');

check($defaults['qr_position'] === 'below' && $defaults['name_size'] === '2x2' && $defaults['name_bold'] === true && $defaults['address_size'] === '1x1' && $defaults['logo_enabled'] === true && $defaults['appointment_size'] === '2x2', 'neue Vorgaben');
$html0 = Ansicht::seite('/terminzettel', $defaults, 'tok', 'Bon-1', null, null, 'server');
check(str_contains($html0, 'data-tip="Dieser Text steht im Kalendereintrag'), 'Tooltip Kalender-Titel fehlt');

// Kopf aus den Praxisdaten der Basis vorbelegt (erfundene Daten), gespeicherter Kopf hat Vorrang
$praxis = ['name' => 'Praxis Beispiel', 'street' => 'Musterweg 1', 'city' => '12345 Beispielstadt', 'contact' => ''];
$vor = Seite::werte($praxis);
check($vor['name_text'] === 'Praxis Beispiel' && $vor['address_text'] === "Musterweg 1\n12345 Beispielstadt", 'Name und Adresse nicht aus Praxisdaten vorbelegt');
check(Seite::werte()['name_text'] === '' && Seite::werte()['address_text'] === '', 'ohne Praxisdaten bleibt der Kopf leer');

// Speichern und Zurücklesen
Seite::speichern($v);
check(Seite::werte() === $v, 'Werte nicht zurückgelesen');
check((fileperms("$dir/layout.json") & 0777) === 0600 && json_decode((string)file_get_contents("$dir/layout.json"), true) === $o, 'layout.json');
// Älteres Format (ein Kopf-Textfeld) wird in Name und Adressblock zerlegt
file_put_contents("$dir/werte.json", json_encode(['header_text' => "Praxis Alt\nAltweg 2\n01234 Altstadt", 'header_size' => '1x2', 'header_bold' => false, 'header_first_size' => 'same', 'header_first_bold' => 'yes', 'heading' => 'ALT']));
$alt = Seite::werte($praxis);
check($alt['name_text'] === 'Praxis Alt' && $alt['address_text'] === "Altweg 2\n01234 Altstadt" && $alt['address_size'] === '1x2' && $alt['name_size'] === '1x2'
    && $alt['address_bold'] === false && $alt['name_bold'] === true && $alt['heading'] === 'ALT', 'Alter Kopf nicht aufgeteilt');
file_put_contents("$dir/werte.json", json_encode(['header_text' => 'Nur Name', 'header_first_bold' => 'same', 'header_bold' => true]));
$alt2 = Seite::werte();
check($alt2['name_text'] === 'Nur Name' && $alt2['address_text'] === '' && $alt2['name_bold'] === true, 'Alter Kopf nur mit Name');
Seite::speichern($v);

// Logo: Upload prüfen, Umrechnung durch lib/logo.py (hier Ersatz), Ablage, Layout, Entfernen
wirft(fn() => Seite::uploadBytes(null), 'Upload ohne Datei');
wirft(fn() => Seite::uploadBytes(['error' => UPLOAD_ERR_INI_SIZE]), 'Upload zu groß (php.ini)');
file_put_contents("$dir/up.bin", str_repeat('x', Seite::LOGO_MAX_BYTES + 1));
wirft(fn() => Seite::uploadBytes(['error' => UPLOAD_ERR_OK, 'tmp_name' => "$dir/up.bin"], false), 'Upload über 2 MB');
wirft(fn() => Seite::uploadBytes(['error' => UPLOAD_ERR_OK, 'tmp_name' => "$dir/up.bin"]), 'Datei, die kein echter Upload ist');
file_put_contents("$dir/up.bin", 'BILD');
check(Seite::uploadBytes(['error' => UPLOAD_ERR_OK, 'tmp_name' => "$dir/up.bin"], false) === 'BILD', 'Upload lesen');
check(!Seite::logoVorhanden() && Seite::logoDaten() === '', 'Anfangs kein Logo');
wirft(fn() => Seite::logoSpeichern('BILD', fn() => ['code' => 2, 'out' => '', 'stderr' => 'Das Logo muss ein PNG-, JPEG- oder GIF-Bild sein.']), 'Ungültiges Bild akzeptiert');
check(!Seite::logoVorhanden(), 'Ungültiges Bild abgelegt');
$png = "\x89PNG\r\n\x1a\nDATEN";
$bekam = '';
Seite::logoSpeichern('BILD', function (string $script, string $input) use ($png, &$bekam) { $bekam = $input; return ['code' => 0, 'out' => $png, 'stderr' => '']; });
check($bekam === 'BILD' && Seite::logoVorhanden() && (fileperms("$dir/logo.png") & 0777) === 0600 && file_get_contents("$dir/logo.png") === $png, 'Logo nicht abgelegt');
check(str_starts_with(Seite::logoDaten(), 'data:image/png;base64,'), 'Logo-Anzeige');
$mitLogo = json_decode((string)file_get_contents("$dir/layout.json"), true);
check($mitLogo['logo'] === ['enabled' => true, 'width_dots' => 384, 'align' => 'right'], 'layout.json nach Logo-Upload nicht neu geschrieben');
$hLogo = Ansicht::seite('/terminzettel', Seite::werte(), 'tok', 'Bon-1', null, null, 'server');
check(str_contains($hLogo, 'alt="Aktuelles Logo"') && str_contains($hLogo, 'value="logo-entfernen"') && str_contains($hLogo, 'id="logo-los"') && str_contains($hLogo, 'data-ziel=') && !str_contains($hLogo, 'multipart'), 'Logo-Bereich der Ansicht');
wirft(fn() => Seite::rohBytes(''), 'Leeres Logo akzeptiert');
wirft(fn() => Seite::rohBytes(str_repeat('a', Seite::LOGO_MAX_BYTES + 1)), 'Zu großes Logo akzeptiert');
check(Seite::rohBytes('abc') === 'abc', 'Logo roh');
check(str_contains($hLogo, 'name="name_size" value="2x2" checked') && str_contains($hLogo, 'name="address_size" value="1x1" checked') && !str_contains($hLogo, '<select'), 'Auswahlleisten statt Aufklappliste');
Seite::logoEntfernen();
check(!Seite::logoVorhanden() && json_decode((string)file_get_contents("$dir/layout.json"), true)['logo']['enabled'] === false, 'Logo entfernen');

file_put_contents("$dir/werte.json", '{"model":"boese","heading":5}');
$k = Seite::werte();
check(!array_key_exists('model', $k) && $k['heading'] === 'IHRE TERMINE', 'Ungültige gespeicherte Werte fallen auf Vorgaben');

// Basisdaten (Drucker, Praxis) aus den Kopfzeilen der Basis
$modul = new KienzleModul('terminzettel', ['KIENZLE_ROLLE' => 'mitarbeiter', 'KIENZLE_RECHTE' => 'layout', 'KIENZLE_CSRF' => str_repeat('a', 64),
    'KIENZLE_DRUCKER' => rawurlencode('{"buero":"Praxis-A4","bon":"Bon-1","bonbreite":58}'), 'KIENZLE_PRAXIS' => rawurlencode('{"name":"Praxis Beispiel","street":"Musterweg 1","city":"","contact":""}')]);
Seite::syncBasis($modul);
$b = json_decode((string)file_get_contents("$dir/basis.json"), true);
check(($b['drucker']['bon'] ?? '') === 'Bon-1' && ($b['praxis']['name'] ?? '') === 'Praxis Beispiel', 'basis.json');
check($modul->recht('layout') && !$modul->recht('anderes'), 'Rechte');
check(($b['bonbreite'] ?? 0) === 58 && $modul->bonBreite() === 58, 'Breite 58 aus der Basis');
check(json_decode((string)file_get_contents("$dir/layout.json"), true)['model'] === 'tm-m10', 'layout.json folgt der Papierbreite');

file_put_contents("$dir/druckprotokoll.txt", "a\nb\nc\n");
check(Seite::protokoll(2) === ['b', 'c'], 'Druckprotokoll');
file_put_contents("$dir/druckprotokoll.txt", "25.11.2026 11:00:00  Auftrag erhalten: PDF, 100 Bytes\n25.11.2026 11:00:01  Bon übergeben\n25.11.2026 11:05:00  Auftrag erhalten: PostScript, 5000 Bytes\n25.11.2026 11:05:01  Bon an die Warteschlange „bon“ übergeben\n25.11.2026 11:05:01  Zeiten: Umwandlung 0,8 s · Texterkennung 6,2 s · gesamt 7,5 s\n");
$letzter = Seite::letzterAuftrag();
check(count($letzter) === 6 && str_contains($letzter[0], 'PostScript') && str_ends_with($letzter[2], 'Zeiten:') && $letzter[3] === '    Umwandlung 0,8 s' && $letzter[5] === '    gesamt 7,5 s', 'Letzter Auftrag mit Zeiten je Zeile');
$p = Seite::druckauftraege();
check($p['letzter'] === $letzter && $p['frueher'] === ['25.11.2026 11:00:00  Auftrag erhalten: PDF, 100 Bytes', '25.11.2026 11:00:01  Bon übergeben'], 'Ein Block: letzter Auftrag und frühere Zeilen ohne Doppelung');
check(count(Seite::druckauftraege(1)['frueher']) === 1, 'Frühere Zeilen begrenzt');
file_put_contents("$dir/druckprotokoll.txt", "kein Auftrag\n");
check(Seite::letzterAuftrag() === [], 'Ohne Auftrag keine Anzeige');
check(Seite::druckauftraege() === ['letzter' => [], 'frueher' => ['kein Auftrag']], 'Ohne erkannten Auftrag bleibt das Protokoll sichtbar');
file_put_contents("$dir/druckprotokoll.txt", "a\nb\nc\n");

// Vorschau und Testdruck mit Ersatz für Python und lp
$gelaufen = [];
$r = Seite::beispiel($o, 'png', function (string $script, string $input) use (&$gelaufen) {
    $gelaufen = json_decode($input, true);
    return ['code' => 0, 'out' => json_encode(['notes' => ['Hinweis']]) . "\nPNGDATEN", 'stderr' => ''];
});
check($r['data'] === 'PNGDATEN' && $r['notes'] === ['Hinweis'] && $gelaufen['overlay']['model'] === 'tm-m30iii', 'Vorschau');
try { Seite::beispiel($o, 'png', fn() => ['code' => 1, 'out' => '', 'stderr' => 'ImportError: No module named PIL']); check(false, 'Fehler der Vorschau verschluckt'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'python3-pil'), 'Hinweis auf fehlende Pakete'); }
wirft(fn() => Seite::testdruck(new KienzleModul('terminzettel', ['KIENZLE_DRUCKER' => rawurlencode('{"bon":""}')]), Seite::werte()), 'Testdruck ohne Bondrucker');

// Ansicht: Eingaben werden maskiert, kein Inline-Skript
$html = Ansicht::seite('/terminzettel', ['heading' => '<script>x</script>'] + Seite::werte(), 'tok', 'Bon-1', null, '<b>Fehler</b>', 'server');
check(!str_contains($html, '<script>x') && !str_contains($html, '<b>Fehler') && str_contains($html, 'Bon-1') && !str_contains($html, '<script') && !str_contains($html, '<html'), 'Ansicht maskiert nicht oder enthält ein Skript/ein eigenes Dokument');
$kopf = Ansicht::kopf('/terminzettel', 'tok"x');
check(substr_count($kopf, '<script') === 1 && str_contains($kopf, 'src="/terminzettel/assets/app.js?v=') && str_contains($kopf, 'assets/style.css?v=') && str_contains($kopf, 'content="tok&quot;x"'), 'Kopf: Stil, Skript, CSRF-Marke');
check(str_contains($html, '<details class="fold">') && !str_contains($html, '<details class="fold" open>') && str_contains($html, 'class="tz-rechts"') && str_contains($html, 'class="tz-speichern"') && !str_contains($html, 'name="enabled"') && str_contains($html, '<svg'), 'Ansicht: zugeklappte Zeilen, Vorschau rechts, Speichern unten, kein Aktiv-Haken, Symbole');
check(Seite::pruefen(array_map(static fn($f) => $f[0] === 'choice' ? array_key_first($f[3]) : '', Seite::schema()))['enabled'] === true, 'Bondruck immer aktiv');
$k = new KienzleModul('terminzettel', ['KIENZLE_MENUE' => rawurlencode('{"apps":{"/terminzettel/":"Terminzettel"},"ids":{"/terminzettel/":"terminzettel"},"admin":true,"login":"anna"}'), 'REQUEST_URI' => '/terminzettel/']);
$seite = $k->rahmen('Terminzettel', $html, ['roh' => true, 'kopf' => $kopf]);
check(str_contains($seite, '<aside class="side">') && str_contains($seite, 'href="/terminzettel/"') && str_contains($seite, 'assets/app.js?v=') && str_contains($seite, 'Bon-1'), 'Seite im Rahmen der Suite mit Seitenleiste');
check(preg_match('#assets/style\.css\?v=[0-9a-f]{8}"#', $kopf) === 1 && preg_match('#assets/app\.js\?v=[0-9a-f]{8}"#', $kopf) === 1, 'Skript und Stil ohne Versionsanhang: Zwischenspeicher zeigt nach einem Update alte Dateien');
echo "OK: Terminzettel-Einstellungen, Layout, Ablage und Vorschau.\n";
