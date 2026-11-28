<?php
// Legt erfundene Beispieldaten für die Vorschau von Terminzettel, Signatur, QM, Fax und Telefon an: php scripts/modul-vorschau/init.php  (Ziel $VORSCHAU_DATEN, Standard /tmp/modul-vorschau)
$root = dirname(__DIR__, 2);
$ziel = rtrim(getenv('VORSCHAU_DATEN') ?: '/tmp/modul-vorschau', '/');
foreach (['signatur', 'terminzettel', 'qm', 'fax', 'telefon', 'austausch', 'schluessel'] as $m) if (!is_dir("$ziel/$m")) mkdir("$ziel/$m", 0755, true);
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
// Live-Vorschau des Pads (sonst schreibt sie der Pad-Dienst): Unterschriftsseite mit erfundenem Text und Unterschriftslinie.
if (!is_file("$ziel/signatur/vorschau/1.png") && function_exists('imagecreatetruecolor')) {
    if (!is_dir("$ziel/signatur/vorschau")) mkdir("$ziel/signatur/vorschau", 0700, true);
    $im = imagecreatetruecolor(640, 400); $weiss = imagecolorallocate($im, 255, 255, 255); $grau = imagecolorallocate($im, 90, 90, 90); $blau = imagecolorallocate($im, 20, 50, 140);
    imagefill($im, 0, 0, $weiss);
    foreach (['Patientenaufklaerung (Muster)', '', 'Patient: Max Mustermann', 'Ich habe die Aufklaerung gelesen und verstanden.', 'Datum: ' . date('d.m.Y')] as $i => $z) imagestring($im, $i ? 4 : 5, 30, 30 + $i * 28, $z, $grau);
    imageline($im, 30, 330, 610, 330, $grau); imagestring($im, 3, 30, 340, 'Unterschrift', $grau);
    $x0 = 60; $y0 = 300;
    for ($x = 0; $x < 260; $x += 2) { $y = (int)($y0 - 18 * sin($x / 14) - ($x % 60 < 30 ? $x % 30 : 30 - $x % 30) * 0.6); imagefilledellipse($im, $x0 + $x, $y, 4, 4, $blau); }
    imagepng($im, "$ziel/signatur/vorschau/1.png");
    file_put_contents("$ziel/signatur/vorschau/1.json", json_encode(['seite' => 2, 'seiten' => 2, 'zeit' => time(), 'art' => 'unterschrift', 'punkte' => 412]));
}

// QM: Handbuch mit erfundenen Dokumenten in allen Zuständen
require $root . '/core/php/modul/Kienzle.php';
require $root . '/modules/qm/src/bootstrap.php';
$_SERVER['KIENZLE_PFAD'] = '/qm';
KienzleQm\Speicher::$data = "$ziel/qm";
if (!(int)KienzleQm\Speicher::pdo()->query('SELECT COUNT(*) FROM dokumente')->fetchColumn()) {
    $q = 'KienzleQm\Dokumente';
    $arzt = ['login' => 'anna', 'name' => 'Dr. med. Erika Beispiel'];
    $ben = ['login' => 'ben', 'name' => 'Ben Beispiel'];
    $personen = [['login' => 'carla', 'name' => 'Carla Muster'], ['login' => 'dana', 'name' => 'Dana Beispiel'], ['login' => 'emil', 'name' => 'Emil Muster'], ['login' => 'ben', 'name' => 'Ben Beispiel']];
    // Kleines Bild (Röhrchen in Abnahmereihenfolge, nur Farbbalken), ohne GD als PNG geschrieben
    $farben = [[230, 230, 230], [80, 140, 220], [220, 60, 60], [240, 200, 40], [60, 170, 90], [150, 90, 200]];
    $w = 480; $h = 110; $roh = '';
    for ($y = 0; $y < $h; $y++) { $roh .= "\0"; for ($x = 0; $x < $w; $x++) { $i = intdiv($x, 80); $in = $x % 80 > 18 && $x % 80 < 62 && $y > 10 && $y < 100; $roh .= $in ? pack('C3', ...$farben[$i]) : pack('C3', 246, 249, 248); } }
    $chunk = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress($roh)) . $chunk('IEND', '');
    $bild = $q::bildSpeichern($png);
    $o = array_column($q::ordnerBaum(), 'id', 'name');
    $t = time() - 40 * 86400;
    $a = $q::anlegen(['titel' => 'Händedesinfektion', 'ordner' => $o['2 Hygiene und Medizinprodukte'], 'tags' => 'Hygiene, IfSG, Einarbeitung', 'inhalt' => '<h2>Wann?</h2><ul><li>vor und nach jedem Patientenkontakt</li><li>vor aseptischen Tätigkeiten</li><li>nach Kontakt mit potenziell infektiösem Material</li></ul><h2>Wie?</h2><p>[Mittel und Einwirkzeit laut Hygieneplan der Praxis]</p>'], $ben, '', $t);
    $q::vidieren($a, $arzt, 'EB', $t + 3600);
    $q::vorlegen([$a], ['carla', 'dana', 'emil', 'ben'], array_map(fn($p) => $p + ['gruppen' => []], $personen), date('Y-m-d', $t + 20 * 86400), $arzt, $t + 7200);
    foreach (['carla', 'dana', 'emil', 'ben'] as $l) $q::gelesen($a, (int)$q::dokument($a)['gueltig'], ['login' => $l, 'name' => $l], $t + 9000);
    $b = $q::anlegen(['titel' => 'Blutentnahme und Probenversand', 'ordner' => $o['6 Abläufe (SOP)'], 'tags' => 'Labor, Einarbeitung', 'inhalt' => '<h2>Ziel</h2><p>Proben werden richtig entnommen, beschriftet und bis 16 Uhr an das Labor übergeben.</p><h2>Ablauf</h2><ul class="checkliste"><li class="erledigt">Identität prüfen (Name, Geburtsdatum)</li><li class="erledigt">Röhrchen nach Anforderung beschriften</li><li>Reihenfolge der Röhrchen beachten (Bild)</li></ul><p><img src="' . $bild . '" alt="Röhrchenreihenfolge"></p>'], $ben, '', $t);
    $q::vidieren($b, $arzt, 'EB', $t + 3600);
    $q::inhaltSpeichern($b, '<h2>Ziel</h2><p>Proben werden richtig entnommen, beschriftet und bis 16 Uhr an das Labor übergeben.</p><h2>Ablauf</h2><ul class="checkliste"><li class="erledigt">Identität prüfen (Name, Geburtsdatum)</li><li class="erledigt">Röhrchen nach Anforderung beschriften</li><li>Reihenfolge der Röhrchen beachten (Bild)</li></ul><p><img src="' . $bild . '" alt="Röhrchenreihenfolge"></p><p>Kühlpflichtige Proben bis zur Abholung in den Probenkühlschrank.</p>', 'Kühlung ergänzt', $ben, $t + 86400);
    $q::vidieren($b, $arzt, 'EB', time() - 2 * 86400);
    $q::vorlegen([$b], ['carla', 'dana', 'emil'], array_map(fn($p) => $p + ['gruppen' => []], $personen), date('Y-m-d', time() + 12 * 86400), $arzt, time() - 2 * 86400);
    $q::gelesen($b, (int)$q::dokument($b)['gueltig'], ['login' => 'dana', 'name' => 'Dana Beispiel'], time() - 86400);
    $c = $q::anlegen(['titel' => 'Notfallplan Anaphylaxie', 'ordner' => $o['3 Notfallmanagement'], 'tags' => 'Notfall, Einarbeitung', 'inhalt' => KienzleQm\Gerueste::alle()['notfall']['inhalt']], $arzt, '', $t);
    $q::vidieren($c, $arzt, 'EB', $t);
    $q::vorlegen([$c], ['carla'], array_map(fn($p) => $p + ['gruppen' => []], $personen), date('Y-m-d', time() - 2 * 86400), $arzt, $t);
    $d = $q::anlegen(['titel' => 'Desinfektionsmittelliste', 'ordner' => $o['2 Hygiene und Medizinprodukte'], 'tags' => 'Hygiene', 'pruef_monate' => 12, 'inhalt' => '<table><tr><th>Bereich</th><th>Mittel</th></tr><tr><td>Hände</td><td>[Präparat]</td></tr><tr><td>Flächen</td><td>[Präparat]</td></tr></table>'], $ben, '', $t);
    $q::vidieren($d, $arzt, 'EB', strtotime('-13 months'));
    $e = $q::anlegen(['titel' => 'Flächendesinfektion Behandlungsraum', 'ordner' => $o['2 Hygiene und Medizinprodukte'], 'tags' => 'Hygiene, Jährlich', 'inhalt' => '<p>Täglich nach der Sprechstunde: Liege, Arbeitsflächen, Türgriffe.</p>'], $ben);
    $q::einreichen($e, $ben);
    $q::anlegen(['titel' => 'Aufbereitung Instrumente', 'ordner' => $o['2 Hygiene und Medizinprodukte'], 'tags' => 'Medizinprodukte', 'inhalt' => '<p>[Entwurf]</p>'], $ben);
    foreach (['Aufgaben und Verantwortlichkeiten' => '1 Praxisführung und Organisation', 'Datenschutz am Empfang' => '5 Datenschutz und Informationssicherheit', 'Einarbeitung neuer Mitarbeitender' => '8 Mitarbeitende und Einarbeitung'] as $titel => $ord) {
        $g = array_values(array_filter(KienzleQm\Gerueste::alle(), fn($x) => $x['titel'] === $titel))[0];
        $x = $q::anlegen(['titel' => $titel, 'ordner' => $o[$ord], 'tags' => $g['tags'], 'inhalt' => $g['inhalt']], $arzt, '', $t);
        $q::vidieren($x, $arzt, 'EB', $t + 600);
    }
}
require __DIR__ . '/init-fax-telefon.php';
echo "Daten in $ziel\n";
