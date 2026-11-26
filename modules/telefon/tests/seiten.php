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
// Seite „Sprachmenü“: Zeiten, Texte, Tasten (Recht sprachmenue, echte Prüfung durch Kienzlefon über lib/anlage.py).
check(str_contains(aufruf($dir, '/telefon/sprachmenue', 'nebenstellen'), '#CODE=403'), 'Sprachmenü ohne Recht');
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue');
check(str_contains($o, '#CODE=200') && str_contains($o, 'Öffnungszeiten') && str_contains($o, 'name="zeit[oeffnungszeiten][montag]"')
    && str_contains($o, 'name="ansage[greeting_open]"') && str_contains($o, 'name="taste[rezept]"') && str_contains($o, 'Zahlwörter'), 'Sprachmenü-Seite');
check(str_contains(aufruf($dir, '/telefon/', 'sprachmenue'), '/telefon/sprachmenue'), 'Link zum Sprachmenü in der Übersicht');
preg_match('/name="stand" value="([a-f0-9]{64})"/', $o, $m);
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue', 'benutzer', ['aktion' => 'sprachmenue-texte', 'kienzle-csrf' => $csrf, 'stand' => $m[1],
    'zeit' => ['telefonzeiten' => ['montag' => '08:00-12:00, 14:00-17:00', 'dienstag' => '08:00-12:00']], 'ansage' => ['emergency' => 'Bei Notfällen wählen Sie bitte die eins eins zwei.'], 'modus' => ['emergency' => 'immer']]);
check(str_contains($o, 'Gespeichert.') && str_contains($o, 'value="08:00-12:00, 14:00-17:00"') && str_contains($o, 'Bei Notfällen wählen Sie bitte die eins eins zwei.'), 'Zeiten und Text gespeichert');
check(str_contains($o, 'Sprachausgabe eingerichtet ist'), 'Hinweis ohne Sprachausgabe');
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue', 'benutzer', ['aktion' => 'sprachmenue-texte', 'kienzle-csrf' => $csrf, 'stand' => $m[1], 'zeit' => ['telefonzeiten' => ['montag' => '8-12']]]);
check(str_contains($o, 'inzwischen geändert'), 'Veralteter Stand');
preg_match('/name="stand" value="([a-f0-9]{64})"/', $o, $m);
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue', 'benutzer', ['aktion' => 'sprachmenue-texte', 'kienzle-csrf' => $csrf, 'stand' => $m[1], 'taste' => ['rezept' => '1']]);
check(str_contains($o, 'doppelt'), 'Doppelte Taste abgelehnt');
check(str_contains(aufruf($dir, '/telefon/sprachmenue', 'sprachmenue', 'benutzer', ['aktion' => 'netz-speichern', 'kienzle-csrf' => $csrf]), 'nur Admins'), 'Andere Aktionen nur Admin');
check(str_contains(aufruf($dir, '/telefon/einstellungen', 'nebenstellen', 'benutzer', ['aktion' => 'sprachmenue-texte', 'kienzle-csrf' => $csrf]), 'fehlt das Recht'), 'Texte nur mit Recht');
if (!is_dir("$dir/daten/kienzlefon/ansagen-master")) mkdir("$dir/daten/kienzlefon/ansagen-master", 0700, true);
file_put_contents("$dir/daten/kienzlefon/ansagen-master/emergency.wav", 'RIFF0000WAVE');
$o = aufruf($dir, '/telefon/ansage/emergency.wav', 'sprachmenue');
check(str_starts_with($o, 'RIFF0000WAVE'), 'Ansage anhören');
check(str_contains(aufruf($dir, '/telefon/ansage/emergency.wav', 'nebenstellen'), '#CODE=403'), 'Anhören nur mit Recht');
check(str_contains(aufruf($dir, '/telefon/ansage/fehlt.wav', 'sprachmenue'), '#CODE=404'), 'Fehlende Ansage');
check(str_contains(aufruf($dir, '/telefon/sprachmenue', 'sprachmenue'), '/telefon/ansage/emergency.wav'), 'Abspielknopf auf der Seite');
// Wartemusik: Musik von Johannes Plachetka über das Formular (echtes ffmpeg), anhören, zurück zum Klingelzeichen, löschen.
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue');
check(str_contains($o, 'id="wartemusik"') && str_contains($o, 'Klingelzeichen') && str_contains($o, 'id="wm-los"') && !str_contains($o, 'value="eigene"'), 'Wartemusik-Abschnitt');
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue', 'benutzer', ['aktion' => 'wartemusik', 'kienzle-csrf' => $csrf, 'wahl' => 'eigene']);
check(str_contains($o, 'zuerst eine eigene Musikdatei'), 'Eigene ohne Upload');
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue', 'benutzer', ['aktion' => 'wartemusik', 'kienzle-csrf' => $csrf, 'wahl' => 'plachetka']);
check(str_contains($o, 'Wartemusik eingeschaltet.') && str_contains($o, 'value="plachetka" checked') && str_contains($o, '/telefon/wartemusik.wav'), 'Plachetka eingeschaltet');
check(str_contains((string)file_get_contents("$dir/daten/kienzlefon/kienzlefon.toml"), 'modus = "musik"'), 'kienzlefon.toml: Musik');
check(str_contains((string)file_get_contents("$dir/daten/asterisk/etc/queues-kienzlefon.conf"), 'musicclass=kienzlefon-wartemusik'), 'Warteschlange mit Musik');
check(str_starts_with(aufruf($dir, '/telefon/wartemusik.wav', 'sprachmenue'), 'RIFF'), 'Wartemusik anhören');
check(str_contains(aufruf($dir, '/telefon/wartemusik.wav', 'status'), '#CODE=403'), 'Anhören nur mit Recht');
check(str_contains(aufruf($dir, '/telefon/wartemusik', 'sprachmenue', 'benutzer', ['x' => '1']), 'Sitzung abgelaufen'), 'Upload ohne CSRF-Kopfzeile');
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue', 'benutzer', ['aktion' => 'wartemusik', 'kienzle-csrf' => $csrf, 'wahl' => 'klingelzeichen']);
check(str_contains($o, 'wieder das Klingelzeichen') && str_contains((string)file_get_contents("$dir/daten/kienzlefon/kienzlefon.toml"), 'modus = "klingelzeichen"'), 'Zurück zum Klingelzeichen');
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue', 'benutzer', ['aktion' => 'wartemusik', 'kienzle-csrf' => $csrf, 'wahl' => 'entfernen']);
check(str_contains($o, 'Wartemusik gelöscht') && !is_dir("$dir/daten/kienzlefon/wartemusik"), 'Wartemusik gelöscht');
// Vorgänge: eigenes Recht; Schlüsselpaar entsteht beim Anwenden mit Sprachmenü.
check(is_file("$dir/daten/vorgaenge-schluessel") && is_file("$dir/daten/kienzlefon/telepraxis-public.pem"), 'Schlüsselpaar beim Anwenden angelegt');
check(str_contains(aufruf($dir, '/telefon/vorgaenge', 'sprachmenue'), '#CODE=403'), 'Vorgänge ohne Recht');
check(str_contains(aufruf($dir, '/telefon/vorgaenge', 'vorgaenge'), 'Keine offenen Vorgänge'), 'Vorgänge leer');
check(str_contains(aufruf($dir, '/telefon/', 'vorgaenge'), '/telefon/vorgaenge">Vorgänge</a>'), 'Knopf Vorgänge in der Übersicht');
$py = 'import sys; from pathlib import Path; from zoneinfo import ZoneInfo; sys.path.insert(0, sys.argv[1]); from kienzlefon.crypto import TelepraxisEncryptor;'
    . ' TelepraxisEncryptor(Path(sys.argv[2]), Path(sys.argv[3]), ZoneInfo("Europe/Berlin")).write_payload({"typ": "termin", "id": "+4930123456", "telefon": "+4930123456",'
    . ' "vorname": "Max", "nachname": "Mustermann", "grund": "Impfung", "_kienzlefon": {"erstellt_am": "2026-11-26T09:14:00+01:00"}}, "20261126_091400_000001")';
exec(implode(' ', array_map('escapeshellarg', ['python3', '-c', $py, dirname(__DIR__) . '/kienzlefon', "$dir/daten/kienzlefon/telepraxis-public.pem", "$dir/daten/kienzlefon/vorgaenge"])), $aus, $rc);
$o = aufruf($dir, '/telefon/vorgaenge', 'vorgaenge');
check($rc === 0 && str_contains($o, 'Max Mustermann') && str_contains($o, 'Impfung') && str_contains($o, 'Offen (1)'), 'Vorgang auf der Seite');
check(str_contains(aufruf($dir, '/telefon/', 'vorgaenge'), 'Vorgänge (1)'), 'Zähler in der Übersicht');
check(str_contains(aufruf($dir, '/telefon/vorgaenge', 'sprachmenue', 'benutzer', ['aktion' => 'vorgang-erledigt', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']), '#CODE=403'), 'Erledigen ohne Recht');
$o = aufruf($dir, '/telefon/vorgaenge', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-erledigt', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']);
check(str_contains($o, 'Als erledigt markiert') && str_contains($o, 'Offen (0)'), 'Erledigt');
check(str_contains(aufruf($dir, '/telefon/vorgaenge?bereich=erledigt', 'vorgaenge'), 'Max Mustermann'), 'Reiter Erledigt');
check(str_contains(aufruf($dir, '/telefon/sprachmenue', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-loeschen', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']), 'fehlt das Recht'), 'Löschen nur auf der Vorgangsseite');
$o = aufruf($dir, '/telefon/vorgaenge?bereich=erledigt', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-loeschen', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']);
check(str_contains($o, 'Vorgang gelöscht') && !glob("$dir/daten/kienzlefon/vorgaenge/erledigt/*.enc"), 'Gelöscht');
// Whisper einrichten (nur Admin): Wahl merken, Auftrag ablegen.
check(str_contains(aufruf($dir, '/telefon/einstellungen', 'nebenstellen', 'benutzer', ['aktion' => 'whisper-einrichten', 'kienzle-csrf' => $csrf]), 'nur Admins'), 'Whisper nur Admin');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'whisper-einrichten', 'kienzle-csrf' => $csrf, 'genau' => '1']);
check(str_contains($o, 'Whisper wird geladen (etwa 5 GB') && json_decode((string)file_get_contents("$dir/daten/kienzlefon/auftrag.json"), true)['aktion'] === 'whisper-einrichten', 'Whisper-Auftrag');
check(str_contains((string)file_get_contents("$dir/daten/kienzlefon/kienzlefon.toml"), 'modell_namen = "large-v3"'), 'Genaueres Modell für Namen');
unlink("$dir/daten/kienzlefon/auftrag.json");
echo "seiten.php (telefon): alles in Ordnung\n";
