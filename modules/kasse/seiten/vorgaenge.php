<?php
declare(strict_types=1);
// Vorgänge: alle T2med-Rechnungen und IGeL-Zahlungen eines Tages, einer Woche, eines Monats oder Jahres als Liste mit Excel-Export und Abhaken.
use KienzleKasse\Suite;
$k = Suite::modul();
if (!Suite::can('kassieren') && !Suite::can('export') && !Suite::can('paid') && !Suite::can('periods') && !Suite::can('storno')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Für die Vorgänge fehlt ein Recht der Kasse (z. B. „Kassieren“). Bitte den Admin fragen (Verwaltung → Benutzer von Praxisherz).\n");
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$version = ks_h(trim((string)file_get_contents(dirname(__DIR__) . '/VERSION')));
$base = ks_h(Suite::base() . '/');
$csrf = ks_h($k->csrf());
ob_start();
?>
<header class="seitenkopf"><div><p class="eyebrow">Kasse</p><h1>Vorgänge</h1></div><?= \KienzleKasse\Kopf::knoepfe('vorgaenge', $base, in_array('catalog', Suite::settingsRights(), true), Suite::settingsRights() !== []) ?></header>
<section class="card vorgaenge">
<div class="vg-bar">
<div class="start-tabs" id="vg-art" role="tablist"></div>
<span class="vg-nav"><button type="button" id="vg-prev" class="secondary" aria-label="Zurück">◀</button><input id="vg-date" type="date" aria-label="Tag im Zeitraum"><button type="button" id="vg-next" class="secondary" aria-label="Weiter">▶</button><button type="button" id="vg-today" class="secondary">Heute</button></span>
</div>
<h2 id="vg-label"></h2>
<div class="vg-filter"><div class="start-tabs" id="vg-kind" role="tablist"></div><div class="start-tabs" id="vg-pay" role="tablist" aria-label="Zahlungsart"></div><div class="start-tabs" id="vg-status" role="tablist"></div><input id="vg-search" type="search" placeholder="Name oder Nummer" autocomplete="off" aria-label="Suchen"></div>
<p id="vg-sum" class="muted"></p>
<div class="vg-actions"><a id="vg-export" class="button primary" href="#">⬇ Excel-Liste</a><button type="button" id="vg-done" class="secondary">T2med: Auswahl abhaken</button><button type="button" id="vg-undone" class="secondary">Haken entfernen</button></div>
<div class="vg-scroll"><table class="fw-table" id="vg-table"><thead><tr><th><input type="checkbox" id="vg-all" aria-label="Alle T2med-Rechnungen auswählen"></th><th data-key="date">Datum</th><th data-key="art">Art</th><th data-key="number">Nummer</th><th data-key="patient">Patient</th><th data-key="method">Zahlung</th><th data-key="status">Status</th><th class="num" data-key="invoice_cents">Betrag</th><th data-key="done_date">In T2med</th><th id="vg-th-post" hidden>Post</th><th id="vg-th-storno" hidden>Storno</th></tr></thead><tbody id="vg-body"></tbody></table></div>
<p id="vg-empty" class="muted" hidden>Keine Vorgänge in diesem Zeitraum.</p>
<p class="muted small">„In T2med“: Haken setzen, sobald die bezahlte Rechnung in T2med als abgerechnet markiert ist. Stornierte Rechnungen bleiben mit Stornonummer in der Liste und zählen nicht als bezahlt. Ein Klick auf eine Spaltenüberschrift sortiert die Liste (noch einmal klicken: umgekehrt).</p>
</section>
<footer>Kasse v<?= $version ?></footer>
<?php
$inhalt = (string)ob_get_clean();
$kopf = '<meta name="kienzle-csrf" content="' . $csrf . '"><link rel="stylesheet" href="' . $base . 'assets/style.css?v=' . $version . '"><link rel="stylesheet" href="' . $base . 'assets/rahmen.css?v=' . $version . '">'
    . '<script src="' . $base . 'assets/vorgaenge.js?v=' . $version . '" defer></script>';
echo $k->rahmen('Vorgänge', $inhalt, ['roh' => true, 'kopf' => $kopf, 'body' => ' data-base="' . $base . '"']);
