<?php
declare(strict_types=1);
/**
 * Einstieg des Moduls „Unterschrift“ (nginx → php-fpm als ks-unterschrift). Die Basis hat die Anmeldung vorher geprüft
 * (auth_request) und Benutzer, Rolle und Rechte als KIENZLE_* übergeben.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleUnterschrift\{Ansicht, Seite};

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');
$root = dirname(__DIR__);
$base = preg_match('#^/[a-z0-9-]{1,31}$#D', (string)($_SERVER['KIENZLE_PFAD'] ?? '')) ? (string)$_SERVER['KIENZLE_PFAD'] : '/unterschrift';
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rel = str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : '/';

try {
    $k = us_modul();
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
    if ($rel !== '/' && $rel !== '') { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    $meldung = $fehler = null;
    $bearbeite = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$k->csrfGueltig($_POST['kienzle-csrf'] ?? null)) { http_response_code(403); echo 'Sitzung abgelaufen. Bitte die Seite neu laden.'; exit; }
        try {
            $aktion = (string)($_POST['aktion'] ?? '');
            if ($aktion === 'neu' && $darfVorgang) {
                Seite::vorgangAnlegen($_POST, $k->benutzer(), $k->praxis());
                $meldung = 'Vorgang angelegt. Er wartet auf die Unterschrift.';
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
    echo $k->rahmen('Unterschrift', Ansicht::seite($k->csrf(), Seite::vorgaenge(), Seite::vorlagen(), $darfVorgang, $darfVorlage, $meldung, $fehler, $bearbeite), ['roh' => true, 'kopf' => Ansicht::kopf($base, $k->csrf())]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Die Unterschrift-Seite ist gerade nicht erreichbar.';
}
