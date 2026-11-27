<?php
declare(strict_types=1);
// Einstieg für alle Anfragen an die Basis (nginx → php-fpm, Pool ks-core).
require dirname(__DIR__) . '/php/bootstrap.php';

use Kienzle\Core\{AuditLog, Auth, Backup, Oidc, Printers, Config, Connections, Database, Firewall, ModuleAdmin, Modules, Network, Passkeys, Request, Secrets, Starter, Updates, Users, Web};

try {
    $config = Config::load(getenv('KIENZLE_CORE_CONFIG') ?: Config::FILE);
    $db = new Database($config->dataDir);
    $db->migrate();
    $modules = new Modules($config->modules);
    $secrets = Secrets::fromFile($config->secretKey);
    $users = new Users($db, $secrets, $modules);
    $audit = new AuditLog($config->dataDir);
    $request = Request::fromGlobals();
    $rpId = Passkeys::rpIdFrom($config);
    $web = new Web($config, $db, $users, new Auth($db, $users, $audit, $request->remote), $rpId ? new Passkeys($db, $rpId, 'Praxisherz') : null, $modules, $audit,
        null, new Firewall($db, new Network(), $modules), new Updates($db, $config), new Connections($db), new Backup($db, $modules), null, new Printers($db), new Oidc($db, $users, $secrets, $config), store: new ModuleAdmin($db), starter: new Starter($db, $secrets, $config, dirname(__DIR__)));
    $web->handle($request)->send();
} catch (Throwable $e) {
    // Keine Einzelheiten an den Browser; ins Protokoll Art, Ort und die (gekürzte) Meldung der Ausnahme, keine Eingaben der Anfrage.
    $msg = substr((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $e->getMessage()), 0, 300);
    error_log('kienzle-core: ' . get_class($e) . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . ($msg !== '' ? ' – ' . $msg : '') . ' [' . ($_SERVER['REQUEST_METHOD'] ?? '-') . ' ' . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') . ']');
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Interner Fehler Praxisherz. Details im Systemprotokoll (journalctl -t kienzle-core-php).\n";
}
