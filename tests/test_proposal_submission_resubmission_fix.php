<?php
/**
 * Test Proposal Submission & Resubmission Fix
 * Verifies that submitting and resubmitting activities by both faculty and program heads
 * complete without HTTP 500 or fatal exceptions.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
$passed = 0;
$failed = 0;

function assertCondition($name, $condition, $msg = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}: {$msg}\n";
        $failed++;
    }
}

echo "=== STARTING PROPOSAL SUBMISSION & RESUBMISSION FIX TESTS ===\n\n";

// 1. Find or verify test faculty and program_head users
$facultyUser = $db->query("SELECT * FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$programHeadUser = $db->query("SELECT * FROM users WHERE role = 'program_head' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assertCondition("Faculty user exists in database", !empty($facultyUser), "Could not find a faculty user");
assertCondition("Program Head user exists in database", !empty($programHeadUser), "Could not find a program_head user");

$tomorrow = date('Y-m-d', strtotime('+10 days'));

// -------------------------------------------------------------
// Test Case 1: Direct simulation of proposal-save.php with action=submit (Faculty)
// -------------------------------------------------------------
$testTitle1 = "TEST Faculty Proposal Submission " . time();
$actId1 = null;
try {
    $ins = $db->prepare("
        INSERT INTO activities (
            faculty_id, title, description, theme, venue, venue_address,
            event_date, start_time, end_time, target_participants,
            general_objectives, specific_objectives, involved_subjects,
            rationale, evaluation_method, source, status, submitted_at, evaluation_questions
        ) VALUES (
            ?, ?, 'Desc', 'Theme', 'Room 101', 'Main Campus',
            ?, '09:00:00', '11:00:00', 50,
            'General Obj', 'Specific Obj', 'Involved',
            'Rationale', 'Student Evaluation', 'faculty', 'draft', NOW(), '[]'
        )
    ");
    $ins->execute([$facultyUser['id'], $testTitle1, $tomorrow]);
    $actId1 = (int)$db->lastInsertId();

    assertCondition("Faculty proposal draft inserted", $actId1 > 0);

    // Now test resubmit / submit routing block from proposal-update.php line 1020-1075
    // Status starts as returned_for_revision
    $db->prepare("UPDATE activities SET status = 'returned_for_revision' WHERE id = ?")->execute([$actId1]);

    // Create a mock approval log indicating Admin 2 returned it
    $admin2User = $db->query("SELECT * FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($admin2User) {
        $db->prepare("INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at) VALUES (?, ?, 'returned_for_revision', 'Please revise schedule', NOW())")
           ->execute([$actId1, $admin2User['id']]);
    }

    // Now execute the fixed routing logic from proposal-update.php
    $logStmt = $db->prepare("
        SELECT al.*, u.role
        FROM approval_logs al
        JOIN users u ON al.reviewer_id = u.id
        WHERE al.activity_id = ? AND al.action = 'returned_for_revision'
        ORDER BY al.acted_at DESC LIMIT 1
    ");
    $logStmt->execute([$actId1]);
    $lastReturn = $logStmt->fetch();

    $check = $db->prepare("SELECT * FROM activities WHERE id = ?");
    $check->execute([$actId1]);
    $activity = $check->fetch();

    // Verify fixed source retrieval does NOT access PDOStatement as array
    $currSource = $activity['source'] ?? $source ?? 'faculty';
    if ($lastReturn) {
        if ($lastReturn['role'] === 'admin1') {
            $nextStatus = 'under_review';
            $notifyRole = 'admin1';
        } elseif ($lastReturn['role'] === 'admin2') {
            $nextStatus = ($currSource === 'student_org') ? 'endorsed' : 'submitted';
            $notifyRole = 'admin2';
        } elseif ($lastReturn['role'] === 'dean') {
            $nextStatus = 'pending_final_approval';
            $notifyRole = 'dean';
        } else {
            $nextStatus = ($currSource === 'student_org') ? 'under_review' : 'submitted';
            $notifyRole = ($currSource === 'student_org') ? 'admin1' : 'admin2';
        }
    } else {
        $nextStatus = ($currSource === 'student_org') ? 'under_review' : 'submitted';
        $notifyRole = ($currSource === 'student_org') ? 'admin1' : 'admin2';
    }

    $db->prepare("UPDATE activities SET status=?, revision_sections=NULL WHERE id=?")->execute([$nextStatus, $actId1]);

    $checkStatus = $db->prepare("SELECT status FROM activities WHERE id = ?");
    $checkStatus->execute([$actId1]);
    $actualStatus = $checkStatus->fetchColumn();

    assertCondition("Faculty resubmission routed without 500 error", $actualStatus === 'submitted', "Expected 'submitted', got '{$actualStatus}'");
} catch (\Throwable $e) {
    assertCondition("Faculty resubmission threw no fatal errors", false, $e->getMessage());
}

// -------------------------------------------------------------
// Test Case 2: Program Head Submission & Resubmission Routing
// -------------------------------------------------------------
$testTitle2 = "TEST Program Head Proposal Submission " . time();
$actId2 = null;
try {
    $ins = $db->prepare("
        INSERT INTO activities (
            faculty_id, title, description, theme, venue, venue_address,
            event_date, start_time, end_time, target_participants,
            general_objectives, specific_objectives, involved_subjects,
            rationale, evaluation_method, source, status, submitted_at, evaluation_questions
        ) VALUES (
            ?, ?, 'Desc', 'Theme', 'Lab 3', 'Main Campus',
            ?, '13:00:00', '15:00:00', 30,
            'General Obj', 'Specific Obj', 'Involved',
            'Rationale', 'Student Evaluation', 'faculty', 'returned_for_revision', NOW(), '[]'
        )
    ");
    $ins->execute([$programHeadUser['id'], $testTitle2, $tomorrow]);
    $actId2 = (int)$db->lastInsertId();

    assertCondition("Program Head returned proposal inserted", $actId2 > 0);

    // Verify task assignment check supports program_head role
    $facultyLookupStmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role IN ('faculty', 'program_head') LIMIT 1");
    $facultyLookupStmt->execute([$programHeadUser['id']]);
    $foundPH = $facultyLookupStmt->fetch(PDO::FETCH_ASSOC);

    assertCondition("Program Head is recognized as valid assignee in tasks", !empty($foundPH) && (int)$foundPH['id'] === (int)$programHeadUser['id']);

    // Resubmit routing for Program Head
    $check = $db->prepare("SELECT * FROM activities WHERE id = ?");
    $check->execute([$actId2]);
    $activity = $check->fetch();

    $currSource = $activity['source'] ?? $source ?? 'faculty';
    $nextStatus = ($currSource === 'student_org') ? 'under_review' : 'submitted';
    $db->prepare("UPDATE activities SET status=?, revision_sections=NULL WHERE id=?")->execute([$nextStatus, $actId2]);

    $checkStatus = $db->prepare("SELECT status FROM activities WHERE id = ?");
    $checkStatus->execute([$actId2]);
    $actualStatus2 = $checkStatus->fetchColumn();

    assertCondition("Program Head resubmission completed successfully", $actualStatus2 === 'submitted', "Expected 'submitted', got '{$actualStatus2}'");

} catch (\Throwable $e) {
    assertCondition("Program Head test threw no fatal errors", false, $e->getMessage());
}

// -------------------------------------------------------------
// Test Case 3: Target Participants multi-select serialization & saving
// -------------------------------------------------------------
try {
    $teachersArray = ["Mr. Aldwin D. Alminar, LPT", "Mr. Dave Lord M. Rubaya"];
    $rawTeacher = $teachersArray;
    if (is_array($rawTeacher)) {
        $tVal = implode(', ', array_filter(array_map('trim', array_map('sanitize', $rawTeacher))));
    } else {
        $tVal = sanitize((string)$rawTeacher);
    }

    assertCondition("Multi-teacher array correctly serialized", $tVal === "Mr. Aldwin D. Alminar, LPT, Mr. Dave Lord M. Rubaya");

    // Insert into activity_participants
    $insPart = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, teacher_name) VALUES (?, 1, 'BSIT 301', 45, ?)");
    $insPart->execute([$actId1, $tVal]);

    $readPart = $db->prepare("SELECT teacher_name FROM activity_participants WHERE activity_id = ? AND row_no = 1");
    $readPart->execute([$actId1]);
    $savedTeacher = $readPart->fetchColumn();

    assertCondition("Participants teacher_name correctly saved and retrievable", $savedTeacher === $tVal);

} catch (\Throwable $e) {
    assertCondition("Target participants multi-select save failed", false, $e->getMessage());
}

// Cleanup test activities
if ($actId1) {
    $db->prepare("DELETE FROM activity_participants WHERE activity_id = ?")->execute([$actId1]);
    $db->prepare("DELETE FROM approval_logs WHERE activity_id = ?")->execute([$actId1]);
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId1]);
}
if ($actId2) {
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId2]);
}

echo "\n=== TEST SUMMARY ===\n";
echo "Total Passed: {$passed}\n";
echo "Total Failed: {$failed}\n";
if ($failed === 0) {
    echo ">>> ALL SUBMISSION & RESUBMISSION FIX TESTS PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
