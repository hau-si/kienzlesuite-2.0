<?php
declare(strict_types=1);
// Gemeinsame Hilfen der Tests: Kasse wie in der Suite, mit der echten Hilfsdatei der Basis (core/php/modul/Kienzle.php)
// und Ordnern im Temp-Verzeichnis statt /etc/kienzle und /var/lib/kienzle. Nur Beispieldaten.
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__, 3) . '/core/php/modul/Kienzle.php';
use KienzleKasse\Suite;

const TEST_CSRF = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

/** Angemeldet als Admin des Moduls (frisch bestätigt). */
function admin_server(array $extra = []): array {
    return $extra + ['KIENZLE_BENUTZER' => 'anna', 'KIENZLE_NAME' => 'Anna%20Beispiel', 'KIENZLE_ROLLE' => 'admin', 'KIENZLE_RECHTE' => '',
        'KIENZLE_CSRF' => TEST_CSRF, 'KIENZLE_BESTAETIGT' => (string)time(), 'KIENZLE_PFAD' => '/kasse', 'HTTP_HOST' => 'kasse.example.org'];
}

/** Angemeldet als Mitarbeiter mit den genannten Rechten. */
function staff_server(array $rights): array {
    return ['KIENZLE_BENUTZER' => 'ben', 'KIENZLE_NAME' => 'Ben', 'KIENZLE_ROLLE' => 'mitarbeiter', 'KIENZLE_RECHTE' => implode(',', $rights),
        'KIENZLE_CSRF' => TEST_CSRF, 'KIENZLE_BESTAETIGT' => '0', 'KIENZLE_PFAD' => '/kasse', 'HTTP_HOST' => 'kasse.example.org'];
}

/**
 * Suite-Umgebung anlegen. $verbindungen wie /etc/kienzle/verbindungen/module/kasse.json („ablage.ausgang“ wird ersetzt),
 * $drucker: Warteschlangen der Suite. Liefert den Ordner; Daten des Moduls in <ordner>/daten.
 */
function suite(array $server, array $verbindungen = [], array $drucker = [], ?string $data = null): string {
    $root = sys_get_temp_dir() . '/ks-suite-' . bin2hex(random_bytes(5));
    foreach (['verb', 'keys', 'daten', 'ausgang'] as $d) mkdir("$root/$d", 0700, true);
    file_put_contents("$root/keys/kasse.key", str_repeat('c', 64));
    if (isset($verbindungen['ablage'])) $verbindungen['ablage']['ausgang'] = "$root/ausgang";
    file_put_contents("$root/verb/kasse.json", json_encode($verbindungen));
    file_put_contents("$root/drucker.json", json_encode(['warteschlangen' => $drucker]));
    foreach ($server as $k => $v) $_SERVER[$k] = $v;
    Suite::use(new KienzleModul('kasse', $server, "$root/verb", "$root/keys", "$root/drucker.json", static fn(array $args, string $data) => ['code' => 0, 'output' => 'request id is ' . $args[2] . '-1 (1 file(s))', 'stderr' => '']), $data ?? "$root/daten");
    if ($data === null) Suite::ensureGrundwerte();
    return $root;
}

function rmtree(string $dir): void { if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir)); }
