<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$db = getDB();

echo "========================================================\n";
echo "DASHBOARD STATS & QUEUE YEAR AUDIT (2026 & 2027)\n";
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

// 1. Ensure 'Student Leadership and Team Building Seminar 2027' exists in local DB with event_date in 2027
$stmt = $db->query("SELECT * FROM activities WHERE title LIKE '%Student Leadership and Team Building Seminar 2027%' LIMIT 1");
$leadershipAct = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leadershipAct) {
    echo "Creating 'Student Leadership and Team Building Seminar 2027' for test...\n";
    $db->prepare("
        INSERT INTO activities (title, faculty_id, source, status, event_date, venue, target_participants, theme, submitted_at, created_at, updated_at)
        VALUES ('Student Leadership and Team Building Seminar 2027', 599, 'student_org', 'under_review', '2027-02-12', 'STI College Marikina Auditorium', 95, 'Empowering Future Innovators: Lead with Purpose, Build as One', NOW(), NOW(), NOW())
    ")->execute();
    $stmt = $db->query("SELECT * FROM activities WHERE title LIKE '%Student Leadership and Team Building Seminar 2027%' LIMIT 1");
    $leadershipAct = $stmt->fetch(PDO::FETCH_ASSOC);
}

assertTest(!empty($leadershipAct), "Student Leadership activity exists in database (ID: {$leadershipAct['id']})");

// 2. Test Admin1 Dashboard query when filtered by year 2027
$year = 2027;
$periodBounds = buildPeriodWhereClause($year, 'all', 'a.event_date');
$whereClause = $periodBounds['where'];
$params = $periodBounds['params'];

$statsStmt = $db->prepare("
  SELECT
    SUM(a.status IN ('under_review','resubmitted')) as pending,
    SUM(a.status='approved') as approved,
    SUM(a.status='returned_for_revision') as returned,
    SUM(a.status='rejected') as rejected
  FROM activities a
  WHERE {$whereClause}
");
$statsStmt->execute($params);
$stats2027 = $statsStmt->fetch() ?: [];

assertTest((int)($stats2027['pending'] ?? 0) >= 1, "Admin1 stat card shows pending activities for 2027 (found: " . ($stats2027['pending'] ?? 0) . ")");

// 3. Test Admin1 Review Queue query for 2027
$pendingStmt = $db->prepare("
  SELECT a.*, u.name as faculty_name FROM activities a
  JOIN users u ON a.faculty_id=u.id
  WHERE a.status IN ('under_review','resubmitted') AND {$whereClause}
  ORDER BY a.submitted_at DESC LIMIT 10
");
$pendingStmt->execute($params);
$pending2027 = $pendingStmt->fetchAll();

$foundInQueue = false;
foreach ($pending2027 as $p) {
    if ((int)$p['id'] === (int)$leadershipAct['id']) {
        $foundInQueue = true;
        break;
    }
}
assertTest($foundInQueue, "Admin1 Review Queue displays 'Student Leadership and Team Building Seminar 2027' when viewing 2027");

// 4. Verify that availableYears on Dashboards contains 2027
$dbYears = $db->query("SELECT DISTINCT YEAR(event_date) as yr FROM activities WHERE event_date IS NOT NULL AND event_date != '0000-00-00' ORDER BY yr DESC")->fetchAll(PDO::FETCH_COLUMN);
$availableYears = array_values(array_unique(array_merge([(int)date('Y'), (int)date('Y') + 1], array_map('intval', $dbYears))));
assertTest(in_array(2027, $availableYears), "Available years dynamically includes 2027: " . implode(', ', $availableYears));

echo "\nSummary: {$passCount} PASSED, {$failCount} FAILED.\n";
if ($failCount > 0) exit(1);
exit(0);

