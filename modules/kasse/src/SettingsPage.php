<?php
declare(strict_types=1);
namespace KienzleKasse;

/**
 * JSON-Aktionen der Einstellungsseite. Angemeldet wird über Praxisherz: Admins des Moduls dürfen alles,
 * Mitarbeiter nur die Bereiche ihrer Rechte (module.toml [[rights]]). Heikle Änderungen verlangen eine
 * Bestätigung mit Passwort und OTP bzw. Passkey in den letzten 24 Stunden (Seite /bestaetigen der Basis).
 */
final class SettingsPage {
    private Database $db;
    private AuditLog $audit;

    /** Rechte der Mitarbeiter und die Aktionen, die sie erlauben. */
    public const RIGHTS = [
        'inbox' => ['Rechnungseingang ansehen, nicht erkannte Rechnungen nachbearbeiten, Rechnungen löschen', ['inbox_overview', 'inbox_delete', 'inbox_delete_many', 'inbox_clear', 'inbox_review', 'inbox_review_page', 'inbox_review_apply', 'inbox_review_drop']],
        'letter' => ['Rechnungen per Post (Letterxpress) senden', ['inbox_overview', 'inbox_letter', 'inbox_letter_many']],
        'catalog' => ['Leistungen und GOÄ-Positionen bearbeiten', ['catalog', 'catalog_service_save', 'catalog_service_delete', 'catalog_group_save', 'catalog_group_delete']],
    ];
    /** Aktionen, die eine frische Bestätigung (Passwort + OTP bzw. Passkey) brauchen. */
    private const CONFIRM = ['save'];
    public const CONFIRM_ERROR = 'Bitte zuerst mit Passwort und Code bestätigen.';

    public function __construct(private Config $base, private ?HttpClient $http = null) {
        $this->db = new Database($base->get('app', 'state_dir'));
        $this->db->migrate();
        $this->audit = new AuditLog($base->get('app', 'state_dir'));
    }

    private function who(): string { return (string)preg_replace('/[^A-Za-z0-9_.-]/', '', Suite::modul()->benutzer()); }

    /** $csrf: Kopfzeile X-Kienzle-Csrf bzw. X-CSRF-Token des Browsers (Marke von Praxisherz für dieses Modul). */
    public function handle(string $action, array $input, ?string $csrf): array {
        $k = Suite::modul();
        if ($k->benutzer() === '') throw new Problem('Bitte über Praxisherz anmelden.', 401);
        $admin = Suite::isAdmin();
        $rights = Suite::rights();
        if ($action === 'status') return ['logged_in' => true, 'csrf' => $k->csrf(), 'role' => $admin ? 'admin' : 'staff', 'user' => $k->name() ?: $k->benutzer()];
        if (!in_array($action, ['get', 'audit', 'inbox_overview', 'catalog'], true) && !$k->csrfGueltig($csrf)) throw new Problem('Sitzung abgelaufen. Bitte Seite neu laden.', 403);
        $staff = !$admin;
        if ($staff) $this->requireStaffRight($action, $rights);
        if (!$staff && in_array($action, self::CONFIRM, true) && !$k->frischBestaetigt()) {
            throw new Problem(self::CONFIRM_ERROR, 403);
        }
        // Alles, was etwas verändert, im Protokoll festhalten (ohne Werte).
        if (!in_array($action, ['get', 'status', 'audit', 'inbox_overview', 'catalog', 'test'], true)) {
            $this->audit->write($action, $this->who() . ($action === 'save' ? ' ' . App::string($input, 'section', 40) : ''));
        }
        $result = match ($action) {
            'get' => $staff ? $this->staffView($rights) : $this->view(),
            'inbox_overview' => InboxOverview::build($this->db, $this->config()),
            'inbox_clear' => (function () { (new InboxLog($this->config()->get('app', 'state_dir')))->clear(); return InboxOverview::build($this->db, $this->config()) + ['message' => 'Liste der abgelehnten Drucke geleert.']; })(),
            'audit' => ['lines' => $this->audit->recent(40)],
            'save' => $this->save(App::string($input, 'section', 40), is_array($input['values'] ?? null) ? $input['values'] : []),
            'test' => ['message' => $this->test(App::string($input, 'kind', 40))],
            'inbox_review' => $this->reviewOpen(App::string($input, 'id', 32)),
            'inbox_review_page' => ['image' => InvoiceReview::page($this->rejectedPdf(App::string($input, 'id', 32)), (int)($input['page'] ?? 1))],
            'inbox_review_apply' => $this->reviewApply($input),
            'inbox_review_drop' => $this->reviewDrop(App::string($input, 'id', 32)),
            'inbox_delete' => $this->inboxDelete($input),
            'inbox_letter' => $this->inboxLetter($input),
            'inbox_letter_many' => $this->inboxMany($input, 'letter'),
            'inbox_delete_many' => $this->inboxMany($input, 'delete'),
            'catalog' => $this->catalog()->overview(),
            'catalog_service_save' => $this->catalogChange(fn(Catalog $c) => $c->saveService($input), 'Leistung gespeichert.'),
            'catalog_service_delete' => $this->catalogChange(fn(Catalog $c) => $c->deleteService(App::string($input, 'id', 32)), 'Leistung gelöscht. Frühere Zahlungen behalten ihre Angaben.'),
            'catalog_group_save' => $this->catalogChange(fn(Catalog $c) => $c->saveGroup($input), 'Kombination gespeichert.'),
            'catalog_group_delete' => $this->catalogChange(fn(Catalog $c) => $c->deleteGroup(App::string($input, 'id', 32)), 'Kombination gelöscht. Die Einzelleistungen bleiben erhalten.'),
            default => throw new Problem('Unbekannte Aktion.', 404),
        };
        // Ohne das Recht „per Post“ blendet der Rechnungseingang die Versand-Schaltflächen aus.
        if ($staff && isset($result['letter_enabled']) && !in_array('letter', $rights, true)) $result['letter_enabled'] = false;
        return $result;
    }

    private function requireStaffRight(string $action, array $rights): void {
        if (in_array($action, ['get'], true)) return;
        foreach ($rights as $right) if (in_array($action, self::RIGHTS[$right][1] ?? [], true)) return;
        $this->audit->write('verweigert', $action . ' ' . $this->who());
        throw new Problem('Dafür fehlt das Recht. Bitte den Admin fragen (Verwaltung → Benutzer von Praxisherz).', 403);
    }

    /** Mitarbeiter sehen nur Modus, Version und die freigegebenen Bereiche – keine Einstellungen, Schlüssel oder Zugangsdaten. */
    private function staffView(array $rights): array {
        $config = $this->config();
        return ['role' => 'staff', 'rights' => $rights, 'mode' => $config->get('sumup', 'mode'), 'fhir_mode' => $config->get('fhir', 'mode'),
            'version' => Module::version()];
    }

    private function config(): Config { return Settings::effective($this->base); }

    private function view(): array {
        $config = $this->config();
        $tools = [];
        foreach (['pdftotext' => '/usr/bin/pdftotext', 'lp' => '/usr/bin/lp', 'rsvg-convert' => '/usr/bin/rsvg-convert', 'qpdf' => '/usr/bin/qpdf'] as $name => $path) $tools[$name] = is_executable($path);
        $broken = Settings::read($this->base) && $config === $this->base;
        return ['sections' => Settings::view($config), 'tools' => $tools + ['gd' => extension_loaded('gd')],
            'base_url' => $config->get('app', 'base_url'), 'base' => Suite::base(), 'version' => Module::version(),
            'mode' => $config->get('sumup', 'mode'), 'fhir_mode' => $config->get('fhir', 'mode'),
            'connections' => ['t2med' => Suite::connected('t2med'), 'mail' => Suite::connected('mail'), 'ablage' => Suite::connected('ablage')],
            'warning' => $broken ? 'Gespeicherte Einstellungen passen nicht zur aktuellen Version und werden ignoriert. Bitte die Bereiche prüfen und neu speichern.' : null,
            'invoice_printer' => Module::invoicePrinter($config), 'letter_printer' => Module::letterPrinter($config), 'setup' => Module::overview($config)];
    }

    private function save(string $section, array $values): array {
        $config = Settings::save($this->db, $this->base, $section, $values);
        Module::refreshOverview($config);
        $message = 'Gespeichert. Gilt ab sofort für alle Arbeitsplätze.';
        if ($section === 'invoice_inbox' && $config->get('invoice_inbox', 'enabled', false)) $message .= ' Der Drucker „vKasse“ steht in Praxisherz bereit (Verwaltung → Drucker).';
        return $this->view() + ['message' => $message];
    }

    private function test(string $kind): string {
        $config = $this->config();
        $mock = $config->get('sumup', 'mode') !== 'live';
        switch ($kind) {
            case 'sumup':
                return (new SumUpClient($config, $this->http ?? new HttpClient()))->check();
            case 'receipt_printer':
                if (!$config->get('printing', 'enabled', false)) throw new Problem('Bondrucker ist nicht aktiviert.', 409);
                // ESC/POS-Text: Initialisieren, Text, Vorschub, Schnitt.
                (new ReceiptPrinter($config))->send("\x1b@Praxisherz Kasse Testdruck\n" . date('d.m.Y H:i') . "\n\n\n\n\x1dVB\x00");
                return 'Testbon an den Bondrucker übergeben.';
            case 'office_printer':
                $office = new OfficePrinter($config);
                if (!$office->enabled()) throw new Problem('Praxisdrucker ist nicht aktiviert.', 409);
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="210mm" height="297mm" viewBox="0 0 210 297"><rect width="210" height="297" fill="white"/>'
                    . '<text x="20" y="30" font-family="DejaVu Sans" font-size="8">Praxisherz Kasse Testseite</text><text x="20" y="42" font-family="DejaVu Sans" font-size="4">' . date('d.m.Y H:i') . '</text></svg>';
                return 'Testseite übergeben: Auftrag ' . $office->send(ReceiptPdf::convert($svg), 'Praxisherz Kasse Testseite') . '.';
            case 'export':
                return (new InvoiceExport($config))->check();
            case 'letterxpress':
                return (new Letterxpress($config, $this->http ?? new HttpClient(), $this->db))->check();
            default:
                throw new Problem('Unbekannter Test.', 404);
        }
    }

    private function catalog(): Catalog { return new Catalog($this->db, $this->config()); }
    private function catalogChange(callable $change, string $message): array {
        $catalog = $this->catalog();
        $change($catalog);
        return $catalog->overview() + ['message' => $message];
    }
    /** Rechnung aus dem Eingang per Post senden (nur mit Admin-Sitzung, CSRF und ausdrücklicher Bestätigung in der Oberfläche). */
    private function inboxLetter(array $input): array {
        $config = $this->config();
        $result = (new Letterxpress($config, $this->http ?? new HttpClient(), $this->db))->send(App::string($input, 'id', 32));
        return InboxOverview::build($this->db, $config) + ['message' => $result['message']];
    }
    /** Mehrere Rechnungen auf einmal senden oder löschen; jede einzeln, Fehler werden gezählt statt abzubrechen. */
    private function inboxMany(array $input, string $what): array {
        $ids = is_array($input['ids'] ?? null) ? array_values(array_unique(array_filter($input['ids'], 'is_string'))) : [];
        if (!$ids) throw new Problem('Keine Rechnung ausgewählt.', 422);
        if (count($ids) > 50) throw new Problem('Bitte höchstens 50 Rechnungen auf einmal auswählen.', 422);
        $config = $this->config();
        $letter = $what === 'letter' ? new Letterxpress($config, $this->http ?? new HttpClient(), $this->db) : null;
        $done = 0; $errors = [];
        foreach ($ids as $id) {
            try {
                if ($letter) $letter->send(App::string(['id' => $id], 'id', 32)); else InboxOverview::delete($this->db, App::string(['id' => $id], 'id', 32));
                $done++;
            } catch (Problem $e) { $errors[] = $e->getMessage(); }
        }
        $errors = array_values(array_unique($errors));
        $verb = $letter ? ($config->get('letterxpress', 'mode', 'test') === 'live' ? 'an Letterxpress übergeben' : 'als Testbrief an Letterxpress übergeben') : 'gelöscht';
        $message = $done . ($done === 1 ? ' Rechnung ' : ' Rechnungen ') . $verb . '.';
        if ($errors) $message .= ' Nicht ausgeführt: ' . (count($ids) - $done) . ' (' . mb_substr(implode(' ', $errors), 0, 300) . ')';
        return InboxOverview::build($this->db, $config) + ['message' => $message];
    }
    private function log(): InboxLog { return new InboxLog($this->config()->get('app', 'state_dir')); }
    private function rejectedPdf(string $id): string {
        $file = $this->log()->pdfPath($id);
        if ($file === null) throw new Problem('Diese Rechnung liegt nicht mehr zur Nachbearbeitung vor (nach ' . InboxLog::KEEP_DAYS . ' Tagen wird sie gelöscht). Bitte in T2med erneut drucken.', 404);
        return $file;
    }
    /** Bildausschnitte und Vorschläge für eine abgelehnte Rechnung. */
    private function reviewOpen(string $id): array {
        $file = $this->rejectedPdf($id);
        $labels = (new App($this->config(), $this->http))->inboxLabels();
        return ['review' => InvoiceReview::analyze($file, $labels) + ['id' => $id]];
    }
    /** Von Hand eingetragene Werte prüfen, die Rechnung in den Eingang legen und den Eintrag samt PDF entfernen. */
    private function reviewApply(array $input): array {
        $id = App::string($input, 'id', 32);
        $file = $this->rejectedPdf($id);
        $config = $this->config();
        $app = new App($config, $this->http);
        $prefixes = (array)($app->inboxLabels()[T2medInvoice::LABEL_KEYS['prefix']] ?? []);
        $invoice = InvoiceReview::validate($input, (int)$config->get('app', 'max_amount_cents'), $prefixes);
        $result = $app->storeInvoice($invoice, (string)file_get_contents($file));
        $this->log()->remove($id);
        return InboxOverview::build($this->db, $config) + ['message' => 'Rechnung ' . $invoice['number'] . ' übernommen. ' . $result['message']];
    }
    private function reviewDrop(string $id): array {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new Problem('Ungültige Auswahl.', 422);
        $this->log()->remove($id);
        return InboxOverview::build($this->db, $this->config()) + ['message' => 'Rechnung aus der Nachbearbeitung entfernt.'];
    }
    private function inboxDelete(array $input): array {
        InboxOverview::delete($this->db, App::string($input, 'id', 32));
        return InboxOverview::build($this->db, $this->config()) + ['message' => 'Rechnung aus dem Eingang gelöscht.'];
    }

}
