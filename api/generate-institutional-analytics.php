<?php
/**
 * generate-institutional-analytics.php
 *
 * REST JSON API endpoint for multi-activity institutional KPI analytics.
 * Strictly restricted to Dean role on the server-side.
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

// 1. Authorize role: Dean only on server-side
startSession();
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$user = currentUser();
if ($user['role'] !== 'dean') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized. Institutional AI insights are restricted to the Dean.']);
    exit;
}

$db = getDB();
$filterYear = (int)($_POST['year'] ?? $_GET['year'] ?? date('Y'));
$filterPeriod = sanitize($_POST['period'] ?? $_GET['period'] ?? 'all'); // 'all', '1st_sem', '2nd_sem'

// 2. Fetch deterministic institutional KPI summary
$summary = getInstitutionalKpiSummary($filterYear, $filterPeriod, $db);

// Handle empty state: no completed KPI data available
if (!$summary['kpi_performance']['has_data']) {
    echo json_encode([
        'status' => 'empty',
        'message' => 'No completed activity data is available for AI analysis yet.',
        'stats' => $summary
    ]);
    exit;
}

$currentHash = $summary['data_hash'];

// 3. Check pre-existing cache record
$cacheStmt = $db->prepare("SELECT * FROM institutional_kpi_analytics WHERE year = ? AND period = ?");
$cacheStmt->execute([$filterYear, $filterPeriod]);
$cache = $cacheStmt->fetch(PDO::FETCH_ASSOC);

$action = $_POST['action'] ?? 'check';

if ($action === 'check') {
    if (!$cache) {
        echo json_encode([
            'status' => 'not_generated',
            'stats' => $summary
        ]);
    } else {
        $isOutdated = ($cache['data_hash'] !== $currentHash);
        echo json_encode([
            'status' => 'generated',
            'is_outdated' => $isOutdated,
            'last_updated' => $cache['updated_at'],
            'stats' => $summary,
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
            'stats' => $summary,
            'analytics' => json_decode($cache['analytics_json'], true)
        ]);
        exit;
    }

    $service = new GeminiKpiAnalyticsService($apiKey);
    $analyticsResult = $service->analyzeInstitutionalKpis($summary);

    if ($analyticsResult === null) {
        // Handle Gemini API failure strictly: do not fabricate data!
        // Return 503 with clean error while preserving deterministic stats
        http_response_code(503);
        echo json_encode([
            'error' => 'AI insights are temporarily unavailable.',
            'stats' => $summary
        ]);
        exit;
    }

    // Persist new cache record
    try {
        // Re-query current stored data_hash to protect against stale in-flight results overwriting newer analytics
        $checkStmt = $db->prepare("SELECT data_hash FROM institutional_kpi_analytics WHERE year = ? AND period = ?");
        $checkStmt->execute([$filterYear, $filterPeriod]);
        $storedHash = $checkStmt->fetchColumn();

        if ($storedHash && $storedHash !== $currentHash) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Institutional KPI analytics have been updated with newer data while generation was in progress and cannot be overwritten.',
                'stats' => $summary
            ]);
            exit;
        }

        $saveStmt = $db->prepare("
            INSERT INTO institutional_kpi_analytics (year, period, analytics_json, data_hash)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                analytics_json = VALUES(analytics_json),
                data_hash = VALUES(data_hash),
                updated_at = CURRENT_TIMESTAMP()
        ");
        $saveStmt->execute([
            $filterYear,
            $filterPeriod,
            json_encode($analyticsResult),
            $currentHash
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'last_updated' => date('Y-m-d H:i:s'),
            'stats' => $summary,
            'analytics' => $analyticsResult
        ]);
    } catch (Exception $e) {
        error_log("Database error saving AI institutional KPI analysis: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save analysis to database.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action.']);
exit;
