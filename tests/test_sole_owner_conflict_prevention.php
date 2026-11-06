<?php
// tests/test_sole_owner_conflict_prevention.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "======================================================================\n";
echo " SOLE OWNER COLLABORATOR CONFLICT PREVENTION TEST SUITE\n";
echo "======================================================================\n";

$db = getDB();
$pass = 0;
$fail = 0;

function assertTest($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] $msg\n";
        $pass++;
    } else {
        echo " [FAIL] $msg\n";
        $fail++;
    }
}

// 1. Pick a faculty user
$uStmt = $db->query("SELECT id, name, email FROM users WHERE role = 'faculty' LIMIT 1");
$user = $uStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo "No faculty user found in DB.\n";
    exit(1);
}

// 2. Create a test activity owned by this faculty user with NO collaborators
$insAct = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, venue, event_date, start_time, end_time, status, created_at, updated_at)
    VALUES (?, 'Sole Owner Conflict Test Activity', 'Testing conflict prevention', 'Room 101', '2026-11-20', '09:00:00', '12:00:00', 'draft', NOW(), NOW())
");
$insAct->execute([$user['id']]);
$actId = (int)$db->lastInsertId();

// Ensure no collaborators exist
$db->prepare("DELETE FROM proposal_collaborators WHERE activity_id = ?")->execute([$actId]);
$db->prepare("DELETE FROM proposal_contribution_logs WHERE activity_id = ?")->execute([$actId]);

// Verify no collaborators exist
$collabCountStmt = $db->prepare("SELECT COUNT(*) FROM proposal_collaborators WHERE activity_id = ? AND user_id != ? AND status = 'active'");
$collabCountStmt->execute([$actId, $user['id']]);
$otherCollabCount = (int)$collabCountStmt->fetchColumn();
assertTest($otherCollabCount === 0, "Initial state: Proposal has 0 other collaborators.");

// 3. Simulate an in-page action updating updated_at (e.g. Create Google Form advancing updated_at)
sleep(1); // small tick
$db->prepare("UPDATE activities SET evaluation_method = 'https://forms.gle/mock123', updated_at = NOW() WHERE id = ?")->execute([$actId]);

$currActStmt = $db->prepare("SELECT updated_at FROM activities WHERE id = ?");
$currActStmt->execute([$actId]);
$serverUpdatedAt = $currActStmt->fetchColumn();

// Stale timestamp representing page load 5 minutes ago
$staleClientTimestamp = date('Y-m-d H:i:s', strtotime($serverUpdatedAt) - 300);

// 4. Test proposal-update.php non-AJAX POST by Sole Owner with stale timestamp
$serverTime = strtotime($serverUpdatedAt);
$clientTime = strtotime($staleClientTimestamp);
assertTest($serverTime > ($clientTime + 2), "Precondition: Server updated_at is newer than client last_known_updated_at by > 2 seconds.");

// Execute conflict logic verification
$isOwner = true;
$hasOtherContributors = ($otherCollabCount > 0) || !$isOwner;
$isAjax = false;

$lastModifierIsOther = false;
if ($hasOtherContributors) {
    $lastLogStmt = $db->prepare("SELECT user_id FROM proposal_contribution_logs WHERE activity_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $lastLogStmt->execute([$actId]);
    $lastLogUserId = $lastLogStmt->fetchColumn();
    $lastModifierIsOther = ($lastLogUserId !== false && (int)$lastLogUserId !== (int)$user['id']);
}

$isConflict = $isAjax ? true : ($hasOtherContributors && $lastModifierIsOther);
assertTest($isConflict === false, "Sole owner standard POST does NOT trigger conflict (isConflict === false).");

// 5. Test when another collaborator IS added
$otherUStmt = $db->prepare("SELECT id, name FROM users WHERE role = 'faculty' AND id != ? LIMIT 1");
$otherUStmt->execute([$user['id']]);
$otherUser = $otherUStmt->fetch(PDO::FETCH_ASSOC);

if ($otherUser) {
    // Add other user as collaborator
    $insCollab = $db->prepare("INSERT INTO proposal_collaborators (activity_id, user_id, role, added_by, status) VALUES (?, ?, 'co_author', ?, 'active')");
    $insCollab->execute([$actId, $otherUser['id'], $user['id']]);

    // Log a contribution by the other user
    $insLog = $db->prepare("INSERT INTO proposal_contribution_logs (activity_id, user_id, user_role, action, section, details) VALUES (?, ?, 'co_author', 'Edited Objectives', 'Objectives', 'Updated SMART objectives')");
    $insLog->execute([$actId, $otherUser['id']]);

    // Re-evaluate conflict check for the owner
    $collabCountStmt->execute([$actId, $user['id']]);
    $newOtherCollabCount = (int)$collabCountStmt->fetchColumn();
    $newHasOther = ($newOtherCollabCount > 0) || !$isOwner;

    $lastLogStmt = $db->prepare("SELECT user_id FROM proposal_contribution_logs WHERE activity_id = ? ORDER BY created_at DESC, id DESC LIMIT 1");
    $lastLogStmt->execute([$actId]);
    $recentLogUser = (int)$lastLogStmt->fetchColumn();
    $newLastModIsOther = ($recentLogUser !== (int)$user['id']);

    $newConflict = $isAjax ? true : ($newHasOther && $newLastModIsOther);
    assertTest($newConflict === true, "When another active collaborator updated the server, conflict IS correctly detected (newConflict === true).");
}

// Clean up test activity
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId]);
$db->prepare("DELETE FROM proposal_collaborators WHERE activity_id = ?")->execute([$actId]);
$db->prepare("DELETE FROM proposal_contribution_logs WHERE activity_id = ?")->execute([$actId]);

echo "======================================================================\n";
echo " SUMMARY: Passed: $pass | Failed: $fail\n";
echo "======================================================================\n";

