<?php
declare(strict_types=1);
// Zahlungsversuch verwerfen: nur bei nicht erfolgter/abgebrochener Zahlung ohne Rechnungsnummer (IGeL).
require dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSumup\{App, Config, FhirClient, HttpClient, InvoiceReceipt, Problem, TaxExport};

function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function problem(callable $fn, string $message, int $http): Problem {
    try { $fn(); } catch (Problem $e) { check($e->http === $http, $message . ' (HTTP ' . $e->http . ': ' . $e->getMessage() . ')'); return $e; }
    throw new RuntimeException($message);
}
final class NoNetwork extends HttpClient {
    public function request(string $method, string $url, array $headers, ?array $body = null, string $caFile = '', bool $pinCertificate = false): array { throw new RuntimeException('Barzahlung darf nichts an SumUp senden: ' . $url); }
}
function config(string $state, bool $practice = true): Config {
    $v = Config::parse(file_get_contents(dirname(__DIR__) . '/config/kienzle-sumup.example.toml'));
    $v['app']['state_dir'] = $state; $v['app']['launcher_key'] = str_repeat('a', 64); $v['app']['encryption_key'] = str_repeat('b', 64);
    $v['sumup'] = ['mode' => 'mock']; $v['fhir'] = ['mode' => 'mock', 'entry_code' => 'ZAHLUNG'];
    if ($practice) $v['practice'] = ['name' => 'Praxis Beispiel', 'street' => 'Musterstraße 1', 'city' => '12345 Musterstadt'];
    return new Config($v);
}
function text(string $svg): string { return (string)preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('</text>', "\n", $svg)), ENT_XML1, 'UTF-8')); }

$dirs = [];
try {
    $state = sys_get_temp_dir() . '/ks-discard-' . bin2hex(random_bytes(6)); $dirs[] = $state; mkdir($state, 0700);
    $app = new App(config($state), new NoNetwork());
    $app->db->migrate();
    $visit = function () use ($app): array {
        $launch = $app->launch(['context_id' => 'ctx-' . bin2hex(random_bytes(4)), 'oauth_token' => '', 'fhir_base_url' => '']);
        parse_str(parse_url($launch['url'], PHP_URL_FRAGMENT), $f); $e = $app->exchange($f['launch'], null);
        return $app->visit($app->browser($e['cookie']), $e['visit_id']);
    };
    $v = $visit();
    $app->saveService($v, ['label' => 'Attest', 'price' => '15,00']);
    $ids = $app->db->query('SELECT id FROM services')->fetchAll(PDO::FETCH_COLUMN);

    // Karte gestartet, abgebrochen: Verwerfen ist möglich, danach ist der Vorgang wieder leer.
    $card = $app->start($v, ['services' => $ids, 'request_id' => App::id()]);
    $cancelled = $app->cancel($v, $card['id']);
    check($cancelled['payment_status'] === 'cancelled' && $cancelled['discardable'] === true, 'Abgebrochene Karte nicht verwerfbar');
    check($app->state($v, ['csrf' => 'x'])['payment']['id'] === $card['id'], 'Abgebrochene Zahlung nicht sichtbar');
    check($app->discard($v, $card['id']) === ['discarded' => true], 'Verwerfen ohne Ergebnis');
    check($app->state($v, ['csrf' => 'x'])['payment'] === null, 'Verworfene Zahlung noch sichtbar');
    check($app->db->one('SELECT payment_status FROM payments WHERE id=?', [$card['id']])['payment_status'] === 'discarded', 'Datensatz nicht erhalten');
    // Neuer Versuch geht danach wieder.
    $again = $app->start($v, ['services' => $ids, 'request_id' => App::id(), 'method' => 'cash']);
    check($again['payment_status'] === 'successful' && $again['discardable'] === false, 'Barzahlung mit Rechnungsnummer darf nicht verwerfbar sein');
    problem(fn() => $app->discard($v, $again['id']), 'Bezahlte Zahlung verworfen', 409);
    echo "Verwerfen: grün.\n";
} finally {
    foreach ($dirs as $d) if (is_dir($d)) { foreach (glob($d . '/*') ?: [] as $f) @unlink($f); @rmdir($d); }
}
