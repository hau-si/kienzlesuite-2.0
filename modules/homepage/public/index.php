<?php
declare(strict_types=1);
/**
 * Einstieg des Moduls „Homepage“ (nginx → php-fpm als ks-homepage). Die Basis hat die Anmeldung vorher geprüft (auth_request)
 * und Benutzer, Rolle und Rechte als KIENZLE_* übergeben.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleHomepage\{Oeffnung, Ansicht, Homepage, Mitteilungen, Praxen, Zeiten};

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header_remove('X-Powered-By');
$root = dirname(__DIR__);
$base = hp_basis();
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rel = str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : '/';
$methode = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

function hp_zeichen(string $text): string { return substr(hash_hmac('sha256', 'meldung:' . $text, hp_modul()->csrf()), 0, 32); }

/** Weiterleitung nach einer Aktion (POST → GET) mit Meldung; Fehlertexte nur mit Unterschrift der Sitzung. */
function hp_weiter(string $ziel, string $ok = '', string $fehler = '', string $anker = ''): never {
    $fehler = mb_substr($fehler, 0, 300);
    $q = array_filter(['ok' => $ok, 'fehler' => $fehler, 's' => $fehler !== '' ? hp_zeichen($fehler) : '']);
    $ziel = preg_replace('/[?&](ok|fehler|s)=[^&#]*/', '', $ziel) ?? $ziel;
    // Für die Seitentests (CLI kennt keine gesendeten Kopfzeilen) zusätzlich merken.
    $GLOBALS['hp_weiter'] = $ziel . ($q ? (str_contains($ziel, '?') ? '&' : '?') . http_build_query($q) : '') . $anker;
    header('Location: ' . $GLOBALS['hp_weiter'], true, 303);
    exit;
}

try {
    $k = hp_modul();
    $r = ['mitteilungen' => $k->recht('mitteilungen'), 'praxen' => $k->recht('praxen'), 'oeffnungszeiten' => $k->recht('oeffnungszeiten'), 'admin' => $k->istAdmin()];
    if (!$r['mitteilungen'] && !$r['praxen'] && !$r['oeffnungszeiten']) $k->verweigern();
    $wer = $k->benutzer();
    if ($wer === '') $k->verweigern();
    Ansicht::$r = $r;
    Ansicht::$csrf = $k->csrf();
    Ansicht::$praxisName = $k->praxis()['name'];
    Ansicht::$zeiten = Zeiten::hinweise($k);
    Ansicht::$zeitenDa = Zeiten::paket($k) !== null;
    // Schreibende Anfragen nur von dieser Seite (zusätzlich zur CSRF-Marke).
    $fremd = !in_array((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin'), ['same-origin', 'none'], true);

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
    if (preg_match('#^/plugin-dateien/(hinweise\.css|oeffnung\.js)$#D', $rel, $m)) {
        // Stil und Skript des WordPress-Plugins für die Vorschau der Öffnungszeiten-Karte (feste Liste)
        header('Content-Type: ' . (str_ends_with($m[1], '.css') ? 'text/css' : 'text/javascript') . '; charset=utf-8');
        header('Cache-Control: private, max-age=3600');
        readfile($root . '/wordpress/praxisherz-hinweise/' . $m[1]);
        exit;
    }
    if ($rel === '/plugin.zip') {
        if (!$r['admin']) $k->verweigern();
        $zip = Homepage::pluginZip();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="praxisherz-hinweise.zip"');
        header('Content-Length: ' . strlen($zip));
        header('Cache-Control: no-store');
        echo $zip;
        exit;
    }
    if ($rel === '/vorschau') {
        // Vorschau beim Tippen: Formular (noch nicht gespeichert) an Stelle der gespeicherten Mitteilung zeigen.
        if ($methode !== 'POST') { http_response_code(405); exit; }
        if ($fremd || !$k->csrfGueltig($_SERVER['HTTP_X_KIENZLE_CSRF'] ?? null)) { http_response_code(403); echo 'Sitzung abgelaufen.'; exit; }
        $datum = is_string($_POST['datum'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $_POST['datum']) ? $_POST['datum'] : Mitteilungen::heute();
        $alle = Mitteilungen::alle();
        $bearbeitet = null;
        if ($r['mitteilungen'] && isset($_POST['art'])) {
            $id = (int)($_POST['id'] ?? 0);
            $neu = ['id' => $id ?: 999999999, 'aktiv' => true] + Mitteilungen::ausFormular($_POST, false);
            $bearbeitet = $neu['id'];
            $ersetzt = false;
            foreach ($alle as $i => $m) if ($m['id'] === $id) { $alle[$i] = $neu; $ersetzt = true; }
            if (!$ersetzt) $alle[] = $neu;
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo Ansicht::vorschau(Ansicht::darstellungen($alle, $datum, $bearbeitet), $datum);
        exit;
    }

    if ($rel === '') $rel = '/';
    if (!in_array($rel, ['/', '/praxen', '/oeffnungszeiten', '/einrichtung'], true)) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    if ($rel === '/einrichtung' && !$r['admin']) $k->verweigern();

    if ($methode === 'POST') {
        if ($fremd || !$k->csrfGueltig($_POST['kienzle-csrf'] ?? null)) { http_response_code(403); echo 'Sitzung abgelaufen. Bitte die Seite neu laden.'; exit; }
        $aktion = (string)($_POST['aktion'] ?? '');
        $hier = $base . ($rel === '/' ? '/' : $rel);
        $id = (int)($_POST['id'] ?? 0);
        try {
            $darf = static fn(bool $b) => $b ?: throw new InvalidArgumentException('Dafür fehlt Ihnen das Recht.');
            switch ($aktion) {
                case 'anlegen':
                    $darf($r['mitteilungen']);
                    $neu = Mitteilungen::speichern(0, $_POST, $wer, !empty($_POST['sofort']));
                    Homepage::nachAenderung($k);
                    hp_weiter($base . '/', 'angelegt', '', '#m' . $neu);
                case 'speichern':
                    $darf($r['mitteilungen']);
                    Mitteilungen::speichern($id, $_POST, $wer);
                    Homepage::nachAenderung($k);
                    hp_weiter($base . '/?id=' . $id, 'gespeichert', '', '#m' . $id);
                case 'schalten':
                    $darf($r['mitteilungen']);
                    Mitteilungen::schalten($id, ($_POST['an'] ?? '') === '1', $wer);
                    Homepage::nachAenderung($k);
                    hp_weiter($base . '/', ($_POST['an'] ?? '') === '1' ? 'an' : 'aus', '', '#m' . $id);
                case 'loeschen':
                    $darf($r['mitteilungen']);
                    Mitteilungen::loeschen($id);
                    Homepage::nachAenderung($k);
                    hp_weiter($base . '/', 'geloescht');
                case 'uebertragen':
                    $darf($r['mitteilungen'] || $r['admin']);
                    $s = Homepage::uebertragen($k);
                    hp_weiter($hier, $s['ok'] ? 'uebertragen' : '', $s['ok'] ? '' : $s['meldung']);
                case 'zeiten':
                    $darf($r['mitteilungen']);
                    Zeiten::speichern($_POST);
                    Homepage::nachAenderung($k);
                    hp_weiter($base . '/', 'zeiten');
                case 'dienst-speichern':
                    $darf($r['oeffnungszeiten']);
                    Oeffnung::dienstSpeichern($id, $_POST);
                    Homepage::nachAenderung($k);
                    hp_weiter($base . '/oeffnungszeiten', 'dienst', '', '#dienst');
                case 'dienst-loeschen':
                    $darf($r['oeffnungszeiten']);
                    Oeffnung::dienstLoeschen($id);
                    Homepage::nachAenderung($k);
                    hp_weiter($base . '/oeffnungszeiten', 'dienst-weg', '', '#dienst');
                case 'notdienst':
                    $darf($r['oeffnungszeiten']);
                    Oeffnung::notdienstSpeichern((string)($_POST['notdienst'] ?? ''));
                    Homepage::nachAenderung($k);
                    hp_weiter($base . '/oeffnungszeiten', 'notdienst', '', '#dienst');
                case 'praxis-speichern':
                    $darf($r['praxen']);
                    $pid = Praxen::speichern($id, $_POST);
                    // Geänderte Anschrift oder Telefonnummer gilt sofort auch auf der Homepage.
                    if ($id > 0) Homepage::nachAenderung($k);
                    hp_weiter($base . '/praxen', 'praxis', '', '#p' . $pid);
                case 'praxis-loeschen':
                    $darf($r['praxen']);
                    Praxen::loeschen($id);
                    Homepage::nachAenderung($k);
                    hp_weiter($base . '/praxen', 'praxis-weg');
                case 'einrichten':
                    $darf($r['admin']);
                    Homepage::einrichten($k, (string)($_POST['url'] ?? ''), (string)($_POST['schluessel'] ?? ''));
                    $s = Homepage::uebertragen($k);
                    hp_weiter($base . '/einrichtung', $s['ok'] ? 'eingerichtet' : '', $s['ok'] ? '' : $s['meldung']);
                case 'trennen':
                    $darf($r['admin']);
                    Homepage::trennen();
                    hp_weiter($base . '/einrichtung', 'getrennt');
                default:
                    throw new InvalidArgumentException('Unbekannte Aktion.');
            }
        } catch (InvalidArgumentException $e) {
            $zurueck = $rel === '/praxen' && $aktion === 'praxis-speichern' ? $base . '/praxen?' . ($id ? 'id=' . $id : 'neu=1') : ($rel === '/' && $id ? $base . '/?id=' . $id : $hier);
            if ($aktion === 'dienst-speichern') $zurueck = $base . '/oeffnungszeiten?dienst=' . ($id ?: 'neu');
            hp_weiter($zurueck, '', $e->getMessage(), $rel === '/' && $id ? '#m' . $id : ($rel === '/oeffnungszeiten' ? '#dienst' : ''));
        }
    }

    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('Content-Type: text/html; charset=utf-8');
    $ok = is_string($_GET['ok'] ?? null) ? $_GET['ok'] : null;
    $fehler = is_string($_GET['fehler'] ?? null) && is_string($_GET['s'] ?? null) && hash_equals(hp_zeichen($_GET['fehler']), $_GET['s']) ? $_GET['fehler'] : null;
    $meldung = Ansicht::meldung($ok, $fehler);
    $inhalt = match ($rel) {
        '/' => Ansicht::start($_GET, $meldung),
        '/praxen' => Ansicht::praxen($_GET, $meldung),
        '/einrichtung' => Ansicht::einrichtung($meldung),
        '/oeffnungszeiten' => Ansicht::oeffnungszeiten($_GET, $meldung, Oeffnung::woche($k), Oeffnung::fuerHomepage($k)),
    };
    echo $k->rahmen('Homepage', $inhalt, ['roh' => true, 'kopf' => Ansicht::kopf($base, $k->csrf(), $rel === '/oeffnungszeiten')]);
} catch (Throwable $e) {
    error_log('homepage: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'Das Modul Homepage ist gerade nicht erreichbar.';
}
