<?php
declare(strict_types=1);
// Startseite der Kasse im Rahmen der Suite: links das Kassieren (freie Eingabe / IGeL / GOÄ, eingebettete Kassenansicht), rechts offene T2med-Rechnungen und Tageswerte.
use KienzleSumup\Suite;
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
$einstellungen = array_diff(Suite::rights(), ['kassieren']) !== [];
ob_start();
?>
<header class="seitenkopf"><div><p class="eyebrow">Kasse</p><h1>Kassieren</h1></div><span class="start-tools"><span id="day-stats" class="muted small"></span><?php if ($einstellungen): ?><a class="button secondary" href="<?= $base ?>einstellungen">⚙ Einstellungen<span id="setup-count" class="zaehler" hidden></span></a><?php endif; ?></span></header>
<div class="start-grid">
<aside class="start-left" aria-label="Rechnungen"><div class="start-tabs" id="tabs" role="tablist"></div>
<input id="inv-search" class="inv-search" type="search" placeholder="Name suchen" autocomplete="off" aria-label="Rechnungen nach Namen suchen">
<button id="free-entry" class="free-entry" type="button">Freie Eingabe (IGeL, GOÄ)</button>
<div id="inbox" class="inbox-list"></div><p id="inbox-empty" class="muted small" hidden>Keine Rechnungen in dieser Ansicht.</p>
<p class="muted small start-hint">Rechnungen aus T2med erscheinen hier, sobald sie auf „SumUp-Rechnung“ gedruckt wurden. „Zurückstellen“ blendet eine Rechnung aus der Liste „Offen“ aus, ohne sie zu drucken.</p></aside>
<section class="start-right" aria-label="Kassieren"><iframe id="work" class="work-frame" title="Kassieren" src="about:blank"></iframe></section>
</div>
<footer>Kasse v<?= $version ?> · auf Grundlage von kienzle-sumup von Dr. Thomas Kienzle</footer>
<?php
$inhalt = (string)ob_get_clean();
$kopf = '<meta name="kienzle-csrf" content="' . $csrf . '"><link rel="stylesheet" href="' . $base . 'assets/style.css?v=' . $version . '"><link rel="stylesheet" href="' . $base . 'assets/rahmen.css?v=' . $version . '">'
    . '<script src="' . $base . 'assets/start.js?v=' . $version . '" defer></script>';
if (!method_exists($k, 'rahmen')) { header('Location: ' . Suite::base() . '/kassieren'); exit; }
echo $k->rahmen('Kasse', $inhalt, ['roh' => true, 'kopf' => $kopf, 'body' => ' data-base="' . $base . '"']);
