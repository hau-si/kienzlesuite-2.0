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
        private Modules $modules, private AuditLog $audit, ?callable $qr = null, private ?Firewall $firewall = null, private ?Updates $updates = null,
        private ?Connections $connections = null, private ?Backup $backup = null, private ?Overview $overview = null, private ?Printers $printers = null, private ?Oidc $oidc = null, private ?Server $server = null,
        private ?ModuleAdmin $store = null,
    ) {
        $this->qr = $qr ?? static function (string $uri): ?string {
            if (!is_executable('/usr/bin/qrencode')) return null;
            try { $r = Command::run(['/usr/bin/qrencode', '-t', 'SVG', '-l', 'M', '-m', '2', '-o', '-', $uri], '', 5, 262144); } catch (Problem) { return null; }
            return $r['code'] === 0 && str_starts_with(ltrim($r['output']), '<?xml') ? $r['output'] : null;
        };
    }

    public function handle(Request $req): Response {
        $this->req = $req;
        V::$starter = $this->modules->starterPath();
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
        // OpenID Connect: Abfragen der Dienste selbst (ohne Cookie, ohne Origin des Browsers).
        if ($this->oidc && ($r->path === '/.well-known/openid-configuration' || in_array($r->path, ['/oidc/jwks', '/oidc/token', '/oidc/userinfo'], true))) return $this->oidcApi($r);
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
            case '/hilfe/arbeitsplatz': if ($get) return $this->workstationHelp(); break;
            case '/uhrzeit/korrigieren': if (!$get && $this->session) return $this->clockFix($r); break;
            case '/oidc/authorize': if ($get && $this->oidc && $stage !== 'full') return Response::redirect('/anmelden?weiter=/oidc/fortsetzen/' . $this->oidc->park($r->query)); break;
            case '/oidc/abmelden': if ($this->oidc && $stage !== 'full') return Response::redirect($this->oidc->logoutTarget($r->get('client_id', 100) ?: $this->oidc->clientFromIdToken($r->get('id_token_hint', 4000)), $r->get('post_logout_redirect_uri', 300)) ?? '/anmelden'); break;
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
        if ($this->oidc && $get && $r->path === '/oidc/authorize') return Response::redirect($this->oidc->authorize($r->query, $this->session));
        if ($this->oidc && $get && preg_match('#^/oidc/fortsetzen/([0-9a-f]{32})$#D', $r->path, $m)) return Response::redirect($this->oidc->authorize($this->oidc->unpark($m[1]), $this->session));
        if ($this->oidc && $r->path === '/oidc/abmelden') return $get ? $this->oidcLogoutPage($r) : $this->oidcLogout($r);
        if (str_starts_with($r->path, '/verwaltung/')) return $this->admin($r, $get);
        throw new Problem('Seite nicht gefunden.', 404);
    }

    // ---- Servername ---------------------------------------------------------------------------------------------
    private function srv(): Server { return $this->server ?? new Server($this->db, $this->config); }

    public static string $caFile = '/etc/kienzle/ca/ca.crt';

    /** Anleitung für die Arbeitsplätze: Zertifizierungsstelle und Servername. Ohne Anmeldung erreichbar (vorher vertraut der Browser der Suite noch nicht). */
    private function workstationHelp(): Response {
        $pem = @file_get_contents(self::$caFile);
        $fp = '';
        if (is_string($pem) && preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m)) {
            $der = (string)base64_decode((string)preg_replace('/\s+/', '', $m[1]));
            $fp = implode(':', str_split(strtoupper(hash('sha256', $der)), 2));
        }
        $ips = array_values(array_filter($this->config->hostnames, fn($n) => filter_var($n, FILTER_VALIDATE_IP)));
        $name = array_values(array_filter($this->config->hostnames, fn($n) => !filter_var($n, FILTER_VALIDATE_IP)))[0] ?? 'kienzle.praxis.internal';
        $ip = $ips[0] ?? '192.0.2.10';
        $body = '<p class="lead">Zwei Dinge braucht jeder PC der Praxis einmal: Er muss der Suite vertrauen (Zertifikat) und ihren Namen kennen.</p>'
            . '<h2 id="zertifikat">1. Der Suite vertrauen (Zertifikat)</h2>'
            . '<p>Ohne diesen Schritt zeigt der Browser „Nicht sicher“. Die Datei ist kein Programm, sondern der Ausweis der Suite.</p>'
            . '<p class="row-buttons"><a class="button" href="/ca.crt">Zertifikat herunterladen</a></p>'
            . ($fp !== '' ? '<p class="muted">Zum Vergleich – so muss der Fingerabdruck (SHA-256) lauten:<br><code>' . V::h($fp) . '</code></p>' : '')
            . '<details class="how" open><summary>Windows</summary><ol class="steps"><li>Die heruntergeladene Datei <strong>kienzle-suite-ca.crt</strong> doppelklicken.</li>'
            . '<li>„Zertifikat installieren …“ → <strong>Lokaler Computer</strong> → Weiter (Admin-Bestätigung).</li>'
            . '<li>„Alle Zertifikate in folgendem Speicher speichern“ → Durchsuchen → <strong>Vertrauenswürdige Stammzertifizierungsstellen</strong> → OK → Weiter → Fertig stellen.</li>'
            . '<li>Browser ganz schließen und neu öffnen.</li></ol>'
            . '<p class="muted">Schneller für IT: PowerShell als Administrator: <code>Import-Certificate -FilePath "$HOME\Downloads\kienzle-suite-ca.crt" -CertStoreLocation Cert:\LocalMachine\Root</code></p></details>'
            . '<details class="how"><summary>Mac</summary><ol class="steps"><li>Datei doppelklicken – die Schlüsselbundverwaltung öffnet sich, Schlüsselbund <strong>System</strong> wählen.</li>'
            . '<li>Das Zertifikat „Kienzle-Suite …“ doppelklicken → Vertrauen → „Bei Verwendung dieses Zertifikats“: <strong>Immer vertrauen</strong>.</li></ol></details>'
            . '<details class="how"><summary>iPhone und iPad</summary><ol class="steps"><li>Diese Seite in Safari öffnen und das Zertifikat laden → „Zulassen“.</li>'
            . '<li>Einstellungen → Profil geladen → Installieren.</li><li>Einstellungen → Allgemein → Info → Zertifikatsvertrauenseinstellungen → „Kienzle-Suite …“ einschalten.</li></ol></details>'
            . '<details class="how"><summary>Android</summary><ol class="steps"><li>Zertifikat laden.</li><li>Einstellungen → Sicherheit → Weitere Einstellungen → Verschlüsselung und Anmeldedaten → '
            . 'Zertifikat installieren → <strong>CA-Zertifikat</strong> (Namen der Menüs je nach Hersteller etwas anders).</li></ol></details>'
            . '<details class="how"><summary>Firefox (eigener Zertifikatsspeicher)</summary><ol class="steps"><li>Einstellungen → Datenschutz &amp; Sicherheit → Zertifikate → Zertifikate anzeigen → '
            . 'Zertifizierungsstellen → Importieren → „Dieser CA vertrauen, um Websites zu identifizieren“.</li></ol></details>'
            . '<h2 id="name">2. Den Namen der Suite bekannt machen</h2>'
            . '<p>Die Suite heißt <strong>' . V::h($name) . '</strong> und hat die Adresse <strong>' . V::h($ip) . '</strong>. Damit der Name funktioniert, gibt es zwei Wege:</p>'
            . '<details class="how" open><summary>Einmal im Router (empfohlen, gilt für alle PCs)</summary><p>Viele Router kennen Geräte im Netz automatisch unter ihrem Rechnernamen, '
            . 'eine FRITZ!Box z. B. als <code>rechnername.fritz.box</code>. Dann unter „Server und Name“ genau diesen Namen mit der Endung „.fritz.box“ wählen. '
            . 'Andere Router oder ein eigener DNS-Server: den Namen <code>' . V::h($name) . '</code> mit der Adresse <code>' . V::h($ip) . '</code> eintragen (meist die IT).</p></details>'
            . '<details class="how"><summary>Oder auf jedem PC in der Datei hosts</summary><ol class="steps">'
            . '<li>Windows: Startmenü → „Editor“ mit der rechten Maustaste → <strong>Als Administrator ausführen</strong>.</li>'
            . '<li>Datei → Öffnen → <code>C:\Windows\System32\drivers\etc\hosts</code> (unten „Alle Dateien“ wählen).</li>'
            . '<li>Am Ende diese Zeile einfügen und speichern:<br><code>' . V::h($ip . '   ' . $name) . '</code></li>'
            . '<li>Mac: im Terminal <code>sudo nano /etc/hosts</code>, dieselbe Zeile ergänzen, speichern.</li></ol></details>'
            . '<p>Danach die Suite unter <a href="https://' . V::h($name) . '/">https://' . V::h($name) . '/</a> öffnen.</p>';
        return Response::html(V::page('Arbeitsplätze einrichten', $body, $this->nav(), $this->config->practice, 'Einrichten'));
    }

    /** Endungen für den Servernamen, die die Zertifizierungsstelle erlaubt (verständlich beschriftet). */
    private const SUFFIXES = ['.praxis.internal' => '.praxis.internal (empfohlen)', '.fritz.box' => '.fritz.box (FRITZ!Box)', '.local' => '.local', '.lan' => '.lan'];

    private function serverPage(?string $error = null): Response {
        $st = $this->srv()->status();
        $addr = $this->address();
        $ips = array_values(array_filter($st['names'], fn($n) => filter_var($n, FILTER_VALIDATE_IP)));
        $dns = array_values(array_filter($st['names'], fn($n) => !filter_var($n, FILTER_VALIDATE_IP)));
        // Erster Name mit bekannter Endung → Feld „Name“ und Auswahl der Endung; alles andere steht unter „Weitere Namen“.
        [$short, $suffix, $extra] = ['kienzle', '.praxis.internal', []];
        foreach ($dns as $i => $n) {
            foreach (self::SUFFIXES as $suf => $_) {
                if (str_ends_with($n, $suf) && strlen($n) > strlen($suf)) { [$short, $suffix] = [substr($n, 0, -strlen($suf)), $suf]; unset($dns[$i]); break 2; }
            }
        }
        $extra = array_values($dns);
        if (!$this->srv()->named() && $extra) $short = explode('.', $extra[0])[0];
        $options = '';
        foreach (self::SUFFIXES as $suf => $label) $options .= '<option value="' . V::h($suf) . '"' . ($suf === $suffix ? ' selected' : '') . '>' . V::h($label) . '</option>';
        $address = 'https://' . $short . $suffix . '/';
        $body = V::error($error) . ($st['last'] ? '<p class="' . (($st['last']['ok'] ?? false) ? 'notice' : 'error') . '">' . V::h((string)($st['last']['meldung'] ?? '')) . '</p>' : '')
            . ($st['pending'] ? '<p class="notice">Wird übernommen …</p>' : '')
            . '<h2>Name der Praxis und des Servers</h2>'
            . '<form method="post" action="/verwaltung/server">' . $this->hidden('speichern')
            . V::input('praxis', 'Name der Praxis', 'text', $st['practice'], 'maxlength="60" placeholder="Praxis Dr. Beispiel"')
            . '<label>Name des Servers<span class="name-row">'
            . '<input type="text" name="kurzname" value="' . V::h($short) . '" required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9-]*" aria-label="Name">'
            . '<select name="endung" aria-label="Endung">' . $options . '</select></span></label>'
            . '<p class="muted">Unter diesem Namen öffnen die Arbeitsplätze die Suite, z. B. <strong>' . V::h($address) . '</strong>. '
            . 'Nur Buchstaben, Ziffern und Bindestrich. Wer nicht sicher ist: „kienzle“ und „.praxis.internal“ lassen.</p>'
            . ($ips ? '<p class="muted">Zusätzlich bleibt die Suite über ' . V::h(implode(', ', $ips)) . ' erreichbar.</p>' : '')
            . '<details class="how"><summary>Weitere Namen und Fachleute-Einstellungen</summary>'
            . '<label>Weitere Namen oder Adressen (einer je Zeile)<textarea name="weitere" rows="3">' . V::h(implode("\n", $extra)) . '</textarea></label>'
            . '<p class="muted">Erlaubt sind interne Namen (.internal, .local, .lan, .home.arpa, .fritz.box …) und private IP-Adressen. '
            . 'Für andere Namen braucht es eine neue Zertifizierungsstelle – dann müssen alle Arbeitsplätze sie neu einspielen.</p>'
            . V::checkbox('neue_ca', '1', 'Falls nötig: neue Zertifizierungsstelle erstellen', false)
            . V::checkbox('ohne_ip', '1', 'Nicht mehr über die IP-Adresse erreichbar', false) . '</details>'
            . '<button' . ($st['pending'] ? ' disabled' : '') . '>Speichern</button></form>'
            . '<h2>Anschrift der Praxis</h2><p class="muted">Steht auf Rechnungen und Belegen. Die Module (z. B. die Kasse) übernehmen sie von hier.</p>'
            . '<form method="post" action="/verwaltung/server">' . $this->hidden('anschrift')
            . V::input('street', 'Straße und Hausnummer', 'text', $addr['street'], 'maxlength="80" autocomplete="street-address"')
            . V::input('city', 'PLZ und Ort', 'text', $addr['city'], 'maxlength="80"')
            . V::input('contact', 'Kontakt auf dem Beleg (optional, z. B. Telefon)', 'text', $addr['contact'], 'maxlength="120"')
            . '<button>Anschrift speichern</button></form>'
            . '<h2>Danach</h2><ol class="steps"><li>Den Namen im Router eintragen – oder auf jedem PC in der Datei hosts: '
            . '<a href="/hilfe/arbeitsplatz#name">Anleitung</a>.</li><li>Die Suite unter <strong>' . V::h($address) . '</strong> öffnen und dort neu anmelden.</li>'
            . '<li>Falls schon Passkeys eingerichtet sind: unter „Mein Konto“ neu anlegen (sie gelten für den alten Namen).</li></ol>'
            . '<p class="muted">Diese drei Schritte bestätigst du von Hand unter <a href="/verwaltung/einrichtung">Einrichtung</a>; erst dann gilt „Servername festgelegt“ als erledigt.</p>';
        $response = Response::html(V::page('Server und Name', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
        return $st['pending'] ? $response->with('Refresh', '3') : $response;
    }

    /** Anschrift der Praxis (Straße, PLZ/Ort, Kontakt); der Name steht in der Serverkonfiguration. */
    private function address(): array {
        $d = json_decode((string)$this->db->setting('praxis.anschrift'), true);
        $out = [];
        foreach (['street', 'city', 'contact'] as $k) $out[$k] = is_array($d) && is_string($d[$k] ?? null) ? $d[$k] : '';
        return $out;
    }

    private function saveAddress(Request $r, string $admin): Response {
        $in = [];
        try {
            foreach (['street' => 80, 'city' => 80, 'contact' => 120] as $k => $max) {
                $v = trim($r->field($k, $max + 1));
                if (mb_strlen($v) > $max || preg_match('/[\x00-\x1f\x7f<>]/', $v)) throw new Problem('Anschrift: Angaben zu lang oder mit unerlaubten Zeichen.', 422);
                $in[$k] = $v;
            }
        } catch (Problem $e) {
            return $this->serverPage($e->getMessage());
        }
        $this->db->store('praxis.anschrift', json_encode($in, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->audit->write($admin, 'praxisanschrift', '');
        return Response::redirect('/verwaltung/server');
    }

    private function serverSave(Request $r, string $admin): Response {
        if ($r->field('aktion', 20) === 'anschrift') return $this->saveAddress($r, $admin);
        $short = strtolower(trim($r->field('kurzname', 60)));
        $suffix = $r->field('endung', 30);
        $names = '';
        try {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/D', $short)) throw new Problem('Name des Servers: nur Buchstaben, Ziffern und Bindestrich, z. B. kienzle.', 422);
            if (!isset(self::SUFFIXES[$suffix])) throw new Problem('Bitte eine Endung wählen.', 422);
            $ips = $r->field('ohne_ip') === '1' ? [] : array_filter($this->config->hostnames, fn($n) => filter_var($n, FILTER_VALIDATE_IP));
            $names = implode("\n", array_merge([$short . $suffix], preg_split('/\R/', trim($r->field('weitere', 3000))) ?: [], $ips));
            $this->srv()->save($names, $r->field('praxis', 200), $r->field('neue_ca') === '1');
        } catch (Problem $e) {
            if (in_array($e->http, [409, 422, 503], true)) return $this->serverPage($e->getMessage());
            throw $e;
        }
        $this->audit->write($admin, 'servername', strtolower(implode(' ', array_slice(preg_split('/[\s,;]+/', trim($names)) ?: [], 0, 10))));
        return Response::redirect('/verwaltung/server');
    }

    // ---- OpenID Connect ------------------------------------------------------------------------------------------
    private function oidcApi(Request $r): Response {
        $json = fn(int $status, array $data) => Response::json($data, $status)->with('Cache-Control', 'no-store')->with('Pragma', 'no-cache');
        if ($r->path === '/.well-known/openid-configuration') return Response::json($this->oidc->discovery())->with('Cache-Control', 'max-age=300');
        if ($r->path === '/oidc/jwks') return Response::json($this->oidc->jwks())->with('Cache-Control', 'max-age=300');
        if ($r->path === '/oidc/token') {
            if ($r->method !== 'POST') return $json(405, ['error' => 'invalid_request']);
            [$status, $data] = $this->oidc->token($r->post, $r->header('authorization'));
            if ($status === 200) $this->audit->write('oidc', 'oidc-anmeldung', (string)($r->post['client_id'] ?? 'basic'));
            return $json($status, $data);
        }
        [$status, $data] = $this->oidc->userinfo($r->header('authorization'));
        $resp = $json($status, $data);
        return $status === 401 ? $resp->with('WWW-Authenticate', 'Bearer error="invalid_token"') : $resp;
    }

    private function oidcLogoutPage(Request $r): Response {
        $target = $this->oidc->logoutTarget($r->get('client_id', 100) ?: $this->oidc->clientFromIdToken($r->get('id_token_hint', 4000)), $r->get('post_logout_redirect_uri', 300));
        $body = '<p>Bei der Kienzle-Suite abmelden? Damit endet die Anmeldung für alle Module und Dienste auf diesem Gerät.</p>'
            . '<form method="post" action="/oidc/abmelden" class="box">' . V::csrf($this->session['csrf'])
            . '<input type="hidden" name="ziel" value="' . V::h($target ?? '') . '"><input type="hidden" name="state" value="' . V::h($r->get('state', 500)) . '">'
            . '<button>Abmelden</button></form>';
        return Response::html(V::page('Abmelden', $body, $this->nav(), $this->config->practice));
    }

    private function oidcLogout(Request $r): Response {
        $target = $r->field('ziel', 300);
        $ok = false;
        foreach ($this->oidc->clients() as $c) if (in_array($target, $c['logout_uris'], true)) $ok = true;
        $this->audit->write((string)$this->session['login'], 'abgemeldet', 'oidc');
        $this->auth->logout($this->token);
        $state = $r->field('state', 500);
        return Response::redirect($ok ? $target . ($state !== '' ? (str_contains($target, '?') ? '&' : '?') . 'state=' . rawurlencode($state) : '') : '/anmelden')
            ->with('Set-Cookie', Auth::clearCookie());
    }

    private function sso(): Oidc {
        if (!$this->oidc) throw new Problem('Anmeldung für Dienste ist auf diesem Server nicht verfügbar.', 503);
        return $this->oidc;
    }

    private function ssoPage(?string $error = null, ?array $created = null): Response {
        $o = $this->sso();
        $body = V::error($error);
        if ($created) {
            $body .= '<div class="box wide notice"><p><strong>Dienst „' . V::h($created['name']) . '“ angelegt.</strong> Diese Angaben im Dienst eintragen:</p><table>'
                . '<tr><td>Anbieter (Issuer)</td><td><code>' . V::h($o->issuer()) . '</code></td></tr>'
                . '<tr><td>Discovery</td><td><code>' . V::h($o->issuer()) . '/.well-known/openid-configuration</code></td></tr>'
                . '<tr><td>Client-ID</td><td><code>' . V::h($created['id']) . '</code></td></tr>'
                . ($created['secret'] !== '' ? '<tr><td>Client-Geheimnis</td><td><code>' . V::h($created['secret']) . '</code><br><small>Wird nur jetzt angezeigt.</small></td></tr>' : '')
                . '</table></div>';
        }
        $body .= '<p>Dienste mit eigener Anmeldung (z. B. Oberfläche der Telefonanlage, NAS) können Benutzer über die Suite anmelden '
            . '(OpenID Connect). Es gelten dieselben Konten, Authenticator-Apps und Passkeys. Die Arbeitsplätze müssen der '
            . '<a href="/ca.crt">Zertifizierungsstelle</a> vertrauen, der Dienst ebenfalls.</p>'
            . '<p>Anbieter: <code>' . V::h($o->issuer()) . '</code></p>';
        $rows = '';
        foreach ($o->clients() as $c) {
            $rows .= '<tr><td><strong>' . V::h($c['name']) . '</strong><br><code>' . V::h($c['id']) . '</code></td><td>' . implode('<br>', array_map(fn($u) => V::h($u), $c['redirect_uris']))
                . '</td><td>' . ($c['admin_only'] ? 'nur Admins' : ($c['module'] !== '' ? 'Zugang zu ' . V::h($this->modules->name($c['module'])) : 'alle Benutzer')) . ($c['public'] ? '<br>öffentlich (PKCE)' : '')
                . '</td><td>' . (!$c['public'] ? '<form method="post" action="/verwaltung/sso" class="inline">' . $this->hidden('geheimnis', ['id' => $c['id']]) . '<button>Neues Geheimnis</button></form> ' : '')
                . '<form method="post" action="/verwaltung/sso" class="inline">' . $this->hidden('loeschen', ['id' => $c['id']]) . '<button class="danger">Entfernen</button></form></td></tr>';
        }
        $body .= '<h2>Dienste</h2>' . ($rows !== '' ? '<table><tr><th>Dienst</th><th>Weiterleitung</th><th>Wer darf</th><th></th></tr>' . $rows . '</table>' : '<p>Noch keine.</p>');
        $mods = '<option value="">alle aktiven Benutzer</option>';
        foreach ($this->modules->all() as $id => $m) $mods .= '<option value="' . V::h($id) . '">nur mit Zugang zu ' . V::h($this->modules->name($id)) . '</option>';
        $body .= '<h2>Dienst hinzufügen</h2><form method="post" action="/verwaltung/sso" class="box wide">' . $this->hidden('anlegen')
            . V::input('name', 'Name', 'text', '', 'required maxlength="60"')
            . '<label>Weiterleitungsadressen (eine je Zeile, https)<textarea name="redirect_uris" rows="3" required placeholder="https://telefon.example.org/oauth/callback"></textarea></label>'
            . '<label>Adressen nach dem Abmelden (optional)<textarea name="logout_uris" rows="2"></textarea></label>'
            . '<label>Wer darf sich anmelden<select name="modul">' . $mods . '</select></label>'
            . V::checkbox('admin_only', '1', 'nur Basis-Admins', false)
            . V::checkbox('public', '1', 'öffentlicher Dienst ohne Geheimnis (App im Browser; nur mit PKCE)', false)
            . '<button>Anlegen</button></form>';
        return Response::html(V::page('Anmeldung für Dienste', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function ssoAction(Request $r, string $admin): Response {
        $o = $this->sso();
        $a = $r->field('aktion', 20);
        try {
            if ($a === 'anlegen') {
                [$id, $secret] = $o->createClient($r->field('name', 100), $r->field('redirect_uris', 4000), $r->field('logout_uris', 4000), $r->field('public') === '1',
                    $r->field('admin_only') === '1', $r->field('modul', 40));
                $this->audit->write($admin, 'sso-dienst-angelegt', $id);
                return $this->ssoPage(null, ['id' => $id, 'secret' => $secret, 'name' => $r->field('name', 100)])->with('Cache-Control', 'no-store');
            }
            if ($a === 'geheimnis') {
                $c = $o->client($r->field('id', 40)) ?? throw new Problem('Dienst nicht gefunden.', 404);
                $secret = $o->newSecret($c['id']);
                $this->audit->write($admin, 'sso-geheimnis-neu', $c['id']);
                return $this->ssoPage(null, ['id' => $c['id'], 'secret' => $secret, 'name' => $c['name']])->with('Cache-Control', 'no-store');
            }
            if ($a === 'loeschen') {
                $o->deleteClient($r->field('id', 40));
                $this->audit->write($admin, 'sso-dienst-entfernt', $r->field('id', 40));
                return Response::redirect('/verwaltung/sso');
            }
            throw new Problem('Unbekannte Aktion.', 422);
        } catch (Problem $e) {
            if (in_array($e->http, [404, 409, 422], true)) return $this->ssoPage($e->getMessage());
            throw $e;
        }
    }

    // ---- Prüfung für nginx --------------------------------------------------------------------------------------
    /** auth_request: 204 mit Benutzer und Rechten für das Modul, 401 ohne Anmeldung, 403 ohne Zugang. */
    private function forward(Request $r, string $module): Response {
        if (!$this->modules->has($module)) return new Response(404);
        $session = $this->auth->session($r->cookie(Auth::COOKIE), 'full');
        if (!$session) return new Response(401);
        $this->claimFirstAdmin((int)$session['user_id']);
        $access = $this->users->access((int)$session['user_id'])[$module] ?? null;
        if (!$access) return new Response(403);
        return (new Response(204))
            ->with('X-Kienzle-Benutzer', $session['login'])
            ->with('X-Kienzle-Name', rawurlencode($session['display'] !== '' ? $session['display'] : $session['login']))
            ->with('X-Kienzle-Rolle', $access['admin'] ? 'admin' : 'mitarbeiter')
            ->with('X-Kienzle-Rechte', implode(',', $access['rights']))
            ->with('X-Kienzle-Csrf', self::moduleCsrf($session['csrf'], $module))
            ->with('X-Kienzle-Bestaetigt', (string)(int)$session['confirmed_at'])
            ->with('X-Kienzle-Drucker', rawurlencode(json_encode($this->printerRoles(), JSON_UNESCAPED_UNICODE)))
            ->with('X-Kienzle-Praxis', rawurlencode(json_encode(['name' => $this->config->practice] + $this->address(), JSON_UNESCAPED_UNICODE)));
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
                . '<button type="button">Mit Passkey anmelden</button><p class="error" hidden></p></div>' : '')
            . '<p class="muted">Warnt der Browser („Nicht sicher“) oder lässt sich die Adresse nicht öffnen? Dieser PC muss einmal eingerichtet werden (Zertifikat und Name): '
            . '<a href="/hilfe/arbeitsplatz">Arbeitsplatz einrichten</a>.</p>';
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

    /** Angebot, die Uhr des Servers zu stellen (nur nach einem Code, der zu einer anderen Uhrzeit passt). */
    private function clockOffer(?\Throwable $e): string {
        if (!$e instanceof ClockProblem || !$this->session) return '';
        return '<form method="post" action="/uhrzeit/korrigieren" class="box notice">' . V::csrf($this->session['csrf'])
            . '<p>Server-Zeit jetzt: <strong>' . date('d.m.Y H:i:s') . '</strong>, laut Ihrem Telefon etwa <strong>' . date('d.m.Y H:i:s', time() + $e->skew) . '</strong>.</p>'
            . '<button>Uhrzeit des Servers korrigieren</button></form>';
    }

    private function clockFix(Request $r): Response {
        $this->checkCsrf($r);
        $skew = $this->auth->takeClockSkew($this->session);
        $back = $this->session['stage'] === 'otp' ? '/anmelden/code' : '/konto/otp';
        if ($skew === null) return Response::redirect($back);
        $this->srv()->setClock($skew);
        $this->audit->write((string)$this->session['login'], 'uhrzeit-korrigiert', ($skew > 0 ? '+' : '') . $skew . ' s');
        $body = V::notice('Die Uhrzeit des Servers wird korrigiert. Danach bitte den aktuellen Code aus der App eingeben.')
            . '<p><a class="button" href="' . $back . '">Weiter</a></p>';
        return Response::html(V::page('Uhrzeit', $body, null, $this->config->practice))->with('Refresh', '4; url=' . $back);
    }

    private function codePage(?string $error = null, ?\Throwable $e = null): Response {
        $body = V::error($error) . $this->clockOffer($e) . '<form method="post" action="/anmelden/code" class="box">'
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
            return $this->codePage($e->getMessage(), $e);
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
    private function enrollPage(?string $error = null, ?\Throwable $e = null): Response {
        $session = $this->session;
        $user = $this->users->find((int)$session['user_id']);
        if ($session['stage'] === 'full' && $this->users->hasOtp($user) && !$this->auth->confirmedRecently($session)) return $this->needConfirm('/konto/otp');
        $start = $this->auth->enrollStart($session, $this->issuer());
        $svg = ($this->qr)($start['uri']);
        $body = ($session['stage'] === 'enroll' ? '<p>Dieses Konto hat Admin-Rechte. Admins melden sich mit Passwort <strong>und</strong> einem Code aus einer Authenticator-App an '
                . '(z. B. Aegis, 2FAS, Google oder Microsoft Authenticator).</p>' : '<p>Neues Telefon oder neue App: Nach der Bestätigung gilt nur noch der neue Code; die Wiederherstellungscodes werden erneuert.</p>')
            . V::error($error) . $this->clockOffer($e)
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
            return $this->enrollPage($e->getMessage(), $e);
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
        if ($r->path === '/verwaltung/gruppen') return $get ? $this->groupsPage() : $this->groupsAction($r, $admin);
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
        if ($r->path === '/verwaltung/firewall') return $get ? $this->firewallPage() : $this->firewallApply($r, $admin);
        if ($r->path === '/verwaltung/firewall/bestaetigen' && !$get) return $this->firewallConfirm($admin);
        if ($r->path === '/verwaltung/firewall/aus' && !$get) return $this->firewallOff($admin);
        if ($r->path === '/verwaltung/updates') return $get ? $this->updatesPage() : $this->updatesAction($r, $admin);
        if ($r->path === '/verwaltung/module') return $get ? $this->modulesPage() : $this->modulesAction($r, $admin);
        if ($r->path === '/verwaltung/verbindungen') return $get ? $this->connectionsPage() : $this->connectionsAction($r, $admin);
        if ($r->path === '/verwaltung/server') return $get ? $this->serverPage() : $this->serverSave($r, $admin);
        if ($r->path === '/verwaltung/sso') return $get ? $this->ssoPage() : $this->ssoAction($r, $admin);
        if ($r->path === '/verwaltung/drucker') return $get ? $this->printersPage() : $this->printersAction($r, $admin);
        if ($r->path === '/verwaltung/einrichtung') return $get ? $this->overviewPage() : $this->overviewAction($r, $admin);
        if ($r->path === '/verwaltung/sicherung') return $get ? $this->backupPage() : $this->backupAction($r, $admin);
        if ($r->path === '/verwaltung/sicherung/export' && $get) {
            [$file, $name] = $this->bk()->exportFile();
            $data = @file_get_contents($file);
            if (!is_string($data)) throw new Problem('Export nicht lesbar.', 500);
            $this->audit->write($admin, 'einstellungen-export-heruntergeladen');
            return (new Response(200, $data, [['Content-Type', 'application/octet-stream']]))->with('Content-Disposition', 'attachment; filename="' . $name . '"')
                ->with('Cache-Control', 'no-store')->with('X-Content-Type-Options', 'nosniff');
        }
        if ($r->path === '/verwaltung/sicherung/blatt' && $get) {
            $this->audit->write($admin, 'sicherung-blatt-angezeigt');
            return Response::html($this->bk()->sheet())->with('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'")
                ->with('Cache-Control', 'no-store');
        }
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
            . $this->groupSelect()
            . V::checkbox('admin', '1', 'Basis-Admin', (int)$user['admin'] === 1) . V::checkbox('active', '1', 'Konto aktiv', (int)$user['active'] === 1)
            . ($modules !== '' ? '<h2>Zugang zu Modulen</h2>' . $modules : '<p>Es sind noch keine Module installiert.</p>')
            . '<button>Speichern</button></form>'
            . (!$self && !$this->users->needsOtp($user) ? '<form method="post" action="/verwaltung/benutzer/' . $id . '/link" class="box">' . V::csrf($this->session['csrf'])
                . '<p>Passwort vergessen? Ein neuer Einrichtungslink löscht das bisherige Passwort und meldet alle Geräte ab.</p><button>Neuen Einrichtungslink erzeugen</button></form>'
                : (!$self ? '<p>Admin-Konten (Passwort oder Authenticator-App verloren) setzt man nur an der Server-Konsole zurück: <code>sudo kienzle-admin zuruecksetzen ' . V::h($user['login']) . '</code></p>' : ''))
            . (!$self ? '<form method="post" action="/verwaltung/benutzer/' . $id . '/loeschen" class="box">' . V::csrf($this->session['csrf']) . '<button class="danger">Benutzer löschen</button></form>' : '');
        return Response::html(V::page('Benutzer ' . $user['login'], $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function groupSelect(): string {
        $opts = '<option value="">– nicht ändern, Haken unten gelten –</option>';
        foreach (array_keys((new Groups($this->db, $this->modules))->all()) as $name) $opts .= '<option value="' . V::h($name) . '">' . V::h($name) . '</option>';
        return '<label>Gruppe anwenden<select name="gruppe">' . $opts . '</select></label>'
            . '<p class="muted small">Setzt beim Speichern die Modulrechte dieser Gruppe (<a href="/verwaltung/gruppen">Gruppen bearbeiten</a>). Danach lassen sich die Haken einzeln ändern.</p>';
    }

    private function groupBoxes(string $prefix, array $g): string {
        $out = '';
        foreach ($this->modules->all() as $mid => $_) {
            $rights = '';
            foreach ($this->modules->rights($mid) as $right) $rights .= V::checkbox('recht[]', $mid . ':' . $right['id'], $right['label'], in_array($mid . ':' . $right['id'], $g['rechte'], true));
            $out .= '<fieldset><legend>' . V::h($this->modules->name($mid)) . '</legend>' . V::checkbox('modul_admin[]', $mid, 'Admin dieses Moduls', in_array($mid, $g['admin'], true)) . $rights . '</fieldset>';
        }
        return $out;
    }

    private function groupsPage(?string $error = null, ?string $notice = null): Response {
        $groups = new Groups($this->db, $this->modules);
        $body = V::error($error) . V::notice($notice)
            . '<p>Gruppen sind Vorlagen für Modulrechte. Beim Bearbeiten eines Benutzers wählt man eine Gruppe, die Rechte werden dann übernommen. '
            . 'Spätere Änderungen an einer Gruppe ändern bestehende Benutzer nicht. Basis-Admins dürfen in allen Modulen ohnehin alles.</p>';
        foreach ($groups->all() as $name => $g) {
            $body .= '<form method="post" action="/verwaltung/gruppen" class="box">' . V::csrf($this->session['csrf']) . '<input type="hidden" name="aktion" value="speichern"><input type="hidden" name="name" value="' . V::h($name) . '">'
                . '<h2>' . V::h($name) . '</h2>' . $this->groupBoxes($name, $g) . '<button>Speichern</button></form>'
                . '<form method="post" action="/verwaltung/gruppen" class="inline">' . V::csrf($this->session['csrf']) . '<input type="hidden" name="aktion" value="zuruecksetzen"><input type="hidden" name="name" value="' . V::h($name) . '">'
                . '<button class="danger">' . (isset($groups->defaults()[$name]) ? 'Auf Vorgabe zurücksetzen' : 'Gruppe löschen') . '</button></form>';
        }
        $body .= '<h2>Neue Gruppe</h2><form method="post" action="/verwaltung/gruppen" class="box">' . V::csrf($this->session['csrf']) . '<input type="hidden" name="aktion" value="speichern">'
            . V::input('name', 'Name der Gruppe', 'text', '', 'maxlength="30" required') . $this->groupBoxes('', ['admin' => [], 'rechte' => []]) . '<button>Anlegen</button></form>';
        return Response::html(V::page('Gruppen', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function groupsAction(Request $r, string $admin): Response {
        $g = new Groups($this->db, $this->modules);
        $name = trim($r->field('name', 30));
        try {
            if ($r->field('aktion', 20) === 'speichern') {
                $g->set($name, $r->list('modul_admin'), $r->list('recht'));
                $this->audit->write($admin, 'gruppe-gespeichert', $name);
            } elseif ($r->field('aktion', 20) === 'zuruecksetzen') {
                $g->reset($name);
                $this->audit->write($admin, 'gruppe-zurueckgesetzt', $name);
            } else {
                throw new Problem('Unbekannte Aktion.', 422);
            }
        } catch (Problem $e) {
            return $this->groupsPage($e->getMessage());
        }
        return $this->groupsPage(null, 'Gespeichert.');
    }

    private function userSave(Request $r, int $id, string $admin): Response {
        $user = $this->users->find($id);
        if (!$user) throw new Problem('Benutzer nicht gefunden.', 404);
        $moduleAdmin = $r->list('modul_admin');
        $entries = $r->list('recht');
        $groupName = $r->field('gruppe', 30);
        if ($groupName !== '') {
            $g = (new Groups($this->db, $this->modules))->get($groupName);
            if (!$g) return $this->userEdit($id, 'Unbekannte Gruppe.');
            $moduleAdmin = $g['admin'];
            $entries = $g['rechte'];
        }
        $rights = [];
        foreach ($entries as $entry) {
            [$m, $right] = array_pad(explode(':', (string)$entry, 2), 2, '');
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

    // ---- Firewall -----------------------------------------------------------------------------------------------
    private function fw(): Firewall {
        if (!$this->firewall) throw new Problem('Die Firewall ist auf diesem Server nicht verfügbar.', 503);
        return $this->firewall;
    }

    private function firewallPage(?string $error = null, ?array $input = null): Response {
        $o = $this->fw()->overview($this->req->remote);
        $c = $input ?? $o['config'];
        $st = $o['status'];
        $csrf = V::csrf($this->session['csrf']);
        $labels = ['off' => 'aus', 'requested' => 'wird geladen …', 'pending' => 'geladen, wartet auf Bestätigung', 'active' => 'aktiv',
            'reverted' => 'zurückgesetzt (nicht bestätigt)', 'failed' => 'Fehler'];
        $body = V::error($error) . '<p>Zustand: <strong>' . V::h($labels[$st['state']] ?? $st['state']) . '</strong>' . ($st['message'] !== '' ? ' – ' . V::h($st['message']) : '') . '</p>';
        if ($st['state'] === 'pending') {
            $body .= '<form method="post" action="/verwaltung/firewall/bestaetigen" class="box notice">' . $csrf
                . '<p><strong>Diese Seite ist mit den neuen Regeln erreichbar?</strong> Dann jetzt bestätigen – sonst werden die vorherigen Regeln um '
                . V::h(date('H:i:s', (int)$st['deadline'])) . ' Uhr wiederhergestellt.</p><button>Firewall bestätigen</button></form>';
        }
        if (!$o['service']) $body .= '<p class="error">Der Firewall-Dienst ist nicht eingerichtet. Bitte den Installer ausführen.</p>';
        $nets = [];
        foreach ($o['network']['interfaces'] as $i) foreach ($i['addresses'] as $a) if ($a['private']) $nets[$a['network']] = $i['name'];
        foreach ($c['cidrs'] as $n) $nets[$n] ??= 'gespeichert';
        $netBoxes = '';
        foreach ($nets as $n => $where) $netBoxes .= V::checkbox('networks[]', $n, $n . ' (' . $where . ')' . ($o['client']['network'] === $n ? ' – hier ist dieses Gerät' : ''), in_array($n, $c['cidrs'], true));
        $ports = '';
        foreach ($o['catalog'] as $id => $p) {
            $ports .= V::checkbox('modules[]', $id, $p['module'] . ': ' . $p['name'] . ' (' . $p['proto'] . ' ' . $p['ports'] . ($p['scope'] === 'any' ? ', von überall' : '') . ')', in_array($id, $c['modules'], true));
        }
        $body .= '<form method="post" action="/verwaltung/firewall" class="box wide">' . $csrf
            . '<h2>Freigegebene Netze</h2><p>Nur aus diesen Netzen ist die Suite erreichbar. Dieses Gerät: <code>' . V::h($o['client']['ip']) . '</code></p>' . $netBoxes
            . V::input('extra', 'Weitere Netze (z. B. VPN), mit Komma getrennt', 'text', (string)($input['extra'] ?? ''), 'placeholder="10.8.0.0/24"')
            . '<h2>Dienste der Basis</h2><p>HTTPS (443) ist immer aus den freigegebenen Netzen offen.</p>'
            . V::checkbox('ssh', '1', 'SSH (Fernwartung)', (bool)$c['ssh']) . V::checkbox('ipp', '1', 'Drucken über IPP (631)', (bool)$c['ipp'])
            . V::checkbox('samba', '1', 'Drucken über Windows-Freigabe (Samba, 445)', (bool)$c['samba'])
            . ($ports !== '' ? '<h2>Ports der Module</h2><p>Module melden ihre Ports an; sie sind zu, bis sie hier freigeschaltet werden.</p>' . $ports : '')
            . '<h2>Eigene Ports</h2><label>Ein Eintrag je Zeile, z. B. <code>tcp 8080</code> oder <code>udp 5000-5100</code><textarea name="custom" rows="3">'
            . V::h(implode("\n", $c['custom'])) . '</textarea></label>'
            . '<h2>Art</h2>' . '<label class="check"><input type="radio" name="strict" value="1"' . ($c['strict'] ? ' checked' : '') . '> Alles andere gesperrt (empfohlen)</label>'
            . '<label class="check"><input type="radio" name="strict" value="0"' . (!$c['strict'] ? ' checked' : '') . '> Nur die oben genannten Ports auf die Netze beschränken, alles andere unverändert</label>'
            . '<p>Nach dem Laden muss die Änderung binnen 2 Minuten auf dieser Seite bestätigt werden, sonst gilt wieder die vorherige Einstellung.</p>'
            . '<button' . ($o['service'] && !in_array($st['state'], ['pending', 'requested'], true) ? '' : ' disabled') . '>Firewall laden</button></form>';
        if (in_array($st['state'], ['active', 'pending', 'failed', 'reverted'], true)) {
            $body .= '<form method="post" action="/verwaltung/firewall/aus" class="box">' . $csrf . '<button class="danger">Firewall ausschalten</button></form>';
        }
        $response = Response::html(V::page('Firewall', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
        return $st['state'] === 'requested' ? $response->with('Refresh', '2') : $response;
    }

    private function firewallApply(Request $r, string $admin): Response {
        $input = ['networks' => $r->list('networks'), 'extra' => $r->field('extra', 1000), 'ssh' => $r->field('ssh') === '1', 'ipp' => $r->field('ipp') === '1',
            'samba' => $r->field('samba') === '1', 'strict' => $r->field('strict') !== '0', 'modules' => $r->list('modules'), 'custom' => $r->field('custom', 2000)];
        try {
            $config = $this->fw()->apply($input, $r->remote);
        } catch (Problem $e) {
            $again = ['cidrs' => $input['networks'], 'custom' => preg_split('/\R/', $input['custom'], -1, PREG_SPLIT_NO_EMPTY)] + $input;
            return $this->firewallPage($e->getMessage(), $again);
        }
        $this->audit->write($admin, 'firewall-geladen', implode(',', $config['cidrs']) . ($config['strict'] ? ' alles-zu' : ' nur-ports') . ($config['modules'] ? ' ' . implode(',', $config['modules']) : ''));
        return Response::redirect('/verwaltung/firewall');
    }

    private function firewallConfirm(string $admin): Response {
        $this->fw()->confirm();
        $this->audit->write($admin, 'firewall-bestaetigt');
        return Response::redirect('/verwaltung/firewall');
    }

    private function firewallOff(string $admin): Response {
        $this->fw()->disable();
        $this->audit->write($admin, 'firewall-ausgeschaltet');
        return Response::redirect('/verwaltung/firewall');
    }

    // ---- Module -------------------------------------------------------------------------------------------------
    private function store(): ModuleAdmin {
        if (!$this->store) throw new Problem('Die Modulverwaltung ist auf diesem Server nicht verfügbar.', 503);
        return $this->store;
    }

    private function modulesPage(?string $error = null): Response {
        $st = $this->store()->status();
        $csrf = V::csrf($this->session['csrf']);
        $busy = $st['pending'] || $st['running'] !== null;
        $off = $busy ? ' disabled' : '';
        $actions = ['installieren' => 'Installation', 'aktualisieren' => 'Aktualisierung', 'entfernen' => 'Entfernen'];
        $body = V::error($error);
        if ($busy) {
            $body .= '<p class="notice">' . ($st['running'] ? V::h(($actions[$st['running']['action']] ?? 'Vorgang') . ' von „' . $st['running']['id'] . '“ läuft …')
                : 'Auftrag wird übernommen …') . ' Das kann einige Minuten dauern (Pakete werden geladen). Die Seite aktualisiert sich selbst.</p>';
        } elseif ($st['last']) {
            $body .= $st['last']['ok'] ? V::notice($st['last']['message']) : V::error('Nicht erfolgreich: ' . $st['last']['message']);
        }
        if (!$st['service']) $body .= '<p class="error">Die Modulverwaltung ist nicht eingerichtet. Bitte den Installer ausführen.</p>';
        $body .= '<p>Module erweitern die Suite um Programme wie die Kasse. Jedes Modul läuft unter einem eigenen Systembenutzer und sieht nur seine eigenen Daten. '
            . 'Nach dem Installieren unter <a href="/verwaltung/benutzer">Benutzer</a> festlegen, wer das Modul nutzen darf.</p>';
        $body .= '<h2>Installiert</h2>';
        if (!$st['installed']) $body .= '<p>Noch keine Module installiert.</p>';
        foreach ($st['installed'] as $m) {
            $hidden = '<input type="hidden" name="id" value="' . V::h($m['id']) . '">';
            $body .= '<div class="box wide"><h3>' . V::h($m['name']) . ' <span class="tag">' . V::h($m['version']) . '</span></h3>'
                . ($m['description'] !== '' ? '<p>' . V::h($m['description']) . '</p>' : '')
                . ($m['path'] ? '<p><a href="' . V::h($m['path']) . '/">Öffnen</a></p>' : '');
            if ($m['update']) {
                $body .= '<form method="post" action="/verwaltung/module">' . $csrf . $hidden . '<input type="hidden" name="aktion" value="aktualisieren">'
                    . '<p>Neue Version <strong>' . V::h($m['update']['version']) . '</strong>' . ($m['update']['source'] === 'github' ? ' (GitHub' . ($m['update']['signed'] === false ? ', nicht signiert' : '') . ')' : ' (mit der Basis geliefert)')
                    . '. Vorher wird ein Schnappschuss der Moduldaten angelegt; schlägt etwas fehl, springt das Modul zurück.</p><button' . $off . '>Aktualisieren</button></form>';
            }
            $body .= '<details class="how"><summary>Entfernen</summary><form method="post" action="/verwaltung/module">' . $csrf . $hidden
                . '<input type="hidden" name="aktion" value="entfernen"><label class="check"><input type="checkbox" name="daten" value="1"> Auch alle Daten des Moduls löschen (nicht rückgängig zu machen)</label>'
                . '<p>Ohne Häkchen bleiben die Daten erhalten; eine spätere Installation übernimmt sie.</p><button class="danger"' . $off . '>Modul entfernen</button></form></details></div>';
        }
        $body .= '<h2>Verfügbar</h2>';
        if (!$st['available']) $body .= '<p>Keine weiteren Module verfügbar.</p>';
        foreach ($st['available'] as $m) {
            $body .= '<div class="box wide"><h3>' . V::h($m['name']) . ' <span class="tag">' . V::h($m['version']) . '</span>'
                . ($m['signed'] === false ? ' <span class="tag warn">nicht signiert</span>' : '') . '</h3>'
                . ($m['description'] !== '' ? '<p>' . V::h($m['description']) . '</p>' : '')
                . '<p class="muted">' . ($m['source'] === 'github' ? 'Von GitHub' : 'Mit der Basis geliefert') . ($m['path'] ? ' · Adresse ' . V::h($m['path']) : '') . '</p>'
                . '<form method="post" action="/verwaltung/module">' . $csrf . '<input type="hidden" name="aktion" value="installieren"><input type="hidden" name="id" value="' . V::h($m['id']) . '">'
                . '<button' . $off . '>Installieren</button></form></div>';
        }
        $body .= '<form method="post" action="/verwaltung/module" class="inline">' . $csrf . '<input type="hidden" name="aktion" value="pruefen"><button class="secondary"' . $off . '>Nach Modulen sehen</button></form> '
            . '<form method="post" action="/verwaltung/module" class="inline">' . $csrf . '<input type="hidden" name="aktion" value="automatisch"><input type="hidden" name="an" value="' . ($st['auto'] ? '0' : '1') . '">'
            . '<button class="secondary"' . $off . '>' . ($st['auto'] ? 'Automatische Updates ausschalten' : 'Automatische Updates einschalten') . '</button></form>'
            . '<p class="muted">' . ($st['auto'] ? 'Neue Modulversionen werden täglich nachts installiert, nicht während einer Zahlung.' : 'Modul-Updates nur von Hand.') . '</p>'
            . '<p class="muted">Zuletzt geprüft: ' . V::date($st['checked_at']) . ($st['error'] ? ' – GitHub nicht erreichbar: ' . V::h($st['error']) : '') . '</p>';
        if ($st['log']) $body .= '<h2>Protokoll</h2><pre class="notes">' . V::h(implode("\n", $st['log'])) . '</pre>';
        $response = Response::html(V::page('Module', $body, $this->nav(), $this->config->practice, 'Betrieb'), $error ? 409 : 200);
        return $busy ? $response->with('Refresh', '4') : $response;
    }

    private function modulesAction(Request $r, string $admin): Response {
        $store = $this->store();
        $id = $r->field('id', 40);
        try {
            switch ($r->field('aktion', 20)) {
                case 'pruefen': $store->check(); break;
                case 'automatisch': $on = $r->field('an', 1) === '1'; $store->auto($on); $this->audit->write($admin, 'modul-automatisch', $on ? 'an' : 'aus'); break;
                case 'installieren': $store->install($id); $this->db->store('erstadmin:' . $id, (string)(int)$this->session['user_id']); $this->audit->write($admin, 'modul-installieren', $id); break;
                case 'aktualisieren': $store->update($id); $this->audit->write($admin, 'modul-aktualisieren', $id); break;
                case 'entfernen':
                    $data = $r->field('daten', 1) === '1';
                    $store->remove($id, $data);
                    $this->audit->write($admin, 'modul-entfernen', $id . ($data ? ' samt-daten' : ''));
                    break;
                default: throw new Problem('Unbekannte Aktion.', 422);
            }
        } catch (Problem $e) {
            return $this->modulesPage($e->getMessage());
        }
        return Response::redirect('/verwaltung/module');
    }

    // ---- Updates ------------------------------------------------------------------------------------------------
    private function up(): Updates {
        if (!$this->updates) throw new Problem('Updates sind auf diesem Server nicht verfügbar.', 503);
        return $this->updates;
    }

    private function updatesPage(?string $error = null, ?string $notice = null): Response {
        $st = $this->up()->status();
        $csrf = V::csrf($this->session['csrf']);
        $busy = in_array($st['state'], ['running', 'requested'], true);
        $labels = ['idle' => 'bereit', 'requested' => 'Auftrag wird übernommen …', 'running' => 'Aktualisierung läuft (' . $st['step'] . ') …', 'done' => 'fertig', 'failed' => 'nicht erfolgreich'];
        $body = V::error($error) . V::notice($notice)
            . '<p>Installiert: <strong>Basis ' . V::h($st['installed']) . '</strong>. Zustand: ' . V::h($labels[$st['state']] ?? $st['state'])
            . ($st['message'] !== '' ? ' – ' . V::h($st['message']) : '') . '</p>'
            . (!$st['service'] ? '<p class="error">Der Update-Dienst ist nicht eingerichtet. Bitte den Installer ausführen.</p>' : '')
            . '<p>Zuletzt geprüft: ' . V::date($st['checked_at']) . ($st['error'] ? ' – <span class="error">' . V::h($st['error']) . '</span>' : '') . '</p>'
            . '<form method="post" action="/verwaltung/updates" class="inline">' . $csrf . '<input type="hidden" name="aktion" value="pruefen"><button' . ($busy ? ' disabled' : '') . '>Jetzt nach Updates sehen</button></form>';
        $rows = '';
        foreach ($st['available'] as $a) {
            $rows .= '<div class="box wide"><h2>Basis ' . V::h($a['version']) . ($a['prerelease'] ? ' <span class="tag">beta</span>' : '')
                . (!$a['signed'] ? ' <span class="tag warn">nicht signiert</span>' : '') . '</h2>'
                . ($a['published'] !== '' ? '<p>Veröffentlicht: ' . V::h($a['published']) . '</p>' : '') . '<pre class="notes">' . V::h($a['notes']) . '</pre>'
                . '<form method="post" action="/verwaltung/updates">' . $csrf . '<input type="hidden" name="aktion" value="installieren"><input type="hidden" name="version" value="' . V::h($a['version']) . '">'
                . '<p>Vorher wird ein Schnappschuss der Daten angelegt. Schlägt etwas fehl, springt die Suite automatisch zurück. Die Seite ist einige Minuten nicht erreichbar.</p>'
                . '<button' . ($busy ? ' disabled' : '') . '>Jetzt installieren</button></form></div>';
        }
        $body .= '<h2>Verfügbar</h2>' . ($rows !== '' ? $rows : '<p>Keine neuere Version.</p>');
        $body .= '<h2>Kanal</h2><form method="post" action="/verwaltung/updates" class="box">' . $csrf . '<input type="hidden" name="aktion" value="kanal">'
            . '<label class="check"><input type="radio" name="kanal" value="stabil"' . ($st['channel'] === 'stabil' ? ' checked' : '') . '> Stabil (empfohlen)</label>'
            . '<label class="check"><input type="radio" name="kanal" value="beta"' . ($st['channel'] === 'beta' ? ' checked' : '') . '> Beta (Vorabversionen zum Testen)</label>'
            . '<h3>Signaturprüfung</h3>'
            . '<label class="check"><input type="radio" name="signatur" value="aus"' . ($st['signatur'] === 'aus' ? ' checked' : '') . '> Ohne Signaturprüfung (Vorgabe)</label>'
            . '<label class="check"><input type="radio" name="signatur" value="beta"' . ($st['signatur'] === 'beta' ? ' checked' : '') . '> Nur im Beta-Kanal ohne Signatur</label>'
            . '<label class="check"><input type="radio" name="signatur" value="pflicht"' . ($st['signatur'] === 'pflicht' ? ' checked' : '') . '> Immer prüfen – sobald der Herausgeber die Releases signiert</label>'
            . '<p>Ohne Signatur ist nicht gesichert, dass eine Version vom Herausgeber stammt: Wer das Repository auf GitHub verändern kann, '
            . 'könnte dann Programme mit root-Rechten auf diesen Server bringen. Geprüft wird weiter gegen Beschädigung (Manifest, SHA-256), und eine '
            . 'vorhandene, aber falsche Signatur wird immer abgelehnt.</p>'
            . '<button' . ($busy ? ' disabled' : '') . '>Kanal speichern</button></form>';
        $older = array_filter($st['local'], fn($v) => $v !== $st['installed']);
        if ($older) {
            $body .= '<h2>Zurück auf eine frühere Version</h2><form method="post" action="/verwaltung/updates" class="box">' . $csrf . '<input type="hidden" name="aktion" value="zurueck">'
                . '<p><strong>Achtung:</strong> Die Daten werden auf den Stand beim damaligen Update zurückgesetzt; spätere Änderungen gehen verloren.</p>';
            foreach ($older as $v) $body .= '<label class="check"><input type="radio" name="version" value="' . V::h($v) . '"> Basis ' . V::h($v) . '</label>';
            $body .= '<button class="danger"' . ($busy ? ' disabled' : '') . '>Zurück auf diese Version</button></form>';
        }
        if ($st['log']) $body .= '<h2>Protokoll</h2><pre class="notes">' . V::h(implode("\n", $st['log'])) . '</pre>';
        $response = Response::html(V::page('Updates', $body, $this->nav(), $this->config->practice), $error ? 409 : 200);
        return $busy ? $response->with('Refresh', '5') : $response;
    }

    private function updatesAction(Request $r, string $admin): Response {
        $up = $this->up();
        try {
            switch ($r->field('aktion', 20)) {
                case 'pruefen': $up->check(); $this->audit->write($admin, 'update-pruefen'); break;
                case 'kanal':
                    $mode = $r->field('signatur', 10) ?: ($r->field('unsigniert') === '1' ? 'beta' : 'aus');
                    $up->setChannel($r->field('kanal', 10), $mode);
                    $this->audit->write($admin, 'update-kanal', $r->field('kanal', 10) . ' signatur-' . $mode);
                    break;
                case 'installieren': $up->install($r->field('version', 40)); $this->audit->write($admin, 'update-installieren', $r->field('version', 40)); break;
                case 'zurueck': $up->rollback($r->field('version', 40)); $this->audit->write($admin, 'update-zurueck', $r->field('version', 40)); break;
                default: throw new Problem('Unbekannte Aktion.', 422);
            }
        } catch (Problem $e) {
            return $this->updatesPage($e->getMessage());
        }
        return Response::redirect('/verwaltung/updates');
    }

    // ---- Verbindungen -------------------------------------------------------------------------------------------
    private function con(): Connections {
        if (!$this->connections) throw new Problem('Verbindungen sind auf diesem Server nicht verfügbar.', 503);
        return $this->connections;
    }

    private function testLine(array $tests, string $key): string {
        $t = $tests[$key] ?? null;
        if (!is_array($t)) return '<p>Noch nicht geprüft.</p>';
        return '<p class="' . (($t['ok'] ?? false) ? 'ok' : 'error') . '">Prüfung ' . V::date((int)($t['zeit'] ?? 0)) . ': ' . V::h((string)($t['meldung'] ?? '')) . '</p>';
    }

    private function hidden(string $action, array $extra = []): string {
        $out = V::csrf($this->session['csrf']) . '<input type="hidden" name="aktion" value="' . V::h($action) . '">';
        foreach ($extra as $k => $v) $out .= '<input type="hidden" name="' . V::h($k) . '" value="' . V::h($v) . '">';
        return $out;
    }

    private function connectionsPage(?string $error = null): Response {
        $st = $this->con()->status();
        $t2 = $st['t2med'] ?? [];
        $mail = $st['mail'] ?? [];
        $set = static fn(bool $on) => $on ? 'gespeichert – leer lassen für unverändert' : '';
        $body = V::error($error)
            . ($st['letzte'] ? '<p class="' . ($st['letzte']['ok'] ? 'notice' : 'error') . '">' . V::h((string)$st['letzte']['meldung']) . '</p>' : '')
            . implode('', array_map(fn($n) => '<p class="error">' . V::h($n) . '</p>', $st['hinweise']))
            . (!$st['service'] ? '<p class="error">Der Dienst für Verbindungen ist nicht eingerichtet. Bitte den Installer ausführen.</p>' : '')
            . '<p>Zugänge, die mehrere Module brauchen, werden hier einmal eingetragen. Ein Modul bekommt nur die Verbindungen, die es anmeldet; '
            . 'Passwörter und Schlüssel werden nach dem Speichern nie wieder angezeigt.</p>';
        // T2med
        $body .= '<section><h2>T2med (FHIR)</h2><form method="post" action="/verwaltung/verbindungen" class="box wide">' . $this->hidden('t2med')
            . V::input('base_url', 'Adresse des FHIR-Servers', 'url', (string)($t2['base_url'] ?? ''), 'placeholder="https://t2med-server:16567/aps/fhir/api/r4"')
            . '<label>Weitere erlaubte Start-Adressen (eine je Zeile)<textarea name="launch_urls" rows="2">' . V::h(implode("\n", array_diff((array)($t2['launch_urls'] ?? []), [(string)($t2['base_url'] ?? '')]))) . '</textarea></label>'
            . V::input('api_key', 'API-Schlüssel (Client Secret aus T2med)', 'password', '', 'autocomplete="new-password" placeholder="' . V::h($set((bool)($t2['api_key_gesetzt'] ?? false))) . '"')
            . '<p class="muted small">In T2med heißt er „Client Secret“ und wird bei der Einrichtung des Drittanbieters nur einmal angezeigt. Ist er verloren, muss der Drittanbieter-Zugriff in T2med gelöscht und neu erstellt werden.</p>'
            . '<label>Zertifikat des T2med-Servers (PEM, optional)' . (($t2['zertifikat'] ?? '') !== '' ? ' – gespeichert, SHA-256 <code>' . V::h(substr((string)$t2['zertifikat'], 0, 16)) . '…</code>' : '')
            . '<textarea name="ca_pem" rows="3" placeholder="-----BEGIN CERTIFICATE-----"></textarea></label>'
            . V::checkbox('ca_entfernen', '1', 'Gespeichertes Zertifikat entfernen', false)
            . V::checkbox('pin', '1', 'Nur genau dieses Zertifikat annehmen (Pinning)', (bool)($t2['pin'] ?? false))
            . '<button>Speichern</button></form>'
            . ($t2 ? '<p>Zertifikat: ' . (($t2['zertifikat'] ?? '') !== '' ? '<code>' . V::h((string)$t2['zertifikat']) . '</code>' . ($t2['pin'] ? ' (fest hinterlegt)' : '') : '<strong>nicht hinterlegt</strong> (nur öffentlich beglaubigte Zertifikate werden angenommen)') . '</p>'
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('zertifikat-holen') . '<button>Zertifikat des Servers abrufen</button></form> '
                . (($st['zertifikate']['t2med']['fingerprint'] ?? '') !== '' ? '<form method="post" action="/verwaltung/verbindungen" class="box">' . $this->hidden('zertifikat-uebernehmen', ['fingerprint' => (string)$st['zertifikate']['t2med']['fingerprint']])
                    . '<p>Abgerufen: SHA-256 <code>' . V::h((string)$st['zertifikate']['t2med']['fingerprint']) . '</code>. Nur übernehmen, wenn der Fingerabdruck mit dem Zertifikat des T2med-Servers übereinstimmt (z. B. im Browser unter „Zertifikat anzeigen“).</p><button>Übernehmen und fest hinterlegen</button></form>' : '')
                . $this->testLine($st['tests'], 't2med') . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('pruefen', ['art' => 't2med']) . '<button>Verbindung prüfen</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('entfernen', ['was' => 't2med']) . '<button class="danger">Entfernen</button></form>' : '')
            . '</section>';
        // E-Mail
        $sec = (string)($mail['sicherheit'] ?? 'starttls');
        $body .= '<section><h2>E-Mail-Versand (SMTP)</h2><form method="post" action="/verwaltung/verbindungen" class="box wide">' . $this->hidden('mail')
            . V::input('host', 'SMTP-Server', 'text', (string)($mail['host'] ?? ''), 'placeholder="smtp.example.org"')
            . V::input('port', 'Port', 'number', (string)($mail['port'] ?? '587'), 'min="1" max="65535"')
            . '<label>Verschlüsselung<select name="sicherheit"><option value="starttls"' . ($sec === 'starttls' ? ' selected' : '') . '>STARTTLS (meist 587)</option>'
            . '<option value="smtps"' . ($sec === 'smtps' ? ' selected' : '') . '>SMTPS (meist 465)</option></select></label>'
            . V::input('benutzer', 'Benutzername', 'text', (string)($mail['benutzer'] ?? ''), 'autocomplete="off"')
            . V::input('passwort', 'Passwort', 'password', '', 'autocomplete="new-password" placeholder="' . V::h($set((bool)($mail['passwort_gesetzt'] ?? false))) . '"')
            . V::input('absender', 'Absender', 'email', (string)($mail['absender'] ?? ''), 'placeholder="praxis@example.org"')
            . '<button>Speichern</button></form>'
            . ($mail ? $this->testLine($st['tests'], 'mail') . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('pruefen', ['art' => 'mail']) . '<button>Anmeldung prüfen</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('testmail') . '<input type="email" name="an" placeholder="empfaenger@example.org" required maxlength="254" aria-label="Empfänger der Testnachricht"> <button>Testnachricht senden</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('entfernen', ['was' => 'mail']) . '<button class="danger">Entfernen</button></form>' : '')
            . '</section>';
        // Netzwerkspeicher
        $body .= '<section><h2>Netzwerkspeicher</h2>';
        foreach ($st['ablage'] as $t) {
            $id = (string)$t['id'];
            $body .= '<div class="box wide"><h3>' . V::h((string)$t['name']) . ' <span class="tag">' . V::h(strtoupper((string)$t['art'])) . '</span></h3><p><code>'
                . V::h(($t['art'] === 'smb' ? '//' . $t['host'] . '/' . ($t['freigabe'] ?? '') : 'sftp://' . ($t['benutzer'] ?? '') . '@' . $t['host'] . ':' . ($t['port'] ?? 22)) . '/' . $t['ordner'])
                . '</code></p>' . $this->testLine($st['tests'], 'ziel:' . $id);
            if ($t['art'] === 'sftp') {
                $body .= '<p>Schlüssel des Servers: ' . (($t['fingerprint'] ?? '') !== '' ? '<code>' . V::h((string)$t['fingerprint']) . '</code>' : '<strong>noch nicht bestätigt</strong> – zuerst „Schlüssel des Servers abrufen“, dann den angezeigten Fingerabdruck mit dem NAS vergleichen und übernehmen.') . '</p>'
                    . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('hostkey-holen', ['id' => $id]) . '<button>Schlüssel des Servers abrufen</button></form> ';
                foreach ((array)($st['hostkeys'][$id] ?? []) as $k) {
                    $body .= '<form method="post" action="/verwaltung/verbindungen" class="box">' . $this->hidden('hostkey-uebernehmen', ['id' => $id, 'fingerprint' => (string)$k['fingerprint']])
                        . '<p>Abgerufen: ' . V::h((string)$k['art']) . ' <code>' . V::h((string)$k['fingerprint']) . '</code>. Nur übernehmen, wenn der Fingerabdruck mit der Anzeige des NAS übereinstimmt.</p>'
                        . '<button>Übernehmen</button></form>';
                }
            }
            $body .= '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('pruefen', ['art' => 'ziel', 'id' => $id]) . '<button>Verbindung prüfen</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('ziel-loeschen', ['id' => $id]) . '<button class="danger">Entfernen</button></form></div>';
        }
        // Feld mit Tooltip; $nur = nur bei dieser Art sichtbar (smb | sftp), leer = immer.
        $f = static fn(string $name, string $label, string $tip, string $type = 'text', string $value = '', string $extra = '', string $nur = '') => '<label' . ($nur !== '' ? ' data-nur="' . $nur . '"' : '') . '>'
            . V::h($label) . ' <span class="hilfe" tabindex="0" role="note" aria-label="' . V::h($tip) . '" data-tip="' . V::h($tip) . '">i</span>'
            . '<input type="' . $type . '" name="' . $name . '" value="' . V::h($value) . '" ' . $extra . '></label>';
        $body .= '<form method="post" action="/verwaltung/verbindungen" class="box wide" id="ziel-form"><h3>Ziel anlegen oder ändern</h3>' . $this->hidden('ziel')
            . '<p>Hier steht, wohin Module Dateien ablegen (z. B. Sicherungen, Export für das Steuerbüro). Beispiel: ein Synology-NAS. '
            . '<strong>SFTP</strong> ist besser: Es meldet sich mit einem Schlüssel statt eines Passworts an, die Verbindung ist verschlüsselt und es braucht keine Windows-Freigabe. '
            . '<strong>SMB</strong> nimmt man, wenn das Gerät nur Windows-Freigaben kann.</p>'
            . '<p class="muted">Synology: SFTP unter Systemsteuerung → Dateidienste → FTP → „SFTP-Dienst aktivieren“ (Port 22), einen eigenen Benutzer mit Zugriff auf den Ordner anlegen und den öffentlichen Schlüssel von unten eintragen. '
            . 'Für SMB genügt die Freigabe unter Systemsteuerung → Gemeinsamer Ordner. Menünamen können je nach DSM-Version abweichen und sind nicht gegen dein Gerät geprüft.</p>'
            . '<label>Art <span class="hilfe" tabindex="0" role="note" aria-label="SFTP: Anmeldung per Schlüssel, empfohlen. SMB: Windows-Freigabe mit Benutzer und Passwort." data-tip="SFTP: Anmeldung per Schlüssel, empfohlen. SMB: Windows-Freigabe mit Benutzer und Passwort.">i</span>'
            . '<select name="art"><option value="sftp">SFTP (empfohlen, z. B. Synology-NAS)</option><option value="smb">SMB (Windows-Freigabe)</option></select></label>'
            . $f('id', 'Kennung', 'Kurzer interner Name ohne Leerzeichen, nur kleine Buchstaben, Ziffern und Bindestrich, z. B. „steuerbuero“ oder „nas-sicherung“. Module merken sich das Ziel unter dieser Kennung. Mit gleicher Kennung ändert man ein vorhandenes Ziel (leeres Passwort = unverändert).', 'text', '', 'pattern="[a-z][a-z0-9-]{1,30}" required placeholder="nas-sicherung"')
            . $f('name', 'Bezeichnung', 'Anzeigename für dich und die Mitarbeiter, frei wählbar, z. B. „Synology im Serverraum“. Erscheint in den Auswahllisten der Module.', 'text', '', 'required placeholder="Synology im Serverraum"')
            . $f('host', 'Server (Name oder IP)', 'Adresse des NAS im Praxisnetz, am besten die feste IP-Adresse (z. B. 192.0.2.20), weil Namen mit „.local“ auf dem Server oft nicht auflösbar sind.', 'text', '', 'required placeholder="192.0.2.20"')
            . $f('freigabe', 'Freigabe', 'Name des freigegebenen Ordners auf dem NAS (bei Synology: Name unter „Gemeinsamer Ordner“), z. B. „praxis“.', 'text', '', 'placeholder="praxis"', 'smb')
            . $f('port', 'Port', 'Port des SFTP-Dienstes. Meist 22; bei Synology 22, wenn nicht geändert.', 'number', '22', 'min="1" max="65535"', 'sftp')
            . $f('ordner', 'Basisordner', 'Ordner auf dem Ziel, unter dem die Module ihre Dateien ablegen, z. B. „Kienzle“ (wird angelegt, wenn möglich). Ohne Platzhalter und ohne „..“.', 'text', '', 'placeholder="Kienzle"')
            . $f('benutzer', 'Benutzer', 'Anmeldename am NAS. Besser ein eigener Benutzer nur für diese Ablage, der nur diesen Ordner schreiben darf.', 'text', '', 'autocomplete="off"')
            . $f('passwort', 'Passwort', 'Passwort des NAS-Benutzers für SMB. Wird nach dem Speichern nie wieder angezeigt; leer lassen = unverändert. SFTP braucht kein Passwort, sondern den Schlüssel unten.', 'password', '', 'autocomplete="new-password"', 'smb')
            . $f('domaene', 'Domäne (optional)', 'Nur bei einer Windows-Domäne nötig, sonst leer lassen.', 'text', '', '', 'smb')
            . '<label class="check" data-nur="smb"><input type="checkbox" name="gast" value="1"> Gastzugang (nicht empfohlen) <span class="hilfe" tabindex="0" role="note" aria-label="Ohne Benutzer und Passwort, jeder im Netz kann dort schreiben." data-tip="Ohne Benutzer und Passwort, jeder im Netz kann dort schreiben. Nur für ein abgeschottetes Testgerät.">i</span></label>'
            . '<button>Ziel speichern</button></form>'
            . ($st['sftp_schluessel'] !== '' ? '<p>Für SFTP diesen öffentlichen Schlüssel beim NAS-Benutzer eintragen (z. B. <code>~/.ssh/authorized_keys</code>):</p><pre class="notes">' . V::h($st['sftp_schluessel']) . '</pre>' : '')
            . '</section>';
        // Nutzung
        $rows = '';
        foreach ($st['nutzung'] as $u) $rows .= '<tr><td>' . V::h((string)$u['name']) . '</td><td>' . V::h((string)$u['verbindung']) . '</td><td>' . V::h((string)$u['zweck']) . '</td><td>' . (($u['pflicht'] ?? false) ? 'ja' : 'nein') . '</td></tr>';
        $body .= '<section><h2>Wer nutzt was</h2>' . ($rows !== '' ? '<table><tr><th>Modul</th><th>Verbindung</th><th>Wofür</th><th>Nötig</th></tr>' . $rows . '</table>' : '<p>Kein installiertes Modul nutzt eine zentrale Verbindung.</p>') . '</section>';
        $response = Response::html(V::page('Verbindungen', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
        return $st['pending'] ? $response->with('Refresh', '2') : $response;
    }

    private function connectionsAction(Request $r, string $admin): Response {
        $c = $this->con();
        $f = fn(string $k, int $n = 300) => $r->field($k, $n);
        try {
            switch ($f('aktion', 30)) {
                case 't2med':
                    $c->saveT2med(['base_url' => $f('base_url'), 'launch_urls' => $f('launch_urls', 3000), 'api_key' => $f('api_key'), 'ca_pem' => $f('ca_pem', 20000),
                        'ca_entfernen' => $f('ca_entfernen') === '1', 'pin' => $f('pin') === '1']);
                    $what = 't2med'; break;
                case 'mail':
                    $c->saveMail(['host' => $f('host'), 'port' => $f('port', 10), 'sicherheit' => $f('sicherheit', 20), 'benutzer' => $f('benutzer'), 'passwort' => $f('passwort'), 'absender' => $f('absender')]);
                    $what = 'mail'; break;
                case 'ziel':
                    $c->saveTarget(['id' => $f('id', 40), 'name' => $f('name', 80), 'art' => $f('art', 10), 'host' => $f('host'), 'freigabe' => $f('freigabe', 100), 'port' => $f('port', 10),
                        'ordner' => $f('ordner'), 'benutzer' => $f('benutzer', 100), 'passwort' => $f('passwort'), 'domaene' => $f('domaene', 100), 'gast' => $f('gast') === '1']);
                    $what = 'ziel ' . $f('id', 40); break;
                case 'ziel-loeschen': $c->removeTarget($f('id', 40)); $what = 'ziel-entfernt ' . $f('id', 40); break;
                case 'entfernen': $c->remove($f('was', 10)); $what = 'entfernt ' . $f('was', 10); break;
                case 'pruefen': $c->test($f('art', 10), $f('id', 40)); $what = 'pruefen ' . $f('art', 10) . ' ' . $f('id', 40); break;
                case 'testmail': $c->testMail($f('an', 300)); $what = 'testmail'; break;
                case 'zertifikat-holen': $c->fetchCertificate(); $what = 'zertifikat-holen'; break;
                case 'zertifikat-uebernehmen': $c->acceptCertificate($f('fingerprint', 100)); $what = 'zertifikat-uebernommen'; break;
                case 'hostkey-holen': $c->fetchHostkey($f('id', 40)); $what = 'hostkey-holen ' . $f('id', 40); break;
                case 'hostkey-uebernehmen': $c->acceptHostkey($f('id', 40), $f('fingerprint', 100)); $what = 'hostkey-uebernommen ' . $f('id', 40); break;
                default: throw new Problem('Unbekannte Aktion.', 422);
            }
        } catch (Problem $e) {
            return $this->connectionsPage($e->getMessage());
        }
        $this->audit->write($admin, 'verbindung', $what);
        return Response::redirect('/verwaltung/verbindungen');
    }

    // ---- Drucker ------------------------------------------------------------------------------------------------
    private function pr(): Printers {
        if (!$this->printers) throw new Problem('Die Druckerverwaltung ist auf diesem Server nicht verfügbar.', 503);
        return $this->printers;
    }

    private function shareBoxes(array $share): string {
        return V::checkbox('samba', '1', 'für Windows freigeben (Samba)', in_array('samba', $share, true))
            . V::checkbox('ipp', '1', 'für macOS und andere freigeben (IPP)', in_array('ipp', $share, true));
    }

    public const PRINTER_ROLES = ['buero' => 'Praxisdrucker (A4, Rechnungen)', 'bon' => 'Bondrucker (Belege)'];

    /** Zuordnung Rolle → Warteschlange (Verwaltung → Drucker); nur noch vorhandene Warteschlangen. */
    private function printerRoles(): array {
        $d = json_decode((string)$this->db->setting('drucker.rollen'), true);
        $names = $this->printers ? array_column($this->printers->status()['queues'], 'name') : [];
        $out = [];
        foreach (self::PRINTER_ROLES as $k => $_) $out[$k] = is_array($d) && is_string($d[$k] ?? null) && in_array($d[$k], $names, true) ? $d[$k] : '';
        return $out;
    }

    private function printersPage(?string $error = null): Response {
        $st = $this->pr()->status();
        $busy = $st['pending'];
        $dis = $busy ? ' disabled' : '';
        $body = V::error($error) . ($st['last'] ? '<p class="' . (($st['last']['ok'] ?? false) ? 'notice' : 'error') . '">' . V::h((string)($st['last']['meldung'] ?? '')) . '</p>' : '')
            . ($busy ? '<p class="notice">Vorgang läuft …</p>' : '') . ($st['error'] !== '' ? '<p class="error">' . V::h($st['error']) . '</p>' : '')
            . (!$st['service'] ? '<p class="error">Der Druckerdienst ist nicht eingerichtet. Bitte den Installer ausführen.</p>' : '')
            . '<p>Drucker werden einmal hier eingerichtet; alle Module können sie nutzen. Freigaben für die Arbeitsplätze wirken erst, '
            . 'wenn die <a href="/verwaltung/firewall">Firewall</a> Drucken (IPP 631 bzw. Windows-Freigabe 445) aus den Praxisnetzen zulässt.</p>';
        $rows = $virtualRows = '';
        foreach ($st['queues'] as $q) {
            $actions = '<form method="post" action="/verwaltung/drucker" class="inline">' . $this->hidden('testseite', ['name' => $q['name']]) . '<button' . $dis . '>Testseite</button></form> ';
            if ($q['managed']) {
                $actions .= '<form method="post" action="/verwaltung/drucker" class="inline">' . $this->hidden('freigabe', ['name' => $q['name']]) . $this->shareBoxes($q['share'])
                    . '<button' . $dis . '>Freigabe speichern</button></form> ';
                if ($q['modul'] === '') $actions .= '<form method="post" action="/verwaltung/drucker" class="inline">' . $this->hidden('entfernen', ['name' => $q['name']])
                    . '<button class="danger"' . $dis . '>Entfernen</button></form>';
            }
            $row = '<tr><td><strong>' . V::h($q['name']) . '</strong><br><small>' . V::h($q['description']) . '</small></td><td>' . V::h($q['address']) . '</td><td>'
                . V::h($q['state']) . '</td><td>' . ($q['modul'] !== '' ? 'Modul ' . V::h($this->modules->name($q['modul'])) : ($q['managed'] ? 'Suite' : 'fremd')) . '</td><td>' . $actions . '</td></tr>';
            if ($q['modul'] !== '') $virtualRows .= $row; else $rows .= $row;
        }
        $head = '<table><tr><th>Name</th><th>Adresse</th><th>Zustand</th><th>Von</th><th></th></tr>';
        $body .= '<h2>Drucker</h2><p class="muted">Echte Drucker und Bondrucker, die in der Praxis stehen.</p>' . ($rows !== '' ? $head . $rows . '</table>' : '<p>Noch keine.</p>')
            . '<h2>Virtuelle Drucker</h2><p class="muted">Legen Module an (z. B. „SumUp-Rechnung“, „Terminzettel“): In T2med oder anderen Programmen drucken, das Modul verarbeitet den Auftrag. Sie werden nicht von Hand angelegt.</p>'
            . ($virtualRows !== '' ? $head . $virtualRows . '</table>' : '<p>Noch keine.</p>')
            . '<form method="post" action="/verwaltung/drucker">' . $this->hidden('abgleichen') . '<button' . $dis . '>Virtuelle Drucker der Module abgleichen</button></form>';
        $roles = $this->printerRoles();
        $pick = '';
        foreach (self::PRINTER_ROLES as $k => $label) {
            $opts = '<option value="">– keiner –</option>';
            foreach ($st['queues'] as $q) if ($q['modul'] === '') $opts .= '<option value="' . V::h($q['name']) . '"' . ($roles[$k] === $q['name'] ? ' selected' : '') . '>' . V::h($q['name']) . '</option>';
            $pick .= '<label>' . V::h($label) . '<select name="' . $k . '">' . $opts . '</select></label>';
        }
        $body .= '<h2>Wofür welcher Drucker?</h2><p class="muted">Module drucken auf diese Zuordnung; in den Einstellungen der Module wird kein Drucker mehr gewählt.</p>'
            . '<form method="post" action="/verwaltung/drucker" class="box">' . $this->hidden('rollen') . $pick . '<button>Zuordnung speichern</button></form>';
        $jobRows = '';
        foreach ($st['jobs'] as $j) {
            $jobRows .= '<tr><td><code>' . V::h($j['id']) . '</code></td><td>' . V::h($j['drucker']) . '</td><td>' . V::h($j['benutzer']) . '</td><td>' . max(1, (int)($j['groesse'] / 1024)) . ' KB</td><td>'
                . V::h($j['zeit']) . '</td><td><form method="post" action="/verwaltung/drucker" class="inline">' . $this->hidden('abbrechen', ['auftrag' => $j['id']])
                . '<button class="danger"' . $dis . '>Abbrechen</button></form></td></tr>';
        }
        $body .= '<h2>Druckaufträge</h2>' . ($jobRows !== '' ? '<table><tr><th>Auftrag</th><th>Drucker</th><th>Von</th><th>Größe</th><th>Seit</th><th></th></tr>' . $jobRows . '</table>'
            . implode('', array_map(fn($q) => '<form method="post" action="/verwaltung/drucker" class="inline">' . $this->hidden('alle-abbrechen', ['name' => $q])
                . '<button' . $dis . '>Alle Aufträge von ' . V::h($q) . ' abbrechen</button></form> ', array_values(array_unique(array_column($st['jobs'], 'drucker'))))) : '<p>Keine offenen Aufträge.</p>');
        $kinds = implode('', array_map(fn($k, $l) => '<option value="' . V::h($k) . '">' . V::h($l) . '</option>', array_keys(Printers::KINDS), Printers::KINDS));
        $found = '';
        foreach ($st['found'] as $f) {
            $found .= '<tr><td>' . V::h($f['name']) . '</td><td>' . V::h($f['connection']) . '</td><td><form method="post" action="/verwaltung/drucker" class="inline">'
                . $this->hidden('anlegen', ['adresse' => $f['uri']]) . '<select name="art">'
                . implode('', array_map(fn($k, $l) => '<option value="' . V::h($k) . '"' . ($f['kind'] === $k ? ' selected' : '') . '>' . V::h($l) . '</option>', array_keys(Printers::KINDS), Printers::KINDS))
                . '</select> ' . V::input('name', 'Name', 'text', '', 'maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_\-]*"') . '<button' . $dis . '>Übernehmen</button></form></td></tr>';
        }
        $body .= '<h2>Drucker suchen</h2><form method="post" action="/verwaltung/drucker">' . $this->hidden('suchen') . '<button' . $dis . '>Netzwerk und USB durchsuchen</button></form>'
            . ($st['searched'] ? '<p><small>Zuletzt gesucht: ' . V::date($st['searched']) . '</small></p>' : '')
            . ($found !== '' ? '<table><tr><th>Gefunden</th><th>Anschluss</th><th></th></tr>' . $found . '</table>' : '');
        $body .= '<h2>Drucker von Hand eintragen</h2><form method="post" action="/verwaltung/drucker" class="box">' . $this->hidden('anlegen')
            . '<label>Art<select name="art">' . $kinds . '</select></label>'
            . V::input('adresse', 'IP-Adresse oder Name des Druckers', 'text', '', 'placeholder="192.0.2.80" required maxlength="300"')
            . V::input('name', 'Name der Warteschlange (leer = Vorschlag)', 'text', '', 'maxlength="40"') . $this->shareBoxes([])
            . '<button' . $dis . '>Anlegen</button></form>';
        $net = $st['net'];
        $body .= '<h2>Windows-Netz</h2>';
        $body .= '<p>Arbeitsgruppe: <strong>' . V::h($net['arbeitsgruppe']) . '</strong>. Hinweis: Windows 11 Pro lässt Gastzugriff auf Windows-Freigaben ab 24H2 '
            . 'nicht mehr zu; dort besser über IPP drucken.</p>'
            . '<form method="post" action="/verwaltung/drucker" class="box">' . $this->hidden('arbeitsgruppe')
            . V::input('arbeitsgruppe', 'Arbeitsgruppe', 'text', $net['arbeitsgruppe'], 'required maxlength="15" pattern="[A-Za-z0-9][A-Za-z0-9\\-]*"')
            . '<button' . $dis . '>Speichern</button></form>';
        $response = Response::html(V::page('Drucker', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
        return $busy ? $response->with('Refresh', '3') : $response;
    }

    private function saveRoles(Request $r): void {
        $names = array_column($this->pr()->status()['queues'], 'name');
        $out = [];
        foreach (self::PRINTER_ROLES as $k => $_) {
            $v = $r->field($k, 40);
            if ($v !== '' && !in_array($v, $names, true)) throw new Problem('Unbekannter Drucker.', 422);
            $out[$k] = $v;
        }
        $this->db->store('drucker.rollen', json_encode($out, JSON_THROW_ON_ERROR));
    }

    private function printersAction(Request $r, string $admin): Response {
        $p = $this->pr();
        $a = $r->field('aktion', 20);
        $share = array_keys(array_filter(['samba' => $r->field('samba') === '1', 'ipp' => $r->field('ipp') === '1']));
        try {
            match ($a) {
                'suchen' => $p->search(),
                'rollen' => $this->saveRoles($r),
                'anlegen' => $p->add($r->field('art', 20), $r->field('name', 60), $r->field('adresse', 400), $share),
                'entfernen' => $p->remove($r->field('name', 60)),
                'testseite' => $p->testPage($r->field('name', 60)),
                'freigabe' => $p->share($r->field('name', 60), $share),
                'abgleichen' => $p->sync(),
                'abbrechen' => $p->cancelJob($r->field('auftrag', 60)),
                'alle-abbrechen' => $p->cancelAll($r->field('name', 60)),
                'arbeitsgruppe' => $p->setWorkgroup($r->field('arbeitsgruppe', 20)),
                default => throw new Problem('Unbekannte Aktion.', 422),
            };
        } catch (Problem $e) {
            if ($e->http === 422 || $e->http === 409 || $e->http === 404) return $this->printersPage($e->getMessage());
            throw $e;
        }
        $this->audit->write($admin, 'drucker-' . $a, $a === 'abbrechen' ? $r->field('auftrag', 60) : ($a === 'arbeitsgruppe' ? $r->field('arbeitsgruppe', 20) : $r->field('name', 60)));
        return Response::redirect('/verwaltung/drucker');
    }

    // ---- Einrichtungs-Überblick ----------------------------------------------------------------------------------
    private function ov(): Overview {
        return $this->overview ?? new Overview($this->db, $this->users, $this->modules, $this->firewall, $this->updates, $this->connections, $this->backup, $this->config);
    }

    private function overviewList(array $items): string {
        $rows = '';
        foreach ($items as $i) {
            $class = ['done' => 'done', 'open' => 'open', 'error' => 'error', 'info' => 'info'][$i['state']] ?? 'open';
            $icon = ['done' => '✓', 'error' => '!', 'info' => 'i'][$i['state']] ?? '○';
            $title = $i['link'] !== '' ? '<a href="' . V::h($i['link']) . '">' . V::h($i['title']) . '</a>' : V::h($i['title']);
            $manual = !empty($i['manuell']) ? '<form method="post" action="/verwaltung/einrichtung" class="inline">'
                . $this->hidden('bestaetigen', ['punkt' => $i['manuell'], 'erledigt' => $i['state'] === 'done' ? '0' : '1'])
                . '<button class="secondary">' . ($i['state'] === 'done' ? 'Zurücknehmen' : 'Erledigt') . '</button></form>' : '';
            $rows .= '<li class="' . $class . '"><span class="mark-icon" aria-hidden="true">' . $icon . '</span><span class="text">' . $title
                . ($i['hint'] !== '' ? '<small>' . V::h($i['hint']) . '</small>' : '') . '</span>' . $manual . '</li>';
        }
        return '<ul class="checklist">' . $rows . '</ul>';
    }

    private function overviewPage(): Response {
        $o = $this->ov();
        $core = $o->core();
        $modules = $o->modules();
        $all = array_merge($core, ...array_map(fn($m) => $m['items'], $modules));
        $done = count(array_filter($all, fn($i) => $i['state'] === 'done'));
        $pct = $all ? (int)round(100 * $done / count($all)) : 100;
        $body = '<p class="lead">' . $done . ' von ' . count($all) . ' Schritten erledigt</p>'
            . '<progress class="progress" value="' . $pct . '" max="100" aria-label="Fortschritt der Einrichtung">' . $pct . ' %</progress>'
            . '<section class="card"><h2>Basis</h2><p class="muted">Diese Schritte sind nur einmal nötig, geordnet nach dem Ort, an dem sie erledigt werden. '
            . 'Ein Klick auf einen Punkt öffnet die passende Seite.</p>';
        foreach (Overview::PLACES as $place => [$label, $hint]) {
            $items = array_values(array_filter($core, fn($i) => ($i['ort'] ?? 'web') === $place));
            if ($items) $body .= '<h3 class="check-group">' . V::h($label) . ' <small>· ' . V::h($hint) . '</small></h3>' . $this->overviewList($items);
        }
        $body .= '</section>';
        foreach ($modules as $m) {
            $body .= '<section class="card"><h2>' . V::h($m['name']) . '</h2>' . ($m['fehler'] !== '' ? '<p class="error">Überblick nicht lesbar: ' . V::h($m['fehler']) . '</p>' : '')
                . ($m['items'] ? $this->overviewList($m['items']) : '') . ($m['zeit'] ? '<p class="muted">Stand ' . V::date($m['zeit']) . '</p>' : '') . '</section>';
        }
        if ($modules) $body .= '<form method="post" action="/verwaltung/einrichtung">' . $this->hidden('neu') . '<button class="secondary">Module neu prüfen</button></form>';
        return Response::html(V::page('Überblick', $body, $this->nav(), $this->config->practice));
    }

    private function overviewAction(Request $r, string $admin): Response {
        $a = $r->field('aktion', 20);
        if ($a === 'bestaetigen') {
            $this->ov()->confirm($r->field('punkt', 20), $r->field('erledigt') === '1');
            $this->audit->write($admin, 'einrichtung-' . ($r->field('erledigt') === '1' ? 'erledigt' : 'zurueck'), $r->field('punkt', 20));
        } elseif ($a === 'neu') {
            $this->ov()->refresh();
        } else {
            throw new Problem('Unbekannte Aktion.', 422);
        }
        return Response::redirect('/verwaltung/einrichtung');
    }

    // ---- Sicherung ----------------------------------------------------------------------------------------------
    private function bk(): Backup {
        if (!$this->backup) throw new Problem('Die Sicherung ist auf diesem Server nicht verfügbar.', 503);
        return $this->backup;
    }

    private function result(?array $r, string $label): string {
        if (!$r) return '<tr><td>' . V::h($label) . '</td><td>–</td></tr>';
        return '<tr><td>' . V::h($label) . '</td><td class="' . (($r['ok'] ?? false) ? 'ok' : 'error') . '">' . V::date((int)($r['zeit'] ?? 0)) . ' – '
            . (($r['ok'] ?? false) ? 'in Ordnung' : 'Fehler') . (isset($r['meldung']) ? ': ' . V::h((string)$r['meldung']) : '')
            . (isset($r['bytes']) ? ' (' . (int)($r['bytes'] / 1048576) . ' MB geprüft, ' . (int)(($r['neu'] ?? 0) / 1048576) . ' MB neu)' : '') . '</td></tr>';
    }

    private function backupPage(?string $error = null): Response {
        $st = $this->bk()->status();
        $busy = $st['pending'] || $st['laeuft'] !== '';
        $dis = $busy ? ' disabled' : '';
        $form = fn(string $action, string $label, string $class = '', array $extra = []) => '<form method="post" action="/verwaltung/sicherung" class="inline">'
            . $this->hidden($action, $extra) . '<button' . ($class ? ' class="' . $class . '"' : '') . $dis . '>' . V::h($label) . '</button></form> ';
        $body = V::error($error)
            . ($st['letzte'] ? '<p class="' . ($st['letzte']['ok'] ? 'notice' : 'error') . '">' . V::h((string)$st['letzte']['meldung']) . '</p>' : '')
            . ($busy ? '<p class="notice">Vorgang läuft: ' . V::h($st['laeuft'] ?: 'wird übernommen') . ' …</p>' : '')
            . implode('', array_map(fn($w) => '<p class="error">' . V::h($w) . '</p>', $st['warnungen']))
            . (!$st['service'] ? '<p class="error">Der Sicherungsdienst ist nicht eingerichtet. Bitte den Installer ausführen.</p>' : '');
        if (!$st['eingerichtet']) {
            $targets = $this->connections ? $this->connections->status()['ablage'] : [];
            $opts = implode('', array_map(fn($t) => '<option value="' . V::h((string)$t['id']) . '">' . V::h((string)$t['name']) . ' (' . V::h(strtoupper((string)$t['art'])) . ')</option>', $targets));
            $body .= '<p>Die Sicherung wird auf dem Server verschlüsselt (restic). Das Passwort wird zufällig erzeugt und einmal auf einem Schlüsselblatt '
                . 'ausgegeben – ohne dieses Blatt lässt sich die Sicherung nach einem Totalausfall nicht lesen.</p>'
                . '<form method="post" action="/verwaltung/sicherung" class="box wide">' . $this->hidden('einrichten')
                . '<label class="check"><input type="radio" name="art" value="ziel" checked> Netzwerkspeicher aus „Verbindungen“ (SFTP empfohlen)</label>'
                . ($opts !== '' ? '<label>Ziel<select name="id">' . $opts . '</select></label>' : '<p class="error">Noch kein Ziel unter <a href="/verwaltung/verbindungen">Verbindungen</a> eingetragen.</p>')
                . V::input('ordner', 'Unterordner auf dem Ziel', 'text', 'kienzle-sicherung')
                . '<label class="check"><input type="radio" name="art" value="rest"> restic-REST-Server (z. B. im Nur-Anfügen-Modus auf dem NAS)</label>'
                . V::input('url', 'Adresse', 'url', '', 'placeholder="https://nas.example.org:8000/kienzle"') . V::input('benutzer', 'Benutzer (REST)', 'text')
                . V::input('passwort', 'Passwort (REST)', 'password', '', 'autocomplete="new-password"')
                . '<label class="check"><input type="radio" name="art" value="lokal"> Ordner auf diesem Server (z. B. eingehängte USB-Platte)</label>'
                . V::input('pfad', 'Ordner', 'text', '', 'placeholder="/media/sicherung"')
                . '<button' . $dis . '>Sicherung einrichten</button></form>';
            $body .= $this->exportSection($st, $dis);
            $response = Response::html(V::page('Sicherung', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
            return $busy ? $response->with('Refresh', '3') : $response;
        }
        if ($st['blatt']) {
            $body .= '<div class="box wide notice"><p><strong>Schlüsselblatt jetzt drucken</strong> und im Praxistresor aufbewahren. Es enthält das Passwort der Sicherung.</p>'
                . '<p><a class="button" href="/verwaltung/sicherung/blatt" target="_blank" rel="noopener">Blatt anzeigen und drucken</a></p>'
                . $form('blatt-gedruckt', 'Blatt ist gedruckt – vom Server löschen') . '</div>';
        }
        $body .= '<table>' . $this->result($st['letzte_sicherung'], 'Letzte Sicherung') . $this->result($st['letzte_pruefung'], 'Letzte Prüfung')
            . $this->result($st['letzte_probe'], 'Probe-Wiederherstellung') . $this->result($st['letztes_aufraeumen'], 'Aufräumen') . '</table>'
            . '<p>Täglich nachts sichern, sonntags aufräumen und prüfen, am ersten Sonntag im Monat zusätzlich lesen und probeweise wiederherstellen.</p><p>'
            . $form('jetzt', 'Jetzt sichern') . $form('pruefen', 'Prüfen') . $form('pruefen', 'Prüfen und 5 % lesen', '', ['lesen' => '1'])
            . $form('probe', 'Probe-Wiederherstellung') . $form('aufraeumen', 'Aufräumen') . '</p>';
        $body .= '<h2>Schutz vor Erpressungstrojanern</h2><form method="post" action="/verwaltung/sicherung" class="box wide">' . $this->hidden('nas-schnappschuesse')
            . '<p>Wer den Server übernimmt, könnte Sicherungen löschen. Schutz: schreibgeschützte Schnappschüsse des Sicherungsordners <strong>am NAS</strong> '
            . '(z. B. täglich, 30 Tage) oder ein REST-Server im Nur-Anfügen-Modus. Das wird am NAS eingerichtet, nicht hier.</p>'
            . V::checkbox('bestaetigt', '1', 'Am NAS sind schreibgeschützte Schnappschüsse eingerichtet', !in_array('Schreibgeschützte Schnappschüsse am NAS sind nicht als eingerichtet bestätigt (Schutz vor Erpressungstrojanern).', $st['warnungen'], true))
            . '<button' . $dis . '>Speichern</button></form>';
        $z = $st['zeitplan'];
        $sel = fn(string $v, string $cur) => $v === $cur ? ' selected' : '';
        $days = '';
        foreach (['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'] as $i => $d) $days .= '<option value="' . $i . '"' . $sel((string)$i, (string)$z['wochentag']) . '>' . $d . '</option>';
        $body .= '<h2>Zeitplan</h2><form method="post" action="/verwaltung/sicherung" class="box">' . $this->hidden('zeitplan')
            . '<label>Häufigkeit<select name="haeufigkeit"><option value="taeglich"' . $sel('taeglich', $z['haeufigkeit']) . '>täglich</option>'
            . '<option value="woechentlich"' . $sel('woechentlich', $z['haeufigkeit']) . '>wöchentlich</option><option value="monatlich"' . $sel('monatlich', $z['haeufigkeit']) . '>monatlich</option></select></label>'
            . V::input('uhrzeit', 'Uhrzeit', 'time', $z['uhrzeit'], 'required') . '<label>Wochentag (bei wöchentlich)<select name="wochentag">' . $days . '</select></label>'
            . V::input('tag', 'Tag im Monat (bei monatlich, 1–28)', 'number', (string)$z['tag'], 'min="1" max="28"')
            . '<p>Aufräumen und Prüfen laufen höchstens wöchentlich, Lesen und Probe-Wiederherstellung höchstens monatlich – jeweils im Anschluss an eine Sicherung.</p>'
            . '<button' . $dis . '>Zeitplan speichern</button></form>';
        $body .= '<h2>Benachrichtigung bei Fehlern</h2><form method="post" action="/verwaltung/sicherung" class="box">' . $this->hidden('mail-an')
            . V::input('an', 'E-Mail-Empfänger (bis zu 5, durch Komma getrennt; leer = keine)', 'text', implode(', ', $st['mail_an']), 'maxlength="1300"')
            . '<p>Verschickt über die <a href="/verwaltung/verbindungen">zentrale E-Mail-Verbindung</a>, wenn Sicherung, Prüfung oder Probe fehlschlagen.</p>'
            . ($st['letzte_mail'] && !($st['letzte_mail']['ok'] ?? false) ? '<p class="error">Letzter Versand fehlgeschlagen: ' . V::h((string)($st['letzte_mail']['meldung'] ?? '')) . '</p>' : '')
            . '<button' . $dis . '>Speichern</button></form>';
        $body .= '<h2>Schlüsselblatt</h2><p>Passwort der Sicherung, SSH-Schlüssel für das NAS und Fingerabdrücke zum Ausdrucken für den Tresor.</p>'
            . $form('blatt-neu', 'Schlüsselblatt neu erzeugen');
        $body .= '<h2>Aufbewahrung</h2><form method="post" action="/verwaltung/sicherung" class="box">' . $this->hidden('aufbewahren')
            . V::input('daily', 'Tägliche', 'number', '7', 'min="0" max="1000"') . V::input('weekly', 'Wöchentliche', 'number', '4', 'min="0" max="1000"')
            . V::input('monthly', 'Monatliche', 'number', '12', 'min="0" max="1000"') . V::input('yearly', 'Jährliche', 'number', '3', 'min="0" max="1000"')
            . '<button' . $dis . '>Speichern</button></form>';
        $rows = '';
        $parts = '';
        foreach ($st['teile'] as $id => $name) $parts .= '<option value="' . V::h($id) . '">' . V::h($name) . '</option>';
        foreach (array_reverse(array_slice($st['schnappschuesse'], -20)) as $snap) {
            $rows .= '<tr><td><code>' . V::h((string)$snap['id']) . '</code></td><td>' . V::h(str_replace('T', ' ', (string)$snap['zeit'])) . '</td><td>'
                . '<form method="post" action="/verwaltung/sicherung" class="inline">' . $this->hidden('wiederherstellen', ['snapshot' => (string)$snap['id']])
                . '<select name="teil">' . $parts . '</select> <button class="danger"' . $dis . '>Wiederherstellen</button></form></td></tr>';
        }
        $body .= '<h2>Vorhandene Sicherungen</h2>' . $form('schnappschuesse', 'Liste aktualisieren')
            . ($rows !== '' ? '<p><strong>Achtung:</strong> Wiederherstellen ersetzt die Daten des gewählten Teils durch den Stand der Sicherung; spätere Änderungen gehen verloren.</p>'
                . '<table><tr><th>Sicherung</th><th>Zeit</th><th></th></tr>' . $rows . '</table>' : '<p>Noch keine.</p>');
        $body .= $this->exportSection($st, $dis);
        $response = Response::html(V::page('Sicherung', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
        return $busy ? $response->with('Refresh', '3') : $response;
    }

    private function exportSection(array $st, string $dis): string {
        $e = $st['export'];
        $out = '<h2>Einstellungen exportieren</h2><p>Alle Einstellungen der Basis und der Module (Zertifizierungsstelle, Schlüssel, Verbindungen, '
            . 'Firewall, Einstellungen der Module, auf Wunsch die Benutzerkonten) in einer Datei, verschlüsselt mit einem Passwort Ihrer Wahl (Argon2id, XChaCha20-Poly1305). '
            . 'Ohne das Passwort ist die Datei wertlos – es wird nirgends gespeichert. Einspielen an der Konsole: '
            . '<code>sudo kienzle-sicherung einstellungen-einspielen DATEI</code>.</p>';
        if ($e) {
            $out .= '<div class="box wide notice"><p>Bereit: <strong>' . V::h($e['datei']) . '</strong> (' . V::date($e['zeit']) . ', ' . $e['dateien'] . ' Dateien, '
                . max(1, (int)($e['groesse'] / 1024)) . ' KB' . ($e['mit_sicherungspasswort'] ? ', mit Sicherungspasswort' : '') . '). Wird nach 24 Stunden gelöscht.</p>'
                . '<p><a class="button" href="/verwaltung/sicherung/export">Herunterladen</a> '
                . '<form method="post" action="/verwaltung/sicherung" class="inline">' . $this->hidden('export-loeschen') . '<button' . $dis . '>Jetzt löschen</button></form></p></div>';
        }
        return $out . '<form method="post" action="/verwaltung/sicherung" class="box">' . $this->hidden('export')
            . V::input('export_passwort', 'Passwort (mindestens 12 Zeichen)', 'password', '', 'autocomplete="new-password" minlength="12" required')
            . V::input('export_wiederholung', 'Passwort wiederholen', 'password', '', 'autocomplete="new-password" minlength="12" required')
            . V::checkbox('mit_benutzern', '1', 'Benutzerkonten mit Rechten, Authenticator-Apps und Passkeys mit exportieren', true)
            . V::checkbox('mit_sicherungspasswort', '1', 'Passwort der Datensicherung mit exportieren', false)
            . '<button' . $dis . '>Exportieren</button></form>';
    }

    private function backupAction(Request $r, string $admin): Response {
        $b = $this->bk();
        $a = $r->field('aktion', 30);
        try {
            if ($a === 'einrichten') {
                $b->setup(['art' => $r->field('art', 10), 'id' => $r->field('id', 40), 'ordner' => $r->field('ordner'), 'url' => $r->field('url'), 'benutzer' => $r->field('benutzer', 100),
                    'passwort' => $r->field('passwort'), 'pfad' => $r->field('pfad')], $this->config->practice);
            } elseif ($a === 'aufbewahren') {
                $keep = [];
                foreach (['daily', 'weekly', 'monthly', 'yearly'] as $k) {
                    if (!ctype_digit($r->field($k, 5))) throw new Problem('Aufbewahrung: bitte ganze Zahlen.', 422);
                    $keep[$k] = (int)$r->field($k, 5);
                }
                $b->action('aufbewahren', ['aufbewahren' => $keep]);
            } elseif ($a === 'zeitplan') {
                $b->setSchedule($r->field('haeufigkeit', 20), $r->field('uhrzeit', 5), $r->field('wochentag', 2) ?: '6', $r->field('tag', 2) ?: '1');
            } elseif ($a === 'mail-an') {
                $b->setRecipients($r->field('an', 1300));
            } elseif ($a === 'blatt-neu') {
                $b->action('blatt-neu', ['praxis' => $this->config->practice]);
            } elseif ($a === 'export') {
                $b->export($r->field('export_passwort', 300), $r->field('export_wiederholung', 300), $r->field('mit_sicherungspasswort') === '1', $r->field('mit_benutzern') === '1');
            } elseif ($a === 'nas-schnappschuesse') {
                $b->action($a, ['bestaetigt' => $r->field('bestaetigt') === '1']);
            } elseif ($a === 'pruefen') {
                $b->action($a, ['lesen' => $r->field('lesen') === '1']);
            } elseif ($a === 'wiederherstellen') {
                $b->action($a, ['snapshot' => $r->field('snapshot', 20), 'teil' => $r->field('teil', 40)]);
            } else {
                $b->action($a);
            }
        } catch (Problem $e) {
            return $this->backupPage($e->getMessage());
        }
        $this->audit->write($admin, 'sicherung-' . $a, $a === 'wiederherstellen' ? $r->field('teil', 40) . ' ' . $r->field('snapshot', 20) : '');
        return Response::redirect('/verwaltung/sicherung');
    }

    // ---- Hilfen -------------------------------------------------------------------------------------------------
    private function openSteps(): int {
        try {
            return count(array_filter($this->ov()->core(), fn($i) => in_array($i['state'], ['open', 'error'], true)));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Wer ein Modul installiert hat, wird beim ersten Aufruf nach der Installation dessen Admin (einmalig; weitere Rechte vergibt er unter Benutzer). */
    private function claimFirstAdmin(int $uid): void {
        foreach ($this->db->query("SELECT key,value FROM settings WHERE key LIKE 'erstadmin:%'")->fetchAll() as $row) {
            $id = substr($row['key'], 10);
            if (!$this->modules->has($id)) continue;
            if ((int)$row['value'] === $uid && !isset($this->users->access($uid)[$id])) $this->users->setAccess($uid, $id, true, []);
            $this->db->query('DELETE FROM settings WHERE key=?', [$row['key']]);
        }
    }

    private function nav(): ?array {
        if (($this->session['stage'] ?? '') !== 'full') return null;
        $this->claimFirstAdmin((int)$this->session['user_id']);
        $admin = (int)$this->session['user_admin'] === 1;
        $apps = $settings = [];
        foreach ($this->users->access((int)$this->session['user_id']) as $id => $a) {
            if (($p = $this->modules->webPath($id)) === null) continue;
            $apps[$p . '/'] = $this->modules->name($id);
            if ($a['admin'] && ($s = $this->modules->settingsUrl($id)) !== null) $settings[$s] = $this->modules->name($id);
        }
        return ['apps' => $apps, 'settings' => $settings, 'login' => $this->session['login'], 'admin' => $admin, 'csrf' => $this->session['csrf'],
            'updates' => $admin && $this->updates ? $this->updates->count() : 0, 'module' => $admin && $this->store ? $this->store->count() : 0, 'sicherung' => $admin && $this->backup ? $this->backup->count() : 0,
            'pfad' => $this->req?->path ?? '', 'einrichtung' => $admin ? $this->openSteps() : 0];
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
        // „null“ schicken Browser z. B. nach einer Weiterleitung oder bei strenger Referrer-Policy; dann zählt Sec-Fetch-Site.
        if ($origin !== '' && $origin !== 'null') {
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
            ->with('Referrer-Policy', 'same-origin')   // „no-referrer“ ließe Browser bei Formularen „Origin: null“ senden
            ->with('X-Frame-Options', 'DENY')
            ->with('Cross-Origin-Opener-Policy', 'same-origin')
            ->with('Cross-Origin-Resource-Policy', 'same-origin')
            ->with('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), publickey-credentials-get=(self), publickey-credentials-create=(self)');
        if ($response->header('Content-Security-Policy') === null && $response->header('Content-Type') !== null && str_starts_with((string)$response->header('Content-Type'), 'text/html')) {
            $response->with('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        }
        return $response;
    }
}
