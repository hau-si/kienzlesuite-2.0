<?php
// Erfundene Beispieldaten für die Vorschau von Fax und Telefon (aus scripts/modul-vorschau/init.php, $ziel und $root sind gesetzt).
// Nur Platzhalter: Nummern aus dem Bereich 030 123…, Namen „Mustermann“/„Beispiel“, Leitung *.example.invalid, keine echten Anbieterdaten.

// Ein Brief als Bild (GD) → PDF über Fax::alsPdf, damit Vorschau und Seitenzahl echte Seiten haben.
function vorschau_brief(string $kopf, array $zeilen): string {
    $im = imagecreatetruecolor(1240, 1754);
    $weiss = imagecolorallocate($im, 255, 255, 255); $schwarz = imagecolorallocate($im, 30, 30, 30); $grau = imagecolorallocate($im, 120, 120, 120);
    imagefill($im, 0, 0, $weiss);
    // GD-Schriften sind klein: Text in eigenes Bild schreiben und vergrößert einsetzen.
    $text = static function (string $t, int $x, int $y, int $faktor, int $farbe) use ($im): void {
        $w = imagefontwidth(5) * strlen($t); $h = imagefontheight(5);
        if ($w < 1) return;
        $klein = imagecreatetruecolor($w, $h); imagefill($klein, 0, 0, imagecolorallocate($klein, 255, 255, 255));
        $rgb = imagecolorsforindex($im, $farbe);
        imagestring($klein, 5, 0, 0, $t, imagecolorallocate($klein, $rgb['red'], $rgb['green'], $rgb['blue']));
        imagecopyresampled($im, $klein, $x, $y, 0, 0, $w * $faktor, $h * $faktor, $w, $h);
    };
    $text($kopf, 110, 130, 4, $schwarz);
    imageline($im, 110, 230, 1130, 230, $grau);
    foreach ($zeilen as $i => $z) $text($z, 110, 290 + $i * 70, 3, $i === 0 ? $schwarz : $grau);
    ob_start(); imagepng($im); $png = (string)ob_get_clean();
    return KienzleFax\Fax::alsPdf($png, 'brief.png');
}

// ---- Fax ------------------------------------------------------------------------------------------------------
putenv("KIENZLE_DATEN=$ziel/fax");
putenv("KIENZLE_AUSTAUSCH=$ziel/austausch");
require $root . '/modules/fax/src/bootstrap.php';
use KienzleFax\Fax;
Fax::$data = "$ziel/fax"; Fax::$austausch = "$ziel/austausch";
if (!Fax::kontakte() && function_exists('imagecreatetruecolor')) {
    file_put_contents("$ziel/fax/einstellungen.json", json_encode(['drucker' => [['name' => 'vFax1'], ['name' => 'vFaxLabor', 'bezeichnung' => 'Labor', 'nummer' => '030 1234570']]]));
    Fax::kontaktSpeichern(0, 'Labor Beispiel', '030 1234570', 'Probenversand', true);
    Fax::kontaktSpeichern(0, 'Klinik Musterstadt, Aufnahme', '030 1234571', '', true);
    Fax::kontaktSpeichern(0, 'Apotheke am Markt', '030 1234572', '', false);
    Fax::kontaktSpeichern(0, 'Dr. med. Max Beispiel (Kardiologie)', '030 1234573', 'Befunde', false);
    Fax::kontaktSpeichern(0, 'Pflegedienst Muster', '030 1234574', '', false);
    file_put_contents("$ziel/fax/zuletzt.json", json_encode([['name' => 'Klinik Musterstadt, Aufnahme', 'nummer' => '0301234571'], ['name' => 'Apotheke am Markt', 'nummer' => '0301234572']], JSON_UNESCAPED_UNICODE));
    $t = time();
    Fax::druckAblegen('vFax1', vorschau_brief('Ueberweisung', ['Patient: Max Mustermann, geb. 01.04.1970', 'An: Kardiologie', 'Fragestellung: Belastungs-EKG', 'Dr. med. Erika Beispiel']), 'Überweisung Max Mustermann', $t - 300);
    Fax::druckAblegen('vFax1', vorschau_brief('Medikationsplan', ['Patientin: Erika Musterfrau', 'Ibuprofen 400 mg  1-0-1', 'Pantoprazol 20 mg  1-0-0']), 'Medikationsplan Erika Musterfrau', $t - 1800);
    Fax::druckAblegen('vFaxLabor', vorschau_brief('Laborauftrag', ['Patient: Max Mustermann', 'Kleines Blutbild, CRP', 'Abholung bis 16 Uhr']), 'Laborauftrag', $t - 5400);
    // Empfangene Faxe (Name: Zeit_Kennung_Absendernummer)
    $ein = Fax::ordner('eingang');
    foreach ([[$t - 900, '0301234570', 'Laborbefund', ['Befund: Max Mustermann', 'Kleines Blutbild unauffaellig', 'CRP < 5 mg/l']],
        [$t - 7200, '0301234571', 'Entlassbrief', ['Entlassbrief: Erika Musterfrau', 'Aufenthalt vom 20. bis 25.11.2026', 'Weiterbehandlung durch Hausarztpraxis']],
        [$t - 86400 - 3000, '0301234572', 'Rueckfrage', ['Rueckfrage zur Verordnung', 'Bitte Dosierung bestaetigen', 'Apotheke am Markt']]] as $i => [$zeit, $nr, $kopf, $zeilen]) {
        $name = date('Ymd-His', $zeit) . '_rx' . $i . '_' . $nr;
        file_put_contents("$ein/$name.pdf", vorschau_brief($kopf, $zeilen));
        file_put_contents("$ein/$name.json", json_encode(['absender' => $nr, 'empfangen' => date('c', $zeit), 'seiten' => 1, 'status' => 'SUCCESS']));
        file_put_contents("$ein/$name.txt", implode("\n", [$kopf, ...$zeilen]));
    }
    file_put_contents("$ziel/fax/gelesen.json", json_encode([date('Ymd-His', $t - 86400 - 3000) . '_rx2_0301234572' => $t]));
    // Sendeprotokoll (Format des Fax-Workers)
    $prot = Fax::ordner('protokoll');
    foreach ([[$t - 3600, 'Labor Beispiel', '0301234570', 'Laborauftrag.pdf', 2], [$t - 2 * 3600, 'Klinik Musterstadt, Aufnahme', '0301234571', 'Ueberweisung.pdf', 1]] as $i => [$zeit, $n, $nr, $datei, $s]) {
        file_put_contents("$prot/" . date('Ymd-His', $zeit) . "_JOB-$i.json", json_encode(['job_id' => 'JOB-' . date('Ymd-His', $zeit) . '-abc00' . $i, 'finalized_at' => date('c', $zeit),
            'recipient' => ['name' => $n, 'number' => $nr], 'source' => ['filename_original' => $datei], 'result' => ['pages' => $s, 'reason' => ''], 'attempt' => ['current' => 1]], JSON_UNESCAPED_UNICODE));
    }
}

// ---- Telefon ----------------------------------------------------------------------------------------------------
if (!is_file("$ziel/schluessel/telefon.key")) file_put_contents("$ziel/schluessel/telefon.key", bin2hex(random_bytes(32)));
putenv("KIENZLE_DATEN=$ziel/telefon");
putenv('KIENZLE_ASTERISK=/bin/false');
putenv('KIENZLE_PROGRAMM=' . $root . '/modules/telefon');
require $root . '/modules/telefon/src/bootstrap.php';
use KienzleTelefon\{Anlage, Vorgaenge};
Anlage::$data = "$ziel/telefon";
$tk = new KienzleModul('telefon', ['KIENZLE_BENUTZER' => 'anna'], "$ziel/verbindungen", "$ziel/schluessel");
if (!is_file("$ziel/telefon/anlage.json")) {
    $cfg = Anlage::laden();
    $cfg = Anlage::leitungSpeichern($cfg, ['anbieter' => '1und1', 'name' => 'Hauptnummer', 'benutzer' => '491234560', 'passwort' => 'Platzhalter-Passwort'], $tk);
    $cfg = Anlage::netzSpeichern($cfg, ['lokal' => '192.0.2.0/24']);
    $cfg = Anlage::nebenstellenAnlegen($cfg, 4, $tk);
    foreach (['201' => 'Empfang', '202' => 'Empfang 2', '203' => 'Sprechzimmer 1', '204' => 'Labor'] as $nr => $n) $cfg = Anlage::nebenstelleSpeichern($cfg, ['nummer' => $nr, 'name' => $n, 'warteschlange' => $nr <= '202' ? '1' : '']);
    try { Anlage::speichern($cfg, $tk); } catch (Throwable $e) {
        // Ohne Asterisk scheitert nur das Anwenden; die Einstellungen sind dann trotzdem gespeichert.
        if (!is_file("$ziel/telefon/anlage.json")) file_put_contents("$ziel/telefon/anlage.json", json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    // Aufgesprochene Anliegen: verschlüsselt wie vom Whisper-Worker (kienzlefon.crypto), erfundene Anrufer.
    Vorgaenge::schluesselSicherstellen($tk);
    $heute = date('Ymd');
    $v = [['rezeptbestellung', '0301234580', ['vorname' => 'Max', 'nachname' => 'Mustermann', 'geburtsdatum' => '1. April 1970', 'medikamente' => "Ibuprofen 400\nPantoprazol 20"], '081200'],
        ['termin', '01701234567', ['vorname' => 'Erika', 'nachname' => 'Musterfrau', 'geburtsdatum' => '12. Mai 1985', 'grund' => 'Kontrolle Blutdruck, gern vormittags'], '084500'],
        ['ueb_req', '0301234581', ['vorname' => 'Tom', 'nachname' => 'Beispiel', 'geburtsdatum' => '3. März 1962', 'fachrichtung' => 'Orthopädie', 'grund' => 'Knieschmerzen rechts'], '091400'],
        ['rueckruf_details', '01511234567', ['vorname' => 'Anja', 'nachname' => 'Muster', 'grund' => 'Frage zum Laborbefund'], '093000']];
    $py = 'import sys, json; from pathlib import Path; from zoneinfo import ZoneInfo; sys.path.insert(0, sys.argv[1]);'
        . ' from kienzlefon.crypto import TelepraxisEncryptor;'
        . ' e = TelepraxisEncryptor(Path(sys.argv[2]), Path(sys.argv[3]), ZoneInfo("Europe/Berlin"));'
        . ' [e.write_payload(p, p["_kienzlefon"]["vorgang_id"]) for p in json.loads(sys.argv[4])]';
    $nutz = [];
    foreach ($v as $i => [$typ, $tel, $daten, $uhr]) {
        $id = $heute . '_' . $uhr . '_00000' . ($i + 1);
        $nutz[] = ['typ' => $typ, 'id' => $tel, 'telefon' => $tel] + $daten + ['_kienzlefon' => ['vorgang_id' => $id, 'erstellt_am' => date('Y-m-d\T', time()) . substr($uhr, 0, 2) . ':' . substr($uhr, 2, 2) . ':00+01:00']];
    }
    exec(implode(' ', array_map('escapeshellarg', ['python3', '-c', $py, $root . '/modules/telefon/kienzlefon', "$ziel/telefon/kienzlefon/telepraxis-public.pem",
        "$ziel/telefon/kienzlefon/vorgaenge", json_encode($nutz, JSON_UNESCAPED_UNICODE)])) . ' 2>&1', $aus, $rc);
    if ($rc !== 0) fwrite(STDERR, "Vorgänge nicht angelegt: " . implode("\n", $aus) . "\n");
    else { Vorgaenge::uebernehmen($tk, $heute . '_091400_000003'); Vorgaenge::dringend($heute . '_084500_000002', true); }
    // Anrufstatistik (gleiches Schema wie lib/telefon-ereignisse): zufällige, aber feste Anrufe der letzten 30 Tage
    if (!is_dir("$ziel/telefon/statistik")) mkdir("$ziel/telefon/statistik", 0700, true);
    $db = new PDO('sqlite:' . "$ziel/telefon/statistik/statistik.sqlite");
    $db->exec('CREATE TABLE anrufe (id TEXT PRIMARY KEY, zeit INTEGER NOT NULL, tag TEXT NOT NULL, stunde INTEGER NOT NULL, wochentag INTEGER NOT NULL);
        CREATE TABLE routen (id TEXT NOT NULL, route TEXT NOT NULL, PRIMARY KEY (id, route));
        CREATE TABLE warten (id TEXT PRIMARY KEY, zeit INTEGER NOT NULL, tag TEXT NOT NULL, ergebnis TEXT NOT NULL, sekunden INTEGER NOT NULL);');
    mt_srand(7);
    $routen = ['prescription' => 30, 'appointment' => 25, 'queue' => 20, 'referral' => 10, 'callback' => 8, 'opening_hours' => 7];
    $db->beginTransaction();
    for ($tag = 29; $tag >= 0; $tag--) {
        $basis = strtotime('today 00:00') - $tag * 86400;
        $wt = (int)date('N', $basis);
        if ($wt > 5) continue;
        $n = [1 => 70, 2 => 55, 3 => 40, 4 => 55, 5 => 45][$wt] + mt_rand(-10, 10);
        if ($tag === 0) $n = intdiv($n, 2);
        for ($i = 0; $i < $n; $i++) {
            $stunde = [8, 8, 8, 9, 9, 9, 10, 10, 11, 11, 12, 14, 15, 16, 17][mt_rand(0, 14)];
            $zeit = $basis + $stunde * 3600 + mt_rand(0, 3599);
            $id = "a$tag-$i";
            $db->prepare('INSERT INTO anrufe VALUES (?, ?, ?, ?, ?)')->execute([$id, $zeit, date('Y-m-d', $zeit), $stunde, $wt]);
            $r = mt_rand(1, 100); $summe = 0;
            foreach ($routen as $route => $anteil) { $summe += $anteil; if ($r <= $summe) break; }
            $db->prepare('INSERT INTO routen VALUES (?, ?)')->execute([$id, $route]);
            if ($route === 'queue') $db->prepare('INSERT INTO warten VALUES (?, ?, ?, ?, ?)')->execute([$id, $zeit, date('Y-m-d', $zeit), mt_rand(1, 10) > 1 ? 'angenommen' : 'aufgelegt', mt_rand(8, 140)]);
        }
    }
    $db->commit();
}
