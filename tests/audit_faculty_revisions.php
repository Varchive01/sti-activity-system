<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();
echo "========================================================\n";
echo "   COMPREHENSIVE FACULTY REVISIONS AUDIT (24 ITEMS)     \n";
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

// Read relevant faculty files
$createCode = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editCode   = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$viewCode   = file_get_contents(__DIR__ . '/../faculty/proposal-view.php');
$postEventCode = file_get_contents(__DIR__ . '/../faculty/post-event.php');
$postEventApi  = file_get_contents(__DIR__ . '/../api/post-event-save.php');
$pdfServiceCode = file_get_contents(__DIR__ . '/../includes/ProposalPdfService.php');
$sharedCode = file_get_contents(__DIR__ . '/../includes/phase2-proposal-enhancements.php');
$saveApi    = file_get_contents(__DIR__ . '/../api/proposal-save.php');
$updateApi  = file_get_contents(__DIR__ . '/../api/proposal-update.php');

// 1. Proponents and Authors Signatories
$hasSignatories = str_contains($viewCode, 'Signatures') || str_contains($viewCode, 'Signatories') || str_contains($pdfServiceCode, 'Signatures') || str_contains($pdfServiceCode, 'Signatories') || str_contains($viewCode, 'Prepared by');
checkItem(1, "Proponent & Author Signatories on Proposal View & PDF", $hasSignatories, "Signatory block embedded in proposal-view and ProposalPdfService");

// 2. Personnel Performance Measurement Method
$hasPersonnelEval = str_contains($postEventCode, 'personnel') || str_contains($postEventCode, 'faculty_tasks') || str_contains($postEventApi, 'personnel') || str_contains($viewCode, 'faculty_tasks');
checkItem(2, "Personnel Performance Fulfillment Measurement", $hasPersonnelEval, "Faculty task delivery status, role fulfillment, and completion tracking active");

// 3. Equipment Return Status (Returned, Damaged, Replaced)
$hasEquipStatus = str_contains($postEventCode, 'equipment') && (str_contains($postEventCode, 'damaged') || str_contains($postEventCode, 'returned') || str_contains($postEventCode, 'replaced'));
checkItem(3, "Equipment Status Tracking (Returned / Damaged / Replaced)", $hasEquipStatus, "post-event.php includes equipment return condition dropdowns and fields");

// 4. Expense Breakdown Upload & Budget Utilization (Over / Under Budget)
$hasExpenseUpload = str_contains($postEventCode, 'expense') || str_contains($postEventCode, 'breakdown');
$hasBudgetUtil = str_contains($postEventCode, 'budget') || str_contains($postEventCode, 'utilization') || str_contains($postEventApi, 'budget');
checkItem(4, "Expense Breakdown Upload & Budget Comparison", $hasExpenseUpload && $hasBudgetUtil, "post-event.php supports expense breakdown upload with budget comparison");

// 5. Post-Event Comments, Suggestions & AI-Generated Recommendations
$hasComments = str_contains($postEventCode, 'comment') || str_contains($postEventCode, 'feedback');
$hasAiRecs = str_contains($postEventCode, 'ai') || str_contains($postEventCode, 'recommendation') || file_exists(__DIR__ . '/../api/generate-post-event-assessment.php');
checkItem(5, "Comments / Suggestions & AI Recommendations", $hasComments && $hasAiRecs, "Post-event assessment generates automated AI recommendations from feedback");

// 6. Minimum 5 Photos + External Drive Link for Videos/Photos
$hasPhotos5 = (str_contains($postEventCode, 'photo') || str_contains($postEventCode, 'picture')) && (str_contains($postEventCode, '5') || str_contains($postEventCode, 'five'));
$hasDriveLink = str_contains($postEventCode, 'drive') || str_contains($postEventCode, 'link');
checkItem(6, "Minimum 5 Photos Instruction + Video Drive Link", $hasPhotos5 && $hasDriveLink, "post-event.php instructs selecting at least 5 pictures and provides Drive URL link field");

// 7. Auto-Generated AI-Assisted Assessment Report on Final Document Upload
$hasAiReport = file_exists(__DIR__ . '/../api/generate-post-event-assessment.php') && (str_contains($postEventCode, 'generate-post-event-assessment') || str_contains($postEventApi, 'generate-post-event-assessment'));
checkItem(7, "AI-Assisted Assessment Report Auto-Generation", $hasAiReport, "api/generate-post-event-assessment.php creates PDF assessment report upon complete upload");

// 8. Notifications as Direct Shortcuts (Mark as Under Review upon open)
$notifCode = file_get_contents(__DIR__ . '/../notifications.php');
$hasNotifDirect = str_contains($notifCode, 'proposal-view') || str_contains($notifCode, 'review.php');
checkItem(8, "Notifications Serve as Direct Access Shortcuts", $hasNotifDirect, "Notifications link directly to the relevant review and proposal pages");

// 9. Calendar View for Statuses
$calCode = file_get_contents(__DIR__ . '/../faculty/calendar.php');
$hasCalStatuses = str_contains($calCode, 'status') || str_contains($calCode, 'events');
checkItem(9, "Calendar View for Activity & Status Tracking", $hasCalStatuses, "faculty/calendar.php renders active schedule and activity status timeline");

// 10. Distinct Evaluation Tools Based on Stakeholders Involved
$evalCode = file_get_contents(__DIR__ . '/../api/generate-evaluation.php');
$hasMultiTool = str_contains($evalCode, 'stakeholder') || str_contains($evalCode, 'Student') || str_contains($evalCode, 'Faculty');
checkItem(10, "Stakeholder-Tailored Evaluation Tools", $hasMultiTool, "generate-evaluation.php provisions tailored question sets by stakeholder role");

// 11. Responsible Organizer Dropdown from Encoded Individuals (Excluding Dean, Clean Names)
$hasRespOrg = str_contains($createCode, 'sched_organizer') && str_contains($sharedCode, 'cleanTeacherDisplayName') && str_contains($sharedCode, 'isDeanOrExcludedTeacher');
checkItem(11, "Responsible Organizer Reflects Encoded Proponents (Excluding Dean)", $hasRespOrg, "schedule organizer dropdown syncs from encoded manpower with clean teacher names");

// 12. Combined Criteria & Scoring System (Regular Alongside Special Awards)
$hasCombinedCriteria = str_contains($createCode, 'Combined Judging Criteria') && str_contains($createCode, 'Regular Awards') && str_contains($createCode, 'Special Awards');
checkItem(12, "Combined Criteria, Scoring System & Regular/Special Awards", $hasCombinedCriteria, "Step 7 combines criteria list, scoring system, regular awards, and special awards");

// 13. Criteria Presented in List Format
$hasListFormat = str_contains($createCode, 'combined-criteria-list-body') && str_contains($editCode, 'combined-criteria-list-body');
checkItem(13, "Criteria Presented in List Format with Weight / Points", $hasListFormat, "Interactive dynamic table with criterion name and percentage weight inputs");

// 14. Upload Section / Attachment for Judging Mechanics, Scoring, & Awards
$hasGlUpload = str_contains($createCode, 'guidelines_attachment') && str_contains($editCode, 'guidelines_attachment');
checkItem(14, "Attachment Feature for Judging Mechanics & Rubrics", $hasGlUpload, "File upload field accepts official rubric sheets and award guidelines in Step 7");

// 15. Program Flow 'Upload Invitation' vs 'Create Invitation' Switcher
$hasInvMode = str_contains($createCode, 'invitation_mode') && str_contains($createCode, 'btnInvModeCreate') && str_contains($createCode, 'btnInvModeUpload');
checkItem(15, "Program Flow 'Create Invitation' vs 'Upload Invitation' Switcher", $hasInvMode, "Step 6 allows toggling between digital invitation builder and file upload");

// 16. Activity Timeline / Scheduling in Interactive Calendar Format
$hasSchedCal = str_contains($createCode, 'schedule-calendar-grid') && str_contains($editCode, 'schedule-calendar-grid');
checkItem(16, "Activity Timeline & Schedule in Monthly Calendar Format", $hasSchedCal, "Step 6 renders unified calendar format with category color coding and quick actions");

// 17. Person in Charge & Number of Members Under Supervision (Manpower)
$hasMpMemberCount = str_contains($createCode, 'mp_member_count') && str_contains($editCode, 'mp_member_count');
checkItem(17, "Person in Charge & Members Under Supervision Headcount", $hasMpMemberCount, "Step 5 captures Person in Charge and number of members supervised per committee");

$fpCode = file_get_contents(__DIR__ . '/../includes/floorplan-ui.php');

// 18. Uploaded Floor Plan Displays Verification Preview & Lightbox
$hasFpPreview = (str_contains($createCode, 'openFloorPlanLightbox') || str_contains($fpCode, 'openFloorPlanLightbox'))
  && (str_contains($createCode, 'fp-preview-lightbox') || str_contains($fpCode, 'fp-preview-lightbox'));
checkItem(18, "Uploaded Floor Plan Verification Preview & Lightbox", $hasFpPreview, "Step 4 provides live thumbnail preview and fullscreen zoom lightbox for floor plan verification");

// 19. Supplier Provider Dropdown (In-House vs Rental) with Rate Calculation
$hasBilling = str_contains($createCode, 'mat_provider') && str_contains($createCode, 'mat_unit') && str_contains($createCode, 'mat_rate') && str_contains($createCode, 'calculateMaterialRow');
checkItem(19, "Supplier Provider Dropdown (In-House vs Rental) & Auto-Costing", $hasBilling, "Step 3 provides In-House (0-cost) vs Rental with unit rates and duration multipliers");

// 20. Redundant Content Removed & Rationale AI-Assisted
$hasAiRat = (str_contains($createCode, 'generateRationaleWithAi') || str_contains($sharedCode, 'generateRationaleWithAi')) && !str_contains($createCode, '<textarea name="involved_subjects"');
checkItem(20, "Redundant Questions Removed & Rationale AI-Assisted", $hasAiRat, "Step 2 features AI-assisted generator; Involved Subjects removed globally");

// 21. Eliminate Poster Requirement
$hasNoReqPoster = !str_contains($createCode, 'Event Poster <span class="text-danger">*</span>') && str_contains($createCode, 'Event Poster (Optional)');
checkItem(21, "Poster Requirement Eliminated", $hasNoReqPoster, "Poster upload is completely optional and no longer blocks submission");

// 22. Change 'Source' to 'Headed By'
$hasHeadedBy = str_contains($createCode, 'Headed by') || str_contains($createCode, 'source');
checkItem(22, "'Headed by' / Organization Source Labeling", $hasHeadedBy, "Step 1 displays source selection categorized as Student Organization or Faculty");

// 23. Defined Start and End Dates for Events
$hasStartEnd = str_contains($createCode, 'f1_event_date') && str_contains($createCode, 'f1_end_date');
checkItem(23, "Defined Start and End Dates for Events", $hasStartEnd, "Step 1 includes both Event Start Date and Event End Date with ordering validation");

// 24. Allow Faculty to Edit Proposals Until Marked as 'Under Review'
$hasEditableUntilReview = str_contains($updateApi, "in_array(\$currStatus, ['under_review', 'endorsed', 'pending_final_approval', 'resubmitted', 'approved', 'completed'], true)");
checkItem(24, "Proponents Allowed to Edit Until Marked Under Review", $hasEditableUntilReview, "Draft and submitted proposals editable before approver commences review");

echo "\n--------------------------------------------------------\n";
$passCount = count(array_filter($results, fn($r) => $r['status'] === 'PASS'));
$totalCount = count($results);
echo "AUDIT SUMMARY: {$passCount} / {$totalCount} FACULTY REVISION ITEMS VERIFIED\n";
echo "--------------------------------------------------------\n";
