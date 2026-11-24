<?php
// Legt die Grundwerte für die Kasse-Vorschau an (erfundene Daten): php scripts/kasse-vorschau/init.php
require dirname(__DIR__, 2) . '/modules/sumup/src/bootstrap.php';
$daten = getenv('VORSCHAU_DATEN') ?: '/tmp/kasse-vorschau';
if (!is_dir($daten)) mkdir($daten, 0755, true);
\KienzleSumup\Suite::$data = $daten;
\KienzleSumup\Suite::$helper = dirname(__DIR__, 2) . '/core/php/modul/Kienzle.php';
$_SERVER += ['KIENZLE_BENUTZER' => 'anna', 'KIENZLE_ROLLE' => 'admin', 'KIENZLE_RECHTE' => '', 'KIENZLE_CSRF' => 'x', 'KIENZLE_PFAD' => '/kasse'];
\KienzleSumup\Suite::ensureGrundwerte();
ks_app()->db->migrate();   // im Betrieb legt das Installationsprogramm die Tabellen an
// Beispieldaten für die Bildschirmfotos: Leistungen und drei Rechnungen im Eingang (alles erfunden).
$app = ks_app(); $catalog = new \KienzleSumup\Catalog($app->db, $app->config);
if (!$app->db->one('SELECT 1 AS x FROM services LIMIT 1')) {
    foreach ([['Beratung, auch telefonisch', '10,72', '1'], ['Untersuchung, Organsystem', '21,45', '7'], ['Befundbericht, ausführlich', '17,43', '75'], ['Sehtest', '7,91', '1200'], ['Farbsinnprüfung', '8,18', '1228'], ['Hörprüfung', '10,19', '1400'], ['Attest', '15,00', '']] as [$label, $price, $code]) {
        $catalog->saveService(['label' => $label, 'price' => $price, 'goae_code' => $code, 'factor' => '2,3', 'fee_type' => 'standard']);
    }
}
if (!$app->db->one('SELECT 1 AS x FROM invoice_inbox LIMIT 1')) {
    \KienzleSumup\Settings::save($app->db, ks_base_config(), 'invoice_inbox', ['enabled' => true, 'max_age_days' => 14]);
    $now = time();
    foreach ([['IG2026-0003', 'Mustermann, Max', '1990-01-01', 7588, $now - 180, null, null], ['IG2026-0002', 'Musterfrau, Erika', '1975-06-12', 4250, $now - 7200, null, null],
              ['IG2026-0001', 'Beispiel, Hans', '1962-03-30', 3120, $now - 86400, $now - 80000, null], ['IG2025-0099', 'Probe, Lisa', '2001-11-05', 1863, $now - 172800, null, $now - 170000]] as [$n, $name, $birth, $cents, $at, $parked, $transfer]) {
        $app->db->query('INSERT INTO invoice_inbox (id, invoice_number, invoice_date, amount_cents, patient_name, patient_birthdate, received_at, parked_at, transfer_at) VALUES (?,?,?,?,?,?,?,?,?)', [bin2hex(random_bytes(16)), $n, date('Y-m-d', $at), $cents, $name, $birth, $at, $parked, $transfer]);
    }
}
echo "Daten in $daten\n";
