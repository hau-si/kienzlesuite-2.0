<?php
declare(strict_types=1);

// Klassen der Basis laden (eine Klasse je Datei, Namensraum Kienzle\Core).
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Kienzle\\Core\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('Kienzle\\Core\\')) . '.php';
        if (is_file($file)) require $file;
    }
});
