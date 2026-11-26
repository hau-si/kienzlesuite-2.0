<?php
declare(strict_types=1);
/**
 * Hilfen für PHP-Module der Kienzle-Suite (docs/MODUL-SCHNITTSTELLE.md). Ein Modul bindet diese Datei ein:
 *   require '/opt/kienzle/core/current/php/modul/Kienzle.php';
 *   $k = new KienzleModul('kasse');
 *   $k->recht('paid') or $k->verweigern();
 * Keine Abhängigkeit von den übrigen Klassen der Basis.
 */
final class KienzleModul {
    /** @var callable|null */
    private $lp;

    /** $server: Vorgabe $_SERVER; die Ordner und $lp nur für Tests ändern. */
    public function __construct(public readonly string $id, private array $server = [],
        private string $verbindungen = '/etc/kienzle/verbindungen/module', private string $schluessel = '/etc/kienzle/schluessel',
        private string $drucker = '/var/lib/kienzle/drucker/status.json', ?callable $lp = null) {
        $this->lp = $lp;
        if (!preg_match('/^[a-z][a-z0-9-]{1,28}$/D', $id)) throw new InvalidArgumentException('Modul-ID ungültig.');
        $this->server = $server ?: $_SERVER;
    }

    /** Angemeldeter Benutzer (von der Basis über nginx weitergereicht; nie aus Kopfzeilen des Browsers). */
    public function benutzer(): string { return (string)($this->server['KIENZLE_BENUTZER'] ?? ''); }
    public function name(): string { return rawurldecode((string)($this->server['KIENZLE_NAME'] ?? '')); }
    public function istAdmin(): bool { return ($this->server['KIENZLE_ROLLE'] ?? '') === 'admin'; }
    /** @return list<string> */
    public function rechte(): array { return array_values(array_filter(explode(',', (string)($this->server['KIENZLE_RECHTE'] ?? '')))); }
    public function recht(string $recht): bool { return $this->istAdmin() || in_array($recht, $this->rechte(), true); }
    /** Menü der Suite für die Seitenleiste: apps (Pfad => Name), ids (Pfad => Modul-ID), admin (Basis-Admin), login. Leer, wenn die Basis nichts mitgeliefert hat. */
    public function menue(): array {
        $d = json_decode(rawurldecode((string)($this->server['KIENZLE_MENUE'] ?? '')), true);
        $apps = [];
        foreach (is_array($d['apps'] ?? null) ? $d['apps'] : [] as $href => $name) {
            if (is_string($href) && is_string($name) && preg_match('#^/[a-z0-9-]{1,31}/$#D', $href)) $apps[$href] = mb_substr($name, 0, 60);
        }
        $ids = [];
        foreach (is_array($d['ids'] ?? null) ? $d['ids'] : [] as $href => $id) if (isset($apps[$href]) && is_string($id)) $ids[$href] = $id;
        $z = [];
        foreach (['updates', 'module', 'sicherung', 'einrichtung'] as $n) $z[$n] = is_array($d) && is_array($d['zaehler'] ?? null) && is_int($d['zaehler'][$n] ?? null) ? max(0, min(99, $d['zaehler'][$n])) : 0;
        $offen = [];
        foreach (is_array($d['offen'] ?? null) ? $d['offen'] : [] as $p) if (is_string($p) && preg_match('#^/[A-Za-z0-9/_?=&.-]{1,120}$#D', $p)) $offen[] = $p;
        $todo = [];
        foreach (is_array($d['todo'] ?? null) ? $d['todo'] : [] as $p) if (is_string($p) && isset($apps[$p])) $todo[] = $p;
        return ['apps' => $apps, 'ids' => $ids, 'todo' => $todo, 'zaehler' => $z, 'offen' => $offen, 'admin' => is_array($d) && ($d['admin'] ?? false) === true, 'login' => is_array($d) && is_string($d['login'] ?? null) ? $d['login'] : $this->benutzer()];
    }

    /**
     * Seite im Rahmen der Suite (Seitenleiste, Kopf, Praxisname) mit dem Inhalt des Moduls. $extra: 'kopf' (Stylesheets/Skripte des Moduls),
     * 'roh' (Inhalt unverändert statt Überschrift und Karten). Ohne die Basis-Klasse gibt es nur den Inhalt zurück.
     */
    public function rahmen(string $titel, string $inhalt, array $extra = []): string {
        $file = dirname(__DIR__) . '/View.php';
        if (!is_file($file)) return $inhalt;
        require_once $file;
        $m = $this->menue();
        $nav = ['apps' => $m['apps'], 'appmeta' => array_map(fn($id) => ['id' => $id, 'todo' => false], $m['ids']), 'settings' => [], 'login' => $m['login'], 'admin' => $m['admin'],
            'csrf' => '', 'updates' => $m['zaehler']['updates'], 'module' => $m['zaehler']['module'], 'sicherung' => $m['zaehler']['sicherung'], 'einrichtung' => $m['zaehler']['einrichtung'],
            'offenLogo' => $m['admin'] && $m['zaehler']['einrichtung'] > 0, 'offen' => $m['offen'],
            'pfad' => (string)parse_url((string)($this->server['REQUEST_URI'] ?? '/'), PHP_URL_PATH)];
        foreach ($nav['appmeta'] as $href => $meta) $nav['appmeta'][$href]['todo'] = in_array($href, $m['todo'], true);
        return \Kienzle\Core\View::page($titel, $inhalt, $nav, $this->praxis()['name'], null, $extra);
    }

    /** Praxisdaten der Basis (Verwaltung → Praxis): name, street, city, contact; leer, wenn keine mitgeliefert wurden. */
    public function praxis(): array {
        $d = json_decode(rawurldecode((string)($this->server['KIENZLE_PRAXIS'] ?? '')), true);
        $out = [];
        foreach (['name', 'street', 'city', 'contact'] as $k) $out[$k] = is_array($d) && is_string($d[$k] ?? null) ? $d[$k] : '';
        return $out;
    }

    /** Kontodaten und Steuerangaben der Basis (Verwaltung → Praxis): holder, iban, bic, bank_name, tax_id, small_business. */
    public function stammdaten(): array {
        $d = json_decode(rawurldecode((string)($this->server['KIENZLE_PRAXIS'] ?? '')), true);
        $out = [];
        foreach (['holder', 'iban', 'bic', 'bank_name', 'tax_id'] as $k) $out[$k] = is_array($d) && is_string($d[$k] ?? null) ? $d[$k] : '';
        $out['small_business'] = is_array($d) && ($d['small_business'] ?? false) === true;
        return $out;
    }

    /** Zuordnung der Drucker (Verwaltung → Drucker): buero = Praxisdrucker (A4), bon = Bondrucker; leer = nicht zugeordnet. */
    public function druckerRollen(): array {
        $d = json_decode(rawurldecode((string)($this->server['KIENZLE_DRUCKER'] ?? '')), true);
        $out = [];
        foreach (['buero', 'bon'] as $k) $out[$k] = is_array($d) && is_string($d[$k] ?? null) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $d[$k]) ? $d[$k] : '';
        return $out;
    }

    /** Papierbreite des Bondruckers in Millimetern (58 oder 80), zentral unter Verwaltung → Drucker eingestellt. */
    public function bonBreite(): int {
        $d = json_decode(rawurldecode((string)($this->server['KIENZLE_DRUCKER'] ?? '')), true);
        return is_array($d) && ($d['bonbreite'] ?? null) === 80 ? 80 : 58;
    }

    public function csrf(): string { return (string)($this->server['KIENZLE_CSRF'] ?? ''); }

    /** CSRF-Marke einer schreibenden Anfrage prüfen (Formularfeld „csrf“ oder Kopfzeile X-Kienzle-Csrf des Browsers). */
    public function csrfGueltig(?string $gesendet): bool { return $this->csrf() !== '' && is_string($gesendet) && hash_equals($this->csrf(), $gesendet); }

    /** Heikle Aktion: Bestätigung (Passwort+OTP oder Passkey) höchstens 24 Stunden alt? Sonst auf /bestaetigen schicken. */
    public function frischBestaetigt(int $sekunden = 86400): bool { return (int)($this->server['KIENZLE_BESTAETIGT'] ?? 0) > time() - $sekunden; }
    public function bestaetigenAdresse(string $zurueck): string { return '/bestaetigen?weiter=' . rawurlencode($zurueck); }

    public function verweigern(): never {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Dafür fehlt das Recht. Bitte den Admin fragen.\n");
    }

    /** Zentrale Verbindungen, die das Modul in [[connections]] angemeldet hat (t2med, mail, ablage), sonst null. */
    public function verbindung(string $art): ?array {
        $data = json_decode((string)@file_get_contents($this->verbindungen . '/' . $this->id . '.json'), true);
        return is_array($data[$art] ?? null) ? $data[$art] : null;
    }

    /** Eigenes Geheimnis verschlüsseln (z. B. Letterxpress- oder SumUp-Schlüssel). Schlüssel nur für dieses Modul lesbar. */
    public function verschluesseln(string $klartext, string $zweck): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . sodium_bin2base64($nonce . sodium_crypto_secretbox($klartext, $nonce, $this->key($zweck)), SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    public function entschluesseln(string $wert, string $zweck): string {
        if (!str_starts_with($wert, 'v1:')) throw new RuntimeException('Unbekanntes Format.');
        $raw = sodium_base642bin(substr($wert, 3), SODIUM_BASE64_VARIANT_ORIGINAL);
        $plain = strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->key($zweck)) : false;
        if ($plain === false) throw new RuntimeException('Geheimnis beschädigt oder gehört zu einem anderen Schlüssel.');
        return $plain;
    }

    /**
     * Datei auf dem Netzwerkspeicher ablegen lassen (Verbindung „ablage“). Die Basis überträgt sie, ohne dass das Modul
     * die Zugangsdaten kennt. $unterordner z. B. „Rechnungen/{jahr}/{monat}“. Liefert die Auftragskennung.
     */
    public function ablegen(string $ziel, string $daten, string $name, string $unterordner = '', ?DateTimeInterface $datum = null): string {
        $a = $this->verbindung('ablage');
        if (!$a) throw new RuntimeException('Verbindung „ablage“ ist für dieses Modul nicht eingerichtet.');
        if (!in_array($ziel, array_column($a['ziele'], 'id'), true)) throw new RuntimeException('Unbekanntes Ziel.');
        $datum ??= new DateTimeImmutable();
        $job = date('Ymd-His') . '-' . bin2hex(random_bytes(6));
        $dir = (string)$a['ausgang'];
        if (file_put_contents("$dir/$job.daten", $daten) !== strlen($daten)) throw new RuntimeException('Ablage konnte nicht vorbereitet werden.');
        chmod("$dir/$job.daten", 0640);
        $meta = json_encode(['ziel' => $ziel, 'ordner' => $unterordner, 'name' => $name, 'jahr' => $datum->format('Y'), 'monat' => $datum->format('m')], JSON_UNESCAPED_UNICODE);
        file_put_contents("$dir/.$job.json", $meta);
        chmod("$dir/.$job.json", 0640);
        rename("$dir/.$job.json", "$dir/$job.json");   // erst vollständig sichtbar machen
        return $job;
    }

    /**
     * Lauschordner auf dem Netzwerkspeicher (Verbindung „ablage“) ein- oder ausschalten: ks-ablage holt neue Dateien mit den
     * Endungen aus dem Ordner (relativ zum Ziel) nach lauschenEingang() und löscht sie erst danach auf dem NAS.
     * Ziel null oder leer schaltet aus.
     */
    public function lauschen(?string $ziel, string $ordner = '', array $endungen = ['pdf']): void {
        $a = $this->verbindung('ablage');
        if (!$a) throw new RuntimeException('Verbindung „ablage“ ist für dieses Modul nicht eingerichtet.');
        $datei = (string)$a['ausgang'] . '/lauschen.anfrage';
        if ($ziel === null || $ziel === '' || trim($ordner, '/') === '') { @unlink($datei); return; }
        if (!in_array($ziel, array_column($a['ziele'], 'id'), true)) throw new RuntimeException('Unbekanntes Ziel.');
        $json = json_encode(['ziel' => $ziel, 'ordner' => trim($ordner, '/'), 'endungen' => array_values($endungen)], JSON_UNESCAPED_UNICODE);
        $tmp = $datei . '.neu';
        if (file_put_contents($tmp, $json) !== strlen($json)) throw new RuntimeException('Lauschordner konnte nicht eingerichtet werden.');
        chmod($tmp, 0660);
        rename($tmp, $datei);
    }

    /** Ordner, in den ks-ablage Dateien aus dem Lauschordner legt (leer, wenn keine Verbindung „ablage“). */
    public function lauschenEingang(): string {
        $a = $this->verbindung('ablage');
        return $a && isset($a['eingang']) ? (string)$a['eingang'] : '';
    }

    private function key(string $zweck): string {
        $hex = trim((string)@file_get_contents($this->schluessel . '/' . $this->id . '.key'));
        if (strlen($hex) !== 64) throw new RuntimeException('Schlüssel des Moduls fehlt.');
        return sodium_crypto_generichash('modul:' . $this->id . ':' . $zweck, sodium_hex2bin($hex), SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    // ---- Drucken (Druckerverwaltung der Basis, Verwaltung → Drucker) ------------------------------------------------

    /** Warteschlangen der Suite zur Auswahl im Modul: Liste aus name, description, state. */
    public function drucker(): array {
        $s = json_decode((string)@file_get_contents($this->drucker), true);
        $out = [];
        foreach ((array)($s['warteschlangen'] ?? []) as $q) {
            if (!is_array($q) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', (string)($q['name'] ?? ''))) continue;
            $out[] = ['name' => (string)$q['name'], 'description' => mb_substr((string)($q['description'] ?? ''), 0, 130), 'state' => (string)($q['state'] ?? '')];
        }
        return $out;
    }

    /**
     * Auftrag mit lp als Modul-Benutzer abgeben; liefert die Auftragsnummer. Der Titel darf keine Patientendaten enthalten.
     * $optionen: CUPS-Optionen „name=wert“ (z. B. media=A4, sides=two-sided-long-edge, raw).
     */
    public function drucken(string $drucker, string $daten, string $titel, array $optionen = []): string {
        if (!in_array($drucker, array_column($this->drucker(), 'name'), true)) throw new RuntimeException('Drucker „' . $drucker . '“ ist in der Suite nicht eingerichtet.');
        $args = ['/usr/bin/lp', '-d', $drucker, '-t', mb_substr((string)preg_replace('/[^A-Za-z0-9 ._\/-]/', '', $titel), 0, 60) ?: 'Kienzle-Suite'];
        foreach ($optionen as $o) {
            if (!is_string($o) || !preg_match('/^[a-z][a-z0-9-]{0,40}(=[A-Za-z0-9_.,-]{1,60})?$/D', $o)) throw new RuntimeException('Druckoption ungültig.');
            array_push($args, '-o', $o);
        }
        $r = $this->lp ? ($this->lp)($args, $daten) : self::run($args, $daten);
        if (($r['code'] ?? 1) !== 0 || !preg_match('/request id is (\S+)/', (string)($r['output'] ?? ''), $m)) {
            $reason = trim((string)preg_replace('/\s+/', ' ', mb_substr((string)(($r['stderr'] ?? '') ?: ($r['output'] ?? '')), 0, 200)));
            throw new RuntimeException('Drucker „' . $drucker . '“: ' . ($reason !== '' ? $reason : 'Fehler ' . (int)($r['code'] ?? 1)) . '.');
        }
        return $m[1];
    }

    private static function run(array $args, string $input): array {
        $p = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) return ['code' => 127, 'output' => '', 'stderr' => 'lp nicht startbar'];
        fwrite($pipes[0], $input); fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]); $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return ['code' => proc_close($p), 'output' => $out, 'stderr' => $err];
    }
}
