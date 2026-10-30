<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: Migration scripts can only be executed via CLI.');
}
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
$db = getDB();
$db->exec("ALTER TABLE kpi_evaluations ADD COLUMN IF NOT EXISTS indicator VARCHAR(255) NULL, ADD COLUMN IF NOT EXISTS target_metric VARCHAR(255) NULL, ADD COLUMN IF NOT EXISTS evaluation_method VARCHAR(255) NULL;");
echo "Done.\n";
