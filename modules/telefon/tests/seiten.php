<?php
declare(strict_types=1);
// Modul telefon: Einstieg public/index.php je Route in einem eigenen PHP-Prozess (Kopfzeilen der Basis nachgestellt).
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
$dir = sys_get_temp_dir() . '/tf-seiten-' . bin2hex(random_bytes(4));
mkdir("$dir/daten", 0700, true);
mkdir("$dir/schluessel", 0700);
file_put_contents("$dir/schluessel/telefon.key", bin2hex(random_bytes(32)));
register_shutdown_function(static function () use ($dir) { exec('rm -r ' . escapeshellarg($dir)); });
$csrf = str_repeat('a', 32);

function aufruf(string $dir, string $uri, string $rechte, string $rolle = 'benutzer', array $post = [], int $bestaetigt = 0): string {
    $server = ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $post ? 'POST' : 'GET', 'KIENZLE_PFAD' => '/telefon', 'KIENZLE_BENUTZER' => 'admin', 'KIENZLE_ROLLE' => $rolle,
        'KIENZLE_RECHTE' => $rechte, 'KIENZLE_CSRF' => str_repeat('a', 32), 'HTTP_HOST' => 'kienzle.praxis.example.org', 'KIENZLE_BESTAETIGT' => (string)$bestaetigt];
    $get = [];
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $get);
    // Der Modulschlüssel liegt im Test in einem eigenen Ordner: KienzleModul mit diesem Ordner vorab anlegen.
    $code = '$_SERVER = ' . var_export($server, true) . ' + $_SERVER; $_GET = ' . var_export($get, true) . '; $_POST = ' . var_export($post, true) . ';'
        . ' require ' . var_export(dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php', true) . '; require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . ';'
        . ' $GLOBALS["tf_test_modul"] = new KienzleModul("telefon", $_SERVER, ' . var_export("$dir/verbindungen", true) . ', ' . var_export("$dir/schluessel", true) . ');'
        . ' register_shutdown_function(function () { echo "\n#CODE=" . (http_response_code() ?: 200); }); require ' . var_export(dirname(__DIR__) . '/public/index.php', true) . ';';
    $env = ['KIENZLE_DATEN' => "$dir/daten", 'KIENZLE_ASTERISK' => '/bin/false', 'KIENZLE_PROGRAMM' => dirname(__DIR__), 'PATH' => '/usr/bin:/bin'];
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    proc_close($p);
    check($err === '', "$uri: PHP-Meldung: $err");
    return $out;
}

$o = aufruf($dir, '/telefon/', 'status');
check(str_contains($o, '#CODE=200') && str_contains($o, 'läuft nicht') && str_contains($o, 'Noch keine Leitung'), 'Übersicht');
check(str_contains(aufruf($dir, '/telefon/einstellungen', 'status'), '#CODE=403'), 'Einstellungen ohne Recht');
check(str_contains(aufruf($dir, '/telefon/', ''), '#CODE=403'), 'ohne Rechte');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'leitung-speichern', 'kienzle-csrf' => $csrf, 'anbieter' => '1und1', 'name' => 'Hauptnummer', 'benutzer' => '4930123456', 'passwort' => 'SIPgeheim']);
check(str_contains($o, 'Gespeichert.') && str_contains($o, 'Hauptnummer'), 'Leitung über das Formular');
$a = json_decode((string)file_get_contents("$dir/daten/anlage.json"), true);
check(str_starts_with($a['leitungen'][0]['passwort'], 'v1:'), 'Passwort verschlüsselt gespeichert');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'netz-speichern', 'kienzle-csrf' => $csrf, 'lokal' => '192.0.2.0/24']);
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'nebenstellen-anlegen', 'kienzle-csrf' => $csrf, 'anzahl' => '2']);
check(str_contains($o, 'Telefone angelegt') && str_contains($o, 'value="202"'), 'Telefone angelegt');
$o = aufruf($dir, '/telefon/einstellungen', 'nebenstellen', 'benutzer', ['aktion' => 'nebenstelle-speichern', 'kienzle-csrf' => $csrf, 'nummer' => '201', 'name' => 'Empfang', 'warteschlange' => '1']);
check(str_contains($o, 'Gespeichert.') && str_contains($o, 'value="Empfang"'), 'Mitarbeiter benennt Telefon');
$o = aufruf($dir, '/telefon/einstellungen', 'nebenstellen', 'benutzer', ['aktion' => 'nebenstelle-loeschen', 'kienzle-csrf' => $csrf, 'nummer' => '201']);
check(str_contains($o, 'nur Admins'), 'Löschen nur Admin');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'zugang-zeigen', 'kienzle-csrf' => $csrf, 'nummer' => '201']);
check(!str_contains($o, 'Zugang für Telefon'), 'Zugang nur nach frischer Bestätigung');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'zugang-zeigen', 'kienzle-csrf' => $csrf, 'nummer' => '201'], time());
check(str_contains($o, 'Zugang für Telefon 201') && preg_match('#<dt>Passwort</dt><dd><code>[A-Za-z0-9]{24}</code>#', $o) === 1, 'Zugang mit Bestätigung');
check(str_contains(aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'netz-speichern', 'kienzle-csrf' => 'falsch']), '#CODE=403'), 'CSRF');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'sprachmenue-speichern', 'kienzle-csrf' => $csrf, 'aktiv' => '1', 'tts' => 'piper']);
check(str_contains($o, 'zuerst die eingehende Telefonleitung'), 'Sprachmenü braucht die Telefonleitung');
$lid = json_decode((string)file_get_contents("$dir/daten/anlage.json"), true)['leitungen'][0]['id'];
aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'zwecke-speichern', 'kienzle-csrf' => $csrf, 'tel_leitung_ein' => $lid, 'tel_nummer_ein' => '4930123456', 'tel_aus_zaehlt' => '1']);
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'sprachmenue-speichern', 'kienzle-csrf' => $csrf, 'aktiv' => '1', 'tts' => 'piper']);
check(str_contains($o, 'Gespeichert.') && str_contains((string)file_get_contents("$dir/daten/asterisk/etc/extensions-kfx-telefonie.conf"), 'Goto(kienzlefon-entry,s,1)'), 'Sprachmenü über die Seite eingeschaltet');
echo "seiten.php (telefon): alles in Ordnung\n";
