<?php
declare(strict_types=1);
// Browser-Nachbildung und Hilfen für die Tests des Webinterfaces (gemeinsam für web.php, firewall.php).

use Kienzle\Core\{Auth, Connections, Firewall, Request, Response, Totp, Updates, Web};

/** Browser-Nachbildung: hält das Cookie, sendet Host und Origin, liest CSRF-Marken aus Seiten. */
final class Browser {
    public ?string $cookie = null; public string $csrf = ''; public ?Response $last = null; public string $host = 'kienzle.example.org';
    /** @var ?Closure(): Web eigene Zusammenstellung (z. B. mit Firewall) */
    public ?Closure $web = null;
    public function __construct(public Env $env, public string $ip = '192.0.2.10') {}
    public function defaultWeb(?Firewall $firewall = null, ?Updates $updates = null, ?Connections $connections = null): Web {
        $auth = new Auth($this->env->db, $this->env->users, $this->env->audit, $this->ip);
        return new Web($this->env->config, $this->env->db, $this->env->users, $auth, $this->env->passkeys, $this->env->modules, $this->env->audit,
            static fn(string $uri) => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', $firewall, $updates, $connections);
    }
    private function make(): Web { return $this->web ? ($this->web)() : $this->defaultWeb(); }
    public function req(string $method, string $uri, array $post = [], array $headers = []): Response {
        $path = (string)parse_url($uri, PHP_URL_PATH); parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        $h = ['host' => $this->host, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0'] + ($method === 'POST' ? ['origin' => 'https://' . $this->host] : []);
        foreach ($headers as $k => $v) { if ($v === null) unset($h[strtolower($k)]); else $h[strtolower($k)] = $v; }
        $r = $this->make()->handle(new Request($method, $path, $query, $post, $this->cookie ? [Auth::COOKIE => $this->cookie] : [], $h, $this->ip));
        $set = $r->header('Set-Cookie');
        if ($set !== null && preg_match('/^' . Auth::COOKIE . '=([^;]*)/', $set, $m)) $this->cookie = $m[1] !== '' ? $m[1] : null;
        if (preg_match('/name="csrf" value="([a-f0-9]+)"/', $r->body, $m)) $this->csrf = $m[1];
        return $this->last = $r;
    }
    public function get(string $uri, array $headers = []): Response { return $this->req('GET', $uri, [], $headers); }
    public function post(string $uri, array $post = [], array $headers = []): Response { return $this->req('POST', $uri, $post + ['csrf' => $this->csrf], $headers); }
    public function follow(): Response { return $this->get((string)$this->last->header('Location')); }
}

function code_now(string $secret, int $offset = 0): string { return Totp::code($secret, Totp::step() + $offset); }

/** Basis-Admin mit OTP, angemeldet und bestätigt. */
function admin_browser(Env $env, string $login = 'anna'): array {
    [$id, $secret] = $env->user($login, true, otp: true);
    $b = new Browser($env);
    $b->post('/anmelden', ['login' => $login, 'password' => 'Wiesen-Blume-Fahrrad-7']);
    $b->follow();
    $b->post('/anmelden/code', ['code' => code_now($secret)]);
    check($b->last->status === 303 && $b->last->header('Location') === '/', 'Admin nicht angemeldet: ' . $b->last->status);
    $b->get('/');
    return [$b, $id, $secret];
}
