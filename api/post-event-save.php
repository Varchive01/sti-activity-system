<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';
require_once __DIR__ . '/../includes/PostEventAssessmentHelper.php';
requireRole('faculty', 'program_head', 'admin1', 'admin2');

$user = currentUser();
$db   = getDB();

// Self-healing database schema: ensure post_event & faculty_tasks tables have Phase 3 expanded fields (run BEFORE any transaction)
$postEventCols = [];
try {
    $existingCols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);
    $requiredPeCols = [
        'target_attendance'      => "INT NULL DEFAULT 0",
        'actual_attendance'      => "INT NULL DEFAULT 0",
        'satisfaction_score'     => "DECIMAL(3,1) NULL DEFAULT NULL",
        'recommendations'        => "TEXT NULL DEFAULT NULL",
        'manual_attendance'      => "INT NULL DEFAULT NULL",
        'assessment_report_path' => "VARCHAR(500) NULL DEFAULT NULL",
        'survey_csv_path'        => "VARCHAR(500) NULL DEFAULT NULL",
        'attendance_method'      => "VARCHAR(50) NOT NULL DEFAULT 'paper_list'",
        'actual_expenses'        => "DECIMAL(12,2) NULL DEFAULT NULL",
        'approved_budget'        => "DECIMAL(12,2) NULL DEFAULT NULL",
        'budget_variance'        => "DECIMAL(12,2) NULL DEFAULT NULL",
        'budget_status'          => "VARCHAR(40) NULL DEFAULT NULL",
        'expense_breakdown_path' => "VARCHAR(500) NULL DEFAULT NULL",
        'equipment_report_json'  => "TEXT NULL DEFAULT NULL",
        'media_drive_link'       => "VARCHAR(500) NULL DEFAULT NULL",
        'comments_summary'       => "TEXT NULL DEFAULT NULL",
        'ai_recommendations'     => "TEXT NULL DEFAULT NULL",
        'ai_assessment_summary'  => "TEXT NULL DEFAULT NULL",
    ];
    foreach ($requiredPeCols as $cName => $cDef) {
        if (!in_array($cName, $existingCols, true)) {
            $db->exec("ALTER TABLE post_event ADD COLUMN {$cName} {$cDef}");
        }
    }
    $postEventCols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);

    $ftCols = $db->query("SHOW COLUMNS FROM faculty_tasks")->fetchAll(PDO::FETCH_COLUMN);
    $requiredFtCols = [
        'verified_by_head'    => "TINYINT(1) NOT NULL DEFAULT 0",
        'verified_at'         => "DATETIME NULL DEFAULT NULL",
        'verification_status' => "VARCHAR(40) NOT NULL DEFAULT 'pending'",
        'performance_rating'  => "INT NULL DEFAULT NULL",
        'performance_remarks' => "TEXT NULL DEFAULT NULL",
    ];
    foreach ($requiredFtCols as $cName => $cDef) {
        if (!in_array($cName, $ftCols, true)) {
            $db->exec("ALTER TABLE faculty_tasks ADD COLUMN {$cName} {$cDef}");
        }
    }
} catch (\Throwable $migEx) {
    // Gracefully continue even if DDL permissions are restricted
    try {
        $postEventCols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $colEx) {
        $postEventCols = [];
    }
}

$id   = (int)($_POST['activity_id'] ?? $_GET['activity_id'] ?? 0);

// Verify access and status
if (!canUserAccessActivity($id, 'modify', $user['id'], $user['role'], $user['dept'])) {
    http_response_code(403);
    die('Access denied: You do not have permission to modify this activity.');
}

$check = $db->prepare("SELECT * FROM activities WHERE id=? AND status IN ('approved', 'completed')");
$check->execute([$id]);
$activity = $check->fetch(PDO::FETCH_ASSOC);
if (!$activity) {
    header('Location: ' . BASE_URL . '/faculty/post-event.php');
    exit;
}

// 1. Participant count validation (Paper-Based Attendance / Manual Headcount)
$manualAttendance = null;
if (isset($_POST['manual_attendance']) && trim((string)$_POST['manual_attendance']) !== '') {
    $rawAtt = trim((string)$_POST['manual_attendance']);
    if (!is_numeric($rawAtt) || (int)$rawAtt < 0) {
        header("Location: " . BASE_URL . "/faculty/post-event-form.php?activity_id={$id}&error=invalid_attendance");
        exit;
    }
    $manualAttendance = (int)$rawAtt;
}

$targetAttendance = (int)($_POST['target_attendance'] ?? $activity['target_participants'] ?? 0);
$actualAttendance = $manualAttendance !== null ? $manualAttendance : null;

// 2. Validate Assessment Report (PDF format only)
$hasNewAssessment = !empty($_FILES['assessment_report_file']['name']);
if ($hasNewAssessment) {
    if (!isValidPdfFile($_FILES['assessment_report_file'])) {
        header("Location: " . BASE_URL . "/faculty/post-event-form.php?activity_id={$id}&error=invalid_assessment_format");
        exit;
    }
}

// 3. Validate Survey-Form Results (CSV format only)
$hasNewSurveyCsv = !empty($_FILES['survey_results_csv']['name']);
if ($hasNewSurveyCsv) {
    if (!isValidCsvFile($_FILES['survey_results_csv'])) {
        header("Location: " . BASE_URL . "/faculty/post-event-form.php?activity_id={$id}&error=invalid_survey_format");
        exit;
    }
}

// 4. Validate Event Documentation files
if (!empty($_FILES['post_event_files']['name'][0])) {
    foreach ($_FILES['post_event_files']['name'] as $i => $fname) {
        if (!$fname) continue;
        $fileItem = [
            'name'     => $fname,
            'tmp_name' => $_FILES['post_event_files']['tmp_name'][$i] ?? '',
            'size'     => $_FILES['post_event_files']['size'][$i] ?? 0,
            'error'    => $_FILES['post_event_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
        ];
        if (!isValidDocumentationFile($fileItem)) {
            header("Location: " . BASE_URL . "/faculty/post-event-form.php?activity_id={$id}&error=invalid_doc_format");
            exit;
        }
    }
}

try {
    $hasTargetCol = in_array('target_attendance', $postEventCols, true);
    $hasActualCol = in_array('actual_attendance', $postEventCols, true);
    $hasSatCol    = in_array('satisfaction_score', $postEventCols, true);
    $hasManualCol = in_array('manual_attendance', $postEventCols, true);
    $hasAssessCol = in_array('assessment_report_path', $postEventCols, true);
    $hasSurveyCol = in_array('survey_csv_path', $postEventCols, true);
    $hasMethodCol = in_array('attendance_method', $postEventCols, true);

    $db->beginTransaction();

    // Fetch existing post_event record if editing
    $peStmt = $db->prepare("SELECT * FROM post_event WHERE activity_id = ? LIMIT 1");
    $peStmt->execute([$id]);
    $existingPe = $peStmt->fetch(PDO::FETCH_ASSOC);

    $assessmentReportPath = null;
    if ($hasNewAssessment) {
        $path = uploadFile($_FILES['assessment_report_file'], 'post-event', $id);
        if ($path) {
            $assessmentReportPath = $path;
            $docFileName = mb_substr('Assessment Report - ' . sanitize($_FILES['assessment_report_file']['name']), 0, 290, 'UTF-8');
            $docStmt = $db->prepare("INSERT INTO documents(activity_id, doc_type, file_name, file_path, uploaded_by) VALUES (?, 'post_event', ?, ?, ?)");
            $docStmt->execute([$id, $docFileName, $path, $user['id']]);
            $newDocId = (int)$db->lastInsertId();
            if ($newDocId && function_exists('syncOneDriveMetadataToRecord')) {
                syncOneDriveMetadataToRecord($db, 'documents', $newDocId, $path, $id);
            }
        }
    }

    $surveyCsvPath = null;
    if ($hasNewSurveyCsv) {
        $path = uploadFile($_FILES['survey_results_csv'], 'post-event', $id);
        if ($path) {
            $surveyCsvPath = $path;
            $docFileName = mb_substr('Survey Results - ' . sanitize($_FILES['survey_results_csv']['name']), 0, 290, 'UTF-8');
            $docStmt = $db->prepare("INSERT INTO documents(activity_id, doc_type, file_name, file_path, uploaded_by) VALUES (?, 'post_event', ?, ?, ?)");
            $docStmt->execute([$id, $docFileName, $path, $user['id']]);
            $newDocId = (int)$db->lastInsertId();
            if ($newDocId && function_exists('syncOneDriveMetadataToRecord')) {
                syncOneDriveMetadataToRecord($db, 'documents', $newDocId, $path, $id);
            }
        }
    }

    // Upload supporting documentation files
    if (!empty($_FILES['post_event_files']['name'][0])) {
        $docStmt = $db->prepare("INSERT INTO documents(activity_id, doc_type, file_name, file_path, uploaded_by) VALUES (?, ?, ?, ?, ?)");
        foreach ($_FILES['post_event_files']['name'] as $i => $fname) {
            if (!$fname || ($_FILES['post_event_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
            $file = [
                'name'     => $fname,
                'tmp_name' => $_FILES['post_event_files']['tmp_name'][$i],
                'size'     => $_FILES['post_event_files']['size'][$i],
                'error'    => $_FILES['post_event_files']['error'][$i],
            ];
            $path = uploadFile($file, 'post-event', $id);
            if ($path) {
                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                if ($ext === 'csv' && $surveyCsvPath === null) {
                    $surveyCsvPath = $path;
                }
                if ($ext === 'pdf' && $assessmentReportPath === null && empty($existingPe['assessment_report_path'])) {
                    $assessmentReportPath = $path;
                }
                $docType = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? 'photo' : 'post_event';
                $docFileName = mb_substr(sanitize($fname), 0, 290, 'UTF-8');
                $docStmt->execute([$id, $docType, $docFileName, $path, $user['id']]);
                $newDocId = (int)$db->lastInsertId();
                if ($newDocId && function_exists('syncOneDriveMetadataToRecord')) {
                    syncOneDriveMetadataToRecord($db, 'documents', $newDocId, $path, $id);
                }
            }
        }
    }

    // Determine final values combining existing with new uploads
    $finalAssessPath = $assessmentReportPath ?? ($existingPe['assessment_report_path'] ?? null);
    $finalSurveyPath = $surveyCsvPath ?? ($existingPe['survey_csv_path'] ?? null);
    $finalManualAtt  = $manualAttendance ?? ($existingPe['manual_attendance'] ?? null);
    $attendanceMethod = sanitize($_POST['attendance_method'] ?? ($existingPe['attendance_method'] ?? ($finalManualAtt !== null ? 'paper_list' : 'survey_only')));

    // Fallback: if no survey CSV path is set yet, check documents table or disk for a CSV belonging to this activity
    if (!$finalSurveyPath) {
        $docCsvStmt = $db->prepare("SELECT file_path FROM documents WHERE activity_id = ? AND (file_path LIKE '%.csv' OR file_name LIKE '%.csv') ORDER BY id DESC LIMIT 1");
        $docCsvStmt->execute([$id]);
        $foundDocCsv = $docCsvStmt->fetchColumn();
        if ($foundDocCsv) {
            $finalSurveyPath = (string)$foundDocCsv;
        } elseif (defined('UPLOAD_PATH')) {
            $csvMatches = @glob(rtrim(str_replace('\\', '/', UPLOAD_PATH), '/') . '/post-event/' . $id . '_*.csv');
            if (!empty($csvMatches)) {
                usort($csvMatches, fn($a, $b) => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));
                $finalSurveyPath = 'post-event/' . basename($csvMatches[0]);
            }
        }
    }

    // Inspect survey responses count from CSV or database
    $surveyRespondentCount = 0;
    $computedSurveyScore = null;

    if ($finalSurveyPath) {
        $csvFullPath = function_exists('resolveUploadedFilePath')
            ? resolveUploadedFilePath($finalSurveyPath)
            : (dirname(__DIR__) . '/uploads/' . ltrim($finalSurveyPath, '/\\'));
        if ($csvFullPath && function_exists('parseEvaluationCsvFile') && file_exists($csvFullPath)) {
            $parsedCsv = parseEvaluationCsvFile($csvFullPath);
            $surveyRespondentCount = (int)($parsedCsv['respondent_count'] ?? 0);
            if (isset($parsedCsv['satisfaction_score']) && $parsedCsv['satisfaction_score'] !== null) {
                $computedSurveyScore = (float)$parsedCsv['satisfaction_score'];
            } elseif (!empty($parsedCsv['average_rating'])) {
                $computedSurveyScore = ($parsedCsv['average_rating'] <= 4.0)
                    ? round(($parsedCsv['average_rating'] / 4.0) * 5.0, 1)
                    : round((float)$parsedCsv['average_rating'], 1);
            }

            if (function_exists('syncCsvEvaluationsToDatabase')) {
                syncCsvEvaluationsToDatabase($db, $id, $parsedCsv);
            }
        }
    }

    if ($surveyRespondentCount === 0) {
        try {
            $dbSurvey = $db->prepare("
                SELECT COUNT(DISTINCT CASE WHEN evaluator_name IS NOT NULL AND TRIM(evaluator_name) != '' AND evaluator_name NOT LIKE 'Survey Summary (%' THEN evaluator_name ELSE id END) AS cnt,
                       AVG(rating) AS avg_r
                FROM kpi_evaluations
                WHERE activity_id = ? AND rating IS NOT NULL
            ");
            $dbSurvey->execute([$id]);
            $dbSurvRow = $dbSurvey->fetch(PDO::FETCH_ASSOC);
            if ($dbSurvRow) {
                $surveyRespondentCount = (int)($dbSurvRow['cnt'] ?? 0);
                if ($computedSurveyScore === null && $dbSurvRow['avg_r'] !== null) {
                    $avgR = (float)$dbSurvRow['avg_r'];
                    $computedSurveyScore = ($avgR <= 4.0) ? round(($avgR / 4.0) * 5.0, 1) : round($avgR, 1);
                }
            }
        } catch (\Throwable $survCountEx) {
            $surveyRespondentCount = 0;
        }
    }

    // Determine final actual attendance based on selected attendance method (preventing double-counting)
    $finalActualAtt = 0;
    if ($attendanceMethod === 'survey_only') {
        $finalActualAtt = $surveyRespondentCount > 0 ? $surveyRespondentCount : ($finalManualAtt ?? 0);
    } elseif ($attendanceMethod === 'both') {
        // Avoid counting the same participants twice when both attendance methods are used:
        // Use deduplicated max count between paper sign-in and survey respondents
        $paperCount = $finalManualAtt ?? 0;
        $finalActualAtt = max($paperCount, $surveyRespondentCount);
    } else { // 'paper_list'
        $finalActualAtt = $finalManualAtt !== null ? $finalManualAtt : (isset($_POST['actual_attendance']) ? (int)$_POST['actual_attendance'] : 0);
    }

    $finalSatScore = $computedSurveyScore ?? ($existingPe['satisfaction_score'] ?? null);

    // ── Phase 3: Budget & Expense Breakdown Processing ─────────────────
    $approvedBudget = isset($_POST['approved_budget']) && trim((string)$_POST['approved_budget']) !== ''
        ? round((float)$_POST['approved_budget'], 2)
        : ($existingPe['approved_budget'] ?? PostEventAssessmentHelper::computeApprovedBudget($db, $id));
    $actualExpenses = isset($_POST['actual_expenses']) && trim((string)$_POST['actual_expenses']) !== ''
        ? round((float)$_POST['actual_expenses'], 2)
        : (isset($existingPe['actual_expenses']) && $existingPe['actual_expenses'] !== null ? (float)$existingPe['actual_expenses'] : $approvedBudget);
    $budgetEval = PostEventAssessmentHelper::evaluateBudgetStatus((float)$approvedBudget, $actualExpenses);
    $budgetVariance = $budgetEval['variance'];
    $budgetStatus   = $budgetEval['status'];

    $expenseBreakdownPath = $existingPe['expense_breakdown_path'] ?? null;
    if (!empty($_FILES['expense_breakdown_file']['name']) && ($_FILES['expense_breakdown_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ebPath = uploadFile($_FILES['expense_breakdown_file'], 'post-event', $id);
        if ($ebPath) {
            $expenseBreakdownPath = $ebPath;
            $ebDocName = mb_substr('Expense Breakdown - ' . sanitize($_FILES['expense_breakdown_file']['name']), 0, 290, 'UTF-8');
            $db->prepare("INSERT INTO documents(activity_id, doc_type, file_name, file_path, uploaded_by) VALUES (?, 'post_event', ?, ?, ?)")
               ->execute([$id, $ebDocName, $ebPath, $user['id']]);
        }
    }

    // ── Phase 3: Equipment Return & Condition Status Report ────────────
    $equipmentItems = [];
    if (!empty($_POST['eq_item_name']) && is_array($_POST['eq_item_name'])) {
        foreach ($_POST['eq_item_name'] as $idx => $eqNameRaw) {
            $eqName = trim((string)$eqNameRaw);
            if ($eqName === '') continue;
            $eqQty = max(1, (int)($_POST['eq_quantity'][$idx] ?? 1));
            $eqStatus = trim((string)($_POST['eq_status'][$idx] ?? 'returned_properly'));
            if (!in_array($eqStatus, ['returned_properly', 'damaged', 'needs_replacement'], true)) {
                $eqStatus = 'returned_properly';
            }
            $eqRemarks = trim((string)($_POST['eq_remarks'][$idx] ?? ''));
            $equipmentItems[] = [
                'item_name' => $eqName,
                'quantity'  => $eqQty,
                'status'    => $eqStatus,
                'remarks'   => $eqRemarks
            ];
        }
    }
    $equipmentReportJson = !empty($equipmentItems)
        ? json_encode($equipmentItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : ($existingPe['equipment_report_json'] ?? null);

    // ── Phase 3: Committee Head Task Verification & Personnel Performance ──
    if (!empty($_POST['task_verify_id']) && is_array($_POST['task_verify_id'])) {
        foreach ($_POST['task_verify_id'] as $tIdx => $tIdRaw) {
            $tId = (int)$tIdRaw;
            if ($tId <= 0) continue;
            $vStatus = trim((string)($_POST['task_verify_status'][$tIdx] ?? 'verified_correct'));
            if (!in_array($vStatus, ['pending', 'verified_correct', 'needs_correction'], true)) {
                $vStatus = 'verified_correct';
            }
            $vByHead = ($vStatus === 'verified_correct') ? 1 : 0;
            $pRating = isset($_POST['task_perf_rating'][$tIdx]) && $_POST['task_perf_rating'][$tIdx] !== ''
                ? max(1, min(5, (int)$_POST['task_perf_rating'][$tIdx]))
                : 5;
            $pRemarks = trim((string)($_POST['task_perf_remarks'][$tIdx] ?? ''));

            if ($vStatus === 'verified_correct') {
                $db->prepare("
                    UPDATE faculty_tasks
                    SET verified_by_head = 1,
                        verified_at = NOW(),
                        verification_status = 'verified_correct',
                        status = 'Completed',
                        completion_pct = 100,
                        performance_rating = ?,
                        performance_remarks = ?
                    WHERE id = ? AND activity_id = ?
                ")->execute([$pRating, $pRemarks ?: null, $tId, $id]);
            } else {
                $db->prepare("
                    UPDATE faculty_tasks
                    SET verified_by_head = ?,
                        verified_at = NOW(),
                        verification_status = ?,
                        performance_rating = ?,
                        performance_remarks = ?
                    WHERE id = ? AND activity_id = ?
                ")->execute([$vByHead, $vStatus, $pRating, $pRemarks ?: null, $tId, $id]);
            }
        }
    }

    // ── Phase 3: Cloud Drive Link, Comments Summary & AI-Assisted Auto-Generated Assessment Report ──
    $mediaDriveLink  = trim((string)($_POST['media_drive_link'] ?? ($existingPe['media_drive_link'] ?? '')));
    $commentsSummary = trim((string)($_POST['comments_summary'] ?? ($existingPe['comments_summary'] ?? '')));
    $aiRecs          = trim((string)($_POST['ai_recommendations'] ?? ($existingPe['ai_recommendations'] ?? '')));
    $aiAssessSummary = trim((string)($_POST['ai_assessment_summary'] ?? ($existingPe['ai_assessment_summary'] ?? '')));

    $synth = PostEventAssessmentHelper::generateAssessmentAndRecommendations($db, $id, [
        'target_attendance'  => $targetAttendance,
        'actual_attendance'  => $finalActualAtt,
        'satisfaction_score' => $finalSatScore,
        'approved_budget'    => $approvedBudget,
        'actual_expenses'    => $actualExpenses,
        'comments_summary'   => $commentsSummary,
        'equipment_items'    => $equipmentItems,
    ]);

    if ($commentsSummary === '') {
        $commentsSummary = $synth['comments_summary'];
    }
    if ($aiRecs === '') {
        $aiRecs = $synth['ai_recommendations'];
    }
    if ($aiAssessSummary === '') {
        $aiAssessSummary = $synth['ai_assessment_summary'];
    }

    // Auto-generate official PDF Assessment Report once final documents/report are saved if no manual PDF was uploaded
    if (empty($finalAssessPath)) {
        $autoPdfPath = PostEventAssessmentHelper::autoGenerateAssessmentPdfFile($db, $id, [
            'ai_assessment_summary' => $aiAssessSummary,
            'approved_budget'       => $approvedBudget,
            'actual_expenses'       => $actualExpenses,
            'budget_label'          => $budgetEval['label'],
            'budget_variance'       => $budgetVariance,
            'comments_summary'      => $commentsSummary,
            'ai_recommendations'    => $aiRecs,
        ], (int)$user['id']);
        if ($autoPdfPath) {
            $finalAssessPath = $autoPdfPath;
        }
    }

    $phase3FieldValues = [
        'actual_expenses'        => $actualExpenses,
        'approved_budget'        => $approvedBudget,
        'budget_variance'        => $budgetVariance,
        'budget_status'          => $budgetStatus,
        'expense_breakdown_path' => $expenseBreakdownPath,
        'equipment_report_json'  => $equipmentReportJson,
        'media_drive_link'       => $mediaDriveLink ?: null,
        'comments_summary'       => $commentsSummary ?: null,
        'ai_recommendations'     => $aiRecs ?: null,
        'ai_assessment_summary'  => $aiAssessSummary ?: null,
        'observations'           => $commentsSummary ?: null,
        'recommendations'        => $aiRecs ?: null,
    ];

    if ($existingPe) {
        // Update existing record
        $updateFields = [
            "submitted_by = ?",
            "submitted_at = NOW()"
        ];
        $params = [$user['id']];

        if ($hasTargetCol) {
            $updateFields[] = "target_attendance = ?";
            $params[] = $targetAttendance;
        }
        if ($hasActualCol) {
            $updateFields[] = "actual_attendance = ?";
            $params[] = $finalActualAtt;
        }
        if ($hasManualCol) {
            $updateFields[] = "manual_attendance = ?";
            $params[] = $finalManualAtt;
        }
        if ($hasAssessCol && $finalAssessPath) {
            $updateFields[] = "assessment_report_path = ?";
            $params[] = $finalAssessPath;
        }
        if ($hasSurveyCol && $finalSurveyPath) {
            $updateFields[] = "survey_csv_path = ?";
            $params[] = $finalSurveyPath;
        }
        if ($hasMethodCol) {
            $updateFields[] = "attendance_method = ?";
            $params[] = $attendanceMethod;
        }
        if ($hasSatCol && $finalSatScore !== null) {
            $updateFields[] = "satisfaction_score = ?";
            $params[] = $finalSatScore;
        }
        foreach ($phase3FieldValues as $colKey => $colVal) {
            if (in_array($colKey, $postEventCols, true)) {
                $updateFields[] = "{$colKey} = ?";
                $params[] = $colVal;
            }
        }

        $params[] = $id;
        $db->prepare("UPDATE post_event SET " . implode(', ', $updateFields) . " WHERE activity_id = ?")->execute($params);
    } else {
        // Insert new record
        $insCols = ["activity_id", "submitted_by", "submitted_at"];
        $insVals = ["?", "?", "NOW()"];
        $params  = [$id, $user['id']];

        if ($hasTargetCol) {
            $insCols[] = "target_attendance";
            $insVals[] = "?";
            $params[]  = $targetAttendance;
        }
        if ($hasActualCol) {
            $insCols[] = "actual_attendance";
            $insVals[] = "?";
            $params[]  = $finalActualAtt;
        }
        if ($hasManualCol) {
            $insCols[] = "manual_attendance";
            $insVals[] = "?";
            $params[]  = $finalManualAtt;
        }
        if ($hasAssessCol) {
            $insCols[] = "assessment_report_path";
            $insVals[] = "?";
            $params[]  = $finalAssessPath;
        }
        if ($hasSurveyCol) {
            $insCols[] = "survey_csv_path";
            $insVals[] = "?";
            $params[]  = $finalSurveyPath;
        }
        if ($hasMethodCol) {
            $insCols[] = "attendance_method";
            $insVals[] = "?";
            $params[]  = $attendanceMethod;
        }
        if ($hasSatCol && $finalSatScore !== null) {
            $insCols[] = "satisfaction_score";
            $insVals[] = "?";
            $params[]  = $finalSatScore;
        }
        foreach ($phase3FieldValues as $colKey => $colVal) {
            if (in_array($colKey, $postEventCols, true)) {
                $insCols[] = $colKey;
                $insVals[] = "?";
                $params[]  = $colVal;
            }
        }

        $db->prepare("INSERT INTO post_event (" . implode(', ', $insCols) . ") VALUES (" . implode(', ', $insVals) . ")")->execute($params);
    }

    // Mark activity status as completed
    $db->prepare("UPDATE activities SET status='completed' WHERE id=?")->execute([$id]);

    if ($db->inTransaction()) {
        $db->commit();
    }

    if (function_exists('reconcilePostEventMetrics')) {
        reconcilePostEventMetrics($db, $id);
    }

    // Notify Sir Ian (admin2) outside transaction so notification/SMTP never rolls back saved report
    try {
        $ian = $db->query("SELECT id, email, name FROM users WHERE role='admin2' LIMIT 1")->fetch();
        if ($ian) {
            $title = $activity['title'] ?: 'Activity';
            $db->prepare("INSERT INTO notifications(user_id, activity_id, message) VALUES(?, ?, ?)")
                ->execute([$ian['id'], $id, "Post-event report uploaded/updated for: {$title}. Ready for KPI review and evaluation."]);

            $emailService = new EmailService();
            $emailService->sendNotification(
                $ian['email'],
                $ian['name'],
                "Post-Event Evaluation Available: " . sanitize($title),
                sanitize($title),
                "Completed",
                "A Post-Event report and documentation have been uploaded for '" . sanitize($title) . "' and are ready for KPI review.",
                BASE_URL . "/admin2/view-activity.php?id=" . $id,
                $id,
                "post_event_available"
            );
        }
    } catch (\Throwable $notifyEx) {
        error_log("Post-event notification/email skipped: " . $notifyEx->getMessage());
    }

    header("Location: " . BASE_URL . "/faculty/post-event-form.php?activity_id={$id}&saved=1");
    exit;
} catch (\Throwable $e) {
    error_log("Post Event Save Error: " . $e->getMessage());
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    header("Location: " . BASE_URL . "/faculty/post-event-form.php?activity_id={$id}&error=1");
    exit;
}
