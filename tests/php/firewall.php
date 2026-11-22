<?php
declare(strict_types=1);
// Netzwerkabfrage und Firewall im Webinterface (ohne echten root-Dienst). Übernommen aus kienzle-suite-beta (tests/firewall.php).
// Nur Platzhalter-Adressen: 192.0.2.0/24 gilt hier als Praxisnetz, 203.0.113.0/24 und 198.51.100.0/24 als fremde Netze.
// Die Bereichs-Konstanten (10.0.0.0/8 usw.) prüfen nur die Erkennung privater Netze.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\{Firewall, Network};

Network::$private[] = '192.0.2.0/24';

function fw_env(): array {
    $env = new Env();
    Firewall::$state = $env->dir . '/fw-state'; mkdir(Firewall::$state);
    Firewall::$unit = $env->dir . '/kienzle-firewall.path';
    $answers = [
        'addr' => json_encode([['ifname' => 'lo', 'operstate' => 'UNKNOWN', 'addr_info' => [['family' => 'inet', 'local' => '127.0.0.1', 'prefixlen' => 8, 'scope' => 'host']]],
            ['ifname' => 'ens18', 'operstate' => 'UP', 'addr_info' => [['family' => 'inet', 'local' => '192.0.2.5', 'prefixlen' => 24, 'scope' => 'global'], ['family' => 'inet6', 'local' => 'fe80::1', 'prefixlen' => 64, 'scope' => 'link']]],
            ['ifname' => 'wg0', 'operstate' => 'UNKNOWN', 'addr_info' => [['family' => 'inet', 'local' => '203.0.113.7', 'prefixlen' => 32, 'scope' => 'global']]]]),
        'route' => json_encode([['dst' => 'default', 'gateway' => '192.0.2.1', 'dev' => 'ens18']]),
        'neigh' => json_encode([['dst' => '192.0.2.20', 'dev' => 'ens18', 'state' => ['REACHABLE']], ['dst' => '192.0.2.99', 'dev' => 'ens18', 'state' => ['FAILED']]]),
        'ss' => "LISTEN 0 4096 0.0.0.0:443 0.0.0.0:*\nLISTEN 0 128 127.0.0.1:631 0.0.0.0:*\nLISTEN 0 128 0.0.0.0:22 0.0.0.0:*\n",
    ];
    $net = new Network(function (array $c) use ($answers): ?string {
        if (str_ends_with($c[0], 'ss')) return $answers['ss'];
        return match ($c[2] ?? '') { 'addr' => $answers['addr'], 'route' => $answers['route'], 'neigh' => $answers['neigh'], default => null };
    });
    return [$env, new Firewall($env->db, $net, $env->modules), $net];
}

function test_netzadressen(): void {
    check(Network::normalize('192.0.2.77/24') === '192.0.2.0/24', 'normalize v4');
    check(Network::normalize('fd12:3456::1/48') === 'fd12:3456::/48', 'normalize v6');
    check(Network::normalize('192.0.2.0/33') === null && Network::normalize('abc') === null && Network::normalize('10.0.0.0/8/1') === null, 'ungültige Netze');
    check(Network::contains('192.0.2.0/24', '192.0.2.200') && !Network::contains('192.0.2.0/24', '198.51.100.1'), 'contains v4');
    check(Network::contains('192.0.2.0/24', '::ffff:192.0.2.5'), 'IPv4-mapped nicht erkannt');
    check(Network::contains('fd12::/16', 'fd12:1::5') && !Network::contains('fd12::/16', 'fd13::1'), 'contains v6');
    foreach (['10.1.0.0/16', '172.16.0.0/12', '172.31.5.0/24', '192.168.0.0/16', '100.64.0.0/10', 'fd00::/8'] as $p) check(Network::isPrivate($p), "{$p} nicht privat");
    foreach (['0.0.0.0/0', '203.0.113.0/24', '172.32.0.0/16', '192.169.0.0/24', '10.0.0.0/7', '::/0', '2001:db8::/32'] as $p) check(!Network::isPrivate($p), "{$p} fälschlich privat");
}

function test_netzwerkabfrage(): void {
    [, , $net] = fw_env();
    $n = $net->detect();
    check(count($n['interfaces']) === 2 && $n['interfaces'][0]['name'] === 'ens18', 'Schnittstellen falsch');
    check($n['interfaces'][0]['addresses'][0]['network'] === '192.0.2.0/24' && $n['interfaces'][0]['addresses'][0]['private'], 'Netz falsch');
    check(count($n['interfaces'][0]['addresses']) === 1, 'Link-Local-IPv6 nicht ausgeblendet');
    check(!$n['interfaces'][1]['addresses'][0]['private'], 'öffentliche Adresse als privat');
    check(count($n['neighbors']) === 1 && $n['gateway']['ip'] === '192.0.2.1', 'Nachbarn/Gateway falsch');
    check(array_column($n['listening'], 'port') === [22, 443, 631] && $n['listening'][2]['local_only'] === true, 'lauschende Ports falsch');
    check((new Network(fn() => null))->detect()['interfaces'] === [], 'Ausfall der Abfrage nicht abgefangen');
}

function test_pruefung_und_aussperrschutz(): void {
    [, $fw] = fw_env();
    $ok = $fw->validate(['networks' => ['192.0.2.0/24'], 'extra' => 'fd12::/16, 10.8.0.0/24', 'ssh' => true], '192.0.2.20');
    check($ok === ['cidrs' => ['192.0.2.0/24', 'fd12::/16', '10.8.0.0/24'], 'ssh' => true, 'ipp' => false, 'samba' => false, 'strict' => true, 'modules' => [], 'custom' => []],
        'Eingabe nicht übernommen: ' . json_encode($ok));
    problem(fn() => $fw->validate(['networks' => ['10.8.0.0/24']], '192.0.2.20'), 422, 'Aussperren des eigenen Geräts nicht verhindert');
    $fw->validate(['networks' => ['192.0.2.0/24']], '::ffff:192.0.2.8');
    $fw->validate(['networks' => ['10.0.0.0/8']], '127.0.0.1');
    foreach ([['networks' => []], ['networks' => ['0.0.0.0/0']], ['networks' => ['203.0.113.0/24', '192.0.2.0/24']], ['networks' => ['192.0.2.0/24; reboot']],
              ['networks' => array_map(fn($i) => "10.{$i}.0.0/16", range(1, 20)) + [20 => '192.0.2.0/24']]] as $bad) {
        problem(fn() => $fw->validate($bad, '192.0.2.20'), 422, 'ungültig angenommen: ' . json_encode($bad));
    }
    check($fw->validate(['networks' => ['192.0.2.0/24'], 'ssh' => 'ja'], '192.0.2.20')['ssh'] === false, 'ssh nicht streng boolesch');
    check($fw->validate(['networks' => ['192.0.2.0/24'], 'strict' => false], '192.0.2.20')['strict'] === false, 'Art nicht übernommen');
}

function test_modul_ports_und_eigene_ports(): void {
    [, $fw] = fw_env();
    $cat = $fw->catalog();
    check(isset($cat['beispiel/1'], $cat['beispiel/2'], $cat['kasse/1']) && $cat['beispiel/2']['ports'] === '10000-10010' && $cat['beispiel/2']['proto'] === 'udp', 'Katalog falsch: ' . json_encode($cat));
    $v = $fw->validate(['networks' => ['192.0.2.0/24'], 'modules' => ['beispiel/2', 'beispiel/1', 'beispiel/2'], 'custom' => "tcp 8080\nudp 5000 - 5100, TCP:9000"], '192.0.2.20');
    check($v['modules'] === ['beispiel/1', 'beispiel/2'] && $v['custom'] === ['tcp 8080', 'udp 5000-5100', 'tcp 9000'], 'Ports falsch: ' . json_encode($v));
    foreach ([['modules' => ['beispiel/9']], ['modules' => ['rootkit']], ['modules' => 'beispiel/1'], ['custom' => 'tcp 0'], ['custom' => 'tcp 70000'], ['custom' => 'udp 200-100'],
              ['custom' => 'icmp 5'], ['custom' => 'tcp 80; reboot'], ['custom' => implode("\n", array_map(fn($i) => 'tcp ' . (1000 + $i), range(1, 21)))]] as $bad) {
        problem(fn() => $fw->validate(['networks' => ['192.0.2.0/24']] + $bad, '192.0.2.20'), 422, 'ungültig angenommen: ' . json_encode($bad));
    }
}

function test_vorschlag(): void {
    [, $fw] = fw_env();
    $o = $fw->overview('192.0.2.20');
    check($o['config']['cidrs'] === ['192.0.2.0/24'] && $o['config']['ssh'] === true && $o['config']['strict'] === true && !$o['saved'], 'Vorschlag falsch: ' . json_encode($o['config']));
    check($o['client']['network'] === '192.0.2.0/24', 'Netz des Geräts nicht erkannt');
}

function test_anfrage_bestaetigung_ausschalten(): void {
    [$env, $fw] = fw_env();
    $in = ['networks' => ['192.0.2.0/24'], 'ssh' => true, 'modules' => ['beispiel/1']];
    problem(fn() => $fw->apply($in, '192.0.2.20'), 503, 'Anwenden ohne Dienst');
    touch(Firewall::$unit);
    $fw->apply($in, '192.0.2.20');
    $req = json_decode((string)file_get_contents($env->dir . '/anfragen/firewall.json'), true);
    check($req === ['action' => 'apply', 'cidrs' => ['192.0.2.0/24'], 'ssh' => true, 'ipp' => false, 'samba' => false, 'strict' => true, 'modules' => ['beispiel/1'], 'custom' => []],
        'Anfragedatei falsch: ' . json_encode($req));
    check(substr(sprintf('%o', fileperms($env->dir . '/anfragen')), -3) === '700', 'Anfrageordner nicht privat');
    check($fw->status()['state'] === 'requested', 'Zustand nach Anfrage');
    problem(fn() => $fw->apply($in, '192.0.2.20'), 409, 'Doppelanfrage erlaubt');
    problem(fn() => $fw->confirm(), 409, 'Bestätigen ohne wartende Änderung');
    check($fw->overview('192.0.2.20')['saved'] && $fw->overview('192.0.2.20')['config']['modules'] === ['beispiel/1'], 'Einstellung nicht gespeichert');
    unlink($env->dir . '/anfragen/firewall.json');
    file_put_contents(Firewall::$state . '/status.json', json_encode(['state' => 'pending', 'message' => 'x', 'deadline' => time() + 100]));
    check($fw->status()['state'] === 'pending', 'pending nicht erkannt');
    problem(fn() => $fw->apply($in, '192.0.2.20'), 409, 'Anwenden während der Bestätigung');
    $fw->confirm();
    check(is_file($env->dir . '/anfragen/firewall-bestaetigt'), 'Bestätigungsdatei fehlt');
    file_put_contents(Firewall::$state . '/status.json', json_encode(['state' => 'pending', 'deadline' => time() - 60]));
    check($fw->status()['state'] === 'reverted', 'abgelaufenes pending nicht als zurückgesetzt');
    $fw->disable();
    check(json_decode((string)file_get_contents($env->dir . '/anfragen/firewall.json'), true) === ['action' => 'disable'], 'Ausschalt-Anfrage falsch');
}

function test_webseite(): void {
    [$env, $fw] = fw_env();
    touch(Firewall::$unit);
    [$b] = admin_browser($env);
    $b->web = fn() => $b->defaultWeb($fw);
    $b->ip = '192.0.2.20';
    $page = $b->get('/verwaltung/firewall');
    check($page->status === 200 && str_contains($page->body, 'value="192.0.2.0/24" checked') && str_contains($page->body, 'value="beispiel/1"'), 'Firewall-Seite unvollständig');
    $r = $b->post('/verwaltung/firewall', ['networks' => ['10.8.0.0/24'], 'strict' => '1']);
    check($r->status === 422 && str_contains($r->body, 'ausgesperrt'), 'Aussperren über die Seite möglich');
    $r = $b->post('/verwaltung/firewall', ['networks' => ['192.0.2.0/24'], 'ssh' => '1', 'modules' => ['beispiel/1'], 'strict' => '0', 'custom' => "tcp 8080\n"]);
    check($r->status === 303, 'Laden fehlgeschlagen: ' . $r->status . strip_tags(substr($r->body, 0, 400)));
    $req = json_decode((string)file_get_contents($env->dir . '/anfragen/firewall.json'), true);
    check($req['strict'] === false && $req['ssh'] === true && $req['custom'] === ['tcp 8080'] && $req['modules'] === ['beispiel/1'], 'Anfrage über die Seite falsch');
    check($b->get('/verwaltung/firewall')->header('Refresh') === '2', 'Seite lädt beim Warten nicht neu');
    unlink($env->dir . '/anfragen/firewall.json');
    file_put_contents(Firewall::$state . '/status.json', json_encode(['state' => 'pending', 'message' => 'x', 'deadline' => time() + 100]));
    check(str_contains($b->get('/verwaltung/firewall')->body, 'Firewall bestätigen'), 'Bestätigen nicht angeboten');
    check($b->post('/verwaltung/firewall/bestaetigen')->status === 303 && is_file($env->dir . '/anfragen/firewall-bestaetigt'), 'Bestätigen über die Seite');
    check(str_contains(implode("\n", $env->audit->recent()), 'firewall-geladen 192.0.2.0/24 nur-ports beispiel/1'), 'nicht protokolliert');
    // Ohne Bestätigung der Sitzung keine Änderung, Mitarbeiter gar nicht.
    $env->db->query('UPDATE sessions SET confirmed_at=0');
    check(str_starts_with((string)$b->post('/verwaltung/firewall/aus')->header('Location'), '/bestaetigen'), 'Ausschalten ohne Bestätigung');
    $env->user('bert');
    $m = new Browser($env); $m->web = fn() => $m->defaultWeb($fw);
    $m->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($m->get('/verwaltung/firewall')->status === 403, 'Mitarbeiter auf der Firewall-Seite');
}

run_tests();
