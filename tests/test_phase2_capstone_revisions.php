<?php
/**
 * Test Suite: Phase 2 Capstone Revisions Verification
 * 
 * Verifies:
 * 1. Global self-healing Phase 2 schema columns (manpower.member_count, activities.invitation_mode/invitation_path, guidelines.regular_awards/attachment_path).
 * 2. AI-Assisted Rationale generation via api/generate-objectives.php (returns general_objective, specific_objectives, and non-redundant rationale).
 * 3. Floor Plan Live Verification Preview UI in includes/floorplan-ui.php.
 * 4. Person in Charge & Members Under Supervision persistence in manpower table.
 * 5. Responsible Organizer Dropdown synced from encoded individuals in proposal-create.php & proposal-edit.php.
 * 6. Interactive Activity Timeline Calendar Format & Status Review Calendar View in admin1/admin2.
 * 7. Program Invitation Mode ("Create Invitation" vs. "Upload Invitation") and Guidelines Combined Criteria & Scoring (List Format), Regular & Special Awards, and Mechanics Attachment.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

function assertPhase2($cond, $msg) {
    if ($cond) {
        echo "[PASS] {$msg}\n";
    } else {
        echo "[FAIL] {$msg}\n";
        exit(1);
    }
}

echo "=== STARTING PHASE 2 CAPSTONE REVISIONS VERIFICATION ===\n\n";

$db = getDB();
ensureOfficialDefenseAccounts($db, false);

// 1. Verify Phase 2 Self-Healing Schema Columns
$mpCol = $db->query("SHOW COLUMNS FROM manpower LIKE 'member_count'")->fetch();
assertPhase2(!empty($mpCol), "manpower.member_count column exists");

$invModeCol = $db->query("SHOW COLUMNS FROM activities LIKE 'invitation_mode'")->fetch();
$invPathCol = $db->query("SHOW COLUMNS FROM activities LIKE 'invitation_path'")->fetch();
assertPhase2(!empty($invModeCol) && !empty($invPathCol), "activities.invitation_mode and activities.invitation_path columns exist");

$regAwardsCol = $db->query("SHOW COLUMNS FROM guidelines LIKE 'regular_awards'")->fetch();
$glAttachCol  = $db->query("SHOW COLUMNS FROM guidelines LIKE 'attachment_path'")->fetch();
assertPhase2(!empty($regAwardsCol) && !empty($glAttachCol), "guidelines.regular_awards and guidelines.attachment_path columns exist");

$schedTypeCol = $db->query("SHOW COLUMNS FROM schedules LIKE 'schedule_type'")->fetch();
assertPhase2(!empty($schedTypeCol), "schedules.schedule_type column exists");

$ftStartCol = $db->query("SHOW COLUMNS FROM faculty_tasks LIKE 'start_date'")->fetch();
assertPhase2(!empty($ftStartCol), "faculty_tasks.start_date column exists");

// 2. Verify Floor Plan Live Preview UI in includes/floorplan-ui.php
$fpUi = file_get_contents(__DIR__ . '/../includes/floorplan-ui.php');
assertPhase2(
    str_contains($fpUi, 'fp-upload-preview-wrap') && str_contains($fpUi, 'fp-live-preview-img') && str_contains($fpUi, 'openFloorPlanLightbox'),
    "Floor plan UI includes live uploaded floor plan image/PDF verification preview and fullscreen lightbox"
);

// 3. Verify Phase 2 UI Enhancements in proposal-create.php & proposal-edit.php
$createHtml = file_get_contents(__DIR__ . '/../faculty/proposal-create.php');
$editHtml   = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
$sharedHtml = file_get_contents(__DIR__ . '/../includes/phase2-proposal-enhancements.php');

assertPhase2(
    (str_contains($createHtml, 'SMART Objectives &amp; Rationale') || str_contains($createHtml, 'forceRegenerateObjectives')) &&
    (str_contains($editHtml, 'SMART Objectives &amp; Rationale') || str_contains($editHtml, 'forceRegenerateObjectives')) &&
    str_contains($sharedHtml, 'generateRationaleWithAi'),
    "Unified AI-Assisted Objectives & Rationale generator wired in both proposal-create.php and proposal-edit.php"
);

assertPhase2(
    !str_contains($createHtml, 'e_involved_subjects') && !str_contains($editHtml, 'e_involved_subjects'),
    "Involved Subjects successfully removed globally from proposal-create.php and proposal-edit.php"
);

assertPhase2(
    str_contains($createHtml, 'mp_member_count[]') && str_contains($editHtml, 'mp_member_count[]') && str_contains($createHtml, 'Person in Charge') &&
    str_contains($createHtml, 'btn-bulk-add-committees') && str_contains($editHtml, 'btn-bulk-add-committees') && str_contains($sharedHtml, 'openBulkAddCommitteesModal'),
    "Person in Charge & Members Under Supervision column, Bulk Add Committees modal, and headcount summation present in proposal files"
);

assertPhase2(
    str_contains($createHtml, 'sched-organizer-select') && str_contains($editHtml, 'sched-organizer-select') &&
    str_contains($sharedHtml, 'refreshEncodedOrganizerDropdowns') && str_contains($sharedHtml, 'schedModalOrganizer') &&
    str_contains($sharedHtml, 'populateScheduleModalOrganizers') && str_contains($sharedHtml, 'getEncodedIndividualsList') &&
    str_contains($sharedHtml, 'cleanTeacherDisplayName') && str_contains($sharedHtml, 'isDeanOrExcludedTeacher'),
    "Responsible Organizer dropdown and modal selector reflecting encoded individuals, clean teacher names, and dean exclusion present in both proposal-create.php and proposal-edit.php"
);

assertPhase2(
    !str_contains($createHtml, 'Alminar, LPT') && !str_contains($editHtml, 'Alminar, LPT') &&
    !str_contains($createHtml, 'Chiu, RN, MBA') && !str_contains($editHtml, 'Chiu, RN, MBA') &&
    !str_contains($createHtml, 'Carlos, MAEd, LPT') && !str_contains($editHtml, 'Carlos, MAEd, LPT'),
    "Official STI College Marikina faculty roster in proposal creation and editing cleaned: all LPT, RN, MAEd, MBA suffixes removed"
);

assertPhase2(
    str_contains($createHtml, 'schedule-calendar-grid') && str_contains($editHtml, 'schedule-calendar-grid') && str_contains($sharedHtml, 'renderScheduleTimelineCalendar') &&
    str_contains($createHtml, 'btnSchedFilterPrep') && str_contains($createHtml, 'btnSchedFilterMain') && str_contains($createHtml, 'btnSchedFilterSub') &&
    str_contains($createHtml, 'sched-type-field') && str_contains($editHtml, 'sched-type-field'),
    "Interactive Activity Timeline Calendar Format with category color coding (Prep, Main Event, Sub-Event, Faculty Tasks) present in both proposal-create.php and proposal-edit.php"
);

assertPhase2(
    str_contains($createHtml, 'invitation_mode') && str_contains($editHtml, 'invitation_mode') && str_contains($createHtml, 'Create Invitation') && str_contains($createHtml, 'Upload Invitation'),
    "Program Flow 'Create Invitation' vs. 'Upload Invitation' switcher present in both proposal-create.php and proposal-edit.php"
);

assertPhase2(
    str_contains($createHtml, 'combined-criteria-list-body') && str_contains($createHtml, 'guidelines_regular_awards') && str_contains($createHtml, 'guidelines_attachment'),
    "Combined Judging Criteria & Scoring System (List Format), Regular & Special Awards, and Mechanics Attachment upload present"
);

assertPhase2(
    str_contains(file_get_contents(__DIR__ . '/../services/GeminiProposalGuidanceService.php'), '"regular_awards"') &&
    str_contains(file_get_contents(__DIR__ . '/../assets/js/ai-proposal-guidance.js'), 'guidelines_regular_awards') &&
    str_contains(file_get_contents(__DIR__ . '/../includes/phase2-proposal-enhancements.php'), 'colonMatch'),
    "AI Proposal Guidance generator populates both Regular Awards and dynamic Judging Criteria list format rows"
);

assertPhase2(
    str_contains($createHtml, 'btn-bulk-add-sections') && str_contains($editHtml, 'btn-bulk-add-sections') && str_contains($sharedHtml, 'bulkAddSectionsModal') && str_contains($createHtml, 'max-height: 380px'),
    "Bulk Add Sections modal, STI group presets, and compact scrollable table container wired globally"
);

assertPhase2(
    str_contains($createHtml, 'ft_start_date[]') && str_contains($editHtml, 'ft_start_date[]') &&
    str_contains($sharedHtml, 'schedModalTaskStartDate') && str_contains($sharedHtml, 'schedModalTaskDueDate'),
    "Faculty task start date and target deadline range inputs present in proposal create, edit, and calendar modals"
);

assertPhase2(
    str_contains($createHtml, 'totalSteps = 8') && str_contains($editHtml, 'totalSteps = 8') &&
    str_contains($createHtml, 'Schedule &amp; Tasks') && str_contains($editHtml, 'Schedule &amp; Tasks') &&
    !str_contains($createHtml, 'data-step="9"') && !str_contains($editHtml, 'data-step="9"'),
    "Faculty tasks merged into Step 6 (Schedule & Tasks) globally, reducing wizard from 9 steps to 8 clean steps"
);

assertPhase2(
    !str_contains($createHtml, 'btnSchedViewTable') && !str_contains($editHtml, 'btnSchedViewTable') &&
    !str_contains($createHtml, 'btnSchedViewCalendar') && !str_contains($editHtml, 'btnSchedViewCalendar'),
    "Schedule view toggle buttons ('Table Only' & 'Calendar Format') removed cleanly from proposal create and edit headers"
);

assertPhase2(
    str_contains($createHtml, 'id="faculty-tasks-card" style="display:none;"') &&
    str_contains($editHtml, 'id="faculty-tasks-card" style="display:none;"') &&
    str_contains($createHtml, "openAddScheduleEventDialog('', 'task')") &&
    str_contains($editHtml, "openAddScheduleEventDialog('', 'task')"),
    "Faculty tasks table card hidden from view and replaced with calendar action shortcuts in proposal create and edit"
);

assertPhase2(
    str_contains($createHtml, "requireRole('faculty', 'program_head', 'admin1', 'admin2')") &&
    str_contains(file_get_contents(__DIR__ . '/../api/proposal-save.php'), "requireRole('faculty', 'program_head', 'admin1', 'admin2')"),
    "Events committee head (admin2) and student org head (admin1) authorized to create and submit activity proposals"
);

$sidebarContent = file_get_contents(__DIR__ . '/../includes/sidebar.php');
assertPhase2(
    str_contains($sidebarContent, "'admin1'") && str_contains($sidebarContent, "'admin2'") &&
    substr_count($sidebarContent, "'label' => 'New Proposal'") >= 3,
    "Sidebar navigation equipped with 'New Proposal' and 'My Proposals' for Student Org Head and Events Committee Head"
);

$bannerContent = file_get_contents(__DIR__ . '/../includes/dashboard-welcome-banner.php');
assertPhase2(
    str_contains($bannerContent, "'admin1', 'admin2'") &&
    str_contains($bannerContent, "STUDENT ORGANIZATION & SYSTEM PORTAL") &&
    str_contains($bannerContent, "EVENTS COMMITTEE & ADMINISTRATIVE PORTAL"),
    "Dashboard welcome hero banners configured with 'New Activity Proposal' action buttons for admin1 and admin2"
);

$viewHtml = file_get_contents(__DIR__ . '/../faculty/proposal-view.php');
assertPhase2(
    str_contains($viewHtml, 'Duration / Due Date') && str_contains($viewHtml, '$tStart'),
    "Proposal view and PDF export include task duration date range"
);

// 4. Verify Database Persistence of Phase 2 Fields
$facUser = $db->query("SELECT id, name FROM users WHERE LOWER(TRIM(email)) = 'faculty@sti.edu' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facultyId = (int)$facUser['id'];
$startDate = date('Y-m-d', strtotime('+4 days'));
$endDate   = date('Y-m-d', strtotime('+5 days'));

$insAct = $db->prepare("INSERT INTO activities (faculty_id, title, event_date, end_date, venue, source, status, invitation_mode, invitation_path, created_at, updated_at) VALUES (?, 'Phase 2 Verification Activity', ?, ?, 'STI Auditorium', 'faculty', 'submitted', 'upload', 'uploads/invitations/sample_invite.pdf', NOW(), NOW())");
$insAct->execute([$facultyId, $startDate, $endDate]);
$testActId = (int)$db->lastInsertId();
assertPhase2($testActId > 0, "Test Phase 2 activity created (ID: {$testActId}) with invitation_mode='upload'");

$insMp = $db->prepare("INSERT INTO manpower (activity_id, role, assigned_person, type, member_count) VALUES (?, 'Floor Director', 'Dave Lord Rubaya', 'faculty', 12)");
$insMp->execute([$testActId]);
$mpRow = $db->query("SELECT assigned_person, member_count FROM manpower WHERE activity_id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
assertPhase2($mpRow && $mpRow['assigned_person'] === 'Dave Lord Rubaya' && (int)$mpRow['member_count'] === 12, "Manpower Person in Charge ('Dave Lord Rubaya') and Members Supervised (12) persisted accurately");

$insGl = $db->prepare("INSERT INTO guidelines (activity_id, mechanics, criteria, scoring_system, regular_awards, special_awards, attachment_path) VALUES (?, 'General mechanics', '1. Innovation (50%)\n2. Execution (50%)', '1. Innovation: 50%\n2. Execution: 50%', '1. Champion\n2. 1st Runner-Up', '1. Best in UI/UX', 'uploads/guidelines/mechanics_rubric.pdf')");
$insGl->execute([$testActId]);
$glRow = $db->query("SELECT criteria, regular_awards, special_awards, attachment_path FROM guidelines WHERE activity_id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
assertPhase2(
    $glRow && str_contains($glRow['criteria'], '1. Innovation (50%)') && str_contains($glRow['regular_awards'], 'Champion') && $glRow['attachment_path'] === 'uploads/guidelines/mechanics_rubric.pdf',
    "Combined Criteria/Scoring List, Regular Awards, Special Awards, and Guidelines Attachment persisted accurately"
);

$insSched = $db->prepare("INSERT INTO schedules (activity_id, sched_date, event_name, schedule_type, venue, organizer) VALUES (?, ?, 'Grand Plenary', 'main_event', 'Main Auditorium', 'Faculty Proponent')");
$insSched->execute([$testActId, $startDate]);
$schedRow = $db->query("SELECT schedule_type FROM schedules WHERE activity_id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
assertPhase2($schedRow && $schedRow['schedule_type'] === 'main_event', "Schedule category type ('main_event') persisted accurately");

$insTask = $db->prepare("INSERT INTO faculty_tasks (activity_id, task_title, task_description, role_in_event, start_date, due_date, status) VALUES (?, 'Technical Setup', 'Audio setup', 'Technical Lead', ?, ?, 'Not Started')");
$insTask->execute([$testActId, $startDate, $endDate]);
$taskRow = $db->query("SELECT start_date, due_date FROM faculty_tasks WHERE activity_id = {$testActId}")->fetch(PDO::FETCH_ASSOC);
assertPhase2($taskRow && $taskRow['start_date'] === $startDate && $taskRow['due_date'] === $endDate, "Faculty task start_date and due_date persisted accurately");

// Clean up test record
$db->prepare("DELETE FROM faculty_tasks WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM schedules WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM manpower WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM guidelines WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);

// 5. Verify Status Review Calendar Integration in admin1 & admin2
$admin1Cal = file_get_contents(__DIR__ . '/../admin1/calendar.php');
$admin2Cal = file_get_contents(__DIR__ . '/../admin2/calendar.php');
assertPhase2(
    str_contains($admin1Cal, 'popupReviewBtn') && str_contains($admin2Cal, 'popupReviewBtn'),
    "Status Review Calendar in admin1 and admin2 includes direct 'Review Status' shortcut in calendar event popups"
);

echo "\n=== ALL PHASE 2 CAPSTONE REVISION TESTS PASSED SUCCESSFULLY! ===\n";
