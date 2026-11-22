<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Firewall im Webinterface. Das Webinterface wählt nur Netze und Optionen; die Regeln erzeugt und lädt der root-Dienst
 * kienzle-firewall (core/lib/kienzle/firewall.py) aus festen Vorlagen. Ports der Module kommen aus deren module.toml
 * (Modulverzeichnis); freigeschaltet werden sie einzeln mit ihrer Kennung „<modul>/<n>“.
 *
 * Schutz vor Aussperren: Das anfragende Gerät muss in einem freigegebenen Netz liegen, und ohne Bestätigung binnen
 * zwei Minuten stellt der Dienst die vorherigen Regeln wieder her. Grundlage: kienzle-suite-beta (src/Firewall.php).
 */
final class Firewall {
    public static string $state = '/var/lib/kienzle/firewall';
    public static string $unit = '/etc/systemd/system/kienzle-firewall.path';
    private const KEY = 'firewall_config';

    public function __construct(private Database $db, private Network $network, private Modules $modules) {}

    private function dir(): string { return $this->db->directory . '/anfragen'; }

    /** Freischaltbare Ports der Module: „<modul>/<n>“ → Angaben aus module.toml. */
    public function catalog(): array {
        $out = [];
        foreach ($this->modules->all() as $id => $m) {
            foreach (array_values($m['firewall'] ?? []) as $i => $p) {
                $out[$id . '/' . ($i + 1)] = ['module' => $this->modules->name($id), 'name' => (string)$p['name'], 'proto' => (string)$p['proto'],
                    'ports' => $p['from'] === $p['to'] ? (string)$p['from'] : $p['from'] . '-' . $p['to'], 'scope' => (string)$p['scope']];
            }
        }
        return $out;
    }

    public function overview(string $client): array {
        $client = Network::clean($client);
        $net = $this->network->detect();
        $config = $this->config();
        $covering = null;
        foreach ($net['interfaces'] as $i) foreach ($i['addresses'] as $a) if (Network::contains($a['network'], $client)) $covering = $a['network'];
        if ($config === null) {
            // Vorschlag: das Netz, aus dem gerade zugegriffen wird; sonst alle erkannten privaten IPv4-Netze. SSH bleibt offen.
            $suggest = [];
            if ($covering && Network::isPrivate($covering)) $suggest = [$covering];
            else foreach ($net['interfaces'] as $i) foreach ($i['addresses'] as $a) if ($a['private'] && $a['family'] === 4 && !in_array($a['network'], $suggest, true)) $suggest[] = $a['network'];
            $config = ['cidrs' => $suggest, 'ssh' => true, 'ipp' => false, 'samba' => false, 'strict' => true, 'modules' => [], 'custom' => []];
        }
        return ['network' => $net, 'client' => ['ip' => $client, 'network' => $covering, 'loopback' => Network::isLoopback($client)],
            'config' => $config, 'catalog' => $this->catalog(), 'saved' => $this->config() !== null, 'status' => $this->status(), 'service' => is_file(self::$unit)];
    }

    /** Eingabe prüfen und bereinigen; wirft Problem, wenn das Gerät des Admins ausgesperrt würde. */
    public function validate(array $input, string $client): array {
        $client = Network::clean($client);
        foreach (['networks', 'modules'] as $k) if (isset($input[$k]) && !is_array($input[$k])) throw new Problem('Ungültige Angabe.', 422);
        $list = [];
        foreach ((array)($input['networks'] ?? []) as $n) if (is_string($n)) $list[] = $n;
        foreach (preg_split('/[\s,;]+/', (string)($input['extra'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $n) $list[] = $n;
        $cidrs = [];
        foreach ($list as $raw) {
            $net = Network::normalize((string)$raw);
            if ($net === null) throw new Problem('Das Netz „' . mb_substr((string)preg_replace('/[^0-9a-fA-F:.\/]/', '', (string)$raw), 0, 50) . '“ ist ungültig. Beispiel: 192.168.1.0/24', 422);
            if (!Network::isPrivate($net)) throw new Problem('Nur private Netze sind erlaubt (10.x.x.x, 172.16–31.x.x, 192.168.x.x, 100.64–127.x.x, fc00::/7). „' . $net . '“ gehört nicht dazu.', 422);
            if (!in_array($net, $cidrs, true)) $cidrs[] = $net;
        }
        if (!$cidrs) throw new Problem('Bitte mindestens ein Netz freigeben, sonst käme niemand mehr auf die Seite.', 422);
        if (count($cidrs) > 20) throw new Problem('Höchstens 20 Netze.', 422);
        if (!Network::isLoopback($client)) {
            $inside = false;
            foreach ($cidrs as $c) if (Network::contains($c, $client)) $inside = true;
            if (!$inside) throw new Problem('Dieses Gerät (' . $client . ') läge außerhalb der freigegebenen Netze und wäre ausgesperrt. Bitte sein Netz mit freigeben.', 422);
        }
        $catalog = $this->catalog();
        $modules = [];
        foreach ((array)($input['modules'] ?? []) as $id) {
            if (!is_string($id) || !isset($catalog[$id])) throw new Problem('Unbekannter Modul-Port.', 422);
            if (!in_array($id, $modules, true)) $modules[] = $id;
        }
        sort($modules);
        $custom = [];
        $lines = is_array($input['custom'] ?? null) ? implode("\n", array_filter($input['custom'], 'is_string')) : (string)($input['custom'] ?? '');
        foreach (preg_split('/[\r\n,;]+/', $lines, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            if (trim($line) === '') continue;
            if (!preg_match('/^\s*(tcp|udp)[\s:]+(\d{1,5})(?:\s*-\s*(\d{1,5}))?\s*$/i', $line, $m)) {
                throw new Problem('Eigener Port ungültig: „' . mb_substr((string)preg_replace('/[^0-9a-zA-Z :-]/', '', $line), 0, 30) . '“. Beispiel: tcp 8080 oder udp 5000-5100', 422);
            }
            $lo = (int)$m[2]; $hi = (int)($m[3] ?? $m[2]);
            if ($lo < 1 || $hi > 65535 || $lo > $hi) throw new Problem('Portnummern müssen zwischen 1 und 65535 liegen.', 422);
            $entry = strtolower($m[1]) . ' ' . $lo . ($hi !== $lo ? '-' . $hi : '');
            if (!in_array($entry, $custom, true)) $custom[] = $entry;
        }
        if (count($custom) > 20) throw new Problem('Höchstens 20 eigene Ports.', 422);
        $flag = static fn(string $k) => ($input[$k] ?? false) === true;
        return ['cidrs' => $cidrs, 'ssh' => $flag('ssh'), 'ipp' => $flag('ipp'), 'samba' => $flag('samba'), 'strict' => ($input['strict'] ?? true) !== false,
            'modules' => $modules, 'custom' => $custom];
    }

    public function apply(array $input, string $client): array {
        $config = $this->validate($input, $client);
        if (!is_file(self::$unit)) throw new Problem('Der Firewall-Dienst ist nicht eingerichtet. Bitte den Installer ausführen.', 503);
        if (in_array($this->status()['state'], ['pending', 'requested'], true)) throw new Problem('Eine Firewall-Änderung wartet noch auf Bestätigung.', 409);
        $this->db->store(self::KEY, json_encode($config, JSON_THROW_ON_ERROR));
        @unlink($this->dir() . '/firewall-bestaetigt');
        $this->write(['action' => 'apply'] + $config);
        return $config;
    }

    public function disable(): void {
        if (!is_file(self::$unit)) throw new Problem('Der Firewall-Dienst ist nicht eingerichtet.', 503);
        $this->write(['action' => 'disable']);
    }

    /** Bestätigung, dass die Seite nach dem Laden erreichbar war: erst danach bleibt die Firewall dauerhaft. */
    public function confirm(): void {
        if ($this->status()['state'] !== 'pending') throw new Problem('Es gibt nichts zu bestätigen.', 409);
        if (file_put_contents($this->dir() . '/firewall-bestaetigt', (string)time()) === false) throw new Problem('Bestätigung konnte nicht gespeichert werden.', 500);
    }

    public function status(): array {
        $s = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        $s = is_array($s) ? $s : [];
        $state = (string)($s['state'] ?? 'off');
        if (is_file($this->dir() . '/firewall.json') && $state !== 'pending') $state = 'requested';
        if ($state === 'pending' && (int)($s['deadline'] ?? 0) < time() - 5) $state = 'reverted';
        return ['state' => $state, 'message' => (string)($s['message'] ?? ''), 'deadline' => isset($s['deadline']) ? (int)$s['deadline'] : null,
            'networks' => $s['networks'] ?? [], 'ports' => $s['ports'] ?? [], 'modules' => $s['modules'] ?? [], 'custom' => $s['custom'] ?? [],
            'strict' => $s['strict'] ?? true, 'now' => time()];
    }

    private function config(): ?array {
        $c = json_decode((string)$this->db->setting(self::KEY), true);
        return is_array($c) && isset($c['cidrs']) ? $c + ['ssh' => false, 'ipp' => false, 'samba' => false, 'strict' => true, 'modules' => [], 'custom' => []] : null;
    }

    private function write(array $request): void {
        $dir = $this->dir();
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        $path = $dir . '/firewall.json';
        $tmp = $dir . '/.firewall.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode($request, JSON_THROW_ON_ERROR)) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        }
    }
}
