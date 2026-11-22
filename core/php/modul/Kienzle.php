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
    /** Praxisdaten der Basis (Verwaltung → Server und Name): name, street, city, contact; leer, wenn keine mitgeliefert wurden. */
    public function praxis(): array {
        $d = json_decode(rawurldecode((string)($this->server['KIENZLE_PRAXIS'] ?? '')), true);
        $out = [];
        foreach (['name', 'street', 'city', 'contact'] as $k) $out[$k] = is_array($d) && is_string($d[$k] ?? null) ? $d[$k] : '';
        return $out;
    }

    /** Zuordnung der Drucker (Verwaltung → Drucker): buero = Praxisdrucker (A4), bon = Bondrucker; leer = nicht zugeordnet. */
    public function druckerRollen(): array {
        $d = json_decode(rawurldecode((string)($this->server['KIENZLE_DRUCKER'] ?? '')), true);
        $out = [];
        foreach (['buero', 'bon'] as $k) $out[$k] = is_array($d) && is_string($d[$k] ?? null) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $d[$k]) ? $d[$k] : '';
        return $out;
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
