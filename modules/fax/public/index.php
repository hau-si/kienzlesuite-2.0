<?php
declare(strict_types=1);
/**
 * Einstieg des Moduls „Fax“ (nginx → php-fpm als ks-fax). Die Basis hat die Anmeldung geprüft (auth_request)
 * und Benutzer, Rolle und Rechte als KIENZLE_* übergeben. Übernommen aus kienzlefax.php (Kienzlefax 3.5.0).
 */
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleFax\{Ansicht, Fax};

header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');
$root = dirname(__DIR__);
$base = fx_basis();
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$rel = str_starts_with($path, $base . '/') ? substr($path, strlen($base)) : '/';
if ($rel === '') $rel = '/';

try {
    $k = fx_modul();
    $rechte = ['admin' => $k->istAdmin(), 'senden' => $k->recht('senden'), 'eingang' => $k->recht('eingang'), 'telefonbuch' => $k->recht('telefonbuch')];
    if (!$rechte['senden'] && !$rechte['eingang'] && !$rechte['telefonbuch']) $k->verweigern();
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
    if ($rel === '/hochladen' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // Eine Datei roh im Anfragekörper (assets/app.js), Name in X-Dateiname (URL-kodiert); Antwort als JSON.
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!$rechte['senden']) { http_response_code(403); echo json_encode(['ok' => false, 'fehler' => 'Dafür fehlt das Recht.']); exit; }
        if (!$k->csrfGueltig($_SERVER['HTTP_X_KIENZLE_CSRF'] ?? null)) { http_response_code(403); echo json_encode(['ok' => false, 'fehler' => 'Sitzung abgelaufen. Bitte die Seite neu laden.']); exit; }
        try {
            $datei = Fax::hochladenRoh((string)file_get_contents('php://input', false, null, 0, Fax::UPLOAD_MAX + 1), rawurldecode((string)($_SERVER['HTTP_X_DATEINAME'] ?? '')));
            echo json_encode(['ok' => true, 'datei' => $datei], JSON_UNESCAPED_UNICODE);
        } catch (InvalidArgumentException | RuntimeException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'fehler' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    Fax::abholen();
    if ($rel === '/status') {
        if (!$rechte['senden']) $k->verweigern();
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo Ansicht::aktiveListe(Fax::aktive(), $k->csrf());
        exit;
    }
    if ($rel === '/pdf') {
        // PDF anzeigen: Eingang braucht „eingang“, alles andere „senden“. Nur Dateien aus den Ordnern des Moduls.
        $art = (string)($_GET['art'] ?? '');
        if (!($art === 'eingang' ? $rechte['eingang'] : $rechte['senden'])) $k->verweigern();
        $datei = (string)($_GET['datei'] ?? '');
        $pfad = Fax::pdfPfad($art, $datei, (string)($_GET['quelle'] ?? ''));
        if ($art === 'eingang') Fax::alsGelesen(substr($datei, 0, -4));
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $datei) . '"');
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; plugin-types application/pdf; frame-ancestors 'self'");
        header('Content-Length: ' . filesize($pfad));
        readfile($pfad);
        exit;
    }
    if ($rel === '/vorschau') {
        // Oberer Teil der ersten Seite als Bild (Fax senden: ?dok=quelle/datei; Faxeingang beim Überfahren: ?art=eingang&datei=…).
        $art = (string)($_GET['art'] ?? '');
        if (!($art === 'eingang' ? $rechte['eingang'] : $rechte['senden'])) $k->verweigern();
        $pfad = $art === 'eingang' ? Fax::pdfPfad('eingang', (string)($_GET['datei'] ?? '')) : Fax::dokument((string)($_GET['dok'] ?? ''))['pfad'];
        try { $png = Fax::vorschau($pfad); } catch (RuntimeException) { http_response_code(404); exit; }
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=600');
        header("Content-Security-Policy: default-src 'none'");
        echo $png;
        exit;
    }
    if (!isset(Ansicht::SEITEN[$rel])) { http_response_code(404); echo 'Nicht gefunden.'; exit; }
    if (!Ansicht::darf($rel, $rechte)) $k->verweigern();
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

    $meldung = $fehler = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$k->csrfGueltig($_POST['kienzle-csrf'] ?? null)) { http_response_code(403); echo 'Sitzung abgelaufen. Bitte die Seite neu laden.'; exit; }
        try {
            $aktion = (string)($_POST['aktion'] ?? '');
            $braucht = match ($aktion) {
                'senden', 'hochladen', 'quelle-loeschen', 'abbrechen', 'bericht-loeschen', 'fehler-uebernehmen', 'bericht-erneut-senden' => 'senden',
                'eingang-loeschen' => 'eingang', 'kontakt-speichern', 'kontakt-loeschen', 'kontakt-favorit' => 'telefonbuch',
                'einstellungen-speichern', 'aufbewahrung-speichern', 'drucker-speichern', 'nas-speichern', 'benachrichtigung-speichern', 'ocr-speichern' => 'admin',
                default => throw new InvalidArgumentException('Unbekannte Aktion.'),
            };
            if (!$rechte[$braucht]) throw new InvalidArgumentException('Dafür fehlt das Recht.');
            $dateien = array_values(array_filter((array)($_POST['dateien'] ?? []), 'is_string'));
            // Auswahl „quelle/datei“; die Reihenfolge des Anhakens kommt aus „reihenfolge“ (JavaScript), sonst gilt die Liste.
            $auswahl = array_values(array_filter((array)($_POST['auswahl'] ?? []), 'is_string'));
            $folge = array_values(array_filter(explode("\n", (string)($_POST['reihenfolge'] ?? '')), fn($x) => $x !== ''));
            if ($folge && count($folge) === count($auswahl) && !array_diff($folge, $auswahl)) $auswahl = $folge;
            if (!$auswahl && $dateien) $auswahl = array_map(fn($x) => (string)($_POST['quelle'] ?? '') . '/' . $x, $dateien);
            $meldung = match ($aktion) {
                'senden' => (function () use ($auswahl, $k) {
                    $n = Fax::sendenAuswahl($auswahl, Fax::empfaenger((array)($_POST['empfaenger'] ?? [])), (string)($_POST['aufloesung'] ?? 'fine'), !empty($_POST['ecm']), !empty($_POST['einzeln']), $k->benutzer());
                    return $n === 1 ? 'Fax beauftragt. Der Stand steht unten bei „Laufende Aufträge“.' : "$n Faxe beauftragt. Der Stand steht unten bei „Laufende Aufträge“.";
                })(),
                'quelle-loeschen' => (function () use ($auswahl) {
                    $n = 0;
                    foreach ($auswahl as $s) { $d = Fax::dokument($s); $n += Fax::quelleLoeschen($d['quelle'], [$d['datei']]); }
                    return $n === 1 ? 'Dokument gelöscht.' : "$n Dokumente gelöscht.";
                })(),
                'abbrechen' => (function () { Fax::abbrechen((string)($_POST['id'] ?? '')); return 'Abbruch angefordert.'; })(),
                'bericht-loeschen' => (function () { Fax::berichtLoeschen((string)($_POST['art'] ?? ''), (string)($_POST['json'] ?? '')); return 'Bericht gelöscht.'; })(),
                'fehler-uebernehmen' => (function () { Fax::fehlerUebernehmen((string)($_POST['json'] ?? '')); return 'Ins Sendeprotokoll übernommen.'; })(),
                'bericht-erneut-senden' => (function () use ($k) { Fax::berichtErneutSenden((string)($_POST['art'] ?? ''), (string)($_POST['json'] ?? ''), $k->benutzer()); return 'Fax erneut beauftragt. Der Stand steht unter „Fax senden“ bei „Laufende Aufträge“.'; })(),
                'eingang-loeschen' => (function () { Fax::eingangLoeschen((string)($_POST['name'] ?? '')); return 'Fax gelöscht.'; })(),
                'kontakt-speichern' => (function () { Fax::kontaktSpeichern((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), (string)($_POST['nummer'] ?? ''), (string)($_POST['notiz'] ?? ''), !empty($_POST['favorit'])); return 'Eintrag gespeichert.'; })(),
                'kontakt-favorit' => (function () { $an = !empty($_POST['an']); Fax::kontaktFavorit((int)($_POST['id'] ?? 0), $an); return $an ? 'Als Favorit markiert.' : 'Kein Favorit mehr.'; })(),
                'kontakt-loeschen' => (function () { Fax::kontaktLoeschen((int)($_POST['id'] ?? 0)); return 'Eintrag gelöscht.'; })(),
                'einstellungen-speichern', 'aufbewahrung-speichern' => (function () { Fax::aufbewahrungSpeichern($_POST); return 'Aufbewahrung gespeichert.'; })(),
                'drucker-speichern' => (function () { Fax::druckerSpeichern((array)($_POST['drucker'] ?? [])); return 'Druckerliste gespeichert. Jetzt unter Praxis → Drucker „Virtuelle Drucker der Module abgleichen“ wählen (oder das Modul Fax neu installieren), damit neue Drucker erscheinen und entfernte verschwinden.'; })(),
                'benachrichtigung-speichern' => (function () { Fax::benachrichtigungSpeichern($_POST); return 'Benachrichtigung gespeichert.'; })(),
                'ocr-speichern' => (function () { Fax::ocrSpeichern(!empty($_POST['ocr'])); return !empty($_POST['ocr']) ? 'Texterkennung ist an. Neue Faxe werden in den nächsten Minuten gelesen.' : 'Texterkennung ist aus; erkannte Texte wurden gelöscht.'; })(),
                'nas-speichern' => (function () use ($k) { Fax::nasSpeichern($k, $_POST); return 'NAS-Einstellungen gespeichert.'; })(),
                'hochladen' => (function () {
                    $r = Fax::hochladen((array)($_FILES['dateien'] ?? []));
                    $_GET['quelle'] = $_POST['quelle'] = Fax::UPLOAD;
                    $_POST['vorgewaehlt'] = $r['dateien'];
                    return count($r['dateien']) . ' Datei(en) übernommen und unten ausgewählt.' . ($r['fehler'] ? ' Nicht übernommen: ' . implode(' ', $r['fehler']) : '');
                })(),
            };
        } catch (InvalidArgumentException | RuntimeException $e) {
            $fehler = $e->getMessage();
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fx' && ($_POST['aktion'] ?? '') === 'hochladen') {   // Drag and Drop: Antwort als JSON
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => $fehler === null, 'meldung' => $meldung, 'fehler' => $fehler, 'dateien' => (array)($_POST['vorgewaehlt'] ?? [])]);
            exit;
        }
    }
    Fax::aufraeumen();
    $erste = Fax::druckerNamen()[0] ?? Fax::VORSATZ;
    $quelle = (string)($_GET['quelle'] ?? ($_POST['quelle'] ?? $erste));
    if (!in_array($quelle, [...Fax::quellen(), 'sendefehler'], true)) $quelle = $erste;
    $d = ['rechte' => $rechte, 'csrf' => $k->csrf(), 'meldung' => $meldung, 'fehler' => $fehler, 'telefon' => Fax::austauschBereit(),
        'ungelesen' => $rechte['eingang'] ? Fax::ungelesen() : 0, 'fehlerzahl' => $rechte['senden'] ? count(Fax::berichte('fehler')) : 0,
        'einstellungen' => Fax::einstellungen(), 'quelle' => $quelle, 'drucker' => Fax::drucker(), 'lauschordner' => Fax::einstellungen()['lausch']['ziel'] !== '', 'nachrichten' => $k->nachrichtenBereit(),
        'vorgewaehlt' => array_values(array_filter((array)($_POST['vorgewaehlt'] ?? []), 'is_string'))];
    if ($rel === '/') {
        $d += ['kontakte' => Fax::kontakte(), 'aktive' => Fax::aktive(), 'dokumente' => Fax::dokumente(), 'zuletzt' => Fax::zuletzt(),
            'auswahl' => $fehler !== null && ($_POST['aktion'] ?? '') === 'senden' ? $auswahl ?? [] : [], 'post' => $fehler !== null && ($_POST['aktion'] ?? '') === 'senden' ? $_POST : []];
    } elseif ($rel === '/protokoll' || $rel === '/fehler') {
        $d['berichte'] = Fax::berichte($rel === '/fehler' ? 'fehler' : 'protokoll');
    } elseif ($rel === '/einstellungen') {
        $d += ['nasZiele' => Fax::nasZiele($k), 'praxisname' => $k->praxis()['name']];
    } elseif ($rel === '/eingang') {
        $d['suche'] = mb_substr(trim((string)($_GET['suche'] ?? '')), 0, 100);
        $d['eingang'] = Fax::eingangSuchen(Fax::eingang(), $d['suche']);
    } elseif ($rel === '/telefonbuch') {
        $d['kontakte'] = Fax::kontakte();
        $d['kontaktBearbeiten'] = $rechte['telefonbuch'] && (int)($_GET['kontakt'] ?? 0) > 0 ? Fax::kontakt((int)$_GET['kontakt']) : null;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $k->rahmen('Fax', Ansicht::seite($rel, $d), ['roh' => true, 'kopf' => Ansicht::kopf($base)]);
} catch (InvalidArgumentException $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
} catch (Throwable $e) {
    error_log('fax: ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo 'Die Fax-Seite ist gerade nicht erreichbar.';
}
