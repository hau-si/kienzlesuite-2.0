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
    $env = ['KIENZLE_AUSTAUSCH' => "$dir/austausch-fehlt", 'KIENZLE_DATEN' => "$dir/daten", 'KIENZLE_ASTERISK' => '/bin/false', 'KIENZLE_PROGRAMM' => dirname(__DIR__), 'PATH' => '/usr/bin:/bin'];
    // Wie auf dem Server: open_basedir nur Programm- und Testordner (fand den Fehler mit „sqlite:file:…?mode=ro“, Telefon beta.25).
    $ob = dirname(__DIR__, 3) . PATH_SEPARATOR . $dir . PATH_SEPARATOR . sys_get_temp_dir();
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'open_basedir=' . $ob, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
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
// Einrichtung der Telefone (Snom): MAC-Adresse und Schlüssel nur durch Admins.
$o = aufruf($dir, '/telefon/einstellungen', 'nebenstellen', 'benutzer', ['aktion' => 'nebenstelle-mac', 'kienzle-csrf' => $csrf, 'nummer' => '201', 'mac' => '00:04:13:a1:b2:c3']);
check(str_contains($o, 'nur Admins') && !str_contains($o, '000413a1b2c3'), 'MAC-Adresse nur Admin');
$o = aufruf($dir, '/telefon/einstellungen', 'nebenstellen', 'benutzer', ['aktion' => 'prov-speichern', 'kienzle-csrf' => $csrf, 'aktiv' => '1']);
check(str_contains($o, 'nur Admins'), 'Einrichtung einschalten nur Admin');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'nebenstelle-mac', 'kienzle-csrf' => $csrf, 'nummer' => '201', 'mac' => 'kaputt']);
check(str_contains($o, 'MAC-Adresse ungültig'), 'Ungültige MAC-Adresse abgelehnt');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'nebenstelle-mac', 'kienzle-csrf' => $csrf, 'nummer' => '201', 'mac' => '00:04:13:A1:B2:C3']);
check(str_contains($o, 'value="00:04:13:a1:b2:c3"'), 'MAC-Adresse gespeichert und angezeigt');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'prov-speichern', 'kienzle-csrf' => $csrf, 'aktiv' => '1']);
check(preg_match('#http://kienzle\.praxis\.example\.org:8780/[0-9a-f]{32}/\{mac\}\.xml#', $o) === 1 && str_contains($o, '1 Telefon'), 'Adresse für die Telefone mit Schlüssel');
preg_match('#8780/([0-9a-f]{32})/#', $o, $m1);
$datei = glob($dir . '/daten/prov/*/000413a1b2c3.xml');
check(count($datei) === 1 && str_contains((string)file_get_contents($datei[0]), '<user_name idx="1" perm="R">201</user_name>'), 'Einstellungsdatei erzeugt');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'prov-schluessel-neu', 'kienzle-csrf' => $csrf]);
preg_match('#8780/([0-9a-f]{32})/#', $o, $m2);
check(($m1[1] ?? '') !== '' && ($m2[1] ?? '') !== '' && $m1[1] !== $m2[1] && !is_dir($dir . '/daten/prov/' . $m1[1]) && is_file($dir . '/daten/prov/' . $m2[1] . '/000413a1b2c3.xml'), 'Neuer Schlüssel ersetzt den alten');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'prov-speichern', 'kienzle-csrf' => $csrf]);
check(glob($dir . '/daten/prov/*/*') === [], 'Ausgeschaltet: keine Dateien');
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
check(substr_count($o, 'data-zeiten-von="oeffnungszeiten"') === 3, 'Zeiten der Praxis übernehmen: Knopf bei Telefonzeiten, Apotheken und Pflege, Krankenhaus');
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
// Öffnungszeiten aus der Basis (Verbindung „zeiten“): Öffnungs- und Telefonzeiten nur anzeigen und beim Speichern nicht überschreiben
@mkdir("$dir/verbindungen");
$vorher = @file_get_contents("$dir/verbindungen/telefon.json");
$verb = json_decode((string)$vorher, true) ?: [];
file_put_contents("$dir/verbindungen/telefon.json", json_encode($verb + ['zeiten' => ['format' => 1, 'stand' => str_repeat('c', 32), 'woche' => [], 'ereignisse' => []]]));
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue');
check(str_contains($o, 'href="/verwaltung/zeiten"') && str_contains($o, 'name="zeit[oeffnungszeiten][montag]" value="" maxlength="200" placeholder="geschlossen" pattern="[0-9:\\-, ;]*" inputmode="numeric" disabled')
    && substr_count($o, 'data-zeiten-von="oeffnungszeiten"') === 2, 'Zeiten aus der Basis nur angezeigt');
preg_match('/name="stand" value="([a-f0-9]{64})"/', $o, $m);
$o = aufruf($dir, '/telefon/sprachmenue', 'sprachmenue', 'benutzer', ['aktion' => 'sprachmenue-texte', 'kienzle-csrf' => $csrf, 'stand' => $m[1], 'zeit' => ['telefonzeiten' => ['montag' => '09:00-10:00']]]);
check(!str_contains($o, 'value="09:00-10:00"') && str_contains($o, 'value="08:00-12:00, 14:00-17:00"'), 'Telefonzeiten trotz Basis überschrieben');
if ($vorher === false) unlink("$dir/verbindungen/telefon.json"); else file_put_contents("$dir/verbindungen/telefon.json", $vorher);
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
check(str_contains(aufruf($dir, '/telefon/vorgaenge', 'vorgaenge'), 'Keine neuen Vorgänge'), 'Vorgänge leer');
check(str_contains(aufruf($dir, '/telefon/', 'vorgaenge'), '/telefon/vorgaenge">Vorgänge</a>'), 'Knopf Vorgänge in der Übersicht');
$py = 'import sys; from pathlib import Path; from zoneinfo import ZoneInfo; sys.path.insert(0, sys.argv[1]); from kienzlefon.crypto import TelepraxisEncryptor;'
    . ' TelepraxisEncryptor(Path(sys.argv[2]), Path(sys.argv[3]), ZoneInfo("Europe/Berlin")).write_payload({"typ": "termin", "id": "+4930123456", "telefon": "+4930123456",'
    . ' "vorname": "Max", "nachname": "Mustermann", "grund": "Impfung", "_kienzlefon": {"erstellt_am": "2026-11-26T09:14:00+01:00"}}, "20261126_091400_000001")';
exec(implode(' ', array_map('escapeshellarg', ['python3', '-c', $py, dirname(__DIR__) . '/kienzlefon', "$dir/daten/kienzlefon/telepraxis-public.pem", "$dir/daten/kienzlefon/vorgaenge"])), $aus, $rc);
$o = aufruf($dir, '/telefon/vorgaenge', 'vorgaenge');
check($rc === 0 && str_contains($o, 'Max Mustermann') && str_contains($o, 'Impfung') && str_contains($o, 'Neu (1)') && str_contains($o, 'data-anzahl='), 'Vorgang auf der Seite');
check(str_contains(aufruf($dir, '/telefon/', 'vorgaenge'), 'Vorgänge (1)'), 'Zähler in der Übersicht');
check(str_contains(aufruf($dir, '/telefon/vorgaenge', 'sprachmenue', 'benutzer', ['aktion' => 'vorgang-erledigt', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']), '#CODE=403'), 'Erledigen ohne Recht');
$o = aufruf($dir, '/telefon/vorgaenge', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-erledigt', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']);
check(str_contains($o, 'Als erledigt markiert') && str_contains($o, 'Neu (0)'), 'Erledigt');
check(str_contains(aufruf($dir, '/telefon/vorgaenge?bereich=erledigt', 'vorgaenge'), 'Max Mustermann'), 'Reiter Erledigt');
check(str_contains(aufruf($dir, '/telefon/sprachmenue', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-loeschen', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']), 'fehlt das Recht'), 'Löschen nur auf der Vorgangsseite');
$o = aufruf($dir, '/telefon/vorgaenge?bereich=erledigt', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-loeschen', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']);
check(str_contains($o, 'In den Papierkorb gelegt') && !glob("$dir/daten/kienzlefon/vorgaenge/erledigt/*.enc"), 'In den Papierkorb');
$o = aufruf($dir, '/telefon/vorgaenge?bereich=papierkorb', 'vorgaenge');
check(str_contains($o, 'Max Mustermann') && str_contains($o, 'Endgültig löschen') && str_contains($o, 'Wiederherstellen'), 'Reiter Papierkorb');
$o = aufruf($dir, '/telefon/vorgaenge?bereich=papierkorb', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-loeschen', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']);
check(str_contains($o, 'endgültig gelöscht') && !glob("$dir/daten/kienzlefon/vorgaenge/papierkorb/*"), 'Endgültig gelöscht');
// Bearbeitung: übernehmen (angemeldeter Benutzer), dringend, Kommentar (verschlüsselt), Zähler-Abfrage.
exec(implode(' ', array_map('escapeshellarg', ['python3', '-c', $py, dirname(__DIR__) . '/kienzlefon', "$dir/daten/kienzlefon/telepraxis-public.pem", "$dir/daten/kienzlefon/vorgaenge"])), $aus, $rc);
check(json_decode(aufruf($dir, '/telefon/vorgaenge/anzahl', 'vorgaenge') ? strstr(aufruf($dir, '/telefon/vorgaenge/anzahl', 'vorgaenge'), "\n#CODE", true) : '', true)['neu'] === 1, 'Zähler-Abfrage');
check(str_contains(aufruf($dir, '/telefon/vorgaenge/anzahl', 'sprachmenue'), '#CODE=403'), 'Zähler nur mit Recht');
$o = aufruf($dir, '/telefon/vorgaenge?bereich=neu', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-uebernehmen', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']);
check(str_contains($o, 'Übernommen') && str_contains($o, 'In Bearbeitung (1)'), 'Übernommen');
$o = aufruf($dir, '/telefon/vorgaenge?bereich=bearbeitung', 'vorgaenge');
check(str_contains($o, 'In Bearbeitung von <strong>admin</strong>') && !str_contains($o, '>Übernehmen<'), 'Bearbeiter sichtbar, eigener Vorgang ohne Übernehmen');
aufruf($dir, '/telefon/vorgaenge?bereich=bearbeitung', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-dringend', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001', 'an' => '1']);
$o = aufruf($dir, '/telefon/vorgaenge?bereich=bearbeitung', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-kommentar', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001', 'text' => 'Rezept liegt am Empfang']);
check(str_contains($o, 'Kommentar gespeichert') && str_contains($o, 'Rezept liegt am Empfang') && str_contains($o, 'tf-dringend'), 'Kommentar und dringend');
check(!str_contains((string)file_get_contents("$dir/daten/kienzlefon/vorgaenge/20261126_091400_000001.kommentare"), 'Empfang'), 'Kommentar verschlüsselt');
aufruf($dir, '/telefon/vorgaenge?bereich=bearbeitung', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-erledigt', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001']);
check(is_file("$dir/daten/kienzlefon/vorgaenge/erledigt/20261126_091400_000001.kommentare") && is_file("$dir/daten/kienzlefon/vorgaenge/erledigt/20261126_091400_000001.stand.json"), 'Stand und Kommentare wandern mit');
check(str_contains(aufruf($dir, '/telefon/vorgaenge?bereich=erledigt', 'vorgaenge'), 'Rezept liegt am Empfang'), 'Kommentar im Reiter Erledigt');
// Whisper einrichten (nur Admin): Wahl merken, Auftrag ablegen.
check(str_contains(aufruf($dir, '/telefon/einstellungen', 'nebenstellen', 'benutzer', ['aktion' => 'whisper-einrichten', 'kienzle-csrf' => $csrf]), 'nur Admins'), 'Whisper nur Admin');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'whisper-einrichten', 'kienzle-csrf' => $csrf, 'genau' => '1']);
check(str_contains($o, 'Whisper wird geladen (etwa 5 GB') && json_decode((string)file_get_contents("$dir/daten/kienzlefon/auftrag.json"), true)['aktion'] === 'whisper-einrichten', 'Whisper-Auftrag');
check(str_contains((string)file_get_contents("$dir/daten/kienzlefon/kienzlefon.toml"), 'modell_namen = "large-v3"'), 'Genaueres Modell für Namen');
unlink("$dir/daten/kienzlefon/auftrag.json");
// SMS an Anrufer über den Nachrichten-Ausgang (nachgestellt).
@mkdir("$dir/verbindungen", 0700);
@mkdir("$dir/nachrichten", 0770);
file_put_contents("$dir/verbindungen/telefon.json", json_encode(['nachrichten' => ['ausgang' => "$dir/nachrichten", 'sms' => true, 'mail' => true]]));
exec(implode(' ', array_map('escapeshellarg', ['python3', '-c', $py, dirname(__DIR__) . '/kienzlefon', "$dir/daten/kienzlefon/telepraxis-public.pem", "$dir/daten/kienzlefon/vorgaenge"])), $aus, $rc);
$o = aufruf($dir, '/telefon/vorgaenge', 'vorgaenge');
check(str_contains($o, 'SMS an den Anrufer') && str_contains($o, 'Bitte rufen Sie uns wegen Ihres Terminwunsches zurück.'), 'SMS-Formular mit Vorlage');
$o = aufruf($dir, '/telefon/vorgaenge', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-sms', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001', 'an' => '0170 1234567', 'text' => 'Bitte rufen Sie uns zurück.', 'erledigt' => '1']);
check(str_contains($o, 'SMS wird verschickt') && str_contains($o, 'als erledigt markiert') && count(glob("$dir/nachrichten/*.json") ?: []) === 1, 'SMS beauftragt und erledigt');
check(str_contains(aufruf($dir, '/telefon/vorgaenge?bereich=erledigt', 'vorgaenge'), 'SMS wird verschickt …'), 'Stand an der Karte');
$o = aufruf($dir, '/telefon/vorgaenge?bereich=erledigt', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-sms', 'kienzle-csrf' => $csrf, 'id' => '20261126_091400_000001', 'an' => '12345', 'text' => 'x']);
check(str_contains($o, 'Handynummer bitte im Format'), 'Ungültige Nummer');
// Rufgruppen: eigenes Recht, Schalter in den Einstellungen; ohne laufendes Asterisk meldet die Seite „Steuerung startet“.
check(str_contains(aufruf($dir, '/telefon/rufgruppen', 'vorgaenge'), '#CODE=403'), 'Rufgruppen ohne Recht');
check(str_contains(aufruf($dir, '/telefon/rufgruppen', 'rufgruppen'), 'noch nicht eingeschaltet'), 'Rufgruppen aus');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'sprachmenue-speichern', 'kienzle-csrf' => $csrf, 'aktiv' => '1', 'tts' => 'piper', 'rufgruppen' => '1']);
check(str_contains($o, 'Gespeichert.') && str_contains((string)file_get_contents("$dir/daten/asterisk/etc/manager_kienzlefon_control.conf"), '[kienzlefon-control]'), 'Rufgruppen eingeschaltet');
$o = aufruf($dir, '/telefon/rufgruppen', 'rufgruppen');
check(str_contains($o, 'Steuerung startet') && str_contains($o, 'name="aktion" value="rufgruppe-schalten"') && str_contains($o, 'ROT') && !str_contains($o, 'Standards speichern'), 'Rufgruppen-Seite für MFA');
check(str_contains(aufruf($dir, '/telefon/rufgruppen', '', 'admin'), 'Standards speichern'), 'Standards für Admins');
check(str_contains(aufruf($dir, '/telefon/', 'rufgruppen'), '/telefon/rufgruppen">Rufgruppen</a>'), 'Knopf Rufgruppen');
$o = aufruf($dir, '/telefon/rufgruppen', 'rufgruppen', 'benutzer', ['aktion' => 'rufgruppe-schalten', 'kienzle-csrf' => $csrf, 'gruppe' => 'normal', 'nummer' => '202', 'an' => '0', 'revision' => 'r1:x:1']);
check(str_contains($o, 'class="fehler"'), 'Schalten ohne Anlage meldet Fehler');
check(str_contains(aufruf($dir, '/telefon/rufgruppen', 'rufgruppen', 'benutzer', ['aktion' => 'rufgruppen-speichern', 'kienzle-csrf' => $csrf]), 'nur Admins'), 'Standards nur Admin');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'sprachmenue-speichern', 'kienzle-csrf' => $csrf, 'aktiv' => '1', 'tts' => 'piper']);
check(str_contains((string)file_get_contents("$dir/daten/asterisk/etc/manager_kienzlefon_control.conf"), 'ausgeschaltet') && !is_file("$dir/daten/kienzlefon/control-ami.json"), 'Rufgruppen wieder aus');
// Tonaufnahmen anhören (Spool von Kienzlefon nachgestellt), endgültiges Löschen entfernt sie.
$pyTon = 'import sys; from pathlib import Path; from zoneinfo import ZoneInfo; sys.path.insert(0, sys.argv[1]); from kienzlefon.crypto import TelepraxisEncryptor;'
    . ' TelepraxisEncryptor(Path(sys.argv[2]), Path(sys.argv[3]), ZoneInfo("Europe/Berlin")).write_payload({"typ": "rezeptbestellung", "id": "+491701234567", "telefon": "+491701234567",'
    . ' "vorname": "Max", "medikamente": "Ibuprofen 400", "_kienzlefon": {"erstellt_am": "2026-11-26T11:15:00+01:00", "audio": [{"feld": "vorname", "datei": "audio/vorname.wav"},'
    . ' {"feld": "medikamente", "index": 1, "datei": "audio/medikament-01.wav"}, {"feld": "nachname", "datei": "audio/fehlt.wav"}]}}, "20261126_111500_000004")';
exec(implode(' ', array_map('escapeshellarg', ['python3', '-c', $pyTon, dirname(__DIR__) . '/kienzlefon', "$dir/daten/kienzlefon/telepraxis-public.pem", "$dir/daten/kienzlefon/vorgaenge"])), $aus, $rc);
mkdir("$dir/daten/kienzlefon/spool/ready/20261126_111500_000004/audio", 0750, true);
file_put_contents("$dir/daten/kienzlefon/spool/ready/20261126_111500_000004/audio/vorname.wav", 'RIFF0000WAVEvorname');
file_put_contents("$dir/daten/kienzlefon/spool/ready/20261126_111500_000004/audio/medikament-01.wav", 'RIFF0000WAVEmedikament');
$o = aufruf($dir, '/telefon/vorgaenge?bereich=neu', 'vorgaenge');
check($rc === 0 && str_contains($o, 'Aufnahmen anhören (2)') && str_contains($o, '/telefon/vorgaenge/ton/20261126_111500_000004/medikament-01.wav') && str_contains($o, 'Medikament 1'), 'Aufnahmen auf der Karte');
check(str_starts_with(aufruf($dir, '/telefon/vorgaenge/ton/20261126_111500_000004/vorname.wav', 'vorgaenge'), 'RIFF0000WAVEvorname'), 'Aufnahme abspielen');
check(str_contains(aufruf($dir, '/telefon/vorgaenge/ton/20261126_111500_000004/vorname.wav', 'rufgruppen'), '#CODE=403'), 'Abspielen nur mit Recht');
check(str_contains(aufruf($dir, '/telefon/vorgaenge/ton/20261126_111500_000004/fehlt.wav', 'vorgaenge'), '#CODE=404'), 'Fehlende Aufnahme');
check(str_contains(aufruf($dir, '/telefon/vorgaenge/ton/..%2F..%2Fanlage/x.wav', 'vorgaenge'), '#CODE=404'), 'Kein Pfad nach außen');
aufruf($dir, '/telefon/vorgaenge?bereich=neu', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-loeschen', 'kienzle-csrf' => $csrf, 'id' => '20261126_111500_000004']);
check(is_dir("$dir/daten/kienzlefon/spool/ready/20261126_111500_000004"), 'Papierkorb behält die Aufnahmen');
aufruf($dir, '/telefon/vorgaenge?bereich=papierkorb', 'vorgaenge', 'benutzer', ['aktion' => 'vorgang-loeschen', 'kienzle-csrf' => $csrf, 'id' => '20261126_111500_000004']);
clearstatcache();
check(!is_dir("$dir/daten/kienzlefon/spool/ready/20261126_111500_000004"), 'Endgültig gelöscht samt Aufnahmen');
// Anrufe: Live-Übersicht und Wählen (ohne laufende Anlage).
check(str_contains(aufruf($dir, '/telefon/anrufe', 'vorgaenge'), '#CODE=403'), 'Anrufe ohne Recht');
$o = aufruf($dir, '/telefon/anrufe', 'anrufe,waehlen');
check(str_contains($o, 'Mein Telefon') && str_contains($o, 'keine Verbindung') && str_contains($o, 'data-quelle="/telefon/anrufe/stand"') && str_contains($o, 'Nummer wählen'), 'Anrufe-Seite');
check(str_contains(aufruf($dir, '/telefon/anrufe/stand', 'anrufe'), 'keine Verbindung') && str_contains(aufruf($dir, '/telefon/anrufe/stand', 'waehlen'), '#CODE=403'), 'Live-Teil nur mit Recht');
check(str_contains(aufruf($dir, '/telefon/', 'anrufe'), '/telefon/anrufe">Anrufe</a>'), 'Knopf Anrufe');
$o = aufruf($dir, '/telefon/anrufe', 'anrufe,waehlen', 'benutzer', ['aktion' => 'anrufen', 'kienzle-csrf' => $csrf, 'nummer' => '0301234567']);
check(str_contains($o, 'zuerst oben „Mein Telefon“'), 'Wählen ohne eigenes Telefon');
$tel = json_decode((string)file_get_contents("$dir/daten/anlage.json"), true)['nebenstellen'][0]['nummer'];
$o = aufruf($dir, '/telefon/anrufe', 'anrufe,waehlen', 'benutzer', ['aktion' => 'mein-telefon', 'kienzle-csrf' => $csrf, 'nummer' => $tel]);
check(str_contains($o, 'Beim Wählen klingelt zuerst dieses Telefon') && str_contains($o, 'value="' . $tel . '" selected'), 'Mein Telefon gespeichert');
$o = aufruf($dir, '/telefon/anrufe', 'anrufe,waehlen', 'benutzer', ['aktion' => 'anrufen', 'kienzle-csrf' => $csrf, 'nummer' => '0301234567']);
check(str_contains($o, 'class="fehler"'), 'Wählen ohne Anlage meldet Fehler');
check(str_contains(aufruf($dir, '/telefon/anrufe', 'anrufe', 'benutzer', ['aktion' => 'anrufen', 'kienzle-csrf' => $csrf, 'nummer' => '0301234567']), 'fehlt das Recht'), 'Wählen nur mit Recht');
// T2med-Telefonverzeichnis, Statistik
$o = aufruf($dir, '/telefon/patienten/suche?q=muster', 'patienten');
check(str_contains($o, '"verzeichnis":false') && str_contains($o, '"treffer":[]'), 'Suche ohne Verzeichnis');
check(str_contains(aufruf($dir, '/telefon/patienten/suche?q=muster', 'anrufe'), '#CODE=403'), 'Suche braucht Recht patienten');
$o = aufruf($dir, '/telefon/anrufe', 'anrufe,patienten,statistik');
check(str_contains($o, 'id="tf-suche"') && str_contains($o, '/telefon/statistik') && str_contains($o, 'tf-leiste'), 'Suchfeld oben, Reiter Statistik, Leiste');
check(!str_contains(aufruf($dir, '/telefon/anrufe', 'anrufe'), 'id="tf-suche"'), 'kein Suchfeld ohne Recht');
check(str_contains(aufruf($dir, '/telefon/statistik', 'statistik'), 'Noch keine Anrufe') && str_contains(aufruf($dir, '/telefon/statistik', 'anrufe'), '#CODE=403'), 'Statistik-Seite und Recht');
check(str_contains(aufruf($dir, '/telefon/einstellungen', '', 'admin'), 'T2med-Telefonverzeichnis') && str_contains(aufruf($dir, '/telefon/einstellungen', '', 'admin'), 'praxisherz-telefonverzeichnis://'), 'Einstellungen Telefonverzeichnis');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin', ['aktion' => 'verzeichnis-speichern', 'kienzle-csrf' => str_repeat('a', 32), 'ortsvorwahl' => '030', 'anrufername' => '1']);
check(str_contains($o, 'Gespeichert') && str_contains((string)file_get_contents("$dir/daten/t2med/einstellungen.json"), '"030"'), 'Ortsvorwahl speichern');
check(str_contains(aufruf($dir, '/telefon/einstellungen', 'patienten', 'benutzer', ['aktion' => 'verzeichnis-loeschen', 'kienzle-csrf' => str_repeat('a', 32)]), '#CODE=403') && str_contains(aufruf($dir, '/telefon/einstellungen', 'nebenstellen', 'benutzer', ['aktion' => 'verzeichnis-loeschen', 'kienzle-csrf' => str_repeat('a', 32)]), 'nur Admins'), 'Verzeichnis löschen nur Admin');
// Statistik und Telefonverzeichnis mit vorhandenen Datenbanken (nur lesend geöffnet, unter open_basedir)
mkdir("$dir/daten/statistik", 0700, true);
$st = new PDO("sqlite:$dir/daten/statistik/statistik.sqlite");
$st->exec('CREATE TABLE anrufe (id TEXT PRIMARY KEY, zeit INTEGER NOT NULL, tag TEXT NOT NULL, stunde INTEGER NOT NULL, wochentag INTEGER NOT NULL);
    CREATE TABLE routen (id TEXT NOT NULL, route TEXT NOT NULL, PRIMARY KEY (id, route));
    CREATE TABLE warten (id TEXT PRIMARY KEY, zeit INTEGER NOT NULL, tag TEXT NOT NULL, ergebnis TEXT NOT NULL, sekunden INTEGER NOT NULL);');
$st->prepare('INSERT INTO anrufe VALUES (?, ?, ?, ?, ?)')->execute(['c1', time(), date('Y-m-d'), 9, 1]);
$st = null;
@mkdir("$dir/daten/t2med", 0700, true);
$vz = new PDO("sqlite:$dir/daten/t2med/patienten.sqlite");
$vz->exec('CREATE TABLE patienten (rid INTEGER PRIMARY KEY, id TEXT NOT NULL UNIQUE, nummer TEXT NOT NULL, vorname TEXT NOT NULL, nachname TEXT NOT NULL, geburt TEXT NOT NULL, suchname TEXT NOT NULL);
    CREATE TABLE telefone (rid INTEGER NOT NULL, original TEXT NOT NULL, schluessel TEXT NOT NULL); CREATE TABLE meta (k TEXT PRIMARY KEY, v TEXT NOT NULL);');
$vz = null;
$o = aufruf($dir, '/telefon/statistik', 'statistik');
check(str_contains($o, '#CODE=200') && !str_contains($o, 'nicht erreichbar'), 'Statistik mit Datenbank unter open_basedir');
$o = aufruf($dir, '/telefon/einstellungen', '', 'admin');
check(str_contains($o, '#CODE=200') && str_contains($o, 'T2med-Telefonverzeichnis'), 'Einstellungen mit Verzeichnis unter open_basedir');
echo "seiten.php (telefon): alles in Ordnung\n";
