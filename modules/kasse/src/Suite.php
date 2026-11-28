<?php
declare(strict_types=1);
namespace KienzleKasse;

/**
 * Anbindung an Praxisherz 2.0 (docs/MODUL-SCHNITTSTELLE.md der Basis):
 * - Anmeldung, Rolle und Rechte kommen von der Basis (nginx → KIENZLE_* in $_SERVER), keine eigene Anmeldung.
 * - T2med (FHIR), E-Mail und Netzwerkspeicher sind zentrale Verbindungen der Basis; das Modul liest nur seine eigene Datei.
 * - Grundwerte (Schlüssel der Einstellungen und des Rechnungseingangs) liegen im eigenen Datenordner (grundwerte.json, 0600),
 *   angelegt vom Haken bin/install.
 * Daraus entsteht die Config der Kasse; die Einstellungen der
 * Weboberfläche (Settings) liegen weiter verschlüsselt in der Datenbank darüber.
 */
final class Suite {
    public const ID = 'kasse';
    public const PATH = '/kasse';
    public static string $helper = '/opt/kienzle/core/current/php/modul/Kienzle.php';
    public static string $data = '/var/lib/kienzle/kasse';
    /** Rechte aus module.toml ([[rights]]); Admins des Moduls haben alle. */
    public const RIGHTS = ['kassieren', 'paid', 'inbox', 'letter', 'catalog', 'export', 'periods', 'storno'];
    /** Rechte, die einen Zugang zur Einstellungsseite geben (die übrigen betreffen nur Kasse und Vorgänge). */
    public const SETTINGS_RIGHTS = ['inbox', 'letter', 'catalog'];
    private static ?\KienzleModul $modul = null;

    public static function modul(): \KienzleModul {
        if (self::$modul === null) {
            if (!class_exists('KienzleModul', false)) {
                if (!is_file(self::$helper)) throw new \RuntimeException('Praxisherz nicht gefunden (' . self::$helper . ').');
                require_once self::$helper;
            }
            self::$modul = new \KienzleModul(self::ID);
        }
        return self::$modul;
    }

    /** Nur für Tests. */
    public static function use(?\KienzleModul $modul, ?string $data = null): void {
        self::$modul = $modul;
        if ($data !== null) self::$data = $data;
    }

    /** Webpfad des Moduls ohne abschließenden Schrägstrich, z. B. „/kasse“. */
    public static function base(): string {
        $p = (string)($_SERVER['KIENZLE_PFAD'] ?? self::PATH);
        return preg_match('#^/[a-z0-9-]{1,31}$#D', $p) ? $p : self::PATH;
    }

    /** Servername der Anfrage (nginx lässt nur die Namen von Praxisherz durch); ohne Anfrage „localhost“. */
    public static function host(): string {
        $h = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $h = (string)preg_replace('/:443$/', '', $h);
        if (preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D', $h) || preg_match('/^\[[0-9a-f:]{2,45}\]$/D', $h)) return $h;
        return 'localhost';
    }

    /** Darf der angemeldete Benutzer das? Ohne Anmeldung (Starter, Drucker, Haken) nie. */
    public static function can(string $right): bool {
        $k = self::modul();
        if ($k->benutzer() === '') return false;
        return $k->recht($right);
    }

    public static function isAdmin(): bool { $k = self::modul(); return $k->benutzer() !== '' && $k->istAdmin(); }

    /** @return list<string> */
    public static function rights(): array {
        if (self::isAdmin()) return self::RIGHTS;
        return array_values(array_intersect(self::RIGHTS, self::modul()->rechte()));
    }

    /** Rechte dieses Benutzers für die Einstellungsseite. */
    public static function settingsRights(): array { return array_values(array_intersect(self::rights(), self::SETTINGS_RIGHTS)); }

    /** Grundwerte aus dem Datenordner (angelegt von bin/install). */
    public static function grundwerte(): array {
        $g = json_decode((string)@file_get_contents(self::$data . '/grundwerte.json'), true);
        if (!is_array($g)) throw new \RuntimeException('Grundwerte des Moduls fehlen. Bitte das Modul unter Betrieb → Module neu installieren.');
        return $g;
    }

    /** Grundwerte anlegen, falls sie fehlen (Haken install/update). */
    public static function ensureGrundwerte(): void {
        $file = self::$data . '/grundwerte.json';
        if (is_file($file)) return;
        $g = ['encryption_key' => bin2hex(random_bytes(32)), 'invoice_key' => bin2hex(random_bytes(32))];
        $tmp = $file . '.neu';
        if (file_put_contents($tmp, json_encode($g, JSON_PRETTY_PRINT) . "\n") === false) throw new \RuntimeException('Grundwerte nicht schreibbar.');
        chmod($tmp, 0600);
        rename($tmp, $file);
    }

    /**
     * Praxisname, Anschrift und die Zuordnung der Drucker gehören der Basis (Praxis → Praxis bzw. Drucker).
     * Bei jeder angemeldeten Anfrage wird die mitgelieferte Fassung als basis.json übernommen, damit auch der Starter
     * (ohne Anmeldung) sie nutzen kann.
     */
    public static function syncBasis(): void {
        try { $k = self::modul(); $b = ['praxis' => $k->praxis(), 'drucker' => $k->druckerRollen(), 'bonbreite' => $k->bonBreite()]; } catch (\Throwable) { return; }
        if (implode('', $b['praxis']) === '' && !array_filter($b['drucker'])) return;
        $file = self::$data . '/basis.json';
        $json = json_encode($b, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        if (@file_get_contents($file) === $json) return;
        $tmp = $file . '.neu';
        if (@file_put_contents($tmp, $json) === false) return;
        @chmod($tmp, 0600);
        @rename($tmp, $file);
    }

    private static function basis(): array {
        $d = json_decode((string)@file_get_contents(self::$data . '/basis.json'), true);
        return is_array($d) ? $d : [];
    }

    /** @return array{name:string,street:string,city:string,contact:string} */
    public static function praxis(): array {
        $d = self::basis()['praxis'] ?? [];
        $out = [];
        foreach (['name', 'street', 'city', 'contact'] as $k) $out[$k] = is_array($d) && is_string($d[$k] ?? null) ? $d[$k] : '';
        return $out;
    }

    /** Papierbreite des Bondruckers in mm (58 oder 80), zentral unter Praxis → Drucker. */
    public static function bonBreite(): int { return (self::basis()['bonbreite'] ?? null) === 80 ? 80 : 58; }

    /** @return array{buero:string,bon:string} Warteschlangen laut Praxis → Drucker (leer = nicht zugeordnet). */
    public static function druckerRollen(): array {
        $d = self::basis()['drucker'] ?? [];
        $out = [];
        foreach (['buero', 'bon'] as $k) $out[$k] = is_array($d) && is_string($d[$k] ?? null) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/D', $d[$k]) ? $d[$k] : '';
        return $out;
    }

    /** Konfiguration wie früher aus der TOML-Datei, jetzt aus Vorgaben, Grundwerten und den Verbindungen der Basis. */
    public static function baseConfig(): Config {
        $v = Config::parse((string)file_get_contents(dirname(__DIR__) . '/config/kienzle-kasse.example.toml'));
        $g = self::grundwerte();
        $v['app']['base_url'] = 'https://' . self::host();
        $v['app']['port'] = 443;
        $v['app']['state_dir'] = self::$data;
        $v['app']['development'] = false;
        $v['app']['encryption_key'] = (string)$g['encryption_key'];
        $v['invoice_inbox']['key'] = (string)$g['invoice_key'];
        // Bondrucker-Breite kommt aus der Basis, nicht mehr aus den Einstellungen der Kasse.
        $v['printing']['model'] = self::bonBreite() === 80 ? 'tm-m30iii' : 'tm-m10';
        $v['printing']['width_dots'] = Config::PRINTER_MODELS[$v['printing']['model']]['width_dots'];
        // Drucker richtet die Basis ein (Praxis → Drucker); das Modul druckt nur auf deren Warteschlangen.
        foreach (self::praxis() as $k => $val) $v['practice'][$k] = $val;
        // Kontodaten und Steuerangaben gehören ebenfalls der Basis (Praxis → Praxis).
        try {
            $st = self::modul()->stammdaten();
            foreach (['holder', 'iban', 'bic', 'bank_name'] as $f) $v['bank'][$f] = $st[$f];
            $v['tax']['tax_id'] = $st['tax_id']; $v['tax']['small_business'] = $st['small_business'];
        } catch (\Throwable) { /* ohne Basis-Angaben bleiben die Vorgaben */ }
        $rollen = self::druckerRollen();
        if ($rollen['bon'] !== '') $v['printing']['queue'] = $rollen['bon'];
        if ($rollen['buero'] !== '') $v['office_printing']['queue'] = $rollen['buero'];
        $v['printing']['transport'] = 'cups';
        $v['printing']['cups_server'] = '';
        $v['office_printing']['cups_server'] = '';
        $k = self::modul();
        $t = $k->verbindung('t2med');
        $v['fhir']['base_url'] = $t ? rtrim((string)($t['base_url'] ?? ''), '/') : '';
        $v['fhir']['launch_urls'] = $t ? array_values(array_map(static fn($u) => rtrim((string)$u, '/'), (array)($t['launch_urls'] ?? []))) : [];
        if ($t && !$v['fhir']['launch_urls'] && $v['fhir']['base_url'] !== '') $v['fhir']['launch_urls'] = [$v['fhir']['base_url']];
        $v['fhir']['api_key'] = $t ? (string)($t['api_key'] ?? '') : '';
        $v['fhir']['ca_file'] = $t ? self::caFile((string)($t['ca_pem'] ?? '')) : '';
        $v['fhir']['pin_certificate'] = $t && !empty($t['pin']) && $v['fhir']['ca_file'] !== '';
        $m = $k->verbindung('mail');
        $v['mail'] = $m && ($m['art'] ?? '') === 'gmail' ? ['enabled' => false, 'art' => 'gmail', 'client_id' => (string)($m['client_id'] ?? ''), 'client_secret' => (string)($m['client_secret'] ?? ''),
            'refresh_token' => (string)($m['refresh_token'] ?? ''), 'from_address' => (string)($m['absender'] ?? ''), 'from_name' => ''] : ($m ? ['enabled' => false, 'host' => (string)($m['host'] ?? ''), 'port' => (int)($m['port'] ?? 587),
            'encryption' => ($m['sicherheit'] ?? '') === 'smtps' ? 'smtps' : 'starttls', 'username' => (string)($m['benutzer'] ?? ''),
            'password' => (string)($m['passwort'] ?? ''), 'from_address' => (string)($m['absender'] ?? ''), 'from_name' => ''] : ['enabled' => false]);
        $l = $k->verbindung('letterxpress');
        $v['letterxpress']['username'] = $l ? (string)($l['username'] ?? '') : '';
        $v['letterxpress']['api_key'] = $l ? (string)($l['api_key'] ?? '') : '';
        $v['letterxpress']['mode'] = $l && ($l['modus'] ?? '') === 'live' ? 'live' : 'test';
        $a = $k->verbindung('ablage');
        $v['invoice_export']['ziel'] = $a ? (string)(($a['ziele'][0]['id'] ?? '')) : '';
        return new Config($v);
    }

    /** Ziele des Netzwerkspeichers (id → Name) aus der Verbindung „ablage“. */
    public static function targets(): array {
        try { $a = self::modul()->verbindung('ablage'); } catch (\RuntimeException) { return []; }
        $out = [];
        foreach ((array)($a['ziele'] ?? []) as $t) if (is_array($t) && preg_match('/^[a-z][a-z0-9-]{1,30}$/D', (string)($t['id'] ?? ''))) $out[(string)$t['id']] = (string)($t['name'] ?? $t['id']);
        return $out;
    }

    public static function connected(string $art): bool {
        try { return self::modul()->verbindung($art) !== null; } catch (\RuntimeException) { return false; }
    }

    /** Zertifikat der T2med-Verbindung als Datei für den FHIR-Client (nur wenn gesetzt). */
    private static function caFile(string $pem): string {
        $pem = trim($pem);
        if ($pem === '') return '';
        $path = self::$data . '/t2med-ca.pem';
        if (@file_get_contents($path) !== $pem . "\n") {
            if (@file_put_contents($path . '.neu', $pem . "\n") === false) return '';
            chmod($path . '.neu', 0600);
            rename($path . '.neu', $path);
        }
        return $path;
    }
}
