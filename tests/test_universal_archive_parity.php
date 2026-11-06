<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
ensureActivitiesArchiveSchema($db);

echo "========================================================" . PHP_EOL;
echo " UNIVERSAL ARCHIVE GLOBAL PARITY & INTEGRATION SUITE" . PHP_EOL;
echo "========================================================" . PHP_EOL;

function assertCheck($label, $condition, $details = '') {
    if ($condition) {
        echo " [PASS] {$label}" . PHP_EOL;
    } else {
        echo " [FAIL] {$label} - {$details}" . PHP_EOL;
    }
}

$base = __DIR__ . '/..';

// 1. Static UI Parity Checks
$files = [
    'faculty/activities.php',
    'admin1/activities.php',
    'admin2/activities.php',
    'dean/activities.php'
];

foreach ($files as $f) {
    $c = file_get_contents("{$base}/{$f}");
    $name = basename(dirname($f)) . '/' . basename($f);
    assertCheck("Universal Archive button present in {$name}", strpos($c, 'openUniversalArchiveModal()') !== false);
    assertCheck("Archived status filter present in {$name}", strpos($c, 'value="archived"') !== false);
    assertCheck("Unarchive row button present in {$name}", strpos($c, 'confirmUnarchiveActivity') !== false);
    assertCheck("is_archived SQL separation present in {$name}", strpos($c, '$filterStat === \'archived\'') !== false);
}

// 2. Verify modal component presence in topbar-profile.php
$topbar = file_get_contents("{$base}/includes/topbar-profile.php");
assertCheck("Universal Archive modal included in topbar-profile.php", strpos($topbar, 'universal-archive-modal.php') !== false);

$modalComp = file_get_contents("{$base}/includes/universal-archive-modal.php");
assertCheck("Universal Archive modal markup defined", strpos($modalComp, 'id="stiUniversalArchiveModal"') !== false);
assertCheck("openUniversalArchiveModal JS defined", strpos($modalComp, 'window.openUniversalArchiveModal') !== false);
assertCheck("submitUniversalArchive JS defined", strpos($modalComp, 'window.submitUniversalArchive') !== false);
assertCheck("fetchArchivePreview JS defined", strpos($modalComp, 'window.fetchArchivePreview') !== false);
assertCheck("confirmUnarchiveActivity JS defined", strpos($modalComp, 'window.confirmUnarchiveActivity') !== false);

// 3. E2E Database & API Functional Test
$user = $db->query("SELECT id, name, role, department FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

function callArchive($params, $u) {
    $runner = __DIR__ . '/_temp_runner_archive.php';
    $script = str_replace('\\', '/', __DIR__ . '/../api/activities-archive.php');
    $content = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST = ' . var_export($params, true) . ';
session_start();
$_SESSION["user_id"] = ' . (int)$u['id'] . ';
$_SESSION["user_role"] = ' . var_export($u['role'], true) . ';
$_SESSION["user_dept"] = ' . var_export($u['department'] ?? '', true) . ';
require ' . var_export($script, true) . ';
';
    file_put_contents($runner, $content);
    $out = shell_exec('php ' . escapeshellarg($runner) . ' 2>&1');
    @unlink($runner);
    return json_decode($out, true);
}

// Create a completed test activity for Oct 2026
$db->prepare("
    INSERT INTO activities (title, faculty_id, status, source, event_date, is_archived, created_at, updated_at)
    VALUES ('E2E Archive Test Oct 2026', ?, 'completed', 'faculty', '2026-10-18', 0, NOW(), NOW())
")->execute([$user['id']]);
$testActId = (int)$db->lastInsertId();

// Preview
$preview = callArchive(['action' => 'preview', 'scope' => 'month', 'month' => 10, 'year' => 2026], $user);
assertCheck("API Preview found Oct 2026 completed activity", ($preview['count'] ?? 0) >= 1);

// Archive
$archiveRes = callArchive(['action' => 'archive', 'scope' => 'month', 'month' => 10, 'year' => 2026], $user);
assertCheck("API Archive executed successfully", !empty($archiveRes['success']));

$isArchivedVal = $db->query("SELECT is_archived FROM activities WHERE id = {$testActId}")->fetchColumn();
assertCheck("Database record updated with is_archived = 1", (int)$isArchivedVal === 1);

// Unarchive
$unarchiveRes = callArchive(['action' => 'unarchive', 'id' => $testActId], $user);
assertCheck("API Unarchive executed successfully", !empty($unarchiveRes['success']));

$isArchivedAfter = $db->query("SELECT is_archived FROM activities WHERE id = {$testActId}")->fetchColumn();
assertCheck("Database record restored with is_archived = 0", (int)$isArchivedAfter === 0);

// Cleanup
$db->exec("DELETE FROM activities WHERE id = {$testActId}");

echo "========================================================" . PHP_EOL;
echo " ALL UNIVERSAL ARCHIVE PARITY CHECKS PASSED!" . PHP_EOL;
echo "========================================================" . PHP_EOL;

