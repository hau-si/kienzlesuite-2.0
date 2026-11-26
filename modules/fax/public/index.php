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
                'senden', 'quelle-loeschen', 'abbrechen', 'bericht-loeschen', 'fehler-uebernehmen' => 'senden',
                'eingang-loeschen' => 'eingang', 'kontakt-speichern', 'kontakt-loeschen' => 'telefonbuch', 'einstellungen-speichern' => 'admin',
                default => throw new InvalidArgumentException('Unbekannte Aktion.'),
            };
            if (!$rechte[$braucht]) throw new InvalidArgumentException('Dafür fehlt das Recht.');
            $dateien = array_values(array_filter((array)($_POST['dateien'] ?? []), 'is_string'));
            $meldung = match ($aktion) {
                'senden' => (function () use ($dateien, $k) {
                    $n = Fax::senden((string)($_POST['quelle'] ?? ''), $dateien, Fax::empfaenger((array)($_POST['empfaenger'] ?? [])), (string)($_POST['aufloesung'] ?? 'fine'), !empty($_POST['ecm']), $k->benutzer());
                    return $n === 1 ? 'Fax beauftragt. Der Stand steht unten bei „Laufende Aufträge“.' : "$n Faxe beauftragt.";
                })(),
                'quelle-loeschen' => Fax::quelleLoeschen((string)($_POST['quelle'] ?? ''), $dateien) . ' Dokument(e) gelöscht.',
                'abbrechen' => (function () { Fax::abbrechen((string)($_POST['id'] ?? '')); return 'Abbruch angefordert.'; })(),
                'bericht-loeschen' => (function () { Fax::berichtLoeschen((string)($_POST['art'] ?? ''), (string)($_POST['json'] ?? '')); return 'Bericht gelöscht.'; })(),
                'fehler-uebernehmen' => (function () { Fax::fehlerUebernehmen((string)($_POST['json'] ?? '')); return 'Ins Sendeprotokoll übernommen.'; })(),
                'eingang-loeschen' => (function () { Fax::eingangLoeschen((string)($_POST['name'] ?? '')); return 'Fax gelöscht.'; })(),
                'kontakt-speichern' => (function () { Fax::kontaktSpeichern((int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''), (string)($_POST['nummer'] ?? ''), (string)($_POST['notiz'] ?? '')); return 'Eintrag gespeichert.'; })(),
                'kontakt-loeschen' => (function () { Fax::kontaktLoeschen((int)($_POST['id'] ?? 0)); return 'Eintrag gelöscht.'; })(),
                'einstellungen-speichern' => (function () { Fax::einstellungenSpeichern($_POST); return 'Gespeichert.'; })(),
            };
        } catch (InvalidArgumentException | RuntimeException $e) {
            $fehler = $e->getMessage();
        }
    }
    Fax::aufraeumen();
    $quelle = (string)($_GET['quelle'] ?? ($_POST['quelle'] ?? 'vFax1'));
    if (!in_array($quelle, [...Fax::DRUCKER, 'sendefehler'], true)) $quelle = 'vFax1';
    $d = ['rechte' => $rechte, 'csrf' => $k->csrf(), 'meldung' => $meldung, 'fehler' => $fehler, 'telefon' => Fax::austauschBereit(),
        'ungelesen' => $rechte['eingang'] ? Fax::ungelesen() : 0, 'fehlerzahl' => $rechte['senden'] ? count(Fax::berichte('fehler')) : 0,
        'einstellungen' => Fax::einstellungen(), 'quelle' => $quelle];
    if ($rel === '/') {
        $d += ['zaehler' => Fax::quellenZaehler(), 'kontakte' => Fax::kontakte(), 'aktive' => Fax::aktive(),
            'dateien' => $quelle === 'sendefehler' ? array_map(fn($f) => ['datei' => basename($f), 'groesse' => (int)filesize($f), 'zeit' => (int)filemtime($f), 'titel' => ''], glob(Fax::ordner('fehler-original') . '/*.pdf') ?: []) : Fax::quelle($quelle)];
    } elseif ($rel === '/protokoll' || $rel === '/fehler') {
        $d['berichte'] = Fax::berichte($rel === '/fehler' ? 'fehler' : 'protokoll');
    } elseif ($rel === '/eingang') {
        $d['eingang'] = Fax::eingang();
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
