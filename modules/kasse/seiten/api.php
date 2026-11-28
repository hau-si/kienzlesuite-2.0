<?php
declare(strict_types=1);
// JSON-Schnittstelle der Kasse (nur mit Anmeldung von Praxisherz und dem Recht „kassieren“). Den Start aus T2med nimmt
// seiten/starter.php entgegen (POST /starter/kasse, Starter-Schlüssel von der Basis geprüft).
use KienzleKasse\{App, Problem, Suite};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Problem('POST erforderlich.', 405);
    ks_require_same_site();
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) throw new Problem('Anfrage zu groß.', 413);
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new Problem('JSON erforderlich.', 415);
    $raw = ks_body(32769);
    if (strlen($raw) > 32768) throw new Problem('Anfrage zu groß.', 413);
    try { $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new Problem('Ungültige JSON-Anfrage.'); }
    if (!is_array($input)) throw new Problem('Ungültige Anfrage.');
    $app = ks_app();
    $action = App::string($input, 'action', 32);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== rtrim($app->config->get('app', 'base_url'), '/')) throw new Problem('Unzulässiger Ursprung.', 403);
    $vorgang = in_array($action, ['vorgaenge', 'vorgaenge_done', 'vorgaenge_post'], true) && (Suite::can('export') || Suite::can('paid') || Suite::can('periods') || Suite::can('storno'));
    $stornoRecht = $action === 'storno' && Suite::can('storno');   // Stornieren aus der Vorgänge-Liste braucht nur das Recht „Rechnungen stornieren“
    if (!$vorgang && !$stornoRecht && !Suite::can('kassieren')) throw new Problem('Für die Kasse fehlt das Recht „Kartenzahlung und Barzahlung aus T2med“. Bitte den Admin fragen (Praxis → Benutzer und Gruppen).', 403);
    // Marke von Praxisherz für dieses Modul (meta kienzle-csrf der Seite) bei allem außer dem reinen Abfragen des Zustands.
    if ($action !== 'state' && !Suite::modul()->csrfGueltig($_SERVER['HTTP_X_KIENZLE_CSRF'] ?? null)) throw new Problem('Sitzung abgelaufen. Bitte Seite neu laden.', 403);
    if ($action === 'demo') {
        // Nur im Testbetrieb (SumUp und T2med im Testmodus): Testpatient ohne T2med öffnen.
        if ($app->config->get('sumup', 'mode') !== 'mock' || $app->config->get('fhir', 'mode') !== 'mock') throw new Problem('Testpatient nur im Testmodus.', 403);
        $result = $app->launch(['context_id' => 'demo', 'oauth_token' => '', 'fhir_base_url' => '']);
        $result['url'] = str_replace('/#launch=', ks_url('#launch='), $result['url']);
    } elseif ($action === 'manual') {
        $result = $app->manualStart($input);
        $result['url'] = str_replace('/#launch=', ks_url('#launch='), $result['url']);
    } elseif ($action === 'uebergabe_einloesen') {
        // Patient aus einem anderen Modul (z. B. Signatur nach einem Aufruf aus T2med), mit dessen T2med-Zugang.
        $ordner = '/var/spool/kienzle/austausch/kasse_signatur';
        $result = $app->uebergabeEinloesen(App::string($input, 'code', 32), (string)($GLOBALS['KS_TEST_AUSTAUSCH'] ?? $ordner));
        $result['url'] = str_replace('/#launch=', ks_url('#launch='), $result['url']);
    } elseif ($action === 'manual_open') {
        $result = $app->manualOpen();
        $result['url'] = str_replace('/#launch=', ks_url('#launch='), $result['url']);
    } elseif ($action === 'inbox_visit') {
        $result = $app->inboxVisit(App::string($input, 'invoice_id', 32));
        $result['url'] = str_replace('/#launch=', ks_url('kassieren#launch='), $result['url']);
    } elseif ($action === 'inbox_tag') {
        $result = $app->inboxTag(App::string($input, 'invoice_id', 32), App::string($input, 'tag', 12), (bool)($input['on'] ?? false));
    } elseif ($action === 'day_stats') {
        $result = $app->dayStats();
    } elseif ($action === 'setup_count') {
        // Rote Zahl am Zahnrad: noch nicht erledigte Punkte des Einrichtungs-Überblicks (nur für Benutzer mit Einstellungsrechten).
        $items = Suite::settingsRights() ? \KienzleKasse\Module::overview($app->config) : [];
        $result = ['count' => count(array_filter($items, static fn($i) => ($i['state'] ?? '') === 'error' || (($i['state'] ?? '') === 'open' && empty($i['optional'])))),
            'red' => count(array_filter($items, static fn($i) => ($i['state'] ?? '') === 'error'))];
    } elseif ($action === 'vorgaenge') {
        $art = App::string($input, 'art', 8);
        if (in_array($art, ['monat', 'jahr'], true) && !Suite::can('periods')) throw new Problem('Für die Monats- und Jahresansicht fehlt das Recht „Monats- und Jahresansicht der Vorgänge“.', 403);
        $result = (new \KienzleKasse\Vorgaenge($app->db, $app->config))->list($art, App::string($input, 'date', 10));
        $result['rights'] = ['export' => Suite::can('export'), 'paid' => Suite::can('paid'), 'periods' => Suite::can('periods'), 'storno' => Suite::can('storno'), 'letter' => Suite::can('letter')];
    } elseif ($action === 'vorgaenge_post') {
        if (!Suite::can('letter')) throw new Problem('Zum Versand per Post fehlt das Recht „Rechnungen per Post (Letterxpress) senden“. Bitte den Admin fragen.', 403);
        $result = $app->vorgangPost(App::string($input, 'invoice_id', 32));
    } elseif ($action === 'vorgaenge_done') {
        if (!Suite::can('paid')) throw new Problem('Zum Abhaken fehlt das Recht „T2med-Rechnungen in den Vorgängen abhaken“.', 403);
        $ids = $input['ids'] ?? null;
        if (!is_array($ids)) throw new Problem('Ungültige Auswahl.', 422);
        $result = ['count' => (new \KienzleKasse\Vorgaenge($app->db, $app->config))->markDone($ids, (bool)($input['done'] ?? false))];
    } elseif ($action === 'inbox_delete') {
        if (!Suite::can('inbox')) throw new Problem('Zum Löschen fehlt das Recht „Rechnungseingang, Nachbearbeitung und Löschen von Rechnungen“.', 403);
        \KienzleKasse\InboxOverview::delete($app->db, App::string($input, 'invoice_id', 32));
        $result = ['ok' => true];
    } elseif ($action === 'processed') {
        $result = ['items' => $app->processed(7), 'now' => time(), 'rights' => ['storno' => Suite::can('storno')]];
    } elseif ($action === 'storno') {
        if (!Suite::can('storno')) throw new Problem('Zum Stornieren fehlt das Recht „Rechnungen stornieren“. Bitte den Admin fragen.', 403);
        $result = $app->storno(App::string($input, 'payment_id', 32), (string)($input['note'] ?? ''));
    } elseif ($action === 'open_inbox') {
        $result = ['items' => $app->openInbox(), 'now' => time(), 'letter' => ['enabled' => $app->letterEnabled()], 'rights' => ['inbox' => Suite::can('inbox')]];
    } elseif ($action === 'exchange') {
        $result = $app->exchange(App::string($input, 'ticket', 64), $_COOKIE['ks_browser'] ?? null);
        setcookie('ks_browser', $result['cookie'], ['expires' => time() + $app->config->get('app', 'session_hours') * 3600,
            'path' => Suite::base() . '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        unset($result['cookie']);
    } else {
        $browser = $app->browser($_COOKIE['ks_browser'] ?? null);
        $visit = $app->visit($browser, App::string($input, 'visit_id', 32));
        if ($action !== 'state' && !hash_equals($browser['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new Problem('Sitzungsprüfung fehlgeschlagen. Bitte Seite neu laden.', 403);
        $result = match ($action) {
            'state' => $app->state($visit, $browser),
            'start' => $app->start($visit, $input),
            'start_invoice' => $app->startInvoice($visit, $input),
            'switch_method' => $app->switchMethod($visit, $input),
            'confirm_transfer' => $app->confirmTransfer($visit, App::string($input, 'payment_id', 32)),
            'invoice_letter' => $app->invoiceLetter($visit, $input),
            'invoice_later' => $app->invoiceLater($visit, $input),
            'invoice_export' => $app->invoiceExport($visit, App::string($input, 'payment_id', 32)),
            'invoice_print' => $app->invoicePrint($visit, $input),
            'print_settings' => $app->savePrintSettings($visit, $input),
            'status' => $app->poll($visit, App::string($input, 'payment_id', 32)),
            'cancel' => $app->cancel($visit, App::string($input, 'payment_id', 32)),
            'discard' => $app->discard($visit, App::string($input, 'payment_id', 32)),
            'reader_status' => $app->readerAction($visit, App::string($input, 'payment_id', 32)),
            'reader_cancel' => $app->readerAction($visit, App::string($input, 'payment_id', 32), true),
            'document' => $app->document($visit, App::string($input, 'payment_id', 32), ($input['bestaetigt'] ?? false) === true),
            'receipt_share' => $app->receiptShare($visit, App::string($input, 'payment_id', 32)),
            'patient_kontakt' => $app->patientKontakt($visit),
            'signatur_offen' => $app->signaturOffen($visit),
            'receipt_recipient' => $app->receiptRecipient($visit, App::string($input, 'payment_id', 32)),
            'receipt_email' => $app->receiptEmail($visit, $input),
            'receipt_print' => $app->receiptPrint($visit, $input),
            'service_save' => (function () use ($app, $visit, $input) { $app->saveService($visit, $input); return ['ok' => true]; })(),
            'service_delete' => (function () use ($app, $visit, $input) { $app->deleteService($visit, App::string($input, 'id', 32)); return ['ok' => true]; })(),
            'group_save' => (function () use ($app, $visit, $input) { $app->saveGroup($visit, $input); return ['ok' => true]; })(),
            'group_delete' => (function () use ($app, $visit, $input) { $app->deleteGroup($visit, App::string($input, 'id', 32)); return ['ok' => true]; })(),
            'mock' => (function () use ($app, $visit, $input) { $app->mock($visit, $input); return ['ok' => true]; })(),
            default => throw new Problem('Unbekannte Aktion.', 404),
        };
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Problem $e) {
    http_response_code($e->http); echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    // Keine Rohantworten, URLs, Tokens oder Gesundheitsdaten in Logs.
    error_log('kienzle-kasse: ' . get_class($e));
    http_response_code(500); echo json_encode(['error' => 'Der Vorgang konnte nicht verarbeitet werden. Bitte erneut laden; ein angelegter Zahlungsversuch bleibt erhalten.']);
}
