<?php
/**
 * Automated Verification: Dean Dashboard Layout Alignment & Card Sizing Parity Test
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$deanContent = file_get_contents(__DIR__ . '/../dean/dashboard.php');
$errors = [];

// 1. Verify dashboard-layout stretch alignment and card-status-breakdown full height
if (strpos($deanContent, '.dashboard-layout {') === false) {
    $errors[] = "Missing .dashboard-layout style block.";
}
if (!preg_match('/\.dashboard-layout\s*\{[^}]*align-items:\s*stretch/s', $deanContent)) {
    $errors[] = ".dashboard-layout must have align-items: stretch to eliminate blank gaps.";
}
if (strpos($deanContent, '.card-status-breakdown {') === false) {
    $errors[] = "Missing .card-status-breakdown style block.";
}
if (strpos($deanContent, 'grid-auto-rows:1fr;') === false) {
    $errors[] = "Status breakdown grid must have grid-auto-rows:1fr for balanced cell distribution.";
}

// 2. Verify dedicated balanced row .dashboard-kpi-split for KPI Overview & Source Breakdown
if (strpos($deanContent, '.dashboard-kpi-split {') === false) {
    $errors[] = "Missing .dashboard-kpi-split grid layout for side-by-side KPI Overview & Source Breakdown.";
}
if (strpos($deanContent, '<div class="dashboard-kpi-split">') === false) {
    $errors[] = "Missing <div class=\"dashboard-kpi-split\"> container markup.";
}

// 3. Verify KPI performance overview card body has balanced padding
if (strpos($deanContent, 'padding: 16px 20px;') === false) {
    $errors[] = "KPI Performance Overview card-body is missing balanced padding (16px 20px).";
}

// 4. Verify kpi-target-strip and kpi-metrics-row grids
if (strpos($deanContent, '.kpi-target-strip { display: grid;') === false) {
    $errors[] = "Missing .kpi-target-strip grid definition.";
}
if (strpos($deanContent, '.kpi-metrics-row { display: grid;') === false) {
    $errors[] = "Missing .kpi-metrics-row grid definition.";
}

// 5. Verify Institutional Quick Actions has been removed
if (strpos($deanContent, 'Institutional Quick Actions') !== false) {
    $errors[] = "'Institutional Quick Actions' card is still present in dean/dashboard.php.";
}

// 6. Verify no duplicate stray rail tags in HTML markup
if (substr_count($deanContent, '<div class="dashboard-rail-sticky">') !== 1) {
    $errors[] = "Expected exactly one '<div class=\"dashboard-rail-sticky\">' in dean/dashboard.php.";
}

// 7. Syntax check
$lint = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../dean/dashboard.php'));
if (strpos($lint, 'No syntax errors detected') === false) {
    $errors[] = "PHP syntax lint error: $lint";
}

if (!empty($errors)) {
    echo "TEST FAILED:\n" . implode("\n", $errors) . "\n";
    exit(1);
} else {
    echo "PASS: Dean Dashboard Layout & Section Alignment successfully verified!\n";
    exit(0);
}

