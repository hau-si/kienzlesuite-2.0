<?php
declare(strict_types=1);
// Haken des Moduls (laufen als ks-homepage, nie als root): install, update, remove, busy, health.
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleHomepage\Speicher;

$haken = (string)($argv[1] ?? '');
try {
    if ($haken === 'install' || $haken === 'update') {
        Speicher::pdo();
        echo "Homepage bereit.\n";
        exit(0);
    }
    if ($haken === 'remove' || $haken === 'busy') exit(0); // Daten bleiben erhalten
    if ($haken === 'health') {
        foreach (['pdo_sqlite', 'curl', 'zip', 'mbstring', 'sodium'] as $ext) {
            if (!extension_loaded($ext)) { echo "PHP-Erweiterung fehlt: $ext\n"; exit(1); }
        }
        Speicher::pdo()->query('SELECT COUNT(*) FROM mitteilungen')->fetchColumn();
        exit(0);
    }
    echo "Unbekannter Haken.\n";
    exit(2);
} catch (Throwable $e) {
    echo 'Fehler: ' . $e->getMessage() . "\n";
    exit(1);
}
