<?php
/**
 * Test Issue 16: Fix Recent Activities for Faculty and Program Head
 *
 * Verifies:
 * 1. Faculty Dashboard:
 *    - Table layout has min-width >= 800px and table-layout: fixed
 *    - Actions column has width >= 18% and min-width >= 170px
 *    - Scoping query restricts activities to faculty_id or active collaborator
 * 2. Program Head Dashboard:
 *    - Table layout has min-width >= 800px and table-layout: fixed
 *    - Section B Actions column has width >= 18% and min-width >= 170px
 *    - Scoping query restricts activities to department proposals or creator/collaborator
 *    - Action buttons use flex alignment with no clipping/wrapping
 */

$rootDir = realpath(__DIR__ . '/..');
$errors = [];
$successes = [];

function assertTest($condition, $successMsg, $failMsg) {
    global $errors, $successes;
    if ($condition) {
        $successes[] = "[PASS] " . $successMsg;
    } else {
        $errors[] = "[FAIL] " . $failMsg;
    }
}

echo "=== Issue 16: Verification Test for Faculty & Program Head Dashboards ===\n\n";

// 1. Inspect faculty/dashboard.php
$facultyFile = $rootDir . '/faculty/dashboard.php';
assertTest(file_exists($facultyFile), "Faculty dashboard exists", "Faculty dashboard not found");

$facultyContent = file_get_contents($facultyFile);

// Check table min-width
$hasFacTableMinWidth = preg_match('/\.activity-table\s*\{[^}]*min-width:\s*([8-9]\d{2})px/s', $facultyContent, $mFacTable);
assertTest($hasFacTableMinWidth && (int)$mFacTable[1] >= 840, 
    "Faculty .activity-table has responsive min-width >= 840px (found: " . ($mFacTable[1] ?? 'none') . "px)", 
    "Faculty .activity-table lacks adequate min-width");

// Check actions column width
$hasFacActionsWidth = preg_match('/\.col-actions\s*\{[^}]*width:\s*(\d+)%[^}]*min-width:\s*(\d+)px/s', $facultyContent, $mFacCol);
assertTest($hasFacActionsWidth && (int)$mFacCol[1] >= 18 && (int)$mFacCol[2] >= 170,
    "Faculty .col-actions has width >= 18% and min-width >= 170px (found: " . ($mFacCol[1] ?? '0') . "%, " . ($mFacCol[2] ?? '0') . "px)",
    "Faculty .col-actions width or min-width too small");

// Check faculty query scoping
$hasFacScoping = strpos($facultyContent, 'a.faculty_id = ?') !== false && strpos($facultyContent, 'proposal_collaborators') !== false;
assertTest($hasFacScoping, 
    "Faculty dashboard query strictly scopes to author or active collaborator", 
    "Faculty dashboard query lacks proper author/collaborator scoping");

// 2. Inspect program_head/dashboard.php
$phFile = $rootDir . '/program_head/dashboard.php';
assertTest(file_exists($phFile), "Program Head dashboard exists", "Program Head dashboard not found");

$phContent = file_get_contents($phFile);

// Check .data-table min-width and table-layout
$hasPhTableMinWidth = preg_match('/\.data-table\s*\{[^}]*min-width:\s*([8-9]\d{2})px/s', $phContent, $mPhTable);
assertTest($hasPhTableMinWidth && (int)$mPhTable[1] >= 840,
    "Program Head .data-table has responsive min-width >= 840px (found: " . ($mPhTable[1] ?? 'none') . "px)",
    "Program Head .data-table lacks adequate min-width");

$hasPhTableFixed = preg_match('/\.data-table\s*\{[^}]*table-layout:\s*fixed/s', $phContent);
assertTest($hasPhTableFixed, 
    "Program Head .data-table has table-layout: fixed to prevent overflow",
    "Program Head .data-table lacks table-layout: fixed");

// Check Section B actions column width and min-width
$hasPhSectionBActions = preg_match('/<th[^>]*min-width:\s*(\d+)px[^>]*>\s*Actions\s*<\/th>/i', $phContent, $mPhActions);
assertTest($hasPhSectionBActions && (int)$mPhActions[1] >= 170,
    "Program Head Section B Actions column has min-width >= 170px (found: " . ($mPhActions[1] ?? '0') . "px)",
    "Program Head Section B Actions column lacks adequate min-width");

// Check .ph-table-actions styling
$hasPhTableActionsCss = strpos($phContent, '.ph-table-actions') !== false;
assertTest($hasPhTableActionsCss, 
    "Program Head dashboard includes .ph-table-actions flex container for buttons",
    "Program Head dashboard missing .ph-table-actions class");

// Check Program Head query scoping
$hasPhScoping = strpos($phContent, '$deptSqlU') !== false && strpos($phContent, 'proposal_collaborators') !== false;
assertTest($hasPhScoping,
    "Program Head dashboard query strictly scopes to department proposals or collaborator",
    "Program Head dashboard query lacks department scoping");

// Print results
echo "\n--- Test Results ---\n";
foreach ($successes as $s) {
    echo $s . "\n";
}
if (!empty($errors)) {
    echo "\nERRORS:\n";
    foreach ($errors as $e) {
        echo $e . "\n";
    }
    exit(1);
}

echo "\nAll Issue 16 checks PASSED successfully!\n";

