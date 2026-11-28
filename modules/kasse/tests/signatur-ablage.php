<?php
declare(strict_types=1);
// Unterschriebene PDFs aus Signatur über den Austauschordner in T2med ablegen (DocumentReference). Nur erfundene Daten.
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleKasse\{App, Config, HttpClient, TransportError};

function check(bool $ok, string $msg): void { if (!$ok) { fwrite(STDERR, "FEHLER: $msg\n"); exit(1); } }

final class AblageHttp extends HttpClient {
    public array $posts = []; public array $eintraege = []; public string $eintragStatus = '201 Created';
    public int $status = 201;
    public ?array $antwort = ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'information']]];
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array {
        if (str_contains($url, '/Patient?')) return ['status' => 200, 'body' => ['resourceType' => 'Patient', 'id' => 'patient-1', 'name' => [['given' => ['Max'], 'family' => 'Mustermann']]]];
        if ($method === 'POST' && str_ends_with($url, '/DocumentReference')) { $this->posts[] = [$headers, $body]; return ['status' => $this->status, 'body' => $this->antwort]; }
        if ($method === 'POST' && str_ends_with($url, '/r4')) { $this->eintraege[] = $body; return ['status' => 200, 'body' => ['resourceType' => 'Bundle', 'type' => 'transaction-response', 'entry' => [['response' => ['status' => $this->eintragStatus]]]]]; }
        throw new TransportError('Unerwarteter Testaufruf ' . $method, false);
    }
}

$v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-kasse.example.toml'));
$v['app']['state_dir'] = sys_get_temp_dir() . '/ks-sig-' . bin2hex(random_bytes(5));
$v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
$v['sumup'] = ['mode' => 'live', 'api_key' => 'sumup-test', 'affiliate_key' => 'affiliate-test', 'app_id' => 'test.app', 'merchant_code' => 'MTEST', 'reader_id' => 'reader-test'];
$v['fhir'] = ['mode' => 'live', 'base_url' => 'https://t2med.example.org/aps/fhir/api/r4', 'launch_urls' => ['https://t2med.example.org/aps/fhir/api/r4'], 'api_key' => 'fhir-test', 'entry_code' => 'ZAHLUNG', 'ca_file' => ''];
$http = new AblageHttp();
$app = new App(new Config($v), $http);
$app->db->migrate();
$aus = sys_get_temp_dir() . '/ks-austausch-' . bin2hex(random_bytes(5));
mkdir("$aus/an-kasse", 0770, true);
register_shutdown_function(static function () use ($v, $aus) { exec('rm -rf ' . escapeshellarg($v['app']['state_dir']) . ' ' . escapeshellarg($aus)); });

$besuch = function (string $kontext) use ($app): array {
    $launch = $app->launch(['context_id' => $kontext, 'oauth_token' => 'token-' . $kontext, 'fhir_base_url' => 'https://t2med.example.org/aps/fhir/api/r4']);
    parse_str((string)parse_url($launch['url'], PHP_URL_FRAGMENT), $f);
    $x = $app->exchange($f['launch'], null);
    return $app->visit($app->browser($x['cookie']), $x['visit_id']);
};
$auftrag = function (int $vid, string $ref, string $pdf = "%PDF-1.4 erfunden", array $mehr = []) use ($aus): void {
    file_put_contents("$aus/an-kasse/$vid.pdf", $pdf);
    file_put_contents("$aus/an-kasse/$vid.json", json_encode(['vorgang' => $vid, 'ref' => $ref, 'titel' => 'IGeL-Vertrag', 'zeit' => 1795000000] + $mehr));
};
$antwort = fn(int $vid) => json_decode((string)@file_get_contents("$aus/von-kasse/$vid.json"), true);

// Übergabe vormerken: Der Zugang bleibt nach dem Kassieren, bis das PDF abgelegt ist.
$b1 = $besuch('kontext-a');
check($app->signaturOffen($b1) === ['ablage' => true] && (int)$app->db->one('SELECT signatur_offen FROM visits WHERE id=?', [$b1['id']])['signatur_offen'] === 1, 'Übergabe vorgemerkt');
$app->db->query('UPDATE visits SET completed_at=? WHERE id=?', [time(), $b1['id']]);

$auftrag(7, $b1['id']);
$app->signaturAblage($aus);
$a = $antwort(7);
check($a === ['vorgang' => 7, 'ok' => true, 'meldung' => ''], 'Erfolg zurückgemeldet');
check((fileperms("$aus/von-kasse/7.json") & 0777) === 0660 && !is_file("$aus/an-kasse/7.json") && !is_file("$aus/an-kasse/7.pdf"), 'Antwort 0660, Auftrag entfernt');
[$kopf, $res] = $http->posts[0];
check(in_array('Authorization: Bearer token-kontext-a', $kopf, true) && $res['resourceType'] === 'DocumentReference'
    && $res['meta']['profile'] === ['https://fhir.t2med.de/StructureDefinition/FhirApiDocumentReferenceAnhang|1.0.0']
    && $res['identifier'][0] === ['system' => 'https://fhir.t2med.de/identifier/kontext', 'value' => 'kontext-a'] && $res['description'] === 'IGeL-Vertrag (unterschrieben)'
    && $res['content'][0]['attachment']['contentType'] === 'application/pdf' && base64_decode($res['content'][0]['attachment']['data']) === '%PDF-1.4 erfunden', 'DocumentReference mit Kontext und PDF');
$nach = $app->db->one('SELECT oauth_cipher, signatur_offen FROM visits WHERE id=?', [$b1['id']]);
check($nach['oauth_cipher'] === '' && (int)$nach['signatur_offen'] === 0, 'Nach dem Ablegen eines abgeschlossenen Besuchs: Zugang gelöscht');

// Zugang abgelaufen: ein anderer gültiger Besuch desselben Patienten springt ein; sonst Fehlermeldung.
$auftrag(8, $b1['id']);
$app->signaturAblage($aus);
check($antwort(8)['ok'] === false && str_contains($antwort(8)['meldung'], 'abgelaufen'), 'ohne gültigen Zugang: Fehler mit Hinweis');
$b2 = $besuch('kontext-b');
$auftrag(9, $b1['id']);
$app->signaturAblage($aus);
check($antwort(9)['ok'] === true && in_array('Authorization: Bearer token-kontext-b', end($http->posts)[0], true) && end($http->posts)[1]['identifier'][0]['value'] === 'kontext-b', 'Ersatz: gültiger Besuch desselben Patienten');

// T2med lehnt ab; ungültige Aufträge
$http->status = 422; $http->antwort = ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'error', 'diagnostics' => 'description fehlt']]];
$auftrag(10, $b2['id']);
$app->signaturAblage($aus);
check($antwort(10)['ok'] === false && str_contains($antwort(10)['meldung'], 'HTTP 422') && str_contains($antwort(10)['meldung'], 'description fehlt'), 'Ablehnung mit Auskunft');
$auftrag(11, '../../etc');
$auftrag(12, 'unbekannt');
$auftrag(13, $b2['id'], 'kein PDF');
file_put_contents("$aus/an-kasse/kaputt.json", '{}');
$app->signaturAblage($aus);
check(!$antwort(11)['ok'] && !$antwort(12)['ok'] && str_contains($antwort(12)['meldung'], 'nicht mehr vorhanden') && !$antwort(13)['ok'] && str_contains($antwort(13)['meldung'], 'PDF'), 'ungültige Aufträge abgelehnt');
check(glob("$aus/an-kasse/*") === [], 'Auftragsordner geleert (keine Endlosschleife des Pfad-Dienstes)');
check(count($http->posts) === 3, 'nur gültige Aufträge an T2med');
check($http->eintraege === [], 'ohne Akteneintrag im Auftrag kein Freitext');

// Akteneintrag aus Signatur: nach dem PDF als Freitext mit Kürzel; Ablehnung nur als Hinweis (PDF bleibt abgelegt)
$http->status = 201; $http->antwort = ['resourceType' => 'OperationOutcome', 'issue' => [['severity' => 'information']]];
$auftrag(14, $b2['id'], "%PDF-1.4 erfunden", ['kuerzel' => 'EINW', 'akte' => "IGeL-Vertrag:\nEinwilligung: ja"]);
$app->signaturAblage($aus);
$obs = $http->eintraege[0]['entry'][0]['resource'] ?? [];
check($antwort(14) === ['vorgang' => 14, 'ok' => true, 'meldung' => ''] && $obs['resourceType'] === 'Observation' && $obs['extension'][0]['valueString'] === 'EINW'
    && $obs['valueString'] === "IGeL-Vertrag:\nEinwilligung: ja" && $obs['identifier'][0]['value'] === 'kontext-b', 'Akteneintrag mit Kürzel');
$http->eintragStatus = '422';
$auftrag(15, $b2['id'], "%PDF-1.4 erfunden", ['kuerzel' => 'mit Leer', 'akte' => 'x']);
$app->signaturAblage($aus);
check($antwort(15)['ok'] === true && str_contains($antwort(15)['meldung'], 'Akteneintrag nicht') && end($http->eintraege)['entry'][0]['resource']['extension'][0]['valueString'] === 'SIGN', 'Ablehnung des Eintrags als Hinweis, ungültiges Kürzel → SIGN');

// Patientenübergabe aus Signatur: einmalig, höchstens 5 Minuten alt, Zugang endet mit dem ursprünglichen Aufruf
mkdir("$aus/uebergabe-kasse", 0770);
$code = str_repeat('ab', 16);
$bis = time() + 1800;
file_put_contents("$aus/uebergabe-kasse/$code.json", json_encode(['kontext' => 'kontext-ueb', 'token' => 'token-ueb', 'bis' => $bis, 'zeit' => time(), 'von' => 'signatur']));
$r = $app->uebergabeEinloesen($code, $aus);
check(str_contains($r['url'], '#launch=') && !is_file("$aus/uebergabe-kasse/$code.json"), 'Übergabe eingelöst, Datei gelöscht');
$v = $app->db->one("SELECT * FROM visits WHERE context_id=?", ["kontext-ueb"]);
check($v && (int)$v['expires_at'] <= $bis && (int)$v['expires_at'] > time() && $v['oauth_cipher'] !== '', 'Besuch mit Zugang höchstens bis zum ursprünglichen Ablauf');
$zweimal = false; try { $app->uebergabeEinloesen($code, $aus); } catch (\KienzleKasse\Problem $e) { $zweimal = $e->http === 410; }
check($zweimal, 'Übergabe nur einmal');
file_put_contents("$aus/uebergabe-kasse/$code.json", json_encode(['kontext' => 'kontext-ueb', 'token' => 'token-ueb', 'zeit' => time() - 900]));
$alt = false; try { $app->uebergabeEinloesen($code, $aus); } catch (\KienzleKasse\Problem $e) { $alt = $e->http === 410; }
check($alt && !is_file("$aus/uebergabe-kasse/$code.json"), 'alte Übergabe abgelehnt und gelöscht');
$falsch = false; try { $app->uebergabeEinloesen('../x', $aus); } catch (\KienzleKasse\Problem) { $falsch = true; }
check($falsch, 'ungültiger Code');

// Ohne T2med (manuelle Kasse) kein Vormerken
check($app->signaturOffen(['id' => 'x', 'patient_id' => 'manual', 'context_id' => 'manual-1', 'oauth_cipher' => '']) === ['ablage' => false], 'manuell: nichts vormerken');
echo "Signatur-Ablage der Kasse: alles in Ordnung\n";
