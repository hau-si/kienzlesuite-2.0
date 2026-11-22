<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** Eingehende Anfrage, unabhängig von PHP-Globals (testbar ohne Webserver). */
final class Request {
    /** @param array<string,string> $headers Namen in Kleinbuchstaben */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly string $remote = '',
    ) {}

    public static function fromGlobals(): self {
        $headers = [];
        foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_') && is_string($v)) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return new self(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), $path === '' ? '/' : $path,
            self::strings($_GET), self::strings($_POST), self::strings($_COOKIE), $headers, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /** Nur Texte und Listen von Texten übernehmen (keine verschachtelten Felder). */
    private static function strings(array $in): array {
        $out = [];
        foreach ($in as $k => $v) {
            if (is_string($v)) $out[(string)$k] = $v;
            elseif (is_array($v)) $out[(string)$k] = array_values(array_filter($v, 'is_string'));
        }
        return $out;
    }

    public function header(string $name): string { return (string)($this->headers[strtolower($name)] ?? ''); }

    public function field(string $name, int $max = 200): string {
        $v = $this->post[$name] ?? '';
        return is_string($v) ? mb_substr($v, 0, $max) : '';
    }

    /** @return list<string> */
    public function list(string $name): array {
        $v = $this->post[$name] ?? [];
        return is_array($v) ? array_slice($v, 0, 100) : [];
    }

    public function get(string $name, int $max = 200): string {
        $v = $this->query[$name] ?? '';
        return is_string($v) ? mb_substr($v, 0, $max) : '';
    }

    public function cookie(string $name): ?string { $v = $this->cookies[$name] ?? null; return is_string($v) ? $v : null; }
}
