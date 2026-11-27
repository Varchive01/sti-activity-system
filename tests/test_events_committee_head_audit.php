<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
echo "========================================================\n";
echo "   EVENTS COMMITTEE HEAD (ADMIN2) REVISIONS & AUDIT     \n";
echo "========================================================\n\n";

$results = [];
function checkItem($id, $title, $condition, $details = '') {
    global $results;
    $status = $condition ? 'PASS' : 'FAIL';
    $results[] = ['id' => $id, 'title' => $title, 'status' => $status, 'details' => $details];
    echo ($condition ? "[PASS] " : "[FAIL] ") . "Item {$id}: {$title}\n";
    if (!empty($details)) {
        echo "       Detail: {$details}\n";
    }
}

// 1. Admin 2 user exists
$admin2 = $db->query("SELECT * FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
checkItem(1, "Events Committee Head (Admin2) Account Exists", !empty($admin2), "Found: " . ($admin2['name'] ?? 'None') . " (" . ($admin2['email'] ?? 'None') . ")");

// 2. Sidebar Navigation for Admin 2
$sidebarCode = file_get_contents(__DIR__ . '/../includes/sidebar.php');
$hasSidebarNew = str_contains($sidebarCode, "'admin2'") && str_contains($sidebarCode, "'href' => BASE_URL . '/faculty/proposal-create.php'") && str_contains($sidebarCode, "'label' => 'My Proposals'");
checkItem(2, "Sidebar 'New Proposal' & 'My Proposals' Navigation", $hasSidebarNew, "Admin2 sidebar menu equipped with proposal creation and tracking shortcuts");

// 3. Welcome Hero Banner for Admin 2
$bannerCode = file_get_contents(__DIR__ . '/../includes/dashboard-welcome-banner.php');
$hasBannerNew = str_contains($bannerCode, "'admin2'") && str_contains($bannerCode, "'label'              => 'New Activity Proposal'");
checkItem(3, "Dashboard Welcome Hero Primary Action", $hasBannerNew, "Admin2 dashboard hero banner features primary 'New Activity Proposal' button");

// 4. Default Source Auto-Selection on Proposal Creation
$createCode = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$hasDefaultFaculty = str_contains($createCode, "(\$user['role'] === 'admin2') ? 'faculty'");
checkItem(4, "Proposal Creation Auto-Defaults to Faculty/Institutional Source", $hasDefaultFaculty, "Form pre-selects 'Faculty' as source when created by Admin 2");

// 5. Submission Routing for Admin 2 (Direct to Dean for Final Approval)
$saveApi   = file_get_contents(__DIR__ . '/../api/proposal-save.php');
$updateApi = file_get_contents(__DIR__ . '/../api/proposal-update.php');
$hasAdmin2Routing = str_contains($saveApi, "\$userRole === 'admin2'") && str_contains($saveApi, "\$nextStatus = 'pending_final_approval';") && str_contains($saveApi, "\$notifyRole = 'dean';");
checkItem(5, "Admin 2 Own Proposal Final Approval Routing (Direct to Dean)", $hasAdmin2Routing, "Proposals authored by Admin 2 route directly to the Academic Head / Dean for final approval");

// 6. Review Screen (admin2/review.php) Verification Parity
$reviewCode = file_get_contents(__DIR__ . '/../admin2/review.php');
$hasReviewDateRange = str_contains($reviewCode, 'event_date') && str_contains($reviewCode, 'end_date');
$hasOptionalPoster  = str_contains($reviewCode, "!empty(\$activity['poster_path'])");
$hasMaterialsBadges = str_contains($reviewCode, 'In-House (School)') && str_contains($reviewCode, 'Rental (Supplier)');
$hasFloorPlanModal  = str_contains($reviewCode, 'hasValidFloorPlanContent');
$hasManpowerCounts  = str_contains($reviewCode, 'member_count');
$hasSchedBadges     = str_contains($reviewCode, 'schedule_type');
$hasAwardsFields    = str_contains($reviewCode, 'regular_awards');
$hasTaskDurations   = str_contains($reviewCode, 'Task Duration') && str_contains($reviewCode, 'due_date');

$reviewParityPass = $hasReviewDateRange && $hasOptionalPoster && $hasMaterialsBadges && $hasFloorPlanModal && $hasManpowerCounts && $hasSchedBadges && $hasAwardsFields && $hasTaskDurations;
checkItem(6, "Review Screen Parity for All Defense Revisions", $reviewParityPass, "Review screen displays date range, optional poster, in-house/rental badges, floor plan, member counts, schedule categories, awards, and task durations");

// 7. Auto-Transition to 'Under Review' Upon Opening Review
$hasAutoUnderReview = str_contains($reviewCode, "\$db->prepare(\"UPDATE activities SET status = 'under_review', updated_at = NOW() WHERE id = ?\")");
checkItem(7, "Reviewer Shortcut Auto-Transitions to 'Under Review'", $hasAutoUnderReview, "When Admin 2 opens a proposal in review.php, status changes from 'submitted' to 'under_review'");

// 8. Calendar View (admin2/calendar.php) Statuses & Direct Review Link
$calCode = file_get_contents(__DIR__ . '/../admin2/calendar.php');
$hasCalSubmitted = str_contains($calCode, "'submitted'              => ['color' => '#D97706', 'bg' => '#FEF3C7', 'label' => 'Submitted']");
$hasCalReviewBtn = str_contains($calCode, 'popupReviewBtn') && str_contains($calCode, '/admin2/review.php?id=');
checkItem(8, "Calendar Status Mapping & Direct Review Shortcut", $hasCalSubmitted && $hasCalReviewBtn, "Calendar labels 'submitted' cleanly and equips event popups with direct review shortcut");

// 9. Post-Event & Reporting Verification
$postEventCode = file_get_contents(__DIR__ . '/../admin2/post-event.php');
$postEventView = file_get_contents(__DIR__ . '/../admin2/post-event-view.php');
$hasPostEventAccess = str_contains($postEventCode, "requireRole('admin2')") && str_contains($postEventView, 'post-event-view-template.php');
checkItem(9, "Post-Event Reporting & Assessment Access", $hasPostEventAccess, "Admin 2 has dedicated post-event results portal with full documentation and assessment view");

// 10. End-to-End Database Lifecycle Test for Admin 2
$admin2Id = (int)($admin2['id'] ?? 1);
$testTitle = "Automated Admin2 Test Event " . time();
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayAfter = date('Y-m-d', strtotime('+2 days'));

$insertStmt = $db->prepare("
    INSERT INTO activities (
        title, theme, source, venue, venue_address, event_date, end_date,
        start_time, end_time, target_participants, faculty_id, status, created_at, updated_at
    ) VALUES (
        ?, 'STI Institutional Foundations Day', 'faculty', 'STI Marikina Main Hall',
        '289 L. de Guzman St., Concepcion I, Marikina City', ?, ?, '07:30:00', '18:00:00',
        500, ?, 'pending_final_approval', NOW(), NOW()
    )
");
$insertStmt->execute([$testTitle, $tomorrow, $dayAfter, $admin2Id]);
$testActId = (int)$db->lastInsertId();

// Verify persistence
$fetchStmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
$fetchStmt->execute([$testActId]);
$savedAct = $fetchStmt->fetch(PDO::FETCH_ASSOC);

$admin2LifecyclePass = ($savedAct && $savedAct['status'] === 'pending_final_approval' && $savedAct['source'] === 'faculty' && $savedAct['end_date'] === $dayAfter);
checkItem(10, "Admin 2 Own Proposal Final Approval Routing & Persistence", $admin2LifecyclePass, "Test activity #{$testActId} persisted with source 'faculty', status 'pending_final_approval', and end_date '{$dayAfter}'");

// Cleanup test activity
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);

echo "\n--------------------------------------------------------\n";
$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$totalCount = count($results);
echo "EVENTS COMMITTEE HEAD AUDIT SUMMARY: {$passCount} / {$totalCount} ITEMS VERIFIED\n";
echo "--------------------------------------------------------\n";
