<?php
declare(strict_types=1);
namespace KienzleSumup;

/**
 * Einstellungen aus der Weboberfläche. Sie liegen verschlüsselt in der Datenbank und
 * überlagern die Grundwerte aus der Suite (Suite::baseConfig: Vorgaben, Schlüssel, zentrale Verbindungen).
 */
final class Settings {
    public const T2MED_DEMO_API_KEY = '7QwA7931lJSQfMKuTH4MQXLn4YEiNhE5tggnYKlY4HE';
    private const ROW = 'config_overrides';

    /** Felder je Abschnitt: Typ, Beschriftung, Auswahl, Hilfetext. */
    public const SCHEMA = [
        'sumup' => ['label' => 'Betrieb und SumUp', 'fields' => [
            'mode' => ['type' => 'choice', 'label' => 'Betrieb', 'choices' => ['mock' => 'Testmodus (keine echten Zahlungen)', 'live' => 'Live (echte Kartenzahlungen)']],
            'api_key' => ['type' => 'secret', 'label' => 'SumUp API-Key', 'help' => 'SumUp → Einstellungen → Für Entwickler → API Keys (geheimer Schlüssel)'],
            'affiliate_key' => ['type' => 'secret', 'label' => 'SumUp Affiliate-Key'],
            'app_id' => ['type' => 'string', 'label' => 'SumUp App-ID', 'default' => 'de.kienzle.sumup'],
            'merchant_code' => ['type' => 'string', 'label' => 'SumUp Händlercode', 'help' => 'z. B. MK01A8C2'],
            'reader_id' => ['type' => 'string', 'label' => 'SumUp Reader-ID', 'help' => 'Beginnt mit rdr_; Herkunft siehe Hinweis (?)'],
        ]],
        'app' => ['label' => 'Zahlungen', 'fields' => [
            'max_amount_cents' => ['type' => 'money', 'label' => 'Höchstbetrag pro Zahlung (Euro)'],
        ]],
        'fhir' => ['label' => 'T2med-Anbindung', 'fields' => [
            'mode' => ['type' => 'choice', 'label' => 'Anbindung', 'choices' => ['mock' => 'Testmodus (Testpatient, keine Akteneinträge)', 'live' => 'Live (echte Patientenakte)'],
                'help' => 'Adresse, Schlüssel und Zertifikat von T2med stehen zentral unter Verwaltung → Verbindungen der Suite.'],
            'entry_code' => ['type' => 'string', 'label' => 'Aktenkürzel', 'default' => 'ZAHLUNG'],
        ]],
        'practice' => ['label' => 'Belege (Praxisname und Anschrift: Verwaltung → Praxis)', 'fields' => [
            'append_sumup_receipt' => ['type' => 'bool', 'label' => 'Originalen SumUp-Beleg statt lokaler Zahlungsbestätigung anhängen'],
        ]],
        'numbers' => ['label' => 'Rechnungsnummern (in der App erzeugte Rechnungen und Stornos)', 'fields' => [
            'igel_format' => ['type' => 'string', 'label' => 'Format der Rechnungsnummer für IGeL-Leistungen', 'default' => 'IGEL-{JJJJ}-{NNNN}', 'help' => 'Platzhalter: {JJJJ} Jahr, {JJ} Jahr zweistellig, {MM} Monat, {NNNN} laufende Nummer (Anzahl der N = Mindeststellen). Beispiel: IGEL-{JJJJ}-{NNNN} ergibt IGEL-2026-0001. Enthält das Format das Jahr, beginnt die Zählung jedes Jahr neu. Vergebene Nummern werden nie wieder verwendet. Gilt für Karte, Bar und Überweisung; T2med-Rechnungen behalten ihre T2med-Nummer.'],
            'storno_format' => ['type' => 'string', 'label' => 'Format der Nummer einer Stornorechnung', 'default' => 'STORNO-{JJJJ}-{NNNN}', 'help' => 'Jede Stornierung bekommt eine eigene neue Nummer; die stornierte Rechnung bleibt für die Buchhaltung erhalten.'],
        ]],
        'bank' => ['label' => 'Überweisung (Kontodaten: Verwaltung → Praxis)', 'fields' => [
            'purpose' => ['type' => 'string', 'label' => 'Verwendungszweck', 'default' => 'Rechnung {nummer}', 'optional' => true],
        ]],
        'tax' => ['label' => 'Umsatzsteuer (Steuernummer und Kleinunternehmerregelung: Verwaltung → Praxis)', 'fields' => [
            'rate' => ['type' => 'percent', 'label' => 'MwSt.-Satz in Prozent', 'default' => 1900],
            'manual_vat' => ['type' => 'bool', 'label' => '„Sonstiger Betrag“ mit MwSt. berechnen', 'default' => false],
            'exempt_note' => ['type' => 'text', 'label' => 'Hinweis bei Positionen ohne MwSt. (optional)', 'default' => ''],
            'small_business_note' => ['type' => 'text', 'label' => 'Hinweis bei Kleinunternehmerregelung', 'default' => 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.'],
        ]],
        'mail' => ['label' => 'E-Mail-Versand', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Belege per E-Mail senden', 'help' => 'Server, Zugang und Absender stehen zentral unter Verwaltung → Verbindungen der Suite.'],
            'from_name' => ['type' => 'string', 'label' => 'Absendername (z. B. Praxisname)', 'optional' => true],
        ]],
        'printing' => ['label' => 'Bondrucker (welcher Drucker und welche Papierbreite: Verwaltung → Drucker → Wofür welcher Drucker?)', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Bondrucker verwenden („Beleg drucken“)'],
            'cut' => ['type' => 'bool', 'label' => 'Bon schneiden', 'default' => true],
            'cash_receipt_auto' => ['type' => 'bool', 'label' => 'Quittung automatisch drucken bei Barzahlung', 'default' => false],
        ]],
        'office_printing' => ['label' => 'Praxisdrucker A4 (welcher Drucker: Verwaltung → Drucker → Wofür welcher Drucker?)', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Praxisdrucker für Rechnungen verwenden'],
            'media' => ['type' => 'choice', 'label' => 'Papier', 'choices' => ['A4' => 'A4', 'Letter' => 'Letter']],
            'duplex' => ['type' => 'bool', 'label' => 'Beidseitig drucken'],
            'invoice_auto' => ['type' => 'bool', 'label' => 'T2med-Rechnungen immer automatisch drucken (Karte, Bar und „Später“)', 'default' => true],
        ]],
        'invoice_inbox' => ['label' => 'T2med-Rechnungen übernehmen', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Rechnungen über den Drucker „SumUp-Rechnung“ übernehmen'],
            'max_age_days' => ['type' => 'int', 'label' => 'Offene Rechnungen anbieten für (Tage)', 'default' => 14],
            'label_number' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor der Rechnungsnummer (einer pro Zeile)', 'optional' => true],
            'label_date' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor dem Rechnungsdatum', 'optional' => true],
            'label_total' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor der Gesamtsumme', 'optional' => true],
            'label_transfer' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor dem Überweisungsbetrag (Gegenprobe)', 'optional' => true],
            'number_prefix' => ['type' => 'list', 'label' => 'Kürzel der Rechnungsnummern (einer pro Zeile, z. B. IG)', 'optional' => true],
            'label_patient' => ['type' => 'list', 'label' => 'Eigene Textschnipsel vor Patient und Geburtsdatum', 'optional' => true],
        ]],
        'letterxpress' => ['label' => 'Letterxpress (Rechnung per Post)', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Rechnungen per Post über Letterxpress versenden', 'help' => 'Benutzername, API-Key und Test/Live stehen zentral unter Verwaltung → Verbindungen der Suite (Letterxpress).'],
            'color' => ['type' => 'choice', 'label' => 'Druck', 'default' => '1', 'choices' => ['1' => 'Schwarz-Weiß', '4' => 'Farbe']],
            'duplex' => ['type' => 'bool', 'label' => 'Beidseitig drucken', 'default' => false],
            'shipping' => ['type' => 'choice', 'label' => 'Versandart', 'default' => 'auto', 'choices' => ['auto' => 'Automatisch (Inland oder Ausland)', 'national' => 'Nur Inland', 'international' => 'Ausland']],
            'registered' => ['type' => 'choice', 'label' => 'Einschreiben', 'default' => 'none', 'choices' => ['none' => 'Kein Einschreiben', 'r1' => 'Einwurf-Einschreiben', 'r2' => 'Einschreiben']],
        ]],
        'invoice_export' => ['label' => 'Ablage im Netzwerkordner', 'fields' => [
            'enabled' => ['type' => 'bool', 'label' => 'Rechnungen als PDF in einen Netzwerkordner kopieren'],
            'ziel' => ['type' => 'target', 'label' => 'Ziel im Netzwerkspeicher', 'help' => 'Ziele und Zugangsdaten stehen zentral unter Verwaltung → Verbindungen der Suite.'],
            'directory' => ['type' => 'string', 'label' => 'Unterordner ({jahr} und {monat} möglich)', 'default' => 'Rechnungen/{jahr}/{monat}'],
            'app_invoices' => ['type' => 'bool', 'label' => 'Auch in der App erzeugte Rechnungen ablegen'],
            'when' => ['type' => 'choice', 'label' => 'T2med-Rechnungen ablegen', 'choices' => ['paid' => 'nur per Karte bezahlte', 'received' => 'jede gedruckte']],
        ]],
    ];

    public static function read(Config $base): array {
        $path = rtrim($base->get('app', 'state_dir'), '/') . '/payments.sqlite';
        if (!is_file($path)) return [];
        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key=?');
            $stmt->execute([self::ROW]);
            $cipher = $stmt->fetchColumn();
        } catch (\PDOException) {
            return [];   // Tabelle noch nicht angelegt (vor der ersten Migration)
        }
        return $cipher ? self::decrypt((string)$cipher, $base) : [];
    }

    /** Wirksame Konfiguration; ungültige Überlagerung fällt auf die TOML-Datei zurück. */
    public static function effective(Config $base): Config {
        $overrides = self::read($base);
        if (!$overrides) return $base;
        try {
            return new Config(self::merge($base->values, $overrides));
        } catch (\RuntimeException $e) {
            error_log('kienzle-sumup: gespeicherte Einstellungen ungültig, verwende Konfigurationsdatei: ' . $e->getMessage());
            return $base;
        }
    }

    public static function merge(array $values, array $overrides): array {
        foreach ($overrides as $section => $keys) {
            if (!is_array($keys) || !isset(self::SCHEMA[$section]) && $section !== 'internal') continue;
            // Nur Felder der Oberfläche: Verbindungen und Grundwerte der Suite lassen sich nicht überschreiben.
            foreach ($keys as $key => $value) if ($section === 'internal' || isset(self::SCHEMA[$section]['fields'][$key])) $values[$section][$key] = $value;
        }
        unset($values['internal']);
        return $values;
    }

    /** Werte für die Oberfläche; Geheimnisse nur als „gesetzt“. */
    public static function view(Config $config): array {
        $sections = [];
        foreach (self::SCHEMA as $section => $definition) {
            $fields = [];
            foreach ($definition['fields'] as $key => $field) {
                $value = $config->get($section, $key, $field['default'] ?? null);
                if ($field['type'] === 'queue' || $field['type'] === 'target') {
                    $field['choices'] = $field['type'] === 'queue' ? self::queues() : Suite::targets();
                    if (is_string($value) && $value !== '' && !isset($field['choices'][$value])) $field['choices'][$value] = $value . ' (nicht eingerichtet)';
                    if (!$field['choices']) $field['choices'] = ['' => $field['type'] === 'queue' ? 'Noch kein Drucker in der Suite eingerichtet' : 'Noch kein Netzwerkspeicher eingerichtet'];
                    $field['type'] = 'choice';
                }
                if ($field['type'] === 'secret') $value = ['set' => is_string($value) && $value !== ''];
                elseif ($field['type'] === 'money') $value = intdiv((int)$value, 100);
                elseif ($field['type'] === 'percent') $value = rtrim(rtrim(number_format((int)$value / 100, 2, ',', ''), '0'), ',');
                elseif ($field['type'] === 'list') $value = implode("\n", (array)$value);
                $fields[] = ['key' => $key, 'value' => $value] + SettingsHelp::for($section, $key) + $field;
            }
            $sections[] = ['id' => $section, 'label' => $definition['label'], 'fields' => $fields];
        }
        return $sections;
    }

    /** Einen Abschnitt speichern; liefert die neue wirksame Konfiguration oder wirft Problem mit Klartext. */
    public static function save(Database $db, Config $base, string $section, array $input): Config {
        if (!isset(self::SCHEMA[$section])) throw new Problem('Unbekannter Einstellungsbereich.', 404);
        $overrides = self::read($base);
        $current = self::effective($base);
        $values = $overrides[$section] ?? [];
        foreach (self::SCHEMA[$section]['fields'] as $key => $field) {
            if (!array_key_exists($key, $input)) continue;
            $raw = $input[$key];
            $values[$key] = match ($field['type']) {
                'bool' => $raw === true,
                'int' => $raw === '' || $raw === null ? null : (is_numeric($raw) && (string)(int)$raw === trim((string)$raw) ? (int)$raw : throw new Problem($field['label'] . ': ganze Zahl erwartet.', 422)),
                'money' => is_numeric($raw) && (int)$raw > 0 ? (int)round((float)$raw * 100) : throw new Problem($field['label'] . ': Betrag erwartet.', 422),
                'choice' => isset($field['choices'][$raw]) ? $raw : throw new Problem($field['label'] . ': ungültige Auswahl.', 422),
                'queue' => isset(self::queues()[$raw]) ? $raw : throw new Problem($field['label'] . ': Bitte einen Drucker aus Verwaltung → Drucker wählen.', 422),
                'target' => isset(Suite::targets()[$raw]) ? $raw : throw new Problem($field['label'] . ': Bitte ein Ziel aus Verwaltung → Verbindungen wählen.', 422),
                'list' => array_values(array_filter(array_map(fn($line) => rtrim(trim($line), '/'), preg_split('/[\r\n,]+/', (string)$raw)))),
                'secret' => trim((string)$raw) === '' ? $current->get($section, $key, '') : trim((string)$raw),
                'text' => self::text($field['label'], $raw),
                'percent' => self::percent($field['label'], (string)$raw),
                default => trim((string)$raw),
            };
            if ($values[$key] === null) unset($values[$key]);
        }
        if ($section === 'fhir' && ($values['mode'] ?? '') === 'live' && !Suite::connected('t2med')) {
            throw new Problem('Für den Live-Betrieb zuerst unter Verwaltung → Verbindungen die T2med-Verbindung einrichten.', 422);
        }
        if ($section === 'mail' && ($values['enabled'] ?? false) && !Suite::connected('mail')) {
            throw new Problem('Für den E-Mail-Versand zuerst unter Verwaltung → Verbindungen den E-Mail-Versand einrichten.', 422);
        }
        $overrides[$section] = $values;
        // Abhängigkeiten, die sonst eine Speicherung blockieren würden.
        $merged = self::merge($base->values, $overrides);
        if ($section === 'sumup' && ($values['mode'] ?? '') === 'mock' && ($merged['fhir']['mode'] ?? 'mock') === 'live') {
            throw new Problem('Im Testmodus darf T2med nicht live angebunden sein. Bitte zuerst bei der T2med-Anbindung den Testmodus wählen.', 422);
        }
        try {
            $config = new Config($merged);
        } catch (\RuntimeException $e) {
            throw new Problem($e->getMessage(), 422);
        }
        $db->query('INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at',
            [self::ROW, self::encrypt($overrides, $base), time()]);
        return $config;
    }

    /** Prozentsatz „19“ oder „7,5“ als Hundertstelprozent (1900, 750); 0 bis 30 %. */
    private static function percent(string $label, string $raw): int {
        $raw = trim(str_replace([',', '%'], ['.', ''], $raw));
        if (!preg_match('/^(\d{1,2})(?:\.(\d{1,2}))?$/D', $raw, $m)) throw new Problem($label . ': Zahl erwartet, z. B. 19 oder 7.', 422);
        $value = (int)$m[1] * 100 + (int)str_pad($m[2] ?? '', 2, '0');
        if ($value > 3000) throw new Problem($label . ': höchstens 30 %.', 422);
        return $value;
    }

    /** Warteschlangen der Suite (Name → Bezeichnung), ohne die virtuellen Drucker dieses Moduls. */
    public static function queues(): array {
        $out = [];
        try {
            foreach (Suite::modul()->drucker() as $q) if ($q['name'] !== 'SumUp-Rechnung') $out[$q['name']] = $q['name'] . ($q['description'] !== '' ? ' – ' . $q['description'] : '');
        } catch (\Throwable) {}
        return $out;
    }

    /** Mehrzeiliger Freitext: höchstens 1000 Zeichen, keine Steuerzeichen. */
    private static function text(string $label, mixed $raw): string {
        $text = trim(str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], (string)$raw), "\n ");
        if (mb_strlen($text) > 1000 || preg_match('/[\x00-\x09\x0b-\x1f\x7f-\x9f]/u', $text) || !mb_check_encoding($text, 'UTF-8')) {
            throw new Problem($label . ': höchstens 1000 Zeichen, nur normaler Text.', 422);
        }
        return $text;
    }

    private static function encrypt(array $data, Config $base): string {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $nonce, hex2bin($base->get('app', 'encryption_key'))));
    }
    private static function decrypt(string $cipher, Config $base): array {
        $raw = base64_decode($cipher, true);
        if ($raw === false || strlen($raw) < 40) return [];
        $json = sodium_crypto_secretbox_open(substr($raw, 24), substr($raw, 0, 24), hex2bin($base->get('app', 'encryption_key')));
        if ($json === false) { error_log('kienzle-sumup: gespeicherte Einstellungen nicht lesbar (Schlüssel geändert?)'); return []; }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
