<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Updates im Webinterface. Das Webinterface hat kein Netz; nach Updates sieht und installiert der root-Dienst
 * kienzle-update (core/lib/kienzle/update.py). Hier wird nur dessen Status gelesen und eine Anfragedatei abgelegt.
 * Grundlage: kienzle-suite-beta (src/Updater.php: Kanal, Statusanzeige mit bereinigtem Protokoll, Anfrage).
 */
final class Updates {
    public static string $state = '/var/lib/kienzle/update';
    public static string $unit = '/etc/systemd/system/kienzle-update.path';
    private const VERSION = '/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?$/D';

    public function __construct(private Database $db, private Config $config) {}

    public function status(): array {
        $s = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        $s = is_array($s) ? $s : [];
        $available = [];
        foreach ((array)($s['available']['core'] ?? []) as $a) {
            if (!is_array($a) || !preg_match(self::VERSION, (string)($a['version'] ?? ''))) continue;
            $available[] = ['version' => (string)$a['version'], 'prerelease' => ($a['prerelease'] ?? false) === true,
                'notes' => mb_substr((string)($a['notes'] ?? ''), 0, 4000), 'published' => mb_substr((string)($a['published'] ?? ''), 0, 20),
                'signed' => ($a['signed'] ?? true) !== false];
        }
        $local = array_values(array_filter((array)($s['local'] ?? []), fn($v) => is_string($v) && preg_match(self::VERSION, $v)));
        $state = (string)($s['state'] ?? 'idle');
        if (is_file($this->request()) && $state !== 'running') $state = 'requested';
        $log = @file_get_contents(self::$state . '/update.log');
        $lines = is_string($log) ? array_slice(preg_split('/\R/', rtrim($log)), -25) : [];
        return ['state' => $state, 'step' => (string)($s['step'] ?? ''), 'message' => mb_substr((string)($s['message'] ?? ''), 0, 500),
            'installed' => $this->config->version(), 'channel' => in_array($s['channel'] ?? '', ['stabil', 'beta'], true) ? $s['channel'] : 'stabil',
            'unsigned' => ($s['unsigned'] ?? false) === true, 'checked_at' => (int)($s['checked_at'] ?? 0), 'error' => isset($s['error']) ? mb_substr((string)$s['error'], 0, 300) : null,
            'available' => $available, 'local' => $local, 'service' => is_file(self::$unit),
            'log' => array_map(static fn($l) => mb_substr((string)preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $l), 0, 300), $lines)];
    }

    /** Anzahl neuerer Versionen (für das Menü). */
    public function count(): int { return count($this->status()['available']); }

    public function check(): void { $this->write(['action' => 'pruefen']); }

    /** Kanal wählen; $unsigned erlaubt unsignierte Vorabversionen (nur Beta, ausdrücklich). */
    public function setChannel(string $channel, bool $unsigned = false): void {
        if (!in_array($channel, ['stabil', 'beta'], true)) throw new Problem('Unbekannter Kanal.', 422);
        if ($unsigned && $channel !== 'beta') throw new Problem('Unsignierte Versionen gibt es nur im Beta-Kanal.', 422);
        $this->write(['action' => 'kanal', 'kanal' => $channel, 'unsigniert' => $unsigned]);
    }

    public function install(string $version): void {
        $st = $this->status();
        if (!in_array($version, array_column($st['available'], 'version'), true)) throw new Problem('Diese Version ist nicht verfügbar. Bitte zuerst nach Updates sehen.', 409);
        $this->write(['action' => 'installieren', 'version' => $version]);
    }

    /** Von Hand auf eine hier aufbewahrte ältere Version zurück (mit Datenstand von damals). */
    public function rollback(string $version): void {
        $st = $this->status();
        if (!in_array($version, $st['local'], true) || $version === $st['installed']) throw new Problem('Diese Version ist hier nicht aufbewahrt.', 409);
        $this->write(['action' => 'zurueck', 'version' => $version]);
    }

    private function request(): string { return $this->db->directory . '/anfragen/update.json'; }

    private function write(array $request): void {
        if (!is_file(self::$unit)) throw new Problem('Der Update-Dienst ist nicht eingerichtet. Bitte den Installer ausführen.', 503);
        $st = $this->status();
        if (in_array($st['state'], ['running', 'requested'], true)) throw new Problem('Der Updater ist gerade beschäftigt.', 409);
        $dir = dirname($this->request());
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        $tmp = $dir . '/.update.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode($request, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $this->request())) {
            @unlink($tmp);
            throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        }
    }
}
