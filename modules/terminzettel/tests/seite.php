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
$in = ['enabled' => '1', 'model' => 'tm-m30iii', 'header_text' => "Praxis Beispiel\r\nMusterweg 1", 'header_first_size' => '2x2', 'header_first_bold' => 'yes', 'header_size' => '1x1',
    'heading' => 'TERMINE', 'heading_size' => '2x2', 'show_patient' => '1', 'appointment_size' => '1x2', 'footer_text' => '', 'footer_size' => '1x1',
    'qr_enabled' => '1', 'qr_position' => 'below', 'qr_size' => 'large', 'qr_summary' => 'Termin Praxis', 'cut' => ''];
$v = Seite::pruefen($in);
check($v['header_text'] === "Praxis Beispiel\nMusterweg 1" && $v['header_bold'] === false && $v['cut'] === false && $v['show_patient'] === true, 'Eingabe falsch übernommen');
wirft(fn() => Seite::pruefen(['model' => 'x'] + $in), 'ungültiges Modell');
wirft(fn() => Seite::pruefen(['heading' => "A\x1bB"] + $in), 'Steuerzeichen in der Überschrift');
wirft(fn() => Seite::pruefen(['header_text' => str_repeat('a', 1001)] + $in), 'zu langer Text');
wirft(fn() => Seite::pruefen(['qr_size' => 'riesig'] + $in), 'ungültige QR-Größe');

// Layout für den Druckdienst: ohne Drucker, mit abgeschaltetem Endtext
$o = Seite::overlay($v);
check(!array_key_exists('queue', $o) && $o['model'] === 'tm-m30iii' && $o['cut'] === false, 'Overlay: Kopf');
check($o['header']['enabled'] === true && $o['header']['first_size'] === '2x2' && $o['header']['first_bold'] === true && $o['footer']['enabled'] === false, 'Overlay: Kopf und Fuß');
check($o['qr']['width_dots'] === 320 && $o['qr']['position'] === 'below' && $o['appointments']['size'] === '1x2', 'Overlay: QR');

// Kopf aus den Praxisdaten der Basis vorbelegt (erfundene Daten), gespeicherter Kopf hat Vorrang
$praxis = ['name' => 'Praxis Beispiel', 'street' => 'Musterweg 1', 'city' => '12345 Beispielstadt', 'contact' => ''];
check(Seite::werte($praxis)['header_text'] === "Praxis Beispiel\nMusterweg 1\n12345 Beispielstadt", 'Kopf nicht aus Praxisdaten vorbelegt');
check(Seite::werte()['header_text'] === '', 'ohne Praxisdaten bleibt der Kopf leer');

// Speichern und Zurücklesen
Seite::speichern($v);
check(Seite::werte() === $v, 'Werte nicht zurückgelesen');
check((fileperms("$dir/layout.json") & 0777) === 0600 && json_decode((string)file_get_contents("$dir/layout.json"), true) === $o, 'layout.json');
file_put_contents("$dir/werte.json", '{"model":"boese","heading":5}');
$k = Seite::werte();
check($k['model'] === 'tm-m10' && $k['heading'] === 'IHRE TERMINE', 'Ungültige gespeicherte Werte fallen auf Vorgaben');

// Basisdaten (Drucker, Praxis) aus den Kopfzeilen der Basis
$modul = new KienzleModul('terminzettel', ['KIENZLE_ROLLE' => 'mitarbeiter', 'KIENZLE_RECHTE' => 'layout', 'KIENZLE_CSRF' => str_repeat('a', 64),
    'KIENZLE_DRUCKER' => rawurlencode('{"buero":"Praxis-A4","bon":"Bon-1"}'), 'KIENZLE_PRAXIS' => rawurlencode('{"name":"Praxis Beispiel","street":"Musterweg 1","city":"","contact":""}')]);
Seite::syncBasis($modul);
$b = json_decode((string)file_get_contents("$dir/basis.json"), true);
check(($b['drucker']['bon'] ?? '') === 'Bon-1' && ($b['praxis']['name'] ?? '') === 'Praxis Beispiel', 'basis.json');
check($modul->recht('layout') && !$modul->recht('anderes'), 'Rechte');

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
check(!str_contains($html, '<script>x') && !str_contains($html, '<b>Fehler') && str_contains($html, 'Bon-1') && substr_count($html, '<script') === 1 && str_contains($html, 'src="/terminzettel/assets/app.js"'), 'Ansicht maskiert nicht');
echo "OK: Terminzettel-Einstellungen, Layout, Ablage und Vorschau.\n";
