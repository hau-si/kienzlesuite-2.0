<?php
declare(strict_types=1);
// Haken des Moduls telefon (laufen als ks-telefon, nie als root): install, update, remove, busy, health.
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleTelefon\Anlage;

$haken = (string)($argv[1] ?? '');
try {
    if ($haken === 'install' || $haken === 'update') {
        Anlage::python('status', null);   // prüft, dass python3 und der Generator laufen
        // Nach einem Update die Asterisk-Dateien aus den gespeicherten Einstellungen neu erzeugen (Vorlagen können sich ändern).
        if (Anlage::eingerichtet()) Anlage::anwenden(tf_modul());
        echo "Telefon bereit.\n";
        exit(0);
    }
    if ($haken === 'remove') exit(0);   // Einstellungen bleiben im Datenordner
    if ($haken === 'busy') {
        // Kein Update während eines Gesprächs oder eines Faxversands.
        $s = Anlage::status();
        exit(($s['gespraeche'] ?? 0) > 0 ? 75 : 0);
    }
    if ($haken === 'health') {
        if (!extension_loaded('mbstring') || !function_exists('sodium_crypto_secretbox')) { echo "PHP-Erweiterung fehlt.\n"; exit(1); }
        if (!is_executable('/usr/sbin/asterisk')) { echo "Asterisk fehlt (Paket asterisk).\n"; exit(1); }
        Anlage::python('status', null);
        exit(0);
    }
    echo "Unbekannter Haken.\n";
    exit(2);
} catch (Throwable $e) {
    echo 'Fehler: ' . $e->getMessage() . "\n";
    exit(1);
}
