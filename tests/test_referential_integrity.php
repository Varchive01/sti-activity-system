<?php
/**
 * Focused Verification Suite for Referential Integrity:
 * - notifications.activity_id -> activities.id
 * - proposal_ai_validation.proposal_id -> activities.id
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "============================================================\n";
echo "  REFERENTIAL INTEGRITY FOCUSED VERIFICATION SUITE\n";
echo "============================================================\n\n";

$passed = 0;
$failed = 0;

function assertCheck(string $title, bool $condition, string $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($details ? " - {$details}" : "") . "\n";
        $failed++;
    }
}

// 1. Check foreign keys exist in INFORMATION_SCHEMA
echo "--- 1. Checking Foreign Key Definitions ---\n";
$fks = $db->query("
    SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() 
      AND (
        (TABLE_NAME = 'notifications' AND COLUMN_NAME = 'activity_id')
        OR (TABLE_NAME = 'proposal_ai_validation' AND COLUMN_NAME = 'proposal_id')
      )
      AND REFERENCED_TABLE_NAME = 'activities'
")->fetchAll(PDO::FETCH_ASSOC);

$hasNotifFk = false;
$hasAiFk = false;
foreach ($fks as $fk) {
    if ($fk['TABLE_NAME'] === 'notifications' && $fk['COLUMN_NAME'] === 'activity_id') {
        $hasNotifFk = true;
        echo "   -> Found notifications.activity_id FK: {$fk['CONSTRAINT_NAME']}\n";
    }
    if ($fk['TABLE_NAME'] === 'proposal_ai_validation' && $fk['COLUMN_NAME'] === 'proposal_id') {
        $hasAiFk = true;
        echo "   -> Found proposal_ai_validation.proposal_id FK: {$fk['CONSTRAINT_NAME']}\n";
    }
}
assertCheck("notifications.activity_id foreign key exists", $hasNotifFk);
assertCheck("proposal_ai_validation.proposal_id foreign key exists", $hasAiFk);

// 2. Check indexes exist on both columns
echo "\n--- 2. Checking Column Indexes ---\n";
$notifIndexes = $db->query("SHOW INDEX FROM notifications WHERE Column_name = 'activity_id'")->fetchAll();
$aiIndexes    = $db->query("SHOW INDEX FROM proposal_ai_validation WHERE Column_name = 'proposal_id'")->fetchAll();

assertCheck("notifications.activity_id is indexed", !empty($notifIndexes), "Found " . count($notifIndexes) . " index(es)");
assertCheck("proposal_ai_validation.proposal_id is indexed", !empty($aiIndexes), "Found " . count($aiIndexes) . " index(es)");

// 3. Confirm no orphan records remain
echo "\n--- 3. Checking for Remaining Orphan Records ---\n";
$orphanNotifs = (int)$db->query("
    SELECT COUNT(*) 
    FROM notifications n 
    LEFT JOIN activities a ON n.activity_id = a.id 
    WHERE n.activity_id IS NOT NULL AND a.id IS NULL
")->fetchColumn();

$orphanAi = (int)$db->query("
    SELECT COUNT(*) 
    FROM proposal_ai_validation pav 
    LEFT JOIN activities a ON pav.proposal_id = a.id 
    WHERE a.id IS NULL
")->fetchColumn();

assertCheck("Zero orphaned notifications remain", $orphanNotifs === 0, "Found {$orphanNotifs} orphans");
assertCheck("Zero orphaned proposal_ai_validation records remain", $orphanAi === 0, "Found {$orphanAi} orphans");

// 4. Test that inserting invalid activity reference is rejected by FK constraint
echo "\n--- 4. Testing Foreign Key Rejection on Invalid References ---\n";
$invalidActivityId = 99999999;

// 4a. notifications with invalid activity_id
$notifRejected = false;
try {
    $db->prepare("
        INSERT INTO notifications (user_id, activity_id, message) 
        VALUES (1, ?, 'Should fail due to invalid activity_id')
    ")->execute([$invalidActivityId]);
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), '1452') || str_contains($e->getMessage(), 'foreign key constraint')) {
        $notifRejected = true;
    }
}
assertCheck("Inserting notification with non-existent activity_id is rejected by FK", $notifRejected);

// 4b. proposal_ai_validation with invalid proposal_id
$aiRejected = false;
try {
    $db->prepare("
        INSERT INTO proposal_ai_validation (proposal_id, is_complete, is_aligned, issues) 
        VALUES (?, 0, 0, 'Should fail due to invalid proposal_id')
    ")->execute([$invalidActivityId]);
} catch (PDOException $e) {
    if (str_contains($e->getMessage(), '1452') || str_contains($e->getMessage(), 'foreign key constraint')) {
        $aiRejected = true;
    }
}
assertCheck("Inserting proposal_ai_validation with non-existent proposal_id is rejected by FK", $aiRejected);

// 5. Test valid insert and operation with valid activity
echo "\n--- 5. Testing Existing Valid Record Flow ---\n";
// Create temporary test activity
$db->prepare("
    INSERT INTO activities (faculty_id, title, status, event_date, start_time, end_time, venue, description)
    VALUES (1, 'FK Test Valid Activity', 'draft', CURDATE(), '08:00:00', '10:00:00', 'Gym', 'Test Desc')
")->execute();
$validActId = (int)$db->lastInsertId();

// Insert valid notification
$db->prepare("
    INSERT INTO notifications (user_id, activity_id, message)
    VALUES (1, ?, 'Valid notification referencing existing activity')
")->execute([$validActId]);
$validNotifId = (int)$db->lastInsertId();

// Insert valid AI validation
$db->prepare("
    INSERT INTO proposal_ai_validation (proposal_id, is_complete, is_aligned, suggestions)
    VALUES (?, 1, 1, 'Valid AI suggestion')
")->execute([$validActId]);
$validAiId = (int)$db->lastInsertId();

$notifFetched = $db->query("SELECT * FROM notifications WHERE id = {$validNotifId}")->fetch(PDO::FETCH_ASSOC);
$aiFetched    = $db->query("SELECT * FROM proposal_ai_validation WHERE id = {$validAiId}")->fetch(PDO::FETCH_ASSOC);

assertCheck("Valid notification successfully inserted and associated", !empty($notifFetched) && (int)$notifFetched['activity_id'] === $validActId);
assertCheck("Valid proposal_ai_validation successfully inserted and associated", !empty($aiFetched) && (int)$aiFetched['proposal_id'] === $validActId);

// Test ON DELETE SET NULL for notification and ON DELETE CASCADE for proposal_ai_validation
echo "\n--- 6. Testing Cascade and Set Null Behavior on Activity Deletion ---\n";
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$validActId]);

// Check notification activity_id is set to NULL
$notifAfterDelete = $db->query("SELECT * FROM notifications WHERE id = {$validNotifId}")->fetch(PDO::FETCH_ASSOC);
assertCheck(
    "Notification activity_id is SET NULL when activity is deleted (message preserved)", 
    $notifAfterDelete !== false && $notifAfterDelete['activity_id'] === null
);

// Check proposal_ai_validation row is CASCADED
$aiAfterDelete = $db->query("SELECT * FROM proposal_ai_validation WHERE id = {$validAiId}")->fetch(PDO::FETCH_ASSOC);
assertCheck(
    "proposal_ai_validation row is automatically CASCADED when proposal is deleted", 
    $aiAfterDelete === false
);

// Cleanup test notification
$db->prepare("DELETE FROM notifications WHERE id = ?")->execute([$validNotifId]);

echo "\n============================================================\n";
echo "VERIFICATION RESULTS: {$passed} PASSED, {$failed} FAILED\n";
echo "============================================================\n";

exit($failed > 0 ? 1 : 0);
