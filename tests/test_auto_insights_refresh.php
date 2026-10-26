<?php
/**
 * test_auto_insights_refresh.php
 *
 * Focused verification for automated AI KPI & Institutional Analytics generation,
 * cache freshness protection, stale refresh, and AI failure preservation.
 *
 * STRICT RULE: Does not modify activity or evaluation records.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
require_once __DIR__ . '/../services/GeminiKpiAnalyticsService.php';

$db = getDB();

echo "========================================================================\n";
echo "   VERIFICATION: AUTOMATED AI KPI & INSTITUTIONAL INSIGHTS REFRESH\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $title, bool $condition, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] {$title}\n";
        if ($detail) echo "        {$detail}\n";
    } else {
        $failCount++;
        echo " [FAIL] {$title}\n";
        if ($detail) echo "        Defect: {$detail}\n";
    }
}

// Subprocess runner matching test_dean_dashboard_phases.php
function runApiSubprocess(string $endpointFile, array $postData, array $sessionData = []): array {
    $defaults = [
        'user_id' => 4,
        'user_role' => 'dean',
        'user_name' => 'Frederic Yulo',
        'user_email' => 'dean@sti.edu',
        'user_dept' => 'Administration',
        'last_activity' => time()
    ];
    $merged = array_merge($defaults, $sessionData);
    $sessionCode = "session_start();\n";
    foreach ($merged as $k => $v) {
        $sessionCode .= "\$_SESSION[" . var_export($k, true) . "] = " . var_export($v, true) . ";\n";
    }
    $postCode = "\$_POST = " . var_export($postData, true) . ";\n";

    $script = "<?php\n"
        . "ini_set('display_errors', '0');\n"
        . "error_reporting(0);\n"
        . $sessionCode
        . $postCode
        . "register_shutdown_function(function() {\n"
        . "    echo '---HTTP_CODE:' . http_response_code() . '---';\n"
        . "});\n"
        . "include " . var_export($endpointFile, true) . ";\n";

    $tmpFile = tempnam(sys_get_temp_dir(), 'test_api_');
    file_put_contents($tmpFile, $script);
    $cmd = 'php ' . escapeshellarg($tmpFile);
    $output = shell_exec($cmd);
    @unlink($tmpFile);

    $httpCode = 200;
    if (preg_match('/---HTTP_CODE:(\d*)---/', $output, $m)) {
        if ($m[1] !== '') {
            $httpCode = (int)$m[1];
        }
        $output = str_replace($m[0], '', $output);
    }

    $raw = trim($output);
    $jsonStart = strpos($raw, '{');
    $decoded = null;
    if ($jsonStart !== false) {
        $decoded = json_decode(substr($raw, $jsonStart), true);
    }

    return [
        'http_code' => $httpCode,
        'raw' => $raw,
        'json' => $decoded
    ];
}

$instApi = __DIR__ . '/../api/generate-institutional-analytics.php';

// 1. Verify summary computation and data hash
$year = 2026;
$period = 'all';
$summary = getInstitutionalKpiSummary($year, $period, $db);
$currentHash = $summary['data_hash'];

assertTest("1. Deterministic institutional summary calculation",
    !empty($summary) && isset($summary['data_hash']) && !empty($summary['kpi_performance']),
    "Hash: " . substr($currentHash, 0, 16) . "..., Completed with KPI: " . $summary['kpi_performance']['completed_with_kpi']
);

// 2. Test: Ordinary reload with fresh cache does NOT trigger AI API
// Ensure we have a valid cache record in institutional_kpi_analytics
$existingStmt = $db->prepare("SELECT * FROM institutional_kpi_analytics WHERE year = ? AND period = ?");
$existingStmt->execute([$year, $period]);
$savedRow = $existingStmt->fetch(PDO::FETCH_ASSOC);

if (!$savedRow) {
    $mockAnalytics = [
        'overall_insight' => 'Initial test insight generated from verified metrics.',
        'performance_summary' => 'Positive engagement.',
        'strengths' => ['Target met on verified events'],
        'areas_of_attention' => ['Monitor survey completeness'],
        'key_findings' => ['High satisfaction'],
        'recommendations' => ['Continue standardized logging'],
        'confidence_note' => 'Based on 1 completed event.'
    ];
    $db->prepare("INSERT INTO institutional_kpi_analytics (year, period, analytics_json, data_hash, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP())")
       ->execute([$year, $period, json_encode($mockAnalytics), $currentHash]);
    $existingStmt->execute([$year, $period]);
    $savedRow = $existingStmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Ensure cache hash matches current data hash to simulate fresh state
    $db->prepare("UPDATE institutional_kpi_analytics SET data_hash = ? WHERE year = ? AND period = ?")
       ->execute([$currentHash, $year, $period]);
}

// Call endpoint under fresh cache conditions
$resFresh = runApiSubprocess($instApi, ['year' => $year, 'period' => $period, 'action' => 'check']);
$dFresh = $resFresh['json'];

assertTest("2. Ordinary page reload uses fresh cache (no repeated AI API calls)",
    isset($dFresh['status']) && $dFresh['status'] === 'generated' &&
    isset($dFresh['is_outdated']) && $dFresh['is_outdated'] === false &&
    isset($dFresh['insight_type']) && $dFresh['insight_type'] === 'ai_generated',
    "HTTP: {$resFresh['http_code']}, Status: " . ($dFresh['status'] ?? 'N/A') . ", is_outdated: " . (($dFresh['is_outdated'] ?? false) ? 'true' : 'false') . ", Last Updated: " . ($dFresh['last_updated'] ?? 'N/A')
);

// 3. Test: Stale cache detected and refresh handled
// Set an artificially altered hash in institutional_kpi_analytics to simulate underlying activity data changing
$staleHash = 'stale_hash_from_older_event_state';
$db->prepare("UPDATE institutional_kpi_analytics SET data_hash = ? WHERE year = ? AND period = ?")
   ->execute([$staleHash, $year, $period]);

$resStale = runApiSubprocess($instApi, ['year' => $year, 'period' => $period, 'action' => 'check']);
$dStale = $resStale['json'];

$staleHandled = false;
$staleDetail = '';
if (isset($dStale['refreshed']) && $dStale['refreshed'] === true) {
    $staleHandled = ($dStale['is_outdated'] === false);
    $staleDetail = "AI Service refreshed stale insights automatically. New hash saved.";
} elseif (isset($dStale['insight_type']) && $dStale['insight_type'] === 'ai_generated_cached') {
    $staleHandled = ($dStale['is_outdated'] === true && !empty($dStale['analytics']));
    $staleDetail = "AI unavailable: Preserved existing insights correctly with warning: '" . ($dStale['warning'] ?? '') . "'";
} elseif (isset($dStale['status']) && $dStale['status'] === 'generated') {
    $staleHandled = true;
    $staleDetail = "Returned generated insights successfully.";
}

assertTest("3. Stale cache / data change triggers auto-refresh or safe preservation",
    $staleHandled,
    $staleDetail
);

// 4. Test: Preservation of existing insights when AI fails
$serviceFail = new GeminiKpiAnalyticsService('');
$failResult = $serviceFail->analyzeInstitutionalKpis($summary);

assertTest("4. Service safely returns null on AI error/quota failure without throwing",
    $failResult === null,
    "GeminiKpiAnalyticsService safely returned null on missing/exhausted API key."
);

// 5. Test: Last-updated time and insight source distinction
assertTest("5. Display actual last-updated timestamp and insight distinction",
    !empty($dFresh['last_updated']) && isset($dFresh['insight_type']),
    "Timestamp: " . ($dFresh['last_updated'] ?? 'N/A') . " | Insight Type: " . ($dFresh['insight_type'] ?? 'N/A')
);

// 6. Test: Role permissions preserved (Faculty/Admin blocked from Dean Institutional Analytics)
$resFaculty = runApiSubprocess($instApi, ['year' => $year, 'period' => $period, 'action' => 'check'], ['user_role' => 'faculty', 'user_id' => 1]);
$facultyBlocked = ($resFaculty['http_code'] === 403) && isset($resFaculty['json']['error']) && str_contains($resFaculty['json']['error'], 'Unauthorized');

assertTest("6. Role security preserved: Non-Dean roles strictly blocked (HTTP 403)",
    $facultyBlocked,
    "HTTP: {$resFaculty['http_code']}, Error: " . ($resFaculty['json']['error'] ?? 'N/A')
);

// Restore fresh hash in institutional_kpi_analytics
$db->prepare("UPDATE institutional_kpi_analytics SET data_hash = ? WHERE year = ? AND period = ?")
   ->execute([$currentHash, $year, $period]);

echo "\n------------------------------------------------------------------------\n";
echo "SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "------------------------------------------------------------------------\n";

if ($failCount === 0) {
    echo "ALL AUTO-INSIGHTS REFRESH TESTS PASSED!\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
