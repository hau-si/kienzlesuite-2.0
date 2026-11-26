<?php
declare(strict_types=1);
namespace KienzleFax;

/**
 * Fachlogik des Moduls Fax, übernommen aus kienzlefax.php 1.4.6 (Kienzlefax 3.5.0, Dr. Thomas Kienzle):
 * Quellen (Faxdrucker), Empfänger mit Telefonbuch, Aufträge im Format des Fax-Workers (job.json, doc.pdf, source.pdf),
 * Abbruch, Sendeprotokoll, Fehlerberichte mit erneutem Senden, Faxeingang.
 *
 * Ablage: Aufträge gehen in den Austauschordner mit dem Modul telefon (queue/), dort sendet der Worker. Fertige
 * Berichte und empfangene Faxe holt dieses Modul in seinen eigenen Datenordner; im Austauschordner bleiben nur
 * laufende Aufträge.
 */
final class Fax {
    public static string $data = '/var/lib/kienzle/fax';
    public static string $austausch = '/var/spool/kienzle/austausch/fax_telefon';
    public const VORSATZ = 'vFax';            // Faxdrucker heißen vFax, vFax2, vFaxLabor … (Vorsatz in module.toml [printer_list])
    public const MAX_DRUCKER = 10;
    public const UPLOAD = 'Upload';            // Quelle für hochgezogene Dateien (Drag and Drop)
    public const NAS = 'NAS';                  // Quelle für Dateien aus dem Lauschordner auf dem NAS
    public const ENDUNGEN = ['pdf', 'ps', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
    public const MAX_EMPFAENGER = 5;
    public const MAX_PDF = 30 * 1024 * 1024;
    private static ?\PDO $pdo = null;

    // ---- Ordner ------------------------------------------------------------------------------------------------
    public static function ordner(string $name): string {
        $map = ['drucker' => self::$data . '/drucker', 'protokoll' => self::$data . '/protokoll', 'fehler' => self::$data . '/fehler',
            'fehler-original' => self::$data . '/fehler/original', 'eingang' => self::$data . '/eingang'];
        $d = $map[$name] ?? throw new \InvalidArgumentException('Ordner unbekannt.');
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d;
    }

    public static function austauschBereit(): bool { return is_dir(self::$austausch); }

    private static function ax(string $sub): string { return self::$austausch . '/' . $sub; }

    /** Ordner im Austauschordner anlegen: 0770, damit die Vorgabe-ACL auch für das Modul telefon gilt. */
    private static function axOrdner(string $sub): string {
        $d = self::ax($sub);
        if (!is_dir($d)) { @mkdir($d, 0770, true); @chmod($d, 0770); }
        return $d;
    }

    public static function dateiname(string $n): bool { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,150}\.(pdf|json)$/D', $n); }

    public static function jobId(string $id): bool { return (bool)preg_match('/^JOB-\d{8}-\d{6}-[a-z0-9]{6}$/D', $id); }

    // ---- Nummern und Empfänger (normalize_fax_number aus kienzlefax.php) ---------------------------------------
    public static function nummer(string $eingabe): string {
        $n = preg_replace('/\D+/', '', $eingabe) ?? '';
        if ($n === '') return '';
        if (str_starts_with($n, '00')) $n = substr($n, 2);
        elseif (str_starts_with($n, '0')) $n = '49' . substr($n, 1);
        return strlen($n) >= 6 && strlen($n) <= 20 ? $n : '';
    }

    /** Lesbar: 49301234567 → 030 1234567, sonst +33 … */
    public static function anzeige(string $n): string {
        if ($n === '') return '';
        return str_starts_with($n, '49') ? '0' . substr($n, 2) : '+' . $n;
    }

    public static function zeile(string $wert, string $name, int $max, bool $pflicht = true): string {
        $wert = trim($wert);
        if ($pflicht && $wert === '') throw new \InvalidArgumentException($name . ': Bitte ausfüllen.');
        if (mb_strlen($wert) > $max || preg_match('/[\x00-\x1f\x7f]/u', $wert)) throw new \InvalidArgumentException($name . ': höchstens ' . $max . ' Zeichen, keine Steuerzeichen.');
        return $wert;
    }

    /** Empfänger aus dem Formular (empfaenger[i][kontakt|name|nummer|merken]), wie collect_recipients_from_post. */
    public static function empfaenger(array $roh): array {
        $out = [];
        $i = 0;
        foreach (array_slice($roh, 0, self::MAX_EMPFAENGER) as $e) {
            if (!is_array($e)) continue;
            $i++;
            $kontakt = (int)($e['kontakt'] ?? 0);
            $name = trim((string)($e['name'] ?? ''));
            $num = trim((string)($e['nummer'] ?? ''));
            if ($kontakt === 0 && $name === '' && $num === '') continue;
            if ($kontakt > 0) {
                $k = self::kontakt($kontakt) ?? throw new \InvalidArgumentException("Empfänger $i: Telefonbuch-Eintrag nicht gefunden.");
                if ($name === '') $name = $k['name'];
                if ($num === '') $num = $k['nummer'];
            }
            $norm = self::nummer($num);
            if ($norm === '') throw new \InvalidArgumentException("Empfänger $i: Faxnummer fehlt oder ist ungültig.");
            $out[] = ['name' => self::zeile($name, "Empfänger $i: Name", 80), 'nummer' => $norm, 'merken' => !empty($e['merken']) && $kontakt === 0];
        }
        if (!$out) throw new \InvalidArgumentException('Bitte mindestens einen Empfänger angeben.');
        return $out;
    }

    // ---- Quellen (Faxdrucker) ----------------------------------------------------------------------------------
    /** PDFs eines Faxdruckers (neueste zuerst) mit Name, Größe, Zeit und Titel des Druckauftrags. */
    public static function quelle(string $drucker): array {
        if (!in_array($drucker, self::quellen(), true)) throw new \InvalidArgumentException('Quelle unbekannt.');
        $dir = self::ordner('drucker') . '/' . $drucker;
        $out = [];
        foreach (glob($dir . '/*.pdf') ?: [] as $f) {
            $n = basename($f);
            if (!self::dateiname($n) || is_link($f)) continue;
            $meta = json_decode((string)@file_get_contents(substr($f, 0, -4) . '.json'), true);
            $out[] = ['datei' => $n, 'groesse' => (int)filesize($f), 'zeit' => (int)filemtime($f), 'titel' => is_array($meta) ? (string)($meta['titel'] ?? '') : ''];
        }
        usort($out, fn($a, $b) => $b['zeit'] <=> $a['zeit']);
        return $out;
    }

    public static function quellenZaehler(): array {
        $z = [];
        foreach (self::quellen() as $d) $z[$d] = count(glob(self::ordner('drucker') . '/' . $d . '/*.pdf') ?: []);
        $z['sendefehler'] = count(glob(self::ordner('fehler-original') . '/*.pdf') ?: []);
        return $z;
    }

    /** Druckauftrag ablegen (vom virtuellen Drucker, als ks-fax). Liefert den Dateinamen. */
    public static function druckAblegen(string $drucker, string $pdf, string $titel, ?int $jetzt = null): string {
        if (!in_array($drucker, self::quellen(), true)) throw new \InvalidArgumentException('Drucker unbekannt.');
        if (!str_starts_with($pdf, '%PDF-') || strlen($pdf) > self::MAX_PDF) throw new \InvalidArgumentException('Kein PDF oder zu groß.');
        $dir = self::ordner('drucker') . '/' . $drucker;
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $name = date('Ymd-His', $jetzt ?? time()) . '-' . bin2hex(random_bytes(3)) . '.pdf';
        file_put_contents($dir . '/' . $name, $pdf);
        $titel = mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f]/u', '', $titel)), 0, 120);
        file_put_contents($dir . '/' . substr($name, 0, -4) . '.json', (string)json_encode(['titel' => $titel, 'zeit' => $jetzt ?? time()], JSON_UNESCAPED_UNICODE));
        @touch(self::$data . '/.drucker-genutzt');   // für den Einrichtungs-Überblick
        return $name;
    }

    public static function quelleLoeschen(string $drucker, array $dateien): int {
        $n = 0;
        foreach ($dateien as $d) {
            [$pfad] = self::quellPfad($drucker, (string)$d);
            if (@unlink($pfad)) $n++;
            @unlink(substr($pfad, 0, -4) . '.json');
        }
        return $n;
    }

    /** Pfad einer Quelldatei (Faxdrucker oder Original eines Sendefehlers), streng geprüft. */
    public static function quellPfad(string $quelle, string $datei): array {
        if (!self::dateiname($datei) || !str_ends_with($datei, '.pdf')) throw new \InvalidArgumentException('Datei ungültig.');
        $dir = $quelle === 'sendefehler' ? self::ordner('fehler-original') : (in_array($quelle, self::quellen(), true) ? self::ordner('drucker') . '/' . $quelle : null);
        if ($dir === null) throw new \InvalidArgumentException('Quelle unbekannt.');
        $pfad = $dir . '/' . $datei;
        if (!is_file($pfad) || is_link($pfad)) throw new \InvalidArgumentException('Datei nicht gefunden: ' . $datei);
        return [$pfad, $dir];
    }

    // ---- Aufträge ----------------------------------------------------------------------------------------------
    /** Für jede Datei und jeden Empfänger einen Auftrag anlegen (erst staging/, dann atomar nach queue/). Liefert die Anzahl. */
    public static function senden(string $quelle, array $dateien, array $empfaenger, string $aufloesung, bool $ecm, string $benutzer): int {
        if (!self::austauschBereit()) throw new \RuntimeException('Das Modul Telefon fehlt oder ist noch nicht verbunden (Verwaltung → Module).');
        $dateien = array_values(array_unique(array_map('strval', $dateien)));
        if (!$dateien) throw new \InvalidArgumentException('Bitte mindestens ein Dokument auswählen.');
        if (!in_array($aufloesung, ['fine', 'standard'], true)) $aufloesung = 'fine';
        $pfade = [];
        foreach ($dateien as $d) $pfade[$d] = self::quellPfad($quelle, $d)[0];
        $staging = self::axOrdner('staging');
        $queue = self::axOrdner('queue');
        $neu = [];
        try {
            foreach ($pfade as $datei => $pfad) {
                $titel = $quelle === 'sendefehler' ? preg_replace('/(__JOB-.*)?\.pdf$/', '', $datei) : (self::metaTitel($pfad) ?: 'Dokument');
                foreach ($empfaenger as $e) {
                    $id = 'JOB-' . date('Ymd-His') . '-' . self::zufall(6);
                    $dir = $staging . '/' . $id;
                    if (!mkdir($dir, 0770)) throw new \RuntimeException('Auftrag ließ sich nicht anlegen.');
                    chmod($dir, 0770);
                    foreach (['doc.pdf', 'source.pdf'] as $f) {
                        if (!copy($pfad, "$dir/$f")) throw new \RuntimeException('Dokument ließ sich nicht kopieren.');
                        chmod("$dir/$f", 0660);
                    }
                    $job = ['job_id' => $id, 'created_at' => date('c'), 'source' => ['src' => $quelle, 'filename_original' => self::dateiTitel($titel) . '.pdf'],
                        'recipient' => ['name' => $e['name'], 'number' => $e['nummer']], 'options' => ['ecm' => $ecm, 'resolution' => $aufloesung],
                        'status' => 'queued', 'kienzle' => ['benutzer' => $benutzer]];
                    self::jsonSchreiben("$dir/job.json", $job);
                    $neu[] = $id;
                }
            }
            foreach ($neu as $id) {
                if (!rename("$staging/$id", "$queue/$id")) throw new \RuntimeException('Auftrag ließ sich nicht einreihen.');
            }
        } catch (\Throwable $e) {
            foreach ($neu as $id) self::ordnerLoeschen("$staging/$id");
            throw $e;
        }
        foreach ($pfade as $pfad) { @unlink($pfad); @unlink(substr($pfad, 0, -4) . '.json'); }
        foreach ($empfaenger as $e) if ($e['merken']) self::kontaktSpeichern(0, $e['name'], $e['nummer'], '');
        return count($neu);
    }

    private static function metaTitel(string $pfad): string {
        $m = json_decode((string)@file_get_contents(substr($pfad, 0, -4) . '.json'), true);
        return is_array($m) ? trim((string)($m['titel'] ?? '')) : '';
    }

    /** Dateiname für Berichte (sanitize_basename des Workers): nur einfache Zeichen. */
    public static function dateiTitel(string $t): string {
        $t = (string)preg_replace('/\.(pdf|docx?|xps|oxps)$/i', '', trim($t));
        $t = strtr($t, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $t = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $t), '._-');
        return mb_substr($t !== '' ? $t : 'fax', 0, 60);
    }

    private static function zufall(int $n): string {
        $z = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $o = '';
        for ($i = 0; $i < $n; $i++) $o .= $z[random_int(0, 35)];
        return $o;
    }

    public static function jsonSchreiben(string $pfad, array $daten): void {
        $tmp = $pfad . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(3));
        $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($tmp, $json) !== strlen($json)) throw new \RuntimeException('Datei ließ sich nicht schreiben.');
        @chmod($tmp, 0660);
        rename($tmp, $pfad);
    }

    private static function ordnerLoeschen(string $dir): void {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir($dir);
    }

    /** Laufende Aufträge (queue und processing) mit Live-Angaben des Workers. */
    public static function aktive(): array {
        $out = [];
        foreach (['queue', 'processing'] as $wo) {
            foreach (glob(self::ax($wo) . '/JOB-*', GLOB_ONLYDIR) ?: [] as $d) {
                if (!self::jobId(basename($d))) continue;
                $j = json_decode((string)@file_get_contents($d . '/job.json'), true);
                if (!is_array($j)) continue;
                $af = $j['live']['asterisk_fax'] ?? [];
                $out[] = ['id' => basename($d), 'wo' => $wo, 'status' => (string)($j['status'] ?? $wo), 'name' => (string)($j['recipient']['name'] ?? ''),
                    'nummer' => (string)($j['recipient']['number'] ?? ''), 'datei' => (string)($j['source']['filename_original'] ?? ''),
                    'erstellt' => (string)($j['created_at'] ?? ''), 'versuch' => (int)($j['attempt']['current'] ?? 0), 'max' => (int)($j['attempt']['max'] ?? $j['retry']['max'] ?? 0),
                    'seite' => is_array($af) ? ($af['page_number'] ?? null) : null, 'seiten' => is_array($af) ? ($af['total_pages'] ?? null) : null,
                    'abbruch' => (bool)($j['cancel']['requested'] ?? false), 'naechster' => (string)($j['retry']['next_try_at'] ?? '')];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['id'], $b['id']));
        return $out;
    }

    /** Abbruch anfordern (cancel.requested, der Worker übernimmt). */
    public static function abbrechen(string $id): void {
        if (!self::jobId($id)) throw new \InvalidArgumentException('Auftrag ungültig.');
        foreach (['queue', 'processing'] as $wo) {
            $p = self::ax($wo) . '/' . $id . '/job.json';
            if (!is_file($p)) continue;
            $j = json_decode((string)file_get_contents($p), true);
            if (!is_array($j)) throw new \RuntimeException('Auftrag nicht lesbar.');
            $j['cancel'] = ['requested' => true, 'requested_at' => date('c')];
            self::jsonSchreiben($p, $j);
            return;
        }
        throw new \InvalidArgumentException('Auftrag nicht gefunden (vielleicht schon fertig).');
    }

    // ---- Ergebnisse abholen ------------------------------------------------------------------------------------
    /** Sendeberichte, Fehlerberichte, Originale und empfangene Faxe aus dem Austauschordner in den Datenordner holen. */
    public static function abholen(): int {
        if (!self::austauschBereit()) return 0;
        $n = 0;
        $neu = [];
        foreach (['sendeberichte' => 'protokoll', 'sendefehler/berichte' => 'fehler', 'sendefehler/eingang' => 'fehler-original', 'empfang' => 'eingang'] as $von => $nach) {
            $dir = self::ax($von);
            // Beim Eingang ist ein Fax erst mit seiner JSON-Datei vollständig (fax-empfangen schreibt sie zuletzt): PDF und JSON als Paar holen.
            $dateien = $von === 'empfang' ? array_merge(...array_map(fn($j) => is_file(substr($j, 0, -5) . '.pdf') ? [substr($j, 0, -5) . '.pdf', $j] : [], glob($dir . '/*.json') ?: []))
                : (glob($dir . '/*.{pdf,json}', GLOB_BRACE) ?: []);
            foreach ($dateien as $f) {
                $b = basename($f);
                if (!self::dateiname($b) || is_link($f)) continue;
                $ziel = self::ordner($nach) . '/' . $b;
                if (@copy($f, $ziel)) {
                    @chmod($ziel, 0600); @unlink($f); $n++;
                    if ($von === 'empfang' && str_ends_with($b, '.json')) $neu[] = substr($b, 0, -5);
                }
            }
        }
        if ($neu) self::meldenVormerken($neu);
        return $n;
    }

    // ---- Benachrichtigung bei Faxeingang (Nachrichten-Ausgang der Basis) ----------------------------------------
    /** Neu eingegangene Faxe für die E-Mail-Benachrichtigung merken (verschickt die Pflege). */
    private static function meldenVormerken(array $namen): void {
        $f = self::$data . '/eingang-melden.json';
        $alt = json_decode((string)@file_get_contents($f), true);
        $liste = array_slice(array_values(array_unique([...(is_array($alt) ? $alt : []), ...$namen])), -200);
        file_put_contents($f, (string)json_encode($liste));
        @chmod($f, 0600);
    }

    /**
     * E-Mail „Neues Fax“ an die eingestellten Adressen über den Nachrichten-Ausgang der Basis. Bewusst ohne Absender, Inhalt
     * und Anhang (Faxe enthalten Gesundheitsdaten): nur Anzahl, Seiten und der Hinweis, wo das Fax liegt. Liefert die Anzahl.
     */
    public static function eingangMelden(\KienzleModul $k): int {
        $f = self::$data . '/eingang-melden.json';
        $namen = json_decode((string)@file_get_contents($f), true);
        if (!is_array($namen) || !$namen) return 0;
        @unlink($f);
        $an = self::einstellungen()['mail_eingang'];
        if (!$an) return 0;
        if (!$k->nachrichtenBereit()['mail']) { error_log('fax: Benachrichtigung bei Faxeingang eingestellt, aber E-Mail ist in der Basis nicht eingerichtet.'); return 0; }
        $seiten = 0;
        foreach ($namen as $n) {
            $j = is_string($n) && self::dateiname($n . '.json') ? json_decode((string)@file_get_contents(self::ordner('eingang') . '/' . $n . '.json'), true) : null;
            $seiten += is_array($j) ? (int)($j['seiten'] ?? $j['pages'] ?? 0) : 0;
        }
        $anzahl = count($namen);
        $praxis = trim((string)($k->praxis()['name'] ?? ''));
        $text = ($anzahl === 1 ? 'Ein neues Fax ist eingegangen' : "$anzahl neue Faxe sind eingegangen") . ($seiten > 0 ? " ($seiten " . ($seiten === 1 ? 'Seite' : 'Seiten') . ')' : '') . ".\n\n"
            . "Öffnen: Kienzle-Suite → Fax → Eingang.\n\nDiese Nachricht enthält aus Datenschutzgründen weder Absender noch Inhalt.\n"
            . ($praxis !== '' ? "\n$praxis\n" : '');
        try {
            $k->mailSenden($an, $anzahl === 1 ? 'Neues Fax eingegangen' : "$anzahl neue Faxe eingegangen", $text);
        } catch (\Throwable $e) {
            error_log('fax: Benachrichtigung nicht abgelegt: ' . $e->getMessage());
            return 0;
        }
        return $anzahl;
    }

    /** Adressen für die Benachrichtigung (höchstens 5, Komma oder Zeilen getrennt; leer = aus). */
    public static function benachrichtigungSpeichern(array $p): void {
        $an = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($p['mail_eingang'] ?? '')) ?: []))));
        if (count($an) > 5) throw new \InvalidArgumentException('Höchstens fünf Adressen.');
        foreach ($an as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Keine gültige E-Mail-Adresse: ' . $a);
        $d = self::roh();
        $d['mail_eingang'] = $an;
        self::rohSchreiben($d);
    }

    /** Berichte (Protokoll oder Fehler): je JSON des Workers ein Eintrag, neueste zuerst. */
    public static function berichte(string $art, int $max = 200): array {
        $dir = self::ordner($art === 'fehler' ? 'fehler' : 'protokoll');
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            $b = basename($f);
            if (!self::dateiname($b)) continue;
            $j = json_decode((string)@file_get_contents($f), true);
            if (!is_array($j)) continue;
            $pdf = substr($b, 0, -5) . ($art === 'fehler' ? '__FAILED.pdf' : '__OK.pdf');
            $ende = (string)($j['finalized_at'] ?? $j['end_time'] ?? $j['created_at'] ?? '');
            $grund = (string)($j['result']['reason'] ?? '');
            $out[] = ['json' => $b, 'pdf' => is_file($dir . '/' . $pdf) ? $pdf : '', 'zeit' => strtotime($ende) ?: (int)filemtime($f),
                'name' => (string)($j['recipient']['name'] ?? ''), 'nummer' => (string)($j['recipient']['number'] ?? ''),
                'datei' => (string)($j['source']['filename_original'] ?? ''), 'seiten' => self::seiten($j), 'grund' => $grund,
                'abgebrochen' => $grund === 'cancelled' || !empty($j['cancel']['requested']), 'versuche' => (int)($j['attempt']['current'] ?? 0)];
        }
        usort($out, fn($a, $b) => $b['zeit'] <=> $a['zeit']);
        return array_slice($out, 0, $max);
    }

    /** Seitenzahl wie extract_pages in kienzlefax.php. */
    private static function seiten(array $j): string {
        $r = $j['result'] ?? [];
        foreach (['faxpages_sent', 'pages', 'faxpages_raw'] as $k) if (isset($r[$k]) && (string)$r[$k] !== '') return (string)$r[$k];
        return '';
    }

    public static function berichtLoeschen(string $art, string $json): void {
        if (!self::dateiname($json) || !str_ends_with($json, '.json')) throw new \InvalidArgumentException('Bericht ungültig.');
        $dir = self::ordner($art === 'fehler' ? 'fehler' : 'protokoll');
        @unlink("$dir/$json");
        foreach (['__OK.pdf', '__FAILED.pdf'] as $s) @unlink($dir . '/' . substr($json, 0, -5) . $s);
    }

    /** Fehlerbericht ins Sendeprotokoll übernehmen (Fehler erledigt, Bericht bleibt nachvollziehbar). */
    public static function fehlerUebernehmen(string $json): void {
        if (!self::dateiname($json) || !str_ends_with($json, '.json')) throw new \InvalidArgumentException('Bericht ungültig.');
        $von = self::ordner('fehler');
        $nach = self::ordner('protokoll');
        if (!is_file("$von/$json")) throw new \InvalidArgumentException('Bericht nicht gefunden.');
        rename("$von/$json", "$nach/$json");
        $pdf = substr($json, 0, -5) . '__FAILED.pdf';
        if (is_file("$von/$pdf")) rename("$von/$pdf", "$nach/" . substr($json, 0, -5) . '__OK.pdf');
    }

    // ---- Eingang -------------------------------------------------------------------------------------------------
    public static function eingang(): array {
        $dir = self::ordner('eingang');
        $gelesen = self::gelesen();
        $namen = [];
        foreach (self::kontakte() as $k) $namen[$k['nummer']] = $k['name'];
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            $b = basename($f, '.json');
            if (!preg_match('/^[0-9]{8}-[0-9]{6}_[0-9a-z]{1,32}_[0-9]{1,40}$/D', $b) || !is_file("$dir/$b.pdf")) continue;
            $m = json_decode((string)@file_get_contents($f), true);
            if (!is_array($m)) continue;
            $abs = (string)($m['absender'] ?? '');
            $norm = self::nummer($abs);
            $out[] = ['name' => $b, 'zeit' => strtotime((string)($m['empfangen'] ?? '')) ?: (int)filemtime($f), 'absender' => $abs,
                'kontakt' => $namen[$norm] ?? ($namen[$abs] ?? ''), 'seiten' => $m['seiten'] ?? null, 'status' => (string)($m['status'] ?? ''),
                'gelesen' => isset($gelesen[$b])];
        }
        usort($out, fn($a, $b) => $b['zeit'] <=> $a['zeit']);
        return $out;
    }

    private static function gelesen(): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/gelesen.json'), true);
        return is_array($d) ? $d : [];
    }

    public static function alsGelesen(string $name): void {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}_[0-9a-z]{1,32}_[0-9]{1,40}$/D', $name)) return;
        $g = self::gelesen();
        $g[$name] = time();
        file_put_contents(self::$data . '/gelesen.json', (string)json_encode($g));
    }

    public static function eingangLoeschen(string $name): void {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}_[0-9a-z]{1,32}_[0-9]{1,40}$/D', $name)) throw new \InvalidArgumentException('Fax ungültig.');
        $dir = self::ordner('eingang');
        @unlink("$dir/$name.pdf");
        @unlink("$dir/$name.json");
        $g = self::gelesen();
        unset($g[$name]);
        file_put_contents(self::$data . '/gelesen.json', (string)json_encode($g));
    }

    public static function ungelesen(): int { return count(array_filter(self::eingang(), fn($e) => !$e['gelesen'])); }

    /** Pfad einer PDF zum Anzeigen (Eingang, Protokoll, Fehler, Quelle), streng geprüft. */
    public static function pdfPfad(string $art, string $datei, string $quelle = ''): string {
        if (!self::dateiname($datei) || !str_ends_with($datei, '.pdf')) throw new \InvalidArgumentException('Datei ungültig.');
        if ($art === 'quelle') return self::quellPfad($quelle, $datei)[0];
        $dir = match ($art) { 'eingang' => self::ordner('eingang'), 'protokoll' => self::ordner('protokoll'), 'fehler' => self::ordner('fehler'), default => throw new \InvalidArgumentException('Art unbekannt.') };
        $p = $dir . '/' . $datei;
        if (!is_file($p) || is_link($p)) throw new \InvalidArgumentException('Datei nicht gefunden.');
        return $p;
    }

    // ---- Telefonbuch (contacts aus kienzlefax.php) ---------------------------------------------------------------
    public static function db(): \PDO {
        if (self::$pdo) return self::$pdo;
        $pdo = new \PDO('sqlite:' . self::$data . '/telefonbuch.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE IF NOT EXISTS kontakte (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, nummer TEXT NOT NULL, notiz TEXT NOT NULL DEFAULT \'\', erstellt TEXT NOT NULL, geaendert TEXT NOT NULL)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS kontakte_name ON kontakte(name)');
        @chmod(self::$data . '/telefonbuch.sqlite', 0600);
        return self::$pdo = $pdo;
    }

    public static function zuruecksetzen(): void { self::$pdo = null; }

    public static function kontakte(): array { return self::db()->query('SELECT * FROM kontakte ORDER BY name COLLATE NOCASE')->fetchAll(); }

    public static function kontakt(int $id): ?array {
        $st = self::db()->prepare('SELECT * FROM kontakte WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public static function kontaktSpeichern(int $id, string $name, string $nummer, string $notiz): int {
        $name = self::zeile($name, 'Name', 80);
        $norm = self::nummer($nummer);
        if ($norm === '') throw new \InvalidArgumentException('Faxnummer fehlt oder ist ungültig.');
        $notiz = self::zeile($notiz, 'Notiz', 200, false);
        $jetzt = date('c');
        if ($id > 0) {
            $st = self::db()->prepare('UPDATE kontakte SET name = ?, nummer = ?, notiz = ?, geaendert = ? WHERE id = ?');
            $st->execute([$name, $norm, $notiz, $jetzt, $id]);
            if ($st->rowCount() === 0) throw new \InvalidArgumentException('Eintrag nicht gefunden.');
            return $id;
        }
        if ((int)self::db()->query('SELECT COUNT(*) FROM kontakte')->fetchColumn() >= 5000) throw new \InvalidArgumentException('Telefonbuch ist voll (5000 Einträge).');
        self::db()->prepare('INSERT INTO kontakte (name, nummer, notiz, erstellt, geaendert) VALUES (?, ?, ?, ?, ?)')->execute([$name, $norm, $notiz, $jetzt, $jetzt]);
        return (int)self::db()->lastInsertId();
    }

    public static function kontaktLoeschen(int $id): void { self::db()->prepare('DELETE FROM kontakte WHERE id = ?')->execute([$id]); }

    // ---- Einstellungen -------------------------------------------------------------------------------------------
    private static function roh(): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/einstellungen.json'), true);
        return is_array($d) ? $d : [];
    }

    private static function rohSchreiben(array $d): void {
        file_put_contents(self::$data . '/einstellungen.json', (string)json_encode($d, JSON_UNESCAPED_UNICODE));
        @chmod(self::$data . '/einstellungen.json', 0600);
    }

    /** Alle Einstellungen mit Vorgaben. Aufbewahrung in Tagen, 0 = unbegrenzt. */
    public static function einstellungen(): array {
        $d = self::roh();
        $tage = static fn(string $k, int $vorgabe): int => isset($d[$k]) && (int)$d[$k] >= 0 && (int)$d[$k] <= 3650 ? (int)$d[$k] : $vorgabe;
        $ex = is_array($d['export'] ?? null) ? $d['export'] : [];
        $la = is_array($d['lausch'] ?? null) ? $d['lausch'] : [];
        return ['eingang_tage' => $tage('eingang_tage', 90), 'protokoll_tage' => $tage('protokoll_tage', 90), 'drucker_tage' => $tage('drucker_tage', 14),
            'export' => ['ziel' => (string)($ex['ziel'] ?? ''), 'ordner' => (string)($ex['ordner'] ?? 'Fax-Eingang'), 'jahresordner' => (bool)($ex['jahresordner'] ?? true)],
            'lausch' => ['ziel' => (string)($la['ziel'] ?? ''), 'ordner' => (string)($la['ordner'] ?? 'Fax-Ausgang')],
            'mail_eingang' => array_values(array_filter((array)($d['mail_eingang'] ?? []), fn($a) => is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL)))];
    }

    public static function aufbewahrungSpeichern(array $p): void {
        $d = self::roh();
        foreach (['eingang_tage' => 'Faxeingang', 'protokoll_tage' => 'Sendeprotokoll und Fehler', 'drucker_tage' => 'Gedruckte Dokumente'] as $k => $name) {
            if (!empty($p[$k . '_unbegrenzt'])) { $d[$k] = 0; continue; }
            $v = (int)($p[$k] ?? -1);
            if ($v < 1 || $v > 3650) throw new \InvalidArgumentException($name . ': 1 bis 3650 Tage oder „unbegrenzt“.');
            $d[$k] = $v;
        }
        self::rohSchreiben($d);
    }

    /** Alte Aufrufe (Tests, Formular ohne „unbegrenzt“): wie aufbewahrungSpeichern. */
    public static function einstellungenSpeichern(array $p): void { self::aufbewahrungSpeichern($p); }

    // ---- Faxdrucker ----------------------------------------------------------------------------------------------
    public static function druckerName(string $n): bool {
        return (bool)preg_match('/^' . self::VORSATZ . '[A-Za-z0-9_-]{0,' . (39 - strlen(self::VORSATZ)) . '}$/D', $n);
    }

    /**
     * Faxdrucker mit Bezeichnung und fester Faxnummer. Ohne gespeicherte Liste: ein Drucker „vFax“; auf einem Server, auf dem das
     * Modul schon benutzt wurde (Telefonbuch vorhanden), bleiben die fünf Drucker vFax1 bis vFax5 der ersten Beta erhalten.
     */
    public static function drucker(): array {
        $d = self::roh();
        $liste = [];
        if (isset($d['drucker']) && is_array($d['drucker'])) {
            foreach ($d['drucker'] as $x) {
                if (!is_array($x) || !self::druckerName((string)($x['name'] ?? '')) || isset($liste[strtolower((string)$x['name'])])) continue;
                $nummer = self::nummer((string)($x['nummer'] ?? ''));
                $liste[strtolower($x['name'])] = ['name' => $x['name'], 'bezeichnung' => mb_substr(trim((string)($x['bezeichnung'] ?? '')), 0, 60),
                    'nummer' => $nummer, 'sofort' => $nummer !== '' && !empty($x['sofort'])];
            }
        }
        if (!$liste) {
            $namen = is_file(self::$data . '/telefonbuch.sqlite') ? ['vFax1', 'vFax2', 'vFax3', 'vFax4', 'vFax5'] : [self::VORSATZ];
            foreach ($namen as $n) $liste[strtolower($n)] = ['name' => $n, 'bezeichnung' => '', 'nummer' => '', 'sofort' => false];
            $d['drucker'] = array_values($liste);
            self::rohSchreiben($d);
        }
        return array_slice(array_values($liste), 0, self::MAX_DRUCKER);
    }

    public static function druckerNamen(): array { return array_column(self::drucker(), 'name'); }

    public static function druckerEintrag(string $name): ?array {
        foreach (self::drucker() as $x) if ($x['name'] === $name) return $x;
        return null;
    }

    /** Alle Quellen für „Fax senden“: Faxdrucker, hochgeladene Dateien und (mit Lauschordner) der NAS-Ordner. */
    public static function quellen(): array {
        $q = [...self::druckerNamen(), self::UPLOAD];
        if (self::einstellungen()['lausch']['ziel'] !== '' || is_dir(self::$data . '/drucker/' . self::NAS)) $q[] = self::NAS;
        return $q;
    }

    /** Liste der Faxdrucker aus dem Formular speichern (drucker[i][name|bezeichnung|nummer|sofort]); schreibt auch drucker.json für die Basis. */
    public static function druckerSpeichern(array $roh): void {
        $liste = [];
        foreach (array_slice($roh, 0, 30) as $x) {
            if (!is_array($x)) continue;
            $name = trim((string)($x['name'] ?? ''));
            if ($name === '') continue;
            if (!self::druckerName($name)) throw new \InvalidArgumentException('Druckername „' . mb_substr($name, 0, 40) . '“: muss mit „' . self::VORSATZ . '“ beginnen, danach nur Buchstaben, Ziffern, _ und -.');
            if (isset($liste[strtolower($name)])) throw new \InvalidArgumentException('Druckername „' . $name . '“ ist doppelt.');
            $nummer = trim((string)($x['nummer'] ?? ''));
            $norm = $nummer === '' ? '' : self::nummer($nummer);
            if ($nummer !== '' && $norm === '') throw new \InvalidArgumentException($name . ': Faxnummer ungültig.');
            $liste[strtolower($name)] = ['name' => $name, 'bezeichnung' => self::zeile((string)($x['bezeichnung'] ?? ''), $name . ': Bezeichnung', 60, false),
                'nummer' => $norm, 'sofort' => $norm !== '' && !empty($x['sofort'])];
        }
        if (!$liste) throw new \InvalidArgumentException('Mindestens ein Faxdrucker ist nötig.');
        if (count($liste) > self::MAX_DRUCKER) throw new \InvalidArgumentException('Höchstens ' . self::MAX_DRUCKER . ' Faxdrucker.');
        $d = self::roh();
        $d['drucker'] = array_values($liste);
        self::rohSchreiben($d);
        self::druckerDateiSchreiben();
    }

    /** Namen der Faxdrucker für die Basis (lib/kienzle/drucker.py liest sie beim Abgleichen). */
    public static function druckerDateiSchreiben(): void {
        $json = (string)json_encode(['drucker' => array_map(fn($x) => ['name' => $x['name']], self::drucker())]);
        $pfad = self::$data . '/drucker.json';
        if (@file_get_contents($pfad) !== $json) {
            file_put_contents($pfad . '.neu', $json);
            @chmod($pfad . '.neu', 0644);
            rename($pfad . '.neu', $pfad);
        }
    }

    // ---- NAS: Export des Faxeingangs und Lauschordner -------------------------------------------------------------
    /** Ziele des Netzwerkspeichers (id → Name) aus der Verbindung „ablage“ (leer, wenn nicht eingerichtet). */
    public static function nasZiele(\KienzleModul $k): array {
        try { $a = $k->verbindung('ablage'); } catch (\Throwable) { return []; }
        $out = [];
        foreach ((array)($a['ziele'] ?? []) as $t) if (is_array($t) && preg_match('/^[a-z][a-z0-9-]{1,30}$/D', (string)($t['id'] ?? ''))) $out[(string)$t['id']] = (string)($t['name'] ?? $t['id']);
        return $out;
    }

    private static function ordnerName(string $o, string $feld): string {
        $o = trim(str_replace('\\', '/', trim($o)), '/');
        if ($o === '') return '';
        if (mb_strlen($o) > 120 || !preg_match('#^[A-Za-z0-9_ äöüÄÖÜß.-]+(/[A-Za-z0-9_ äöüÄÖÜß.-]+)*$#Du', $o) || str_contains($o, '..')) {
            throw new \InvalidArgumentException($feld . ': nur Buchstaben, Ziffern, Leerzeichen, _ . - und / (Unterordner), keine „..“.');
        }
        return $o;
    }

    public static function nasSpeichern(\KienzleModul $k, array $p): void {
        $ziele = self::nasZiele($k);
        $d = self::roh();
        $ziel = (string)($p['export_ziel'] ?? '');
        if ($ziel !== '' && !isset($ziele[$ziel])) throw new \InvalidArgumentException('Export: unbekanntes Ziel.');
        $d['export'] = ['ziel' => $ziel, 'ordner' => self::ordnerName((string)($p['export_ordner'] ?? ''), 'Exportordner') ?: 'Fax-Eingang', 'jahresordner' => !empty($p['export_jahresordner'])];
        $lziel = (string)($p['lausch_ziel'] ?? '');
        if ($lziel !== '' && !isset($ziele[$lziel])) throw new \InvalidArgumentException('Lauschordner: unbekanntes Ziel.');
        $lordner = self::ordnerName((string)($p['lausch_ordner'] ?? ''), 'Lauschordner') ?: 'Fax-Ausgang';
        if ($lziel !== '' && $lziel === $ziel && strcasecmp($lordner, $d['export']['ordner']) === 0) {
            throw new \InvalidArgumentException('Export- und Lauschordner dürfen nicht derselbe Ordner sein (sonst würde jedes empfangene Fax wieder zum Senden angeboten).');
        }
        $d['lausch'] = ['ziel' => $lziel, 'ordner' => $lordner];
        self::rohSchreiben($d);
        $k->lauschen($lziel === '' ? null : $lziel, $lordner, self::ENDUNGEN);
    }

    private static function exportiert(): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/exportiert.json'), true);
        return is_array($d) ? $d : [];
    }

    /** Neue Faxe des Eingangs auf das NAS legen lassen (ks-ablage überträgt und wiederholt bei Fehlern). Liefert die Anzahl. */
    public static function exportieren(\KienzleModul $k): int {
        $e = self::einstellungen()['export'];
        if ($e['ziel'] === '' || !isset(self::nasZiele($k)[$e['ziel']])) return 0;
        $schon = self::exportiert();
        $n = 0;
        foreach (array_reverse(self::eingang()) as $f) {
            if (isset($schon[$f['name']])) continue;
            $pdf = (string)@file_get_contents(self::ordner('eingang') . '/' . $f['name'] . '.pdf');
            if ($pdf === '') continue;
            $von = self::dateiTitel($f['kontakt'] !== '' ? $f['kontakt'] : ($f['absender'] !== '' ? self::nummer($f['absender']) ?: $f['absender'] : 'unbekannt'));
            $name = date('Y-m-d_H-i', $f['zeit']) . '_' . $von . '_' . ($f['seiten'] !== null && $f['seiten'] !== '' ? (int)$f['seiten'] . 'S_' : '') . substr(preg_replace('/\D/', '', $f['name']), -6) . '.pdf';
            try {
                $k->ablegen($e['ziel'], $pdf, $name, $e['ordner'] . ($e['jahresordner'] ? '/{jahr}/{monat}' : ''), (new \DateTimeImmutable())->setTimestamp($f['zeit']));
                $schon[$f['name']] = time();
                $n++;
            } catch (\Throwable $ex) {
                error_log('fax: Export: ' . $ex->getMessage());
                break;
            }
        }
        if ($n > 0) {
            $schon = array_filter($schon, fn($z, $name) => is_file(self::ordner('eingang') . '/' . $name . '.pdf') || $z > time() - 30 * 86400, ARRAY_FILTER_USE_BOTH);
            file_put_contents(self::$data . '/exportiert.json', (string)json_encode($schon));
            @chmod(self::$data . '/exportiert.json', 0600);
        }
        return $n;
    }

    /** Dateien aus dem Lauschordner (von ks-ablage geholt) in die Quelle „NAS“ übernehmen; nicht lesbare in lausch-fehler/. */
    public static function lauschHolen(\KienzleModul $k): int {
        $dir = $k->lauschenEingang();
        if ($dir === '' || !is_dir($dir)) return 0;
        $n = 0;
        foreach (glob($dir . '/*') ?: [] as $f) {
            $name = basename($f);
            if (!is_file($f) || is_link($f) || str_starts_with($name, '.')) continue;
            try {
                $pdf = self::alsPdf((string)file_get_contents($f), $name);
                $ziel = self::ordner('drucker') . '/' . self::NAS;
                if (!is_dir($ziel)) mkdir($ziel, 0700, true);
                self::druckAblegen(self::NAS, $pdf, $name);
                @unlink($f);
                $n++;
            } catch (\Throwable $e) {
                error_log('fax: Lauschordner: ' . $name . ': ' . $e->getMessage());
                $fehler = self::$data . '/lausch-fehler';
                if (!is_dir($fehler)) @mkdir($fehler, 0700, true);
                @rename($f, $fehler . '/' . date('Ymd-His') . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name));
            }
        }
        return $n;
    }

    // ---- Umwandlung in PDF (Faxdrucker, Hochladen, Lauschordner) --------------------------------------------------
    /**
     * Inhalt als PDF: PDF bleibt, PostScript wandelt Ghostscript, JPEG wird (ohne Umweg) in eine A4-Seite gesetzt, andere Bilder
     * (PNG, GIF, BMP, WebP) wandelt zuerst GD in JPEG. Alles andere (Word, LibreOffice …) lehnt das Modul ab.
     */
    public static function alsPdf(string $inhalt, string $name = ''): string {
        if ($inhalt === '' || strlen($inhalt) > self::MAX_PDF) throw new \InvalidArgumentException('Datei leer oder zu groß (höchstens 30 MB).');
        if (str_starts_with($inhalt, '%PDF-')) return $inhalt;
        if (str_starts_with($inhalt, '%!')) return self::postscript($inhalt);
        $info = @getimagesizefromstring($inhalt);
        if ($info !== false && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_BMP, IMAGETYPE_WEBP], true)) return self::bildAlsPdf($inhalt, $info);
        throw new \InvalidArgumentException(($name !== '' ? '„' . mb_substr($name, 0, 50) . '“: ' : '') . 'Nur PDF, PostScript oder Bilder (JPEG, PNG, GIF, BMP, WebP). Word- und LibreOffice-Dokumente bitte als PDF speichern oder auf den Faxdrucker drucken.');
    }

    private static function postscript(string $inhalt): string {
        $tmp = self::$data . '/.druck-' . bin2hex(random_bytes(4));
        file_put_contents($tmp . '.ps', $inhalt);
        $p = proc_open(['/usr/bin/gs', '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-sPAPERSIZE=a4', '-o', $tmp . '.pdf', $tmp . '.ps'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) { @unlink($tmp . '.ps'); throw new \RuntimeException('Ghostscript fehlt.'); }
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        $pdf = $code === 0 && is_file($tmp . '.pdf') ? (string)file_get_contents($tmp . '.pdf') : '';
        @unlink($tmp . '.ps'); @unlink($tmp . '.pdf');
        if (!str_starts_with($pdf, '%PDF-')) throw new \RuntimeException('Umwandlung in PDF fehlgeschlagen.');
        return $pdf;
    }

    /** Bild als eine A4-Seite (Hoch- oder Querformat, mit Rand, Seitenverhältnis bleibt). */
    private static function bildAlsPdf(string $inhalt, array $info): string {
        if ($info[2] !== IMAGETYPE_JPEG) {
            if (!function_exists('imagecreatefromstring')) throw new \RuntimeException('Dieses Bildformat braucht die PHP-Erweiterung GD.');
            $bild = @imagecreatefromstring($inhalt);
            if (!$bild) throw new \InvalidArgumentException('Bild nicht lesbar.');
            $weiss = imagecreatetruecolor(imagesx($bild), imagesy($bild));
            imagefill($weiss, 0, 0, (int)imagecolorallocate($weiss, 255, 255, 255));
            imagecopy($weiss, $bild, 0, 0, 0, 0, imagesx($bild), imagesy($bild));      // Transparenz wird weiß
            ob_start();
            imagejpeg($weiss, null, 90);
            $inhalt = (string)ob_get_clean();
            $info = @getimagesizefromstring($inhalt) ?: throw new \RuntimeException('Bild ließ sich nicht umwandeln.');
        }
        [$w, $h] = $info;
        $kanaele = (int)($info['channels'] ?? 3);
        if ($w < 1 || $h < 1 || !in_array($kanaele, [1, 3], true)) throw new \InvalidArgumentException('JPEG im CMYK-Format wird nicht unterstützt. Bitte als PDF speichern.');
        $quer = $w > $h;
        [$sw, $sh] = $quer ? [842, 595] : [595, 842];
        $f = min(($sw - 40) / $w, ($sh - 40) / $h);
        $bw = $w * $f; $bh = $h * $f;
        $x = ($sw - $bw) / 2; $y = ($sh - $bh) / 2;
        $inhaltsstrom = sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im0 Do Q", $bw, $bh, $x, $y);
        $obj = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $sw $sh] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>",
            4 => "<< /Length " . strlen($inhaltsstrom) . " >>\nstream\n$inhaltsstrom\nendstream",
            5 => "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /" . ($kanaele === 1 ? 'DeviceGray' : 'DeviceRGB') . " /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($inhalt) . " >>\nstream\n$inhalt\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $pos = [];
        foreach ($obj as $nr => $inh) { $pos[$nr] = strlen($pdf); $pdf .= "$nr 0 obj\n$inh\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($obj) + 1) . "\n0000000000 65535 f \n";
        foreach ($pos as $p) $pdf .= sprintf("%010d 00000 n \n", $p);
        return $pdf . "trailer\n<< /Size " . (count($obj) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** Hochgeladene Dateien ($_FILES['dateien']) als Dokumente der Quelle „Upload“ ablegen. Liefert die Dateinamen. */
    public static function hochladen(array $files): array {
        $namen = (array)($files['name'] ?? []);
        $out = [];
        $fehler = [];
        if (count($namen) > 20) throw new \InvalidArgumentException('Höchstens 20 Dateien auf einmal.');
        foreach ($namen as $i => $orig) {
            $err = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            $tmp = (string)($files['tmp_name'][$i] ?? '');
            $orig = mb_substr((string)$orig, 0, 120);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $fehler[] = "„{$orig}“ ist zu groß (höchstens 3 MB)."; continue; }
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) && !self::$testUpload) { $fehler[] = "„{$orig}“ ließ sich nicht hochladen."; continue; }
            try {
                $out[] = self::druckAblegen(self::UPLOAD, self::alsPdf((string)file_get_contents($tmp), $orig), $orig);
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                $fehler[] = $e->getMessage();
            }
        }
        if (!$out && !$fehler) throw new \InvalidArgumentException('Keine Datei gewählt.');
        if ($fehler && !$out) throw new \InvalidArgumentException(implode(' ', $fehler));
        return ['dateien' => $out, 'fehler' => $fehler];
    }

    public static bool $testUpload = false;

    // ---- Pflege (Zeitgeber jede Minute und beim Laden der Seiten) ---------------------------------------------------
    /** Drucker mit fester Nummer und „sofort senden“: neue Dokumente gehen ohne Rückfrage hinaus. Liefert die Anzahl. */
    public static function sofortSenden(): int {
        if (!self::austauschBereit()) return 0;
        $n = 0;
        foreach (self::drucker() as $x) {
            if (!$x['sofort'] || $x['nummer'] === '') continue;
            foreach (self::quelle($x['name']) as $f) {
                if ($f['zeit'] > time() - 5) continue;     // Auftrag noch nicht ganz geschrieben
                try {
                    $n += self::senden($x['name'], [$f['datei']], [['name' => $x['bezeichnung'] !== '' ? $x['bezeichnung'] : $x['name'], 'nummer' => $x['nummer'], 'merken' => false]], 'fine', true, 'Faxdrucker ' . $x['name']);
                } catch (\Throwable $e) {
                    error_log('fax: sofort senden: ' . $e->getMessage());
                    break;
                }
            }
        }
        return $n;
    }

    /** Alles, was ohne geöffnete Seite passieren soll: Ergebnisse abholen, sofort senden, Lauschordner, Export, Aufräumen. */
    public static function pflege(?\KienzleModul $k = null): array {
        $lock = fopen(self::$data . '/.pflege.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['uebersprungen' => true];
        try {
            $r = ['abgeholt' => self::abholen(), 'sofort' => self::sofortSenden(), 'lausch' => 0, 'export' => 0];
            if ($k) {
                try { $r['lausch'] = self::lauschHolen($k); $r['export'] = self::exportieren($k); } catch (\Throwable $e) { error_log('fax: ' . $e->getMessage()); }
                $r['gemeldet'] = self::eingangMelden($k);
            }
            $r['aufgeraeumt'] = self::aufraeumen();
            return $r;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Ältere Dateien löschen (Fristen aus den Einstellungen; 0 = unbegrenzt). Liefert die Anzahl. */
    public static function aufraeumen(?int $jetzt = null): int {
        $jetzt ??= time();
        $e = self::einstellungen();
        $n = 0;
        $ordner = [[self::ordner('eingang'), $e['eingang_tage']], [self::ordner('protokoll'), $e['protokoll_tage']], [self::ordner('fehler'), $e['protokoll_tage']],
            [self::ordner('fehler-original'), $e['protokoll_tage']]];
        foreach (glob(self::ordner('drucker') . '/*', GLOB_ONLYDIR) ?: [] as $d) $ordner[] = [$d, $e['drucker_tage']];   // auch Ordner entfernter Drucker
        foreach ($ordner as [$dir, $tage]) {
            if ($tage <= 0) continue;
            foreach (glob($dir . '/*.{pdf,json}', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f) && filemtime($f) < $jetzt - $tage * 86400 && @unlink($f)) $n++;
            }
        }
        return $n;
    }

    public static function ueberblick(bool $telefonDa): array {
        return [
            ['title' => 'Modul Telefon verbunden (Faxleitung)', 'state' => $telefonDa ? 'done' : 'error',
                'hint' => $telefonDa ? '' : 'Das Modul Telefon installieren und dort unter Einstellungen → Rufnummern die Faxleitung wählen.', 'link' => '/fax/'],
            ['title' => 'Faxdrucker an den Arbeitsplätzen eingerichtet', 'state' => is_file(self::$data . '/.drucker-genutzt') ? 'done' : 'open',
                'hint' => 'Verwaltung → Drucker: den Faxdrucker (Name vFax) an den PCs einrichten und einmal etwas darauf drucken.', 'link' => '/fax/'],
        ];
    }
}
