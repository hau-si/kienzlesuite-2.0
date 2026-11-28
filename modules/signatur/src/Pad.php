<?php
declare(strict_types=1);
namespace KienzleSignatur;

/**
 * Pad-Software von signotec (signoPAD-API Linux): Die Bibliotheken liegen nicht im Repository, sondern werden vom Admin
 * über die Oberfläche hochgeladen. Aus der ZIP-Datei von signotec werden nur zwei Bibliotheken und der Lizenztext
 * übernommen (feste Dateinamen, nichts wird entpackt oder ausgeführt); beides wird vorher geprüft.
 */
final class Pad {
    /** Größe der hochgeladenen ZIP-Datei: die PHP-Instanz des Moduls nimmt höchstens 8 MB entgegen. */
    public const MAX_ZIP = 8000000;
    public const MAX_BIB = 20000000;
    public const BIBLIOTHEKEN = ['libSTPadLib.so', 'libSTCPImageEngine.so'];
    private const LIZENZ = 'licenses/signotec endnutzer lizenzvertrag.txt';

    private static function ordner(): string { return Speicher::$data . '/signotec'; }

    /** Stand der Pad-Software: installiert, Version, Zeitpunkt, Benutzer. */
    public static function status(): array {
        $info = json_decode((string)@file_get_contents(self::ordner() . '/info.json'), true);
        $da = true;
        foreach (self::BIBLIOTHEKEN as $b) $da = $da && is_file(self::ordner() . '/' . $b);
        if (!$da || !is_array($info)) return ['installiert' => false, 'version' => '', 'zeit' => 0, 'von' => ''];
        return ['installiert' => true, 'version' => (string)($info['version'] ?? ''), 'zeit' => (int)($info['zeit'] ?? 0), 'von' => (string)($info['von'] ?? '')];
    }

    public static function ordnerPfad(): string { return self::ordner(); }

    /** Eine Bibliothek muss eine 64-Bit-x86-ELF-Datei sein (Linux, x86-64). */
    public static function istElfX64(string $bytes): bool {
        return strlen($bytes) > 64 && strncmp($bytes, "\x7fELF", 4) === 0 && $bytes[4] === "\x02" && $bytes[5] === "\x01" && substr($bytes, 18, 2) === "\x3e\x00";
    }

    /**
     * Prüft die ZIP-Datei von signotec und legt die Bibliotheken ab.
     * @return array Neuer Stand wie status()
     * @throws \InvalidArgumentException mit Text für den Admin
     */
    public static function installieren(string $zipBytes, string $benutzer, bool $lizenzAkzeptiert, ?int $jetzt = null): array {
        if (!$lizenzAkzeptiert) throw new \InvalidArgumentException('Bitte zuerst bestätigen, dass Sie den Lizenzvertrag von signotec gelesen haben und akzeptieren.');
        if ($zipBytes === '') throw new \InvalidArgumentException('Bitte zuerst die ZIP-Datei auswählen.');
        if (strlen($zipBytes) > self::MAX_ZIP) throw new \InvalidArgumentException('Die Datei ist zu groß (höchstens 8 MB). Bitte die ZIP-Datei „signoPAD-API Linux“ von signotec verwenden.');
        if (strncmp($zipBytes, "PK\x03\x04", 4) !== 0) throw new \InvalidArgumentException('Das ist keine ZIP-Datei. Bitte die Datei „signoPAD-API_Linux_….zip“ von signotec auswählen.');
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Die PHP-Erweiterung zip fehlt (Paket php-zip). Bitte das Modul unter Betrieb → Module aktualisieren.');
        if (!is_dir(Speicher::$data) && !mkdir(Speicher::$data, 0700, true) && !is_dir(Speicher::$data)) throw new \RuntimeException('Datenordner nicht anlegbar.');
        $tmp = tempnam(Speicher::$data, 'zip');
        if ($tmp === false || file_put_contents($tmp, $zipBytes) === false) throw new \RuntimeException('Zwischendatei nicht schreibbar.');
        try {
            $zip = new \ZipArchive();
            if ($zip->open($tmp, \ZipArchive::RDONLY) !== true) throw new \InvalidArgumentException('Die ZIP-Datei lässt sich nicht öffnen. Bitte erneut von signotec herunterladen.');
            if ($zip->numFiles > 5000) throw new \InvalidArgumentException('Die ZIP-Datei hat zu viele Einträge.');
            $teile = [];
            foreach (self::BIBLIOTHEKEN as $b) {
                $bytes = null;
                foreach (['libSTPadLib.so' => ['libSTPadLib.so/x64/', 'Demo/x64/'], 'libSTCPImageEngine.so' => ['libSTCPImageEngine.so/x64/', 'Demo/x64/']][$b] as $pfad) {
                    $i = $zip->locateName($pfad . $b, \ZipArchive::FL_NOCASE);
                    if ($i === false) continue;
                    $st = $zip->statIndex($i);
                    if (!$st || $st['size'] > self::MAX_BIB || $st['size'] < 1000) throw new \InvalidArgumentException("$b hat eine ungewöhnliche Größe.");
                    $bytes = (string)$zip->getFromIndex($i);
                    break;
                }
                if ($bytes === null || $bytes === '') throw new \InvalidArgumentException("In der ZIP-Datei fehlt $b. Bitte das Paket „signoPAD-API Linux“ von signotec verwenden.");
                if (!self::istElfX64($bytes)) throw new \InvalidArgumentException("$b ist keine Linux-Bibliothek für x86-64.");
                $teile[$b] = $bytes;
            }
            $version = '';
            $readme = (string)$zip->getFromName('ReadMe.txt');
            if (preg_match('/signoPAD-API\s+Linux\s+Release\s+(\d+(?:\.\d+){1,3})/i', $readme, $m)) $version = $m[1];
            $lizenz = (string)$zip->getFromName(self::LIZENZ);
            $zip->close();
        } finally {
            @unlink($tmp);
        }
        $ziel = self::ordner();
        if (!is_dir($ziel) && !mkdir($ziel, 0700, true) && !is_dir($ziel)) throw new \RuntimeException('Ordner für die Pad-Software nicht anlegbar.');
        foreach ($teile as $name => $bytes) {
            $t = $ziel . '/.neu-' . $name;
            if (file_put_contents($t, $bytes) === false || !@chmod($t, 0640) || !rename($t, $ziel . '/' . $name)) throw new \RuntimeException("$name nicht schreibbar.");
        }
        if ($lizenz !== '') file_put_contents($ziel . '/Lizenzvertrag-signotec.txt', $lizenz);
        $info = ['version' => $version, 'zeit' => $jetzt ?? time(), 'von' => $benutzer, 'lizenz_akzeptiert' => true,
            'sha256' => array_map(fn($b) => hash('sha256', $b), $teile)];
        file_put_contents($ziel . '/info.json', json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return self::status();
    }

    public static function entfernen(): void {
        foreach (array_merge(self::BIBLIOTHEKEN, ['info.json', 'Lizenzvertrag-signotec.txt']) as $f) @unlink(self::ordner() . '/' . $f);
        @rmdir(self::ordner());
    }

    /** Private IPv4-Netze (a.b.c) dieses Servers aus /proc/net/fib_trie, ohne Loopback; höchstens 4. */
    public static function eigeneNetze(string $datei = '/proc/net/fib_trie'): array {
        $netze = [];
        $letzte = '';
        foreach (preg_split('/\R/', (string)@file_get_contents($datei)) ?: [] as $zeile) {
            if (preg_match('/\|--\s+(\d{1,3}(?:\.\d{1,3}){3})\s*$/', $zeile, $m)) { $letzte = $m[1]; continue; }
            if ($letzte !== '' && str_contains($zeile, '/32 host LOCAL') && self::netzGueltig(implode('.', array_slice(explode('.', $letzte), 0, 3)))) {
                $netze[implode('.', array_slice(explode('.', $letzte), 0, 3))] = true;
            }
        }
        return array_slice(array_keys($netze), 0, 4);
    }

    /** Nur private Netze (10.x.y, 172.16–31.x, 192.168.x) im Format „a.b.c“: es wird nie im Internet gesucht. */
    public static function netzGueltig(string $netz): bool {
        if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/D', $netz, $m) || max((int)$m[1], (int)$m[2], (int)$m[3]) > 255) return false;
        return (int)$m[1] === 10 || ((int)$m[1] === 172 && (int)$m[2] >= 16 && (int)$m[2] <= 31) || ((int)$m[1] === 192 && (int)$m[2] === 168);
    }

    /**
     * Sucht in einem /24-Netz (a.b.c.1–254) Geräte mit offenem Port 1002 (Ethernet-Modus von Delta und Alpha).
     * Alle Verbindungen laufen gleichzeitig, die Suche dauert rund zwei Sekunden. $pruefen ist für Tests ersetzbar.
     * @return list<string> gefundene Adressen
     */
    public static function suchen(string $netz, int $port = 1002, ?callable $pruefen = null, float $wartezeit = 2.0): array {
        if (!self::netzGueltig($netz)) throw new \InvalidArgumentException('Bitte ein privates Netz angeben, z. B. 192.168.1');
        $adressen = array_map(fn($i) => $netz . '.' . $i, range(1, 254));
        if ($pruefen) return array_values(array_filter($adressen, $pruefen));
        $offen = $sockets = [];
        foreach ($adressen as $a) {
            $s = @stream_socket_client("tcp://$a:$port", $nr, $text, 0, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
            if ($s) { stream_set_blocking($s, false); $sockets[$a] = $s; }
        }
        $ende = microtime(true) + $wartezeit;
        while ($sockets && microtime(true) < $ende) {
            $w = array_values($sockets); $r = $e = [];
            if (@stream_select($r, $w, $e, 0, 200000) < 1) continue;
            foreach ($w as $s) {
                $a = array_search($s, $sockets, true);
                // Ein abgelehnter Verbindungsaufbau meldet sich ebenfalls als „schreibbar“; echte Verbindungen haben einen Gegenstellennamen.
                if ($a !== false && @stream_socket_get_name($s, true) !== false) $offen[] = $a;
                if ($a !== false) { fclose($s); unset($sockets[$a]); }
            }
        }
        foreach ($sockets as $s) fclose($s);
        usort($offen, fn($x, $y) => ip2long($x) <=> ip2long($y));
        return $offen;
    }
}
