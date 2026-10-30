<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';
require_once __DIR__ . '/../includes/ai/schedule_conflict.php';
requireRole('faculty');

$user   = currentUser();
$db     = getDB();
$id     = (int)($_POST['activity_id'] ?? 0);
$action = $_POST['action'] ?? 'draft';

// Verify ownership
$check = $db->prepare("SELECT * FROM activities WHERE id=? AND faculty_id=?");
$check->execute([$id, $user['id']]);
$activity = $check->fetch();
if (!$activity) { header('Location: '.BASE_URL.'/faculty/activities.php'); exit; }

// Fetch current status to prevent losing returned_for_revision state
$curr = $db->prepare("SELECT status FROM activities WHERE id=?");
$curr->execute([$id]);
$currStatus = $curr->fetchColumn();

if ($action === 'submit') {
    $newStatus = 'submitted';
    $clearNotes = true;
} else {
    // If it was returned for revision, keep that status when saving as draft
    $newStatus = ($currStatus === 'returned_for_revision') ? 'returned_for_revision' : 'draft';
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

// Check editability for section 1 (event_details)
$s1Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['event_details']);

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

// Server-side required-field and KPI validation for submissions
if ($action === 'submit') {
    $title              = isset($_POST['title']) && is_string($_POST['title']) ? trim($_POST['title']) : '';
    $theme              = isset($_POST['theme']) && is_string($_POST['theme']) ? trim($_POST['theme']) : '';
    $venue              = isset($_POST['venue']) && is_string($_POST['venue']) ? trim($_POST['venue']) : '';
    $targetParticipants = isset($_POST['target_participants']) ? trim((string)$_POST['target_participants']) : '';

    if (!$s1Editable) {
        if ($title === '' && !empty($activity['title'])) {
            $title = trim($activity['title']);
            $_POST['title'] = $title;
        }
        if ($theme === '' && !empty($activity['theme'])) {
            $theme = trim($activity['theme']);
            $_POST['theme'] = $theme;
        }
        if ($venue === '' && !empty($activity['venue'])) {
            $venue = trim($activity['venue']);
            $_POST['venue'] = $venue;
        }
        if (($targetParticipants === '' || !is_numeric($targetParticipants) || (int)$targetParticipants <= 0) && !empty($activity['target_participants'])) {
            $targetParticipants = (string)$activity['target_participants'];
            $_POST['target_participants'] = $targetParticipants;
        }
    }

    $generalObjectives  = isset($_POST['general_objectives']) && is_string($_POST['general_objectives']) ? trim($_POST['general_objectives']) : '';
    $specificObjectives = isset($_POST['specific_objectives']) && is_string($_POST['specific_objectives']) ? trim($_POST['specific_objectives']) : '';
    $rationale          = isset($_POST['rationale']) && is_string($_POST['rationale']) ? trim($_POST['rationale']) : '';

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

    // Server-side AI completeness & alignment validation gating
    require_once __DIR__ . '/../services/GeminiProposalValidationService.php';
    require_once __DIR__ . '/../includes/ai/proposal_validator.php';

    $aiService = new GeminiProposalValidationService(getGeminiApiKeySecure());
    $canonicalPayload = $aiService->getCanonicalProposalPayload($_POST);
    $currentHash = $aiService->calculateProposalHash($canonicalPayload);

    // Verify against server session cache (never trust client-supplied approval flags)
    if (!isset($_SESSION['last_ai_validation'][$currentHash])) {
        // Validation result does not correspond to current payload or was never performed
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=ai_validation_critical");
        exit;
    }

    $cachedValidation = $_SESSION['last_ai_validation'][$currentHash]['result'] ?? null;
    $hasCriticalAiIssues = false;
    if (is_array($cachedValidation) && !empty($cachedValidation['sections']) && is_array($cachedValidation['sections'])) {
        foreach ($cachedValidation['sections'] as $sec) {
            if (is_array($sec) && ($sec['status'] ?? '') === 'error') {
                $hasCriticalAiIssues = true;
                break;
            }
        }
    } else {
        $hasCriticalAiIssues = true;
    }

    if ($hasCriticalAiIssues) {
        header("Location: " . BASE_URL . "/faculty/proposal-edit.php?id={$id}&error=ai_validation_critical");
        exit;
    }
}

$source = sanitize($_POST['source'] ?? 'faculty');
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

    $hasAnyFloorPlan = $hasFpFile || $hasFpImage || $hasFpCanvas;
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
            sanitize($_POST['title']??''), sanitize($_POST['theme']??''), sanitize($_POST['venue']??''), sanitize($_POST['venue_address']??''),
            $saveEventDate, $saveStartTime, $saveEndTime,
            (int)($_POST['target_participants']??0),
            sanitize($_POST['general_objectives']??''), sanitize($_POST['specific_objectives']??''),
            sanitize($_POST['involved_subjects']??''), sanitize($_POST['rationale']??''),
            sanitize($_POST['evaluation_method'] ?? ($_POST['eval_form_link'] ?? '')),
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
            sanitize($_POST['title']??''), sanitize($_POST['theme']??''), sanitize($_POST['venue']??''), sanitize($_POST['venue_address']??''),
            $saveEventDate, $saveStartTime, $saveEndTime,
            (int)($_POST['target_participants']??0),
            sanitize($_POST['general_objectives']??''), sanitize($_POST['specific_objectives']??''),
            sanitize($_POST['involved_subjects']??''), sanitize($_POST['rationale']??''),
            sanitize($_POST['evaluation_method'] ?? ($_POST['eval_form_link'] ?? '')),
            $source, $newStatus,
            $evalQuestions,
            $fpRequiredInt,
            $id
        ]);
    }

    // Replace child tables
    $db->prepare("DELETE FROM materials WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['mat_item'])) {
        $ms = $db->prepare("INSERT INTO materials(activity_id,item_name,description,quantity,provider,est_cost)VALUES(?,?,?,?,?,?)");
        foreach ($_POST['mat_item'] as $i=>$name) {
            if (!trim($name)) continue;
            $ms->execute([$id,sanitize($name),sanitize($_POST['mat_desc'][$i]??''),(int)($_POST['mat_qty'][$i]??1),sanitize($_POST['mat_provider'][$i]??''),(float)($_POST['mat_cost'][$i]??0)]);
        }
    }

    $db->prepare("DELETE FROM program_sequence WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['prog_segment'])) {
        $ps = $db->prepare("INSERT INTO program_sequence(activity_id,time_slot,segment,description,person_ic,sort_order)VALUES(?,?,?,?,?,?)");
        foreach ($_POST['prog_segment'] as $i=>$seg) {
            if (!trim($seg)) continue;
            $ps->execute([$id,$_POST['prog_time'][$i]??null,sanitize($seg),sanitize($_POST['prog_desc'][$i]??''),sanitize($_POST['prog_pic'][$i]??''),$i]);
        }
    }

    $db->prepare("DELETE FROM manpower WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['mp_role'])) {
        $mp = $db->prepare("INSERT INTO manpower(activity_id,role,assigned_person,type)VALUES(?,?,?,?)");
        foreach ($_POST['mp_role'] as $i=>$role) {
            if (!trim($role)) continue;
            $mp->execute([$id,sanitize($role),sanitize($_POST['mp_person'][$i]??''),$_POST['mp_type'][$i]??'faculty']);
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

    // Guidelines upsert
    $db->prepare("DELETE FROM guidelines WHERE activity_id=?")->execute([$id]);
    $db->prepare("INSERT INTO guidelines(activity_id,mechanics,criteria,scoring_system,special_awards)VALUES(?,?,?,?,?)")->execute([
        $id,sanitize($_POST['guidelines_mechanics']??''),sanitize($_POST['guidelines_criteria']??''),
        sanitize($_POST['guidelines_scoring']??''),sanitize($_POST['guidelines_awards']??'')
    ]);

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

                    // Notify new assignee if task was newly assigned or reassigned to a different person
                    if ($assignedUserId && $assignedUserId !== $prevAssignee) {
                        sendTaskAssignmentNotification($db, $assignedUserId, $id, $tTitle, $dueDate);
                    }
                } else {
                    $insertTaskStmt->execute([
                        $id, $assignedUserId, $facultyName, $tTitle, $tTitle, $tDesc, $tDesc, $tRole, $dueDate, $user['id'] ?? null
                    ]);
                    if ($assignedUserId) {
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
    if (!empty($_FILES['poster_file']['name'])) {
        $posterPath = uploadFile($_FILES['poster_file'], 'posters', $id);
        if ($posterPath) {
            $db->prepare("UPDATE activities SET poster_path=? WHERE id=?")->execute([$posterPath, $id]);
        }
    }

    // KPIs
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

        if ($lastReturn) {
            if ($lastReturn['role'] === 'admin1') {
                $nextStatus = 'under_review';
                $notifyRole = 'admin1';
            } elseif ($lastReturn['role'] === 'dean') {
                $nextStatus = 'pending_final_approval';
                $notifyRole = 'dean';
            } else {
                $nextStatus = 'submitted'; // Ian catches 'submitted'
                $notifyRole = 'admin2';
            }
        } else {
            $nextStatus = ($source === 'student_org') ? 'under_review' : 'submitted';
            $notifyRole = ($source === 'student_org') ? 'admin1' : 'admin2';
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

    $db->commit();

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
    header("Location: ".BASE_URL."/faculty/proposal-edit.php?id={$id}&error=1");
    exit;
}
