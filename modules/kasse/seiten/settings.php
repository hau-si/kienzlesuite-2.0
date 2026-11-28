<?php
declare(strict_types=1);
// Einstellungen und Abrechnung der Kasse. Anmeldung über Praxisherz; Admins des Moduls sehen alles, Mitarbeiter ihre Bereiche.
use KienzleKasse\Suite;
if (!Suite::settingsRights()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Für die Einstellungen der Kasse fehlt das Recht. Bitte den Admin fragen (Praxis → Benutzer und Gruppen von Praxisherz).\n");
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$version = ks_h(trim((string)file_get_contents(dirname(__DIR__) . '/VERSION')));
$base = ks_h(Suite::base() . '/');
ob_start();
?>
<header class="seitenkopf"><div><p class="eyebrow">Kasse</p><h1>Einstellungen</h1></div><?= \KienzleKasse\Kopf::knoepfe('einstellungen', $base, in_array('catalog', Suite::settingsRights(), true), true, '<span id="mode" class="badge" hidden></span>') ?></header>
<p id="message" class="notice" role="status" hidden></p>

<p id="lade" class="muted" role="status">Wird geladen …</p>
<div id="settings" hidden>
<nav id="tabs" hidden></nav>
<p id="warning" class="inline-error" hidden></p>
<div id="tab-panels"></div>
<div id="sections" hidden></div>
<section class="card settings-card" id="s-checklist"><details class="fold" id="checklist-fold" open><summary><h2>Einrichtung – Überblick</h2><span class="tag" id="checklist-tag"></span></summary><p class="muted">Diese Schritte sind nur einmal nötig, geordnet nach dem Ort, an dem sie erledigt werden. Ein Klick auf einen Punkt öffnet die passende Einstellung.</p><div id="checklist"></div></details></section>
<section class="card settings-card" id="s-clients"><h2>Arbeitsplätze: Praxisherz-Agent</h2>
<p>Den Starter, mit dem T2med die Kasse öffnet, das Zertifikat von Praxisherz und die Drucker „vKasse“ und „vBriefversand“ richtet der Praxisherz-Agent auf jedem Windows-PC selbst ein (<a href="/hilfe/arbeitsplatz" target="_top">Einrichten → Arbeitsplätze</a>). Ein Paket für alle Module.</p>
<p class="row-buttons"><a class="button primary" href="/hilfe/arbeitsplatz#paket" target="_top">Paket für Arbeitsplätze</a></p>
</section>
<section class="card settings-card" id="invoice-printer-help" hidden><h2>Drucker „vKasse“</h2>
<div id="invoice-printer-status"></div>
</section>
<section class="card settings-card printer-card" id="letter-printer-help" hidden><details class="fold" id="letter-printer-fold"><summary><span class="mark-icon open" id="letter-printer-mark" aria-hidden="true">○</span><h2>Drucker „vBriefversand“</h2><span class="tag" id="letter-printer-tag"></span></summary>
<div id="letter-printer-status"></div>
</details></section>
<section class="card settings-card" id="s-inbox-list"><h2>Rechnungseingang</h2>
<p class="muted">Die Rechnungsliste steht jetzt unter „Vorgänge“ (Startseite der Kasse). Hier erscheinen nur Rechnungen, die der Drucker „vKasse“ angenommen, aber nicht übernommen hat.</p>
<div class="row-buttons"><button id="inbox-load" class="secondary" type="button">Aktualisieren</button></div>
<div id="inbox-box"><p class="muted">Wird geladen …</p></div></section>
<section class="card settings-card" id="s-audit"><h2>Sicherheitsprotokoll</h2>
<p class="muted">Änderungen an dieser Einstellungsseite mit Zeit, Adresse des Geräts und Benutzer – nie mit Passwörtern oder Werten. Anmeldungen protokolliert Praxisherz (Betrieb → Sicherheit, Protokoll).</p>
<div class="row-buttons"><button id="audit-load" class="secondary" type="button">Letzte Vorgänge anzeigen</button></div>
<pre id="audit-list" class="audit-list" hidden></pre></section>
<p class="settings-foot"><a href="<?= $base ?>">Zur Kasse</a> · <a href="/">Zu Praxisherz</a></p>
</div>
<?php
$inhalt = (string)ob_get_clean();
$kopf = '<link rel="stylesheet" href="' . $base . 'assets/style.css?v=' . $version . '"><link rel="stylesheet" href="' . $base . 'assets/rahmen.css?v=' . $version . '">'
    . '<script src="' . $base . 'assets/goae-calc.js?v=' . $version . '" defer></script><script src="' . $base . 'assets/settings.js?v=' . $version . '" defer></script>';
$k = Suite::modul();
if (!method_exists($k, 'rahmen')) {   // ältere Basis ohne Rahmen: eigenständige Seite wie früher
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kasse · Einstellungen · Praxisherz</title>' . $kopf . '</head><body data-base="' . $base . '"><main class="settings-main">' . $inhalt . '</main></body></html>';
    return;
}
echo $k->rahmen('Kasse – Einstellungen', $inhalt, ['roh' => true, 'kopf' => $kopf, 'body' => ' data-base="' . $base . '"']);
