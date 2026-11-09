<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$db = getDB();

echo "--- BEFORE FIX COMPARISON ---\n";
echo "WITH DEAN ID 4:\n";
$withDean = getInstitutionalKpiSummary(2026, 'all', $db, 4);
print_r($withDean['statistics']);

echo "WITHOUT DEAN ID (INSTITUTIONAL):\n";
$withoutDean = getInstitutionalKpiSummary(2026, 'all', $db, null);
print_r($withoutDean['statistics']);

