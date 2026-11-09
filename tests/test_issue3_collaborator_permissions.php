<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$passed = 0;
$failed = 0;

function assertCheck(string $label, bool $cond) {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] $label\n";
        $passed++;
    } else {
        echo "  [FAIL] $label\n";
        $failed++;
    }
}

echo "=== Issue 3 Verification: Designated Collaborator Proposal Permissions ===\n";

$db = getDB();

// 1. Find or verify admin1 and admin2 accounts
$admin1 = $db->query("SELECT id, name, role, department FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2 = $db->query("SELECT id, name, role, department FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$faculty = $db->query("SELECT id, name, role, department FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

assertCheck("Found Student Org Head (admin1) account", !empty($admin1));
assertCheck("Found Events Committee Head (admin2) account", !empty($admin2));
assertCheck("Found Faculty creator account", !empty($faculty));

$admin1Id = (int)$admin1['id'];
$admin2Id = (int)$admin2['id'];
$facultyId = (int)$faculty['id'];

// 2. Create a test activity owned by faculty
$db->beginTransaction();
try {
    $ins = $db->prepare("
        INSERT INTO activities (faculty_id, title, status, event_date, source, target_participants, created_at, updated_at)
        VALUES (?, 'Test Issue 3 Collaborator Activity', 'draft', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'faculty', 50, NOW(), NOW())
    ");
    $ins->execute([$facultyId]);
    $testActId = (int)$db->lastInsertId();

    // 2a. Before adding collaborator:
    // admin1 must NOT have modify or view access to draft
    $canModBefore = canUserAccessActivity($testActId, 'modify', $admin1Id, 'admin1', $admin1['department']);
    $canViewBefore = canUserAccessActivity($testActId, 'view', $admin1Id, 'admin1', $admin1['department']);
    assertCheck("Unassigned admin1 cannot modify draft proposal (modify = false)", $canModBefore === false);
    assertCheck("Unassigned admin1 cannot view draft proposal (view = false due to draft privacy)", $canViewBefore === false);

    // 2b. Add admin1 as active collaborator (co-author)
    $addRes = addProposalCollaborator($testActId, $admin1Id, 'co_author', $facultyId, $db);
    assertCheck("Successfully added admin1 as collaborator via addProposalCollaborator()", !empty($addRes['success']));

    // 2c. Now verify admin1 has access to this specific proposal
    $canModAfter = canUserAccessActivity($testActId, 'modify', $admin1Id, 'admin1', $admin1['department']);
    $canViewAfter = canUserAccessActivity($testActId, 'view', $admin1Id, 'admin1', $admin1['department']);
    $roleInProp = getProposalUserRole($testActId, $admin1Id, $db);
    assertCheck("Assigned admin1 collaborator CAN modify this proposal (modify = true)", $canModAfter === true);
    assertCheck("Assigned admin1 collaborator CAN view this proposal (view = true)", $canViewAfter === true);
    assertCheck("getProposalUserRole recognizes admin1 role as 'co_author'", $roleInProp === 'co_author');

    // 2d. admin2 is NOT assigned to this proposal -> admin2 must be denied modify access
    $admin2Mod = canUserAccessActivity($testActId, 'modify', $admin2Id, 'admin2', $admin2['department']);
    assertCheck("Unassigned admin2 cannot modify this proposal (modify = false)", $admin2Mod === false);

    // 2e. Test on 'returned_for_revision' status
    $db->prepare("UPDATE activities SET status = 'returned_for_revision' WHERE id = ?")->execute([$testActId]);
    $admin1ModReturned = canUserAccessActivity($testActId, 'modify', $admin1Id, 'admin1', $admin1['department']);
    $admin2ModReturned = canUserAccessActivity($testActId, 'modify', $admin2Id, 'admin2', $admin2['department']);
    assertCheck("Assigned admin1 can modify returned proposal (modify = true)", $admin1ModReturned === true);
    assertCheck("Unassigned admin2 cannot modify returned proposal (modify = false)", $admin2ModReturned === false);

    // 2f. Add admin2 as contributor to verify admin2 access
    $addAdmin2 = addProposalCollaborator($testActId, $admin2Id, 'contributor', $facultyId, $db);
    assertCheck("Successfully added admin2 as contributor via addProposalCollaborator()", !empty($addAdmin2['success']));
    $admin2ModAfter = canUserAccessActivity($testActId, 'modify', $admin2Id, 'admin2', $admin2['department']);
    assertCheck("Assigned admin2 collaborator CAN modify this proposal (modify = true)", $admin2ModAfter === true);

} finally {
    // Rollback test changes to preserve clean database state
    $db->rollBack();
}

// 3. Verify static file enforcement
$editFile = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$createFile = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$updateFile = file_get_contents(__DIR__ . '/../api/proposal-update.php');
$viewAct1 = file_get_contents(__DIR__ . '/../admin1/view-activity.php');
$viewAct2 = file_get_contents(__DIR__ . '/../admin2/view-activity.php');

assertCheck("proposal-edit.php allows admin1 and admin2 in requireRole",
    strpos($editFile, "requireRole('faculty', 'program_head', 'admin1', 'admin2')") !== false);
assertCheck("proposal-edit.php strictly checks canUserAccessActivity(\$id, 'modify', ...)",
    strpos($editFile, "canUserAccessActivity(\$id, 'modify'") !== false);
assertCheck("proposal-create.php still forbids admin1 and admin2 (general creation blocked)",
    strpos($createFile, "requireRole('faculty', 'program_head');") !== false);
assertCheck("api/proposal-update.php allows admin1 and admin2 in requireRole and preserves owner-only submission",
    strpos($updateFile, "requireRole('faculty', 'program_head', 'admin1', 'admin2')") !== false &&
    strpos($updateFile, "\$isOwner = ((int)\$activity['faculty_id'] === (int)\$user['id']);") !== false);
assertCheck("admin1/view-activity.php includes Edit Proposal button for authorized collaborators",
    strpos($viewAct1, "faculty/proposal-edit.php?id=") !== false);
assertCheck("admin2/view-activity.php includes Edit Proposal button for authorized collaborators",
    strpos($viewAct2, "faculty/proposal-edit.php?id=") !== false);

echo "\nSummary: $passed passed, $failed failed.\n";
exit($failed > 0 ? 1 : 0);

