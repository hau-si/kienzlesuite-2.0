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
$o = aufruf($dir, '/fax/?quelle=vFaxLabor', 'senden');
check(str_contains($o, 'Feste Faxnummer des Druckers vFaxLabor') && str_contains($o, 'value="0309876543"') && str_contains($o, 'value="Labor"') && str_contains($o, 'id="fx-drop"') && str_contains($o, 'vFax1, vFaxLabor'), 'feste Nummer vorbelegt, Ablagefläche');
check(str_contains($o, 'mit-icon') && str_contains($o, '<svg'), 'Symbole im Kopf');
check(str_contains(aufruf($dir, '/fax/?quelle=vFaxGibtsNicht', 'senden'), 'name="quelle" value="vFax1"'), 'unbekannte Quelle fällt auf den ersten Drucker zurück');
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
echo "seiten.php (fax): alles in Ordnung\n";
