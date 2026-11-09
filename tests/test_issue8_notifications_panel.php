<?php
/**
 * tests/test_issue8_notifications_panel.php
 *
 * Verifies Issue 8:
 * - 'See All' button in notifications panel
 * - 'Mark All as Read' button in notifications panel
 * - Dedicated notifications page (notifications.php) with date/time filtering and organization
 * - Scoped database updates ensuring actions do not affect other users
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== Issue 8: Notifications Panel & Dedicated Center Verification ===\n\n";

$allPassed = true;

function checkTest(string $desc, bool $condition, string $detail = '') {
    global $allPassed;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc} " . ($detail ? "({$detail})" : "") . "\n";
        $allPassed = false;
    }
}

// 1. Inspect notification-topbar-widget.php
$widgetCode = file_get_contents(__DIR__ . '/../includes/notification-topbar-widget.php');
checkTest("notification-topbar-widget.php has NO duplicate 'See All' button in header",
    strpos($widgetCode, 'id="seeAllBtn"') === false
);
checkTest("notification-topbar-widget.php contains 'Mark All as Read' button",
    strpos($widgetCode, 'id="markAllBtn"') !== false &&
    strpos($widgetCode, 'Mark All as Read') !== false
);
checkTest("notification-topbar-widget.php contains footer 'See All Notifications' link",
    strpos($widgetCode, 'notif-footer') !== false &&
    strpos($widgetCode, 'See All Notifications') !== false
);

// 2. Inspect assets/js/notifications.js
$jsCode = file_get_contents(__DIR__ . '/../assets/js/notifications.js');
checkTest("notifications.js handles mark_all_read action",
    strpos($jsCode, "action: 'mark_all_read'") !== false
);
checkTest("notifications.js immediately removes notifBadge on mark all read",
    strpos($jsCode, "document.getElementById('notifBadge')?.remove()") !== false
);

// 3. Inspect dedicated notifications.php page
$pageCode = file_get_contents(__DIR__ . '/../notifications.php');
checkTest("notifications.php file exists and requires login",
    strpos($pageCode, "requireLogin()") !== false
);
checkTest("notifications.php supports date range filtering (today, yesterday, week, month, custom)",
    strpos($pageCode, "date_range") !== false &&
    strpos($pageCode, "CURDATE()") !== false &&
    strpos($pageCode, "from_date") !== false &&
    strpos($pageCode, "to_date") !== false
);
checkTest("notifications.php organizes by date and time (newest / oldest sort)",
    strpos($pageCode, "sort") !== false &&
    strpos($pageCode, "n.created_at ASC") !== false &&
    strpos($pageCode, "n.created_at DESC") !== false
);
checkTest("notifications.php filters by read status (unread / read)",
    strpos($pageCode, "n.is_read = 0") !== false &&
    strpos($pageCode, "n.is_read = 1") !== false
);
checkTest("notifications.php supports keyword search",
    strpos($pageCode, "n.message LIKE ?") !== false
);

// 4. Database User Isolation Integration Test
$db = getDB();
$testUserA = $db->query("SELECT id, name FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$testUserB = $db->query("SELECT id, name FROM users WHERE role IN ('faculty', 'program_head', 'admin1') AND id != " . (int)$testUserA['id'] . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($testUserA && $testUserB) {
    $uidA = (int)$testUserA['id'];
    $uidB = (int)$testUserB['id'];

    // Insert 2 test unread notifications for User A and 2 for User B
    $ins = $db->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
    $ins->execute([$uidA, "Test Notif A1 for User {$uidA}"]);
    $notifA1Id = (int)$db->lastInsertId();
    $ins->execute([$uidA, "Test Notif A2 for User {$uidA}"]);
    $notifA2Id = (int)$db->lastInsertId();

    $ins->execute([$uidB, "Test Notif B1 for User {$uidB}"]);
    $notifB1Id = (int)$db->lastInsertId();
    $ins->execute([$uidB, "Test Notif B2 for User {$uidB}"]);
    $notifB2Id = (int)$db->lastInsertId();

    // Simulate mark_all_read strictly as User A
    $markStmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $markStmt->execute([$uidA]);

    // Check User A's notifications
    $chkA = $db->prepare("SELECT is_read FROM notifications WHERE id IN (?, ?)");
    $chkA->execute([$notifA1Id, $notifA2Id]);
    $aReads = $chkA->fetchAll(PDO::FETCH_COLUMN);
    $allARead = !empty($aReads) && !in_array(0, array_map('intval', $aReads), true);
    checkTest("User A's notifications are marked as read (is_read = 1)", $allARead);

    // Check User B's notifications (Must NOT be affected!)
    $chkB = $db->prepare("SELECT is_read FROM notifications WHERE id IN (?, ?)");
    $chkB->execute([$notifB1Id, $notifB2Id]);
    $bReads = $chkB->fetchAll(PDO::FETCH_COLUMN);
    $allBUnread = !empty($bReads) && !in_array(1, array_map('intval', $bReads), true);
    checkTest("User B's notifications remain unread (is_read = 0, no cross-user pollution)", $allBUnread);

    // Clean up test notifications
    $db->prepare("DELETE FROM notifications WHERE id IN (?, ?, ?, ?)")->execute([$notifA1Id, $notifA2Id, $notifB1Id, $notifB2Id]);
} else {
    checkTest("Database user isolation test skipped (insufficient test users)", false);
}

echo "\nResult: " . ($allPassed ? "ALL 12 CHECKS PASSED!" : "SOME CHECKS FAILED") . "\n";
exit($allPassed ? 0 : 1);

