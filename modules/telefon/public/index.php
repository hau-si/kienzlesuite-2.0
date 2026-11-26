<?php
declare(strict_types=1);
/**
 * Einstieg des Moduls „Telefon“ (nginx → php-fpm als ks-telefon). Die Basis hat die Anmeldung geprüft
 * (auth_request) und Benutzer, Rolle und Rechte als KIENZLE_* übergeben.
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleTelefon\{Anlage, Ansicht, Anrufe, Vorgaenge};

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
    $darfSprachmenue = $k->recht('sprachmenue');
    $darfVorgaenge = $k->recht('vorgaenge');
    $darfRufgruppen = $k->recht('rufgruppen');
    $darfAnrufe = $k->recht('anrufe');
    $darfWaehlen = $k->recht('waehlen');
    if (!$darfStatus && !$darfNebenstellen && !$darfSprachmenue && !$darfVorgaenge && !$darfRufgruppen && !$darfAnrufe && !$darfWaehlen) $k->verweigern();
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
    if (preg_match('#^/ansage/([a-z][a-z0-9_]{0,63})\.wav$#D', $rel, $m)) {
        // Erzeugte Ansage zum Anhören auf der Seite „Sprachmenü“ (feste Praxistexte, keine Anruferdaten).
        if (!$darfSprachmenue) $k->verweigern();
        $file = Anlage::ansageDatei($m[1]);
        if ($file === null) { http_response_code(404); exit; }
        header('Content-Type: audio/wav');
        header('Cache-Control: private, no-cache');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    if ($rel === '/wartemusik.wav') {
        if (!$darfSprachmenue) $k->verweigern();
        $file = Anlage::$data . '/kienzlefon/wartemusik/wartemusik.wav16';
        if (!is_file($file) || is_link($file)) { http_response_code(404); exit; }
        header('Content-Type: audio/wav');
        header('Cache-Control: private, no-cache');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    if ($rel === '/wartemusik' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // Eigene Wartemusik: Datei roh im Anfragekörper (Module haben keine Formular-Uploads), Antwort als JSON.
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$darfSprachmenue) { http_response_code(403); echo json_encode(['fehler' => 'Dafür fehlt das Recht.']); exit; }
        if (!$k->csrfGueltig($_SERVER['HTTP_X_KIENZLE_CSRF'] ?? null)) { http_response_code(403); echo json_encode(['fehler' => 'Sitzung abgelaufen. Bitte die Seite neu laden.']); exit; }
        try {
            $bytes = (string)file_get_contents('php://input', false, null, 0, Anlage::WARTEMUSIK_MAX + 1);
            echo json_encode(['ok' => true, 'meldung' => Anlage::wartemusikHochladen(Anlage::laden(), $bytes, $k)], JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException | RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['fehler' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    if ($rel === '') $rel = '/';
    if (preg_match('#^/vorgaenge/ton/([A-Za-z0-9_-]{1,80})/([A-Za-z0-9_-]{1,80}\.wav)$#D', $rel, $m)) {
        // Tonaufnahme eines Anliegens (Patientendaten): nur mit Recht, nicht zwischenspeichern.
        if (!$darfVorgaenge) $k->verweigern();
        $file = Vorgaenge::tonDatei($m[1], $m[2]);
        if ($file === null) { http_response_code(404); exit; }
        header('Content-Type: audio/wav');
        header('Cache-Control: no-store');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
    if ($rel === '/anrufe/stand') {
        // Live-Übersicht als HTML-Ausschnitt (alle paar Sekunden von assets/app.js abgefragt), frisch aus Asterisk.
        if (!$darfAnrufe) $k->verweigern();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo Ansicht::anrufeStand(Anrufe::stand(Anlage::laden()), $k->csrf(), $darfWaehlen);
        exit;
    }
    if ($rel === '/vorgaenge/anzahl') {
        // Für den Hinweis „neuer Vorgang“ auf der Seite (nur Zahlen, keine Inhalte).
        if (!$darfVorgaenge) $k->verweigern();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(Vorgaenge::anzahl());
        exit;
    }
    if (!in_array($rel, ['/', '/einstellungen', '/sprachmenue', '/vorgaenge', '/rufgruppen', '/anrufe'], true)) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    if ($rel === '/rufgruppen' && !$darfRufgruppen) $k->verweigern();
    if ($rel === '/anrufe' && !$darfAnrufe) $k->verweigern();
    if ($rel === '/vorgaenge' && !$darfVorgaenge) $k->verweigern();
    if ($rel === '/einstellungen' && !$darfNebenstellen) $k->verweigern();
    if ($rel === '/sprachmenue' && !$darfSprachmenue) $k->verweigern();
    if ($rel === '/' && !$darfStatus && !$darfNebenstellen && !$darfSprachmenue && !$darfVorgaenge && !$darfRufgruppen && !$darfAnrufe) $k->verweigern();
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

    $meldung = $fehler = null;
    $zugang = null;
    $cfg = Anlage::laden();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$k->csrfGueltig($_POST['kienzle-csrf'] ?? null)) { http_response_code(403); echo 'Sitzung abgelaufen. Bitte die Seite neu laden.'; exit; }
        try {
            $aktion = (string)($_POST['aktion'] ?? '');
            $vorgang = in_array($aktion, ['vorgang-erledigt', 'vorgang-offen', 'vorgang-loeschen', 'vorgang-sms', 'vorgang-uebernehmen', 'vorgang-freigeben', 'vorgang-dringend', 'vorgang-kommentar', 'vorgang-anliegen'], true);
            $nur = ['nebenstelle-speichern', 'sprachmenue-texte', 'wartemusik', 'vorgang-erledigt', 'vorgang-offen', 'vorgang-loeschen', 'vorgang-sms', 'vorgang-uebernehmen', 'vorgang-freigeben', 'vorgang-dringend', 'vorgang-kommentar', 'vorgang-anliegen', 'rufgruppe-schalten', 'anrufen', 'mein-telefon'];
            if (!$admin && !in_array($aktion, $nur, true)) throw new InvalidArgumentException('Das dürfen nur Admins.');
            $erlaubt = match (true) {
                $vorgang => $darfVorgaenge && $rel === '/vorgaenge',
                $aktion === 'rufgruppe-schalten', $aktion === 'rufgruppen-speichern' => $darfRufgruppen && $rel === '/rufgruppen',
                $aktion === 'anrufen' => $darfWaehlen && in_array($rel, ['/anrufe', '/vorgaenge'], true),
                $aktion === 'mein-telefon' => ($darfWaehlen || $darfAnrufe) && $rel === '/anrufe',
                in_array($aktion, ['sprachmenue-texte', 'wartemusik'], true) => $darfSprachmenue && $rel === '/sprachmenue',
                default => $darfNebenstellen,
            };
            if (!$erlaubt) throw new InvalidArgumentException('Dafür fehlt das Recht.');
            $neu = match ($aktion) {
                'sprachmenue-texte', 'wartemusik', 'vorgang-erledigt', 'vorgang-offen', 'vorgang-loeschen', 'vorgang-sms', 'vorgang-uebernehmen', 'vorgang-freigeben', 'vorgang-dringend', 'vorgang-kommentar', 'vorgang-anliegen', 'rufgruppe-schalten', 'rufgruppen-speichern', 'anrufen', 'mein-telefon' => null,
                'whisper-einrichten' => Anlage::whisperWahl($cfg, $_POST),
                'leitung-speichern' => Anlage::leitungSpeichern($cfg, $_POST, $k),
                'leitung-loeschen' => Anlage::leitungLoeschen($cfg, (string)($_POST['id'] ?? '')),
                'zwecke-speichern' => Anlage::zweckeSpeichern($cfg, $_POST),
                'kanaele-speichern' => Anlage::kanaeleSpeichern($cfg, $_POST),
                'netz-speichern' => Anlage::netzSpeichern($cfg, $_POST),
                'sprachmenue-speichern' => Anlage::sprachmenueSpeichern($cfg, $_POST, $k),
                'piper-einrichten', 'piper-entfernen', 'kokoro-einrichten', 'kokoro-entfernen', 'ansagen-erzeugen', 'whisper-entfernen' => null,
                'nebenstellen-anlegen' => Anlage::nebenstellenAnlegen($cfg, (int)($_POST['anzahl'] ?? 0), $k),
                'nebenstelle-speichern' => Anlage::nebenstelleSpeichern($cfg, $_POST),
                'nebenstelle-loeschen' => Anlage::nebenstelleLoeschen($cfg, (string)($_POST['nummer'] ?? '')),
                'passwort-neu' => Anlage::nebenstellePasswortNeu($cfg, (string)($_POST['nummer'] ?? ''), $k),
                'zugang-zeigen' => null,
                default => throw new InvalidArgumentException('Unbekannte Aktion.'),
            };
            if ($aktion === 'anrufen') {
                $meldung = Anrufe::waehlen($k, $cfg, (string)($_POST['nummer'] ?? ''));
            } elseif ($aktion === 'mein-telefon') {
                Anrufe::meinTelefonSetzen($k, $cfg, (string)($_POST['nummer'] ?? ''));
                $meldung = 'Gespeichert. Beim Wählen klingelt zuerst dieses Telefon.';
            } elseif ($aktion === 'rufgruppe-schalten') {
                $meldung = Anlage::rufgruppeSchalten($_POST);
            } elseif ($aktion === 'rufgruppen-speichern') {
                $meldung = Anlage::rufgruppenSpeichern($_POST);
            } elseif ($vorgang) {
                $id = (string)($_POST['id'] ?? '');
                $meldung = match ($aktion) {
                    'vorgang-erledigt' => (function () use ($id) { Vorgaenge::verschieben($id, 'erledigt'); return 'Als erledigt markiert. Erledigte Vorgänge werden nach 7 Tagen gelöscht.'; })(),
                    'vorgang-offen' => (function () use ($id) { Vorgaenge::verschieben($id, 'offen'); return 'Wieder offen.'; })(),
                    'vorgang-loeschen' => Vorgaenge::loeschen($id) ? 'Vorgang endgültig gelöscht.' : 'In den Papierkorb gelegt (wird nach 7 Tagen gelöscht, bis dahin wiederherstellbar).',
                    'vorgang-sms' => (function () use ($k, $id) { Vorgaenge::smsSenden($k, $id, (string)($_POST['an'] ?? ''), (string)($_POST['text'] ?? '')); return 'SMS wird verschickt (innerhalb einer Minute). Der Stand steht an der Karte.'; })(),
                    'vorgang-uebernehmen' => (function () use ($k, $id) { Vorgaenge::uebernehmen($k, $id); return 'Übernommen. Der Vorgang steht jetzt unter „In Bearbeitung“.'; })(),
                    'vorgang-freigeben' => (function () use ($id) { Vorgaenge::freigeben($id); return 'Zurück zu „Neu“.'; })(),
                    'vorgang-dringend' => (function () use ($id) { Vorgaenge::dringend($id, ($_POST['an'] ?? '') === '1'); return ($_POST['an'] ?? '') === '1' ? 'Als dringend markiert.' : 'Nicht mehr dringend.'; })(),
                    'vorgang-kommentar' => (function () use ($k, $id) { Vorgaenge::kommentieren($k, $id, (string)($_POST['text'] ?? '')); return 'Kommentar gespeichert.'; })(),
                    default => (function () use ($k, $id) { Vorgaenge::anliegenAbhaken($k, $id, (int)($_POST['nr'] ?? -1), ($_POST['an'] ?? '') === '1'); return 'Gespeichert.'; })(),
                };
                if ($aktion === 'vorgang-sms' && !empty($_POST['erledigt'])) { Vorgaenge::verschieben($id); $meldung .= ' Vorgang als erledigt markiert.'; }
            } elseif ($aktion === 'whisper-einrichten') {
                Anlage::speichern($neu, $k);
                $cfg = $neu;
                Anlage::auftrag('whisper-einrichten');
                $meldung = 'Whisper wird geladen (' . (($neu['kienzlefon']['whisper_genau'] ?? false) ? 'etwa 5 GB' : 'etwa 2 GB') . ', je nach Leitung 10 bis 60 Minuten). Der Stand steht unter „Sprachmenü“.';
            } elseif ($aktion === 'whisper-entfernen') {
                Anlage::auftrag('whisper-entfernen');
                $meldung = 'Whisper wird entfernt.';
            } elseif ($aktion === 'sprachmenue-texte') {
                $meldung = Anlage::sprachmenueSchreiben($_POST, $k);
            } elseif ($aktion === 'wartemusik') {
                $meldung = Anlage::wartemusikWaehlen($cfg, (string)($_POST['wahl'] ?? ''), $k);
                $cfg = Anlage::laden();
            } elseif (in_array($aktion, ['piper-einrichten', 'piper-entfernen', 'kokoro-einrichten', 'kokoro-entfernen', 'ansagen-erzeugen'], true)) {
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
    Ansicht::$sprachmenue = $darfSprachmenue && (bool)($cfg['kienzlefon']['aktiv'] ?? false);
    if ($rel === '/anrufe') {
        $inhalt = Ansicht::anrufe(Anrufe::stand($cfg), $cfg, $k->csrf(), $darfWaehlen, Anrufe::meinTelefon($k, $cfg), $meldung, $fehler);
    } elseif ($rel === '/rufgruppen') {
        try {
            $daten = Anlage::rufgruppenLesen();
        } catch (RuntimeException $e) {
            $daten = ['bereit' => false];
            $fehler ??= $e->getMessage();
        }
        $inhalt = Ansicht::rufgruppen($daten, $k->csrf(), $admin, $meldung, $fehler);
    } elseif ($rel === '/vorgaenge') {
        $bereich = isset(Vorgaenge::BEREICHE[(string)($_GET['bereich'] ?? '')]) ? (string)$_GET['bereich'] : 'neu';
        try {
            $liste = Vorgaenge::liste($k, $bereich);
        } catch (RuntimeException $e) {
            $liste = [];
            $fehler ??= $e->getMessage();
        }
        $stand = [];
        foreach (Vorgaenge::smsListe() as $vid => $jobs) {
            $letzte = is_array($jobs) ? end($jobs) : null;
            if (is_array($letzte)) $stand[(string)$vid] = $k->nachricht((string)($letzte['job'] ?? '')) + ['zeit' => (int)($letzte['zeit'] ?? 0)];
        }
        $sms = ['bereit' => $k->nachrichtenBereit()['sms'], 'praxis' => trim((string)($k->praxis()['name'] ?? '')), 'stand' => $stand];
        $inhalt = Ansicht::vorgaenge($liste, $bereich, Vorgaenge::anzahl(), $k->csrf(), $meldung, $fehler, $sms + ['ich' => $k->benutzer(), 'waehlen' => $darfWaehlen]);
    } elseif ($rel === '/sprachmenue') {
        try {
            $daten = Anlage::sprachmenueLesen();
        } catch (RuntimeException $e) {
            $daten = ['bereit' => false];
            $fehler ??= $e->getMessage();
        }
        if ($meldung === null && isset($_GET['hochgeladen'])) $meldung = 'Eigene Wartemusik eingerichtet und eingeschaltet.';
        $inhalt = Ansicht::sprachmenue($daten, $k->csrf(), $meldung, $fehler, (string)($cfg['kienzlefon']['wartemusik'] ?? 'klingelzeichen'));
    } elseif ($rel === '/einstellungen') {
        $leitung = $admin && preg_match('/^[a-z0-9]{1,12}$/D', (string)($_GET['leitung'] ?? '')) ? (string)$_GET['leitung'] : null;
        $server = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'Adresse des Servers';
        $inhalt = Ansicht::einstellungen($cfg, $status, $k->csrf(), $admin, $leitung, $zugang, (string)$server, $meldung, $fehler);
    } else {
        Ansicht::$anrufe = $darfAnrufe;
        Ansicht::$rufgruppen = $darfRufgruppen && (bool)($cfg['kienzlefon']['rufgruppen'] ?? false);
        Ansicht::$vorgaenge = $darfVorgaenge && (bool)($cfg['kienzlefon']['aktiv'] ?? false) ? Vorgaenge::anzahlOffen() : null;
        $inhalt = Ansicht::uebersicht($cfg, $status, $darfNebenstellen, tf_fax_bereit(), $meldung, $fehler);
    }
    echo $k->rahmen('Telefon', $inhalt, ['roh' => true, 'kopf' => Ansicht::kopf($base) . '<script src="' . tf_h($base) . '/assets/app.js" defer></script>']);
} catch (Throwable $e) {
    error_log('telefon: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'Die Telefon-Seite ist gerade nicht erreichbar.';
}
