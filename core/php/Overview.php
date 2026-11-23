<?php
declare(strict_types=1);
namespace Kienzle\Core;

/**
 * Einrichtungs-Überblick (Verwaltung → Einrichtung): zuerst die Punkte der Basis, darunter die der Module.
 * Die Überblicke der Module sammelt der root-Dienst kienzle-ueberblick (Haken laufen als Modul-Benutzer);
 * hier werden nur deren geprüfte Ergebnisse gelesen. Punkte, die sich nicht prüfen lassen, bestätigt ein Admin von Hand.
 */
final class Overview {
    public static string $state = '/var/lib/kienzle/ueberblick';
    public const MANUAL = ['ca' => 'Zertifizierungsstelle auf allen Arbeitsplätzen eingerichtet', 'codes' => 'Wiederherstellungscodes der Admins ausgedruckt und verwahrt',
        'name' => 'Servername auf den Arbeitsplätzen bekannt (Router/DNS oder hosts-Datei)',
        'anmeldung' => 'Suite unter dem neuen Namen geöffnet und neu angemeldet', 'passkeys' => 'Passkeys unter dem neuen Namen neu angelegt (oder keine vorhanden)'];
    /** Wo ein Punkt erledigt wird (wie im Einrichtungs-Überblick von kienzle-sumup). */
    public const PLACES = ['web' => ['In diesem Webfenster', 'Einstellungen dieses Servers'], 'pc' => ['Auf den Arbeitsplätzen', 'jeder PC der Praxis'],
        'nas' => ['Am NAS und im Router', 'Geräte im Praxisnetz']];
    private const KEY = 'ueberblick_manuell';

    public function __construct(private Database $db, private Users $users, private Modules $modules, private ?Firewall $firewall = null,
        private ?Updates $updates = null, private ?Connections $connections = null, private ?Backup $backup = null, private ?Config $config = null) {}

    private function manual(): array {
        $m = json_decode((string)$this->db->setting(self::KEY), true);
        return is_array($m) ? array_intersect_key($m, self::MANUAL) : [];
    }

    public function confirm(string $item, bool $done): void {
        if (!isset(self::MANUAL[$item])) throw new Problem('Unbekannter Punkt.', 422);
        $m = $this->manual();
        if ($done) $m[$item] = time(); else unset($m[$item]);
        $this->db->store(self::KEY, json_encode($m, JSON_THROW_ON_ERROR));
    }

    public function refresh(): void {
        $f = $this->db->directory . '/anfragen/ueberblick.json';
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0700);
        if (@file_put_contents($f, '{}') === false) throw new Problem('Anfrage konnte nicht gespeichert werden.', 500);
    }

    private static function item(string $title, string $state, string $hint, string $link, ?string $manual = null): array {
        return ['title' => $title, 'state' => $state, 'hint' => $hint, 'link' => $link, 'manuell' => $manual, 'ort' => 'web', 'optional' => false];
    }

    /** Notwendige Reihenfolge der Basis-Schritte: Anfang des Titels => [Ort, optional]. Nicht aufgeführte Punkte kommen ans Ende. */
    private const ORDER = [
        'Admin mit Authenticator-App' => ['web', false],
        'Servername festgelegt' => ['web', false],
        'Servername auf den Arbeitsplätzen' => ['pc', false],
        'Zertifizierungsstelle auf allen Arbeitsplätzen' => ['pc', false],
        'Suite unter dem neuen Namen' => ['pc', false],
        'Mindestens zwei Admins' => ['web', false],
        'Wiederherstellungscodes für jeden Admin' => ['web', false],
        'Wiederherstellungscodes der Admins ausgedruckt' => ['web', false],
        'Passkeys unter dem neuen Namen' => ['pc', true],
        'Firewall eingeschaltet' => ['web', false],
        'Netzwerkspeicher eingetragen' => ['web', false],
        'Datensicherung eingerichtet' => ['web', false],
        'Schlüsselblatt gedruckt' => ['web', false],
        'Letzte Sicherung erfolgreich' => ['web', false],
        'Probe-Wiederherstellung erfolgreich' => ['web', false],
        'Schreibgeschützte Schnappschüsse am NAS' => ['nas', true],
        'Updates: kürzlich' => ['web', false],
        'Updates: Signaturprüfung' => ['web', true],
    ];

    /** Punkte der Basis, in der Reihenfolge, in der sie erledigt werden sollten, mit Ort und Kennzeichnung „optional“. */
    public function core(): array {
        $items = $this->coreItems();
        $rank = static function (array $i): int {
            $n = 0;
            foreach (self::ORDER as $start => $_) { if (str_starts_with($i['title'], $start)) return $n; $n++; }
            return 100;
        };
        foreach ($items as $k => $i) {
            foreach (self::ORDER as $start => [$ort, $optional]) if (str_starts_with($i['title'], $start)) { $items[$k]['ort'] = $ort; $items[$k]['optional'] = $optional; break; }
            $items[$k]['rang'] = $rank($i) * 100 + $k;
        }
        usort($items, fn($a, $b) => $a['rang'] <=> $b['rang']);
        return $items;
    }

    private function coreItems(): array {
        $admins = array_filter($this->users->all(), fn($u) => $u['admin'] && $u['active']);
        $withOtp = array_filter($admins, fn($u) => $u['otp']);
        $codes = array_filter($admins, fn($u) => $this->users->recoveryLeft((int)$u['id']) > 0);
        $manual = $this->manual();
        $out = [];
        $named = false;
        foreach ($this->config?->hostnames ?? [] as $h) if (!filter_var($h, FILTER_VALIDATE_IP) && str_contains($h, '.')) $named = true;
        // Der Name zählt erst als erledigt, wenn die Schritte danach (Router/hosts, neu anmelden, Passkeys) von Hand bestätigt sind.
        $after = isset($manual['name'], $manual['anmeldung'], $manual['passkeys']);
        if ($this->config) $out[] = self::item('Servername festgelegt', $named && $after ? 'done' : 'open',
            !$named ? 'Für Passkeys auf allen Arbeitsplätzen nötig' : implode(', ', $this->config->hostnames) . ($after ? '' : ' – noch offen: Namen im Router/hosts eintragen, unter dem Namen neu anmelden, Passkeys neu anlegen (jeweils unten bestätigen)'), '/verwaltung/server');
        $out[] = self::item('Admin mit Authenticator-App', $admins && count($withOtp) === count($admins) ? 'done' : 'open',
            $admins ? count($withOtp) . ' von ' . count($admins) . ' Admins mit OTP' : 'Noch kein Admin', '/verwaltung/benutzer');
        $out[] = self::item('Mindestens zwei Admins (Vertretung)', count($admins) >= 2 ? 'done' : 'open', 'Fällt ein Admin aus, kommt sonst nur noch die Konsole weiter.', '/verwaltung/benutzer');
        $out[] = self::item('Wiederherstellungscodes für jeden Admin', $admins && count($codes) === count($admins) ? 'done' : 'open', 'Unter „Mein Konto“ neu erzeugen', '/konto');
        $out[] = self::item(self::MANUAL['codes'], isset($manual['codes']) ? 'done' : 'open', 'Auf Papier ausdrucken und sicher verwahren; danach hier bestätigen', '/verwaltung/einrichtung', 'codes');
        $out[] = ['ort' => 'pc'] + self::item(self::MANUAL['ca'], isset($manual['ca']) ? 'done' : 'open', 'Datei ca.crt als vertrauenswürdige Stammzertifizierungsstelle eintragen – Anleitung für Windows, Mac, iPhone und Android', '/hilfe/arbeitsplatz#zertifikat', 'ca');
        $out[] = self::item(self::MANUAL['anmeldung'], isset($manual['anmeldung']) ? 'done' : 'open', 'Suite unter dem Namen aus „Server und Name“ öffnen, dort neu anmelden', '/verwaltung/server', 'anmeldung');
        $out[] = self::item(self::MANUAL['passkeys'], isset($manual['passkeys']) ? 'done' : 'open', 'Unter „Mein Konto“ neu anlegen; sie gelten nur für den Namen, unter dem sie angelegt wurden', '/konto', 'passkeys');
        $out[] = ['ort' => 'pc'] + self::item(self::MANUAL['name'], isset($manual['name']) ? 'done' : 'open', 'Einmal im Router bzw. DNS eintragen – oder auf jedem PC in der Datei hosts', '/hilfe/arbeitsplatz#name', 'name');
        if ($this->firewall) {
            $fw = $this->firewall->status()['state'];
            $out[] = self::item('Firewall eingeschaltet und bestätigt', $fw === 'active' ? 'done' : ($fw === 'failed' ? 'error' : 'open'),
                ['active' => 'aktiv', 'off' => 'aus', 'pending' => 'wartet auf Bestätigung', 'reverted' => 'zurückgenommen', 'failed' => 'Fehler', 'requested' => 'wird übernommen'][$fw] ?? $fw, '/verwaltung/sicherheit?bereich=firewall');
        }
        if ($this->updates) {
            $u = $this->updates->status();
            $out[] = self::item('Updates: Signaturprüfung (optional)', $u['signatur'] === 'pflicht' ? 'done' : 'info',
                $u['signatur'] === 'pflicht' ? 'Nur signierte Versionen' : 'Derzeit ohne Signaturprüfung – einschalten, sobald Releases signiert werden', '/verwaltung/updates');
            $out[] = self::item('Updates: kürzlich nach neuen Versionen gesehen', $u['checked_at'] > time() - 3 * 86400 && !$u['error'] ? 'done' : ($u['error'] ? 'error' : 'open'),
                $u['error'] ? (string)$u['error'] : ($u['checked_at'] ? 'Zuletzt ' . View::date($u['checked_at']) : 'Noch nie'), '/verwaltung/updates');
        }
        if ($this->connections) {
            $c = $this->connections->status();
            $out[] = self::item('Netzwerkspeicher eingetragen', $c['ablage'] ? 'done' : 'open', count($c['ablage']) . ' Ziel(e)', '/verwaltung/verbindungen');
        }
        if ($this->backup) {
            $b = $this->backup->status();
            $last = $b['letzte_sicherung'];
            $out[] = self::item('Datensicherung eingerichtet', $b['eingerichtet'] ? 'done' : 'open', '', '/verwaltung/sicherung');
            $out[] = self::item('Schlüsselblatt gedruckt', $b['eingerichtet'] && !$b['blatt'] ? 'done' : 'open', '', '/verwaltung/sicherung');
            $out[] = self::item('Letzte Sicherung erfolgreich (höchstens 36 Stunden alt)', $last && ($last['ok'] ?? false) && (int)($last['zeit'] ?? 0) > time() - 36 * 3600 ? 'done' : ($last && !($last['ok'] ?? false) ? 'error' : 'open'),
                $last ? View::date((int)($last['zeit'] ?? 0)) : 'Noch keine', '/verwaltung/sicherung');
            $snap = !in_array('Schreibgeschützte Schnappschüsse am NAS sind nicht als eingerichtet bestätigt (Schutz vor Erpressungstrojanern).', $b['warnungen'], true) && $b['eingerichtet'];
            $out[] = ['ort' => 'nas'] + self::item('Schreibgeschützte Schnappschüsse am NAS', $snap ? 'done' : 'open', 'Am NAS einrichten, dann auf der Seite Sicherung bestätigen', '/verwaltung/sicherung');
            $probe = $b['letzte_probe'];
            $out[] = self::item('Probe-Wiederherstellung erfolgreich', $probe && ($probe['ok'] ?? false) ? 'done' : ($probe ? 'error' : 'open'),
                $probe ? View::date((int)($probe['zeit'] ?? 0)) : 'Noch keine – auf der Seite Sicherung starten', '/verwaltung/sicherung');
        }
        return $out;
    }

    /** Überblicke der Module (nur installierte mit [setup] overview). */
    public function modules(): array {
        $out = [];
        foreach ($this->modules->all() as $id => $m) {
            if (empty($m['setup']['overview'])) continue;
            $d = json_decode((string)@file_get_contents(self::$state . '/' . $id . '.json'), true);
            $items = [];
            $path = (string)($m['web']['path'] ?? '');
            foreach ((array)($d['items'] ?? []) as $i) {
                if (!is_array($i) || !in_array($i['state'] ?? '', ['done', 'open', 'error'], true)) continue;
                $link = (string)($i['link'] ?? '');
                if ($link !== '' && ($path === '' || !str_starts_with($link, rtrim($path, '/')) || str_contains($link, '..'))) $link = '';
                $items[] = ['optional' => !empty($i['optional'])] + self::item(mb_substr((string)($i['title'] ?? ''), 0, 120), $i['state'], mb_substr((string)($i['hint'] ?? ''), 0, 300), $link);
            }
            $out[] = ['id' => $id, 'name' => $this->modules->name($id), 'items' => array_slice($items, 0, 30),
                'fehler' => is_array($d) ? mb_substr((string)($d['fehler'] ?? ''), 0, 200) : 'Noch nicht gesammelt', 'zeit' => is_array($d) ? (int)($d['zeit'] ?? 0) : 0];
        }
        return $out;
    }

    public function open(): int {
        $n = count(array_filter($this->core(), fn($i) => $i['state'] !== 'done' && !$i['optional']));
        foreach ($this->modules() as $m) $n += count(array_filter($m['items'], fn($i) => $i['state'] !== 'done' && !$i['optional']));
        return $n;

    }

    /** Kurzstand für die Startseite: erledigte und alle nötigen Schritte (ohne optionale) und der nächste offene. */
    public function summary(): array {
        $all = $this->core();
        foreach ($this->modules() as $m) foreach ($m['items'] as $i) $all[] = $i;
        $required = array_values(array_filter($all, fn($i) => !$i['optional']));
        $open = array_values(array_filter($required, fn($i) => $i['state'] !== 'done'));
        return ['done' => count($required) - count($open), 'total' => count($required), 'next' => $open[0]['title'] ?? '', 'link' => $open[0]['link'] ?? '', 'errors' => count(array_filter($open, fn($i) => $i['state'] === 'error'))];
    }
}
