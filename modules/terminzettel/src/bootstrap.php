<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KienzleTerminzettel\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('KienzleTerminzettel\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

$daten = (string)getenv('KIENZLE_DATEN');
if (preg_match('#^/[A-Za-z0-9/_.-]{1,200}$#D', $daten) && !str_contains($daten, '..')) KienzleTerminzettel\Seite::$data = $daten;
if (PHP_SAPI === 'cli' && is_file((string)getenv('KIENZLE_SUITE_HILFE'))) KienzleTerminzettel\Seite::$helper = (string)getenv('KIENZLE_SUITE_HILFE');

function tz_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function tz_modul(): KienzleModul {
    static $k;
    if (!$k) {
        if (!class_exists('KienzleModul', false)) {
            if (!is_file(KienzleTerminzettel\Seite::$helper)) throw new RuntimeException('DocLibre nicht gefunden.');
            require_once KienzleTerminzettel\Seite::$helper;
        }
        $k = new KienzleModul('terminzettel');
    }
    return $k;
}
