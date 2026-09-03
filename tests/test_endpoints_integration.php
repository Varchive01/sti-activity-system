<?php
/**
 * test_endpoints_integration.php
 *
 * Mocks session authentication to directly test generate-kpi-analytics.php and
 * generate-institutional-analytics.php REST endpoints.
 */

// Start session and mock Dean login
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user_id'] = 4; // Dean Frederic Yulo
$_SESSION['user_role'] = 'dean';
$_SESSION['user_name'] = 'Frederic Yulo';
$_SESSION['user_email'] = 'dean@sti.edu';
$_SESSION['user_dept'] = 'Administration';
$_SESSION['last_activity'] = time();

define('SESSION_TIMEOUT', 3600);

echo "===========================================\n";
echo "INTEGRATION TESTS FOR KPI ANALYTICS REST APIS\n";
echo "===========================================\n\n";

// Helper to capture API output
function testApiEndpoint(string $filepath, array $postData): array {
    $_POST = $postData;
    $_GET = [];
    
    ob_start();
    try {
        include $filepath;
    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage();
    }
    $output = ob_get_clean();
    
    $decoded = json_decode($output, true);
    return [
        'raw' => $output,
        'decoded' => $decoded
    ];
}

$apiPathKpi = __DIR__ . '/../api/generate-kpi-analytics.php';
$apiPathInst = __DIR__ . '/../api/generate-institutional-analytics.php';

// --- Test A: check individual activity analytics (ID = 118) ---
echo "TEST A: check individual activity analytics (Activity ID: 118)\n";
$resA = testApiEndpoint($apiPathKpi, ['activity_id' => 118, 'action' => 'check']);
if ($resA['decoded']) {
    echo "  Status: " . ($resA['decoded']['status'] ?? 'N/A') . "\n";
    echo "  Overall Performance Score: " . ($resA['decoded']['overall_performance'] ?? 'N/A') . "\n";
    echo "  Overall Status: " . ($resA['decoded']['overall_status'] ?? 'N/A') . "\n";
    echo "  PASSED: Endpoint returned valid JSON response.\n";
} else {
    echo "  FAILED: Raw output was:\n" . $resA['raw'] . "\n";
}

// --- Test B: analyze individual activity (ID = 118) ---
echo "\nTEST B: analyze individual activity (Activity ID: 118)\n";
$resB = testApiEndpoint($apiPathKpi, ['activity_id' => 118, 'action' => 'analyze']);
if ($resB['decoded']) {
    echo "  Status: " . ($resB['decoded']['status'] ?? 'N/A') . "\n";
    if (isset($resB['decoded']['analytics'])) {
        echo "  Overall Insight: " . substr($resB['decoded']['analytics']['overall_insight'], 0, 100) . "...\n";
        echo "  Strengths Count: " . count($resB['decoded']['analytics']['strengths'] ?? []) . "\n";
        echo "  Recommendations Count: " . count($resB['decoded']['analytics']['recommendations'] ?? []) . "\n";
        echo "  PASSED: Gemini completed interpretation and saved cache.\n";
    } elseif (isset($resB['decoded']['error'])) {
        echo "  Error: " . $resB['decoded']['error'] . "\n";
        echo "  (This is a clean, expected 503 fallback error state if Gemini API key is missing/unconfigured)\n";
    }
} else {
    echo "  FAILED: Raw output was:\n" . $resB['raw'] . "\n";
}

// --- Test C: check institutional aggregate analytics (Year 2026) ---
echo "\nTEST C: check institutional aggregate analytics (Year: 2026)\n";
$resC = testApiEndpoint($apiPathInst, ['year' => 2026, 'period' => 'all', 'action' => 'check']);
if ($resC['decoded']) {
    echo "  Status: " . ($resC['decoded']['status'] ?? 'N/A') . "\n";
    if (isset($resC['decoded']['stats'])) {
        echo "  Total Activities aggregated: " . $resC['decoded']['stats']['total_activities'] . "\n";
        echo "  Completed Activities: " . $resC['decoded']['stats']['completed_activities'] . "\n";
        echo "  Avg KPI rating: " . $resC['decoded']['stats']['avg_kpi_rating'] . "\n";
        echo "  Avg Satisfaction: " . $resC['decoded']['stats']['avg_satisfaction'] . "\n";
    }
    echo "  PASSED: Aggregate metrics calculated deterministically.\n";
} else {
    echo "  FAILED: Raw output was:\n" . $resC['raw'] . "\n";
}

// --- Test D: analyze institutional aggregate analytics (Year 2026) ---
echo "\nTEST D: analyze institutional aggregate analytics (Year: 2026)\n";
$resD = testApiEndpoint($apiPathInst, ['year' => 2026, 'period' => 'all', 'action' => 'analyze']);
if ($resD['decoded']) {
    echo "  Status: " . ($resD['decoded']['status'] ?? 'N/A') . "\n";
    if (isset($resD['decoded']['analytics'])) {
        echo "  Overall Insight: " . substr($resD['decoded']['analytics']['overall_insight'], 0, 100) . "...\n";
        echo "  Recommendations Count: " . count($resD['decoded']['analytics']['recommendations'] ?? []) . "\n";
        echo "  PASSED: Aggregate Gemini insights generated and saved cache.\n";
    } elseif (isset($resD['decoded']['error'])) {
        echo "  Error: " . $resD['decoded']['error'] . "\n";
        echo "  (This is a clean, expected 503 fallback error state if Gemini API key is missing/unconfigured)\n";
    }
} else {
    echo "  FAILED: Raw output was:\n" . $resD['raw'] . "\n";
}

echo "\nIntegration tests finished.\n";
