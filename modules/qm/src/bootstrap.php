<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KienzleQm\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('KienzleQm\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

$daten = (string)getenv('KIENZLE_DATEN');
if (preg_match('#^/[A-Za-z0-9/_.-]{1,200}$#D', $daten) && !str_contains($daten, '..')) KienzleQm\Speicher::$data = $daten;

/** Webpfad des Moduls (von der Basis in KIENZLE_PFAD übergeben). */
function qm_basis(): string {
    $p = (string)($_SERVER['KIENZLE_PFAD'] ?? '');
    return preg_match('#^/[a-z0-9-]{1,31}$#D', $p) ? $p : '/qm';
}
function qm_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function qm_modul(): KienzleModul {
    static $k;
    if (!$k) {
        if (!class_exists('KienzleModul', false)) {
            $helfer = (string)(getenv('KIENZLE_SUITE_HILFE') ?: '/opt/kienzle/core/current/php/modul/Kienzle.php');
            if (!is_file($helfer)) throw new RuntimeException('Praxisherz-Basis nicht gefunden.');
            require_once $helfer;
        }
        $k = new KienzleModul('qm');
    }
    return $k;
}
