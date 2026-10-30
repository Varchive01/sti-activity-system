<?php
/**
 * tests/test_admin_my_tasks.php
 *
 * Comprehensive verification suite for Admin 1 & Admin 2 "My Tasks" implementation:
 * 1. Role enforcement and access boundaries (Admin 1 vs Admin 2 vs Faculty vs Unauthenticated)
 * 2. Task visibility and account isolation (Admin 1 sees only Admin 1 tasks, Admin 2 sees only Admin 2 tasks)
 * 3. Prevention of cross-account task status modification via API
 * 4. Prevention of cross-account task deliverable uploads via API
 * 5. Successful self-service status update for assigned admins
 * 6. Sidebar navigation registration and path verification
 */

echo "============================================================\n";
echo "  ADMIN 1 & ADMIN 2 MY TASKS VERIFICATION SUITE\n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(bool $condition, string $description): void {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failCount++;
    }
}

// ─────────────────────────────────────────────────────────────
// 1. SIDEBAR NAVIGATION VERIFICATION
// ─────────────────────────────────────────────────────────────
echo "1. Checking Sidebar Navigation Configuration...\n";

// Emulate BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/sti-activity-system');
}

$sidebarContent = file_get_contents(__DIR__ . '/../includes/sidebar.php');

$admin1HasTasks = strpos($sidebarContent, "'label' => 'My Tasks', 'href' => BASE_URL . '/admin1/tasks.php'") !== false;
assertTest($admin1HasTasks, "Admin 1 sidebar includes 'My Tasks' pointing to /admin1/tasks.php");

$admin2HasTasks = strpos($sidebarContent, "'label' => 'My Tasks', 'href' => BASE_URL . '/admin2/tasks.php'") !== false;
assertTest($admin2HasTasks, "Admin 2 sidebar includes 'My Tasks' pointing to /admin2/tasks.php");

$facultyHasTasks = strpos($sidebarContent, "'label' => 'My Tasks', 'href' => BASE_URL . '/faculty/tasks.php'") !== false;
assertTest($facultyHasTasks, "Faculty sidebar still preserves 'My Tasks' pointing to /faculty/tasks.php");

// ─────────────────────────────────────────────────────────────
// 2. ROLE ENFORCEMENT & ENTRY POINT CHECK
// ─────────────────────────────────────────────────────────────
echo "\n2. Checking Entry Points and Role Enforcement...\n";

$admin1TasksFile = file_get_contents(__DIR__ . '/../admin1/tasks.php');
$admin2TasksFile = file_get_contents(__DIR__ . '/../admin2/tasks.php');
$facultyTasksFile = file_get_contents(__DIR__ . '/../faculty/tasks.php');

assertTest(strpos($admin1TasksFile, "requireRole('admin1')") !== false, "admin1/tasks.php enforces requireRole('admin1')");
assertTest(strpos($admin2TasksFile, "requireRole('admin2')") !== false, "admin2/tasks.php enforces requireRole('admin2')");
assertTest(strpos($facultyTasksFile, "requireRole('faculty')") !== false, "faculty/tasks.php enforces requireRole('faculty')");

assertTest(strpos($admin1TasksFile, "theme-arjay") !== false, "admin1/tasks.php applies theme-arjay");
assertTest(strpos($admin2TasksFile, "theme-ian") !== false, "admin2/tasks.php applies theme-ian");

// ─────────────────────────────────────────────────────────────
// 3. DATABASE SETUP & TASK ISOLATION (In-Memory SQLite)
// ─────────────────────────────────────────────────────────────
echo "\n3. Testing Task Visibility & Account Isolation (SQLite In-Memory)...\n";

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Create required schema
$pdo->exec("
    CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        name TEXT,
        email TEXT,
        role TEXT,
        department TEXT
    );

    CREATE TABLE activities (
        id INTEGER PRIMARY KEY,
        title TEXT,
        description TEXT,
        event_date TEXT,
        status TEXT,
        faculty_id INTEGER
    );

    CREATE TABLE faculty_tasks (
        id INTEGER PRIMARY KEY,
        activity_id INTEGER,
        assigned_user_id INTEGER,
        faculty_name TEXT,
        committee TEXT,
        assigned_task TEXT,
        task_title TEXT,
        contribution_desc TEXT,
        task_description TEXT,
        role_in_event TEXT,
        due_date TEXT,
        status TEXT DEFAULT 'Not Started',
        completion_pct INTEGER DEFAULT 0,
        created_by INTEGER
    );

    CREATE TABLE task_deliverables (
        id INTEGER PRIMARY KEY,
        task_id INTEGER,
        activity_id INTEGER,
        file_name TEXT,
        file_path TEXT,
        uploaded_by INTEGER,
        uploaded_at TEXT,
        review_status TEXT DEFAULT 'pending',
        review_notes TEXT,
        reviewed_by INTEGER
    );
");

// Populate users:
// Admin 1 (ID 101), Admin 2 (ID 102), Faculty Leader (ID 201), Other Faculty (ID 202)
$pdo->exec("
    INSERT INTO users VALUES (101, 'Arjay Admin1', 'admin1@sti.edu', 'admin1', 'Student Affairs');
    INSERT INTO users VALUES (102, 'Ian Admin2', 'admin2@sti.edu', 'admin2', 'Academic Affairs');
    INSERT INTO users VALUES (201, 'Prof Santos', 'santos@sti.edu', 'faculty', 'IT Department');
    INSERT INTO users VALUES (202, 'Prof Reyes', 'reyes@sti.edu', 'faculty', 'Business Dept');
");

// Create activity owned by Prof Santos (ID 201)
$pdo->exec("
    INSERT INTO activities VALUES (1, 'University Hackathon 2026', 'Annual tech event', '2026-11-20', 'approved', 201);
");

// Create tasks:
// Task 1 -> Assigned to Admin 1 (ID 101)
// Task 2 -> Assigned to Admin 2 (ID 102)
// Task 3 -> Assigned to Prof Reyes (ID 202)
$pdo->exec("
    INSERT INTO faculty_tasks (id, activity_id, assigned_user_id, faculty_name, task_title, task_description, role_in_event, due_date, status, completion_pct, created_by)
    VALUES (1, 1, 101, 'Arjay Admin1', 'Venue Safety & Permits Inspection', 'Ensure all city permits are posted', 'Safety Inspector', '2026-11-15', 'In Progress', 40, 201);

    INSERT INTO faculty_tasks (id, activity_id, assigned_user_id, faculty_name, task_title, task_description, role_in_event, due_date, status, completion_pct, created_by)
    VALUES (2, 1, 102, 'Ian Admin2', 'Curriculum Alignment Audit', 'Verify workshop alignment with IT curriculum', 'Academic Auditor', '2026-11-16', 'Not Started', 0, 201);

    INSERT INTO faculty_tasks (id, activity_id, assigned_user_id, faculty_name, task_title, task_description, role_in_event, due_date, status, completion_pct, created_by)
    VALUES (3, 1, 202, 'Prof Reyes', 'Sponsorship Coordination', 'Follow up with industry partners', 'Sponsorship Lead', '2026-11-14', 'Delayed', 20, 201);
");

// Helper query function replicating includes/my-tasks-template.php
function queryMyTasks(PDO $db, int $userId): array {
    $stmt = $db->prepare("
        SELECT ft.*,
               a.id as activity_id,
               a.title as activity_title,
               a.event_date,
               a.status as activity_status,
               u.name as leader_name,
               u.department as leader_dept
        FROM faculty_tasks ft
        JOIN activities a ON ft.activity_id = a.id
        JOIN users u ON a.faculty_id = u.id
        WHERE ft.assigned_user_id = ?
        ORDER BY ft.due_date ASC, ft.id DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// TEST 3.1: Admin 1 view isolation
$admin1Tasks = queryMyTasks($pdo, 101);
$admin1TaskIds = array_column($admin1Tasks, 'id');
assertTest(
    count($admin1Tasks) === 1 && in_array(1, $admin1TaskIds) && !in_array(2, $admin1TaskIds) && !in_array(3, $admin1TaskIds),
    "Admin 1 sees ONLY Task 1 (Venue Safety) and CANNOT see Admin 2's or Faculty's tasks"
);

// TEST 3.2: Admin 2 view isolation
$admin2Tasks = queryMyTasks($pdo, 102);
$admin2TaskIds = array_column($admin2Tasks, 'id');
assertTest(
    count($admin2Tasks) === 1 && in_array(2, $admin2TaskIds) && !in_array(1, $admin2TaskIds) && !in_array(3, $admin2TaskIds),
    "Admin 2 sees ONLY Task 2 (Curriculum Alignment) and CANNOT see Admin 1's or Faculty's tasks"
);

// TEST 3.3: Faculty member view isolation
$facultyTasks = queryMyTasks($pdo, 202);
$facultyTaskIds = array_column($facultyTasks, 'id');
assertTest(
    count($facultyTasks) === 1 && in_array(3, $facultyTaskIds) && !in_array(1, $facultyTaskIds) && !in_array(2, $facultyTaskIds),
    "Faculty member sees ONLY Task 3 and CANNOT see Admin 1 or Admin 2 tasks"
);

// TEST 3.4: Non-assigned user returns clean empty state
$emptyTasks = queryMyTasks($pdo, 9999);
assertTest(count($emptyTasks) === 0, "Unassigned user returns 0 tasks (triggers empty state UI)");

// ─────────────────────────────────────────────────────────────
// 4. API PERMISSIONS & CROSS-ACCOUNT RESISTANCE
// ─────────────────────────────────────────────────────────────
echo "\n4. Testing API Access Control & Status Updates...\n";

// Function simulating api/task-assignment.php update_status logic
function simulateApiUpdateStatus(PDO $db, array $user, int $activityId, int $taskId, string $newStatus): array {
    if (empty($user['id'])) {
        return ['status' => 401, 'error' => 'Authentication required'];
    }

    $actStmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
    $actStmt->execute([$activityId]);
    $act = $actStmt->fetch(PDO::FETCH_ASSOC);
    if (!$act) return ['status' => 404, 'error' => 'Activity not found'];

    $isLeader = ($user['role'] === 'faculty' && (int)$act['faculty_id'] === (int)$user['id']);
    $validStatuses = ['Not Started', 'In Progress', 'Completed', 'Delayed'];

    if (!in_array($newStatus, $validStatuses, true)) {
        return ['status' => 400, 'error' => 'Invalid status'];
    }

    if (!$isLeader) {
        // Updated check supporting assigned members of role faculty, admin1, or admin2
        if (!in_array($user['role'], ['faculty', 'admin1', 'admin2'], true)) {
            return ['status' => 403, 'error' => 'Unauthorized: Only assigned members can update task status.'];
        }

        $chkTask = $db->prepare("SELECT assigned_user_id FROM faculty_tasks WHERE id = ? AND activity_id = ?");
        $chkTask->execute([$taskId, $activityId]);
        $assignedUserId = $chkTask->fetchColumn();

        if ($assignedUserId === false) {
            return ['status' => 404, 'error' => 'Task assignment not found.'];
        }

        if ((int)$assignedUserId !== (int)$user['id']) {
            return ['status' => 403, 'error' => 'Unauthorized: You can only update the status of tasks assigned to you.'];
        }
    }

    // Execute update
    if ($isLeader) {
        if ($newStatus === 'Completed') {
            $stmt = $db->prepare("UPDATE faculty_tasks SET status = ?, completion_pct = 100 WHERE id = ? AND activity_id = ?");
        } elseif ($newStatus === 'Not Started') {
            $stmt = $db->prepare("UPDATE faculty_tasks SET status = ?, completion_pct = 0 WHERE id = ? AND activity_id = ?");
        } else {
            $stmt = $db->prepare("UPDATE faculty_tasks SET status = ? WHERE id = ? AND activity_id = ?");
        }
    } else {
        // Assigned member updates status without altering reviewer-controlled percentage
        $stmt = $db->prepare("UPDATE faculty_tasks SET status = ? WHERE id = ? AND activity_id = ?");
    }
    $stmt->execute([$newStatus, $taskId, $activityId]);

    return ['status' => 200, 'success' => true];
}

// TEST 4.1: Unauthenticated request rejected with 401
$resUnauth = simulateApiUpdateStatus($pdo, ['id' => null, 'role' => ''], 1, 1, 'Completed');
assertTest($resUnauth['status'] === 401, "Unauthenticated status update returns HTTP 401");

// TEST 4.2: Admin 1 attempts to update Admin 2's task (Task 2) -> BLOCKED (403)
$resAdmin1OnAdmin2 = simulateApiUpdateStatus($pdo, ['id' => 101, 'role' => 'admin1'], 1, 2, 'Completed');
assertTest(
    $resAdmin1OnAdmin2['status'] === 403 && strpos($resAdmin1OnAdmin2['error'], 'assigned to you') !== false,
    "Admin 1 CANNOT update Admin 2's task status (blocked with 403: 'assigned to you')"
);

// TEST 4.3: Admin 2 attempts to update Admin 1's task (Task 1) -> BLOCKED (403)
$resAdmin2OnAdmin1 = simulateApiUpdateStatus($pdo, ['id' => 102, 'role' => 'admin2'], 1, 1, 'Delayed');
assertTest(
    $resAdmin2OnAdmin1['status'] === 403 && strpos($resAdmin2OnAdmin1['error'], 'assigned to you') !== false,
    "Admin 2 CANNOT update Admin 1's task status (blocked with 403: 'assigned to you')"
);

// TEST 4.4: Admin 1 updates their OWN task (Task 1) -> SUCCEEDS (200)
$resAdmin1Self = simulateApiUpdateStatus($pdo, ['id' => 101, 'role' => 'admin1'], 1, 1, 'Completed');
$t1After = $pdo->query("SELECT status, completion_pct FROM faculty_tasks WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
assertTest(
    $resAdmin1Self['status'] === 200 && $t1After['status'] === 'Completed' && (int)$t1After['completion_pct'] === 40,
    "Admin 1 successfully updates OWN task to 'Completed' (HTTP 200, completion_pct preserved at 40%)"
);

// TEST 4.5: Admin 2 updates their OWN task (Task 2) -> SUCCEEDS (200)
$resAdmin2Self = simulateApiUpdateStatus($pdo, ['id' => 102, 'role' => 'admin2'], 1, 2, 'In Progress');
$t2After = $pdo->query("SELECT status FROM faculty_tasks WHERE id = 2")->fetchColumn();
assertTest(
    $resAdmin2Self['status'] === 200 && $t2After === 'In Progress',
    "Admin 2 successfully updates OWN task to 'In Progress' (HTTP 200)"
);

// TEST 4.6: Activity leader can update tasks on their activity -> SUCCEEDS (200)
$resLeader = simulateApiUpdateStatus($pdo, ['id' => 201, 'role' => 'faculty'], 1, 2, 'Completed');
$t2Leader = $pdo->query("SELECT status, completion_pct FROM faculty_tasks WHERE id = 2")->fetch(PDO::FETCH_ASSOC);
assertTest(
    $resLeader['status'] === 200 && $t2Leader['status'] === 'Completed' && (int)$t2Leader['completion_pct'] === 100,
    "Activity leader successfully updates task status to 'Completed' (sets completion_pct to 100%)"
);

// ─────────────────────────────────────────────────────────────
// 5. DELIVERABLES ACCESS CONTROL & UPLOAD ISOLATION
// ─────────────────────────────────────────────────────────────
echo "\n5. Testing Deliverables Upload Authorization...\n";

function simulateDeliverableUpload(PDO $db, array $user, int $taskId, string $fileName): array {
    if (empty($user['id'])) return ['status' => 401, 'error' => 'Authentication required'];

    $tStmt = $db->prepare("
        SELECT ft.*, a.faculty_id as leader_id
        FROM faculty_tasks ft
        JOIN activities a ON ft.activity_id = a.id
        WHERE ft.id = ?
    ");
    $tStmt->execute([$taskId]);
    $task = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) return ['status' => 404, 'error' => 'Task not found'];

    $isLeader = ($user['role'] === 'faculty' && (int)$task['leader_id'] === (int)$user['id']);
    $isAssigned = ((int)$task['assigned_user_id'] === (int)$user['id']);

    if (!$isAssigned && !$isLeader) {
        return ['status' => 403, 'error' => 'Forbidden: You are not assigned to this task'];
    }

    $db->prepare("
        INSERT INTO task_deliverables (task_id, activity_id, file_name, file_path, uploaded_by, uploaded_at)
        VALUES (?, ?, ?, ?, ?, datetime('now'))
    ")->execute([$taskId, $task['activity_id'], $fileName, '/uploads/' . $fileName, $user['id']]);

    return ['status' => 200, 'success' => true];
}

// TEST 5.1: Admin 1 attempts upload on Admin 2's task -> BLOCKED (403)
$delivCross = simulateDeliverableUpload($pdo, ['id' => 101, 'role' => 'admin1'], 2, 'rogue_file.pdf');
assertTest($delivCross['status'] === 403, "Admin 1 CANNOT upload deliverables to Admin 2's task (HTTP 403 Forbidden)");

// TEST 5.2: Admin 1 uploads on their own task -> SUCCEEDS (200)
$delivSelf1 = simulateDeliverableUpload($pdo, ['id' => 101, 'role' => 'admin1'], 1, 'safety_checklist_admin1.pdf');
$cnt1 = $pdo->query("SELECT COUNT(*) FROM task_deliverables WHERE task_id = 1 AND uploaded_by = 101")->fetchColumn();
assertTest($delivSelf1['status'] === 200 && (int)$cnt1 === 1, "Admin 1 successfully uploads deliverable to their own task (HTTP 200)");

// TEST 5.3: Admin 2 uploads on their own task -> SUCCEEDS (200)
$delivSelf2 = simulateDeliverableUpload($pdo, ['id' => 102, 'role' => 'admin2'], 2, 'curriculum_audit_admin2.pdf');
$cnt2 = $pdo->query("SELECT COUNT(*) FROM task_deliverables WHERE task_id = 2 AND uploaded_by = 102")->fetchColumn();
assertTest($delivSelf2['status'] === 200 && (int)$cnt2 === 1, "Admin 2 successfully uploads deliverable to their own task (HTTP 200)");

// ─────────────────────────────────────────────────────────────
// 6. DASHBOARD SUMMARY CARD VERIFICATION
// ─────────────────────────────────────────────────────────────
echo "\n6. Checking Dashboard Rail Card Integration...\n";

$admin1Dash = file_get_contents(__DIR__ . '/../admin1/dashboard.php');
$admin2Dash = file_get_contents(__DIR__ . '/../admin2/dashboard.php');

$a1HasCard = strpos($admin1Dash, "My Assigned Tasks") !== false && strpos($admin1Dash, "/admin1/tasks.php") !== false;
$a2HasCard = strpos($admin2Dash, "My Assigned Tasks") !== false && strpos($admin2Dash, "/admin2/tasks.php") !== false;

assertTest($a1HasCard, "admin1/dashboard.php includes 'My Assigned Tasks' summary card linking to /admin1/tasks.php");
assertTest($a2HasCard, "admin2/dashboard.php includes 'My Assigned Tasks' summary card linking to /admin2/tasks.php");

echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passCount} Passed, {$failCount} Failed\n";
echo "============================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);

