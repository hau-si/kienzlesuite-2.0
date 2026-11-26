<?php
declare(strict_types=1);
namespace KienzleKasse;

/** Gemeinsamer Seitenkopf aller Kasse-Seiten: Knöpfe mit denselben Symbolen wie im Modul Signatur. */
final class Kopf {
    private const SVG = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
    public static function symbol(string $name): string {
        return self::SVG . match ($name) {
            'start' => '<path d="M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10"/>',
            'vorgaenge' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
            'igel' => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01"/>',
            'einstellungen' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1"/>',
            default => '',
        } . '</svg>';
    }
    /** @param string $aktiv start|vorgaenge|igel|einstellungen; $extra: Zeug vor den Knöpfen (z. B. Tageswerte) */
    public static function knoepfe(string $aktiv, string $base, bool $igel, bool $einstellungen, string $extra = ''): string {
        $ziele = ['start' => ['', 'Start'], 'vorgaenge' => ['vorgaenge', 'Vorgänge']];
        if ($igel) $ziele['igel'] = ['igel', 'IGeL'];
        if ($einstellungen) $ziele['einstellungen'] = ['einstellungen', 'Einstellungen'];
        $o = '<span class="start-tools">' . $extra;
        foreach ($ziele as $id => [$pfad, $text]) {
            $aktuell = $id === $aktiv;
            $o .= '<a class="button ' . ($aktuell ? 'primary' : 'secondary') . ' mit-icon" href="' . $base . $pfad . '"' . ($aktuell ? ' aria-current="page"' : '') . '>' . self::symbol($id) . $text
                . ($id === 'einstellungen' ? '<span id="setup-count" class="zaehler" hidden></span>' : '') . '</a>';
        }
        return $o . '</span>';
    }
}
