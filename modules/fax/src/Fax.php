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
            'fehler-original' => self::$data . '/fehler/original', 'protokoll-original' => self::$data . '/protokoll/original', 'eingang' => self::$data . '/eingang'];
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
            if ($num === '' && preg_match('/^[\d\s+\/().-]{6,30}$/D', $name)) { $num = $name; $name = ''; }   // Nummer direkt ins Suchfeld getippt
            $norm = self::nummer($num);
            if ($norm === '') throw new \InvalidArgumentException("Empfänger $i: Faxnummer fehlt oder ist ungültig.");
            if ($name === '') $name = self::anzeige($norm);
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

    /** Pfad einer Quelldatei (Faxdrucker oder Original eines Sendefehlers/erfolgreich gesendeten Faxes), streng geprüft. */
    public static function quellPfad(string $quelle, string $datei): array {
        if (!self::dateiname($datei) || !str_ends_with($datei, '.pdf')) throw new \InvalidArgumentException('Datei ungültig.');
        $dir = $quelle === 'sendefehler' ? self::ordner('fehler-original') : ($quelle === 'sendeprotokoll' ? self::ordner('protokoll-original')
            : (in_array($quelle, self::quellen(), true) ? self::ordner('drucker') . '/' . $quelle : null));
        if ($dir === null) throw new \InvalidArgumentException('Quelle unbekannt.');
        $pfad = $dir . '/' . $datei;
        if (!is_file($pfad) || is_link($pfad)) throw new \InvalidArgumentException('Datei nicht gefunden: ' . $datei);
        return [$pfad, $dir];
    }

    // ---- Aufträge ----------------------------------------------------------------------------------------------
    /** Alte Form (sofort senden der Faxdrucker, Tests): jede Datei als eigenes Fax. */
    public static function senden(string $quelle, array $dateien, array $empfaenger, string $aufloesung, bool $ecm, string $benutzer): int {
        $dateien = array_values(array_unique(array_map('strval', $dateien)));
        if (!$dateien) throw new \InvalidArgumentException('Bitte mindestens ein Dokument auswählen.');
        return self::sendenAuswahl(array_map(fn($d) => $quelle . '/' . $d, $dateien), $empfaenger, $aufloesung, $ecm, true, $benutzer);
    }

    /** Schlüssel „quelle/datei“ eines Dokuments in Pfad, Quelle und Titel auflösen (streng geprüft). */
    public static function dokument(string $schluessel): array {
        $p = strpos($schluessel, '/');
        if ($p === false) throw new \InvalidArgumentException('Dokument ungültig.');
        $quelle = substr($schluessel, 0, $p);
        $datei = substr($schluessel, $p + 1);
        [$pfad] = self::quellPfad($quelle, $datei);
        $titel = in_array($quelle, ['sendefehler', 'sendeprotokoll'], true) ? (string)preg_replace('/(__JOB-.*)?\.pdf$/', '', $datei) : (self::metaTitel($pfad) ?: 'Dokument');
        return ['pfad' => $pfad, 'quelle' => $quelle, 'datei' => $datei, 'titel' => $titel];
    }

    /**
     * Ausgewählte Dokumente (Schlüssel „quelle/datei“ in der gewünschten Reihenfolge) an alle Empfänger senden. Mehrere Dokumente
     * werden zu EINEM Fax zusammengefügt (ein Anruf, ein Sendebericht), mit $einzeln geht jedes als eigenes Fax. Aufträge entstehen
     * erst in staging/ und gehen dann atomar nach queue/. Danach werden die Quelldateien gelöscht. Liefert die Anzahl der Aufträge.
     */
    public static function sendenAuswahl(array $schluessel, array $empfaenger, string $aufloesung, bool $ecm, bool $einzeln, string $benutzer): int {
        if (!self::austauschBereit()) throw new \RuntimeException('Das Modul Telefon fehlt oder ist noch nicht verbunden (Betrieb → Module).');
        $schluessel = array_values(array_unique(array_filter(array_map('strval', $schluessel), fn($x) => $x !== '')));
        if (!$schluessel) throw new \InvalidArgumentException('Bitte mindestens ein Dokument auswählen.');
        if (count($schluessel) > self::MAX_DOKUMENTE) throw new \InvalidArgumentException('Höchstens ' . self::MAX_DOKUMENTE . ' Dokumente auf einmal.');
        if (!in_array($aufloesung, ['fine', 'standard'], true)) $aufloesung = 'fine';
        $doks = array_map(fn($x) => self::dokument($x), $schluessel);
        $tmp = null;
        if ($einzeln || count($doks) === 1) {
            $faxe = array_map(fn($d) => ['pfad' => $d['pfad'], 'quelle' => $d['quelle'], 'titel' => $d['titel']], $doks);
        } else {
            $tmp = self::zusammenfuegen(array_column($doks, 'pfad'));
            $faxe = [['pfad' => $tmp, 'quelle' => implode('+', array_values(array_unique(array_column($doks, 'quelle')))),
                'titel' => preg_replace('/\.(pdf|ps|jpe?g|png|gif|bmp|webp)$/i', '', $doks[0]['titel']) . ' + ' . (count($doks) - 1) . ' weitere']];
        }
        $staging = self::axOrdner('staging');
        $queue = self::axOrdner('queue');
        $neu = [];
        try {
            foreach ($faxe as $fax) {
                foreach ($empfaenger as $e) {
                    $id = 'JOB-' . date('Ymd-His') . '-' . self::zufall(6);
                    $dir = $staging . '/' . $id;
                    if (!mkdir($dir, 0770)) throw new \RuntimeException('Auftrag ließ sich nicht anlegen.');
                    chmod($dir, 0770);
                    foreach (['doc.pdf', 'source.pdf'] as $f) {
                        if (!copy($fax['pfad'], "$dir/$f")) throw new \RuntimeException('Dokument ließ sich nicht kopieren.');
                        chmod("$dir/$f", 0660);
                    }
                    $job = ['job_id' => $id, 'created_at' => date('c'), 'source' => ['src' => $fax['quelle'], 'filename_original' => self::dateiTitel($fax['titel']) . '.pdf'],
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
        } finally {
            if ($tmp !== null) @unlink($tmp);
        }
        foreach ($doks as $d) { @unlink($d['pfad']); @unlink(substr($d['pfad'], 0, -4) . '.json'); }
        foreach ($empfaenger as $e) if ($e['merken']) self::kontaktSpeichern(0, $e['name'], $e['nummer'], '');
        self::zuletztMerken($empfaenger);
        return count($neu);
    }

    /** Mehrere PDFs in dieser Reihenfolge zu einer Datei zusammenfügen (Ghostscript pdfwrite). Liefert den Pfad. */
    private static function zusammenfuegen(array $pfade): string {
        $ziel = self::$data . '/.zusammen-' . bin2hex(random_bytes(4)) . '.pdf';
        [$code] = self::gs(['-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', '-o', $ziel, ...$pfade]);
        if ($code !== 0 || !is_file($ziel) || (string)file_get_contents($ziel, false, null, 0, 5) !== '%PDF-') {
            @unlink($ziel);
            throw new \RuntimeException('Die Dokumente ließen sich nicht zusammenfügen. Bitte „Jedes Dokument als eigenes Fax“ wählen.');
        }
        return $ziel;
    }

    /** Ghostscript ohne Shell starten; liefert [Rückgabewert, Ausgabe]. */
    private static function gs(array $args): array {
        // Nur Pipes: /dev/null liegt außerhalb von open_basedir des Moduls (daran scheiterte die Vorschau auf dem Server).
        $p = @proc_open(['/usr/bin/gs', '-q', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) { error_log('fax: Ghostscript ließ sich nicht starten.'); return [127, '']; }
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        if ($code !== 0) error_log('fax: Ghostscript meldet Fehler ' . $code . ': ' . mb_substr(trim(preg_replace('/\s+/', ' ', $err) ?? ''), 0, 300));
        return [$code, $out];
    }

    // ---- Dokumente für „Fax senden“: alle Quellen in einer Liste, Seitenzahl, Vorschau -----------------------------
    public const MAX_DOKUMENTE = 20;

    /** Alle Dokumente aller Quellen (Faxdrucker, Upload, NAS, Sendefehler), neueste zuerst, mit Seitenzahl. */
    public static function dokumente(): array {
        $out = [];
        foreach ([...self::quellen(), 'sendefehler'] as $q) {
            if ($q === 'sendefehler') {
                $liste = [];
                foreach (glob(self::ordner('fehler-original') . '/*.pdf') ?: [] as $f) {
                    $n = basename($f);
                    if (!self::dateiname($n) || is_link($f)) continue;
                    $liste[] = ['datei' => $n, 'groesse' => (int)filesize($f), 'zeit' => (int)filemtime($f), 'titel' => (string)preg_replace('/(__JOB-.*)?\.pdf$/', '', $n)];
                }
                $dir = self::ordner('fehler-original');
            } else {
                $liste = self::quelle($q);
                $dir = self::ordner('drucker') . '/' . $q;
            }
            foreach ($liste as $f) {
                $out[] = $f + ['quelle' => $q, 'schluessel' => $q . '/' . $f['datei'], 'seiten' => self::seitenZahl($dir . '/' . $f['datei'])];
            }
        }
        usort($out, fn($a, $b) => [$b['zeit'], $b['datei']] <=> [$a['zeit'], $a['datei']]);
        self::seitenCacheSchreiben();
        return $out;
    }

    private static ?array $seitenCache = null;
    private static bool $seitenGeaendert = false;

    private static function cacheSchluessel(string $pfad): string { return sha1($pfad . '|' . (int)@filemtime($pfad) . '|' . (int)@filesize($pfad)); }

    /** Seitenzahl einer PDF (Ghostscript, zwischengespeichert); null, wenn sie sich nicht bestimmen lässt. */
    public static function seitenZahl(string $pfad): ?int {
        self::$seitenCache ??= (array)(json_decode((string)@file_get_contents(self::$data . '/.seiten.json'), true) ?: []);
        $k = self::cacheSchluessel($pfad);
        if (isset(self::$seitenCache[$k])) return (int)self::$seitenCache[$k] ?: null;
        [$code, $out] = self::gs(['-dNODISPLAY', '-dSAFER', '--permit-file-read=' . $pfad, '-sDatei=' . $pfad, '-c', 'Datei (r) file runpdfbegin pdfpagecount = quit']);
        $n = $code === 0 && preg_match('/^\s*(\d{1,5})\s*$/', $out, $m) ? (int)$m[1] : 0;
        if ($n === 0) $n = preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', (string)@file_get_contents($pfad, false, null, 0, self::MAX_PDF));
        self::$seitenCache[$k] = $n;
        self::$seitenGeaendert = true;
        return $n ?: null;
    }

    private static function seitenCacheSchreiben(): void {
        if (!self::$seitenGeaendert) return;
        $c = array_slice(self::$seitenCache ?? [], -500, null, true);
        file_put_contents(self::$data . '/.seiten.json', (string)json_encode($c));
        @chmod(self::$data . '/.seiten.json', 0600);
        self::$seitenGeaendert = false;
    }

    /**
     * Vorschau: oberer Teil der ersten Seite als PNG in Graustufen (so ungefähr sieht es auch das Faxgerät). Zwischengespeichert in
     * <daten>/vorschau (0700), nach einem Tag gelöscht (aufraeumen), weil die Bilder Gesundheitsdaten zeigen können.
     */
    public static function vorschau(string $pfad): string {
        $dir = self::$data . '/vorschau';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $cache = $dir . '/' . self::cacheSchluessel($pfad) . '.png';
        if (is_file($cache)) return (string)file_get_contents($cache);
        $tmp = $dir . '/.neu-' . bin2hex(random_bytes(4)) . '.png';
        [$code] = self::gs(['-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=pnggray', '-r72', '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
            '-dFirstPage=1', '-dLastPage=1', '-o', $tmp, $pfad]);
        $png = $code === 0 && is_file($tmp) ? (string)file_get_contents($tmp) : '';
        @unlink($tmp);
        if (!str_starts_with($png, "\x89PNG")) throw new \RuntimeException('Keine Vorschau möglich.');
        if (function_exists('imagecreatefromstring') && ($bild = @imagecreatefromstring($png))) {
            $w = imagesx($bild);
            $h = imagesy($bild);
            $oben = @imagecrop($bild, ['x' => 0, 'y' => 0, 'width' => $w, 'height' => max(1, min($h, (int)round($w * 0.62)))]);
            if ($oben) { ob_start(); imagepng($oben, null, 9); $png = (string)ob_get_clean(); }
        }
        file_put_contents($cache, $png);
        @chmod($cache, 0600);
        return $png;
    }

    // ---- Zuletzt gefaxt an ---------------------------------------------------------------------------------------
    public static function zuletzt(): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/zuletzt.json'), true);
        return is_array($d) ? array_values(array_filter($d, fn($e) => is_array($e) && is_string($e['name'] ?? null) && is_string($e['nummer'] ?? null))) : [];
    }

    private static function zuletztMerken(array $empfaenger): void {
        $liste = self::zuletzt();
        foreach ($empfaenger as $e) {
            $liste = array_values(array_filter($liste, fn($x) => $x['nummer'] !== $e['nummer']));
            array_unshift($liste, ['name' => $e['name'], 'nummer' => $e['nummer']]);
        }
        file_put_contents(self::$data . '/zuletzt.json', (string)json_encode(array_slice($liste, 0, 8), JSON_UNESCAPED_UNICODE));
        @chmod(self::$data . '/zuletzt.json', 0600);
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
        foreach (['sendeberichte' => 'protokoll', 'sendeberichte/eingang' => 'protokoll-original', 'sendefehler/berichte' => 'fehler', 'sendefehler/eingang' => 'fehler-original', 'empfang' => 'eingang'] as $von => $nach) {
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
            . "Öffnen: Praxisherz → Fax → Eingang.\n\nDiese Nachricht enthält aus Datenschutzgründen weder Absender noch Inhalt.\n"
            . ($praxis !== '' ? "\n$praxis\n" : '');
        try {
            $k->mailSenden($an, $anzahl === 1 ? 'Neues Fax eingegangen' : "$anzahl neue Faxe eingegangen", $text);
        } catch (\Throwable $e) {
            error_log('fax: Benachrichtigung nicht abgelegt: ' . $e->getMessage());
            return 0;
        }
        return $anzahl;
    }

    /** Texterkennung empfangener Faxe ein/aus; aus löscht die erkannten Texte. */
    public static function ocrSpeichern(bool $an): void {
        $d = self::roh();
        $d['ocr'] = $an;
        self::rohSchreiben($d);
        if (!$an) foreach (glob(self::ordner('eingang') . '/*.txt') ?: [] as $f) @unlink($f);
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
        $originalDir = self::ordner($art === 'fehler' ? 'fehler-original' : 'protokoll-original');
        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            $b = basename($f);
            if (!self::dateiname($b)) continue;
            $j = json_decode((string)@file_get_contents($f), true);
            if (!is_array($j)) continue;
            $pdf = substr($b, 0, -5) . ($art === 'fehler' ? '__FAILED.pdf' : '__OK.pdf');
            $ende = (string)($j['finalized_at'] ?? $j['end_time'] ?? $j['created_at'] ?? '');
            $grund = (string)($j['result']['reason'] ?? '');
            $jobId = (string)($j['job_id'] ?? '');
            $out[] = ['json' => $b, 'pdf' => is_file($dir . '/' . $pdf) ? $pdf : '', 'zeit' => strtotime($ende) ?: (int)filemtime($f),
                'name' => (string)($j['recipient']['name'] ?? ''), 'nummer' => (string)($j['recipient']['number'] ?? ''),
                'datei' => (string)($j['source']['filename_original'] ?? ''), 'seiten' => self::seiten($j), 'grund' => $grund,
                'abgebrochen' => $grund === 'cancelled' || !empty($j['cancel']['requested']), 'versuche' => (int)($j['attempt']['current'] ?? 0),
                'original' => self::jobId($jobId) && (bool)glob($originalDir . '/*__' . $jobId . '.pdf')];
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

    /** Tage, die das Original eines erfolgreich gesendeten Faxes zum erneuten Senden aufbewahrt wird (danach bleibt nur der Bericht). */
    public const PROTOKOLL_ORIGINAL_TAGE = 7;

    /**
     * Bericht (Fehlerbericht oder Sendeprotokoll) mit dem damaligen Empfänger und den damaligen Einstellungen erneut senden,
     * solange das Original noch vorliegt (bei Sendeprotokoll höchstens PROTOKOLL_ORIGINAL_TAGE Tage, siehe aufraeumen()).
     */
    public static function berichtErneutSenden(string $art, string $json, string $benutzer): int {
        if (!self::dateiname($json) || !str_ends_with($json, '.json')) throw new \InvalidArgumentException('Bericht ungültig.');
        $dir = self::ordner($art === 'fehler' ? 'fehler' : 'protokoll');
        if (!is_file("$dir/$json")) throw new \InvalidArgumentException('Bericht nicht gefunden.');
        $j = json_decode((string)file_get_contents("$dir/$json"), true);
        if (!is_array($j)) throw new \RuntimeException('Bericht nicht lesbar.');
        $jobId = (string)($j['job_id'] ?? '');
        if (!self::jobId($jobId)) throw new \RuntimeException('Bericht ohne gültige Auftrags-ID.');
        $nummer = (string)($j['recipient']['number'] ?? '');
        if ($nummer === '') throw new \RuntimeException('Bericht ohne Empfängernummer.');
        $originalDir = self::ordner($art === 'fehler' ? 'fehler-original' : 'protokoll-original');
        $treffer = glob($originalDir . '/*__' . $jobId . '.pdf') ?: [];
        if (!$treffer) throw new \RuntimeException('Das Original liegt nicht mehr vor (' . ($art === 'fehler'
            ? 'vermutlich schon erneut gesendet oder gelöscht' : 'erfolgreich gesendete Faxe werden nach ' . self::PROTOKOLL_ORIGINAL_TAGE . ' Tagen entfernt') . ').');
        $empfaenger = [['name' => (string)($j['recipient']['name'] ?? ''), 'nummer' => $nummer, 'merken' => false]];
        return self::senden($art === 'fehler' ? 'sendefehler' : 'sendeprotokoll', [basename($treffer[0])], $empfaenger,
            (string)($j['options']['resolution'] ?? 'fine'), (bool)($j['options']['ecm'] ?? true), $benutzer);
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
                'gelesen' => isset($gelesen[$b]), 'erkannt' => is_file("$dir/$b.txt")];
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
        @unlink("$dir/$name.txt");
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
        $spalten = array_column($pdo->query('PRAGMA table_info(kontakte)')->fetchAll(), 'name');
        if (!in_array('favorit', $spalten, true)) $pdo->exec('ALTER TABLE kontakte ADD COLUMN favorit INTEGER NOT NULL DEFAULT 0');
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

    public static function kontaktSpeichern(int $id, string $name, string $nummer, string $notiz, ?bool $favorit = null): int {
        $name = self::zeile($name, 'Name', 80);
        $norm = self::nummer($nummer);
        if ($norm === '') throw new \InvalidArgumentException('Faxnummer fehlt oder ist ungültig.');
        $notiz = self::zeile($notiz, 'Notiz', 200, false);
        $jetzt = date('c');
        if ($id > 0) {
            $st = self::db()->prepare('UPDATE kontakte SET name = ?, nummer = ?, notiz = ?, geaendert = ? WHERE id = ?');
            $st->execute([$name, $norm, $notiz, $jetzt, $id]);
            if ($st->rowCount() === 0) throw new \InvalidArgumentException('Eintrag nicht gefunden.');
            if ($favorit !== null) self::kontaktFavorit($id, $favorit);
            return $id;
        }
        if ((int)self::db()->query('SELECT COUNT(*) FROM kontakte')->fetchColumn() >= 5000) throw new \InvalidArgumentException('Telefonbuch ist voll (5000 Einträge).');
        self::db()->prepare('INSERT INTO kontakte (name, nummer, notiz, erstellt, geaendert, favorit) VALUES (?, ?, ?, ?, ?, ?)')->execute([$name, $norm, $notiz, $jetzt, $jetzt, $favorit ? 1 : 0]);
        return (int)self::db()->lastInsertId();
    }

    /** Favorit an/aus (Favoriten stehen bei „Fax senden“ direkt zur Auswahl). */
    public static function kontaktFavorit(int $id, bool $an): void {
        $st = self::db()->prepare('UPDATE kontakte SET favorit = ? WHERE id = ?');
        $st->execute([$an ? 1 : 0, $id]);
        if ($st->rowCount() === 0) throw new \InvalidArgumentException('Eintrag nicht gefunden.');
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
            'mail_eingang' => array_values(array_filter((array)($d['mail_eingang'] ?? []), fn($a) => is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL))),
            'ocr' => (bool)($d['ocr'] ?? true)];
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

    /** Eine Datei gilt als fertig kopiert, wenn sie seit einer Sekunde weder größer noch neuer geworden ist. */
    private static function fertigKopiert(string $f): bool {
        clearstatcache(true, $f);
        $vorher = [@filesize($f), @filemtime($f)];
        if (time() - (int)$vorher[1] >= 2) return true;
        usleep(1_200_000);
        clearstatcache(true, $f);
        return $vorher === [@filesize($f), @filemtime($f)];
    }

    /** Dateien aus dem Lauschordner (von ks-ablage geholt) in die Quelle „NAS“ übernehmen; nicht lesbare in lausch-fehler/. */
    public static function lauschHolen(\KienzleModul $k): int {
        $quellen = array_values(array_filter([$k->lauschenEingang(), self::$data . '/ablage'], fn($d) => $d !== '' && is_dir($d)));
        $n = 0;
        foreach ($quellen as $dir) foreach (glob($dir . '/*') ?: [] as $f) {
            $name = basename($f);
            if (!is_file($f) || is_link($f) || str_starts_with($name, '.')) continue;
            if (!self::fertigKopiert($f)) continue;   // wird gerade noch geschrieben (Windows-Freigabe „Fax-Ausgang“)
            try {
                $roh = @file_get_contents($f);
                if ($roh === false) { error_log('fax: Lauschordner: ' . $name . ' ist nicht lesbar (Rechte); bleibt liegen.'); continue; }
                $pdf = self::alsPdf($roh, $name);
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

    /** Höchstgröße einer roh hochgeladenen Datei (PHP der Module: post_max_size 8M). */
    public const UPLOAD_MAX = 7 * 1024 * 1024;

    /**
     * Eine Datei roh aus dem Anfragekörper übernehmen (Module haben keine verlässlichen Formular-Uploads: ein multipart-Upload
     * hing auf der VM ohne Meldung, siehe AGENTS.md „Fallen“). Liefert den Dateinamen im Ordner „Upload“.
     */
    public static function hochladenRoh(string $bytes, string $name): string {
        $name = trim(mb_substr(str_replace(["\0", '/', '\\'], '', $name), 0, 120));
        if ($bytes === '') throw new \InvalidArgumentException('Die Datei ist leer.');
        if (strlen($bytes) > self::UPLOAD_MAX) throw new \InvalidArgumentException("„{$name}“ ist zu groß (höchstens 7 MB).");
        return self::druckAblegen(self::UPLOAD, self::alsPdf($bytes, $name !== '' ? $name : 'Dokument'), $name !== '' ? $name : 'Dokument');
    }

    // ---- Texterkennung empfangener Faxe (eigener Dienst kienzle-fax-ocr, niedrige Priorität) -----------------------
    public const OCR_MAX_SEITEN = 30;

    /** Pfad der erkannten Textdatei eines Faxes im Eingang (<name>.txt, 0600). */
    public static function textPfad(string $name): string { return self::ordner('eingang') . '/' . $name . '.txt'; }

    /** Erkannter Text eines Faxes ('' = noch nicht erkannt oder nichts lesbar). */
    public static function text(string $name): string {
        if (!preg_match('/^[0-9]{8}-[0-9]{6}_[0-9a-z]{1,32}_[0-9]{1,40}$/D', $name)) return '';
        return (string)@file_get_contents(self::textPfad($name));
    }

    /** Programm ohne Shell starten (nur Pipes, siehe gs()); liefert [Rückgabewert, Ausgabe]. */
    private static function programm(array $befehl, int $zeit = 120): array {
        $p = @proc_open($befehl, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) return [127, ''];
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], $zeit);
        $out = (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($p), $out];
    }

    /** Text einer PDF erkennen: Seiten mit Ghostscript als Bild (300 dpi, Graustufen), dann Tesseract (Deutsch). */
    public static function erkennen(string $pdf): string {
        $tmp = self::$data . '/.ocr-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0700);
        try {
            [$code] = self::gs(['-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=pnggray', '-r300', '-dFirstPage=1', '-dLastPage=' . self::OCR_MAX_SEITEN, '-o', $tmp . '/s%03d.png', $pdf]);
            $seiten = glob($tmp . '/s*.png') ?: [];
            if ($code !== 0 || !$seiten) throw new \RuntimeException('Fax ließ sich nicht in Bilder umwandeln.');
            sort($seiten);
            $text = [];
            foreach ($seiten as $bild) {
                [$c, $out] = self::programm(['/usr/bin/tesseract', $bild, 'stdout', '-l', 'deu', '--psm', '3']);
                if ($c !== 0) throw new \RuntimeException('Tesseract meldet Fehler ' . $c . '.');
                $text[] = trim(str_replace("\f", '', $out));
            }
            return implode("\n\f\n", $text);
        } finally {
            foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
            @rmdir($tmp);
        }
    }

    /**
     * Neue Faxe im Eingang erkennen (neueste zuerst), höchstens $max je Lauf oder bis $sekunden vorbei sind. Ein Fax, das sich nicht
     * lesen lässt, bekommt eine leere Textdatei (kein endloses Wiederholen). Liefert die Anzahl.
     */
    public static function ocrLauf(int $max = 5, int $sekunden = 240): int {
        if (!self::einstellungen()['ocr'] || !is_executable('/usr/bin/tesseract')) return 0;
        $lock = fopen(self::$data . '/.ocr.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0;
        $start = time();
        $n = 0;
        try {
            foreach (self::eingang() as $f) {
                if ($n >= $max || time() - $start > $sekunden) break;
                $ziel = self::textPfad($f['name']);
                if (is_file($ziel)) continue;
                try {
                    $text = self::erkennen(self::ordner('eingang') . '/' . $f['name'] . '.pdf');
                } catch (\Throwable $e) {
                    error_log('fax: Texterkennung ' . $f['name'] . ': ' . $e->getMessage());
                    $text = '';
                }
                file_put_contents($ziel . '.neu', $text);
                chmod($ziel . '.neu', 0600);
                rename($ziel . '.neu', $ziel);
                $n++;
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        return $n;
    }

    /** Wortweise suchen (alle Wörter müssen vorkommen, Groß-/Kleinschreibung egal) in Absender, Kontakt und erkanntem Text. */
    public static function eingangSuchen(array $liste, string $suche): array {
        $woerter = array_filter(preg_split('/\s+/u', mb_strtolower(trim(mb_substr($suche, 0, 100)))) ?: [], fn($w) => $w !== '');
        if (!$woerter) return $liste;
        $out = [];
        foreach ($liste as $f) {
            $text = mb_strtolower($f['kontakt'] . ' ' . $f['absender'] . ' ' . self::anzeige(self::nummer($f['absender'])) . ' ' . self::text($f['name']));
            $ok = true;
            foreach ($woerter as $w) if (!str_contains($text, $w)) { $ok = false; break; }
            if (!$ok) continue;
            $f['treffer'] = self::ausschnitt(self::text($f['name']), $woerter[array_key_first($woerter)]);
            $out[] = $f;
        }
        return $out;
    }

    /** Kurzer Textausschnitt um den ersten Treffer (für die Trefferliste). */
    public static function ausschnitt(string $text, string $wort): string {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        $pos = mb_stripos($text, $wort);
        if ($pos === false) return '';
        $von = max(0, $pos - 50);
        return ($von > 0 ? '… ' : '') . mb_substr($text, $von, 140) . (mb_strlen($text) > $von + 140 ? ' …' : '');
    }

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
            [self::ordner('fehler-original'), $e['protokoll_tage']], [self::ordner('protokoll-original'), self::PROTOKOLL_ORIGINAL_TAGE]];
        foreach (glob(self::ordner('drucker') . '/*', GLOB_ONLYDIR) ?: [] as $d) $ordner[] = [$d, $e['drucker_tage']];   // auch Ordner entfernter Drucker
        foreach (glob(self::$data . '/vorschau/*.png') ?: [] as $f) if (filemtime($f) < $jetzt - 86400 && @unlink($f)) $n++;
        foreach (glob(self::$data . '/.zusammen-*.pdf') ?: [] as $f) if (filemtime($f) < $jetzt - 3600) @unlink($f);
        foreach (glob(self::$data . '/.ocr-*', GLOB_ONLYDIR) ?: [] as $d) {      // Reste eines abgebrochenen Texterkennungslaufs
            if (filemtime($d) >= $jetzt - 3600) continue;
            foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
            @rmdir($d);
        }
        foreach ($ordner as [$dir, $tage]) {
            if ($tage <= 0) continue;
            foreach (glob($dir . '/*.{pdf,json,txt}', GLOB_BRACE) ?: [] as $f) {
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
                'hint' => 'Der Praxisherz-Agent an den PCs (Praxis → Arbeitsplätze) richtet die Faxdrucker ein; danach einmal etwas auf einen Faxdrucker (z. B. vFax) drucken.', 'link' => '/fax/'],
        ];
    }
}
