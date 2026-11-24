<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Übersicht aller Vorgänge (T2med-Rechnungen und IGeL) für einen Tag, eine Woche, einen Monat oder ein Jahr:
 * Liste zum Ansehen, Abhaken der T2med-Rechnungen („in T2med als abgerechnet markiert“) und Excel-Export.
 */
final class Vorgaenge {
    public const ARTEN = ['tag' => 'Tag', 'woche' => 'Woche', 'monat' => 'Monat', 'jahr' => 'Jahr'];
    private const MONATE = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    public function __construct(private Database $db, private Config $config) {}
    private function zone(): \DateTimeZone { return new \DateTimeZone($this->config->get('app', 'timezone')); }

    /** Zeitraum: [von, bis (ausschließlich), Beschriftung, Dateiname-Teil]. $date = beliebiger Tag im Zeitraum (Y-m-d). */
    public function period(string $art, string $date): array {
        if (!isset(self::ARTEN[$art]) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[1] < 2000 || (int)$m[1] > 2100) throw new Problem('Ungültiger Zeitraum.', 422);
        $day = new \DateTimeImmutable($date . ' 00:00:00', $this->zone());
        switch ($art) {
            case 'tag': $from = $day; $to = $day->modify('+1 day'); $label = $day->format('d.m.Y'); $file = $day->format('Y-m-d'); break;
            case 'woche':
                $from = $day->modify('monday this week'); $to = $from->modify('+7 days');
                $label = 'Woche ' . $from->format('W/o') . ' (' . $from->format('d.m.') . ' – ' . $to->modify('-1 day')->format('d.m.Y') . ')'; $file = $from->format('o-\WW'); break;
            case 'monat': $from = $day->modify('first day of this month'); $to = $from->modify('+1 month'); $label = self::MONATE[(int)$from->format('n')] . ' ' . $from->format('Y'); $file = $from->format('Y-m'); break;
            default: $from = new \DateTimeImmutable($m[1] . '-01-01 00:00:00', $this->zone()); $to = $from->modify('+1 year'); $label = $from->format('Y'); $file = $from->format('Y');
        }
        return ['from' => $from->getTimestamp(), 'to' => $to->getTimestamp(), 'label' => $label, 'file' => $file];
    }

    /** Alle Vorgänge des Zeitraums, neueste zuerst. */
    public function list(string $art, string $date): array {
        $p = $this->period($art, $date);
        $test = $this->config->get('sumup', 'mode') === 'mock';
        $zone = $this->zone();
        $when = static fn(int $t): array => [(new \DateTimeImmutable('@' . $t))->setTimezone($zone)->format('Y-m-d'), (new \DateTimeImmutable('@' . $t))->setTimezone($zone)->format('H:i')];
        $out = [];
        $rows = $this->db->query("SELECT p.*, v.patient_name AS pname, v.patient_birthdate AS pbirth, s.number AS snumber FROM payments p JOIN visits v ON v.id=p.visit_id LEFT JOIN stornos s ON s.payment_id=p.id
            WHERE (p.payment_status IN ('successful','transfer_open') OR p.storno_at IS NOT NULL) AND COALESCE(p.paid_at,p.created_at) >= CAST(? AS INTEGER) AND COALESCE(p.paid_at,p.created_at) < CAST(? AS INTEGER)
            ORDER BY COALESCE(p.paid_at,p.created_at) DESC, p.rowid DESC", [$p['from'], $p['to']])->fetchAll();
        foreach ($rows as $r) {
            $mock = $r['reader_id'] === 'mock-reader' || (!empty($r['invoice_json']) && !empty(json_decode($r['invoice_json'], true)['mock'] ?? null));
            if ($mock && !$test) continue;
            [$day, $time] = $when((int)($r['paid_at'] ?? $r['created_at']));
            $t2med = $r['t2med_invoice'] !== null;
            $amount = (int)($r['corrected_cents'] ?? $r['amount_cents']);
            $status = $r['storno_at'] !== null ? 'storniert' : ($r['payment_status'] === 'transfer_open' ? 'Überweisung offen' : 'bezahlt');
            $method = (string)($r['payment_method'] ?: 'card');
            $vat = 0;
            if (!$t2med && $r['invoice_json']) { foreach (Tax::totals(json_decode($r['invoice_json'], true)['items'] ?? []) as $rate => $sum) if ($rate > 0) $vat += (int)$sum['vat']; }
            $number = (string)($r['t2med_invoice'] ?: ($r['doc_number'] ?: ($r['transaction_id'] ?: $r['reference'])));
            $out[] = ['id' => $r['id'], 'source' => 'payment', 'date' => $day, 'time' => $time, 'art' => $t2med ? 'T2med' : 'IGeL', 'number' => $number, 'patient' => (string)$r['pname'], 'birthdate' => (string)$r['pbirth'],
                'method' => $method, 'status' => $status, 'invoice_cents' => $amount, 'vat_cents' => $vat, 'net_cents' => $t2med ? 0 : $amount - $vat, 'paid_cents' => $status === 'bezahlt' ? $amount : 0, 'storno' => (string)($r['snumber'] ?? ''),
                'done_date' => $r['t2med_done_at'] !== null ? $when((int)$r['t2med_done_at'])[0] : '', 'can_done' => $t2med && $r['payment_status'] === 'successful' && $r['storno_at'] === null, 'sort' => (int)($r['paid_at'] ?? $r['created_at'])];
        }
        // Noch nicht bezahlte T2med-Rechnungen aus dem Eingang (offen, zurückgestellt, überwiesen, abgelaufen).
        $limit = time() - $this->config->get('invoice_inbox', 'max_age_days', 14) * 86400;
        foreach ($this->db->query("SELECT * FROM invoice_inbox i WHERE i.superseded_at IS NULL AND i.received_at >= CAST(? AS INTEGER) AND i.received_at < CAST(? AS INTEGER)
            AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.t2med_invoice=i.invoice_number AND p.payment_status IN ('starting','pending','unknown','cancel_requested','successful') AND p.storno_at IS NULL)
            ORDER BY i.received_at DESC", [$p['from'], $p['to']])->fetchAll() as $i) {
            [$day, $time] = $when((int)$i['received_at']);
            // Per Post über Letterxpress verschickt (Drucker „vBriefversand“ oder Knopf „Per Post“): Zahlung folgt per Überweisung.
            $post = in_array($i['lxp_status'], ['sent', 'unknown'], true);
            $status = $post ? ($i['lxp_status'] === 'unknown' ? 'Postversand unklar' : 'per Letterxpress verschickt' . ($i['lxp_mode'] === 'live' ? '' : ' (Test)'))
                : ((int)$i['received_at'] < $limit ? 'abgelaufen' : ($i['parked_at'] !== null ? 'zurückgestellt' : ($i['transfer_at'] !== null ? 'überwiesen (offen)' : 'offen')));
            $out[] = ['id' => $i['id'], 'source' => 'inbox', 'date' => $day, 'time' => $time, 'art' => 'T2med', 'number' => (string)$i['invoice_number'], 'patient' => (string)$i['patient_name'], 'birthdate' => (string)$i['patient_birthdate'],
                'method' => $post ? 'transfer' : '', 'status' => $status, 'post' => $post, 'invoice_cents' => (int)$i['amount_cents'], 'vat_cents' => 0, 'net_cents' => 0, 'paid_cents' => 0, 'storno' => '', 'done_date' => '', 'can_done' => false, 'sort' => (int)$i['received_at']];
        }
        usort($out, static fn($a, $b) => $b['sort'] <=> $a['sort']);
        return ['period' => ['art' => $art, 'label' => $p['label'], 'file' => $p['file'], 'from' => $p['from'], 'to' => $p['to']], 'items' => $out, 'now' => time()];
    }

    public function filename(string $art, string $date): string { return 'Vorgaenge-' . $this->period($art, $date)['file'] . '.xlsx'; }

    public function workbook(string $art, string $date, string $kind = 'alle', string $pay = 'alle'): string {
        $data = $this->list($art, $date);
        $rows = [];
        foreach ($data['items'] as $r) {
            if ($kind === 't2med' && $r['art'] !== 'T2med' || $kind === 'igel' && $r['art'] !== 'IGeL') continue;
            if ($pay !== 'alle' && $r['method'] !== $pay) continue;
            $rows[] = [$r['date'], $r['time'], $r['art'], $r['number'], $r['patient'], $r['birthdate'] ?: null, ['card' => 'Karte', 'cash' => 'Bar', 'transfer' => 'Überweisung', '' => ''][$r['method']] ?? '',
                $r['status'], $r['invoice_cents'], $r['net_cents'], $r['vat_cents'], $r['paid_cents'], $r['storno'], $r['art'] === 'T2med' ? ($r['done_date'] ?: null) : null];
        }
        $columns = [['title' => 'Datum', 'type' => 'date', 'width' => 12], ['title' => 'Uhrzeit', 'width' => 8], ['title' => 'Art', 'width' => 8], ['title' => 'Rechnungsnummer', 'width' => 24], ['title' => 'Patient', 'width' => 28],
            ['title' => 'Geburtsdatum', 'type' => 'date', 'width' => 14], ['title' => 'Zahlungsart', 'width' => 14], ['title' => 'Status', 'width' => 18], ['title' => 'Rechnungsbetrag', 'type' => 'money', 'width' => 16], ['title' => 'davon Netto', 'type' => 'money', 'width' => 14], ['title' => 'davon MwSt.', 'type' => 'money', 'width' => 14],
            ['title' => 'Bezahlt (ohne Storno)', 'type' => 'money', 'width' => 20], ['title' => 'Stornonummer', 'width' => 22], ['title' => 'In T2med abgehakt am', 'type' => 'date', 'width' => 20]];
        $x = new Xlsx(); $x->sheet('Vorgänge', $columns, $rows, true);
        return $x->build();
    }

    /** T2med-Rechnungen als „in T2med abgerechnet“ abhaken oder zurücknehmen. */
    public function markDone(array $ids, bool $done): int { return (new TaxExport($this->db, $this->config))->markT2medDone($ids, $done); }
}
