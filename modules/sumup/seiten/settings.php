<?php
declare(strict_types=1);
// Einstellungen und Abrechnung der Kasse. Anmeldung über die Suite; Admins des Moduls sehen alles, Mitarbeiter ihre Bereiche.
use KienzleSumup\Suite;
if (!Suite::settingsRights()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Für die Einstellungen der Kasse fehlt das Recht. Bitte den Admin fragen (Verwaltung → Benutzer der Suite).\n");
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$version = ks_h(trim((string)file_get_contents(dirname(__DIR__) . '/VERSION')));
$base = ks_h(Suite::base() . '/');
ob_start();
?>
<header class="seitenkopf"><div><p class="eyebrow">Kasse</p><h1>Einstellungen</h1></div><span id="mode" class="badge" hidden></span></header>
<p id="message" class="notice" role="status" hidden></p>

<div id="settings" hidden>
<nav id="tabs" class="tab-bar" role="tablist" aria-label="Bereiche"><span id="update-bell" hidden></span></nav>
<p id="warning" class="inline-error" hidden></p>
<div id="tab-panels"></div>
<div id="sections" hidden></div>
<section class="card settings-card" id="s-checklist"><h2>Einrichtung – Überblick</h2><p class="muted">Diese Schritte sind nur einmal nötig, geordnet nach dem Ort, an dem sie erledigt werden. Ein Klick auf einen Punkt öffnet die passende Einstellung.</p><div id="checklist"></div></section>
<section class="card settings-card" id="s-clients"><h2>Arbeitsplätze einrichten</h2>
<p>Auf dem Kassen-PC diese Seite öffnen, an der Suite anmelden und das passende Paket laden. Es enthält die Einrichtung des Starters, mit dem T2med die Kasse öffnet. Das Zertifikat der Suite vorher einmal einrichten: <a href="/hilfe/arbeitsplatz">Arbeitsplatz einrichten</a>. Dort steht auch, wie die virtuellen Drucker „SumUp-Rechnung“ und „Letterxpress“ am PC eingerichtet werden (<a href="/hilfe/arbeitsplatz#drucker">Schritt „Virtuelle Drucker“</a>).</p>
<div id="client-downloads" class="row-buttons"></div>
<div id="client-steps" class="steps"></div>
<p class="muted small">Die Pakete enthalten den Starter-Schlüssel der Praxis: nicht per E-Mail verschicken, nach der Einrichtung löschen. Der Starter prüft genau das aktuelle Serverzertifikat der Suite; bekommt der Server ein neues Zertifikat (neuer Name, Erneuerung nach gut zwei Jahren), das Paket auf jedem Kassen-PC erneut ausführen.</p></section>
<section class="card settings-card" id="invoice-printer-help" hidden><h2>Drucker „SumUp-Rechnung“</h2>
<div id="invoice-printer-status"></div>
</section>
<section class="card settings-card" id="letter-printer-help" hidden><h2>Drucker „Letterxpress“</h2>
<div id="letter-printer-status"></div>
</section>
<section class="card settings-card" id="s-inbox-list"><h2>Rechnungseingang</h2>
<p class="muted">Die Rechnungsliste steht jetzt unter „Vorgänge“ (Startseite der Kasse). Hier erscheinen nur Rechnungen, die der Drucker „SumUp-Rechnung“ angenommen, aber nicht übernommen hat.</p>
<div class="row-buttons"><button id="inbox-load" class="secondary" type="button">Aktualisieren</button></div>
<div id="inbox-box"><p class="muted">Wird geladen …</p></div></section>
<section class="card settings-card" id="s-catalog"><h2>Leistungen und GOÄ-Positionen</h2>
<p class="muted">Die an der Kasse auswählbaren Leistungen. Mit GOÄ-Ziffer, Faktor und Gebührenrahmen für den Beleg; der GOÄ-Rechner stellt den Faktor so ein, dass der gewünschte Endbetrag genau erreicht wird. Gelöschte Leistungen verschwinden aus der Auswahl, frühere Zahlungen behalten ihre Angaben.</p>
<div id="catalog-box"><p class="muted">Wird geladen …</p></div></section>
<section class="card settings-card" id="s-audit"><h2>Sicherheitsprotokoll</h2>
<p class="muted">Änderungen an dieser Einstellungsseite mit Zeit, Adresse des Geräts und Benutzer – nie mit Passwörtern oder Werten. Anmeldungen protokolliert die Suite (Verwaltung → Protokoll).</p>
<div class="row-buttons"><button id="audit-load" class="secondary" type="button">Letzte Vorgänge anzeigen</button></div>
<pre id="audit-list" class="audit-list" hidden></pre></section>
<p class="settings-foot"><a href="<?= $base ?>">Zur Kasse</a> · <a href="/">Zur Kienzle-Suite</a></p>
</div>
<footer>Kasse v<?= $version ?> · auf Grundlage von kienzle-sumup von Dr. Thomas Kienzle</footer>
<?php
$inhalt = (string)ob_get_clean();
$kopf = '<link rel="stylesheet" href="' . $base . 'assets/style.css?v=' . $version . '"><link rel="stylesheet" href="' . $base . 'assets/rahmen.css?v=' . $version . '">'
    . '<script src="' . $base . 'assets/goae-calc.js?v=' . $version . '" defer></script><script src="' . $base . 'assets/settings.js?v=' . $version . '" defer></script>';
$k = Suite::modul();
if (!method_exists($k, 'rahmen')) {   // ältere Basis ohne Rahmen: eigenständige Seite wie früher
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kasse · Einstellungen · Kienzle-Suite</title>' . $kopf . '</head><body data-base="' . $base . '"><main class="settings-main">' . $inhalt . '</main></body></html>';
    return;
}
echo $k->rahmen('Kasse – Einstellungen', $inhalt, ['roh' => true, 'kopf' => $kopf, 'body' => ' data-base="' . $base . '"']);
