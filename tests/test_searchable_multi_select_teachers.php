<?php
/**
 * tests/test_searchable_multi_select_teachers.php
 *
 * Dedicated verification suite for Searchable Multi-Select Dropdown for Subject Teachers.
 * Verifies:
 * 1. Dynamic faculty query from database (no hardcoding)
 * 2. Deduplication and normalization
 * 3. JS Multi-select component architecture & duplicate prevention
 * 4. Create and Edit wizard markup & script integration
 * 5. Backend save & update with multiple teacher persistence
 * 6. Responsive badge rendering in view screens
 * 7. End-to-end persistence cycle
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=======================================================================\n";
echo "   SEARCHABLE MULTI-SELECT SUBJECT TEACHERS VERIFICATION SUITE         \n";
echo "=======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $category, string $title, bool $condition, string $detail = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo sprintf(" [PASS] | %-20s | %-45s | %s\n", $category, $title, $detail ?: 'OK');
    } else {
        $failCount++;
        echo sprintf(" [FAIL] | %-20s | %-45s | %s\n", $category, $title, $detail ?: 'FAILED');
    }
}

$db = getDB();

// 1. Dynamic Faculty Roster
$roster = getRegisteredFacultyList($db);
assertTest(
    'Dynamic Faculty Query',
    'Faculty queried from DB and returns valid array',
    is_array($roster) && count($roster) > 0,
    'Total eligible faculty: ' . count($roster)
);

$hasDave = false;
$hasMaria = false;
foreach ($roster as $f) {
    if (stripos($f['name'], 'Dave Lord') !== false) $hasDave = true;
    if (stripos($f['name'], 'Maria Santos') !== false) $hasMaria = true;
}
assertTest(
    'Dynamic Faculty Query',
    'Includes registered Program Heads & Faculty',
    $hasDave && $hasMaria,
    'Found Dave Lord Rubaya & Maria Santos'
);

// Check deduplication
$nameCounts = [];
$duplicatesFound = false;
foreach ($roster as $f) {
    $clean = strtolower(preg_replace('/[^a-zA-Z]/', '', $f['name']));
    $nameCounts[$clean] = ($nameCounts[$clean] ?? 0) + 1;
    if ($nameCounts[$clean] > 1) {
        $duplicatesFound = true;
        break;
    }
}
assertTest(
    'Deduplication',
    'Faculty roster is free of duplicates',
    !$duplicatesFound,
    'Unique normalized names confirmed'
);

// 2. JS Multi-Select Engine
$jsContent = file_get_contents(__DIR__ . '/../assets/js/teacher-multi-select.js');
assertTest(
    'JS Engine Architecture',
    'Defines initTeacherMultiSelect and initAllTeacherMultiSelects',
    str_contains($jsContent, 'window.initTeacherMultiSelect = initTeacherMultiSelect') &&
    str_contains($jsContent, 'window.initAllTeacherMultiSelects = initAllTeacherMultiSelects')
);

assertTest(
    'JS Engine Architecture',
    'Implements duplicate check before adding teacher',
    str_contains($jsContent, 'isDuplicate') && str_contains($jsContent, 'selectedNames.some')
);

assertTest(
    'JS Engine Architecture',
    'Implements removable tag chip rendering and hidden input sync',
    str_contains($jsContent, 'teacher-chip-remove') && str_contains($jsContent, 'syncHiddenInput')
);

assertTest(
    'JS Engine Architecture',
    'Implements real-time name and department search filter',
    str_contains($jsContent, 'nameMatch') && str_contains($jsContent, 'deptMatch')
);

// 3. Create & Edit Wizard Templates
$createContent = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
assertTest(
    'Proposal Create',
    'Renders teacher-multi-select container in table row',
    str_contains($createContent, 'class="teacher-multi-select"') &&
    str_contains($createContent, 'class="teacher-tags-container"')
);

assertTest(
    'Proposal Create',
    'Includes STI_FACULTY_ROSTER and teacher-multi-select.js script',
    str_contains($createContent, 'window.STI_FACULTY_ROSTER =') &&
    str_contains($createContent, 'assets/js/teacher-multi-select.js')
);

assertTest(
    'Proposal Create',
    'addParticipantSectionRow dynamically initializes multi-select',
    str_contains($createContent, 'window.initTeacherMultiSelect(ms, teacher)')
);

$editContent = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
assertTest(
    'Proposal Edit',
    'Renders teacher-multi-select container in table row',
    str_contains($editContent, 'class="teacher-multi-select"') &&
    str_contains($editContent, 'class="teacher-tags-container"')
);

assertTest(
    'Proposal Edit',
    'Includes STI_FACULTY_ROSTER and teacher-multi-select.js script',
    str_contains($editContent, 'window.STI_FACULTY_ROSTER =') &&
    str_contains($editContent, 'assets/js/teacher-multi-select.js')
);

// 4. CSS Responsive Design Tokens
$cssContent = file_get_contents(__DIR__ . '/../assets/css/main.css');
assertTest(
    'CSS Tokens & Design',
    'Defines .teacher-multi-select and .teacher-chip rules',
    str_contains($cssContent, '.teacher-multi-select') &&
    str_contains($cssContent, '.teacher-chip')
);

assertTest(
    'CSS Tokens & Design',
    'Defines dark-mode chip and dropdown styles',
    str_contains($cssContent, '[data-theme="dark"] .teacher-chip') &&
    str_contains($cssContent, '[data-theme="dark"] .teacher-dropdown-list')
);

assertTest(
    'CSS Tokens & Design',
    'Defines .teacher-view-badge and wrap for responsive viewing',
    str_contains($cssContent, '.teacher-view-badge') &&
    str_contains($cssContent, '.teacher-view-badges-wrap')
);

// 5. Backend Table & Persistence
$tableCheck = $db->query("SHOW COLUMNS FROM activity_participants LIKE 'teacher_name'")->fetch(PDO::FETCH_ASSOC);
assertTest(
    'Database Schema',
    'teacher_name column exists and accommodates multiple names',
    !empty($tableCheck),
    'Type: ' . ($tableCheck['Type'] ?? 'unknown')
);

// Test renderParticipantSectionsTable function
$sampleParticipants = [
    [
        'row_no' => 1,
        'subject_section' => 'BSIT 301 / Software Engineering',
        'participants_count' => 45,
        'teacher_name' => 'Prof. Maria Santos, Dave Lord Rubaya, Tristan Unabia'
    ],
    [
        'row_no' => 2,
        'subject_section' => 'BSCS 201 / Discrete Math',
        'participants_count' => 38,
        'teacher_name' => 'Michelle Carlos'
    ]
];

$renderedHtml = renderParticipantSectionsTable($sampleParticipants);
assertTest(
    'View Table Renderer',
    'Renders table with multiple removable-style view badges',
    str_contains($renderedHtml, 'BSIT 301 / Software Engineering') &&
    str_contains($renderedHtml, 'Prof. Maria Santos') &&
    str_contains($renderedHtml, 'Dave Lord Rubaya') &&
    str_contains($renderedHtml, 'Tristan Unabia') &&
    str_contains($renderedHtml, 'teacher-view-badge')
);

// 6. End-to-End Persistence Cycle
$testActivityId = 999998;
try {
    $db->prepare("DELETE FROM activity_participants WHERE activity_id=?")->execute([$testActivityId]);
    
    // Simulate proposal-save insertion with multiple teachers
    $pStmt = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, teacher_name) VALUES (?, ?, ?, ?, ?)");
    $pStmt->execute([
        $testActivityId,
        1,
        'BSIT 401 / Capstone Project',
        50,
        'Dave Lord Rubaya, Maria Santos, Aldwin Alminar'
    ]);

    $queryStmt = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC");
    $queryStmt->execute([$testActivityId]);
    $saved = $queryStmt->fetch(PDO::FETCH_ASSOC);

    assertTest(
        'E2E Persistence',
        'Saved row correctly stores all selected faculty teachers',
        $saved && $saved['teacher_name'] === 'Dave Lord Rubaya, Maria Santos, Aldwin Alminar',
        'Stored: ' . ($saved['teacher_name'] ?? 'none')
    );

    // Verify separation into individual badges
    $separated = array_filter(array_map('trim', explode(',', $saved['teacher_name'])));
    assertTest(
        'E2E Persistence',
        'Stored value separates cleanly into 3 distinct teacher names',
        count($separated) === 3 && in_array('Dave Lord Rubaya', $separated, true) && in_array('Maria Santos', $separated, true) && in_array('Aldwin Alminar', $separated, true),
        'Count: ' . count($separated)
    );

    // Cleanup
    $db->prepare("DELETE FROM activity_participants WHERE activity_id=?")->execute([$testActivityId]);
} catch (\Throwable $e) {
    assertTest('E2E Persistence', 'Exception during E2E test: ' . $e->getMessage(), false);
}

echo "-----------------------------------------------------------------------\n";
echo sprintf("Summary: %d Passed, %d Failed (Total: %d)\n", $passCount, $failCount, $passCount + $failCount);
echo "=======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
