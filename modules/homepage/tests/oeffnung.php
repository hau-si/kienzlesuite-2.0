<?php
declare(strict_types=1);
// Modul homepage: Öffnungszeiten-Karte (Sprechzeiten aus der Basis, Bereitschaftsdienst, Feiertage, Urlaub mit Vertretung) und Plugin-Seite dazu.
// Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/wp-nachbau.php';
require dirname(__DIR__) . '/wordpress/praxisherz-hinweise/praxisherz-hinweise.php';
use KienzleHomepage\{Ansicht, Mitteilungen, Oeffnung, Praxen, Speicher, Zeiten};

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg, string $text = ''): void {
    try { $f(); } catch (InvalidArgumentException $e) { check($text === '' || str_contains($e->getMessage(), $text), "$msg: " . $e->getMessage()); return; }
    fwrite(STDERR, "FEHLER: $msg\n"); exit(1);
}

$dir = sys_get_temp_dir() . '/hp-oe-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
Speicher::$data = $dir;
register_shutdown_function(static function () use ($dir) { Speicher::zuruecksetzen(); exec('rm -r ' . escapeshellarg($dir)); });
$_SERVER['KIENZLE_PFAD'] = '/homepage';
Mitteilungen::$heute = '2026-12-20';

// Ohne Basis-Paket und ohne Dienste: keine Karte
Zeiten::$paket = null;
check(Oeffnung::woche(null) === null && Oeffnung::fuerHomepage(null) === null, 'ohne Daten keine Karte');

$a = Praxen::speichern(0, ['name' => 'Praxis Dr. med. Erika Beispiel', 'strasse' => 'Musterstraße 1', 'ort' => '12345 Musterstadt', 'telefon' => '01234 56789']);
$b = Praxen::speichern(0, ['name' => 'Hausarztpraxis Max <Mustermann>', 'telefon' => '01234 98765']);
$c = Praxen::speichern(0, ['name' => 'Bereitschaftspraxis am Klinikum', 'ort' => '12345 Musterstadt', 'telefon' => '116117']);

// ---- Bereitschaftsdienst: Prüfung ----
wirft(fn() => Oeffnung::dienstSpeichern(0, ['wann' => 'xx', 'von' => '13:00', 'bis' => '19:00', 'praxis' => $a]), 'Wann Pflicht', 'wann');
wirft(fn() => Oeffnung::dienstSpeichern(0, ['wann' => 'datum', 'datum' => '', 'von' => '13:00', 'bis' => '19:00', 'praxis' => $a]), 'Datum Pflicht', 'Datum');
wirft(fn() => Oeffnung::dienstSpeichern(0, ['wann' => 'mi', 'von' => '25:00', 'bis' => '19:00', 'praxis' => $a]), 'Uhrzeit', 'Uhrzeit');
wirft(fn() => Oeffnung::dienstSpeichern(0, ['wann' => 'mi', 'von' => '13:00', 'bis' => '13:00', 'praxis' => $a]), 'gleich', 'gleich');
wirft(fn() => Oeffnung::dienstSpeichern(0, ['wann' => 'mi', 'von' => '13:00', 'bis' => '19:00', 'praxis' => 999]), 'Praxis Pflicht', 'Praxis');
$mi = Oeffnung::dienstSpeichern(0, ['wann' => 'mi', 'von' => '13', 'bis' => '19.00', 'praxis' => $a]);
$fr = Oeffnung::dienstSpeichern(0, ['wann' => 'fr', 'von' => '18:00', 'bis' => '08:00', 'praxis' => $b]);
$ft = Oeffnung::dienstSpeichern(0, ['wann' => 'feiertag', 'von' => '08:00', 'bis' => '20:00', 'praxis' => $c]);
$hl = Oeffnung::dienstSpeichern(0, ['wann' => 'datum', 'datum' => '2026-12-24', 'von' => '12:00', 'bis' => '18:00', 'praxis' => $c]);
$alt = Oeffnung::dienstSpeichern(0, ['wann' => 'datum', 'datum' => '2026-11-01', 'von' => '08:00', 'bis' => '18:00', 'praxis' => $c]);
check(Oeffnung::dienst($mi)['von'] === '13:00' && Oeffnung::dienst($mi)['bis'] === '19:00', 'Uhrzeiten vereinheitlicht');
check(array_column(Oeffnung::dienste(), 'id') === [$mi, $fr, $ft, $alt, $hl], 'Reihenfolge: Wochentage, Feiertage, Daten');
check(Oeffnung::wannText('mi') === 'jeden Mittwoch' && Oeffnung::wannText('feiertag') === 'an Feiertagen' && Oeffnung::wannText('datum', '2026-12-24') === 'Do, 24.12.2026', 'Texte');
wirft(fn() => Praxen::loeschen($a), 'Praxis im Dienst nicht löschbar', 'Bereitschaftsdienst');
Oeffnung::aufraeumen();
check(Oeffnung::dienst($alt) === null && Oeffnung::dienst($hl) !== null, 'alte Tage aufgeräumt');
Oeffnung::dienstSpeichern($fr, ['wann' => 'fr', 'von' => '18:00', 'bis' => '08:00', 'praxis' => $b]);
Oeffnung::notdienstSpeichern('  Ärztlicher   Bereitschaftsdienst: 116 117 ');
check(Oeffnung::notdienst() === 'Ärztlicher Bereitschaftsdienst: 116 117', 'Hinweis gespeichert');
wirft(fn() => Oeffnung::notdienstSpeichern(str_repeat('x', 161)), 'Hinweis zu lang', '160');

// Nur Dienste, keine Basis: Karte mit leerer Woche
$d = Oeffnung::fuerHomepage(null);
check($d !== null && $d['woche']['mo'] === [] && count($d['dienste']['woche']) === 2, 'Karte nur mit Diensten');

// ---- Mit Öffnungszeiten aus der Basis ----
Zeiten::$paket = ['format' => 1, 'stand' => str_repeat('b', 32), 'woche' => ['sprech' => ['mo' => ['08:00-12:00', '14:00-18:00'], 'di' => ['08:00-12:00', '14:00-18:00'],
    'mi' => ['08:00-12:00'], 'do' => ['08:00-12:00', '14:00-18:00'], 'fr' => ['08:00-13:00'], 'sa' => [], 'so' => [], 'xx' => ['01:00-02:00']], 'telefon' => []],
    'ereignisse' => [
        ['id' => 'u1', 'art' => 'urlaub', 'titel' => 'Urlaub', 'von' => '2026-12-28', 'bis' => '2026-12-30', 'zeiten' => [],
         'vertretung' => [['von' => '2026-12-28', 'bis' => '2026-12-29', 'praxen' => [['name' => 'Praxis Dr. med. Anna Muster', 'telefon' => '01234 55555']]]]],
        ['id' => 'f-weihnacht-2026', 'art' => 'feiertag', 'titel' => '1. Weihnachtstag', 'von' => '2026-12-25', 'bis' => '2026-12-25', 'zeiten' => [], 'vertretung' => []],
        ['id' => 's1', 'art' => 'sonder', 'titel' => 'Sonderöffnung', 'von' => '2026-12-31', 'bis' => '2026-12-31', 'zeiten' => ['08:00-12:00', 'kaputt'], 'vertretung' => []],
        ['id' => 'alt', 'art' => 'geschlossen', 'titel' => 'Alt', 'von' => '2026-01-01', 'bis' => '2026-01-02', 'zeiten' => [], 'vertretung' => []],
    ]];
$w = Oeffnung::woche(null);
check($w['mo'] === [['08:00', '12:00'], ['14:00', '18:00']] && $w['sa'] === [] && !isset($w['xx']), 'Woche aus der Basis');
$g = Oeffnung::gruppen($w);
check($g[0] === ['tage' => ['mo', 'di', 'do'], 'label' => 'Mo, Di, Do', 'text' => '08:00–12:00, 14:00–18:00 Uhr'] && $g[1]['label'] === 'Mi' && end($g)['label'] === 'Sa, So' && end($g)['text'] === 'geschlossen', 'Gruppen');
$d = Oeffnung::fuerHomepage(null);
$tage = (array)$d['tage'];
check(isset($tage['2026-12-28'], $tage['2026-12-30'], $tage['2026-12-25']) && !isset($tage['2026-01-01']), 'Schließungen und Feiertage je Tag');
check($tage['2026-12-28']['zeiten'] === [] && $tage['2026-12-28']['grund'] === 'Urlaub' && $tage['2026-12-28']['vertretung'][0]['name'] === 'Praxis Dr. med. Anna Muster'
    && $tage['2026-12-30']['vertretung'] === [], 'Vertretung nur in ihrem Zeitraum');
check($tage['2026-12-25']['feiertag'] === true && $tage['2026-12-31']['zeiten'] === [['08:00', '12:00']], 'Feiertag, Sonderöffnung');
$dt = (array)$d['dienste']['tage'];
check(count($d['dienste']['woche']) === 2 && $d['dienste']['woche'][1]['tag'] === 'fr' && $d['dienste']['woche'][1]['bis'] === '08:00' && $d['dienste']['feiertag'][0]['praxis']['name'] === 'Bereitschaftspraxis am Klinikum'
    && array_keys($dt) === ['2026-12-24'] && $d['notdienst'] === 'Ärztlicher Bereitschaftsdienst: 116 117', 'Dienste für die Homepage');

// ---- Plugin: Bereinigung und gleiches Markup wie die Vorschau im Modul ----
$roh = json_decode((string)json_encode($d), true);
$p = praxisherz_oeffnung_bereinigen($roh);
check($p['woche']['mo'] === [['08:00', '12:00'], ['14:00', '18:00']] && $p['tage']['2026-12-28']['vertretung'][0]['telefon'] === '01234 55555' && count($p['dienste']['woche']) === 2
    && $p['dienste']['tage']['2026-12-24'][0]['von'] === '12:00', 'Plugin übernimmt die Daten');
$boese = $roh;
$boese['woche']['mo'] = [['08:00', '12:00'], ['<b>', 'x'], ['12:00', '08:00']];
$boese['tage']['kein-datum'] = ['zeiten' => []];
$boese['dienste']['woche'][] = ['tag' => 'xx', 'von' => '08:00', 'bis' => '09:00', 'praxis' => ['name' => 'X']];
$boese['dienste']['woche'][] = ['tag' => 'mo', 'von' => '08:00', 'bis' => '09:00', 'praxis' => ['name' => '']];
$q = praxisherz_oeffnung_bereinigen($boese);
check($q['woche']['mo'] === [['08:00', '12:00']] && !isset($q['tage']['kein-datum']) && count($q['dienste']['woche']) === 2, 'Plugin verwirft Unsinn');
check(praxisherz_oeffnung_bereinigen('x') === null, 'kein Aufbau');
check(praxisherz_oeffnung_karte($p) === Ansicht::karte($p), 'Karte im Plugin und in der Vorschau gleich');
$karte = praxisherz_oeffnung_karte($p, 'Sprechzeiten <i>');
check(str_contains($karte, '<h3 class="ph-oe-titel">Sprechzeiten &lt;i&gt;</h3>') && str_contains($karte, '<tr data-tage="mo di do"><th scope="row">Mo, Di, Do</th><td>08:00–12:00, 14:00–18:00 Uhr</td></tr>')
    && str_contains($karte, 'Max &lt;Mustermann&gt;') && !str_contains($karte, '<Mustermann>') && str_contains($karte, 'class="ph-oe-notdienst"'), 'Karte maskiert: ' . substr($karte, 0, 200));
check(praxisherz_oeffnung_karte(null) === '' && !str_contains(praxisherz_oeffnung_karte($p, ''), 'ph-oe-titel'), 'ohne Daten leer, ohne Überschrift');
// Empfang über die Schnittstelle des Plugins speichert die Karte; Kurzcode gibt sie aus
$GLOBALS['wp_optionen_speicher'] = [];
$schluessel = praxisherz_hinweise_schluessel();
$koerper = (string)json_encode(['format' => 1, 'zeit' => time(), 'hinweise' => [], 'oeffnung' => $d], JSON_UNESCAPED_UNICODE);
$antwort = praxisherz_hinweise_empfangen(new WP_REST_Request($koerper, ['X-Praxisherz-Zeit' => (string)json_decode($koerper, true)['zeit'],
    'X-Praxisherz-Signatur' => hash_hmac('sha256', json_decode($koerper, true)['zeit'] . "\n" . $koerper, $schluessel)]));
check($antwort->status === 200 && is_array(get_option('praxisherz_oeffnung')), 'empfangen');
check(str_contains(($GLOBALS['wp_kurzcodes']['praxisherz_oeffnungszeiten'])(['titel' => 'Sprechzeiten']), '<h3 class="ph-oe-titel">Sprechzeiten</h3>'), 'Kurzcode');
$koerper = (string)json_encode(['format' => 1, 'zeit' => time() + 1, 'hinweise' => []]);
praxisherz_hinweise_empfangen(new WP_REST_Request($koerper, ['X-Praxisherz-Zeit' => (string)(time() + 1), 'X-Praxisherz-Signatur' => hash_hmac('sha256', (time() + 1) . "\n" . $koerper, $schluessel)]));
check(get_option('praxisherz_oeffnung', null) === null && ($GLOBALS['wp_kurzcodes']['praxisherz_oeffnungszeiten'])() === '', 'ohne Karte gelöscht');

// Status-Rechnung im Browser (oeffnung.js) mit node, falls vorhanden
if (trim((string)shell_exec('command -v node')) !== '') {
    $aus = [];
    exec('node ' . escapeshellarg(__DIR__ . '/oeffnung.test.js') . ' 2>&1', $aus, $rc);
    check($rc === 0, "oeffnung.test.js:\n" . implode("\n", $aus));
} else {
    echo "node fehlt – Test von oeffnung.js übersprungen.\n";
}

echo "Öffnungszeiten: alle Tests bestanden.\n";
