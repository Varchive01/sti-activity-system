<?php
/**
 * Test Suite: Dean User Management Parity & Admin 2 Settings Removal
 * Verifies that:
 * 1. admin2/settings.php is completely removed.
 * 2. Admin 2 sidebar no longer displays System Settings.
 * 3. Dean sidebar contains Administration -> User Management (/dean/users.php).
 * 4. dean/users.php requires 'dean' role and loads includes/user-management-view.php.
 * 5. api/user-create.php, api/user-update.php, api/user-delete.php, and api/user-reset-password.php authorize 'dean'.
 * 6. User management view provides identical CRUD capabilities to Dean and Admin 2.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

echo "=======================================================================\n";
echo "      DEAN USER MANAGEMENT PARITY & ADMIN 2 SETTINGS REMOVAL TEST      \n";
echo "=======================================================================\n";

$allPassed = true;

function checkTest($label, $condition, $details = '') {
    global $allPassed;
    if ($condition) {
        printf(" [PASS] | %-52s | %s\n", $label, $details ?: 'OK');
    } else {
        printf(" [FAIL] | %-52s | %s\n", $label, $details ?: 'FAILED');
        $allPassed = false;
    }
}

// 1. admin2/settings.php file must not exist
checkTest("admin2/settings.php does not exist", !file_exists(__DIR__ . '/../admin2/settings.php'), "Confirmed deleted");

// 2. Inspect includes/sidebar.php content
$sidebarContent = file_get_contents(__DIR__ . '/../includes/sidebar.php');

// Admin 2 check: No settings.php
checkTest("Admin 2 sidebar: No settings.php reference", strpos($sidebarContent, 'admin2/settings.php') === false, "No settings link");
checkTest("Admin 2 sidebar: No 'System Settings' label", strpos($sidebarContent, "'System Settings'") === false, "Label removed");

// Dean check: Has Administration -> User Management
checkTest("Dean sidebar: User Management included", strpos($sidebarContent, "dean/users.php") !== false, "Points to /dean/users.php");

// 3. dean/users.php existence and structure
$deanUsersFile = __DIR__ . '/../dean/users.php';
checkTest("dean/users.php exists", file_exists($deanUsersFile), "File present");
$deanUsersContent = file_get_contents($deanUsersFile);
checkTest("dean/users.php requires 'dean' role", strpos($deanUsersContent, "requireRole('dean')") !== false, "Enforces dean role");
checkTest("dean/users.php includes user-management-view.php", strpos($deanUsersContent, "user-management-view.php") !== false, "Shared view loaded");

// 4. API Endpoints authorization checks
$apiFiles = [
    'api/user-create.php',
    'api/user-update.php',
    'api/user-delete.php',
    'api/user-reset-password.php'
];

foreach ($apiFiles as $apiRelPath) {
    $apiContent = file_get_contents(__DIR__ . '/../' . $apiRelPath);
    $hasDeanRole = strpos($apiContent, "requireRole('dean'") !== false || strpos($apiContent, "requireRole('admin2', 'dean')") !== false;
    checkTest("$apiRelPath authorizes dean", $hasDeanRole, "Dean role permitted");
}

// 5. Shared user-management-view.php checks
$viewContent = file_get_contents(__DIR__ . '/../includes/user-management-view.php');
checkTest("user-management-view allows Dean creation", strpos($viewContent, "'dean'") !== false, "Dean supported");
checkTest("user-management-view supports action modals", strpos($viewContent, "addModalBackdrop") !== false && strpos($viewContent, "editModalBackdrop") !== false && strpos($viewContent, "deleteModalBackdrop") !== false && strpos($viewContent, "resetModalBackdrop") !== false, "All modals present");

echo "-----------------------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL TESTS PASSED (PERFECT PARITY)" : "SOME TESTS FAILED") . "\n";
echo "=======================================================================\n";

exit($allPassed ? 0 : 1);
