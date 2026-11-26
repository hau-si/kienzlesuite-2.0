<?php
declare(strict_types=1);
// Haken des Moduls fax (laufen als ks-fax, nie als root): install, update, remove, busy, health.
require_once dirname(__DIR__) . '/src/bootstrap.php';
use KienzleFax\Fax;

$haken = (string)($argv[1] ?? '');
try {
    if ($haken === 'install' || $haken === 'update') {
        Fax::druckerDateiSchreiben();                         // vor der Datenbank: ohne Telefonbuch gilt ein Drucker, mit Telefonbuch bleiben vFax1 bis vFax5
        foreach (['drucker', 'protokoll', 'fehler', 'fehler-original', 'eingang'] as $o) Fax::ordner($o);
        Fax::db();
        echo "Fax bereit.\n";
        exit(0);
    }
    if ($haken === 'pflege') {                            // Zeitgeber jede Minute (systemd/kienzle-fax-pflege.timer)
        $r = Fax::pflege(fx_modul());
        if (array_sum(array_filter($r, 'is_int')) > 0) echo json_encode($r), "\n";
        exit(0);
    }
    if ($haken === 'remove' || $haken === 'busy') exit(0);   // laufende Aufträge sendet das Modul telefon weiter
    if ($haken === 'health') {
        foreach (['pdo_sqlite', 'mbstring'] as $ext) if (!extension_loaded($ext)) { echo "PHP-Erweiterung fehlt: $ext\n"; exit(1); }
        Fax::db()->query('SELECT COUNT(*) FROM kontakte')->fetchColumn();
        exit(0);
    }
    echo "Unbekannter Haken.\n";
    exit(2);
} catch (Throwable $e) {
    echo 'Fehler: ' . $e->getMessage() . "\n";
    exit(1);
}
