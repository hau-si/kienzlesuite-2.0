<?php
declare(strict_types=1);
// Überweisung und Zahlartwechsel: Kontodaten, EPC-QR-Code, offener Vorgang, Bestätigung, Wechsel nach fehlgeschlagener Zahlung.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Bank, Config, HttpClient, InvoiceReceipt, Problem, Qr};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): void {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return; }
    throw new RuntimeException($message);
}
final class NoNetwork extends HttpClient {
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array { throw new RuntimeException('Kein Netzzugriff erwartet: ' . $url); }
}
function config(string $state, bool $bank = true): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $state; $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['sumup'] = ['mode' => 'mock']; $v['fhir'] = ['mode' => 'mock', 'entry_code' => 'ZAHLUNG'];
    $v['practice'] = ['name' => 'Praxis Beispiel', 'street' => 'Musterstraße 1', 'city' => '12345 Musterstadt'];
    if ($bank) $v['bank'] = ['holder' => 'Praxis Beispiel', 'iban' => 'DE02 1203 0000 0000 2020 51', 'bic' => 'BYLADEM1001', 'purpose' => 'Rechnung {nummer}'];
    return new Config($v);
}

$dirs = [];
try {
    // IBAN-Prüfung und EPC-Nutzlast
    check(Bank::validIban('DE02 1203 0000 0000 2020 51') && !Bank::validIban('DE02 1203 0000 0000 2020 52') && !Bank::validIban('XX'), 'IBAN-Prüfung falsch');
    $state = sys_get_temp_dir() . '/ks-transfer-' . bin2hex(random_bytes(6)); $dirs[] = $state; mkdir($state, 0700);
    $bank = new Bank(config($state));
    check($bank->ready(), 'Bank nicht bereit');
    $epc = explode("\n", $bank->epc(1505, 'Rechnung 42'));
    check($epc[0] === 'BCD' && $epc[3] === 'SCT' && $epc[4] === 'BYLADEM1001' && $epc[6] === 'DE02120300000000202051' && $epc[7] === 'EUR15.05' && $epc[10] === 'Rechnung 42', 'EPC falsch: ' . json_encode($epc));
    $svg = Qr::svg($bank->epc(1505, 'Rechnung 42'));
    check(str_starts_with($svg, '<svg') && str_contains($svg, '<path') && !str_contains($svg, 'script'), 'QR-SVG ungültig');
    check(count(Qr::matrix(str_repeat('A', 100))) === 41, 'QR-Version für 100 Bytes unerwartet'); // Version 6 bei Level M
    problem(fn() => Qr::matrix(str_repeat('A', 400)), 'Zu große Nutzlast akzeptiert', 400);
    check(!(new Bank(config($state, false)))->ready(), 'Ohne Kontodaten darf Bank nicht bereit sein');

    $app = new App(config($state), new NoNetwork());
    $app->db->migrate();
    $visit = function () use ($app): array {
        $launch = $app->launch(['context_id' => 'ctx-' . bin2hex(random_bytes(4)), 'oauth_token' => '', 'fhir_base_url' => '']);
        parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $e = $app->exchange($f['launch'], null);
        return $app->visit($app->browser($e['cookie']), $e['visit_id']);
    };
    $v1 = $visit();
    $app->saveService($v1, ['label' => 'Attest', 'price' => '15,00']);
    $ids = $app->db->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN);

    // Überweisung direkt starten: offen, mit QR, ohne Nummer.
    $t = $app->start($v1, ['services' => $ids, 'request_id' => App::id(), 'method' => 'transfer']);
    check($t['method'] === 'transfer' && $t['payment_status'] === 'transfer_open' && $t['invoice_number'] === null, 'Überweisung falsch gestartet: ' . json_encode($t));
    check(($t['transfer']['iban'] ?? '') === 'DE02 1203 0000 0000 2020 51' && str_contains($t['transfer']['qr_svg'], '<svg') && str_starts_with($t['transfer']['purpose'], 'Rechnung UEB-'), 'Überweisungsdaten falsch: ' . json_encode($t['transfer'] ?? null));
    problem(fn() => $app->receipt($v1, $t['id']), 'Beleg vor Zahlungseingang', 409);
    // Wechsel auf bar: die offene Überweisung wird beendet, neue Barzahlung mit denselben Positionen.
    $cash = $app->switchMethod($v1, ['payment_id' => $t['id'], 'request_id' => App::id(), 'method' => 'cash']);
    check($cash['method'] === 'cash' && $cash['payment_status'] === 'successful' && $cash['amount_cents'] === 1500 && str_starts_with((string)$cash['invoice_number'], 'TEST-BAR-'), 'Wechsel auf bar falsch: ' . json_encode($cash));
    check($app->db->one('SELECT payment_status FROM payments WHERE id=?', [$t['id']])['payment_status'] === 'cancelled', 'Offene Überweisung nicht beendet');
    problem(fn() => $app->switchMethod($v1, ['payment_id' => $cash['id'], 'request_id' => App::id(), 'method' => 'transfer']), 'Wechsel nach erfolgreicher Zahlung erlaubt', 409);

    // Fehlgeschlagene Kartenzahlung → Überweisung → Eingang bestätigen → Beleg.
    $v2 = $visit();
    $card = $app->start($v2, ['services' => $ids, 'request_id' => App::id()]);
    problem(fn() => $app->switchMethod($v2, ['payment_id' => $card['id'], 'request_id' => App::id(), 'method' => 'cash']), 'Wechsel bei laufender Kartenzahlung erlaubt', 409);
    $app->db->query("UPDATE payments SET payment_status='failed' WHERE id=?", [$card['id']]);
    problem(fn() => $app->switchMethod($v2, ['payment_id' => $card['id'], 'request_id' => App::id(), 'method' => 'scheck']), 'Unbekannte Zahlart akzeptiert', 400);
    $request = App::id();
    $open = $app->switchMethod($v2, ['payment_id' => $card['id'], 'request_id' => $request, 'method' => 'transfer']);
    check($open['payment_status'] === 'transfer_open' && $open['amount_cents'] === 1500, 'Wechsel auf Überweisung falsch');
    check($app->switchMethod($v2, ['payment_id' => $card['id'], 'request_id' => $request, 'method' => 'transfer'])['id'] === $open['id'], 'Wechsel nicht idempotent');
    $done = $app->confirmTransfer($v2, $open['id']);
    check($done['payment_status'] === 'successful' && $done['invoice_number'] === 'TEST-UEB-' . date('Y') . '-0001', 'Bestätigung falsch: ' . json_encode($done));
    problem(fn() => $app->confirmTransfer($v2, $open['id']), 'Zweite Bestätigung akzeptiert', 409);
    check(str_starts_with($app->receipt($v2, $open['id']), '%PDF'), 'Kein PDF für Überweisung');
    $row = $app->db->one('SELECT * FROM payments WHERE id=?', [$open['id']]);
    $invoice = json_decode($row['invoice_json'], true); $invoice['number'] = $row['transaction_id']; $invoice['issued_date'] = date('Y-m-d');
    $text = html_entity_decode(strip_tags(str_replace('</text>', "\n", InvoiceReceipt::source($invoice, ['status' => 'successful', 'paid_date' => 'heute', 'reference' => $row['reference'], 'transaction_id' => $row['transaction_id'], 'mock' => true, 'method' => 'transfer']))), ENT_XML1, 'UTF-8');
    check(str_contains($text, 'Überwiesen') && str_contains($text, 'Überweisung') && str_contains($text, 'erhalten') && !str_contains($text, 'SumUp'), 'Überweisungsbeleg falsch: ' . $text);

    // Ohne Kontodaten keine Überweisung.
    $s2 = sys_get_temp_dir() . '/ks-transfer-' . bin2hex(random_bytes(6)); $dirs[] = $s2; mkdir($s2, 0700);
    $plain = new App(config($s2, false), new NoNetwork()); $plain->db->migrate();
    $launch = $plain->launch(['context_id' => 'ctx-x1', 'oauth_token' => '', 'fhir_base_url' => '']);
    parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $e = $plain->exchange($f['launch'], null);
    $pv = $plain->visit($plain->browser($e['cookie']), $e['visit_id']);
    $plain->saveService($pv, ['label' => 'Attest', 'price' => '15,00']);
    problem(fn() => $plain->start($pv, ['services' => $plain->db->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN), 'request_id' => App::id(), 'method' => 'transfer']), 'Überweisung ohne Kontodaten', 409);
    echo "Überweisung: Tests bestanden\n";
} finally {
    foreach ($dirs as $d) exec('rm -rf ' . escapeshellarg($d));
}
