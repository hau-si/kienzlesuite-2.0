<?php
declare(strict_types=1);
// Modul telefon: Einstellungen, Verschlüsselung der Passwörter, Generator-Aufruf, Seiten. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleTelefon\{Anlage, Ansicht};

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
wirft(fn() => Anlage::sprachmenueSpeichern($cfg, ['aktiv' => '1', 'tts' => 'espeak']), 'Sprachausgabe geprüft', 'Sprachausgabe');
check(Anlage::sprachmenueSpeichern($cfg, ['aktiv' => '1', 'tts' => 'google'])['kienzlefon'] === ['aktiv' => true, 'tts' => 'google'], 'Sprachmenü-Schalter');
check(Anlage::laden()['kienzlefon'] === ['aktiv' => false, 'tts' => 'piper'], 'Vorgabe: Sprachmenü aus, Piper');

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
echo "telefon.php: alles in Ordnung\n";
