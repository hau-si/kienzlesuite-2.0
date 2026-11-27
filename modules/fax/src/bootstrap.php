<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KienzleFax\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('KienzleFax\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

foreach (['KIENZLE_DATEN' => 'data', 'KIENZLE_AUSTAUSCH' => 'austausch'] as $var => $feld) {
    $wert = (string)getenv($var);
    if (preg_match('#^/[A-Za-z0-9/_.-]{1,200}$#D', $wert) && !str_contains($wert, '..')) KienzleFax\Fax::$$feld = $wert;
}

/** Webpfad des Moduls (von der Basis in KIENZLE_PFAD übergeben). */
function fx_basis(): string {
    $p = (string)($_SERVER['KIENZLE_PFAD'] ?? '');
    return preg_match('#^/[a-z0-9-]{1,31}$#D', $p) ? $p : '/fax';
}
function fx_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function fx_modul(): KienzleModul {
    static $k;
    if (!$k) {
        if (!class_exists('KienzleModul', false)) {
            $helfer = (string)(getenv('KIENZLE_SUITE_HILFE') ?: '/opt/kienzle/core/current/php/modul/Kienzle.php');
            if (!is_file($helfer)) throw new RuntimeException('Praxisherz nicht gefunden.');
            require_once $helfer;
        }
        $k = new KienzleModul('fax');
    }
    return $k;
}
