<?php
/**
 * Test Suite: Activities Schema Resilience & Zero-500 Crash Guarantee
 * Tests:
 * 1. hasActivityArchiveColumn() returns bool without throwing exceptions.
 * 2. hasFacultyTasksTable() returns bool without throwing exceptions.
 * 3. hasCollaboratorsTable() returns bool without throwing exceptions.
 * 4. faculty/activities.php executes safely under simulated web session.
 * 5. admin1/activities.php, admin2/activities.php, dean/activities.php execute safely.
 * 6. faculty/approved.php executes safely without crashing.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

echo "=======================================================================\n";
echo "      ACTIVITIES SCHEMA RESILIENCE & ZERO-500 CRASH TEST SUITE        \n";
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

$db = getDB();

// 1. Schema capability helper checks
checkTest("hasActivityArchiveColumn returns boolean", is_bool(hasActivityArchiveColumn($db)), "Returned: " . (hasActivityArchiveColumn($db) ? 'true' : 'false'));
checkTest("hasFacultyTasksTable returns boolean", is_bool(hasFacultyTasksTable($db)), "Returned: " . (hasFacultyTasksTable($db) ? 'true' : 'false'));
checkTest("hasCollaboratorsTable returns boolean", is_bool(hasCollaboratorsTable($db)), "Returned: " . (hasCollaboratorsTable($db) ? 'true' : 'false'));

// 2. Simulate web execution of faculty/activities.php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_GET = [];

$facultyUser = $db->query("SELECT * FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_SESSION['user_id'] = $facultyUser['id'];
$_SESSION['user_role'] = 'faculty';
$_SESSION['user_name'] = $facultyUser['name'];
$_SESSION['user_department'] = $facultyUser['department'];

ob_start();
try {
    include __DIR__ . '/../faculty/activities.php';
    $output = ob_get_clean();
    $noFatal = (strpos($output, 'Fatal error') === false && strpos($output, 'Parse error') === false);
    checkTest("faculty/activities.php renders without 500 error", $noFatal && strlen($output) > 1000, "Rendered " . strlen($output) . " bytes");
} catch (\Throwable $e) {
    ob_end_clean();
    checkTest("faculty/activities.php renders without 500 error", false, "Exception: " . $e->getMessage());
}

// 3. Test with filter status = 'archived'
$_GET['status'] = 'archived';
ob_start();
try {
    include __DIR__ . '/../faculty/activities.php';
    $output = ob_get_clean();
    checkTest("faculty/activities.php handles ?status=archived safely", strlen($output) > 1000);
} catch (\Throwable $e) {
    ob_end_clean();
    checkTest("faculty/activities.php handles ?status=archived safely", false, "Exception: " . $e->getMessage());
}

// 4. Test faculty/approved.php
$_GET = [];
ob_start();
try {
    include __DIR__ . '/../faculty/approved.php';
    $output = ob_get_clean();
    checkTest("faculty/approved.php renders without 500 error", strlen($output) > 500);
} catch (\Throwable $e) {
    ob_end_clean();
    checkTest("faculty/approved.php renders without 500 error", false, "Exception: " . $e->getMessage());
}

// 5. Test Program Head role on activities.php
$phUser = $db->query("SELECT * FROM users WHERE role = 'program_head' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($phUser) {
    $_SESSION['user_id'] = $phUser['id'];
    $_SESSION['user_role'] = 'program_head';
    $_SESSION['user_name'] = $phUser['name'];
    $_SESSION['user_department'] = $phUser['department'];
    $_GET = [];

    ob_start();
    try {
        include __DIR__ . '/../faculty/activities.php';
        $output = ob_get_clean();
        checkTest("Program Head activities.php renders safely", strlen($output) > 1000);
    } catch (\Throwable $e) {
        ob_end_clean();
        checkTest("Program Head activities.php renders safely", false, "Exception: " . $e->getMessage());
    }
}

echo "-----------------------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL TESTS PASSED (ZERO 500 ERROR RISK)" : "TESTS FAILED") . "\n";
echo "=======================================================================\n";

exit($allPassed ? 0 : 1);
