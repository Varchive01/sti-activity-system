<?php
require '../config/config.php';
require '../config/database.php';
$db = getDB();
$db->exec("ALTER TABLE kpi_evaluations ADD COLUMN indicator VARCHAR(255) NULL, ADD COLUMN target_metric VARCHAR(255) NULL, ADD COLUMN evaluation_method VARCHAR(255) NULL;");
echo "Done.";
