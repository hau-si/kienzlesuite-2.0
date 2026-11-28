<?php
declare(strict_types=1);
// Router für „php -S“: nachgebautes WordPress mit dem Plugin; beantwortet nur /?rest_route=/praxisherz/v1/hinweise (nur für Tests).
require __DIR__ . '/wp-nachbau.php';
require dirname(__DIR__) . '/wordpress/praxisherz-hinweise/praxisherz-hinweise.php';
do_action('rest_api_init');
$pfad = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
$route = (string)($_GET['rest_route'] ?? '');
$def = $GLOBALS['wp_routen'][$route] ?? null;
if ($pfad === '/weiter/') { header('Location: https://www.example.org/', true, 301); return true; }
if ($pfad !== '/' || !$def || $_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(404); echo '{"code":"rest_no_route"}'; return true; }
$kopf = [];
foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $kopf[substr($k, 5)] = $v;
$antwort = ($def['callback'])(new WP_REST_Request((string)file_get_contents('php://input'), $kopf));
http_response_code($antwort->status);
header('Content-Type: application/json');
echo json_encode($antwort->data);
return true;
