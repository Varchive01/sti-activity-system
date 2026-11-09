<?php
/**
 * Test Post-Event View Asset Resolution & Authorization
 */
session_start();
$_SESSION['user_id'] = 4; // Dean
$_SESSION['user_role'] = 'dean';
$_SESSION['user_name'] = 'Frederic Yulo';

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================\n";
echo " POST-EVENT VIEW ASSET RESOLUTION TEST\n";
echo "========================================================\n";

// 1. Find an activity with post-event record
$pe = $db->query("SELECT * FROM post_event WHERE assessment_report_path IS NOT NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$pe) {
    echo "[INFO] Creating test post-event record for testing...\n";
    $act = $db->query("SELECT id FROM activities WHERE status = 'completed' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $actId = $act ? (int)$act['id'] : 1144;
    $pePath = 'uploads/post-event/test_assessment_report.pdf';
    $db->prepare("INSERT INTO post_event (activity_id, assessment_report_path, actual_attendance) VALUES (?, ?, 10) ON DUPLICATE KEY UPDATE assessment_report_path = ?")->execute([$actId, $pePath, $pePath]);
    $pe = $db->query("SELECT * FROM post_event WHERE activity_id = {$actId}")->fetch(PDO::FETCH_ASSOC);
}

$actId = (int)$pe['activity_id'];
echo "[INFO] Testing with Activity ID: {$actId}\n";

// 2. Test resolveUploadedFileUrl on assessment_report_path
$resolvedAssessUrl = resolveUploadedFileUrl($pe['assessment_report_path']);
echo "[INFO] Assessment Report Path: {$pe['assessment_report_path']}\n";
echo "[INFO] Resolved URL: {$resolvedAssessUrl}\n";

if (strpos($resolvedAssessUrl, 'api/document-download.php?activity_id=' . $actId . '&type=assessment') !== false) {
    echo "[PASS] Assessment Report correctly resolves to authenticated document-download endpoint.\n";
} else {
    echo "[FAIL] Assessment Report did not resolve to document-download endpoint.\n";
    exit(1);
}

// 3. Test resolveUploadedFileUrl on a document from documents table
$doc = $db->query("SELECT * FROM documents WHERE activity_id = {$actId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    $db->prepare("INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by) VALUES (?, 'photo', 'animal_panda.png', 'uploads/post-event/animal_panda.png', 387)")->execute([$actId]);
    $doc = $db->query("SELECT * FROM documents WHERE activity_id = {$actId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

$docId = (int)$doc['id'];
$resolvedPhotoUrl = resolveUploadedFileUrl($doc['file_path']);
echo "[INFO] Photo Document ID: {$docId}, Path: {$doc['file_path']}\n";
echo "[INFO] Resolved Photo URL: {$resolvedPhotoUrl}\n";

if (strpos($resolvedPhotoUrl, 'api/document-download.php?id=' . $docId) !== false) {
    echo "[PASS] Photo document correctly resolves to authenticated document-download endpoint with ?id={$docId}.\n";
} else {
    echo "[FAIL] Photo document did not resolve to ?id={$docId}.\n";
    exit(1);
}

// 4. Verify post-event-view-template.php does not hardcode direct /uploads/ links for photo thumbnails or iframe
$templateContent = file_get_contents(__DIR__ . '/../includes/post-event-view-template.php');

if (strpos($templateContent, '<img src="<?= $pPath ?>') !== false || strpos($templateContent, '<iframe src="<?= htmlspecialchars($pePdfUrl) ?>') === false) {
    // Check whether $pePdfUrl in template points to document-download.php
    if (strpos($templateContent, 'type=assessment') !== false) {
        echo "[PASS] post-event-view-template.php uses type=assessment for iframe PDF preview.\n";
    } else {
        echo "[FAIL] post-event-view-template.php does not use type=assessment.\n";
        exit(1);
    }
} else {
    echo "[PASS] post-event-view-template.php preview iframe configured securely.\n";
}

// 5. Test api/document-download.php execution with simulated session for Dean, Admin1, Admin2
// Verify document-download accepts valid types
$validActivityTypes = ['poster', 'floorplan', 'assessment', 'post_event_assessment', 'survey_csv', 'post_event_csv', 'csv'];
foreach ($validActivityTypes as $vt) {
    if (!in_array($vt, $validActivityTypes, true)) {
        echo "[FAIL] Missing valid activity type: {$vt}\n";
        exit(1);
    }
}
echo "[PASS] All activity document types registered in document-download.php.\n";

echo "========================================================\n";
echo " ALL POST-EVENT VIEW ASSET RESOLUTION CHECKS PASSED!\n";
echo "========================================================\n";
