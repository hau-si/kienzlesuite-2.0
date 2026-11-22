<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** Antwort mit Status, Kopfzeilen und Inhalt. */
final class Response {
    /** @param list<array{0:string,1:string}> $headers */
    public function __construct(public int $status = 200, public string $body = '', public array $headers = []) {}

    public static function html(string $body, int $status = 200): self { return new self($status, $body, [['Content-Type', 'text/html; charset=utf-8']]); }

    public static function redirect(string $to): self { return new self(303, '', [['Location', $to]]); }

    public static function json(array $data, int $status = 200): self {
        return new self($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), [['Content-Type', 'application/json']]);
    }

    public function with(string $name, string $value): self { $this->headers[] = [$name, $value]; return $this; }

    public function header(string $name): ?string {
        foreach ($this->headers as [$k, $v]) if (strcasecmp($k, $name) === 0) return $v;
        return null;
    }

    public function send(): void {
        http_response_code($this->status);
        header_remove('X-Powered-By');
        foreach ($this->headers as [$k, $v]) header($k . ': ' . $v, false);
        echo $this->body;
    }
}
