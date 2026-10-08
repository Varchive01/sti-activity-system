<?php
/**
 * generate-kpi-analytics.php
 *
 * REST JSON API endpoint for individual activity KPI analytics.
 */

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
require_once __DIR__ . '/../services/GeminiKpiAnalyticsService.php';

header('Content-Type: application/json');

// 1. Authorize role
startSession();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$user = currentUser();
if (!in_array($user['role'], ['dean', 'admin1', 'admin2', 'faculty'], true)) {
    http_response_code(403);
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

// 2. Fetch Activity details
$actStmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
$actStmt->execute([$activityId]);
$activity = $actStmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    http_response_code(404);
    echo json_encode(['error' => 'Activity not found.']);
    exit;
}

// Faculty can only access KPI results for their own activities
if ($user['role'] === 'faculty' && (int)$activity['faculty_id'] !== (int)$user['id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized. You can only view KPI analytics for your own activities.']);
    exit;
}

// 3. Compute current deterministic KPI numbers
$kpiResults = calculateActivityKpis($activityId, $db);
if (empty($kpiResults)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to calculate KPI metrics.']);
    exit;
}

if (!$kpiResults['has_data']) {
    echo json_encode([
        'status' => 'empty',
        'message' => 'No completed activity KPI data is available for AI analysis yet.'
    ]);
    exit;
}

$currentHash = $kpiResults['data_hash'];

// 4. Fetch pre-existing cached analysis
$cacheStmt = $db->prepare("SELECT * FROM activity_kpi_analytics WHERE activity_id = ?");
$cacheStmt->execute([$activityId]);
$cache = $cacheStmt->fetch(PDO::FETCH_ASSOC);

$action = $_POST['action'] ?? 'check';

if ($action === 'check') {
    if (!$cache) {
        echo json_encode([
            'status' => 'not_generated',
            'kpis' => $kpiResults['kpis'],
            'overall_performance' => $kpiResults['overall_performance'],
            'overall_status' => $kpiResults['overall_status']
        ]);
    } else {
        $isOutdated = ($cache['data_hash'] !== $currentHash);
        echo json_encode([
            'status' => 'generated',
            'is_outdated' => $isOutdated,
            'last_updated' => $cache['updated_at'],
            'kpis' => $kpiResults['kpis'],
            'overall_performance' => $kpiResults['overall_performance'],
            'overall_status' => $kpiResults['overall_status'],
            'analytics' => json_decode($cache['analytics_json'], true)
        ]);
    }
    exit;
}

if ($action === 'analyze') {
    $apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
    
    // Check if cache is already fresh
    if ($cache && $cache['data_hash'] === $currentHash && !isset($_POST['force'])) {
        echo json_encode([
            'status' => 'success',
            'last_updated' => $cache['updated_at'],
            'kpis' => $kpiResults['kpis'],
            'overall_performance' => $kpiResults['overall_performance'],
            'overall_status' => $kpiResults['overall_status'],
            'analytics' => json_decode($cache['analytics_json'], true)
        ]);
        exit;
    }

    // Fetch existing qualitative feedback analysis for prompt context
    $feedbackStmt = $db->prepare("SELECT analysis_json FROM activity_feedback_analysis WHERE activity_id = ?");
    $feedbackStmt->execute([$activityId]);
    $feedbackRow = $feedbackStmt->fetch(PDO::FETCH_ASSOC);
    $feedbackAnalysis = $feedbackRow ? json_decode($feedbackRow['analysis_json'], true) : null;

    $service = new GeminiKpiAnalyticsService($apiKey);
    $analyticsResult = $service->analyzeActivityKpis($kpiResults, $feedbackAnalysis);

    if ($analyticsResult === null) {
        // Handle Gemini API failure strictly: do not fabricate data!
        http_response_code(503);
        echo json_encode([
            'error' => 'AI insights are temporarily unavailable.',
            'kpis' => $kpiResults['kpis'],
            'overall_performance' => $kpiResults['overall_performance'],
            'overall_status' => $kpiResults['overall_status']
        ]);
        exit;
    }

    // Persist fresh analysis JSON & signature hash
    try {
        // Re-query current stored data_hash to protect against stale in-flight results overwriting newer analytics
        $checkStmt = $db->prepare("SELECT data_hash FROM activity_kpi_analytics WHERE activity_id = ?");
        $checkStmt->execute([$activityId]);
        $storedHash = $checkStmt->fetchColumn();

        if ($storedHash && $storedHash !== $currentHash) {
            http_response_code(409);
            echo json_encode([
                'error' => 'KPI analytics have been updated with newer data while generation was in progress and cannot be overwritten.',
                'kpis' => $kpiResults['kpis'],
                'overall_performance' => $kpiResults['overall_performance'],
                'overall_status' => $kpiResults['overall_status']
            ]);
            exit;
        }

        $saveStmt = $db->prepare("
            INSERT INTO activity_kpi_analytics (activity_id, analytics_json, data_hash)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                analytics_json = VALUES(analytics_json),
                data_hash = VALUES(data_hash),
                updated_at = CURRENT_TIMESTAMP()
        ");
        $saveStmt->execute([
            $activityId,
            json_encode($analyticsResult),
            $currentHash
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'last_updated' => date('Y-m-d H:i:s'),
            'kpis' => $kpiResults['kpis'],
            'overall_performance' => $kpiResults['overall_performance'],
            'overall_status' => $kpiResults['overall_status'],
            'analytics' => $analyticsResult
        ]);
    } catch (Exception $e) {
        error_log("Database error saving AI KPI analysis: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save analysis to database.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
exit;
