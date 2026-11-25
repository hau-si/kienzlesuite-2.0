<?php
declare(strict_types=1);
namespace KienzleSignatur;

/**
 * Patient aus T2med lesen (FHIR), nur für den Start aus T2med (kienzle-signatur://…). Verbindungsdaten liefert die Basis
 * (Verwaltung → Verbindungen → T2med). Keine Weiterleitungen, nur HTTPS; Fehlermeldungen enthalten keine Zugangsdaten.
 */
class Fhir {
    public const KONTEXT = 'https://fhir.t2med.de/identifier/kontext';

    /** @param array<string,mixed> $v Verbindung „t2med“ der Basis */
    public function __construct(private array $v, private string $caDatei = '') {}

    /** Adresse, die T2med beim Aufruf übergibt, ist nur erlaubt, wenn sie in der Basis hinterlegt ist. */
    public function erlaubt(string $url): bool {
        $basis = rtrim((string)($this->v['base_url'] ?? ''), '/');
        $liste = array_map(static fn($u) => rtrim((string)$u, '/'), (array)($this->v['launch_urls'] ?? []));
        if ($basis !== '') $liste[] = $basis;
        return $url !== '' && in_array(rtrim($url, '/'), $liste, true);
    }

    /** @return array{id:string,name:string,geburtsdatum:string} */
    public function patient(string $kontext, string $token): array {
        $basis = rtrim((string)($this->v['base_url'] ?? ''), '/');
        if ($basis === '') throw new \RuntimeException('T2med ist in der Basis nicht eingerichtet (Verwaltung → Verbindungen).');
        $antwort = $this->anfrage($basis . '/Patient?' . http_build_query(['identifier' => self::KONTEXT . '|' . $kontext]), $token);
        if ($antwort['status'] !== 200) throw new \RuntimeException('Patient konnte nicht aus T2med geladen werden (HTTP ' . $antwort['status'] . '). Bitte frisch aus der Patientenakte öffnen.');
        $body = $antwort['body']; $liste = [];
        if (($body['resourceType'] ?? '') === 'Patient') $liste[] = $body;
        elseif (($body['resourceType'] ?? '') === 'Bundle') foreach ($body['entry'] ?? [] as $e) if (($e['resource']['resourceType'] ?? '') === 'Patient') $liste[] = $e['resource'];
        if (count($liste) !== 1 || empty($liste[0]['id'])) throw new \RuntimeException('Der Patientenkontext ist nicht eindeutig.');
        $p = $liste[0]; $n = $p['name'][0] ?? [];
        $name = trim(implode(' ', array_filter((array)($n['given'] ?? []), 'is_string')) . ' ' . (is_string($n['family'] ?? null) ? $n['family'] : ''));
        $name = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name) ?? '');
        $geb = is_string($p['birthDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $p['birthDate']) ? $p['birthDate'] : '';
        return ['id' => (string)$p['id'], 'name' => mb_substr($name !== '' ? $name : 'Patient ' . $p['id'], 0, 80), 'geburtsdatum' => $geb];
    }

    /** @return array{status:int,body:mixed} */
    private function anfrage(string $url, string $token): array {
        $key = (string)($this->v['api_key'] ?? '');
        $kopf = ['Accept: application/fhir+json', 'Authorization: Bearer ' . $token, 'X-API-Key: ' . $key, 'X-FHIR-Profile: https://fhir.t2med.de/StructureDefinition/FhirApiPatient|1.0.0',
            'User-Agent: kienzle-signatur'];
        $roh = ''; $zuGross = false;
        $curl = curl_init($url);
        $opt = [CURLOPT_HTTPHEADER => $kopf, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $c) use (&$roh, &$zuGross): int {
                if (strlen($roh) + strlen($c) > 1024 * 1024) { $zuGross = true; return 0; }
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
        if ($fehler || $zuGross) throw new \RuntimeException('T2med ist nicht erreichbar oder das Zertifikat passt nicht (cURL ' . $fehler . '). Verbindung unter Verwaltung → Verbindungen prüfen.');
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
