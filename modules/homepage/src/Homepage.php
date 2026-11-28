<?php
declare(strict_types=1);
namespace KienzleHomepage;

/**
 * Verbindung zur WordPress-Homepage: Adresse und Schlüssel (vom Plugin „Praxisherz-Hinweise“ erzeugt, hier verschlüsselt gespeichert),
 * Übertragung aller eingeschalteten Mitteilungen als JSON mit HMAC-SHA256-Unterschrift und Zeitstempel (Schutz vor Wiederholung).
 * Der Praxisserver schickt nur; die Homepage muss den Server nicht erreichen können.
 */
final class Homepage {
    public const ROUTE = '/?rest_route=/praxisherz/v1/hinweise';
    public const PLUGIN = 'praxisherz-hinweise';

    public static function adresse(): string { return Speicher::wert('url'); }

    public static function eingerichtet(): bool { return self::adresse() !== '' && Speicher::wert('schluessel') !== ''; }

    /** Adresse prüfen und vereinheitlichen: https://name[:port][/pfad], ohne Zugangsdaten, Abfrage oder Anker. */
    public static function adressePruefen(string $url): string {
        $url = trim($url);
        if ($url !== '' && !preg_match('#^[a-z]+://#i', $url)) $url = 'https://' . $url;
        $t = parse_url($url);
        $host = strtolower((string)($t['host'] ?? ''));
        $lokal = in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
        if (!is_array($t) || $host === '' || isset($t['user']) || isset($t['pass']) || isset($t['query']) || isset($t['fragment'])
            || strlen($url) > 200 || !preg_match('/^[a-z0-9.\-\[\]:]+$/', $host)) {
            throw new \InvalidArgumentException('Bitte die Adresse der Homepage eintragen, z. B. https://www.praxis-beispiel.example.org');
        }
        $schema = strtolower((string)$t['scheme']);
        if ($schema !== 'https' && !($schema === 'http' && $lokal)) throw new \InvalidArgumentException('Die Homepage muss mit https:// erreichbar sein.');
        $pfad = rtrim((string)($t['path'] ?? ''), '/');
        if (!preg_match('#^[A-Za-z0-9/_.~-]*$#D', $pfad) || str_contains($pfad, '..')) throw new \InvalidArgumentException('Der Pfad der Adresse enthält ungültige Zeichen.');
        return $schema . '://' . $host . (isset($t['port']) ? ':' . (int)$t['port'] : '') . $pfad;
    }

    /** Adresse und (wenn eingetragen) neuen Schlüssel speichern; ein leeres Schlüsselfeld behält den bisherigen. */
    public static function einrichten(\KienzleModul $k, string $url, string $schluessel): void {
        $url = self::adressePruefen($url);
        $schluessel = strtolower(trim($schluessel));
        if ($schluessel !== '' && !preg_match('/^[0-9a-f]{64}$/D', $schluessel)) {
            throw new \InvalidArgumentException('Der Schlüssel hat nicht das richtige Format (64 Zeichen 0–9 und a–f). Bitte aus WordPress neu kopieren.');
        }
        if ($schluessel === '' && Speicher::wert('schluessel') === '') throw new \InvalidArgumentException('Bitte den Schlüssel aus WordPress eintragen.');
        Speicher::setzen('url', $url);
        if ($schluessel !== '') Speicher::setzen('schluessel', $k->verschluesseln($schluessel, 'wordpress'));
    }

    public static function trennen(): void {
        Speicher::setzen('url', '');
        Speicher::setzen('schluessel', '');
        Speicher::setzen('stand', '');
    }

    /** Letzte Übertragung: ['zeit' => int, 'ok' => bool, 'meldung' => string, 'anzahl' => int] oder null. */
    public static function stand(): ?array {
        $s = json_decode(Speicher::wert('stand'), true);
        return is_array($s) && isset($s['zeit'], $s['ok']) ? $s : null;
    }

    /** Muss noch übertragen werden (nach Änderungen ohne Erfolg)? */
    public static function ausstehend(): bool { return Speicher::wert('ausstehend') === '1'; }

    /** Nach jeder Änderung: übertragen, wenn eingerichtet; sonst nur vormerken. Liefert den neuen Stand oder null. */
    public static function nachAenderung(\KienzleModul $k): ?array {
        Speicher::setzen('ausstehend', '1');
        return self::eingerichtet() ? self::uebertragen($k) : null;
    }

    /** Alle Mitteilungen an die Homepage schicken; hält das Ergebnis fest (ohne Schlüssel oder Inhalte). */
    public static function uebertragen(\KienzleModul $k): array {
        if (!self::eingerichtet()) throw new \InvalidArgumentException('Die Homepage ist noch nicht eingerichtet.');
        try {
            $geheim = $k->entschluesseln(Speicher::wert('schluessel'), 'wordpress');
        } catch (\Throwable) {
            return self::festhalten(false, 'Der gespeicherte Schlüssel ist nicht lesbar. Bitte unter „Einrichtung“ neu eintragen.', 0);
        }
        $hinweise = Mitteilungen::fuerHomepage();
        $zeit = time();
        $koerper = (string)json_encode(['format' => 1, 'zeit' => $zeit, 'hinweise' => $hinweise], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$code, $antwort, $fehler, $weiter] = self::senden(self::adresse() . self::ROUTE, $koerper, [
            'Content-Type: application/json; charset=utf-8',
            'X-Praxisherz-Zeit: ' . $zeit,
            'X-Praxisherz-Signatur: ' . self::signatur($geheim, $zeit, $koerper),
        ]);
        $j = json_decode($antwort, true);
        if ($code === 200 && is_array($j) && ($j['ok'] ?? false) === true) {
            Speicher::setzen('ausstehend', '0');
            return self::festhalten(true, '', count($hinweise));
        }
        $meldung = match (true) {
            $code === 0 => 'Die Homepage ist nicht erreichbar' . ($fehler !== '' ? ' (' . $fehler . ')' : '') . '.',
            $code >= 300 && $code < 400 => 'Die Homepage leitet weiter' . ($weiter !== '' ? ' auf ' . $weiter : '') . '. Bitte unter „Einrichtung“ die genaue Adresse eintragen.',
            $code === 401 || $code === 403 => 'Die Homepage hat die Mitteilungen abgelehnt: ' . (is_array($j) && is_string($j['message'] ?? null) ? mb_substr($j['message'], 0, 160) : 'Schlüssel stimmt nicht') . '.',
            $code === 404 => 'Auf der Homepage fehlt das Plugin „Praxisherz-Hinweise“ (installiert und aktiviert?).',
            default => 'Die Homepage antwortete mit Fehler ' . $code . '.',
        };
        return self::festhalten(false, $meldung, 0);
    }

    /** Unterschrift wie im Plugin: HMAC-SHA256 über „Zeit\nKörper“ mit dem Schlüssel (64 Hexzeichen) als Text. */
    public static function signatur(string $geheim, int $zeit, string $koerper): string {
        return hash_hmac('sha256', $zeit . "\n" . $koerper, $geheim);
    }

    private static function festhalten(bool $ok, string $meldung, int $anzahl): array {
        $s = ['zeit' => time(), 'ok' => $ok, 'meldung' => $meldung, 'anzahl' => $anzahl];
        Speicher::setzen('stand', (string)json_encode($s, JSON_UNESCAPED_UNICODE));
        if (!$ok) error_log('homepage: Übertragung fehlgeschlagen');
        return $s;
    }

    /** @return array{0:int,1:string,2:string,3:string} HTTP-Code (0 = keine Verbindung), Antwort (höchstens 64 KB), Fehlertext, Weiterleitungsziel */
    private static function senden(string $url, string $koerper, array $kopf): array {
        $c = curl_init($url);
        $antwort = '';
        curl_setopt_array($c, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $koerper, CURLOPT_HTTPHEADER => $kopf,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_USERAGENT => 'Praxisherz-Homepage/1',
            CURLOPT_WRITEFUNCTION => static function ($c, string $teil) use (&$antwort): int {
                if (strlen($antwort) + strlen($teil) > 65536) return 0;
                $antwort .= $teil;
                return strlen($teil);
            },
        ]);
        $ok = curl_exec($c);
        $code = $ok === false ? 0 : (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        $fehler = $ok === false ? self::curlFehler(curl_errno($c)) : '';
        $weiter = (string)(curl_getinfo($c, CURLINFO_REDIRECT_URL) ?: '');
        curl_close($c);
        return [$code, $antwort, $fehler, mb_substr($weiter, 0, 200)];
    }

    private static function curlFehler(int $nr): string {
        return match ($nr) {
            CURLE_COULDNT_RESOLVE_HOST => 'Name nicht gefunden',
            CURLE_COULDNT_CONNECT => 'Verbindung abgelehnt',
            CURLE_OPERATION_TIMEOUTED => 'keine Antwort',
            CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION, CURLE_SSL_CACERT => 'Zertifikat der Homepage ungültig',
            default => 'Fehler ' . $nr,
        };
    }

    /** ZIP mit dem WordPress-Plugin zum Hochladen unter Plugins → Installieren → Plugin hochladen. */
    public static function pluginZip(): string {
        $quelle = dirname(__DIR__) . '/wordpress/' . self::PLUGIN;
        $tmp = tempnam(sys_get_temp_dir(), 'hpz');
        if ($tmp === false) throw new \RuntimeException('Kein temporärer Ordner.');
        try {
            $z = new \ZipArchive();
            if ($z->open($tmp, \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('ZIP nicht anlegbar.');
            foreach (['praxisherz-hinweise.php', 'hinweise.css', 'hinweise.js', 'readme.txt'] as $f) $z->addFile($quelle . '/' . $f, self::PLUGIN . '/' . $f);
            $z->close();
            return (string)file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Version des mitgelieferten Plugins (aus dessen Kopf). */
    public static function pluginVersion(): string {
        $kopf = (string)file_get_contents(dirname(__DIR__) . '/wordpress/' . self::PLUGIN . '/praxisherz-hinweise.php', false, null, 0, 2000);
        return preg_match('/^\s*\*\s*Version:\s*([0-9][0-9A-Za-z.\-]*)/m', $kopf, $m) ? $m[1] : '';
    }

    /** Einrichtungs-Überblick der Basis. */
    public static function ueberblick(): array {
        $stand = self::stand();
        $praxen = count(Praxen::alle());
        $verbunden = self::eingerichtet() && $stand && $stand['ok'];
        return [
            ['title' => 'WordPress-Homepage verbunden', 'state' => $verbunden ? 'done' : (self::eingerichtet() ? 'error' : 'open'),
             'hint' => $verbunden ? 'Mitteilungen gehen an ' . self::adresse() . '.' : (self::eingerichtet() ? ($stand['meldung'] ?? 'Noch nicht übertragen.') : 'Plugin „Praxisherz-Hinweise“ in WordPress installieren und den Schlüssel hier eintragen.'),
             'link' => '/homepage/einrichtung'],
            ['title' => 'Vertretungspraxen angelegt', 'optional' => true, 'state' => $praxen > 0 ? 'done' : 'open',
             'hint' => $praxen > 0 ? $praxen . ' Praxen in der Liste.' : 'Praxen mit Anschrift und Telefon einmal anlegen; danach in Mitteilungen nur noch ankreuzen.', 'link' => '/homepage/praxen'],
        ];
    }
}
