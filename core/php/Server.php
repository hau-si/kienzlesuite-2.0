<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Servernamen und Praxisname (Verwaltung → Server). Übernommen werden sie vom root-Dienst kienzle-servername
 * (core/lib/kienzle/servername.py): core.json, Server-Zertifikat, nginx. Hier nur Vorprüfung und Anfrage.
 */
final class Server {
    public static string $state = '/var/lib/kienzle/servername';
    public static string $unit = '/etc/systemd/system/kienzle-servername.path';

    public function __construct(private Database $db, private Config $config) {}

    public function status(): array {
        $s = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        return ['names' => $this->config->hostnames, 'practice' => $this->config->practice, 'last' => is_array($s['letzte'] ?? null) ? $s['letzte'] : null,
            'pending' => is_file($this->request()), 'service' => is_file(self::$unit)];
    }

    /** Ein „richtiger“ Name ist festgelegt (mit Punkt, keine IP): Voraussetzung für Passkeys auf allen Arbeitsplätzen. */
    public function named(): bool {
        foreach ($this->config->hostnames as $h) if (!filter_var($h, FILTER_VALIDATE_IP) && str_contains($h, '.')) return true;
        return false;
    }

    public function save(string $names, string $practice, bool $newCa = false): void {
        $list = array_values(array_unique(array_filter(array_map(fn($n) => strtolower(trim($n)), preg_split('/[\s,;]+/', $names) ?: []))));
        if (!$list || count($list) > 10) throw new Problem('Bitte 1 bis 10 Namen bzw. Adressen angeben.', 422);
        foreach ($list as $n) {
            if (!filter_var($n, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $n)) {
                throw new Problem('„' . mb_substr($n, 0, 60) . '“ ist kein gültiger Name.', 422);
            }
        }
        $practice = trim($practice);
        if (mb_strlen($practice) > 60 || preg_match('/[\x00-\x1f<>]/', $practice)) throw new Problem('Praxisname: höchstens 60 Zeichen, ohne < und >.', 422);
        if (!is_file(self::$unit)) throw new Problem('Der Dienst für Servernamen ist nicht eingerichtet. Bitte den Installer ausführen.', 503);
        if (is_file($this->request())) throw new Problem('Eine Änderung wird gerade übernommen. Bitte warten.', 409);
        $dir = dirname($this->request());
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        $tmp = $dir . '/.servername.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode(['namen' => $list, 'praxis' => $practice] + ($newCa ? ['neue_ca' => true] : []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($tmp, $this->request())) {
            @unlink($tmp);
            throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        }
    }

    /** Uhr des Servers um $skew Sekunden stellen (Wert nur aus einem passenden Code abgeleitet, höchstens 24 Stunden). */
    public function setClock(int $skew): void {
        if (abs($skew) > 86400) throw new Problem('Abweichung zu groß.', 422);
        if (!is_file(self::$unit)) throw new Problem('Der Dienst für die Uhrzeit ist nicht eingerichtet. Bitte den Installer ausführen.', 503);
        $dir = dirname($this->request());
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        $tmp = $dir . '/.servername.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, json_encode(['aktion' => 'uhrzeit', 'delta' => $skew])) === false || !rename($tmp, $this->request())) {
            @unlink($tmp);
            throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        }
    }

    private function request(): string { return $this->db->directory . '/anfragen/servername.json'; }
}
