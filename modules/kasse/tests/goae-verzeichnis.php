<?php
declare(strict_types=1);
// GOÄ-Gebührenverzeichnis: Daten aus der amtlichen Anlage, genaue Gebühr nach § 5 GOÄ, Prüfung beim Speichern einer Leistung.
require dirname(__DIR__) . '/src/bootstrap.php';
require_once __DIR__ . '/lib.php';
use KienzleKasse\{App, Config, GoaeVerzeichnis, Problem, ServiceCatalog};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

$dirs = [];
try {
    // Datei: vollständig, Nummern eindeutig, Einfachsatz = Punktzahl × 5,82873 Cent (gerundet), bekannte Rahmen.
    $data = json_decode(file_get_contents(GoaeVerzeichnis::datei()), true, 8, JSON_THROW_ON_ERROR);
    $rows = $data['eintraege'];
    check(count($rows) > 2700, 'Zu wenige Ziffern: ' . count($rows));
    check(count(array_unique(array_map(static fn($r) => strtolower($r[0]), $rows))) === count($rows), 'Ziffern doppelt');
    foreach ($rows as $r) {
        check(count($r) === 7 && is_string($r[0]) && is_int($r[2]) && $r[2] > 0 && is_string($r[5]) && $r[5] !== '', 'Eintrag unvollständig: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
        check($r[3] === (int)round($r[2] * 5.82873), 'Einfachsatz falsch: ' . $r[0]);
        check(isset(ServiceCatalog::FEES[$r[4]]), 'Unbekannter Rahmen: ' . $r[0]);
    }
    // Stichproben aus dem amtlichen Verzeichnis.
    $e = GoaeVerzeichnis::eintrag('1');
    check($e['punkte'] === 80 && $e['einfachsatz_cents'] === 466 && $e['rahmen'] === 'standard' && str_starts_with($e['text'], 'Beratung'), 'Nr. 1 falsch');
    check(GoaeVerzeichnis::eintrag('250')['rahmen'] === 'technical' && GoaeVerzeichnis::eintrag('250a') !== null, 'Nr. 250/250a (Abschnitt A, technisch) falsch');
    check(GoaeVerzeichnis::eintrag('3511')['rahmen'] === 'lab' && GoaeVerzeichnis::eintrag('437')['rahmen'] === 'lab', 'Labor (M, Nr. 437) falsch');
    check(GoaeVerzeichnis::eintrag('500')['rahmen'] === 'technical' && GoaeVerzeichnis::eintrag('5000')['rahmen'] === 'technical', 'Abschnitt E/O nicht technisch');
    check(GoaeVerzeichnis::eintrag('410')['punkte'] === 200 && GoaeVerzeichnis::eintrag('4214')['text'] === 'Lithium' && GoaeVerzeichnis::eintrag('4114')['text'] !== 'Lithium', 'Nr. 410/4214 falsch');
    check(GoaeVerzeichnis::eintrag('K 1')['punkte'] === 120 && GoaeVerzeichnis::eintrag('k1')['nur_einfach'] && GoaeVerzeichnis::eintrag('A')['nur_einfach'], 'Zuschläge falsch');
    check(GoaeVerzeichnis::eintrag('3504')['punkte'] === 60, 'Laborkatalog ohne Punktzahl');
    check(GoaeVerzeichnis::eintrag('9999') === null && GoaeVerzeichnis::eintrag('') === null, 'Unbekannte Ziffer gefunden');

    // Gebühr genau nach § 5 GOÄ, erst am Ende gerundet: Nr. 3 (150 Punkte) × 2,3 = 20,11 € (über den gerundeten Einfachsatz 8,74 € wären es 20,10 €).
    check(GoaeVerzeichnis::gebuehr(150, 23000) === 2011 && ServiceCatalog::feeUnits(874, 23000) === 2010, 'Gebühr Nr. 3 falsch');
    check(GoaeVerzeichnis::gebuehr(80, 23000) === 1072 && GoaeVerzeichnis::gebuehr(80, 35000) === 1632, 'Gebühr Nr. 1 falsch');
    check(GoaeVerzeichnis::punkte('3', 874) === 150 && GoaeVerzeichnis::punkte('3', 1072) === 0 && GoaeVerzeichnis::punkte('', 874) === 0, 'Punktzahl-Zuordnung falsch');

    // Speichern: Mit passendem Einfachsatz gilt die genaue Gebühr; Abweichungen über 1 Cent werden abgelehnt.
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-kasse.example.toml'));
    $v['app']['state_dir'] = $state = sys_get_temp_dir() . '/ks-goaev-' . bin2hex(random_bytes(6)); $dirs[] = $state; mkdir($state, 0700);
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['sumup'] = ['mode' => 'mock']; $v['fhir'] = ['mode' => 'mock', 'entry_code' => 'ZAHLUNG'];
    $v['practice'] = ['name' => 'Praxis Beispiel', 'street' => 'Musterstraße 1', 'city' => '12345 Musterstadt'];
    $app = new App(new Config($v)); $app->db->migrate();
    $launch = $app->launch(['context_id' => 'ctx-' . bin2hex(random_bytes(4)), 'oauth_token' => '', 'fhir_base_url' => '']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $x = $app->exchange($f['launch'], null);
    $vis = $app->visit($app->browser($x['cookie']), $x['visit_id']);
    $app->saveService($vis, ['label' => 'Eingehende Beratung', 'price' => '20,11', 'goae_code' => '3', 'factor' => '2,3', 'base' => '8,74']);
    // Fall mit 2 Cent Unterschied zwischen beiden Rechenwegen: Nr. 6018 (300 Punkte) × 3,5 = 61,20 €, über 17,49 € wären es 61,22 €.
    check(GoaeVerzeichnis::gebuehr(300, 35000) === 6120 && ServiceCatalog::feeUnits(1749, 35000) === 6122, 'Gegenprobe 6018');
    $hoch = null;
    foreach ($rows as $r) {
        foreach ([23000, 35000, 25000, 18000, 13000, 11500] as $u) {
            if (abs(GoaeVerzeichnis::gebuehr($r[2], $u) - ServiceCatalog::feeUnits($r[3], $u)) >= 2) { $hoch = [$r, $u]; break 2; }
        }
    }
    if ($hoch !== null) {
        [$r, $u] = $hoch; $fee = GoaeVerzeichnis::gebuehr($r[2], $u);
        $app->saveService($vis, ['label' => 'Genau ' . $r[0], 'price' => number_format($fee / 100, 2, ',', ''), 'goae_code' => $r[0], 'fee_type' => $r[4], 'factor' => ServiceCatalog::text($u), 'base' => number_format($r[3] / 100, 2, ',', '')]);
    }
    try {
        $app->saveService($vis, ['label' => 'Falsch', 'price' => '20,14', 'goae_code' => '3', 'factor' => '2,3', 'base' => '8,74']);
        throw new RuntimeException('Unpassender Preis akzeptiert');
    } catch (Problem $p) { check(str_contains($p->getMessage(), '20,11'), 'Meldung nennt nicht die genaue Gebühr: ' . $p->getMessage()); }
    check((int)$app->db->one("SELECT price_cents FROM services WHERE label='Eingehende Beratung'")['price_cents'] === 2011, 'Leistung nicht gespeichert');

    // Ausgelieferte Datei über den Router: JSON mit eigenem Inhaltstyp.
    check(str_contains(file_get_contents(dirname(__DIR__) . '/public/index.php'), "'json' => 'application/json; charset=utf-8'"), 'Router liefert JSON nicht aus');
    echo "GOÄ-Verzeichnis ok\n";
} finally {
    foreach ($dirs as $dir) if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
}
