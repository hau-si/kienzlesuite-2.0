<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Zentraler Starter-Schlüssel der Arbeitsplätze (T2med → Starterprogramm → /starter/<modul>) und das Einrichtungspaket.
 * Ein Schlüssel für alle Module. Er liegt verschlüsselt in der Datenbank (Secrets), das Paket enthält ihn im Klartext
 * und ist nur nach Admin-Anmeldung abrufbar.
 */
final class Starter {
    private const KEY = 'starter:schluessel';
    public const CERTIFICATE = '/etc/kienzle/ca/server.crt';

    public const SYSTEMS = [
        'windows' => ['label' => 'Windows', 'scripts' => ['install-windows.cmd', 'install-windows.ps1', 'install-drucker.ps1'], 'assets' => ['kienzle-starter-windows-amd64.exe', 'kienzle-starter-windows-arm64.exe']],
        'macos' => ['label' => 'macOS', 'scripts' => ['install-macos.command'], 'assets' => ['kienzle-starter-darwin-amd64', 'kienzle-starter-darwin-arm64']],
        'linux' => ['label' => 'Linux', 'scripts' => ['install-linux.sh'], 'assets' => ['kienzle-starter-linux-amd64', 'kienzle-starter-linux-arm64', 'kienzle-starter-linux-arm']],
    ];

    public function __construct(private Database $db, private Secrets $secrets, private Config $config, private string $root, private string $certificate = self::CERTIFICATE) {}

    /** Schlüssel lesen; beim ersten Aufruf wird er erzeugt. */
    public function key(): string {
        $stored = $this->db->setting(self::KEY);
        if ($stored !== null) return $this->secrets->decrypt($stored, self::KEY);
        return $this->renew();
    }

    public function renew(): string {
        $key = bin2hex(random_bytes(32));
        $this->db->store(self::KEY, $this->secrets->encrypt($key, self::KEY));
        return $key;
    }

    public function exists(): bool { return $this->db->setting(self::KEY) !== null; }

    /** Prüft „Authorization: Bearer <Schlüssel>“ ohne Zeitunterschied. Ohne gespeicherten Schlüssel ist jeder Aufruf abgelehnt. */
    public function verify(string $header): bool {
        if (!$this->exists() || !str_starts_with($header, 'Bearer ')) return false;
        return hash_equals($this->key(), substr($header, 7));
    }

    /** SHA-256 des Serverzertifikats (DER) wie es der Starter prüft; leer, wenn nicht lesbar. */
    public function certificateHash(): string {
        $pem = @file_get_contents($this->certificate);
        if (!$pem || !preg_match('/-----BEGIN CERTIFICATE-----\s*(.+?)\s*-----END CERTIFICATE-----/s', $pem, $m)) return '';
        $der = base64_decode((string)preg_replace('/\s+/', '', $m[1]), true);
        return $der === false ? '' : hash('sha256', $der);
    }

    /**
     * @param array<string,string> $kontexte Schema → Pfad auf dem Server (/starter/<modul>)
     * @param list<array{name:string,url:string,papier?:string}> $drucker virtuelle Drucker der Module (Einrichtung am Arbeitsplatz)
     */
    public function files(string $system, array $kontexte, string $serverUrl, array $drucker = []): array {
        $spec = self::SYSTEMS[$system] ?? throw new Problem('Unbekanntes Betriebssystem.', 404);
        if (!$kontexte) throw new Problem('Kein installiertes Modul hat einen Startkontext.', 404);
        $pem = @file_get_contents($this->certificate);
        $ca = (string)@file_get_contents(dirname($this->certificate) . '/ca.crt');
        if (!str_contains($ca, 'BEGIN CERTIFICATE') || str_contains($ca, 'PRIVATE KEY')) throw new Problem('Zertifizierungsstelle der Suite nicht lesbar. Bitte den Installer der Basis ausführen.', 503);
        if (!$pem || str_contains($pem, 'PRIVATE KEY') || $this->certificateHash() === '') throw new Problem('Serverzertifikat der Suite nicht lesbar. Bitte den Installer der Basis ausführen.', 503);
        ksort($kontexte);
        $client = ['server_url' => rtrim($serverUrl, '/'), 'launcher_key' => $this->key(), 'certificate_sha256' => $this->certificateHash(), 'kontexte' => $kontexte];
        $files = [
            'client.json' => ['data' => json_encode($client, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 'mode' => 0600],
            'schemas.txt' => ['data' => implode("\n", array_keys($kontexte)) . "\n", 'mode' => 0644],
            'server.crt' => ['data' => $pem, 'mode' => 0644],
            // Zertifizierungsstelle der Suite: Prüfung der Verbindung (Agent) und vertrauenswürdig für die Browser des PCs.
            'ca.crt' => ['data' => (string)@file_get_contents(dirname($this->certificate) . '/ca.crt'), 'mode' => 0644],
            'LIESMICH.txt' => ['data' => self::readme($system, $client['server_url'], array_keys($kontexte), (bool)$drucker), 'mode' => 0644],
        ];
        if ($drucker) {
            $lines = [];
            foreach ($drucker as $d) $lines[] = preg_replace('/[|\r\n]/', '', $d['name']) . '|' . preg_replace('/[|\r\n]/', '', $d['url']) . '|' . ($d['papier'] ?? 'A4');
            $files['drucker.txt'] = ['data' => implode("\n", $lines) . "\n", 'mode' => 0644];
        }
        foreach ($spec['scripts'] as $script) {
            $path = $this->root . '/client/' . $script;
            if (!is_file($path)) throw new Problem('Installationsskript fehlt auf dem Server: ' . $script, 503);
            $files[$script] = ['path' => $path, 'mode' => 0755];
        }
        $dist = $this->root . '/client-dist';
        $sums = is_file($dist . '/SHA256SUMS') ? (string)file_get_contents($dist . '/SHA256SUMS') : '';
        $lines = [];
        foreach ($spec['assets'] as $asset) {
            if (is_file($dist . '/' . $asset) && preg_match('/^([0-9a-f]{64})\s+\*?' . preg_quote($asset, '/') . '$/m', $sums, $hit)) {
                $files[$asset] = ['path' => $dist . '/' . $asset, 'mode' => 0755];
                $lines[] = $hit[1] . '  ' . $asset;
            }
        }
        if ($lines) $files['SHA256SUMS'] = ['data' => implode("\n", $lines) . "\n", 'mode' => 0644];
        return $files;
    }

    /** Ob die Starterprogramme für das System auf dem Server liegen (sonst enthält das Paket nur Skripte). */
    public function hasPrograms(string $system): bool {
        $dist = $this->root . '/client-dist';
        if (!is_file($dist . '/SHA256SUMS')) return false;
        foreach (self::SYSTEMS[$system]['assets'] ?? [] as $a) if (is_file($dist . '/' . $a)) return true;
        return false;
    }

    public static function filename(string $system): string { return 'kienzle-starter-' . $system . '.zip'; }

    /** ZIP ohne Kompression schreiben; liefert die Gesamtgröße. */
    public static function zip(array $files, callable $write): int {
        $offset = 0; $central = ''; $time = getdate();
        $dosTime = ($time['hours'] << 11) | ($time['minutes'] << 5) | intdiv($time['seconds'], 2);
        $dosDate = (($time['year'] - 1980) << 9) | ($time['mon'] << 5) | $time['mday'];
        $emit = function (string $bytes) use ($write, &$offset) { $write($bytes); $offset += strlen($bytes); };
        foreach ($files as $name => $file) {
            if (isset($file['data'])) { $size = strlen($file['data']); $crc = crc32($file['data']); }
            else { $size = (int)filesize($file['path']); $crc = (int)hexdec(hash_file('crc32b', $file['path'])); }
            $start = $offset;
            $emit(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0) . $name);
            if (isset($file['data'])) $emit($file['data']);
            else {
                $handle = fopen($file['path'], 'rb');
                if (!$handle) throw new Problem('Datei nicht lesbar: ' . $name, 500);
                while (!feof($handle)) $emit((string)fread($handle, 1 << 20));
                fclose($handle);
            }
            $attributes = ((0100000 | ($file['mode'] ?? 0644)) << 16);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, (3 << 8) | 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size,
                strlen($name), 0, 0, 0, 0, $attributes, $start) . $name;
        }
        $centralStart = $offset;
        $emit($central);
        $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $centralStart, 0));
        return $offset;
    }

    private static function readme(string $system, string $url, array $schemas, bool $drucker = false): string {
        $nl = $system === 'windows' ? "\r\n" : "\n";
        $steps = match ($system) {
            'windows' => ["Diese ZIP-Datei mit Rechtsklick → „Alle extrahieren …“ entpacken (nicht direkt aus der ZIP starten).", "Im entpackten Ordner „install-windows.cmd“ doppelklicken. Meldet Windows „Der Computer wurde durch Windows geschützt“: „Weitere Informationen“ → „Trotzdem ausführen“.", "Die Frage nach Administratorrechten mit „Ja“ bestätigen (das Fenster kann hinter anderen liegen).", "„Arbeitsplatz eingerichtet“ erscheint. Der Ordner kann danach gelöscht werden; Starter, Zertifikat und Drucker hält der Kienzle-Agent ab jetzt selbst aktuell."],
            'macos' => ["Die ZIP-Datei doppelklicken (entpackt in „Downloads“).", "Im entpackten Ordner „install-macos.command“ mit Rechtsklick → „Öffnen“ starten und „Öffnen“ bestätigen.", "Für das Zertifikat das Mac-Anmeldepasswort eingeben.", "„Kienzle-Starter eingerichtet“ erscheint. Der Ordner kann danach gelöscht werden."],
            default => ["Die ZIP-Datei entpacken (unzip kienzle-starter-linux.zip -d kienzle-starter).", "Im Terminal im entpackten Ordner:  bash install-linux.sh", "Das Zertifikat server.crt im Browser als vertrauenswürdig importieren."],
        };
        $text = 'Kienzle-Starter – Einrichtung des Arbeitsplatzes' . $nl . $nl;
        foreach ($steps as $i => $s) $text .= ($i + 1) . '. ' . $s . $nl;
        return $text . $nl . 'In T2med als Eintrag „Programm/URL“ je Modul: ' . implode(', ', array_map(fn($s) => $s . '://?kontextId=${kontextId}&fhirBasisUrl=${fhirBasisUrl}&oAuthToken=${oAuthToken}', $schemas)) . $nl
            . 'Server: ' . $url . $nl . 'Diese Dateien enthalten den Starter-Schlüssel der Praxis. Nicht weitergeben, nach der Einrichtung löschen.' . $nl;
    }
}
