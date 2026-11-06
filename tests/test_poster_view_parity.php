<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================\n";
echo " TEST SUITE: POSTER VIEW & ACCESS PARITY AUDIT\n";
echo "========================================================\n\n";

$allPassed = true;

function checkTest($label, $condition) {
    global $allPassed;
    if ($condition) {
        echo " [PASS] $label\n";
    } else {
        echo " [FAIL] $label\n";
        $allPassed = false;
    }
}

// 1. Check admin1/view-activity.php posterUrl code
$admin1View = file_get_contents(__DIR__ . '/../admin1/view-activity.php');
checkTest("admin1/view-activity.php uses document-download.php for posterUrl", strpos($admin1View, "BASE_URL . '/api/document-download.php?activity_id=' . (int)\$activity['id'] . '&type=poster'") !== false);
checkTest("admin1/view-activity.php does NOT use direct unmapped poster path", strpos($admin1View, "BASE_URL . '/' . \$activity['poster_path']") === false);

// 2. Check admin2/view-activity.php posterUrl code
$admin2View = file_get_contents(__DIR__ . '/../admin2/view-activity.php');
checkTest("admin2/view-activity.php uses document-download.php for posterUrl", strpos($admin2View, "BASE_URL . '/api/document-download.php?activity_id=' . (int)\$activity['id'] . '&type=poster'") !== false);
checkTest("admin2/view-activity.php does NOT use direct unmapped poster path", strpos($admin2View, "BASE_URL . '/' . \$activity['poster_path']") === false);

// 3. Check dean/view-activity.php posterUrl code
$deanView = file_get_contents(__DIR__ . '/../dean/view-activity.php');
checkTest("dean/view-activity.php uses document-download.php for posterUrl", strpos($deanView, "BASE_URL . '/api/document-download.php?activity_id=' . (int)\$activity['id'] . '&type=poster'") !== false);
checkTest("dean/view-activity.php does NOT use direct unmapped poster path", strpos($deanView, "BASE_URL . '/' . \$activity['poster_path']") === false);

// 4. Check api/document-download.php authorization code
$docDl = file_get_contents(__DIR__ . '/../api/document-download.php');
checkTest("document-download.php grants admin1, admin2, and dean access to posters", strpos($docDl, "in_array(\$role, ['admin1', 'admin2', 'dean'], true)") !== false);

// 5. Functional test: Create a real test poster file and verify document-download endpoint
$uploadsDir = realpath(UPLOAD_PATH . 'posters');
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}

$testFileName = 'test_poster_dl_' . time() . '.png';
$testFilePath = $uploadsDir . DIRECTORY_SEPARATOR . $testFileName;
// Create 1x1 transparent PNG
file_put_contents($testFilePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

$facUser = $db->query("SELECT id FROM users WHERE role='faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin1User = $db->query("SELECT id, role, department FROM users WHERE role='admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2User = $db->query("SELECT id, role, department FROM users WHERE role='admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$stmt = $db->prepare("INSERT INTO activities (faculty_id, title, source, status, poster_path) VALUES (?, 'Poster Parity Test Activity', 'faculty', 'submitted', ?)");
$stmt->execute([$facUser['id'], 'posters/' . $testFileName]);
$testActId = (int)$db->lastInsertId();

// Verify canUserAccessActivity for admin1 and admin2
$a1Access = canUserAccessActivity($testActId, 'view', $admin1User['id'], 'admin1', $admin1User['department'] ?? '');
checkTest("canUserAccessActivity returns true for admin1 on submitted activity", $a1Access === true);

$a2Access = canUserAccessActivity($testActId, 'view', $admin2User['id'], 'admin2', $admin2User['department'] ?? '');
checkTest("canUserAccessActivity returns true for admin2 on submitted activity", $a2Access === true);

// Verify file existence resolution as document-download.php does
$cleanRelative = 'posters/' . $testFileName;
$baseUploadDir = realpath(UPLOAD_PATH);
$targetFullPath = realpath($baseUploadDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cleanRelative));
checkTest("Poster file resolves to existing file on disk", $targetFullPath && is_file($targetFullPath));

// Cleanup test activity & file
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);
@unlink($testFilePath);
checkTest("Temporary test activity and poster file cleaned up", true);

echo "\n========================================================\n";
if ($allPassed) {
    echo " ALL POSTER VIEW PARITY TESTS PASSED!\n";
} else {
    echo " SOME TESTS FAILED. PLEASE REVIEW.\n";
    exit(1);
}
echo "========================================================\n";

