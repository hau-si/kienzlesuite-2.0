<?php
declare(strict_types=1);
/**
 * Kleinster Nachbau der WordPress-Funktionen, die das Plugin „Praxisherz-Hinweise“ benutzt (nur für Tests).
 * Optionen liegen in der JSON-Datei aus WP_OPTIONEN (oder im Speicher). Kein echtes WordPress.
 */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['wp_haken'] = [];
$GLOBALS['wp_routen'] = [];
$GLOBALS['wp_kurzcodes'] = [];
$GLOBALS['wp_optionen_speicher'] = null;
$GLOBALS['wp_ist_startseite'] = true;
$GLOBALS['wp_ist_seite'] = true;

function wp_optionen(): array {
    $f = getenv('WP_OPTIONEN');
    if ($f) return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    return $GLOBALS['wp_optionen_speicher'] ?? [];
}
function wp_optionen_schreiben(array $o): void {
    $f = getenv('WP_OPTIONEN');
    if ($f) file_put_contents($f, json_encode($o, JSON_UNESCAPED_UNICODE));
    else $GLOBALS['wp_optionen_speicher'] = $o;
}
function get_option($n, $v = false) { $o = wp_optionen(); return array_key_exists($n, $o) ? $o[$n] : $v; }
function update_option($n, $w, $autoload = null) { $o = wp_optionen(); $o[$n] = $w; wp_optionen_schreiben($o); return true; }
function delete_option($n) { $o = wp_optionen(); unset($o[$n]); wp_optionen_schreiben($o); return true; }
function add_action($haken, $f, $prio = 10, $n = 1) { $GLOBALS['wp_haken'][$haken][] = $f; return true; }
function add_filter($haken, $f, $prio = 10, $n = 1) { $GLOBALS['wp_haken'][$haken][] = $f; return true; }
function do_action($haken, ...$a) { foreach ($GLOBALS['wp_haken'][$haken] ?? [] as $f) $f(...$a); }
function apply_filters($haken, $wert, ...$a) { foreach ($GLOBALS['wp_haken'][$haken] ?? [] as $f) $wert = $f($wert, ...$a); return $wert; }
function add_shortcode($name, $f) { $GLOBALS['wp_kurzcodes'][$name] = $f; }
function register_activation_hook($datei, $f) {}
function register_uninstall_hook($datei, $f) {}
function register_rest_route($ns, $route, $def) { $GLOBALS['wp_routen']['/' . $ns . $route] = $def; }
function wp_check_invalid_utf8($s) { return mb_check_encoding((string)$s, 'UTF-8') ? (string)$s : ''; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function wp_date($f, $t = null) { return date($f, $t ?? time()); }
function current_time($f) { return date($f); }
function plugins_url($p, $f) { return 'https://www.example.org/wp-content/plugins/praxisherz-hinweise/' . $p; }
function plugin_basename($f) { return 'praxisherz-hinweise/praxisherz-hinweise.php'; }
function is_front_page() { return $GLOBALS['wp_ist_startseite']; }
function is_page() { return $GLOBALS['wp_ist_seite']; }
function in_the_loop() { return true; }
function selected($a, $b, $echo = true) { return $a === $b ? ' selected="selected"' : ''; }
function wp_unslash($w) { return $w; }
function is_main_query() { return true; }
function wp_enqueue_style(...$a) {}
function wp_enqueue_script(...$a) {}

class WP_REST_Response {
    public function __construct(public $data = null, public int $status = 200) {}
}
/** Wie WP_REST_Request: Kopfzeilen klein und mit „_“ statt „-“. */
class WP_REST_Request {
    private array $kopf = [];
    public function __construct(private string $koerper, array $kopf) { foreach ($kopf as $k => $v) $this->kopf[str_replace('-', '_', strtolower($k))] = $v; }
    public function get_body() { return $this->koerper; }
    public function get_header($k) { return $this->kopf[str_replace('-', '_', strtolower($k))] ?? null; }
}
