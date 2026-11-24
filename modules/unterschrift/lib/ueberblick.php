<?php
declare(strict_types=1);
// Einrichtungs-Überblick des Moduls unterschrift als JSON (Aufruf über bin/setup-overview).
require_once dirname(__DIR__) . '/src/bootstrap.php';
echo json_encode(['items' => KienzleUnterschrift\Seite::ueberblick()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
