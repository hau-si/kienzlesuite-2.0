<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** HTML-Bausteine der Basis. Alle Ausgaben fremder Werte laufen über h(). Kein Inline-Skript (CSP script-src 'self'). */
final class View {
    public static function h(string|int|null $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Menü der Seitenleiste: Gruppe => [Pfad => Bezeichnung]. Nur Admins sehen die Gruppen ab „Einrichten“. */
    public const MENU = [
        'Einrichten' => ['/verwaltung/server' => 'Server und Name', '/hilfe/arbeitsplatz' => 'Arbeitsplätze'],
        'Praxis' => ['/verwaltung/praxis' => 'Praxis und Daten', '/verwaltung/benutzer' => 'Benutzer und Gruppen', '/verwaltung/drucker' => 'Drucker', '/verwaltung/verbindungen' => 'Verbindungen', '/verwaltung/sso' => 'Dienste'],
        'Betrieb' => ['/verwaltung/sicherung' => 'Sicherung', '/verwaltung/updates' => 'Updates', '/verwaltung/module' => 'Module', '/verwaltung/sicherheit' => 'Sicherheit'],
    ];

    /** Abschnitte, die auf langen Seiten eingeklappt erscheinen, weil man sie selten braucht. */
    public const COLLAPSED = ['Drucker suchen', 'Drucker von Hand eintragen', 'Wer nutzt was', 'Danach', 'Zeitplan', 'Benachrichtigung bei Fehlern', 'Aufbewahrung',
        'Protokoll', 'Kanal', 'Zurück auf eine frühere Version', 'Dienst hinzufügen', 'Neue Gruppe'];

    /** Symbole der Menüeinträge (Linienzeichnungen 24×24); Schlüssel: Pfad ohne Anhang, sonst das Standardsymbol. */
    private const ICONS = [
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
    ];

    public static function icon(string $href, string $fallback = 'app'): string {
        $key = strtok($href, '?#') ?: $href;
        $svg = self::ICONS[$key] ?? self::ICONS[$fallback];
        return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg>';
    }

    /** Webpfad des Moduls mit Arbeitsplatz-Starter (web.starter), z. B. „/kasse“: core.js leitet „#launch=…“ dorthin weiter. */
    public static string $starter = '';

    /** Ganze Seite. $nav: null = ohne Menü (Anmeldung), sonst ['login', 'admin' => bool, 'csrf', 'pfad', Zähler]. $eyebrow: Gruppe über dem Titel. */
    public static function page(string $title, string $body, ?array $nav = null, string $practice = '', ?string $eyebrow = null): string {
        $brand = '<a class="brand" href="/" title="Start"><span class="mark" aria-hidden="true">K' . (!empty($nav['offenLogo']) ? '<span class="logo-dot" title="Die Einrichtung ist noch nicht abgeschlossen"></span>' : '') . '</span><span><strong>Kienzle-Suite</strong>'
            . '<span class="sub">' . self::h($practice !== '' ? $practice : 'Praxis') . '</span></span></a>';
        $head = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::h($title) . ' – Kienzle-Suite</title><link rel="stylesheet" href="/assets/core.css?v=15"><script src="/assets/core.js" defer></script></head><body'
            . (self::$starter !== '' ? ' data-starter="' . self::h(self::$starter) . '"' : '') . '>';
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
        if (!empty($nav['apps'])) {
            $menu .= '<div class="menu-group"><span class="menu-title">Anwendungen</span>';
            foreach ($nav['apps'] as $href => $label) $menu .= '<a href="' . self::h($href) . '"><span>' . self::icon('app') . self::h($label) . '</span></a>';
            $menu .= '</div>';
        }
        if (!empty($nav['settings'])) {
            $menu .= '<div class="menu-group"><span class="menu-title">Einstellungen</span>';
            foreach ($nav['settings'] as $href => $label) $menu .= '<a href="' . self::h($href) . '"><span>' . self::icon('settings') . self::h($label) . '</span>'
                . (in_array($href, (array)($nav['offen'] ?? []), true) ? '<span class="todo-dot" title="Hier muss noch etwas eingegeben oder bestätigt werden (siehe Einrichtung auf der Startseite)"></span>' : '') . '</a>';
            $menu .= '</div>';
        }
        $group = null;
        if ($nav['admin']) {
            foreach (self::MENU as $title2 => $items) {
                if (isset($items[$path]) || array_filter(array_keys($items), fn($h) => str_starts_with($path, $h . '/'))) $group = $title2;
                $menu .= '<div class="menu-group"><span class="menu-title">' . self::h($title2) . '</span>';
                foreach ($items as $href => $label) $menu .= $link($href, $label);
                $menu .= '</div>';
            }
        }
        $menu .= '</nav>';
        $restart = '';
        if ($nav['admin']) {
            $restart = '<details class="menu-fold"' . (str_starts_with($path, '/verwaltung/neustart') ? ' open' : '') . '><summary class="menu-title">' . self::icon('/verwaltung/neustart') . 'Neu starten</summary>'
                . $link('/verwaltung/neustart?art=dienste', 'Dienste neu starten') . $link('/verwaltung/neustart?art=server', 'Server neu starten') . '</details>';
        }
        $foot = '<div class="side-foot">' . $restart . '<form method="post" action="/abmelden" class="inline">' . self::csrf($nav['csrf'])
            . '<button class="link">Abmelden (' . self::h($nav['login']) . ')</button></form></div>';
        $eyebrow ??= $group;
        return $head . '<div class="layout"><aside class="side">' . $brand . $menu . $foot . '</aside><main>'
            . ($eyebrow ? '<p class="eyebrow">' . self::h($eyebrow) . '</p>' : '') . '<h1>' . self::h($title) . '</h1>' . self::cards($body) . '</main></div></body></html>';
    }

    /** Inhalt in Karten aufteilen: Jede Überschrift h2 beginnt eine neue Karte; Meldungen am Anfang bleiben darüber. */
    public static function cards(string $body): string {
        if (str_contains($body, 'class="card')) return $body;
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
                $out .= '<section class="card"><details class="fold"><summary><h2>' . $m[1] . '</h2></summary>' . $m[2] . '</details></section>';
                continue;
            }
            $out .= '<section class="card">' . $part . '</section>';
        }
        return $out;
    }

    public static function csrf(string $token): string { return '<input type="hidden" name="csrf" value="' . self::h($token) . '">'; }

    public static function error(?string $message): string { return $message ? '<p class="error" role="alert">' . self::h($message) . '</p>' : ''; }

    public static function notice(?string $message): string { return $message ? '<p class="notice" role="status">' . self::h($message) . '</p>' : ''; }

    public static function input(string $name, string $label, string $type = 'text', string $value = '', string $extra = ''): string {
        return '<label>' . self::h($label) . '<input type="' . $type . '" name="' . $name . '" value="' . self::h($value) . '" ' . $extra . '></label>';
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
