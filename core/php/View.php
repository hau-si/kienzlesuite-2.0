<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** HTML-Bausteine der Basis. Alle Ausgaben fremder Werte laufen über h(). Kein Inline-Skript (CSP script-src 'self'). */
final class View {
    public static function h(string|int|null $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Menü der Seitenleiste: Gruppe => [Pfad => Bezeichnung]. Nur Admins sehen die Gruppen ab „Einrichten“. */
    public const MENU = [
        'Einrichten' => ['/verwaltung/server' => 'Server', '/hilfe/arbeitsplatz' => 'Arbeitsplätze'],
        'Praxis' => ['/verwaltung/praxis' => 'Praxis', '/verwaltung/benutzer' => 'Benutzer und Gruppen', '/verwaltung/drucker' => 'Drucker', '/verwaltung/verbindungen' => 'Verbindungen', '/verwaltung/sso' => 'Dienste'],
        'Betrieb' => ['/verwaltung/sicherung' => 'Sicherung', '/verwaltung/updates' => 'Updates', '/verwaltung/module' => 'Module', '/verwaltung/sicherheit' => 'Sicherheit'],
    ];

    /** Abschnitte, die auf langen Seiten eingeklappt erscheinen, weil man sie selten braucht. */
    public const COLLAPSED = ['Drucker suchen', 'Drucker von Hand eintragen', 'Wer nutzt was', 'Danach', 'Zeitplan', 'Benachrichtigung bei Fehlern', 'Aufbewahrung',
        'Protokoll', 'Kanal', 'E-Mail-Adresse', 'Passwort ändern', 'Handynummer für SMS-Code (Ersatz)', 'Authenticator-App', 'Passkeys', 'Gemerkte Geräte', 'Zurück auf eine frühere Version', 'Dienst hinzufügen', 'Neue Gruppe'];

    /** Symbole der Menüeinträge (Linienzeichnungen 24×24); Schlüssel: Pfad ohne Anhang, sonst das Standardsymbol. */
    private const ICONS = [
        'rolle:admin' => '<path d="M12 3l8 3v6c0 4.500-3.400 7.800-8 9-4.600-1.200-8-4.500-8-9V6z"/><path d="M8.500 12l2.500 2.500 4.500-5"/>',
        'rolle:arzt' => '<path d="M6 3v6a4 4 0 008 0V3"/><path d="M5 3h2M13 3h2M10 13v2a4 4 0 008 0v-1"/><circle cx="18" cy="12" r="2"/>',
        'rolle:mfa' => '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6 1.500 0 2.800.3 3.800.9"/><path d="M18 21l-3.500-3.500a2.300 2.300 0 013.500-3 2.300 2.300 0 013.500 3z"/>',
        'rolle:gruppe' => '<circle cx="9" cy="8" r="3.500"/><path d="M2 20c0-3.500 3-5.500 7-5.500s7 2 7 5.500"/><path d="M16 4.500a3.500 3.500 0 010 7M18 15c2.500.7 4 2.300 4 5"/>',
        'stift' => '<path d="M4 20l1-4L16 5l3 3L8 19z"/><path d="M14 7l3 3"/>',
        '/' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        '/konto' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'app' => '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/>',
        '/verwaltung/server' => '<rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/><path d="M7 7h.01M7 17h.01"/>',
        '/hilfe/arbeitsplatz' => '<rect x="3" y="4" width="18" height="12" rx="1"/><path d="M8 20h8M12 16v4"/>',
        '/verwaltung/praxis' => '<path d="M4 21V5l8-2 8 2v16"/><path d="M9 21v-5h6v5M9 9h.01M15 9h.01M9 13h.01M15 13h.01"/>',
        '/verwaltung/benutzer' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.5c2.5.6 4 2.3 4 5.5"/>',
        '/verwaltung/drucker' => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="1"/><path d="M7 14h10v7H7z"/>',
        '/verwaltung/verbindungen' => '<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/>',
        '/verwaltung/sso' => '<path d="M9 3v5M15 3v5"/><path d="M6 8h12v4a6 6 0 01-12 0z"/><path d="M12 18v3"/>',
        '/verwaltung/sicherung' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        '/verwaltung/updates' => '<path d="M21 12a9 9 0 01-15.5 6.2M3 12A9 9 0 0118.5 5.8"/><path d="M18 2v4h-4M6 22v-4h4"/>',
        '/verwaltung/module' => '<path d="M12 3l9 5v8l-9 5-9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        '/verwaltung/sicherheit' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        '/verwaltung/neustart' => '<path d="M12 3v9"/><path d="M6.3 6.3a8 8 0 1011.4 0"/>',
        // Startseite: Einrichtung, Module und Serverwerte
        'start:einrichtung' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 01-2 2H6a2 2 0 01-2-2V5a2 2 0 012-2h9"/>',
        'modul:sumup' => '<rect x="4" y="13" width="16" height="7" rx="1"/><path d="M7 13V6h10v7M9 9h6M8 17h.01M12 17h.01M16 17h.01"/>',
        'modul:terminzettel' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6M9 12h6"/>',
        'modul:signatur' => '<path d="M3 17c3-4 4-8 6-8s0 6 2 6 2-4 4-4 1 3 3 3 3-1 3-1"/><path d="M3 21h18"/>',
        'st:disk' => '<rect x="3" y="14" width="18" height="6" rx="1"/><path d="M5 14l3-9h8l3 9M7 17h.01"/>',
        'st:ram' => '<rect x="3" y="8" width="18" height="8" rx="1"/><path d="M7 16v3M11 16v3M15 16v3M7 8V5M11 8V5M15 8V5"/>',
        'st:cpu' => '<rect x="6" y="6" width="12" height="12" rx="1"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v4M15 2v4M9 18v4M15 18v4M2 9h4M2 15h4M18 9h4M18 15h4"/>',
        'st:temp' => '<path d="M10 14V5a2 2 0 014 0v9a4 4 0 11-4 0z"/>',
        'st:zeit' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'st:backup' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'st:post' => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>',
        'st:sms' => '<path d="M21 12a8 8 0 01-11.6 7.1L3 21l1.9-5.4A8 8 0 1121 12z"/>',
        'st:aktualisieren' => '<path d="M21 12a9 9 0 01-15.5 6.2M3 12A9 9 0 0118.5 5.8"/><path d="M18 2v4h-4M6 22v-4h4"/>',
        'st:oeffnen' => '<path d="M7 17L17 7M8 7h9v9"/>',
        'st:neustart' => '<path d="M20 11a8 8 0 10-2.3 6.3"/><path d="M20 4v7h-7"/>',
        'st:start' => '<path d="M7 4l13 8-13 8z"/>',
        'st:mehr' => '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        'st:papierkorb' => '<path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"/>',
        'st:herunter' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'st:server' => '<rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/><path d="M7 7h.01M7 17h.01"/>',
    ];

    /** Zeichen für Kartenüberschriften: Schlagwort im Titel => Symbol. Die erste Übereinstimmung zählt. */
    private const HEADINGS = [
        'Einrichtung' => '<path d="M9 11l3 3 8-8"/><path d="M20 12v7a2 2 0 01-2 2H6a2 2 0 01-2-2V5a2 2 0 012-2h9"/>',
        'Server-Zustand' => '<rect x="3" y="4" width="18" height="6" rx="1"/><rect x="3" y="14" width="18" height="6" rx="1"/><path d="M7 7h.01M7 17h.01"/>',
        'Ihre Module' => '<rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/>',
        'Firewall' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'Erpressungstrojaner' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'Zugang (' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'Zeitplan' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'Protokoll' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'Passkeys' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
        'Schlüsselblatt' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
        'Authenticator' => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
        'Handynummer' => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/>',
        'Passwort' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/>',
        'Kontodaten' => '<path d="M3 10l9-6 9 6M5 10v8M9 10v8M15 10v8M19 10v8M3 20h18"/>',
        'Anschrift' => '<path d="M12 21s7-6 7-11a7 7 0 00-14 0c0 5 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'Steuer' => '<path d="M19 5L5 19"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
        'Name der Praxis' => '<path d="M4 21V5l8-2 8 2v16"/><path d="M9 21v-5h6v5M9 9h.01M15 9h.01M9 13h.01M15 13h.01"/>',
        'T2med' => '<path d="M12 21s-8-5-8-11a4.5 4.5 0 018-2.8A4.5 4.5 0 0120 10c0 6-8 11-8 11z"/>',
        'E-Mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'Benachrichtigung' => '<path d="M6 8a6 6 0 0112 0c0 7 3 9 3 9H3s3-2 3-9M10 21h4"/>',
        'SMS' => '<path d="M21 12a8 8 0 01-11.6 7.1L3 21l1.9-5.4A8 8 0 1121 12z"/>',
        'Letterxpress' => '<path d="M22 2L11 13M22 2l-7 20-4-9-9-4z"/>',
        'Netzwerkspeicher' => '<rect x="3" y="14" width="18" height="6" rx="1"/><path d="M5 14l3-9h8l3 9M7 17h.01"/>',
        'Vorhandene Sicherungen' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'Aufbewahrung' => '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11h14V8M10 12h4"/>',
        'Wer nutzt was' => '<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 10h18M9 4v16"/>',
        'Drucker' => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="1"/><path d="M7 14h10v7H7z"/>',
        'Druckaufträge' => '<path d="M7 9V3h10v6"/><rect x="3" y="9" width="18" height="8" rx="1"/><path d="M7 14h10v7H7z"/>',
        'Benutzer' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.5c2.3.6 4 2.3 4 5.5"/>',
        'Gruppe' => '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.5c2.3.6 4 2.3 4 5.5"/>',
        'Gemerkte Geräte' => '<rect x="3" y="4" width="18" height="12" rx="1"/><path d="M8 20h8M12 16v4"/>',
        'Netz' => '<circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v5M12 12l-6 5M12 12l6 5"/>',
        'Ports' => '<circle cx="12" cy="5" r="2"/><circle cx="5" cy="19" r="2"/><circle cx="19" cy="19" r="2"/><path d="M12 7v5M12 12l-6 5M12 12l6 5"/>',
        'Dienst' => '<path d="M9 3v5M15 3v5"/><path d="M6 8h12v4a6 6 0 01-12 0z"/><path d="M12 18v3"/>',
        'neu starten' => '<path d="M12 3v9"/><path d="M6.3 6.3a8 8 0 1011.4 0"/>',
        'Installiert' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'Verfügbar' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'Kanal' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'Zurück auf' => '<path d="M3 7v6h6"/><path d="M3 13a9 9 0 109-9 9 9 0 00-7 3"/>',
        'Einstellungen exportieren' => '<path d="M12 15V3M7 8l5-5 5 5M4 21h16"/>',
        'Zugang zu Modulen' => '<path d="M12 3l9 5v8l-9 5-9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
        'Art' => '<circle cx="12" cy="12" r="9"/>',
    ];

    /** Status-Schild hinter Kartenüberschriften: Titel => [erledigt, Text wenn ja, Text wenn nein]. Wird je Seite gesetzt und beim Aufbau der Karten verbraucht. */
    public static array $status = [];

    /** Überschrift mit Symbol davor und Status-Schild dahinter (einheitlich im ganzen Projekt). */
    public static function heading(string $titleHtml): string {
        $title = html_entity_decode(strip_tags($titleHtml), ENT_QUOTES, 'UTF-8');
        $svg = null;
        foreach (self::HEADINGS as $needle => $path) if (str_contains($title, $needle)) { $svg = $path; break; }
        $icon = $svg !== null ? '<svg class="h-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg>' : '';
        $tag = '';
        if (isset(self::$status[$title])) {
            [$ok, $yes, $no] = self::$status[$title] + [1 => 'eingerichtet', 2 => 'nicht eingerichtet'];
            $tag = ' <span class="tag ' . ($ok ? 'ok' : 'opt') . '">' . ($ok ? '✓ ' . self::h($yes) : self::h($no)) . '</span>';
        }
        return $icon . $titleHtml . $tag;
    }

    /** Kleines Symbol für Knöpfe und Zeilen (ohne Seitenbezug). */
    public static function symbol(string $name): string {
        $paths = ['coins' => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>'];
        return '<svg class="h-ico sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
    }

    public static function icon(string $href, string $fallback = 'app'): string {
        $key = strtok($href, '?#') ?: $href;
        $svg = self::ICONS[$key] ?? self::ICONS[$fallback];
        return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg>';
    }

    /** Webpfad des Moduls mit Arbeitsplatz-Starter (web.starter), z. B. „/kasse“: core.js leitet „#launch=…“ dorthin weiter. */
    public static string $starter = '';

    /** Ganze Seite. $nav: null = ohne Menü (Anmeldung), sonst ['login', 'admin' => bool, 'csrf', 'pfad', Zähler]. $eyebrow: Gruppe über dem Titel. */
    /**
     * $extra für Modulseiten im Rahmen der Suite: 'kopf' = zusätzliche Tags im Kopf (Stylesheets, Skripte des Moduls), 'roh' = Inhalt unverändert
     * (ohne Überschrift und Karten), 'body' = zusätzliche Attribute am body.
     */
    public static function page(string $title, string $body, ?array $nav = null, string $practice = '', ?string $eyebrow = null, array $extra = []): string {
        $brand = '<a class="brand" href="/" title="Start"><span class="mark" aria-hidden="true">K' . (!empty($nav['offenLogo']) ? '<span class="logo-dot" title="Die Einrichtung ist noch nicht abgeschlossen"></span>' : '') . '</span><span><strong>Kienzle-Suite</strong>'
            . '<span class="sub">' . self::h($practice !== '' ? $practice : 'Praxis') . '</span></span></a>';
        $head = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::h($title) . ' – Kienzle-Suite</title><link rel="stylesheet" href="/assets/core.css?v=32"><script src="/assets/core.js?v=3" defer></script>' . ($extra['kopf'] ?? '') . '</head><body'
            . (self::$starter !== '' ? ' data-starter="' . self::h(self::$starter) . '"' : '') . ($extra['body'] ?? '') . '>';
        if (!$nav) {
            return $head . '<div class="solo">' . $brand . '<main><h1>' . self::h($title) . '</h1>' . self::cards($body) . '</main></div></body></html>';
        }
        $path = (string)($nav['pfad'] ?? '');
        $badges = ['/verwaltung/einrichtung' => [(int)($nav['einrichtung'] ?? 0), 'Offene Punkte'], '/verwaltung/sicherung' => [(int)($nav['sicherung'] ?? 0), 'Hinweise zur Sicherung'],
            '/verwaltung/updates' => [(int)($nav['updates'] ?? 0), 'Neue Version verfügbar'], '/verwaltung/module' => [(int)($nav['module'] ?? 0), 'Neue Modulversion verfügbar']];
        $link = function (string $href, string $label, ?string $icon = null) use ($path, $badges, $nav): string {
            $current = $path === $href || ($href !== '/' && str_starts_with($path, $href . '/'));
            [$n, $hint] = $badges[$href] ?? [0, ''];
            $todo = in_array(strtok($href, '?#') ?: $href, (array)($nav['offen'] ?? []), true);
            return '<a href="' . $href . '"' . ($current ? ' class="current" aria-current="page"' : '') . '><span>' . ($icon ?? self::icon($href)) . self::h($label) . '</span>'
                . ($n > 0 ? '<span class="badge" title="' . self::h($hint) . '">' . $n . '</span>' : '')
                . ($todo ? '<span class="todo-dot" title="Hier muss noch etwas eingegeben oder bestätigt werden (siehe Einrichtung auf der Startseite)"></span>' : '') . '</a>';
        };
        $menu = '<nav class="menu" aria-label="Menü"><div class="menu-group">' . $link('/konto', 'Mein Konto') . '</div>';
        // Anwendungen: nur noch ein Eintrag je Modul (mit Symbol des Moduls); der Punkt zeigt offene Einstellungen. Einklappbar, weil weitere Module dazukommen.
        if (!empty($nav['apps'])) {
            $links = '';
            $open = false;
            foreach ($nav['apps'] as $href => $label) {
                $meta = (array)($nav['appmeta'][$href] ?? []);
                $current = $path === rtrim($href, '/') || str_starts_with($path, $href);
                $open = $open || $current;
                $links .= '<div class="menu-app" draggable="true" data-app="' . self::h($href) . '"><a href="' . self::h($href) . '"' . ($current ? ' class="current" aria-current="page"' : '') . ' draggable="false" title="Zum Sortieren ziehen oder Alt + Pfeil nach oben/unten"><span>' . self::icon('modul:' . (string)($meta['id'] ?? ''), 'app') . self::h($label) . '</span>'
                    . (!empty($meta['todo']) ? '<span class="todo-dot" title="Hier muss noch etwas eingegeben oder bestätigt werden (siehe Einrichtung auf der Startseite)"></span>' : '') . '</a></div>';
            }
            // Reihenfolge wählt jeder selbst: per Ziehen (oder Alt + Pfeiltaste), im Browser gemerkt, siehe core.js.
            $menu .= '<details class="menu-group menu-fold" data-menu="anwendungen" open><summary class="menu-title">Anwendungen</summary>' . $links . '</details>';
        }
        $group = null;
        if ($nav['admin']) {
            foreach (self::MENU as $title2 => $items) {
                if (isset($items[$path]) || array_filter(array_keys($items), fn($h) => str_starts_with($path, $h . '/'))) $group = $title2;
                $menu .= '<details class="menu-group menu-fold" data-menu="' . self::h(strtolower($title2)) . '" open><summary class="menu-title">' . self::h($title2) . '</summary>';
                foreach ($items as $href => $label) $menu .= $link($href, $label);
                $menu .= '</details>';
            }
        }
        $menu .= '</nav>';
        $restart = '';
        if ($nav['admin']) {
            $restart = '<details class="menu-fold"' . (str_starts_with($path, '/verwaltung/neustart') ? ' open' : '') . '><summary class="menu-title">' . self::icon('/verwaltung/neustart') . 'Neu starten</summary>'
                . $link('/verwaltung/neustart?art=dienste', 'Dienste neu starten') . $link('/verwaltung/neustart?art=server', 'Server neu starten') . '</details>';
        }
        // Modulseiten kennen die Sitzungs-Marke nicht (nur eine eigene): dort führt „Mein Konto“ zum Abmelden.
        $foot = '<div class="side-foot">' . $restart . (($nav['csrf'] ?? '') !== '' ? '<form method="post" action="/abmelden" class="inline">' . self::csrf($nav['csrf'])
            . '<button class="link">Abmelden (' . self::h($nav['login']) . ')</button></form>' : '<a class="link" href="/konto">' . self::h($nav['login']) . ' · Abmelden</a>') . '</div>';
        $eyebrow ??= $group;
        $inner = !empty($extra['roh']) ? $body : ($eyebrow ? '<p class="eyebrow">' . self::h($eyebrow) . '</p>' : '') . '<h1>' . self::h($title) . '</h1>' . self::cards($body);
        return $head . '<div class="layout"><aside class="side">' . $brand . $menu . $foot . '</aside><main' . (!empty($extra['roh']) ? ' class="modul"' : '') . '>' . $inner . '</main></div></body></html>';
    }

    /** Inhalt in Karten aufteilen: Jede Überschrift h2 beginnt eine neue Karte; Meldungen am Anfang bleiben darüber. */
    public static function cards(string $body): string {
        if (str_contains($body, 'class="card')) {
            $body = preg_replace_callback('#<h2>([^<]*)</h2>#', fn($h) => '<h2>' . self::heading($h[1]) . '</h2>', $body) ?? $body;
            self::$status = [];
            return $body;
        }
        $lead = '';
        while (preg_match('#^\s*(<p class="(?:error|notice)"[^>]*>.*?</p>)#s', $body, $m)) { $lead .= $m[1]; $body = substr($body, strlen($m[0])); }
        // Nur Überschriften auf oberster Ebene beginnen eine Karte; eine h2 in einem offenen div/form (z. B. Update-Kasten) darf den Aufbau nicht zerreißen.
        $cuts = [0];
        $depth = 0;
        if (preg_match_all('#<(/?)(div|form|section|details|table|ul|ol|pre|fieldset)\b[^>]*>|<h2[ >]#', $body, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($found as $tag) {
                if (str_starts_with($tag[0][0], '<h2')) { if ($depth === 0 && $tag[0][1] > 0) $cuts[] = $tag[0][1]; }
                elseif ($tag[1][0] === '/') $depth = max(0, $depth - 1);
                else $depth++;
            }
        }
        $cuts[] = strlen($body);
        $parts = [];
        for ($k = 0; $k + 1 < count($cuts); $k++) $parts[] = substr($body, $cuts[$k], $cuts[$k + 1] - $cuts[$k]);
        $out = $lead;
        foreach ($parts as $part) {
            if (trim($part) === '') continue;
            // Selten gebrauchte Abschnitte klappen zu (Überschrift bleibt sichtbar); Abschnitte mit Fehlermeldung bleiben offen.
            if (preg_match('#^\s*<h2>([^<]*)</h2>(.*)$#s', $part, $m) && in_array(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), self::COLLAPSED, true) && !str_contains($part, 'class="error"')) {
                $out .= '<section class="card"><details class="fold"><summary><h2>' . self::heading($m[1]) . '</h2></summary>' . $m[2] . '</details></section>';
                continue;
            }
            $out .= '<section class="card">' . $part . '</section>';
        }
        // Alle noch schlichten Überschriften (auch in verschachtelten Abschnitten) mit Symbol und Status versehen.
        $out = preg_replace_callback('#<h2>([^<]*)</h2>#', fn($h) => '<h2>' . self::heading($h[1]) . '</h2>', $out) ?? $out;
        self::$status = [];
        return $out;
    }

    public static function csrf(string $token): string { return '<input type="hidden" name="csrf" value="' . self::h($token) . '">'; }

    public static function error(?string $message): string { return $message ? '<p class="error" role="alert">' . self::h($message) . '</p>' : ''; }

    public static function notice(?string $message): string { return $message ? '<p class="notice" role="status">' . self::h($message) . '</p>' : ''; }

    public static function input(string $name, string $label, string $type = 'text', string $value = '', string $extra = ''): string {
        return '<label>' . self::h($label) . '<input type="' . $type . '" name="' . $name . '" value="' . self::h($value) . '" ' . $extra . '></label>';
    }

    /** Rollen-Symbol: Admin = Schild mit Haken, Arzt = Stethoskop, MFA = Person mit Herz, sonst Personengruppe. */
    public static function role(string $name): string {
        $key = match (strtolower($name)) { 'admin' => 'rolle:admin', 'arzt' => 'rolle:arzt', 'mfa' => 'rolle:mfa', default => 'rolle:gruppe' };
        return '<span class="rolle" title="' . self::h($name) . '">' . self::icon($key) . '</span>';
    }

    /** Avatar: Initialen im Kreis, unten rechts das Symbol der wichtigsten Rolle (Admin vor Arzt vor MFA vor sonstigen). $small für Listen. */
    public static function avatar(string $name, array $roles, bool $admin = false, bool $small = false): string {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $ini = '';
        foreach (array_slice(array_filter($words), 0, 2) as $w) $ini .= mb_strtoupper(mb_substr($w, 0, 1));
        $badge = '';
        $pick = $admin ? 'Admin' : null;
        foreach (['Admin', 'Arzt', 'MFA'] as $k) if ($pick === null && in_array($k, $roles, true)) $pick = $k;
        $pick ??= $roles[0] ?? null;
        if ($pick !== null) $badge = '<span class="rb">' . self::role($pick) . '</span>';
        return '<span class="avatar' . ($small ? ' klein' : '') . '" aria-hidden="true">' . self::h($ini !== '' ? $ini : '?') . $badge . '</span>';
    }

    public static function checkbox(string $name, string $value, string $label, bool $checked, bool $disabled = false): string {
        return '<label class="check"><input type="checkbox" name="' . $name . '" value="' . self::h($value) . '"' . ($checked ? ' checked' : '') . ($disabled ? ' disabled' : '') . '> ' . self::h($label) . '</label>';
    }

    public static function date(int $time): string { return $time > 0 ? date('d.m.Y H:i', $time) : '–'; }

    /** Wiederherstellungscodes zum einmaligen Anzeigen und Ausdrucken. */
    public static function codes(array $codes): string {
        return '<div class="codes"><p><strong>Wiederherstellungscodes – jetzt ausdrucken oder notieren.</strong> Jeder Code gilt einmal, wenn das Telefon mit der '
            . 'Authenticator-App fehlt. Sie werden nur jetzt angezeigt.</p><ol>' . implode('', array_map(fn($c) => '<li><code>' . self::h($c) . '</code></li>', $codes))
            . '</ol><button type="button" data-print>Drucken</button></div>';
    }
}
