<?php
/**
 * Automated Verification: Admin 1 & Admin 2 Dashboard Alignment & Card Parity Test
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin1Content = file_get_contents(__DIR__ . '/../admin1/dashboard.php');
$admin2Content = file_get_contents(__DIR__ . '/../admin2/dashboard.php');

$errors = [];

// 1. Verify no orphan margin-bottom: 20px on task card
if (strpos($admin1Content, 'style="margin-bottom: 20px;"') !== false) {
    $errors[] = "Admin 1 dashboard still has inline margin-bottom: 20px on Assigned Tasks card.";
}
if (strpos($admin2Content, 'style="margin-bottom: 20px;"') !== false) {
    $errors[] = "Admin 2 dashboard still has inline margin-bottom: 20px on Assigned Tasks card.";
}

// 2. Verify both dashboards use modern SVG column chart
if (strpos($admin1Content, 'trends-chart-svg') === false) {
    $errors[] = "Admin 1 dashboard is missing trends-chart-svg.";
}
if (strpos($admin2Content, 'trends-chart-svg') === false) {
    $errors[] = "Admin 2 dashboard is missing trends-chart-svg.";
}

// 3. Verify tooltip popover exists on both
if (strpos($admin1Content, 'id="chartTooltip"') === false) {
    $errors[] = "Admin 1 dashboard is missing chartTooltip popover.";
}
if (strpos($admin2Content, 'id="chartTooltip"') === false) {
    $errors[] = "Admin 2 dashboard is missing chartTooltip popover.";
}

// 4. Verify dashboard-layout and rail-sticky structure
if (strpos($admin1Content, 'dashboard-rail-sticky') === false) {
    $errors[] = "Admin 1 dashboard missing dashboard-rail-sticky.";
}
if (strpos($admin2Content, 'dashboard-rail-sticky') === false) {
    $errors[] = "Admin 2 dashboard missing dashboard-rail-sticky.";
}

// 5. Check syntax linting
$lint1 = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../admin1/dashboard.php'));
$lint2 = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../admin2/dashboard.php'));
if (strpos($lint1, 'No syntax errors detected') === false) {
    $errors[] = "Admin 1 PHP lint failed: $lint1";
}
if (strpos($lint2, 'No syntax errors detected') === false) {
    $errors[] = "Admin 2 PHP lint failed: $lint2";
}

if (!empty($errors)) {
    echo "TEST FAILED:\n" . implode("\n", $errors) . "\n";
    exit(1);
} else {
    echo "PASS: Dashboard Layout Alignment & Card Parity verified across Admin 1 and Admin 2!\n";
    exit(0);
}

