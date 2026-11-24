<?php
declare(strict_types=1);
// Startseite der Kasse: freie Eingabe ohne Patientenakte, Vorgang für eine Rechnung aus dem Eingang, Tageswerte, offene Rechnungen.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, Problem};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (Problem) { return; }
    throw new RuntimeException($message);
}
$v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
$v['app']['state_dir'] = sys_get_temp_dir() . '/ks-start-' . bin2hex(random_bytes(6));
$v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
$v['invoice_inbox'] = ['enabled' => true, 'key' => str_repeat('c', 64), 'max_age_days' => 14];
$app = new App(new Config($v)); $app->db->migrate();
function enter(App $app, string $url): array {
    parse_str((string)parse_url($url, PHP_URL_FRAGMENT), $f);
    $x = $app->exchange($f['launch'], null); $browser = $app->browser($x['cookie']);
    return [$app->visit($browser, $x['visit_id']), $browser];
}
// 1. Freie Eingabe: eigener Vorgang ohne Patientenakte.
[$visit, $browser] = enter($app, $app->manualOpen()['url']);
check(str_starts_with((string)$visit['context_id'], 'manual-'), 'Freie Eingabe ist kein manueller Vorgang');
check($app->state($visit, $browser)['manual'] === true, 'Zustand kennt die freie Eingabe nicht');
check($app->dayStats() === ['total_cents' => 0, 'card' => 0, 'cash' => 0], 'Tageswerte am Anfang nicht leer');
// 2. Offene Rechnung: Vorgang mit Patient der Rechnung; unbekannte Nummern werden abgelehnt.
check($app->openInbox() === [], 'Eingang nicht leer');
$stored = $app->storeInvoice(['number' => 'RE2026-0042', 'invoice_date' => date('Y-m-d'), 'amount_cents' => 4250, 'patient_name' => 'Erika Musterfrau', 'patient_birthdate' => '1980-04-12']);
$open = $app->openInbox();
check(count($open) === 1 && isset($open[0]['id']) && $open[0]['patient'] === 'Erika Musterfrau', 'Offene Rechnung fehlt in der Liste');
rejects(fn() => $app->inboxVisit('nicht-hex'), 'Ungültige Rechnungsnummer akzeptiert');
rejects(fn() => $app->inboxVisit(str_repeat('0', 32)), 'Unbekannte Rechnung akzeptiert');
[$iv, $ib] = enter($app, $app->inboxVisit($open[0]['id'])['url']);
check($iv['patient_name'] === 'Erika Musterfrau' && $iv['patient_birthdate'] === '1980-04-12', 'Vorgang ohne Patient der Rechnung');
$invoices = $app->state($iv, $ib)['invoices'];
check(count($invoices) === 1 && $invoices[0]['id'] === $open[0]['id'] && $invoices[0]['amount_cents'] === 4250, 'Rechnung wird im Vorgang nicht angeboten');
// Zweiter Aufruf nutzt denselben offenen Vorgang.
[$iv2] = enter($app, $app->inboxVisit($open[0]['id'])['url']);
check($iv2['id'] === $iv['id'], 'Für dieselbe Rechnung entstand ein zweiter Vorgang');
// 3. Bar bezahlen → Tageswerte, Rechnung nicht mehr offen.
$app->startInvoice($iv, ['invoice_id' => $open[0]['id'], 'method' => 'cash', 'request_id' => bin2hex(random_bytes(16))]);
$stats = $app->dayStats();
check($stats['total_cents'] === 4250 && $stats['cash'] === 1 && $stats['card'] === 0, 'Tageswerte nach Barzahlung falsch');
check($app->openInbox() === [], 'Bezahlte Rechnung noch offen');
echo "Startseite der Kasse: ok\n";
