<?php
declare(strict_types=1);
// Virtueller Drucker „vBriefversand“: Rechnung lesen, Textebene sicherstellen (Texterkennung), sofort per Post senden, unter Vorgänge zeigen. API nachgebildet.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, HttpClient, InvoiceExport, PdfText, Problem, Vorgaenge};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message, int $http = 0): Problem {
    try { $fn(); } catch (Problem $e) { if ($http && $e->http !== $http) throw new RuntimeException($message . ' (HTTP ' . $e->http . ')'); return $e; }
    throw new RuntimeException($message);
}
final class LdFakeHttp extends HttpClient {
    public array $calls = [];
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array {
        check(str_starts_with($url, 'https://api.letterxpress.de/v3/'), 'Unerwarteter Netzwerkaufruf: ' . $url);
        $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
        return ['status' => 200, 'body' => ['status' => 200, 'message' => 'OK', 'data' => ['id' => 4000 + count($this->calls)]]];
    }
}
final class LdFakeExport extends InvoiceExport {
    public array $files = [], $folders = [];
    public bool $fail = false;
    protected function deliver(string $data, string $name, string $folder): void {
        if ($this->fail) throw new Problem('Netzwerkspeicher: Ausgangsordner nicht beschreibbar.', 502);
        $this->files[$name] = $data; $this->folders[$name] = $folder;
    }
}
function values(bool $letter, bool $inbox = false, string $mode = 'test'): array {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir() . '/ks-ld-' . bin2hex(random_bytes(6));
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['invoice_inbox'] = ['enabled' => $inbox, 'key' => str_repeat('c', 64), 'max_age_days' => 14];
    if ($letter) $v['letterxpress'] = ['enabled' => true, 'username' => 'praxis-test', 'api_key' => 'lxp-secret-key', 'mode' => $mode];
    return $v;
}
$dirs = [];
try {
    $text = (string)file_get_contents(__DIR__ . '/fixtures/t2med-beispielrechnung.pdf');
    check(str_starts_with($text, '%PDF-') && PdfText::hasText($text), 'Beispielrechnung hat keine Textebene');

    // 1. Ausgeschaltet: kein Versand. Der Rechnungseingang muss dafür nicht eingeschaltet sein.
    $off = values(false); $dirs[] = $off['app']['state_dir'];
    $appOff = new App(new Config($off), new LdFakeHttp()); $appOff->db->migrate();
    rejects(fn() => $appOff->letterPrint($text), 'Versand bei ausgeschaltetem Letterxpress', 409);

    // 2. Text-PDF: gelesen, in den Vorgängen gespeichert, ein Auftrag an Letterxpress (Testmodus), ohne Texterkennung.
    $v = values(true); $dirs[] = $v['app']['state_dir'];
    $http = new LdFakeHttp(); $app = new App(new Config($v), $http); $app->db->migrate();
    $r = $app->letterPrint($text);
    check($r['ok'] === true && $r['ocr'] === false && str_contains($r['message'], 'Testmodus') && count($http->calls) === 1, 'Postdruck: ' . json_encode($r));
    $sent = base64_decode($http->calls[0]['body']['letter']['base64_file'], true);
    check(is_string($sent) && str_starts_with($sent, '%PDF-') && PdfText::hasText($sent), 'An Letterxpress ging kein PDF mit Text');
    check(!str_contains(json_encode($http->calls[0]['body']['letter']), 'Musterfrau'), 'Patientenname an Letterxpress gesendet');
    $list = (new Vorgaenge($app->db, new Config($v)))->list('jahr', date('Y-m-d'));
    $items = array_values(array_filter($list['items'], fn($i) => $i['number'] === $r['number']));
    check(count($items) === 1 && $items[0]['method'] === 'transfer' && $items[0]['status'] === 'per Letterxpress verschickt (Test)' && $items[0]['post'] === true && $items[0]['art'] === 'T2med', 'Vorgänge zeigen den Postversand nicht: ' . json_encode($items));

    // 3. Live: Der zweite Druck derselben Rechnung verschickt keinen zweiten Brief.
    $app->db->query("UPDATE invoice_inbox SET lxp_mode='live'");
    $e = rejects(fn() => $app->letterPrint($text), 'Zweiter Brief bei live gesendeter Rechnung', 409);
    check(str_contains($e->getMessage(), 'bereits per Post') && count($http->calls) === 1, 'Doppelversand nicht verhindert');
    $list = (new Vorgaenge($app->db, new Config($v)))->list('jahr', date('Y-m-d'));
    $live = array_values(array_filter($list['items'], fn($i) => $i['number'] === $r['number']));
    check(count($live) === 1 && $live[0]['status'] === 'per Letterxpress verschickt', 'Live-Versand falsch angezeigt');

    // 4. Bild-PDF ohne Textebene: Texterkennung ergänzt den Text, Letterxpress bekommt ein PDF mit Text.
    $ocrReady = is_executable('/usr/bin/tesseract') || is_executable('/usr/local/bin/tesseract');
    $image = $ocrReady ? shell_exec('python3 ' . escapeshellarg(__DIR__ . '/make-image-invoice.py') . ' 2>/dev/null') : null;
    if ($ocrReady && is_string($image) && str_starts_with($image, '%PDF-')) {
        check(!PdfText::hasText($image), 'Bild-PDF hat unerwartet Text');
        [$searchable, $did] = PdfText::ensure($image);
        check($did === true && PdfText::hasText($searchable), 'Texterkennung ergänzt keinen Text');
        $v2 = values(true); $dirs[] = $v2['app']['state_dir'];
        $http2 = new LdFakeHttp(); $app2 = new App(new Config($v2), $http2); $app2->db->migrate();
        $r2 = $app2->letterPrint($image);
        $sent2 = base64_decode($http2->calls[0]['body']['letter']['base64_file'], true);
        check($r2['ocr'] === true && is_string($sent2) && PdfText::hasText($sent2) && str_contains($r2['message'], 'Texterkennung'), 'Bild-PDF nicht mit Textebene gesendet');
    } else {
        echo "Texterkennung übersprungen (tesseract fehlt).\n";
    }

    // 5. Versandte Rechnungen bleiben für die Vorgänge stehen; das PDF verschwindet nach der Frist.
    $app->db->query("UPDATE invoice_inbox SET lxp_mode='test', received_at=?", [time() - 30 * 86400]);
    $other = ['number' => 'RE2026-0099', 'invoice_date' => '2026-03-16', 'amount_cents' => 1000, 'patient_name' => 'Max Mustermann', 'patient_birthdate' => '1970-01-01'];
    $app->storeInvoice($other, "%PDF-1.6\nx\n", true);
    $old = $app->db->one("SELECT lxp_status, pdf FROM invoice_inbox WHERE invoice_number=? AND superseded_at IS NULL", [$r['number']]);
    check($old !== null && $old['lxp_status'] === 'sent' && $old['pdf'] === null, 'Versandte Rechnung wurde aufgeräumt oder das PDF blieb liegen');
    // 6. Ablage für die Steuerberatung: nur mit eingeschalteter Option, Dateiname mit _Post, Fehler der Ablage stoppen den Versand nicht.
    foreach ([false, true] as $with) {
        $v3 = values(true, true); $dirs[] = $v3['app']['state_dir'];
        $v3['invoice_export'] = ['enabled' => true, 'ziel' => 'steuerbuero', 'directory' => 'Rechnungen/{jahr}/{monat}', 'when' => 'paid', 'letter' => $with];
        $export = new LdFakeExport(new Config($v3)); $http3 = new LdFakeHttp();
        $app3 = new App(new Config($v3), $http3, null, null, $export); $app3->db->migrate();
        $r3 = $app3->letterPrint($text);
        if (!$with) { check($export->files === [] && $r3['filed'] === null && count($http3->calls) === 1, 'Ablage ohne Option'); continue; }
        $names = array_keys($export->files);
        check(count($names) === 1 && str_ends_with($names[0], '_Post.pdf') && str_contains($names[0], $r3['number']) && $export->folders[$names[0]] === 'Rechnungen/2026/05' && $r3['filed'] === true
            && str_contains($r3['message'], 'Ablage gespeichert') && str_starts_with($export->files[$names[0]], '%PDF-'), 'Ablage der Postrechnung: ' . json_encode($names) . ' ' . $r3['message']);
        $export->fail = true;
        $v4 = values(true); $dirs[] = $v4['app']['state_dir'];
        $v4['invoice_export'] = $v3['invoice_export'];
        $export4 = new LdFakeExport(new Config($v4)); $export4->fail = true; $http4 = new LdFakeHttp();
        $app4 = new App(new Config($v4), $http4, null, null, $export4); $app4->db->migrate();
        $r4 = $app4->letterPrint($text);
        check(count($http4->calls) === 1 && $r4['filed'] === false && str_contains($r4['message'], 'NICHT in der Ablage'), 'Fehler der Ablage stoppt den Versand oder bleibt unbemerkt');
    }
    echo "OK: Drucker vBriefversand: Lesen, Texterkennung, Versand, Schutz vor Doppelversand, Vorgänge.\n";
} finally {
    foreach ($dirs as $dir) { if (is_dir($dir)) { foreach (glob($dir . '/*') as $f) @unlink($f); @rmdir($dir); } }
}
