<?php
declare(strict_types=1);
namespace Kienzle\Core;

/** HTML-Bausteine der Basis. Alle Ausgaben fremder Werte laufen über h(). Kein Inline-Skript (CSP script-src 'self'). */
final class View {
    public static function h(string|int|null $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Ganze Seite. $nav: null = ohne Menü (Anmeldung), sonst ['login', 'admin' => bool, 'csrf', 'practice']. */
    public static function page(string $title, string $body, ?array $nav = null, string $practice = ''): string {
        $menu = '';
        if ($nav) {
            $menu = '<nav class="menu"><a href="/">Start</a><a href="/konto">Mein Konto</a>'
                . ($nav['admin'] ? '<a href="/verwaltung/einrichtung">Einrichtung</a><a href="/verwaltung/benutzer">Benutzer</a><a href="/verwaltung/verbindungen">Verbindungen</a><a href="/verwaltung/drucker">Drucker</a><a href="/verwaltung/sso">Dienste</a><a href="/verwaltung/sicherung">Sicherung'
                    . (($nav['sicherung'] ?? 0) > 0 ? ' <span class="badge" title="Hinweise zur Sicherung">' . (int)$nav['sicherung'] . '</span>' : '') . '</a><a href="/verwaltung/firewall">Firewall</a><a href="/verwaltung/updates">Updates'
                    . (($nav['updates'] ?? 0) > 0 ? ' <span class="badge" title="Neue Version verfügbar">' . (int)$nav['updates'] . '</span>' : '') . '</a><a href="/verwaltung/zugang">Zugang</a><a href="/verwaltung/protokoll">Protokoll</a>' : '')
                . '<form method="post" action="/abmelden" class="inline">' . self::csrf($nav['csrf']) . '<button class="link">Abmelden (' . self::h($nav['login']) . ')</button></form></nav>';
        }
        return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::h($title) . ' – Kienzle-Suite</title><link rel="stylesheet" href="/assets/core.css"><script src="/assets/core.js" defer></script></head>'
            . '<body><header><strong>Kienzle-Suite</strong>' . ($practice !== '' ? ' <span class="practice">' . self::h($practice) . '</span>' : '') . $menu . '</header>'
            . '<main><h1>' . self::h($title) . '</h1>' . $body . '</main></body></html>';
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
