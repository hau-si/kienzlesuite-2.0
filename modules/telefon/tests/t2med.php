<?php
declare(strict_types=1);
// Modul telefon: T2med-Telefonverzeichnis (Laden, Suche, Nummern), Statistik, Seiten. Nur erfundene Daten.
require dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleTelefon\{Anlage, Ansicht, Statistik, T2med, Verzeichnis};

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool { if (!(error_reporting() & $no)) return false; throw new ErrorException($msg, 0, $no, $file, $line); });
function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }
function wirft(callable $f, string $msg, string $text = ''): void {
    try { $f(); } catch (InvalidArgumentException | RuntimeException $e) { if ($text === '' || str_contains($e->getMessage(), $text)) return; fwrite(STDERR, "FEHLER: $msg (Meldung: {$e->getMessage()})\n"); exit(1); }
    fwrite(STDERR, "FEHLER: $msg\n"); exit(1);
}

$dir = sys_get_temp_dir() . '/tf-t2med-' . bin2hex(random_bytes(4));
mkdir("$dir/daten", 0700, true);
mkdir("$dir/schluessel", 0700);
file_put_contents("$dir/schluessel/telefon.key", bin2hex(random_bytes(32)));
register_shutdown_function(static function () use ($dir) { exec('rm -r ' . escapeshellarg($dir)); });
Anlage::$data = "$dir/daten";
$k = new KienzleModul('telefon', ['KIENZLE_BENUTZER' => 'mfa1', 'KIENZLE_ROLLE' => 'benutzer', 'KIENZLE_CSRF' => str_repeat('c', 32)], "$dir/verbindungen", "$dir/schluessel");

// Nummern (dieselben Beispiele prüft tests/test_t2med.py für lib/anrufername.agi)
$faelle = json_decode((string)file_get_contents(__DIR__ . '/nummern.json'), true);
foreach ($faelle as [$roh, $vw, $soll]) check(Verzeichnis::schluessel($roh, $vw) === $soll, "Nummer $roh ($vw): " . var_export(Verzeichnis::schluessel($roh, $vw), true));
check(Verzeichnis::anzeige('49301234567') === '0301234567' && Verzeichnis::suchname('Jürgen', 'Groß') === 'juergen gross', 'Anzeige und Suchname');

// T2med nachgestellt: zwei Seiten, Weiterblättern nur auf demselben Server
final class TestT2med extends T2med {
    public array $aufrufe = [];
    public array $seiten = [];
    protected function anfrage(string $url, string $token): array { $this->aufrufe[] = [$url, $token]; return ['status' => 200, 'body' => $this->seiten[$url] ?? ['resourceType' => 'Bundle', 'entry' => []]]; }
}
$basis = 'https://t2med.example.org/aps/fhir/api/r4';
$p = static fn(string $id, string $nr, string $vor, string $nach, string $geb, array $tel, array $extra = []) => ['resource' => ['resourceType' => 'Patient', 'id' => $id,
    'identifier' => $nr === '' ? [] : [['system' => T2med::PATIENTENNUMMER, 'value' => $nr]], 'name' => [['use' => 'official', 'family' => $nach, 'given' => [$vor]]] + $extra,
    'birthDate' => $geb, 'telecom' => array_map(fn($t) => ['system' => 'phone', 'value' => $t], $tel)]];
$t = new TestT2med(['base_url' => $basis, 'launch_urls' => []]);
$t->seiten["$basis/Patient"] = ['resourceType' => 'Bundle', 'link' => [['relation' => 'next', 'url' => "$basis/Patient?page=2"]], 'entry' => [
    $p('p1', '4711', 'Max', 'Mustermann', '1970-01-01', ['030 1234567', '0170 1234567']),
    $p('p2', '4712', 'Maria', 'Mustermann', '1975-03-12', ['030 1234567']),
    $p('p3', '4713', 'Erika', 'Beispiel', '1980-05-05', ['+49 (0)40 9876543'])]];
$t->seiten["$basis/Patient?page=2"] = ['resourceType' => 'Bundle', 'entry' => [$p('p4', '', 'Jürgen', 'Groß', 'kaputt', ['1234567']),
    ['resource' => ['resourceType' => 'Patient', 'id' => '../x', 'name' => [['family' => 'Böse']]]]]];
check(!$t->erlaubt('https://fremd.example.org/fhir') && $t->erlaubt($basis) && !$t->erlaubt('http://t2med.example.org/aps/fhir/api/r4'), 'nur freigegebene FHIR-Adresse');
check(T2med::naechste(['link' => [['relation' => 'next', 'url' => 'https://fremd.example.org/aps/fhir/api/r4/Patient?page=2']]], $basis) === null
    && T2med::naechste(['link' => [['relation' => 'next', 'url' => 'https://user:pw@t2med.example.org/aps/fhir/api/r4/Patient']]], $basis) === null
    && T2med::naechste(['link' => [['relation' => 'next', 'url' => 'https://t2med.example.org/anderes/Patient']]], $basis) === null, 'Weiterblättern nur auf demselben Server');
wirft(fn() => Verzeichnis::starten(['context_id' => 'k1', 'oauth_token' => 'tok', 'fhir_base_url' => 'https://fremd.example.org/fhir'], $t, $k, 'kienzle.example.org'), 'fremde Adresse', 'nicht freigegeben');
wirft(fn() => Verzeichnis::starten(['context_id' => 'k1', 'oauth_token' => '${oAuthToken}', 'fhir_base_url' => $basis], $t, $k, 'kienzle.example.org'), 'Platzhalter statt Token', 'Zugriffstoken');
Verzeichnis::einstellungenSpeichern(['ortsvorwahl' => '030', 'anrufername' => '1']);
$start = Verzeichnis::starten(['context_id' => 'k1', 'oauth_token' => 'geheimes-token', 'fhir_base_url' => $basis . '/'], $t, $k, 'kienzle.example.org');
check(preg_match('#^https://kienzle\.example\.org/\#launch=([0-9a-f]{64})&m=telefon$#D', $start['url'], $m) === 1, 'Startlink');
check(!str_contains((string)file_get_contents("$dir/daten/t2med/laden.json"), 'geheimes-token'), 'Token nur verschlüsselt');
wirft(fn() => Verzeichnis::schritt(str_repeat('0', 64), $t, $k), 'falscher Code', 'Bitte frisch');
$r1 = Verzeichnis::schritt($m[1], $t, $k, 1);
check($r1 === ['fertig' => false, 'anzahl' => 3, 'seiten' => 1] && !is_file(Verzeichnis::datei()), 'erster Schritt, altes Verzeichnis bleibt bis zum Ende');
$r2 = Verzeichnis::schritt($m[1], $t, $k);
check($r2['fertig'] && $r2['anzahl'] === 4 && is_file(Verzeichnis::datei()) && !is_file("$dir/daten/t2med/laden.json") && (fileperms(Verzeichnis::datei()) & 0777) === 0600, 'fertig geladen: ' . json_encode($r2));
check($t->aufrufe[0] === ["$basis/Patient", 'geheimes-token'], 'Token an T2med');
Verzeichnis::zuruecksetzen();
check(Verzeichnis::stand()['anzahl'] === 4, 'Stand');

check(Verzeichnis::stand()['begrenzt'] === false, 'zwei Seiten: nicht begrenzt');

// Demo-Schlüssel: höchstens DEMO_SEITEN Anfragen je Laden; mit eigenem Schlüssel alles
$viele = static function (array $v) use ($basis, $p): TestT2med {
    $t = new TestT2med($v + ['base_url' => $basis]);
    for ($i = 1; $i <= 6; $i++) {
        $url = $i === 1 ? "$basis/Patient" : "$basis/Patient?page=$i";
        $t->seiten[$url] = ['resourceType' => 'Bundle', 'entry' => [$p("d$i", (string)(9000 + $i), 'Max', 'Muster' . $i, '1970-01-01', [])]]
            + ($i < 6 ? ['link' => [['relation' => 'next', 'url' => "$basis/Patient?page=" . ($i + 1)]]] : []);
    }
    return $t;
};
$demo = $viele([]);
check($demo->demo() && !$viele(['api_key' => 'eigener-schluessel'])->demo(), 'Demo erkannt');
$s1 = Verzeichnis::starten(['context_id' => 'k2', 'oauth_token' => 'tok', 'fhir_base_url' => $basis], $demo, $k, 'kienzle.example.org');
preg_match('/launch=([0-9a-f]{64})/', $s1['url'], $mm);
$r = Verzeichnis::schritt($mm[1], $demo, $k);
check($r['fertig'] && $r['begrenzt'] && $r['anzahl'] === Verzeichnis::DEMO_SEITEN && count($demo->aufrufe) === Verzeichnis::DEMO_SEITEN, 'Demo: nur ' . Verzeichnis::DEMO_SEITEN . ' Anfragen: ' . json_encode($r) . ' ' . count($demo->aufrufe));
Verzeichnis::zuruecksetzen();
check(Verzeichnis::stand()['begrenzt'] === true, 'Stand merkt die Begrenzung');
$voll = $viele(['api_key' => 'eigener-schluessel']);
$s2 = Verzeichnis::starten(['context_id' => 'k3', 'oauth_token' => 'tok', 'fhir_base_url' => $basis], $voll, $k, 'kienzle.example.org');
preg_match('/launch=([0-9a-f]{64})/', $s2['url'], $mm);
$r = Verzeichnis::schritt($mm[1], $voll, $k);
check($r['fertig'] && !$r['begrenzt'] && $r['anzahl'] === 6 && count($voll->aufrufe) === 6, 'eigener Schlüssel: alle Seiten');
Verzeichnis::zuruecksetzen();
// wieder das Verzeichnis der Suchtests laden
$s3 = Verzeichnis::starten(['context_id' => 'k4', 'oauth_token' => 'tok', 'fhir_base_url' => $basis], $t, $k, 'kienzle.example.org');
preg_match('/launch=([0-9a-f]{64})/', $s3['url'], $mm);
Verzeichnis::schritt($mm[1], $t, $k);
Verzeichnis::zuruecksetzen();

// Suche
$namen = fn(array $l) => array_map(fn($x) => Verzeichnis::name($x), $l);
check($namen(Verzeichnis::suchen('muster ma')) === ['Mustermann, Maria', 'Mustermann, Max'], 'Namensteile, Reihenfolge egal');
check($namen(Verzeichnis::suchen('max mustermann')) === ['Mustermann, Max'] && $namen(Verzeichnis::suchen('Mustermann, Max')) === ['Mustermann, Max'], 'Vor- und Nachname');
check($namen(Verzeichnis::suchen('juergen gross')) === ['Groß, Jürgen'] && $namen(Verzeichnis::suchen('groß')) === ['Groß, Jürgen'], 'Umlaute');
check($namen(Verzeichnis::suchen('4713')) === ['Beispiel, Erika'], 'Patientennummer');
check(count(Verzeichnis::suchen('030 1234567')) === 3 && $namen(Verzeichnis::suchen('0049409876543')) === ['Beispiel, Erika'], 'Telefonnummer');
check($namen(Verzeichnis::suchen('1234567')) === ['Groß, Jürgen', 'Mustermann, Maria', 'Mustermann, Max'], 'Nummer ohne Vorwahl (Ortsvorwahl), Endziffern');
check(Verzeichnis::suchen('%') === [] && Verzeichnis::suchen('') === [], 'leer und Platzhalter');
check(Verzeichnis::suchen('max')[0]['telefone'] === ['030 1234567', '0170 1234567'] && Verzeichnis::suchen('juergen')[0]['geburt'] === '', 'Telefone und ungültiges Geburtsdatum');
// Anrufer
check(Verzeichnis::nachNummer('+491701234567')['vorname'] === 'Max', 'Handy eindeutig');
check(Verzeichnis::nachNummer('0301234567') === null, 'zwei Patienten gleiche Nummer: kein Name');
check(Verzeichnis::nachNummer('unbekannt') === null && Verzeichnis::nachNummer('201') === null, 'unbekannt und Nebenstelle');
wirft(fn() => Verzeichnis::einstellungenSpeichern(['ortsvorwahl' => '12']), 'Ortsvorwahl geprüft', 'Ortsvorwahl');

// Statistik (Tabellen wie lib/telefon-ereignisse)
$s = "$dir/daten/statistik";
mkdir($s, 0700, true);
$pdo = new PDO("sqlite:$s/statistik.sqlite");
$pdo->exec('CREATE TABLE anrufe (id TEXT PRIMARY KEY, zeit INTEGER NOT NULL, tag TEXT NOT NULL, stunde INTEGER NOT NULL, wochentag INTEGER NOT NULL);
    CREATE TABLE routen (id TEXT NOT NULL, route TEXT NOT NULL, PRIMARY KEY (id, route));
    CREATE TABLE warten (id TEXT PRIMARY KEY, zeit INTEGER NOT NULL, tag TEXT NOT NULL, ergebnis TEXT NOT NULL, sekunden INTEGER NOT NULL);');
$jetzt = strtotime('2026-11-26 10:30:00 Europe/Berlin');
foreach ([[0, 9, 'prescription', 'angenommen', 40], [0, 10, 'prescription', 'angenommen', 80], [0, 10, 'appointment', 'aufgelegt', 200], [1, 8, 'queue', 'angenommen', 30], [20, 9, 'referral', '', 0]] as $i => [$tagZurueck, $std, $route, $erg, $sek]) {
    $t0 = strtotime(date('Y-m-d', $jetzt - $tagZurueck * 86400) . " $std:15:00 Europe/Berlin");
    $pdo->prepare('INSERT INTO anrufe VALUES (?, ?, ?, ?, ?)')->execute(["c$i", $t0, date('Y-m-d', $t0), $std, (int)date('N', $t0)]);
    $pdo->prepare('INSERT INTO routen VALUES (?, ?)')->execute(["c$i", $route]);
    if ($erg !== '') $pdo->prepare('INSERT INTO warten VALUES (?, ?, ?, ?, ?)')->execute(["c$i", $t0, date('Y-m-d', $t0), $erg, $sek]);
}
$a = Statistik::auswerten('woche', $jetzt);
check($a['heute'] === 3 && $a['zeitraum'] === 4 && $a['angenommen'] === 75 && $a['warten'] === 50 && $a['aufgelegt'] === 1, 'Kennzahlen: ' . json_encode($a));
check($a['routen'] == ['Rezept' => 2, 'Termin' => 1, 'Praxis (persönlich)' => 1] && count($a['verlauf']) === 14 && $a['verlauf']['2026-11-26'] === 3, 'Anliegen und Verlauf');
check($a['muster'][4][10] === 2, 'Wochenmuster (Donnerstag 10 Uhr)');
check(Statistik::auswerten('monat', $jetzt)['routen']['Überweisung'] === 1, 'Monat');
check(Statistik::heute($jetzt) === ['anrufe' => 3, 'routen' => ['Rezept' => 2, 'Termin' => 1], 'warten' => 60], 'Leiste heute');
Ansicht::$statistik = true;
$html = Ansicht::statistik($a, 'woche');
check(str_contains($html, '75 %') && str_contains($html, '0:50') && str_contains($html, 'tf-muster') && str_contains($html, 'aria-current="page"') && !str_contains($html, 'style="'), 'Statistik-Seite ohne Inline-Styles');
check(str_contains(Ansicht::statistik(['daten' => false] + Statistik::auswerten('woche', $jetzt), 'woche'), 'Noch keine Anrufe'), 'leere Statistik');

// Anrufe: Namen und Akte, Suchfeld
Ansicht::$patienten = true; Ansicht::$csrf = 'x';
$stand = ['laeuft' => true, 'sprachmenue' => 0, 'warteschlange' => [['schlange' => 'praxis', 'nummer' => '01701234567', 'wahl' => '01701234567', 'warten' => '0:35'], ['schlange' => 'praxis', 'nummer' => '0151 9876543', 'wahl' => '01519876543', 'warten' => '0:10']],
    'telefone' => [['nummer' => '201', 'name' => 'Empfang', 'art' => 'belegt', 'partner' => '+49409876543', 'dauer' => '2:10']]];
$html = Ansicht::anrufeStand($stand, 'x', true);
check(str_contains($html, '<strong>Mustermann, Max</strong>') && str_contains($html, 'data-akte="4711"') && str_contains($html, 'Beispiel, Erika') && str_contains($html, 'data-suche="01519876543"'), 'Namen, Akte, Suchen');
Ansicht::$patienten = false;
check(!str_contains(Ansicht::anrufeStand($stand, 'x', true), 'Mustermann'), 'ohne Recht keine Namen');
Verzeichnis::loeschen();
check(!is_file(Verzeichnis::datei()) && Verzeichnis::stand()['anzahl'] === 0, 'Verzeichnis gelöscht');
echo "t2med.php: alles in Ordnung\n";
