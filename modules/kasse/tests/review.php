<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleKasse\{App, Config, InboxLog, InvoiceReview, Problem, T2medInvoice};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (Problem $e) { return; }
    throw new RuntimeException($message);
}
$tools = is_executable('/usr/bin/pdftotext') || is_executable('/usr/local/bin/pdftotext');
$dir = sys_get_temp_dir() . '/ks-review-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);

// --- Gemerkte PDFs ---------------------------------------------------------------------------------------------------------------
$log = new InboxLog($dir);
$id = $log->add('Rechnungsnummer der Rechnung nicht gefunden', 'Textebene', ['Re.-Datum: TT.MM.JJJJ'], "%PDF-1.4\nTEST");
check(preg_match('/^[a-f0-9]{32}$/D', $id) === 1, 'Kennung');
$file = $log->pdfPath($id);
check($file !== null && (fileperms($file) & 0777) === 0600 && (fileperms($dir . '/rejected') & 0777) === 0700, 'PDF-Rechte');
check($log->recent()[0]['pdf'] === true && $log->recent()[0]['id'] === $id, 'Eintrag mit PDF');
check($log->pdfPath('../../etc/passwd') === null && $log->pdfPath(str_repeat('g', 32)) === null, 'Ungültige Kennung');
$none = $log->add('ohne PDF', '', []);
check($log->recent()[0]['pdf'] === false && $log->pdfPath($none) === null, 'Eintrag ohne PDF');
// Älter als sieben Tage: Eintrag und PDF verschwinden.
$data = json_decode((string)file_get_contents($log->path()), true);
foreach ($data as &$e) if ($e['id'] === $id) $e['at'] = time() - 8 * 86400;
unset($e);
file_put_contents($log->path(), json_encode($data));
check(count($log->recent()) === 1 && $log->pdfPath($id) === null, 'Alte PDF nicht gelöscht');
$id2 = $log->add('x', '', [], "%PDF-1.4\nTEST");
$log->remove($id2);
check($log->pdfPath($id2) === null && !in_array($id2, array_column($log->recent(), 'id'), true), 'Entfernen');
$id3 = $log->add('y', '', [], "%PDF-1.4\nTEST");
$log->clear();
check($log->pdfPath($id3) === null && $log->recent() === [], 'Leeren löscht auch PDFs');

// --- Prüfung der Eingabe -------------------------------------------------------------------------------------------------------
$ok = ['number' => 'RE2026-0042', 'date' => '15.03.2026', 'total' => '1.042,50 €', 'name' => 'Dr. Erika Musterfrau', 'birth' => '12.04.1980'];
$v = InvoiceReview::validate($ok, 100000000);
check($v === ['number' => 'RE2026-0042', 'invoice_date' => '2026-03-15', 'amount_cents' => 104250, 'patient_name' => 'Dr. Erika Musterfrau', 'patient_birthdate' => '1980-04-12'], 'Eingabe falsch übernommen');
foreach ([['84', 8400], ['84,5', 8450], ['84.50', 8450], ['84,00', 8400], ['1.084,50', 108450]] as [$in, $cents]) check(InvoiceReview::money($in) === $cents, "Betrag $in");
foreach ([['number' => 'ohne Ziffer'], ['number' => ''], ['date' => '31.02.2026'], ['date' => '2026-03-15'], ['total' => '0,00'], ['total' => 'abc'], ['total' => '999999999,00'], ['name' => ''], ['name' => 'Erika <b>'], ['birth' => '12.04.80']] as $bad) {
    rejects(fn() => InvoiceReview::validate($bad + $ok, 100000), 'Ungültige Eingabe akzeptiert: ' . json_encode($bad));
}
check(InvoiceReview::validate(['number' => 'IG 2026-309'] + $ok, 10000000, ['IG'])['number'] === 'IG2026-309', 'Leerzeichen in der Nummer');

// --- Analyse mit Ausschnitten (Textebene) --------------------------------------------------------------------------------------
if (!$tools || !is_executable('/usr/bin/pdftoppm') && !is_executable('/usr/local/bin/pdftoppm')) { echo "Analyse übersprungen (poppler fehlt).\n"; echo "OK: Nachbearbeitung abgelehnter Rechnungen.\n"; exit; }
$pdf = shell_exec('python3 ' . escapeshellarg(__DIR__ . '/make-review-invoice.py') . ' 2>/dev/null');
if (!is_string($pdf) || !str_starts_with($pdf, '%PDF-')) { echo "Analyse übersprungen (reportlab fehlt).\n"; echo "OK: Nachbearbeitung abgelehnter Rechnungen.\n"; exit; }
rejects(fn() => T2medInvoice::fromPdf($pdf, 100000), 'Unbekannte Beschriftung der Nummer wurde erkannt');
$file = $dir . '/rechnung.pdf';
file_put_contents($file, $pdf);
$r = InvoiceReview::analyze($file, []);
check($r['source'] === 'Textebene' && $r['pages'] >= 1, 'Quelle');
check($r['fields']['number']['status'] === 'fehlt' && $r['fields']['number']['snippets'] === [], 'Nummer sollte fehlen');
check($r['fields']['date']['status'] === 'gelesen' && $r['fields']['date']['guess'] === '15.03.2026', 'Datum');
check($r['fields']['total']['status'] === 'gelesen' && $r['fields']['total']['guess'] === '42,50', 'Betrag (Gesamtsumme und Zahlbetrag gleich)');
check($r['fields']['patient']['guess'] === 'Erika Musterfrau | 12.04.1980', 'Patient');
foreach (['date', 'total', 'patient'] as $k) {
    check(count($r['fields'][$k]['snippets']) >= 1 && str_starts_with($r['fields'][$k]['snippets'][0], 'data:image/png;base64,'), "Ausschnitt $k");
    $png = base64_decode(substr($r['fields'][$k]['snippets'][0], 22), true);
    $size = getimagesizefromstring((string)$png);
    check($size !== false && $size[0] > 100 && $size[1] > 10 && $size[1] <= 260, "Ausschnitt $k kein Bild");
}
// Mit dem eigenen Textschnipsel wird die Nummer gefunden, samt Ausschnitt.
$r2 = InvoiceReview::analyze($file, ['label_number' => ['Beleg-Nr.']]);
check($r2['fields']['number']['status'] === 'gelesen' && $r2['fields']['number']['guess'] === 'RE2026-0042' && count($r2['fields']['number']['snippets']) === 1, 'Nummer mit eigener Beschriftung');
check(str_starts_with(InvoiceReview::page($file, 1), 'data:image/png;base64,'), 'Seitenansicht');
rejects(fn() => InvoiceReview::page($file, 9), 'Seite 9 akzeptiert');
rejects(fn() => InvoiceReview::analyze($dir . '/fehlt.pdf', []), 'Fehlende Datei');

// --- Bild-PDF (Texterkennung) ---------------------------------------------------------------------------------------------------
if ((is_executable('/usr/bin/tesseract') || is_executable('/usr/local/bin/tesseract'))) {
    $image = shell_exec('python3 ' . escapeshellarg(__DIR__ . '/make-image-invoice.py') . ' 2>/dev/null');
    if (is_string($image) && str_starts_with($image, '%PDF-')) {
        file_put_contents($dir . '/bild.pdf', $image);
        $o = InvoiceReview::analyze($dir . '/bild.pdf', []);
        check($o['source'] === 'Texterkennung' && $o['fields']['number']['guess'] === 'RE2026-0042' && $o['fields']['total']['guess'] === '42,50'
            && $o['fields']['patient']['guess'] === 'Erika Musterfrau | 12.04.1980' && count($o['fields']['number']['snippets']) === 1, 'Bild-PDF: Ausschnitte und Vorschläge');
        echo "OCR-Analyse ausgeführt.\n";
    }
}

// --- Ganzer Weg: abgelehnt → gemerkt → von Hand übernommen -----------------------------------------------------------------
$values = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-kasse.example.toml'));
$values['app']['state_dir'] = $dir . '/state'; mkdir($values['app']['state_dir'], 0700);
$values['app']['launcher_key'] = str_repeat('a', 64); $values['app']['encryption_key'] = str_repeat('b', 64);
$values['invoice_inbox'] = ['enabled' => true, 'key' => str_repeat('c', 64), 'max_age_days' => 14];
$app = new App(new Config($values)); $app->db->migrate();
rejects(fn() => $app->inboxReceive($pdf), 'Abgelehnte Rechnung angenommen');
$entry = (new InboxLog($values['app']['state_dir']))->recent()[0];
check($entry['pdf'] === true && $entry['lines'] !== [], 'Abgelehnte Rechnung nicht gemerkt');
$stored = $app->storeInvoice(InvoiceReview::validate(['number' => 'RE2026-0042', 'date' => '15.03.2026', 'total' => '42,50', 'name' => 'Erika Musterfrau', 'birth' => '12.04.1980'], 100000), $pdf);
check($stored['ok'] === true && $stored['amount_cents'] === 4250 && str_contains($stored['message'], 'liegt zum Kassieren bereit'), 'Rechnung nicht übernommen');
echo "OK: Nachbearbeitung abgelehnter Rechnungen.\n";
