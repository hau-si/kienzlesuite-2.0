<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'KienzleTelefon\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('KienzleTelefon\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

$daten = (string)getenv('KIENZLE_DATEN');
if (preg_match('#^/[A-Za-z0-9/_.-]{1,200}$#D', $daten) && !str_contains($daten, '..')) KienzleTelefon\Anlage::$data = $daten;

/** Webpfad des Moduls (von der Basis in KIENZLE_PFAD übergeben). */
function tf_basis(): string {
    $p = (string)($_SERVER['KIENZLE_PFAD'] ?? '');
    return preg_match('#^/[a-z0-9-]{1,31}$#D', $p) ? $p : '/telefon';
}
function tf_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function tf_modul(): KienzleModul {
    static $k;
    if (!$k) {
        if (!class_exists('KienzleModul', false)) {
            $helfer = (string)(getenv('KIENZLE_SUITE_HILFE') ?: '/opt/kienzle/core/current/php/modul/Kienzle.php');
            if (!is_file($helfer)) throw new RuntimeException('Praxisherz nicht gefunden.');
            require_once $helfer;
        }
        // Tests legen vorab ein Objekt mit eigenem Schlüsselordner an (tests/seiten.php); im Betrieb gibt es das nicht.
        $k = ($GLOBALS['tf_test_modul'] ?? null) instanceof KienzleModul ? $GLOBALS['tf_test_modul'] : new KienzleModul('telefon');
    }
    return $k;
}

/** Ist der Austauschordner mit dem Modul fax da (beide Module installiert)? */
function tf_fax_bereit(): bool { return is_dir((string)(getenv('KIENZLE_AUSTAUSCH') ?: '/var/spool/kienzle/austausch/fax_telefon')); }
