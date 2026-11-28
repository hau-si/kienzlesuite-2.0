<?php
declare(strict_types=1);
namespace KienzleSignatur;

/**
 * Patient aus T2med lesen (FHIR), nur für den Start aus T2med (praxisherz-signatur://…). Verbindungsdaten liefert die Basis
 * (Praxis → Verbindungen → T2med). Keine Weiterleitungen, nur HTTPS; Fehlermeldungen enthalten keine Zugangsdaten.
 */
class Fhir {
    public const KONTEXT = 'https://fhir.t2med.de/identifier/kontext';
    /** Ohne API-Schlüssel der Praxis gibt es keinen Ersatz (der Schlüssel steht nie im Quelltext). */
    public const OHNE_SCHLUESSEL = 'Kein T2med-API-Schlüssel eingetragen. Bitte unter Praxis → Verbindungen → T2med den API-Schlüssel der Praxis eintragen.';

    /** @param array<string,mixed> $v Verbindung „t2med“ der Basis */
    public function __construct(private array $v, private string $caDatei = '') {}

    /** Adresse, die T2med beim Aufruf übergibt, ist nur erlaubt, wenn sie in der Basis hinterlegt ist. */
    public function erlaubt(string $url): bool {
        $basis = rtrim((string)($this->v['base_url'] ?? ''), '/');
        $liste = array_map(static fn($u) => rtrim((string)$u, '/'), (array)($this->v['launch_urls'] ?? []));
        if ($basis !== '') $liste[] = $basis;
        return $url !== '' && in_array(rtrim($url, '/'), $liste, true);
    }

    /** @return array{id:string,name:string,vorname:string,nachname:string,geschlecht:string,geburtsdatum:string,telefon:string,email:string,adresse:string} */
    public function patient(string $kontext, string $token): array {
        $basis = rtrim((string)($this->v['base_url'] ?? ''), '/');
        if ($basis === '') throw new \RuntimeException('T2med ist in der Basis nicht eingerichtet (Praxis → Verbindungen).');
        $antwort = $this->anfrage($basis . '/Patient?' . http_build_query(['identifier' => self::KONTEXT . '|' . $kontext]), $token);
        if ($antwort['status'] !== 200) throw new \RuntimeException('Patient konnte nicht aus T2med geladen werden (HTTP ' . $antwort['status'] . '). ' . match (true) {
            $antwort['status'] === 401, $antwort['status'] === 403 => 'T2med lehnt den Zugriff ab: den API-Schlüssel (Praxis → Verbindungen → T2med) und den Drittanbieter-Zugriff in T2med prüfen; außerdem muss der Aufruf frisch aus der Patientenakte kommen.',
            $antwort['status'] === 404 => 'Die Adresse oder der Kontext ist T2med unbekannt. Bitte frisch aus der Patientenakte öffnen und die FHIR-Adresse prüfen.',
            default => 'Bitte frisch aus der Patientenakte öffnen.',
        });
        $body = $antwort['body']; $liste = [];
        if (($body['resourceType'] ?? '') === 'Patient') $liste[] = $body;
        elseif (($body['resourceType'] ?? '') === 'Bundle') foreach ($body['entry'] ?? [] as $e) if (($e['resource']['resourceType'] ?? '') === 'Patient') $liste[] = $e['resource'];
        if (count($liste) !== 1 || empty($liste[0]['id'])) throw new \RuntimeException('Der Patientenkontext ist nicht eindeutig.');
        $p = $liste[0]; $n = $p['name'][0] ?? [];
        $name = trim(implode(' ', array_filter((array)($n['given'] ?? []), 'is_string')) . ' ' . (is_string($n['family'] ?? null) ? $n['family'] : ''));
        $name = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $name) ?? '');
        $geb = is_string($p['birthDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $p['birthDate']) ? $p['birthDate'] : '';
        $tel = ''; $mail = '';
        $kontakte = array_filter(is_array($p['telecom'] ?? null) ? $p['telecom'] : [], static fn($c) => is_array($c) && ($c['use'] ?? '') !== 'old' && is_string($c['value'] ?? null));
        usort($kontakte, static fn($a, $b) => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));
        foreach ($kontakte as $c) {
            $w = trim($c['value']);
            if ($mail === '' && ($c['system'] ?? '') === 'email' && strlen($w) <= 254 && filter_var($w, FILTER_VALIDATE_EMAIL)) $mail = $w;
            if ($tel === '' && in_array($c['system'] ?? '', ['phone', 'sms'], true) && preg_match('/^[0-9 +()\/.-]{3,40}$/D', $w)) $tel = $w;
        }
        $adresse = '';
        foreach (is_array($p['address'] ?? null) ? $p['address'] : [] as $a) {
            if (!is_array($a) || ($a['use'] ?? '') === 'old') continue;
            $ende = $a['period']['end'] ?? '';
            if (is_string($ende) && $ende !== '' && substr($ende, 0, 10) < date('Y-m-d')) continue;
            $teile = array_filter(is_array($a['line'] ?? null) ? $a['line'] : [], 'is_string');
            $teile[] = trim((string)($a['postalCode'] ?? '') . ' ' . (string)($a['city'] ?? ''));
            $adresse = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', implode(', ', array_filter(array_slice($teile, 0, 5)))) ?? ''), 0, 200);
            if ($adresse !== '') break;
        }
        $vorname = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', implode(' ', array_filter((array)($n['given'] ?? []), 'is_string'))) ?? '');
        $nachname = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', is_string($n['family'] ?? null) ? $n['family'] : '') ?? '');
        $geschlecht = in_array($p['gender'] ?? '', ['male', 'female', 'other', 'unknown'], true) ? $p['gender'] : 'unknown';
        return ['id' => (string)$p['id'], 'name' => mb_substr($name !== '' ? $name : 'Patient ' . $p['id'], 0, 80), 'vorname' => mb_substr($vorname, 0, 80), 'nachname' => mb_substr($nachname, 0, 80), 'geschlecht' => $geschlecht,
            'geburtsdatum' => $geb, 'telefon' => $tel, 'email' => $mail, 'adresse' => $adresse];
    }

    /**
     * Telefon und/oder E-Mail eines Patienten in T2med ändern. Der Patient wird frisch gelesen, nur diese beiden Angaben werden
     * ersetzt (leer = unverändert lassen, nie löschen), alles andere geht unverändert zurück; PUT mit If-Match auf die gelesene Version.
     * @return array{telefon:bool,email:bool} was tatsächlich geändert wurde
     */
    public function kontaktSchreiben(string $patientId, string $token, string $telefon, string $email): array {
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $patientId)) throw new \InvalidArgumentException('Ungültige Patienten-ID.');
        $basis = rtrim((string)($this->v['base_url'] ?? ''), '/');
        if ($basis === '') throw new \RuntimeException('T2med ist in der Basis nicht eingerichtet (Praxis → Verbindungen).');
        $url = $basis . '/Patient/' . rawurlencode($patientId);
        $alt = $this->anfrage($url, $token);
        if ($alt['status'] !== 200 || !is_array($alt['body']) || ($alt['body']['resourceType'] ?? '') !== 'Patient' || ($alt['body']['id'] ?? '') !== $patientId) {
            throw new \RuntimeException('Der Patient konnte vor dem Ändern nicht aus T2med gelesen werden (HTTP ' . $alt['status'] . '). Bitte frisch aus der Patientenakte öffnen.');
        }
        $p = $alt['body'];
        $version = (string)($p['meta']['versionId'] ?? '');
        $teil = $version !== '' ? preg_replace('~^.*/_history/~', '', $version) : '';
        $telecom = is_array($p['telecom'] ?? null) ? array_values($p['telecom']) : [];
        $geaendert = ['telefon' => false, 'email' => false];
        foreach (['telefon' => ['phone', $telefon], 'email' => ['email', $email]] as $art => [$system, $wert]) {
            if ($wert === '') continue;
            $stelle = null;
            foreach ($telecom as $i => $c) if (is_array($c) && ($c['system'] ?? '') === $system && ($c['use'] ?? '') !== 'old') { $stelle = $i; break; }
            if ($stelle === null) { $telecom[] = ['system' => $system, 'value' => $wert, 'use' => 'home']; $geaendert[$art] = true; }
            elseif (($telecom[$stelle]['value'] ?? '') !== $wert) { $telecom[$stelle]['value'] = $wert; $geaendert[$art] = true; }
        }
        if (!$geaendert['telefon'] && !$geaendert['email']) return $geaendert;
        $p['telecom'] = $telecom;
        $p['meta']['profile'] = ['https://fhir.t2med.de/StructureDefinition/FhirApiPatient|1.0.0'];
        $kopf = ['Content-Type: application/fhir+json'];
        if ($teil !== '' && preg_match('/^\d{1,12}$/D', $teil)) $kopf[] = 'If-Match: W/"' . $teil . '"';
        $neu = $this->anfrage($url, $token, 'PUT', $p, $kopf);
        if ($neu['status'] === 409) throw new \RuntimeException('Der Patient wurde in T2med inzwischen geändert. Bitte frisch aus der Patientenakte öffnen und erneut übernehmen.');
        if ($neu['status'] < 200 || $neu['status'] > 299) throw new \RuntimeException('T2med hat die Änderung abgelehnt (HTTP ' . $neu['status'] . ').');
        return $geaendert;
    }

    public const ANHANG = 'https://fhir.t2med.de/StructureDefinition/FhirApiDocumentReferenceAnhang|1.0.0';

    /**
     * DocumentReference (Profil „Anhang“) für ein PDF in der Akte des Kontexts, wie im T2med-Integrationsleitfaden (1.10).
     * Gleicher Aufbau wie in der Kasse (dort für Vorgänge, die aus der Kasse kamen).
     */
    public static function dokumentRessource(string $kontext, string $pdf, string $titel, int $zeit): array {
        $iso = date('c', $zeit);
        $titel = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $titel) ?? '') ?: 'Unterschriebenes Dokument';
        $datei = trim(preg_replace('/[^A-Za-z0-9ÄÖÜäöüß _.-]+/u', '', $titel) ?? '') ?: 'dokument';
        return ['resourceType' => 'DocumentReference', 'meta' => ['profile' => [self::ANHANG]],
            'identifier' => [['system' => self::KONTEXT, 'value' => $kontext]], 'status' => 'current', 'date' => $iso,
            'description' => mb_substr($titel . ' (unterschrieben)', 0, 200),
            'content' => [['attachment' => ['contentType' => 'application/pdf', 'title' => mb_substr($datei, 0, 80) . '.pdf', 'creation' => $iso, 'data' => base64_encode($pdf)]]]];
    }

    /** Legt das unterschriebene PDF in der T2med-Akte ab (POST DocumentReference). Wirft mit verständlicher Meldung. */
    public function dokumentAblegen(string $kontext, string $token, string $pdf, string $titel, int $zeit): void {
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $kontext)) throw new \InvalidArgumentException('Ungültiger T2med-Kontext.');
        if (!str_starts_with($pdf, '%PDF-') || strlen($pdf) > 10 * 1024 * 1024) throw new \InvalidArgumentException('Das PDF ist ungültig oder zu groß.');
        $basis = rtrim((string)($this->v['base_url'] ?? ''), '/');
        if ($basis === '') throw new \RuntimeException('T2med ist in der Basis nicht eingerichtet (Praxis → Verbindungen).');
        $a = $this->anfrage($basis . '/DocumentReference', $token, 'POST', self::dokumentRessource($kontext, $pdf, $titel, $zeit), ['Content-Type: application/fhir+json'], '');
        self::antwortPruefen($a['status'], $a['body']);
    }

    /** Akteneintrag (Freitext mit Kürzel) als Transaktion – wie die Kasse ihre Zahlungen dokumentiert (nur dokumentierte Funktionen). */
    public static function eintragRessource(string $kontext, string $kuerzel, string $text, int $zeit): array {
        return ['resourceType' => 'Bundle', 'type' => 'transaction', 'entry' => [['request' => ['method' => 'POST', 'url' => 'Observation'], 'resource' => [
            'resourceType' => 'Observation', 'meta' => ['profile' => ['https://fhir.t2med.de/StructureDefinition/FhirApiObservationFreitext|1.0.0']],
            'identifier' => [['system' => self::KONTEXT, 'value' => $kontext]],
            'extension' => [['url' => 'https://fhir.t2med.de/StructureDefinition/FhirApiFreitextKuerzel', 'valueString' => $kuerzel]],
            'status' => 'final', 'effectiveDateTime' => date(DATE_ATOM, $zeit), 'valueString' => mb_substr($text, 0, 4000)]]]];
    }

    /** Schreibt den Akteneintrag des Vorgangs in die T2med-Akte. Wirft mit verständlicher Meldung. */
    public function eintragAblegen(string $kontext, string $token, string $kuerzel, string $text, int $zeit): void {
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $kontext)) throw new \InvalidArgumentException('Ungültiger T2med-Kontext.');
        $basis = rtrim((string)($this->v['base_url'] ?? ''), '/');
        if ($basis === '') throw new \RuntimeException('T2med ist in der Basis nicht eingerichtet (Praxis → Verbindungen).');
        $a = $this->anfrage($basis, $token, 'POST', self::eintragRessource($kontext, $kuerzel, $text, $zeit), ['Content-Type: application/fhir+json'], '');
        $body = $a['body'];
        $antwort = is_array($body) && ($body['resourceType'] ?? '') === 'Bundle' ? ($body['entry'][0]['response'] ?? []) : null;
        if ($a['status'] >= 200 && $a['status'] < 300 && is_array($antwort)) {
            $status = (int)($antwort['status'] ?? 0);
            self::antwortPruefen($status ?: 500, $antwort['outcome'] ?? null);
            return;
        }
        self::antwortPruefen($a['status'] >= 200 && $a['status'] < 300 ? 502 : $a['status'], $body);
    }

    /** 2xx ohne Fehler im OperationOutcome = abgelegt; sonst Ausnahme mit Grund (ohne Patientendaten, höchstens 160 Zeichen von T2med). */
    public static function antwortPruefen(int $status, mixed $body): void {
        $detail = '';
        $fehler = false;
        if (is_array($body) && ($body['resourceType'] ?? '') === 'OperationOutcome') {
            foreach ((array)($body['issue'] ?? []) as $i) {
                if (!is_array($i) || !in_array($i['severity'] ?? '', ['fatal', 'error'], true)) continue;
                $fehler = true;
                $t = (string)($i['details']['text'] ?? $i['diagnostics'] ?? '');
                $detail = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $t) ?? ''), 0, 160);
                break;
            }
        }
        if ($status >= 200 && $status < 300 && !$fehler) return;
        $grund = match (true) {
            $status === 401, $status === 403 => 'T2med lehnt den Zugriff ab (Aufruf älter als 4 Stunden oder API-Schlüssel falsch). Bitte frisch aus der Patientenakte öffnen und erneut ablegen.',
            $status === 413 => 'Das PDF ist für T2med zu groß.',
            default => 'T2med hat den Eintrag nicht angenommen (HTTP ' . $status . ').',
        };
        throw new \RuntimeException($grund . ($detail !== '' ? ' Auskunft von T2med: ' . $detail : ''));
    }

    /** @return array{status:int,body:mixed} */
    protected function anfrage(string $url, string $token, string $methode = 'GET', ?array $body = null, array $mehr = [], string $profil = 'https://fhir.t2med.de/StructureDefinition/FhirApiPatient|1.0.0'): array {
        $key = (string)($this->v['api_key'] ?? '');
        if ($key === '') throw new \RuntimeException(self::OHNE_SCHLUESSEL);
        $kopf = array_merge(['Accept: application/fhir+json', 'Authorization: Bearer ' . $token, 'X-API-Key: ' . $key],
            $profil !== '' ? ['X-FHIR-Profile: ' . $profil] : [], ['X-TreatWarningAsError: true', 'Prefer: return=OperationOutcome', 'User-Agent: kienzle-signatur'], $mehr);
        $roh = ''; $zuGross = false;
        $curl = curl_init($url);
        $opt = [CURLOPT_HTTPHEADER => $kopf, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $methode === 'GET' ? 20 : 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $c) use (&$roh, &$zuGross): int {
                if (strlen($roh) + strlen($c) > 1024 * 1024) { $zuGross = true; return 0; }
                $roh .= $c; return strlen($c);
            }];
        if ($methode !== 'GET') {
            $opt[CURLOPT_CUSTOMREQUEST] = $methode;
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
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
