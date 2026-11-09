<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';
requireRole('faculty', 'program_head');

$user = currentUser();
$db   = getDB();

// Self-healing database schema: ensure post_event table has expanded fields (run BEFORE any transaction)
$postEventCols = [];
try {
    $existingCols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('target_attendance', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN target_attendance INT NULL DEFAULT 0");
    }
    if (!in_array('actual_attendance', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN actual_attendance INT NULL DEFAULT 0");
    }
    if (!in_array('satisfaction_score', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN satisfaction_score DECIMAL(3,1) NULL DEFAULT NULL");
    }
    if (!in_array('recommendations', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN recommendations TEXT NULL DEFAULT NULL");
    }
    if (!in_array('manual_attendance', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN manual_attendance INT NULL DEFAULT NULL");
    }
    if (!in_array('assessment_report_path', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN assessment_report_path VARCHAR(500) NULL DEFAULT NULL");
    }
    if (!in_array('survey_csv_path', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN survey_csv_path VARCHAR(500) NULL DEFAULT NULL");
    }
    if (!in_array('attendance_method', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN attendance_method VARCHAR(50) NOT NULL DEFAULT 'paper_list'");
    }
    $postEventCols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);
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
        header("Location: " . BASE_URL . "/faculty/post-event.php?activity_id={$id}&error=invalid_attendance");
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
        header("Location: " . BASE_URL . "/faculty/post-event.php?activity_id={$id}&error=invalid_assessment_format");
        exit;
    }
}

// 3. Validate Survey-Form Results (CSV format only)
$hasNewSurveyCsv = !empty($_FILES['survey_results_csv']['name']);
if ($hasNewSurveyCsv) {
    if (!isValidCsvFile($_FILES['survey_results_csv'])) {
        header("Location: " . BASE_URL . "/faculty/post-event.php?activity_id={$id}&error=invalid_survey_format");
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
            header("Location: " . BASE_URL . "/faculty/post-event.php?activity_id={$id}&error=invalid_doc_format");
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

    header("Location: " . BASE_URL . "/faculty/post-event.php?activity_id={$id}&saved=1");
    exit;
} catch (\Throwable $e) {
    error_log("Post Event Save Error: " . $e->getMessage());
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    header("Location: " . BASE_URL . "/faculty/post-event.php?activity_id={$id}&error=1");
    exit;
}
