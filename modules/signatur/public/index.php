<?php
declare(strict_types=1);
/**
 * Einstieg des Moduls „Signatur“ (nginx → php-fpm als ks-signatur). Die Basis hat die Anmeldung vorher geprüft
 * (auth_request) und Benutzer, Rolle und Rechte als KIENZLE_* übergeben.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSignatur\{Ansicht, Pad, Seite, Start};

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');
$root = dirname(__DIR__);
$base = preg_match('#^/[a-z0-9-]{1,31}$#D', (string)($_SERVER['KIENZLE_PFAD'] ?? '')) ? (string)$_SERVER['KIENZLE_PFAD'] : '/signatur';
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rel = str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : '/';

if (($_SERVER['KIENZLE_OEFFENTLICH'] ?? '') === 'starter') {
    // POST /starter/signatur: nur mit der Prüfung der Basis (auth_request), nie direkt aus dem Browser.
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    $antwort = static function (array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
    try {
        if (($_SERVER['KIENZLE_STARTER_GEPRUEFT'] ?? '') !== '1') $antwort(['error' => 'Starter nicht autorisiert.'], 401);
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $antwort(['error' => 'POST erforderlich.'], 405);
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') !== '' || ($_SERVER['HTTP_ORIGIN'] ?? '') !== '') $antwort(['error' => 'Nur für den Arbeitsplatz-Starter.'], 403);
        if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) $antwort(['error' => 'JSON erforderlich.'], 415);
        $roh = (string)file_get_contents('php://input', false, null, 0, 32769);
        $eingabe = strlen($roh) <= 32768 ? json_decode($roh, true) : null;
        if (!is_array($eingabe)) $antwort(['error' => 'Ungültige Anfrage.'], 400);
        $verbindung = sg_modul()->verbindung('t2med') ?? [];
        $ca = '';
        if (trim((string)($verbindung['ca_pem'] ?? '')) !== '') {
            $ca = KienzleSignatur\Speicher::$data . '/t2med-ca.pem';
            if (!is_file($ca) || file_get_contents($ca) !== trim((string)$verbindung['ca_pem']) . "\n") { file_put_contents($ca, trim((string)$verbindung['ca_pem']) . "\n"); chmod($ca, 0600); }
        }
        $antwort(KienzleSignatur\Start::anlegen($eingabe, new KienzleSignatur\Fhir($verbindung, $ca), (string)($_SERVER['HTTP_HOST'] ?? '')));
    } catch (InvalidArgumentException | RuntimeException $e) {
        $antwort(['error' => $e->getMessage()], 422);
    } catch (Throwable $e) {
        error_log('signatur starter: ' . get_class($e));
        $antwort(['error' => 'Der Start konnte nicht verarbeitet werden. Bitte erneut aus T2med öffnen.'], 500);
    }
}

try {
    $k = sg_modul();
    $darfVorgang = $k->recht('vorgaenge');
    $darfVorlage = $k->recht('vorlagen');
    if (!$darfVorgang && !$darfVorlage) $k->verweigern();
    if (preg_match('#^/assets/([A-Za-z0-9][A-Za-z0-9_.-]{0,80})$#D', $rel, $m)) {
        $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8'];
        $ext = strtolower(pathinfo($m[1], PATHINFO_EXTENSION));
        $file = $root . '/public/assets/' . $m[1];
        if (!isset($types[$ext]) || !is_file($file)) { http_response_code(404); exit; }
        header('Content-Type: ' . $types[$ext]);
        header('Cache-Control: private, max-age=3600');
        readfile($file);
        exit;
    }
    if ($rel === '/start-einloesen') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$darfVorgang || !$k->csrfGueltig($_POST['kienzle-csrf'] ?? null)) { http_response_code(403); echo '{"error":"Nicht erlaubt."}'; exit; }
        $z = Start::einloesen((string)($_POST['code'] ?? ''));
        if (!$z) { http_response_code(410); echo '{"error":"Startlink abgelaufen oder schon verwendet. Bitte erneut aus T2med öffnen."}'; exit; }
        echo json_encode($z, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($rel === '/pad-hochladen') {
        // Roher Upload (nicht multipart): Für die PHP-Instanz der Module ist file_uploads aus.
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        $antwort = static function (array $d, int $code = 200): never { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $antwort(['error' => 'Nur POST.'], 405);
        if (!$k->istAdmin()) $antwort(['error' => 'Nur Admins dürfen die Pad-Software hochladen.'], 403);
        if (!$k->csrfGueltig($_SERVER['HTTP_X_KIENZLE_CSRF'] ?? null)) $antwort(['error' => 'Sitzung abgelaufen. Bitte die Seite neu laden.'], 403);
        try {
            $bytes = (string)file_get_contents('php://input', false, null, 0, Pad::MAX_ZIP + 1);
            $stand = Pad::installieren($bytes, $k->benutzer(), ($_SERVER['HTTP_X_LIZENZ'] ?? '') === '1');
            $antwort(['ok' => true, 'version' => $stand['version']]);
        } catch (InvalidArgumentException $e) {
            $antwort(['error' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            $antwort(['error' => $e->getMessage()], 500);
        } catch (Throwable $e) {
            error_log('signatur pad-hochladen: ' . get_class($e) . ': ' . $e->getMessage());
            $antwort(['error' => 'Interner Fehler (' . get_class($e) . '): ' . $e->getMessage()], 500);
        }
    }
    if ($rel === '') $rel = '/';
    if (!in_array($rel, ['/', '/vorgaenge', '/einstellungen'], true)) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    if ($rel === '/einstellungen' && !$darfVorlage && !$k->istAdmin()) $k->verweigern();
    if ($rel !== '/einstellungen' && !$darfVorgang) $k->verweigern();
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    $meldung = $fehler = null;
    $bearbeite = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$k->csrfGueltig($_POST['kienzle-csrf'] ?? null)) { http_response_code(403); echo 'Sitzung abgelaufen. Bitte die Seite neu laden.'; exit; }
        try {
            $aktion = (string)($_POST['aktion'] ?? '');
            if ($aktion === 'neu' && $darfVorgang) {
                Seite::vorgangAnlegen($_POST, $k->benutzer(), $k->praxis(), (string)($_POST['kontext'] ?? ''));
                $meldung = 'Vorgang angelegt. Er wartet auf die Unterschrift.';
            } elseif ($aktion === 'am-pad' && $darfVorgang) {
                Seite::amPadZeigen((int)($_POST['id'] ?? 0));
                $meldung = 'Der Vorgang erscheint jetzt am Pad.';
            } elseif ($aktion === 'vom-pad' && $darfVorgang) {
                Seite::vomPadNehmen((int)($_POST['id'] ?? 0));
                $meldung = 'Vorgang vom Pad genommen.';
            } elseif ($aktion === 'vorgang-loeschen' && $darfVorgang) {
                Seite::vorgangLoeschen((int)($_POST['id'] ?? 0));
                $meldung = 'Vorgang gelöscht.';
            } elseif ($aktion === 'abbrechen' && $darfVorgang) {
                Seite::abbrechen((int)($_POST['id'] ?? 0));
                $meldung = 'Vorgang abgebrochen.';
            } elseif ($aktion === 'vorlage-speichern' && $darfVorlage) {
                try {
                    Seite::vorlageSpeichern($_POST);
                    $meldung = 'Vorlage gespeichert.';
                } catch (InvalidArgumentException $e) {
                    $bearbeite = ['id' => (int)($_POST['id'] ?? 0), 'art' => (string)($_POST['art'] ?? 'sonstige'), 'titel' => (string)($_POST['titel'] ?? ''), 'text' => (string)($_POST['text'] ?? ''), 'aktiv' => !empty($_POST['aktiv']) ? 1 : 0, 'muster' => 0];
                    throw $e;
                }
            } elseif ($aktion === 'vorlage-loeschen' && $darfVorlage) {
                Seite::vorlageLoeschen((int)($_POST['id'] ?? 0));
                $meldung = 'Vorlage gelöscht.';
            } elseif ($aktion === 'pad-speichern' && $k->istAdmin()) {
                Seite::padSpeichern($_POST);
                $meldung = 'Pad gespeichert.';
            } elseif ($aktion === 'pad-loeschen' && $k->istAdmin()) {
                Seite::padLoeschen((int)($_POST['id'] ?? 0));
                $meldung = 'Pad gelöscht. Wartende Vorgänge dafür brauchen ein neues Pad.';
            } elseif ($aktion === 'pad-entfernen' && $k->istAdmin()) {
                Pad::entfernen();
                $meldung = 'Pad-Software entfernt.';
            } else {
                throw new InvalidArgumentException('Unbekannte Aktion oder fehlendes Recht.');
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            $fehler = $e->getMessage();
        }
    }
    Seite::aufraeumen();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $k->rahmen('Signatur', Ansicht::seite($rel, $k->csrf(), Seite::vorgaenge(), Seite::vorlagen(), $darfVorgang, $darfVorlage, $meldung, $fehler, $bearbeite, $k->istAdmin() ? Pad::status() : null, Seite::pads(), Seite::aktuellerVorgang()), ['roh' => true, 'kopf' => Ansicht::kopf($base, $k->csrf())]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Die Signatur-Seite ist gerade nicht erreichbar.';
}
