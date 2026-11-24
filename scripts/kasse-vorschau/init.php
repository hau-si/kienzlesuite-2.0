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
echo "Daten in $daten\n";
