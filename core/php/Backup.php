<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Datensicherung im Webinterface. Gesichert, geprüft und wiederhergestellt wird vom root-Dienst kienzle-sicherung
 * (core/lib/kienzle/sicherung.py). Das Webinterface liest den Status und – nur bis zur Bestätigung „gedruckt“ – das
 * Schlüsselblatt; das Sicherungspasswort selbst kann es nicht lesen.
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
            'letzte' => is_array($s['letzte'] ?? null) ? View::frisch($s['letzte']) : null, 'laeuft' => (string)($s['laeuft'] ?? ''),
            'schnappschuesse' => array_values(array_filter((array)($s['schnappschuesse'] ?? []), fn($x) => is_array($x) && preg_match('/^[0-9a-f]{8,16}$/', (string)($x['id'] ?? '')))),
            'blatt' => is_file(self::$state . '/blatt.html'), 'zeitplan' => self::schedule($s['zeitplan'] ?? null),
            'mail_an' => array_values(array_filter((array)($s['mail_an'] ?? []), fn($a) => is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL))),
            'letzte_mail' => is_array($s['letzte_mail'] ?? null) ? $s['letzte_mail'] : null, 'export' => $this->exportInfo($s['export'] ?? null), 'teile' => $parts, 'pending' => is_file($this->request()), 'service' => is_file(self::$unit)];
    }

    private static function schedule(mixed $z): array {
        $z = is_array($z) ? $z : [];
        return ['haeufigkeit' => in_array($z['haeufigkeit'] ?? '', ['taeglich', 'woechentlich', 'monatlich'], true) ? $z['haeufigkeit'] : 'taeglich',
            'uhrzeit' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', (string)($z['uhrzeit'] ?? '')) ? (string)$z['uhrzeit'] : '02:30',
            'wochentag' => max(0, min(6, (int)($z['wochentag'] ?? 6))), 'tag' => max(1, min(28, (int)($z['tag'] ?? 1)))];
    }

    public function setSchedule(string $freq, string $time, string $weekday, string $day): void {
        if (!in_array($freq, ['taeglich', 'woechentlich', 'monatlich'], true)) throw new Problem('Bitte täglich, wöchentlich oder monatlich wählen.', 422);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $time)) throw new Problem('Uhrzeit bitte als HH:MM.', 422);
        if (!ctype_digit($weekday) || (int)$weekday > 6 || !ctype_digit($day) || (int)$day < 1 || (int)$day > 28) throw new Problem('Wochentag oder Tag ungültig.', 422);
        $this->write(['action' => 'zeitplan', 'zeitplan' => ['haeufigkeit' => $freq, 'uhrzeit' => $time, 'wochentag' => (int)$weekday, 'tag' => (int)$day]]);
    }

    public function setRecipients(string $list): void {
        $addrs = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $list) ?: [])));
        if (count($addrs) > 5) throw new Problem('Höchstens 5 Empfänger.', 422);
        foreach ($addrs as $a) if (!filter_var($a, FILTER_VALIDATE_EMAIL)) throw new Problem('Keine gültige E-Mail-Adresse: ' . mb_substr($a, 0, 60), 422);
        $this->write(['action' => 'mail-an', 'an' => $addrs]);
    }

    private function exportInfo(mixed $e): ?array {
        if (!is_array($e) || !preg_match('/^kienzle-einstellungen-\d{8}-\d{6}\.kse$/D', (string)($e['datei'] ?? '')) || !is_file(self::$state . '/export/' . $e['datei'])) return null;
        return ['datei' => (string)$e['datei'], 'zeit' => (int)($e['zeit'] ?? 0), 'groesse' => (int)($e['groesse'] ?? 0), 'dateien' => (int)($e['dateien'] ?? 0),
            'mit_sicherungspasswort' => ($e['mit_sicherungspasswort'] ?? false) === true];
    }

    /** Export der Einstellungen anstoßen. Das Passwort geht nur in die Anfragedatei (0600) an den root-Dienst. */
    public function export(string $password, string $repeat, bool $withBackupPassword, bool $withUsers = true): void {
        if (mb_strlen($password) < 12) throw new Problem('Das Passwort für den Export braucht mindestens 12 Zeichen.', 422);
        if (mb_strlen($password) > 200 || preg_match('/[\x00-\x1f]/', $password)) throw new Problem('Passwort ungültig.', 422);
        if (!hash_equals($password, $repeat)) throw new Problem('Die beiden Passwörter stimmen nicht überein.', 422);
        $this->write(['action' => 'export', 'passwort' => $password, 'mit_sicherungspasswort' => $withBackupPassword, 'mit_benutzern' => $withUsers]);
    }

    /** Pfad der fertigen Export-Datei (zum Herunterladen). */
    public function exportFile(): array {
        $e = $this->status()['export'];
        if (!$e) throw new Problem('Es liegt kein Export bereit.', 404);
        return [self::$state . '/export/' . $e['datei'], $e['datei']];
    }

    public function count(): int { return count($this->status()['warnungen']); }

    /** Schlüsselblatt (enthält das Passwort) – nur solange es nicht als gedruckt bestätigt ist. */
    public function sheet(): string {
        $html = @file_get_contents(self::$state . '/blatt.html');
        if (!is_string($html) || !str_starts_with($html, '<!doctype html>')) throw new Problem('Das Schlüsselblatt ist nicht mehr vorhanden (schon als gedruckt bestätigt).', 404);
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
        if (!in_array($action, ['jetzt', 'pruefen', 'probe', 'aufraeumen', 'blatt-gedruckt', 'nas-schnappschuesse', 'aufbewahren', 'wiederherstellen', 'schnappschuesse', 'export-loeschen', 'zeitplan', 'mail-an', 'blatt-neu'], true)) {
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
