<?php
declare(strict_types=1);
// JSON-Schnittstelle der Einstellungsseite (Anmeldung über Praxisherz, CSRF-Marke von Praxisherz per Kopfzeile).
use KienzleKasse\{App, Problem, SettingsPage};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Problem('POST erforderlich.', 405);
    ks_require_same_site();
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) throw new Problem('Anfrage zu groß.', 413);
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new Problem('JSON erforderlich.', 415);
    $raw = ks_body(65537);
    if (strlen($raw) > 65536) throw new Problem('Anfrage zu groß.', 413);
    try { $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new Problem('Ungültige JSON-Anfrage.'); }
    if (!is_array($input)) throw new Problem('Ungültige Anfrage.');
    $base = ks_base_config();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== rtrim($base->get('app', 'base_url'), '/')) throw new Problem('Unzulässiger Ursprung.', 403);
    $page = new SettingsPage($base);
    $result = $page->handle(App::string($input, 'action', 32), $input, $_SERVER['HTTP_X_KIENZLE_CSRF'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null));
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Problem $e) {
    http_response_code($e->http);
    $out = ['error' => $e->getMessage()];
    // Heikle Änderung ohne frische Bestätigung: die Seite schickt den Browser zur Bestätigung der Basis und wieder zurück.
    if ($e->getMessage() === SettingsPage::CONFIRM_ERROR) $out['bestaetigen'] = KienzleKasse\Suite::modul()->bestaetigenAdresse(ks_url('settings.php'));
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    error_log('kienzle-kasse settings: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500); echo json_encode(['error' => 'Interner Fehler. Details im Systemprotokoll: journalctl -t kienzle-kasse-php.'], JSON_UNESCAPED_UNICODE);
}
