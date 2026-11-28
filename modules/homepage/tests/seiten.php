<?php
declare(strict_types=1);
// Modul homepage: Einstieg public/index.php je Route in einem eigenen PHP-Prozess (Kopfzeilen der Basis nachgestellt). Nur erfundene Daten.
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
$dir = sys_get_temp_dir() . '/hp-seiten-' . bin2hex(random_bytes(4));
mkdir("$dir/daten", 0700, true);
mkdir("$dir/schluessel", 0700);
file_put_contents("$dir/schluessel/homepage.key", bin2hex(random_bytes(32)));
register_shutdown_function(static function () use ($dir) { exec('rm -r ' . escapeshellarg($dir)); });
$csrf = str_repeat('b', 64);
define('BASEDIR', implode(':', [realpath($dir) . '/', dirname(__DIR__, 3) . '/', '/usr/bin/', '/tmp/']));

/** Ruft eine Seite auf; liefert Ausgabe mit „#CODE=…“ und „#ORT=…“ am Ende. */
function aufruf(string $dir, string $uri, string $rechte, string $rolle = 'mitarbeiter', ?array $post = null, array $kopf = []): string {
    $server = ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $post !== null ? 'POST' : 'GET', 'KIENZLE_PFAD' => '/homepage', 'KIENZLE_BENUTZER' => 'anna',
        'KIENZLE_NAME' => 'Anna%20Muster', 'KIENZLE_ROLLE' => $rolle, 'KIENZLE_RECHTE' => $rechte, 'KIENZLE_CSRF' => str_repeat('b', 64), 'HTTP_HOST' => 'praxisherz.example.org',
        'KIENZLE_PRAXIS' => rawurlencode(json_encode(['name' => 'Praxis Dr. med. Erika Beispiel']))] + $kopf;
    $get = [];
    parse_str((string)parse_url($uri, PHP_URL_QUERY), $get);
    $code = '$_SERVER = ' . var_export($server, true) . ' + $_SERVER; $_GET = ' . var_export($get, true) . '; $_POST = ' . var_export($post ?? [], true) . ';'
        . ' require ' . var_export(dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php', true) . ';'
        . ' $GLOBALS["hp_test_modul"] = new KienzleModul("homepage", $_SERVER, ' . var_export("$dir/verbindungen", true) . ', ' . var_export("$dir/schluessel", true) . ');'
        . ' register_shutdown_function(function () { echo "\n#CODE=" . (http_response_code() ?: 200) . "\n#ORT=" . ($GLOBALS["hp_weiter"] ?? ""); }); require ' . var_export(dirname(__DIR__) . '/public/index.php', true) . ';';
    $env = ['KIENZLE_DATEN' => "$dir/daten", 'PATH' => '/usr/bin:/bin'];
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=' . $dir . '/fehler.log', '-d', 'open_basedir=' . BASEDIR, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    proc_close($p);
    check($err === '', "$uri: PHP-Meldung: $err");
    return $out;
}
function db(string $dir): PDO { return new PDO('sqlite:' . $dir . '/daten/homepage.db', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
function ort(string $o): string { return preg_match('/#ORT=(.*)$/', $o, $m) ? $m[1] : ''; }

// Zugang und Rechte
check(str_contains(aufruf($dir, '/homepage/', ''), '#CODE=403'), 'ohne Recht kein Zugang');
$o = aufruf($dir, '/homepage/', 'mitteilungen');
check(str_contains($o, '#CODE=200') && str_contains($o, '<aside class="side">') && str_contains($o, 'Neue Mitteilung') && str_contains($o, 'details class="card hp-neu" id="neu" open')
    && str_contains($o, 'Homepage noch nicht verbunden') && !str_contains($o, 'Einrichtung</a>'), 'Startseite im Rahmen, Neu offen ohne Mitteilungen, ohne Einrichtung');
check(str_contains($o, 'Noch keine Praxen angelegt') && str_contains($o, 'id="hp-vorschau"') && str_contains($o, "script-src 'self'") === false, 'Praxishinweis, Vorschau');
check(str_contains(aufruf($dir, '/homepage/einrichtung', 'mitteilungen,praxen'), '#CODE=403') && str_contains(aufruf($dir, '/homepage/plugin.zip', 'mitteilungen'), '#CODE=403'), 'Einrichtung und Plugin nur Admin');
check(str_contains(aufruf($dir, '/homepage/gibtsnicht', 'mitteilungen'), '#CODE=404'), 'unbekannte Seite');
check(str_contains(aufruf($dir, '/homepage/assets/homepage.css', 'praxen'), '.hp-zwei') && str_contains(aufruf($dir, '/homepage/assets/..%2fsrc%2fSpeicher.php', 'praxen'), '#CODE=404'), 'Dateien');

// Vertretungspraxen: Liste mit Knopf oben, Anlegen, Ändern
$o = aufruf($dir, '/homepage/praxen', 'praxen');
check(str_contains($o, '+ Praxis anlegen') && str_contains($o, 'Noch keine Praxen angelegt.') && !str_contains($o, 'name="aktion" value="praxis-speichern"'), 'Praxenliste leer');
check(str_contains(aufruf($dir, '/homepage/praxen?neu=1', 'praxen'), 'Neue Praxis'), 'Formular nach Knopf');
check(!str_contains(aufruf($dir, '/homepage/praxen?neu=1', 'mitteilungen'), 'Neue Praxis'), 'ohne Recht kein Formular');
check(str_contains(aufruf($dir, '/homepage/praxen', 'praxen', 'mitarbeiter', ['aktion' => 'praxis-speichern', 'name' => 'X']), '#CODE=403'), 'POST ohne CSRF');
check(str_contains(aufruf($dir, '/homepage/praxen', 'praxen', 'mitarbeiter', ['aktion' => 'praxis-speichern', 'name' => 'X', 'kienzle-csrf' => $csrf], ['HTTP_SEC_FETCH_SITE' => 'cross-site']), '#CODE=403'), 'POST fremde Seite');
check(str_contains(ort(aufruf($dir, '/homepage/praxen', 'mitteilungen', 'mitarbeiter', ['aktion' => 'praxis-speichern', 'name' => 'X', 'kienzle-csrf' => $csrf])), 'fehler='), 'Praxis nur mit Recht');
$o = aufruf($dir, '/homepage/praxen', 'praxen', 'mitarbeiter', ['aktion' => 'praxis-speichern', 'id' => '0', 'name' => 'Praxis Dr. med. Erika Beispiel', 'strasse' => 'Musterstraße 1',
    'ort' => '12345 Musterstadt', 'telefon' => '01234 56789', 'kienzle-csrf' => $csrf]);
check(str_contains($o, '#CODE=303') && str_contains(ort($o), 'ok=praxis'), 'Praxis angelegt');
aufruf($dir, '/homepage/praxen', 'praxen', 'mitarbeiter', ['aktion' => 'praxis-speichern', 'id' => '0', 'name' => 'Hausarztpraxis Max Mustermann', 'telefon' => '01234 98765', 'kienzle-csrf' => $csrf]);
$fehler = ort(aufruf($dir, '/homepage/praxen', 'praxen', 'mitarbeiter', ['aktion' => 'praxis-speichern', 'id' => '0', 'name' => '', 'kienzle-csrf' => $csrf]));
check(str_contains($fehler, 'neu=1') && str_contains($fehler, 'fehler='), 'Fehler zurück ins Formular: ' . $fehler);
$o = aufruf($dir, substr($fehler, strlen('')), 'praxen');
check(str_contains($o, 'Bitte den Namen der Praxis eintragen.') && str_contains($o, 'Neue Praxis'), 'Fehlermeldung mit Unterschrift');
check(!str_contains(aufruf($dir, '/homepage/praxen?fehler=Gef%C3%A4lscht&s=abc', 'praxen'), 'Gefälscht'), 'gefälschte Fehlermeldung nicht angezeigt');
$o = aufruf($dir, '/homepage/praxen', 'praxen');
check(str_contains($o, 'Praxis Dr. med. Erika Beispiel') && str_contains($o, 'Musterstraße 1, 12345 Musterstadt') && str_contains($o, 'Bearbeiten') && str_contains($o, 'value="praxis-loeschen"'), 'Liste');
check(!str_contains(aufruf($dir, '/homepage/praxen', 'mitteilungen'), 'Bearbeiten'), 'Liste nur lesen');
$ids = db($dir)->query('SELECT id FROM praxen ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

// Mitteilung „Vertretung“ mit zwei Praxen
$o = aufruf($dir, '/homepage/', 'mitteilungen');
check(str_contains($o, 'name="praxen[]" value="' . $ids[0] . '"') && str_contains($o, 'name="praxen[]" value="' . $ids[1] . '"'), 'Praxen zum Ankreuzen');
$neu = ['aktion' => 'anlegen', 'id' => '0', 'art' => 'vertretung', 'titel' => 'Urlaubsvertretung', 'praxen' => [(string)$ids[0], (string)$ids[1]], 'von' => date('Y-m-d'),
    'bis' => date('Y-m-d', strtotime('+10 days')), 'orte' => ['leiste', 'kasten'], 'sofort' => '1', 'text' => '', 'kienzle-csrf' => $csrf];
check(str_contains(aufruf($dir, '/homepage/', 'praxen', 'mitarbeiter', $neu), 'fehler='), 'Mitteilung nur mit Recht');
$o = aufruf($dir, '/homepage/', 'mitteilungen', 'mitarbeiter', $neu);
$m = db($dir)->query('SELECT * FROM mitteilungen')->fetch();
check(str_contains(ort($o), 'ok=angelegt') && str_ends_with(ort($o), '#m' . $m['id']) && (int)$m['aktiv'] === 1 && $m['praxen'] === json_encode([(int)$ids[0], (int)$ids[1]]), 'angelegt');
$o = aufruf($dir, '/homepage/?ok=angelegt', 'mitteilungen');
check(str_contains($o, 'Mitteilung angelegt.') && str_contains($o, 'Jetzt auf der Homepage') && str_contains($o, 'hp-schalter an') && str_contains($o, 'Urlaubsvertretung'), 'Zeile mit Schalter');
check(str_contains($o, 'class="hp-v-leiste hp-f-blau"') && str_contains($o, 'Hausarztpraxis Max Mustermann (Tel. 01234 98765)') && str_contains($o, 'Willkommen in der Praxis Dr. med. Erika Beispiel'), 'Vorschau mit Leiste');
check(str_contains($o, 'Noch nicht übertragen') === false && str_contains($o, 'Homepage noch nicht verbunden'), 'ohne Verbindung nur vorgemerkt');
// Fehler beim Speichern bleibt an der Zeile
$o = aufruf($dir, '/homepage/', 'mitteilungen', 'mitarbeiter', ['aktion' => 'speichern', 'id' => $m['id'], 'art' => 'vertretung', 'praxen' => [], 'orte' => ['leiste'], 'kienzle-csrf' => $csrf]);
check(str_contains(ort($o), '?id=' . $m['id']) && str_contains(ort($o), 'fehler=') && str_ends_with(ort($o), '#m' . $m['id']), 'Fehler zurück zur Zeile: ' . ort($o));
check(str_contains(aufruf($dir, '/homepage/?id=' . $m['id'], 'mitteilungen'), 'id="m' . $m['id'] . '" data-id="' . $m['id'] . '" open'), 'Zeile offen');
// Ausschalten und wieder einschalten
aufruf($dir, '/homepage/', 'mitteilungen', 'mitarbeiter', ['aktion' => 'schalten', 'id' => $m['id'], 'an' => '0', 'kienzle-csrf' => $csrf]);
$o = aufruf($dir, '/homepage/', 'mitteilungen');
check((int)db($dir)->query('SELECT aktiv FROM mitteilungen')->fetchColumn() === 0 && str_contains($o, 'Gerade erscheint keine Mitteilung') && str_contains($o, '<span class="tag">aus</span>'), 'ausgeschaltet');
aufruf($dir, '/homepage/', 'mitteilungen', 'mitarbeiter', ['aktion' => 'schalten', 'id' => $m['id'], 'an' => '1', 'kienzle-csrf' => $csrf]);
check((int)db($dir)->query('SELECT aktiv FROM mitteilungen')->fetchColumn() === 1, 'eingeschaltet');
// Praxis in laufender Vertretung nicht löschbar
check(str_contains(ort(aufruf($dir, '/homepage/praxen', 'praxen', 'mitarbeiter', ['aktion' => 'praxis-loeschen', 'id' => $ids[0], 'kienzle-csrf' => $csrf])), 'fehler='), 'Praxis geschützt');

// Vorschau beim Tippen (noch nicht gespeichert)
$vor = static fn(array $post, array $kopf = ['HTTP_X_KIENZLE_CSRF' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']) => aufruf($dir, '/homepage/vorschau', 'mitteilungen', 'mitarbeiter', $post, $kopf);
$o = $vor(['art' => 'impfstoff', 'titel' => 'Grippeimpfstoff <b>da</b>', 'orte' => ['fenster'], 'farbe' => 'rot', 'symbol' => 'spritze', 'id' => '0', 'datum' => date('Y-m-d')]);
check(str_contains($o, 'hp-v-fenster hp-f-rot hp-v-bearbeitet') && str_contains($o, 'Grippeimpfstoff &lt;b&gt;da&lt;/b&gt;') && str_contains($o, 'Urlaubsvertretung'), 'Vorschau mit ungespeicherter Mitteilung, maskiert');
check(db($dir)->query('SELECT COUNT(*) FROM mitteilungen')->fetchColumn() === 1, 'Vorschau speichert nichts');
$o = $vor(['datum' => date('Y-m-d', strtotime('+30 days'))]);
check(str_contains($o, 'erscheint keine Mitteilung'), 'Vorschau an einem späteren Tag');
check(str_contains($vor(['datum' => '2026-01-01'], []), '#CODE=403'), 'Vorschau ohne CSRF');
check(str_contains(aufruf($dir, '/homepage/vorschau', 'mitteilungen'), '#CODE=405'), 'Vorschau nur POST');

// Löschen
aufruf($dir, '/homepage/', 'mitteilungen', 'mitarbeiter', ['aktion' => 'loeschen', 'id' => $m['id'], 'kienzle-csrf' => $csrf]);
check((int)db($dir)->query('SELECT COUNT(*) FROM mitteilungen')->fetchColumn() === 0, 'gelöscht');
check(str_contains(ort(aufruf($dir, '/homepage/praxen', 'praxen', 'mitarbeiter', ['aktion' => 'praxis-loeschen', 'id' => $ids[1], 'kienzle-csrf' => $csrf])), 'ok=praxis-weg'), 'Praxis gelöscht');

// Einrichtung (Admin)
$o = aufruf($dir, '/homepage/einrichtung', '', 'admin');
check(str_contains($o, 'Plugin herunterladen') && str_contains($o, 'Version 0.') && str_contains($o, 'Einrichtung</a>') && str_contains($o, 'type="password"'), 'Einrichtungsseite');
$o = aufruf($dir, '/homepage/einrichtung', '', 'admin', ['aktion' => 'einrichten', 'url' => 'http://example.org', 'schluessel' => str_repeat('a', 64), 'kienzle-csrf' => $csrf]);
check(str_contains(ort($o), 'fehler=') && db($dir)->query("SELECT COUNT(*) FROM einstellungen WHERE name = 'url'")->fetchColumn() == 0, 'nur https');
// 127.0.0.1:9 (Discard) ist nicht erreichbar: gespeichert, Übertragung schlägt fehl und wird gemeldet.
$o = aufruf($dir, '/homepage/einrichtung', '', 'admin', ['aktion' => 'einrichten', 'url' => 'http://127.0.0.1:9', 'schluessel' => str_repeat('a', 64), 'kienzle-csrf' => $csrf]);
check(str_contains(ort($o), 'fehler=') && str_contains(urldecode(ort($o)), 'nicht erreichbar'), 'Übertragung gemeldet: ' . urldecode(ort($o)));
$s = db($dir)->query("SELECT wert FROM einstellungen WHERE name = 'schluessel'")->fetchColumn();
check(str_starts_with($s, 'v1:') && !str_contains($s, str_repeat('a', 64)), 'Schlüssel verschlüsselt');
$o = aufruf($dir, '/homepage/einrichtung', '', 'admin');
check(str_contains($o, 'Übertragung fehlgeschlagen') && str_contains($o, 'gespeichert – leer lassen') && !str_contains($o, str_repeat('a', 64)) && str_contains($o, 'Verbindung trennen'), 'Schlüssel nicht wieder angezeigt');
check(str_contains(aufruf($dir, '/homepage/', 'mitteilungen'), 'Erneut übertragen'), 'Knopf zum erneuten Übertragen');
aufruf($dir, '/homepage/einrichtung', '', 'admin', ['aktion' => 'trennen', 'kienzle-csrf' => $csrf]);
check(db($dir)->query("SELECT wert FROM einstellungen WHERE name = 'schluessel'")->fetchColumn() === '', 'getrennt');
$o = aufruf($dir, '/homepage/plugin.zip', '', 'admin');
check(str_starts_with($o, "PK\x03\x04"), 'Plugin als ZIP');

// Automatisch aus Praxis → Öffnungszeiten
check(str_contains(aufruf($dir, '/homepage/', 'mitteilungen'), 'Automatisch aus den Öffnungszeiten') && str_contains(aufruf($dir, '/homepage/', 'mitteilungen'), 'href="/verwaltung/zeiten"'), 'Hinweis ohne Paket');
@mkdir("$dir/verbindungen");
file_put_contents("$dir/verbindungen/homepage.json", json_encode(['zeiten' => ['format' => 1, 'stand' => str_repeat('c', 32), 'woche' => [], 'ereignisse' => [
    ['id' => 'e1', 'art' => 'urlaub', 'titel' => 'Urlaub <b>', 'von' => '2099-12-23', 'bis' => '2099-12-30', 'homepage' => true, 'satz' => 'Die Praxis ist vom 23.12. bis 30.12. geschlossen.', 'vertretung' => []]]]]));
$o = aufruf($dir, '/homepage/', 'mitteilungen');
check(str_contains($o, 'Urlaub &lt;b&gt;') && str_contains($o, 'ab 09.12.') && str_contains($o, 'name="aktion" value="zeiten"'), 'automatische Mitteilung in der Liste');
$o = aufruf($dir, '/homepage/', 'mitteilungen', 'mitarbeiter', ['aktion' => 'zeiten', 'aktiv' => '1', 'orte' => ['kasten'], 'farbe' => 'gruen', 'symbol' => 'standard', 'vorlauf' => '3', 'feiertage' => '1', 'kienzle-csrf' => $csrf]);
check(str_contains(ort($o), 'ok=zeiten') && str_contains(aufruf($dir, '/homepage/', 'mitteilungen'), 'ab 20.12.'), 'Einstellungen gespeichert: ' . ort($o));
check(str_contains(ort(aufruf($dir, '/homepage/', 'praxen', 'mitarbeiter', ['aktion' => 'zeiten', 'orte' => ['kasten'], 'vorlauf' => '3', 'feiertage' => '1', 'kienzle-csrf' => $csrf])), 'fehler='), 'Einstellungen nur mit Recht');

// Öffnungszeiten-Karte und Bereitschaftsdienst
check(str_contains(aufruf($dir, '/homepage/oeffnungszeiten', 'oeffnungszeiten'), '#CODE=200'), 'Seite nur mit Recht „oeffnungszeiten“ erreichbar');
file_put_contents("$dir/verbindungen/homepage.json", json_encode(['zeiten' => ['format' => 1, 'stand' => str_repeat('d', 32), 'woche' => ['sprech' => ['mo' => ['08:00-12:00'], 'di' => [], 'mi' => [],
    'do' => [], 'fr' => [], 'sa' => [], 'so' => []], 'telefon' => []], 'ereignisse' => []]]));
$o = aufruf($dir, '/homepage/oeffnungszeiten', 'oeffnungszeiten');
check(str_contains($o, 'Reguläre Sprechzeiten') && str_contains($o, '08:00–12:00 Uhr') && str_contains($o, 'href="/verwaltung/zeiten"') && str_contains($o, '+ Eintrag')
    && str_contains($o, 'class="ph-oeffnung"') && str_contains($o, '/homepage/plugin-dateien/oeffnung.js') && str_contains($o, '[praxisherz_oeffnungszeiten]'), 'Seite Öffnungszeiten');
check(!str_contains(aufruf($dir, '/homepage/oeffnungszeiten', 'mitteilungen'), '+ Eintrag'), 'ohne Recht nur lesen');
check(str_contains(aufruf($dir, '/homepage/oeffnungszeiten?dienst=neu', 'oeffnungszeiten'), 'name="wann"'), 'Formular');
check(str_contains(aufruf($dir, '/homepage/plugin-dateien/oeffnung.js', 'oeffnungszeiten'), 'function status') && str_contains(aufruf($dir, '/homepage/plugin-dateien/praxisherz-hinweise.php', 'oeffnungszeiten'), '#CODE=404'), 'Plugin-Dateien (feste Liste)');
$neu = ['aktion' => 'dienst-speichern', 'id' => '0', 'wann' => 'mi', 'von' => '13:00', 'bis' => '19:00', 'praxis' => (string)$ids[0], 'kienzle-csrf' => $csrf];
check(str_contains(ort(aufruf($dir, '/homepage/oeffnungszeiten', 'mitteilungen', 'mitarbeiter', $neu)), 'fehler='), 'Dienst nur mit Recht');
check(str_contains(aufruf($dir, '/homepage/oeffnungszeiten', 'oeffnungszeiten', 'mitarbeiter', ['kienzle-csrf' => 'x'] + $neu), '#CODE=403'), 'Dienst ohne CSRF');
check(str_contains(ort(aufruf($dir, '/homepage/oeffnungszeiten', 'oeffnungszeiten', 'mitarbeiter', $neu)), 'ok=dienst'), 'Dienst angelegt');
$fehler = ort(aufruf($dir, '/homepage/oeffnungszeiten', 'oeffnungszeiten', 'mitarbeiter', ['wann' => 'datum', 'datum' => ''] + $neu));
check(str_contains($fehler, 'dienst=neu') && str_contains($fehler, 'fehler='), 'Fehler zurück ins Formular');
$o = aufruf($dir, '/homepage/oeffnungszeiten', 'oeffnungszeiten');
check(str_contains($o, 'jeden Mittwoch') && str_contains($o, '13:00–19:00') && str_contains($o, 'value="dienst-loeschen"'), 'Dienst in der Liste');
$did = (int)db($dir)->query('SELECT id FROM dienste')->fetchColumn();
check(str_contains(ort(aufruf($dir, '/homepage/oeffnungszeiten', 'oeffnungszeiten', 'mitarbeiter', ['aktion' => 'notdienst', 'notdienst' => 'Notruf 112, Bereitschaftsdienst 116 117', 'kienzle-csrf' => $csrf])), 'ok=notdienst')
    && str_contains(aufruf($dir, '/homepage/oeffnungszeiten', 'oeffnungszeiten'), 'Notruf 112, Bereitschaftsdienst 116 117'), 'Hinweis gespeichert');
check(str_contains(ort(aufruf($dir, '/homepage/oeffnungszeiten', 'oeffnungszeiten', 'mitarbeiter', ['aktion' => 'dienst-loeschen', 'id' => (string)$did, 'kienzle-csrf' => $csrf])), 'ok=dienst-weg')
    && (int)db($dir)->query('SELECT COUNT(*) FROM dienste')->fetchColumn() === 0, 'Dienst gelöscht');

echo "Homepage-Seiten: alle Tests bestanden.\n";
