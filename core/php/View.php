<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** HTML-Bausteine der Basis. Alle Ausgaben fremder Werte laufen über h(). Kein Inline-Skript (CSP script-src 'self'). */
final class View {
    public static function h(string|int|null $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Menü der Seitenleiste: Gruppe => [Pfad => Bezeichnung]. Nur Admins sehen die Gruppen ab „Einrichten“. */
    public const MENU = [
        'Einrichten' => ['/verwaltung/einrichtung' => 'Überblick', '/verwaltung/server' => 'Server und Name', '/hilfe/arbeitsplatz' => 'Arbeitsplätze'],
        'Praxis' => ['/verwaltung/praxis' => 'Praxis und Daten', '/verwaltung/benutzer' => 'Benutzer und Gruppen', '/verwaltung/drucker' => 'Drucker', '/verwaltung/verbindungen' => 'Verbindungen', '/verwaltung/sso' => 'Dienste'],
        'Betrieb' => ['/verwaltung/sicherung' => 'Sicherung', '/verwaltung/updates' => 'Updates', '/verwaltung/module' => 'Module', '/verwaltung/sicherheit' => 'Sicherheit'],
    ];

    /** Webpfad des Moduls mit Arbeitsplatz-Starter (web.starter), z. B. „/kasse“: core.js leitet „#launch=…“ dorthin weiter. */
    public static string $starter = '';

    /** Ganze Seite. $nav: null = ohne Menü (Anmeldung), sonst ['login', 'admin' => bool, 'csrf', 'pfad', Zähler]. $eyebrow: Gruppe über dem Titel. */
    public static function page(string $title, string $body, ?array $nav = null, string $practice = '', ?string $eyebrow = null): string {
        $brand = '<a class="brand" href="/"><span class="mark" aria-hidden="true">K</span><span><strong>Kienzle-Suite</strong>'
            . '<span class="sub">' . self::h($practice !== '' ? $practice : 'Praxis') . '</span></span></a>';
        $head = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::h($title) . ' – Kienzle-Suite</title><link rel="stylesheet" href="/assets/core.css?v=5"><script src="/assets/core.js" defer></script></head><body'
            . (self::$starter !== '' ? ' data-starter="' . self::h(self::$starter) . '"' : '') . '>';
        if (!$nav) {
            return $head . '<div class="solo">' . $brand . '<main><h1>' . self::h($title) . '</h1>' . self::cards($body) . '</main></div></body></html>';
        }
        $path = (string)($nav['pfad'] ?? '');
        $badges = ['/verwaltung/einrichtung' => [(int)($nav['einrichtung'] ?? 0), 'Offene Punkte'], '/verwaltung/sicherung' => [(int)($nav['sicherung'] ?? 0), 'Hinweise zur Sicherung'],
            '/verwaltung/updates' => [(int)($nav['updates'] ?? 0), 'Neue Version verfügbar'], '/verwaltung/module' => [(int)($nav['module'] ?? 0), 'Neue Modulversion verfügbar']];
        $link = function (string $href, string $label) use ($path, $badges): string {
            $current = $path === $href || ($href !== '/' && str_starts_with($path, $href . '/'));
            [$n, $hint] = $badges[$href] ?? [0, ''];
            return '<a href="' . $href . '"' . ($current ? ' class="current" aria-current="page"' : '') . '><span>' . self::h($label) . '</span>'
                . ($n > 0 ? '<span class="badge" title="' . self::h($hint) . '">' . $n . '</span>' : '') . '</a>';
        };
        $menu = '<nav class="menu" aria-label="Menü"><div class="menu-group">' . $link('/', 'Start') . $link('/konto', 'Mein Konto') . '</div>';
        if (!empty($nav['apps'])) {
            $menu .= '<div class="menu-group"><span class="menu-title">Anwendungen</span>';
            foreach ($nav['apps'] as $href => $label) $menu .= '<a href="' . self::h($href) . '"><span>' . self::h($label) . '</span></a>';
            $menu .= '</div>';
        }
        if (!empty($nav['settings'])) {
            $menu .= '<div class="menu-group"><span class="menu-title">Einstellungen</span>';
            foreach ($nav['settings'] as $href => $label) $menu .= '<a href="' . self::h($href) . '"><span>' . self::h($label) . '</span></a>';
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
        if ($nav['admin']) {
            $menu .= '<details class="menu-group menu-fold"' . (str_starts_with($path, '/verwaltung/neustart') ? ' open' : '') . '><summary class="menu-title">Neu starten</summary>'
                . $link('/verwaltung/neustart?art=dienste', 'Dienste neu starten') . $link('/verwaltung/neustart?art=server', 'Server neu starten') . '</details>';
        }
        $menu .= '</nav>';
        $foot = '<div class="side-foot"><form method="post" action="/abmelden" class="inline">' . self::csrf($nav['csrf'])
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
        $parts = preg_split('#(?=<h2[ >])#', $body) ?: [$body];
        $out = $lead;
        foreach ($parts as $part) if (trim($part) !== '') $out .= '<section class="card">' . $part . '</section>';
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
