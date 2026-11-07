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
$action = $_POST['action'] ?? 'draft';
$isSendToCollaborators = ($action === 'send_to_collaborators' || ($_POST['send_to_collaborators'] ?? '') === '1');
$status = ($action === 'submit') ? 'submitted' : 'draft';

$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
    (isset($_POST['is_ajax_sync']) && $_POST['is_ajax_sync'] === '1')
);

// Resolve session workspace token and staged collaborators
$sessionToken = trim($_POST['proposal_session_token'] ?? '');
$stagedCollabs = (!empty($sessionToken) && isset($_SESSION['temp_collaborators'][$sessionToken]) && is_array($_SESSION['temp_collaborators'][$sessionToken]))
    ? $_SESSION['temp_collaborators'][$sessionToken]
    : [];

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

// Validation when owner clicks "Send to Collaborators"
if ($isSendToCollaborators) {
    $title = trim($_POST['title'] ?? '');
    if ($title === '') {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Activity Title is required before sharing with collaborators.']);
            exit;
        }
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=missing_title");
        exit;
    }

    if (empty($stagedCollabs)) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'No collaborators have been added to share with.']);
            exit;
        }
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=no_collaborators");
        exit;
    }
}

// Duplicate prevention for offline synchronization retries and inline draft-to-submit transitions:
// If an activity_id is already present and exists in the database, delegate to proposal-update.php
$existingActivityId = (int)($_POST['activity_id'] ?? 0);
if ($existingActivityId > 0) {
    $chkExisting = $db->prepare("SELECT id FROM activities WHERE id = ?");
    $chkExisting->execute([$existingActivityId]);
    if ($chkExisting->fetch()) {
        require __DIR__ . '/proposal-update.php';
        exit;
    }
}

// Authoritative server-side date validation (Asia/Manila)
$todayManila = date('Y-m-d');
$eventDate   = trim($_POST['event_date'] ?? '');

if ($action === 'submit' && empty($eventDate)) {
    header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=missing_date");
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
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=past_date");
        exit;
    }
}

if (!empty($_POST['sched_date']) && is_array($_POST['sched_date'])) {
    foreach ($_POST['sched_date'] as $sDate) {
        $sDate = trim($sDate);
        if (!empty($sDate)) {
            $sd = DateTime::createFromFormat('Y-m-d', $sDate);
            if (!$sd || $sd->format('Y-m-d') !== $sDate || $sDate <= $todayManila) {
                header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=past_date");
                exit;
            }
        }
    }
}

// Authoritative server-side schedule time validation
$startTime = isset($_POST['start_time']) && is_string($_POST['start_time']) ? trim($_POST['start_time']) : '';
$endTime   = isset($_POST['end_time']) && is_string($_POST['end_time']) ? trim($_POST['end_time']) : '';

if ($action === 'submit') {
    $timeCheck = validateScheduleTimeRange($startTime, $endTime, true);
    if (!$timeCheck['valid']) {
        $errCode = ($timeCheck['code'] === 'invalid_order') ? 'invalid_time' : 'missing_time';
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error={$errCode}");
        exit;
    }

    // Authoritative server-side schedule conflict check for submitted proposals
    $venue = isset($_POST['venue']) && is_string($_POST['venue']) ? trim($_POST['venue']) : '';
    if (!empty($venue) && !empty($eventDate) && !empty($startTime) && !empty($endTime)) {
        $conflictCheck = checkScheduleConflict($venue, $eventDate, $startTime, $endTime);
        if (!empty($conflictCheck['conflict'])) {
            header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=conflict");
            exit;
        }
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
            header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=invalid_time");
            exit;
        }
    }
}

if (!empty($_POST['schedule_rows']) && is_array($_POST['schedule_rows'])) {
    $rowsCheck = validateScheduleRows($_POST['schedule_rows'], ($action === 'submit'));
    if (!$rowsCheck['valid']) {
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=invalid_time");
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
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=invalid_poster_format");
        exit;
    }
}

if (!empty($_FILES['floor_plan_file']['name'])) {
    if (!isValidFloorPlanFile($_FILES['floor_plan_file'])) {
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=invalid_floor_plan_format");
        exit;
    }
}

// Server-side required-field and KPI validation for submissions
if ($action === 'submit') {
    $title              = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
    $theme              = isset($_POST['theme']) && is_string($_POST['theme']) ? trim($_POST['theme']) : '';
    $venue              = isset($_POST['venue']) && is_string($_POST['venue']) ? trim($_POST['venue']) : '';
    $generalObjectives  = isset($_POST['general_objectives']) && is_string($_POST['general_objectives']) ? trim($_POST['general_objectives']) : '';
    $specificObjectives = isset($_POST['specific_objectives']) && is_string($_POST['specific_objectives']) ? trim($_POST['specific_objectives']) : '';
    $rationale          = isset($_POST['rationale']) && is_string($_POST['rationale']) ? trim($_POST['rationale']) : '';
    $targetParticipants = isset($_POST['target_participants']) ? trim((string)$_POST['target_participants']) : '';
    $partCounts = $_POST['part_count'] ?? $_POST['participant_count'] ?? [];
    if (is_array($partCounts) && !empty($partCounts)) {
        $sumParticipants = 0;
        foreach ($partCounts as $pc) {
            $sumParticipants += max(0, (int)$pc);
        }
        if ($sumParticipants > 0) {
            $targetParticipants = (string)$sumParticipants;
            $_POST['target_participants'] = $sumParticipants;
        }
    }

    if (
        $title === '' ||
        $theme === '' ||
        $venue === '' ||
        $generalObjectives === '' ||
        $specificObjectives === '' ||
        $rationale === '' ||
        $targetParticipants === '' ||
        !is_numeric($targetParticipants) ||
        (int)$targetParticipants <= 0
    ) {
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=missing_fields");
        exit;
    }

    // Authoritative server-side gibberish detection for submissions
    require_once __DIR__ . '/../includes/gibberish_detector.php';
    $gibberishCheck = validateProposalGibberish($_POST, true);
    if (!$gibberishCheck['valid']) {
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=invalid_text_input");
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
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=missing_kpi");
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
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=validation_incomplete");
        exit;
    }
}

$saveEventDate = ($eventDate !== '') ? $eventDate : null;
$saveStartTime = ($startTime !== '') ? $startTime : null;
$saveEndTime   = ($endTime !== '') ? $endTime : null;

// Server-side validation of floor plan requirement choice
$rawFpReq = $_POST['floor_plan_required'] ?? 'yes';
$floorPlanRequired = true; // default Yes for existing behavior & backward compatibility
if (is_string($rawFpReq)) {
    $rawFpReqLower = strtolower(trim($rawFpReq));
    if ($rawFpReqLower === 'no' || $rawFpReqLower === '0' || $rawFpReqLower === 'false') {
        $floorPlanRequired = false;
    } elseif ($rawFpReqLower === 'yes' || $rawFpReqLower === '1' || $rawFpReqLower === 'true') {
        $floorPlanRequired = true;
    }
}
$fpRequiredInt = $floorPlanRequired ? 1 : 0;

// Authoritative server-side floor plan validation when required for submission
if ($action === 'submit' && $floorPlanRequired) {
    $fpNotes = trim($_POST['floor_plan_notes'] ?? '');
    $hasFpFile = !empty($_FILES['floor_plan_file']['name']);
    $hasFpImage = !empty($_POST['floor_plan_image']);
    $hasFpCanvas = false;
    if (!empty($_POST['floor_plan_json'])) {
        $parsedFp = json_decode($_POST['floor_plan_json'], true);
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
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=missing_fields");
        exit;
    }

    // When using the integrated creator (and no file uploaded), notes/description is strictly required
    if (!$hasFpFile && ($hasFpImage || $hasFpCanvas) && $fpNotes === '') {
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=missing_fields");
        exit;
    }
}

// Server-side validation for test scores and actual attendees
if (!empty($_POST['part_pre_test']) && is_array($_POST['part_pre_test'])) {
    foreach ($_POST['part_pre_test'] as $score) {
        if ($score !== '' && $score !== null) {
            if (!is_numeric($score) || (float)$score < 0 || (float)$score > 999.99) {
                header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=invalid_score");
                exit;
            }
        }
    }
}
if (!empty($_POST['part_post_test']) && is_array($_POST['part_post_test'])) {
    foreach ($_POST['part_post_test'] as $score) {
        if ($score !== '' && $score !== null) {
            if (!is_numeric($score) || (float)$score < 0 || (float)$score > 999.99) {
                header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=invalid_score");
                exit;
            }
        }
    }
}
if (!empty($_POST['part_actual_attendees']) && is_array($_POST['part_actual_attendees'])) {
    foreach ($_POST['part_actual_attendees'] as $att) {
        if ($att !== '' && $att !== null) {
            if (!is_numeric($att) || (int)$att < 0) {
                header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=invalid_score");
                exit;
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

    $colInfo = $db->query("SHOW COLUMNS FROM activity_participants LIKE 'teacher_name'")->fetch(PDO::FETCH_ASSOC);
    if ($colInfo && stripos($colInfo['Type'], 'varchar') !== false) {
        $db->exec("ALTER TABLE activity_participants MODIFY COLUMN teacher_name TEXT NOT NULL");
    }
} catch (\Throwable $tblEx) {
    error_log("activity_participants table check notice: " . $tblEx->getMessage());
}

$newlyCreatedDiskPath = null;

try {
    $db->beginTransaction();

    // 1. Insert activity
    $stmt = $db->prepare("INSERT INTO activities
        (faculty_id,title,description,theme,venue,venue_address,event_date,start_time,end_time,
         target_participants,general_objectives,specific_objectives,involved_subjects,rationale,
         evaluation_method,source,status,submitted_at,evaluation_questions)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

        $saveTargetParticipants = (int)($_POST['target_participants'] ?? 0);
        $partCounts = $_POST['part_count'] ?? $_POST['participant_count'] ?? [];
        if (is_array($partCounts) && !empty($partCounts)) {
            $sumParticipants = 0;
            foreach ($partCounts as $pc) {
                $sumParticipants += max(0, (int)$pc);
            }
            if ($sumParticipants > 0 || $saveTargetParticipants <= 0) {
                $saveTargetParticipants = $sumParticipants;
            }
        }

        $rawSource = trim($_POST['source'] ?? '');
        $saveSource = in_array($rawSource, ['student_org', 'faculty'], true) ? $rawSource : 'faculty';

        $stmt->execute([
            $user['id'],
            sanitize($_POST['title'] ?? ''),
            sanitize($_POST['description'] ?? ''),
            sanitize($_POST['theme'] ?? ''),
            sanitize($_POST['venue'] ?? ''),
            sanitize($_POST['venue_address'] ?? ''),
            $saveEventDate,
            $saveStartTime,
            $saveEndTime,
            $saveTargetParticipants,
            sanitize($_POST['general_objectives'] ?? ''),
            sanitize($_POST['specific_objectives'] ?? ''),
            sanitize($_POST['involved_subjects'] ?? ''),
            sanitize($_POST['rationale'] ?? ''),
            sanitize($_POST['evaluation_method'] ?? ($_POST['eval_form_link'] ?? '')),
            $saveSource,
            $status,
            ($status === 'submitted') ? date('Y-m-d H:i:s') : null,
            $_POST['evaluation_questions'] ?? null,
        ]);
        $actId = $db->lastInsertId();

        // 1b. Target Participants (dynamic sections)
        $sections = $_POST['part_section'] ?? $_POST['participant_section'] ?? [];
        $counts   = $_POST['part_count'] ?? $_POST['participant_count'] ?? [];
        $teachers = $_POST['part_teacher'] ?? $_POST['participant_teacher'] ?? [];
        $actuals  = $_POST['part_actual_attendees'] ?? [];
        $preTests = $_POST['part_pre_test'] ?? [];
        $postTests= $_POST['part_post_test'] ?? [];

        if (!empty($sections) || !empty($counts)) {
            try {
                $colCheck = $db->query("SHOW COLUMNS FROM activity_participants LIKE 'actual_attendees'")->fetch();
                $hasKpiCols = (bool)$colCheck;

                if ($hasKpiCols) {
                    $pStmt = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, actual_attendees, pre_test_score, post_test_score, learning_kpi_result, teacher_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                } else {
                    $pStmt = $db->prepare("INSERT INTO activity_participants (activity_id, row_no, subject_section, participants_count, teacher_name) VALUES (?, ?, ?, ?, ?)");
                }

                $rowNo = 1;
                foreach ($sections as $i => $sec) {
                    $secText = sanitize($sec ?? '');
                    $cntNum  = max(0, (int)($counts[$i] ?? 0));
                    $rawTeacher = $teachers[$i] ?? '';
                    if (is_array($rawTeacher)) {
                        $tName = implode(', ', array_filter(array_map('trim', array_map('sanitize', $rawTeacher))));
                    } else {
                        $tName = sanitize((string)$rawTeacher);
                    }
                    $actVal  = (isset($actuals[$i]) && $actuals[$i] !== '' && is_numeric($actuals[$i])) ? max(0, (int)$actuals[$i]) : null;
                    $preVal  = (isset($preTests[$i]) && $preTests[$i] !== '' && is_numeric($preTests[$i])) ? (float)$preTests[$i] : null;
                    $postVal = (isset($postTests[$i]) && $postTests[$i] !== '' && is_numeric($postTests[$i])) ? (float)$postTests[$i] : null;
                    $kpiOutcome = null;
                    if ($preVal !== null && $postVal !== null) {
                        $diff = $postVal - $preVal;
                        $kpiOutcome = ($diff >= 0 ? '+' : '') . number_format($diff, 1) . ' pts';
                    }

                    if ($secText !== '' || $cntNum > 0 || $tName !== '' || $actVal !== null || $preVal !== null || $postVal !== null) {
                        if ($hasKpiCols) {
                            $pStmt->execute([$actId, $rowNo++, $secText, $cntNum, $actVal, $preVal, $postVal, $kpiOutcome, $tName]);
                        } else {
                            $pStmt->execute([$actId, $rowNo++, $secText, $cntNum, $tName]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("Error saving activity_participants in proposal-save: " . $e->getMessage());
            }
        }

    // Safely persist floor plan requirement if the schema column exists
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM activities LIKE 'floor_plan_required'")->fetch();
        if ($colCheck) {
            $db->prepare("UPDATE activities SET floor_plan_required = ? WHERE id = ?")->execute([$fpRequiredInt, $actId]);
        }
    } catch (\Throwable $e) {
        // Schema migration pending; handled gracefully
    }

    // 2. Materials
    if (!empty($_POST['mat_item'])) {
        $ms = $db->prepare("INSERT INTO materials (activity_id,item_name,description,quantity,provider,est_cost) VALUES (?,?,?,?,?,?)");
        foreach ($_POST['mat_item'] as $i => $name) {
            if (!trim($name)) continue;
            $ms->execute([$actId, sanitize($name), sanitize($_POST['mat_desc'][$i]??''),
                (int)($_POST['mat_qty'][$i]??1), sanitize($_POST['mat_provider'][$i]??''),
                (float)($_POST['mat_cost'][$i]??0)]);
        }
    }

    // 3. Program
    if (!empty($_POST['prog_segment'])) {
        $ps = $db->prepare("INSERT INTO program_sequence (activity_id,time_slot,segment,description,person_ic,sort_order) VALUES (?,?,?,?,?,?)");
        foreach ($_POST['prog_segment'] as $i => $seg) {
            if (!trim($seg)) continue;
            $ps->execute([$actId, $_POST['prog_time'][$i]??null, sanitize($seg),
                sanitize($_POST['prog_desc'][$i]??''), sanitize($_POST['prog_pic'][$i]??''), $i]);
        }
    }

    // 4. Manpower
    if (!empty($_POST['mp_role'])) {
        $mp = $db->prepare("INSERT INTO manpower (activity_id,role,assigned_person,type) VALUES (?,?,?,?)");
        foreach ($_POST['mp_role'] as $i => $role) {
            if (!trim($role)) continue;
            $rawMpType = strtolower(trim((string)($_POST['mp_type'][$i] ?? 'faculty')));
            $normMpType = in_array($rawMpType, ['faculty', 'staff', 'student'], true) ? $rawMpType : 'faculty';
            $mp->execute([$actId, sanitize($role), sanitize($_POST['mp_person'][$i]??''), $normMpType]);
        }
    }

    // 5. Schedule
    if (!empty($_POST['sched_event'])) {
        $sc = $db->prepare("INSERT INTO schedules (activity_id,sched_date,event_name,venue,organizer) VALUES (?,?,?,?,?)");
        foreach ($_POST['sched_event'] as $i => $ev) {
            if (!trim($ev)) continue;
            $sc->execute([$actId, $_POST['sched_date'][$i]??null, sanitize($ev),
                sanitize($_POST['sched_venue'][$i]??''), sanitize($_POST['sched_organizer'][$i]??'')]);
        }
    }

    // 6. Guidelines
    $gl = $db->prepare("INSERT INTO guidelines (activity_id,mechanics,criteria,scoring_system,special_awards) VALUES (?,?,?,?,?)");
    $gl->execute([$actId, sanitize($_POST['guidelines_mechanics']??''), sanitize($_POST['guidelines_criteria']??''),
        sanitize($_POST['guidelines_scoring']??''), sanitize($_POST['guidelines_awards']??'')]);

    // 7. Faculty Tasks
    $taskItems = !empty($_POST['ft_task']) && is_array($_POST['ft_task'])
        ? $_POST['ft_task']
        : (!empty($_POST['ft_name']) && is_array($_POST['ft_name']) ? $_POST['ft_name'] : []);

    if (!empty($taskItems)) {
        $ft = $db->prepare("
            INSERT INTO faculty_tasks (
                activity_id, assigned_user_id, faculty_name, assigned_task,
                task_title, contribution_desc, task_description, role_in_event,
                due_date, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Not Started', ?)
        ");

        $facultyLookupStmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role IN ('faculty', 'program_head') LIMIT 1");

        foreach ($taskItems as $i => $itemVal) {
            $tTitle = sanitize($_POST['ft_task'][$i] ?? '');
            $rawUserId = isset($_POST['ft_user_id'][$i]) && is_numeric($_POST['ft_user_id'][$i]) ? (int)$_POST['ft_user_id'][$i] : 0;
            $submittedName = sanitize($_POST['ft_name'][$i] ?? '');

            // Skip row if task title, submitted name, and user ID are all completely empty
            if ($tTitle === '' && $submittedName === '' && $rawUserId === 0) {
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
                    if ($isAjax) {
                        http_response_code(422);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'error_code' => 'invalid_faculty_assignment', 'error' => 'Invalid faculty member assigned.']);
                        exit;
                    }
                    header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=invalid_faculty_assignment");
                    exit;
                }
            }

            $tDesc = sanitize($_POST['ft_contribution'][$i] ?? '');
            $tRole = sanitize($_POST['ft_role'][$i] ?? '');

            // Validate optional due date (YYYY-MM-DD)
            $dueDate = null;
            if (!empty($_POST['ft_due_date'][$i])) {
                $rawDate = trim($_POST['ft_due_date'][$i]);
                $dObj = DateTime::createFromFormat('Y-m-d', $rawDate);
                if ($dObj && $dObj->format('Y-m-d') === $rawDate) {
                    $dueDate = $rawDate;
                }
            }

            $ft->execute([
                $actId,
                $assignedUserId,
                $facultyName,
                $tTitle,
                $tTitle,
                $tDesc,
                $tDesc,
                $tRole,
                $dueDate,
                $user['id'] ?? null
            ]);

            if ($assignedUserId && $status !== 'draft') {
                sendTaskAssignmentNotification($db, $assignedUserId, $actId, $tTitle, $dueDate);
            }
        }
    }

    // 8. Floor Plan upload
    $path = null;
    if ($floorPlanRequired || !empty($_FILES['floor_plan_file']['name']) || !empty($_POST['floor_plan_image'])) {
        if (!empty($_FILES['floor_plan_file']['name'])) {
            $path = uploadFile($_FILES['floor_plan_file'], 'floorplans', $actId);
            if ($path === false) {
                $db->rollBack();
                header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=upload_failed");
                exit;
            }
            $newlyCreatedDiskPath = UPLOAD_PATH . $path;
        } elseif (!empty($_POST['floor_plan_image'])) {
            // Base64 image
            $data = $_POST['floor_plan_image'];
            list($type, $data) = explode(';', $data);
            list(, $data)      = explode(',', $data);
            $data = base64_decode($data);
            $filename = 'fp_' . $actId . '_' . time() . '.png';
            $uploadDir = __DIR__ . '/../uploads/floorplans/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            file_put_contents($uploadDir . $filename, $data);
            $newlyCreatedDiskPath = $uploadDir . $filename;
            $path = 'uploads/floorplans/' . $filename;
        }
    }
    
    // Only insert a floor_plans record if a floor plan is required or if actual content was provided
    $hasCanvasShapes = false;
    if (!empty($_POST['floor_plan_json'])) {
        $cj = json_decode($_POST['floor_plan_json'], true);
        if (is_array($cj)) {
            if (!empty($cj['shapes']) && is_array($cj['shapes']) && count($cj['shapes']) > 0) {
                $hasCanvasShapes = true;
            } elseif (!empty($cj['floors']) && is_array($cj['floors'])) {
                foreach ($cj['floors'] as $fl) {
                    if (is_array($fl) && !empty($fl['shapes']) && is_array($fl['shapes']) && count($fl['shapes']) > 0) {
                        $hasCanvasShapes = true;
                        break;
                    }
                }
            }
        }
    }
    $hasFpNotes = trim($_POST['floor_plan_notes'] ?? '') !== '';

    if ($path || $hasCanvasShapes || ($floorPlanRequired && ($hasFpNotes || !empty($_POST['floor_plan_json'])))) {
        $fp = $db->prepare("INSERT INTO floor_plans (activity_id,file_path,notes,canvas_json) VALUES (?,?,?,?)");
        $fp->execute([$actId, $path, sanitize($_POST['floor_plan_notes']??''), $_POST['floor_plan_json'] ?? null]);
    }

    // 8.5 Poster upload
    $savedPosterPath = null;
    if (!empty($_FILES['poster_file']['name'])) {
        $posterPath = uploadFile($_FILES['poster_file'], 'posters', $actId);
        if ($posterPath) {
            $db->prepare("UPDATE activities SET poster_path=? WHERE id=?")->execute([$posterPath, $actId]);
            $savedPosterPath = $posterPath;
        }
    }

    // 9. KPIs
    if (!empty($_POST['kpi_indicator'])) {
        $kpi = $db->prepare("INSERT INTO kpi_evaluations (activity_id, indicator, target_metric, evaluation_method) VALUES (?,?,?,?)");
        foreach ($_POST['kpi_indicator'] as $i => $ind) {
            if (!trim($ind)) continue;
            $kpi->execute([
                $actId, 
                sanitize($ind), 
                sanitize($_POST['kpi_target'][$i] ?? ''), 
                sanitize($_POST['kpi_method'][$i] ?? '')
            ]);
        }
    } elseif (!empty($_POST['kpi_criteria'])) {
        $kpi = $db->prepare("INSERT INTO kpi_evaluations (activity_id, indicator, target_metric, evaluation_method) VALUES (?,?,?,?)");
        foreach ($_POST['kpi_criteria'] as $i => $crit) {
            if (!trim($crit)) continue;
            $target = isset($_POST['kpi_rating'][$i]) ? ('Rating: ' . $_POST['kpi_rating'][$i]) : '';
            $kpi->execute([
                $actId,
                sanitize($crit),
                sanitize($target),
                'Student Evaluation'
            ]);
        }
    }

    // 9. Routing logic
    $emailsToSend = [];
    if ($status === 'submitted') {
        $source = $_POST['source'] ?? 'faculty';
        $nextStatus = ($source === 'student_org') ? 'under_review' : 'submitted';
        $db->prepare("UPDATE activities SET status=? WHERE id=?")->execute([$nextStatus, $actId]);

        // Notify appropriate admin: student_org goes to admin1, faculty goes straight to admin2
        $notifyRole = ($source === 'student_org') ? 'admin1' : 'admin2';
        $admins = $db->prepare("SELECT id, email, name FROM users WHERE role=?");
        $admins->execute([$notifyRole]);
        $adminRows = $admins->fetchAll();
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id,activity_id,message) VALUES (?,?,?)");
        $proposalTitle = sanitize($_POST['title'] ?? '');
        foreach ($adminRows as $admin) {
            $notifStmt->execute([$admin['id'], $actId, "New activity proposal submitted: " . $proposalTitle]);
            if (!empty($admin['email'])) {
                $emailsToSend[] = [
                    'email'  => $admin['email'],
                    'name'   => $admin['name'] ?? 'Administrator',
                    'role'   => $notifyRole,
                    'status' => 'Under Review',
                ];
            }
        }
    }

    if ($db->inTransaction()) {
        $db->commit();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Collaborative Setup: Register Owner and Log Initial Creation (Task 14)
    // ══════════════════════════════════════════════════════════════════════════
    try {
        $ownerCollab = $db->prepare("
            INSERT INTO proposal_collaborators (activity_id, user_id, role, added_by, status)
            VALUES (?, ?, 'owner', ?, 'active')
            ON DUPLICATE KEY UPDATE role = 'owner', status = 'active'
        ");
        $ownerCollab->execute([$actId, (int)$user['id'], (int)$user['id']]);

        $actionDesc = ($status === 'submitted') ? 'submitted_proposal' : 'created_proposal';
        $detailsDesc = ($status === 'submitted') ? 'Created and submitted proposal for approval' : 'Created initial activity proposal draft';
        logProposalContribution($actId, (int)$user['id'], $actionDesc, 'Event Details', $detailsDesc, $db);

        // If explicitly sent to collaborators by owner, attach staged collaborators and dispatch notifications
        if ($isSendToCollaborators && !empty($stagedCollabs)) {
            foreach ($stagedCollabs as $collab) {
                $targetUserId = (int)$collab['user_id'];
                $collabRole   = $collab['role'] ?? 'contributor';
                if ($targetUserId > 0 && $targetUserId !== (int)$user['id']) {
                    addProposalCollaborator($actId, $targetUserId, $collabRole, (int)$user['id'], $db);
                }
            }
            logProposalContribution(
                $actId,
                (int)$user['id'],
                'shared_with_collaborators',
                'Collaboration',
                'Owner shared proposal draft with collaborators (' . count($stagedCollabs) . ' member(s))',
                $db
            );
        }

        // Safely clear session-based temporary collaborators
        if (!empty($sessionToken)) {
            unset($_SESSION['temp_collaborators'][$sessionToken]);
        }
        unset($_SESSION['proposal_create_token']);
    } catch (\Throwable $collabEx) {
        error_log("Collaborator registration notice: " . $collabEx->getMessage());
    }

    // Automatically generate and archive proposal PDF snapshot to OneDrive upon submission
    if ($status === 'submitted') {
        try {
            syncProposalPdfSnapshot($actId, 'submitted', (int)$user['id']);
        } catch (\Throwable $snapEx) {
            error_log("Failed to create proposal snapshot on save: " . $snapEx->getMessage());
        }
    }

    // Persist verified AI validation record to database
    if ($status === 'submitted' && !empty($cachedValidation) && isset($aiService) && is_object($aiService) && function_exists('saveProposalAiValidationResult')) {
        try {
            $mappedAi = $aiService->mapToDatabaseFormat($cachedValidation);
            saveProposalAiValidationResult($db, $actId, $mappedAi);
        } catch (\Throwable $aiSaveEx) {
            error_log("Failed to persist AI validation for activity {$actId}: " . $aiSaveEx->getMessage());
        }
    }

    // Send emails after successful commit so failure never impacts database transaction
    if (!empty($emailsToSend)) {
        try {
            $emailService = new EmailService();
            $proposalTitle = sanitize($_POST['title'] ?? '');
            foreach ($emailsToSend as $recipient) {
                $reviewLink = ($recipient['role'] === 'admin1')
                    ? BASE_URL . "/admin1/review.php?id=" . $actId
                    : BASE_URL . "/admin2/review.php?id=" . $actId;

                $emailService->sendNotification(
                    $recipient['email'],
                    $recipient['name'],
                    "New Proposal Submitted: " . $proposalTitle,
                    $proposalTitle,
                    $recipient['status'],
                    "A new activity proposal has been submitted by " . htmlspecialchars($user['name']) . " and is awaiting your review.",
                    $reviewLink,
                    $actId,
                    "proposal_submitted"
                );
            }
        } catch (\Throwable $mailEx) {
            error_log("Failed to dispatch submission email for activity {$actId}: " . $mailEx->getMessage());
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'     => true,
            'activity_id' => $actId,
            'source'      => $saveSource,
            'poster_path' => $savedPosterPath,
            'status'      => $status,
            'shared'      => $isSendToCollaborators,
            'message'     => $isSendToCollaborators
                ? 'Proposal draft saved and shared with collaborators successfully.'
                : 'Proposal draft saved successfully.',
            'redirect'    => $isSendToCollaborators
                ? BASE_URL . "/faculty/proposal-edit.php?id={$actId}&shared=1"
                : ((($user['role'] ?? '') === 'program_head') ? BASE_URL . "/program_head/dashboard.php?saved=1" : BASE_URL . "/faculty/dashboard.php?saved=1"),
            'updated_at'  => date('Y-m-d H:i:s'),
            'title'       => sanitize($_POST['title'] ?? '')
        ]);
        exit;
    }

    if ($isSendToCollaborators) {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$actId}&shared=1");
        exit;
    }

    $targetDash = (($user['role'] ?? '') === 'program_head')
        ? BASE_URL . "/program_head/dashboard.php?saved=1"
        : BASE_URL . "/faculty/dashboard.php?saved=1";
    header("Location: " . $targetDash);
    exit;

} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // Clean up newly created orphaned floor plan file if creation transaction failed
    if (!empty($newlyCreatedDiskPath) && file_exists($newlyCreatedDiskPath)) {
        try {
            $floorplansDir = realpath(UPLOAD_PATH . 'floorplans');
            $realNew = realpath($newlyCreatedDiskPath);
            if ($floorplansDir && $realNew && str_starts_with($realNew, $floorplansDir) && is_file($realNew)) {
                $newBase = basename($realNew);
                $refCheck = $db->prepare("SELECT COUNT(*) FROM floor_plans WHERE file_path LIKE ?");
                $refCheck->execute(['%' . $newBase]);
                if ((int)$refCheck->fetchColumn() === 0) {
                    @unlink($realNew);
                }
            }
        } catch (\Throwable $rollEx) {
            error_log("Failed to clean up orphaned floor plan on creation rollback: " . $rollEx->getMessage());
        }
    }
    error_log("proposal-save exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    if ($isAjax) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Server error saving proposal: ' . $e->getMessage()
        ]);
        exit;
    }
    header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=1");
    exit;
}
