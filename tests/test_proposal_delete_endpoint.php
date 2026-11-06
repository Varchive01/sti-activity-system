<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

echo "========================================================" . PHP_EOL;
echo " PROPOSAL DRAFT DELETION ENDPOINT VERIFICATION SUITE" . PHP_EOL;
echo "========================================================" . PHP_EOL;

function assertCheck($label, $condition, $details = '') {
    if ($condition) {
        echo " [PASS] {$label}" . PHP_EOL;
    } else {
        echo " [FAIL] {$label} - {$details}" . PHP_EOL;
    }
}

// Fetch test users
$facultyUser1 = $db->query("SELECT id, name, role, department FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$facultyUser2 = $db->query("SELECT id, name, role, department FROM users WHERE role = 'faculty' AND id != {$facultyUser1['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$headUser     = $db->query("SELECT id, name, role, department FROM users WHERE role = 'program_head' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

function simulateDeleteRequest($proposalId, $user) {
    // Write a temporary runner file
    $runnerFile = __DIR__ . '/_temp_delete_runner.php';
    $script = str_replace('\\', '/', __DIR__ . '/../api/proposal-delete.php');
    $content = '<?php
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST["id"] = ' . (int)$proposalId . ';
session_start();
$_SESSION["user_id"] = ' . (int)$user['id'] . ';
$_SESSION["user_role"] = ' . var_export($user['role'], true) . ';
$_SESSION["user_dept"] = ' . var_export($user['department'] ?? '', true) . ';
require ' . var_export($script, true) . ';
';
    file_put_contents($runnerFile, $content);

    $output = shell_exec('php ' . escapeshellarg($runnerFile) . ' 2>&1');
    @unlink($runnerFile);

    $json = json_decode($output, true);
    return ['stdout' => $output, 'json' => $json];
}

// 1. Create a draft proposal owned by facultyUser1
$db->prepare("
    INSERT INTO activities (title, faculty_id, status, source, event_date, created_at, updated_at)
    VALUES ('Automated Test Draft 1', ?, 'draft', 'faculty', '2027-03-01', NOW(), NOW())
")->execute([$facultyUser1['id']]);
$draft1Id = (int)$db->lastInsertId();

// Add child records
$db->prepare("INSERT INTO materials (activity_id, item_name, quantity, est_cost) VALUES (?, 'Test Item', 10, 500)")->execute([$draft1Id]);
$db->prepare("INSERT INTO manpower (activity_id, role, assigned_person, type) VALUES (?, 'Test Role', 'Person A', 'faculty')")->execute([$draft1Id]);
$db->prepare("INSERT INTO faculty_tasks (activity_id, task_title, status) VALUES (?, 'Test Task', 'Not Started')")->execute([$draft1Id]);

echo "Created Draft Activity ID: {$draft1Id} owned by Faculty {$facultyUser1['name']}" . PHP_EOL;

// Test A: Unauthorized faculty (User 2) tries to delete User 1's draft
$resA = simulateDeleteRequest($draft1Id, $facultyUser2);
assertCheck("Unauthorized faculty blocked from deleting another faculty's draft", 
    isset($resA['json']['success']) && $resA['json']['success'] === false,
    $resA['stdout']
);

// Test B: Owner faculty successfully deletes their own draft
$resB = simulateDeleteRequest($draft1Id, $facultyUser1);
assertCheck("Faculty owner successfully deletes their own draft", 
    isset($resB['json']['success']) && $resB['json']['success'] === true,
    $resB['stdout']
);

// Verify record is gone from DB
$checkAct = $db->query("SELECT COUNT(*) FROM activities WHERE id = {$draft1Id}")->fetchColumn();
assertCheck("Activity record removed from activities table", (int)$checkAct === 0);

$checkMat = $db->query("SELECT COUNT(*) FROM materials WHERE activity_id = {$draft1Id}")->fetchColumn();
assertCheck("Associated materials removed", (int)$checkMat === 0);

$checkTasks = $db->query("SELECT COUNT(*) FROM faculty_tasks WHERE activity_id = {$draft1Id}")->fetchColumn();
assertCheck("Associated faculty tasks removed", (int)$checkTasks === 0);

// Test C: Attempting to delete a submitted proposal (must be blocked)
$db->prepare("
    INSERT INTO activities (title, faculty_id, status, source, event_date, created_at, updated_at)
    VALUES ('Automated Test Submitted Proposal', ?, 'submitted', 'faculty', '2027-03-01', NOW(), NOW())
")->execute([$facultyUser1['id']]);
$submittedId = (int)$db->lastInsertId();

$resC = simulateDeleteRequest($submittedId, $facultyUser1);
assertCheck("Submitted proposal CANNOT be deleted (protected by audit rule)", 
    isset($resC['json']['success']) && $resC['json']['success'] === false,
    $resC['stdout']
);

// Clean up submitted test activity
$db->exec("DELETE FROM activities WHERE id = {$submittedId}");

// Test D: Program Head deletes their own draft proposal
$db->prepare("
    INSERT INTO activities (title, faculty_id, status, source, event_date, created_at, updated_at)
    VALUES ('Program Head Test Draft', ?, 'draft', 'faculty', '2027-03-01', NOW(), NOW())
")->execute([$headUser['id']]);
$headDraftId = (int)$db->lastInsertId();

$resD = simulateDeleteRequest($headDraftId, $headUser);
assertCheck("Program Head successfully deletes their own draft", 
    isset($resD['json']['success']) && $resD['json']['success'] === true,
    $resD['stdout']
);

$checkHeadAct = $db->query("SELECT COUNT(*) FROM activities WHERE id = {$headDraftId}")->fetchColumn();
assertCheck("Program Head draft removed from database", (int)$checkHeadAct === 0);

echo PHP_EOL . "========================================================" . PHP_EOL;
echo " ENDPOINT VERIFICATION COMPLETE" . PHP_EOL;
echo "========================================================" . PHP_EOL;
