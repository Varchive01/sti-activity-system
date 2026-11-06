<?php
// tests/test_department_activities_filtering.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "======================================================================\n";
echo " DEPARTMENT ACTIVITIES QUERY & TITLE VERIFICATION SUITE\n";
echo "======================================================================\n";

$db = getDB();
$pass = 0;
$fail = 0;

function assertCheck($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo " [PASS] $msg\n";
        $pass++;
    } else {
        echo " [FAIL] $msg\n";
        $fail++;
    }
}

// 1. Find a Program Head
$phStmt = $db->query("SELECT id, name, role, department FROM users WHERE role = 'program_head' LIMIT 1");
$phUser = $phStmt->fetch(PDO::FETCH_ASSOC);

if (!$phUser) {
    echo "No program head found in database.\n";
    exit(1);
}

$userDept = resolveUserDepartment($db, $phUser);
$deptCond = buildDepartmentSqlCondition('u_creator.department', $userDept);
$deptStatusCond = "a.status NOT IN ('draft', 'submitted', 'under_review', 'resubmitted')";

$hasCollabTable = true;
try {
    $db->query("SELECT 1 FROM proposal_collaborators LIMIT 1");
} catch (\PDOException $e) {
    $hasCollabTable = false;
}

$where = "(
    (
        a.faculty_id = ?
        OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active')
        OR EXISTS (SELECT 1 FROM users u_creator WHERE u_creator.id = a.faculty_id AND {$deptCond})
    )
    AND {$deptStatusCond}
)";

// Create sample test activities for this department across various statuses
$testActs = [
    ['title' => 'Test PH Draft Event', 'status' => 'draft'],
    ['title' => 'Test PH Under Review Event', 'status' => 'under_review'],
    ['title' => 'Test PH Submitted Event', 'status' => 'submitted'],
    ['title' => 'Test PH Approved Event', 'status' => 'approved'],
    ['title' => 'Test PH Completed Event', 'status' => 'completed'],
    ['title' => 'Test PH Endorsed Event', 'status' => 'endorsed'],
];

$insertedIds = [];
$insStmt = $db->prepare("
    INSERT INTO activities (faculty_id, title, description, venue, event_date, status, created_at, updated_at)
    VALUES (?, ?, 'Test description', 'Campus Room', '2026-12-10', ?, NOW(), NOW())
");

foreach ($testActs as $item) {
    $insStmt->execute([$phUser['id'], $item['title'], $item['status']]);
    $insertedIds[] = (int)$db->lastInsertId();
}

// Execute Program Head query
$queryStmt = $db->prepare("SELECT a.id, a.title, a.status FROM activities a WHERE $where");
$queryStmt->execute([$phUser['id'], $phUser['id']]);
$results = $queryStmt->fetchAll(PDO::FETCH_ASSOC);

$resultStatuses = array_column($results, 'status');
$resultTitles = array_column($results, 'title');

assertCheck(!in_array('draft', $resultStatuses, true), "Program Head results do NOT contain 'draft' activities.");
assertCheck(!in_array('under_review', $resultStatuses, true), "Program Head results do NOT contain 'under_review' activities.");
assertCheck(!in_array('submitted', $resultStatuses, true), "Program Head results do NOT contain 'submitted' activities.");

assertCheck(in_array('Test PH Approved Event', $resultTitles, true), "Program Head results DO contain approved activities.");
assertCheck(in_array('Test PH Completed Event', $resultTitles, true), "Program Head results DO contain completed activities.");
assertCheck(in_array('Test PH Endorsed Event', $resultTitles, true), "Program Head results DO contain endorsed activities.");

// Test title rendering logic
$phTitle = ($phUser['role'] === 'program_head') ? 'Department Activities' : 'My Activities';
assertCheck($phTitle === 'Department Activities', "Program head top bar title resolves to 'Department Activities'.");

$facUser = ['role' => 'faculty'];
$facTitle = ($facUser['role'] === 'program_head') ? 'Department Activities' : 'My Activities';
assertCheck($facTitle === 'My Activities', "Faculty top bar title resolves to 'My Activities'.");

// Clean up test rows
if (!empty($insertedIds)) {
    $inClause = implode(',', $insertedIds);
    $db->exec("DELETE FROM activities WHERE id IN ({$inClause})");
}

echo "======================================================================\n";
echo " SUMMARY: Passed: $pass | Failed: $fail\n";
echo "======================================================================\n";

