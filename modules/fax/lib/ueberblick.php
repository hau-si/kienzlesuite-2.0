<?php
declare(strict_types=1);
// Einrichtungs-Überblick des Moduls fax als JSON (Aufruf über bin/setup-overview).
require_once dirname(__DIR__) . '/src/bootstrap.php';
echo json_encode(['items' => KienzleFax\Fax::ueberblick(KienzleFax\Fax::austauschBereit())], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
