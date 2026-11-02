<?php
/**
 * Automated Test Suite for Program Head Navigation and Sidebar Audit (Task 6)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

echo "========================================\n";
echo "STARTING TASK 6 NAVIGATION AUDIT TESTS\n";
echo "========================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $description\n";
        $passCount++;
    } else {
        echo " [FAIL] $description\n";
        $failCount++;
    }
}

// 1. Verify all destination files exist
$phPages = [
    'Dashboard' => 'program_head/dashboard.php',
    'New Proposal' => 'faculty/proposal-create.php',
    'Department Activities' => 'faculty/activities.php',
    'Pending Proposals' => 'faculty/pending.php',
    'Approved Activities' => 'faculty/approved.php',
    'Returned Proposals' => 'faculty/returned.php',
    'Task Monitoring' => 'faculty/tasks.php',
    'Calendar' => 'faculty/calendar.php',
    'Post-Event' => 'faculty/post-event.php',
    'KPI and Evaluation Reports' => 'faculty/kpi.php'
];

echo "--- 1. Destination Files Existence Check ---\n";
foreach ($phPages as $label => $relPath) {
    $fullPath = dirname(__DIR__) . '/' . $relPath;
    assertTest(file_exists($fullPath), "Page file exists: $label ($relPath)");
}

// 2. Test Sidebar Nav Structure for all roles
echo "\n--- 2. Sidebar Navigation Array Audit ---\n";

// Emulate user session for Program Head
$_SESSION['user_id'] = 99991;
$_SESSION['user_role'] = 'program_head';
$_SESSION['user_dept'] = 'IT Department';
$_SESSION['user_name'] = 'Test PH IT';

// Check sidebar data directly
ob_start();
include dirname(__DIR__) . '/includes/sidebar.php';
$sidebarHtml = ob_get_clean();

// Check $navs structure defined in sidebar.php
assertTest(isset($navs['program_head']), "navs has 'program_head' configuration");
assertTest(isset($navs['faculty']), "navs has 'faculty' configuration");
assertTest(isset($navs['admin1']), "navs has 'admin1' configuration");
assertTest(isset($navs['admin2']), "navs has 'admin2' configuration");
assertTest(isset($navs['dean']), "navs has 'dean' configuration");

// Inspect Program Head links in $navs
$phLinks = [];
foreach ($navs['program_head'] as $grp) {
    foreach ($grp['links'] as $lnk) {
        $phLinks[$lnk['label']] = $lnk['href'];
    }
}

assertTest(isset($phLinks['Dashboard']), "PH sidebar contains Dashboard");
assertTest(isset($phLinks['New Proposal']), "PH sidebar contains New Proposal");
assertTest(isset($phLinks['Department Activities']), "PH sidebar contains Department Activities");
assertTest(isset($phLinks['Pending Proposals']), "PH sidebar contains Pending Proposals");
assertTest(isset($phLinks['Approved Activities']), "PH sidebar contains Approved Activities");
assertTest(isset($phLinks['Returned Proposals']), "PH sidebar contains Returned Proposals");
assertTest(isset($phLinks['Task Monitoring']), "PH sidebar contains Task Monitoring");
assertTest(isset($phLinks['Calendar']), "PH sidebar contains Calendar");
assertTest(isset($phLinks['Post-Event']), "PH sidebar contains Post-Event");
assertTest(isset($phLinks['KPI and Evaluation Reports']), "PH sidebar contains KPI and Evaluation Reports");

// 3. Verify Role Isolation
echo "\n--- 3. Role Isolation & Menu Separation ---\n";
// Ensure PH does not see Dean or Admin links in their HTML
assertTest(strpos($sidebarHtml, 'Final Approval') === false, "PH does not see Dean 'Final Approval'");
assertTest(strpos($sidebarHtml, 'User Management') === false, "PH does not see Dean/Admin 'User Management'");
assertTest(strpos($sidebarHtml, 'Review Queue') === false, "PH does not see Admin 'Review Queue'");
assertTest(strpos($sidebarHtml, 'All Activities') === false, "PH does not see Dean/Admin 'All Activities'");

// Check that Faculty sidebar remains intact
$_SESSION['user_role'] = 'faculty';
$_SESSION['user_dept'] = 'IT Department';
$_SESSION['user_name'] = 'Test Faculty';
ob_start();
include dirname(__DIR__) . '/includes/sidebar.php';
$facultySidebarHtml = ob_get_clean();

assertTest(strpos($facultySidebarHtml, 'Activity Management') !== false, "Faculty sidebar has Activity Management");
assertTest(strpos($facultySidebarHtml, 'New Proposal') !== false, "Faculty sidebar has New Proposal");
assertTest(strpos($facultySidebarHtml, 'My Activities') !== false, "Faculty sidebar has My Activities");
assertTest(strpos($facultySidebarHtml, 'My Tasks') !== false, "Faculty sidebar has My Tasks");
assertTest(strpos($facultySidebarHtml, 'Department Management') === false, "Faculty does NOT see Department Management");

// Check Dean sidebar remains intact
$_SESSION['user_role'] = 'dean';
$_SESSION['user_dept'] = '';
$_SESSION['user_name'] = 'Test Dean';
ob_start();
include dirname(__DIR__) . '/includes/sidebar.php';
$deanSidebarHtml = ob_get_clean();
assertTest(strpos($deanSidebarHtml, 'Final Approval') !== false, "Dean sidebar has Final Approval");
assertTest(strpos($deanSidebarHtml, 'User Management') !== false, "Dean sidebar has User Management");

// 4. Check Walkthrough Inclusion & Dept Badge for Program Head
echo "\n--- 4. Walkthrough & Department Badge Check ---\n";
$_SESSION['user_role'] = 'program_head';
$_SESSION['user_dept'] = 'THM Department';
$_SESSION['user_name'] = 'Test PH THM';
ob_start();
include dirname(__DIR__) . '/includes/sidebar.php';
$phThmSidebarHtml = ob_get_clean();

assertTest(strpos($phThmSidebarHtml, 'walkthrough-faculty.js') !== false, "PH includes walkthrough script");
assertTest(strpos($phThmSidebarHtml, 'THM Department') !== false, "PH sidebar displays assigned department badge");

// 5. Active Highlighting Check
echo "\n--- 5. Active Highlighting Check ---\n";
foreach ($phPages as $label => $relPath) {
    $_SERVER['PHP_SELF'] = '/' . $relPath;
    ob_start();
    include dirname(__DIR__) . '/includes/sidebar.php';
    $rendered = ob_get_clean();
    $targetBase = basename($relPath);
    // Find the link with targetBase and check if it has class 'active'
    $hasActive = (bool)preg_match('/href="[^"]*' . preg_quote($targetBase, '/') . '"[^>]*class="[^"]*active[^"]*"/', $rendered);
    assertTest($hasActive, "Active highlight applied when on $relPath");
}

// 6. Role Authorization & Department Scoping Code Verification
echo "\n--- 6. Role Authorization & Scoping Verification ---\n";
$authRequirements = [
    'program_head/dashboard.php' => ['requireRole(\'program_head\')', 'department'],
    'faculty/proposal-create.php' => ['program_head', 'department'],
    'faculty/activities.php'      => ['program_head', 'department'],
    'faculty/pending.php'         => ['program_head', 'department'],
    'faculty/approved.php'        => ['program_head', 'department'],
    'faculty/returned.php'        => ['program_head', 'department'],
    'faculty/tasks.php'           => ['program_head'],
    'faculty/calendar.php'        => ['program_head', 'department'],
    'faculty/post-event.php'      => ['program_head', 'department'],
    'faculty/kpi.php'             => ['program_head', 'department']
];

foreach ($authRequirements as $relPath => $expectedPatterns) {
    $fullPath = dirname(__DIR__) . '/' . $relPath;
    $content = file_get_contents($fullPath);
    foreach ($expectedPatterns as $pattern) {
        assertTest(stripos($content, $pattern) !== false, "$relPath contains authorization/scoping: '$pattern'");
    }
}

// 7. Login Role-Routing Check
echo "\n--- 7. Login Role-Routing Check ---\n";
$loginFile = dirname(__DIR__) . '/auth/login.php';
$loginContent = file_get_contents($loginFile);
assertTest(strpos($loginContent, "'program_head' => 'program_head'") !== false, "Login routing routes program_head to program_head directory");

echo "\n========================================\n";
echo "SUMMARY: Passed: $passCount, Failed: $failCount\n";
echo "========================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
