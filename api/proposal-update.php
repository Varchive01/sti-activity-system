<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';
require_once __DIR__ . '/../includes/ai/schedule_conflict.php';
requireRole('faculty', 'program_head');

$user   = currentUser();
$db     = getDB();
$id     = (int)($_POST['activity_id'] ?? 0);
// Apply global automatic capitalization rules (John correction and sentence capitalization)
$textFieldsToCapitalize = [
    'title', 'theme', 'venue', 'venue_address',
    'general_objectives', 'specific_objectives',
    'involved_subjects', 'rationale', 'description',
    'floor_plan_notes'
];
foreach ($textFieldsToCapitalize as $tf) {
    if (isset($_POST[$tf]) && is_string($_POST[$tf]) && function_exists('autoCapitalizeText')) {
        $_POST[$tf] = autoCapitalizeText($_POST[$tf]);
    }
}

$action = $_POST['action'] ?? 'draft';

$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
    (isset($_POST['is_ajax_sync']) && $_POST['is_ajax_sync'] === '1')
);

// Verify access
if (!canUserAccessActivity($id, 'modify', $user['id'], $user['role'], $user['dept'])) {
    http_response_code(403);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error_code' => 'access_denied', 'error' => 'Access denied: You do not have permission to modify this activity.']);
        exit;
    }
    die('Access denied: You do not have permission to modify this activity.');
}
$check = $db->prepare("SELECT * FROM activities WHERE id=?");
$check->execute([$id]);
$activity = $check->fetch();
if (!$activity) {
    if ($isAjax) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error_code' => 'not_found', 'error' => 'Activity not found.']);
        exit;
    }
    header('Location: '.BASE_URL.'/faculty/activities.php');
    exit;
}

// Conflict detection for offline draft synchronization and collaborative editing:
// Check if server version was modified after the client's last known server timestamp.
$lastKnown = trim((string)($_POST['last_known_updated_at'] ?? ''));
$forceOverwrite = !empty($_POST['force_overwrite']);

if ($lastKnown !== '' && !$forceOverwrite && !empty($activity['updated_at'])) {
    $serverTime = strtotime($activity['updated_at']);
    $clientTime = strtotime($lastKnown);
    // Tolerance margin of 2 seconds for server-client timestamp resolution
    if ($serverTime > ($clientTime + 2)) {
        // Determine if other active collaborators exist on this proposal
        $isOwner = ((int)$activity['faculty_id'] === (int)$user['id']);
        $otherCollabStmt = $db->prepare("
            SELECT COUNT(*) 
            FROM proposal_collaborators 
            WHERE activity_id = ? AND user_id != ? AND status = 'active'
        ");
        $otherCollabStmt->execute([$id, (int)$user['id']]);
        $otherCollabCount = (int)$otherCollabStmt->fetchColumn();

        // Other contributors exist if there are active collaborator rows or user is not the proposal owner
        $hasOtherContributors = ($otherCollabCount > 0) || !$isOwner;

        // Check if the most recent contribution was made by another user
        $lastModifierIsOther = false;
        if ($hasOtherContributors) {
            $lastLogStmt = $db->prepare("
                SELECT user_id 
                FROM proposal_contribution_logs 
                WHERE activity_id = ? 
                ORDER BY created_at DESC, id DESC 
                LIMIT 1
            ");
            $lastLogStmt->execute([$id]);
            $lastLogUserId = $lastLogStmt->fetchColumn();
            if ($lastLogUserId !== false && $lastLogUserId !== null) {
                $lastModifierIsOther = ((int)$lastLogUserId !== (int)$user['id']);
            } else {
                $lastModifierIsOther = true;
            }
        }

        // Trigger conflict only if:
        // 1. It is an AJAX sync request (offline draft sync detecting server version changes), OR
        // 2. Other contributors actually exist AND the last modification was made by someone else
        $isConflict = $isAjax ? true : ($hasOtherContributors && $lastModifierIsOther);

        if ($isConflict) {
            if ($isAjax) {
                http_response_code(409); // Conflict
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'success'           => false,
                    'conflict'          => true,
                    'error_code'        => 'conflict_requires_review',
                    'error'             => 'Conflict detected: The server version of this proposal was modified by another contributor after your view was loaded. Please reload to review the latest changes.',
                    'server_updated_at' => $activity['updated_at'],
                    'client_updated_at' => $lastKnown,
                    'server_title'      => $activity['title'],
                    'activity_id'       => $id
                ]);
                exit;
            } else {
                header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&conflict=1&server_time=" . urlencode($activity['updated_at']));
                exit;
            }
        }
    }
}

// Fetch current status to prevent losing returned_for_revision state
$curr = $db->prepare("SELECT status FROM activities WHERE id=?");
$curr->execute([$id]);
$currStatus = $curr->fetchColumn();

if ($action === 'submit') {
    // Collaborative permission: Only proposal owner may perform final submission for approval
    $isOwner = ((int)$activity['faculty_id'] === (int)$user['id']);
    if (!$isOwner) {
        http_response_code(403);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'    => false,
                'error_code' => 'owner_submission_only',
                'error'      => 'Only the proposal owner may submit this proposal for approval. Contributors may save draft changes.'
            ]);
            exit;
        }
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=owner_submission_only");
        exit;
    }
    $newStatus = 'submitted';
    $clearNotes = true;
} else {
    // Never allow a draft save or background offline-draft sync to downgrade an already-submitted proposal back to 'draft'
    if (in_array($currStatus, ['submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted', 'approved', 'completed'], true)) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'     => true,
                'activity_id' => $id,
                'status'      => $currStatus,
                'message'     => 'Proposal is already submitted.',
                'updated_at'  => $activity['updated_at'] ?? date('Y-m-d H:i:s'),
                'title'       => $activity['title'] ?? ''
            ]);
            exit;
        }
        $newStatus = $currStatus;
    } else {
        // If it was returned for revision, keep that status when saving as draft
        $newStatus = ($currStatus === 'returned_for_revision') ? 'returned_for_revision' : 'draft';
    }
    $clearNotes = false;
}

// Parse section-specific reviewer comments from revision_sections JSON
$revisionSections = [];
if (!empty($activity['revision_sections'])) {
    $decoded = json_decode($activity['revision_sections'], true);
    if (is_array($decoded)) $revisionSections = $decoded;
}
$isReturned = ($activity['status'] === 'returned_for_revision');
$hasSectionComments = !empty($revisionSections);

// Check editability for all wizard sections
$s1Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['event_details']);
$s2Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['objectives']);
$s3Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['materials']);
$s4Editable = true; // Floor plan is always fully editable
$s5Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['people']);
$s6Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['schedule']);
$s7Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['guidelines']);
$s8Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['faculty_tasks']);
$s9Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['kpi']);

// Authoritative date validation (Asia/Manila)
$todayManila = date('Y-m-d');
$eventDate   = trim($_POST['event_date'] ?? '');

if ($s1Editable) {
    if ($action === 'submit' && empty($eventDate)) {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=missing_date");
        exit;
    }
    if (!empty($eventDate)) {
        $d = DateTime::createFromFormat('Y-m-d', $eventDate);
        // Rule: event_date > today's date (earliest allowed date is tomorrow)
        if (!$d || $d->format('Y-m-d') !== $eventDate || $eventDate <= $todayManila) {
            if ($isAjax) {
                http_response_code(400);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error_code' => 'past_date', 'error' => 'Event date cannot be in the past.']);
                exit;
            }
            header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=past_date");
            exit;
        }
    }
    $saveEventDate = $eventDate ?: null;

    // Authoritative server-side schedule time validation
    $startTime = isset($_POST['start_time']) && is_string($_POST['start_time']) ? trim($_POST['start_time']) : '';
    $endTime   = isset($_POST['end_time']) && is_string($_POST['end_time']) ? trim($_POST['end_time']) : '';

    if ($action === 'submit') {
        $timeCheck = validateScheduleTimeRange($startTime, $endTime, true);
        if (!$timeCheck['valid']) {
            $errCode = ($timeCheck['code'] === 'invalid_order') ? 'invalid_time' : 'missing_time';
            header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error={$errCode}");
            exit;
        }
    } else {
        // Draft: if both times are provided, validate order (end > start)
        if ($startTime !== '' && $endTime !== '') {
            $timeCheck = validateScheduleTimeRange($startTime, $endTime, false);
            if (!$timeCheck['valid']) {
                if ($isAjax) {
                    http_response_code(400);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'error_code' => 'invalid_time', 'error' => 'Invalid time range.']);
                    exit;
                }
                header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=invalid_time");
                exit;
            }
        }
    }
    $saveStartTime = $startTime ?: null;
    $saveEndTime   = $endTime ?: null;
} else {
    // When Section 1 is locked, preserve existing event_date and times
    $saveEventDate = $activity['event_date'];
    $saveStartTime = $activity['start_time'];
    $saveEndTime   = $activity['end_time'];
}

// Authoritative server-side schedule conflict check for submitted proposals
if ($action === 'submit') {
    $venue = isset($_POST['venue']) && is_string($_POST['venue']) ? trim($_POST['venue']) : '';
    if (!$s1Editable && empty($venue) && !empty($activity['venue'])) {
        $venue = trim($activity['venue']);
    }
    if (!empty($venue) && !empty($saveEventDate) && !empty($saveStartTime) && !empty($saveEndTime)) {
        $conflictCheck = checkScheduleConflict($venue, $saveEventDate, $saveStartTime, $saveEndTime, $id);
        if (!empty($conflictCheck['conflict'])) {
            header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=conflict");
            exit;
        }
    }
}

$s6Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['schedule']);
if ($s6Editable && !empty($_POST['sched_date']) && is_array($_POST['sched_date'])) {
    foreach ($_POST['sched_date'] as $sDate) {
        $sDate = trim($sDate);
        if (!empty($sDate)) {
            $sd = DateTime::createFromFormat('Y-m-d', $sDate);
            if (!$sd || $sd->format('Y-m-d') !== $sDate || $sDate <= $todayManila) {
                header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=past_date");
                exit;
            }
        }
    }
}

if (!empty($_POST['schedule_rows']) && is_array($_POST['schedule_rows'])) {
    $rowsCheck = validateScheduleRows($_POST['schedule_rows'], ($action === 'submit'));
    if (!$rowsCheck['valid']) {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=invalid_time");
        exit;
    }
}

if (!empty($_FILES['poster_file']['name'])) {
    if (!isValidPosterImage($_FILES['poster_file'])) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error_code' => 'invalid_poster_format', 'error' => 'Invalid poster format.']);
            exit;
        }
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=invalid_poster_format");
        exit;
    }
}

if (!empty($_FILES['floor_plan_file']['name'])) {
    if (!isValidFloorPlanFile($_FILES['floor_plan_file'])) {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=invalid_floor_plan_format");
        exit;
    }
}

// Resolve Section 1 data (Event Details) for both draft and submission
if (!$s1Editable) {
    $title              = trim($activity['title'] ?? '');
    $theme              = trim($activity['theme'] ?? '');
    $venue              = trim($activity['venue'] ?? '');
    $venueAddress       = trim($activity['venue_address'] ?? '');
    $targetParticipants = (int)($activity['target_participants'] ?? 0);
} else {
    $title              = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
    $theme              = isset($_POST['theme']) && is_string($_POST['theme']) ? trim($_POST['theme']) : '';
    $venue              = isset($_POST['venue']) && is_string($_POST['venue']) ? trim($_POST['venue']) : '';
    $venueAddress       = isset($_POST['venue_address']) && is_string($_POST['venue_address']) ? trim($_POST['venue_address']) : '';
    $targetParticipants = isset($_POST['target_participants']) && is_numeric($_POST['target_participants']) ? (int)$_POST['target_participants'] : 0;
    $partCounts = $_POST['part_count'] ?? $_POST['participant_count'] ?? [];
    if (is_array($partCounts) && !empty($partCounts)) {
        $sumParticipants = 0;
        foreach ($partCounts as $pc) {
            $sumParticipants += max(0, (int)$pc);
        }
        if ($sumParticipants > 0 || $targetParticipants <= 0) {
            $targetParticipants = $sumParticipants;
        }
    }

    // When saving draft, preserve existing values if field was omitted in POST
    if ($action === 'draft') {
        if ($title === '' && !empty($activity['title']) && !isset($_POST['title'])) {
            $title = trim($activity['title']);
        }
        if ($theme === '' && !empty($activity['theme']) && !isset($_POST['theme'])) {
            $theme = trim($activity['theme']);
        }
        if ($venue === '' && !empty($activity['venue']) && !isset($_POST['venue'])) {
            $venue = trim($activity['venue']);
        }
        if ($venueAddress === '' && !empty($activity['venue_address']) && !isset($_POST['venue_address'])) {
            $venueAddress = trim($activity['venue_address']);
        }
        if ($targetParticipants <= 0 && !empty($activity['target_participants']) && !isset($_POST['target_participants']) && empty($partCounts)) {
            $targetParticipants = (int)$activity['target_participants'];
        }
    }
}
$_POST['title'] = $title;
$_POST['theme'] = $theme;
$_POST['venue'] = $venue;
$_POST['venue_address'] = $venueAddress;
$_POST['target_participants'] = $targetParticipants;

// Resolve Section 2 data (Objectives) for both draft and submission
if (!$s2Editable) {
    $generalObjectives  = trim($activity['general_objectives'] ?? '');
    $specificObjectives = trim($activity['specific_objectives'] ?? '');
    $involvedSubjects   = trim($activity['involved_subjects'] ?? '');
    $rationale          = trim($activity['rationale'] ?? '');
} else {
    $generalObjectives  = isset($_POST['general_objectives']) && is_string($_POST['general_objectives']) ? trim($_POST['general_objectives']) : '';
    $specificObjectives = isset($_POST['specific_objectives']) && is_string($_POST['specific_objectives']) ? trim($_POST['specific_objectives']) : '';
    $involvedSubjects   = isset($_POST['involved_subjects']) && is_string($_POST['involved_subjects']) ? trim($_POST['involved_subjects']) : '';
    $rationale          = isset($_POST['rationale']) && is_string($_POST['rationale']) ? trim($_POST['rationale']) : '';

    // When saving draft, preserve existing values if field was omitted in POST
    if ($action === 'draft') {
        if ($generalObjectives === '' && !empty($activity['general_objectives']) && !isset($_POST['general_objectives'])) {
            $generalObjectives = trim($activity['general_objectives']);
        }
        if ($specificObjectives === '' && !empty($activity['specific_objectives']) && !isset($_POST['specific_objectives'])) {
            $specificObjectives = trim($activity['specific_objectives']);
        }
        if ($involvedSubjects === '' && !empty($activity['involved_subjects']) && !isset($_POST['involved_subjects'])) {
            $involvedSubjects = trim($activity['involved_subjects']);
        }
        if ($rationale === '' && !empty($activity['rationale']) && !isset($_POST['rationale'])) {
            $rationale = trim($activity['rationale']);
        }
    }
}
$_POST['general_objectives'] = $generalObjectives;
$_POST['specific_objectives'] = $specificObjectives;
$_POST['involved_subjects'] = $involvedSubjects;
$_POST['rationale'] = $rationale;

// Server-side required-field and KPI validation for submissions
if ($action === 'submit') {
    if (
        $title === '' ||
        $theme === '' ||
        $venue === '' ||
        $generalObjectives === '' ||
        $specificObjectives === '' ||
        $rationale === '' ||
        $targetParticipants <= 0
    ) {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=missing_fields");
        exit;
    }

    // Authoritative server-side gibberish detection for submissions
    require_once __DIR__ . '/../includes/gibberish_detector.php';
    $gibberishCheck = validateProposalGibberish($_POST, $s1Editable);
    if (!$gibberishCheck['valid']) {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=invalid_text_input");
        exit;
    }

    $hasValidIndicator = false;
    if (!empty($_POST['kpi_indicator']) && is_array($_POST['kpi_indicator'])) {
        foreach ($_POST['kpi_indicator'] as $ind) {
            if (is_string($ind) && trim($ind) !== '') {
                $hasValidIndicator = true;
                break;
            }
        }
    }

    $hasValidCriteria = false;
    if (!empty($_POST['kpi_criteria']) && is_array($_POST['kpi_criteria'])) {
        foreach ($_POST['kpi_criteria'] as $crit) {
            if (is_string($crit) && trim($crit) !== '') {
                $hasValidCriteria = true;
                break;
            }
        }
    }

    if (!$hasValidIndicator && !$hasValidCriteria) {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=missing_kpi");
        exit;
    }

    if (!$hasValidIndicator && isset($_POST['kpi_indicator'])) {
        unset($_POST['kpi_indicator']);
    }
    if (!$hasValidCriteria && isset($_POST['kpi_criteria'])) {
        unset($_POST['kpi_criteria']);
    }

    // Server-side rule-based completeness & consistency validation gating (100% offline)
    $ruleTitle     = trim((string)($_POST['title'] ?? ''));
    $ruleGenObj    = trim((string)($_POST['general_objectives'] ?? ''));
    $ruleSpecObj   = trim((string)($_POST['specific_objectives'] ?? ''));
    $ruleRationale = trim((string)($_POST['rationale'] ?? ''));
    $ruleVenue     = trim((string)($_POST['venue'] ?? ''));
    $ruleDate      = trim((string)($_POST['event_date'] ?? ''));
    $ruleStart     = trim((string)($_POST['start_time'] ?? ''));
    $ruleEnd       = trim((string)($_POST['end_time'] ?? ''));

    if ($ruleTitle === '' || $ruleGenObj === '' || $ruleSpecObj === '' || $ruleRationale === '' || $ruleVenue === '' || $ruleDate === '' || $ruleStart === '' || $ruleEnd === '') {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=validation_incomplete");
        exit;
    }
}

$rawSource = trim($_POST['source'] ?? '');
if (in_array($rawSource, ['student_org', 'faculty'], true)) {
    $source = $rawSource;
} elseif (!empty($activity['source']) && in_array($activity['source'], ['student_org', 'faculty'], true)) {
    $source = $activity['source'];
} else {
    $source = 'faculty';
}
$evalQuestions = isset($_POST['evaluation_questions']) ? $_POST['evaluation_questions'] : $activity['evaluation_questions'];

// Server-side validation of floor plan requirement choice
$rawFpReq = $_POST['floor_plan_required'] ?? ($activity['floor_plan_required'] ?? 1);
$floorPlanRequired = true; // default Yes for existing behavior & backward compatibility
if (is_string($rawFpReq)) {
    $rawFpReqLower = strtolower(trim($rawFpReq));
    if ($rawFpReqLower === 'no' || $rawFpReqLower === '0' || $rawFpReqLower === 'false') {
        $floorPlanRequired = false;
    } elseif ($rawFpReqLower === 'yes' || $rawFpReqLower === '1' || $rawFpReqLower === 'true') {
        $floorPlanRequired = true;
    }
} elseif (is_numeric($rawFpReq)) {
    $floorPlanRequired = ((int)$rawFpReq === 1);
}
$fpRequiredInt = $floorPlanRequired ? 1 : 0;

// Authoritative server-side floor plan validation when required for submission
if ($action === 'submit' && $floorPlanRequired) {
    $existingFpStmt = $db->prepare("SELECT file_path, notes, canvas_json FROM floor_plans WHERE activity_id=?");
    $existingFpStmt->execute([$id]);
    $existingFp = $existingFpStmt->fetch();

    $fpNotes = trim($_POST['floor_plan_notes'] ?? ($existingFp['notes'] ?? ''));
    $hasFpFile = !empty($_FILES['floor_plan_file']['name']) || !empty($existingFp['file_path']);
    $hasFpImage = !empty($_POST['floor_plan_image']);
    $hasFpCanvas = false;
    $jsonToCheck = !empty($_POST['floor_plan_json']) ? $_POST['floor_plan_json'] : ($existingFp['canvas_json'] ?? '');
    if (!empty($jsonToCheck)) {
        $parsedFp = json_decode($jsonToCheck, true);
        if (is_array($parsedFp)) {
            if (!empty($parsedFp['shapes']) && is_array($parsedFp['shapes']) && count($parsedFp['shapes']) > 0) {
                $hasFpCanvas = true;
            } elseif (!empty($parsedFp['floors']) && is_array($parsedFp['floors'])) {
                foreach ($parsedFp['floors'] as $fl) {
                    if (is_array($fl) && !empty($fl['shapes']) && is_array($fl['shapes']) && count($fl['shapes']) > 0) {
                        $hasFpCanvas = true;
                        break;
                    }
                }
            }
        }
    }

    $hasAnyFloorPlan = $hasFpFile || $hasFpImage || $hasFpCanvas || ($fpNotes !== '');
    if (!$hasAnyFloorPlan) {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=missing_fields");
        exit;
    }

    // When using the integrated creator (and no file uploaded or existing), notes/description is strictly required
    if (!$hasFpFile && ($hasFpImage || $hasFpCanvas) && $fpNotes === '') {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=missing_fields");
        exit;
    }
}

// Server-side validation for test scores and actual attendees when Section 9 is editable
if ($s9Editable) {
    if (!empty($_POST['part_pre_test']) && is_array($_POST['part_pre_test'])) {
        foreach ($_POST['part_pre_test'] as $score) {
            if ($score !== '' && $score !== null) {
                if (!is_numeric($score) || (float)$score < 0 || (float)$score > 999.99) {
                    header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=invalid_score");
                    exit;
                }
            }
        }
    }
    if (!empty($_POST['part_post_test']) && is_array($_POST['part_post_test'])) {
        foreach ($_POST['part_post_test'] as $score) {
            if ($score !== '' && $score !== null) {
                if (!is_numeric($score) || (float)$score < 0 || (float)$score > 999.99) {
                    header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=invalid_score");
                    exit;
                }
            }
        }
    }
    if (!empty($_POST['part_actual_attendees']) && is_array($_POST['part_actual_attendees'])) {
        foreach ($_POST['part_actual_attendees'] as $att) {
            if ($att !== '' && $att !== null) {
                if (!is_numeric($att) || (int)$att < 0) {
                    header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=invalid_score");
                    exit;
                }
            }
        }
    }
}

// Ensure activity_participants table exists before starting transaction to avoid MySQL implicit DDL commit
try {
    $db->exec("CREATE TABLE IF NOT EXISTS activity_participants (
        id INT AUTO_INCREMENT PRIMARY KEY,
        activity_id INT NOT NULL,
        row_no INT NOT NULL DEFAULT 1,
        subject_section VARCHAR(255) NOT NULL DEFAULT '',
        participants_count INT NOT NULL DEFAULT 0,
        actual_attendees INT NULL DEFAULT NULL,
        pre_test_score DECIMAL(5,2) NULL DEFAULT NULL,
        post_test_score DECIMAL(5,2) NULL DEFAULT NULL,
        learning_kpi_result VARCHAR(100) NULL DEFAULT NULL,
        teacher_name TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_activity_id (activity_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $tblEx) {
    error_log("activity_participants table check notice: " . $tblEx->getMessage());
}

$newlyCreatedDiskPath = null;
$previousFloorPlanPath = null;

try {
    $db->beginTransaction();

    if ($clearNotes) {
        $db->prepare("UPDATE activities SET
            title=?,theme=?,venue=?,venue_address=?,event_date=?,start_time=?,end_time=?,
            target_participants=?,general_objectives=?,specific_objectives=?,
            involved_subjects=?,rationale=?,evaluation_method=?,source=?,
            status=?,evaluation_questions=?,floor_plan_required=?,revision_notes=NULL,revision_sections=NULL,submitted_at=?,updated_at=NOW()
            WHERE id=?")->execute([
            sanitize($title), sanitize($theme), sanitize($venue), sanitize($venueAddress),
            $saveEventDate, $saveStartTime, $saveEndTime,
            (int)$targetParticipants,
            sanitize($generalObjectives), sanitize($specificObjectives),
            sanitize($involvedSubjects), sanitize($rationale),
            sanitize($_POST['evaluation_method'] ?? ($_POST['eval_form_link'] ?? ($activity['evaluation_method'] ?? ''))),
            $source, $newStatus,
            $evalQuestions,
            $fpRequiredInt,
            date('Y-m-d H:i:s'), $id
        ]);
    } else {
        $db->prepare("UPDATE activities SET
            title=?,theme=?,venue=?,venue_address=?,event_date=?,start_time=?,end_time=?,
            target_participants=?,general_objectives=?,specific_objectives=?,
            involved_subjects=?,rationale=?,evaluation_method=?,source=?,
            status=?,evaluation_questions=?,floor_plan_required=?,updated_at=NOW()
            WHERE id=?")->execute([
            sanitize($title), sanitize($theme), sanitize($venue), sanitize($venueAddress),
            $saveEventDate, $saveStartTime, $saveEndTime,
            (int)$targetParticipants,
            sanitize($generalObjectives), sanitize($specificObjectives),
            sanitize($involvedSubjects), sanitize($rationale),
            sanitize($_POST['evaluation_method'] ?? ($_POST['eval_form_link'] ?? ($activity['evaluation_method'] ?? ''))),
            $source, $newStatus,
            $evalQuestions,
            $fpRequiredInt,
            $id
        ]);
    }

    // Replace child tables only if their sections are editable or post-activity KPI values submitted
    $partActuals  = $_POST['part_actual_attendees'] ?? null;
    $partPreTests = $_POST['part_pre_test'] ?? null;
    $partPostTests= $_POST['part_post_test'] ?? null;

    if ($s1Editable) {
        $partSections = $_POST['part_section'] ?? $_POST['participant_section'] ?? null;
        $partCounts   = $_POST['part_count'] ?? $_POST['participant_count'] ?? null;
        $partTeachers = $_POST['part_teacher'] ?? $_POST['participant_teacher'] ?? null;

        if ($partSections !== null || $partCounts !== null) {
            try {
                $colCheck = $db->query("SHOW COLUMNS FROM activity_participants LIKE 'actual_attendees'")->fetch();
                $hasKpiCols = (bool)$colCheck;

                // Query existing rows to preserve actual / pre / post test if not submitted in this request
                $existingMap = [];
                if ($hasKpiCols) {
                    $existStmt = $db->prepare("SELECT * FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC, id ASC");
                    $existStmt->execute([$id]);
                    foreach ($existStmt->fetchAll(PDO::FETCH_ASSOC) as $ep) {
                        $existingMap[$ep['row_no']] = $ep;
                    }
                }

                $db->prepare("DELETE FROM activity_participants WHERE activity_id=?")->execute([$id]);

                if (is_array($partSections) || is_array($partCounts)) {
                    if ($hasKpiCols) {
                        $pStmt = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, actual_attendees, pre_test_score, post_test_score, learning_kpi_result, teacher_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    } else {
                        $pStmt = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, teacher_name) VALUES (?, ?, ?, ?, ?)");
                    }

                    $rowNo = 1;
                    $loopList = is_array($partSections) ? $partSections : $partCounts;
                    foreach ($loopList as $i => $item) {
                        $secText = sanitize($partSections[$i] ?? '');
                        $cntVal  = max(0, (int)($partCounts[$i] ?? 0));
                        $tRaw    = $partTeachers[$i] ?? '';
                        if (is_array($tRaw)) {
                            $tVal = implode(', ', array_filter(array_map('trim', $tRaw)));
                        } else {
                            $tVal = trim((string)$tRaw);
                        }
                        $tVal    = sanitize($tVal);

                        $actVal = null;
                        $preVal = null;
                        $postVal = null;
                        $kpiOutcome = null;

                        if ($s9Editable) {
                            if (is_array($partActuals) && isset($partActuals[$i]) && $partActuals[$i] !== '' && is_numeric($partActuals[$i])) {
                                $actVal = max(0, (int)$partActuals[$i]);
                            } elseif (isset($existingMap[$rowNo]['actual_attendees'])) {
                                $actVal = $existingMap[$rowNo]['actual_attendees'];
                            }

                            if (is_array($partPreTests) && isset($partPreTests[$i]) && $partPreTests[$i] !== '' && is_numeric($partPreTests[$i])) {
                                $preVal = (float)$partPreTests[$i];
                            } elseif (isset($existingMap[$rowNo]['pre_test_score'])) {
                                $preVal = $existingMap[$rowNo]['pre_test_score'];
                            }

                            if (is_array($partPostTests) && isset($partPostTests[$i]) && $partPostTests[$i] !== '' && is_numeric($partPostTests[$i])) {
                                $postVal = (float)$partPostTests[$i];
                            } elseif (isset($existingMap[$rowNo]['post_test_score'])) {
                                $postVal = $existingMap[$rowNo]['post_test_score'];
                            }

                            if ($preVal !== null && $postVal !== null) {
                                $diff = $postVal - $preVal;
                                $kpiOutcome = ($diff >= 0 ? '+' : '') . number_format($diff, 1) . ' pts';
                            } elseif (isset($existingMap[$rowNo]['learning_kpi_result'])) {
                                $kpiOutcome = $existingMap[$rowNo]['learning_kpi_result'];
                            }
                        } else {
                            // Section 9 is locked: discard any submitted KPI changes and preserve existing database values
                            if (isset($existingMap[$rowNo])) {
                                $actVal     = $existingMap[$rowNo]['actual_attendees'] ?? null;
                                $preVal     = $existingMap[$rowNo]['pre_test_score'] ?? null;
                                $postVal    = $existingMap[$rowNo]['post_test_score'] ?? null;
                                $kpiOutcome = $existingMap[$rowNo]['learning_kpi_result'] ?? null;
                            }
                        }

                        if ($secText !== '' || $cntVal > 0 || $tVal !== '' || $actVal !== null || $preVal !== null || $postVal !== null) {
                            if ($hasKpiCols) {
                                $pStmt->execute([$id, $rowNo, $secText, $cntVal, $actVal, $preVal, $postVal, $kpiOutcome, $tVal]);
                            } else {
                                $pStmt->execute([$id, $rowNo, $secText, $cntVal, $tVal]);
                            }
                        }
                        $rowNo++;
                    }
                }
            } catch (Exception $e) {
                error_log("Error saving activity_participants in proposal-update: " . $e->getMessage());
            }
        }
    } elseif (!$s1Editable && $s9Editable && ($partActuals !== null || $partPreTests !== null || $partPostTests !== null)) {
        // Section 1 is locked, but Section 9 IS editable: update post-activity KPI values without altering participant structure
        try {
            $existStmt = $db->prepare("SELECT id, row_no, actual_attendees, pre_test_score, post_test_score, learning_kpi_result FROM activity_participants WHERE activity_id=? ORDER BY row_no ASC, id ASC");
            $existStmt->execute([$id]);
            $existingParts = $existStmt->fetchAll(PDO::FETCH_ASSOC);

            $updateKpiStmt = $db->prepare("UPDATE activity_participants SET actual_attendees=?, pre_test_score=?, post_test_score=?, learning_kpi_result=? WHERE id=?");
            foreach ($existingParts as $i => $ep) {
                $actVal = (is_array($partActuals) && isset($partActuals[$i]) && $partActuals[$i] !== '' && is_numeric($partActuals[$i])) ? max(0, (int)$partActuals[$i]) : ($ep['actual_attendees'] ?? null);
                $preVal = (is_array($partPreTests) && isset($partPreTests[$i]) && $partPreTests[$i] !== '' && is_numeric($partPreTests[$i])) ? (float)$partPreTests[$i] : ($ep['pre_test_score'] ?? null);
                $postVal = (is_array($partPostTests) && isset($partPostTests[$i]) && $partPostTests[$i] !== '' && is_numeric($partPostTests[$i])) ? (float)$partPostTests[$i] : ($ep['post_test_score'] ?? null);
                $kpiOutcome = null;
                if ($preVal !== null && $postVal !== null) {
                    $diff = $postVal - $preVal;
                    $kpiOutcome = ($diff >= 0 ? '+' : '') . number_format($diff, 1) . ' pts';
                } else {
                    $kpiOutcome = $ep['learning_kpi_result'] ?? null;
                }
                $updateKpiStmt->execute([$actVal, $preVal, $postVal, $kpiOutcome, $ep['id']]);
            }
        } catch (Exception $e) {
            error_log("Error updating post-activity participant KPIs: " . $e->getMessage());
        }
    }

    if ($s3Editable) {
        $db->prepare("DELETE FROM materials WHERE activity_id=?")->execute([$id]);
        if (!empty($_POST['mat_item'])) {
            $ms = $db->prepare("INSERT INTO materials(activity_id,item_name,description,quantity,provider,est_cost)VALUES(?,?,?,?,?,?)");
            foreach ($_POST['mat_item'] as $i=>$name) {
                if (!trim($name)) continue;
                $ms->execute([$id,sanitize($name),sanitize($_POST['mat_desc'][$i]??''),(int)($_POST['mat_qty'][$i]??1),sanitize($_POST['mat_provider'][$i]??''),(float)($_POST['mat_cost'][$i]??0)]);
            }
        }
    }

    if ($s6Editable) {
        $db->prepare("DELETE FROM program_sequence WHERE activity_id=?")->execute([$id]);
        if (!empty($_POST['prog_segment'])) {
            $ps = $db->prepare("INSERT INTO program_sequence(activity_id,time_slot,segment,description,person_ic,sort_order)VALUES(?,?,?,?,?,?)");
            foreach ($_POST['prog_segment'] as $i=>$seg) {
                if (!trim($seg)) continue;
                $ps->execute([$id,$_POST['prog_time'][$i]??null,sanitize($seg),sanitize($_POST['prog_desc'][$i]??''),sanitize($_POST['prog_pic'][$i]??''),$i]);
            }
        }

        $db->prepare("DELETE FROM schedules WHERE activity_id=?")->execute([$id]);
        if (!empty($_POST['sched_event'])) {
            $sc = $db->prepare("INSERT INTO schedules(activity_id,sched_date,event_name,venue,organizer)VALUES(?,?,?,?,?)");
            foreach ($_POST['sched_event'] as $i=>$ev) {
                if (!trim($ev)) continue;
                $sc->execute([$id,$_POST['sched_date'][$i]??null,sanitize($ev),sanitize($_POST['sched_venue'][$i]??''),sanitize($_POST['sched_organizer'][$i]??'')]);
            }
        }
    }

    if ($s5Editable) {
        $db->prepare("DELETE FROM manpower WHERE activity_id=?")->execute([$id]);
        if (!empty($_POST['mp_role'])) {
            $mp = $db->prepare("INSERT INTO manpower(activity_id,role,assigned_person,type)VALUES(?,?,?,?)");
            foreach ($_POST['mp_role'] as $i=>$role) {
                if (!trim($role)) continue;
                $rawMpType = strtolower(trim((string)($_POST['mp_type'][$i] ?? 'faculty')));
                $normMpType = in_array($rawMpType, ['faculty', 'staff', 'student'], true) ? $rawMpType : 'faculty';
                $mp->execute([$id,sanitize($role),sanitize($_POST['mp_person'][$i]??''),$normMpType]);
            }
        }
    }

    if ($s7Editable) {
        $db->prepare("DELETE FROM guidelines WHERE activity_id=?")->execute([$id]);
        $db->prepare("INSERT INTO guidelines(activity_id,mechanics,criteria,scoring_system,special_awards)VALUES(?,?,?,?,?)")->execute([
            $id,sanitize($_POST['guidelines_mechanics']??''),sanitize($_POST['guidelines_criteria']??''),
            sanitize($_POST['guidelines_scoring']??''),sanitize($_POST['guidelines_awards']??'')
        ]);
    }

    // Faculty Tasks Reconciliation
    $s8Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['faculty_tasks']);
    if ($s8Editable) {
        $existingTasksStmt = $db->prepare("SELECT id, assigned_user_id FROM faculty_tasks WHERE activity_id = ?");
        $existingTasksStmt->execute([$id]);
        $existingTaskRows = $existingTasksStmt->fetchAll(PDO::FETCH_ASSOC);
        $existingTaskIdsMap = [];
        $existingTaskAssigneesMap = [];
        foreach ($existingTaskRows as $er) {
            $existingTaskIdsMap[(int)$er['id']] = true;
            $existingTaskAssigneesMap[(int)$er['id']] = $er['assigned_user_id'] ? (int)$er['assigned_user_id'] : null;
        }

        $submittedKeepIds = [];

        $updateTaskStmt = $db->prepare("
            UPDATE faculty_tasks 
            SET assigned_user_id = ?,
                faculty_name = ?,
                assigned_task = ?,
                task_title = ?,
                contribution_desc = ?,
                task_description = ?,
                role_in_event = ?,
                due_date = ?
            WHERE id = ? AND activity_id = ?
        ");

        $insertTaskStmt = $db->prepare("
            INSERT INTO faculty_tasks (
                activity_id, assigned_user_id, faculty_name, assigned_task,
                task_title, contribution_desc, task_description, role_in_event,
                due_date, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Not Started', ?)
        ");

        $facultyLookupStmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'faculty' LIMIT 1");

        $taskItems = !empty($_POST['ft_task']) && is_array($_POST['ft_task'])
            ? $_POST['ft_task']
            : (!empty($_POST['ft_name']) && is_array($_POST['ft_name']) ? $_POST['ft_name'] : []);

        if (!empty($taskItems)) {
            foreach ($taskItems as $i => $itemVal) {
                $tTitle = sanitize($_POST['ft_task'][$i] ?? '');
                $rawTaskId = isset($_POST['ft_id'][$i]) ? (int)$_POST['ft_id'][$i] : 0;
                $rawUserId = isset($_POST['ft_user_id'][$i]) && is_numeric($_POST['ft_user_id'][$i]) ? (int)$_POST['ft_user_id'][$i] : 0;
                $submittedName = sanitize($_POST['ft_name'][$i] ?? '');

                if ($tTitle === '' && $submittedName === '' && $rawUserId === 0 && $rawTaskId === 0) {
                    continue;
                }

                $assignedUserId = null;
                $facultyName = $submittedName !== '' ? $submittedName : null;

                if ($rawUserId > 0) {
                    $facultyLookupStmt->execute([$rawUserId]);
                    $fUser = $facultyLookupStmt->fetch(PDO::FETCH_ASSOC);
                    if ($fUser) {
                        $assignedUserId = (int)$fUser['id'];
                        $facultyName = $fUser['name'];
                    } else {
                        $db->rollBack();
                        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id=" . $id . "&error=invalid_faculty_assignment");
                        exit;
                    }
                }

                $tDesc  = sanitize($_POST['ft_contribution'][$i] ?? '');
                $tRole  = sanitize($_POST['ft_role'][$i] ?? '');

                // Validate optional due date (YYYY-MM-DD)
                $dueDate = null;
                if (!empty($_POST['ft_due_date'][$i])) {
                    $rawDate = trim($_POST['ft_due_date'][$i]);
                    $dObj = DateTime::createFromFormat('Y-m-d', $rawDate);
                    if ($dObj && $dObj->format('Y-m-d') === $rawDate) {
                        $dueDate = $rawDate;
                    } else {
                        $db->rollBack();
                        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id=" . $id . "&error=invalid_task_deadline");
                        exit;
                    }
                }

                if ($rawTaskId > 0 && isset($existingTaskIdsMap[$rawTaskId])) {
                    $prevAssignee = $existingTaskAssigneesMap[$rawTaskId] ?? null;
                    $updateTaskStmt->execute([
                        $assignedUserId, $facultyName, $tTitle, $tTitle, $tDesc, $tDesc, $tRole, $dueDate, $rawTaskId, $id
                    ]);
                    $submittedKeepIds[] = $rawTaskId;

                    // Notify new assignee if task was newly assigned or reassigned to a different person (only on non-drafts)
                    if ($assignedUserId && $assignedUserId !== $prevAssignee && $newStatus !== 'draft') {
                        sendTaskAssignmentNotification($db, $assignedUserId, $id, $tTitle, $dueDate);
                    }
                } else {
                    $insertTaskStmt->execute([
                        $id, $assignedUserId, $facultyName, $tTitle, $tTitle, $tDesc, $tDesc, $tRole, $dueDate, $user['id'] ?? null
                    ]);
                    if ($assignedUserId && $newStatus !== 'draft') {
                        sendTaskAssignmentNotification($db, $assignedUserId, $id, $tTitle, $dueDate);
                    }
                }
            }
        }

        // Handle omitted tasks: only delete if the task has NO deliverables and NO reminder logs
        $checkDeliv = $db->prepare("SELECT COUNT(*) FROM task_deliverables WHERE task_id = ?");
        $checkRemind = $db->prepare("SELECT COUNT(*) FROM task_reminder_logs WHERE task_id = ?");
        $deleteTaskStmt = $db->prepare("DELETE FROM faculty_tasks WHERE id = ? AND activity_id = ?");

        foreach (array_keys($existingTaskIdsMap) as $oldTaskId) {
            if (!in_array($oldTaskId, $submittedKeepIds, true)) {
                $checkDeliv->execute([$oldTaskId]);
                $hasDeliv = ((int)$checkDeliv->fetchColumn() > 0);

                $checkRemind->execute([$oldTaskId]);
                $hasRemind = ((int)$checkRemind->fetchColumn() > 0);

                if (!$hasDeliv && !$hasRemind) {
                    $deleteTaskStmt->execute([$oldTaskId, $id]);
                }
                // If it has deliverables or reminder logs, preserve the task to prevent orphaned records and history loss
            }
        }
    }

    // Floor plan update
    $checkFp = $db->prepare("SELECT id, file_path, notes, canvas_json FROM floor_plans WHERE activity_id=?");
    $checkFp->execute([$id]);
    $existingFpRecord = $checkFp->fetch();
    $previousFloorPlanPath = $existingFpRecord['file_path'] ?? null;

    $path = null;
    $hasNewImage = false;
    if (!empty($_FILES['floor_plan_file']['name'])) {
        $path = uploadFile($_FILES['floor_plan_file'], 'floorplans', $id);
        if ($path === false) {
            $db->rollBack();
            header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=upload_failed");
            exit;
        }
        $newlyCreatedDiskPath = UPLOAD_PATH . $path;
        $hasNewImage = true;
    } elseif (!empty($_POST['floor_plan_image'])) {
        $data = $_POST['floor_plan_image'];
        list($type, $data) = explode(';', $data);
        list(, $data)      = explode(',', $data);
        $data = base64_decode($data);
        $filename = 'fp_' . $id . '_' . time() . '.png';
        $uploadDir = __DIR__ . '/../uploads/floorplans/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        file_put_contents($uploadDir . $filename, $data);
        $newlyCreatedDiskPath = $uploadDir . $filename;
        $path = 'uploads/floorplans/' . $filename;
        $hasNewImage = true;
    }

    $saveFpNotes = sanitize($_POST['floor_plan_notes'] ?? '');
    $saveFpJson = $_POST['floor_plan_json'] ?? null;

    if ($hasNewImage) {
        if ($existingFpRecord) {
            $db->prepare("UPDATE floor_plans SET file_path=?, notes=?, canvas_json=? WHERE activity_id=?")
               ->execute([$path, ($saveFpNotes !== '' ? $saveFpNotes : ($existingFpRecord['notes'] ?? '')), ($saveFpJson ? $saveFpJson : ($existingFpRecord['canvas_json'] ?? null)), $id]);
        } else {
            $fp = $db->prepare("INSERT INTO floor_plans(activity_id,file_path,notes,canvas_json) VALUES (?,?,?,?)");
            $fp->execute([$id, $path, $saveFpNotes, $saveFpJson]);
        }
    } else {
        if ($existingFpRecord) {
            $finalNotes = ($saveFpNotes !== '') ? $saveFpNotes : $existingFpRecord['notes'];
            $finalJson = ($saveFpJson !== null && $saveFpJson !== '') ? $saveFpJson : $existingFpRecord['canvas_json'];
            $db->prepare("UPDATE floor_plans SET notes=?, canvas_json=? WHERE activity_id=?")
               ->execute([$finalNotes, $finalJson, $id]);
        } elseif ($floorPlanRequired && ($saveFpNotes !== '' || ($saveFpJson !== null && $saveFpJson !== ''))) {
            $fp = $db->prepare("INSERT INTO floor_plans(activity_id,file_path,notes,canvas_json) VALUES (?,?,?,?)");
            $fp->execute([$id, null, $saveFpNotes, $saveFpJson]);
        }
    }

    // 8.5 Poster update
    $savedPosterPath = null;
    if (!empty($_FILES['poster_file']['name'])) {
        $posterPath = uploadFile($_FILES['poster_file'], 'posters', $id);
        if ($posterPath) {
            $db->prepare("UPDATE activities SET poster_path=? WHERE id=?")->execute([$posterPath, $id]);
            $savedPosterPath = $posterPath;
        }
    }

    // KPIs (Section 9)
    if ($s9Editable) {
        $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id=?")->execute([$id]);
        if (!empty($_POST['kpi_indicator'])) {
            $kpi = $db->prepare("INSERT INTO kpi_evaluations(activity_id,indicator,target_metric,evaluation_method)VALUES(?,?,?,?)");
            foreach ($_POST['kpi_indicator'] as $i=>$ind) {
                if (!trim($ind)) continue;
                $kpi->execute([$id,sanitize($ind),sanitize($_POST['kpi_target'][$i]??''),sanitize($_POST['kpi_method'][$i]??'')]);
            }
        } elseif (!empty($_POST['kpi_criteria'])) {
            $kpi = $db->prepare("INSERT INTO kpi_evaluations(activity_id,indicator,target_metric,evaluation_method)VALUES(?,?,?,?)");
            foreach ($_POST['kpi_criteria'] as $i=>$crit) {
                if (!trim($crit)) continue;
                $target = isset($_POST['kpi_rating'][$i]) ? ('Rating: ' . $_POST['kpi_rating'][$i]) : '';
                $kpi->execute([$id,sanitize($crit),sanitize($target),'Student Evaluation']);
            }
        }
    }

    // Routing on resubmit
    if ($newStatus === 'submitted') {
        // Find last return reviewer role
        $logStmt = $db->prepare("
            SELECT al.*, u.role
            FROM approval_logs al
            JOIN users u ON al.reviewer_id = u.id
            WHERE al.activity_id = ? AND al.action = 'returned_for_revision'
            ORDER BY al.acted_at DESC LIMIT 1
        ");
        $logStmt->execute([$id]);
        $lastReturn = $logStmt->fetch();

        $currSource = $curr['source'] ?? 'faculty';
        if ($lastReturn) {
            if ($lastReturn['role'] === 'admin1') {
                $nextStatus = 'under_review';
                $notifyRole = 'admin1';
            } elseif ($lastReturn['role'] === 'admin2') {
                $nextStatus = ($currSource === 'student_org') ? 'endorsed' : 'submitted';
                $notifyRole = 'admin2';
            } elseif ($lastReturn['role'] === 'dean') {
                $nextStatus = 'pending_final_approval';
                $notifyRole = 'dean';
            } else {
                $nextStatus = ($currSource === 'student_org') ? 'under_review' : 'submitted';
                $notifyRole = ($currSource === 'student_org') ? 'admin1' : 'admin2';
            }
        } else {
            // Default routing by source if no previous return log
            $nextStatus = ($currSource === 'student_org') ? 'under_review' : 'submitted';
            $notifyRole = ($currSource === 'student_org') ? 'admin1' : 'admin2';
        }

        $db->prepare("UPDATE activities SET status=?, revision_sections=NULL WHERE id=?")->execute([$nextStatus,$id]);

        $admins = $db->prepare("SELECT id, email, name FROM users WHERE role=?");
        $admins->execute([$notifyRole]);
        $adminRows = $admins->fetchAll();
        $ns = $db->prepare("INSERT INTO notifications(user_id,activity_id,message)VALUES(?,?,?)");
        $proposalTitle = sanitize($_POST['title'] ?? '');
        $emailsToSend = [];
        foreach ($adminRows as $admin) {
            $ns->execute([$admin['id'],$id,"Resubmitted proposal: " . $proposalTitle]);
            if (!empty($admin['email'])) {
                $emailsToSend[] = [
                    'email'  => $admin['email'],
                    'name'   => $admin['name'] ?? 'Administrator',
                    'role'   => $notifyRole,
                    'status' => ucwords(str_replace('_', ' ', $nextStatus)),
                ];
            }
        }
    }

    if ($db->inTransaction()) {
        $db->commit();
    }

    // Safely remove superseded floor plan file after successful replacement
    if ($hasNewImage && !empty($previousFloorPlanPath)) {
        try {
            $floorplansDir = realpath(UPLOAD_PATH . 'floorplans');
            $oldClean = str_replace('\\', '/', $previousFloorPlanPath);
            if (str_starts_with($oldClean, 'uploads/floorplans/')) {
                $oldCand = __DIR__ . '/../' . $oldClean;
            } elseif (str_starts_with($oldClean, 'floorplans/')) {
                $oldCand = UPLOAD_PATH . $oldClean;
            } else {
                $oldCand = UPLOAD_PATH . 'floorplans/' . basename($oldClean);
            }

            $realOld = realpath($oldCand);
            $realNew = !empty($newlyCreatedDiskPath) ? realpath($newlyCreatedDiskPath) : null;

            if ($floorplansDir && $realOld && str_starts_with($realOld, $floorplansDir) && is_file($realOld)) {
                if (!$realNew || $realOld !== $realNew) {
                    $oldBase = basename($realOld);
                    // Confirm not referenced by any other floor_plans record
                    $refCheck = $db->prepare("SELECT COUNT(*) FROM floor_plans WHERE file_path LIKE ?");
                    $refCheck->execute(['%' . $oldBase]);
                    $refCount = (int)$refCheck->fetchColumn();
                    if ($refCount === 0) {
                        @unlink($realOld);
                    } else {
                        error_log("Floor plan cleanup skipped for activity {$id}: old file {$oldBase} is still referenced ({$refCount}).");
                    }
                }
            }
        } catch (\Throwable $cleanEx) {
            error_log("Floor plan cleanup skipped for activity {$id}: " . $cleanEx->getMessage());
        }
    }

    // Automatically generate and archive proposal PDF snapshot to OneDrive upon submission/resubmission
    if ($action === 'submit') {
        try {
            $trigger = ($currStatus === 'returned_for_revision') ? 'resubmitted' : 'submitted';
            syncProposalPdfSnapshot($id, $trigger, (int)$user['id']);
        } catch (\Throwable $snapEx) {
            error_log("Failed to create proposal snapshot on update: " . $snapEx->getMessage());
        }
    }

    // Persist verified AI validation record to database
    if ($newStatus === 'submitted' && !empty($cachedValidation)) {
        try {
            $mappedAi = $aiService->mapToDatabaseFormat($cachedValidation);
            saveProposalAiValidationResult($db, $id, $mappedAi);
        } catch (\Throwable $aiSaveEx) {
            error_log("Failed to persist AI validation for activity {$id}: " . $aiSaveEx->getMessage());
        }
    }

    // Send emails after successful commit so failure never impacts database transaction
    if (!empty($emailsToSend)) {
        try {
            $emailService = new EmailService();
            $proposalTitle = sanitize($_POST['title'] ?? '');
            foreach ($emailsToSend as $recipient) {
                $reviewLink = match($recipient['role']) {
                    'admin1' => BASE_URL . "/admin1/review.php?id=" . $id,
                    'dean'   => BASE_URL . "/dean/review.php?id=" . $id,
                    default  => BASE_URL . "/admin2/review.php?id=" . $id,
                };

                $emailService->sendNotification(
                    $recipient['email'],
                    $recipient['name'],
                    "Resubmitted Proposal: " . $proposalTitle,
                    $proposalTitle,
                    $recipient['status'],
                    "The activity proposal '" . $proposalTitle . "' has been revised and resubmitted by " . htmlspecialchars($user['name']) . " for your review.",
                    $reviewLink,
                    $id,
                    "proposal_resubmitted"
                );
            }
        } catch (\Throwable $mailEx) {
            error_log("Failed to dispatch resubmission email for activity {$id}: " . $mailEx->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Log Collaborative Contribution (Task 14) & Notifications (Task 18)
    // ══════════════════════════════════════════════════════════════════════════
    try {
        $contribSections = [];
        $changedSections = [];

        // Check each section for actual changes
        $detailsChanged = (
            $title !== trim($activity['title'] ?? '') ||
            $theme !== trim($activity['theme'] ?? '') ||
            $venue !== trim($activity['venue'] ?? '') ||
            $venueAddress !== trim($activity['venue_address'] ?? '') ||
            (int)$targetParticipants !== (int)($activity['target_participants'] ?? 0)
        );
        if (!empty($s1Editable)) {
            $contribSections[] = 'Event Details';
            if ($detailsChanged) $changedSections[] = 'Event Details';
        }

        $objChanged = (
            $generalObjectives !== trim($activity['general_objectives'] ?? '') ||
            $specificObjectives !== trim($activity['specific_objectives'] ?? '') ||
            $involvedSubjects !== trim($activity['involved_subjects'] ?? '') ||
            $rationale !== trim($activity['rationale'] ?? '')
        );
        if (!empty($s2Editable)) {
            $contribSections[] = 'Objectives';
            if ($objChanged) $changedSections[] = 'Objectives';
        }

        $materialsChanged = !empty($_POST['item_desc']) || !empty($_POST['materials_changed']);
        if (!empty($s3Editable)) {
            $contribSections[] = 'Materials & Budget';
            if ($materialsChanged) $changedSections[] = 'Materials & Budget';
        }

        $manpowerChanged = !empty($_POST['committee']) || !empty($_POST['manpower_changed']);
        if (!empty($s5Editable)) {
            $contribSections[] = 'Manpower & Committees';
            if ($manpowerChanged) $changedSections[] = 'Manpower & Committees';
        }

        $schedChanged = (
            (!empty($saveEventDate) && $saveEventDate !== ($activity['event_date'] ?? '')) ||
            (!empty($saveStartTime) && $saveStartTime !== ($activity['start_time'] ?? '')) ||
            (!empty($saveEndTime) && $saveEndTime !== ($activity['end_time'] ?? '')) ||
            !empty($_POST['schedule_rows']) || !empty($_POST['sched_date']) || !empty($_POST['prog_activity'])
        );
        if (!empty($s6Editable)) {
            $contribSections[] = 'Schedule & Program';
            if ($schedChanged) $changedSections[] = 'Schedule & Program';
        }

        $guidelinesChanged = (
            (isset($_POST['guidelines']) && trim($_POST['guidelines']) !== trim($activity['guidelines'] ?? '')) ||
            (isset($_POST['house_rules']) && trim($_POST['house_rules']) !== trim($activity['house_rules'] ?? ''))
        );
        if (!empty($s7Editable)) {
            $contribSections[] = 'Guidelines';
            if ($guidelinesChanged) $changedSections[] = 'Guidelines';
        }

        $tasksChanged = !empty($_POST['ft_title']) || !empty($_POST['tasks_changed']);
        if (!empty($s8Editable)) {
            $contribSections[] = 'Faculty Tasks';
            if ($tasksChanged) $changedSections[] = 'Faculty Tasks';
        }

        $kpiChanged = !empty($_POST['kpi_indicator']) || !empty($_POST['kpi_criteria']) || !empty($_POST['kpi_changed']);
        if (!empty($s9Editable)) {
            $contribSections[] = 'KPI & Evaluation';
            if ($kpiChanged) $changedSections[] = 'KPI & Evaluation';
        }

        $attachmentsChanged = (
            !empty($_FILES['poster_file']['name']) ||
            !empty($_FILES['floor_plan_file']['name']) ||
            !empty($_POST['floor_plan_image'])
        );
        if ($attachmentsChanged) {
            $contribSections[] = 'Supporting Documents & Attachments';
            $changedSections[] = 'Supporting Documents & Attachments';
        }

        $sectionSummary = !empty($contribSections) ? implode(', ', $contribSections) : 'Proposal Information';
        $changedSummary = !empty($changedSections) ? implode(', ', $changedSections) : $sectionSummary;

        $actionDesc = ($action === 'submit') ? 'submitted_proposal' : 'updated_draft';
        $detailsDesc = ($action === 'submit') 
            ? "Submitted proposal for approval ({$sectionSummary})" 
            : "Saved edits to {$changedSummary}";

        logProposalContribution($id, (int)$user['id'], $actionDesc, ($action === 'submit' ? $sectionSummary : $changedSummary), $detailsDesc, $db);

        // Collaborative Proposal Notifications (Task 18)
        if ($action === 'submit') {
            if (function_exists('dispatchCollaboratorNotification')) {
                dispatchCollaboratorNotification($id, (int)$user['id'], 'proposal_submitted', [
                    'proposal_title' => $title,
                    'status_label'   => 'Submitted for Review'
                ], $db);
            }
        } elseif (!empty($changedSections)) {
            if (function_exists('dispatchCollaboratorNotification')) {
                dispatchCollaboratorNotification($id, (int)$user['id'], 'proposal_updated', [
                    'proposal_title' => $title,
                    'sections'       => $changedSummary,
                    'description'    => $detailsDesc,
                    'date_time'      => date('Y-m-d H:i:s'),
                    'event_key'      => $_POST['event_key'] ?? ''
                ], $db);
            }
        }
    } catch (\Throwable $collabLogEx) {
        error_log("Failed to log proposal contribution or dispatch notifications: " . $collabLogEx->getMessage());
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'     => true,
            'activity_id' => $id,
            'status'      => $newStatus,
            'source'      => $source,
            'poster_path' => $savedPosterPath ?? ($activity['poster_path'] ?? null),
            'message'     => 'Proposal draft updated successfully.',
            'updated_at'  => date('Y-m-d H:i:s'),
            'title'       => $title
        ]);
        exit;
    }

    header("Location: ".BASE_URL."/faculty/proposal-view.php?id={$id}&updated=1");
    exit;
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // Clean up newly created orphaned floor plan file if transaction failed
    if (!empty($newlyCreatedDiskPath) && file_exists($newlyCreatedDiskPath)) {
        try {
            $floorplansDir = realpath(UPLOAD_PATH . 'floorplans');
            $realNew = realpath($newlyCreatedDiskPath);
            if ($floorplansDir && $realNew && str_starts_with($realNew, $floorplansDir) && is_file($realNew)) {
                $newBase = basename($realNew);
                $prevBase = !empty($previousFloorPlanPath) ? basename($previousFloorPlanPath) : null;
                // Never delete the previous floor plan file during rollback
                if ($newBase !== $prevBase) {
                    $refCheck = $db->prepare("SELECT COUNT(*) FROM floor_plans WHERE file_path LIKE ?");
                    $refCheck->execute(['%' . $newBase]);
                    if ((int)$refCheck->fetchColumn() === 0) {
                        @unlink($realNew);
                    }
                }
            }
        } catch (\Throwable $rollEx) {
            error_log("Failed to clean up orphaned floor plan on rollback: " . $rollEx->getMessage());
        }
    }
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Server error updating proposal: ' . $e->getMessage()
        ]);
        exit;
    }
    header("Location: ".BASE_URL."/faculty/proposal-edit.php?id={$id}&error=1");
    exit;
}
