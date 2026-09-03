<?php
/**
 * generate-institutional-analytics.php
 *
 * REST JSON API endpoint for multi-activity institutional KPI analytics.
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
try {
    requireRole('dean', 'admin1', 'admin2');
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

$db = getDB();
$filterYear = (int)($_POST['year'] ?? $_GET['year'] ?? date('Y'));
$filterPeriod = sanitize($_POST['period'] ?? $_GET['period'] ?? 'all'); // 'all', '1st_sem', '2nd_sem'

// Build period bounds
$where = "YEAR(a.event_date) = ?";
$params = [$filterYear];

if ($filterPeriod === '1st_sem') {
    // 1st Sem typically June to October
    $where .= " AND MONTH(a.event_date) BETWEEN 6 AND 10";
} elseif ($filterPeriod === '2nd_sem') {
    // 2nd Sem typically November to March
    $where .= " AND (MONTH(a.event_date) >= 11 OR MONTH(a.event_date) <= 3)";
}

// 2. Fetch completed activities and their metrics
$query = "
    SELECT a.id, a.title, a.event_date, a.status,
           pe.actual_attendance, pe.target_attendance, pe.satisfaction_score,
           AVG(k.rating) as avg_kpi
    FROM activities a
    LEFT JOIN post_event pe ON a.id = pe.activity_id
    LEFT JOIN kpi_evaluations k ON a.id = k.activity_id
    WHERE {$where} AND a.status IN ('approved', 'completed')
    GROUP BY a.id
    ORDER BY a.event_date DESC
";
$stmt = $db->prepare($query);
$stmt->execute($params);
$rawActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalActivities = count($rawActivities);
if ($totalActivities === 0) {
    echo json_encode([
        'status' => 'empty',
        'message' => 'No completed activity data is available for AI analysis yet.'
    ]);
    exit;
}

// 3. Compute aggregate KPI statistics deterministically
$completedCount = 0;
$kpiRatingsSum = 0;
$kpiRatingsCount = 0;
$satisfactionSum = 0;
$satisfactionCount = 0;
$attendanceActualSum = 0;
$attendanceTargetSum = 0;

$meetingTargets = 0;
$belowTargets = 0;
$listActivities = [];

foreach ($rawActivities as $act) {
    if ($act['status'] === 'completed') {
        $completedCount++;
    }

    $avgKpi = $act['avg_kpi'] !== null ? (float)$act['avg_kpi'] : null;
    $satisfaction = $act['satisfaction_score'] !== null ? (float)$act['satisfaction_score'] : null;
    $actualAtt = $act['actual_attendance'] !== null ? (int)$act['actual_attendance'] : null;
    $targetAtt = $act['target_attendance'] !== null ? (int)$act['target_attendance'] : null;

    if ($avgKpi !== null) {
        $kpiRatingsSum += $avgKpi;
        $kpiRatingsCount++;
    }
    if ($satisfaction !== null) {
        $satisfactionSum += $satisfaction;
        $satisfactionCount++;
    }
    if ($actualAtt !== null && $targetAtt !== null && $targetAtt > 0) {
        $attendanceActualSum += $actualAtt;
        $attendanceTargetSum += $targetAtt;
    }

    // Determine performance status of this activity to compute meets/below totals
    $kpiDetails = calculateActivityKpis((int)$act['id'], $db);
    if (!empty($kpiDetails) && $kpiDetails['overall_performance'] !== null) {
        if ($kpiDetails['overall_performance'] >= 90.0) {
            $meetingTargets++;
        } else {
            $belowTargets++;
        }
    }

    $listActivities[] = [
        'id' => $act['id'],
        'title' => $act['title'],
        'event_date' => $act['event_date'],
        'avg_kpi' => $avgKpi !== null ? number_format($avgKpi, 2) : '—',
        'satisfaction_score' => $satisfaction !== null ? number_format($satisfaction, 1) : '—',
        'attendance_rate' => ($targetAtt !== null && $targetAtt > 0 && $actualAtt !== null) ? round(($actualAtt / $targetAtt) * 100, 1) : '—'
    ];
}

$avgKpiRating = $kpiRatingsCount > 0 ? round($kpiRatingsSum / $kpiRatingsCount, 2) : null;
$avgSatisfaction = $satisfactionCount > 0 ? round($satisfactionSum / $satisfactionCount, 1) : null;
$overallAttendanceRate = $attendanceTargetSum > 0 ? round(($attendanceActualSum / $attendanceTargetSum) * 100, 1) : null;

$aggregateData = [
    'year' => $filterYear,
    'period' => $filterPeriod,
    'total_activities' => $totalActivities,
    'completed_activities' => $completedCount,
    'avg_kpi_rating' => $avgKpiRating !== null ? $avgKpiRating : '—',
    'avg_satisfaction' => $avgSatisfaction !== null ? $avgSatisfaction : '—',
    'overall_attendance_rate' => $overallAttendanceRate !== null ? $overallAttendanceRate : '—',
    'meeting_targets' => $meetingTargets,
    'below_targets' => $belowTargets,
    'activities' => $listActivities
];

// Generate deterministic data signature hash for cache detection
$hashInput = json_encode([
    'year' => $filterYear,
    'period' => $filterPeriod,
    'total_activities' => $totalActivities,
    'completed_activities' => $completedCount,
    'meeting_targets' => $meetingTargets,
    'below_targets' => $belowTargets,
    'avg_kpi_rating' => $avgKpiRating,
    'avg_satisfaction' => $avgSatisfaction,
    'overall_attendance_rate' => $overallAttendanceRate
]);
$currentHash = hash('sha256', $hashInput);

// 4. Fetch pre-existing cache record
$cacheStmt = $db->prepare("SELECT * FROM institutional_kpi_analytics WHERE year = ? AND period = ?");
$cacheStmt->execute([$filterYear, $filterPeriod]);
$cache = $cacheStmt->fetch(PDO::FETCH_ASSOC);

$action = $_POST['action'] ?? 'check';

if ($action === 'check') {
    if (!$cache) {
        echo json_encode([
            'status' => 'not_generated',
            'stats' => $aggregateData
        ]);
    } else {
        $isOutdated = ($cache['data_hash'] !== $currentHash);
        echo json_encode([
            'status' => 'generated',
            'is_outdated' => $isOutdated,
            'last_updated' => $cache['updated_at'],
            'stats' => $aggregateData,
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
            'stats' => $aggregateData,
            'analytics' => json_decode($cache['analytics_json'], true)
        ]);
        exit;
    }

    $service = new GeminiKpiAnalyticsService($apiKey);
    $analyticsResult = $service->analyzeInstitutionalKpis($aggregateData);

    if ($analyticsResult === null) {
        // Do not fabricate AI insights if Gemini fails
        http_response_code(503);
        echo json_encode([
            'error' => 'AI insights are temporarily unavailable.',
            'stats' => $aggregateData
        ]);
        exit;
    }

    // Persist new cache record
    try {
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

        echo json_encode([
            'status' => 'success',
            'last_updated' => date('Y-m-d H:i:s'),
            'stats' => $aggregateData,
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
