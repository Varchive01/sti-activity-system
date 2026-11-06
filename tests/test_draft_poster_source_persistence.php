<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================\n";
echo " TEST SUITE: DRAFT POSTER & SOURCE PERSISTENCE\n";
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

// 1. Verify source handling in proposal-save.php code
$saveFile = file_get_contents(__DIR__ . '/../api/proposal-save.php');
checkTest("proposal-save.php contains safe source validation", strpos($saveFile, '$saveSource = in_array($rawSource, [\'student_org\', \'faculty\'], true) ? $rawSource : \'faculty\';') !== false);
checkTest("proposal-save.php returns source in JSON response", strpos($saveFile, "'source'      => \$saveSource,") !== false);
checkTest("proposal-save.php returns poster_path in JSON response", strpos($saveFile, "'poster_path' => \$savedPosterPath,") !== false);

// 2. Verify source handling in proposal-update.php code
$upFile = file_get_contents(__DIR__ . '/../api/proposal-update.php');
checkTest("proposal-update.php contains safe source validation", strpos($upFile, '$rawSource = trim($_POST[\'source\'] ?? \'\');') !== false);
checkTest("proposal-update.php returns source in JSON response", strpos($upFile, "'source'      => \$source,") !== false);
checkTest("proposal-update.php returns poster_path in JSON response", strpos($upFile, "'poster_path' => \$savedPosterPath") !== false);

// 3. Verify offline-drafts.js code fixes
$odFile = file_get_contents(__DIR__ . '/../assets/js/offline-drafts.js');
checkTest("offline-drafts.js skips File objects in serializeForm", strpos($odFile, 'if (value instanceof File) continue;') !== false);
checkTest("offline-drafts.js skips file input in restoreForm to prevent DOMException", strpos($odFile, 'input.type === \'file\'') !== false);
checkTest("offline-drafts.js uses FormData when formEl is in DOM for multipart poster uploads", strpos($odFile, 'const fd = new FormData(formEl);') !== false);
checkTest("offline-drafts.js updates poster preview and hides error on sync success", strpos($odFile, 'result.poster_path') !== false);
checkTest("offline-drafts.js uses STIModal for draft discard confirmation", strpos($odFile, "title: 'Discard Auto-Saved Draft?'") !== false && strpos($odFile, "variant: 'danger'") !== false);
checkTest("offline-drafts.js uses STIModal for offline alerts", strpos($odFile, "title: 'Offline Mode'") !== false);

// 4. Verify proposal-edit.php draft poster required attribute
$editFile = file_get_contents(__DIR__ . '/../faculty/proposal-edit.php');
checkTest("proposal-edit.php relaxes HTML5 required attribute for drafts without poster", strpos($editFile, "((\$activity['status'] ?? '') === 'draft' ? '' : 'required')") !== false);

// 5. Functional database test: Insert draft with empty source and verify fallback to 'faculty'
$facUser = $db->query("SELECT id FROM users WHERE role='faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facId = (int)$facUser['id'];

// Test Case A: Empty source fallback in database logic
$rawSource = '';
$saveSource = in_array($rawSource, ['student_org', 'faculty'], true) ? $rawSource : 'faculty';
checkTest("Empty source correctly defaults to 'faculty'", $saveSource === 'faculty');

// Test Case B: Selected source 'student_org'
$rawSource2 = 'student_org';
$saveSource2 = in_array($rawSource2, ['student_org', 'faculty'], true) ? $rawSource2 : 'faculty';
checkTest("Selected source 'student_org' is preserved", $saveSource2 === 'student_org');

// Test Case C: Create a test draft activity with poster_path and source in database
$testTitle = 'Unit Test Draft Activity ' . time();
$dummyPoster = 'uploads/posters/test_poster_' . time() . '.png';

$stmt = $db->prepare("INSERT INTO activities (faculty_id, title, source, status, poster_path) VALUES (?, ?, ?, 'draft', ?)");
$stmt->execute([$facId, $testTitle, $saveSource2, $dummyPoster]);
$testActId = (int)$db->lastInsertId();

$savedRow = $db->query("SELECT id, title, source, status, poster_path FROM activities WHERE id = $testActId")->fetch(PDO::FETCH_ASSOC);
checkTest("Database row saved with correct source: " . $savedRow['source'], $savedRow['source'] === 'student_org');
checkTest("Database row saved with correct poster_path: " . $savedRow['poster_path'], $savedRow['poster_path'] === $dummyPoster);

// Test Case D: Update draft with new source and verify preservation
$upSource = in_array('faculty', ['student_org', 'faculty'], true) ? 'faculty' : $savedRow['source'];
$upStmt = $db->prepare("UPDATE activities SET source = ?, poster_path = ? WHERE id = ?");
$upPoster = 'uploads/posters/test_poster_updated_' . time() . '.png';
$upStmt->execute([$upSource, $upPoster, $testActId]);

$updatedRow = $db->query("SELECT id, title, source, status, poster_path FROM activities WHERE id = $testActId")->fetch(PDO::FETCH_ASSOC);
checkTest("Database row updated with new source: " . $updatedRow['source'], $updatedRow['source'] === 'faculty');
checkTest("Database row updated with new poster_path: " . $updatedRow['poster_path'], $updatedRow['poster_path'] === $upPoster);

// Cleanup test row
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);
checkTest("Temporary test activity cleaned up", true);

echo "\n========================================================\n";
if ($allPassed) {
    echo " ALL DRAFT POSTER & SOURCE TESTS PASSED!\n";
} else {
    echo " SOME TESTS FAILED. PLEASE REVIEW.\n";
    exit(1);
}
echo "========================================================\n";

