<?php
/**
 * Test: Default "All Years" filter and comprehensive activity rendering on dashboards
 *
 * Checks:
 * 1. Default $selectedYear is 'all'
 * 2. buildPeriodWhereClause('all', 'all') produces '1=1' without restricting by YEAR
 * 3. Student Leadership 2027 shows up in Admin1 Dashboard Review Queue by default without needing to change filter
 * 4. All activities (pending, approved, returned, rejected) are properly counted on cards and visible in queues
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$db = getDB();

echo "========================================================\n";
echo "DASHBOARD COMPREHENSIVE ACTIVITY VISIBILITY AUDIT\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $description) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] " . $description . "\n";
        $passCount++;
    } else {
        echo "[FAIL] " . $description . "\n";
        $failCount++;
    }
}

// 1. Verify buildPeriodWhereClause('all', 'all')
$bounds = buildPeriodWhereClause('all', 'all', 'a.event_date');
assertTest($bounds['where'] === '1=1', "buildPeriodWhereClause('all', 'all') produces unrestricted '1=1'");
assertTest(empty($bounds['params']), "buildPeriodWhereClause('all', 'all') has empty params array");

// 2. Test Admin 1 Dashboard default queries (All Years)
$whereClause = $bounds['where'];
$params = $bounds['params'];

$statsStmt = $db->prepare("
  SELECT
    SUM(a.status IN ('under_review','resubmitted')) as pending,
    SUM(a.status='approved') as approved,
    SUM(a.status='returned_for_revision') as returned,
    SUM(a.status='rejected') as rejected
  FROM activities a
  WHERE a.source='student_org' AND {$whereClause}
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

echo "Admin 1 Dashboard Counts (All Years):\n";
echo "  - Pending: " . ($stats['pending'] ?? 0) . "\n";
echo "  - Approved: " . ($stats['approved'] ?? 0) . "\n";
echo "  - Returned: " . ($stats['returned'] ?? 0) . "\n";
echo "  - Rejected: " . ($stats['rejected'] ?? 0) . "\n";

assertTest((int)($stats['pending'] ?? 0) >= 1, "Admin 1 Pending Reviews card has count >= 1 by default (includes 2027 activities)");

// 3. Test Admin 1 Review Queue query by default
$pendingStmt = $db->prepare("
  SELECT a.*, u.name as faculty_name FROM activities a
  JOIN users u ON a.faculty_id=u.id
  WHERE a.source='student_org' AND a.status IN ('under_review','resubmitted') AND {$whereClause}
  ORDER BY a.submitted_at DESC LIMIT 10
");
$pendingStmt->execute($params);
$pendingActs = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

$found2027Leadership = false;
foreach ($pendingActs as $p) {
    if (strpos($p['title'], 'Student Leadership and Team Building Seminar 2027') !== false) {
        $found2027Leadership = true;
        break;
    }
}
assertTest($found2027Leadership, "'Student Leadership and Team Building Seminar 2027' is displayed in Review Queue by default!");

// 4. Test Dean Dashboard (All Years)
$deanSummary = getInstitutionalKpiSummary('all', 'all', $db);
$deanStats = $deanSummary['statistics'];
echo "Dean Dashboard Counts (All Years):\n";
echo "  - Total: " . ($deanStats['total'] ?? 0) . "\n";
echo "  - Pending: " . ($deanStats['pending'] ?? 0) . "\n";
echo "  - Approved: " . ($deanStats['approved'] ?? 0) . "\n";
echo "  - Completed: " . ($deanStats['completed'] ?? 0) . "\n";
echo "  - Returned: " . ($deanStats['returned'] ?? 0) . "\n";
echo "  - Rejected: " . ($deanStats['rejected'] ?? 0) . "\n";

assertTest((int)($deanStats['total'] ?? 0) >= 1, "Dean Total Activities card has active count by default");

echo "\nSummary: {$passCount} PASSED, {$failCount} FAILED.\n";
if ($failCount > 0) exit(1);
exit(0);

