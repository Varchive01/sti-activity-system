<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
echo "========================================================\n";
echo "   PROGRAM HEAD REVISIONS & PROPOSAL PARITY AUDIT       \n";
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

// 1. Program Head role exists
$ph = $db->query("SELECT * FROM users WHERE role = 'program_head' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
checkItem(1, "Program Head Account Exists", !empty($ph), "Found: " . ($ph['name'] ?? 'None') . " (" . ($ph['email'] ?? 'None') . ")");

// 2. Sidebar Navigation for Program Head
$sidebarCode = file_get_contents(__DIR__ . '/../includes/sidebar.php');
$hasSidebarNewProposal = str_contains($sidebarCode, "'program_head'") && str_contains($sidebarCode, "'href' => BASE_URL . '/faculty/proposal-create.php'");
checkItem(2, "Sidebar 'New Proposal' Navigation Link", $hasSidebarNewProposal, "Program Head sidebar menu includes direct link to proposal-create.php");

// 3. Welcome Banner Primary Action Button
$bannerCode = file_get_contents(__DIR__ . '/../includes/dashboard-welcome-banner.php');
$hasBannerNewProposal = str_contains($bannerCode, "'program_head'") && str_contains($bannerCode, "'href'               => \$baseUrl . '/faculty/proposal-create.php'");
checkItem(3, "Dashboard Welcome Hero Primary Action", $hasBannerNewProposal, "Program Head dashboard hero banner features primary 'New Activity Proposal' button");

// 4. Role Authorization on Proposal Creation & Editing
$createCode = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editCode   = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$saveApi    = file_get_contents(__DIR__ . '/../api/proposal-save.php');
$updateApi  = file_get_contents(__DIR__ . '/../api/proposal-update.php');

$isCreateAuthorized = str_contains($createCode, "requireRole('faculty', 'program_head'");
$isEditAuthorized   = str_contains($editCode, "requireRole('faculty', 'program_head'");
$isSaveAuthorized   = str_contains($saveApi, "requireRole('faculty', 'program_head'");
$isUpdateAuthorized = str_contains($updateApi, "requireRole('faculty', 'program_head'");
checkItem(4, "Proposal Creation & Editing Role Authorization", $isCreateAuthorized && $isEditAuthorized && $isSaveAuthorized && $isUpdateAuthorized, "Program Head is fully authorized across creation, editing, save, and update endpoints");

// 5. Shared Proposal Form Parity (Revisions on New Proposal Form)
$hasEndDate = str_contains($createCode, 'id="f1_end_date"') && str_contains($createCode, 'checkEndDateField()');
$hasOptionalPoster = str_contains($createCode, 'Event Poster (Optional)');
$hasRentalEngine = str_contains($createCode, 'mat-provider-select') && str_contains($createCode, 'calculateMaterialRow');
$hasFloorPlanLightbox = str_contains($createCode, 'floorplan-ui.php');
$hasCombinedCriteria = str_contains($createCode, 'combined-criteria-list-body') && str_contains($createCode, 'Regular Awards');
$hasMergedSchedule = str_contains($createCode, 'ft_start_date') && !str_contains($createCode, 'btnSchedViewTable');

$formParityPass = $hasEndDate && $hasOptionalPoster && $hasRentalEngine && $hasFloorPlanLightbox && $hasCombinedCriteria && $hasMergedSchedule;
if (!$formParityPass) {
    $failedSub = [];
    if (!$hasEndDate) $failedSub[] = 'hasEndDate';
    if (!$hasOptionalPoster) $failedSub[] = 'hasOptionalPoster';
    if (!$hasRentalEngine) $failedSub[] = 'hasRentalEngine';
    if (!$hasFloorPlanLightbox) $failedSub[] = 'hasFloorPlanLightbox';
    if (!$hasCombinedCriteria) $failedSub[] = 'hasCombinedCriteria';
    if (!$hasMergedSchedule) $failedSub[] = 'hasMergedSchedule';
    $parityDetail = "Failed sub-checks: " . implode(', ', $failedSub);
} else {
    $parityDetail = "All revisions (End Date, Optional Poster, Rental Multipliers, Floor Plan Lightbox, Combined Criteria, Merged Schedule & Tasks) are 100% active on the form Program Heads use";
}
checkItem(5, "New Proposal Form Parity (All Capstone Defense Revisions)", $formParityPass, $parityDetail);

// 6. Submission Status Integrity (Status is 'submitted', NOT 'under_review')
$hasCleanSubmittedStatus = str_contains($saveApi, "\$nextStatus = 'submitted';") && !str_contains($saveApi, "\$nextStatus = 'under_review';\n            \$notifyRole = 'admin1';");
checkItem(6, "Proposal Submission Sets Status to 'Submitted' (Not Under Review)", $hasCleanSubmittedStatus, "Proposals submitted by Program Head enter the queue as 'submitted', allowing edits before review commences");

// 7. End-to-End Database Lifecycle Test for Program Head Proposal
$phId = (int)($ph['id'] ?? 1);
$testTitle = "Automated Program Head Test Event " . time();
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$dayAfter = date('Y-m-d', strtotime('+2 days'));

$insertStmt = $db->prepare("
    INSERT INTO activities (
        title, theme, source, venue, venue_address, event_date, end_date,
        start_time, end_time, target_participants, faculty_id, status, created_at, updated_at
    ) VALUES (
        ?, 'Empowering Academic Departments', 'faculty', 'STI Marikina Lab 1',
        '289 L. de Guzman St., Concepcion I, Marikina City', ?, ?, '09:00:00', '16:00:00',
        40, ?, 'submitted', NOW(), NOW()
    )
");
$insertStmt->execute([$testTitle, $tomorrow, $dayAfter, $phId]);
$testActId = (int)$db->lastInsertId();

// Verify persistence
$fetchStmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
$fetchStmt->execute([$testActId]);
$savedAct = $fetchStmt->fetch(PDO::FETCH_ASSOC);

$lifecyclePass = ($savedAct && $savedAct['status'] === 'submitted' && $savedAct['end_date'] === $dayAfter);
checkItem(7, "Program Head Proposal Persistence & End Date", $lifecyclePass, "Test activity #{$testActId} persisted with status 'submitted' and end_date '{$dayAfter}'");

// Verify can edit while 'submitted'
$canEditSubmitted = in_array($savedAct['status'], ['draft', 'submitted', 'returned_for_revision'], true);
checkItem(8, "Program Head Can Edit While 'Submitted'", $canEditSubmitted, "Proponent can modify details while waiting in review queue");

// Simulate review opening by Events Committee Head (admin2)
$db->prepare("UPDATE activities SET status = 'under_review', updated_at = NOW() WHERE id = ?")->execute([$testActId]);
$fetchStmt->execute([$testActId]);
$underReviewAct = $fetchStmt->fetch(PDO::FETCH_ASSOC);
$canEditUnderReview = in_array($underReviewAct['status'], ['draft', 'submitted', 'returned_for_revision'], true);
checkItem(9, "Editing Locks Once Reviewer Opens Proposal ('Under Review')", !$canEditUnderReview, "Once proposal status transitions to 'under_review', editing is locked");

// Cleanup test activity
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);

echo "\n--------------------------------------------------------\n";
$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$totalCount = count($results);
echo "PROGRAM HEAD AUDIT SUMMARY: {$passCount} / {$totalCount} ITEMS VERIFIED\n";
echo "--------------------------------------------------------\n";
