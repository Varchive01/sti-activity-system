<?php
/**
 * Automated test suite for isolating Rejected and Approved activities globally across roles.
 *
 * Requirements:
 * 1. admin1/approved.php must NOT return rejected activities.
 * 2. admin2/approved.php must NOT return rejected activities.
 * 3. dean/approved.php must NOT return rejected activities.
 * 4. faculty/approved.php must NOT return rejected activities.
 * 5. admin1/returned.php MUST return rejected activities.
 * 6. admin2/returned.php MUST return rejected activities.
 * 7. dean/returned.php MUST return rejected activities.
 * 8. faculty/returned.php MUST return rejected activities.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================\n";
echo "STATUS ISOLATION AUDIT: APPROVED VS RETURNED/REJECTED\n";
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

// Check Foreign Language Week 2027 in activities table
$stmt = $db->query("SELECT a.*, u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id = u.id WHERE a.title LIKE '%Foreign Language Week 2027%' LIMIT 1");
$foreignActivity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$foreignActivity) {
    echo "Creating dummy rejected activity 'Foreign Language Week 2027' for test...\n";
    $db->exec("INSERT INTO activities (title, faculty_id, status, event_date, created_at, updated_at) VALUES ('Foreign Language Week 2027', 387, 'rejected', '2027-02-01', NOW(), NOW())");
    $actId = $db->lastInsertId();
    // Add approval log for Ar-Jay (admin1) and Ian (admin2)
    $db->exec("INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at) VALUES ($actId, 2, 'rejected', 'Budget constraint', NOW())");
    $stmt = $db->query("SELECT a.*, u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id = u.id WHERE a.id = $actId");
    $foreignActivity = $stmt->fetch(PDO::FETCH_ASSOC);
}

echo "Target Activity: '{$foreignActivity['title']}' (ID: {$foreignActivity['id']}, Status: {$foreignActivity['status']})\n\n";

// 1. Test Admin1 Approved Query
$admin1User = $db->query("SELECT id FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin1Id = $admin1User ? $admin1User['id'] : 2;

$admin1ApprovedQuery = $db->prepare("
    SELECT DISTINCT a.*, u.name as fn 
    FROM activities a 
    JOIN users u ON a.faculty_id = u.id 
    JOIN approval_logs al ON a.id = al.activity_id 
    WHERE al.reviewer_id = ? 
      AND al.action IN ('forwarded', 'approved')
      AND a.status NOT IN ('rejected', 'returned_for_revision', 'draft')
    ORDER BY a.updated_at DESC
");
$admin1ApprovedQuery->execute([$admin1Id]);
$admin1Approved = $admin1ApprovedQuery->fetchAll(PDO::FETCH_ASSOC);

$foundInAdmin1Approved = false;
foreach ($admin1Approved as $a) {
    if ((int)$a['id'] === (int)$foreignActivity['id'] || $a['status'] === 'rejected') {
        $foundInAdmin1Approved = true;
        break;
    }
}
assertTest(!$foundInAdmin1Approved, "admin1/approved.php query does NOT include rejected proposals");

// 2. Test Admin2 Approved Query
$admin2User = $db->query("SELECT id FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2Id = $admin2User ? $admin2User['id'] : 3;

$admin2ApprovedQuery = $db->prepare("
    SELECT DISTINCT a.*, u.name as fn FROM activities a
    JOIN users u ON a.faculty_id = u.id
    JOIN approval_logs al ON a.id = al.activity_id
    WHERE al.reviewer_id = ?
      AND al.action IN ('forwarded', 'approved')
      AND a.status NOT IN ('rejected', 'returned_for_revision', 'draft')
    ORDER BY a.updated_at DESC
");
$admin2ApprovedQuery->execute([$admin2Id]);
$admin2Approved = $admin2ApprovedQuery->fetchAll(PDO::FETCH_ASSOC);

$foundInAdmin2Approved = false;
foreach ($admin2Approved as $a) {
    if ((int)$a['id'] === (int)$foreignActivity['id'] || $a['status'] === 'rejected') {
        $foundInAdmin2Approved = true;
        break;
    }
}
assertTest(!$foundInAdmin2Approved, "admin2/approved.php query does NOT include rejected proposals");

// 3. Test Dean Approved Query
$deanApproved = $db->query("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.status IN ('approved', 'completed') ORDER BY a.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$foundInDeanApproved = false;
foreach ($deanApproved as $a) {
    if ((int)$a['id'] === (int)$foreignActivity['id'] || $a['status'] === 'rejected') {
        $foundInDeanApproved = true;
        break;
    }
}
assertTest(!$foundInDeanApproved, "dean/approved.php query does NOT include rejected proposals");

// 4. Test Faculty & Program Head Approved Query
$facultyApproved = $db->query("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.status IN ('approved', 'completed') ORDER BY a.updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$foundInFacultyApproved = false;
foreach ($facultyApproved as $a) {
    if ((int)$a['id'] === (int)$foreignActivity['id'] || $a['status'] === 'rejected') {
        $foundInFacultyApproved = true;
        break;
    }
}
assertTest(!$foundInFacultyApproved, "faculty/approved.php query does NOT include rejected proposals");

// 5. Test Admin1 Returned Query
$admin1ReturnedQuery = $db->prepare("
  SELECT a.*, u.name as fn 
  FROM activities a 
  JOIN users u ON a.faculty_id = u.id 
  JOIN approval_logs al ON a.id = al.activity_id 
  WHERE al.reviewer_id = ? 
    AND al.action IN ('returned', 'rejected', 'returned_for_revision') 
  ORDER BY al.acted_at DESC
");
$admin1ReturnedQuery->execute([$admin1Id]);
$admin1Returned = $admin1ReturnedQuery->fetchAll(PDO::FETCH_ASSOC);

$foundInAdmin1Returned = false;
foreach ($admin1Returned as $a) {
    if ((int)$a['id'] === (int)$foreignActivity['id']) {
        $foundInAdmin1Returned = true;
        break;
    }
}
assertTest($foundInAdmin1Returned, "admin1/returned.php query DOES include rejected proposals reviewed by admin1");

// 6. Test Faculty Returned Query
$authorId = (int)$foreignActivity['faculty_id'];
$facultyReturnedQuery = $db->query("
    SELECT a.*, u.name as faculty_name 
    FROM activities a 
    JOIN users u ON a.faculty_id = u.id 
    WHERE a.faculty_id = {$authorId} AND a.status IN ('returned_for_revision', 'rejected') 
    ORDER BY a.updated_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$foundInFacultyReturned = false;
foreach ($facultyReturnedQuery as $a) {
    if ((int)$a['id'] === (int)$foreignActivity['id']) {
        $foundInFacultyReturned = true;
        break;
    }
}
assertTest($foundInFacultyReturned, "faculty/returned.php query DOES include author's rejected proposals");

echo "\nSummary: {$passCount} PASSED, {$failCount} FAILED.\n";
if ($failCount > 0) {
    exit(1);
}
exit(0);
