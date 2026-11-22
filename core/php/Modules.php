<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Verzeichnis der installierten Module. Quelle ist modules.json, die geprüfte Ausgabe von
 * `kienzle-modul-pruefen --alle --json` (docs/MODULE-TOML.md); module.toml liest PHP nie selbst.
 */
final class Modules {
    /** @var array<string, array> id → geprüfte Beschreibung */
    private array $modules = [];

    public function __construct(string $file) {
        $raw = @file_get_contents($file);
        if (!is_string($raw)) return;                       // noch keine Module installiert
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['modules'] ?? null)) throw new \RuntimeException('Modulverzeichnis ist beschädigt.');
        foreach ($data['modules'] as $m) {
            $id = (string)($m['module']['id'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9-]{1,28}$/D', $id)) throw new \RuntimeException('Modulverzeichnis ist beschädigt.');
            $this->modules[$id] = $m;
        }
        ksort($this->modules);
    }

    public static function fromArray(array $modules): self {
        $self = new self('/nonexistent');
        foreach ($modules as $m) $self->modules[$m['module']['id']] = $m;
        ksort($self->modules);
        return $self;
    }

    /** @return array<string, array> */
    public function all(): array { return $this->modules; }

    public function has(string $id): bool { return isset($this->modules[$id]); }

    public function name(string $id): string { return (string)($this->modules[$id]['module']['name'] ?? $id); }

    /** @return list<string> Rechte-IDs des Moduls */
    public function rightIds(string $id): array { return array_column($this->modules[$id]['rights'] ?? [], 'id'); }

    /** @return list<array{id:string,label:string}> */
    public function rights(string $id): array { return $this->modules[$id]['rights'] ?? []; }

    /** Webpfad des Moduls, das den Arbeitsplatz-Starter (/api.php) bedient, sonst leer. */
    public function starterPath(): string {
        foreach ($this->modules as $m) if (($m['web']['starter'] ?? false) === true) return (string)$m['web']['path'];
        return '';
    }

    public function webPath(string $id): ?string { return $this->modules[$id]['web']['path'] ?? null; }

    /** Modul, zu dessen Webpfad die angefragte Adresse gehört (genau der Pfad oder darunter), sonst null. */
    public function forUri(string $uri): ?string {
        $path = (string)parse_url($uri, PHP_URL_PATH);
        if ($path === '' || str_contains($path, "\0")) return null;
        foreach ($this->modules as $id => $m) {
            $base = $m['web']['path'] ?? null;
            if ($base !== null && ($path === $base || str_starts_with($path, $base . '/'))) return $id;
        }
        return null;
    }
}
