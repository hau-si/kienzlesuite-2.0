<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Drucker im Webinterface. Eingerichtet wird nur vom root-Dienst kienzle-drucker (core/lib/kienzle/drucker.py);
 * hier werden Status gelesen und Anfragen abgelegt. Die Prüfungen hier sind ein Vorfilter, maßgeblich ist der Dienst.
 */
final class Printers {
    public static string $state = '/var/lib/kienzle/drucker';
    public static string $unit = '/etc/systemd/system/kienzle-drucker.path';
    public const KINDS = ['tm-m10' => 'Bondrucker Epson TM-m10 (58 mm)', 'tm-m30iii' => 'Bondrucker Epson TM-m30III (80 mm)',
        'bon' => 'Bondrucker (ESC/POS, RAW über Port 9100)', 'office' => 'A4-Drucker im Netzwerk (IPP / AirPrint)'];
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D';

    public function __construct(private Database $db) {}

    public function status(): array {
        $s = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        $s = is_array($s) ? $s : [];
        $queues = [];
        foreach ((array)($s['warteschlangen'] ?? []) as $q) {
            if (!is_array($q) || !preg_match(self::NAME, (string)($q['name'] ?? ''))) continue;
            $queues[] = ['name' => (string)$q['name'], 'address' => mb_substr((string)($q['address'] ?? ''), 0, 100), 'state' => mb_substr((string)($q['state'] ?? ''), 0, 20),
                'description' => mb_substr((string)($q['description'] ?? ''), 0, 130), 'managed' => ($q['managed'] ?? false) === true,
                'modul' => (string)($q['modul'] ?? ''), 'share' => array_values(array_intersect(['samba', 'ipp'], (array)($q['share'] ?? [])))];
        }
        $found = [];
        foreach ((array)($s['gefunden'] ?? []) as $f) {
            if (!is_array($f) || !preg_match('#^(socket|ipp|ipps|dnssd|usb|lpd)://[A-Za-z0-9%._~:/?&=@+\-]{1,300}$#D', (string)($f['uri'] ?? ''))) continue;
            $found[] = ['uri' => (string)$f['uri'], 'name' => mb_substr((string)($f['name'] ?? ''), 0, 80), 'kind' => isset(self::KINDS[$f['kind'] ?? '']) ? $f['kind'] : null,
                'connection' => ($f['connection'] ?? '') === 'USB' ? 'USB' : 'Netzwerk'];
        }
        $jobs = [];
        foreach ((array)($s['auftraege'] ?? []) as $j) {
            if (!is_array($j) || !preg_match('/^[A-Za-z0-9_-]{1,40}-\d{1,9}$/D', (string)($j['id'] ?? ''))) continue;
            $jobs[] = ['id' => (string)$j['id'], 'drucker' => (string)($j['drucker'] ?? ''), 'benutzer' => mb_substr((string)($j['benutzer'] ?? ''), 0, 40),
                'groesse' => (int)($j['groesse'] ?? 0), 'zeit' => mb_substr((string)($j['zeit'] ?? ''), 0, 40)];
        }
        $n = is_array($s['netz'] ?? null) ? $s['netz'] : [];
        $net = ['arbeitsgruppe' => mb_substr((string)($n['arbeitsgruppe'] ?? 'WORKGROUP'), 0, 15)];
        return ['queues' => $queues, 'found' => $found, 'jobs' => $jobs, 'net' => $net, 'searched' => (int)($s['gesucht'] ?? 0), 'error' => mb_substr((string)($s['fehler'] ?? ''), 0, 300),
            'last' => is_array($s['letzte'] ?? null) ? View::frisch($s['letzte']) : null, 'pending' => is_file($this->request()), 'service' => is_file(self::$unit)];
    }

    public function search(): void { $this->write(['action' => 'suchen']); }

    public function add(string $kind, string $name, string $address, array $share): void {
        if (!isset(self::KINDS[$kind])) throw new Problem('Bitte die Art des Druckers wählen.', 422);
        $name = trim($name);
        if ($name !== '' && !preg_match(self::NAME, $name)) throw new Problem('Name: nur Buchstaben, Ziffern, - und _ (ohne Leerzeichen, höchstens 40).', 422);
        $address = trim($address);
        if ($address === '' || strlen($address) > 300 || preg_match('/[\s\x00-\x1f]/', $address)) throw new Problem('Bitte die Adresse des Druckers eintragen.', 422);
        $this->write(['action' => 'anlegen', 'art' => $kind, 'name' => $name, 'adresse' => $address, 'freigabe' => self::shares($share)]);
    }

    public function remove(string $name): void { $this->write(['action' => 'entfernen', 'name' => $this->known($name)]); }
    public function testPage(string $name): void { $this->write(['action' => 'testseite', 'name' => $this->known($name)]); }
    public function share(string $name, array $share): void {
        $virtual = array_filter($this->status()['queues'], fn($q) => $q['name'] === $name && $q['modul'] !== '') !== [];
        // Virtuelle Drucker der Module sind immer per IPP freigegeben; Samba (alte Windows-Freigabe) ist optional.
        $this->write(['action' => 'freigabe', 'name' => $this->known($name), 'freigabe' => self::shares($virtual ? [...$share, 'ipp'] : $share)]);
    }
    public function sync(): void { $this->write(['action' => 'abgleichen']); }

    public function cancelJob(string $id): void {
        if (!in_array($id, array_column($this->status()['jobs'], 'id'), true)) throw new Problem('Auftrag nicht gefunden (schon gedruckt?).', 404);
        $this->write(['action' => 'abbrechen', 'auftrag' => $id]);
    }

    public function cancelAll(string $name): void { $this->write(['action' => 'alle-abbrechen', 'name' => $this->known($name)]); }

    public function setWorkgroup(string $name): void {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,14}$/D', $name)) throw new Problem('Arbeitsgruppe: 1–15 Buchstaben, Ziffern oder Bindestrich.', 422);
        $this->write(['action' => 'arbeitsgruppe', 'arbeitsgruppe' => $name]);
    }

    private static function shares(array $share): array { return array_values(array_intersect(['samba', 'ipp'], $share)); }

    private function known(string $name): string {
        if (!in_array($name, array_column($this->status()['queues'], 'name'), true)) throw new Problem('Drucker nicht gefunden.', 404);
        return $name;
    }

    private function request(): string { return $this->db->directory . '/anfragen/drucker.json'; }

    private function write(array $request): void {
        if (!is_file(self::$unit)) throw new Problem('Der Druckerdienst ist nicht eingerichtet. Bitte den Installer ausführen.', 503);
        if (is_file($this->request())) throw new Problem('Es läuft gerade ein Vorgang der Druckerverwaltung. Bitte warten.', 409);
        $dir = dirname($this->request());
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        $tmp = $dir . '/.drucker.' . bin2hex(random_bytes(4));
        $fh = fopen($tmp, 'x');
        if ($fh) { chmod($tmp, 0600); fclose($fh); }
        if (!$fh || file_put_contents($tmp, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $this->request())) {
            @unlink($tmp);
            throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        }
    }
}
