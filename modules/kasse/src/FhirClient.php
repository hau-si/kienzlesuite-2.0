<?php
declare(strict_types=1);
namespace KienzleKasse;

final class FhirClient {
    public const CONTEXT = 'https://fhir.t2med.de/identifier/kontext';
    public const PAYMENT = 'https://github.com/thomaskien/t2med-sumup/identifier/payment';
    public const NUMMER = 'https://fhir.t2med.de/identifier/patientennummer';
    public function __construct(private Config $config, private HttpClient $http) {}

    private function request(string $method, string $path, string $token, ?array $body = null, bool $patient = false): array {
        $headers = ['Accept: application/fhir+json', 'Content-Type: application/fhir+json',
            'Authorization: Bearer ' . $token, 'X-API-Key: ' . $this->config->get('fhir', 'api_key'),
            'X-TreatWarningAsError: true', 'Prefer: return=OperationOutcome', 'User-Agent: kienzle-kasse/' . trim(file_get_contents(dirname(__DIR__) . '/VERSION'))];
        if ($patient) $headers[] = 'X-FHIR-Profile: https://fhir.t2med.de/StructureDefinition/FhirApiPatient|1.0.0';
        return $this->http->request($method, rtrim($this->config->get('fhir', 'base_url'), '/') . $path, $headers, $body,
            $this->config->get('fhir', 'ca_file', ''), $this->config->get('fhir', 'pin_certificate', false));
    }
    /** Meldung zu einem fehlgeschlagenen Patientenabruf: HTTP-Status, wahrscheinliche Ursache und ggf. die Auskunft von t2med (OperationOutcome, ohne Patientendaten). */
    private static function contextError(int $status, mixed $body): string {
        $hint = match (true) {
            $status === 401, $status === 403 => 'T2med lehnt den Zugriff ab. Das Client Secret in der Suite (Verwaltung → Verbindungen → T2med) prüfen und ob der Drittanbieter-Zugriff in T2med noch zu diesem Secret gehört; außerdem darf der Aufruf nicht älter sein: bitte frisch aus der Patientenakte öffnen.',
            $status === 404 => 'Die Adresse oder der Kontext ist T2med unbekannt. Bitte frisch aus der Patientenakte öffnen und die FHIR-Adresse prüfen.',
            $status === 0 => 'Keine Antwort von T2med.',
            default => 'Bitte erneut aus t2med öffnen.',
        };
        $detail = '';
        if (is_array($body) && ($body['resourceType'] ?? '') === 'OperationOutcome') {
            $text = (string)($body['issue'][0]['details']['text'] ?? $body['issue'][0]['diagnostics'] ?? '');
            $text = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $text) ?? '');
            if ($text !== '') $detail = ' Auskunft von T2med: ' . mb_substr($text, 0, 160);
        }
        return 'Patientenkontext konnte nicht aus t2med geladen werden (HTTP ' . $status . '). ' . $hint . $detail;
    }
    public function patient(string $context, string $token): array {
        if ($this->config->get('fhir', 'mode') === 'mock') return ['id' => 'demo-' . $context, 'nummer' => '1001', 'name' => 'Erika Musterfrau', 'birthdate' => '1980-04-12', 'email' => 'erika@example.invalid', 'address' => ['Musterstraße 1', '12345 Musterstadt']];
        try {
            $result = $this->request('GET', '/Patient?' . http_build_query(['identifier' => self::CONTEXT . '|' . $context]), $token, null, true);
        } catch (TransportError $e) {
            throw new Problem('Patient konnte nicht aus t2med geladen werden: ' . $e->getMessage(), 502);
        }
        if ($result['status'] !== 200) throw new Problem(self::contextError((int)$result['status'], $result['body']), 502);
        $body = $result['body']; $patients = [];
        if (($body['resourceType'] ?? '') === 'Patient') $patients[] = $body;
        elseif (($body['resourceType'] ?? '') === 'Bundle') {
            foreach ($body['entry'] ?? [] as $entry) if (($entry['resource']['resourceType'] ?? '') === 'Patient') $patients[] = $entry['resource'];
        }
        if (count($patients) !== 1 || empty($patients[0]['id'])) throw new Problem('Der Patientenkontext ist nicht eindeutig.', 502);
        $p = $patients[0]; $name = $p['name'][0] ?? [];
        $label = trim(implode(' ', $name['given'] ?? []) . ' ' . ($name['family'] ?? ''));
        $emails = array_filter(is_array($p['telecom'] ?? null) ? $p['telecom'] : [], static fn($contact) =>
            is_array($contact) && ($contact['system'] ?? '') === 'email' && ($contact['use'] ?? '') !== 'old' &&
            is_string($contact['value'] ?? null) && strlen($contact['value']) <= 254 && filter_var($contact['value'], FILTER_VALIDATE_EMAIL));
        usort($emails, static fn($a, $b) => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));
        $phones = array_filter(is_array($p['telecom'] ?? null) ? $p['telecom'] : [], static fn($contact) =>
            is_array($contact) && in_array($contact['system'] ?? '', ['phone', 'sms'], true) && ($contact['use'] ?? '') !== 'old' &&
            is_string($contact['value'] ?? null) && preg_match('/^[0-9 +()\/.-]{3,40}$/D', trim($contact['value'])));
        usort($phones, static fn($a, $b) => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));
        $address = [];
        foreach (is_array($p['address'] ?? null) ? $p['address'] : [] as $a) {
            if (!is_array($a) || ($a['use'] ?? '') === 'old') continue;
            $end = $a['period']['end'] ?? '';
            if (is_string($end) && $end !== '' && substr($end, 0, 10) < date('Y-m-d')) continue;
            $lines = array_filter(is_array($a['line'] ?? null) ? $a['line'] : [], 'is_string');
            $lines[] = trim((string)($a['postalCode'] ?? '') . ' ' . (string)($a['city'] ?? ''));
            $address = array_values(array_filter(array_map(static fn($line) => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $line), 0, 200), array_slice($lines, 0, 5))));
            if ($address) break;
        }
        // Patientennummer (für „Akte öffnen“ per GDT): nur eine eindeutige, gültige Nummer, sonst leer.
        $nummern = [];
        foreach (is_array($p['identifier'] ?? null) ? $p['identifier'] : [] as $i) {
            if (is_array($i) && ($i['system'] ?? '') === self::NUMMER && is_string($i['value'] ?? null) && preg_match('/^[1-9][0-9]{0,11}$/D', $i['value'])) $nummern[] = $i['value'];
        }
        $nummern = array_values(array_unique($nummern));
        return ['id' => (string)$p['id'], 'nummer' => count($nummern) === 1 ? $nummern[0] : '', 'name' => $label ?: 'Patient ' . $p['id'], 'birthdate' => $p['birthDate'] ?? '', 'email' => $emails[0]['value'] ?? '', 'given' => mb_substr(trim(implode(' ', array_filter((array)($name['given'] ?? []), 'is_string'))), 0, 80), 'family' => mb_substr(is_string($name['family'] ?? null) ? trim($name['family']) : '', 0, 80), 'gender' => in_array($p['gender'] ?? '', ['male', 'female', 'other', 'unknown'], true) ? $p['gender'] : 'unknown', 'phone' => isset($phones[0]) ? trim($phones[0]['value']) : '', 'address' => $address];
    }
    public function resource(array $visit, array $payment): array {
        $zone = new \DateTimeZone($this->config->get('app', 'timezone'));
        $paid = (new \DateTimeImmutable('@' . $payment['paid_at']))->setTimezone($zone);
        $services = json_decode($payment['services_json'], true, 32, JSON_THROW_ON_ERROR);
        $lines = array_map(static fn (array $s): string => (($s['goae_code'] ?? '') !== '' ? 'GOÄ ' . $s['goae_code'] . ', Faktor ' . str_replace('.', ',', $s['factor']) . ': ' : '') .
            $s['label'] . ' ' . Money::format($s['price_cents']) . (!empty($s['on_request']) ? ' (auf Verlangen)' : '') .
            (!empty($s['reason']) ? '; Begründung: ' . $s['reason'] : ''), $services);
        $kind = $payment['payment_method'] ?? 'card'; $cash = $kind === 'cash'; $transfer = $kind === 'transfer';
        $text = ($transfer ? 'Überweisung – ' : ($cash ? 'Barzahlung – ' : 'Kartenzahlung – ')) . Money::format($payment['amount_cents']) . "\n" . implode('; ', $lines) . "\n" .
            'Zahlung am ' . $paid->format('d.m.Y') . ' um ' . $paid->format('H:i') . ($transfer ? ' Uhr per Überweisung eingegangen.' : ($cash ? ' Uhr bar erhalten.' : ' Uhr über SumUp erfolgreich.')) . "\n" . 'Transaktionsreferenz: ' . $payment['reference'];
        if (!empty($payment['invoice_json'])) {
            $invoice = json_decode($payment['invoice_json'], true, 32, JSON_THROW_ON_ERROR);
            $text .= (($payment['doc_number'] ?? '') !== '' ? "\nRechnungsnummer: " . $payment['doc_number'] . ($cash || $transfer ? '' : "\nSumUp-Transaktion: " . ($payment['transaction_id'] ?? '')) : ($cash || $transfer ? "\nRechnungsnummer: " : "\nRechnungsnummer (SumUp): ") . ($payment['transaction_id'] ?? '')) . "\nLeistungsdatum: " . $invoice['service_date'];
        }
        return ['resourceType' => 'Observation',
            'meta' => ['profile' => ['https://fhir.t2med.de/StructureDefinition/FhirApiObservationFreitext|1.0.0']],
            'identifier' => [['system' => self::CONTEXT, 'value' => $visit['context_id']], ['system' => self::PAYMENT, 'value' => $payment['id']]],
            'extension' => [['url' => 'https://fhir.t2med.de/StructureDefinition/FhirApiFreitextKuerzel', 'valueString' => $this->config->get('fhir', 'entry_code', 'ZAHLUNG')]],
            'status' => 'final', 'effectiveDateTime' => $paid->format(DATE_ATOM), 'valueString' => $text];
    }
    public function supportsConditionalCreate(string $token): bool {
        try {
            $result = $this->request('GET', '/metadata', $token);
            if ($result['status'] !== 200 || ($result['body']['resourceType'] ?? '') !== 'CapabilityStatement') return false;
            foreach ($result['body']['rest'] ?? [] as $rest) {
                if (($rest['mode'] ?? '') !== 'server') continue;
                foreach ($rest['resource'] ?? [] as $resource) {
                    if (($resource['type'] ?? '') === 'Observation' && ($resource['conditionalCreate'] ?? false) === true) return true;
                }
            }
        } catch (TransportError) { /* Gewöhnliches POST bleibt nutzbar. Unklare Wiederholungen bleiben gesperrt. */ }
        return false;
    }
    public function findPayment(array $visit, array $payment, string $token): ?string {
        $result = $this->request('GET', '/Observation?' . http_build_query(['identifier' => self::PAYMENT . '|' . $payment['id']]), $token);
        if ($result['status'] !== 200 || ($result['body']['resourceType'] ?? '') !== 'Bundle') throw new TransportError('Der vorhandene Akteneintrag konnte nicht geprüft werden.');
        $found = [];
        foreach ($result['body']['entry'] ?? [] as $entry) {
            $resource = $entry['resource'] ?? [];
            if (($resource['resourceType'] ?? '') !== 'Observation') continue;
            $matching = false; $context = false;
            foreach ($resource['identifier'] ?? [] as $id) {
                if (($id['system'] ?? '') === self::PAYMENT && ($id['value'] ?? '') === $payment['id']) $matching = true;
                if (($id['system'] ?? '') === self::CONTEXT && ($id['value'] ?? '') === $visit['context_id']) $context = true;
            }
            if ($matching && $context && !empty($resource['id']) && ($resource['valueString'] ?? null) === $this->resource($visit, $payment)['valueString']) $found[] = 'Observation/' . $resource['id'];
        }
        if (count($found) > 1) throw new TransportError('Mehrere passende Akteneinträge gefunden. Bitte in t2med prüfen.');
        // Ein leeres Ergebnis ist KEIN Beweis, dass ein vorheriger POST nicht noch verarbeitet wird.
        return $found[0] ?? null;
    }
    public const ANHANG = 'https://fhir.t2med.de/StructureDefinition/FhirApiDocumentReferenceAnhang|1.0.0';

    /** Unterschriebenes PDF aus Signatur als DocumentReference (Profil „Anhang“) in die Akte des Kontexts (Integrationsleitfaden 1.10). */
    public function dokument(string $context, string $token, string $pdf, string $titel, int $zeit): void {
        if (!preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $context)) throw new Problem('Ungültiger T2med-Kontext.');
        $iso = date('c', $zeit);
        $titel = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $titel) ?? '') ?: 'Unterschriebenes Dokument';
        $datei = trim(preg_replace('/[^A-Za-z0-9ÄÖÜäöüß _.-]+/u', '', $titel) ?? '') ?: 'dokument';
        $result = $this->request('POST', '/DocumentReference', $token, ['resourceType' => 'DocumentReference', 'meta' => ['profile' => [self::ANHANG]],
            'identifier' => [['system' => self::CONTEXT, 'value' => $context]], 'status' => 'current', 'date' => $iso,
            'description' => mb_substr($titel . ' (unterschrieben)', 0, 200),
            'content' => [['attachment' => ['contentType' => 'application/pdf', 'title' => mb_substr($datei, 0, 80) . '.pdf', 'creation' => $iso, 'data' => base64_encode($pdf)]]]]);
        $code = $result['status'];
        $detail = ''; $error = false;
        $body = $result['body'];
        if (is_array($body) && ($body['resourceType'] ?? '') === 'OperationOutcome') foreach ($body['issue'] ?? [] as $issue) {
            if (!in_array($issue['severity'] ?? '', ['fatal', 'error'], true)) continue;
            $error = true;
            $detail = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string)($issue['details']['text'] ?? $issue['diagnostics'] ?? '')) ?? ''), 0, 160);
            break;
        }
        if ($code >= 200 && $code < 300 && !$error) return;
        $grund = match (true) {
            $code === 401, $code === 403 => 'T2med lehnt den Zugriff ab (Aufruf älter als 4 Stunden oder Schlüssel falsch).',
            $code === 413 => 'Das PDF ist für T2med zu groß.',
            default => 'T2med hat das Dokument nicht angenommen (HTTP ' . $code . ').',
        };
        throw new TransportError($grund . ($detail !== '' ? ' Auskunft von T2med: ' . $detail : ''), false, $code);
    }

    public function write(array $visit, array $payment, string $token, bool $conditional): string {
        $request = ['method' => 'POST', 'url' => 'Observation'];
        if ($conditional) $request['ifNoneExist'] = http_build_query(['identifier' => self::PAYMENT . '|' . $payment['id']]);
        $result = $this->request('POST', '', $token, ['resourceType' => 'Bundle', 'type' => 'transaction',
            'entry' => [['request' => $request, 'resource' => $this->resource($visit, $payment)]]]);
        $code = $result['status'];
        if ($code < 200 || $code >= 300) throw new TransportError('t2med hat die Dokumentation nicht bestätigt. (HTTP ' . $code . ')', $code >= 500 || $code === 408 || $code < 400, $code);
        $body = $result['body'];
        if (($body['resourceType'] ?? '') !== 'Bundle' || ($body['type'] ?? '') !== 'transaction-response' || count($body['entry'] ?? []) !== 1) throw new TransportError('t2med hat keine eindeutige Bestätigung geliefert.');
        $response = $body['entry'][0]['response'] ?? []; $status = (int)($response['status'] ?? 0);
        $error = false;
        foreach ($response['outcome']['issue'] ?? [] as $issue) if (in_array($issue['severity'] ?? '', ['fatal', 'error'], true)) $error = true;
        if ($status < 200 || $status >= 300 || $error) throw new TransportError('t2med hat den Akteneintrag abgelehnt. Bitte erneut versuchen.', !($status >= 400 && $status < 500 && $status !== 408), $status);
        $location = (string)($response['location'] ?? '');
        return preg_match('~(?:^|/)(Observation/[A-Za-z0-9.-]+)(?:/|$)~', $location, $m) ? $m[1] : '';
    }
}
