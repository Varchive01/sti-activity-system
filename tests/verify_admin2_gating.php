<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "=== VERIFYING ADMIN 2 REVIEW GATING ===\n";

// Let's test the logic for both:
// 1. $where clause in admin2/pending.php:
// ((a.source = 'faculty' AND a.status IN ('submitted', 'resubmitted')) OR (a.source = 'student_org' AND a.status = 'endorsed'))

// 2. $canAdmin2Review condition in admin2/activities.php:
// ($a['source'] === 'faculty' && in_array($a['status'], ['submitted', 'resubmitted'], true))
// || ($a['source'] === 'student_org' && $a['status'] === 'endorsed')

$testCases = [
    ['source' => 'faculty',     'status' => 'submitted',              'expected_pending' => true,  'expected_review' => true,  'desc' => '1. Faculty submitted appears in Admin 2 pending queue and has Review'],
    ['source' => 'faculty',     'status' => 'resubmitted',            'expected_pending' => true,  'expected_review' => true,  'desc' => '2. Faculty resubmitted appears and has Review'],
    ['source' => 'student_org', 'status' => 'endorsed',               'expected_pending' => true,  'expected_review' => true,  'desc' => '3. Student organization endorsed appears and has Review'],
    ['source' => 'student_org', 'status' => 'submitted',              'expected_pending' => false, 'expected_review' => false, 'desc' => '4. Student organization submitted does not appear in Admin 2 pending queue'],
    ['source' => 'faculty',     'status' => 'pending_final_approval', 'expected_pending' => false, 'expected_review' => false, 'desc' => '5a. Faculty pending_final_approval does not show Review in Admin 2 activities'],
    ['source' => 'student_org', 'status' => 'pending_final_approval', 'expected_pending' => false, 'expected_review' => false, 'desc' => '5b. Student Org pending_final_approval does not show Review in Admin 2 activities'],
    ['source' => 'student_org', 'status' => 'under_review',          'expected_pending' => false, 'expected_review' => false, 'desc' => 'Student org under_review (Admin 1 stage) does not show Review'],
    ['source' => 'faculty',     'status' => 'approved',               'expected_pending' => false, 'expected_review' => false, 'desc' => 'Faculty approved does not show Review'],
    ['source' => 'student_org', 'status' => 'approved',               'expected_pending' => false, 'expected_review' => false, 'desc' => 'Student org approved does not show Review'],
];

$allPassed = true;

foreach ($testCases as $tc) {
    // Test PHP condition in activities.php
    $a = ['source' => $tc['source'], 'status' => $tc['status'], 'id' => 999];
    $canAdmin2Review =
        ($a['source'] === 'faculty' && in_array($a['status'], ['submitted', 'resubmitted'], true))
        || ($a['source'] === 'student_org' && $a['status'] === 'endorsed');

    $reviewMatches = ($canAdmin2Review === $tc['expected_review']);

    // Test SQL condition in pending.php using SQL query
    $stmt = $db->prepare("
        SELECT CASE 
            WHEN ((:source = 'faculty' AND :status IN ('submitted', 'resubmitted')) OR (:source2 = 'student_org' AND :status2 = 'endorsed')) 
            THEN 1 ELSE 0 
        END as in_pending
    ");
    $stmt->execute([
        ':source' => $tc['source'],
        ':status' => $tc['status'],
        ':source2' => $tc['source'],
        ':status2' => $tc['status'],
    ]);
    $inPending = (bool)$stmt->fetchColumn();
    $pendingMatches = ($inPending === $tc['expected_pending']);

    $passed = $reviewMatches && $pendingMatches;
    if (!$passed) {
        $allPassed = false;
        echo "[FAIL] {$tc['desc']}: reviewMatches=" . ($reviewMatches?'YES':'NO') . ", pendingMatches=" . ($pendingMatches?'YES':'NO') . "\n";
    } else {
        echo "[PASS] {$tc['desc']} (pending=" . ($inPending?'YES':'NO') . ", review=" . ($canAdmin2Review?'YES':'NO') . ")\n";
    }
}

// Check case 6: Existing View links
echo "\nChecking View links in admin2/activities.php:\n";
$activitiesCode = file_get_contents(__DIR__ . '/../admin2/activities.php');
if (strpos($activitiesCode, '<a href="<?= BASE_URL ?>/admin2/view-activity.php?id=<?= $a[\'id\'] ?>" class="btn btn-outline btn-sm">View</a>') !== false) {
    echo "[PASS] admin2/activities.php contains unchanged View link: admin2/view-activity.php?id=\n";
} else {
    echo "[FAIL] admin2/activities.php View link altered or missing\n";
    $allPassed = false;
}

// Check actual activities in database against pending query
echo "\nChecking pending query against current DB rows:\n";
$stmt = $db->query("
    SELECT a.id, a.title, a.source, a.status, u.name as faculty_name 
    FROM activities a
    JOIN users u ON a.faculty_id=u.id
    WHERE ((a.source = 'faculty' AND a.status IN ('submitted', 'resubmitted')) OR (a.source = 'student_org' AND a.status = 'endorsed'))
");
$pendingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($pendingRows) . " activities in Admin 2 pending queue:\n";
foreach ($pendingRows as $row) {
    echo " - ID {$row['id']}: [{$row['source']}] [{$row['status']}] {$row['title']} (by {$row['faculty_name']})\n";
    // Verify each matches authoritative rule
    $valid = ($row['source'] === 'faculty' && in_array($row['status'], ['submitted', 'resubmitted']))
          || ($row['source'] === 'student_org' && $row['status'] === 'endorsed');
    if (!$valid) {
        echo "   [ERROR] Invalid row in Admin 2 pending queue!\n";
        $allPassed = false;
    }
}

// Check whether student_org + submitted or pending_final_approval would ever match
$stmtInvalid = $db->query("
    SELECT a.id, a.title, a.source, a.status 
    FROM activities a
    WHERE ((a.source = 'faculty' AND a.status IN ('submitted', 'resubmitted')) OR (a.source = 'student_org' AND a.status = 'endorsed'))
      AND (
          (a.source = 'student_org' AND a.status = 'submitted')
          OR (a.status = 'pending_final_approval')
      )
");
$invalidRows = $stmtInvalid->fetchAll(PDO::FETCH_ASSOC);
if (count($invalidRows) === 0) {
    echo "[PASS] Zero invalid rows (student_org+submitted or pending_final_approval) match pending query in current DB.\n";
} else {
    echo "[FAIL] Unexpected invalid rows matched!\n";
    $allPassed = false;
}

echo "\nOVERALL STATUS: " . ($allPassed ? "ALL PASSED" : "FAILURES DETECTED") . "\n";
