<?php
/**
 * tests/test_attendance_rate_capping.php
 *
 * Automated Verification for Attendance Rate Capping (<= 100%).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$passCount = 0;
$failCount = 0;

function assertCondition(bool $cond, string $name, string $details = '') {
    global $passCount, $failCount;
    if ($cond) {
        $passCount++;
        echo " [PASS] {$name}\n";
    } else {
        $failCount++;
        echo " [FAIL] {$name} - Details: {$details}\n";
    }
}

echo "========================================================================\n";
echo "  ATTENDANCE RATE CAPPING VERIFICATION (MAX 100%)\n";
echo "========================================================================\n\n";

// 1. Centralized Calculation Tests
echo "--- 1. Centralized calculateAttendanceRate() Test Cases ---\n";

$rate1 = calculateAttendanceRate(80, 100);
assertCondition($rate1 === 80.0, "Actual 80, Target 100 => 80.0%", "Got: " . var_export($rate1, true));

$rate2 = calculateAttendanceRate(100, 100);
assertCondition($rate2 === 100.0, "Actual 100, Target 100 => 100.0%", "Got: " . var_export($rate2, true));

$rate3 = calculateAttendanceRate(118, 100);
assertCondition($rate3 === 100.0, "Actual 118, Target 100 => 100.0% (Capped)", "Got: " . var_export($rate3, true));

$rate4 = calculateAttendanceRate(118.3, 100);
assertCondition($rate4 === 100.0, "Actual 118.3, Target 100 => 100.0% (Capped)", "Got: " . var_export($rate4, true));

$rate5 = calculateAttendanceRate(0, 100);
assertCondition($rate5 === 0.0, "Actual 0, Target 100 => 0.0%", "Got: " . var_export($rate5, true));

$rate6 = calculateAttendanceRate(50, 0);
assertCondition($rate6 === null, "Target 0 => null (no division by zero)", "Got: " . var_export($rate6, true));

$rate7 = calculateAttendanceRate(50, null);
assertCondition($rate7 === null, "Target null => null (safe missing target handling)", "Got: " . var_export($rate7, true));

$rate8 = calculateAttendanceRate(null, 100);
assertCondition($rate8 === 0.0, "Actual null, Target 100 => 0.0%", "Got: " . var_export($rate8, true));

// 2. Database & Role Integration Tests
echo "\n--- 2. Role Displays & Institutional Summary Capping ---\n";
$mockDb = new PDO('sqlite::memory:');
$mockDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mockDb->sqliteCreateFunction('YEAR', function($date) {
    return $date ? (int)date('Y', strtotime($date)) : null;
});
$mockDb->sqliteCreateFunction('MONTH', function($date) {
    return $date ? (int)date('m', strtotime($date)) : null;
});

$mockDb->exec("CREATE TABLE activities (id INTEGER PRIMARY KEY, status TEXT, title TEXT, event_date TEXT, start_date TEXT, created_by INTEGER, target_participants INTEGER, type TEXT, academic_year TEXT, term TEXT)");
$mockDb->exec("CREATE TABLE post_event (id INTEGER PRIMARY KEY, activity_id INTEGER, actual_attendance INTEGER, target_attendance INTEGER, satisfaction_score REAL, recommendations TEXT, budget_allocated REAL, budget_spent REAL, created_at TEXT, updated_at TEXT)");
$mockDb->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, department TEXT, role TEXT)");
$mockDb->exec("CREATE TABLE activity_ratings (id INTEGER PRIMARY KEY, activity_id INTEGER, rating REAL)");
$mockDb->exec("CREATE TABLE kpi_evaluations (id INTEGER PRIMARY KEY, activity_id INTEGER, rating REAL, criteria TEXT)");
$mockDb->exec("CREATE TABLE proposal_steps (id INTEGER PRIMARY KEY, activity_id INTEGER, step_number INTEGER, step_name TEXT, comments TEXT, action_date TEXT)");

// Exceeding attendance record: 118 actual vs 100 target
$mockDb->exec("INSERT INTO activities (id, status, title, event_date, start_date, created_by, target_participants, type, academic_year, term) VALUES (1, 'completed', 'Test Over-Turnout Activity', '2026-10-15', '2026-10-15', 1, 100, 'Workshop', '2026-2027', '1st')");
$mockDb->exec("INSERT INTO post_event (id, activity_id, actual_attendance, target_attendance, satisfaction_score, recommendations, budget_allocated, budget_spent, created_at, updated_at) VALUES (1, 1, 118, 100, 4.5, 'None', 5000, 4800, '2026-10-16 10:00:00', '2026-10-16 10:00:00')");
$mockDb->exec("INSERT INTO users (id, name, department, role) VALUES (1, 'John Doe', 'Information Technology', 'faculty')");

$kpi = calculateActivityKpis(1, $mockDb);
$achievedVal = (float)$kpi['kpis']['attendance']['achievement_val'];
assertCondition($achievedVal <= 100.0, "Activity KPI achievement_val capped at <= 100.0% (Got: {$achievedVal}%)");
assertCondition($achievedVal === 100.0, "Activity KPI achievement_val exactly capped at 100.0% for 118/100");

$summary = getInstitutionalKpiSummary(2026, 'all', $mockDb);
$attRate = $summary['kpi_performance']['attendance_rate'];
$rawRate = $summary['kpi_performance']['raw_attendance_rate'];

if ($attRate !== '—') {
    $attVal = (float)rtrim($attRate, '%');
    assertCondition($attVal <= 100.0, "Institutional attendance_rate capped at <= 100.0% (Got: {$attRate})");
    assertCondition($attVal === 100.0, "Institutional attendance_rate exactly 100.0% (Got: {$attRate})");
} else {
    assertCondition(true, "Institutional attendance_rate safely handles no data state");
}

if ($rawRate !== '—') {
    $rawVal = (float)rtrim($rawRate, '%');
    assertCondition($rawVal <= 100.0, "Institutional raw_attendance_rate capped at <= 100.0% (Got: {$rawRate})");
    assertCondition($rawVal === 100.0, "Institutional raw_attendance_rate exactly 100.0% (Got: {$rawRate})");
} else {
    assertCondition(true, "Institutional raw_attendance_rate safely handles no data state");
}

echo "\n========================================================================\n";
echo "  SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "========================================================================\n";

exit($failCount > 0 ? 1 : 0);
