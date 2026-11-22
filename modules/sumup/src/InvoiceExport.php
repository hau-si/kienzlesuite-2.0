<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Legt die Original-PDF einer Rechnung im Netzwerkspeicher der Suite ab (z. B. für die Steuerberatung).
 * Das Modul kennt die Zugangsdaten nicht: Es übergibt einen Auftrag an die Basis (KienzleModul::ablegen); der Dienst
 * ks-ablage überträgt ihn, prüft die Datei und versucht es bei Fehlern erneut (docs/MODUL-SCHNITTSTELLE.md).
 * Dateiname ohne Patientendaten. Übernommen aus kienzle-suite-beta (vorher direkt per smbclient).
 */
class InvoiceExport {
    public function __construct(private Config $config) {}

    public function enabled(): bool { return (bool)$this->config->get('invoice_export', 'enabled', false); }
    public function when(): string { return $this->config->get('invoice_export', 'when', 'paid'); }
    /** Auch die in der App erzeugten Rechnungen/Leistungsbelege ablegen? */
    public function appInvoices(): bool { return $this->enabled() && (bool)$this->config->get('invoice_export', 'app_invoices', false); }

    /** Dateiname: 2026-03-15_RE2026-0042_42,50EUR[_Karte].pdf */
    /** $paid: false = unbezahlt, true/'Karte' = per Karte bezahlt, 'Bar' = bar bezahlt. */
    public static function filename(array $invoice, bool|string $paid): string {
        $number = preg_replace('/[^A-Za-z0-9._-]/', '_', $invoice['invoice_number']);
        $amount = number_format($invoice['amount_cents'] / 100, 2, ',', '');
        return $invoice['invoice_date'] . '_' . $number . '_' . $amount . 'EUR' . ($paid === true ? '_Karte' : (is_string($paid) && $paid !== '' ? '_' . $paid : '')) . '.pdf';
    }
    /** Unterordner mit Platzhaltern {jahr} und {monat} aus dem Rechnungsdatum. */
    public function directory(array $invoice): array {
        [$year, $month] = explode('-', $invoice['invoice_date']);
        $path = str_replace(['{jahr}', '{monat}'], [$year, $month], (string)$this->config->get('invoice_export', 'directory', ''));
        return array_values(array_filter(explode('/', $path), static fn($part) => $part !== ''));
    }

    public function target(): string { return (string)$this->config->get('invoice_export', 'ziel', ''); }

    /** Auftrag an den Netzwerkspeicher der Suite; liefert den Pfad unter dem Ziel. */
    public function export(array $invoice, string $pdf, bool|string $paid): string {
        if (!$this->enabled()) throw new Problem('Ablage im Netzwerkordner ist deaktiviert.', 409);
        if (!str_starts_with($pdf, '%PDF-')) throw new Problem('Original-PDF der Rechnung fehlt.', 409);
        // Testzahlungen nie mit echten Rechnungen verwechseln.
        $name = ($this->config->get('sumup', 'mode') === 'mock' ? 'TEST_' : '') . self::filename($invoice, $paid);
        $folders = $this->directory($invoice);
        $this->deliver($pdf, $name, implode('/', $folders));
        return implode('/', [...$folders, $name]);
    }

    /** Prüfung in den Einstellungen: Verbindung und Ziel vorhanden. Übertragen und Kontrolllesen übernimmt die Basis. */
    public function check(): string {
        if (!$this->enabled()) throw new Problem('Ablage im Netzwerkordner ist deaktiviert.', 409);
        $targets = Suite::targets();
        if (!isset($targets[$this->target()])) throw new Problem('Das gewählte Ziel gibt es nicht (mehr). Bitte unter Verwaltung → Verbindungen prüfen und hier neu wählen.', 409);
        $this->deliver("Kienzle-Suite Kasse: Verbindungstest\n", 'kienzle-kasse-verbindungstest.txt', '');
        return 'Testdatei an „' . $targets[$this->target()] . '“ übergeben. Ob sie angekommen ist, zeigt Verwaltung → Verbindungen der Basis.';
    }

    /** Auftrag abgeben (in Tests ersetzbar). */
    protected function deliver(string $data, string $name, string $folder): void {
        try {
            Suite::modul()->ablegen($this->target(), $data, $name, $folder);
        } catch (\RuntimeException $e) {
            throw new Problem('Netzwerkspeicher: ' . $e->getMessage(), 502);
        }
    }
}
