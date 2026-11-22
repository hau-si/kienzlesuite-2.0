<?php
declare(strict_types=1);
// Eine Seite des Moduls wie unter nginx/php-fpm aufrufen (für tests/suite.php):
//   php tests/page.php <auftrag.json>   Auftrag: root (aus suite()), server ($_SERVER), body, cookies
// Gibt die Antwort aus, danach eine Zeile „HTTP <code>“.
$job = json_decode((string)file_get_contents($argv[1]), true);
require_once __DIR__ . '/lib.php';
$root = $job['root'];
$_SERVER = $job['server'] + ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '192.0.2.10'];
$_COOKIE = $job['cookies'] ?? [];
$_GET = $job['get'] ?? [];
$GLOBALS['KS_TEST_BODY'] = $job['body'] ?? '';
KienzleSumup\Suite::use(new KienzleModul('sumup', $_SERVER, "$root/verb", "$root/keys", "$root/drucker.json"), "$root/daten");
register_shutdown_function(static function (): void { echo "\nHTTP ", http_response_code() ?: 200, "\n"; });
require dirname(__DIR__) . '/public/index.php';
