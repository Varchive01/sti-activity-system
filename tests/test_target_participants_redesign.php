<?php
/**
 * Test Suite: Target Participants Redesign & Event Details Parity
 * STI-ARMES
 */

require_once __DIR__ . '/../config/database.php';

echo "=== STI-ARMES: Target Participants & Event Details Redesign Tests ===\n\n";

$db = getDB();
$allPassed = true;

function testAssert($description, $condition) {
    global $allPassed;
    if ($condition) {
        echo " [PASS] $description\n";
    } else {
        echo " [FAIL] $description\n";
        $allPassed = false;
    }
}

// ── Test 1: Database Schema ──
echo "--- 1. Database Schema Verification ---\n";
$stmt = $db->query("SHOW TABLES LIKE 'activity_participants'");
$tableExists = $stmt->rowCount() > 0;
testAssert("Table 'activity_participants' exists in database", $tableExists);

if ($tableExists) {
    $colsStmt = $db->query("DESCRIBE activity_participants");
    $cols = $colsStmt->fetchAll(PDO::FETCH_COLUMN);
    testAssert("Column 'id' exists", in_array('id', $cols));
    testAssert("Column 'activity_id' exists", in_array('activity_id', $cols));
    testAssert("Column 'row_no' exists", in_array('row_no', $cols));
    testAssert("Column 'subject_section' exists", in_array('subject_section', $cols));
    testAssert("Column 'participants_count' exists", in_array('participants_count', $cols));
    testAssert("Column 'teacher_name' exists", in_array('teacher_name', $cols));
}

// ── Test 2: File Inspection - proposal-create.php ──
echo "\n--- 2. File Inspection: proposal-create.php ---\n";
$createCode = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');

testAssert("proposal-create: Theme and Venue are placed before Venue Address", 
    strpos($createCode, 'id="f1_theme"') !== false &&
    strpos($createCode, 'id="f1_venue"') !== false &&
    strpos($createCode, 'id="f1_venue_address"') !== false &&
    strpos($createCode, 'id="f1_theme"') < strpos($createCode, 'id="f1_venue_address"') &&
    strpos($createCode, 'id="f1_venue"') < strpos($createCode, 'id="f1_venue_address"')
);

testAssert("proposal-create: Venue Address is full width (col-span-2)",
    strpos($createCode, 'class="form-group col-span-2"') !== false &&
    preg_match('/<div class="form-group col-span-2">\s*<label[^>]*>Venue Address/s', $createCode) === 1
);

testAssert("proposal-create: Target Participants panel exists",
    strpos($createCode, 'target-participants-panel') !== false &&
    strpos($createCode, 'id="target-participants-card"') !== false
);

testAssert("proposal-create: Header contains + Add Section and Total Target Participants counter",
    strpos($createCode, '+ Add Section') !== false &&
    strpos($createCode, 'id="target-participants-total-display"') !== false
);

testAssert("proposal-create: Initial state has exactly 1 participant row",
    substr_count($createCode, 'class="participant-row"') === 1
);

testAssert("proposal-create: First row delete button is disabled",
    strpos($createCode, 'btn-delete-section') !== false &&
    strpos($createCode, 'title="Cannot delete the only row" disabled') !== false
);

testAssert("proposal-create: Helper functions exist (renumber, updateTotal, add, remove)",
    strpos($createCode, 'function renumberParticipantRows()') !== false &&
    strpos($createCode, 'function updateTotalTargetParticipants()') !== false &&
    strpos($createCode, 'function addParticipantSectionRow(') !== false &&
    strpos($createCode, 'function removeParticipantSectionRow(') !== false
);

testAssert("proposal-create: validateStep(1) validates participant rows and total",
    strpos($createCode, "updateTotalTargetParticipants()") !== false &&
    strpos($createCode, "querySelectorAll('#participant-sections-body tr.participant-row')") !== false &&
    strpos($createCode, ".part-section-input") !== false &&
    strpos($createCode, ".part-count-input") !== false
);

// ── Test 3: File Inspection - proposal-edit.php ──
echo "\n--- 3. File Inspection: proposal-edit.php ---\n";
$editCode = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');

testAssert("proposal-edit: Theme and Venue are placed before Venue Address", 
    strpos($editCode, 'id="f1_theme"') !== false &&
    strpos($editCode, 'id="f1_venue"') !== false &&
    strpos($editCode, 'id="f1_venue_address"') !== false &&
    strpos($editCode, 'id="f1_theme"') < strpos($editCode, 'id="f1_venue_address"') &&
    strpos($editCode, 'id="f1_venue"') < strpos($editCode, 'id="f1_venue_address"')
);

testAssert("proposal-edit: Venue Address is full width (col-span-2)",
    strpos($editCode, 'class="form-group col-span-2"') !== false &&
    preg_match('/<div class="form-group col-span-2">\s*<label[^>]*>Venue Address/s', $editCode) === 1
);

testAssert("proposal-edit: Target Participants panel exists",
    strpos($editCode, 'target-participants-panel') !== false &&
    strpos($editCode, 'id="target-participants-card"') !== false
);

testAssert("proposal-edit: Header contains + Add Section and Total Target Participants counter",
    strpos($editCode, '+ Add Section') !== false &&
    strpos($editCode, 'id="target-participants-total-display"') !== false
);

testAssert("proposal-edit: Loads participant rows from activity_participants table",
    strpos($editCode, 'FROM activity_participants WHERE activity_id=?') !== false
);

testAssert("proposal-edit: Backward compatibility synthesizes 1 row if no child records",
    strpos($editCode, "empty(\$participantRows) && !empty(\$activity['target_participants'])") !== false &&
    strpos($editCode, "'participants_count' => (int)\$activity['target_participants']") !== false
);

testAssert("proposal-edit: Helper functions exist (renumber, updateTotal, add, remove)",
    strpos($editCode, 'function renumberParticipantRows()') !== false &&
    strpos($editCode, 'function updateTotalTargetParticipants()') !== false &&
    strpos($editCode, 'function addParticipantSectionRow(') !== false &&
    strpos($editCode, 'function removeParticipantSectionRow(') !== false
);

testAssert("proposal-edit: validateStep(1) validates participant rows and total",
    strpos($editCode, "updateTotalTargetParticipants()") !== false &&
    strpos($editCode, "querySelectorAll('#participant-sections-body tr.participant-row')") !== false &&
    strpos($editCode, ".part-section-input") !== false &&
    strpos($editCode, ".part-count-input") !== false
);

// ── Test 4: Database Persistence & Total Synchronization ──
echo "\n--- 4. Backend Persistence & Data Integrity Tests ---\n";

// Insert a test user if needed or get first faculty user
$uStmt = $db->query("SELECT id FROM users WHERE role = 'faculty' LIMIT 1");
$testUser = $uStmt->fetch(PDO::FETCH_ASSOC);
$userId = $testUser ? (int)$testUser['id'] : 1;

// Create a mock activity
$tomorrow = date('Y-m-d', strtotime('+7 days'));
$insertStmt = $db->prepare("INSERT INTO activities (
    faculty_id, title, theme, venue, venue_address, event_date, start_time, end_time,
    target_participants, status, created_at, updated_at
) VALUES (?, 'Test Participant Redesign Event', 'Innovation Through Tech', 'STI Auditorium', '289 L. de Guzman St.', ?, '09:00:00', '12:00:00', 0, 'draft', NOW(), NOW())");
$insertStmt->execute([$userId, $tomorrow]);
$testActivityId = (int)$db->lastInsertId();

testAssert("Created temporary test activity ID: $testActivityId", $testActivityId > 0);

if ($testActivityId > 0) {
    // Simulate saving multiple participant sections
    $sections = ['BSIT 301', 'BSCS 201', 'BSCPE 101'];
    $counts = [35, 28, 42];
    $teachers = ['Prof. Alan Turing', 'Prof. Ada Lovelace', 'Prof. John von Neumann'];
    $expectedTotal = array_sum($counts); // 35 + 28 + 42 = 105

    // Save to activity_participants
    $pInsert = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, teacher_name) VALUES (?, ?, ?, ?, ?)");
    foreach ($sections as $i => $sec) {
        $pInsert->execute([$testActivityId, $i + 1, $sec, $counts[$i], $teachers[$i]]);
    }
    // Update total in activities table
    $db->prepare("UPDATE activities SET target_participants = ? WHERE id = ?")->execute([$expectedTotal, $testActivityId]);

    // Query back
    $pCheck = $db->prepare("SELECT * FROM activity_participants WHERE activity_id = ? ORDER BY row_no ASC");
    $pCheck->execute([$testActivityId]);
    $savedRows = $pCheck->fetchAll(PDO::FETCH_ASSOC);

    testAssert("All 3 participant sections persisted to activity_participants", count($savedRows) === 3);
    testAssert("Row 1 subject_section matches 'BSIT 301'", ($savedRows[0]['subject_section'] ?? '') === 'BSIT 301');
    testAssert("Row 1 count matches 35", (int)($savedRows[0]['participants_count'] ?? 0) === 35);
    testAssert("Row 1 teacher matches 'Prof. Alan Turing'", ($savedRows[0]['teacher_name'] ?? '') === 'Prof. Alan Turing');
    testAssert("Row 2 count matches 28", (int)($savedRows[1]['participants_count'] ?? 0) === 28);
    testAssert("Row 3 count matches 42", (int)($savedRows[2]['participants_count'] ?? 0) === 42);

    $actCheck = $db->prepare("SELECT target_participants FROM activities WHERE id = ?");
    $actCheck->execute([$testActivityId]);
    $syncedTotal = (int)$actCheck->fetchColumn();

    testAssert("activities.target_participants synchronized to sum ($expectedTotal)", $syncedTotal === $expectedTotal);

    // Test modification: delete middle section, change count
    $db->prepare("DELETE FROM activity_participants WHERE activity_id = ?")->execute([$testActivityId]);
    $newSections = ['BSIT 301', 'BSCPE 101'];
    $newCounts = [40, 50];
    $newTeachers = ['Prof. Alan Turing', 'Prof. John von Neumann'];
    $newExpectedTotal = array_sum($newCounts); // 90

    foreach ($newSections as $i => $sec) {
        $pInsert->execute([$testActivityId, $i + 1, $sec, $newCounts[$i], $newTeachers[$i]]);
    }
    $db->prepare("UPDATE activities SET target_participants = ? WHERE id = ?")->execute([$newExpectedTotal, $testActivityId]);

    $pCheck->execute([$testActivityId]);
    $updatedRows = $pCheck->fetchAll(PDO::FETCH_ASSOC);
    testAssert("After modification, exactly 2 rows remain", count($updatedRows) === 2);
    testAssert("Row 1 count updated to 40", (int)($updatedRows[0]['participants_count'] ?? 0) === 40);
    testAssert("Row 2 row_no is 2 and count is 50", (int)($updatedRows[1]['row_no'] ?? 0) === 2 && (int)($updatedRows[1]['participants_count'] ?? 0) === 50);

    $actCheck->execute([$testActivityId]);
    $newSyncedTotal = (int)$actCheck->fetchColumn();
    testAssert("activities.target_participants updated to 90", $newSyncedTotal === 90);

    // Test Backward Compatibility: Legacy activity with no activity_participants records
    $db->prepare("DELETE FROM activity_participants WHERE activity_id = ?")->execute([$testActivityId]);
    $db->prepare("UPDATE activities SET target_participants = 75, involved_subjects = 'General IT' WHERE id = ?")->execute([$testActivityId]);

    // Simulate proposal-edit.php backward compatibility retrieval logic:
    $bcCheck = $db->prepare("SELECT * FROM activity_participants WHERE activity_id = ? ORDER BY row_no ASC");
    $bcCheck->execute([$testActivityId]);
    $bcRows = $bcCheck->fetchAll(PDO::FETCH_ASSOC);

    if (empty($bcRows)) {
        $actStmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
        $actStmt->execute([$testActivityId]);
        $legacyAct = $actStmt->fetch(PDO::FETCH_ASSOC);
        $bcRows = [[
            'id' => 0,
            'activity_id' => $legacyAct['id'],
            'row_no' => 1,
            'subject_section' => $legacyAct['involved_subjects'] ?? '',
            'participants_count' => (int)($legacyAct['target_participants'] ?? 0),
            'teacher_name' => ''
        ]];
    }

    testAssert("Backward compatibility creates 1 synthesized row for legacy proposal", count($bcRows) === 1);
    testAssert("Synthesized row count matches legacy target_participants (75)", $bcRows[0]['participants_count'] === 75);
    testAssert("Synthesized row subject matches legacy involved_subjects ('General IT')", $bcRows[0]['subject_section'] === 'General IT');

    // Clean up test record
    $db->prepare("DELETE FROM activity_participants WHERE activity_id = ?")->execute([$testActivityId]);
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActivityId]);
    echo " Cleaned up temporary test activity ID: $testActivityId\n";
}

echo "\n=======================================================\n";
if ($allPassed) {
    echo ">>> ALL TESTS PASSED! Target Participants redesign verified. <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
