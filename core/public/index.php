<?php
declare(strict_types=1);
// Einstieg für alle Anfragen an die Basis (nginx → php-fpm, Pool ks-core).
require dirname(__DIR__) . '/php/bootstrap.php';

use Kienzle\Core\{AuditLog, Auth, Config, Connections, Database, Firewall, Modules, Network, Passkeys, Request, Secrets, Updates, Users, Web};

try {
    $config = Config::load(getenv('KIENZLE_CORE_CONFIG') ?: Config::FILE);
    $db = new Database($config->dataDir);
    $db->migrate();
    $modules = new Modules($config->modules);
    $users = new Users($db, Secrets::fromFile($config->secretKey), $modules);
    $audit = new AuditLog($config->dataDir);
    $request = Request::fromGlobals();
    $rpId = Passkeys::rpIdFrom($config);
    $web = new Web($config, $db, $users, new Auth($db, $users, $audit, $request->remote), $rpId ? new Passkeys($db, $rpId, 'Kienzle-Suite') : null, $modules, $audit,
        null, new Firewall($db, new Network(), $modules), new Updates($db, $config), new Connections($db));
    $web->handle($request)->send();
} catch (Throwable $e) {
    // Keine Einzelheiten an den Browser; ins Protokoll nur Art und Ort, keine Eingaben.
    error_log('kienzle-core: ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Interner Fehler der Kienzle-Suite. Details im Systemprotokoll (journalctl -t kienzle-core-php).\n";
}
