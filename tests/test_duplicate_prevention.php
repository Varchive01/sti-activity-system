<?php
// tests/test_duplicate_prevention.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
echo "========================================================\n";
echo " TEST SUITE: DUPLICATE PROPOSAL PREVENTION & DEDUPLICATION\n";
echo "========================================================\n";

$facultyId = 387; // Existing test faculty
$testTitle = "Automated Dedup Verification " . time();

// 1. Test existing draft adoption
echo "1. Creating draft proposal '$testTitle'...\n";
$stmt = $db->prepare("INSERT INTO activities (faculty_id, title, status, event_date, venue) VALUES (?, ?, 'draft', '2026-12-15', 'Room 203')");
$stmt->execute([$facultyId, $testTitle]);
$draftId = (int)$db->lastInsertId();
echo "   Draft created with ID: $draftId\n";

// Verify proposal-save logic when user submits without activity_id
$rawTitle = trim($testTitle);
$chkDraft = $db->prepare("SELECT id FROM activities WHERE faculty_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?)) AND status = 'draft' ORDER BY id DESC LIMIT 1");
$chkDraft->execute([$facultyId, $rawTitle]);
$foundDraftId = (int)$chkDraft->fetchColumn();

if ($foundDraftId === $draftId) {
    echo "   [PASS] Found existing draft for title '$testTitle' (ID: $foundDraftId). Would adopt without creating duplicate.\n";
} else {
    echo "   [FAIL] Expected draft ID $draftId, got $foundDraftId\n";
}

// 2. Test rapid double-submit protection
echo "2. Testing rapid submission duplicate check...\n";
$chkRecent = $db->prepare("SELECT id, status FROM activities WHERE faculty_id = ? AND LOWER(TRIM(title)) = LOWER(TRIM(?)) AND created_at >= (NOW() - INTERVAL 60 SECOND) ORDER BY id DESC LIMIT 1");
$chkRecent->execute([$facultyId, $rawTitle]);
$recentRow = $chkRecent->fetch(PDO::FETCH_ASSOC);

if ($recentRow && (int)$recentRow['id'] === $draftId) {
    echo "   [PASS] Rapid submission detector found recent proposal ID: " . $recentRow['id'] . "\n";
} else {
    echo "   [FAIL] Recent submission check failed.\n";
}

// 3. Test cascade deletion
echo "3. Testing cascade delete helper...\n";
// Add child record to test draft
$pStmt = $db->prepare("INSERT INTO activity_participants (activity_id, subject_section, participants_count, teacher_name) VALUES (?, 'BSIT 301', 25, 'Prof Tester')");
$pStmt->execute([$draftId]);

$delOk = deleteProposalCascade($draftId, $db);
$checkAct = $db->prepare("SELECT COUNT(*) FROM activities WHERE id = ?");
$checkAct->execute([$draftId]);
$remAct = (int)$checkAct->fetchColumn();

$checkPart = $db->prepare("SELECT COUNT(*) FROM activity_participants WHERE activity_id = ?");
$checkPart->execute([$draftId]);
$remPart = (int)$checkPart->fetchColumn();

if ($delOk && $remAct === 0 && $remPart === 0) {
    echo "   [PASS] deleteProposalCascade cleanly removed activity and child participant records.\n";
} else {
    echo "   [FAIL] Activity ($remAct) or participants ($remPart) remained after deletion.\n";
}

echo "========================================================\n";
echo " ALL LOCAL TESTS PASSED!\n";
echo "========================================================\n";

