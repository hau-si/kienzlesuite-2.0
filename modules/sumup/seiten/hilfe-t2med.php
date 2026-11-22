<?php
declare(strict_types=1);
// Anleitung „T2med einrichten“ (docs/T2MED-EINRICHTUNG.md) als Seite. Einfache Umwandlung: Überschriften, Absätze,
// nummerierte Schritte, Codeblöcke, Bilder aus docs/screenshots. Alles läuft über ks_h(); kein HTML aus der Datei.
$md = (string)file_get_contents(dirname(__DIR__) . '/docs/T2MED-EINRICHTUNG.md');
$base = KienzleSumup\Suite::base() . '/';
$inline = static function (string $t): string {
    $t = ks_h($t);
    $t = (string)preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
    $t = (string)preg_replace('/\*(.+?)\*/', '<em>$1</em>', $t);
    return (string)preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
};
$html = ''; $code = null; $para = [];
$flush = static function () use (&$para, &$html, $inline): void { if ($para) { $html .= '<p>' . $inline(implode(' ', $para)) . '</p>'; $para = []; } };
foreach (preg_split('/\R/', $md) as $line) {
    $t = trim($line);
    if ($code !== null) {
        if (str_starts_with($t, '```')) { $html .= '<pre class="audit-list">' . ks_h(implode("\n", $code)) . '</pre>'; $code = null; } else $code[] = $t;
        continue;
    }
    if (str_starts_with($t, '```')) { $flush(); $code = []; continue; }
    if ($t === '') { $flush(); continue; }
    if (preg_match('/^(#{1,3}) (.+)$/', $t, $m)) { $flush(); $n = strlen($m[1]); $html .= "<h$n>" . $inline($m[2]) . "</h$n>"; continue; }
    if (preg_match('#^!\[([^\]]*)\]\(screenshots/([a-z0-9-]{1,60}\.png)\)$#', $t, $m)) {
        $flush(); $html .= '<p><img class="guide-image" src="' . ks_h($base . 'hilfe/bilder/' . $m[2]) . '" alt="' . ks_h($m[1]) . '" loading="lazy"></p>'; continue;
    }
    if (preg_match('/^(\d+)\. (.+)$/', $t, $m)) { $flush(); $para[] = $m[1] . '. ' . $m[2]; continue; }
    $para[] = $t;
}
$flush();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; frame-ancestors 'none'; base-uri 'none'");
echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>T2med einrichten · Kasse</title>'
    . '<link rel="stylesheet" href="' . ks_h($base . 'assets/style.css') . '"></head><body><main class="settings-main"><section class="card guide">' . $html
    . '<p><a href="' . ks_h($base . 'settings.php') . '">Zurück zu den Einstellungen</a></p></section></main></body></html>';
