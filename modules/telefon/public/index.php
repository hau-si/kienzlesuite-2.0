<?php
declare(strict_types=1);
/**
 * Einstieg des Moduls „Telefon“ (nginx → php-fpm als ks-telefon). Die Basis hat die Anmeldung geprüft
 * (auth_request) und Benutzer, Rolle und Rechte als KIENZLE_* übergeben.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleTelefon\{Anlage, Ansicht};

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');
$root = dirname(__DIR__);
$base = tf_basis();
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rel = str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : '/';

try {
    $k = tf_modul();
    $admin = $k->istAdmin();
    $darfStatus = $k->recht('status');
    $darfNebenstellen = $k->recht('nebenstellen');
    if (!$darfStatus && !$darfNebenstellen) $k->verweigern();
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
    if ($rel === '') $rel = '/';
    if (!in_array($rel, ['/', '/einstellungen'], true)) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    if ($rel === '/einstellungen' && !$darfNebenstellen) $k->verweigern();
    if ($rel === '/' && !$darfStatus && !$darfNebenstellen) $k->verweigern();
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

    $meldung = $fehler = null;
    $zugang = null;
    $cfg = Anlage::laden();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$k->csrfGueltig($_POST['kienzle-csrf'] ?? null)) { http_response_code(403); echo 'Sitzung abgelaufen. Bitte die Seite neu laden.'; exit; }
        try {
            $aktion = (string)($_POST['aktion'] ?? '');
            $nur = ['nebenstelle-speichern'];
            if (!$admin && !in_array($aktion, $nur, true)) throw new InvalidArgumentException('Das dürfen nur Admins.');
            if (!$darfNebenstellen) throw new InvalidArgumentException('Dafür fehlt das Recht.');
            $neu = match ($aktion) {
                'leitung-speichern' => Anlage::leitungSpeichern($cfg, $_POST, $k),
                'leitung-loeschen' => Anlage::leitungLoeschen($cfg, (string)($_POST['id'] ?? '')),
                'zwecke-speichern' => Anlage::zweckeSpeichern($cfg, $_POST),
                'kanaele-speichern' => Anlage::kanaeleSpeichern($cfg, $_POST),
                'netz-speichern' => Anlage::netzSpeichern($cfg, $_POST),
                'sprachmenue-speichern' => Anlage::sprachmenueSpeichern($cfg, $_POST, $k),
                'piper-einrichten', 'piper-entfernen', 'kokoro-einrichten', 'kokoro-entfernen', 'ansagen-erzeugen' => null,
                'nebenstellen-anlegen' => Anlage::nebenstellenAnlegen($cfg, (int)($_POST['anzahl'] ?? 0), $k),
                'nebenstelle-speichern' => Anlage::nebenstelleSpeichern($cfg, $_POST),
                'nebenstelle-loeschen' => Anlage::nebenstelleLoeschen($cfg, (string)($_POST['nummer'] ?? '')),
                'passwort-neu' => Anlage::nebenstellePasswortNeu($cfg, (string)($_POST['nummer'] ?? ''), $k),
                'zugang-zeigen' => null,
                default => throw new InvalidArgumentException('Unbekannte Aktion.'),
            };
            if (in_array($aktion, ['piper-einrichten', 'piper-entfernen', 'kokoro-einrichten', 'kokoro-entfernen', 'ansagen-erzeugen'], true)) {
                Anlage::auftrag($aktion === 'ansagen-erzeugen' ? 'erzeugen' : $aktion, !empty($_POST['alle']));
                $meldung = ['piper-einrichten' => 'Piper wird geladen (etwa 200 MB, einige Minuten). Der Stand steht unter „Sprachmenü“.',
                    'piper-entfernen' => 'Piper wird entfernt.', 'kokoro-einrichten' => 'Kokoro wird geladen (mehrere GB, kann lange dauern). Der Stand steht unter „Sprachmenü“.',
                    'kokoro-entfernen' => 'Kokoro wird entfernt.', 'ansagen-erzeugen' => 'Ansagen werden erzeugt. Der Stand steht unter „Sprachmenü“.'][$aktion];
            } elseif ($aktion === 'zugang-zeigen') {
                // Heikle Anzeige: Passwort eines Telefons nur nach frischer Bestätigung (Passwort+OTP oder Passkey).
                if (!$k->frischBestaetigt(900)) { header('Location: ' . $k->bestaetigenAdresse($base . '/einstellungen#telefone'), true, 303); exit; }
                $nr = (string)($_POST['nummer'] ?? '');
                $zugang = ['nummer' => $nr, 'passwort' => Anlage::nebenstellePasswort($cfg, $nr, $k)];
            } else {
                $r = Anlage::speichern($neu, $k);
                $cfg = $neu;
                $meldung = match ($aktion) {
                    'leitung-loeschen' => 'Leitung gelöscht.', 'nebenstellen-anlegen' => 'Telefone angelegt. Die Zugangsdaten stehen unter „Zugang“.',
                    'nebenstelle-loeschen' => 'Nebenstelle gelöscht.', 'passwort-neu' => 'Neues Passwort vergeben. Das Telefon bitte neu einrichten.',
                    default => 'Gespeichert.',
                } . (($r['neustart'] ?? false) ? ' Die Anlage startet neu, sobald kein Gespräch mehr läuft.' : '')
                  . (!($r['laeuft'] ?? false) ? ' Hinweis: Die Telefonanlage läuft gerade nicht; die Einstellungen gelten beim nächsten Start.' : '');
            }
        } catch (InvalidArgumentException | RuntimeException $e) {
            $fehler = $e->getMessage();
        }
    }
    $status = Anlage::status();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if ($rel === '/einstellungen' && in_array(Anlage::auftragStatus()['stand'] ?? '', ['laeuft', 'wartet'], true)) header('Refresh: 5');
    if ($rel === '/einstellungen') {
        $leitung = $admin && preg_match('/^[a-z0-9]{1,12}$/D', (string)($_GET['leitung'] ?? '')) ? (string)$_GET['leitung'] : null;
        $server = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'Adresse des Servers';
        $inhalt = Ansicht::einstellungen($cfg, $status, $k->csrf(), $admin, $leitung, $zugang, (string)$server, $meldung, $fehler);
    } else {
        $inhalt = Ansicht::uebersicht($cfg, $status, $darfNebenstellen, tf_fax_bereit(), $meldung, $fehler);
    }
    echo $k->rahmen('Telefon', $inhalt, ['roh' => true, 'kopf' => Ansicht::kopf($base) . '<script src="' . tf_h($base) . '/assets/app.js" defer></script>']);
} catch (Throwable $e) {
    error_log('telefon: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'Die Telefon-Seite ist gerade nicht erreichbar.';
}
