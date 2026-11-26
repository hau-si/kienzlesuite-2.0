<?php
declare(strict_types=1);
// Modul fax: Einstieg public/index.php je Route in einem eigenen PHP-Prozess (Kopfzeilen der Basis nachgestellt).
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
$dir = sys_get_temp_dir() . '/fx-seiten-' . bin2hex(random_bytes(4));
mkdir("$dir/daten/drucker/vFax1", 0700, true);
mkdir("$dir/austausch", 0770);
register_shutdown_function(static function () use ($dir) { exec('rm -r ' . escapeshellarg($dir)); });
file_put_contents("$dir/daten/einstellungen.json", json_encode(['drucker' => [['name' => 'vFax1'], ['name' => 'vFaxLabor', 'bezeichnung' => 'Labor', 'nummer' => '49309876543']]]));
file_put_contents("$dir/daten/drucker/vFax1/20260101-120000-abcdef.pdf", "%PDF-1.4\n%%EOF\n");
$csrf = str_repeat('a', 32);

function aufruf(string $dir, string $uri, string $rechte, string $rolle = 'benutzer', array $post = []): string {
    $server = ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $post ? 'POST' : 'GET', 'KIENZLE_PFAD' => '/fax', 'KIENZLE_BENUTZER' => 'mfa1', 'KIENZLE_ROLLE' => $rolle,
        'KIENZLE_RECHTE' => $rechte, 'KIENZLE_CSRF' => str_repeat('a', 32), 'HTTP_HOST' => 'kienzle.praxis.example.org'];
    $get = [];
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $get);
    $code = '$_SERVER = ' . var_export($server, true) . ' + $_SERVER; $_GET = ' . var_export($get, true) . '; $_POST = ' . var_export($post, true) . ';'
        . ' register_shutdown_function(function () { echo "\n#CODE=" . (http_response_code() ?: 200); }); require ' . var_export(dirname(__DIR__) . '/public/index.php', true) . ';';
    $env = ['KIENZLE_DATEN' => "$dir/daten", 'KIENZLE_AUSTAUSCH' => "$dir/austausch", 'KIENZLE_SUITE_HILFE' => dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php', 'PATH' => '/usr/bin:/bin'];
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    proc_close($p);
    check($err === '', "$uri: PHP-Meldung: $err");
    return $out;
}

$o = aufruf($dir, '/fax/', 'senden,eingang');
check(str_contains($o, '#CODE=200') && str_contains($o, 'Fax senden') && str_contains($o, '20260101-120000-abcdef.pdf'), 'Startseite');
check(str_contains(aufruf($dir, '/fax/eingang', 'eingang'), 'Noch keine Faxe empfangen'), 'Eingang');
check(str_contains(aufruf($dir, '/fax/', 'eingang'), '#CODE=403'), 'Senden ohne Recht');
check(str_contains(aufruf($dir, '/fax/einstellungen', 'senden'), '#CODE=403'), 'Einstellungen nur Admin');
$o = aufruf($dir, '/fax/einstellungen', '', 'admin');
check(str_contains($o, 'Aufbewahrung') && str_contains($o, 'Faxdrucker') && str_contains($o, 'drucker[1][nummer]') && str_contains($o, 'Virtuelle Drucker der Module abgleichen')
    && str_contains($o, 'Faxkennung') && str_contains($o, 'unbegrenzt') && str_contains($o, 'Netzwerkspeicher'), 'Einstellungen Admin');
$o = aufruf($dir, '/fax/einstellungen', '', 'admin', ['aktion' => 'drucker-speichern', 'kienzle-csrf' => $csrf, 'drucker' => [['name' => 'vFax1'], ['name' => 'vFaxLabor', 'bezeichnung' => 'Labor', 'nummer' => '030 9876543'], ['name' => 'vFaxPraxis']]]);
check(str_contains($o, 'Druckerliste gespeichert') && str_contains($o, 'vFaxPraxis') && str_contains(file_get_contents("$dir/daten/drucker.json"), 'vFaxPraxis'), 'Druckerliste über das Formular');
$o = aufruf($dir, '/fax/einstellungen', '', 'admin', ['aktion' => 'aufbewahrung-speichern', 'kienzle-csrf' => $csrf, 'eingang_tage' => '30', 'protokoll_tage' => '90', 'drucker_tage' => '14', 'eingang_tage_unbegrenzt' => '1']);
check(str_contains($o, 'Aufbewahrung gespeichert') && str_contains($o, 'name="eingang_tage_unbegrenzt" value="1" data-schalter="eingang_tage" checked'), 'unbegrenzt über das Formular');
check(str_contains(aufruf($dir, '/fax/einstellungen', 'senden', 'benutzer', ['aktion' => 'drucker-speichern', 'kienzle-csrf' => $csrf, 'drucker' => [['name' => 'vFaxX']]]), '#CODE=403'), 'Druckerliste nur Admin');
mkdir("$dir/daten/drucker/vFaxLabor", 0700);
file_put_contents("$dir/daten/drucker/vFaxLabor/20260102-090000-aaaaaa.pdf", "%PDF-1.4\n%%EOF\n");
touch("$dir/daten/drucker/vFaxLabor/20260102-090000-aaaaaa.pdf", time() + 5);
$o = aufruf($dir, '/fax/', 'senden');
check(str_contains($o, 'value="vFaxLabor/20260102-090000-aaaaaa.pdf" checked') && str_contains($o, 'value="0309876543"') && str_contains($o, 'value="Labor"') && str_contains($o, 'id="fx-drop"'), 'neuestes Dokument vorgewählt, feste Nummer vorbelegt');
$o = aufruf($dir, '/fax/', 'senden', 'benutzer', ['aktion' => 'senden', 'kienzle-csrf' => $csrf, 'auswahl' => ['vFaxLabor/20260102-090000-aaaaaa.pdf'], 'empfaenger' => [['name' => 'Labor', 'nummer' => '12']], 'einzeln' => '1']);
check(str_contains($o, 'Faxnummer fehlt oder ist ungültig') && str_contains($o, 'value="12"') && str_contains($o, 'name="einzeln" value="1" id="fx-einzeln" checked'), 'Fehler: Eingaben bleiben erhalten');
check(str_contains(aufruf($dir, '/fax/', 'senden', 'benutzer', ['aktion' => 'quelle-loeschen', 'kienzle-csrf' => $csrf, 'auswahl' => ['vFaxLabor/20260102-090000-aaaaaa.pdf']]), 'Dokument gelöscht.')
    && !is_file("$dir/daten/drucker/vFaxLabor/20260102-090000-aaaaaa.pdf"), 'angehakte Dokumente löschen');
$o = aufruf($dir, '/fax/vorschau?dok=' . rawurlencode('vFax1/../x.pdf'), 'senden');
check(str_contains($o, '#CODE=404'), 'Vorschau: Pfad geprüft');
check(str_contains(aufruf($dir, '/fax/vorschau?art=eingang&datei=x.pdf', 'senden'), '#CODE=403'), 'Vorschau des Eingangs braucht Recht');
$o = aufruf($dir, '/fax/', 'senden');
check(str_contains($o, 'mit-icon') && str_contains($o, '<svg'), 'Symbole im Kopf');
check(str_contains(aufruf($dir, '/fax/?quelle=vFaxGibtsNicht', 'senden'), '#CODE=200'), 'alter Verweis mit unbekannter Quelle');
check(str_contains(aufruf($dir, '/fax/gibtsnicht', 'senden'), '#CODE=404'), 'unbekannte Seite');
check(str_contains(aufruf($dir, '/fax/pdf?art=quelle&quelle=vFax1&datei=20260101-120000-abcdef.pdf', 'senden'), '%PDF-1.4'), 'PDF anzeigen');
check(str_contains(aufruf($dir, '/fax/pdf?art=quelle&quelle=vFax1&datei=../../x.pdf', 'senden'), '#CODE=404'), 'PDF-Pfad geprüft');
check(str_contains(aufruf($dir, '/fax/pdf?art=eingang&datei=x.pdf', 'senden'), '#CODE=403'), 'Eingang-PDF braucht Recht');
check(str_contains(aufruf($dir, '/fax/', 'senden', 'benutzer', ['aktion' => 'senden', 'kienzle-csrf' => 'falsch']), '#CODE=403'), 'CSRF geprüft');
$o = aufruf($dir, '/fax/', 'senden', 'benutzer', ['aktion' => 'senden', 'kienzle-csrf' => $csrf, 'quelle' => 'vFax1', 'dateien' => ['20260101-120000-abcdef.pdf'],
    'empfaenger' => [['name' => 'Labor Beispiel', 'nummer' => '030 9876543']], 'aufloesung' => 'fine', 'ecm' => '1']);
check(str_contains($o, 'Fax beauftragt') && count(glob("$dir/austausch/queue/JOB-*") ?: []) === 1, 'Senden über das Formular');
check(str_contains(aufruf($dir, '/fax/status', 'senden'), 'Labor Beispiel'), 'Status der laufenden Aufträge');
$o = aufruf($dir, '/fax/telefonbuch', 'senden', 'benutzer', ['aktion' => 'kontakt-speichern', 'kienzle-csrf' => $csrf, 'name' => 'X', 'nummer' => '030 1']);
check(str_contains($o, 'Dafür fehlt das Recht'), 'Telefonbuch bearbeiten braucht Recht');
$o = aufruf($dir, '/fax/telefonbuch', 'telefonbuch,senden', 'benutzer', ['aktion' => 'kontakt-speichern', 'kienzle-csrf' => $csrf, 'name' => 'Klinik Beispiel', 'nummer' => '030 1112223', 'favorit' => '1']);
check(str_contains($o, 'Eintrag gespeichert') && str_contains($o, 'class="fx-stern an"'), 'Favorit beim Speichern');
$o = aufruf($dir, '/fax/telefonbuch', 'telefonbuch', 'benutzer', ['aktion' => 'kontakt-favorit', 'kienzle-csrf' => $csrf, 'id' => '1', 'an' => '0']);
check(str_contains($o, 'Kein Favorit mehr') && !str_contains($o, 'class="fx-stern an"'), 'Favorit per Stern entfernen');
check(str_contains(aufruf($dir, '/fax/telefonbuch', 'senden', 'benutzer', ['aktion' => 'kontakt-favorit', 'kienzle-csrf' => $csrf, 'id' => '1', 'an' => '1']), 'Dafür fehlt das Recht'), 'Stern braucht Recht Telefonbuch');
$o = aufruf($dir, '/fax/', 'senden');
check(str_contains($o, '⟲ Labor Beispiel') && str_contains($o, '&quot;name&quot;:&quot;Klinik Beispiel&quot;'), 'Zuletzt und Telefonbuch für die Suche');
// Roher Upload über einen echten HTTP-Server (PHP-Testserver mit nachgestellten Kopfzeilen der Basis).
$port = 18000 + random_int(0, 999);
file_put_contents("$dir/router.php", '<?php $_SERVER += ["KIENZLE_PFAD" => "/fax", "KIENZLE_BENUTZER" => "mfa1", "KIENZLE_ROLLE" => "benutzer", "KIENZLE_RECHTE" => $_SERVER["HTTP_X_TEST_RECHTE"] ?? "senden", "KIENZLE_CSRF" => str_repeat("a", 32)]; require ' . var_export(dirname(__DIR__) . '/public/index.php', true) . ';');
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null,
    ['KIENZLE_DATEN' => "$dir/daten", 'KIENZLE_AUSTAUSCH' => "$dir/austausch", 'KIENZLE_SUITE_HILFE' => dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php', 'PATH' => '/usr/bin:/bin']);
register_shutdown_function(static function () use ($srv) { proc_terminate($srv); });
$post = static function (string $body, array $kopf) use ($port): array {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $kopf), 'content' => $body, 'ignore_errors' => true, 'timeout' => 20]]);
    for ($i = 0; $i < 50; $i++) { $r = @file_get_contents("http://127.0.0.1:$port/fax/hochladen", false, $ctx); if ($r !== false) break; usleep(100000); }
    return [(int)explode(' ', $http_response_header[0] ?? 'x 0')[1], json_decode((string)$r, true)];
};
$pdfTest = (string)file_get_contents(dirname(__DIR__, 3) . '/modules/kasse/tests/fixtures/t2med-beispielrechnung.pdf');
[$code, $j] = $post($pdfTest, ['Content-Type: application/octet-stream', 'X-Kienzle-CSRF: ' . str_repeat('a', 32), 'X-Dateiname: ' . rawurlencode('Befund für Labor.pdf')]);
check($code === 200 && ($j['ok'] ?? false) && str_ends_with((string)$j['datei'], '.pdf'), 'Roher Upload über HTTP');
[$code, $j] = $post($pdfTest, ['Content-Type: application/octet-stream', 'X-Kienzle-CSRF: falsch', 'X-Dateiname: a.pdf']);
check($code === 403 && str_contains((string)$j['fehler'], 'Sitzung'), 'Upload ohne gültige CSRF-Marke');
[$code, $j] = $post($pdfTest, ['Content-Type: application/octet-stream', 'X-Kienzle-CSRF: ' . str_repeat('a', 32), 'X-Test-Rechte: eingang']);
check($code === 403, 'Upload ohne Recht „senden“');
check(str_contains(aufruf($dir, '/fax/', 'senden'), 'Befund für Labor.pdf') && str_contains(aufruf($dir, '/fax/', 'senden'), 'data-hochladen="/fax/hochladen"'), 'Datei in der Quelle „Upload“');
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 20]]);
$hoch = array_values(array_filter(glob("$dir/daten/drucker/Upload/*.pdf") ?: [], fn($f) => true));
$bild = (string)file_get_contents("http://127.0.0.1:$port/fax/vorschau?dok=" . rawurlencode('Upload/' . basename($hoch[0])), false, $ctx);
check(str_starts_with($bild, "\x89PNG") && str_contains(implode("\n", $http_response_header), 'Content-Type: image/png'), 'Vorschau als Bild über HTTP');
echo "seiten.php (fax): alles in Ordnung\n";
