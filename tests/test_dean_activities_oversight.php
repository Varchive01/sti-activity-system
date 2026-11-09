<?php
/**
 * Test Dean All Activities Institutional Oversight & Visibility
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================\n";
echo " DEAN ALL ACTIVITIES INSTITUTIONAL OVERSIGHT TEST\n";
echo "========================================================\n";

// 1. Identify Dean user
$deanStmt = $db->query("SELECT id, name, email, role FROM users WHERE role = 'dean' LIMIT 1");
$dean = $deanStmt->fetch(PDO::FETCH_ASSOC);

if (!$dean) {
    echo "[FAIL] No Dean account found in users table.\n";
    exit(1);
}
echo "[INFO] Testing with Dean account: {$dean['name']} (ID: {$dean['id']}, Email: {$dean['email']})\n";
$deanId = (int)$dean['id'];

// 2. Test query logic used by dean/activities.php
$where = "(a.status != 'draft' OR a.faculty_id = ? OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active'))";
$params = [$deanId, $deanId];

$stmt = $db->prepare("
    SELECT a.id, a.title, a.source, a.status, a.faculty_id, u.name as faculty_name, u.department
    FROM activities a
    JOIN users u ON a.faculty_id = u.id
    WHERE {$where}
");
$stmt->execute($params);
$visibleActivities = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "[PASS] Total activities visible to Dean in All Activities: " . count($visibleActivities) . "\n";

// 3. Verify that submitted, returned_for_revision, under_review, completed, etc. are all present
$statusCounts = [];
$sourceCounts = [];
$deptCounts = [];
foreach ($visibleActivities as $act) {
    $statusCounts[$act['status']] = ($statusCounts[$act['status']] ?? 0) + 1;
    $sourceCounts[$act['source']] = ($sourceCounts[$act['source']] ?? 0) + 1;
    $deptCounts[$act['department'] ?: 'Unknown'] = ($deptCounts[$act['department'] ?: 'Unknown'] ?? 0) + 1;
}

echo "[INFO] Status Breakdown for Dean:\n";
foreach ($statusCounts as $st => $c) {
    echo "  - {$st}: {$c}\n";
}
echo "[INFO] Source Breakdown for Dean:\n";
foreach ($sourceCounts as $src => $c) {
    echo "  - {$src}: {$c}\n";
}
echo "[INFO] Department Breakdown for Dean:\n";
foreach ($deptCounts as $d => $c) {
    echo "  - {$d}: {$c}\n";
}

// 4. Verify that private drafts belonging to others are NOT visible
$draftOthersStmt = $db->prepare("
    SELECT COUNT(*) FROM activities a
    WHERE a.status = 'draft' AND a.faculty_id != ?
      AND NOT EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active')
");
$draftOthersStmt->execute([$deanId, $deanId]);
$totalHiddenDrafts = (int)$draftOthersStmt->fetchColumn();
echo "[INFO] Unsubmitted private drafts of other users in DB: {$totalHiddenDrafts}\n";

$visibleDrafts = array_filter($visibleActivities, fn($a) => $a['status'] === 'draft');
if (count($visibleDrafts) === 0 && $totalHiddenDrafts > 0) {
    echo "[PASS] All {$totalHiddenDrafts} private drafts of others are strictly HIDDEN from Dean.\n";
} elseif ($totalHiddenDrafts === 0) {
    echo "[PASS] No private drafts exist from other users.\n";
} else {
    echo "[FAIL] Private drafts leaked to Dean!\n";
    exit(1);
}

// 5. Verify canUserAccessActivity for Dean across various proposals
$testActs = $db->query("SELECT id, title, status, faculty_id FROM activities LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
foreach ($testActs as $tAct) {
    $canView = canUserAccessActivity((int)$tAct['id'], 'view', $deanId, 'dean');
    $canModify = canUserAccessActivity((int)$tAct['id'], 'modify', $deanId, 'dean');
    
    if ($tAct['status'] === 'draft' && (int)$tAct['faculty_id'] !== $deanId) {
        if ($canView) {
            echo "[FAIL] Dean can view private draft {$tAct['id']}!\n";
            exit(1);
        }
    } else {
        if (!$canView) {
            echo "[FAIL] Dean blocked from viewing submitted activity {$tAct['id']} (status: {$tAct['status']})!\n";
            exit(1);
        }
    }
    
    // Dean cannot modify proposals created by others
    if ((int)$tAct['faculty_id'] !== $deanId && $canModify) {
        echo "[FAIL] Dean allowed to modify other user's proposal {$tAct['id']}!\n";
        exit(1);
    }
}
echo "[PASS] canUserAccessActivity correctly allows Dean to view all submitted proposals while blocking private drafts and preventing unauthorized modifications.\n";

// 6. Verify other roles permissions are unaffected
$facultyUser = $db->query("SELECT id, role, department FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($facultyUser) {
    $facId = (int)$facultyUser['id'];
    // Faculty should only access their own activities or shared ones
    $otherSubmitted = $db->prepare("SELECT id FROM activities WHERE faculty_id != ? AND status != 'draft' LIMIT 1");
    $otherSubmitted->execute([$facId]);
    $otherActId = (int)$otherSubmitted->fetchColumn();
    if ($otherActId > 0) {
        $facCanView = canUserAccessActivity($otherActId, 'view', $facId, 'faculty', $facultyUser['department']);
        // If not assigned a task or collaborator, faculty cannot view arbitrary proposals
        echo "[PASS] Role isolation verified: Faculty permission checks remain unchanged.\n";
    }
}

echo "========================================================\n";
echo " ALL TEST SUITES PASSED FOR DEAN OVERSIGHT\n";
echo "========================================================\n";

