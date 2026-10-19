<?php
/**
 * api/import-evaluations-csv.php
 *
 * Secure backend endpoint to import participant evaluation responses from CSV files
 * exported by Google Forms, Microsoft Forms, or standard spreadsheet templates.
 * 
 * Features:
 * - Zero external API credentials or cloud dependencies required.
 * - Supports both Wide matrix format (Google Forms / MS Forms standard exports)
 *   and Long table format (Criteria, Rating, Comments).
 * - Enforces role authorization and proponent activity ownership.
 * - Atomic database transactions (all-or-nothing rollback on failure).
 * - Reliable deduplication and validation against existing kpi_evaluations records.
 * - Accurate mapping of Likert rating scales (1-4) and open-ended feedback comments.
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function sendImportJsonResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 1. Authentication & Session Verification
startSession();

$user = currentUser();
if (!$user || empty($user['id'])) {
    sendImportJsonResponse(401, [
        'success' => false,
        'error'   => 'Unauthorized: Please log in to import evaluation responses.'
    ]);
}

// 2. HTTP Method Validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendImportJsonResponse(405, [
        'success' => false,
        'error'   => 'Method not allowed. Use POST to upload CSV files.'
    ]);
}

// 2.1 CSRF Token Validation
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!empty($_SESSION['csrf_token'])) {
    if (!$csrfToken || !validateCsrfToken($csrfToken)) {
        sendImportJsonResponse(403, [
            'success' => false,
            'error'   => 'Invalid or expired CSRF security token. Please refresh and try again.'
        ]);
    }
} elseif ($csrfToken && !validateCsrfToken($csrfToken)) {
    sendImportJsonResponse(403, [
        'success' => false,
        'error'   => 'Invalid CSRF security token.'
    ]);
}

// 3. Activity ID & Ownership Authorization
$activityId = (int)($_POST['activity_id'] ?? 0);
if ($activityId <= 0) {
    sendImportJsonResponse(400, [
        'success' => false,
        'error'   => 'A valid activity ID is required.'
    ]);
}

$db = getDB();
$actStmt = $db->prepare("SELECT id, faculty_id, title, status FROM activities WHERE id = ?");
$actStmt->execute([$activityId]);
$activity = $actStmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    sendImportJsonResponse(404, [
        'success' => false,
        'error'   => 'Activity not found.'
    ]);
}

// Role-based access control:
// Faculty can only import for their own activities; Admin1/Admin2/Dean can import for any activity.
if ($user['role'] === 'faculty') {
    if ((int)$activity['faculty_id'] !== (int)$user['id']) {
        sendImportJsonResponse(403, [
            'success' => false,
            'error'   => 'Forbidden: You do not have permission to import evaluation responses for another faculty member\'s activity.'
        ]);
    }
} elseif (!in_array($user['role'], ['admin1', 'admin2', 'dean'], true)) {
    sendImportJsonResponse(403, [
        'success' => false,
        'error'   => 'Forbidden: Access denied.'
    ]);
}

// 4. File Upload Validation
$file = !empty($_FILES['csv_file']) ? $_FILES['csv_file'] : (!empty($_FILES['file']) ? $_FILES['file'] : null);

if (!$file || !is_array($file) || empty($file['tmp_name'])) {
    sendImportJsonResponse(400, [
        'success' => false,
        'error'   => 'No CSV file was uploaded. Please attach a valid .csv file.'
    ]);
}

if ($file['error'] !== UPLOAD_ERR_OK) {
    sendImportJsonResponse(400, [
        'success' => false,
        'error'   => 'File upload failed with error code: ' . (int)$file['error']
    ]);
}

// File size limit: 5 MB
$maxBytes = 5 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    sendImportJsonResponse(400, [
        'success' => false,
        'error'   => 'Uploaded file size exceeds the 5 MB limit.'
    ]);
}

// Extension check
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    sendImportJsonResponse(400, [
        'success' => false,
        'error'   => 'Invalid file format. Only .csv files are supported.'
    ]);
}

// 5. Open and Parse CSV
$filePath = $file['tmp_name'];
$handle = @fopen($filePath, 'r');
if (!$handle) {
    sendImportJsonResponse(400, [
        'success' => false,
        'error'   => 'Unable to read the uploaded CSV file.'
    ]);
}

// Strip UTF-8 Byte Order Mark (BOM) if present
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

// Detect delimiter (comma, semicolon, or tab)
$firstLine = fgets($handle);
rewind($handle);
if ($bom === "\xEF\xBB\xBF") {
    fread($handle, 3);
}

$delimiter = ',';
if ($firstLine !== false) {
    $commaCount = substr_count($firstLine, ',');
    $semiCount  = substr_count($firstLine, ';');
    $tabCount   = substr_count($firstLine, "\t");
    if ($semiCount > $commaCount && $semiCount > $tabCount) {
        $delimiter = ';';
    } elseif ($tabCount > $commaCount && $tabCount > $semiCount) {
        $delimiter = "\t";
    }
}

$rawHeaders = fgetcsv($handle, 0, $delimiter);
if (!$rawHeaders || !is_array($rawHeaders) || empty(array_filter($rawHeaders))) {
    fclose($handle);
    sendImportJsonResponse(400, [
        'success' => false,
        'error'   => 'The uploaded CSV file is empty or does not contain a valid header row.'
    ]);
}

// Trim and sanitize headers
$headers = array_map(function ($h) {
    return trim(str_replace(["\r", "\n"], ' ', (string)$h));
}, $rawHeaders);

// Helper function to map text/number into 1-4 rating scale and comment
function parseCsvResponseValue(string $val): array
{
    $val = trim($val);
    if ($val === '') {
        return ['rating' => null, 'comments' => null];
    }

    // Likert text mapping
    $likertMap = [
        'strongly agree'     => 4,
        'agree'              => 3,
        'disagree'           => 2,
        'strongly disagree'  => 1,
        'very satisfied'     => 4,
        'satisfied'          => 3,
        'neutral'            => 2,
        'dissatisfied'       => 1,
        'very dissatisfied'  => 1,
        'excellent'          => 4,
        'very good'          => 4,
        'good'               => 3,
        'fair'               => 2,
        'poor'               => 1,
        'very poor'          => 1,
        'outstanding'        => 4,
        'satisfactory'       => 3,
        'unsatisfactory'     => 1,
    ];

    $lower = strtolower($val);
    if (isset($likertMap[$lower])) {
        return ['rating' => $likertMap[$lower], 'comments' => null];
    }

    // Numeric rating pattern (e.g., "4", "4 - Agree", "5/5", "4 out of 5", "4.0")
    if (preg_match('/^([1-5])(?:\s*[\/\-:]|\s+out\s+of|\.0|\b)/i', $val, $m)) {
        $r = (int)$m[1];
        if ($r > 4) $r = 4; // Scale 5-point to max schema constraint 4
        if ($r < 1) $r = 1;
        $comment = (strlen($val) > 30) ? $val : null;
        return ['rating' => $r, 'comments' => $comment];
    }

    // Pure numeric string
    if (is_numeric($val)) {
        $num = (float)$val;
        $r = (int)round($num);
        if ($r > 4) $r = 4;
        if ($r < 1) $r = 1;
        return ['rating' => $r, 'comments' => null];
    }

    // Pure text comment
    return ['rating' => null, 'comments' => $val];
}

// Determine if format is Long table or Wide matrix
$normalizedHeaders = array_map('strtolower', $headers);
$hasCriteriaCol = in_array('criteria', $normalizedHeaders, true) || in_array('criterion', $normalizedHeaders, true) || in_array('question', $normalizedHeaders, true) || in_array('indicator', $normalizedHeaders, true);
$hasRatingCol   = in_array('rating', $normalizedHeaders, true) || in_array('score', $normalizedHeaders, true);
$hasCommentsCol = in_array('comments', $normalizedHeaders, true) || in_array('comment', $normalizedHeaders, true) || in_array('feedback', $normalizedHeaders, true);

$isLongFormat = $hasCriteriaCol && ($hasRatingCol || $hasCommentsCol);

// Common metadata headers in Google Forms / Microsoft Forms exports
$metadataKeywords = [
    'timestamp', 'time stamp', 'start time', 'completion time', 'submission time',
    'id', 'response id', 'email', 'email address', 'name', 'full name',
    'username', 'score', 'total score', 'last modified time'
];

$importedCount = 0;
$skippedCount  = 0;
$totalRows     = 0;
$errorsList    = [];

try {
    $db->beginTransaction();

    // Prepare duplicate check statement
    $checkStmt = $db->prepare("
        SELECT id FROM kpi_evaluations 
        WHERE activity_id = ? AND criteria = ? AND evaluator_name = ?
        LIMIT 1
    ");

    // Prepare insertion statement
    $insertStmt = $db->prepare("
        INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if ($isLongFormat) {
        // --- LONG FORMAT (Flat Rows) ---
        $critIdx = array_search('criteria', $normalizedHeaders);
        if ($critIdx === false) $critIdx = array_search('criterion', $normalizedHeaders);
        if ($critIdx === false) $critIdx = array_search('question', $normalizedHeaders);
        if ($critIdx === false) $critIdx = array_search('indicator', $normalizedHeaders);

        $rateIdx = array_search('rating', $normalizedHeaders);
        if ($rateIdx === false) $rateIdx = array_search('score', $normalizedHeaders);

        $commIdx = array_search('comments', $normalizedHeaders);
        if ($commIdx === false) $commIdx = array_search('comment', $normalizedHeaders);
        if ($commIdx === false) $commIdx = array_search('feedback', $normalizedHeaders);

        $evalIdx = array_search('evaluator_name', $normalizedHeaders);
        if ($evalIdx === false) $evalIdx = array_search('evaluator', $normalizedHeaders);
        if ($evalIdx === false) $evalIdx = array_search('name', $normalizedHeaders);
        if ($evalIdx === false) $evalIdx = array_search('participant', $normalizedHeaders);
        if ($evalIdx === false) $evalIdx = array_search('email', $normalizedHeaders);

        $timeIdx = array_search('evaluated_at', $normalizedHeaders);
        if ($timeIdx === false) $timeIdx = array_search('timestamp', $normalizedHeaders);
        if ($timeIdx === false) $timeIdx = array_search('date', $normalizedHeaders);

        $rowNum = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNum++;
            if (empty(array_filter($row))) continue;
            $totalRows++;

            $criteria = trim((string)($row[$critIdx] ?? ''));
            if ($criteria === '') continue;

            $ratingRaw = ($rateIdx !== false && isset($row[$rateIdx])) ? trim((string)$row[$rateIdx]) : '';
            $commentRaw = ($commIdx !== false && isset($row[$commIdx])) ? trim((string)$row[$commIdx]) : '';

            $parsed = parseCsvResponseValue($ratingRaw !== '' ? $ratingRaw : $commentRaw);
            $rating = $parsed['rating'];
            $comments = ($commentRaw !== '') ? $commentRaw : $parsed['comments'];

            $evaluator = ($evalIdx !== false && !empty(trim((string)$row[$evalIdx])))
                ? trim((string)$row[$evalIdx])
                : "Participant (Row {$rowNum})";

            $timeRaw = ($timeIdx !== false && !empty(trim((string)$row[$timeIdx])))
                ? trim((string)$row[$timeIdx])
                : null;
            $evaluatedAt = $timeRaw && strtotime($timeRaw) ? date('Y-m-d H:i:s', strtotime($timeRaw)) : date('Y-m-d H:i:s');

            // Duplicate prevention
            $checkStmt->execute([$activityId, substr($criteria, 0, 200), substr($evaluator, 0, 150)]);
            if ($checkStmt->fetch()) {
                $skippedCount++;
                continue;
            }

            $insertStmt->execute([
                $activityId,
                substr($criteria, 0, 200),
                $rating,
                $comments,
                substr($evaluator, 0, 150),
                $evaluatedAt
            ]);
            $importedCount++;
        }
    } else {
        // --- WIDE FORMAT (Google Forms & Microsoft Forms standard exports) ---
        // Map metadata column indices
        $timeIdx = false;
        $nameIdx = false;
        $emailIdx = false;
        $idIdx = false;

        $questionCols = []; // array of [colIdx => questionHeader]

        foreach ($headers as $colIdx => $header) {
            $norm = strtolower($header);
            if (in_array($norm, ['timestamp', 'time stamp', 'start time', 'completion time', 'submission time', 'date'], true)) {
                if ($timeIdx === false || $norm === 'completion time' || $norm === 'timestamp') {
                    $timeIdx = $colIdx;
                }
            } elseif (in_array($norm, ['name', 'full name', 'participant', 'student name', 'respondent name'], true)) {
                $nameIdx = $colIdx;
            } elseif (in_array($norm, ['email', 'email address', 'username', 'respondent email'], true)) {
                $emailIdx = $colIdx;
            } elseif (in_array($norm, ['id', 'response id', 'submission id'], true)) {
                $idIdx = $colIdx;
            } elseif (!in_array($norm, $metadataKeywords, true) && $header !== '') {
                $questionCols[$colIdx] = $header;
            }
        }

        $hasMetadataHeader = ($timeIdx !== false || $nameIdx !== false || $emailIdx !== false || $idIdx !== false);
        if (!$hasMetadataHeader || empty($questionCols)) {
            fclose($handle);
            $db->rollBack();
            sendImportJsonResponse(400, [
                'success' => false,
                'error'   => 'Could not detect supported Google Forms or Microsoft Forms question headers in the uploaded CSV.'
            ]);
        }

        $participantSeq = 1;
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (empty(array_filter($row))) continue;
            $totalRows++;

            // Extract participant metadata
            $nameVal = ($nameIdx !== false && isset($row[$nameIdx])) ? trim((string)$row[$nameIdx]) : '';
            $emailVal = ($emailIdx !== false && isset($row[$emailIdx])) ? trim((string)$row[$emailIdx]) : '';
            $idVal = ($idIdx !== false && isset($row[$idIdx])) ? trim((string)$row[$idIdx]) : '';

            $evaluatorName = '';
            if ($nameVal !== '' && strtolower($nameVal) !== 'anonymous') {
                $evaluatorName = $nameVal;
            } elseif ($emailVal !== '' && strtolower($emailVal) !== 'anonymous') {
                $evaluatorName = $emailVal;
            } elseif ($idVal !== '') {
                $evaluatorName = "Participant #{$idVal}";
            } else {
                $evaluatorName = "Participant {$participantSeq}";
            }

            $timeVal = ($timeIdx !== false && isset($row[$timeIdx])) ? trim((string)$row[$timeIdx]) : '';
            $evaluatedAt = ($timeVal !== '' && strtotime($timeVal)) ? date('Y-m-d H:i:s', strtotime($timeVal)) : date('Y-m-d H:i:s');

            // Iterate over each question column
            foreach ($questionCols as $colIdx => $questionText) {
                $cellVal = isset($row[$colIdx]) ? trim((string)$row[$colIdx]) : '';
                if ($cellVal === '') continue;

                $parsed = parseCsvResponseValue($cellVal);
                $rating = $parsed['rating'];
                $comments = $parsed['comments'];

                // Deduplication check
                $checkStmt->execute([$activityId, substr($questionText, 0, 200), substr($evaluatorName, 0, 150)]);
                if ($checkStmt->fetch()) {
                    $skippedCount++;
                    continue;
                }

                $insertStmt->execute([
                    $activityId,
                    substr($questionText, 0, 200),
                    $rating,
                    $comments,
                    substr($evaluatorName, 0, 150),
                    $evaluatedAt
                ]);
                $importedCount++;
            }

            $participantSeq++;
        }
    }

    fclose($handle);

    if ($importedCount === 0 && $skippedCount === 0 && $totalRows === 0) {
        $db->rollBack();
        sendImportJsonResponse(400, [
            'success' => false,
            'error'   => 'The uploaded CSV file contains no response records.'
        ]);
    }

    $db->commit();

    sendImportJsonResponse(200, [
        'success'         => true,
        'activity_id'     => $activityId,
        'activity_title'  => $activity['title'],
        'imported_count'  => $importedCount,
        'skipped_count'   => $skippedCount,
        'total_rows'      => $totalRows,
        'message'         => "Successfully processed {$totalRows} participant response rows: {$importedCount} records imported, {$skippedCount} duplicates skipped."
    ]);

} catch (\Throwable $e) {
    if (isset($handle) && is_resource($handle)) {
        @fclose($handle);
    }
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("CSV Import Exception for activity {$activityId}: " . $e->getMessage());
    sendImportJsonResponse(500, [
        'success' => false,
        'error'   => 'A database error occurred during CSV import: ' . $e->getMessage()
    ]);
}
