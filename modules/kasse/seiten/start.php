<?php
declare(strict_types=1);
// Startseite der Kasse im Rahmen der Suite: links das Kassieren (freie Eingabe / IGeL / GOÄ, eingebettete Kassenansicht), rechts offene T2med-Rechnungen und Tageswerte.
use KienzleKasse\Suite;
$k = Suite::modul();
if (!Suite::can('kassieren')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Für die Kasse fehlt das Recht „Kassieren“. Bitte den Admin fragen (Verwaltung → Benutzer der Suite).\n");
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$version = ks_h(trim((string)file_get_contents(dirname(__DIR__) . '/VERSION')));
$base = ks_h(Suite::base() . '/');
$csrf = ks_h($k->csrf());
$einstellungen = Suite::settingsRights() !== [];
$igel = in_array('catalog', Suite::settingsRights(), true);
ob_start();
?>
<header class="seitenkopf"><div><p class="eyebrow">Kasse</p><h1>Kassieren</h1></div><?= \KienzleKasse\Kopf::knoepfe('start', $base, $igel, $einstellungen, '<span id="day-stats" class="muted small"></span>') ?></header>
<div class="start-grid">
<aside class="start-left" aria-label="Rechnungen"><div class="start-left-kopf"><span class="muted small">Rechnungen</span><button id="inv-refresh" class="start-refresh" type="button" title="Liste aktualisieren" aria-label="Liste aktualisieren">↻</button></div><div class="start-tabs" id="kinds" role="tablist" aria-label="Art"></div><div class="start-tabs" id="tabs" role="tablist"></div>
<input id="inv-search" class="inv-search" type="search" placeholder="Name suchen" autocomplete="off" aria-label="Rechnungen nach Namen suchen">
<button id="free-entry" class="free-entry" type="button">Freie Eingabe (IGeL, GOÄ)</button>
<div id="inbox" class="inbox-list"></div><p id="inbox-empty" class="muted small" hidden>Keine Rechnungen in dieser Ansicht.</p>
<p class="muted small start-hint">Rechnungen aus T2med erscheinen hier, sobald sie auf „vKasse“ gedruckt wurden. „Bezahlt“ und „Zurückgestellt“ zeigen die Vorgänge der letzten 7 Tage; dort lässt sich auch stornieren. „Zurückstellen“ blendet eine Rechnung aus „Offen“ aus, ohne sie zu drucken.</p></aside>
<section class="start-right" aria-label="Kassieren"><div id="patient-karte" class="patient-karte" hidden><div class="pk-marke"><svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 5 6.4 5c2 0 3.4 1.1 4.1 2.3h3C14.2 6.1 15.6 5 17.6 5 21 5 23.1 8.4 21.6 11.8 19.5 16.4 12 21 12 21z"/></svg><span>T2med</span></div><div class="pk-text"><strong id="pk-name"></strong><span id="pk-geb"></span></div><div class="pk-knoepfe"><button id="pk-zum" class="primary pk-knopf" type="button"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg> Zum Patienten</button><button id="pk-weg" class="pk-knopf pk-weg" type="button" title="Patient aus der Kasse entfernen"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg> Entfernen</button></div></div><iframe id="work" class="work-frame" title="Kassieren" src="about:blank"></iframe><div id="detail" class="start-detail card" hidden></div></section>
</div>
<?php
$inhalt = (string)ob_get_clean();
$kopf = '<meta name="kienzle-csrf" content="' . $csrf . '"><link rel="stylesheet" href="' . $base . 'assets/style.css?v=' . $version . '"><link rel="stylesheet" href="' . $base . 'assets/rahmen.css?v=' . $version . '">'
    . '<script src="' . $base . 'assets/start.js?v=' . $version . '" defer></script>';
if (!method_exists($k, 'rahmen')) { header('Location: ' . Suite::base() . '/kassieren'); exit; }
echo $k->rahmen('Kasse', $inhalt, ['roh' => true, 'kopf' => $kopf, 'body' => ' data-base="' . $base . '"']);
