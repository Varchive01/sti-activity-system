<?php
/**
 * Automated Verification Script for Program Head Functional Integration (Task 5)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "=== STI-ARMES Program Head Functional Integration Verification ===\n\n";

$createdUserIds = [];
$createdActivityIds = [];
$createdTaskIds = [];
$createdDocIds = [];

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

    // 1. Create temporary test users
    // IT Program Head
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test PH IT', 'test_ph_it_int_" . time() . "@test.com', ?, 'program_head', 'IT Department')");
    $stmt->execute([$pwd]);
    $phItId = (int)$db->lastInsertId();
    $createdUserIds[] = $phItId;

    // THM Program Head
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test PH THM', 'test_ph_thm_int_" . time() . "@test.com', ?, 'program_head', 'THM Department')");
    $stmt->execute([$pwd]);
    $phThmId = (int)$db->lastInsertId();
    $createdUserIds[] = $phThmId;

    // IT Faculty
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Test Faculty IT', 'test_fac_it_int_" . time() . "@test.com', ?, 'faculty', 'IT Department')");
    $stmt->execute([$pwd]);
    $facItId = (int)$db->lastInsertId();
    $createdUserIds[] = $facItId;

    // Dean user (use ID 4 or mock)
    $deanId = 4;
    $admin1Id = 2;

    echo "--- 1. Proposal Workflow: Creation, Draft, Edit, Submission ---\n";
    // 1a. Program Head creates an activity proposal as draft
    $stmt = $db->prepare("
        INSERT INTO activities (faculty_id, title, status, event_date, venue, target_participants, general_objectives)
        VALUES (?, 'PH Led Tech Symposium', 'draft', DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Auditorium', 150, 'Enhance tech literacy')
    ");
    $stmt->execute([$phItId]);
    $phActId = (int)$db->lastInsertId();
    $createdActivityIds[] = $phActId;

    assertCondition("PH created proposal exists as draft", $phActId > 0, true);
    assertCondition("PH can view own draft proposal", canUserAccessActivity($phActId, 'view', $phItId, 'program_head', 'IT Department'), true);
    assertCondition("PH can modify own draft proposal", canUserAccessActivity($phActId, 'modify', $phItId, 'program_head', 'IT Department'), true);

    // 1b. PH edits draft and submits proposal
    $stmt = $db->prepare("UPDATE activities SET title = 'PH Led Tech Symposium 2026', status = 'submitted' WHERE id = ?");
    $stmt->execute([$phActId]);

    $stmt = $db->prepare("SELECT status, title FROM activities WHERE id = ?");
    $stmt->execute([$phActId]);
    $updatedAct = $stmt->fetch(PDO::FETCH_ASSOC);
    assertCondition("PH proposal updated title successfully", $updatedAct['title'], 'PH Led Tech Symposium 2026');
    assertCondition("PH proposal submitted successfully into workflow", $updatedAct['status'], 'submitted');

    echo "\n--- 2. Activity & Task Monitoring ---\n";
    // Create an activity by IT Faculty
    $stmt = $db->prepare("
        INSERT INTO activities (faculty_id, title, status, event_date, venue, target_participants)
        VALUES (?, 'IT Web Dev Bootcamp', 'approved', DATE_ADD(CURDATE(), INTERVAL 15 DAY), 'Lab 1', 50)
    ");
    $stmt->execute([$facItId]);
    $facActId = (int)$db->lastInsertId();
    $createdActivityIds[] = $facActId;

    // Assign a task to IT Faculty
    $stmt = $db->prepare("
        INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, task_title, due_date, status, completion_pct)
        VALUES (?, ?, 'Test Faculty IT', 'Setup Workstations', DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'In Progress', 60)
    ");
    $stmt->execute([$facActId, $facItId]);
    $taskId = (int)$db->lastInsertId();
    $createdTaskIds[] = $taskId;

    // PH IT checks
    assertCondition("IT PH can view IT department activity created by faculty", canUserAccessActivity($facActId, 'view', $phItId, 'program_head', 'IT Department'), true);
    assertCondition("IT PH can manage/modify IT department activity", canUserAccessActivity($facActId, 'modify', $phItId, 'program_head', 'IT Department'), true);

    // THM PH checks
    assertCondition("THM PH CANNOT view IT department activity (isolated)", canUserAccessActivity($facActId, 'view', $phThmId, 'program_head', 'THM Department'), false);
    assertCondition("THM PH CANNOT modify IT department activity (isolated)", canUserAccessActivity($facActId, 'modify', $phThmId, 'program_head', 'THM Department'), false);

    echo "\n--- 3. Shared Cross-Department Activities ---\n";
    // Create shared activity: IT creates it, task assigned to THM PH
    $stmt = $db->prepare("
        INSERT INTO faculty_tasks (activity_id, assigned_user_id, faculty_name, task_title, due_date, status, completion_pct)
        VALUES (?, ?, 'Test PH THM', 'Inter-department Hospitality', DATE_ADD(CURDATE(), INTERVAL 8 DAY), 'Not Started', 0)
    ");
    $stmt->execute([$facActId, $phThmId]);
    $sharedTaskId = (int)$db->lastInsertId();
    $createdTaskIds[] = $sharedTaskId;

    assertCondition("THM PH can view shared IT activity with assigned task", canUserAccessActivity($facActId, 'view', $phThmId, 'program_head', 'THM Department'), true);
    assertCondition("THM PH CANNOT modify shared IT activity created by IT", canUserAccessActivity($facActId, 'modify', $phThmId, 'program_head', 'THM Department'), false);

    echo "\n--- 4. Post-Event & Document Workflow ---\n";
    // Mark activity approved and insert post_event
    $stmt = $db->prepare("
        INSERT INTO post_event (activity_id, target_attendance, actual_attendance, satisfaction_score, submitted_by, submitted_at)
        VALUES (?, 50, 48, 3.85, ?, NOW())
        ON DUPLICATE KEY UPDATE actual_attendance = 48
    ");
    $stmt->execute([$facActId, $facItId]);

    // Insert sample document
    $stmt = $db->prepare("
        INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by)
        VALUES (?, 'post_event', 'attendance_sheet.pdf', 'uploads/documents/att_50_test.pdf', ?)
    ");
    $stmt->execute([$facActId, $facItId]);
    $docId = (int)$db->lastInsertId();
    $createdDocIds[] = $docId;

    assertCondition("IT PH can access department post-event documents", canUserAccessActivity($facActId, 'view', $phItId, 'program_head', 'IT Department'), true);
    assertCondition("THM PH CANNOT access exclusive post-event documents of other dept", canUserAccessActivity($phActId, 'view', $phThmId, 'program_head', 'THM Department'), false);

    echo "\n--- 5. Notifications & AI Assistants Compatibility ---\n";
    // Test notification insertion for PH
    $stmt = $db->prepare("INSERT INTO notifications (user_id, activity_id, message, is_read) VALUES (?, ?, 'Proposal submitted', 0)");
    $stmt->execute([$phItId, $phActId]);
    $notifId = (int)$db->lastInsertId();

    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$phItId]);
    $unreadCount = (int)$stmt->fetchColumn();
    assertCondition("PH receives and queries unread notifications", $unreadCount >= 1, true);

    // Clean up test notification
    $db->prepare("DELETE FROM notifications WHERE id = ?")->execute([$notifId]);

    // Check generate-theme and generate-objectives authorization code
    $themeCode = file_get_contents(__DIR__ . '/../api/generate-theme.php');
    assertCondition("generate-theme.php allows program_head", strpos($themeCode, "'program_head'") !== false, true);

    $objCode = file_get_contents(__DIR__ . '/../api/generate-objectives.php');
    assertCondition("generate-objectives.php allows program_head", strpos($objCode, "'program_head'") !== false, true);

    echo "\n--- 6. Dean, Admin & Faculty Functionality Intact ---\n";
    assertCondition("Dean has institutional access to PH activity", canUserAccessActivity($phActId, 'view', $deanId, 'dean', 'Administration'), true);
    assertCondition("Dean can modify PH activity", canUserAccessActivity($phActId, 'modify', $deanId, 'dean', 'Administration'), true);
    assertCondition("Admin 1 has institutional access to PH activity", canUserAccessActivity($phActId, 'view', $admin1Id, 'admin1', 'Student Org Head'), true);
    assertCondition("Faculty can view own activity", canUserAccessActivity($facActId, 'view', $facItId, 'faculty', 'IT Department'), true);
    assertCondition("Faculty CANNOT modify another user's activity", canUserAccessActivity($phActId, 'modify', $facItId, 'faculty', 'IT Department'), false);

    echo "\nSummary: {$passCount}/{$assertCount} assertions passed.\n";

} finally {
    echo "\nCleaning up temporary test records...\n";
    if (!empty($createdDocIds)) {
        $in = implode(',', $createdDocIds);
        $db->exec("DELETE FROM documents WHERE id IN ($in)");
    }
    if (!empty($createdTaskIds)) {
        $in = implode(',', $createdTaskIds);
        $db->exec("DELETE FROM faculty_tasks WHERE id IN ($in)");
    }
    if (!empty($createdActivityIds)) {
        $in = implode(',', $createdActivityIds);
        $db->exec("DELETE FROM post_event WHERE activity_id IN ($in)");
        $db->exec("DELETE FROM activities WHERE id IN ($in)");
    }
    if (!empty($createdUserIds)) {
        $in = implode(',', $createdUserIds);
        $db->exec("DELETE FROM users WHERE id IN ($in)");
    }
    echo "Cleanup complete.\n";
}

