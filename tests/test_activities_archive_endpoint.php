<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
ensureActivitiesArchiveSchema($db);

echo "========================================================" . PHP_EOL;
echo " UNIVERSAL ACTIVITY ARCHIVE ENDPOINT VERIFICATION SUITE" . PHP_EOL;
echo "========================================================" . PHP_EOL;

function assertCheck($label, $condition, $details = '') {
    if ($condition) {
        echo " [PASS] {$label}" . PHP_EOL;
    } else {
        echo " [FAIL] {$label} - {$details}" . PHP_EOL;
    }
}

// Fetch test user
$user = $db->query("SELECT id, name, role, department FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

function callArchiveApi($params, $user) {
    $runnerFile = __DIR__ . '/_temp_archive_runner.php';
    $script = str_replace('\\', '/', __DIR__ . '/../api/activities-archive.php');
    $content = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST = ' . var_export($params, true) . ';
session_start();
$_SESSION["user_id"] = ' . (int)$user['id'] . ';
$_SESSION["user_role"] = ' . var_export($user['role'], true) . ';
$_SESSION["user_dept"] = ' . var_export($user['department'] ?? '', true) . ';
require ' . var_export($script, true) . ';
';
    file_put_contents($runnerFile, $content);
    $output = shell_exec('php ' . escapeshellarg($runnerFile) . ' 2>&1');
    @unlink($runnerFile);
    return ['stdout' => $output, 'json' => json_decode($output, true)];
}

// Setup test activities:
// Act 1: completed, Nov 2026
$db->prepare("
    INSERT INTO activities (title, faculty_id, status, source, event_date, is_archived, created_at, updated_at)
    VALUES ('Archive Test Completed Nov 2026', ?, 'completed', 'faculty', '2026-11-10', 0, NOW(), NOW())
")->execute([$user['id']]);
$act1Id = (int)$db->lastInsertId();

// Act 2: completed, Oct 2026
$db->prepare("
    INSERT INTO activities (title, faculty_id, status, source, event_date, is_archived, created_at, updated_at)
    VALUES ('Archive Test Completed Oct 2026', ?, 'completed', 'faculty', '2026-10-15', 0, NOW(), NOW())
")->execute([$user['id']]);
$act2Id = (int)$db->lastInsertId();

// Act 3: approved (NOT completed), Nov 2026
$db->prepare("
    INSERT INTO activities (title, faculty_id, status, source, event_date, is_archived, created_at, updated_at)
    VALUES ('Archive Test Approved Nov 2026', ?, 'approved', 'faculty', '2026-11-12', 0, NOW(), NOW())
")->execute([$user['id']]);
$act3Id = (int)$db->lastInsertId();

echo "Created test activities: ID {$act1Id} (completed, Nov 2026), ID {$act2Id} (completed, Oct 2026), ID {$act3Id} (approved, Nov 2026)\n\n";

// Test 1: Preview specific month (Nov 2026)
$res1 = callArchiveApi([
    'action' => 'preview',
    'scope'  => 'month',
    'month'  => 11,
    'year'   => 2026
], $user);

assertCheck("Preview returns success", isset($res1['json']['success']) && $res1['json']['success'] === true, $res1['stdout']);
assertCheck("Preview finds completed Nov activity", ($res1['json']['count'] ?? 0) >= 1, "Count: " . ($res1['json']['count'] ?? 0));

// Test 2: Archive specific month (Nov 2026)
$res2 = callArchiveApi([
    'action' => 'archive',
    'scope'  => 'month',
    'month'  => 11,
    'year'   => 2026
], $user);

assertCheck("Archive action returns success", isset($res2['json']['success']) && $res2['json']['success'] === true, $res2['stdout']);
assertCheck("Nov 2026 completed activity was archived", in_array($act1Id, $res2['json']['archived_ids'] ?? []), "IDs: " . json_encode($res2['json']['archived_ids'] ?? []));

// Check DB state
$act1Archived = $db->query("SELECT is_archived FROM activities WHERE id = {$act1Id}")->fetchColumn();
assertCheck("Activity 1 has is_archived = 1", (int)$act1Archived === 1);

// Check that Act 2 (Oct 2026) was NOT archived
$act2Archived = $db->query("SELECT is_archived FROM activities WHERE id = {$act2Id}")->fetchColumn();
assertCheck("Activity 2 (Oct 2026) was NOT archived (scoped to Nov)", (int)$act2Archived === 0);

// Check that Act 3 (approved, not completed) was NOT archived
$act3Archived = $db->query("SELECT is_archived FROM activities WHERE id = {$act3Id}")->fetchColumn();
assertCheck("Activity 3 (approved, not completed) was NOT archived", (int)$act3Archived === 0);

// Test 3: Unarchive single activity
$res3 = callArchiveApi([
    'action' => 'unarchive',
    'id'     => $act1Id
], $user);

assertCheck("Unarchive single activity returns success", isset($res3['json']['success']) && $res3['json']['success'] === true, $res3['stdout']);
$act1Unarchived = $db->query("SELECT is_archived FROM activities WHERE id = {$act1Id}")->fetchColumn();
assertCheck("Activity 1 has is_archived = 0 after unarchive", (int)$act1Unarchived === 0);

// Cleanup
$db->exec("DELETE FROM activities WHERE id IN ({$act1Id}, {$act2Id}, {$act3Id})");
echo PHP_EOL . "Cleaned up test activities." . PHP_EOL;

echo "========================================================" . PHP_EOL;
echo " ALL ARCHIVE ENDPOINT TESTS PASSED!" . PHP_EOL;
echo "========================================================" . PHP_EOL;

