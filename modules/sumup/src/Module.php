<?php
declare(strict_types=1);
namespace KienzleSumup;

/** Angaben des Moduls für Einstellungsseite, Einrichtungs-Überblick der Suite und Haken. */
final class Module {
    public const INVOICE_QUEUE = 'SumUp-Rechnung';

    public static function version(): string { return trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')); }

    /** Zustand des virtuellen Druckers „SumUp-Rechnung“ (legt die Basis aus module.toml an). */
    public static function invoicePrinter(Config $config): array {
        $queue = null;
        try {
            foreach (Suite::modul()->drucker() as $q) if ($q['name'] === self::INVOICE_QUEUE) $queue = ['state' => $q['state'] ?: 'bereit', 'ours' => true];
        } catch (\Throwable) {}
        return ['enabled' => (bool)$config->get('invoice_inbox', 'enabled', false), 'installed' => true, 'queue' => $queue,
            'url' => 'http://' . Suite::host() . ':631/printers/' . self::INVOICE_QUEUE, 'ips' => []];
    }

    /**
     * Einrichtungs-Überblick im Format der Suite (docs/MODULE-TOML.md, [setup] overview): Punkte mit title, state
     * (done/open/error), hint und link (unter dem eigenen Webpfad).
     */
    public static function overview(Config $config): array {
        $base = Suite::base();
        $link = fn(string $card) => $base . '/settings.php#' . $card;
        $practice = trim((string)$config->get('practice', 'name', '')) !== '' && trim((string)$config->get('practice', 'street', '')) !== '';
        $items = [
            ['title' => 'Praxisname und Anschrift für Rechnungen', 'state' => $practice ? 'done' : 'open', 'hint' => 'Einstellungen → Praxis & Rechnungen', 'link' => $link('s-practice')],
            ['title' => Suite::connected('t2med') ? 'T2med-Verbindung der Suite vorhanden' : 'T2med-Verbindung fehlt', 'state' => Suite::connected('t2med') ? 'done' : 'open',
                'hint' => 'Verwaltung → Verbindungen der Suite (Adresse, API-Key, Zertifikat); der Link im Bereich „T2med-Anbindung“ führt direkt dorthin', 'link' => $link('s-fhir')],
            ['title' => $config->get('fhir', 'mode') === 'live' ? 'T2med-Anbindung live' : 'T2med-Anbindung noch im Testmodus', 'state' => $config->get('fhir', 'mode') === 'live' ? 'done' : 'open',
                'hint' => 'Einstellungen → Zahlung & T2med', 'link' => $link('s-fhir')],
            ['title' => $config->get('sumup', 'mode') === 'live' ? 'SumUp live verbunden' : 'SumUp: noch Testmodus', 'state' => $config->get('sumup', 'mode') === 'live' ? 'done' : 'open',
                'hint' => 'API-Key, Händlercode und Reader-ID eintragen, dann auf Live stellen', 'link' => $link('s-sumup')],
        ];
        if ($config->get('invoice_inbox', 'enabled', false)) {
            $q = self::invoicePrinter($config)['queue'];
            $items[] = ['title' => 'Drucker „SumUp-Rechnung“ ' . ($q ? 'bereit' : 'fehlt'), 'state' => $q ? 'done' : 'error',
                'hint' => $q ? 'In T2med die Rechnung auf diesen Drucker drucken' : 'Verwaltung → Drucker der Suite: virtuelle Drucker abgleichen', 'link' => $link('invoice-printer-help')];
        }
        foreach (['printing' => 'Bondrucker', 'office_printing' => 'Praxisdrucker (A4)'] as $section => $label) {
            if (!$config->get($section, 'enabled', false)) continue;
            $known = Suite::druckerRollen()[$section === 'printing' ? 'bon' : 'buero'] !== '';
            $items[] = ['title' => $label . ($known ? ' gewählt' : ': Drucker nicht in der Suite eingerichtet'), 'state' => $known ? 'done' : 'error',
                'hint' => 'Verwaltung → Drucker der Suite: unter „Wofür welcher Drucker?“ zuordnen', 'link' => $link('s-' . $section)];
        }
        if ($config->get('mail', 'enabled', false) && !Suite::connected('mail')) {
            $items[] = ['title' => 'E-Mail-Versand: Verbindung fehlt', 'state' => 'error', 'hint' => 'Verwaltung → Verbindungen der Suite', 'link' => $link('s-mail')];
        }
        if ($config->get('letterxpress', 'enabled', false) && !Suite::connected('letterxpress')) {
            $items[] = ['title' => 'Letterxpress: Verbindung fehlt', 'state' => 'error', 'hint' => 'Verwaltung → Verbindungen der Suite (Benutzername, API-Key)', 'link' => $link('s-letterxpress')];
        }
        if ($config->get('invoice_export', 'enabled', false) && !isset(Suite::targets()[(string)$config->get('invoice_export', 'ziel', '')])) {
            $items[] = ['title' => 'Ablage: Ziel im Netzwerkspeicher fehlt', 'state' => 'error', 'hint' => 'Verwaltung → Verbindungen der Suite, dann hier wählen', 'link' => $link('s-invoice_export')];
        }
        $items[] = ['title' => 'Starter auf den Kassen-PCs installieren', 'state' => self::clientsCurrent($config) ? 'done' : 'open',
            'hint' => 'Einstellungen → Arbeitsplätze: Paket laden und auf jedem Kassen-PC einmal ausführen (nach einem neuen Serverzertifikat erneut)', 'link' => $link('s-clients')];
        return $items;
    }

    /** Wurde seit dem aktuellen Serverzertifikat mindestens ein Starter-Paket geladen? (Der Starter prüft genau dieses Zertifikat.) */
    public static function clientsCurrent(Config $config): bool {
        $mark = rtrim((string)$config->get('app', 'state_dir'), '/') . '/starter-zertifikat';
        $current = ClientPackage::certificateHash();
        return $current !== '' && trim((string)@file_get_contents($mark)) === $current;
    }

    /** Für den Einrichtungs-Überblick nichts zwischenspeichern: die Basis fragt den Haken selbst ab. */
    public static function refreshOverview(Config $config): void {}
}
