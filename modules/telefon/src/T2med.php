<?php
declare(strict_types=1);
namespace KienzleTelefon;

/**
 * T2med lesen (FHIR) für das Telefonverzeichnis, wie kienzlefon-t2med (fhir-importer) und das Modul Signatur: GET {basis}/Patient,
 * Weiterblättern nur über Bundle.link „next“ auf demselben Server. Verbindungsdaten (Adresse, API-Schlüssel, Zertifikat) liefert die
 * Basis (Praxis → Verbindungen → T2med); das Zugriffstoken kommt beim Aufruf aus T2med und wird nie gespeichert außer verschlüsselt
 * für die Dauer des Ladens.
 */
class T2med {
    public const PATIENTENNUMMER = 'https://fhir.t2med.de/identifier/patientennummer';
    /** Ohne API-Schlüssel der Praxis gibt es keinen Ersatz (der Schlüssel steht nie im Quelltext). */
    public const OHNE_SCHLUESSEL = 'Kein T2med-API-Schlüssel eingetragen. Bitte unter Praxis → Verbindungen → T2med den API-Schlüssel der Praxis eintragen.';

    /** @param array<string,mixed> $v Verbindung „t2med“ der Basis */
    public function __construct(private array $v, private string $caDatei = '') {}

    /** Fehlt der API-Schlüssel der Praxis in der Basis? */
    public function ohneSchluessel(): bool { return trim((string)($this->v['api_key'] ?? '')) === ''; }

    /** Die von T2med übergebene Adresse ist nur erlaubt, wenn sie in der Basis hinterlegt ist (kein Token an fremde Server). */
    public function erlaubt(string $url): bool {
        $basis = rtrim((string)($this->v['base_url'] ?? ''), '/');
        $liste = array_map(static fn($u) => rtrim((string)$u, '/'), (array)($this->v['launch_urls'] ?? []));
        if ($basis !== '') $liste[] = $basis;
        return $url !== '' && preg_match('#^https://#', $url) === 1 && in_array(rtrim($url, '/'), $liste, true);
    }

    /** Nächste Seite nur auf demselben Server und unter derselben Basis, https, ohne Zugangsdaten in der Adresse. */
    public static function naechste(array $bundle, string $basis): ?string {
        foreach ((array)($bundle['link'] ?? []) as $l) {
            if (!is_array($l) || ($l['relation'] ?? '') !== 'next' || !is_string($l['url'] ?? null)) continue;
            $u = parse_url($l['url']);
            $b = parse_url($basis);
            if (!is_array($u) || !is_array($b) || ($u['scheme'] ?? '') !== 'https' || isset($u['user']) || isset($u['pass'])) return null;
            if (strtolower((string)($u['host'] ?? '')) !== strtolower((string)($b['host'] ?? '')) || (int)($u['port'] ?? 443) !== (int)($b['port'] ?? 443)) return null;
            if (!str_starts_with((string)($u['path'] ?? ''), rtrim((string)($b['path'] ?? ''), '/') . '/')) return null;
            return $l['url'];
        }
        return null;
    }

    /**
     * Patienten aus einem Bundle: id, Patientennummer (genau eine, sonst ''), Vor- und Nachname (offizieller Name zuerst, alte und
     * Geburtsnamen übersprungen), Geburtsdatum (JJJJ-MM-TT) und Telefonnummern.
     * @return list<array{id:string,nummer:string,vorname:string,nachname:string,geburt:string,telefone:list<string>}>
     */
    public static function patienten(array $bundle): array {
        $out = [];
        foreach ((array)($bundle['entry'] ?? []) as $e) {
            $p = is_array($e) ? ($e['resource'] ?? null) : null;
            if (!is_array($p) || ($p['resourceType'] ?? '') !== 'Patient' || !is_string($p['id'] ?? null) || !preg_match('/^[A-Za-z0-9.-]{1,64}$/D', $p['id'])) continue;
            $nummern = [];
            foreach ((array)($p['identifier'] ?? []) as $i) {
                if (is_array($i) && ($i['system'] ?? '') === self::PATIENTENNUMMER && is_string($i['value'] ?? null) && preg_match('/^[1-9][0-9]{0,11}$/D', $i['value'])) $nummern[] = $i['value'];
            }
            $namen = array_values(array_filter((array)($p['name'] ?? []), fn($n) => is_array($n) && !in_array($n['use'] ?? '', ['old', 'maiden'], true)));
            usort($namen, fn($a, $b) => (['official' => 0, 'usual' => 1][$a['use'] ?? ''] ?? 2) <=> (['official' => 0, 'usual' => 1][$b['use'] ?? ''] ?? 2));
            $n = $namen[0] ?? [];
            $sauber = static fn(string $s): string => mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $s)), 0, 80);
            $vorname = $sauber(implode(' ', array_filter((array)($n['given'] ?? []), 'is_string')));
            $nachname = $sauber(is_string($n['family'] ?? null) ? $n['family'] : '');
            if ($vorname === '' && $nachname === '' && is_string($n['text'] ?? null)) $nachname = $sauber($n['text']);
            $tel = [];
            foreach ((array)($p['telecom'] ?? []) as $c) {
                if (is_array($c) && in_array($c['system'] ?? '', ['phone', 'sms'], true) && ($c['use'] ?? '') !== 'old' && is_string($c['value'] ?? null) && preg_match('/^[0-9 +()\/.-]{3,40}$/D', trim($c['value']))) $tel[] = trim($c['value']);
            }
            $geb = is_string($p['birthDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $p['birthDate']) ? $p['birthDate'] : '';
            $out[] = ['id' => $p['id'], 'nummer' => count(array_unique($nummern)) === 1 ? $nummern[0] : '', 'vorname' => $vorname, 'nachname' => $nachname, 'geburt' => $geb, 'telefone' => array_values(array_unique($tel))];
        }
        return $out;
    }

    /** Eine Seite der Patientenliste (erste: {basis}/Patient). */
    public function seite(string $url, string $token): array {
        $a = $this->anfrage($url, $token);
        if ($a['status'] === 401 || $a['status'] === 403) throw new \RuntimeException('T2med lehnt den Zugriff ab (HTTP ' . $a['status'] . '). Den API-Schlüssel (Praxis → Verbindungen → T2med) und den Drittanbieter-Zugriff in T2med prüfen; das Telefonverzeichnis frisch aus T2med aktualisieren.');
        if ($a['status'] !== 200 || !is_array($a['body']) || ($a['body']['resourceType'] ?? '') !== 'Bundle') throw new \RuntimeException('T2med hat keine Patientenliste geliefert (HTTP ' . $a['status'] . ').');
        return $a['body'];
    }

    /** @return array{status:int,body:mixed} */
    protected function anfrage(string $url, string $token): array {
        $key = (string)($this->v['api_key'] ?? '');
        if ($key === '') throw new \RuntimeException(self::OHNE_SCHLUESSEL);
        $kopf = ['Accept: application/fhir+json', 'Authorization: Bearer ' . $token, 'X-API-Key: ' . $key, 'X-FHIR-Profile: https://fhir.t2med.de/StructureDefinition/FhirApiPatient|1.0.0',
            'X-TreatWarningAsError: true', 'User-Agent: kienzle-telefon'];
        $roh = ''; $zuGross = false;
        $curl = curl_init($url);
        $opt = [CURLOPT_HTTPHEADER => $kopf, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $c) use (&$roh, &$zuGross): int {
                if (strlen($roh) + strlen($c) > 16 * 1024 * 1024) { $zuGross = true; return 0; }
                $roh .= $c; return strlen($c);
            }];
        if ($this->caDatei !== '') $opt[CURLOPT_CAINFO] = $this->caDatei;
        if (!empty($this->v['pin']) && $this->caDatei !== '') {
            $opt[CURLOPT_PINNEDPUBLICKEY] = self::pin($this->caDatei);
            $opt[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($curl, $opt);
        curl_exec($curl);
        $fehler = curl_errno($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curl = null;
        if ($fehler || $zuGross) throw new \RuntimeException('T2med ist nicht erreichbar oder das Zertifikat passt nicht (cURL ' . $fehler . '). Verbindung unter Praxis → Verbindungen prüfen.');
        $json = json_decode($roh, true);
        return ['status' => $status, 'body' => is_array($json) ? $json : null];
    }

    private static function pin(string $datei): string {
        $pem = (string)@file_get_contents($datei, false, null, 0, 65537);
        $cert = @openssl_x509_read($pem);
        $key = $cert === false ? false : @openssl_pkey_get_public($cert);
        $d = $key === false ? false : openssl_pkey_get_details($key);
        $der = is_array($d) ? base64_decode(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\r", "\n"], '', (string)($d['key'] ?? '')), true) : false;
        if (!$der) throw new \RuntimeException('Zertifikatsbindung benötigt genau ein lesbares T2med-Serverzertifikat.');
        return 'sha256//' . base64_encode(hash('sha256', $der, true));
    }
}
