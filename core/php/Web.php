<?php
declare(strict_types=1);
namespace Kienzle\Core;

use Kienzle\Core\View as V;

/**
 * Webinterface der Basis: Anmeldung, Konto, Benutzerverwaltung und die Prüfung für nginx (/_auth/<modul>).
 * Jede Antwort bekommt Schutz-Kopfzeilen; jede POST-Anfrage braucht die eigene Herkunft und (mit Sitzung) die CSRF-Marke.
 */
final class Web {
    /** @var callable(string): ?string otpauth-URI → SVG des QR-Codes oder null */
    private $qr;
    private ?string $token = null;
    private ?array $session = null;
    private ?Request $req = null;

    public function __construct(
        private Config $config, private Database $db, private Users $users, private Auth $auth, private ?Passkeys $passkeys,
        private Modules $modules, private AuditLog $audit, ?callable $qr = null,
    ) {
        $this->qr = $qr ?? static function (string $uri): ?string {
            if (!is_executable('/usr/bin/qrencode')) return null;
            try { $r = Command::run(['/usr/bin/qrencode', '-t', 'SVG', '-l', 'M', '-m', '2', '-o', '-', $uri], '', 5, 262144); } catch (Problem) { return null; }
            return $r['code'] === 0 && str_starts_with(ltrim($r['output']), '<?xml') ? $r['output'] : null;
        };
    }

    public function handle(Request $req): Response {
        $this->req = $req;
        try {
            $response = $this->route($req);
        } catch (Problem $e) {
            $response = str_starts_with($req->path, '/_') || str_contains($req->header('accept'), 'application/json') || str_contains($req->path, '/passkey')
                ? Response::json(['error' => $e->getMessage()], $e->http)
                : Response::html(V::page('Hinweis', V::error($e->getMessage()) . '<p><a href="/">Zur Startseite</a></p>', $this->nav(), $this->config->practice), $e->http);
        }
        return self::secure($response);
    }

    private function route(Request $r): Response {
        if ($r->path === '/_health') return $this->health();
        if (!$this->config->ownHost($r->header('host'))) throw new Problem('Unbekannter Servername.', 421);
        if (preg_match('#^/_auth/([a-z][a-z0-9-]{1,28})$#D', $r->path, $m)) return $this->forward($r, $m[1]);
        if ($r->method === 'POST') $this->checkOrigin($r);
        elseif ($r->method !== 'GET' && $r->method !== 'HEAD') throw new Problem('Methode nicht erlaubt.', 405);

        $this->token = $r->cookie(Auth::COOKIE);
        $this->session = $this->auth->session($this->token, null);
        $stage = $this->session['stage'] ?? null;
        $get = $r->method !== 'POST';

        // Seiten ohne vollständige Anmeldung
        switch ($r->path) {
            case '/anmelden': return $get ? $this->loginPage() : $this->login($r);
            case '/anmelden/passkey/optionen': return $this->passkeyLoginOptions();
            case '/anmelden/passkey': return $this->passkeyLogin($r);
            case '/einrichten': return $get ? $this->setupPage($r->get('t', 60)) : $this->setup($r);
            case '/abmelden': if (!$get) return $this->logout($r); break;
        }
        if ($stage === 'otp') return $r->path === '/anmelden/code' ? ($get ? $this->codePage() : $this->code($r)) : Response::redirect('/anmelden/code');
        if ($stage === 'enroll') return $r->path === '/konto/otp' ? ($get ? $this->enrollPage() : $this->enroll($r)) : Response::redirect('/konto/otp');
        if ($stage !== 'full') return Response::redirect('/anmelden' . ($get && $r->path !== '/' ? '?weiter=' . rawurlencode($r->path) : ''));
        if (!$get) $this->checkCsrf($r);

        $routes = [
            'GET /' => fn() => $this->start(),
            'GET /konto' => fn() => $this->account(),
            'POST /konto/passwort' => fn() => $this->changePassword($r),
            'GET /konto/otp' => fn() => $this->enrollPage(),
            'POST /konto/otp' => fn() => $this->enroll($r),
            'POST /konto/codes' => fn() => $this->newCodes(),
            'POST /konto/geraete' => fn() => $this->revoke($r),
            'POST /konto/passkey/optionen' => fn() => $this->passkeyRegisterOptions(),
            'POST /konto/passkey' => fn() => $this->passkeyRegister($r),
            'POST /konto/passkey/entfernen' => fn() => $this->passkeyRemove($r),
            'GET /bestaetigen' => fn() => $this->confirmPage($r->get('weiter')),
            'POST /bestaetigen' => fn() => $this->confirm($r),
            'POST /bestaetigen/passkey/optionen' => fn() => $this->passkeyConfirmOptions(),
            'POST /bestaetigen/passkey' => fn() => $this->passkeyConfirm($r),
        ];
        $key = ($get ? 'GET' : 'POST') . ' ' . $r->path;
        if (isset($routes[$key])) return $routes[$key]();
        if (str_starts_with($r->path, '/verwaltung/')) return $this->admin($r, $get);
        throw new Problem('Seite nicht gefunden.', 404);
    }

    // ---- Prüfung für nginx --------------------------------------------------------------------------------------
    /** auth_request: 204 mit Benutzer und Rechten für das Modul, 401 ohne Anmeldung, 403 ohne Zugang. */
    private function forward(Request $r, string $module): Response {
        if (!$this->modules->has($module)) return new Response(404);
        $session = $this->auth->session($r->cookie(Auth::COOKIE), 'full');
        if (!$session) return new Response(401);
        $access = $this->users->access((int)$session['user_id'])[$module] ?? null;
        if (!$access) return new Response(403);
        return (new Response(204))
            ->with('X-Kienzle-Benutzer', $session['login'])
            ->with('X-Kienzle-Name', rawurlencode($session['display'] !== '' ? $session['display'] : $session['login']))
            ->with('X-Kienzle-Rolle', $access['admin'] ? 'admin' : 'mitarbeiter')
            ->with('X-Kienzle-Rechte', implode(',', $access['rights']))
            ->with('X-Kienzle-Csrf', self::moduleCsrf($session['csrf'], $module))
            ->with('X-Kienzle-Bestaetigt', (string)(int)$session['confirmed_at']);
    }

    /** CSRF-Marke, die ein Modul in seine Formulare schreibt und mit X-Kienzle-Csrf vergleicht. */
    public static function moduleCsrf(string $sessionCsrf, string $module): string { return hash_hmac('sha256', 'modul:' . $module, $sessionCsrf); }

    private function health(): Response {
        $this->db->one('SELECT 1 AS ok');
        return Response::json(['status' => 'ok', 'version' => $this->config->version()]);
    }

    // ---- Anmeldung ----------------------------------------------------------------------------------------------
    private function loginPage(?string $error = null, string $login = ''): Response {
        if (($this->session['stage'] ?? '') === 'full') return Response::redirect($this->next($this->req?->get('weiter') ?? ''));
        $weiter = $this->next($this->req?->get('weiter') ?? '');
        $body = V::error($error)
            . '<form method="post" action="/anmelden?weiter=' . V::h(rawurlencode($weiter)) . '" class="box">'
            . V::input('login', 'Anmeldename', 'text', $login, 'autocomplete="username" autocapitalize="none" required autofocus')
            . V::input('password', 'Passwort', 'password', '', 'autocomplete="current-password" required')
            . V::checkbox('remember', '1', 'Dieses Gerät merken (bis zu 6 Monate, nur aus diesem Netz)', false)
            . '<button>Anmelden</button></form>'
            . ($this->passkeys ? '<div class="box" data-passkey-login data-next="' . V::h($weiter) . '"><p>Oder ohne Passwort:</p>'
                . '<button type="button">Mit Passkey anmelden</button><p class="error" hidden></p></div>' : '');
        return Response::html(V::page('Anmelden', $body, null, $this->config->practice), $error ? 401 : 200);
    }

    private function login(Request $r): Response {
        try {
            $result = $this->auth->login($r->field('login', 64), $r->field('password'), $r->field('remember') === '1', $r->header('user-agent'));
        } catch (Problem $e) {
            return $this->loginPage($e->getMessage(), $r->field('login', 64));
        }
        return $this->started($result, $this->next($r->get('weiter')));
    }

    /** Nach Passwort oder Passkey: Cookie setzen und je nach Stufe weiter. */
    private function started(array $result, string $next): Response {
        $target = match ($result['stage']) { 'otp' => '/anmelden/code', 'enroll' => '/konto/otp', default => $next };
        if ($result['stage'] !== 'full') {
            $this->db->query("DELETE FROM settings WHERE key LIKE 'next:%' AND updated_at<?", [time() - Auth::STEP_SECONDS]);
            $this->db->store('next:' . hash('sha256', $result['token']), $next);
        }
        $remember = $this->auth->session($result['token'], null);
        return Response::redirect($target)->with('Set-Cookie', Auth::cookie($result['token'], $remember ? $this->auth->cookieTtl($remember) : null));
    }

    private function codePage(?string $error = null): Response {
        $body = V::error($error) . '<form method="post" action="/anmelden/code" class="box">'
            . V::input('code', 'Code aus der Authenticator-App (oder Wiederherstellungscode)', 'text', '', 'autocomplete="one-time-code" inputmode="text" required autofocus')
            . '<button>Weiter</button></form>'
            . '<form method="post" action="/abmelden"><button class="link">Abbrechen</button></form>';
        return Response::html(V::page('Code eingeben', $body, null, $this->config->practice), $error ? 401 : 200);
    }

    private function code(Request $r): Response {
        try {
            $token = $this->auth->otp($this->session, $r->field('code', 40));
        } catch (Problem $e) {
            if (!$this->auth->session($this->token, 'otp')) return Response::redirect('/anmelden')->with('Set-Cookie', Auth::clearCookie());
            return $this->codePage($e->getMessage());
        }
        $next = $this->takeNext($this->token);
        return Response::redirect($next)->with('Set-Cookie', Auth::cookie($token, $this->auth->cookieTtl($this->session)));
    }

    private function logout(Request $r): Response {
        if ($this->session && ($this->session['stage'] === 'full')) $this->checkCsrf($r);
        if ($this->session) $this->audit->write((string)$this->session['login'], 'abgemeldet');
        $this->auth->logout($this->token);
        return Response::redirect('/anmelden')->with('Set-Cookie', Auth::clearCookie());
    }

    // ---- Einrichtungslink ---------------------------------------------------------------------------------------
    private function setupPage(string $token, ?string $error = null): Response {
        $info = $this->users->setupInfo($token);
        if (!$info) return Response::html(V::page('Einrichtungslink ungültig', '<p>Der Link ist abgelaufen oder wurde schon verwendet. '
            . 'Einen neuen Link erzeugt ein Admin (für Mitarbeiter) oder an der Server-Konsole: <code>sudo kienzle-admin zuruecksetzen &lt;anmeldename&gt;</code></p>', null, $this->config->practice), 404);
        $user = $info['user_id'] ? $this->users->find($info['user_id']) : null;
        $body = V::error($error) . '<form method="post" action="/einrichten" class="box"><input type="hidden" name="t" value="' . V::h($token) . '">'
            . ($user ? '<p>Konto: <strong>' . V::h($user['login']) . '</strong></p>'
                : '<p>Ersten Admin anlegen. Er verwaltet Benutzer, Firewall, Updates und Sicherung und meldet sich mit Passwort und Code aus einer Authenticator-App an.</p>'
                . V::input('login', 'Anmeldename', 'text', $this->req?->field('login', 64) ?? '', 'autocomplete="username" autocapitalize="none" required')
                . V::input('display', 'Name (optional)', 'text', $this->req?->field('display', 80) ?? ''))
            . V::input('password', 'Neues Passwort (mindestens 10 Zeichen)', 'password', '', 'autocomplete="new-password" required')
            . V::input('password2', 'Passwort wiederholen', 'password', '', 'autocomplete="new-password" required')
            . '<button>Speichern</button></form>';
        return Response::html(V::page($user ? 'Passwort festlegen' : 'Einrichtung', $body, null, $this->config->practice), $error ? 422 : 200);
    }

    private function setup(Request $r): Response {
        $token = $r->field('t', 60);
        $info = $this->users->setupInfo($token);
        if (!$info) return $this->setupPage($token);
        try {
            if ($r->field('password') !== $r->field('password2')) throw new Problem('Die Passwörter stimmen nicht überein.', 422);
            $id = $this->db->transaction(function () use ($info, $r, $token): int {
                $id = $info['user_id'] ?? $this->users->create($r->field('login', 64), $r->field('display', 80), true);
                $this->users->setPassword($id, $r->field('password'));
                $this->users->redeemSetup($token);
                return $id;
            });
        } catch (Problem $e) {
            return $this->setupPage($token, $e->getMessage());
        }
        $user = $this->users->find($id);
        $this->audit->write($user['login'], $info['user_id'] ? 'passwort-ueber-link' : 'erster-admin-angelegt');
        $stage = !$this->users->needsOtp($user) ? 'full' : ($this->users->hasOtp($user) ? 'otp' : 'enroll');
        if ($stage === 'otp') return Response::redirect('/anmelden');    // OTP vorhanden: normal anmelden
        $result = $this->auth->login($user['login'], $r->field('password'), false, $r->header('user-agent'));
        return $this->started($result, '/');
    }

    // ---- OTP einrichten -----------------------------------------------------------------------------------------
    private function enrollPage(?string $error = null): Response {
        $session = $this->session;
        $user = $this->users->find((int)$session['user_id']);
        if ($session['stage'] === 'full' && $this->users->hasOtp($user) && !$this->auth->confirmedRecently($session)) return $this->needConfirm('/konto/otp');
        $start = $this->auth->enrollStart($session, $this->issuer());
        $svg = ($this->qr)($start['uri']);
        $body = ($session['stage'] === 'enroll' ? '<p>Dieses Konto hat Admin-Rechte. Admins melden sich mit Passwort <strong>und</strong> einem Code aus einer Authenticator-App an '
                . '(z. B. Aegis, 2FAS, Google oder Microsoft Authenticator).</p>' : '<p>Neues Telefon oder neue App: Nach der Bestätigung gilt nur noch der neue Code; die Wiederherstellungscodes werden erneuert.</p>')
            . V::error($error)
            . '<ol><li>App öffnen und den QR-Code scannen' . ($svg ? '' : ' (QR-Code nicht verfügbar)') . ' oder den Schlüssel eintippen.</li><li>Den angezeigten 6-stelligen Code eingeben.</li></ol>'
            . ($svg ? '<p><img class="qr" alt="QR-Code für die Authenticator-App" src="data:image/svg+xml;base64,' . base64_encode($svg) . '"></p>' : '')
            . '<p>Schlüssel: <code class="secret">' . V::h(Totp::grouped($start['secret'])) . '</code></p>'
            . '<form method="post" action="/konto/otp" class="box">' . V::csrf($session['csrf'])
            . V::input('code', 'Code aus der App', 'text', '', 'autocomplete="one-time-code" inputmode="numeric" pattern="[0-9 ]{6,7}" required autofocus')
            . '<button>Bestätigen</button></form>';
        return Response::html(V::page('Authenticator-App einrichten', $body, $session['stage'] === 'full' ? $this->nav() : null, $this->config->practice), $error ? 422 : 200);
    }

    private function enroll(Request $r): Response {
        $this->checkCsrf($r);
        $session = $this->session;
        $user = $this->users->find((int)$session['user_id']);
        if ($session['stage'] === 'full' && $this->users->hasOtp($user) && !$this->auth->confirmedRecently($session)) return $this->needConfirm('/konto/otp');
        try {
            $result = $this->auth->enrollConfirm($session, $r->field('code', 20));
        } catch (Problem $e) {
            return $this->enrollPage($e->getMessage());
        }
        $next = $session['stage'] === 'enroll' ? $this->takeNext($this->token) : '/konto';
        $this->token = $result['token'];
        $this->session = $this->auth->session($result['token']);
        $body = V::notice('Die Authenticator-App ist eingerichtet.') . V::codes($result['codes']) . '<p><a class="button" href="' . V::h($next) . '">Weiter</a></p>';
        return Response::html(V::page('Wiederherstellungscodes', $body, $this->nav(), $this->config->practice))
            ->with('Set-Cookie', Auth::cookie($result['token'], $this->auth->cookieTtl($this->session)));
    }

    // ---- Start und Konto ----------------------------------------------------------------------------------------
    private function start(): Response {
        $uid = (int)$this->session['user_id'];
        $items = '';
        foreach ($this->users->access($uid) as $module => $a) {
            $path = $this->modules->webPath($module);
            if ($path !== null) $items .= '<li><a href="' . V::h($path) . '/">' . V::h($this->modules->name($module)) . '</a>' . ($a['admin'] ? ' <span class="tag">Admin</span>' : '') . '</li>';
        }
        $body = '<p>Angemeldet als <strong>' . V::h($this->session['display'] ?: $this->session['login']) . '</strong>.</p>'
            . ($items !== '' ? '<ul class="modules">' . $items . '</ul>' : '<p>Für dieses Konto sind noch keine Module freigegeben.'
                . ((int)$this->session['user_admin'] === 1 ? ' Zugang zu Modulen vergeben Sie unter <a href="/verwaltung/benutzer">Benutzer</a>, auch für sich selbst.' : ' Bitte den Admin fragen.') . '</p>');
        return Response::html(V::page('Start', $body, $this->nav(), $this->config->practice));
    }

    private function account(?string $error = null, ?string $notice = null): Response {
        $uid = (int)$this->session['user_id'];
        $user = $this->users->find($uid);
        $csrf = V::csrf($this->session['csrf']);
        $devices = '';
        foreach ($this->auth->devices($uid, $this->token) as $d) {
            $devices .= '<tr><td>' . V::h($d['label']) . ($d['current'] ? ' (dieses Gerät)' : '') . '</td><td>' . V::date($d['last_used']) . '</td><td>'
                . '<form method="post" action="/konto/geraete">' . $csrf . '<input type="hidden" name="id" value="' . V::h($d['id']) . '"><button class="link">Abmelden</button></form></td></tr>';
        }
        $keys = '';
        if ($this->passkeys) foreach ($this->passkeys->list($uid) as $k) {
            $keys .= '<tr><td>' . V::h($k['label']) . ($k['synced'] ? ' <span class="tag">synchronisiert</span>' : '') . '</td><td>' . V::date($k['last_used']) . '</td><td>'
                . '<form method="post" action="/konto/passkey/entfernen">' . $csrf . '<input type="hidden" name="id" value="' . V::h($k['id']) . '"><button class="link">Entfernen</button></form></td></tr>';
        }
        $otp = $this->users->hasOtp($user);
        $body = V::error($error) . V::notice($notice)
            . '<section><h2>Passwort ändern</h2><form method="post" action="/konto/passwort" class="box">' . $csrf
            . V::input('current', 'Bisheriges Passwort', 'password', '', 'autocomplete="current-password" required')
            . V::input('password', 'Neues Passwort', 'password', '', 'autocomplete="new-password" required')
            . V::input('password2', 'Neues Passwort wiederholen', 'password', '', 'autocomplete="new-password" required')
            . '<button>Ändern</button></form></section>'
            . '<section><h2>Authenticator-App</h2><p>' . ($otp ? 'Eingerichtet. Übrige Wiederherstellungscodes: ' . $this->users->recoveryLeft($uid) . '.' : 'Nicht eingerichtet' . ($this->users->needsOtp($user) ? ' (für Admins Pflicht).' : '.')) . '</p>'
            . '<p><a class="button" href="/konto/otp">' . ($otp ? 'Neu einrichten' : 'Einrichten') . '</a></p>'
            . ($otp ? '<form method="post" action="/konto/codes">' . $csrf . '<button>Neue Wiederherstellungscodes erzeugen</button></form>' : '') . '</section>'
            . ($this->passkeys ? '<section><h2>Passkeys</h2><p>Anmelden ohne Passwort mit Fingerabdruck, Gesicht oder PIN des Geräts. Funktioniert nur unter der Adresse <code>'
                . V::h($this->passkeys->origin()) . '</code>.' . ($this->users->needsOtp($user) ? ' Die Authenticator-App bleibt als Rückfall nötig.' : '') . '</p>'
                . ($keys !== '' ? '<table><tr><th>Passkey</th><th>Zuletzt</th><th></th></tr>' . $keys . '</table>' : '<p>Noch kein Passkey.</p>')
                . '<div class="box" data-passkey-register data-csrf="' . V::h($this->session['csrf']) . '">' . V::input('label', 'Bezeichnung', 'text', '', 'maxlength="60" placeholder="z. B. Laptop Empfang"')
                . '<button type="button">Passkey hinzufügen</button><p class="error" hidden></p></div></section>' : '')
            . '<section><h2>Gemerkte Geräte</h2>' . ($devices !== '' ? '<table><tr><th>Gerät</th><th>Zuletzt</th><th></th></tr>' . $devices . '</table>'
                . '<form method="post" action="/konto/geraete">' . $csrf . '<input type="hidden" name="id" value="all"><button class="link">Alle abmelden</button></form>' : '<p>Keine.</p>') . '</section>';
        return Response::html(V::page('Mein Konto', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function changePassword(Request $r): Response {
        $uid = (int)$this->session['user_id'];
        $user = $this->users->find($uid);
        try {
            if (!$this->users->verifyPassword($user, $r->field('current'))) {
                $this->auth->throttle->fail('login');
                throw new Problem('Bisheriges Passwort falsch.', 403);
            }
            if ($r->field('password') !== $r->field('password2')) throw new Problem('Die neuen Passwörter stimmen nicht überein.', 422);
            $this->users->setPassword($uid, $r->field('password'));
        } catch (Problem $e) {
            return $this->account($e->getMessage());
        }
        $this->db->query('DELETE FROM sessions WHERE user_id=? AND token_hash<>?', [$uid, $this->session['token_hash']]);   // andere Geräte abmelden
        $this->audit->write($user['login'], 'passwort-geaendert');
        return $this->account(null, 'Passwort geändert. Andere Geräte wurden abgemeldet.');
    }

    private function newCodes(): Response {
        if (!$this->auth->confirmedRecently($this->session)) return $this->needConfirm('/konto');
        $uid = (int)$this->session['user_id'];
        if (!$this->users->hasOtp($this->users->find($uid))) throw new Problem('Zuerst die Authenticator-App einrichten.', 409);
        $codes = $this->users->newRecoveryCodes($uid);
        $this->audit->write((string)$this->session['login'], 'wiederherstellungscodes-erneuert');
        return Response::html(V::page('Wiederherstellungscodes', V::notice('Die bisherigen Codes gelten nicht mehr.') . V::codes($codes) . '<p><a class="button" href="/konto">Weiter</a></p>', $this->nav(), $this->config->practice));
    }

    private function revoke(Request $r): Response {
        $n = $this->auth->revoke((int)$this->session['user_id'], $r->field('id', 20));
        $this->audit->write((string)$this->session['login'], 'geraet-abgemeldet', $r->field('id', 20) === 'all' ? 'alle' : '');
        return $this->account(null, $n === 1 ? 'Gerät abgemeldet.' : $n . ' Geräte abgemeldet.');
    }

    // ---- Passkeys -----------------------------------------------------------------------------------------------
    private function passkeysOn(): Passkeys {
        if (!$this->passkeys) throw new Problem('Passkeys sind nicht verfügbar: Die Suite hat keinen Servernamen (nur IP-Adressen).', 409);
        if (!hash_equals($this->passkeys->rpId, strtolower((string)preg_replace('/:443$/', '', $this->req->header('host'))))) {
            throw new Problem('Passkeys funktionieren nur unter ' . $this->passkeys->origin() . '.', 409);
        }
        return $this->passkeys;
    }

    private function passkeyLoginOptions(): Response { return Response::json($this->passkeysOn()->assertionOptions(null)); }

    private function passkeyLogin(Request $r): Response {
        $pk = $this->passkeysOn();
        $client = self::jsonBody($r);
        try {
            $uid = $pk->finishAssertion($client, null);
        } catch (Problem $e) {
            $this->auth->throttle->fail('login');
            throw $e;
        }
        $next = $this->next(is_string($client['weiter'] ?? null) ? $client['weiter'] : '');
        $response = $this->started($this->auth->loginWithPasskey($uid, ($client['remember'] ?? false) === true, $r->header('user-agent')), $next);
        return Response::json(['weiter' => $response->header('Location')])->with('Set-Cookie', (string)$response->header('Set-Cookie'));
    }

    private function passkeyRegisterOptions(): Response {
        if (!$this->auth->confirmedRecently($this->session)) throw new Problem('Bitte zuerst unter „Bestätigen“ Passwort und Code eingeben.', 403);
        $user = $this->users->find((int)$this->session['user_id']);
        $pk = $this->passkeysOn();
        return Response::json($pk->registrationOptions($user, $pk->handle($user)));
    }

    private function passkeyRegister(Request $r): Response {
        if (!$this->auth->confirmedRecently($this->session)) throw new Problem('Bitte zuerst bestätigen.', 403);
        $client = self::jsonBody($r);
        $user = $this->users->find((int)$this->session['user_id']);
        $this->passkeysOn()->finishRegistration($user, $client, is_string($client['label'] ?? null) ? mb_substr($client['label'], 0, 60) : '');
        $this->audit->write($user['login'], 'passkey-hinzugefuegt');
        return Response::json(['weiter' => '/konto']);
    }

    private function passkeyRemove(Request $r): Response {
        if (!$this->auth->confirmedRecently($this->session)) return $this->needConfirm('/konto');
        if (!$this->passkeys || !$this->passkeys->remove((int)$this->session['user_id'], $r->field('id', 1400))) throw new Problem('Passkey nicht gefunden.', 404);
        $this->audit->write((string)$this->session['login'], 'passkey-entfernt');
        return $this->account(null, 'Passkey entfernt.');
    }

    private function passkeyConfirmOptions(): Response { return Response::json($this->passkeysOn()->assertionOptions((int)$this->session['user_id'])); }

    private function passkeyConfirm(Request $r): Response {
        $client = self::jsonBody($r);
        $this->passkeysOn()->finishAssertion($client, (int)$this->session['user_id']);
        $this->auth->confirmWithPasskey($this->session);
        return Response::json(['weiter' => $this->next(is_string($client['weiter'] ?? null) ? $client['weiter'] : '')]);
    }

    // ---- Bestätigen heikler Aktionen ----------------------------------------------------------------------------
    private function needConfirm(string $back): Response { return Response::redirect('/bestaetigen?weiter=' . rawurlencode($back)); }

    private function confirmPage(string $weiter, ?string $error = null): Response {
        $weiter = $this->next($weiter);
        $user = $this->users->find((int)$this->session['user_id']);
        $pk = $this->passkeys && $this->passkeys->count((int)$user['id']) > 0;
        $body = '<p>Diese Aktion ist geschützt. Bitte einmal bestätigen; das gilt dann 24 Stunden auf diesem Gerät.</p>' . V::error($error)
            . '<form method="post" action="/bestaetigen" class="box">' . V::csrf($this->session['csrf']) . '<input type="hidden" name="weiter" value="' . V::h($weiter) . '">'
            . V::input('password', 'Passwort', 'password', '', 'autocomplete="current-password" required autofocus')
            . ($this->users->needsOtp($user) ? V::input('code', 'Code aus der Authenticator-App', 'text', '', 'autocomplete="one-time-code" inputmode="numeric" required') : '')
            . '<button>Bestätigen</button></form>'
            . ($pk ? '<div class="box" data-passkey-confirm data-csrf="' . V::h($this->session['csrf']) . '" data-next="' . V::h($weiter) . '"><button type="button">Mit Passkey bestätigen</button><p class="error" hidden></p></div>' : '');
        return Response::html(V::page('Bestätigen', $body, $this->nav(), $this->config->practice), $error ? 403 : 200);
    }

    private function confirm(Request $r): Response {
        try {
            $this->auth->confirm($this->session, $r->field('password'), $r->field('code', 20));
        } catch (Problem $e) {
            return $this->confirmPage($r->field('weiter'), $e->getMessage());
        }
        return Response::redirect($this->next($r->field('weiter')));
    }

    // ---- Verwaltung (nur Basis-Admin) ---------------------------------------------------------------------------
    private function admin(Request $r, bool $get): Response {
        if ((int)$this->session['user_admin'] !== 1) throw new Problem('Nur für Basis-Admins.', 403);
        // Verwaltung nur mit frischer Bestätigung (24 Stunden) – schon beim Öffnen, damit keine Eingaben verloren gehen.
        if (!$this->auth->confirmedRecently($this->session)) return $this->needConfirm($get ? $r->path : (string)preg_replace('#/(link|loeschen)$#', '', $r->path));
        $admin = (string)$this->session['login'];
        if ($r->path === '/verwaltung/benutzer') return $get ? $this->userList() : $this->userCreate($r, $admin);
        if ($r->path === '/verwaltung/benutzer/neu' && $get) return $this->userNew();
        if (preg_match('#^/verwaltung/benutzer/(\d{1,9})(/link|/loeschen)?$#D', $r->path, $m)) {
            $id = (int)$m[1];
            $action = $m[2] ?? '';
            if ($get && $action === '') return $this->userEdit($id);
            if (!$get && $action === '') return $this->userSave($r, $id, $admin);
            if (!$get && $action === '/link') return $this->userLink($id, $admin);
            if (!$get && $action === '/loeschen') return $this->userDelete($id, $admin);
        }
        if ($r->path === '/verwaltung/zugang') return $get ? $this->accessPage() : $this->accessSave($r, $admin);
        if ($r->path === '/verwaltung/protokoll' && $get) return $this->protocol();
        throw new Problem('Seite nicht gefunden.', 404);
    }

    private function userList(?string $notice = null): Response {
        $rows = '';
        foreach ($this->users->all() as $u) {
            $roles = $u['admin'] ? ['Basis-Admin'] : [];
            foreach ($u['access'] as $m => $a) $roles[] = $this->modules->name($m) . ($a['admin'] ? ' (Admin)' : '');
            $rows .= '<tr><td><a href="/verwaltung/benutzer/' . $u['id'] . '">' . V::h($u['login']) . '</a></td><td>' . V::h($u['display']) . '</td><td>' . V::h(implode(', ', $roles) ?: '–')
                . '</td><td>' . ($u['active'] ? ($u['password'] ? ($u['otp'] ? 'Passwort + App' : 'Passwort') : 'Einrichtung offen') : 'gesperrt') . '</td></tr>';
        }
        return Response::html(V::page('Benutzer', V::notice($notice) . '<p><a class="button" href="/verwaltung/benutzer/neu">Benutzer anlegen</a></p>'
            . '<table><tr><th>Anmeldename</th><th>Name</th><th>Zugang</th><th>Anmeldung</th></tr>' . $rows . '</table>', $this->nav(), $this->config->practice));
    }

    private function userNew(?string $error = null): Response {
        $body = V::error($error) . '<form method="post" action="/verwaltung/benutzer" class="box">' . V::csrf($this->session['csrf'])
            . V::input('login', 'Anmeldename', 'text', $this->req->field('login', 64), 'autocapitalize="none" required')
            . V::input('display', 'Name', 'text', $this->req->field('display', 80))
            . V::checkbox('admin', '1', 'Basis-Admin (Benutzer, Firewall, Updates, Sicherung; braucht Authenticator-App)', $this->req->field('admin') === '1')
            . '<p>Das Passwort legt die Person selbst fest: Nach dem Anlegen erscheint ein einmaliger Einrichtungslink. Zugang zu Modulen danach beim Benutzer einstellen.</p>'
            . '<button>Anlegen</button></form>';
        return Response::html(V::page('Benutzer anlegen', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function userCreate(Request $r, string $admin): Response {
        try {
            $id = $this->users->create($r->field('login', 64), $r->field('display', 80), $r->field('admin') === '1');
        } catch (Problem $e) {
            return $this->userNew($e->getMessage());
        }
        $user = $this->users->find($id);
        $this->audit->write($admin, 'benutzer-angelegt', $user['login'] . ((int)$user['admin'] ? ' basis-admin' : ''));
        return $this->userEdit($id, null, $this->users->setupToken($id));
    }

    private function userEdit(int $id, ?string $error = null, ?string $link = null): Response {
        $user = $this->users->find($id);
        if (!$user) throw new Problem('Benutzer nicht gefunden.', 404);
        $access = $this->users->access($id);
        $self = $id === (int)$this->session['user_id'];
        $modules = '';
        foreach ($this->modules->all() as $mid => $m) {
            $a = $access[$mid] ?? ['admin' => false, 'rights' => []];
            $rights = '';
            foreach ($this->modules->rights($mid) as $right) $rights .= V::checkbox('recht[]', $mid . ':' . $right['id'], $right['label'], in_array($right['id'], $a['rights'], true));
            $modules .= '<fieldset><legend>' . V::h($this->modules->name($mid)) . '</legend>' . V::checkbox('modul_admin[]', $mid, 'Admin dieses Moduls (braucht Authenticator-App)', $a['admin']) . $rights . '</fieldset>';
        }
        $url = 'https://' . $this->config->hostnames[0] . '/einrichten?t=';
        $body = V::error($error)
            . ($link ? '<div class="notice"><p><strong>Einmaliger Einrichtungslink</strong> (3 Tage gültig, wird nur jetzt angezeigt). An ' . V::h($user['login'])
                . ' weitergeben, nicht per unverschlüsselter E-Mail:</p><p><code class="secret">' . V::h($url . $link) . '</code></p></div>' : '')
            . '<form method="post" action="/verwaltung/benutzer/' . $id . '" class="box">' . V::csrf($this->session['csrf'])
            . '<p>Anmeldename: <strong>' . V::h($user['login']) . '</strong></p>'
            . V::input('display', 'Name', 'text', $user['display'])
            . V::checkbox('admin', '1', 'Basis-Admin', (int)$user['admin'] === 1) . V::checkbox('active', '1', 'Konto aktiv', (int)$user['active'] === 1)
            . ($modules !== '' ? '<h2>Zugang zu Modulen</h2>' . $modules : '<p>Es sind noch keine Module installiert.</p>')
            . '<button>Speichern</button></form>'
            . (!$self && !$this->users->needsOtp($user) ? '<form method="post" action="/verwaltung/benutzer/' . $id . '/link" class="box">' . V::csrf($this->session['csrf'])
                . '<p>Passwort vergessen? Ein neuer Einrichtungslink löscht das bisherige Passwort und meldet alle Geräte ab.</p><button>Neuen Einrichtungslink erzeugen</button></form>'
                : (!$self ? '<p>Admin-Konten (Passwort oder Authenticator-App verloren) setzt man nur an der Server-Konsole zurück: <code>sudo kienzle-admin zuruecksetzen ' . V::h($user['login']) . '</code></p>' : ''))
            . (!$self ? '<form method="post" action="/verwaltung/benutzer/' . $id . '/loeschen" class="box">' . V::csrf($this->session['csrf']) . '<button class="danger">Benutzer löschen</button></form>' : '');
        return Response::html(V::page('Benutzer ' . $user['login'], $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function userSave(Request $r, int $id, string $admin): Response {
        $user = $this->users->find($id);
        if (!$user) throw new Problem('Benutzer nicht gefunden.', 404);
        $moduleAdmin = $r->list('modul_admin');
        $rights = [];
        foreach ($r->list('recht') as $entry) {
            [$m, $right] = array_pad(explode(':', $entry, 2), 2, '');
            $rights[$m][] = $right;
        }
        try {
            $this->db->transaction(function () use ($id, $r, $moduleAdmin, $rights): void {
                $self = $id === (int)$this->session['user_id'];
                $this->users->update($id, $r->field('display', 80), $self || $r->field('admin') === '1', $self || $r->field('active') === '1');
                foreach (array_keys($this->modules->all()) as $m) $this->users->setAccess($id, $m, in_array($m, $moduleAdmin, true), $rights[$m] ?? []);
                foreach (array_diff(array_keys($rights), array_keys($this->modules->all())) as $unknown) throw new Problem('Unbekanntes Modul.', 422);
            });
        } catch (Problem $e) {
            return $this->userEdit($id, $e->getMessage());
        }
        $after = $this->users->find($id);
        $summary = [];
        if ((int)$after['admin'] === 1) $summary[] = 'basis-admin';
        foreach ($this->users->access($id) as $m => $a) $summary[] = $m . ($a['admin'] ? ':admin' : '') . ($a['rights'] ? ':' . implode('/', $a['rights']) : '');
        $this->audit->write($admin, 'benutzer-geaendert', $after['login'] . ' ' . ((int)$after['active'] ? '' : 'gesperrt ') . implode(',', $summary));
        // Neue Admin-Rolle ohne OTP: offene Sitzungen beenden, damit die nächste Anmeldung die App-Einrichtung verlangt.
        if ($this->users->needsOtp($after) && !$this->users->hasOtp($after) && $id !== (int)$this->session['user_id']) $this->db->query('DELETE FROM sessions WHERE user_id=?', [$id]);
        return $this->userList('Gespeichert: ' . $after['login'] . '.');
    }

    private function userLink(int $id, string $admin): Response {
        $user = $this->users->find($id);
        if (!$user) throw new Problem('Benutzer nicht gefunden.', 404);
        if ($id === (int)$this->session['user_id']) throw new Problem('Das eigene Passwort bitte unter „Mein Konto“ ändern.', 409);
        if ($this->users->needsOtp($user)) throw new Problem('Admin-Konten werden nur an der Server-Konsole zurückgesetzt.', 403);
        $this->users->resetCredentials($id);
        $this->audit->write($admin, 'einrichtungslink-erzeugt', $user['login']);
        return $this->userEdit($id, null, $this->users->setupToken($id));
    }

    private function userDelete(int $id, string $admin): Response {
        $user = $this->users->find($id);
        if (!$user) throw new Problem('Benutzer nicht gefunden.', 404);
        if ($id === (int)$this->session['user_id']) throw new Problem('Das eigene Konto kann nicht gelöscht werden.', 409);
        $this->users->delete($id);
        $this->audit->write($admin, 'benutzer-geloescht', $user['login']);
        return $this->userList('Gelöscht: ' . $user['login'] . '.');
    }

    private function accessPage(?string $error = null, ?string $notice = null): Response {
        $body = V::error($error) . V::notice($notice) . '<form method="post" action="/verwaltung/zugang" class="box">' . V::csrf($this->session['csrf'])
            . V::input('idle', 'Abmelden nach Minuten ohne Aktion (5 bis 263520)', 'number', (string)intdiv($this->auth->idleSeconds(), 60), 'min="5" max="263520" required')
            . V::input('max', 'Höchstdauer einer Sitzung in Stunden (1 bis 4392)', 'number', (string)intdiv($this->auth->maxSeconds(), 3600), 'min="1" max="4392" required')
            . '<p>Gemerkte Geräte bleiben bis zu 30 Tage ohne Nutzung und höchstens 6 Monate angemeldet, nur aus dem Netz der Anmeldung.</p><button>Speichern</button></form>';
        return Response::html(V::page('Zugang', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function accessSave(Request $r, string $admin): Response {
        try {
            $this->auth->saveTimes((int)$r->field('idle', 10), (int)$r->field('max', 10));
        } catch (Problem $e) {
            return $this->accessPage($e->getMessage());
        }
        $this->audit->write($admin, 'zugang-gespeichert', 'abmeldezeiten');
        return $this->accessPage(null, 'Gespeichert.');
    }

    private function protocol(): Response {
        $lines = array_map(fn($l) => '<li><code>' . V::h($l) . '</code></li>', $this->audit->recent(200));
        return Response::html(V::page('Protokoll', '<p>Anmeldungen und Änderungen, neueste zuerst. Ohne Passwörter, Codes und Patientendaten.</p><ol class="log">' . implode('', $lines) . '</ol>',
            $this->nav(), $this->config->practice));
    }

    // ---- Hilfen -------------------------------------------------------------------------------------------------
    private function nav(): ?array {
        if (($this->session['stage'] ?? '') !== 'full') return null;
        return ['login' => $this->session['login'], 'admin' => (int)$this->session['user_admin'] === 1, 'csrf' => $this->session['csrf']];
    }

    private function issuer(): string { return $this->config->practice !== '' ? 'Kienzle-Suite ' . $this->config->practice : 'Kienzle-Suite'; }

    /** Nur eigene, einfache Pfade als Weiterleitungsziel (keine fremden Adressen, kein „//“). */
    private function next(string $target): string {
        return preg_match('#^/(?!/)[A-Za-z0-9/_.-]{0,200}$#D', $target) && !str_contains($target, '..') && !str_starts_with($target, '/_') ? $target : '/';
    }

    /** Ziel nach der Code-Eingabe bzw. OTP-Einrichtung (beim Passwortschritt gemerkt). */
    private function takeNext(?string $token): string {
        if (!$token) return '/';
        $key = 'next:' . hash('sha256', $token);
        $next = $this->db->setting($key) ?? '/';
        $this->db->query('DELETE FROM settings WHERE key=?', [$key]);
        return $this->next($next);
    }

    private function checkOrigin(Request $r): void {
        $origin = $r->header('origin');
        $site = $r->header('sec-fetch-site');
        if ($origin !== '') {
            if (!preg_match('#^https://([^/]+)$#D', $origin, $m) || !$this->config->ownHost($m[1])) throw new Problem('Anfrage von einer fremden Seite abgelehnt.', 403);
        } elseif ($site !== 'same-origin') {
            throw new Problem('Anfrage ohne Herkunftsangabe abgelehnt.', 403);
        }
    }

    private function checkCsrf(Request $r): void {
        $sent = $r->field('csrf', 100);
        if ($sent === '') $sent = $r->header('x-kienzle-csrf');
        if (!$this->session || !hash_equals((string)$this->session['csrf'], $sent)) throw new Problem('Die Seite ist veraltet. Bitte neu laden und noch einmal versuchen.', 403);
    }

    private static function jsonBody(Request $r): array {
        $raw = $r->post['json'] ?? '';
        $data = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
        if (!is_array($data)) throw new Problem('Ungültige Anfrage.', 400);
        return $data;
    }

    private static function secure(Response $response): Response {
        $response->with('Cache-Control', 'no-store')
            ->with('X-Content-Type-Options', 'nosniff')
            ->with('Referrer-Policy', 'no-referrer')
            ->with('X-Frame-Options', 'DENY')
            ->with('Cross-Origin-Opener-Policy', 'same-origin')
            ->with('Cross-Origin-Resource-Policy', 'same-origin')
            ->with('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), publickey-credentials-get=(self), publickey-credentials-create=(self)');
        if ($response->header('Content-Type') !== null && str_starts_with((string)$response->header('Content-Type'), 'text/html')) {
            $response->with('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        }
        return $response;
    }
}
