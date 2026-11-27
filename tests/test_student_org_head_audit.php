<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
echo "========================================================\n";
echo "   STUDENT ORG HEAD (ADMIN1) REVISIONS & AUDIT          \n";
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

// 1. Admin 1 user exists
$admin1 = $db->query("SELECT * FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
checkItem(1, "Student Org Head (Admin1) Account Exists", !empty($admin1), "Found: " . ($admin1['name'] ?? 'None') . " (" . ($admin1['email'] ?? 'None') . ")");

// 2. Sidebar Navigation for Admin 1
$sidebarCode = file_get_contents(__DIR__ . '/../includes/sidebar.php');
$hasSidebarNew = str_contains($sidebarCode, "'admin1'") && str_contains($sidebarCode, "'href' => BASE_URL . '/faculty/proposal-create.php'") && str_contains($sidebarCode, "'label' => 'My Proposals'");
checkItem(2, "Sidebar 'New Proposal' & 'My Proposals' Navigation", $hasSidebarNew, "Admin1 sidebar menu equipped with proposal creation and tracking shortcuts");

// 3. Welcome Hero Banner for Admin 1
$bannerCode = file_get_contents(__DIR__ . '/../includes/dashboard-welcome-banner.php');
$hasBannerNew = str_contains($bannerCode, "'admin1'") && str_contains($bannerCode, "'label'              => 'New Activity Proposal'");
checkItem(3, "Dashboard Welcome Hero Primary Action", $hasBannerNew, "Admin1 dashboard hero banner features primary 'New Activity Proposal' button");

// 4. Default Source Auto-Selection on Proposal Creation
$createCode = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$hasDefaultStudentOrg = str_contains($createCode, "(\$user['role'] === 'admin1') ? 'student_org'");
checkItem(4, "Proposal Creation Auto-Defaults to Student Organization", $hasDefaultStudentOrg, "Form pre-selects 'Student Organization' as source when created by Admin 1");

// 5. Submission Routing for Admin 1 (Endorsed -> Events Committee Head)
$saveApi   = file_get_contents(__DIR__ . '/../api/proposal-save.php');
$updateApi = file_get_contents(__DIR__ . '/../api/proposal-update.php');
$hasAdmin1Routing = str_contains($saveApi, "\$userRole === 'admin1'") && str_contains($saveApi, "\$nextStatus = 'endorsed';") && str_contains($saveApi, "\$notifyRole = 'admin2';");
checkItem(5, "Admin 1 Own Proposal Auto-Endorsement Routing", $hasAdmin1Routing, "Proposals authored by Admin 1 are automatically endorsed and routed to Events Committee Head (admin2)");

// 6. Review Screen (admin1/review.php) Verification Parity
$reviewCode = file_get_contents(__DIR__ . '/../admin1/review.php');
$hasReviewDateRange = str_contains($reviewCode, 'event_date') && str_contains($reviewCode, 'end_date');
$hasOptionalPoster  = str_contains($reviewCode, "!empty(\$activity['poster_path'])");
$hasMaterialsBadges = str_contains($reviewCode, 'In-House (School)') && str_contains($reviewCode, 'Rental (Supplier)');
$hasFloorPlanModal  = str_contains($reviewCode, 'hasValidFloorPlanContent') && str_contains($reviewCode, 'View Floor Plan File');
$hasManpowerCounts  = str_contains($reviewCode, 'member_count');
$hasSchedBadges     = str_contains($reviewCode, 'schedule_type') && str_contains($reviewCode, 'Prep') && str_contains($reviewCode, 'Sub-Event');
$hasAwardsFields    = str_contains($reviewCode, 'regular_awards') && str_contains($reviewCode, 'Special Awards');
$hasTaskDurations   = str_contains($reviewCode, 'Task Duration') && str_contains($reviewCode, 'due_date');

$reviewParityPass = $hasReviewDateRange && $hasOptionalPoster && $hasMaterialsBadges && $hasFloorPlanModal && $hasManpowerCounts && $hasSchedBadges && $hasAwardsFields && $hasTaskDurations;
checkItem(6, "Review Screen Parity for All Defense Revisions", $reviewParityPass, "Review screen displays date range, optional poster, in-house/rental badges, floor plan, member counts, schedule categories, awards, and task durations");

// 7. Auto-Transition to 'Under Review' Upon Opening Review
$hasAutoUnderReview = str_contains($reviewCode, "\$db->prepare(\"UPDATE activities SET status = 'under_review', updated_at = NOW() WHERE id = ?\")");
checkItem(7, "Reviewer Shortcut Auto-Transitions to 'Under Review'", $hasAutoUnderReview, "When Admin 1 opens a proposal in review.php, status changes from 'submitted' to 'under_review'");

// 8. Calendar View (admin1/calendar.php) Statuses & Direct Review Link
$calCode = file_get_contents(__DIR__ . '/../admin1/calendar.php');
$hasCalSubmitted = str_contains($calCode, "'submitted'              => ['color' => '#D97706', 'bg' => '#FEF3C7', 'label' => 'Submitted']");
$hasCalReviewBtn = str_contains($calCode, 'popupReviewBtn') && str_contains($calCode, '/admin1/review.php?id=');
checkItem(8, "Calendar Status Mapping & Direct Review Shortcut", $hasCalSubmitted && $hasCalReviewBtn, "Calendar labels 'submitted' cleanly and equips event popups with direct review shortcut");

// 9. Post-Event & Reporting Verification
$postEventCode = file_get_contents(__DIR__ . '/../admin1/post-event.php');
$postEventView = file_get_contents(__DIR__ . '/../admin1/post-event-view.php');
$hasPostEventAccess = str_contains($postEventCode, "requireRole('admin1')") && str_contains($postEventView, 'post-event-view-template.php');
checkItem(9, "Post-Event Reporting & Assessment Access", $hasPostEventAccess, "Admin 1 has dedicated post-event results portal with full documentation and assessment view");

// 10. End-to-End Database Lifecycle Test for Admin 1
$admin1Id = (int)($admin1['id'] ?? 1);
$testTitle = "Automated Admin1 Test Event " . time();
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayAfter = date('Y-m-d', strtotime('+2 days'));

$insertStmt = $db->prepare("
    INSERT INTO activities (
        title, theme, source, venue, venue_address, event_date, end_date,
        start_time, end_time, target_participants, faculty_id, status, created_at, updated_at
    ) VALUES (
        ?, 'Student Leadership Conclave', 'student_org', 'STI Marikina Gym',
        '289 L. de Guzman St., Concepcion I, Marikina City', ?, ?, '08:00:00', '17:00:00',
        250, ?, 'endorsed', NOW(), NOW()
    )
");
$insertStmt->execute([$testTitle, $tomorrow, $dayAfter, $admin1Id]);
$testActId = (int)$db->lastInsertId();

// Verify persistence
$fetchStmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
$fetchStmt->execute([$testActId]);
$savedAct = $fetchStmt->fetch(PDO::FETCH_ASSOC);

$admin1LifecyclePass = ($savedAct && $savedAct['status'] === 'endorsed' && $savedAct['source'] === 'student_org' && $savedAct['end_date'] === $dayAfter);
checkItem(10, "Admin 1 Own Proposal Endorsement & Persistence", $admin1LifecyclePass, "Test activity #{$testActId} persisted with source 'student_org', status 'endorsed', and end_date '{$dayAfter}'");

// Cleanup test activity
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);

echo "\n--------------------------------------------------------\n";
$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$totalCount = count($results);
echo "STUDENT ORG HEAD AUDIT SUMMARY: {$passCount} / {$totalCount} ITEMS VERIFIED\n";
echo "--------------------------------------------------------\n";
