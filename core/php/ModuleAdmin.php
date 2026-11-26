<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Module installieren, aktualisieren, entfernen (Verwaltung → Module). Ausgeführt wird nur vom root-Dienst
 * kienzle-module (core/lib/kienzle/module.py); hier werden Status gelesen und Anfragen abgelegt. Die Prüfungen
 * hier sind ein Vorfilter, maßgeblich ist der Dienst.
 */
final class ModuleAdmin {
    public static string $state = '/var/lib/kienzle/module';
    public static string $unit = '/etc/systemd/system/kienzle-module.path';
    private const ID = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/D';
    private const VERSION = '/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})(-[0-9A-Za-z]+(\.[0-9A-Za-z]+)*)?$/D';

    public function __construct(private Database $db) {}

    public function status(): array {
        $s = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        $s = is_array($s) ? $s : [];
        $clean = function (mixed $m): ?array {
            if (!is_array($m) || !preg_match(self::ID, (string)($m['id'] ?? '')) || !preg_match(self::VERSION, (string)($m['version'] ?? ''))) return null;
            $u = is_array($m['update'] ?? null) && preg_match(self::VERSION, (string)($m['update']['version'] ?? '')) ? $m['update'] : null;
            return ['id' => (string)$m['id'], 'name' => mb_substr((string)($m['name'] ?? $m['id']), 0, 80), 'version' => (string)$m['version'],
                'description' => mb_substr((string)($m['description'] ?? ''), 0, 300), 'path' => is_string($m['path'] ?? null) ? $m['path'] : null,
                'source' => ($m['source'] ?? '') === 'github' ? 'github' : 'mitgeliefert', 'signed' => $m['signed'] ?? null,
                'requires' => array_values(array_filter((array)($m['requires'] ?? []), fn($r) => is_string($r) && preg_match(self::ID, $r))),
                'dienste' => array_values(array_filter(array_map(static fn($d) => is_array($d) && is_string($d['name'] ?? null) && preg_match('/^[A-Za-z0-9@_.-]{1,80}$/D', $d['name'])
                    ? ['name' => $d['name'], 'aktiv' => is_bool($d['aktiv'] ?? null) ? $d['aktiv'] : null] : null, (array)($m['dienste'] ?? [])))),
                'update' => $u ? ['version' => (string)$u['version'], 'source' => ($u['source'] ?? '') === 'github' ? 'github' : 'mitgeliefert', 'signed' => $u['signed'] ?? null] : null];
        };
        $installed = array_values(array_filter(array_map($clean, (array)($s['installed'] ?? []))));
        $available = array_values(array_filter(array_map($clean, (array)($s['available'] ?? []))));
        $running = is_array($s['running'] ?? null) ? ['action' => (string)($s['running']['action'] ?? ''), 'id' => (string)($s['running']['id'] ?? ''),
            'since' => (int)($s['running']['since'] ?? 0)] : null;
        $last = is_array($s['last'] ?? null) ? ['ok' => ($s['last']['ok'] ?? false) === true, 'message' => mb_substr((string)($s['last']['message'] ?? ''), 0, 400),
            'time' => (int)($s['last']['time'] ?? 0)] : null;
        $last = $last !== null ? View::frisch($last) : null;
        $log = @file_get_contents(self::$state . '/module.log');
        $lines = is_string($log) ? array_slice(preg_split('/\R/', rtrim($log)), -20) : [];
        return ['installed' => $installed, 'available' => $available, 'running' => $running, 'pending' => is_file($this->request()), 'last' => $last,
            'checked_at' => (int)($s['checked_at'] ?? 0), 'error' => isset($s['error']) ? mb_substr((string)$s['error'], 0, 300) : null,
            'service' => is_file(self::$unit), 'auto' => ($s['auto'] ?? true) !== false,
            'log' => array_map(static fn($l) => mb_substr((string)preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $l), 0, 300), $lines)];
    }

    /** Anzahl installierter Module mit neuerer Version (für das Menü). */
    public function count(): int { return count(array_filter($this->status()['installed'], fn($m) => $m['update'] !== null)); }

    public function auto(bool $on): void { $this->write(['action' => 'automatisch', 'an' => $on]); }

    public function refreshStates(): void { $this->write(['action' => 'status']); }

    public function startService(string $id, bool $restart): void {
        $m = $this->find('installed', $id);
        $this->write(['action' => $restart ? 'dienst-neustart' : 'dienst-start', 'id' => $m['id']]);
    }

    public function check(): void { $this->write(['action' => 'pruefen']); }

    public function install(string $id): void {
        $m = $this->find('available', $id);
        $this->write(['action' => 'installieren', 'id' => $m['id'], 'version' => $m['version']]);
    }

    public function update(string $id): void {
        $m = $this->find('installed', $id);
        if (!$m['update']) throw new Problem('Für dieses Modul gibt es keine neuere Version.', 409);
        $this->write(['action' => 'aktualisieren', 'id' => $m['id'], 'version' => $m['update']['version']]);
    }

    public function remove(string $id, bool $deleteData): void {
        $m = $this->find('installed', $id);
        $this->write(['action' => 'entfernen', 'id' => $m['id'], 'daten_loeschen' => $deleteData]);
    }

    private function find(string $list, string $id): array {
        foreach ($this->status()[$list] as $m) if ($m['id'] === $id) return $m;
        throw new Problem($list === 'installed' ? 'Modul ist nicht installiert.' : 'Modul ist nicht verfügbar. Bitte zuerst nach Modulen sehen.', 404);
    }

    private function request(): string { return $this->db->directory . '/anfragen/module.json'; }

    private function write(array $request): void {
        if (!is_file(self::$unit)) throw new Problem('Die Modulverwaltung ist nicht eingerichtet. Bitte den Installer ausführen.', 503);
        $st = $this->status();
        if ($st['pending'] || $st['running']) throw new Problem('Es läuft gerade ein Vorgang der Modulverwaltung. Bitte warten.', 409);
        $dir = dirname($this->request());
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        $tmp = $dir . '/.module.' . bin2hex(random_bytes(4));
        $fh = fopen($tmp, 'x');
        if ($fh) { chmod($tmp, 0600); fclose($fh); }
        if (!$fh || file_put_contents($tmp, json_encode($request, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $this->request())) {
            @unlink($tmp);
            throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        }
    }
}
