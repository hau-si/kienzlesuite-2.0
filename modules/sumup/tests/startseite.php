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
$o = $app->openInbox()[0];
check($o['parked'] === false && $o['transfer'] === false && $o['received_at'] > time() - 60, 'Neue Rechnung hat Marken oder falsche Eingangszeit');
// Marken: zurückgestellt und überwiesen bleiben im Eingang, lassen sich setzen und wieder löschen.
$app->inboxTag($open[0]['id'], 'parked', true);
check($app->openInbox()[0]['parked'] === true && count($app->openInbox()) === 1, 'Zurückgestellte Rechnung verschwindet aus dem Eingang');
$app->inboxTag($open[0]['id'], 'parked', false);
check($app->openInbox()[0]['parked'] === false, 'Marke „zurückgestellt“ nicht löschbar');
rejects(fn() => $app->inboxTag($open[0]['id'], 'unsinn', true), 'Unbekannte Marke akzeptiert');
rejects(fn() => $app->inboxTag(str_repeat('0', 32), 'parked', true), 'Unbekannte Rechnung akzeptiert');
// Zweiter Aufruf nutzt denselben offenen Vorgang.
[$iv2] = enter($app, $app->inboxVisit($open[0]['id'])['url']);
check($iv2['id'] === $iv['id'], 'Für dieselbe Rechnung entstand ein zweiter Vorgang');
// „Überweisung“ (später zahlen) markiert die Rechnung als überwiesen; sie bleibt im Eingang.
$app->invoiceLater($iv, ['invoice_id' => $open[0]['id']]);
check($app->openInbox()[0]['transfer'] === true, 'Überweisung wird nicht als Marke gespeichert');
// 3. Bar bezahlen → Tageswerte, Rechnung nicht mehr offen.
$app->startInvoice($iv, ['invoice_id' => $open[0]['id'], 'method' => 'cash', 'request_id' => bin2hex(random_bytes(16))]);
$stats = $app->dayStats();
check($stats['total_cents'] === 4250 && $stats['cash'] === 1 && $stats['card'] === 0, 'Tageswerte nach Barzahlung falsch');
check($app->openInbox() === [], 'Bezahlte Rechnung noch offen');
// 4. Patient aus T2med (FHIR) gemerkt: die passende Rechnung läuft über diesen Vorgang (mit Akte), andere Patienten nicht.
$app->storeInvoice(['number' => 'RE2026-0043', 'invoice_date' => date('Y-m-d'), 'amount_cents' => 1500, 'patient_name' => 'Erika Musterfrau', 'patient_birthdate' => '1980-04-12']);
$app->storeInvoice(['number' => 'RE2026-0044', 'invoice_date' => date('Y-m-d'), 'amount_cents' => 900, 'patient_name' => 'Max Mustermann', 'patient_birthdate' => '1975-01-02']);
$byNumber = []; foreach ($app->openInbox() as $o) $byNumber[$o['number']] = $o;
check($byNumber['RE2026-0043']['t2med'] === false, 'Ohne FHIR-Vorgang als T2med-verbunden markiert');
[$fv, $fb] = enter($app, $app->launch(['context_id' => 'ctx-fhir1', 'oauth_token' => '', 'fhir_base_url' => '']) ['url']);
check($app->state($fv, $fb)['t2med'] === true, 'FHIR-Vorgang nicht als T2med-verbunden erkannt');
$byNumber = []; foreach ($app->openInbox() as $o) $byNumber[$o['number']] = $o;
check($byNumber['RE2026-0043']['t2med'] === true && $byNumber['RE2026-0044']['t2med'] === false, 'T2med-Marke an falscher Rechnung');
[$lv, $lb] = enter($app, $app->inboxVisit($byNumber['RE2026-0043']['id'])['url']);
check($lv['id'] === $fv['id'] && $app->state($lv, $lb)['t2med'] === true, 'Rechnung nutzt nicht den gemerkten FHIR-Vorgang');
[$ov, $ob] = enter($app, $app->inboxVisit($byNumber['RE2026-0044']['id'])['url']);
check($ov['id'] !== $fv['id'] && $app->state($ov, $ob)['t2med'] === false, 'Fremder Patient erhielt den FHIR-Vorgang');
echo "Startseite der Kasse: ok\n";
