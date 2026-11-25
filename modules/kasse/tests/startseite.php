<?php
declare(strict_types=1);
// Startseite der Kasse: freie Eingabe ohne Patientenakte, Vorgang für eine Rechnung aus dem Eingang, Tageswerte, offene Rechnungen.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleKasse\{App, Config, DocNumbers, Problem, Vorgaenge};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (Problem) { return; }
    throw new RuntimeException($message);
}
$v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-kasse.example.toml'));
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
// 5. Rechnungsnummern nach eigenem Format, Storno mit neuer Nummer, verarbeitete Rechnungen der letzten 7 Tage.
foreach (['', 'ohne-zaehler', '{NNNN}{NN}', '{XYZ}-{NNNN}', str_repeat('A', 41) . '{NNNN}', 'RE<{NNNN}'] as $bad) {
    try { DocNumbers::validate($bad, 'x'); throw new RuntimeException('Ungültiges Nummernformat akzeptiert: ' . $bad); } catch (RuntimeException $e) { check(!str_starts_with($e->getMessage(), 'Ungültiges Nummernformat'), $e->getMessage()); }
}
DocNumbers::validate('R{JJ}/{MM}-{NNN}', 'x');
$v2 = $v; $v2['numbers'] = ['igel_format' => 'R{JJ}/{MM}-{NNN}', 'storno_format' => 'ST-{JJJJ}-{NNNN}']; $v2['sumup'] = ['mode' => 'live', 'api_key' => 'k', 'affiliate_key' => 'k', 'app_id' => 'a', 'merchant_code' => 'M', 'reader_id' => 'r'];
$v2['practice'] = ['name' => 'Praxis Beispiel', 'street' => 'Musterstraße 1', 'city' => '12345 Musterstadt'];
$a2 = new App(new Config($v2));
[$mv, $mb] = enter($a2, $a2->manualOpen()['url']);
$a2->saveService($mv, ['label' => 'Attest', 'price' => '15,00']);
$ids = $a2->db->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN);
$cash = $a2->start($mv, ['services' => $ids, 'request_id' => App::id(), 'method' => 'cash', 'patient_name' => 'Max Mustermann']);
$expected = 'R' . date('y') . '/' . date('m') . '-001';
check($cash['invoice_number'] === $expected, 'Eigenes Nummernformat nicht angewendet: ' . ($cash['invoice_number'] ?? ''));
[$mv2] = enter($a2, $a2->manualOpen()['url']);
$cash2 = $a2->start($mv2, ['services' => $ids, 'request_id' => App::id(), 'method' => 'cash']);
check($cash2['invoice_number'] === 'R' . date('y') . '/' . date('m') . '-002', 'Nummern nicht fortlaufend');
$list = array_column($a2->processed(7), null, 'id');
check(isset($list[$cash['id']]) && $list[$cash['id']]['number'] === $expected && $list[$cash['id']]['method'] === 'cash' && $list[$cash['id']]['storno'] === null, 'Verarbeitete Rechnung fehlt in der Liste');
check(str_starts_with($a2->processedPdf($cash['id']), '%PDF'), 'Kein PDF der verarbeiteten Rechnung');
$vorher = $a2->dayStats();
$st = $a2->storno($cash['id'], 'Irrtum');
check($st['number'] === 'ST-' . date('Y') . '-0001' && $st['original_number'] === $expected && $st['amount_cents'] === 1500, 'Storno falsch: ' . json_encode($st));
check(str_starts_with($a2->stornoPdf($st['id']), '%PDF'), 'Kein PDF des Stornos');
rejects(fn() => $a2->storno($cash['id'], ''), 'Doppeltes Storno akzeptiert');
rejects(fn() => $a2->storno('nicht-hex', ''), 'Ungültige Zahlung akzeptiert');
$after = array_column($a2->processed(7), null, 'id');
check($after[$cash['id']]['storno']['number'] === $st['number'], 'Storno fehlt in der Liste');
check($a2->db->one('SELECT amount_cents FROM payments WHERE id=?', [$cash['id']])['amount_cents'] === 1500, 'Stornierte Zahlung wurde verändert');
check($a2->dayStats()['total_cents'] === $vorher['total_cents'] - 1500 && $a2->dayStats()['cash'] === $vorher['cash'] - 1, 'Stornierte Zahlung zählt noch in den Tageswerten');
$cash3 = $a2->start((function () use ($a2) { [$x] = enter($a2, $a2->manualOpen()['url']); return $x; })(), ['services' => $ids, 'request_id' => App::id(), 'method' => 'cash']);
check($cash3['invoice_number'] === 'R' . date('y') . '/' . date('m') . '-003', 'Nummer einer stornierten Rechnung wurde wiederverwendet');
$st2 = $a2->storno($cash3['id'], '');
check($st2['number'] === 'ST-' . date('Y') . '-0002', 'Stornonummer nicht fortlaufend');
// Alte Zahlungen (älter als 7 Tage) erscheinen nicht mehr.
$a2->db->query('UPDATE payments SET paid_at=?, created_at=? WHERE id=?', [time() - 8 * 86400, time() - 8 * 86400, $cash2['id']]);
check(!isset(array_column($a2->processed(7), null, 'id')[$cash2['id']]), 'Zahlung älter als 7 Tage in der Liste');
// T2med-Rechnung nach Storno wieder offen.
$a2->storeInvoice(['number' => 'RE2026-0050', 'invoice_date' => date('Y-m-d'), 'amount_cents' => 2000, 'patient_name' => 'Erika Musterfrau', 'patient_birthdate' => '1980-04-12']);
$open50 = array_values(array_filter($a2->openInbox(), fn($o) => $o['number'] === 'RE2026-0050'))[0];
[$tv, $tb] = enter($a2, $a2->inboxVisit($open50['id'])['url']);
$a2->startInvoice($tv, ['invoice_id' => $open50['id'], 'method' => 'cash', 'request_id' => bin2hex(random_bytes(16))]);
check(!array_filter($a2->openInbox(), fn($o) => $o['number'] === 'RE2026-0050'), 'Bezahlte T2med-Rechnung noch offen');
$paid50 = array_values(array_filter($a2->processed(7), fn($x) => $x['number'] === 'RE2026-0050'))[0];
check($paid50['t2med'] === true && $paid50['amount_cents'] === 2000, 'T2med-Zahlung fehlt unter „Bezahlt“');
$st3 = $a2->storno($paid50['id'], 'falscher Betrag');
check($st3['original_number'] === 'RE2026-0050' && str_starts_with($st3['number'], 'ST-'), 'Storno einer T2med-Rechnung falsch');
check((bool)array_filter($a2->openInbox(), fn($o) => $o['number'] === 'RE2026-0050'), 'Stornierte T2med-Rechnung ist nicht wieder offen');
// 6. Offene Überweisungsrechnung stornieren (bekommt dafür erst jetzt ihre Nummer).
[$ov2] = enter($a2, $a2->manualOpen()['url']);
$c4 = $a2->start($ov2, ['services' => $ids, 'request_id' => App::id(), 'method' => 'cash']);
$a2->db->query("UPDATE payments SET payment_status='transfer_open', paid_at=NULL, payment_method='transfer', doc_number='', transaction_id=NULL WHERE id=?", [$c4['id']]);
$open4 = array_column($a2->processed(7), null, 'id')[$c4['id']];
check($open4['open_transfer'] === true && $open4['storno'] === null, 'Offene Überweisung nicht als offen erkannt');
$st4 = $a2->storno($c4['id'], 'Patient zahlt nicht');
check(str_starts_with($st4['number'], 'ST-') && $st4['original_number'] !== '' && $st4['original_number'] === $a2->db->one('SELECT doc_number FROM payments WHERE id=?', [$c4['id']])['doc_number'], 'Storno der offenen Überweisung falsch');
$after4 = array_column($a2->processed(7), null, 'id')[$c4['id']];
check($after4['open_transfer'] === false && $after4['storno']['number'] === $st4['number'], 'Stornierte offene Überweisung bleibt offen');
// 7. Vorgänge: Zeiträume, Liste, Abhaken, Excel.
$vg = new Vorgaenge($a2->db, $a2->config);
foreach (['tag', 'woche', 'monat', 'jahr'] as $art) {
    $per = $vg->period($art, '2026-03-18');
    check($per['to'] > $per['from'], 'Zeitraum falsch: ' . $art);
}
$w = $vg->period('woche', '2026-03-18'); $tzd = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone($a2->config->get('app', 'timezone')))->format('Y-m-d');
check($tzd($w['from']) === '2026-03-16' && $tzd($w['to']) === '2026-03-23', 'Woche beginnt nicht am Montag');
check($tzd($vg->period('monat', '2026-02-10')['from']) === '2026-02-01' && $tzd($vg->period('monat', '2026-02-10')['to']) === '2026-03-01', 'Monat falsch');
foreach (['quatsch', ''] as $badArt) { try { $vg->period($badArt, '2026-01-01'); throw new RuntimeException('Ungültige Zeitraumart akzeptiert'); } catch (Problem) {} }
try { $vg->period('tag', '2026-02-31'); throw new RuntimeException('Ungültiges Datum akzeptiert'); } catch (Problem) {}
$day = $vg->list('tag', date('Y-m-d'));
$byNum = []; foreach ($day['items'] as $r) if ($r['source'] === 'payment') $byNum[$r['number']] = $r;
check((bool)array_filter($day['items'], fn($r) => $r['number'] === 'RE2026-0050' && $r['source'] === 'inbox' && $r['status'] === 'offen'), 'Wieder offene T2med-Rechnung fehlt in den Vorgängen');
check(isset($byNum['RE2026-0050']) && $byNum['RE2026-0050']['art'] === 'T2med' && $byNum['RE2026-0050']['status'] === 'storniert' && $byNum['RE2026-0050']['storno'] === $st3['number'], 'T2med-Storno fehlt in den Vorgängen');
check(isset($byNum['R' . date('y') . '/' . date('m') . '-003']) && $byNum['R' . date('y') . '/' . date('m') . '-003']['art'] === 'IGeL' && $byNum['R' . date('y') . '/' . date('m') . '-003']['status'] === 'storniert', 'IGeL-Storno fehlt in den Vorgängen');
$a2->storeInvoice(['number' => 'RE2026-0060', 'invoice_date' => date('Y-m-d'), 'amount_cents' => 700, 'patient_name' => 'Max Mustermann', 'patient_birthdate' => '1975-01-02']);
$o60 = array_values(array_filter($vg->list('tag', date('Y-m-d'))['items'], fn($r) => $r['number'] === 'RE2026-0060'))[0];
check($o60['source'] === 'inbox' && $o60['status'] === 'offen' && $o60['can_done'] === false, 'Offene T2med-Rechnung fehlt in den Vorgängen');
// Abhaken: nur bezahlte T2med-Rechnung.
[$dv] = enter($a2, $a2->inboxVisit($o60['id'])['url']);
$a2->startInvoice($dv, ['invoice_id' => $o60['id'], 'method' => 'cash', 'request_id' => bin2hex(random_bytes(16))]);
$p60 = array_values(array_filter($vg->list('tag', date('Y-m-d'))['items'], fn($r) => $r['number'] === 'RE2026-0060'))[0];
check($p60['status'] === 'bezahlt' && $p60['can_done'] === true && $p60['done_date'] === '', 'Bezahlte T2med-Rechnung nicht abhakbar');
check($vg->markDone([$p60['id']], true) === 1, 'Abhaken ohne Wirkung');
$p60 = array_values(array_filter($vg->list('tag', date('Y-m-d'))['items'], fn($r) => $r['number'] === 'RE2026-0060'))[0];
check($p60['done_date'] === date('Y-m-d'), 'Abhak-Datum fehlt');
check($vg->markDone([$p60['id']], false) === 1, 'Haken entfernen ohne Wirkung');
try { $vg->markDone(['nicht-hex'], true); throw new RuntimeException('Ungültige ID beim Abhaken akzeptiert'); } catch (Problem) {}
$xlsx = $vg->workbook('tag', date('Y-m-d'), 'alle');
check(str_starts_with($xlsx, 'PK') && strlen($xlsx) > 1000, 'Excel-Liste ungültig');
check(str_starts_with($vg->workbook('tag', date('Y-m-d'), 'igel', 'cash'), 'PK') && str_starts_with($vg->workbook('tag', date('Y-m-d'), 't2med', 'card'), 'PK'), 'Excel mit Filter Zahlungsart fehlerhaft');
check(isset($byNum['RE2026-0050']['net_cents'], $byNum['RE2026-0050']['vat_cents']), 'Netto/MwSt. fehlen in den Vorgängen');
check($vg->filename('monat', '2026-03-05') === 'Vorgaenge-2026-03.xlsx', 'Dateiname falsch');
echo "Startseite der Kasse: ok\n";
