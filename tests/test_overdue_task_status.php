<?php
/**
 * tests/test_overdue_task_status.php
 *
 * Verification suite for Overdue Task Status Audit and Fix:
 * 1. Confirm how task status is stored and overdue status is calculated and displayed.
 * 2. Keep execution status ('Not Started', 'In Progress', 'Completed', 'Delayed') distinct from deadline condition.
 * 3. Incomplete task past due date clearly displays as overdue without falsely marking it completed or losing actual progress.
 * 4. Completed task must NOT be displayed as overdue merely because its due date has passed.
 * 5. Automated status changes do not overwrite execution status on overdue tasks.
 * 6. Dean/Admin1/Admin2 read-only permissions remain strictly intact.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
$testActId = null;
$createdTaskIds = [];
$testUserIds = [];

function out($msg) {
    echo $msg . "\n";
}

function assertTest($condition, $description) {
    if ($condition) {
        echo "  [PASS] {$description}\n";
        return true;
    } else {
        echo "  [FAIL] {$description}\n";
        return false;
    }
}

$allPassed = true;
out("============================================================");
out("  OVERDUE TASK STATUS AUDIT AND FIX VERIFICATION SUITE");
out("============================================================");

$loginUrl = BASE_URL . '/auth/login.php';

function httpRequest(string $url, string $method = 'GET', $data = null, ?string $cookie = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if (!empty($cookie)) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    }

    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr($raw, 0, $hSize);
    $body = substr($raw, $hSize);

    return [
        'code' => $code,
        'headers' => $headerStr,
        'body' => $body
    ];
}

function getLoginCookie(string $email, string $password): string {
    global $loginUrl;
    $res = httpRequest($loginUrl, 'POST', [
        'email' => $email,
        'password' => $password
    ]);

    preg_match_all('/Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $res['headers'], $m);
    if (!empty($m[1])) {
        return end($m[1]);
    }
    return '';
}

try {
    // 1. Create test faculty and dean
    $timestamp = time();
    $leaderEmail = "audit_lead_{$timestamp}@sti.edu";
    $deanEmail   = "audit_dean_{$timestamp}@sti.edu";
    $facPass = password_hash('Pass123!', PASSWORD_BCRYPT);

    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Audit Leader', ?, ?, 'faculty', 'CCS')")
       ->execute([$leaderEmail, $facPass]);
    $leaderId = (int)$db->lastInsertId();
    $testUserIds[] = $leaderId;

    $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Audit Dean', ?, ?, 'dean', 'Academic Affairs')")
       ->execute([$deanEmail, $facPass]);
    $deanId = (int)$db->lastInsertId();
    $testUserIds[] = $deanId;

    $actStmt = $db->prepare("
        INSERT INTO activities (faculty_id, title, description, theme, venue, event_date, start_time, end_time, status, created_at, updated_at)
        VALUES (?, 'Overdue Status Verification Activity', 'Test Desc', 'Test Theme', 'Gym', DATE_ADD(CURDATE(), INTERVAL 7 DAY), '08:00', '17:00', 'approved', NOW(), NOW())
    ");
    $actStmt->execute([$leaderId]);
    $testActId = (int)$db->lastInsertId();
    assertTest($testActId > 0, "Test activity created with ID {$testActId}");

    // Log in leader and dean
    $leaderCookie = getLoginCookie($leaderEmail, 'Pass123!');
    $deanCookie   = getLoginCookie($deanEmail, 'Pass123!');
    assertTest(!empty($leaderCookie), "Leader successfully authenticated with session");
    assertTest(!empty($deanCookie), "Dean successfully authenticated with session");

    // 2. Create Tasks:
    // Task A: Incomplete (In Progress, 45%), Past Due (overdue)
    // Task B: Completed (100%), Past Due (must NOT be overdue)
    // Task C: Incomplete (Not Started, 0%), Future Due (On Track)
    // Task D: Explicit Delayed, Past Due (Overdue)
    $yesterday = date('Y-m-d', strtotime('-2 days'));
    $futureDate = date('Y-m-d', strtotime('+5 days'));

    $insTask = $db->prepare("
        INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, committee, task_title, task_description, role_in_event, due_date, status, completion_pct, created_by, created_at)
        VALUES (?, ?, 'Audit Leader', 'Technical', ?, 'Task Desc', 'Lead', ?, ?, ?, ?, NOW())
    ");

    $insTask->execute([$testActId, $leaderId, 'Task A: Incomplete Past Due', $yesterday, 'In Progress', 45, $leaderId]);
    $taskA = (int)$db->lastInsertId();
    $createdTaskIds[] = $taskA;

    $insTask->execute([$testActId, $leaderId, 'Task B: Completed Past Due', $yesterday, 'Completed', 100, $leaderId]);
    $taskB = (int)$db->lastInsertId();
    $createdTaskIds[] = $taskB;

    $insTask->execute([$testActId, $leaderId, 'Task C: Future Due Not Started', $futureDate, 'Not Started', 0, $leaderId]);
    $taskC = (int)$db->lastInsertId();
    $createdTaskIds[] = $taskC;

    $insTask->execute([$testActId, $leaderId, 'Task D: Explicit Delayed Past Due', $yesterday, 'Delayed', 30, $leaderId]);
    $taskD = (int)$db->lastInsertId();
    $createdTaskIds[] = $taskD;

    out("\n--- 1. Testing Dean Centralized Task Monitoring View ---");
    $deanRes = httpRequest(BASE_URL . "/dean/task-monitoring.php?activity_id={$testActId}", 'GET', null, $deanCookie);
    $allPassed &= assertTest($deanRes['code'] === 200, "Dean can load task-monitoring.php (HTTP 200)");

    // Find the Monitoring Table (after Gantt visualization)
    $tablePos = strpos($deanRes['body'], 'activity-table');
    assertTest($tablePos !== false, "Task monitoring table located in Dean page");
    $tableHtml = substr($deanRes['body'], $tablePos);

    // Task A: Incomplete, past due -> Status 'In Progress', Timing 'Delayed (Overdue)'
    $taskAPos = strpos($tableHtml, 'Task A: Incomplete Past Due');
    $allPassed &= assertTest($taskAPos !== false, "Dean table renders Task A");
    $taskARow = substr($tableHtml, $taskAPos, 2000);
    $allPassed &= assertTest(str_contains($taskARow, 'In Progress'), "Task A shows execution status 'In Progress'");
    $allPassed &= assertTest(str_contains($taskARow, 'Delayed (Overdue)'), "Task A shows timing indicator 'Delayed (Overdue)'");
    $allPassed &= assertTest(str_contains($taskARow, '45%'), "Task A progress is preserved at 45%");

    // Task B: Completed, past due -> Status 'Completed', Timing 'Completed' (must NOT be Overdue)
    $taskBPos = strpos($tableHtml, 'Task B: Completed Past Due');
    $allPassed &= assertTest($taskBPos !== false, "Dean table renders Task B");
    $taskBRow = substr($tableHtml, $taskBPos, 2000);
    $allPassed &= assertTest(str_contains($taskBRow, 'badge-success') && str_contains($taskBRow, 'Completed'), "Task B shows execution status 'Completed'");
    $allPassed &= assertTest(!str_contains($taskBRow, 'Delayed (Overdue)'), "Task B row does NOT show 'Delayed (Overdue)' despite past due date");

    // Task C: Future due -> Status 'Not Started', Timing 'On Track'
    $taskCPos = strpos($tableHtml, 'Task C: Future Due Not Started');
    $allPassed &= assertTest($taskCPos !== false, "Dean table renders Task C");
    $taskCRow = substr($tableHtml, $taskCPos, 2000);
    $allPassed &= assertTest(str_contains($taskCRow, 'On Track'), "Task C shows timing 'On Track'");

    out("\n--- 2. Testing Faculty View Activity Overdue Badge ---");
    $facRes = httpRequest(BASE_URL . "/faculty/view-activity.php?id={$testActId}", 'GET', null, $leaderCookie);
    $allPassed &= assertTest($facRes['code'] === 200, "Leader can load view-activity.php (HTTP 200)");

    // Task A row in faculty view must have 'Overdue' badge next to target date
    $taskAFacPos = strpos($facRes['body'], 'task-row-' . $taskA);
    $allPassed &= assertTest($taskAFacPos !== false, "Faculty view contains task row for Task A");
    $taskAFacRow = substr($facRes['body'], $taskAFacPos, 2500);
    $allPassed &= assertTest(str_contains($taskAFacRow, 'Overdue'), "Task A displays 'Overdue' badge on faculty view");
    $allPassed &= assertTest(str_contains($taskAFacRow, '45%'), "Task A retains 45% completion in faculty view");

    // Task B row (completed) in faculty view must NOT have 'Overdue' badge
    $taskBFacPos = strpos($facRes['body'], 'task-row-' . $taskB);
    $allPassed &= assertTest($taskBFacPos !== false, "Faculty view contains task row for Task B");
    $taskBFacRow = substr($facRes['body'], $taskBFacPos, 2500);
    $allPassed &= assertTest(!str_contains($taskBFacRow, 'Overdue'), "Task B (Completed) does NOT display 'Overdue' badge");

    out("\n--- 3. Deliverable Review Execution Status Integrity ---");
    // Upload a deliverable for Task A
    $insDeliv = $db->prepare("
        INSERT INTO task_deliverables (task_id, activity_id, uploaded_by, file_name, file_path, file_size, mime_type, uploaded_at)
        VALUES (?, ?, ?, 'test_evidence.pdf', 'uploads/deliverables/test.pdf', 1024, 'application/pdf', NOW())
    ");
    $insDeliv->execute([$taskA, $testActId, $leaderId]);
    $delivId = (int)$db->lastInsertId();

    // Call review with completion_pct = 60%
    $revRes = httpRequest(BASE_URL . '/api/task-deliverables.php', 'POST', [
        'action' => 'review',
        'deliverable_id' => $delivId,
        'review_status' => 'approved',
        'completion_pct' => 60,
        'notes' => 'Good progress'
    ], $leaderCookie);

    $revDec = json_decode($revRes['body'], true);
    $allPassed &= assertTest($revDec['success'] === true, "Deliverable review API returned success");
    $allPassed &= assertTest($revDec['task_status'] === 'In Progress', "Deliverable review API returned task_status 'In Progress'");
    $allPassed &= assertTest((int)$revDec['completion_pct'] === 60, "Deliverable review API returned completion_pct 60%");

    // Verify database state: status remains In Progress and 60%
    $chkA = $db->prepare("SELECT status, completion_pct FROM faculty_tasks WHERE id = ?");
    $chkA->execute([$taskA]);
    $taskAAfter = $chkA->fetch(PDO::FETCH_ASSOC);
    $allPassed &= assertTest($taskAAfter['status'] === 'In Progress', "Task A status preserved as 'In Progress' in DB (not forced to Delayed)");
    $allPassed &= assertTest((int)$taskAAfter['completion_pct'] === 60, "Task A completion_pct updated to 60% in DB");

    out("\n--- 4. Read-Only Protection for Dean ---");
    $deanMutRes = httpRequest(BASE_URL . '/api/task-assignment.php', 'POST', [
        'action' => 'update_status',
        'activity_id' => $testActId,
        'task_id' => $taskA,
        'status' => 'Completed'
    ], $deanCookie);
    $allPassed &= assertTest($deanMutRes['code'] === 403, "Dean blocked from modifying task status (HTTP {$deanMutRes['code']})");

} catch (Exception $e) {
    out("  [ERROR] " . $e->getMessage());
    $allPassed = false;
} finally {
    // Cleanup
    if ($testActId) {
        $db->prepare("DELETE FROM task_deliverables WHERE activity_id = ?")->execute([$testActId]);
        $db->prepare("DELETE FROM task_reminder_logs WHERE activity_id = ?")->execute([$testActId]);
        $db->prepare("DELETE FROM notifications WHERE activity_id = ?")->execute([$testActId]);
        $db->prepare("DELETE FROM faculty_tasks WHERE activity_id = ?")->execute([$testActId]);
        $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);
    }
    if (!empty($testUserIds)) {
        $in = implode(',', array_fill(0, count($testUserIds), '?'));
        $db->prepare("DELETE FROM notifications WHERE user_id IN ($in)")->execute($testUserIds);
        $db->prepare("DELETE FROM users WHERE id IN ($in)")->execute($testUserIds);
    }
    out("\n--- Cleanup ---");
    out("  Test fixtures cleaned up successfully.");
}

out("\n============================================================");
out($allPassed ? "  ALL OVERDUE TASK STATUS TESTS PASSED!" : "  SOME TESTS FAILED.");
out("============================================================");
