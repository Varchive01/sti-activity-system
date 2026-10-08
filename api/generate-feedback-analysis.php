<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/GeminiFeedbackAnalysisService.php';

header('Content-Type: application/json');

// Ensure user is logged in
$user = currentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

$db = getDB();
$activityId = (int)($_POST['activity_id'] ?? $_GET['activity_id'] ?? 0);

if (!$activityId) {
    http_response_code(400);
    echo json_encode(['error' => 'Activity ID is required.']);
    exit;
}

// Fetch activity details
$actStmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
$actStmt->execute([$activityId]);
$activity = $actStmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    http_response_code(404);
    echo json_encode(['error' => 'Activity not found.']);
    exit;
}

// Role authorization checks
if ($user['role'] === 'faculty' && (int)$activity['faculty_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo json_encode(['error' => 'You are not authorized to access this activity feedback.']);
    exit;
}

// Fetch raw evaluation responses
$respStmt = $db->prepare("
    SELECT id, criteria, rating, comments, evaluator_name, evaluated_at 
    FROM kpi_evaluations 
    WHERE activity_id = ? AND (rating IS NOT NULL OR (comments IS NOT NULL AND TRIM(comments) != ''))
    ORDER BY id ASC
");
$respStmt->execute([$activityId]);
$responses = $respStmt->fetchAll(PDO::FETCH_ASSOC);
$responsesCount = count($responses);

if ($responsesCount === 0) {
    http_response_code(200);
    echo json_encode([
        'status' => 'empty',
        'message' => 'No participant feedback available yet.'
    ]);
    exit;
}

// Generate dataset hash for freshness checks
$serializedDataset = json_encode($responses);
$datasetHash = hash('sha256', $serializedDataset);

// Fetch existing analysis
$analysisStmt = $db->prepare("SELECT * FROM activity_feedback_analysis WHERE activity_id = ?");
$analysisStmt->execute([$activityId]);
$existingAnalysis = $analysisStmt->fetch(PDO::FETCH_ASSOC);

$action = $_POST['action'] ?? ($_GET['action'] ?? 'check');

if ($action === 'check') {
    http_response_code(200);
    // Return comparison state
    if (!$existingAnalysis) {
        echo json_encode([
            'status' => 'not_generated',
            'responses_count' => $responsesCount
        ]);
    } else {
        $decoded = json_decode($existingAnalysis['analysis_json'], true) ?: [];
        $isFinalized = !empty($decoded['is_finalized']);
        $isReviewed = !empty($decoded['is_reviewed']);
        $isOutdated = ($existingAnalysis['response_hash'] !== $datasetHash);
        $diff = $responsesCount - (int)$existingAnalysis['responses_count'];
        echo json_encode([
            'status' => 'generated',
            'is_outdated' => $isFinalized ? false : $isOutdated,
            'is_finalized' => $isFinalized,
            'is_reviewed' => $isReviewed,
            'finalized_by' => $decoded['finalized_by'] ?? null,
            'finalized_at' => $decoded['finalized_at'] ?? null,
            'reviewed_by' => $decoded['reviewed_by'] ?? null,
            'reviewed_at' => $decoded['reviewed_at'] ?? null,
            'new_responses_count' => max(0, $diff),
            'last_updated' => $existingAnalysis['updated_at'],
            'responses_count' => $responsesCount,
            'analysis' => $decoded
        ]);
    }
    exit;
}

function parseListInput($val): ?array {
    if ($val === null) return null;
    if (is_array($val)) return array_values(array_filter(array_map('trim', $val)));
    $str = trim((string)$val);
    if ($str === '') return [];
    if (str_starts_with($str, '[')) {
        $decoded = json_decode($str, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('trim', $decoded)));
        }
    }
    $lines = explode("\n", $str);
    return array_values(array_filter(array_map(function($line) {
        $l = trim($line);
        $l = preg_replace('/^(\*|-|\d+\.)\s*/', '', $l);
        return trim($l);
    }, $lines)));
}

function parseThemesInput($val): ?array {
    if ($val === null) return null;
    if (is_string($val)) {
        $decoded = json_decode($val, true);
        if (is_array($decoded)) $val = $decoded;
    }
    if (!is_array($val)) return [];
    $themes = [];
    foreach ($val as $item) {
        if (!is_array($item)) continue;
        $theme = trim($item['theme'] ?? '');
        $summary = trim($item['summary'] ?? '');
        if ($theme === '' && $summary === '') continue;
        $themes[] = [
            'theme' => $theme,
            'summary' => $summary,
            'frequency' => (int)($item['frequency'] ?? 1)
        ];
    }
    return $themes;
}

// Edit analysis action (authorized administrators only)
if ($action === 'edit') {
    if (!in_array($user['role'], ['dean', 'admin1', 'admin2'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized. Only administrators can edit the evaluation analysis.']);
        exit;
    }

    if (!$existingAnalysis) {
        http_response_code(400);
        echo json_encode(['error' => 'No analysis exists yet to edit. Please generate analysis first.']);
        exit;
    }

    $currAnalysis = json_decode($existingAnalysis['analysis_json'], true) ?: [];

    $inputRaw = file_get_contents('php://input');
    $inputJson = !empty($inputRaw) ? json_decode($inputRaw, true) : null;

    $summary = $_POST['summary'] ?? ($inputJson['summary'] ?? ($_POST['overall_summary'] ?? ($inputJson['overall_summary'] ?? null)));
    if ($summary !== null) {
        $currAnalysis['overall_summary'] = trim((string)$summary);
    }

    $posThemes = parseThemesInput($_POST['positive_themes'] ?? ($inputJson['positive_themes'] ?? null));
    if ($posThemes !== null) {
        $currAnalysis['positive_themes'] = $posThemes;
    }

    $impThemes = parseThemesInput($_POST['improvement_themes'] ?? ($inputJson['improvement_themes'] ?? null));
    if ($impThemes !== null) {
        $currAnalysis['improvement_themes'] = $impThemes;
    }

    $commonSug = parseListInput($_POST['common_suggestions'] ?? ($inputJson['common_suggestions'] ?? null));
    if ($commonSug !== null) {
        $currAnalysis['common_suggestions'] = $commonSug;
    }

    $keyFindings = parseListInput($_POST['key_findings'] ?? ($inputJson['key_findings'] ?? null));
    if ($keyFindings !== null) {
        $currAnalysis['key_findings'] = $keyFindings;
    }

    $recommendations = parseListInput($_POST['recommendations'] ?? ($inputJson['recommendations'] ?? null));
    if ($recommendations !== null) {
        $currAnalysis['recommendations'] = $recommendations;
    }

    $currAnalysis['is_reviewed'] = true;
    $currAnalysis['reviewed_by'] = [
        'id'   => $user['id'],
        'name' => $user['name'],
        'role' => $user['role'],
    ];
    $currAnalysis['reviewed_at'] = date('Y-m-d H:i:s');

    $saveStmt = $db->prepare("UPDATE activity_feedback_analysis SET analysis_json = ?, updated_at = CURRENT_TIMESTAMP() WHERE activity_id = ?");
    $saveStmt->execute([json_encode($currAnalysis), $activityId]);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Analysis successfully updated by administrator.',
        'is_finalized' => !empty($currAnalysis['is_finalized']),
        'is_reviewed' => true,
        'reviewed_by' => $currAnalysis['reviewed_by'],
        'reviewed_at' => $currAnalysis['reviewed_at'],
        'analysis' => $currAnalysis
    ]);
    exit;
}

// Finalize analysis action (authorized administrators only)
if ($action === 'finalize') {
    if (!in_array($user['role'], ['dean', 'admin1', 'admin2'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized. Only administrators can finalize the evaluation analysis.']);
        exit;
    }

    if (!$existingAnalysis) {
        http_response_code(400);
        echo json_encode(['error' => 'No analysis exists yet to finalize. Please generate analysis first.']);
        exit;
    }

    $currAnalysis = json_decode($existingAnalysis['analysis_json'], true) ?: [];

    $inputRaw = file_get_contents('php://input');
    $inputJson = !empty($inputRaw) ? json_decode($inputRaw, true) : null;

    $summary = $_POST['summary'] ?? ($inputJson['summary'] ?? ($_POST['overall_summary'] ?? ($inputJson['overall_summary'] ?? null)));
    if ($summary !== null) $currAnalysis['overall_summary'] = trim((string)$summary);

    $posThemes = parseThemesInput($_POST['positive_themes'] ?? ($inputJson['positive_themes'] ?? null));
    if ($posThemes !== null) {
        $currAnalysis['positive_themes'] = $posThemes;
    }

    $impThemes = parseThemesInput($_POST['improvement_themes'] ?? ($inputJson['improvement_themes'] ?? null));
    if ($impThemes !== null) {
        $currAnalysis['improvement_themes'] = $impThemes;
    }

    $commonSug = parseListInput($_POST['common_suggestions'] ?? ($inputJson['common_suggestions'] ?? null));
    if ($commonSug !== null) {
        $currAnalysis['common_suggestions'] = $commonSug;
    }

    $keyFindings = parseListInput($_POST['key_findings'] ?? ($inputJson['key_findings'] ?? null));
    if ($keyFindings !== null) {
        $currAnalysis['key_findings'] = $keyFindings;
    }

    $recommendations = parseListInput($_POST['recommendations'] ?? ($inputJson['recommendations'] ?? null));
    if ($recommendations !== null) {
        $currAnalysis['recommendations'] = $recommendations;
    }

    $currAnalysis['is_finalized'] = true;
    $currAnalysis['is_reviewed'] = true;
    $currAnalysis['status'] = 'finalized';
    $currAnalysis['finalized_by'] = [
        'id'   => $user['id'],
        'name' => $user['name'],
        'role' => $user['role'],
    ];
    $currAnalysis['finalized_at'] = date('Y-m-d H:i:s');

    $saveStmt = $db->prepare("UPDATE activity_feedback_analysis SET analysis_json = ?, updated_at = CURRENT_TIMESTAMP() WHERE activity_id = ?");
    $saveStmt->execute([json_encode($currAnalysis), $activityId]);

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Analysis successfully finalized by administrator.',
        'is_finalized' => true,
        'is_reviewed' => true,
        'finalized_by' => $currAnalysis['finalized_by'],
        'finalized_at' => $currAnalysis['finalized_at'],
        'analysis' => $currAnalysis
    ]);
    exit;
}

if ($action === 'analyze') {
    // Determine force parameter (supports $_POST, $_GET, or raw JSON)
    $forceInput = $_POST['force'] ?? ($_GET['force'] ?? null);
    if ($forceInput === null) {
        $rawInput = file_get_contents('php://input');
        if (!empty($rawInput)) {
            $jsonInput = json_decode($rawInput, true);
            if (is_array($jsonInput) && isset($jsonInput['force'])) {
                $forceInput = $jsonInput['force'];
            }
        }
    }
    $isForce = !empty($forceInput);

    // Prevent overwriting reviewed or finalized analysis without explicit authorized force
    if (!empty($existingAnalysis)) {
        $currDecoded = json_decode($existingAnalysis['analysis_json'], true) ?: [];
        $isLocked = (!empty($currDecoded['is_finalized']) || !empty($currDecoded['is_reviewed']));
        if ($isLocked) {
            if (!$isForce) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'This evaluation analysis has been reviewed or finalized by an administrator and cannot be automatically overwritten.',
                    'is_finalized' => !empty($currDecoded['is_finalized']),
                    'is_reviewed' => !empty($currDecoded['is_reviewed']),
                    'finalized_by' => $currDecoded['finalized_by'] ?? null,
                    'finalized_at' => $currDecoded['finalized_at'] ?? null,
                    'reviewed_by' => $currDecoded['reviewed_by'] ?? null,
                    'reviewed_at' => $currDecoded['reviewed_at'] ?? null,
                ]);
                exit;
            }

            // Explicit force=1 requires administrator role (dean, admin1, admin2)
            if (!in_array($user['role'], ['dean', 'admin1', 'admin2'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Unauthorized. Only administrators can force regeneration of a reviewed or finalized analysis.']);
                exit;
            }
        }
    }

    // 1. Verify API Key is available
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    
    // Parse evaluation questions
    $questions = [];
    if (!empty($activity['evaluation_questions'])) {
        $questions = json_decode($activity['evaluation_questions'], true) ?: [];
    }

    // 2. Call Service to get qualitative / thematic analysis
    $service = new GeminiFeedbackAnalysisService($apiKey);
    $analysisResult = $service->analyzeFeedback($activity, $questions, $responses);

    $analysisResult['is_finalized'] = false;
    $analysisResult['status'] = 'ai_generated';
    $analysisJson = json_encode($analysisResult);

    try {
        // Re-check current database record to prevent stale asynchronous writes from overwriting newer reviews
        $isAuthorizedForce = ($isForce && in_array($user['role'], ['dean', 'admin1', 'admin2'], true));
        $freshCheckStmt = $db->prepare("SELECT analysis_json FROM activity_feedback_analysis WHERE activity_id = ?");
        $freshCheckStmt->execute([$activityId]);
        $freshJson = $freshCheckStmt->fetchColumn();
        if ($freshJson) {
            $freshDecoded = json_decode($freshJson, true) ?: [];
            if ((!empty($freshDecoded['is_finalized']) || !empty($freshDecoded['is_reviewed'])) && !$isAuthorizedForce) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'This evaluation analysis was reviewed or finalized by an administrator while generation was in progress and cannot be overwritten.',
                    'is_finalized' => !empty($freshDecoded['is_finalized']),
                    'is_reviewed' => !empty($freshDecoded['is_reviewed']),
                    'finalized_by' => $freshDecoded['finalized_by'] ?? null,
                    'finalized_at' => $freshDecoded['finalized_at'] ?? null,
                    'reviewed_by' => $freshDecoded['reviewed_by'] ?? null,
                    'reviewed_at' => $freshDecoded['reviewed_at'] ?? null,
                ]);
                exit;
            }
        }

        // 3. Persist analysis JSON and dataset signature hash
        $saveStmt = $db->prepare("
            INSERT INTO activity_feedback_analysis (activity_id, analysis_json, response_hash, responses_count)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                analysis_json = VALUES(analysis_json), 
                response_hash = VALUES(response_hash), 
                responses_count = VALUES(responses_count),
                updated_at = CURRENT_TIMESTAMP()
        ");
        $saveStmt->execute([
            $activityId,
            $analysisJson,
            $datasetHash,
            $responsesCount
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'is_finalized' => false,
            'last_updated' => date('Y-m-d H:i:s'),
            'responses_count' => $responsesCount,
            'analysis' => $analysisResult
        ]);
    } catch (Exception $e) {
        error_log("Database error saving AI analysis: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save analysis to database.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
exit;
