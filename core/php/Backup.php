<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Datensicherung im Webinterface. Gesichert, geprüft und wiederhergestellt wird vom root-Dienst kienzle-sicherung
 * (core/lib/kienzle/sicherung.py). Das Webinterface liest den Status und – nur bis zur Bestätigung „gedruckt“ – das
 * Wiederherstellungsblatt; das Sicherungspasswort selbst kann es nicht lesen.
 */
final class Backup {
    public static string $state = '/var/lib/kienzle/sicherung';
    public static string $unit = '/etc/systemd/system/kienzle-sicherung.path';

    public function __construct(private Database $db, private Modules $modules) {}

    public function status(): array {
        $s = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        $s = is_array($s) ? $s : [];
        $parts = ['core' => 'Basis'];
        foreach ($this->modules->all() as $id => $m) if (!empty($m['backup'])) $parts[$id] = $this->modules->name($id);
        return ['eingerichtet' => (int)($s['eingerichtet'] ?? 0) > 0, 'warnungen' => array_values(array_filter((array)($s['warnungen'] ?? ['Die Datensicherung ist noch nicht eingerichtet.']), 'is_string')),
            'letzte_sicherung' => is_array($s['letzte_sicherung'] ?? null) ? $s['letzte_sicherung'] : null, 'letzte_pruefung' => is_array($s['letzte_pruefung'] ?? null) ? $s['letzte_pruefung'] : null,
            'letzte_probe' => is_array($s['letzte_probe'] ?? null) ? $s['letzte_probe'] : null, 'letztes_aufraeumen' => is_array($s['letztes_aufraeumen'] ?? null) ? $s['letztes_aufraeumen'] : null,
            'letzte' => is_array($s['letzte'] ?? null) ? $s['letzte'] : null, 'laeuft' => (string)($s['laeuft'] ?? ''),
            'schnappschuesse' => array_values(array_filter((array)($s['schnappschuesse'] ?? []), fn($x) => is_array($x) && preg_match('/^[0-9a-f]{8,16}$/', (string)($x['id'] ?? '')))),
            'blatt' => is_file(self::$state . '/blatt.html'), 'teile' => $parts, 'pending' => is_file($this->request()), 'service' => is_file(self::$unit)];
    }

    public function count(): int { return count($this->status()['warnungen']); }

    /** Wiederherstellungsblatt (enthält das Passwort) – nur solange es nicht als gedruckt bestätigt ist. */
    public function sheet(): string {
        $html = @file_get_contents(self::$state . '/blatt.html');
        if (!is_string($html) || !str_starts_with($html, '<!doctype html>')) throw new Problem('Das Wiederherstellungsblatt ist nicht mehr vorhanden (schon als gedruckt bestätigt).', 404);
        return $html;
    }

    public function setup(array $in, string $practice): void {
        $art = (string)($in['art'] ?? '');
        $ziel = match ($art) {
            'ziel' => ['art' => 'ziel', 'id' => (string)($in['id'] ?? ''), 'ordner' => trim((string)($in['ordner'] ?? '')) ?: 'kienzle-sicherung'],
            'rest' => ['art' => 'rest', 'url' => trim((string)($in['url'] ?? '')), 'benutzer' => trim((string)($in['benutzer'] ?? '')), 'passwort' => (string)($in['passwort'] ?? '')],
            'lokal' => ['art' => 'lokal', 'pfad' => trim((string)($in['pfad'] ?? ''))],
            default => throw new Problem('Bitte ein Ziel wählen.', 422),
        };
        $this->write(['action' => 'einrichten', 'ziel' => $ziel, 'praxis' => $practice]);
    }

    public function action(string $action, array $extra = []): void {
        if (!in_array($action, ['jetzt', 'pruefen', 'probe', 'aufraeumen', 'blatt-gedruckt', 'nas-schnappschuesse', 'aufbewahren', 'wiederherstellen', 'schnappschuesse'], true)) {
            throw new Problem('Unbekannte Aktion.', 422);
        }
        if ($action === 'wiederherstellen') {
            if (!isset($this->status()['teile'][(string)($extra['teil'] ?? '')])) throw new Problem('Unbekannter Teil.', 422);
            if (!preg_match('/^[0-9a-f]{8,16}$/D', (string)($extra['snapshot'] ?? ''))) throw new Problem('Unbekannte Sicherung.', 422);
        }
        $this->write(['action' => $action] + $extra);
    }

    private function request(): string { return $this->db->directory . '/anfragen/sicherung.json'; }

    private function write(array $request): void {
        if (!is_file(self::$unit)) throw new Problem('Der Sicherungsdienst ist nicht eingerichtet. Bitte den Installer ausführen.', 503);
        if (is_file($this->request()) || $this->status()['laeuft'] !== '') throw new Problem('Es läuft gerade ein Vorgang der Sicherung. Bitte warten.', 409);
        $dir = dirname($this->request());
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        $tmp = $dir . '/.sicherung.' . bin2hex(random_bytes(4));
        $fh = fopen($tmp, 'x');
        if (!$fh) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        chmod($tmp, 0600);
        fwrite($fh, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        fclose($fh);
        if (!rename($tmp, $this->request())) { @unlink($tmp); throw new Problem('Anfrage konnte nicht gespeichert werden.', 500); }
    }
}
