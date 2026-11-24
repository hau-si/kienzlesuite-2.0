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
            case '/passwort-vergessen': return $get ? $this->forgotPage() : $this->forgot($r);
            case '/einrichten': return $get ? $this->setupPage($r->get('t', 60)) : $this->setup($r);
            case '/abmelden': if (!$get) return $this->logout($r); break;
            case '/hilfe/arbeitsplatz': if ($get) return $this->workstationHelp(); break;
            case '/uhrzeit/korrigieren': if (!$get && $this->session) return $this->clockFix($r); break;
            case '/oidc/authorize': if ($get && $this->oidc && $stage !== 'full') return Response::redirect('/anmelden?weiter=/oidc/fortsetzen/' . $this->oidc->park($r->query)); break;
            case '/oidc/abmelden': if ($this->oidc && $stage !== 'full') return Response::redirect($this->oidc->logoutTarget($r->get('client_id', 100) ?: $this->oidc->clientFromIdToken($r->get('id_token_hint', 4000)), $r->get('post_logout_redirect_uri', 300)) ?? '/anmelden'); break;
        }
        if ($stage === 'otp') return $r->path === '/anmelden/code' ? ($get ? $this->codePage() : $this->code($r)) : ($r->path === '/anmelden/code/sms' && !$get ? $this->smsCodeSend() : Response::redirect('/anmelden/code'));
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
            'POST /konto/telefon' => fn() => $this->savePhone($r),
            'POST /konto/email' => fn() => $this->saveEmail($r),
            'POST /konto/name' => fn() => $this->saveName($r),
            'POST /konto/email-code' => fn() => $this->confirmEmail($r),
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
            . 'eine FRITZ!Box z. B. als <code>rechnername.fritz.box</code>. Dann unter „Server“ genau diesen Namen mit der Endung „.fritz.box“ wählen. '
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
            . '<h2>Servername</h2>'
            . '<form method="post" action="/verwaltung/server">' . $this->hidden('speichern')
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
            . '<h2>Name, Anschrift, Konto und Steuer der Praxis</h2><p class="muted">Stehen gemeinsam unter <a href="/verwaltung/praxis">Praxis</a>.</p>'
            . '<h2>Danach</h2><ol class="steps"><li>Den Namen im Router eintragen – oder auf jedem PC in der Datei hosts: '
            . '<a href="/hilfe/arbeitsplatz#name">Anleitung</a>.</li><li>Die Suite unter <strong>' . V::h($address) . '</strong> öffnen und dort neu anmelden.</li>'
            . '<li>Falls schon Passkeys eingerichtet sind: unter „Mein Konto“ neu anlegen (sie gelten für den alten Namen).</li></ol>'
            . '<p class="muted">Diese drei Schritte bestätigst du von Hand unter <a href="/verwaltung/einrichtung">Einrichtung</a>; erst dann gilt „Servername festgelegt“ als erledigt.</p>';
        $response = Response::html(V::page('Server', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
        return $st['pending'] ? $response->with('Refresh', '3') : $response;
    }

    /** Anschrift der Praxis (Straße, PLZ/Ort, Kontakt); der Name steht in der Serverkonfiguration. */
    private function address(): array {
        $d = json_decode((string)$this->db->setting('praxis.anschrift'), true);
        $out = [];
        foreach (['street', 'city', 'contact'] as $k) $out[$k] = is_array($d) && is_string($d[$k] ?? null) ? $d[$k] : '';
        return $out;
    }

    private const MASTER = ['holder' => 70, 'iban' => 42, 'bic' => 11, 'bank_name' => 100, 'tax_id' => 40];

    /** Kontodaten und Steuerangaben der Praxis (Kontoinhaber, IBAN, BIC, Bank, Steuernummer/USt-IdNr., Kleinunternehmer). */
    private function master(): array {
        $d = json_decode((string)$this->db->setting('praxis.stammdaten'), true);
        $out = [];
        foreach (self::MASTER as $k => $_) $out[$k] = is_array($d) && is_string($d[$k] ?? null) ? $d[$k] : '';
        $out['small_business'] = is_array($d) && ($d['small_business'] ?? false) === true;
        return $out;
    }

    private function practicePage(?string $error = null, ?string $notice = null): Response {
        $a = $this->address(); $m = $this->master();
        $form = fn(string $kind, string $fields, string $button) => '<form method="post" action="/verwaltung/praxis">' . V::csrf($this->session['csrf'])
            . '<input type="hidden" name="aktion" value="' . $kind . '">' . $fields . '<button>' . $button . '</button></form>';
        $body = V::error($error) . V::notice($notice) . '<section class="card"><p class="muted">Diese Angaben gelten für alle Module (Rechnungen, Belege, Überweisungs-QR, Bons). </p>'
            . '<details class="fold" open><summary><h2>Name der Praxis</h2></summary>' . $form('name',
                V::input('praxis', 'Name der Praxis (erscheint auf Belegen, Bons und im Kopf der Suite)', 'text', $this->config->practice, 'maxlength="60" placeholder="Praxis Dr. Beispiel"'), 'Name speichern') . '</details>'
            . '<details class="fold"><summary><h2>Anschrift</h2></summary>' . $form('anschrift',
                V::input('street', 'Straße und Hausnummer', 'text', $a['street'], 'maxlength="80" autocomplete="street-address"')
                . V::input('city', 'PLZ und Ort', 'text', $a['city'], 'maxlength="80"')
                . V::input('contact', 'Kontakt auf dem Beleg (optional, z. B. Telefon)', 'text', $a['contact'], 'maxlength="120"'), 'Anschrift speichern') . '</details>'
            . '<details class="fold"><summary><h2>Kontodaten für Überweisungen</h2></summary>' . $form('konto',
                V::input('holder', 'Kontoinhaber', 'text', $m['holder'], 'maxlength="70"')
                . V::input('iban', 'IBAN', 'text', $m['iban'], 'maxlength="42" autocapitalize="characters"')
                . V::input('bic', 'BIC (optional)', 'text', $m['bic'], 'maxlength="11"')
                . V::input('bank_name', 'Bank (optional)', 'text', $m['bank_name'], 'maxlength="100"'), 'Kontodaten speichern') . '</details>'
            . '<details class="fold"><summary><h2>Steuer</h2></summary>' . $form('steuer',
                V::input('tax_id', 'Steuernummer oder USt-IdNr. (steht auf der Rechnung)', 'text', $m['tax_id'], 'maxlength="40"')
                . V::checkbox('small_business', '1', 'Kleinunternehmerregelung (§ 19 UStG): keine Umsatzsteuer ausweisen', $m['small_business']), 'Steuerangaben speichern') . '</details></section>';
        $filled = fn(bool $ok) => [$ok, 'ausgefüllt', 'nicht ausgefüllt'];
        V::$status = ['Name der Praxis' => [$this->config->practice !== '', 'eingetragen', 'nicht eingetragen'], 'Anschrift' => $filled($a['street'] !== '' && $a['city'] !== ''), 'Kontodaten für Überweisungen' => $filled($m['iban'] !== ''), 'Steuer' => $filled($m['tax_id'] !== '' || $m['small_business'])];
        return Response::html(V::page('Praxis', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function practiceSave(Request $r, string $admin): Response {
        $kind = $r->field('aktion', 20);
        try {
            if ($kind === 'anschrift') {
                $in = [];
                foreach (['street' => 80, 'city' => 80, 'contact' => 120] as $k => $max) {
                    $v = trim($r->field($k, $max + 1));
                    if (mb_strlen($v) > $max || preg_match('/[\x00-\x1f\x7f<>]/', $v)) throw new Problem('Anschrift: Angaben zu lang oder mit unerlaubten Zeichen.', 422);
                    $in[$k] = $v;
                }
                $this->db->store('praxis.anschrift', json_encode($in, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $this->audit->write($admin, 'praxisanschrift', '');
                return $this->practicePage(null, 'Anschrift gespeichert.');
            }
            if ($kind === 'name') {
                // Der Name steckt in der Serverkonfiguration (Root-Dienst): Servernamen bleiben unverändert, nur der Praxisname wechselt.
                $name = trim($r->field('praxis', 200));
                $this->srv()->save(implode("\n", $this->config->hostnames), $name, false);
                $this->audit->write($admin, 'praxisname', $name);
                return $this->practicePage(null, 'Name der Praxis wird übernommen und erscheint gleich überall.');
            }
            if (!in_array($kind, ['konto', 'steuer'], true)) throw new Problem('Unbekannte Aktion.', 400);
            $m = $this->master();
            foreach ($kind === 'konto' ? ['holder', 'iban', 'bic', 'bank_name'] : ['tax_id'] as $k) {
                $v = trim($r->field($k, self::MASTER[$k] + 1));
                if (mb_strlen($v) > self::MASTER[$k] || preg_match('/[\x00-\x1f\x7f<>]/', $v)) throw new Problem('Angaben zu lang oder mit unerlaubten Zeichen: ' . $k . '.', 422);
                $m[$k] = $v;
            }
            if ($kind === 'steuer') $m['small_business'] = $r->field('small_business') === '1';
            if ($m['iban'] !== '' && !self::validIban($m['iban'])) throw new Problem('Die IBAN ist ungültig (Prüfziffer stimmt nicht).', 422);
            $this->db->store('praxis.stammdaten', json_encode($m, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->audit->write($admin, 'praxisdaten-gespeichert', $kind);
        } catch (Problem $e) {
            return $this->practicePage($e->getMessage());
        }
        return $this->practicePage(null, $kind === 'konto' ? 'Kontodaten gespeichert.' : 'Steuerangaben gespeichert.');
    }

    private static function validIban(string $iban): bool {
        $iban = strtoupper((string)preg_replace('/\s+/', '', $iban));
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $iban)) return false;
        $n = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $c) $n .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c;
        $rest = 0;
        foreach (str_split($n, 7) as $part) $rest = (int)($rest . $part) % 97;
        return $rest === 1;
    }

    private function serverSave(Request $r, string $admin): Response {
        $short = strtolower(trim($r->field('kurzname', 60)));
        $suffix = $r->field('endung', 30);
        $names = '';
        try {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/D', $short)) throw new Problem('Name des Servers: nur Buchstaben, Ziffern und Bindestrich, z. B. kienzle.', 422);
            if (!isset(self::SUFFIXES[$suffix])) throw new Problem('Bitte eine Endung wählen.', 422);
            $ips = $r->field('ohne_ip') === '1' ? [] : array_filter($this->config->hostnames, fn($n) => filter_var($n, FILTER_VALIDATE_IP));
            $names = implode("\n", array_merge([$short . $suffix], preg_split('/\R/', trim($r->field('weitere', 3000))) ?: [], $ips));
            $this->srv()->save($names, $this->config->practice, $r->field('neue_ca') === '1');   // Praxisname unverändert; er steht unter Praxis
        } catch (Problem $e) {
            if (in_array($e->http, [409, 422, 503], true)) return $this->serverPage($e->getMessage());
            throw $e;
        }
        $this->audit->write($admin, 'servername', strtolower(implode(' ', array_slice(preg_split('/[\s,;]+/', trim($names)) ?: [], 0, 10))));
        $new = array_values(array_unique(array_filter(array_map(fn($n) => strtolower(trim($n)), preg_split('/[\s,;]+/', $names) ?: []))));
        $old = array_map('strtolower', $this->config->hostnames);
        sort($new);
        sort($old);
        if ($new !== $old || $r->field('neue_ca') === '1') {   // Name oder Adresse geändert: alles, was daran hängt, muss erneut erledigt werden (rot)
            $this->ov()->nameChanged($r->field('neue_ca') === '1');
            try { $this->ov()->refresh(); } catch (Problem) { /* Module werden beim nächsten Öffnen neu geprüft */ }
        }
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
            ->with('X-Kienzle-Drucker', rawurlencode(json_encode($this->printerRoles() + ['bonbreite' => $this->bonWidth()], JSON_UNESCAPED_UNICODE)))
            ->with('X-Kienzle-Menue', $this->moduleMenu((int)$session['user_id'], (int)$session['user_admin'] === 1, (string)$session['login']))
            ->with('X-Kienzle-Praxis', rawurlencode(json_encode(['name' => $this->config->practice] + $this->address() + $this->master(), JSON_UNESCAPED_UNICODE)));
    }

    /**
     * Menü für die Seitenleiste in Modulseiten: Anwendungen des Benutzers, für Admins zusätzlich die Zähler (Updates, Module, Sicherung, Einrichtung) und
     * die offenen Einstellungen wie in der Basis. Die Zähler werden 30 Sekunden zwischengespeichert, damit die Prüfung jeder Modul-Anfrage schnell bleibt.
     */
    private function moduleMenu(int $uid, bool $admin, string $login): string {
        $apps = $meta = [];
        foreach ($this->users->access($uid) as $id => $_) {
            if (($p = $this->modules->webPath($id)) === null) continue;
            $apps[$p . '/'] = $this->modules->name($id);
            $meta[$p . '/'] = $id;
        }
        $out = ['apps' => $apps, 'ids' => $meta, 'admin' => $admin, 'login' => $login];
        if ($admin) {
            $h = $this->menuHints();
            $out['zaehler'] = $h['zaehler'];
            $out['offen'] = $h['offen'];
            $out['todo'] = [];
            foreach ($this->users->access($uid) as $id => $a) {
                if (($p = $this->modules->webPath($id)) !== null && $a['admin'] && ($u = $this->modules->settingsUrl($id)) !== null && in_array($u, $h['offen'], true)) $out['todo'][] = $p . '/';
            }
        }
        return rawurlencode(json_encode($out, JSON_UNESCAPED_UNICODE));
    }

    /** Zähler und offene Einstellungen für die Seitenleiste der Module (kurz zwischengespeichert). */
    private function menuHints(): array {
        try {
            $c = json_decode((string)$this->db->setting('menue.hinweise'), true);
            if (is_array($c) && is_int($c['t'] ?? null) && time() - $c['t'] < 30 && is_array($c['zaehler'] ?? null) && is_array($c['offen'] ?? null)) return $c;
        } catch (\Throwable) {
        }
        $c = ['t' => time(), 'zaehler' => [
            'updates' => $this->updates ? $this->updates->count() : 0, 'module' => $this->store ? $this->store->count() : 0,
            'sicherung' => $this->backup ? $this->backup->count() : 0, 'einrichtung' => $this->openSteps()], 'offen' => $this->openPaths()];
        try {
            $this->db->store('menue.hinweise', json_encode($c));
        } catch (\Throwable) {
        }
        return $c;
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
            . ($this->mailReady() ? '<p class="muted"><a href="/passwort-vergessen">Passwort vergessen?</a> Ein Link zum Neusetzen kommt per E-Mail, wenn im Konto eine Adresse bestätigt ist.</p>' : '')
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

    /** Handynummer für den SMS-Ersatzcode (nur Admins; leer = keine). */
    private function phoneOf(int $uid): string { return (string)$this->db->setting('telefon.' . $uid); }

    private function smsReady(int $uid): bool {
        try { return $this->phoneOf($uid) !== '' && $this->con()->status()['sms'] !== null; } catch (Problem) { return false; }
    }

    private function smsCodeSend(): Response {
        $uid = (int)$this->session['user_id'];
        try {
            if (!$this->smsReady($uid)) throw new Problem('SMS-Codes sind nicht eingerichtet.', 409);
            $code = $this->auth->smsCodeIssue($this->session);
            $this->con()->sendSms($this->phoneOf($uid), 'Kienzle-Suite: Ihr Code lautet ' . $code . ' (10 Minuten gültig). Niemandem weitergeben.');
        } catch (Problem $e) {
            return $this->codePage($e->getMessage());
        }
        $this->audit->write((string)$this->session['login'], 'sms-code-gesendet');
        return $this->codePage(null, null, 'Der Code wurde per SMS geschickt. Es kann einen Moment dauern.');
    }

    private function codePage(?string $error = null, ?\Throwable $e = null, ?string $notice = null): Response {
        $uid = (int)($this->session['user_id'] ?? 0);
        $body = V::error($error) . V::notice($notice) . $this->clockOffer($e) . '<form method="post" action="/anmelden/code" class="box">'
            . V::input('code', 'Code aus der Authenticator-App (oder Wiederherstellungscode oder SMS-Code)', 'text', '', 'autocomplete="one-time-code" inputmode="text" required autofocus')
            . '<button>Weiter</button></form>'
            . ($this->smsReady($uid) ? '<form method="post" action="/anmelden/code/sms"><button class="link">Code per SMS senden</button></form>' : '')
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
                // Link aus „Passwort vergessen“ (Konto hat noch Passwort, App, Passkeys): wie beim Admin-Link alles zurücksetzen und alle Geräte abmelden
                if ($info['user_id'] && $this->users->find($id)['password_hash'] !== '') $this->users->resetCredentials($id);
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
    /** Dienste, die die Startseite für Serverwerte benutzt (für Tests austauschbar). */
    public ?SystemStatus $system = null;

    private function start(): Response {
        $uid = (int)$this->session['user_id'];
        $items = '';
        foreach ($this->users->access($uid) as $module => $a) {
            $path = $this->modules->webPath($module);
            if ($path !== null) $items .= '<li><a href="' . V::h($path) . '/">' . V::icon('modul:' . $module) . '<span>' . V::h($this->modules->name($module)) . '</span></a>' . ($a['admin'] ? ' <span class="tag">Admin</span>' : '') . '</li>';
        }
        $body = '<h2>Ihre Module</h2><p>Angemeldet als <strong>' . V::h($this->session['display'] ?: $this->session['login']) . '</strong>.</p>'
            . ($items !== '' ? '<ul class="modules">' . $items . '</ul>' : '<p>Für dieses Konto sind noch keine Module freigegeben.'
                . ((int)$this->session['user_admin'] === 1 ? ' Zugang zu Modulen vergeben Sie unter <a href="/verwaltung/benutzer">Benutzer</a>, auch für sich selbst.' : ' Bitte den Admin fragen.') . '</p>');
        $pending = false;
        $body = '<section class="card">' . $body . '</section>';
        if ((int)$this->session['user_admin'] === 1) {
            $server = '';
            try {
                [$tiles, $pending] = $this->statusTiles();
                if ($tiles !== '') $server = '<section class="card"><h2>Server-Zustand</h2><div class="tiles">' . $tiles . '</div></section>';
            } catch (\Throwable) { /* ohne Werte keine Karte */ }
            $setup = '';
            try {
                $sum = $this->ov()->summary();
                $pct = $sum['total'] ? (int)round(100 * $sum['done'] / $sum['total']) : 100;
                $setup = '<a class="card setup-card" href="/verwaltung/einrichtung"><h2>' . V::heading('Einrichtung') . ' ' . ($sum['done'] >= $sum['total'] ? '<span class="tag ok">✓ fertig</span>' : '<span class="tag warn">' . ($sum['total'] - $sum['done']) . ' offen</span>') . '</h2>'
                    . '<progress class="progress" value="' . $pct . '" max="100" aria-label="Fortschritt der Einrichtung">' . $pct . ' %</progress>'
                    . '<p class="muted">' . $sum['done'] . ' von ' . $sum['total'] . ' nötigen Schritten erledigt.'
                    . ($sum['next'] !== '' ? ' Als Nächstes: <strong>' . V::h($sum['next']) . '</strong>.' : '') . '</p></a>';
            } catch (\Throwable) { /* ohne Überblick keine Karte */ }
            $body = $setup . $server . $body;
        }
        $response = Response::html(V::page('Start', $body, $this->nav(), $this->config->practice));
        // Wird gerade ein Guthaben abgefragt, nach kurzer Zeit neu laden, damit der neue Wert erscheint.
        return $pending ? $response->with('Refresh', '3; url=/') : $response;
    }

    private function tile(string $icon, string $label, string $value, string $sub = '', string $level = 'ok', ?int $percent = null, string $extra = '', string $href = ''): string {
        $val = '<strong class="t-val">' . V::h($value) . '</strong>';
        return '<div class="tile ' . $level . '"><span class="t-ico">' . V::icon($icon) . '</span><div class="t-body"><span class="t-label">' . V::h($label) . '</span>'
            . ($href !== '' ? '<a href="' . V::h($href) . '">' . $val . '</a>' : $val)
            . ($sub !== '' ? '<small class="muted">' . V::h($sub) . '</small>' : '')
            . ($percent !== null ? '<progress class="progress" value="' . $percent . '" max="100" aria-label="' . V::h($label) . '">' . $percent . ' %</progress>' : '') . '</div>' . $extra . '</div>';
    }

    /** Kacheln für den Server-Zustand und ob gerade eine Abfrage läuft. @return array{0:string,1:bool} */
    private function statusTiles(): array {
        $sys = ($this->system ?? new SystemStatus())->read();
        $t = '';
        if ($sys['disk']) {
            $used = (int)round(100 * (1 - $sys['disk']['frei'] / $sys['disk']['gesamt']));
            $t .= $this->tile('st:disk', 'Speicherplatz', SystemStatus::bytes($sys['disk']['frei']) . ' frei', 'von ' . SystemStatus::bytes($sys['disk']['gesamt']) . ' (' . $used . ' % belegt)', $used >= 95 ? 'bad' : ($used >= 85 ? 'warn' : 'ok'), $used);
        }
        if ($sys['ram']) {
            $used = (int)round(100 * (1 - $sys['ram']['frei'] / $sys['ram']['gesamt']));
            $t .= $this->tile('st:ram', 'Arbeitsspeicher', SystemStatus::bytes($sys['ram']['frei']) . ' frei', 'von ' . SystemStatus::bytes($sys['ram']['gesamt']) . ' (' . $used . ' % belegt)', $used >= 95 ? 'bad' : ($used >= 85 ? 'warn' : 'ok'), $used);
        }
        if ($sys['cpu']) {
            $p = $sys['cpu']['prozent'];
            $t .= $this->tile('st:cpu', 'Prozessor', $p . ' % ausgelastet', 'Last ' . number_format($sys['cpu']['last'], 2, ',', '') . ' bei ' . $sys['cpu']['kerne'] . ($sys['cpu']['kerne'] === 1 ? ' Kern' : ' Kernen') . ' (Minutenmittel)', $p >= 95 ? 'bad' : ($p >= 80 ? 'warn' : 'ok'), $p);
        }
        if ($sys['temp'] !== null) $t .= $this->tile('st:temp', 'Temperatur', number_format($sys['temp'], 0, ',', '') . ' °C', 'höchster Messwert', $sys['temp'] >= 85 ? 'bad' : ($sys['temp'] >= 75 ? 'warn' : 'ok'));
        if ($sys['laufzeit'] !== null) $t .= $this->tile('st:zeit', 'Läuft seit', SystemStatus::dauer($sys['laufzeit']), 'seit dem letzten Neustart');
        if ($this->backup) {
            $b = $this->backup->status();
            $l = $b['letzte_sicherung'];
            if (!$b['eingerichtet']) $t .= $this->tile('st:backup', 'Letzte Sicherung', 'nicht eingerichtet', 'Ohne Sicherung gehen Daten bei einem Ausfall verloren.', 'warn', null, '', '/verwaltung/sicherung');
            elseif (!$l) $t .= $this->tile('st:backup', 'Letzte Sicherung', 'noch keine', 'Die erste Sicherung steht noch aus.', 'warn', null, '', '/verwaltung/sicherung');
            else {
                $alt = time() - (int)($l['zeit'] ?? 0);
                $ok = ($l['ok'] ?? false) === true;
                $t .= $this->tile('st:backup', 'Letzte Sicherung', V::date((int)($l['zeit'] ?? 0)), $ok ? ($alt > 3 * 86400 ? 'Älter als drei Tage' : 'in Ordnung') : 'Fehlgeschlagen', !$ok ? 'bad' : ($alt > 3 * 86400 ? 'warn' : 'ok'), null, '', '/verwaltung/sicherung');
            }
        }
        $pending = false;
        if ($this->connections) {
            $st = $this->connections->status();
            $pending = (bool)$st['pending'];
            foreach (['letterxpress' => ['st:post', 'Guthaben Letterxpress', 'Brief-Versand'], 'sms' => ['st:sms', 'Guthaben seven.io', 'SMS-Versand']] as $key => [$icon, $label, $sub]) {
                if (empty($st[$key])) continue;
                $g = $st['guthaben'][$key] ?? null;
                $wert = is_array($g) && isset($g['wert']) && is_numeric($g['wert']) ? (float)$g['wert'] : null;
                $knopf = '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('pruefen', ['art' => 'guthaben', 'id' => $key === 'sms' ? 'sms' : '', 'zurueck' => 'start'])
                    . '<button class="icon-btn" title="Guthaben jetzt abfragen" aria-label="' . V::h($label) . ' aktualisieren"' . ($pending ? ' disabled' : '') . '>' . V::icon('st:aktualisieren') . '</button></form>';
                $t .= $this->tile($icon, $label, $wert !== null ? number_format($wert, 2, ',', '.') . ' €' : ($g ? 'unbekannt' : 'noch nicht abgefragt'),
                    $pending ? 'wird abgefragt …' : ($g ? $sub . ', abgefragt ' . V::date((int)($g['zeit'] ?? 0)) : $sub . ': mit dem Pfeil abfragen'),
                    $wert !== null && $wert < 5 ? 'warn' : 'ok', null, $knopf);
            }
        }
        return [$t, $pending];
    }

    /** Ist der E-Mail-Versand der Suite eingerichtet? Ohne ihn gibt es weder Bestätigung noch „Passwort vergessen“. */
    private function mailReady(): bool {
        try { return $this->connections !== null && $this->connections->status()['mail'] !== null; } catch (Problem) { return false; }
    }

    private function forgotPage(?string $notice = null, ?string $error = null): Response {
        $body = V::error($error) . V::notice($notice)
            . ($notice ? '' : '<form method="post" action="/passwort-vergessen" class="box">'
                . '<p>Gib die E-Mail-Adresse ein, die du unter „Mein Konto“ bestätigt hast. Dorthin schicken wir einen Link, mit dem du ein neues Passwort festlegst. Der Link gilt 3 Tage und nur einmal.</p>'
                . V::input('email', 'E-Mail-Adresse', 'email', '', 'autocomplete="email" maxlength="254" required autofocus') . '<button>Link schicken</button></form>')
            . '<p><a href="/anmelden">Zurück zur Anmeldung</a></p>';
        return Response::html(V::page('Passwort vergessen', $body, null, $this->config->practice), $error ? 429 : 200);
    }

    private function forgot(Request $r): Response {
        try {
            if (!$this->mailReady()) throw new Problem('Der E-Mail-Versand ist auf diesem Server nicht eingerichtet. Bitte einen Admin um einen Einrichtungslink bitten.', 503);
            $this->auth->throttle->check('reset');
            $this->auth->throttle->fail('reset', 5);   // jede Anfrage zählt: so lässt sich niemand mit Mails überfluten
            $user = $this->users->byEmail($r->field('email', 254));
            // Immer dieselbe Antwort, damit sich nicht erraten lässt, welche Adressen es gibt. Admin-Konten (App-Pflicht) nur über die Konsole.
            if ($user && (int)$user['active'] === 1 && !$this->users->needsOtp($user)) {
                $url = 'https://' . $this->config->hostnames[0] . '/einrichten?t=' . $this->users->setupToken((int)$user['id']);
                $this->con()->sendMail($this->users->email((int)$user['id']), 'Kienzle-Suite: Passwort neu festlegen',
                    "Hallo " . ($user['display'] ?: $user['login']) . ",\n\nüber diesen Link legst du ein neues Passwort fest (3 Tage gültig, nur einmal verwendbar):\n\n" . $url
                    . "\n\nWar das nicht deine Anfrage, kannst du diese Nachricht ignorieren. Dein Passwort bleibt, solange du den Link nicht verwendest.\n");
                $this->audit->write($user['login'], 'passwort-link-per-mail');
            }
        } catch (Problem $e) {
            if ($e->http === 429 || $e->http === 503) return $this->forgotPage(null, $e->getMessage());
            throw $e;
        }
        return $this->forgotPage('Wenn zu dieser Adresse ein Konto gehört, ist ein Link unterwegs. Bitte auch im Spam-Ordner nachsehen.');
    }

    private function saveEmail(Request $r): Response {
        $uid = (int)$this->session['user_id'];
        $user = $this->users->find($uid);
        try {
            if (!$this->auth->confirmedRecently($this->session)) return $this->needConfirm('/konto');
            $address = trim($r->field('email', 254));
            if ($address === '') {
                $this->users->emailRemove($uid);
                $notice = 'E-Mail-Adresse entfernt.';
            } else {
                if (!$this->mailReady()) throw new Problem('Der E-Mail-Versand ist nicht eingerichtet (Verwaltung → Verbindungen). Ohne ihn lässt sich die Adresse nicht bestätigen.', 409);
                $code = $this->users->emailStart($uid, $address);
                $this->con()->sendMail($address, 'Kienzle-Suite: E-Mail-Adresse bestätigen', "Hallo " . ($user['display'] ?: $user['login']) . ",\n\ndein Bestätigungscode für diese Adresse (1 Stunde gültig):\n\n" . $code
                    . "\n\nGib ihn in der Kienzle-Suite unter „Mein Konto → E-Mail-Adresse“ ein. Du musst dazu angemeldet sein.\n\nWar das nicht deine Anfrage, kannst du diese Nachricht ignorieren.\n");
                $notice = 'Bestätigungscode an ' . $address . ' geschickt. Gib ihn unten ein, dann gilt die Adresse.';
            }
        } catch (Problem $e) {
            return $this->account($e->getMessage());
        }
        $this->audit->write($user['login'], 'email-geaendert');
        return $this->account(null, $notice);
    }

    private function confirmEmail(Request $r): Response {
        $uid = (int)$this->session['user_id'];
        $address = $this->users->emailConfirm($uid, $r->field('code', 40));
        if ($address === null) return $this->account('Der Code stimmt nicht oder ist abgelaufen (fünf Versuche, eine Stunde). Bitte die Adresse oben noch einmal eintragen, dann kommt ein neuer Code.');
        $this->audit->write((string)$this->session['login'], 'email-bestaetigt');
        return $this->account(null, 'E-Mail-Adresse ' . $address . ' bestätigt.');
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
        $mail = $this->users->email($uid);
        $pending = $this->users->emailPending($uid);
        $roles = $this->users->roles($uid);
        $chips = ((int)$user['admin'] === 1 ? '<span class="tag rollentag">' . V::role('Admin') . 'Basis-Admin</span>' : '')
            . implode('', array_map(fn($n) => '<span class="tag rollentag">' . V::role($n) . V::h($n) . '</span>', $roles));
        $name = (string)($user['display'] ?: $user['login']);
        $needsOtp = $this->users->needsOtp($user);
        $phone = $this->phoneOf($uid);
        // Profil mit änderbarem Anzeigenamen (der Benutzername zum Anmelden bleibt)
        $profil = '<section class="card konto"><div class="profil">' . V::avatar($name, $roles, (int)$user['admin'] === 1) . '<div class="profil-text">'
            . '<form method="post" action="/konto/name" class="name-form">' . $csrf . '<label class="sr-only" for="k-name">Anzeigename</label>'
            . '<input id="k-name" name="display" value="' . V::h((string)$user['display']) . '" maxlength="80" placeholder="' . V::h((string)$user['login']) . '" autocomplete="name"><button class="secondary">Namen speichern</button></form>'
            . '<div class="muted small">Benutzername ' . V::h($user['login']) . ($mail !== '' ? ' · ' . V::h($mail) : '') . '</div>'
            . ($chips !== '' ? '<div class="rollen-zeile">' . $chips . '</div>' : '<div class="muted small">Keine Gruppe zugewiesen</div>') . '</div></div></section>';
        $fold = static fn(string $title, string $tag, string $inner, bool $open = false): string => '<details class="fold"' . ($open ? ' open' : '') . '><summary><h2>' . $title . '</h2>' . $tag . '</summary><div class="fold-body">' . $inner . '</div></details>';
        $ok = static fn(bool $good, string $yes, string $no): string => '<span class="tag ' . ($good ? 'ok' : 'warn') . ' fold-status">' . V::h($good ? $yes : $no) . '</span>';
        $folds = $fold('E-Mail-Adresse', $ok($mail !== '', 'bestätigt', $pending !== '' ? 'wartet auf Bestätigung' : 'nicht hinterlegt'),
            '<p class="muted">Mit einer bestätigten Adresse kannst du dir auf der Anmeldeseite selbst einen Link zum Neusetzen des Passworts schicken lassen'
            . ($needsOtp ? ' (für Admin-Konten mit Authenticator-App gilt das nicht: sie werden an der Server-Konsole zurückgesetzt)' : '') . '.'
            . ($this->mailReady() ? '' : ' <strong>Der E-Mail-Versand ist noch nicht eingerichtet</strong> (Verwaltung → Verbindungen).') . '</p>'
            . ($pending !== '' ? '<p class="notice">Wartet auf Bestätigung: ' . V::h($pending) . '. Den Code aus der E-Mail hier eingeben.</p><form method="post" action="/konto/email-code" class="box">' . $csrf
                . V::input('code', 'Bestätigungscode (z. B. 1234-5678-9012-3456)', 'text', '', 'inputmode="numeric" autocomplete="one-time-code" maxlength="24" required placeholder="0000-0000-0000-0000"') . '<button>Code bestätigen</button></form>' : '')
            . '<form method="post" action="/konto/email" class="box">' . $csrf . V::input('email', 'E-Mail-Adresse (leer lassen zum Entfernen)', 'email', $mail !== '' ? $mail : $pending, 'maxlength="254" autocomplete="email" placeholder="name@praxis.de"')
            . '<button>Bestätigungscode schicken</button></form>', $pending !== '');
        $folds .= $fold('Passwort ändern', '', '<form method="post" action="/konto/passwort" class="box">' . $csrf
            . V::input('current', 'Bisheriges Passwort', 'password', '', 'autocomplete="current-password" required')
            . V::input('password', 'Neues Passwort', 'password', '', 'autocomplete="new-password" required')
            . V::input('password2', 'Neues Passwort wiederholen', 'password', '', 'autocomplete="new-password" required')
            . '<button>Ändern</button></form>');
        if ($needsOtp) $folds .= $fold('Handynummer für SMS-Code (Ersatz)', $ok($phone !== '', 'hinterlegt', 'nicht hinterlegt'),
            '<p class="muted">Hast du die Authenticator-App nicht dabei, kann ein Code per SMS geschickt werden. Das geht nur, wenn unter Verwaltung → Verbindungen SMS eingerichtet ist.</p>'
            . '<form method="post" action="/konto/telefon" class="box">' . $csrf . V::input('telefon', 'Handynummer (leer lassen zum Entfernen)', 'tel', $phone, 'maxlength="30" placeholder="01701234567" autocomplete="tel"')
            . '<button>Speichern</button></form>');
        $folds .= $fold('Authenticator-App', $ok($otp, 'eingerichtet', 'nicht eingerichtet'),
            '<p>' . ($otp ? 'Eingerichtet. Übrige Wiederherstellungscodes: ' . $this->users->recoveryLeft($uid) . '.' : 'Nicht eingerichtet' . ($needsOtp ? ' (für Admins Pflicht).' : '.')) . '</p>'
            . '<p><a class="button" href="/konto/otp">' . ($otp ? 'Neu einrichten' : 'Einrichten') . '</a></p>'
            . ($otp ? '<form method="post" action="/konto/codes">' . $csrf . '<button class="secondary">Neue Wiederherstellungscodes erzeugen</button></form>' : ''));
        if ($this->passkeys) $folds .= $fold('Passkeys', $ok($keys !== '', 'eingerichtet', 'nicht eingerichtet'),
            '<p class="muted">Anmelden ohne Passwort mit Fingerabdruck, Gesicht oder PIN des Geräts. Funktioniert nur unter der Adresse <code>' . V::h($this->passkeys->origin()) . '</code>.'
            . ($needsOtp ? ' Die Authenticator-App bleibt als Rückfall nötig.' : '') . '</p>'
            . ($keys !== '' ? '<table><tr><th>Passkey</th><th>Zuletzt</th><th></th></tr>' . $keys . '</table>' : '<p>Noch kein Passkey.</p>')
            . '<div class="box" data-passkey-register data-csrf="' . V::h($this->session['csrf']) . '">' . V::input('label', 'Bezeichnung', 'text', '', 'maxlength="60" placeholder="z. B. Laptop Empfang"')
            . '<button type="button">Passkey hinzufügen</button><p class="error" hidden></p></div>');
        $folds .= $fold('Gemerkte Geräte', '<span class="tag fold-status">' . ($devices !== '' ? count($this->auth->devices($uid, $this->token)) : 0) . '</span>',
            $devices !== '' ? '<table><tr><th>Gerät</th><th>Zuletzt</th><th></th></tr>' . $devices . '</table><form method="post" action="/konto/geraete">' . $csrf . '<input type="hidden" name="id" value="all"><button class="link">Alle abmelden</button></form>' : '<p>Keine.</p>');
        $body = V::error($error) . V::notice($notice) . $profil . '<section class="card konto">' . $folds . '</section>';
        V::$status = [];
        return Response::html(V::page('Mein Konto', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function saveName(Request $r): Response {
        $uid = (int)$this->session['user_id'];
        $user = $this->users->find($uid);
        try {
            $this->users->rename($uid, $r->field('display', 120));
        } catch (Problem $e) {
            return $this->account($e->getMessage());
        }
        $this->audit->write($user['login'], 'name-geaendert');
        return $this->account(null, 'Name gespeichert.');
    }

    private function savePhone(Request $r): Response {
        $uid = (int)$this->session['user_id'];
        $user = $this->users->find($uid);
        try {
            if (!$this->auth->confirmedRecently($this->session)) return $this->needConfirm('/konto');
            if (!$this->users->needsOtp($user)) throw new Problem('Die SMS-Ersatzanmeldung gibt es nur für Admin-Konten.', 403);
            $raw = trim($r->field('telefon', 40));
            if ($raw === '') { $this->db->query('DELETE FROM settings WHERE key=?', ['telefon.' . $uid]); $notice = 'Handynummer entfernt.'; }
            else { $this->db->store('telefon.' . $uid, Connections::phone($raw)); $notice = 'Handynummer gespeichert.'; }
        } catch (Problem $e) {
            return $this->account($e->getMessage());
        }
        $this->audit->write($user['login'], 'handynummer-geaendert');
        return $this->account(null, $notice);
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
        if ($r->path === '/verwaltung/sicherheit') return $this->securityPage();
        if ($r->path === '/verwaltung/zugang') return $get ? $this->accessPage() : $this->accessSave($r, $admin);
        if ($r->path === '/verwaltung/protokoll' && $get) return $this->protocol();
        if ($r->path === '/verwaltung/firewall') return $get ? $this->firewallPage() : $this->firewallApply($r, $admin);
        if ($r->path === '/verwaltung/firewall/bestaetigen' && !$get) return $this->firewallConfirm($admin);
        if ($r->path === '/verwaltung/firewall/aus' && !$get) return $this->firewallOff($admin);
        if ($r->path === '/verwaltung/updates') return $get ? $this->updatesPage() : $this->updatesAction($r, $admin);
        if ($r->path === '/verwaltung/module') return $get ? $this->modulesPage() : $this->modulesAction($r, $admin);
        if ($r->path === '/verwaltung/verbindungen') return $get ? $this->connectionsPage() : $this->connectionsAction($r, $admin);
        if ($r->path === '/verwaltung/neustart') return $get ? $this->restartPage() : $this->restartDo($r, $admin);
        if ($r->path === '/verwaltung/praxis') return $get ? $this->practicePage() : $this->practiceSave($r, $admin);
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
            $icons = ($u['admin'] ? V::role('Admin') : '') . implode('', array_map([V::class, 'role'], $u['roles']));
            $rows .= '<tr><td>' . V::avatar($u['display'] ?: $u['login'], $u['roles'], (bool)$u['admin'], true) . ' <a href="/verwaltung/benutzer/' . $u['id'] . '">' . V::h($u['login']) . '</a></td><td>' . V::h($u['display']) . '</td><td>' . $icons . ' ' . V::h(implode(', ', $roles) ?: '–')
                . '</td><td>' . ($u['active'] ? ($u['password'] ? ($u['otp'] ? 'Passwort + App' : 'Passwort') : 'Einrichtung offen') : 'gesperrt') . '</td></tr>';
        }
        $groups = self::stripCards(self::inner($this->groupsPage()));
        return Response::html(V::page('Benutzer und Gruppen', V::notice($notice) . '<section class="card"><details class="fold" open><summary><h2>Benutzer</h2></summary>'
            . '<p><a class="button" href="/verwaltung/benutzer/neu">Benutzer anlegen</a></p>'
            . '<table><tr><th>Anmeldename</th><th>Name</th><th>Zugang</th><th>Anmeldung</th></tr>' . $rows . '</table></details>'
            . '<details class="fold" id="gruppen"' . (str_contains((string)($this->req?->query['bereich'] ?? ''), 'gruppen') ? ' open' : '') . '><summary><h2>Gruppen</h2></summary>' . $groups . '</details></section>',
            $this->nav(), $this->config->practice));
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
        $groups = (new Groups($this->db, $this->modules))->all();
        $roles = $this->users->roles($id);
        $chips = '';
        foreach (array_keys($groups) as $name) $chips .= '<label class="rolle-chip">' . '<input type="checkbox" name="rolle[]" value="' . V::h($name) . '" data-rolle' . (in_array($name, $roles, true) ? ' checked' : '') . '> ' . V::role($name) . ' ' . V::h($name) . '</label>';
        $modules = '';
        foreach ($this->modules->all() as $mid => $m) {
            $a = $access[$mid] ?? ['admin' => false, 'rights' => []];
            $head = '<th>Recht</th>';
            foreach ($groups as $name => $_) $head .= '<th class="gcol" data-gcol="' . V::h($name) . '">' . V::role($name) . '<span class="gname">' . V::h($name) . '</span></th>';
            $head .= '<th>Für ' . V::h($this->users->find($id)['login']) . '</th>';
            $rows = [['@admin', 'Admin dieses Moduls (braucht Authenticator-App)', 'modul_admin[]', $mid, $a['admin']]];
            foreach ($this->modules->rights($mid) as $right) $rows[] = [$right['id'], $right['label'], 'recht[]', $mid . ':' . $right['id'], in_array($right['id'], $a['rights'], true)];
            $body = '';
            foreach ($rows as [$rid, $label, $field, $value, $on]) {
                $grants = [];
                $cells = '';
                foreach ($groups as $name => $g) {
                    $has = $rid === '@admin' ? in_array($mid, $g['admin'], true) : in_array($mid . ':' . $rid, $g['rechte'], true);
                    if ($has) $grants[] = $name;
                    $cells .= '<td class="gcol' . ($has ? ' ja' : '') . '" data-gcol="' . V::h($name) . '">' . ($has ? '✓' : '') . '</td>';
                }
                $base = array_intersect($grants, $roles) !== [];
                $abw = $on !== $base;
                $body .= '<tr class="recht' . ($abw ? ' abw' : '') . '" data-grants="' . V::h(implode('|', $grants)) . '"><td>' . V::h($label) . '</td>' . $cells
                    . '<td class="erg"><label class="check"><input type="checkbox" name="' . $field . '" value="' . V::h($value) . '"' . ($on ? ' checked' : '') . ' data-erg> <span class="stift" title="Einzeln geändert, weicht von der Rolle ab">' . V::icon('stift') . '</span></label></td></tr>';
            }
            $modules .= '<fieldset><legend>' . V::h($this->modules->name($mid)) . '</legend><table class="matrix"><tr>' . $head . '</tr>' . $body . '</table></fieldset>';
        }
        $url = 'https://' . $this->config->hostnames[0] . '/einrichten?t=';
        $body = V::error($error)
            . ($link ? '<div class="notice"><p><strong>Einmaliger Einrichtungslink</strong> (3 Tage gültig, wird nur jetzt angezeigt). An ' . V::h($user['login'])
                . ' weitergeben, nicht per unverschlüsselter E-Mail:</p><p><code class="secret">' . V::h($url . $link) . '</code></p></div>' : '')
            . '<form method="post" action="/verwaltung/benutzer/' . $id . '" class="box">' . V::csrf($this->session['csrf'])
            . '<p>Anmeldename: <strong>' . V::h($user['login']) . '</strong></p>'
            . V::input('display', 'Name', 'text', $user['display'])
            . '<h2>Rollen</h2><input type="hidden" name="rollen_gesendet" value="1"><div class="rollen">' . $chips . '</div>'
            . '<p class="muted small">Die Rolle gilt dauerhaft: Ändert sich die <a href="/verwaltung/benutzer?bereich=gruppen">Gruppe</a>, ändern sich die Rechte aller Mitglieder mit. '
            . '<span class="legende"><span class="punkt blau"></span> von der Rolle &nbsp; <span class="punkt gelb"></span> einzeln geändert</span></p>'
            . V::checkbox('admin', '1', 'Basis-Admin', (int)$user['admin'] === 1) . V::checkbox('active', '1', 'Konto aktiv', (int)$user['active'] === 1)
            . ($modules !== '' ? '<h2>Zugang zu Modulen</h2><p><button type="button" class="secondary" data-reset>Alle Einzeländerungen zurücknehmen (zurück auf Rolle)</button></p>' . $modules : '<p>Es sind noch keine Module installiert.</p>')
            . '<button>Speichern</button></form>'
            . (!$self && !$this->users->needsOtp($user) ? '<form method="post" action="/verwaltung/benutzer/' . $id . '/link" class="box">' . V::csrf($this->session['csrf'])
                . '<p>Passwort vergessen? Ein neuer Einrichtungslink löscht das bisherige Passwort und meldet alle Geräte ab.</p><button>Neuen Einrichtungslink erzeugen</button></form>'
                : (!$self ? '<p>Admin-Konten (Passwort oder Authenticator-App verloren) setzt man nur an der Server-Konsole zurück: <code>sudo kienzle-admin zuruecksetzen ' . V::h($user['login']) . '</code></p>' : ''))
            . (!$self ? '<form method="post" action="/verwaltung/benutzer/' . $id . '/loeschen" class="box">' . V::csrf($this->session['csrf']) . '<button class="danger">Benutzer löschen</button></form>' : '');
        return Response::html(V::page('Benutzer ' . $user['login'], $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
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
            . '<p>Gruppen sind dauerhafte Rollen: Wer einer Gruppe angehört, hat ihre Rechte, und jede Änderung hier gilt sofort für alle Mitglieder. '
            . 'Beim Benutzer lassen sich einzelne Rechte abweichend erlauben oder entziehen. Basis-Admins dürfen in allen Modulen ohnehin alles.</p>';
        foreach ($groups->all() as $name => $g) {
            $body .= '<form method="post" action="/verwaltung/gruppen" class="box">' . V::csrf($this->session['csrf']) . '<input type="hidden" name="aktion" value="speichern"><input type="hidden" name="name" value="' . V::h($name) . '">'
                . '<h2>' . V::role($name) . ' ' . V::h($name) . '</h2><p class="muted small">Mitglieder: ' . V::h(implode(', ', $this->users->members($name)) ?: 'keine') . '</p>' . $this->groupBoxes($name, $g) . '<button>Speichern</button></form>'
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
        return Response::redirect('/verwaltung/benutzer?bereich=gruppen');
    }

    private function userSave(Request $r, int $id, string $admin): Response {
        $user = $this->users->find($id);
        if (!$user) throw new Problem('Benutzer nicht gefunden.', 404);
        $moduleAdmin = $r->list('modul_admin');
        $entries = $r->list('recht');
        $groupName = $r->field('gruppe', 30);
        $roles = $r->field('rollen_gesendet') === '1' ? array_map('strval', $r->list('rolle')) : null;
        if ($groupName !== '') {   // älterer Weg: eine Gruppe als alleinige Rolle, ohne Einzeländerungen
            $g = (new Groups($this->db, $this->modules))->get($groupName);
            if (!$g) return $this->userEdit($id, 'Unbekannte Gruppe.');
            $roles = [$groupName];
            $moduleAdmin = $g['admin'];
            $entries = $g['rechte'];
        }
        $rights = [];
        foreach ($entries as $entry) {
            [$m, $right] = array_pad(explode(':', (string)$entry, 2), 2, '');
            $rights[$m][] = $right;
        }
        try {
            $this->db->transaction(function () use ($id, $r, $moduleAdmin, $rights, $roles): void {
                $self = $id === (int)$this->session['user_id'];
                $this->users->update($id, $r->field('display', 80), $self || $r->field('admin') === '1', $self || $r->field('active') === '1');
                if ($roles !== null) $this->users->setRoles($id, $roles);
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

    /** Zeitfeld mit Einheit: Wert + Minuten/Stunden/Tage (nur Einheiten ab $smallest). */
    private function durationField(string $name, string $label, int $seconds, array $units): string {
        $factor = ['min' => 60, 'h' => 3600, 'd' => 86400]; $names = ['min' => 'Minuten', 'h' => 'Stunden', 'd' => 'Tage'];
        $unit = $units[0];
        foreach (array_reverse($units) as $u) if ($seconds % $factor[$u] === 0) { $unit = $u; break; }
        $options = '';
        foreach ($units as $u) $options .= '<option value="' . $u . '"' . ($u === $unit ? ' selected' : '') . '>' . $names[$u] . '</option>';
        return '<label>' . V::h($label) . '<span class="row"><input type="number" name="' . $name . '" min="1" value="' . intdiv($seconds, $factor[$unit]) . '" required>'
            . '<select name="' . $name . '_einheit" aria-label="Einheit">' . $options . '</select></span></label>';
    }

    private function accessPage(?string $error = null, ?string $notice = null): Response {
        return Response::html(V::page('Zugang', $this->accessBody($error, $notice), $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function accessBody(?string $error = null, ?string $notice = null): string {
        return V::error($error) . V::notice($notice) . '<form method="post" action="/verwaltung/zugang">' . V::csrf($this->session['csrf'])
            . $this->durationField('idle', 'Abmelden nach … ohne Aktion (5 Minuten bis 1 Jahr)', $this->auth->idleSeconds(), ['min', 'h', 'd'])
            . $this->durationField('max', 'Höchstdauer einer Sitzung (1 Stunde bis 1 Jahr)', $this->auth->maxSeconds(), ['h', 'd'])
            . '<p>Gemerkte Geräte bleiben bis zu 30 Tage ohne Nutzung und höchstens 6 Monate angemeldet, nur aus dem Netz der Anmeldung.</p><button>Speichern</button></form>';
    }

    private static function seconds(Request $r, string $name, int $default): int {
        $factor = ['min' => 60, 'h' => 3600, 'd' => 86400];
        $unit = $r->field($name . '_einheit', 5);
        return max(0, (int)$r->field($name, 10)) * ($factor[$unit] ?? $default);
    }

    private function accessSave(Request $r, string $admin): Response {
        try {
            $idle = self::seconds($r, 'idle', 60); $max = self::seconds($r, 'max', 3600);
            if ($idle % 60 !== 0 || $max % 3600 !== 0) throw new Problem('Die Höchstdauer einer Sitzung gibt es nur in ganzen Stunden oder Tagen.', 422);
            $this->auth->saveTimes(intdiv($idle, 60), intdiv($max, 3600));
        } catch (Problem $e) {
            return $this->securityPage($e->getMessage());
        }
        $this->audit->write($admin, 'zugang-gespeichert', 'abmeldezeiten');
        return $this->securityPage(null, 'Gespeichert.');
    }

    /** Inhalt einer fertigen Seite ohne Rahmen und Überschrift (für zusammengelegte Seiten). */
    private static function inner(Response $page): string {
        return preg_match('#</h1>(.*)</main>#s', $page->body, $m) ? $m[1] : '';
    }

    /** Sicherheit: Firewall, Zugang (Abmeldezeiten) und Protokoll als aufklappbare Abschnitte in einer Karte. */
    private function securityPage(?string $error = null, ?string $notice = null): Response {
        $log = $this->protocol();
        try {
            $fw = $this->firewallPage(); $fwState = (string)$this->fw()->overview($this->req->remote)['status']['state'];
        } catch (Problem $e) {
            $fw = Response::html('<main><h1>x</h1><p class="muted">' . V::h($e->getMessage()) . '</p></main>'); $fwState = 'off';
        }
        $open = fn(string $id) => str_contains((string)($this->req?->query['bereich'] ?? ''), $id) || $error !== null && $id === 'zugang' ? ' open' : '';
        $body = V::error($error) . V::notice($notice) . '<section class="card">'
            . '<details class="fold" id="firewall"' . ($open('firewall') ?: ($fwState === 'pending' ? ' open' : '')) . '><summary><h2>Firewall</h2></summary>' . self::stripCards(self::inner($fw)) . '</details>'
            . '<details class="fold" id="zugang"' . $open('zugang') . '><summary><h2>Zugang (Abmeldezeiten)</h2></summary>' . $this->accessBody() . '</details>'
            . '<details class="fold" id="protokoll"' . $open('protokoll') . '><summary><h2>Protokoll</h2></summary>' . self::stripCards(self::inner($log)) . '</details></section>';
        V::$status = ['Firewall' => [$fwState === 'active', 'aktiv', $fwState === 'pending' ? 'wartet auf Bestätigung' : 'aus']];
        $response = Response::html(V::page('Sicherheit', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
        if (($refresh = $fw->header('Refresh')) !== null) $response->with('Refresh', $refresh);
        return $response;
    }

    private static function stripCards(string $html): string { return preg_replace('#</?section[^>]*>#', '', $html) ?? $html; }

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
        return Response::redirect('/verwaltung/sicherheit?bereich=firewall');
    }

    private function firewallConfirm(string $admin): Response {
        $this->fw()->confirm();
        $this->audit->write($admin, 'firewall-bestaetigt');
        return Response::redirect('/verwaltung/sicherheit?bereich=firewall');
    }

    private function firewallOff(string $admin): Response {
        $this->fw()->disable();
        $this->audit->write($admin, 'firewall-ausgeschaltet');
        return Response::redirect('/verwaltung/sicherheit?bereich=firewall');
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
        $post = fn(string $aktion, string $label, string $hidden = '', string $class = '', string $extra = '') => '<form method="post" action="/verwaltung/module" class="inline">' . $csrf . $hidden
            . '<input type="hidden" name="aktion" value="' . $aktion . '">' . $extra . '<button' . ($class !== '' ? ' class="' . $class . '"' : '') . $off . '>' . $label . '</button></form>';
        $tiles = '';
        foreach ($st['installed'] as $m) {
            $hidden = '<input type="hidden" name="id" value="' . V::h($m['id']) . '">';
            $state = '';
            $stopped = false;
            if ($m['dienste']) {
                $active = count(array_filter($m['dienste'], fn($d) => $d['aktiv'] === true));
                $unknown = count(array_filter($m['dienste'], fn($d) => $d['aktiv'] === null)) > 0;
                $all = $active === count($m['dienste']);
                $stopped = !$all && !$unknown;
                $state = $unknown ? '<span class="tag">Status unbekannt</span>' : ($all ? '<span class="tag ok">✓ läuft</span>' : '<span class="tag warn">' . ($active === 0 ? 'gestoppt' : 'teilweise gestoppt') . '</span>');
            }
            $menuItem = fn(string $aktion, string $label, string $icon) => '<form method="post" action="/verwaltung/module">' . $csrf . $hidden . '<input type="hidden" name="aktion" value="' . $aktion . '">'
                . '<button class="mod-item"' . $off . '>' . V::icon($icon) . ' ' . $label . '</button></form>';
            $more = '<details class="mod-more"><summary class="icon-btn" title="Mehr" aria-label="Mehr zu ' . V::h($m['name']) . '">' . V::icon('st:mehr') . '</summary><div class="mod-menu">'
                . ($m['dienste'] ? '<small class="muted">Dienste: ' . V::h(implode(', ', array_map(fn($d) => preg_replace('/^kienzle-|\.service$/', '', $d['name']) . ($d['aktiv'] === true ? ' ✓' : ($d['aktiv'] === false ? ' ✗' : ' ?')), $m['dienste']))) . '</small>' : '')
                . ($stopped ? $menuItem('dienst-start', 'Starten', 'st:start') : '')
                . ($m['dienste'] ? $menuItem('dienst-neustart', 'Neu starten', 'st:neustart') : '')
                . '<details class="mod-del"><summary class="mod-item danger-text">' . V::icon('st:papierkorb') . ' Entfernen …</summary>'
                . '<form method="post" action="/verwaltung/module" class="mod-remove">' . $csrf . $hidden . '<input type="hidden" name="aktion" value="entfernen">'
                . '<small><strong>' . V::h($m['name']) . ' entfernen.</strong> Ohne Häkchen bleiben die Daten erhalten; eine spätere Installation übernimmt sie.</small>'
                . '<label class="check"><input type="checkbox" name="daten" value="1"> Auch alle Daten löschen (nicht rückgängig zu machen)</label>'
                . '<label class="muted">Zur Bestätigung den Namen „' . V::h($m['name']) . '“ eintippen<input name="bestaetigung" autocomplete="off" required maxlength="80" placeholder="' . V::h($m['name']) . '"></label>'
                . '<button class="danger"' . $off . '>' . V::icon('st:papierkorb') . ' Modul entfernen</button></form></details></div></details>';
            $formUpd = fn(string $aktion, string $label, string $class, string $icon, string $title = '') => '<form method="post" action="/verwaltung/module" class="inline">' . $csrf . $hidden . '<input type="hidden" name="aktion" value="' . $aktion . '">'
                . '<button class="' . $class . '" title="' . V::h($title ?: strip_tags($label)) . '"' . $off . '>' . V::icon($icon) . ($label !== '' ? ' ' . $label : '') . '</button></form>';
            // Ein rundes Pfeil-Symbol für beides: Update einspielen (wenn eins da ist) oder nach einem Update suchen.
            $upd = $m['update']
                ? '<div class="mod-upd">' . $formUpd('aktualisieren', 'Auf ' . V::h($m['update']['version']) . ' aktualisieren', 'upd-pill', 'st:aktualisieren', 'Update auf ' . $m['update']['version'] . ' installieren') . '</div>'
                : '<div class="mod-upd"><span class="tag">aktuell</span>' . $formUpd('pruefen', '', 'icon-btn round', 'st:aktualisieren', 'Nach Update suchen') . '</div>';
            $logo = $m['path'] ? '<a class="t-ico mod-open" href="' . V::h($m['path']) . '/" title="' . V::h($m['name']) . ' öffnen" aria-label="' . V::h($m['name']) . ' öffnen">' . V::icon('modul:' . $m['id']) . '<span class="go">' . V::icon('st:oeffnen') . '</span></a>'
                : '<span class="t-ico">' . V::icon('modul:' . $m['id']) . '</span>';
            $tiles .= '<div class="mod-tile"><div class="mod-head">' . $logo . $state . '</div>'
                . '<p class="mod-name">' . V::h($m['name']) . '</p><p class="muted mod-sub">Version ' . V::h($m['version']) . '</p>' . ($m['description'] !== '' ? '<p class="muted mod-desc">' . V::h($m['description']) . '</p>' : '')
                . $upd
                . ($m['update'] ? '<p class="muted mod-desc">Neue Version <strong>' . V::h($m['update']['version']) . '</strong>' . ($m['update']['source'] === 'github' ? ' (GitHub' . ($m['update']['signed'] === false ? ', nicht signiert' : '') . ')' : ' (mit der Basis geliefert)')
                    . '. Vorher wird ein Schnappschuss der Moduldaten angelegt; schlägt etwas fehl, springt das Modul zurück.</p>' : '')
                . '<div class="mod-foot">' . $more . '</div></div>';
        }
        foreach ($st['available'] as $m) {
            $tiles .= '<div class="mod-tile available"><div class="mod-head"><span class="t-ico">' . V::icon('modul:' . $m['id']) . '</span><span class="tag">verfügbar</span></div>'
                . '<p class="mod-name">' . V::h($m['name']) . '</p><p class="muted mod-sub">Version ' . V::h($m['version']) . ($m['signed'] === false ? ' <span class="tag warn">nicht signiert</span>' : '') . '</p>'
                . ($m['description'] !== '' ? '<p class="muted mod-desc">' . V::h($m['description']) . '</p>' : '')
                . '<p class="muted mod-desc">' . ($m['source'] === 'github' ? 'Von GitHub' : 'Mit der Basis geliefert') . ($m['path'] ? ' · Adresse ' . V::h($m['path']) : '') . '</p>'
                . '<div class="mod-foot"><form method="post" action="/verwaltung/module">' . $csrf . '<input type="hidden" name="aktion" value="installieren"><input type="hidden" name="id" value="' . V::h($m['id']) . '">'
                . '<button' . $off . '>' . V::icon('st:herunter') . ' Installieren</button></form></div></div>';
        }
        $body .= '<h2>Module</h2>' . ($tiles !== '' ? '<div class="mod-grid">' . $tiles . '</div>' : '<p>Noch keine Module installiert, und es sind keine weiteren verfügbar.</p>');
        $body .= '<form method="post" action="/verwaltung/module" class="inline">' . $csrf . '<input type="hidden" name="aktion" value="status"><button class="secondary"' . $off . '>Status der Dienste aktualisieren</button></form> '
            . '<form method="post" action="/verwaltung/module" class="inline">' . $csrf . '<input type="hidden" name="aktion" value="pruefen"><button class="secondary"' . $off . '>Nach Modulen sehen</button></form> '
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
                case 'status': $store->refreshStates(); break;
                case 'dienst-start': case 'dienst-neustart': $restart = $r->field('aktion', 20) === 'dienst-neustart'; $store->startService($id, $restart); $this->audit->write($admin, $restart ? 'modul-dienst-neustart' : 'modul-dienst-start', $id); break;
                case 'automatisch': $on = $r->field('an', 1) === '1'; $store->auto($on); $this->audit->write($admin, 'modul-automatisch', $on ? 'an' : 'aus'); break;
                case 'installieren': $store->install($id); $this->db->store('erstadmin:' . $id, (string)(int)$this->session['user_id']); $this->audit->write($admin, 'modul-installieren', $id); break;
                case 'aktualisieren': $store->update($id); $this->audit->write($admin, 'modul-aktualisieren', $id); break;
                case 'entfernen':
                    $name = '';
                    foreach ($store->status()['installed'] as $m) if ($m['id'] === $id) $name = (string)$m['name'];
                    if ($name === '' || mb_strtolower(trim($r->field('bestaetigung', 80))) !== mb_strtolower($name)) throw new Problem('Zum Entfernen bitte den Namen des Moduls („' . ($name !== '' ? $name : $id) . '“) zur Bestätigung eintippen.', 422);
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
        $working = $busy ? '<div class="working" role="status" aria-live="polite"><span class="spinner" aria-hidden="true"></span><div><strong>Update läuft – bitte nicht erneut starten und nichts anderes anklicken.</strong>'
            . '<p>' . V::h($st['state'] === 'requested' ? 'Der Auftrag wird übernommen …' : 'Aktueller Schritt: ' . $st['step']) . '. Die Seite lädt sich alle 5 Sekunden selbst neu und kann zwischendurch kurz nicht erreichbar sein. '
            . 'Erst wenn hier „fertig“ steht, ist das Update ganz durchgelaufen.</p></div></div>' : '';
        $rows = '';
        foreach ($st['available'] as $a) {
            $rows .= '<div class="box wide"><h3>Basis ' . V::h($a['version']) . ($a['prerelease'] ? ' <span class="tag">beta</span>' : '')
                . (!$a['signed'] ? ' <span class="tag warn">nicht signiert</span>' : '') . '</h3>'
                . ($a['published'] !== '' ? '<p>Veröffentlicht: ' . V::h($a['published']) . '</p>' : '') . '<pre class="notes">' . V::h($a['notes']) . '</pre>'
                . '<form method="post" action="/verwaltung/updates">' . $csrf . '<input type="hidden" name="aktion" value="installieren"><input type="hidden" name="version" value="' . V::h($a['version']) . '">'
                . '<p>Vorher wird ein Schnappschuss der Daten angelegt. Schlägt etwas fehl, springt die Suite automatisch zurück. Die Seite ist einige Minuten nicht erreichbar.</p>'
                . '<button' . ($busy ? ' disabled' : '') . '>Jetzt installieren</button></form></div>';
        }
        $failed = $st['state'] === 'failed' || $st['error'];
        $kind = $failed ? 'err' : ($st['available'] ? 'new' : 'ok');
        $head = $failed ? 'Nicht erfolgreich' : ($st['available'] ? 'Neue Version verfügbar: Basis ' . $st['available'][0]['version'] : 'Aktuell – keine neuere Version');
        $body = $working . V::error($error) . V::notice($notice)
            . '<div class="update-state ' . $kind . '"><span class="big" aria-hidden="true">' . ($failed ? '!' : ($st['available'] ? '↑' : '✓')) . '</span><div><strong>' . V::h($head) . '</strong>'
            . '<span class="muted">Installiert: Basis ' . V::h($st['installed']) . ' · Zustand: ' . V::h($labels[$st['state']] ?? $st['state'])
            . ($st['message'] !== '' ? ' (' . V::h($st['message']) . ')' : '') . ' · Zuletzt geprüft: ' . V::date($st['checked_at']) . '</span></div></div>'
            . ($st['error'] ? '<p class="error">' . V::h($st['error']) . '</p>' : '')
            . (!$st['service'] ? '<p class="error">Der Update-Dienst ist nicht eingerichtet. Bitte den Installer ausführen.</p>' : '')
            . '<form method="post" action="/verwaltung/updates" class="inline">' . $csrf . '<input type="hidden" name="aktion" value="pruefen"><button class="secondary"' . ($busy ? ' disabled' : '') . '>Jetzt nach Updates sehen</button></form>'
            . $rows
            . '<p class="muted">Module werden beim Update der Basis automatisch mit aktualisiert, sofern unter <a href="/verwaltung/module">Module</a> die automatische Aktualisierung eingeschaltet ist; das geschieht täglich nach der Prüfung. Ein gerade beschäftigtes Modul (z. B. bei laufender Zahlung) wird dabei übersprungen und am nächsten Tag erneut versucht.</p>';
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

    /** Letzter abgerufener Kontostand mit Zeit. */
    private function balanceLine(?array $g): string {
        if (!$g) return '<p class="muted">Guthaben noch nicht abgefragt.</p>';
        $wert = isset($g['wert']) && is_numeric($g['wert']) ? number_format((float)$g['wert'], 2, ',', '.') . ' €' : 'unbekannt';
        return '<p>' . V::symbol('coins') . ' Guthaben: <strong>' . V::h($wert) . '</strong> <span class="muted">(abgefragt ' . V::date((int)($g['zeit'] ?? 0)) . ')</span></p>';
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

    /** Kleines „i“ mit Hilfetext (Tooltip bei Maus und Tastatur). */
    private function tip(string $text): string {
        return ' <span class="hilfe" tabindex="0" role="note" aria-label="' . V::h($text) . '" data-tip="' . V::h($text) . '">i</span>';
    }

    /** Eingabefeld mit Tooltip hinter der Beschriftung. */
    private function tipInput(string $name, string $label, string $tip, string $type = 'text', string $value = '', string $extra = ''): string {
        return '<label>' . V::h($label) . $this->tip($tip) . '<input type="' . $type . '" name="' . $name . '" value="' . V::h($value) . '" ' . $extra . '></label>';
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
            . '<label>Erlaubte fhirBasisUrl aus T2med – weitere Adressen (eine je Zeile)<textarea name="launch_urls" rows="2">' . V::h(implode("\n", array_diff((array)($t2['launch_urls'] ?? []), [(string)($t2['base_url'] ?? '')]))) . '</textarea></label>'
            . V::input('api_key', 'API-Schlüssel der FHIR-Schnittstelle', 'password', '', 'title="Beim T2med-Demo-Schlüssel hier nichts eintragen: Feld leer lassen und den gespeicherten Schlüssel entfernen. Ein eigener Schlüssel führt dort zu Fehler 403." autocomplete="new-password" placeholder="' . V::h($set((bool)($t2['api_key_gesetzt'] ?? false))) . '"')
            . '<p class="muted small">Schlüssel der FHIR-Schnittstelle (wird als X-API-Key gesendet). Antwortet T2med mit „FHIR-API-Key fehlt oder ist ungültig“, passt der Wert nicht, z. B. wenn hier das Client Secret des Drittanbieters steht. Mit „Schlüssel entfernen“ nutzt die Kasse wie das alte Tool den hinterlegten T2med-Demo-Schlüssel. <strong>Beim Demo-Schlüssel darf hier kein eigener API-Schlüssel stehen</strong> (sonst Fehler 403): Feld leer lassen und den gespeicherten Schlüssel entfernen.</p>'
            . V::checkbox('api_key_entfernen', '1', 'Gespeicherten Schlüssel entfernen (Kasse nimmt den T2med-Demo-Schlüssel)', false)
            . '<label>Zertifikat des T2med-Servers (PEM, optional)' . (($t2['zertifikat'] ?? '') !== '' ? ' – gespeichert, SHA-256 <code>' . V::h(substr((string)$t2['zertifikat'], 0, 16)) . '…</code>' : '')
            . '<textarea name="ca_pem" rows="3" placeholder="-----BEGIN CERTIFICATE-----"></textarea></label>'
            . V::checkbox('ca_entfernen', '1', 'Gespeichertes Zertifikat entfernen', false)
            . V::checkbox('pin', '1', 'Nur genau dieses Zertifikat annehmen (Pinning)', (bool)($t2['pin'] ?? false))
            . '<p class="muted small">Die „erlaubte fhirBasisUrl“ ist die FHIR-Adresse, die T2med beim Aufruf mitschickt (Platzhalter fhirBasisUrl). Die Adresse des FHIR-Servers oben gilt immer als erlaubt; weitere Zeilen nur, wenn T2med sich anders meldet. Bei einer Ablehnung zeigt die Kasse den tatsächlich gesendeten Wert.</p>'
            . '<button>Speichern</button></form>'
            . '<div class="box wide"><h3>Eintrag in T2med (Drittanbieter-Zugriffe verwalten → Neue URL hinzufügen)</h3>'
            . '<p>Als Adresse genau so eintragen, mit den Platzhaltern, und eine Bezeichnung wie „Kartenzahlung“ vergeben:</p>'
            . '<p><code class="secret">kienzle-sumup://?kontextId=${kontextId}&amp;fhirBasisUrl=${fhirBasisUrl}&amp;oAuthToken=${oAuthToken}</code></p>'
            . '<p class="muted small">Ausführliche Anleitung mit Bildern: in der Kasse unter Einstellungen → Zahlung &amp; T2med. Der Starter muss auf dem Arbeitsplatz installiert sein.</p></div>'
            . ($t2 ? '<p>Zertifikat: ' . (($t2['zertifikat'] ?? '') !== '' ? '<code>' . V::h((string)$t2['zertifikat']) . '</code>' . ($t2['pin'] ? ' (fest hinterlegt)' : '') : '<strong>nicht hinterlegt</strong> (nur öffentlich beglaubigte Zertifikate werden angenommen)') . '</p>'
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('zertifikat-holen') . '<button>Zertifikat des Servers abrufen</button></form> '
                . (($st['zertifikate']['t2med']['fingerprint'] ?? '') !== '' ? '<form method="post" action="/verwaltung/verbindungen" class="box">' . $this->hidden('zertifikat-uebernehmen', ['fingerprint' => (string)$st['zertifikate']['t2med']['fingerprint']])
                    . '<p>Abgerufen: SHA-256 <code>' . V::h((string)$st['zertifikate']['t2med']['fingerprint']) . '</code>. Nur übernehmen, wenn der Fingerabdruck mit dem Zertifikat des T2med-Servers übereinstimmt (z. B. im Browser unter „Zertifikat anzeigen“).</p><button>Übernehmen und fest hinterlegen</button></form>' : '')
                . $this->testLine($st['tests'], 't2med') . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('pruefen', ['art' => 't2med']) . '<button>Verbindung prüfen</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('entfernen', ['was' => 't2med']) . '<button class="danger">Entfernen</button></form>' : '')
            . '</section>';
        // E-Mail
        $sec = (string)($mail['sicherheit'] ?? 'starttls');
        $gmail = ($mail['art'] ?? '') === 'gmail';
        $pending = $this->con()->gmailPending();
        $connected = $gmail && !empty($mail['verbunden']);
        $reconnect = $connected && !$pending;
        $lead = !$mail ? '' : ($gmail && !$connected ? '<p class="error">Gmail: ' . V::h((string)($mail['absender'] ?? '')) . ' – noch nicht angemeldet</p>'
            : '<div class="status-ok">' . ($gmail ? 'Mit Google verbunden: ' . V::h((string)($mail['absender'] ?? '')) : 'SMTP-Server: ' . V::h((string)($mail['host'] ?? '')) . ' (' . V::h((string)($mail['absender'] ?? '')) . ')') . '</div>');
        $body .= '<section><h2>E-Mail-Versand</h2>' . $lead
            . '<details class="fold"' . (!$gmail ? ' open' : '') . '><summary><h3>SMTP-Server (eigener Mailserver, z. B. Praxis-Domain)' . ($mail && !$gmail ? ' <span class="tag ok">✓ eingerichtet</span>' : ' <span class="tag opt">nicht eingerichtet</span>') . '</h3></summary>'
            . 'form method="post" action="/verwaltung/verbindungen" class="box wide">' . $this->hidden('mail')
            . V::input('host', 'SMTP-Server', 'text', (string)($mail['host'] ?? ''), 'placeholder="smtp.example.org"')
            . V::input('port', 'Port', 'number', (string)($mail['port'] ?? '587'), 'min="1" max="65535"')
            . '<label>Verschlüsselung<select name="sicherheit"><option value="starttls"' . ($sec === 'starttls' ? ' selected' : '') . '>STARTTLS (meist 587)</option>'
            . '<option value="smtps"' . ($sec === 'smtps' ? ' selected' : '') . '>SMTPS (meist 465)</option></select></label>'
            . V::input('benutzer', 'Benutzername', 'text', (string)($mail['benutzer'] ?? ''), 'autocomplete="off"')
            . V::input('passwort', 'Passwort', 'password', '', 'autocomplete="new-password" placeholder="' . V::h($set((bool)($mail['passwort_gesetzt'] ?? false))) . '"')
            . V::input('absender', 'Absender', 'email', (string)($mail['absender'] ?? ''), 'placeholder="praxis@example.org"')
            . '<button>Speichern</button></form>' . '</details>'
            . '<details class="fold"' . (($gmail && !$connected) || $pending ? ' open' : '') . '><summary><h3>Gmail-Konto (OAuth, ohne Passwort im Klartext)' . ($connected ? ' <span class="tag ok">✓ eingerichtet</span>' : ' <span class="tag opt">nicht eingerichtet</span>') . '</h3></summary>'
            . ($reconnect ? '<details class="fold"><summary><h3>Erneut einrichten oder anderes Konto verbinden</h3></summary>' : '')
            . '<p class="muted">Gilt für ein normales Gmail-Konto. Jede Praxis legt dafür einmalig einen eigenen Zugang bei Google an (kostenlos, ca. 10 Minuten). Gesendet wird nur über die Gmail-Schnittstelle mit der Berechtigung „E-Mails senden“; die Suite kann keine Postfächer lesen.</p>'
            . '<ol class="muted"><li>Auf <strong>console.cloud.google.com</strong> mit dem Gmail-Konto anmelden und oben ein <strong>neues Projekt</strong> anlegen (Name z. B. „Kienzle-Suite“).</li>'
            . '<li>Im Suchfeld oben „<strong>Gmail API</strong>“ suchen und <strong>Aktivieren</strong> klicken.</li>'
            . '<li>Im Suchfeld „<strong>Google Auth Platform</strong>“ öffnen und <strong>Erste Schritte</strong> wählen: App-Name (z. B. „Kienzle-Suite“) und deine E-Mail-Adresse als Support-E-Mail eintragen, Zielgruppe <strong>Extern</strong>, Kontakt-E-Mail eintragen, Bedingungen bestätigen.</li>'
            . '<li>Links <strong>Datenzugriff</strong> → „Bereiche hinzufügen oder entfernen“ → in „Bereiche manuell hinzufügen“ <code>https://www.googleapis.com/auth/gmail.send</code> eintragen, hinzufügen, <strong>Speichern</strong>.</li>'
            . '<li>Für „In Produktion“ verlangt Google vorher unter <strong>Branding</strong>: gültigen App-Namen, Support-E-Mail sowie Adressen für <strong>Startseite der App</strong> und <strong>Datenschutzerklärung</strong> (zwei Webadressen, z. B. die Startseite und die Datenschutzseite der eigenen Praxis-Website). Die Domain dieser Adressen unter „Autorisierte Domains“ eintragen, dann <strong>Speichern</strong>. Die Adressen werden nur auf Googles Zustimmungsseite angezeigt, es muss keine Seite der Suite sein.</li>'
            . '<li>Links <strong>Zielgruppe</strong> → <strong>App veröffentlichen</strong> („In Produktion“ statt „Test“). Im Teststatus läuft die Anmeldung nach 7 Tagen ab. Google verlangt für diesen Bereich eine Überprüfung der App, lässt eine nicht überprüfte App für wenige Nutzer aber zu: Bei der Anmeldung erscheint „Google hat diese App nicht überprüft“ – dort <strong>Erweitert</strong> → „Weiter zu … (unsicher)“ wählen.</li>'
            . '<li>Links <strong>Clients</strong> → <strong>Client erstellen</strong> → Anwendungstyp <strong>Desktop-App</strong>, Namen vergeben, <strong>Erstellen</strong>. Client-ID und Client-Secret kopieren (das Secret wird nur direkt nach dem Erstellen angezeigt) und unten eintragen.</li></ol>'
            . '<p class="muted">Die Bezeichnungen bei Google ändern sich gelegentlich; sinngemäß gesucht werden: Branding/App-Informationen, Zielgruppe, Datenzugriff, Clients. Stimmt etwas nicht mit deiner Ansicht überein, bitte melden.</p>'
            . '<form method="post" action="/verwaltung/verbindungen" class="box wide">' . $this->hidden('gmail-start') . '<h4>Schritt 1: bei Google anmelden</h4>'
            . $this->tipInput('client_id', 'Client-ID', 'Endet meist auf .apps.googleusercontent.com.', 'text', (string)($pending['client_id'] ?? $mail['client_id'] ?? ''), 'autocomplete="off" maxlength="200"')
            . $this->tipInput('absender', 'Gmail-Adresse (Absender)', 'Das Konto, mit dem du dich bei Google anmeldest; von dort werden die E-Mails verschickt.', 'email', (string)($pending['absender'] ?? $mail['absender'] ?? ''), 'placeholder="praxis@gmail.com"')
            . '<button>Weiter</button></form>'
            . ($pending ? '<div class="box wide"><h4>Schritt 2: Code übernehmen</h4><p><a href="' . V::h($this->con()->gmailUrl($pending)) . '" target="_blank" rel="noopener">Hier bei Google anmelden und den Zugriff erlauben</a> (öffnet in neuem Tab). '
                . 'Danach zeigt der Browser eine Fehlerseite („Seite nicht erreichbar“ unter <code>localhost</code>) – das ist richtig. Die <strong>ganze Adresse aus der Adresszeile</strong> (beginnt mit <code>http://localhost/?state=</code>) kopieren und hier einfügen:</p>'
                . '<form method="post" action="/verwaltung/verbindungen">' . $this->hidden('gmail')
                . '<label>Adresse aus der Adresszeile<input type="text" name="adresse" required maxlength="2000" autocomplete="off" placeholder="http://localhost/?state=…&amp;code=…"></label>'
                . V::input('client_secret', 'Client-Secret', 'password', '', 'autocomplete="new-password" required maxlength="200"')
                . '<button>Verbinden</button></form><p class="muted">Die Anmeldung ist 30 Minuten gültig.</p></div>' : '')
            . ($reconnect ? '</details>' : '')
            . '</details>'

            . ($mail ? $this->testLine($st['tests'], 'mail') . '<div class="actions"><form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('pruefen', ['art' => 'mail']) . '<button>Anmeldung prüfen</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline grp">' . $this->hidden('testmail') . '<input type="email" name="an" placeholder="empfaenger@example.org" required maxlength="254" aria-label="Empfänger der Testnachricht"> <button>Testnachricht senden</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('entfernen', ['was' => 'mail']) . '<button class="danger">Entfernen</button></form></div>' : '')
            . '</section>';
        // SMS
        $sms = $st['sms'] ?? [];
        $body .= '<section><h2>SMS (seven.io)</h2><p class="muted">Für Einmalcodes als Ersatz bei der Admin-Anmeldung und später für Nachrichten der kienzlefon-App. Schlüssel unter seven.io → Entwickler → API-Schlüssel anlegen.</p>'
            . '<form method="post" action="/verwaltung/verbindungen" class="box wide">' . $this->hidden('sms')
            . $this->tipInput('api_key', 'API-Schlüssel von seven.io', 'Bei seven.io unter Entwickler → API-Schlüssel → „API-Key anlegen“: Label z. B. „Kienzle-Suite“, Umgebung Live (Sandbox verschickt nichts), Gültig bis Unbegrenzt, Zugriff auf Subaccounts aus. Berechtigungen nur „sms“ (Versand) und „balance“ (Schlüssel prüfen); alle anderen aus.', 'password', '', 'autocomplete="new-password" placeholder="' . V::h($set((bool)($sms['schluessel_gesetzt'] ?? false))) . '"')
            . $this->tipInput('absender', 'Absendername (optional, höchstens 11 Zeichen)', 'Erscheint beim Empfänger als Absender, z. B. „Praxis“. Nur Buchstaben und Ziffern, keine Leerzeichen; leer = Standard von seven.io.', 'text', (string)($sms['absender'] ?? ''), 'maxlength="16" placeholder="Praxis"')
            . '<button>Speichern</button></form>'
            . ($sms ? $this->testLine($st['tests'], 'sms') . '<div class="actions"><form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('pruefen', ['art' => 'sms']) . '<button>Schlüssel prüfen</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline grp">' . $this->hidden('testsms') . '<input type="tel" name="an" placeholder="01701234567" required maxlength="30" aria-label="Handynummer der Test-SMS"> <button>Test-SMS senden</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('entfernen', ['was' => 'sms']) . '<button class="danger">Entfernen</button></form></div>' : '')
            . '</section>';
        // Letterxpress
        $lxp = $st['letterxpress'] ?? [];
        $body .= '<section><h2>Letterxpress (Briefversand)</h2><p class="muted">Rechnungen und Briefe per Post. Zugang unter letterxpress.de im Kundenkonto → API anlegen. Module, die Post versenden, nutzen diese Verbindung. Im Testbetrieb wird nichts gedruckt oder verschickt.</p>'
            . '<form method="post" action="/verwaltung/verbindungen" class="box wide">' . $this->hidden('letterxpress')
            . $this->tipInput('username', 'Letterxpress Benutzername', 'Benutzername des Letterxpress-Kundenkontos.', 'text', (string)($lxp['username'] ?? ''), 'autocomplete="off" maxlength="200"')
            . $this->tipInput('api_key', 'Letterxpress API-Key', 'Im Letterxpress-Kundenbereich unter Mein Konto → Zugangsdaten → LXP API erzeugen. Er wird nach dem Speichern nie wieder angezeigt.', 'password', '', 'autocomplete="new-password" placeholder="' . V::h($set((bool)($lxp['schluessel_gesetzt'] ?? false))) . '"')
            . '<p>' . (($lxp['modus'] ?? 'test') === 'live' ? '<span class="tag ok">Live</span> Echter Postversand, kostenpflichtig.' : '<span class="tag warn">Testbetrieb</span> Nichts wird gedruckt oder verschickt.') . '</p>'
            . '<label>Betrieb' . $this->tip('Im Testbetrieb landen Aufträge nur im Postausgang bei Letterxpress (nach 7 Tagen gelöscht) und können dort geprüft werden. Erst nach einem gelungenen Test auf Live stellen.') . '<select name="modus"><option value="test"' . (($lxp['modus'] ?? 'test') !== 'live' ? ' selected' : '') . '>Test (nur Postausgang bei Letterxpress, nichts wird gedruckt oder verschickt)</option>'
            . '<option value="live"' . (($lxp['modus'] ?? '') === 'live' ? ' selected' : '') . '>Live (echter Postversand, kostenpflichtig)</option></select></label>'
            . '<button>Speichern</button></form>'
            . ($lxp ? $this->testLine($st['tests'], 'letterxpress') . $this->balanceLine($st['guthaben']['letterxpress'] ?? null)
                . '<div class="actions"><form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('pruefen', ['art' => 'letterxpress']) . '<button>Zugang prüfen</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('pruefen', ['art' => 'guthaben']) . '<button class="secondary">' . V::symbol('coins') . ' Guthaben abfragen</button></form> '
                . '<form method="post" action="/verwaltung/verbindungen" class="inline">' . $this->hidden('entfernen', ['was' => 'letterxpress']) . '<button class="danger">Entfernen</button></form></div>' : '')
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
        $body = preg_replace('#(<p>Zugänge, die mehrere Module brauchen.*?</p>)#s', '<section class="card">$1</section>', $body, 1);
        // Jeder Abschnitt klappt zu; eingerichtete tragen ein grünes „✓ eingerichtet“, offene und fehlerhafte bleiben aufgeklappt.
        $done = ['T2med (FHIR)' => trim((string)($t2['base_url'] ?? '')) !== '', 'E-Mail-Versand' => $mail !== null && $mail !== [],
            'SMS (seven.io)' => !empty($st['sms']), 'Letterxpress (Briefversand)' => !empty($st['letterxpress']), 'Netzwerkspeicher' => !empty($st['ablage'])];
        foreach ($done as $title => $ok) V::$status[$title] = [$ok, 'eingerichtet', 'nicht eingerichtet'];
        $body = preg_replace_callback('#<section><h2>([^<]*)</h2>(.*?)</section>#s', function (array $m) use ($done): string {
            $title = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            $open = array_key_exists($title, $done) && !$done[$title] || str_contains($m[2], 'class="error"');
            return '<section class="card"><details class="fold"' . ($open ? ' open' : '') . '><summary><h2>' . $m[1] . '</h2></summary>' . $m[2] . '</details></section>';
        }, $body);
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
                        'ca_entfernen' => $f('ca_entfernen') === '1', 'api_key_entfernen' => $f('api_key_entfernen') === '1', 'pin' => $f('pin') === '1']);
                    $what = 't2med'; break;
                case 'mail':
                    $c->saveMail(['host' => $f('host'), 'port' => $f('port', 10), 'sicherheit' => $f('sicherheit', 20), 'benutzer' => $f('benutzer'), 'passwort' => $f('passwort'), 'absender' => $f('absender')]);
                    $what = 'mail'; break;
                case 'sms': $c->saveSms(['api_key' => $f('api_key'), 'absender' => $f('absender', 20)]); $what = 'sms'; break;
                case 'letterxpress': $c->saveLetterxpress(['username' => $f('username', 200), 'api_key' => $f('api_key', 200), 'modus' => $f('modus', 10)]); $what = 'letterxpress'; break;
                case 'gmail-start': $c->gmailStart($f('client_id', 200), $f('absender', 254)); $what = 'gmail-start'; break;
                case 'gmail': $c->saveGmail($f('client_secret', 200), $f('adresse', 2000)); $what = 'gmail'; break;
                case 'testsms': $c->testSms($f('an', 40)); $what = 'testsms'; break;
                case 'ziel':
                    $c->saveTarget(['id' => $f('id', 40), 'name' => $f('name', 80), 'art' => $f('art', 10), 'host' => $f('host'), 'freigabe' => $f('freigabe', 100), 'port' => $f('port', 10),
                        'ordner' => $f('ordner'), 'benutzer' => $f('benutzer', 100), 'passwort' => $f('passwort'), 'domaene' => $f('domaene', 100), 'gast' => $f('gast') === '1']);
                    $what = 'ziel ' . $f('id', 40); break;
                case 'ziel-loeschen': $c->removeTarget($f('id', 40)); $what = 'ziel-entfernt ' . $f('id', 40); break;
                case 'entfernen': $c->remove($f('was', 20)); $what = 'entfernt ' . $f('was', 10); break;
                case 'pruefen': $c->test($f('art', 20), $f('id', 40)); $what = 'pruefen ' . $f('art', 10) . ' ' . $f('id', 40); break;
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
        return Response::redirect($f('zurueck', 10) === 'start' ? '/' : '/verwaltung/verbindungen');
    }

    // ---- Neustart -----------------------------------------------------------------------------------------------
    public static string $restartUnit = '/etc/systemd/system/kienzle-neustart.path';

    private function restartPage(?string $error = null, ?string $notice = null): Response {
        $art = $this->req->get('art', 10) === 'server' ? 'server' : 'dienste';
        $text = $art === 'server'
            ? 'Der ganze Server startet neu. Alle Arbeitsplätze verlieren kurz die Verbindung (Drucken, Kasse, Terminzettel); nach etwa ein bis zwei Minuten ist die Suite wieder da und du musst dich neu anmelden.'
            : 'Webinterface und alle Module werden neu gestartet. Das dauert wenige Sekunden; laufende Anmeldungen bleiben bestehen, eine Seite lädt eventuell einmal neu.';
        $body = V::error($error) . V::notice($notice) . '<section class="card"><h2>' . ($art === 'server' ? 'Server neu starten' : 'Dienste neu starten') . '</h2><p>' . V::h($text)
            . '</p><p class="muted">Läuft gerade ein Update, eine Sicherung oder eine Zahlung an der Kasse, bitte erst warten.</p>'
            . '<form method="post" action="/verwaltung/neustart">' . V::csrf($this->session['csrf']) . '<input type="hidden" name="art" value="' . $art . '">'
            . '<button class="danger">' . ($art === 'server' ? 'Server jetzt neu starten' : 'Dienste jetzt neu starten') . '</button></form></section>';
        return Response::html(V::page('Neu starten', $body, $this->nav(), $this->config->practice), $error ? 422 : 200);
    }

    private function restartDo(Request $r, string $admin): Response {
        $art = $r->field('art', 10);
        try {
            if (!in_array($art, ['dienste', 'server'], true)) throw new Problem('Unbekannte Art des Neustarts.', 422);
            if (!is_file(self::$restartUnit)) throw new Problem('Der Neustart-Dienst ist nicht eingerichtet. Bitte den Installer ausführen.', 503);
            $file = $this->db->directory . '/anfragen/neustart.json';
            if (is_file($file)) throw new Problem('Ein Neustart wurde schon angefordert.', 409);
            $dir = dirname($file);
            if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
            $tmp = $dir . '/.neustart.' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, json_encode(['art' => $art], JSON_THROW_ON_ERROR)) === false || !rename($tmp, $file)) { @unlink($tmp); throw new Problem('Anfrage konnte nicht gespeichert werden.', 500); }
        } catch (Problem $e) {
            return $this->restartPage($e->getMessage());
        }
        $this->audit->write($admin, 'neustart', $art);
        return Response::html(V::page('Neu starten', V::notice($art === 'server' ? 'Der Server startet neu. In ein bis zwei Minuten diese Seite neu laden.' : 'Die Dienste starten neu. Gleich diese Seite neu laden.')
            . '<p><a class="button" href="/">Zur Startseite</a></p>', $this->nav(), $this->config->practice))->with('Refresh', $art === 'server' ? '90; url=/' : '8; url=/');
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

    /** Papierbreite des Bondruckers in mm (58 oder 80); gilt für alle Module. */
    private function bonWidth(): int { return (int)$this->db->setting('drucker.bonbreite') === 80 ? 80 : 58; }

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
            . '<p>Drucker werden einmal hier eingerichtet; alle Module können sie nutzen. Echte Drucker brauchen keine Freigabe. Die Freigabe der virtuellen Drucker wirkt erst, '
            . 'wenn die <a href="/verwaltung/firewall">Firewall</a> Drucken (IPP 631 bzw. Windows-Freigabe 445) aus den Praxisnetzen zulässt.</p>';
        $rows = $virtualRows = '';
        foreach ($st['queues'] as $q) {
            $actions = '<form method="post" action="/verwaltung/drucker" class="inline">' . $this->hidden('testseite', ['name' => $q['name']]) . '<button' . $dis . '>Testseite</button></form> ';
            if ($q['managed']) {
                // Freigaben gibt es nur für die virtuellen Drucker der Module: Die Arbeitsplätze drucken dorthin, echte Drucker braucht kein Arbeitsplatz direkt.
                if ($q['modul'] !== '') $actions .= '<form method="post" action="/verwaltung/drucker" class="inline">' . $this->hidden('freigabe', ['name' => $q['name']]) . $this->shareBoxes($q['share'])
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
            . $this->virtualPrinterHelp($st['queues'])
            . '<form method="post" action="/verwaltung/drucker">' . $this->hidden('abgleichen') . '<button' . $dis . '>Virtuelle Drucker der Module abgleichen</button></form>';
        $roles = $this->printerRoles();
        $pick = '';
        foreach (self::PRINTER_ROLES as $k => $label) {
            $opts = '<option value="">– keiner –</option>';
            foreach ($st['queues'] as $q) if ($q['modul'] === '') $opts .= '<option value="' . V::h($q['name']) . '"' . ($roles[$k] === $q['name'] ? ' selected' : '') . '>' . V::h($q['name']) . '</option>';
            $pick .= '<label>' . V::h($label) . '<select name="' . $k . '">' . $opts . '</select></label>';
        }
        $width = $this->bonWidth();
        $pick .= '<label>Papierbreite des Bondruckers <span class="hilfe" tabindex="0" role="note" aria-label="Gilt für alle Module (Kasse, Terminzettel)." data-tip="Gilt für alle Module, die Bons drucken (Kasse, Terminzettel). 58 mm: Epson TM-m10, 80 mm: Epson TM-m30III.">?</span><select name="bonbreite"><option value="58"' . ($width === 58 ? ' selected' : '') . '>58 mm (Epson TM-m10)</option><option value="80"' . ($width === 80 ? ' selected' : '') . '>80 mm (Epson TM-m30III)</option></select></label>';
        $body .= '<h2>Wofür welcher Drucker?</h2><p class="muted">Module drucken auf diese Zuordnung; in den Einstellungen der Module wird kein Drucker und keine Papierbreite mehr gewählt.</p>'
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
            . V::input('name', 'Name der Warteschlange (leer = Vorschlag)', 'text', '', 'maxlength="40"')
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

    /** Aufklappbare Anleitung: virtuelle Drucker der Module an den Arbeitsplätzen einrichten (Windows, Mac, T2med), im Format der Kasse. */
    private function virtualPrinterHelp(array $queues): string {
        $host = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'server')));
        if (!$this->config->ownHost($host)) $host = 'server';
        $ppd = ['terminzettel' => '/terminzettel/assets/Terminzettel-PDF.ppd', 'sumup' => '/kasse/assets/SumUp-Rechnung-PDF.ppd'];
        $blocks = '';
        foreach ($queues as $q) {
            if ($q['modul'] === '') continue;
            $name = (string)$q['name'];
            $url = 'http://' . $host . ':631/printers/' . rawurlencode($name);
            $paper = preg_match('/terminzettel/i', $name) ? 'A6' : 'A4';
            $script = "Enable-WindowsOptionalFeature -Online -FeatureName Printing-Foundation-InternetPrinting-Client -NoRestart | Out-Null\n"
                . "# Druckspooler neu starten und auf den laufenden Dienst warten; alten Drucker entfernen (behebt „Das Handle ist ungültig“)\n"
                . "Restart-Service Spooler -Force\n(Get-Service Spooler).WaitForStatus('Running', '00:00:30')\nStart-Sleep 3\n"
                . "Get-Printer -Name \"" . $name . "\" -ErrorAction SilentlyContinue | Remove-Printer\n"
                . "Add-PrinterDriver -Name \"Microsoft IPP Class Driver\"\n\$url = \"" . $url . "\"\n"
                . "# Anschluss und Drucker anlegen, bis zu viermal (direkt nach dem Neustart des Spoolers ist der Anschluss manchmal noch nicht nutzbar)\n"
                . "for (\$i = 1; \$i -le 4 -and -not (Get-Printer -Name \"" . $name . "\" -ErrorAction SilentlyContinue); \$i++) {\n"
                . "  Add-PrinterPort -Name \$url -ErrorAction SilentlyContinue\n  Start-Sleep 3\n"
                . "  Add-Printer -Name \"" . $name . "\" -DriverName \"Microsoft IPP Class Driver\" -PortName \$url -ErrorAction SilentlyContinue\n}\n"
                . "Get-Printer -Name \"" . $name . "\" | Select-Object Name, DriverName, PortName"
               ;
            $mac = isset($ppd[$q['modul']]) ? ' <a href="' . V::h($ppd[$q['modul']]) . '" download>PDF-Beschreibungsdatei (PPD) herunterladen</a>' : '';
            $blocks .= '<h3>Drucker „' . V::h($name) . '“</h3><ol>'
                . '<li>Windows: Drucker hinzufügen → „Freigegebenen Drucker über den Namen auswählen“: <code>' . V::h($url) . '</code> – Treiber „Microsoft IPP Class Driver“, Papier ' . $paper . '. '
                . 'Per IP-Adresse (z. B. aus dem VPN) statt des Namens: <code>http://&lt;IP-Adresse&gt;:631/printers/' . V::h(rawurlencode($name)) . '</code>.</li>'
                . '<li>Windows 11: Fehlt der Treiber „Microsoft IPP Class Driver“ oder akzeptiert Windows die Adresse nicht, PowerShell als Administrator öffnen und ausführen:<pre>' . V::h($script) . '</pre></li>'
                . '<li>' . V::h('Druckereigenschaften lassen sich nicht öffnen („Das Handle ist ungültig“) oder der Druck bleibt aus: Die Windows-Einrichtung ist veraltet, z. B. nach Neueinrichtung des Servers. Drucker entfernen und mit dem PowerShell-Skript oben neu einrichten (es startet den Druckspooler neu und entfernt den alten Drucker). Eine Meldung beim Löschen alter Anschlüsse („Anschluss unbekannt“) ist harmlos. Möglichst die IP-Adresse statt des Namens verwenden.') . '</li>'
                . '<li>Mac: „' . V::h($name) . '“ hinzufügen, als Treiber die Datei wählen.' . $mac . '</li></ol>';
        }
        if ($blocks === '') return '';
        return '<details class="box"><summary><strong>Anleitung: virtuelle Drucker an den Arbeitsplätzen einrichten</strong></summary>'
            . '<p>Nur die virtuellen Drucker der Module werden an den Arbeitsplätzen (T2med-PC) eingerichtet. Echte Drucker und der Bondrucker brauchen keine Freigabe und keine Einrichtung am Arbeitsplatz: die Module drucken von hier aus.</p>'
            . '<p><strong>Voraussetzung:</strong> Haken bei „für Windows freigeben (Samba)“ bzw. „für macOS und andere freigeben (IPP)“ beim virtuellen Drucker und in der <a href="/verwaltung/firewall">Firewall</a> Drucken (IPP, Port 631) aus dem Praxisnetz erlauben. '
            . 'Windows 11 Pro ab 24H2 lässt Gastzugriff auf Windows-Freigaben (Samba) nicht mehr zu: dort immer über IPP einrichten.</p>'
            . $blocks
            . '<h3>In T2med</h3><ul><li>„Terminzettel“: Einstellungen → Kalender → Terminliste (DIN A6), Drucker „Terminzettel“ wählen.</li>'
            . '<li>„SumUp-Rechnung“: Rechnung aus T2med auf diesen Drucker drucken, sie erscheint danach beim Patienten in der Kasse.</li></ul></details>';
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
        $width = $r->field('bonbreite', 3);
        if ($width !== '' && !in_array($width, ['58', '80'], true)) throw new Problem('Bitte 58 mm oder 80 mm als Papierbreite wählen.', 422);
        if ($width !== '') $this->db->store('drucker.bonbreite', $width);
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

    private function overviewList(array $items, bool $places = false): string {
        $rows = '';
        foreach ($items as $i) {
            $class = ($i['state'] !== 'done' && !empty($i['ignoriert']) ? 'ignored ' : '') . ['done' => 'done', 'open' => 'open', 'error' => 'error', 'info' => 'info'][$i['state']] ?? 'open';
            $icon = ['done' => '✓', 'error' => '!', 'info' => 'i'][$i['state']] ?? '○';
            $title = $i['link'] !== '' ? '<a href="' . V::h($i['link']) . '">' . V::h($i['title']) . '</a>' : V::h($i['title']);
            $manual = !empty($i['manuell']) ? '<form method="post" action="/verwaltung/einrichtung" class="inline">'
                . $this->hidden('bestaetigen', ['punkt' => $i['manuell'], 'erledigt' => $i['state'] === 'done' ? '0' : '1'])
                . '<button class="secondary">' . ($i['state'] === 'done' ? 'Zurücknehmen' : 'Erledigt') . '</button></form>' : '';
            $chips = ($places ? '<span class="tag">' . V::h(Overview::PLACES[$i['ort'] ?? 'web'][0] ?? '') . '</span>' : '') . (!empty($i['optional']) ? '<span class="tag opt">optional</span>' : '');
            $skip = $i['state'] !== 'done' && empty($i['optional']) && isset($i['key']) ? '<form method="post" action="/verwaltung/einrichtung" class="inline">'
                . $this->hidden('ignorieren', ['punkt' => $i['key'], 'an' => !empty($i['ignoriert']) ? '0' : '1'])
                . '<button class="secondary small">' . (!empty($i['ignoriert']) ? 'Wieder beachten' : 'Ignorieren') . '</button></form>' : '';
            $rows .= '<li class="' . $class . '"><span class="nr" aria-hidden="true"></span><span class="mark-icon" aria-hidden="true">' . $icon . '</span><span class="text">' . $title
                . ($chips !== '' ? '<span class="chips">' . $chips . '</span>' : '') . ($i['hint'] !== '' ? '<small>' . V::h($i['hint']) . '</small>' : '') . '</span>' . $manual . $skip . '</li>';
        }
        return '<ol class="checklist">' . $rows . '</ol>';
    }

    private function overviewPage(): Response {
        $o = $this->ov();
        $core = $o->core();
        $modules = $o->modules();
        $sum = $o->summary();
        $pct = $sum['total'] ? (int)round(100 * $sum['done'] / $sum['total']) : 100;
        $again = count(array_filter($core, fn($i) => $i['state'] === 'error' && str_starts_with($i['hint'], 'Erneut nötig')));
        $body = '<p class="lead">' . $sum['done'] . ' von ' . $sum['total'] . ' nötigen Schritten erledigt <span class="muted">(optionale Punkte zählen nicht mit)</span></p>'
            . '<progress class="progress" value="' . $pct . '" max="100" aria-label="Fortschritt der Einrichtung">' . $pct . ' %</progress>'
            . ($again ? '<div class="notice warn"><p><strong>Der Servername wurde geändert.</strong> ' . $again . ' Punkt' . ($again === 1 ? '' : 'e') . ' unten stehen deshalb wieder rot und müssen erneut erledigt werden.</p></div>' : '')
            . '<p class="legend"><span class="lg done">✓ erledigt</span><span class="lg open">○ offen</span><span class="lg error">! Fehler oder erneut nötig</span><span class="lg info">i optional</span>'
            . ' · Orte: ' . implode(', ', array_map(fn($p) => '<strong>' . V::h($p[0]) . '</strong> (' . V::h($p[1]) . ')', Overview::PLACES)) . '</p>';
        foreach (Overview::GROUPS as $gid => $glabel) {
            $part = array_values(array_filter($core, fn($i) => ($i['gruppe'] ?? 'betrieb') === $gid));
            if (!$part) continue;
            $need = array_filter($part, fn($i) => Overview::needed($i));
            $bad = array_filter($need, fn($i) => $i['state'] === 'error');
            $tag = $bad ? '<span class="tag err">' . count($bad) . ' rot</span>' : ($need ? '<span class="tag warn">' . count($need) . ' offen</span>' : '<span class="tag ok">fertig</span>');
            $body .= '<section class="card group"><h2>' . V::h($glabel) . ' ' . $tag . '</h2>' . $this->overviewList($part, true) . '</section>';
        }
        foreach ($modules as $m) {
            $need = array_filter($m['items'], fn($i) => Overview::needed($i));
            $err = array_filter($need, fn($i) => $i['state'] === 'error');
            $url = $this->modules->settingsUrl($m['id']);
            $tag = $m['fehler'] !== '' ? '<span class="tag warn">nicht lesbar</span>' : ($need ? '<span class="tag warn">' . count($need) . ' offen' . ($err ? ', davon ' . count($err) . ' mit Fehler' : '') . '</span>' : '<span class="tag">fertig</span>');
            $inner = '<h2>' . V::h($m['name']) . ' ' . $tag . '</h2><p class="muted">' . ($m['fehler'] !== '' ? 'Überblick nicht lesbar: ' . V::h($m['fehler']) . '. ' : '')
                . ($need ? 'Die Einrichtung geschieht in den Einstellungen des Moduls: ' . V::h(implode(', ', array_map(fn($i) => $i['title'], array_slice(array_values($need), 0, 3)))) . (count($need) > 3 ? ' …' : '') . '.' : 'Alle nötigen Schritte sind erledigt.')
                . ($m['zeit'] ? ' Stand ' . V::date($m['zeit']) . '.' : '') . '</p>';
            $body .= $url !== null ? '<a class="card setup-card' . ($need ? ' needs' : '') . '" href="' . V::h($url) . '">' . $inner . '</a>' : '<section class="card">' . $inner . '</section>';
        }
        if ($modules) $body .= '<form method="post" action="/verwaltung/einrichtung">' . $this->hidden('neu') . '<button class="secondary">Module neu prüfen</button></form>';
        return Response::html(V::page('Einrichtung', $body, $this->nav(), $this->config->practice));
    }

    private function overviewAction(Request $r, string $admin): Response {
        $a = $r->field('aktion', 20);
        if ($a === 'bestaetigen') {
            $this->ov()->confirm($r->field('punkt', 20), $r->field('erledigt') === '1');
            $this->audit->write($admin, 'einrichtung-' . ($r->field('erledigt') === '1' ? 'erledigt' : 'zurueck'), $r->field('punkt', 20));
        } elseif ($a === 'ignorieren') {
            $this->ov()->ignore($r->field('punkt', 20), $r->field('an') === '1');
            $this->audit->write($admin, 'einrichtung-' . ($r->field('an') === '1' ? 'ignoriert' : 'beachtet'), $r->field('punkt', 20));
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
            V::$status = ['Schutz vor Erpressungstrojanern' => [!in_array('Schreibgeschützte Schnappschüsse am NAS sind nicht als eingerichtet bestätigt (Schutz vor Erpressungstrojanern).', $st['warnungen'], true), 'bestätigt', 'nicht bestätigt'],
            'Schlüsselblatt' => [!$st['blatt'], 'gedruckt', 'noch nicht gedruckt'], 'Benachrichtigung bei Fehlern' => [!empty($st['mail_an']), 'eingerichtet', 'nicht eingerichtet']];
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
    /** Seiten mit noch offenen Schritten; für Modul-Einstellungen zählt der Pfadanfang (z. B. /kasse). */
    private function openPaths(): array {
        try {
            $paths = $this->ov()->openPaths();
            foreach ($paths as $p) if (preg_match('#^(/[a-z0-9-]+)/#', $p, $m) && !str_starts_with($p, '/verwaltung') && !str_starts_with($p, '/hilfe')) {
                foreach ($this->modules->all() as $id => $_) if (($u = $this->modules->settingsUrl($id)) !== null && str_starts_with($u, $m[1])) $paths[] = $u;
            }
            return array_values(array_unique($paths));
        } catch (\Throwable) {
            return [];
        }
    }

    private function openSteps(): int {
        try {
            return $this->ov()->open();
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
        $apps = $settings = $meta = [];
        foreach ($this->users->access((int)$this->session['user_id']) as $id => $a) {
            if (($p = $this->modules->webPath($id)) === null) continue;
            $apps[$p . '/'] = $this->modules->name($id);
            $s = $a['admin'] ? $this->modules->settingsUrl($id) : null;
            if ($s !== null) $settings[$s] = $this->modules->name($id);
            $meta[$p . '/'] = ['id' => $id, 'settings' => $s];
        }
        $offen = $admin ? $this->openPaths() : [];
        foreach ($meta as $k => $m) $meta[$k]['todo'] = $m['settings'] !== null && in_array($m['settings'], $offen, true);
        return ['apps' => $apps, 'appmeta' => $meta, 'settings' => $settings, 'login' => $this->session['login'], 'admin' => $admin, 'csrf' => $this->session['csrf'],
            'updates' => $admin && $this->updates ? $this->updates->count() : 0, 'module' => $admin && $this->store ? $this->store->count() : 0, 'sicherung' => $admin && $this->backup ? $this->backup->count() : 0,
            'pfad' => $this->req?->path ?? '', 'einrichtung' => $admin ? $this->openSteps() : 0, 'offenLogo' => $admin && $this->openSteps() > 0, 'offen' => $admin ? $this->openPaths() : []];
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
