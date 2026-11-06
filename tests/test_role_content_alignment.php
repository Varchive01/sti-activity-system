<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================\n";
echo " TEST SUITE: ROLE-BASED ACCOUNT CONTENT ALIGNMENT\n";
echo "========================================================\n\n";

$allPassed = true;

function checkTest($label, $condition) {
    global $allPassed;
    if ($condition) {
        echo " [PASS] $label\n";
    } else {
        echo " [FAIL] $label\n";
        $allPassed = false;
    }
}

// 1. Program Head contributors.php check
checkTest("program_head/contributors.php file exists", file_exists(__DIR__ . '/../program_head/contributors.php'));
$contributorsContent = file_get_contents(__DIR__ . '/../program_head/contributors.php');
checkTest("program_head/contributors.php enforces program_head role", strpos($contributorsContent, "requireRole('program_head')") !== false);
checkTest("program_head/contributors.php enforces department scoping", strpos($contributorsContent, "resolveUserDepartment") !== false);

// 2. Admin 2 settings.php check (Removed per user request)
checkTest("admin2/settings.php file is removed", !file_exists(__DIR__ . '/../admin2/settings.php'));

// 3. Dean and Admin 2 sidebar and User Management parity check
$sidebarRaw = file_get_contents(__DIR__ . '/../includes/sidebar.php');
checkTest("Admin 2 sidebar does NOT contain System Settings", strpos($sidebarRaw, "settings.php") === false);

$_SESSION['user_id'] = 4;
$_SESSION['user_role'] = 'dean';
$_SESSION['user_name'] = 'Dean Frederic';
include __DIR__ . '/../includes/sidebar.php';
$deanNavGroups = array_column($navs['dean'] ?? [], 'group');
checkTest("Dean sidebar contains 'Administration' group", in_array('Administration', $deanNavGroups, true));

$deanUsersContent = file_get_contents(__DIR__ . '/../dean/users.php');
checkTest("dean/users.php enforces dean role", strpos($deanUsersContent, "requireRole('dean')") !== false);
checkTest("dean/users.php renders user-management-view.php", strpos($deanUsersContent, "user-management-view.php") !== false);

// 4. Faculty dashboard draft card check
$facultyDashContent = file_get_contents(__DIR__ . '/../faculty/dashboard.php');
checkTest("faculty/dashboard.php renders Draft Proposals card", strpos($facultyDashContent, "stat-card-draft") !== false);
checkTest("faculty/dashboard.php links draft card to status=draft", strpos($facultyDashContent, "activities.php?status=draft") !== false);

// 5. Program Head dashboard cards check
$phDashContent = file_get_contents(__DIR__ . '/../program_head/dashboard.php');
checkTest("program_head/dashboard.php renders Draft Proposals card", strpos($phDashContent, "Draft Proposals") !== false);
checkTest("program_head/dashboard.php renders Returned Proposals card", strpos($phDashContent, "Returned Proposals") !== false);

// 6. Admin 1 dashboard upcoming activities check (explicitly removed per user request)
$admin1DashContent = file_get_contents(__DIR__ . '/../admin1/dashboard.php');
checkTest("admin1/dashboard.php does NOT query upcoming activities", strpos($admin1DashContent, '$upcomingActivities') === false);
checkTest("admin1/dashboard.php does NOT render Upcoming Activities card (removed per user request)", strpos($admin1DashContent, 'Upcoming Activities') === false);

// 7. Admin 2 dashboard upcoming activities check (explicitly removed per user request)
$admin2DashContent = file_get_contents(__DIR__ . '/../admin2/dashboard.php');
checkTest("admin2/dashboard.php does NOT query upcoming activities", strpos($admin2DashContent, '$upcoming') === false);
checkTest("admin2/dashboard.php does NOT render Upcoming Approved Events card (removed per user request)", strpos($admin2DashContent, 'Upcoming Approved Events') === false);

// 8. Dean dashboard upcoming activities check (explicitly removed per user request)
$deanDashContent = file_get_contents(__DIR__ . '/../dean/dashboard.php');
checkTest("dean/dashboard.php does NOT query upcoming activities", strpos($deanDashContent, '$upcomingActivities') === false);
checkTest("dean/dashboard.php does NOT render Upcoming Activities card (removed per user request)", strpos($deanDashContent, 'Upcoming Activities') === false);

// 9. Department Isolation Check on Contributors query
$thmMbrs = $db->query("SELECT COUNT(*) FROM users WHERE role = 'faculty' AND department LIKE '%THM%'")->fetchColumn();
echo "\n[INFO] Database THM Faculty Count: $thmMbrs\n";
$itMbrs = $db->query("SELECT COUNT(*) FROM users WHERE role = 'faculty' AND department LIKE '%IT%'")->fetchColumn();
echo "[INFO] Database IT Faculty Count: $itMbrs\n";

echo "\n========================================================\n";
if ($allPassed) {
    echo " ALL ROLE-BASED ALIGNMENT TESTS PASSED!\n";
} else {
    echo " SOME TESTS FAILED. PLEASE REVIEW.\n";
    exit(1);
}
echo "========================================================\n";
