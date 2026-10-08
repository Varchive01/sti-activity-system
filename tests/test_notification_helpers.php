<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "============================================================\n";
echo " TESTING SHARED NOTIFICATION DATA HELPER FUNCTIONS\n";
echo "============================================================\n\n";

$db = getDB();

// 1. Confirm functions exist
echo "[1] Checking function existence...\n";
if (!function_exists('getUnreadNotificationCount')) {
    echo " [FAIL] getUnreadNotificationCount() is not defined\n";
    exit(1);
}
echo " [PASS] getUnreadNotificationCount() exists\n";

if (!function_exists('getUserNotifications')) {
    echo " [FAIL] getUserNotifications() is not defined\n";
    exit(1);
}
echo " [PASS] getUserNotifications() exists\n\n";

// 2. Fetch an existing valid user
echo "[2] Fetching a valid existing user...\n";
$userStmt = $db->query("SELECT id, name, role FROM users LIMIT 1");
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo " [FAIL] No user found in users table\n";
    exit(1);
}
$userId = (int)$user['id'];
echo " [PASS] Found user ID {$userId} ({$user['name']}, Role: {$user['role']})\n\n";

// 3. Test getUnreadNotificationCount with real user
echo "[3] Testing getUnreadNotificationCount()...\n";
$initialCount = getUnreadNotificationCount($db, $userId);
echo " [INFO] Initial unread count: " . var_export($initialCount, true) . "\n";
if (!is_int($initialCount)) {
    echo " [FAIL] getUnreadNotificationCount did not return an integer\n";
    exit(1);
}
echo " [PASS] getUnreadNotificationCount() returned integer: {$initialCount}\n\n";

// 4. Test getUserNotifications with real user
echo "[4] Testing getUserNotifications()...\n";
$notifs = getUserNotifications($db, $userId);
if (!is_array($notifs)) {
    echo " [FAIL] getUserNotifications did not return an array\n";
    exit(1);
}
echo " [PASS] getUserNotifications() returned array with " . count($notifs) . " item(s)\n\n";

// 5. Create a test activity and test notification to verify exact structure and count increment
echo "[5] Testing notification lifecycle with test record...\n";
$actStmt = $db->query("SELECT id, title FROM activities LIMIT 1");
$act = $actStmt->fetch(PDO::FETCH_ASSOC);
$testActId = $act ? (int)$act['id'] : null;
$testActTitle = $act ? $act['title'] : null;

// Insert a test unread notification
$ins = $db->prepare("INSERT INTO notifications (user_id, activity_id, message, is_read, created_at) VALUES (?, ?, ?, 0, NOW())");
$testMessage = "Test Notification for Helper Functions Verification " . time();
$ins->execute([$userId, $testActId, $testMessage]);
$testNotifId = (int)$db->lastInsertId();

// Verify unread count incremented by 1
$newCount = getUnreadNotificationCount($db, $userId);
echo " [INFO] Count after inserting test notification: {$newCount}\n";
if ($newCount !== $initialCount + 1) {
    echo " [FAIL] Expected unread count " . ($initialCount + 1) . ", got {$newCount}\n";
    $db->prepare("DELETE FROM notifications WHERE id = ?")->execute([$testNotifId]);
    exit(1);
}
echo " [PASS] Unread count correctly incremented\n";

// Verify getUserNotifications returns the test item with all expected fields
$freshNotifs = getUserNotifications($db, $userId, 5);
$foundItem = null;
foreach ($freshNotifs as $n) {
    if ((int)$n['id'] === $testNotifId) {
        $foundItem = $n;
        break;
    }
}

if (!$foundItem) {
    echo " [FAIL] Test notification ID {$testNotifId} not found in getUserNotifications output\n";
    $db->prepare("DELETE FROM notifications WHERE id = ?")->execute([$testNotifId]);
    exit(1);
}

echo " [PASS] Test notification found in recent list\n";

// Check expected keys
$requiredKeys = ['id', 'user_id', 'activity_id', 'message', 'is_read', 'created_at', 'activity_title'];
foreach ($requiredKeys as $key) {
    if (!array_key_exists($key, $foundItem)) {
        echo " [FAIL] Missing expected key '{$key}' in notification record\n";
        $db->prepare("DELETE FROM notifications WHERE id = ?")->execute([$testNotifId]);
        exit(1);
    }
}
echo " [PASS] All expected fields present: " . implode(', ', $requiredKeys) . "\n";
echo " [INFO] activity_title joined value: " . var_export($foundItem['activity_title'], true) . "\n";
if ($testActTitle !== null && $foundItem['activity_title'] !== $testActTitle) {
    echo " [FAIL] Expected activity_title '{$testActTitle}', got '{$foundItem['activity_title']}'\n";
    $db->prepare("DELETE FROM notifications WHERE id = ?")->execute([$testNotifId]);
    exit(1);
}
echo " [PASS] Activity title correctly joined\n";

// Clean up test notification
$db->prepare("DELETE FROM notifications WHERE id = ?")->execute([$testNotifId]);
$finalCount = getUnreadNotificationCount($db, $userId);
if ($finalCount !== $initialCount) {
    echo " [FAIL] Expected cleanup count {$initialCount}, got {$finalCount}\n";
    exit(1);
}
echo " [PASS] Cleaned up test notification and restored original count\n\n";

echo "============================================================\n";
echo " ALL TESTS PASSED: NOTIFICATION HELPERS OPERATIONAL\n";
echo "============================================================\n";
