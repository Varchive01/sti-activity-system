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

try {
    $db->beginTransaction();

    if ($clearNotes) {
        $db->prepare("UPDATE activities SET
            title=?,theme=?,venue=?,venue_address=?,event_date=?,start_time=?,end_time=?,
            target_participants=?,general_objectives=?,specific_objectives=?,
            involved_subjects=?,rationale=?,evaluation_method=?,source=?,
            status=?,evaluation_questions=?,revision_notes=NULL,revision_sections=NULL,submitted_at=?,updated_at=NOW()
            WHERE id=?")->execute([
            sanitize($_POST['title']??''), sanitize($_POST['theme']??''), sanitize($_POST['venue']??''), sanitize($_POST['venue_address']??''),
            $saveEventDate, $saveStartTime, $saveEndTime,
            (int)($_POST['target_participants']??0),
            sanitize($_POST['general_objectives']??''), sanitize($_POST['specific_objectives']??''),
            sanitize($_POST['involved_subjects']??''), sanitize($_POST['rationale']??''),
            sanitize($_POST['evaluation_method'] ?? ($_POST['eval_form_link'] ?? '')),
            $source, $newStatus,
            $evalQuestions,
            date('Y-m-d H:i:s'), $id
        ]);
    } else {
        $db->prepare("UPDATE activities SET
            title=?,theme=?,venue=?,venue_address=?,event_date=?,start_time=?,end_time=?,
            target_participants=?,general_objectives=?,specific_objectives=?,
            involved_subjects=?,rationale=?,evaluation_method=?,source=?,
            status=?,evaluation_questions=?,updated_at=NOW()
            WHERE id=?")->execute([
            sanitize($_POST['title']??''), sanitize($_POST['theme']??''), sanitize($_POST['venue']??''), sanitize($_POST['venue_address']??''),
            $saveEventDate, $saveStartTime, $saveEndTime,
            (int)($_POST['target_participants']??0),
            sanitize($_POST['general_objectives']??''), sanitize($_POST['specific_objectives']??''),
            sanitize($_POST['involved_subjects']??''), sanitize($_POST['rationale']??''),
            sanitize($_POST['evaluation_method'] ?? ($_POST['eval_form_link'] ?? '')),
            $source, $newStatus,
            $evalQuestions,
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

    $db->prepare("DELETE FROM faculty_tasks WHERE activity_id=?")->execute([$id]);
    if (!empty($_POST['ft_name'])) {
        $ft = $db->prepare("INSERT INTO faculty_tasks(activity_id,faculty_name,assigned_task,task_title,contribution_desc,task_description,role_in_event,created_by)VALUES(?,?,?,?,?,?,?,?)");
        foreach ($_POST['ft_name'] as $i=>$name) {
            if (!trim($name)) continue;
            $tTitle = sanitize($_POST['ft_task'][$i]??'');
            $tDesc = sanitize($_POST['ft_contribution'][$i]??'');
            $ft->execute([$id,sanitize($name),$tTitle,$tTitle,$tDesc,$tDesc,sanitize($_POST['ft_role'][$i]??''),$user['id']??null]);
        }
    }

    // Floor plan update
    $path = null;
    $hasNewImage = false;
    if (!empty($_FILES['floor_plan_file']['name'])) {
        $path = uploadFile($_FILES['floor_plan_file'], 'floorplans', $id);
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
        $path = 'uploads/floorplans/' . $filename;
        $hasNewImage = true;
    }

    if ($hasNewImage) {
        $db->prepare("DELETE FROM floor_plans WHERE activity_id=?")->execute([$id]);
        $fp = $db->prepare("INSERT INTO floor_plans(activity_id,file_path,notes,canvas_json) VALUES (?,?,?,?)");
        $fp->execute([$id, $path, sanitize($_POST['floor_plan_notes']??''), $_POST['floor_plan_json'] ?? null]);
    } else {
        if (!empty($_POST['floor_plan_json'])) {
            $db->prepare("UPDATE floor_plans SET notes=?, canvas_json=? WHERE activity_id=?")
               ->execute([sanitize($_POST['floor_plan_notes']??''), $_POST['floor_plan_json'], $id]);
        } else {
            $db->prepare("UPDATE floor_plans SET notes=? WHERE activity_id=?")
               ->execute([sanitize($_POST['floor_plan_notes']??''), $id]);
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

    // Persist verified AI validation record to database
    if ($status === 'submitted' && !empty($cachedValidation)) {
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
    $db->rollBack();
    header("Location: ".BASE_URL."/faculty/proposal-edit.php?id={$id}&error=1");
    exit;
}
