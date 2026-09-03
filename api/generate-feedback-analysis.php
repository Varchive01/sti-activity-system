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

$action = $_POST['action'] ?? 'check';

if ($action === 'check') {
    // Return comparison state
    if (!$existingAnalysis) {
        echo json_encode([
            'status' => 'not_generated',
            'responses_count' => $responsesCount
        ]);
    } else {
        $isOutdated = ($existingAnalysis['response_hash'] !== $datasetHash);
        $diff = $responsesCount - (int)$existingAnalysis['responses_count'];
        echo json_encode([
            'status' => 'generated',
            'is_outdated' => $isOutdated,
            'new_responses_count' => max(0, $diff),
            'last_updated' => $existingAnalysis['updated_at'],
            'responses_count' => $responsesCount,
            'analysis' => json_decode($existingAnalysis['analysis_json'], true)
        ]);
    }
    exit;
}

if ($action === 'analyze') {
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

    $analysisJson = json_encode($analysisResult);

    try {
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

        echo json_encode([
            'status' => 'success',
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
