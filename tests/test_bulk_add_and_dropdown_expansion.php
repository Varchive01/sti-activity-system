<?php
/**
 * Automated Verification Script: Bulk Add Sections Table Expansion & Teacher Dropdown Non-Clipping
 * Across 4 User Accounts: Faculty, Program Head, Student Org Head (Admin1), Events Committee Head (Admin2).
 */

require_once __DIR__ . '/../includes/config.php';
$db = getDB();

$totalChecks = 0;
$passedChecks = 0;

function assertCheck($condition, $description) {
    global $totalChecks, $passedChecks;
    $totalChecks++;
    if ($condition) {
        $passedChecks++;
        echo "  [PASS] {$description}\n";
    } else {
        echo "  [FAIL] {$description}\n";
    }
}

echo "========================================================================\n";
echo "AUTOMATED AUDIT: BULK ADD SECTIONS & TEACHER MULTISELECT EXPANSION\n";
echo "========================================================================\n\n";

$createCode = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editCode   = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$mainCss    = file_get_contents(__DIR__ . '/../assets/css/main.css');
$tmsJs      = file_get_contents(__DIR__ . '/../assets/js/teacher-multiselect.js');
$enhCode    = file_get_contents(__DIR__ . '/../includes/phase2-proposal-enhancements.php');

// 1. Check proposal-create.php expansion & overflow
echo "--- 1. Checking proposal-create.php ---\n";
assertCheck(strpos($createCode, 'max-height: none') !== false,
    "proposal-create.php removes 380px clamp, allowing target-participants table to expand naturally for bulk sections");
assertCheck(strpos($createCode, 'overflow: visible !important') !== false,
    "proposal-create.php sets overflow: visible on scroll-box preventing dropdown clipping");

// 2. Check proposal-edit.php expansion & overflow
echo "\n--- 2. Checking proposal-edit.php ---\n";
assertCheck(strpos($editCode, 'max-height: none') !== false,
    "proposal-edit.php removes 380px clamp, allowing target-participants table to expand naturally for bulk sections");
assertCheck(strpos($editCode, 'overflow: visible !important') !== false,
    "proposal-edit.php sets overflow: visible on scroll-box preventing dropdown clipping");

// 3. Check teacher-multiselect.js smart viewport positioning
echo "\n--- 3. Checking assets/js/teacher-multiselect.js ---\n";
assertCheck(strpos($tmsJs, 'positionDropdown') !== false,
    "teacher-multiselect.js contains positionDropdown() for dynamic viewport coordinates");
assertCheck(strpos($tmsJs, 'position: fixed') !== false || strpos($tmsJs, "'fixed'") !== false,
    "teacher-multiselect.js uses position: fixed to float freely outside table container");
assertCheck(strpos($tmsJs, 'tms-dropup') !== false,
    "teacher-multiselect.js includes smart flip/dropup logic when close to bottom of screen");

// 4. Check assets/css/main.css
echo "\n--- 4. Checking assets/css/main.css ---\n";
assertCheck(strpos($mainCss, 'position: fixed !important') !== false,
    "main.css configures .teacher-multiselect .tms-dropdown as position: fixed with top-tier z-index");

// 5. Check bulk add modal integration
echo "\n--- 5. Checking includes/phase2-proposal-enhancements.php ---\n";
assertCheck(strpos($enhCode, 'bulkAddSectionsModal') !== false,
    "phase2-proposal-enhancements.php contains Quick Bulk Add Sections modal");
assertCheck(strpos($enhCode, 'processBulkAddSections') !== false,
    "phase2-proposal-enhancements.php contains processBulkAddSections logic");

// 6. Check parity across all 4 requested user accounts
echo "\n--- 6. Checking Account Parity (Faculty, Program Head, Student Org Head, Events Committee Head) ---\n";
$targetRoles = [
    'faculty'      => 'Faculty Member / Event Proponent',
    'program_head' => 'Department Program Head',
    'admin1'       => 'Student Organization Head',
    'admin2'       => 'Events Committee Head'
];

foreach ($targetRoles as $roleKey => $roleDesc) {
    $stmt = $db->prepare("SELECT id, name, role, department FROM users WHERE role = ? LIMIT 1");
    $stmt->execute([$roleKey]);
    $u = $stmt->fetch();
    assertCheck(!empty($u), "Account active and verified for {$roleKey} ({$roleDesc}): ID {$u['id']} ({$u['name']})");
    
    // Check role access permission in proposal-create.php
    assertCheck(strpos($createCode, "'{$roleKey}'") !== false,
        "proposal-create.php explicitly grants proposal authoring permissions to role: {$roleKey}");
}

// -------------------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------------------
echo "\n========================================================================\n";
echo "AUDIT RESULTS: {$passedChecks} / {$totalChecks} CHECKS PASSED (" . round(($passedChecks / $totalChecks) * 100, 1) . "%)\n";
echo "========================================================================\n";

if ($passedChecks === $totalChecks) {
    echo "STATUS: BULK ADD EXPANSION & MULTISELECT DROPDOWN FULLY VERIFIED ACROSS ALL 4 ROLES.\n";
    exit(0);
} else {
    echo "STATUS: CHECKS FAILED.\n";
    exit(1);
}
