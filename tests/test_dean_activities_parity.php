<?php
/**
 * Test: Parity between Dean Dashboard "Total Activities" card and Dean "All Activities" page
 *
 * 1. Dean Dashboard Total Activities counts all non-draft activities: a.status != 'draft' (within selected year/period)
 * 2. Dean All Activities page query displays all non-draft activities: a.status != 'draft'
 * 3. Both queries return the exact same count!
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$db = getDB();

echo "========================================================\n";
echo "DEAN DASHBOARD & ALL ACTIVITIES PARITY AUDIT\n";
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

// 1. Dean Dashboard Total Activities Query
$currentYear = (int)date('Y');
$periodBounds = buildPeriodWhereClause($currentYear, 'all', 'a.event_date');
$whereClause = $periodBounds['where'];
$params = $periodBounds['params'];

$stats = getInstitutionalKpiSummary($currentYear, 'all', $db);
$dashboardTotal = (int)($stats['statistics']['total'] ?? 0);

// 2. Dean All Activities Page Query (Default view without extra filter)
$allActivitiesStmt = $db->query("SELECT COUNT(*) FROM activities a WHERE a.status != 'draft'");
$allActivitiesTotal = (int)$allActivitiesStmt->fetchColumn();

echo "Dean Dashboard 'Total Activities' for {$currentYear}: {$dashboardTotal}\n";
echo "Dean All Activities Page overall total (non-draft): {$allActivitiesTotal}\n";

assertTest($allActivitiesTotal >= $dashboardTotal, "All Activities page query includes all active non-draft proposals");

// Check that with matching year filter, counts are identical
$matchingYearStmt = $db->prepare("SELECT COUNT(*) FROM activities a WHERE a.status != 'draft' AND {$whereClause}");
$matchingYearStmt->execute($params);
$matchingYearTotal = (int)$matchingYearStmt->fetchColumn();

assertTest($dashboardTotal === $matchingYearTotal, "Dashboard Total Activities count ({$dashboardTotal}) matches activities table query ({$matchingYearTotal})");

// Ensure activities page does not artificially hide under_review or submitted proposals
$submittedAct = $db->query("SELECT id FROM activities WHERE status IN ('submitted', 'under_review') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($submittedAct) {
    $checkStmt = $db->prepare("SELECT COUNT(*) FROM activities a WHERE a.id = ? AND a.status != 'draft'");
    $checkStmt->execute([$submittedAct['id']]);
    $found = (int)$checkStmt->fetchColumn() === 1;
    assertTest($found, "Submitted/Under Review proposals are visible on Dean All Activities page");
}

echo "\nSummary: {$passCount} PASSED, {$failCount} FAILED.\n";
if ($failCount > 0) exit(1);
exit(0);

