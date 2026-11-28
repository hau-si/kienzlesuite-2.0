<?php
declare(strict_types=1);
// Modul homepage: Praxen, Mitteilungen, Texte für die Homepage, Adresse, Plugin (mit nachgebautem WordPress) und echte Übertragung. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/wp-nachbau.php';
require dirname(__DIR__) . '/wordpress/praxisherz-hinweise/praxisherz-hinweise.php';
use KienzleHomepage\{Homepage, Mitteilungen, Praxen, Speicher};

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg, string $text = ''): void {
    try { $f(); } catch (InvalidArgumentException $e) { check($text === '' || str_contains($e->getMessage(), $text), "$msg: " . $e->getMessage()); return; }
    fwrite(STDERR, "FEHLER: $msg\n"); exit(1);
}

$dir = sys_get_temp_dir() . '/hp-test-' . bin2hex(random_bytes(4));
mkdir("$dir/daten", 0700, true);
mkdir("$dir/schluessel", 0700);
file_put_contents("$dir/schluessel/homepage.key", bin2hex(random_bytes(32)));
Speicher::$data = "$dir/daten";
ini_set('error_log', "$dir/fehler.log"); // erwartete Meldungen „Übertragung fehlgeschlagen“
$server = null;
register_shutdown_function(static function () use ($dir, &$server) {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    Speicher::zuruecksetzen();
    exec('rm -r ' . escapeshellarg($dir));
});
$k = new KienzleModul('homepage', ['KIENZLE_BENUTZER' => 'anna'], "$dir/verbindungen", "$dir/schluessel");
Mitteilungen::$heute = '2026-12-20';

// ---- Vertretungspraxen ----
$a = Praxen::speichern(0, ['name' => ' Praxis Dr. med. Erika   Beispiel ', 'strasse' => 'Musterstraße 1', 'ort' => '12345 Musterstadt', 'telefon' => '01234 56789']);
$b = Praxen::speichern(0, ['name' => 'Hausarztpraxis Max Mustermann', 'strasse' => 'Beispielweg 7', 'ort' => '12345 Musterstadt', 'telefon' => '01234 98765', 'zusatz' => 'Mo–Fr 8–12 Uhr']);
$c = Praxen::speichern(0, ['name' => 'Praxis Dr. med. Anna Muster', 'telefon' => '']);
check(Praxen::eine($a)['name'] === 'Praxis Dr. med. Erika Beispiel', 'Leerzeichen bereinigt');
check(array_column(Praxen::alle(), 'name') === ['Hausarztpraxis Max Mustermann', 'Praxis Dr. med. Anna Muster', 'Praxis Dr. med. Erika Beispiel'], 'nach Namen sortiert');
wirft(fn() => Praxen::speichern(0, ['name' => '']), 'Name Pflicht', 'Namen');
wirft(fn() => Praxen::speichern(0, ['name' => 'X', 'telefon' => '<script>']), 'Telefon geprüft', 'Telefon');
wirft(fn() => Praxen::speichern(999, ['name' => 'X']), 'unbekannte Praxis');
Praxen::speichern($c, ['name' => 'Praxis Dr. med. Anna Muster', 'ort' => '12345 Musterstadt', 'telefon' => '01234 55555']);
check(Praxen::eine($c)['telefon'] === '01234 55555', 'Praxis geändert');
check(Praxen::zeile(Praxen::eine($b)) === 'Hausarztpraxis Max Mustermann, Beispielweg 7, 12345 Musterstadt, Tel. 01234 98765 – Mo–Fr 8–12 Uhr', 'Zeile für die Homepage');

// ---- Mitteilungen: Prüfung ----
$alleOrte = ['leiste', 'kasten'];
wirft(fn() => Mitteilungen::ausFormular(['art' => 'x', 'orte' => $alleOrte]), 'Art Pflicht', 'Art');
wirft(fn() => Mitteilungen::ausFormular(['art' => 'vertretung', 'orte' => $alleOrte]), 'Vertretung ohne Praxis', 'Praxis');
wirft(fn() => Mitteilungen::ausFormular(['art' => 'vertretung', 'praxen' => [999], 'orte' => $alleOrte]), 'unbekannte Praxis zählt nicht', 'Praxis');
wirft(fn() => Mitteilungen::ausFormular(['art' => 'frei', 'titel' => 'X']), 'Ort Pflicht', 'erscheinen');
wirft(fn() => Mitteilungen::ausFormular(['art' => 'frei', 'orte' => $alleOrte]), 'freier Text leer', 'Überschrift');
wirft(fn() => Mitteilungen::ausFormular(['art' => 'frei', 'titel' => 'X', 'von' => '2026-12-24', 'bis' => '2026-12-23', 'orte' => $alleOrte]), 'Bis vor Vom', 'Bis');
wirft(fn() => Mitteilungen::ausFormular(['art' => 'frei', 'titel' => 'X', 'von' => '2026-02-30', 'orte' => $alleOrte]), 'ungültiges Datum', 'Datum');
wirft(fn() => Mitteilungen::ausFormular(['art' => 'oeffnungszeiten', 'orte' => $alleOrte, 'tage_datum' => [''], 'tage_zeit' => ['8–12 Uhr']]), 'Tag ohne Datum', 'Datum');
$lax = Mitteilungen::ausFormular(['art' => 'unsinn', 'von' => 'x', 'orte' => ['nirgends']], false);
check($lax['art'] === 'frei' && $lax['von'] === '' && $lax['orte'] === [], 'Vorschau prüft nachsichtig');
$f = Mitteilungen::ausFormular(['art' => 'vertretung', 'praxen' => [(string)$b, (string)$a, (string)$a], 'von' => '2026-12-22', 'bis' => '2027-01-02', 'orte' => ['leiste', 'kasten', 'leiste', 'evil']]);
check($f['praxen'] === [$b, $a] && $f['orte'] === ['leiste', 'kasten'], 'Praxen und Orte ohne Doppelte, Reihenfolge wie angekreuzt');
$f = Mitteilungen::ausFormular(['art' => 'oeffnungszeiten', 'orte' => ['fenster'], 'tage_datum' => ['2026-12-31', '', '2026-12-24'], 'tage_zeit' => ['8–12 Uhr', '', '']]);
check($f['tage'] === [['2026-12-24', 'geschlossen'], ['2026-12-31', '8–12 Uhr']] && $f['bis'] === '2026-12-31', 'Tage sortiert, leere Zeit = geschlossen, Bis = letzter Tag');

// ---- Mitteilungen: Speichern, Zustand, Texte ----
$v = Mitteilungen::speichern(0, ['art' => 'vertretung', 'titel' => 'Urlaubsvertretung', 'praxen' => [$a, $b], 'von' => '2026-12-22', 'bis' => '2027-01-02', 'orte' => ['leiste', 'kasten']], 'anna');
$i = Mitteilungen::speichern(0, ['art' => 'impfstoff', 'titel' => 'Grippeimpfstoff ist lieferbar', 'text' => 'Bitte vereinbaren Sie einen Termin.', 'orte' => ['kasten']], 'anna');
$o = Mitteilungen::speichern(0, ['art' => 'oeffnungszeiten', 'titel' => '', 'orte' => ['fenster', 'kurzcode'], 'tage_datum' => ['2026-12-31'], 'tage_zeit' => ['8–12 Uhr']], 'anna', false);
$alt = Mitteilungen::speichern(0, ['art' => 'frei', 'titel' => 'Fortbildung', 'bis' => '2026-12-01', 'orte' => ['leiste']], 'anna');
check(Mitteilungen::zustand(Mitteilungen::eine($v)) === 'geplant' && Mitteilungen::zustand(Mitteilungen::eine($v), '2026-12-22') === 'aktiv' && Mitteilungen::zustand(Mitteilungen::eine($v), '2027-01-03') === 'abgelaufen', 'Zustand nach Datum');
check(Mitteilungen::zustand(Mitteilungen::eine($i)) === 'aktiv' && Mitteilungen::zustand(Mitteilungen::eine($o)) === 'aus' && Mitteilungen::zustand(Mitteilungen::eine($alt)) === 'abgelaufen', 'Zustände');
$pn = Mitteilungen::praxenNachId();
$d = Mitteilungen::darstellung(Mitteilungen::eine($v), $pn);
check($d['titel'] === 'Urlaubsvertretung vom 22.12.2026 bis 02.01.2027', 'Überschrift Vertretung: ' . $d['titel']);
check($d['kurz'] === 'Urlaubsvertretung vom 22.12.2026 bis 02.01.2027: Praxis Dr. med. Erika Beispiel (Tel. 01234 56789) und Hausarztpraxis Max Mustermann (Tel. 01234 98765).', 'Leiste Vertretung: ' . $d['kurz']);
check(count($d['punkte']) === 2 && str_starts_with($d['punkte'][0], 'Praxis Dr. med. Erika Beispiel, Musterstraße 1'), 'Punkte mit Anschrift');
Mitteilungen::speichern($v, ['art' => 'vertretung', 'praxen' => [$a, $b, $c], 'von' => '2026-12-22', 'bis' => '2027-01-02', 'orte' => ['leiste']], 'ben');
check(str_contains(Mitteilungen::darstellung(Mitteilungen::eine($v), $pn)['kurz'], 'Vertretung vom 22.12.2026 bis 02.01.2027: Praxis Dr. med. Erika Beispiel (Tel. 01234 56789), Hausarztpraxis Max Mustermann (Tel. 01234 98765) und Praxis Dr. med. Anna Muster'), 'drei Praxen, Vorgabetitel');
check(Mitteilungen::eine($v)['geaendert_von'] === 'ben', 'Bearbeiter vermerkt');
$d = Mitteilungen::darstellung(Mitteilungen::eine($i), $pn);
check($d['kurz'] === 'Grippeimpfstoff ist lieferbar. Bitte vereinbaren Sie einen Termin.', 'Leiste Impfstoff: ' . $d['kurz']);
check($d['farbe'] === 'gruen' && $d['symbol'] === 'spritze', 'Farbe und Symbol passend zur Art');
Mitteilungen::speichern($i, ['art' => 'impfstoff', 'titel' => 'Grippeimpfstoff ist lieferbar', 'text' => 'Bitte vereinbaren Sie einen Termin.', 'orte' => ['kasten'], 'farbe' => 'rot', 'symbol' => 'achtung'], 'anna');
$d = Mitteilungen::darstellung(Mitteilungen::eine($i), $pn);
check($d['farbe'] === 'rot' && $d['symbol'] === 'achtung', 'Farbe und Symbol gewählt');
$f = Mitteilungen::ausFormular(['art' => 'frei', 'titel' => 'X', 'orte' => ['leiste'], 'farbe' => 'pink', 'symbol' => '<svg>']);
check($f['farbe'] === 'standard' && $f['symbol'] === 'standard' && Mitteilungen::darstellung(['id' => 1, 'praxen' => [], 'tage' => []] + $f, [])['farbe'] === 'grau', 'unbekannte Farbe/Symbol = passend zur Art');
check(Mitteilungen::svg('keins') === '' && str_contains(Mitteilungen::svg('uhr'), '<svg') && array_keys(Mitteilungen::SYMBOLE) === array_keys(PRAXISHERZ_HINWEISE_SYMBOLE)
    && array_column(Mitteilungen::SYMBOLE, 1) === array_values(PRAXISHERZ_HINWEISE_SYMBOLE) && array_values(array_diff(array_keys(Mitteilungen::FARBEN), ['standard'])) === PRAXISHERZ_HINWEISE_FARBEN
    && Mitteilungen::FARBE_ZUR_ART === PRAXISHERZ_HINWEISE_FARBE_ZUR_ART && Mitteilungen::SYMBOL_ZUR_ART === PRAXISHERZ_HINWEISE_SYMBOL_ZUR_ART, 'Farben und Symbole gleich in Modul und Plugin');
$d = Mitteilungen::darstellung(Mitteilungen::eine($o), $pn);
check($d['titel'] === 'Geänderte Öffnungszeiten' && $d['punkte'] === ['Do, 31.12.2026: 8–12 Uhr'] && $d['kurz'] === 'Geänderte Öffnungszeiten: Do, 31.12.2026: 8–12 Uhr.', 'Öffnungszeiten: ' . $d['kurz']);
check(array_column(Mitteilungen::fuerHomepage(), 'id') === ['m' . $i, 'm' . $v], 'an die Homepage: eingeschaltete, nicht abgelaufene (auch geplante)');
Mitteilungen::schalten($o, true, 'anna');
check(count(Mitteilungen::fuerHomepage()) === 3, 'eingeschaltet');
wirft(fn() => Praxen::loeschen($a), 'Praxis in laufender Mitteilung nicht löschbar', 'Mitteilung „Vertretung“');
Mitteilungen::loeschen($alt);
check(Mitteilungen::eine($alt) === null, 'gelöscht');

// ---- Adresse ----
check(Homepage::adressePruefen('www.praxis-beispiel.example.org/') === 'https://www.praxis-beispiel.example.org', 'https ergänzt');
check(Homepage::adressePruefen('https://example.org/wordpress/') === 'https://example.org/wordpress', 'Unterordner');
check(Homepage::adressePruefen('http://127.0.0.1:8080') === 'http://127.0.0.1:8080', 'http nur lokal (Tests)');
wirft(fn() => Homepage::adressePruefen('http://example.org'), 'http abgelehnt', 'https');
wirft(fn() => Homepage::adressePruefen('https://user:pw@example.org'), 'Zugangsdaten in der Adresse');
wirft(fn() => Homepage::adressePruefen('https://example.org/?x=1'), 'Abfrage in der Adresse');
wirft(fn() => Homepage::adressePruefen('ftp://example.org'), 'nur https', 'https');
wirft(fn() => Homepage::adressePruefen(''), 'leer');
wirft(fn() => Homepage::einrichten($k, 'https://example.org', 'kurz'), 'Schlüsselformat', 'Format');
wirft(fn() => Homepage::einrichten($k, 'https://example.org', ''), 'Schlüssel Pflicht beim ersten Mal', 'Schlüssel');
check(Homepage::ueberblick()[0]['state'] === 'open', 'Überblick: noch nicht verbunden');

// ---- Plugin-Paket ----
$zip = Homepage::pluginZip();
$tmp = "$dir/p.zip";
file_put_contents($tmp, $zip);
$z = new ZipArchive();
check($z->open($tmp) === true && $z->locateName('praxisherz-hinweise/praxisherz-hinweise.php') !== false && $z->locateName('praxisherz-hinweise/hinweise.js') !== false
    && $z->locateName('praxisherz-hinweise/readme.txt') !== false && $z->numFiles === 4, 'ZIP mit Plugin-Ordner');
$z->close();
check(Homepage::pluginVersion() === PRAXISHERZ_HINWEISE_VERSION, 'Version aus dem Plugin-Kopf');

// ---- Plugin: Unterschrift, Bereinigung, Ausgabe (nachgebautes WordPress) ----
$schluessel = praxisherz_hinweise_schluessel();
check(preg_match('/^[0-9a-f]{64}$/', $schluessel) === 1 && praxisherz_hinweise_schluessel() === $schluessel, 'Schlüssel erzeugt und behalten');
$jetzt = time();
$koerper = '{"format":1,"zeit":' . $jetzt . ',"hinweise":[]}';
$sig = Homepage::signatur($schluessel, $jetzt, $koerper);
check(praxisherz_hinweise_pruefen($koerper, (string)$jetzt, $sig, $schluessel, 0, $jetzt) === null, 'Unterschrift des Moduls passt zum Plugin');
check(praxisherz_hinweise_pruefen($koerper . ' ', (string)$jetzt, $sig, $schluessel, 0, $jetzt) === 'Schlüssel stimmt nicht', 'veränderter Körper');
check(praxisherz_hinweise_pruefen($koerper, (string)$jetzt, $sig, str_repeat('0', 64), 0, $jetzt) === 'Schlüssel stimmt nicht', 'falscher Schlüssel');
check(str_contains((string)praxisherz_hinweise_pruefen($koerper, (string)($jetzt - 400), Homepage::signatur($schluessel, $jetzt - 400, $koerper), $schluessel, 0, $jetzt), 'Uhrzeit'), 'zu alt');
check(praxisherz_hinweise_pruefen($koerper, (string)$jetzt, $sig, $schluessel, $jetzt + 1, $jetzt) === 'veraltete Übertragung', 'Wiederholung älterer Übertragung');
check(praxisherz_hinweise_pruefen($koerper, '', $sig, $schluessel, 0, $jetzt) !== null, 'ohne Zeit');
$roh = ['format' => 1, 'zeit' => $jetzt, 'hinweise' => [
    ['id' => 'm1', 'art' => 'frei', 'titel' => '<script>alert(1)</script>Titel', 'kurz' => 'Kurz <b>fett</b>', 'text' => "Zeile 1\nZeile 2", 'punkte' => ['<i>P</i>', ''], 'von' => '2026-12-01', 'bis' => 'morgen', 'orte' => ['kasten', 'leiste', 'boese']],
    ['id' => 'x"y', 'art' => 'frei', 'orte' => ['kasten']], ['id' => 'm2', 'art' => 'hack', 'orte' => ['kasten']], ['id' => 'm3', 'art' => 'frei', 'orte' => []],
    ['id' => 'm4', 'art' => 'impfstoff', 'titel' => 'Später', 'kurz' => 'Später', 'von' => '2099-01-01', 'orte' => ['kasten', 'fenster']],
]];
$h = praxisherz_hinweise_bereinigen($roh);
check(count($h) === 2 && $h[0]['orte'] === ['leiste', 'kasten'] && $h[0]['bis'] === '' && $h[0]['punkte'] === ['<i>P</i>'], 'bereinigt: ' . json_encode($h));
check(praxisherz_hinweise_bereinigen(['format' => 2, 'hinweise' => []]) === null && praxisherz_hinweise_bereinigen('x') === null, 'falsches Format');
$html = praxisherz_hinweise_html($h[0], 'kasten', '2026-12-20');
check(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;') && str_contains($html, '&lt;i&gt;P&lt;/i&gt;') && str_contains($html, "Zeile 1<br />\nZeile 2") && !str_contains($html, ' hidden'), 'Kasten maskiert: ' . $html);
check(str_contains(praxisherz_hinweise_html($h[0], 'leiste', '2026-12-20'), '<span class="ph-kurz">Kurz &lt;b&gt;fett&lt;/b&gt;</span>'), 'Leiste maskiert');
check(str_contains(praxisherz_hinweise_html($h[0], 'kasten', '2026-11-30'), ' hidden'), 'vor „Vom“ verborgen');
check(str_contains(praxisherz_hinweise_html($h[1], 'fenster', '2099-01-02'), ' hidden') && str_contains(praxisherz_hinweise_html($h[1], 'fenster'), 'role="dialog"'), 'Fenster erst per Skript');

// ---- Echte Übertragung an das nachgebaute WordPress (php -S) ----
$port = 0;
for ($versuch = 0; $versuch < 5 && !$port; $versuch++) {
    $p = random_int(20000, 60000);
    $s = @stream_socket_server("tcp://127.0.0.1:$p");
    if ($s) { fclose($s); $port = $p; }
}
check($port > 0, 'freier Port');
$optionen = "$dir/wp-optionen.json";
file_put_contents($optionen, json_encode(['praxisherz_hinweise_schluessel' => $schluessel]));
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/wp-server.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $rohr, __DIR__, ['WP_OPTIONEN' => $optionen, 'PATH' => '/usr/bin:/bin']);
for ($t = 0; $t < 50 && !@fsockopen('127.0.0.1', $port); $t++) usleep(100000);
Homepage::einrichten($k, "http://127.0.0.1:$port", strtoupper($schluessel));
check(Homepage::eingerichtet() && str_starts_with(Speicher::wert('schluessel'), 'v1:') && !str_contains(Speicher::wert('schluessel'), $schluessel), 'Schlüssel verschlüsselt gespeichert');
$st = Homepage::nachAenderung($k);
check($st !== null && $st['ok'] === true && $st['anzahl'] === 3 && !Homepage::ausstehend(), 'übertragen: ' . json_encode($st));
$wp = json_decode((string)file_get_contents($optionen), true);
check(count($wp["praxisherz_hinweise"]["hinweise"]) === 3 && $wp["praxisherz_hinweise"]["hinweise"][2]["kurz"] === 'Vertretung vom 22.12.2026 bis 02.01.2027: Praxis Dr. med. Erika Beispiel (Tel. 01234 56789), Hausarztpraxis Max Mustermann (Tel. 01234 98765) und Praxis Dr. med. Anna Muster (Tel. 01234 55555).', 'in WordPress angekommen');
check(Homepage::ueberblick()[0]['state'] === 'done', 'Überblick: verbunden');
// Ausgabe im nachgebauten WordPress mit den empfangenen Daten
$GLOBALS['wp_optionen_speicher'] = $wp;
$leiste = praxisherz_hinweise_block('leiste');
check(str_contains($leiste, 'ph-block-leiste') && str_contains($leiste, 'Praxis Dr. med. Anna Muster'), 'Leiste ausgegeben');
$kasten = apply_filters('the_content', '<p>Inhalt</p>');
check(str_contains($kasten, 'Grippeimpfstoff ist lieferbar') && str_ends_with($kasten, '<p>Inhalt</p>') && apply_filters('the_content', 'x') === 'x', 'Kasten einmal vor dem Inhalt der Startseite');
check(str_contains(($GLOBALS['wp_kurzcodes']['praxisherz_hinweise'])(), 'Geänderte Öffnungszeiten'), 'Kurzcode');
ob_start(); do_action('wp_footer'); $fuss = ob_get_clean();
check(str_contains($fuss, 'ph-nachreichen') && str_contains($fuss, 'ph-fenster'), 'Leiste ohne wp_body_open im Fuß, Fenster im Fuß');
check(str_contains($kasten, 'ph-farbe-rot') && str_contains($kasten, '<svg class="ph-symbol"') && !str_contains($fuss, 'ph-nachreichen-kasten'), 'Farbe und Symbol in WordPress, Kasten nicht doppelt');
// Startseite ohne Seiteninhalt (Theme baut sie selbst): Kasten im Fuß, das Skript setzt ihn
$GLOBALS['praxisherz_hinweise_kasten'] = false;
$GLOBALS['wp_ist_seite'] = false;
check(apply_filters('the_content', 'x') === 'x', 'Beitragsliste: kein Kasten im Beitrag');
ob_start(); do_action('wp_footer'); $fuss = ob_get_clean();
check(str_contains($fuss, 'ph-nachreichen-kasten" hidden data-ziel="" data-lage="anfang"') && str_contains($fuss, 'Grippeimpfstoff ist lieferbar'), 'Kasten im Fuß: ' . substr($fuss, 0, 300));
update_option('praxisherz_hinweise_kasten_ziel', '#aktuelles .liste');
update_option('praxisherz_hinweise_kasten_lage', 'vor');
$GLOBALS['praxisherz_hinweise_kasten'] = false;
ob_start(); do_action('wp_footer'); $fuss = ob_get_clean();
check(str_contains($fuss, 'data-ziel="#aktuelles .liste" data-lage="vor"'), 'eingestellte Stelle');
update_option('praxisherz_hinweise_kasten_ziel', '"><script>');
check(praxisherz_hinweise_kasten_stelle()[0] === '', 'ungültiger Selektor verworfen');

Homepage::einrichten($k, "http://127.0.0.1:$port", str_repeat('ab', 32));
$st = Homepage::uebertragen($k);
check($st['ok'] === false && str_contains($st['meldung'], 'Schlüssel stimmt nicht'), 'falscher Schlüssel gemeldet: ' . $st['meldung']);
check(Homepage::ueberblick()[0]['state'] === 'error', 'Überblick: Fehler');
Homepage::einrichten($k, "http://127.0.0.1:$port/nix", $schluessel);
check(str_contains(Homepage::uebertragen($k)['meldung'], 'Plugin'), 'ohne Plugin: Hinweis');
Homepage::einrichten($k, "http://127.0.0.1:$port/weiter", $schluessel);
check(str_contains(Homepage::uebertragen($k)['meldung'], 'leitet weiter auf https://www.example.org/'), 'Weiterleitung gemeldet');
proc_terminate($server);
proc_close($server);
Homepage::einrichten($k, "http://127.0.0.1:$port", $schluessel);
$st = Homepage::nachAenderung($k);
check($st['ok'] === false && str_contains($st['meldung'], 'nicht erreichbar') && Homepage::ausstehend(), 'nicht erreichbar, bleibt vorgemerkt: ' . $st['meldung']);
Homepage::trennen();
check(!Homepage::eingerichtet() && Homepage::nachAenderung($k) === null, 'getrennt: nur vormerken');

echo "Homepage: alle Tests bestanden.\n";
