<?php
/**
 * tests/test_target_participants_multiselect.php
 *
 * Verification suite for Searchable Multi-Select Dropdown on Subject Teacher / Target Participants
 *
 * Checks:
 * 1. getEligibleFacultyMembers() returns active faculty & program heads dynamically
 * 2. Database column activity_participants.teacher_name is TEXT type
 * 3. Multi-teacher insertion and persistence in activity_participants
 * 4. Multi-teacher update and persistence in activity_participants
 * 5. teacher-multiselect.js presence and structure
 * 6. proposal-create.php multi-select structure
 * 7. proposal-edit.php multi-select structure and loading
 * 8. proposal-view.php target participants breakdown display
 * 9. View activity files display target participants & subject teachers
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================================\n";
echo "   SEARCHABLE MULTI-SELECT SUBJECT TEACHER VERIFICATION SUITE         \n";
echo "=======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCheck(string $category, string $title, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo sprintf(" [PASS] | %-20s | %-42s | %s\n", $category, $title, $detail ?: 'OK');
    } else {
        $failCount++;
        echo sprintf(" [FAIL] | %-20s | %-42s | %s\n", $category, $title, $detail ?: 'FAILED');
    }
}

$db = getDB();

// 1. Dynamic Eligible Faculty Members
$facultyList = getEligibleFacultyMembers($db);
assertCheck(
    'Eligible Faculty',
    'getEligibleFacultyMembers returns dynamic records',
    count($facultyList) >= 10,
    "Found " . count($facultyList) . " eligible members"
);

$hasProgramHead = false;
$hasFacultyRole = false;
foreach ($facultyList as $f) {
    if ($f['role'] === 'program_head') $hasProgramHead = true;
    if ($f['role'] === 'faculty') $hasFacultyRole = true;
}
assertCheck(
    'Eligible Faculty',
    'Includes both faculty and program head roles',
    $hasProgramHead && $hasFacultyRole,
    "Program Head: " . ($hasProgramHead ? 'YES' : 'NO') . ", Faculty: " . ($hasFacultyRole ? 'YES' : 'NO')
);

// 2. Database Schema
$colInfo = $db->query("SHOW COLUMNS FROM activity_participants LIKE 'teacher_name'")->fetch(PDO::FETCH_ASSOC);
$isTextType = $colInfo && stripos($colInfo['Type'], 'text') !== false;
assertCheck(
    'Database Schema',
    'activity_participants.teacher_name is TEXT',
    $isTextType,
    "Type: " . ($colInfo['Type'] ?? 'unknown')
);

// 3. Multi-teacher Database Persistence (Save & Retrieve)
// Create test proposal
$testTitle = "Multi-Select Subject Teacher Test " . uniqid();
$stmt = $db->prepare("
    INSERT INTO activities (title, faculty_id, status, target_participants, event_date, start_time, end_time, venue, source)
    VALUES (?, (SELECT id FROM users WHERE role='faculty' LIMIT 1), 'draft', 95, CURDATE(), '09:00:00', '12:00:00', 'Gymnasium', 'faculty')
");
$stmt->execute([$testTitle]);
$testActId = (int)$db->lastInsertId();

// Insert participant row with multiple teachers
$multiTeachers1 = "Dave Lord Rubaya, Michelle Carlos, John Cedrick Agapito";
$multiTeachers2 = "Andrea J. Gatbonton, Aldwin Alminar";

$pStmt = $db->prepare("
    INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, teacher_name)
    VALUES (?, ?, ?, ?, ?)
");
$pStmt->execute([$testActId, 1, 'BSIT 301 / Data Structures', 55, $multiTeachers1]);
$pStmt->execute([$testActId, 2, 'THM 201 / Event Management', 40, $multiTeachers2]);

// Retrieve and verify
$verifyStmt = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC");
$verifyStmt->execute([$testActId]);
$savedRows = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);

assertCheck(
    'Data Persistence',
    'Saves multiple teachers in row 1 without truncation',
    count($savedRows) === 2 && $savedRows[0]['teacher_name'] === $multiTeachers1,
    $savedRows[0]['teacher_name'] ?? ''
);

assertCheck(
    'Data Persistence',
    'Saves multiple teachers in row 2 without truncation',
    count($savedRows) === 2 && $savedRows[1]['teacher_name'] === $multiTeachers2,
    $savedRows[1]['teacher_name'] ?? ''
);

// 4. Update Persistence
$updatedTeachers = "Dave Lord Rubaya, Michelle Carlos, Jedekiah Veloso, Shintaro Lagare";
$db->prepare("UPDATE activity_participants SET teacher_name=? WHERE activity_id=? AND row_no=1")
   ->execute([$updatedTeachers, $testActId]);

$updatedCheck = $db->prepare("SELECT teacher_name FROM activity_participants WHERE activity_id=? AND row_no=1");
$updatedCheck->execute([$testActId]);
$savedUpdate = $updatedCheck->fetchColumn();

assertCheck(
    'Data Persistence',
    'Updates multiple teachers cleanly on edit',
    $savedUpdate === $updatedTeachers,
    $savedUpdate
);

// Cleanup test proposal
$db->prepare("DELETE FROM activity_participants WHERE activity_id=?")->execute([$testActId]);
$db->prepare("DELETE FROM activities WHERE id=?")->execute([$testActId]);

// 5. JavaScript Component
$jsContent = file_get_contents(__DIR__ . '/../assets/js/teacher-multiselect.js');
assertCheck(
    'JavaScript Engine',
    'teacher-multiselect.js defines initTeacherMultiSelect',
    str_contains($jsContent, 'window.initTeacherMultiSelect = function')
);
assertCheck(
    'JavaScript Engine',
    'teacher-multiselect.js prevents duplicate entries',
    str_contains($jsContent, 'selectedNames.some')
);
assertCheck(
    'JavaScript Engine',
    'teacher-multiselect.js supports search filtering',
    str_contains($jsContent, 'renderDropdownOptions')
);
assertCheck(
    'JavaScript Engine',
    'teacher-multiselect.js creates removable tags',
    str_contains($jsContent, 'tms-tag-remove')
);

// 6. CSS Styling
$cssContent = file_get_contents(__DIR__ . '/../assets/css/main.css');
assertCheck(
    'CSS Tokens',
    'main.css defines .teacher-multiselect container styles',
    str_contains($cssContent, '.teacher-multiselect')
);
assertCheck(
    'CSS Tokens',
    'main.css defines dark mode tokens for multi-select',
    str_contains($cssContent, '[data-theme="dark"] .teacher-multiselect')
);

// 7. proposal-create.php
$createContent = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
assertCheck(
    'Proposal Create',
    'proposal-create.php defines Subject Teacher(s) header',
    str_contains($createContent, 'Subject Teacher(s)')
);
assertCheck(
    'Proposal Create',
    'proposal-create.php has .teacher-multiselect container',
    str_contains($createContent, 'class="teacher-multiselect"')
);
assertCheck(
    'Proposal Create',
    'proposal-create.php loads teacher-multiselect.js',
    str_contains($createContent, 'teacher-multiselect.js')
);
assertCheck(
    'Proposal Create',
    'proposal-create.php embeds dynamic ELIGIBLE_FACULTY_ROSTER',
    str_contains($createContent, 'window.ELIGIBLE_FACULTY_ROSTER =')
);

// 8. proposal-edit.php
$editContent = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
assertCheck(
    'Proposal Edit',
    'proposal-edit.php defines Subject Teacher(s) header',
    str_contains($editContent, 'Subject Teacher(s)')
);
assertCheck(
    'Proposal Edit',
    'proposal-edit.php has .teacher-multiselect container',
    str_contains($editContent, 'class="teacher-multiselect"')
);
assertCheck(
    'Proposal Edit',
    'proposal-edit.php loads teacher-multiselect.js',
    str_contains($editContent, 'teacher-multiselect.js')
);

// 9. proposal-view.php & view-activity.php
$viewContent = file_get_contents(__DIR__ . '/../faculty/proposal-view.php');
assertCheck(
    'Proposal View',
    'proposal-view.php renders Target Participants & Involved Sections',
    str_contains($viewContent, 'Target Participants & Involved Sections')
);

$a1ViewContent = file_get_contents(__DIR__ . '/../admin1/view-activity.php');
assertCheck(
    'Admin 1 View',
    'admin1/view-activity.php renders Target Participants & Subject Teachers',
    str_contains($a1ViewContent, 'Target Participants & Subject Teachers')
);

$a2ViewContent = file_get_contents(__DIR__ . '/../admin2/view-activity.php');
assertCheck(
    'Admin 2 View',
    'admin2/view-activity.php renders Target Participants & Subject Teachers',
    str_contains($a2ViewContent, 'Target Participants & Subject Teachers')
);

$deanViewContent = file_get_contents(__DIR__ . '/../dean/view-activity.php');
assertCheck(
    'Dean View',
    'dean/view-activity.php renders Target Participants & Subject Teachers',
    str_contains($deanViewContent, 'Target Participants & Subject Teachers')
);

echo "\n-----------------------------------------------------------------------\n";
echo sprintf("Summary: %d Passed, %d Failed (Total: %d)\n", $passCount, $failCount, $passCount + $failCount);
echo "=======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
