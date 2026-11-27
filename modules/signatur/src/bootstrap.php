<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KienzleSignatur\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('KienzleSignatur\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

$daten = (string)getenv('KIENZLE_DATEN');
if (preg_match('#^/[A-Za-z0-9/_.-]{1,200}$#D', $daten) && !str_contains($daten, '..')) KienzleSignatur\Speicher::$data = $daten;

/** Webpfad des Moduls (von der Basis in KIENZLE_PFAD übergeben), für Links zwischen den Unterseiten. */
function sg_basis(): string {
    $p = (string)($_SERVER['KIENZLE_PFAD'] ?? '');
    return preg_match('#^/[a-z0-9-]{1,31}$#D', $p) ? $p : '/signatur';
}
function sg_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function sg_modul(): KienzleModul {
    static $k;
    if (!$k) {
        if (!class_exists('KienzleModul', false)) {
            $helfer = (string)(getenv('KIENZLE_SUITE_HILFE') ?: '/opt/kienzle/core/current/php/modul/Kienzle.php');
            if (!is_file($helfer)) throw new RuntimeException('Kienzle-Suite nicht gefunden.');
            require_once $helfer;
        }
        $k = new KienzleModul('signatur');
    }
    return $k;
}

/** T2med-Zugang aus der Verbindung „t2med“ der Basis (Web und Hintergrundlauf). */
function sg_fhir(): KienzleSignatur\Fhir {
    $verbindung = sg_modul()->verbindung('t2med') ?? [];
    $ca = '';
    if (trim((string)($verbindung['ca_pem'] ?? '')) !== '') {
        $ca = KienzleSignatur\Speicher::$data . '/t2med-ca.pem';
        if (!is_file($ca) || file_get_contents($ca) !== trim((string)$verbindung['ca_pem']) . "\n") { file_put_contents($ca, trim((string)$verbindung['ca_pem']) . "\n"); chmod($ca, 0600); }
    }
    return new KienzleSignatur\Fhir($verbindung, $ca);
}
