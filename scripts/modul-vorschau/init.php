<?php
// Legt erfundene Beispieldaten für die Vorschau von Terminzettel und Signatur an: php scripts/modul-vorschau/init.php  (Ziel $VORSCHAU_DATEN, Standard /tmp/modul-vorschau)
$root = dirname(__DIR__, 2);
$ziel = rtrim(getenv('VORSCHAU_DATEN') ?: '/tmp/modul-vorschau', '/');
foreach (['signatur', 'terminzettel'] as $m) if (!is_dir("$ziel/$m")) mkdir("$ziel/$m", 0755, true);
putenv("KIENZLE_DATEN=$ziel/signatur");
require $root . '/modules/signatur/src/bootstrap.php';
use KienzleSignatur\{Pad, Seite, Speicher};
Speicher::$data = "$ziel/signatur";
$pdo = Speicher::pdo();
if (!(int)$pdo->query('SELECT COUNT(*) FROM pads')->fetchColumn()) {
    // Pad-Software „eingerichtet“ (nur Platzhalterdateien, nicht von signotec).
    $o = "$ziel/signatur/signotec"; if (!is_dir($o)) mkdir($o, 0755, true);
    foreach (Pad::BIBLIOTHEKEN as $b) file_put_contents("$o/$b", str_repeat('x', 2000));
    file_put_contents("$o/info.json", json_encode(['version' => '9.0.1', 'zeit' => time() - 3600, 'von' => 'anna', 'lizenz_akzeptiert' => true]));
    Seite::padSpeichern(['name' => 'Pad Anmeldung', 'arbeitsplatz' => 'Empfang', 'adresse' => '192.0.2.10', 'port' => '1002', 'aktiv' => '1']);
    Seite::padSpeichern(['name' => 'Pad Sprechzimmer 1', 'arbeitsplatz' => 'Sprechzimmer 1', 'adresse' => '192.0.2.11', 'port' => '1002', 'aktiv' => '1']);
    $praxis = ['name' => 'Praxis Dr. Beispiel'];
    $v = Seite::vorlagen(true);
    $id1 = Seite::vorgangAnlegen(['vorlage_id' => $v[0]['id'], 'patient' => 'Max Mustermann', 'leistung' => 'Reisemedizinische Beratung', 'betrag' => '45,00', 'pad_id' => Seite::pads(true)[0]['id']], 'anna', $praxis);
    Seite::vorgangAnlegen(['vorlage_id' => $v[1]['id'], 'patient' => 'Erika Musterfrau', 'leistung' => 'Impfberatung', 'pad_id' => Seite::pads(true)[1]['id']], 'anna', $praxis);
    Seite::amPadZeigen($id1);
}
echo "Daten in $ziel\n";
