<?php
/**
 * Plugin Name: Praxisherz-Hinweise
 * Description: Zeigt Mitteilungen aus Praxisherz (Vertretung, Impfstoff lieferbar, Sonderöffnungszeiten, freier Text) auf der Homepage an: als Leiste oben, Kasten auf der Startseite, Fenster beim Öffnen oder an der Stelle des Kurzcodes [praxisherz_hinweise].
 * Version: 0.2.0
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Author: Praxisherz
 * License: MIT
 * Text Domain: praxisherz-hinweise
 *
 * Der Praxisserver schickt die Liste der Mitteilungen per POST an /?rest_route=/praxisherz/v1/hinweise, unterschrieben mit
 * HMAC-SHA256 über „Zeit\nKörper“ (Kopfzeilen X-Praxisherz-Zeit und X-Praxisherz-Signatur). Den Schlüssel erzeugt dieses Plugin;
 * er steht unter Einstellungen → Praxisherz-Hinweise und wird in Praxisherz unter Homepage → Einrichtung eingetragen.
 * Das Plugin speichert nur die Texte der Mitteilungen, keine Daten von Besuchern; es setzt keine Cookies.
 */

if (!defined('ABSPATH')) exit;

define('PRAXISHERZ_HINWEISE_VERSION', '0.2.0');
define('PRAXISHERZ_HINWEISE_ARTEN', ['vertretung', 'impfstoff', 'oeffnungszeiten', 'frei']);
define('PRAXISHERZ_HINWEISE_ORTE', ['leiste', 'kasten', 'fenster', 'kurzcode']);
define('PRAXISHERZ_HINWEISE_MAX_KOERPER', 262144);
define('PRAXISHERZ_HINWEISE_FARBEN', ['blau', 'gruen', 'petrol', 'ocker', 'rot', 'violett', 'grau']);
define('PRAXISHERZ_HINWEISE_FARBE_ZUR_ART', ['vertretung' => 'blau', 'impfstoff' => 'gruen', 'oeffnungszeiten' => 'ocker', 'frei' => 'grau']);
define('PRAXISHERZ_HINWEISE_SYMBOL_ZUR_ART', ['vertretung' => 'praxis', 'impfstoff' => 'spritze', 'oeffnungszeiten' => 'uhr', 'frei' => 'info']);
/** Symbole (gleiche Namen und Pfade wie im Modul Homepage von Praxisherz). */
define('PRAXISHERZ_HINWEISE_SYMBOLE', [
    'keins' => '',
    'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    'achtung' => '<path d="M12 3.5 21.5 20h-19z"/><path d="M12 9.5v5M12 17.2v.1"/>',
    'praxis' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M12 11v6M9 14h6"/>',
    'spritze' => '<path d="M18 2l4 4M20 4l-3 3"/><path d="M17 7l-9.5 9.5-3-3L14 4z"/><path d="M11 7l2 2M8 10l2 2M4.5 19.5l2-2"/>',
    'kalender' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    'uhr' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'telefon' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2"/>',
    'urlaub' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'herz' => '<path d="M12 20s-7-4.4-9-8.6A4.8 4.8 0 0112 7a4.8 4.8 0 019 4.4C19 15.6 12 20 12 20z"/>',
]);

// ---- Schlüssel ---------------------------------------------------------------------------------------------------------

/** Schlüssel (64 Hexzeichen); wird beim ersten Aufruf erzeugt. */
function praxisherz_hinweise_schluessel(): string {
    $s = (string)get_option('praxisherz_hinweise_schluessel', '');
    if (!preg_match('/^[0-9a-f]{64}$/D', $s)) {
        $s = bin2hex(random_bytes(32));
        update_option('praxisherz_hinweise_schluessel', $s, false);
    }
    return $s;
}

register_activation_hook(__FILE__, 'praxisherz_hinweise_schluessel');
register_uninstall_hook(__FILE__, 'praxisherz_hinweise_entfernen');

function praxisherz_hinweise_entfernen(): void {
    foreach (['praxisherz_hinweise_schluessel', 'praxisherz_hinweise', 'praxisherz_hinweise_zuletzt', 'praxisherz_hinweise_kasten_ziel', 'praxisherz_hinweise_kasten_lage'] as $o) delete_option($o);
}

// ---- Empfang ------------------------------------------------------------------------------------------------------------

add_action('rest_api_init', function () {
    register_rest_route('praxisherz/v1', '/hinweise', [
        'methods' => 'POST',
        'callback' => 'praxisherz_hinweise_empfangen',
        // Geprüft wird die Unterschrift im Rückruf (der Praxisserver ist kein WordPress-Benutzer).
        'permission_callback' => '__return_true',
    ]);
});

/** Unterschrift und Zeit prüfen; liefert null oder den Grund der Ablehnung. */
function praxisherz_hinweise_pruefen(string $koerper, string $zeit, string $signatur, string $schluessel, int $zuletzt, int $jetzt): ?string {
    if (!preg_match('/^[0-9]{9,11}$/D', $zeit)) return 'Zeitangabe fehlt';
    if (abs($jetzt - (int)$zeit) > 300) return 'Uhrzeit von Praxisserver und Homepage weicht mehr als 5 Minuten ab';
    if ((int)$zeit < $zuletzt) return 'veraltete Übertragung';
    if (!preg_match('/^[0-9a-f]{64}$/D', $signatur) || !hash_equals(hash_hmac('sha256', $zeit . "\n" . $koerper, $schluessel), $signatur)) return 'Schlüssel stimmt nicht';
    return null;
}

function praxisherz_hinweise_text($w, int $max): string {
    if (!is_string($w)) return '';
    $w = wp_check_invalid_utf8($w);
    $w = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $w);
    return function_exists('mb_substr') ? mb_substr((string)$w, 0, $max) : substr((string)$w, 0, $max);
}

function praxisherz_hinweise_datum($w): string {
    return is_string($w) && preg_match('/^20[0-9]{2}-[01][0-9]-[0-3][0-9]$/D', $w) ? $w : '';
}

/** Empfangene Liste säubern (nur bekannte Felder, Längen begrenzt); null bei falschem Aufbau. */
function praxisherz_hinweise_bereinigen($daten): ?array {
    if (!is_array($daten) || ($daten['format'] ?? null) !== 1 || !is_array($daten['hinweise'] ?? null)) return null;
    $out = [];
    foreach (array_slice($daten['hinweise'], 0, 50) as $h) {
        if (!is_array($h) || !is_string($h['id'] ?? null) || !preg_match('/^m[0-9]{1,9}$/D', $h['id'])) continue;
        if (!in_array($h['art'] ?? '', PRAXISHERZ_HINWEISE_ARTEN, true)) continue;
        $orte = array_values(array_intersect(PRAXISHERZ_HINWEISE_ORTE, is_array($h['orte'] ?? null) ? $h['orte'] : []));
        if (!$orte) continue;
        $punkte = [];
        foreach (array_slice(is_array($h['punkte'] ?? null) ? $h['punkte'] : [], 0, 31) as $p) {
            $p = praxisherz_hinweise_text($p, 300);
            if ($p !== '') $punkte[] = $p;
        }
        $out[] = ['id' => $h['id'], 'art' => $h['art'], 'titel' => praxisherz_hinweise_text($h['titel'] ?? '', 200), 'kurz' => praxisherz_hinweise_text($h['kurz'] ?? '', 600),
            'text' => praxisherz_hinweise_text($h['text'] ?? '', 1000), 'punkte' => $punkte, 'von' => praxisherz_hinweise_datum($h['von'] ?? ''),
            'bis' => praxisherz_hinweise_datum($h['bis'] ?? ''), 'orte' => $orte,
            'farbe' => in_array($h['farbe'] ?? '', PRAXISHERZ_HINWEISE_FARBEN, true) ? $h['farbe'] : PRAXISHERZ_HINWEISE_FARBE_ZUR_ART[$h['art']],
            'symbol' => is_string($h['symbol'] ?? null) && array_key_exists($h['symbol'], PRAXISHERZ_HINWEISE_SYMBOLE) ? $h['symbol'] : PRAXISHERZ_HINWEISE_SYMBOL_ZUR_ART[$h['art']]];
    }
    return $out;
}

function praxisherz_hinweise_empfangen($anfrage) {
    $koerper = (string)$anfrage->get_body();
    if (strlen($koerper) > PRAXISHERZ_HINWEISE_MAX_KOERPER) return new WP_REST_Response(['ok' => false, 'message' => 'zu groß'], 413);
    $grund = praxisherz_hinweise_pruefen($koerper, (string)$anfrage->get_header('x_praxisherz_zeit'), strtolower((string)$anfrage->get_header('x_praxisherz_signatur')),
        praxisherz_hinweise_schluessel(), (int)get_option('praxisherz_hinweise_zuletzt', 0), time());
    if ($grund !== null) return new WP_REST_Response(['ok' => false, 'message' => $grund], 403);
    $daten = json_decode($koerper, true);
    $hinweise = praxisherz_hinweise_bereinigen($daten);
    if ($hinweise === null) return new WP_REST_Response(['ok' => false, 'message' => 'unbekanntes Format'], 400);
    update_option('praxisherz_hinweise', ['empfangen' => time(), 'hinweise' => $hinweise], true);
    update_option('praxisherz_hinweise_zuletzt', (int)$daten['zeit'], false);
    praxisherz_hinweise_cache_leeren();
    return new WP_REST_Response(['ok' => true, 'anzahl' => count($hinweise)], 200);
}

/** Seitencache gängiger Plugins leeren, damit die Änderung sofort sichtbar ist. */
function praxisherz_hinweise_cache_leeren(): void {
    if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();          // WP Super Cache
    if (function_exists('w3tc_flush_all')) w3tc_flush_all();                      // W3 Total Cache
    if (function_exists('rocket_clean_domain')) rocket_clean_domain();            // WP Rocket
    do_action('litespeed_purge_all');                                            // LiteSpeed Cache
    if (function_exists('wp_cache_flush')) wp_cache_flush();
}

// ---- Ausgabe ------------------------------------------------------------------------------------------------------------

function praxisherz_hinweise_heute(): string {
    return function_exists('wp_date') ? wp_date('Y-m-d') : current_time('Y-m-d');
}

/** Mitteilungen für einen Ort (auch solche außerhalb des Zeitraums; sie bekommen „hidden“, das Skript prüft im Browser erneut). */
function praxisherz_hinweise_fuer(string $ort): array {
    $d = get_option('praxisherz_hinweise', []);
    $out = [];
    foreach (is_array($d) && is_array($d['hinweise'] ?? null) ? $d['hinweise'] : [] as $h) if (in_array($ort, $h['orte'], true)) $out[] = $h;
    return $out;
}

function praxisherz_hinweise_sichtbar(array $h, string $heute): bool {
    return ($h['von'] === '' || $h['von'] <= $heute) && ($h['bis'] === '' || $h['bis'] >= $heute);
}

function praxisherz_hinweise_svg(string $symbol): string {
    $pfad = PRAXISHERZ_HINWEISE_SYMBOLE[$symbol] ?? '';
    return $pfad === '' ? '' : '<svg class="ph-symbol" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $pfad . '</svg>';
}

function praxisherz_hinweise_html(array $h, string $ort, ?string $heute = null): string {
    $heute = $heute ?? praxisherz_hinweise_heute();
    // Ältere Übertragungen (Plugin 0.1) ohne Farbe und Symbol: passend zur Art.
    $farbe = $h['farbe'] ?? (PRAXISHERZ_HINWEISE_FARBE_ZUR_ART[$h['art']] ?? 'grau');
    $svg = praxisherz_hinweise_svg($h['symbol'] ?? (PRAXISHERZ_HINWEISE_SYMBOL_ZUR_ART[$h['art']] ?? 'keins'));
    $attr = ' class="ph-hinweis ph-' . esc_attr($ort) . ' ph-art-' . esc_attr($h['art']) . ' ph-farbe-' . esc_attr($farbe) . '" data-id="' . esc_attr($h['id']) . '" data-von="' . esc_attr($h['von'])
        // Fenster erst durch das Skript (Schließen braucht es ohnehin).
        . '" data-bis="' . esc_attr($h['bis']) . '"' . ($ort !== 'fenster' && praxisherz_hinweise_sichtbar($h, $heute) ? '' : ' hidden');
    if ($ort === 'leiste') return '<div' . $attr . ' role="note">' . $svg . '<span class="ph-kurz">' . esc_html($h['kurz']) . '</span></div>';
    $inhalt = '';
    if ($h['titel'] !== '') $inhalt .= '<strong class="ph-titel" id="ph-titel-' . esc_attr($ort . '-' . $h['id']) . '">' . $svg . '<span>' . esc_html($h['titel']) . '</span></strong>';
    if ($h['punkte']) $inhalt .= '<ul class="ph-punkte"><li>' . implode('</li><li>', array_map('esc_html', $h['punkte'])) . '</li></ul>';
    if ($h['text'] !== '') $inhalt .= '<p class="ph-text">' . nl2br(esc_html($h['text'])) . '</p>';
    if ($ort === 'fenster') {
        $stand = substr(md5($h['kurz'] . $h['text'] . implode('|', $h['punkte'])), 0, 8);
        return '<div' . $attr . ' data-stand="' . $stand . '" role="dialog" aria-modal="true" aria-labelledby="ph-titel-fenster-' . esc_attr($h['id']) . '">'
            . '<div class="ph-fenster-box"><button type="button" class="ph-zu" aria-label="Schließen">&times;</button>' . $inhalt . '</div></div>';
    }
    return '<div' . $attr . '>' . $inhalt . '</div>';
}

function praxisherz_hinweise_block(string $ort, string $klasse = '', string $attribute = ''): string {
    $liste = praxisherz_hinweise_fuer($ort);
    if (!$liste) return '';
    $html = '';
    foreach ($liste as $h) $html .= praxisherz_hinweise_html($h, $ort);
    return '<div class="ph-block ph-block-' . esc_attr($ort) . ($klasse !== '' ? ' ' . esc_attr($klasse) : '') . '"' . $attribute . '>' . $html . '</div>';
}

/** Stelle für den Kasten: CSS-Selektor (leer = automatisch) und Lage dazu. */
function praxisherz_hinweise_kasten_stelle(): array {
    $ziel = (string)get_option('praxisherz_hinweise_kasten_ziel', '');
    $lage = (string)get_option('praxisherz_hinweise_kasten_lage', 'anfang');
    return [preg_match('/^[A-Za-z0-9#.\-_ >:\[\]=",*]{0,200}$/D', $ziel) ? $ziel : '', in_array($lage, ['vor', 'anfang', 'ende', 'nach'], true) ? $lage : 'anfang'];
}

add_action('wp_enqueue_scripts', function () {
    $d = get_option('praxisherz_hinweise', []);
    if (empty($d['hinweise'])) return;
    wp_enqueue_style('praxisherz-hinweise', plugins_url('hinweise.css', __FILE__), [], PRAXISHERZ_HINWEISE_VERSION);
    wp_enqueue_script('praxisherz-hinweise', plugins_url('hinweise.js', __FILE__), [], PRAXISHERZ_HINWEISE_VERSION, true);
});

$GLOBALS['praxisherz_hinweise_leiste'] = false;
add_action('wp_body_open', function () {
    $GLOBALS['praxisherz_hinweise_leiste'] = true;
    echo praxisherz_hinweise_block('leiste'); // phpcs:ignore WordPress.Security.EscapeOutput -- in praxisherz_hinweise_html maskiert
});

add_action('wp_footer', function () {
    // Themes ohne wp_body_open: Leiste hier ausgeben, das Skript setzt sie an den Anfang der Seite.
    if (!$GLOBALS['praxisherz_hinweise_leiste']) echo praxisherz_hinweise_block('leiste', 'ph-nachreichen'); // phpcs:ignore WordPress.Security.EscapeOutput
    echo praxisherz_hinweise_block('fenster'); // phpcs:ignore WordPress.Security.EscapeOutput
    // Startseiten, die das Theme selbst zusammenbaut (ohne den Inhalt einer Seite): Kasten hier ausgeben, das Skript setzt ihn an
    // die eingestellte Stelle bzw. automatisch nach den ersten Abschnitt.
    if (!$GLOBALS['praxisherz_hinweise_kasten'] && is_front_page()) {
        $GLOBALS['praxisherz_hinweise_kasten'] = true;
        [$ziel, $lage] = praxisherz_hinweise_kasten_stelle();
        echo praxisherz_hinweise_block('kasten', 'ph-nachreichen-kasten', ' hidden data-ziel="' . esc_attr($ziel) . '" data-lage="' . esc_attr($lage) . '"'); // phpcs:ignore WordPress.Security.EscapeOutput
    }
});

$GLOBALS['praxisherz_hinweise_kasten'] = false;
add_filter('the_content', function ($inhalt) {
    // Nur auf einer statischen Startseite (Seite) und ohne eingestellte Stelle; sonst setzt das Skript den Kasten.
    if ($GLOBALS['praxisherz_hinweise_kasten'] || !is_front_page() || !is_page() || !in_the_loop() || !is_main_query() || praxisherz_hinweise_kasten_stelle()[0] !== '') return $inhalt;
    $GLOBALS['praxisherz_hinweise_kasten'] = true;
    return praxisherz_hinweise_block('kasten') . $inhalt;
}, 5);

add_shortcode('praxisherz_hinweise', function () {
    return praxisherz_hinweise_block('kurzcode');
});

// ---- Einstellungsseite --------------------------------------------------------------------------------------------------

add_action('admin_menu', function () {
    add_options_page('Praxisherz-Hinweise', 'Praxisherz-Hinweise', 'manage_options', 'praxisherz-hinweise', 'praxisherz_hinweise_seite');
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=praxisherz-hinweise')) . '">Einstellungen</a>');
    return $links;
});

add_action('admin_post_praxisherz_hinweise_neuer_schluessel', function () {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    check_admin_referer('praxisherz_hinweise_neuer_schluessel');
    update_option('praxisherz_hinweise_schluessel', '', false);
    praxisherz_hinweise_schluessel();
    wp_safe_redirect(admin_url('options-general.php?page=praxisherz-hinweise&neu=1'));
    exit;
});

add_action('admin_post_praxisherz_hinweise_stelle', function () {
    if (!current_user_can('manage_options')) wp_die('Keine Berechtigung.');
    check_admin_referer('praxisherz_hinweise_stelle');
    $ziel = trim(wp_unslash((string)($_POST['ziel'] ?? '')));
    if (!preg_match('/^[A-Za-z0-9#.\-_ >:\[\]=",*]{0,200}$/D', $ziel)) $ziel = '';
    $lage = (string)($_POST['lage'] ?? 'anfang');
    update_option('praxisherz_hinweise_kasten_ziel', $ziel, true);
    update_option('praxisherz_hinweise_kasten_lage', in_array($lage, ['vor', 'anfang', 'ende', 'nach'], true) ? $lage : 'anfang', true);
    praxisherz_hinweise_cache_leeren();
    wp_safe_redirect(admin_url('options-general.php?page=praxisherz-hinweise&stelle=1'));
    exit;
});

function praxisherz_hinweise_seite(): void {
    if (!current_user_can('manage_options')) return;
    $d = get_option('praxisherz_hinweise', []);
    $liste = is_array($d['hinweise'] ?? null) ? $d['hinweise'] : [];
    $orte = ['leiste' => 'Leiste oben', 'kasten' => 'Kasten Startseite', 'fenster' => 'Fenster', 'kurzcode' => 'Kurzcode'];
    echo '<div class="wrap"><h1>Praxisherz-Hinweise</h1>';
    if (!empty($_GET['stelle'])) echo '<div class="notice notice-success"><p>Stelle für den Kasten gespeichert.</p></div>';
    if (!empty($_GET['neu'])) echo '<div class="notice notice-warning"><p>Neuer Schlüssel erzeugt. Bitte in Praxisherz unter Homepage → Einrichtung neu eintragen.</p></div>';
    echo '<h2>Schlüssel für Praxisherz</h2><p>In Praxisherz unter <strong>Homepage → Einrichtung</strong> zusammen mit der Adresse dieser Homepage eintragen.</p>'
        . '<p><input type="text" class="large-text code" readonly value="' . esc_attr(praxisherz_hinweise_schluessel()) . '" onfocus="this.select()"></p>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="praxisherz_hinweise_neuer_schluessel">';
    wp_nonce_field('praxisherz_hinweise_neuer_schluessel');
    submit_button('Neuen Schlüssel erzeugen', 'secondary', 'submit', false, ['onclick' => "return confirm('Der alte Schlüssel gilt dann nicht mehr. Fortfahren?')"]);
    echo '</form><h2>Zuletzt empfangen</h2>';
    if (empty($d['empfangen'])) echo '<p>Noch nichts empfangen.</p>';
    else {
        echo '<p>' . esc_html(wp_date('d.m.Y H:i', (int)$d['empfangen'])) . ' – ' . count($liste) . ' Mitteilungen.</p>';
        if ($liste) {
            echo '<table class="widefat striped"><thead><tr><th>Mitteilung</th><th>Zeitraum</th><th>Ort</th></tr></thead><tbody>';
            foreach ($liste as $h) {
                $zr = trim(($h['von'] !== '' ? 'ab ' . $h['von'] : '') . ' ' . ($h['bis'] !== '' ? 'bis ' . $h['bis'] : '')) ?: 'ohne Ende';
                echo '<tr><td>' . esc_html($h['kurz']) . '</td><td>' . esc_html($zr) . '</td><td>' . esc_html(implode(', ', array_map(function ($o) use ($orte) { return $orte[$o]; }, $h['orte']))) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }
    [$ziel, $lage] = praxisherz_hinweise_kasten_stelle();
    $lagen = ['vor' => 'vor dem Element', 'anfang' => 'am Anfang im Element', 'ende' => 'am Ende im Element', 'nach' => 'nach dem Element'];
    echo '<h2>Stelle für den Kasten auf der Startseite</h2><p>Leer lassen: automatisch (über dem Inhalt der Startseite bzw. nach dem ersten Abschnitt der Seite). '
        . 'Sonst den CSS-Selektor eines Elements der Startseite eintragen, z. B. <code>#aktuelles</code>.</p>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="praxisherz_hinweise_stelle">';
    wp_nonce_field('praxisherz_hinweise_stelle');
    echo '<p><input type="text" name="ziel" class="regular-text code" maxlength="200" value="' . esc_attr($ziel) . '" placeholder="automatisch"> <select name="lage">';
    foreach ($lagen as $w => $t) echo '<option value="' . esc_attr($w) . '"' . selected($lage, $w, false) . '>' . esc_html($t) . '</option>';
    echo '</select> ';
    submit_button('Speichern', 'secondary', 'submit', false);
    echo '</p></form>';
    echo '<h2>Orte auf der Homepage</h2><ul style="list-style:disc;padding-left:20px">'
        . '<li><strong>Leiste oben:</strong> auf allen Seiten ganz oben.</li>'
        . '<li><strong>Kasten Startseite:</strong> über dem Inhalt der Startseite oder an der eingestellten Stelle.</li>'
        . '<li><strong>Fenster:</strong> öffnet sich beim Besuch einmal je Sitzung.</li>'
        . '<li><strong>Kurzcode:</strong> an der Stelle, an der <code>[praxisherz_hinweise]</code> in einer Seite oder einem Widget steht.</li></ul></div>';
}
