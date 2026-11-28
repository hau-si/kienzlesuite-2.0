<?php
declare(strict_types=1);
/**
 * Einstieg des Moduls „Qualitätsmanagement“ (nginx → php-fpm als ks-qm). Die Basis hat die Anmeldung vorher geprüft (auth_request)
 * und Benutzer, Rolle, Rechte und die Personenliste als KIENZLE_* übergeben.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleQm\{Ansicht, Dokumente, Gerueste, Import};

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header_remove('X-Powered-By');
$root = dirname(__DIR__);
$base = qm_basis();
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rel = str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : '/';
$methode = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

/** JSON-Antwort für die Hilfsadressen (Bild, Import). */
function qm_json(array $d, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Körper einer rohen Anfrage (Bild, Import); in Tests (CLI) von der Standardeingabe. */
function qm_eingabe(int $max): string { return (string)file_get_contents(PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input', false, null, 0, $max); }

function qm_zeichen(string $text): string { return substr(hash_hmac('sha256', 'meldung:' . $text, qm_modul()->csrf()), 0, 32); }

/** Weiterleitung nach einer Aktion (POST → GET) mit Meldung. */
function qm_weiter(string $ziel, string $ok = '', string $fehler = ''): never {
    $fehler = mb_substr($fehler, 0, 300);
    // Fehlertext nur mit Unterschrift der Sitzung anzeigen, damit fremde Links keine Meldungen in die Seite schreiben.
    $q = array_filter(['ok' => $ok, 'fehler' => $fehler, 's' => $fehler !== '' ? qm_zeichen($fehler) : '']);
    $ziel = preg_replace('/[?&](ok|fehler|s)=[^&]*/', '', $ziel) ?? $ziel;
    header('Location: ' . $ziel . ($q ? (str_contains($ziel, '?') ? '&' : '?') . http_build_query($q) : ''), true, 303);
    exit;
}

try {
    $k = qm_modul();
    $recht = static fn(string $r): bool => $k->recht($r);
    $r = ['lesen' => true, 'schreiben' => $recht('schreiben'), 'vidieren' => $recht('vidieren'), 'vorlegen' => $recht('vorlegen'), 'admin' => $k->istAdmin()];
    if (!$k->recht('lesen') && !$r['schreiben'] && !$r['vidieren'] && !$r['vorlegen']) $k->verweigern();
    $wer = ['login' => $k->benutzer(), 'name' => $k->name() !== '' ? $k->name() : $k->benutzer()];
    if ($wer['login'] === '') $k->verweigern();
    Ansicht::$r = $r;
    Ansicht::$csrf = $k->csrf();
    Ansicht::$login = $wer['login'];
    Ansicht::$name = $wer['name'];
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
    if (preg_match('#^/bild/([0-9a-f]{64}\.(png|jpg|gif|webp))$#D', $rel, $m)) {
        $datei = Dokumente::bildDatei($m[1]);
        if (!$datei) { http_response_code(404); exit; }
        header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$m[2]]);
        header('Cache-Control: private, max-age=31536000, immutable');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($datei);
        exit;
    }
    if ($rel === '/bild') {
        // Roher Upload (nicht multipart): Für die PHP-Instanz der Module ist file_uploads aus.
        if ($methode !== 'POST') qm_json(['error' => 'Nur POST.'], 405);
        if (!$r['schreiben']) qm_json(['error' => 'Kein Recht, Dokumente zu bearbeiten.'], 403);
        if ($fremd || !$k->csrfGueltig($_SERVER['HTTP_X_KIENZLE_CSRF'] ?? null)) qm_json(['error' => 'Sitzung abgelaufen. Bitte die Seite neu laden.'], 403);
        try {
            qm_json(['url' => Dokumente::bildSpeichern(qm_eingabe(Dokumente::MAX_BILD + 1))]);
        } catch (InvalidArgumentException $e) {
            qm_json(['error' => $e->getMessage()], 422);
        }
    }
    if ($rel === '/import' && $methode === 'POST') {
        if (!$r['schreiben']) qm_json(['error' => 'Kein Recht, Dokumente anzulegen.'], 403);
        if ($fremd || !$k->csrfGueltig($_SERVER['HTTP_X_KIENZLE_CSRF'] ?? null)) qm_json(['error' => 'Sitzung abgelaufen. Bitte die Seite neu laden.'], 403);
        try {
            $pfad = rawurldecode((string)($_SERVER['HTTP_X_QM_PFAD'] ?? ''));
            $ordner = (int)($_SERVER['HTTP_X_QM_ORDNER'] ?? 0) ?: null;
            $tags = Dokumente::tagsLesen(rawurldecode((string)($_SERVER['HTTP_X_QM_TAGS'] ?? '')));
            $bytes = qm_eingabe(Import::MAX_UPLOAD + 1);
            @set_time_limit(110);
            qm_json(Import::datei($bytes, $pfad, $ordner, $tags, $wer, ($_SERVER['HTTP_X_QM_STRUKTUR'] ?? '1') === '1'));
        } catch (InvalidArgumentException $e) {
            qm_json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('qm import: ' . get_class($e));
            qm_json(['error' => 'Die Datei konnte nicht übernommen werden.'], 500);
        }
    }

    if ($rel === '') $rel = '/';
    $dokId = preg_match('#^/(dokument|druck)/([0-9]{1,9})$#D', $rel, $m) ? (int)$m[2] : 0;
    $seite = $dokId ? $m[1] : $rel;
    if (!in_array($seite, ['/', '/handbuch', '/neu', '/import', 'dokument', 'druck'], true)) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    if (in_array($seite, ['/neu', '/import'], true) && !$r['schreiben']) $k->verweigern();
    $dok = $dokId ? Dokumente::dokument($dokId) : null;
    if ($dokId && (!$dok || ($dok['gueltig'] === null && !$r['schreiben'] && !$r['vidieren']))) { http_response_code(404); echo 'Dokument nicht gefunden.'; exit; }

    if ($methode === 'POST') {
        if ($fremd || !$k->csrfGueltig($_POST['kienzle-csrf'] ?? null)) { http_response_code(403); echo 'Sitzung abgelaufen. Bitte die Seite neu laden.'; exit; }
        $aktion = (string)($_POST['aktion'] ?? '');
        $hier = (string)($_SERVER['REQUEST_URI'] ?? $base . '/');
        $hier = str_starts_with($hier, $base . '/') ? $hier : $base . '/';
        $zuDok = $dokId ? $base . '/dokument/' . $dokId : $base . '/';
        try {
            $darf = static fn(bool $b) => $b ?: throw new InvalidArgumentException('Dafür fehlt Ihnen das Recht.');
            switch ($aktion) {
                case 'anlegen':
                    $darf($r['schreiben']);
                    $g = Gerueste::alle()[(string)($_POST['geruest'] ?? '')] ?? null;
                    $titel = trim((string)($_POST['titel'] ?? ''));
                    $id = Dokumente::anlegen(['titel' => $titel !== '' ? $titel : ($g['titel'] ?? ''), 'ordner' => $_POST['ordner'] ?? 0, 'tags' => (string)($_POST['tags'] ?? '') !== '' ? $_POST['tags'] : ($g['tags'] ?? ''),
                        'pruef_monate' => $_POST['pruef_monate'] ?? 12, 'inhalt' => $g['inhalt'] ?? ''], $wer, $g ? 'geruest:' . $_POST['geruest'] : '');
                    qm_weiter($base . '/dokument/' . $id . '?bearbeiten=1', 'angelegt');
                case 'speichern':
                    $darf($r['schreiben'] && $dok !== null);
                    Dokumente::eigenschaftenSpeichern($dokId, $_POST, $wer);
                    Dokumente::inhaltSpeichern($dokId, (string)($_POST['inhalt'] ?? ''), (string)($_POST['notiz'] ?? ''), $wer);
                    qm_weiter($zuDok . '?ansicht=entwurf', 'gespeichert');
                case 'einreichen':
                    $darf($r['schreiben'] && $dok !== null);
                    Dokumente::einreichen($dokId, $wer);
                    qm_weiter($zuDok . '?ansicht=entwurf', 'eingereicht');
                case 'vidieren':
                    $darf($r['vidieren'] && $dok !== null);
                    Dokumente::vidieren($dokId, $wer, trim((string)($_POST['kuerzel'] ?? '')), null, !empty($_POST['erneut']));
                    qm_weiter($zuDok, 'vidiert');
                case 'zurueckweisen':
                    $darf($r['vidieren'] && $dok !== null);
                    Dokumente::zurueckweisen($dokId, (string)($_POST['grund'] ?? ''), $wer);
                    qm_weiter($zuDok . '?ansicht=entwurf', 'zurueck');
                case 'verwerfen':
                    $darf($r['schreiben'] && $dok !== null);
                    $weg = Dokumente::entwurfVerwerfen($dokId, $wer);
                    qm_weiter($weg ? $base . '/handbuch' : $zuDok, $weg ? 'geloescht' : 'verworfen');
                case 'vorlegen':
                    $darf($r['vorlegen']);
                    $personen = $k->personen();
                    $ids = $dokId ? [$dokId] : array_map('intval', is_array($_POST['ids'] ?? null) ? $_POST['ids'] : []);
                    Dokumente::vorlegen($ids, Ansicht::empfaenger($_POST, $personen), $personen, (string)($_POST['frist'] ?? ''), $wer);
                    qm_weiter($hier, 'vorgelegt');
                case 'gelesen':
                    $darf($dok !== null);
                    Dokumente::gelesen($dokId, (int)($_POST['version'] ?? 0), $wer);
                    qm_weiter($zuDok, 'gelesen');
                case 'geprueft':
                    $darf($r['vidieren'] && $dok !== null);
                    Dokumente::geprueft($dokId, $wer);
                    qm_weiter($zuDok, 'geprueft');
                case 'archivieren':
                case 'wiederherstellen':
                    $darf($r['vidieren'] && $dok !== null);
                    Dokumente::archivieren($dokId, $aktion === 'archivieren', $wer);
                    qm_weiter($zuDok, $aktion === 'archivieren' ? 'archiviert' : 'wiederhergestellt');
                case 'ordner-anlegen':
                    $darf($r['schreiben']);
                    $neu = Dokumente::ordnerAnlegen((string)($_POST['name'] ?? ''), (int)($_POST['eltern'] ?? 0) ?: null);
                    qm_weiter($base . '/handbuch?ordner=' . $neu, 'ordner');
                case 'ordner-aendern':
                    $darf($r['schreiben']);
                    $oid = (int)($_POST['id'] ?? 0);
                    Dokumente::ordnerUmbenennen($oid, (string)($_POST['name'] ?? ''));
                    Dokumente::ordnerVerschieben($oid, (int)($_POST['eltern'] ?? 0) ?: null);
                    qm_weiter($base . '/handbuch?ordner=' . $oid, 'ordner');
                case 'ordner-loeschen':
                    $darf($r['schreiben']);
                    Dokumente::ordnerLoeschen((int)($_POST['id'] ?? 0));
                    qm_weiter($base . '/handbuch', 'ordner-weg');
                default:
                    throw new InvalidArgumentException('Unbekannte Aktion.');
            }
        } catch (InvalidArgumentException $e) {
            qm_weiter($hier, '', $e->getMessage());
        }
    }

    header('Cache-Control: no-store');
    if ($seite === 'druck') {
        $v = isset($_GET['v']) && ($r['schreiben'] || $r['vidieren'] || $r['vorlegen']) ? Dokumente::version((int)$_GET['v']) : null;
        if (!$v || (int)$v['dokument'] !== $dokId || !in_array($v['status'], ['freigegeben', 'ersetzt'], true)) $v = $dok['gueltig'] !== null ? Dokumente::version((int)$dok['gueltig']) : null;
        if (!$v) { http_response_code(404); echo 'Keine freigegebene Fassung.'; exit; }
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        header('Content-Type: text/html; charset=utf-8');
        echo Ansicht::druck($dok, $v, $k->praxis());
        exit;
    }
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('Content-Type: text/html; charset=utf-8');
    $ok = is_string($_GET['ok'] ?? null) ? $_GET['ok'] : null;
    $fehler = is_string($_GET['fehler'] ?? null) && is_string($_GET['s'] ?? null) && hash_equals(qm_zeichen($_GET['fehler']), $_GET['s']) ? $_GET['fehler'] : null;
    $meldung = Ansicht::meldung($ok, $fehler);
    $inhalt = match ($seite) {
        '/' => Ansicht::start($meldung),
        '/handbuch' => Ansicht::handbuch($_GET, $meldung, $r['vorlegen'] ? $k->personen() : []),
        '/neu' => Ansicht::neu((int)($_GET['ordner'] ?? 0), $meldung),
        '/import' => Ansicht::import($meldung),
        'dokument' => Ansicht::dokument($dok, $_GET, $meldung, $r['vorlegen'] ? $k->personen() : []),
    };
    echo $k->rahmen('Qualitätsmanagement', $inhalt, ['roh' => true, 'kopf' => Ansicht::kopf($base, $k->csrf())]);
} catch (Throwable $e) {
    error_log('qm: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'Das QM-Handbuch ist gerade nicht erreichbar.';
}
