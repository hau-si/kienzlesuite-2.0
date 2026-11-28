<?php
declare(strict_types=1);
// Arbeitsplatz-Starter: POST https://<server>/starter/kasse (der Starter-Schlüssel wird von der Basis geprüft, Praxis → Arbeitsplätze).
// Ohne Anmeldung von Praxisherz; erlaubt ist nur „launch“. Antwort: einmaliger Link https://<server>/#launch=<code>&m=kasse,
// den die Basis nach der Anmeldung an /kasse/ weitergibt.
use KienzleKasse\{App, Problem};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Problem('POST erforderlich.', 405);
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') !== '' || ($_SERVER['HTTP_ORIGIN'] ?? '') !== '') throw new Problem('Nur für den Arbeitsplatz-Starter.', 403);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 32768) throw new Problem('Anfrage zu groß.', 413);
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new Problem('JSON erforderlich.', 415);
    $raw = ks_body(32769);
    if (strlen((string)$raw) > 32768) throw new Problem('Anfrage zu groß.', 413);
    try { $input = json_decode((string)$raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new Problem('Ungültige JSON-Anfrage.'); }
    if (!is_array($input)) throw new Problem('Ungültige Anfrage.');
    $app = ks_app();
    // Die Basis hat den Starter-Schlüssel schon geprüft (nginx auth_request); ohne dieses Merkmal wird nichts ausgeführt.
    if (($_SERVER['KIENZLE_STARTER_GEPRUEFT'] ?? '') !== '1') throw new Problem('Starter nicht autorisiert.', 401);
    // Felder aus dem T2med-Aufruf: context_id, fhir_base_url, oauth_token (dazu action = launch).
    $antwort = $app->launch($input);
    if (isset($antwort['url']) && is_string($antwort['url'])) $antwort['url'] .= '&m=kasse';   // core.js leitet an das Modul weiter
    echo json_encode($antwort, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Problem $e) {
    http_response_code($e->http); echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('kienzle-kasse starter: ' . get_class($e));
    http_response_code(500); echo json_encode(['error' => 'Der Start konnte nicht verarbeitet werden. Bitte erneut aus T2med öffnen.'], JSON_UNESCAPED_UNICODE);
}
