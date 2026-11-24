<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KienzleUnterschrift\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('KienzleUnterschrift\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

$daten = (string)getenv('KIENZLE_DATEN');
if (preg_match('#^/[A-Za-z0-9/_.-]{1,200}$#D', $daten) && !str_contains($daten, '..')) KienzleUnterschrift\Speicher::$data = $daten;

function us_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function us_modul(): KienzleModul {
    static $k;
    if (!$k) {
        if (!class_exists('KienzleModul', false)) {
            $helfer = (string)(getenv('KIENZLE_SUITE_HILFE') ?: '/opt/kienzle/core/current/php/modul/Kienzle.php');
            if (!is_file($helfer)) throw new RuntimeException('Kienzle-Suite nicht gefunden.');
            require_once $helfer;
        }
        $k = new KienzleModul('unterschrift');
    }
    return $k;
}
