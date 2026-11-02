<?php
/**
 * Automated Verification Script for Dedicated Program Head Dashboard
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "=== STI-ARMES Program Head Dashboard Verification ===\n\n";

$createdUserIds = [];
$createdActivityIds = [];
$createdTaskIds = [];

$assertCount = 0;
$passCount = 0;

function assertCondition($desc, $result, $expected) {
    global $assertCount, $passCount;
    $assertCount++;
    if ($result === $expected) {
        $passCount++;
        echo "[PASS] $desc\n";
    } else {
        echo "[FAIL] $desc (Expected: " . var_export($expected, true) . ", Got: " . var_export($result, true) . ")\n";
    }
}

try {
    $pwd = password_hash('password123', PASSWORD_DEFAULT);

    // Baseline IT count before test insertions
    $baselineItTotal = (int)$db->query("SELECT COUNT(DISTINCT a.id) FROM activities a JOIN users u ON a.faculty_id = u.id WHERE u.department = 'IT Department'")->fetchColumn();
    $baselineItPending = (int)$db->query("SELECT COUNT(DISTINCT a.id) FROM activities a JOIN users u ON a.faculty_id = u.id WHERE u.department = 'IT Department' AND a.status IN ('submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted')")->fetchColumn();
    $baselineItApproved = (int)$db->query("SELECT COUNT(DISTINCT a.id) FROM activities a JOIN users u ON a.faculty_id = u.id WHERE u.department = 'IT Department' AND a.status = 'approved'")->fetchColumn();

    // Create temporary users for testing
    // IT Faculty
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test IT Faculty', 'test_it_fac_" . time() . "@test.com', ?, 'faculty', 'IT Department')");
    $stmt->execute([$pwd]);
    $itFacId = (int)$db->lastInsertId();
    $createdUserIds[] = $itFacId;

    // THM Faculty
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test THM Faculty', 'test_thm_fac_" . time() . "@test.com', ?, 'faculty', 'THM Department')");
    $stmt->execute([$pwd]);
    $thmFacId = (int)$db->lastInsertId();
    $createdUserIds[] = $thmFacId;

    // GE Faculty
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test GE Faculty', 'test_ge_fac_" . time() . "@test.com', ?, 'faculty', 'G.E. Department')");
    $stmt->execute([$pwd]);
    $geFacId = (int)$db->lastInsertId();
    $createdUserIds[] = $geFacId;

    // IT Program Head
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test IT Head', 'test_it_head_" . time() . "@test.com', ?, 'program_head', 'IT Department')");
    $stmt->execute([$pwd]);
    $itHeadId = (int)$db->lastInsertId();
    $createdUserIds[] = $itHeadId;

    // THM Program Head
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test THM Head', 'test_thm_head_" . time() . "@test.com', ?, 'program_head', 'THM Department')");
    $stmt->execute([$pwd]);
    $thmHeadId = (int)$db->lastInsertId();
    $createdUserIds[] = $thmHeadId;

    // GE Program Head
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test GE Head', 'test_ge_head_" . time() . "@test.com', ?, 'program_head', 'G.E. Department')");
    $stmt->execute([$pwd]);
    $geHeadId = (int)$db->lastInsertId();
    $createdUserIds[] = $geHeadId;

    // Create Activities
    // 1. Exclusive IT Activity (submitted / pending)
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date) VALUES (?, 'IT Coding Camp', 'submitted', DATE_ADD(CURDATE(), INTERVAL 7 DAY))");
    $stmt->execute([$itFacId]);
    $itAct1 = (int)$db->lastInsertId();
    $createdActivityIds[] = $itAct1;

    // 2. Exclusive IT Activity (approved)
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date) VALUES (?, 'IT Cybersecurity Summit', 'approved', DATE_ADD(CURDATE(), INTERVAL 14 DAY))");
    $stmt->execute([$itFacId]);
    $itAct2 = (int)$db->lastInsertId();
    $createdActivityIds[] = $itAct2;

    // 3. Exclusive THM Activity (approved)
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date) VALUES (?, 'THM Culinary Expo', 'approved', DATE_ADD(CURDATE(), INTERVAL 10 DAY))");
    $stmt->execute([$thmFacId]);
    $thmAct1 = (int)$db->lastInsertId();
    $createdActivityIds[] = $thmAct1;

    // 4. Exclusive GE Activity (draft)
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date) VALUES (?, 'GE Debate Championship', 'draft', DATE_ADD(CURDATE(), INTERVAL 12 DAY))");
    $stmt->execute([$geFacId]);
    $geAct1 = (int)$db->lastInsertId();
    $createdActivityIds[] = $geAct1;

    // 5. Shared Activity: Created by IT, but task assigned to THM faculty member
    $stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date) VALUES (?, 'STI Annual Festival (Shared IT & THM)', 'approved', DATE_ADD(CURDATE(), INTERVAL 20 DAY))");
    $stmt->execute([$itFacId]);
    $sharedAct = (int)$db->lastInsertId();
    $createdActivityIds[] = $sharedAct;

    // Assign tasks:
    // IT task on IT activity
    $stmt = $db->prepare("INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, task_title, status, completion_pct) VALUES (?, ?, 'Test IT Faculty', 'IT Network Setup', 'In Progress', 50)");
    $stmt->execute([$itAct1, $itFacId]);
    $createdTaskIds[] = (int)$db->lastInsertId();

    // THM task on shared IT activity
    $stmt = $db->prepare("INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, task_title, status, completion_pct) VALUES (?, ?, 'Test THM Faculty', 'Hospitality Catering', 'Not Started', 0)");
    $stmt->execute([$sharedAct, $thmFacId]);
    $createdTaskIds[] = (int)$db->lastInsertId();

    echo "--- 1. Login & Dashboard Routing Checks ---\n";
    $loginCode = file_get_contents(__DIR__ . '/../auth/login.php');
    assertCondition("auth/login.php routes program_head to program_head", strpos($loginCode, "'program_head' => 'program_head'") !== false, true);

    $indexCode = file_get_contents(__DIR__ . '/../index.php');
    assertCondition("index.php routes program_head to program_head", strpos($indexCode, "'program_head'=>'program_head'") !== false, true);

    $facultyDashCode = file_get_contents(__DIR__ . '/../faculty/dashboard.php');
    assertCondition("faculty/dashboard.php redirects program_head to program_head/dashboard.php", strpos($facultyDashCode, "/program_head/dashboard.php") !== false, true);

    echo "\n--- 2. Role Protection Checks ---\n";
    $phDashCode = file_get_contents(__DIR__ . '/../program_head/dashboard.php');
    assertCondition("program_head/dashboard.php requires program_head role", strpos($phDashCode, "requireRole('program_head')") !== false, true);

    $deanDashCode = file_get_contents(__DIR__ . '/../dean/dashboard.php');
    assertCondition("dean/dashboard.php strictly requires dean role", strpos($deanDashCode, "requireRole('dean')") !== false, true);

    echo "\n--- 3. Department Overview & Query Isolation ---\n";
    // Test IT Program Head queries
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT a.id) as total,
               SUM(CASE WHEN a.status IN ('submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted') THEN 1 ELSE 0 END) as pending,
               SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) as approved
        FROM activities a
        JOIN users u ON a.faculty_id = u.id
        WHERE (u.department = 'IT Department' OR a.faculty_id = ?)
    ");
    $stmt->execute([$itHeadId]);
    $itStats = $stmt->fetch(PDO::FETCH_ASSOC);

    assertCondition("IT Program Head total matches baseline + 3", (int)$itStats['total'], $baselineItTotal + 3);
    assertCondition("IT Program Head pending matches baseline + 1", (int)$itStats['pending'], $baselineItPending + 1);
    assertCondition("IT Program Head approved matches baseline + 2", (int)$itStats['approved'], $baselineItApproved + 2);

    // Test THM Program Head queries
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT a.id) as total,
               SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) as approved
        FROM activities a
        JOIN users u ON a.faculty_id = u.id
        WHERE (u.department = 'THM Department' OR a.faculty_id = ?)
    ");
    $stmt->execute([$thmHeadId]);
    $thmStats = $stmt->fetch(PDO::FETCH_ASSOC);

    assertCondition("THM Program Head sees exactly 1 THM department activity", (int)$thmStats['total'], 1);
    assertCondition("THM Program Head sees exactly 1 approved THM activity", (int)$thmStats['approved'], 1);

    // Test GE Program Head queries
    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT a.id) as total,
               SUM(CASE WHEN a.status = 'draft' THEN 1 ELSE 0 END) as drafts
        FROM activities a
        JOIN users u ON a.faculty_id = u.id
        WHERE (u.department = 'G.E. Department' OR a.faculty_id = ?)
    ");
    $stmt->execute([$geHeadId]);
    $geStats = $stmt->fetch(PDO::FETCH_ASSOC);

    assertCondition("GE Program Head sees exactly 1 GE department activity", (int)$geStats['total'], 1);
    assertCondition("GE Program Head sees exactly 1 draft GE activity", (int)$geStats['drafts'], 1);

    echo "\n--- 4. Shared / Cross-Department Activity Monitoring ---\n";
    // THM Shared Activities query
    $stmt = $db->prepare("
        SELECT a.id, a.title
        FROM activities a
        JOIN users u_creator ON a.faculty_id = u_creator.id
        JOIN faculty_tasks ft ON a.id = ft.activity_id
        JOIN users u_dept ON ft.assigned_user_id = u_dept.id
        WHERE (u_creator.department != 'THM Department' AND a.faculty_id != ?)
          AND (u_dept.department = 'THM Department' OR ft.assigned_user_id = ?)
        GROUP BY a.id
    ");
    $stmt->execute([$thmHeadId, $thmHeadId]);
    $thmShared = $stmt->fetchAll(PDO::FETCH_ASSOC);

    assertCondition("THM Program Head sees the shared IT activity with THM task", count($thmShared) === 1 && (int)$thmShared[0]['id'] === $sharedAct, true);

    // GE Shared Activities query
    $stmt = $db->prepare("
        SELECT a.id, a.title
        FROM activities a
        JOIN users u_creator ON a.faculty_id = u_creator.id
        JOIN faculty_tasks ft ON a.id = ft.activity_id
        JOIN users u_dept ON ft.assigned_user_id = u_dept.id
        WHERE (u_creator.department != 'G.E. Department' AND a.faculty_id != ?)
          AND (u_dept.department = 'G.E. Department' OR ft.assigned_user_id = ?)
        GROUP BY a.id
    ");
    $stmt->execute([$geHeadId, $geHeadId]);
    $geShared = $stmt->fetchAll(PDO::FETCH_ASSOC);

    assertCondition("GE Program Head sees 0 shared activities (no GE tasks in external activities)", count($geShared) === 0, true);

    echo "\n--- 5. Task Monitoring Isolation ---\n";
    // Check task monitoring for THM faculty
    $stmt = $db->prepare("
        SELECT ft.id, ft.task_title, ft.assigned_user_id, u.name
        FROM faculty_tasks ft
        JOIN users u ON ft.assigned_user_id = u.id
        WHERE u.department = 'THM Department'
    ");
    $stmt->execute();
    $thmTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    assertCondition("THM task monitoring captures Hospitality Catering assigned to THM faculty", count($thmTasks) === 1 && (int)$thmTasks[0]['assigned_user_id'] === $thmFacId, true);

    // Check task monitoring for IT faculty
    $stmt = $db->prepare("
        SELECT ft.id, ft.task_title, ft.assigned_user_id, u.name
        FROM faculty_tasks ft
        JOIN users u ON ft.assigned_user_id = u.id
        WHERE u.department = 'IT Department'
    ");
    $stmt->execute();
    $itTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    assertCondition("IT task monitoring captures IT Network Setup assigned to IT faculty", count($itTasks) === 1 && (int)$itTasks[0]['assigned_user_id'] === $itFacId, true);

    echo "\n--- 6. Backend Department Access Control (Task 3 Compliance) ---\n";
    assertCondition("IT Program Head can view department activity", canUserAccessActivity($itAct1, 'view', $itHeadId, 'program_head', 'IT Department'), true);
    assertCondition("IT Program Head CANNOT view unrelated THM activity", canUserAccessActivity($thmAct1, 'view', $itHeadId, 'program_head', 'IT Department'), false);
    assertCondition("THM Program Head can view shared cross-dept activity", canUserAccessActivity($sharedAct, 'view', $thmHeadId, 'program_head', 'THM Department'), true);
    assertCondition("THM Program Head CANNOT modify shared cross-dept activity", canUserAccessActivity($sharedAct, 'modify', $thmHeadId, 'program_head', 'THM Department'), false);

    echo "\nSummary: {$passCount}/{$assertCount} assertions passed.\n";

} finally {
    echo "\nCleaning up temporary test records...\n";
    if (!empty($createdTaskIds)) {
        $in = implode(',', $createdTaskIds);
        $db->exec("DELETE FROM faculty_tasks WHERE id IN ($in)");
    }
    if (!empty($createdActivityIds)) {
        $in = implode(',', $createdActivityIds);
        $db->exec("DELETE FROM activities WHERE id IN ($in)");
    }
    if (!empty($createdUserIds)) {
        $in = implode(',', $createdUserIds);
        $db->exec("DELETE FROM users WHERE id IN ($in)");
    }
    echo "Cleanup complete.\n";
}
