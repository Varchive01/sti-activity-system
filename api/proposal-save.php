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
$action = $_POST['action'] ?? 'draft';
$status = ($action === 'submit') ? 'submitted' : 'draft';

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

    // Server-side AI completeness & alignment validation gating
    require_once __DIR__ . '/../services/GeminiProposalValidationService.php';
    require_once __DIR__ . '/../includes/ai/proposal_validator.php';

    $aiService = new GeminiProposalValidationService(getGeminiApiKeySecure());
    $canonicalPayload = $aiService->getCanonicalProposalPayload($_POST);
    $currentHash = $aiService->calculateProposalHash($canonicalPayload);

    // Verify against server session cache (never trust client-supplied approval flags)
    if (!isset($_SESSION['last_ai_validation'][$currentHash])) {
        // Validation result does not correspond to current payload or was never performed
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=ai_validation_critical");
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
        header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=ai_validation_critical");
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

    $hasAnyFloorPlan = $hasFpFile || $hasFpImage || $hasFpCanvas;
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

$newlyCreatedDiskPath = null;

try {
    $db->beginTransaction();

    // 1. Insert activity
    $stmt = $db->prepare("INSERT INTO activities
        (faculty_id,title,description,theme,venue,venue_address,event_date,start_time,end_time,
         target_participants,general_objectives,specific_objectives,involved_subjects,rationale,
         evaluation_method,source,status,submitted_at,evaluation_questions)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

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
        (int)($_POST['target_participants'] ?? 0),
        sanitize($_POST['general_objectives'] ?? ''),
        sanitize($_POST['specific_objectives'] ?? ''),
        sanitize($_POST['involved_subjects'] ?? ''),
        sanitize($_POST['rationale'] ?? ''),
        sanitize($_POST['evaluation_method'] ?? ($_POST['eval_form_link'] ?? '')),
        $_POST['source'] ?? 'faculty',
        $status,
        ($status === 'submitted') ? date('Y-m-d H:i:s') : null,
        $_POST['evaluation_questions'] ?? null,
    ]);
    $actId = $db->lastInsertId();

    // Safely persist floor plan requirement if the schema column exists
    try {
        $colCheck = $db->query("SHOW COLUMNS FROM activities LIKE 'floor_plan_required'")->fetch();
        if ($colCheck) {
            $db->prepare("UPDATE activities SET floor_plan_required = ? WHERE id = ?")->execute([$fpRequiredInt, $actId]);
        }
    } catch (Exception $e) {
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
            $mp->execute([$actId, sanitize($role), sanitize($_POST['mp_person'][$i]??''), $_POST['mp_type'][$i]??'faculty']);
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

        $facultyLookupStmt = $db->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'faculty' LIMIT 1");

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

            if ($assignedUserId) {
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
    if (!empty($_FILES['poster_file']['name'])) {
        $posterPath = uploadFile($_FILES['poster_file'], 'posters', $actId);
        if ($posterPath) {
            $db->prepare("UPDATE activities SET poster_path=? WHERE id=?")->execute([$posterPath, $actId]);
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

        // Notify appropriate admin
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
                    'status' => ($nextStatus === 'under_review') ? 'Under Review' : 'Submitted',
                ];
            }
        }
    }

    $db->commit();

    // Automatically generate and archive proposal PDF snapshot to OneDrive upon submission
    if ($status === 'submitted') {
        try {
            syncProposalPdfSnapshot($actId, 'submitted', (int)$user['id']);
        } catch (\Throwable $snapEx) {
            error_log("Failed to create proposal snapshot on save: " . $snapEx->getMessage());
        }
    }

    // Persist verified AI validation record to database
    if ($status === 'submitted' && !empty($cachedValidation)) {
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

    header("Location: " . BASE_URL . "/faculty/dashboard.php?saved=1");
    exit;

} catch (Exception $e) {
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
    header("Location: " . BASE_URL . "/faculty/proposal-create.php?error=1");
    exit;
}
