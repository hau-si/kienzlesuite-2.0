<?php
declare(strict_types=1);
// Fehlermeldung beim Patientenabruf aus t2med: HTTP-Status, Ursache und Auskunft von t2med (nur erfundene Daten).
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{Config, FhirClient, HttpClient, Problem};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

final class FakeHttp extends HttpClient {
    public function __construct(private int $status, private mixed $body) {}
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array { return ['status' => $this->status, 'body' => $this->body]; }
}
function client(int $status, mixed $body): FhirClient {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = sys_get_temp_dir() . '/ks-fhir-' . bin2hex(random_bytes(6));
    $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['sumup'] = ['mode' => 'live', 'api_key' => 'k', 'affiliate_key' => 'a', 'app_id' => 'test.app', 'merchant_code' => 'MTEST', 'reader_id' => 'reader-test'];
    $v['fhir'] = ['mode' => 'live', 'base_url' => 'https://t2med.test/aps/fhir/api/r4', 'launch_urls' => ['https://t2med.test/aps/fhir/api/r4'], 'api_key' => 'f', 'entry_code' => 'ZAHLUNG', 'ca_file' => ''];
    return new FhirClient(new Config($v), new FakeHttp($status, $body));
}
function message(int $status, mixed $body): string {
    try { client($status, $body)->patient('ctx-1', 'token'); } catch (Problem $e) { return $e->getMessage(); }
    throw new RuntimeException('Fehler nicht gemeldet');
}

$m = message(401, ['resourceType' => 'OperationOutcome', 'issue' => [['diagnostics' => 'Invalid client secret']]]);
check(str_contains($m, 'HTTP 401') && str_contains($m, 'Client Secret') && str_contains($m, 'Auskunft von T2med: Invalid client secret'), '401: ' . $m);
check(str_contains(message(404, null), 'HTTP 404') && str_contains(message(500, null), 'HTTP 500'), 'Status fehlt');
check(!str_contains($m, 'token'), 'Token in der Meldung');
echo "OK: Fehlermeldung beim Patientenabruf.\n";
