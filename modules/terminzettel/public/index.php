<?php
declare(strict_types=1);
/**
 * Einstieg des Moduls „Terminzettel“ (nginx → php-fpm als ks-terminzettel). Die Basis hat die Anmeldung vorher geprüft
 * (auth_request) und Benutzer, Rolle und Rechte als KIENZLE_* übergeben. Es gibt nur die Einstellungsseite.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleTerminzettel\{Ansicht, Seite};

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');
$root = dirname(__DIR__);
$base = preg_match('#^/[a-z0-9-]{1,31}$#D', (string)($_SERVER['KIENZLE_PFAD'] ?? '')) ? (string)$_SERVER['KIENZLE_PFAD'] : '/terminzettel';
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rel = str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : '/';

function tz_json(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $k = tz_modul();
    if (!$k->recht('layout')) $k->verweigern();
    if (preg_match('#^/assets/([A-Za-z0-9][A-Za-z0-9_.-]{0,80})$#D', $rel, $m)) {
        $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'ppd' => 'application/vnd.cups-ppd'];
        $ext = strtolower(pathinfo($m[1], PATHINFO_EXTENSION));
        $file = $root . '/public/assets/' . $m[1];
        if (!isset($types[$ext]) || !is_file($file)) { http_response_code(404); exit; }
        header('Content-Type: ' . $types[$ext]);
        header('Cache-Control: private, max-age=3600');
        readfile($file);
        exit;
    }
    Seite::syncBasis($k);
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $meldung = $fehler = null;
    if ($rel === '/vorschau' && $method === 'POST') {
        if (!$k->csrfGueltig($_SERVER['HTTP_X_KIENZLE_CSRF'] ?? null)) tz_json(['error' => 'Sitzung abgelaufen. Bitte die Seite neu laden.'], 403);
        try {
            $in = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
            $values = Seite::pruefen(is_array($in['values'] ?? null) ? $in['values'] : []);
            $r = Seite::beispiel(Seite::overlay($values));
            tz_json(['image' => 'data:image/png;base64,' . base64_encode($r['data']), 'notes' => $r['notes']]);
        } catch (InvalidArgumentException | RuntimeException $e) {
            tz_json(['error' => $e->getMessage()], 422);
        }
    }
    if ($rel !== '/' && $rel !== '') { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    if ($method === 'POST') {
        if (!$k->csrfGueltig($_POST['kienzle-csrf'] ?? null)) { http_response_code(403); echo 'Sitzung abgelaufen. Bitte die Seite neu laden.'; exit; }
        try {
            $aktion = (string)($_POST['aktion'] ?? '');
            if ($aktion === 'speichern') {
                Seite::speichern(Seite::pruefen($_POST));
                $meldung = 'Gespeichert.';
            } elseif ($aktion === 'logo') {
                Seite::logoSpeichern(Seite::uploadBytes($_FILES['logo'] ?? null));
                $meldung = 'Logo gespeichert. Es wird gedruckt, wenn „Logo drucken“ angehakt und das Layout gespeichert ist.';
            } elseif ($aktion === 'logo-entfernen') {
                Seite::logoEntfernen();
                $meldung = 'Logo entfernt.';
            } elseif ($aktion === 'testdruck') {
                Seite::testdruck($k, Seite::werte($k->praxis()));
                $meldung = 'Beispiel-Terminzettel an den Bondrucker übergeben (gespeichertes Layout).';
            } else {
                throw new InvalidArgumentException('Unbekannte Aktion.');
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            $fehler = $e->getMessage();
        }
    }
    $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'server')) ?: 'server';
    $host = preg_replace('/:\d+$/', '', $host);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo Ansicht::seite($base, Seite::werte($k->praxis()), $k->csrf(), $k->druckerRollen()['bon'], $meldung, $fehler, $host);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Der Terminzettel ist gerade nicht erreichbar.';
}
