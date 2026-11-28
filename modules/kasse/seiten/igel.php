<?php
declare(strict_types=1);
// IGeL- und Leistungsliste der Kasse (eigener Reiter neben den Einstellungen): GOÄ-Positionen, Preise, „Unterschrift erforderlich“.
use KienzleKasse\Suite;
if (!in_array('catalog', Suite::settingsRights(), true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Für die Leistungsliste der Kasse fehlt das Recht „Leistungen bearbeiten“. Bitte den Admin fragen (Verwaltung → Benutzer von Praxisherz).\n");
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$version = ks_h(trim((string)file_get_contents(dirname(__DIR__) . '/VERSION')));
$base = ks_h(Suite::base() . '/');
ob_start();
?>
<header class="seitenkopf"><div><p class="eyebrow">Kasse</p><h1>IGeL und Leistungen</h1></div><?= \KienzleKasse\Kopf::knoepfe('igel', $base, true, Suite::settingsRights() !== []) ?></header>
<p id="message" class="notice" role="status" hidden></p>
<p id="lade" class="muted" role="status">Wird geladen …</p>
<div id="settings" hidden>
<p id="warning" class="inline-error" hidden></p>
<div id="tab-panels"></div>
<section class="card settings-card" id="s-catalog"><h2>Leistungen und GOÄ-Positionen</h2>
<p class="muted">Die an der Kasse auswählbaren Leistungen. Mit GOÄ-Ziffer, Faktor und Gebührenrahmen für den Beleg; der GOÄ-Rechner stellt den Faktor so ein, dass der gewünschte Endbetrag genau erreicht wird. Gelöschte Leistungen verschwinden aus der Auswahl, frühere Zahlungen behalten ihre Angaben.</p>
<div id="catalog-box"><p class="muted">Wird geladen …</p></div></section>
</div>
<?php
$inhalt = (string)ob_get_clean();
$kopf = '<link rel="stylesheet" href="' . $base . 'assets/style.css?v=' . $version . '"><link rel="stylesheet" href="' . $base . 'assets/rahmen.css?v=' . $version . '">'
    . '<script src="' . $base . 'assets/goae-calc.js?v=' . $version . '" defer></script><script src="' . $base . 'assets/settings.js?v=' . $version . '" defer></script>';
$k = Suite::modul();
if (!method_exists($k, 'rahmen')) { header('Location: ' . Suite::base() . '/einstellungen'); exit; }
echo $k->rahmen('Kasse – IGeL', $inhalt, ['roh' => true, 'kopf' => $kopf, 'body' => ' data-base="' . $base . '" data-seite="igel"']);
