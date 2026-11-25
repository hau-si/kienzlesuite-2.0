<?php
declare(strict_types=1);
// Verwaltung → Module (ohne echten root-Dienst): Status lesen und bereinigen, Anfragen ablegen, Seite, Menü, Rechte.
require __DIR__ . '/lib.php';
require __DIR__ . '/browser.php';

use Kienzle\Core\{Auth, ModuleAdmin, Web};

function mod_env(array $status = []): array {
    $env = new Env();
    ModuleAdmin::$state = $env->dir . '/module'; mkdir(ModuleAdmin::$state);
    ModuleAdmin::$unit = $env->dir . '/kienzle-module.path'; touch(ModuleAdmin::$unit);
    $status += ['checked_at' => time(), 'running' => null,
        'installed' => [['id' => 'kasse', 'name' => 'Kasse', 'version' => '2.0.0', 'description' => '', 'path' => '/kasse', 'update' => ['version' => '2.1.0', 'source' => 'github', 'signed' => false]]],
        'available' => [['id' => 'telefon', 'name' => 'Telefon <b>', 'version' => '1.0.0', 'description' => 'Ansagen', 'path' => '/telefon', 'source' => 'mitgeliefert', 'signed' => null]]];
    file_put_contents(ModuleAdmin::$state . '/status.json', json_encode($status));
    return [$env, new ModuleAdmin($env->db)];
}

function mreq(Env $env): array { return json_decode((string)file_get_contents($env->dir . '/anfragen/module.json'), true); }

function module_browser(Env $env, ModuleAdmin $store, string $login = 'anna'): Browser {
    [$b] = admin_browser($env, $login);
    $b->web = function () use ($b, $store, $env): Web {
        $auth = new Auth($env->db, $env->users, $env->audit, $b->ip);
        return new Web($env->config, $env->db, $env->users, $auth, $env->passkeys, $env->modules, $env->audit,
            static fn(string $uri) => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', store: $store);
    };
    return $b;
}

function test_status_bereinigt(): void {
    [, $m] = mod_env(['available' => [['id' => '../core', 'version' => '1.0.0'], ['id' => 'gut', 'version' => 'x'], ['id' => 'telefon', 'name' => 'Telefon', 'version' => '1.0.0']]]);
    file_put_contents(ModuleAdmin::$state . '/module.log', "Zeile\x1b[31m\n");
    $s = $m->status();
    check(array_column($s['available'], 'id') === ['telefon'], 'ungültige Module übernommen: ' . json_encode($s['available']));
    check($s['log'] === ['Zeile[31m'] && $m->count() === 1, 'Protokoll oder Zählung falsch');
}

function test_anfragen(): void {
    [$env, $m] = mod_env();
    problem(fn() => $m->install('kasse'), 404, 'installiertes Modul erneut installierbar');
    problem(fn() => $m->install('gibtsnicht'), 404, 'unbekanntes Modul installierbar');
    $m->install('telefon');
    check(mreq($env) === ['action' => 'installieren', 'id' => 'telefon', 'version' => '1.0.0'], 'Installationsanfrage falsch');
    problem(fn() => $m->update('kasse'), 409, 'zweite Anfrage während der ersten');
    unlink($env->dir . '/anfragen/module.json');
    $m->update('kasse');
    check(mreq($env) === ['action' => 'aktualisieren', 'id' => 'kasse', 'version' => '2.1.0'], 'Aktualisierungsanfrage falsch');
    unlink($env->dir . '/anfragen/module.json');
    $m->remove('kasse', true);
    check(mreq($env) === ['action' => 'entfernen', 'id' => 'kasse', 'daten_loeschen' => true], 'Entfernen falsch');
    unlink($env->dir . '/anfragen/module.json');
    unlink(ModuleAdmin::$unit);
    problem(fn() => $m->check(), 503, 'ohne Dienst');
}

function test_dienststatus_und_start(): void {
    [$env, $m] = mod_env(['installed' => [['id' => 'kasse', 'name' => 'Kasse', 'version' => '2.0.0', 'description' => '', 'path' => '/kasse', 'update' => null,
        'dienste' => [['name' => 'kienzle-kasse-php.service', 'aktiv' => false], ['name' => '../boese', 'aktiv' => true]]]]]);
    $s = $m->status();
    check($s['installed'][0]['dienste'] === [['name' => 'kienzle-kasse-php.service', 'aktiv' => false]], 'Dienste nicht bereinigt: ' . json_encode($s['installed'][0]['dienste']));
    $b = module_browser($env, $m);
    $page = $b->get('/verwaltung/module')->body;
    check(str_contains($page, 'gestoppt') && str_contains($page, 'value="dienst-start"') && str_contains($page, 'value="dienst-neustart"'), 'Dienststatus oder Knöpfe fehlen');
    $b->post('/verwaltung/module', ['aktion' => 'dienst-start', 'id' => 'kasse']);
    check(mreq($env) === ['action' => 'dienst-start', 'id' => 'kasse'], 'Startanfrage falsch');
    check(str_contains(implode("\n", $env->audit->recent()), 'modul-dienst-start kasse'), 'Start nicht protokolliert');
    unlink($env->dir . '/anfragen/module.json');
    $b->post('/verwaltung/module', ['aktion' => 'status']);
    check(mreq($env) === ['action' => 'status'], 'Statusanfrage falsch');
}

function test_seite_menue_und_rechte(): void {
    [$env, $m] = mod_env();
    $b = module_browser($env, $m);
    $page = $b->get('/verwaltung/module');
    check($page->status === 200 && str_contains($page->body, 'Neue Version <strong>2.1.0</strong> (GitHub, nicht signiert)') && str_contains($page->body, 'Telefon &lt;b&gt;'), 'Seite unvollständig oder nicht maskiert');
    check(str_contains($page->body, 'class="mod-grid"') && str_contains($page->body, 'mod-tile available') && str_contains($page->body, '<span class="tag">verfügbar</span>') && str_contains($page->body, 'class="mod-remove"')
        && str_contains($page->body, '<button class="danger">') && str_contains($page->body, 'Auf 2.1.0 aktualisieren') && str_contains($page->body, ' Installieren</button>'), 'Modulkacheln: installiert, verfügbar, Update, rotes Entfernen');
    check(str_contains($b->get('/')->body, 'title="Neue Modulversion verfügbar">1</span>'), 'Hinweis im Menü fehlt');
    $r = $b->post('/verwaltung/module', ['aktion' => 'installieren', 'id' => 'telefon']);
    check($r->status === 303 && mreq($env)['id'] === 'telefon', 'Installieren über die Seite');
    check($b->get('/verwaltung/module')->header('Refresh') === '4', 'Seite lädt beim Warten nicht neu');
    check(str_contains(implode("\n", $env->audit->recent()), 'modul-installieren telefon'), 'nicht protokolliert');
    unlink($env->dir . '/anfragen/module.json');
    check(str_contains($page->body, 'class="t-ico mod-open" href="/kasse/"') && str_contains($page->body, 'name="bestaetigung"') && !str_contains($page->body, '>Öffnen</a>'), 'Logo öffnet nicht das Modul oder Namensbestätigung fehlt');
    $r = $b->post('/verwaltung/module', ['aktion' => 'entfernen', 'id' => 'kasse']);
    check($r->status === 409 || $r->status === 422, 'Entfernen ohne Namensbestätigung akzeptiert (' . $r->status . ')');
    check(!is_file($env->dir . '/anfragen/module.json'), 'Entfernen ohne Bestätigung wurde angefordert');
    $r = $b->post('/verwaltung/module', ['aktion' => 'entfernen', 'id' => 'kasse', 'bestaetigung' => 'falsch']);
    check(!is_file($env->dir . '/anfragen/module.json'), 'Entfernen mit falschem Namen wurde angefordert');
    $b->post('/verwaltung/module', ['aktion' => 'entfernen', 'id' => 'kasse', 'bestaetigung' => ' kasse ']);
    check(mreq($env)['daten_loeschen'] === false, 'Daten ohne Häkchen gelöscht');
    unlink($env->dir . '/anfragen/module.json');
    $env->db->query('UPDATE sessions SET confirmed_at=0');
    check(str_starts_with((string)$b->post('/verwaltung/module', ['aktion' => 'pruefen'])->header('Location'), '/bestaetigen'), 'Module ohne Bestätigung');
    $env->user('bert');
    $u = new Browser($env); $u->web = $b->web;
    $u->post('/anmelden', ['login' => 'bert', 'password' => 'Wiesen-Blume-Fahrrad-7']);
    check($u->get('/verwaltung/module')->status === 403, 'Mitarbeiter sieht Module');
}

function test_starter_weiterleitung_im_seitenkopf(): void {
    [$env, $m] = mod_env();
    $b = module_browser($env, $m);
    check(!str_contains($b->get('/')->body, 'data-starter='), 'Starter ohne Modul angekündigt');
    $mods = $env->modules->all();
    $mods['kasse']['web']['starter'] = true;
    $env->modules = Kienzle\Core\Modules::fromArray(array_values($mods));
    check(str_contains($b->get('/')->body, '<body data-starter="/kasse">'), 'Starter-Ziel fehlt im Seitenkopf');
    check(str_contains($b->get('/anmelden')->body, 'data-starter="/kasse"') || $b->get('/anmelden')->status === 303, 'Anmeldeseite ohne Starter-Ziel');
}

run_tests();
