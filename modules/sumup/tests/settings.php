<?php
declare(strict_types=1);
// Einstellungen im Browser: verschlüsselte Überlagerung der Grundwerte, Anmeldung und Rechte über die Suite.
require __DIR__ . '/lib.php';
use KienzleSumup\{Config, Database, Problem, Settings, SettingsPage};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}
function base(): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir() . '/ks-settings-' . bin2hex(random_bytes(6));
    mkdir($v['app']['state_dir'], 0700);
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['invoice_inbox']['key'] = str_repeat('e', 64);
    return new Config($v);
}

$dirs = [];
try {
    $base = base(); $dirs[] = $state = $base->get('app', 'state_dir');
    $db = new Database($state); $db->migrate();

    // Ohne gespeicherte Werte gilt die Datei unverändert.
    check(Settings::effective($base) === $base, 'Ohne Überlagerung nicht die Basiskonfiguration');

    // Praxisdaten speichern: wirksam, verschlüsselt abgelegt, Basis unberührt.
    Settings::save($db, $base, 'practice', ['append_sumup_receipt' => true]);
    $eff = Settings::effective($base);
    check($eff->get('practice', 'append_sumup_receipt') === true, 'Belegeinstellung nicht wirksam');
    check($base->get('practice', 'append_sumup_receipt', false) !== true, 'Basiskonfiguration verändert');
    Settings::save($db, $base, 'practice', ['name' => 'Fremd']);
    check(Settings::effective($base)->get('practice', 'name', '') !== 'Fremd', 'Praxisname darf nicht mehr lokal überschrieben werden');
    $raw = (string)(new PDO('sqlite:' . $state . '/payments.sqlite'))->query("SELECT value FROM settings WHERE key='config_overrides'")->fetchColumn();
    check($raw !== '' && !str_contains($raw, 'Beispielpraxis'), 'Einstellungen unverschlüsselt gespeichert');

    // SumUp live: Geheimnis wird nie angezeigt, leeres Feld behält den Wert.
    $sumup = ['mode' => 'live', 'api_key' => 'sup-geheim', 'affiliate_key' => 'aff-geheim', 'app_id' => 'test.app', 'merchant_code' => 'MTEST', 'reader_id' => 'rdr'];
    Settings::save($db, $base, 'app', ['max_amount_cents' => '250']);
    Settings::save($db, $base, 'sumup', $sumup);
    $eff = Settings::effective($base);
    check($eff->get('app', 'max_amount_cents') === 25000, 'Höchstbetrag nicht in Cent umgerechnet');
    $json = json_encode(Settings::view($eff));
    check(!str_contains($json, 'sup-geheim') && !str_contains($json, 'aff-geheim'), 'Geheimnis in der Ansicht');
    Settings::save($db, $base, 'sumup', ['api_key' => ''] + $sumup);
    check(Settings::effective($base)->get('sumup', 'api_key') === 'sup-geheim', 'Leeres Geheimnisfeld hat den Schlüssel gelöscht');

    // T2med live ohne Verbindung der Suite: abgelehnt.
    problem(fn() => Settings::save($db, $base, 'fhir', ['mode' => 'live', 'entry_code' => 'ZAHLUNG']), 'T2med live ohne Verbindung', 422);
    // Ungültige Eingaben: Klartext-Fehler, nichts gespeichert.
    problem(fn() => Settings::save($db, $base, 'app', ['max_amount_cents' => 'viel']), 'Ungültiger Betrag akzeptiert', 422);
    problem(fn() => Settings::save($db, $base, 'unbekannt', []), 'Unbekannter Bereich akzeptiert', 404);
    check(Settings::effective($base)->get('app', 'max_amount_cents') === 25000, 'Fehlerhafte Eingabe teilweise gespeichert');

    // Bondrucker TM-m30III: Breite nach Modell (Drucker der Suite folgt unten).
    Settings::save($db, $base, 'printing', ['enabled' => true, 'model' => 'tm-m30iii']);
    check(Settings::effective($base)->get('printing', 'model') === $base->get('printing', 'model'), 'Modell darf nicht mehr in der Kasse einstellbar sein (kommt aus der Basis)');
    // Überlagerung kann Grundwerte und Verbindungen nicht ersetzen (nur Felder der Oberfläche).
    check(Settings::merge(['fhir' => ['api_key' => 'aus-der-suite']], ['fhir' => ['api_key' => 'fremd'], 'app' => ['launcher_key' => 'x']]) === ['fhir' => ['api_key' => 'aus-der-suite']], 'Überlagerung ersetzt Grundwerte');

    // Anmeldung über die Suite: ohne Benutzer nichts, Admin des Moduls alles, Mitarbeiter nur ihre Bereiche.
    $root = suite(['KIENZLE_PFAD' => '/kasse'], [], []); $dirs[] = $root;
    $page = new SettingsPage(KienzleSumup\Suite::baseConfig());
    problem(fn() => $page->handle('get', [], TEST_CSRF), 'Einstellungen ohne Anmeldung lesbar', 401);
    $root = suite(admin_server(), ['t2med' => ['base_url' => 'https://t2med.example.org/aps/fhir/api/r4', 'launch_urls' => [], 'api_key' => 'k-geheim', 'ca_pem' => '', 'pin' => '']],
        [['name' => 'Praxisdrucker', 'description' => 'Empfang', 'state' => 'bereit'], ['name' => 'TM-m30III', 'description' => '', 'state' => 'bereit']]); $dirs[] = $root;
    $page = new SettingsPage(KienzleSumup\Suite::baseConfig());
    $st = $page->handle('status', [], null);
    check($st['logged_in'] === true && $st['role'] === 'admin' && $st['csrf'] === TEST_CSRF, 'Status der Suite-Anmeldung falsch');
    $view = $page->handle('get', [], null);
    check(isset($view['sections']) && !str_contains(json_encode($view), 'k-geheim') && !str_contains(json_encode($view), 'sup-geheim'), 'Geheimnis in der Ansicht');
    $printing = array_values(array_filter($view['sections'], fn($x) => $x['id'] === 'printing'))[0]['fields'];
    check(!array_filter($printing, fn($f) => $f['key'] === 'queue'), 'Druckerwahl gehört in die Basis, nicht in die Kasse');
    problem(fn() => $page->handle('save', ['section' => 'practice', 'values' => ['name' => 'X']], 'falsch'), 'Speichern ohne CSRF-Marke der Suite', 403);
    $page->handle('save', ['section' => 'office_printing', 'values' => ['enabled' => true, 'media' => 'A4', 'duplex' => false]], TEST_CSRF);
    check(Settings::effective(KienzleSumup\Suite::baseConfig())->get('office_printing', 'enabled') === true, 'Praxisdrucker nicht gespeichert');
    // T2med live: Adresse und Schlüssel kommen aus der Verbindung der Suite.
    $page->handle('save', ['section' => 'sumup', 'values' => ['mode' => 'live'] + $sumup], TEST_CSRF);
    $page->handle('save', ['section' => 'fhir', 'values' => ['mode' => 'live', 'entry_code' => 'ZAHLUNG']], TEST_CSRF);
    $eff = Settings::effective(KienzleSumup\Suite::baseConfig());
    check($eff->get('fhir', 'api_key') === 'k-geheim' && $eff->get('fhir', 'launch_urls') === ['https://t2med.example.org/aps/fhir/api/r4'], 'T2med-Verbindung der Suite nicht übernommen');
    problem(fn() => $page->handle('save', ['section' => 'mail', 'values' => ['enabled' => true]], TEST_CSRF), 'E-Mail ohne Verbindung eingeschaltet', 422);
    // Heikle Änderung ohne frische Bestätigung: Hinweis auf /bestaetigen.
    $root = suite(admin_server(['KIENZLE_BESTAETIGT' => (string)(time() - 90000)]), [], []); $dirs[] = $root;
    $e = problem(fn() => (new SettingsPage(KienzleSumup\Suite::baseConfig()))->handle('save', ['section' => 'practice', 'values' => ['name' => 'X']], TEST_CSRF), 'Speichern ohne Bestätigung', 403);
    check($e->getMessage() === SettingsPage::CONFIRM_ERROR, 'Falsche Meldung ohne Bestätigung');
    problem(function () { (new SettingsPage(KienzleSumup\Suite::baseConfig()))->clientDownload('windows', fn($h) => null, fn($d) => null); }, 'Paket mit Schlüssel ohne Bestätigung', 403);
    // Leistungen und GOÄ-Positionen.
    $root = suite(admin_server(), [], []); $dirs[] = $root;
    $page = new SettingsPage(KienzleSumup\Suite::baseConfig());
    $c = $page->handle('catalog', [], null);
    problem(fn() => $page->handle('catalog_service_save', ['label' => 'X', 'price' => '10,00'], 'falsch'), 'Leistung ohne CSRF-Marke gespeichert', 403);
    $c = $page->handle('catalog_service_save', ['id' => '', 'label' => 'Ausführliche Beratung', 'price' => '25,00', 'goae_code' => '3', 'fee_type' => 'standard', 'factor' => '2,3'], TEST_CSRF);
    check(count($c['services']) === 1, 'Leistung nicht gespeichert');
    // Rechnungseingang und Bezahlt-Liste.
    $page->handle('save', ['section' => 'invoice_inbox', 'values' => ['enabled' => true, 'max_age_days' => 14, 'label_number' => "Beleg-Nr.\nBeleg Nummer"]], TEST_CSRF);
    check(Settings::effective(KienzleSumup\Suite::baseConfig())->get('invoice_inbox', 'label_number') === ['Beleg-Nr.', 'Beleg Nummer'], 'Textschnipsel nicht gespeichert');
    problem(fn() => $page->handle('inbox_delete', ['id' => str_repeat('a', 32)], 'falsch'), 'Löschen ohne CSRF-Marke', 403);
    problem(fn() => $page->handle('inbox_delete', ['id' => str_repeat('a', 32)], TEST_CSRF), 'Unbekannte Rechnung gelöscht', 404);
    problem(fn() => $page->handle('inbox_letter_many', ['ids' => array_map(fn($i) => sprintf('%032d', $i), range(1, 51))], TEST_CSRF), 'Mehr als 50 Rechnungen', 422);
    problem(fn() => $page->handle('inbox_letter', ['id' => str_repeat('a', 32)], TEST_CSRF), 'Postversand bei ausgeschaltetem Letterxpress', 409);
    check(str_contains(implode("\n", $page->handle('audit', [], null)['lines']), ' anna'), 'Benutzer fehlt im Protokoll');
    // Mitarbeiter: nur die Bereiche ihrer Rechte, keine Einstellungen oder Schlüssel.
    $root = suite(staff_server(['paid', 'inbox']), [], []); $dirs[] = $root;
    $staff = new SettingsPage(KienzleSumup\Suite::baseConfig());
    $sv = $staff->handle('get', [], null);
    check($sv['role'] === 'staff' && $sv['rights'] === ['paid', 'inbox'] && !isset($sv['sections']) && !str_contains(json_encode($sv), 'api_key'), 'Mitarbeiter-Ansicht enthält Einstellungen');
    $staff->handle('t2med_paid', [], null); $staff->handle('inbox_overview', [], null);
    check($staff->handle('inbox_overview', [], null)['letter_enabled'] === false, 'Postversand ohne Recht sichtbar');
    foreach (['save' => ['section' => 'sumup', 'values' => []], 'catalog' => [], 'inbox_letter' => ['id' => 'x'], 'audit' => [], 'test' => ['kind' => 'sumup'], 'catalog_service_delete' => ['id' => 'x']] as $act => $in)
        problem(fn() => $staff->handle($act, $in, TEST_CSRF), "Mitarbeiter darf $act", 403);
    problem(fn() => $staff->handle('t2med_done', ['ids' => []], null), 'Mitarbeiter ohne CSRF-Marke', 403);
    problem(function () use ($staff) { $staff->clientDownload('windows', fn($h) => null, fn($d) => null); }, 'Mitarbeiter lädt Paket mit Schlüssel', 403);
    problem(function () use ($staff) { $staff->taxExport(['kind' => 'steuer', 'year' => '2026'], fn($h) => null, fn($d) => null); }, 'Mitarbeiter ohne Export-Recht exportiert', 403);
    $staff->taxExport(['kind' => 't2med', 'status' => 'open', 'year' => '0', 'month' => '0'], fn($h) => null, fn($d) => null);
    // Kaputte Überlagerung (z. B. andere Version): Datei gilt weiter.
    $db->query("UPDATE settings SET value='kaputt' WHERE key='config_overrides'");
    check(Settings::effective($base) === $base, 'Unlesbare Einstellungen nicht ignoriert');
    echo "Einstellungen, Anmeldung und Rechte über die Suite ok\n";
} finally {
    foreach ($dirs as $dir) rmtree($dir);
}
