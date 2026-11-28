<?php
declare(strict_types=1);
// Startseite der Kasse im Rahmen von Praxisherz: links das Kassieren (freie Eingabe / IGeL / GOÄ, eingebettete Kassenansicht), rechts offene T2med-Rechnungen und Tageswerte.
use KienzleKasse\Suite;
$k = Suite::modul();
if (!Suite::can('kassieren')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Für die Kasse fehlt das Recht „Kartenzahlung und Barzahlung aus T2med“. Bitte den Admin fragen (Praxis → Benutzer und Gruppen von Praxisherz).\n");
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
<section class="start-right" aria-label="Kassieren"><div id="patient-karte" class="patient-karte" hidden><button type="button" id="pk-marke" class="pk-marke pk-akte" title="Patientenakte in T2med öffnen"><svg class="pk-herz" viewBox="0 0 24 24" width="26" height="26" fill="currentColor" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 5 6.4 5c2 0 3.4 1.1 4.1 2.3h3C14.2 6.1 15.6 5 17.6 5 21 5 23.1 8.4 21.6 11.8 19.5 16.4 12 21 12 21z"/></svg><svg class="pk-person" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5M10 13h6M10 17h6"/></svg><span id="pk-etikett">T2med</span></button><div class="pk-text"><button type="button" id="pk-name-knopf" class="pk-name pk-akte" title="Patientenakte in T2med öffnen"><strong id="pk-name"></strong></button><span id="pk-geb"></span></div><div id="pk-tabs" class="pk-tabs" role="group" aria-label="Ansicht" hidden><button type="button" class="pk-tab" data-tab="rechnung" aria-pressed="true"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19.5 12.572l-7.5 7.428l-7.5 -7.428a5 5 0 1 1 7.5 -6.566a5 5 0 1 1 7.5 6.572"/></svg> T2m-Rechnung</button><button type="button" class="pk-tab" data-tab="igel" aria-pressed="false"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 16l1-4l2 1l1-4l2.5 1.5L12 7l2 3l2.5-1.5L17 12l2.5 .5L19 16"/><path d="M4 16c0 1.5 1 2 2 2h12c1.5 0 2.500-.8 3-2l2 1l-2 1"/><path d="M8 18v2M15 18v2"/><path d="M18.500 14.500h.01"/></svg> IGeL</button></div><div class="pk-knoepfe"><button id="pk-zum" class="primary pk-knopf" type="button"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg> Zum Patienten</button><button id="pk-weg" class="pk-knopf pk-weg pk-symbol" type="button" title="Patient aus der Kasse entfernen" aria-label="Patient aus der Kasse entfernen"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg></button></div></div><iframe id="work" class="work-frame" title="Kassieren" src="about:blank"></iframe><div id="detail" class="start-detail card" hidden></div></section>
</div>
<?php
$inhalt = (string)ob_get_clean();
$kopf = '<meta name="kienzle-csrf" content="' . $csrf . '"><link rel="stylesheet" href="' . $base . 'assets/style.css?v=' . $version . '"><link rel="stylesheet" href="' . $base . 'assets/rahmen.css?v=' . $version . '">'
    . '<script src="' . $base . 'assets/start.js?v=' . $version . '" defer></script>';
if (!method_exists($k, 'rahmen')) { header('Location: ' . Suite::base() . '/kassieren'); exit; }
echo $k->rahmen('Kasse', $inhalt, ['roh' => true, 'kopf' => $kopf, 'body' => ' data-base="' . $base . '"']);
