<?php
declare(strict_types=1);
// Haken des Moduls (laufen als ks-signatur, nie als root): install, update, remove, busy, health.
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleSignatur\Speicher;

$haken = (string)($argv[1] ?? '');
try {
    if ($haken === 'install' || $haken === 'update') {
        Speicher::pdo();
        echo "Signatur bereit.\n";
        exit(0);
    }
    if ($haken === 'ablage') {
        // Unterschriebene PDFs in T2med ablegen (direkt oder über die Kasse); vom Pad-Dienst angestoßen.
        $a = (string)getenv('KIENZLE_AUSTAUSCH');
        if (preg_match('#^/[A-Za-z0-9/_.-]{1,200}$#D', $a) && !str_contains($a, '..')) \KienzleSignatur\Ablage::$austausch = $a;
        foreach (\KienzleSignatur\Ablage::lauf(static fn() => sg_fhir()) as $zeile) echo $zeile, "\n";
        exit(0);
    }
    if ($haken === 'remove' || $haken === 'busy') exit(0); // Daten bleiben; offene Vorgänge liegen in der Datenbank
    if ($haken === 'health') {
        foreach (['pdo_sqlite', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) { echo "PHP-Erweiterung fehlt: $ext\n"; exit(1); }
        }
        Speicher::pdo()->query('SELECT COUNT(*) FROM vorlagen')->fetchColumn();
        exit(0);
    }
    echo "Unbekannter Haken.\n";
    exit(2);
} catch (Throwable $e) {
    echo 'Fehler: ' . $e->getMessage() . "\n";
    exit(1);
}
