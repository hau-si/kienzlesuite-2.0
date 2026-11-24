<?php
declare(strict_types=1);
// Gemeinsame Hilfen für Tests mit Verbindungen (Status-Datei, Anfragen des Webinterfaces).
require_once __DIR__ . '/lib.php';

use Kienzle\Core\Connections;

function con_env(array $status = []): array {
    $env = new Env();
    Connections::$state = $env->dir . '/verb'; mkdir(Connections::$state);
    Connections::$unit = $env->dir . '/kienzle-verbindungen.path'; touch(Connections::$unit);
    file_put_contents(Connections::$state . '/status.json', json_encode($status + [
        't2med' => ['base_url' => 'https://t2med.example.org:16567/fhir', 'launch_urls' => ['https://t2med.example.org:16567/fhir'], 'pin' => false, 'api_key_gesetzt' => true, 'zertifikat' => ''],
        'mail' => null, 'ablage' => [['id' => 'steuerbuero', 'name' => 'Steuer<b>büro', 'art' => 'smb', 'host' => 'nas.example.org', 'freigabe' => 'praxis', 'ordner' => 'Kienzle', 'passwort_gesetzt' => true]],
        'tests' => ['t2med' => ['ok' => true, 'meldung' => 'FHIR-Server antwortet', 'zeit' => time()]],
        'nutzung' => [['modul' => 'beispiel', 'name' => 'Beispielmodul', 'verbindung' => 't2med', 'zweck' => 'Patient lesen', 'pflicht' => true]],
        'letzte' => ['ok' => false, 'meldung' => 'Fehler <script>', 'zeit' => time()], 'sftp_schluessel' => 'ssh-ed25519 AAAATEST ks-ablage']));
    return [$env, new Connections($env->db)];
}

function req_v(Env $env): array {
    $f = $env->dir . '/anfragen/verbindungen.json';
    $r = json_decode((string)file_get_contents($f), true);
    check(substr(sprintf('%o', fileperms($f)), -3) === '600', 'Anfrage mit Passwörtern nicht 0600');
    unlink($f);
    return $r;
}
