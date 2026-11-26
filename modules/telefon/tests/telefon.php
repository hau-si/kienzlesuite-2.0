<?php
declare(strict_types=1);
// Modul telefon: Einstellungen, Verschlüsselung der Passwörter, Generator-Aufruf, Seiten. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleTelefon\{Anlage, Ansicht, Vorgaenge};

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool { if (!(error_reporting() & $no)) return false; throw new ErrorException($msg, 0, $no, $file, $line); });
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg, string $text = ''): void {
    try { $f(); } catch (InvalidArgumentException $e) { if ($text === '' || str_contains($e->getMessage(), $text)) return; fwrite(STDERR, "FEHLER: $msg (Meldung: {$e->getMessage()})\n"); exit(1); }
    fwrite(STDERR, "FEHLER: $msg\n"); exit(1);
}

$dir = sys_get_temp_dir() . '/tf-test-' . bin2hex(random_bytes(4));
mkdir("$dir/daten", 0700, true);
mkdir("$dir/schluessel", 0700);
file_put_contents("$dir/schluessel/telefon.key", bin2hex(random_bytes(32)));
register_shutdown_function(static function () use ($dir) { exec('rm -r ' . escapeshellarg($dir)); });
Anlage::$data = "$dir/daten";
putenv('KIENZLE_ASTERISK=/bin/false');
putenv('KIENZLE_PROGRAMM=/opt/kienzle/telefon/current');
$server = ['KIENZLE_BENUTZER' => 'admin', 'KIENZLE_ROLLE' => 'admin', 'KIENZLE_CSRF' => 'c' . str_repeat('0', 31),
    'KIENZLE_PRAXIS' => rawurlencode(json_encode(['name' => 'Praxis Dr. med. Erika Beispiel'])), 'REQUEST_URI' => '/telefon/einstellungen'];
$k = new KienzleModul('telefon', $server, "$dir/verbindungen", "$dir/schluessel");

// Leer: Vorgaben
$cfg = Anlage::laden();
check($cfg['kanaele']['gesamt'] === 4 && $cfg['leitungen'] === [] && !Anlage::eingerichtet(), 'Vorgaben');
check(Anlage::ueberblick()[0]['state'] === 'open', 'Überblick: Leitung fehlt');

// Leitung: Passwort verschlüsselt gespeichert, leer behält das alte
$cfg = Anlage::leitungSpeichern($cfg, ['anbieter' => '1und1', 'name' => '1&1 Praxis', 'benutzer' => '4930123456', 'passwort' => 'SIP;geheim'], $k);
$l = $cfg['leitungen'][0];
check(preg_match('/^l[0-9a-f]{6}$/', $l['id']) === 1 && str_starts_with($l['passwort'], 'v1:') && !str_contains(json_encode($cfg), 'SIP;geheim'), 'Passwort verschlüsselt');
$cfg = Anlage::leitungSpeichern($cfg, ['id' => $l['id'], 'anbieter' => '1und1', 'name' => '1&1 Hauptnummer', 'benutzer' => '4930123456', 'passwort' => ''], $k);
check(count($cfg['leitungen']) === 1 && $cfg['leitungen'][0]['passwort'] === $l['passwort'] && $cfg['leitungen'][0]['name'] === '1&1 Hauptnummer', 'Bearbeiten behält Passwort');
wirft(fn() => Anlage::leitungSpeichern($cfg, ['anbieter' => 'gibtsnicht', 'passwort' => 'x'], $k), 'Anbieter geprüft', 'Anbieter');
wirft(fn() => Anlage::leitungSpeichern($cfg, ['anbieter' => '1und1', 'benutzer' => 'x'], $k), 'neues Passwort Pflicht', 'Passwort');
check(Anlage::klartext($cfg, $k)['leitungen'][0]['passwort'] === 'SIP;geheim', 'Entschlüsseln für den Generator');
check(Anlage::klartext($cfg, $k)['praxis'] === 'Praxis Dr. med. Erika Beispiel', 'Praxisname der Basis');

// Zwecke, Nebenstellen, Netz; Speichern prüft mit lib/anlage.py
$cfg = Anlage::zweckeSpeichern($cfg, ['tel_leitung_ein' => $l['id'], 'tel_nummer_ein' => '4930123456', 'tel_aus_zaehlt' => '1']);
$cfg = Anlage::nebenstellenAnlegen($cfg, 3, $k);
check(array_column($cfg['nebenstellen'], 'nummer') === ['201', '202', '203'], 'Nummern ab 201');
check(strlen(Anlage::nebenstellePasswort($cfg, '202', $k)) === 24, 'Passwort je Telefon');
wirft(fn() => Anlage::speichern($cfg, $k), 'ohne Netz abgelehnt', 'Netz der Telefone');
check(!Anlage::eingerichtet(), 'ungültige Einstellungen nicht gespeichert');
$cfg = Anlage::netzSpeichern($cfg, ['lokal' => '192.0.2.0/24']);
$r = Anlage::speichern($cfg, $k);
check(($r['ok'] ?? false) && !$r['laeuft'] && Anlage::eingerichtet(), 'gespeichert und angewendet');
$pjsip = (string)file_get_contents("$dir/daten/asterisk/etc/pjsip.conf");
$telefone = (string)file_get_contents("$dir/daten/asterisk/etc/pjsip-kfx-telefonie.conf");
check(str_contains($pjsip, 'password=SIP\;geheim') && str_contains($telefone, '[203]') && (fileperms("$dir/daten/asterisk/etc/pjsip.conf") & 0777) === 0600, 'pjsip.conf erzeugt (0600)');
check(!str_contains((string)file_get_contents("$dir/daten/anlage.json"), 'SIP;geheim'), 'anlage.json ohne Klartext');
check((fileperms("$dir/daten/anlage.json") & 0777) === 0600, 'anlage.json 0600');
$alt = Anlage::nebenstellePasswort($cfg, '202', $k);
$cfg = Anlage::nebenstellePasswortNeu($cfg, '202', $k);
check(Anlage::nebenstellePasswort($cfg, '202', $k) !== $alt, 'neues Telefonpasswort');
$cfg = Anlage::nebenstelleSpeichern($cfg, ['nummer' => '203', 'name' => 'Labor', 'warteschlange' => '']);
check($cfg['nebenstellen'][2]['name'] === 'Labor' && $cfg['nebenstellen'][2]['warteschlange'] === false, 'Nebenstelle bearbeitet');
$cfg = Anlage::nebenstelleLoeschen($cfg, '201');
check(array_column($cfg['nebenstellen'], 'nummer') === ['202', '203'], 'Nebenstelle gelöscht');
wirft(fn() => Anlage::leitungLoeschen($cfg, $l['id']), 'benutzte Leitung nicht löschbar', 'verwendet');
Anlage::speichern($cfg, $k);
$cfg = Anlage::nebenstellenAnlegen(Anlage::laden(), 1, $k);
check(end($cfg['nebenstellen'])['nummer'] === '201', 'freie Nummer wird wieder vergeben');
$u = Anlage::ueberblick(['laeuft' => false]);
check($u[0]['state'] === 'done' && $u[1]['state'] === 'done' && $u[2]['state'] === 'done' && $u[3]['state'] === 'error', 'Überblick nach Einrichtung');
check(Anlage::status() === ['laeuft' => false], 'Status ohne Asterisk');
wirft(fn() => Anlage::sprachmenueSpeichern($cfg, ['aktiv' => '1', 'tts' => 'espeak'], $k), 'Sprachausgabe geprüft', 'Sprachausgabe');
$g = Anlage::sprachmenueSpeichern($cfg, ['aktiv' => '1', 'tts' => 'google', 'google_schluessel' => 'AIza' . str_repeat('x', 35), 'google_tempo' => '1,1'], $k)['kienzlefon'];
check($g['aktiv'] === true && $g['tts'] === 'google' && str_starts_with($g['google']['schluessel'], 'v1:') && $g['google']['tempo'] === '1,1', 'Sprachmenü-Schalter, Google-Schlüssel verschlüsselt');
$cfg2 = $cfg; $cfg2['kienzlefon'] = $g;
check(Anlage::sprachmenueSpeichern($cfg2, ['aktiv' => '1', 'tts' => 'google', 'google_schluessel' => ''], $k)['kienzlefon']['google']['schluessel'] === $g['google']['schluessel'], 'leerer Schlüssel behält den alten');
check(Anlage::sprachmenueSpeichern($cfg2, ['tts' => 'piper', 'google_entfernen' => '1'], $k)['kienzlefon']['google']['schluessel'] === '', 'Schlüssel löschen');
check(Anlage::klartext($cfg2, $k)['kienzlefon']['google']['schluessel'] === 'AIza' . str_repeat('x', 35), 'Schlüssel für den Generator entschlüsselt');
check(Anlage::laden()['kienzlefon']['aktiv'] === false && Anlage::laden()['kienzlefon']['tts'] === 'piper', 'Vorgabe: Sprachmenü aus, Piper');

// Seiten
$status = ['laeuft' => true, 'leitungen' => [$l['id'] => 'Registered'], 'telefone' => ['202' => 'Avail'], 'gespraeche' => 1, 'wartend' => 0];
$cfg = Anlage::laden();
$html = Ansicht::uebersicht($cfg, $status, true, false, 'Gespeichert.', null);
check(str_contains($html, 'angemeldet') && str_contains($html, '1&amp;1 Hauptnummer') && str_contains($html, 'Gespräche gerade: 1'), 'Übersicht');
$html = Ansicht::einstellungen($cfg, $status, $k->csrf(), true, $l['id'], ['nummer' => '202', 'passwort' => 'Testpasswort123456789012'], 'kienzle.praxis.example.org', null, 'Fehler <b>');
check(str_contains($html, 'Leitung bearbeiten') && str_contains($html, 'Testpasswort123456789012') && str_contains($html, 'Fehler &lt;b&gt;'), 'Einstellungen mit Zugang');
check(!str_contains($html, 'SIP;geheim') && !str_contains($html, $cfg['leitungen'][0]['passwort']), 'Leitungspasswort nie in der Seite');
$html = Ansicht::einstellungen($cfg, ['laeuft' => false], $k->csrf(), false, null, null, 'x', null, null);
check(!str_contains($html, 'id="leitungen"') && str_contains($html, 'id="telefone"') && !str_contains($html, 'zugang-zeigen'), 'Mitarbeiter sieht nur Telefone');
// Eigene Wartemusik: leere, zu große und fremde Dateien werden vor dem Umwandeln abgelehnt.
wirft(fn() => Anlage::wartemusikHochladen(Anlage::laden(), '', $k), 'Wartemusik leer', 'auswählen');
wirft(fn() => Anlage::wartemusikHochladen(Anlage::laden(), str_repeat('a', Anlage::WARTEMUSIK_MAX + 1), $k), 'Wartemusik zu groß', '7 MB');
wirft(fn() => Anlage::wartemusikHochladen(Anlage::laden(), "%PDF-1.7\n" . str_repeat('x', 100), $k), 'Wartemusik kein Audio', 'Musikdatei');
check(!file_exists("$dir/daten/kienzlefon/wartemusik-upload"), 'Abgelehnte Datei nicht abgelegt');
check(Anlage::wartemusik() === ['bereit' => false, 'quelle' => '', 'dauer' => 0], 'Keine Wartemusik');
// Vorgänge: Schlüsselpaar des Moduls; Kienzlefon (crypto.py) verschlüsselt mit dem öffentlichen Teil, PHP entschlüsselt.
Vorgaenge::schluesselSicherstellen($k);
$pub = "$dir/daten/kienzlefon/telepraxis-public.pem";
check(str_contains((string)file_get_contents($pub), 'BEGIN PUBLIC KEY') && str_starts_with((string)file_get_contents("$dir/daten/vorgaenge-schluessel"), 'v1:'), 'Schlüsselpaar, geheimer Teil verschlüsselt');
check(decoct(fileperms("$dir/daten/vorgaenge-schluessel") & 0777) === '600', 'Geheimer Schlüssel 0600');
$alt = (string)file_get_contents($pub);
Vorgaenge::schluesselSicherstellen($k);
check((string)file_get_contents($pub) === $alt, 'Schlüssel bleibt');
check(Vorgaenge::liste($k) === [] && Vorgaenge::anzahlOffen() === 0, 'Noch keine Vorgänge');
$py = 'import sys, json; from pathlib import Path; from zoneinfo import ZoneInfo; sys.path.insert(0, sys.argv[1]);'
    . ' from kienzlefon.crypto import TelepraxisEncryptor;'
    . ' e = TelepraxisEncryptor(Path(sys.argv[2]), Path(sys.argv[3]), ZoneInfo("Europe/Berlin"));'
    . ' e.write_payload({"typ": "rezeptbestellung", "id": "+4930123456", "telefon": "+4930123456", "vorname": "Max", "nachname": "Mustermann", "geburtsdatum": "1. April 1970",'
    . ' "medikamente": "Ibuprofen 400\nPantoprazol 20", "_kienzlefon": {"vorgang_id": "20261126_091400_000001", "erstellt_am": "2026-11-26T09:14:00+01:00"}}, "20261126_091400_000001");'
    . ' e.write_payload({"typ": "mehrere_anliegen", "id": "unbekannt", "telefon": "unbekannt", "anliegen_liste": [{"typ": "termin", "vorname": "Erika", "grund": "Kontrolle"}],'
    . ' "_kienzlefon": {"vorgang_id": "20261126_101500_000002", "erstellt_am": "2026-11-26T10:15:00+01:00"}}, "20261126_101500_000002")';
exec(implode(' ', array_map('escapeshellarg', ['python3', '-c', $py, dirname(__DIR__) . '/kienzlefon', $pub, "$dir/daten/kienzlefon/vorgaenge"])) . ' 2>&1', $aus, $rc);
check($rc === 0, 'Kienzlefon verschlüsselt: ' . implode("\n", $aus));
check(!str_contains((string)file_get_contents("$dir/daten/kienzlefon/vorgaenge/20261126_091400_000001.json.enc"), 'Mustermann'), 'Vorgang verschlüsselt abgelegt');
$l = Vorgaenge::liste($k);
check(count($l) === 2 && $l[0]['id'] === '20261126_101500_000002' && $l[1]['daten']['nachname'] === 'Mustermann' && $l[1]['typ'] === 'rezeptbestellung', 'Vorgänge entschlüsselt');
$html = Ansicht::vorgaenge($l, 'offen', 2, $k->csrf(), null, null);
check(str_contains($html, 'Max Mustermann') && str_contains($html, 'Ibuprofen 400<br />') && str_contains($html, 'Rezept') && str_contains($html, '26.11.2026, 09:14 Uhr')
    && str_contains($html, 'Anruf von +4930123456') && str_contains($html, '1. Termin') && str_contains($html, 'Kontrolle') && str_contains($html, 'Name nicht verstanden'), 'Vorgänge-Seite');
Vorgaenge::verschieben('20261126_091400_000001');
check(Vorgaenge::anzahlOffen() === 1 && count(Vorgaenge::liste($k, 'erledigt')) === 1, 'Erledigt');
Vorgaenge::verschieben('20261126_091400_000001', true);
check(Vorgaenge::anzahlOffen() === 2, 'Wieder offen');
Vorgaenge::loeschen('20261126_101500_000002');
check(Vorgaenge::anzahlOffen() === 1, 'Gelöscht');
wirft(fn() => Vorgaenge::verschieben('../anlage'), 'Pfad im Vorgang');
wirft(fn() => Vorgaenge::loeschen('gibtsnicht'), 'Unbekannter Vorgang');
// Fremd verschlüsselter Vorgang (anderer Schlüssel): wird als Fehler gezeigt, nichts bricht.
file_put_contents("$dir/daten/kienzlefon/vorgaenge/20261126_120000_000003.json.enc", json_encode(['v' => 1, 'cipher' => 'AES-256-CBC', 'ek' => base64_encode(random_bytes(384)), 'iv' => base64_encode(random_bytes(16)), 'ct' => base64_encode(random_bytes(32)), 'sha256' => '']));
$l = Vorgaenge::liste($k);
check(count($l) === 2 && ($l[0]['fehler'] ?? '') !== '', 'Unlesbarer Vorgang als Fehler');
// SMS an Anrufer: Handynummer erkennen, Vorlage ohne Gesundheitsangaben, Auftrag im Nachrichten-Ausgang (nachgestellt).
check(Vorgaenge::handynummer('01701234567') === '+491701234567' && Vorgaenge::handynummer('004915112345678') === '+4915112345678'
    && Vorgaenge::handynummer('49 170 1234567') === '+491701234567' && Vorgaenge::handynummer('+4930123456') === '' && Vorgaenge::handynummer('unbekannt') === '', 'Handynummer');
check(Vorgaenge::smsVorlage('rezeptbestellung', 'Praxis Dr. med. Erika Beispiel') === 'Ihr Rezept liegt zur Abholung bereit. Ihre Praxis Dr. med. Erika Beispiel'
    && Vorgaenge::smsVorlage('termin', 'Dr. Beispiel') === 'Bitte rufen Sie uns wegen Ihres Terminwunsches zurück. Ihre Praxis Dr. Beispiel', 'SMS-Vorlage');
mkdir("$dir/verbindungen", 0700);
mkdir("$dir/nachrichten", 0770);
file_put_contents("$dir/verbindungen/telefon.json", json_encode(['nachrichten' => ['ausgang' => "$dir/nachrichten", 'sms' => true, 'mail' => false]]));
$ks = new KienzleModul('telefon', $server, "$dir/verbindungen", "$dir/schluessel");
Vorgaenge::smsSenden($ks, '20261126_091400_000001', '0170 1234567', 'Ihr Rezept liegt zur Abholung bereit.');
$smsJobs = glob("$dir/nachrichten/*.json");
check(count($smsJobs) === 1 && json_decode((string)file_get_contents($smsJobs[0]), true)['an'] === '+491701234567', 'SMS-Auftrag abgelegt');
$liste = Vorgaenge::smsListe();
check(count($liste['20261126_091400_000001']) === 1 && !str_contains((string)file_get_contents("$dir/daten/kienzlefon/vorgaenge/sms.json"), '1701234567'), 'SMS gemerkt ohne Nummer');
wirft(fn() => Vorgaenge::smsSenden($ks, 'gibtsnicht', '+491701234567', 'x'), 'SMS zu unbekanntem Vorgang');
$sms = ['bereit' => true, 'praxis' => 'Praxis Beispiel', 'stand' => ['20261126_091400_000001' => ['stand' => 'gesendet', 'meldung' => '', 'zeit' => time()]]];
$v = Vorgaenge::liste($k);
$html = Ansicht::vorgaenge($v, 'offen', 2, $k->csrf(), null, null, $sms);
check(str_contains($html, 'SMS verschickt') && str_contains($html, 'value="+4930123456"') === false && str_contains($html, 'name="aktion" value="vorgang-sms"'), 'SMS-Formular und Stand');
check(!str_contains(Ansicht::vorgaenge($v, 'offen', 2, $k->csrf(), null, null, ['bereit' => false]), 'vorgang-sms'), 'ohne SMS-Zugang kein Formular');
echo "telefon.php: alles in Ordnung\n";
