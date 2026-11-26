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
    public const DRUCKER = ['vFax1', 'vFax2', 'vFax3', 'vFax4', 'vFax5'];
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
        if (!in_array($drucker, self::DRUCKER, true)) throw new \InvalidArgumentException('Quelle unbekannt.');
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
        foreach (self::DRUCKER as $d) $z[$d] = count(glob(self::ordner('drucker') . '/' . $d . '/*.pdf') ?: []);
        $z['sendefehler'] = count(glob(self::ordner('fehler-original') . '/*.pdf') ?: []);
        return $z;
    }

    /** Druckauftrag ablegen (vom virtuellen Drucker, als ks-fax). Liefert den Dateinamen. */
    public static function druckAblegen(string $drucker, string $pdf, string $titel, ?int $jetzt = null): string {
        if (!in_array($drucker, self::DRUCKER, true)) throw new \InvalidArgumentException('Drucker unbekannt.');
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
        $dir = $quelle === 'sendefehler' ? self::ordner('fehler-original') : (in_array($quelle, self::DRUCKER, true) ? self::ordner('drucker') . '/' . $quelle : null);
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
        foreach (['sendeberichte' => 'protokoll', 'sendefehler/berichte' => 'fehler', 'sendefehler/eingang' => 'fehler-original', 'empfang' => 'eingang'] as $von => $nach) {
            $dir = self::ax($von);
            // Beim Eingang ist ein Fax erst mit seiner JSON-Datei vollständig (fax-empfangen schreibt sie zuletzt): PDF und JSON als Paar holen.
            $dateien = $von === 'empfang' ? array_merge(...array_map(fn($j) => is_file(substr($j, 0, -5) . '.pdf') ? [substr($j, 0, -5) . '.pdf', $j] : [], glob($dir . '/*.json') ?: []))
                : (glob($dir . '/*.{pdf,json}', GLOB_BRACE) ?: []);
            foreach ($dateien as $f) {
                $b = basename($f);
                if (!self::dateiname($b) || is_link($f)) continue;
                $ziel = self::ordner($nach) . '/' . $b;
                if (@copy($f, $ziel)) { @chmod($ziel, 0600); @unlink($f); $n++; }
            }
        }
        return $n;
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

    // ---- Einstellungen und Aufräumen -----------------------------------------------------------------------------
    public static function einstellungen(): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/einstellungen.json'), true);
        $d = is_array($d) ? $d : [];
        return ['eingang_tage' => (int)($d['eingang_tage'] ?? 90), 'protokoll_tage' => (int)($d['protokoll_tage'] ?? 90), 'drucker_tage' => (int)($d['drucker_tage'] ?? 14)];
    }

    public static function einstellungenSpeichern(array $p): void {
        $e = [];
        foreach (['eingang_tage' => 'Faxeingang', 'protokoll_tage' => 'Sendeprotokoll und Fehler', 'drucker_tage' => 'Gedruckte Dokumente'] as $k => $name) {
            $v = (int)($p[$k] ?? -1);
            if ($v < 1 || $v > 3650) throw new \InvalidArgumentException($name . ': 1 bis 3650 Tage.');
            $e[$k] = $v;
        }
        file_put_contents(self::$data . '/einstellungen.json', (string)json_encode($e));
        @chmod(self::$data . '/einstellungen.json', 0600);
    }

    /** Ältere Dateien löschen (Fristen aus den Einstellungen). Liefert die Anzahl. */
    public static function aufraeumen(?int $jetzt = null): int {
        $jetzt ??= time();
        $e = self::einstellungen();
        $n = 0;
        $ordner = [[self::ordner('eingang'), $e['eingang_tage']], [self::ordner('protokoll'), $e['protokoll_tage']], [self::ordner('fehler'), $e['protokoll_tage']],
            [self::ordner('fehler-original'), $e['protokoll_tage']]];
        foreach (self::DRUCKER as $d) $ordner[] = [self::ordner('drucker') . '/' . $d, $e['drucker_tage']];
        foreach ($ordner as [$dir, $tage]) {
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
                'hint' => 'Verwaltung → Drucker: vFax1 bis vFax5 an den PCs einrichten und einmal etwas darauf drucken.', 'link' => '/fax/'],
        ];
    }
}
