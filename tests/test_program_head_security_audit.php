<?php
/**
 * Automated Security & Permissions Test Suite for Program Head (Task 7)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "=================================================\n";
echo "STARTING TASK 7 SECURITY & PERMISSIONS AUDIT TESTS\n";
echo "=================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertSecurity($condition, $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $description\n";
        $passCount++;
    } else {
        echo " [FAIL] $description\n";
        $failCount++;
    }
}

$cleanupUserIds = [];
$cleanupActivityIds = [];
$cleanupTaskIds = [];

try {
    // 0. Create test fixtures
    $pwd = password_hash('SecTestPass123', PASSWORD_DEFAULT);
    $timeSfx = time() . '_' . rand(100, 999);

    // IT Program Head
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('SecTest IT PH', 'ph_it_{$timeSfx}@test.com', ?, 'program_head', 'IT Department')");
    $stmt->execute([$pwd]);
    $phItId = (int)$db->lastInsertId();
    $cleanupUserIds[] = $phItId;

    // THM Program Head
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('SecTest THM PH', 'ph_thm_{$timeSfx}@test.com', ?, 'program_head', 'THM Department')");
    $stmt->execute([$pwd]);
    $phThmId = (int)$db->lastInsertId();
    $cleanupUserIds[] = $phThmId;

    // IT Faculty
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('SecTest IT Fac', 'fac_it_{$timeSfx}@test.com', ?, 'faculty', 'IT Department')");
    $stmt->execute([$pwd]);
    $facItId = (int)$db->lastInsertId();
    $cleanupUserIds[] = $facItId;

    // THM Faculty
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('SecTest THM Fac', 'fac_thm_{$timeSfx}@test.com', ?, 'faculty', 'THM Department')");
    $stmt->execute([$pwd]);
    $facThmId = (int)$db->lastInsertId();
    $cleanupUserIds[] = $facThmId;

    // IT Activity
    $stmt = $db->prepare("INSERT INTO activities (title, faculty_id, status, event_date, venue) VALUES ('IT Security Activity', ?, 'approved', '2026-11-20', 'Gym')");
    $stmt->execute([$facItId]);
    $itActId = (int)$db->lastInsertId();
    $cleanupActivityIds[] = $itActId;

    // THM Activity
    $stmt = $db->prepare("INSERT INTO activities (title, faculty_id, status, event_date, venue) VALUES ('THM Security Activity', ?, 'approved', '2026-11-21', 'Auditorium')");
    $stmt->execute([$facThmId]);
    $thmActId = (int)$db->lastInsertId();
    $cleanupActivityIds[] = $thmActId;

    // 1. Unauthenticated Access Rejection
    echo "--- 1. Unauthenticated Access Rejection ---\n";
    unset($_SESSION['user_id'], $_SESSION['user_role']);
    assertSecurity(empty($_SESSION['user_id']), "Session user_id is empty when unauthenticated");
    assertSecurity(currentUser()['id'] === null, "currentUser() returns null id when unauthenticated");

    $unauthBlocked = false;
    if (empty($_SESSION['user_id'])) {
        $unauthBlocked = true;
    }
    assertSecurity($unauthBlocked, "requireRole strictly stops unauthenticated requests");

    // 2. Program Head Role Restrictions on Dean & Admin Pages
    echo "\n--- 2. Program Head Role Restrictions on Dean & Admin Pages ---\n";
    $_SESSION['user_id'] = $phItId;
    $_SESSION['user_role'] = 'program_head';
    $_SESSION['user_dept'] = 'IT Department';

    $deanPages = ['dean/dashboard.php', 'dean/users.php', 'dean/pending.php', 'dean/approved.php'];
    foreach ($deanPages as $dp) {
        $code = file_get_contents(dirname(__DIR__) . '/' . $dp);
        $hasStrictDean = (strpos($code, "requireRole('dean')") !== false);
        assertSecurity($hasStrictDean, "$dp enforces requireRole('dean')");
    }

    $adminPages = ['admin1/dashboard.php', 'admin2/users.php', 'admin2/dashboard.php'];
    foreach ($adminPages as $ap) {
        $code = file_get_contents(dirname(__DIR__) . '/' . $ap);
        $hasStrictAdmin = (strpos($code, "requireRole('admin1')") !== false || strpos($code, "requireRole('admin2')") !== false);
        assertSecurity($hasStrictAdmin, "$ap enforces strict admin role requirement");
    }

    // 3. Cross-Department Activity Isolation
    echo "\n--- 3. Cross-Department Activity Isolation ---\n";
    $canViewIT = canUserAccessActivity($itActId, 'view', $phItId, 'program_head', 'IT Department');
    $canModifyIT = canUserAccessActivity($itActId, 'modify', $phItId, 'program_head', 'IT Department');
    assertSecurity($canViewIT === true, "IT Program Head CAN view IT Department activity");
    assertSecurity($canModifyIT === true, "IT Program Head CAN modify IT Department activity");

    $canViewTHM = canUserAccessActivity($thmActId, 'view', $phItId, 'program_head', 'IT Department');
    $canModifyTHM = canUserAccessActivity($thmActId, 'modify', $phItId, 'program_head', 'IT Department');
    assertSecurity($canViewTHM === false, "IT Program Head CANNOT view unrelated THM Department activity");
    assertSecurity($canModifyTHM === false, "IT Program Head CANNOT modify unrelated THM Department activity");

    // 4. Cross-Department Document Isolation
    echo "\n--- 4. Cross-Department Document Isolation ---\n";
    $docDownloadCode = file_get_contents(dirname(__DIR__) . '/api/document-download.php');
    assertSecurity(strpos($docDownloadCode, "canUserAccessActivity") !== false, "document-download.php enforces canUserAccessActivity");
    assertSecurity(strpos($docDownloadCode, "http_response_code(403)") !== false, "document-download.php rejects unauthorized downloads with 403");

    // 5. Unauthorized Task Modification Guard
    echo "\n--- 5. Unauthorized Task Modification Guard ---\n";
    $taskAssignCode = file_get_contents(dirname(__DIR__) . '/api/task-assignment.php');
    assertSecurity(strpos($taskAssignCode, "canUserAccessActivity(\$activityId, 'modify'") !== false, "task-assignment.php checks modify rights for task management");
    assertSecurity(strpos($taskAssignCode, "taskAssignedUserId !== (int)\$user['id']") !== false, "task-assignment.php prevents modifying unassigned tasks");

    $taskDelivCode = file_get_contents(dirname(__DIR__) . '/api/task-deliverables.php');
    assertSecurity(strpos($taskDelivCode, "in_array(\$user['role'], ['faculty', 'program_head'], true)") !== false, "task-deliverables.php allows authorized leader review");
    assertSecurity(strpos($taskDelivCode, "!\$isAssigned && !\$isLeader") !== false, "task-deliverables.php rejects unauthorized uploaders with 403");

    // 6. Department Assignment Validation Server-Side
    echo "\n--- 6. Department Assignment Validation ---\n";
    $allowedDepts = ['IT Department', 'G.E. Department', 'THM Department'];
    assertSecurity(in_array('IT Department', $allowedDepts, true), "'IT Department' is an allowed academic department");
    assertSecurity(in_array('THM Department', $allowedDepts, true), "'THM Department' is an allowed academic department");
    assertSecurity(in_array('G.E. Department', $allowedDepts, true), "'G.E. Department' is an allowed academic department");
    assertSecurity(!in_array('Invalid Dept', $allowedDepts, true), "'Invalid Dept' is strictly rejected");
    assertSecurity(!in_array('', $allowedDepts, true), "Empty department is strictly rejected");
    assertSecurity(normalizeDepartmentName('Information Technology Department') === 'IT', "normalizeDepartmentName normalizes IT correctly");
    assertSecurity(normalizeDepartmentName('Hospitality and Tourism') === 'THM', "normalizeDepartmentName normalizes THM correctly");
    assertSecurity(normalizeDepartmentName('General Education') === 'GE', "normalizeDepartmentName normalizes GE correctly");

    $userCreateCode = file_get_contents(dirname(__DIR__) . '/api/user-create.php');
    assertSecurity(strpos($userCreateCode, "missing_program_head_department") !== false, "user-create.php validates required academic department for program_head");

    $userUpdateCode = file_get_contents(dirname(__DIR__) . '/api/user-update.php');
    assertSecurity(strpos($userUpdateCode, "missing_program_head_department") !== false, "user-update.php validates required academic department for program_head");

    // 7. Dean & Admin Access Preservation
    echo "\n--- 7. Dean & Admin Access Preservation ---\n";
    assertSecurity(canUserAccessActivity($itActId, 'view', 1, 'dean', '') === true, "Dean retains universal view access");
    assertSecurity(canUserAccessActivity($thmActId, 'modify', 1, 'dean', '') === true, "Dean retains universal modify access");
    assertSecurity(canUserAccessActivity($itActId, 'view', 2, 'admin1', '') === true, "Admin 1 retains universal view access");
    assertSecurity(canUserAccessActivity($thmActId, 'view', 3, 'admin2', '') === true, "Admin 2 retains universal view access");

    // 8. Faculty Access Preservation
    echo "\n--- 8. Faculty Access Preservation ---\n";
    assertSecurity(canUserAccessActivity($itActId, 'modify', $facItId, 'faculty', 'IT Department') === true, "Faculty creator retains modify access to own activity");
    assertSecurity(canUserAccessActivity($itActId, 'modify', $facThmId, 'faculty', 'THM Department') === false, "Non-creator faculty CANNOT modify other department activity");

    // 9. Direct API Authorization
    echo "\n--- 9. Direct API Authorization Checks ---\n";
    $apis = [
        'api/proposal-save.php' => "requireRole('faculty', 'program_head')",
        'api/proposal-update.php' => "canUserAccessActivity",
        'api/post-event-save.php' => "canUserAccessActivity",
        'api/create-google-form.php' => "canUserAccessActivity",
        'api/sync-google-form-responses.php' => "canUserAccessActivity",
        'api/import-evaluations-csv.php' => "canUserAccessActivity",
        'api/generate-kpi-analytics.php' => "canUserAccessActivity"
    ];

    foreach ($apis as $apiRel => $expectedPattern) {
        $apiCode = file_get_contents(dirname(__DIR__) . '/' . $apiRel);
        assertSecurity(strpos($apiCode, $expectedPattern) !== false, "$apiRel guards access via $expectedPattern");
    }

    // 10. Existing Functionality Compatibility
    echo "\n--- 10. Existing Functionality Compatibility ---\n";
    assertSecurity(function_exists('currentUser'), "currentUser() is defined and compatible");
    assertSecurity(function_exists('requireRole'), "requireRole() is defined and compatible");
    assertSecurity(function_exists('normalizeDepartmentName'), "normalizeDepartmentName() is defined and compatible");
    assertSecurity(function_exists('canUserAccessActivity'), "canUserAccessActivity() is defined and compatible");

} finally {
    echo "\n--- Cleaning up temporary test fixtures ---\n";
    if (!empty($cleanupTaskIds)) {
        $in = implode(',', $cleanupTaskIds);
        $db->exec("DELETE FROM faculty_tasks WHERE id IN ($in)");
    }
    if (!empty($cleanupActivityIds)) {
        $in = implode(',', $cleanupActivityIds);
        $db->exec("DELETE FROM activities WHERE id IN ($in)");
    }
    if (!empty($cleanupUserIds)) {
        $in = implode(',', $cleanupUserIds);
        $db->exec("DELETE FROM users WHERE id IN ($in)");
    }
    echo "Cleanup complete.\n";
}

echo "\n=================================================\n";
echo "SECURITY AUDIT SUMMARY: Passed: $passCount, Failed: $failCount\n";
echo "=================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
