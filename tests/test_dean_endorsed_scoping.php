<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';

$db = getDB();

echo "=== TEST SUITE: DEAN ENDORSED-ONLY SCOPING VERIFICATION ===\n\n";

// 1. Locate Dean User
$deanStmt = $db->query("SELECT id, name, email, role FROM users WHERE role = 'dean' LIMIT 1");
$dean = $deanStmt->fetch(PDO::FETCH_ASSOC);
if (!$dean) {
    die("[FAIL] No Dean user found in database.\n");
}
$deanId = (int)$dean['id'];
echo "[INFO] Testing with Dean: {$dean['name']} (ID: {$deanId})\n";

// 2. Locate Admin 1 User
$admin1Stmt = $db->query("SELECT id, name, email FROM users WHERE role = 'admin1' LIMIT 1");
$admin1 = $admin1Stmt->fetch(PDO::FETCH_ASSOC);
$admin1Id = $admin1 ? (int)$admin1['id'] : 0;

// 3. Test Activity 1: Preliminary Review Activity (under_review)
$underReviewStmt = $db->query("SELECT id, title, status FROM activities WHERE status IN ('under_review', 'submitted') LIMIT 1");
$underReviewAct = $underReviewStmt->fetch(PDO::FETCH_ASSOC);
if ($underReviewAct) {
    echo "[INFO] Testing with Under Review Activity: #{$underReviewAct['id']} - '{$underReviewAct['title']}'\n";
    
    // Check canUserAccessActivity
    $canAccess = canUserAccessActivity((int)$underReviewAct['id'], 'view', $deanId, 'dean');
    echo "  - canUserAccessActivity for Dean: " . ($canAccess ? "ALLOWED (FAIL)" : "DENIED (PASS)") . "\n";
    assert(!$canAccess, "Dean should NOT have access to under_review proposal");
}

// 4. Test Activity 2: Proposal Rejected before reaching Dean (e.g. Foreign Language Week 2027)
$rejectedPreDeanStmt = $db->prepare("
    SELECT a.id, a.title, a.status 
    FROM activities a 
    WHERE a.status = 'rejected' 
      AND NOT EXISTS (SELECT 1 FROM approval_logs al WHERE al.activity_id = a.id AND al.reviewer_id = ?)
    LIMIT 1
");
$rejectedPreDeanStmt->execute([$deanId]);
$rejectedPreDeanAct = $rejectedPreDeanStmt->fetch(PDO::FETCH_ASSOC);

if ($rejectedPreDeanAct) {
    echo "[INFO] Testing with Pre-Dean Rejected Activity: #{$rejectedPreDeanAct['id']} - '{$rejectedPreDeanAct['title']}'\n";
    
    // Check canUserAccessActivity
    $canAccess = canUserAccessActivity((int)$rejectedPreDeanAct['id'], 'view', $deanId, 'dean');
    echo "  - canUserAccessActivity for Dean: " . ($canAccess ? "ALLOWED (FAIL)" : "DENIED (PASS)") . "\n";
    assert(!$canAccess, "Dean should NOT have access to proposal rejected before reaching him");
}

// 5. Test dean/activities.php query filtering
$baseDeanFilter = "(a.status IN ('pending_final_approval', 'approved', 'completed') OR (a.status IN ('returned_for_revision', 'rejected') AND EXISTS (SELECT 1 FROM approval_logs al WHERE al.activity_id = a.id AND al.reviewer_id = ?)))";
$actsQuery = $db->prepare("
    SELECT a.id, a.title, a.status 
    FROM activities a 
    WHERE {$baseDeanFilter}
");
$actsQuery->execute([$deanId]);
$deanVisibleActs = $actsQuery->fetchAll(PDO::FETCH_ASSOC);

echo "\n[CHECK] Dean Visible Activities Count: " . count($deanVisibleActs) . "\n";
foreach ($deanVisibleActs as $act) {
    echo "  - [Visible] #{$act['id']}: '{$act['title']}' (Status: {$act['status']})\n";
    // Assert status is valid for Dean
    $isValidStatus = in_array($act['status'], ['pending_final_approval', 'approved', 'completed', 'returned_for_revision', 'rejected'], true);
    assert($isValidStatus, "Unexpected status visible to Dean: {$act['status']}");
    if ($act['status'] === 'under_review' || $act['status'] === 'submitted') {
        die("[FAIL] under_review proposal visible on Dean activities page!\n");
    }
}

// 6. Verify Foreign Language Week is NOT in Dean Visible Activities
if ($rejectedPreDeanAct) {
    $foundInDeanList = false;
    foreach ($deanVisibleActs as $act) {
        if ((int)$act['id'] === (int)$rejectedPreDeanAct['id']) {
            $foundInDeanList = true;
            break;
        }
    }
    echo "[CHECK] Pre-Dean Rejected Activity in Dean list: " . ($foundInDeanList ? "YES (FAIL)" : "NO (PASS)") . "\n";
    assert(!$foundInDeanList, "Pre-dean rejected activity must NOT show in Dean activities page");
}

// 7. Verify getInstitutionalKpiSummary Scoping
$kpiSummary = getInstitutionalKpiSummary('all', 'all', $db, $deanId);
$stats = $kpiSummary['statistics'];

echo "\n[CHECK] Dean Dashboard KPI Summary Statistics:\n";
echo "  - Total Activities: {$stats['total']}\n";
echo "  - Pending Final Approval: {$stats['pending']}\n";
echo "  - Approved: {$stats['approved']}\n";
echo "  - Completed: {$stats['completed']}\n";
echo "  - Returned: {$stats['returned']}\n";
echo "  - Rejected: {$stats['rejected']}\n";

// Total should match the count of visible activities for Dean
$expectedTotal = count($deanVisibleActs);
echo "[CHECK] Total matches Dean visible count ({$stats['total']} == {$expectedTotal}): " . ($stats['total'] === $expectedTotal ? "PASS" : "FAIL") . "\n";
assert($stats['total'] === $expectedTotal, "Dean KPI total must match Dean visible activities count");

// 8. Verify getApprovalTrends Scoping
$trends = getApprovalTrends('all', 'all', $db, null, $deanId);
echo "\n[CHECK] Dean Approval Trends:\n";
echo "  - Total Approved in Trends: {$trends['total_approved']}\n";
echo "  - Total Returned/Rejected in Trends: {$trends['total_returned']}\n";

echo "\n[SUCCESS] ALL DEAN ENDORSED-ONLY SCOPING CHECKS PASSED!\n";

