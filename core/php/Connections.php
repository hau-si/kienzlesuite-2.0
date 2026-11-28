<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Zentrale Verbindungen im Webinterface (T2med, E-Mail, Netzwerkspeicher). Gespeichert, geprüft und an die Module
 * verteilt wird vom root-Dienst kienzle-verbindungen (core/lib/kienzle/verbindungen.py); das Webinterface hat kein Netz
 * und sieht nur eine Fassung ohne Geheimnisse. Ein leeres Passwortfeld heißt „unverändert“.
 */
final class Connections {
    public static string $state = '/var/lib/kienzle/verbindungen';
    public static string $unit = '/etc/systemd/system/kienzle-verbindungen.path';

    public function __construct(private Database $db) {}

    public function status(): array {
        $s = json_decode((string)@file_get_contents(self::$state . '/status.json'), true);
        $s = is_array($s) ? $s : [];
        return ['t2med' => is_array($s['t2med'] ?? null) ? $s['t2med'] : null, 'mail' => is_array($s['mail'] ?? null) ? $s['mail'] : null, 'sms' => is_array($s['sms'] ?? null) ? $s['sms'] : null,
            'letterxpress' => is_array($s['letterxpress'] ?? null) ? $s['letterxpress'] : null, 'uebersetzung' => is_array($s['uebersetzung'] ?? null) ? $s['uebersetzung'] : null,
            'ablage' => array_values(array_filter((array)($s['ablage'] ?? []), 'is_array')), 'tests' => (array)($s['tests'] ?? []), 'guthaben' => (array)($s['guthaben'] ?? []),
            'hostkeys' => (array)($s['hostkeys'] ?? []), 'zertifikate' => (array)($s['zertifikate'] ?? []), 'nutzung' => array_values(array_filter((array)($s['nutzung'] ?? []), 'is_array')),
            'hinweise' => array_values(array_filter((array)($s['hinweise'] ?? []), 'is_string')), 'letzte' => is_array($s['letzte'] ?? null) ? View::frisch($s['letzte']) : null,
            'sftp_schluessel' => (string)($s['sftp_schluessel'] ?? ''), 'pending' => is_file($this->request()), 'service' => is_file(self::$unit)];
    }

    public function saveT2med(array $in): void {
        $launch = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($in['launch_urls'] ?? ''))), fn($u) => $u !== ''));
        $d = ['base_url' => trim((string)($in['base_url'] ?? '')), 'launch_urls' => $launch, 'api_key' => (string)($in['api_key'] ?? ''), 'pin' => ($in['pin'] ?? false) === true];
        if (($in['api_key_entfernen'] ?? false) === true) $d['api_key_entfernen'] = true;
        if (trim((string)($in['ca_pem'] ?? '')) !== '' || ($in['ca_entfernen'] ?? false) === true) $d['ca_pem'] = trim((string)($in['ca_pem'] ?? ''));
        if (!preg_match('#^https://#', $d['base_url'])) throw new Problem('Adresse des FHIR-Servers: bitte mit https:// angeben.', 422);
        $this->write(['action' => 't2med', 'daten' => $d]);
    }

    public function saveLetterxpress(array $in): void {
        $this->write(['action' => 'letterxpress', 'daten' => ['username' => trim((string)($in['username'] ?? '')), 'api_key' => trim((string)($in['api_key'] ?? '')),
            'modus' => ($in['modus'] ?? '') === 'live' ? 'live' : 'test']]);
    }

    public function saveUebersetzung(array $in): void {
        $this->write(['action' => 'uebersetzung', 'daten' => ['api_key' => trim((string)($in['api_key'] ?? ''))]]);
    }

    private const GMAIL_KEY = 'gmail_offen';

    /** Schritt 1 der Gmail-Anmeldung: Client-ID und Absender merken, Zustandswert für die Rückleitung erzeugen. */
    public function gmailStart(string $clientId, string $sender): void {
        $clientId = trim($clientId); $sender = trim($sender);
        if (!preg_match('/^[A-Za-z0-9._-]{10,200}$/D', $clientId)) throw new Problem('Client-ID ungültig (sie endet meist auf .apps.googleusercontent.com).', 422);
        if (!filter_var($sender, FILTER_VALIDATE_EMAIL) || strlen($sender) > 254) throw new Problem('Absender: bitte die Gmail-Adresse eingeben, mit der du dich anmeldest.', 422);
        $this->db->store(self::GMAIL_KEY, json_encode(['client_id' => $clientId, 'absender' => $sender, 'state' => bin2hex(random_bytes(16)), 'bis' => time() + 1800], JSON_THROW_ON_ERROR));
    }

    /** Laufende Anmeldung (30 Minuten gültig) oder null. */
    public function gmailPending(): ?array {
        $d = json_decode((string)$this->db->setting(self::GMAIL_KEY), true);
        return is_array($d) && ($d['bis'] ?? 0) > time() && is_string($d['state'] ?? null) ? $d : null;
    }

    public function gmailUrl(array $pending): string {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $pending['client_id'], 'redirect_uri' => 'http://localhost', 'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/gmail.send', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $pending['state'], 'login_hint' => $pending['absender']], '', '&', PHP_QUERY_RFC3986);
    }

    /** Schritt 2: Adresse aus der Browserleiste (http://localhost/?code=…&state=…) prüfen und den Code an den root-Dienst geben. */
    public function saveGmail(string $secret, string $pasted): void {
        $p = $this->gmailPending();
        if (!$p) throw new Problem('Die Anmeldung ist abgelaufen oder nicht gestartet. Bitte bei Schritt 1 neu beginnen.', 409);
        $query = [];
        parse_str((string)parse_url(trim($pasted), PHP_URL_QUERY), $query);
        if (isset($query['error'])) throw new Problem('Google meldet: ' . preg_replace('/[^A-Za-z0-9_ .-]/', '', (string)$query['error']) . '. Bitte Schritt 1 wiederholen.', 422);
        $code = (string)($query['code'] ?? '');
        if ($code === '' || !hash_equals($p['state'], (string)($query['state'] ?? ''))) throw new Problem('In der Adresse fehlt der Code, oder sie gehört zu einer anderen Anmeldung. Bitte die ganze Adresse aus der Browserleiste einfügen (beginnt mit http://localhost/?state=…).', 422);
        $this->write(['action' => 'gmail', 'daten' => ['client_id' => $p['client_id'], 'client_secret' => trim($secret), 'absender' => $p['absender'], 'code' => $code]]);
        $this->db->store(self::GMAIL_KEY, '');
    }

    public function saveSms(array $in): void {
        $this->write(['action' => 'sms', 'daten' => ['api_key' => trim((string)($in['api_key'] ?? '')), 'absender' => trim((string)($in['absender'] ?? ''))]]);
    }

    /** Handynummer im internationalen Format (+491701234567); aus 0170… wird +49170…. */
    public static function phone(string $raw): string {
        $n = preg_replace('/[\s\/()-]+/', '', trim($raw)) ?? '';
        if (str_starts_with($n, '00')) $n = '+' . substr($n, 2);
        elseif (str_starts_with($n, '0')) $n = '+49' . substr($n, 1);
        if (!preg_match('/^\+[1-9][0-9]{7,14}$/D', $n)) throw new Problem('Handynummer bitte so eingeben: +491701234567 oder 01701234567.', 422);
        return $n;
    }

    public function testSms(string $to): void { $this->write(['action' => 'pruefen', 'art' => 'testsms', 'an' => self::phone($to)]); }

    /** Eine SMS über die zentrale Verbindung verschicken (der root-Dienst sendet; der Text darf einen Code enthalten, die Anfrage ist nur für ks-core und root lesbar). */
    public function sendSms(string $to, string $text): void { $this->write(['action' => 'sms-senden', 'an' => self::phone($to), 'text' => $text]); }

    /** Eine E-Mail über die zentrale Verbindung verschicken (Bestätigungs- und Zurücksetzen-Links; der root-Dienst sendet). */
    public function sendMail(string $to, string $subject, string $text): void {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || strlen($to) > 254) throw new Problem('Bitte eine gültige E-Mail-Adresse eingeben.', 422);
        $this->write(['action' => 'mail-senden', 'an' => $to, 'betreff' => $subject, 'text' => $text]);
    }

    public function saveMail(array $in): void {
        $port = (string)($in['port'] ?? '');
        if (!ctype_digit($port)) throw new Problem('Port: bitte eine Zahl angeben (meist 587 oder 465).', 422);
        $this->write(['action' => 'mail', 'daten' => ['host' => trim((string)($in['host'] ?? '')), 'port' => (int)$port, 'sicherheit' => (string)($in['sicherheit'] ?? ''),
            'benutzer' => trim((string)($in['benutzer'] ?? '')), 'passwort' => (string)($in['passwort'] ?? ''), 'absender' => trim((string)($in['absender'] ?? ''))]]);
    }

    public function saveTarget(array $in): void {
        $art = (string)($in['art'] ?? '');
        $d = ['id' => trim((string)($in['id'] ?? '')), 'name' => trim((string)($in['name'] ?? '')), 'art' => $art, 'host' => trim((string)($in['host'] ?? '')),
            'ordner' => trim((string)($in['ordner'] ?? '')), 'benutzer' => trim((string)($in['benutzer'] ?? ''))];
        if ($art === 'smb') $d += ['freigabe' => trim((string)($in['freigabe'] ?? '')), 'passwort' => (string)($in['passwort'] ?? ''),
            'domaene' => trim((string)($in['domaene'] ?? '')), 'gast' => ($in['gast'] ?? false) === true];
        elseif ($art === 'sftp') {
            $port = (string)($in['port'] ?? '22');
            if (!ctype_digit($port)) throw new Problem('Port: bitte eine Zahl angeben.', 422);
            $d['port'] = (int)$port;
        } else throw new Problem('Art: SMB oder SFTP.', 422);
        $this->write(['action' => 'ziel', 'daten' => $d]);
    }

    public function removeTarget(string $id): void { $this->write(['action' => 'ziel-loeschen', 'id' => $id]); }

    public function remove(string $what): void {
        if (!in_array($what, ['t2med', 'mail', 'sms', 'letterxpress', 'uebersetzung'], true)) throw new Problem('Unbekannte Verbindung.', 422);
        $this->write(['action' => $what, 'loeschen' => true]);
    }

    public function testMail(string $to): void {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || strlen($to) > 254) throw new Problem('Bitte eine gültige Empfängeradresse eingeben.', 422);
        $this->write(['action' => 'pruefen', 'art' => 'testmail', 'an' => $to]);
    }

    public function test(string $art, string $id = ''): void {
        if (!in_array($art, ['t2med', 'mail', 'sms', 'letterxpress', 'uebersetzung', 'guthaben', 'ziel'], true)) throw new Problem('Unbekannte Prüfung.', 422);
        $this->write(['action' => 'pruefen', 'art' => $art, 'id' => $id]);
    }

    public function fetchCertificate(): void { $this->write(['action' => 'zertifikat-holen']); }

    public function acceptCertificate(string $fingerprint): void { $this->write(['action' => 'zertifikat-uebernehmen', 'fingerprint' => $fingerprint]); }

    public function fetchHostkey(string $id): void { $this->write(['action' => 'hostkey-holen', 'id' => $id]); }

    public function acceptHostkey(string $id, string $fingerprint): void { $this->write(['action' => 'hostkey-uebernehmen', 'id' => $id, 'fingerprint' => $fingerprint]); }

    private function request(): string { return $this->db->directory . '/anfragen/verbindungen.json'; }

    private function write(array $request): void {
        if (!is_file(self::$unit)) throw new Problem('Der Dienst für Verbindungen ist nicht eingerichtet. Bitte den Installer ausführen.', 503);
        if (is_file($this->request())) throw new Problem('Eine Änderung wird gerade übernommen. Bitte kurz warten.', 409);
        $dir = dirname($this->request());
        if (!is_dir($dir) && !mkdir($dir, 0700) && !is_dir($dir)) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        $tmp = $dir . '/.verbindungen.' . bin2hex(random_bytes(4));
        $fh = fopen($tmp, 'x');
        if (!$fh) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
        chmod($tmp, 0600);   // enthält ggf. Passwörter: nur ks-core und root
        fwrite($fh, json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        fclose($fh);
        if (!rename($tmp, $this->request())) { @unlink($tmp); throw new Problem('Anfrage konnte nicht gespeichert werden.', 500); }
    }
}
