<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Grundeinstellungen der Basis aus /etc/kienzle/core/core.json (schreibt der Installer, root-eigen, für ks-core lesbar).
 * Alles, was im Webinterface einstellbar ist, liegt dagegen in der Datenbank (Tabelle settings).
 */
final class Config {
    public const FILE = '/etc/kienzle/core/core.json';
    private const HOST = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D';

    /** @param list<string> $hostnames Namen und Adressen, unter denen Praxisherz erreichbar ist (wie im Zertifikat). */
    private function __construct(
        public readonly array $hostnames,
        public readonly string $dataDir,
        public readonly string $secretKey,
        public readonly string $modules,
        public readonly string $practice,
        public readonly string $root,
    ) {}

    public static function load(string $file = self::FILE): self {
        $raw = @file_get_contents($file);
        if (!is_string($raw)) throw new \RuntimeException('Konfiguration ' . $file . ' fehlt. Bitte den Installer ausführen.');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('Konfiguration ' . $file . ' ist kein gültiges JSON.');
        return self::fromArray($data);
    }

    public static function fromArray(array $data): self {
        $hosts = $data['hostnames'] ?? null;
        if (!is_array($hosts) || !$hosts || count($hosts) > 20) throw new \RuntimeException('Konfiguration: hostnames fehlt.');
        foreach ($hosts as $h) {
            if (!is_string($h) || !(preg_match(self::HOST, $h) || filter_var($h, FILTER_VALIDATE_IP))) {
                throw new \RuntimeException('Konfiguration: ungültiger Servername.');
            }
        }
        $dataDir = (string)($data['data_dir'] ?? '/var/lib/kienzle/core');
        return new self(array_values(array_unique($hosts)), rtrim($dataDir, '/'),
            (string)($data['secret_key'] ?? '/etc/kienzle/core/secret.key'),
            (string)($data['modules'] ?? $dataDir . '/modules.json'),
            mb_substr(trim((string)($data['practice'] ?? '')), 0, 80),
            dirname(__DIR__));
    }

    /** Ist $host (aus Host-Kopfzeile oder Origin) einer der eigenen Namen? IPv6 ohne Klammern vergleichen. */
    public function ownHost(string $host): bool {
        $host = strtolower(trim($host));
        if (preg_match('/^\[([0-9a-f:.]+)\](?::443)?$/D', $host, $m)) $host = $m[1];
        elseif (preg_match('/^([^:\[\]]+)(?::443)?$/D', $host, $m)) $host = $m[1];
        else return false;
        return in_array($host, $this->hostnames, true);
    }

    public function version(): string { return trim((string)@file_get_contents($this->root . '/VERSION')); }
}
