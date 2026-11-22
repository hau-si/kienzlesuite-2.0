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
    /** $server: Vorgabe $_SERVER; die Ordner nur für Tests ändern. */
    public function __construct(public readonly string $id, private array $server = [],
        private string $verbindungen = '/etc/kienzle/verbindungen/module', private string $schluessel = '/etc/kienzle/schluessel') {
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
}
