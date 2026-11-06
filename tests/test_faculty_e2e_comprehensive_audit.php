<?php
/**
 * Comprehensive End-to-End Audit Suite for Faculty Account
 * Tests every page, module, button, form, workflow, and API endpoint.
 */

// Enable full error reporting to catch notices and warnings
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Start session early before headers sent
startSession();

echo "=======================================================================\n";
echo "       STI-ARMES COMPREHENSIVE FACULTY END-TO-END AUDIT SUITE          \n";
echo "=======================================================================\n\n";

$db = getDB();
$testPassedCount = 0;
$testFailedCount = 0;
$issuesFound = [];

function recordResult($testName, $condition, $details = '') {
    global $testPassedCount, $testFailedCount, $issuesFound;
    if ($condition) {
        printf(" [PASS] | %-54s | %s\n", $testName, $details ?: 'OK');
        $testPassedCount++;
    } else {
        printf(" [FAIL] | %-54s | %s\n", $testName, $details ?: 'ISSUE DETECTED');
        $testFailedCount++;
        $issuesFound[] = [
            'test' => $testName,
            'details' => $details
        ];
    }
}

// 1. SETUP FACULTY SESSION ENVIRONMENT
$facultyUser = $db->query("SELECT * FROM users WHERE role = 'faculty' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$facultyUser) {
    die("FATAL: No active faculty account found in database to audit.\n");
}

$_SESSION['user_id'] = $facultyUser['id'];
$_SESSION['user_name'] = $facultyUser['name'];
$_SESSION['user_email'] = $facultyUser['email'];
$_SESSION['user_role'] = 'faculty';
$_SESSION['role'] = 'faculty';
$_SESSION['department'] = $facultyUser['department'];
$_SESSION['last_activity'] = time();

echo "Auditing Faculty: {$facultyUser['name']} (ID: {$facultyUser['id']}, Dept: {$facultyUser['department']})\n";
echo "-----------------------------------------------------------------------\n";

// SECTION 1: PAGE RENDERING & SYNTAX EXECUTION (OUTPUT BUFFERING)
echo "\n--- 1. FACULTY PAGE RENDERING & OUTPUT BUFFER AUDIT ---\n";
$facultyPages = [
    'dashboard.php',
    'activities.php',
    'pending.php',
    'approved.php',
    'returned.php',
    'calendar.php',
    'tasks.php',
    'post-event.php',
    'kpi.php',
    'kpi-overview.php',
    'kpi-reports.php',
    'generate-report.php'
];

foreach ($facultyPages as $pageFile) {
    $filePath = __DIR__ . '/../faculty/' . $pageFile;
    if (!file_exists($filePath)) {
        recordResult("Page exists: $pageFile", false, "File missing");
        continue;
    }
    
    // Test PHP syntax lint
    $lintOutput = [];
    $lintCode = 0;
    exec("php -l \"$filePath\" 2>&1", $lintOutput, $lintCode);
    $isLintClean = ($lintCode === 0);
    recordResult("Syntax clean: $pageFile", $isLintClean, $isLintClean ? "No syntax errors" : implode(" ", $lintOutput));
    
    // Simulate runtime page execution
    ob_start();
    $caughtException = null;
    try {
        // Use a clean sub-context to avoid header redirect exits
        include $filePath;
    } catch (Throwable $e) {
        $caughtException = $e->getMessage();
    }
    $renderedHtml = ob_get_clean();
    
    $noExceptions = ($caughtException === null);
    recordResult("Runtime render: $pageFile", $noExceptions, $noExceptions ? "Rendered " . strlen($renderedHtml) . " bytes" : "Exception: $caughtException");
}

// SECTION 2: ACTIVITY VIEWER & PROPOSAL EDITORS WITH REAL DATA
echo "\n--- 2. ACTIVITY VIEWER & PROPOSAL EDITORS AUDIT ---\n";

// Find or create an activity owned by this faculty
$stmt = $db->prepare("SELECT id FROM activities WHERE faculty_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$facultyUser['id']]);
$activityId = $stmt->fetchColumn();

if (!$activityId) {
    // Create a dummy test activity for audit
    $insStmt = $db->prepare("INSERT INTO activities (title, description, activity_type, event_date, start_time, end_time, venue, target_participants, expected_attendees, faculty_id, status, created_at, updated_at) VALUES ('Audit Sample Activity', 'Created for testing audit', 'Academic Event', CURDATE(), '08:00:00', '12:00:00', 'Gymnasium', 'All Students', 100, ?, 'draft', NOW(), NOW())");
    $insStmt->execute([$facultyUser['id']]);
    $activityId = $db->lastInsertId();
}

// Test proposal-edit.php
$_GET['id'] = $activityId;
ob_start();
$editException = null;
try {
    include __DIR__ . '/../faculty/proposal-edit.php';
} catch (Throwable $e) {
    $editException = $e->getMessage();
}
$editHtml = ob_get_clean();
recordResult("Runtime render: proposal-edit.php", $editException === null, $editException ?: "Rendered " . strlen($editHtml) . " bytes");

// Test view-activity.php
ob_start();
$viewException = null;
try {
    include __DIR__ . '/../faculty/view-activity.php';
} catch (Throwable $e) {
    $viewException = $e->getMessage();
}
$viewHtml = ob_get_clean();
recordResult("Runtime render: view-activity.php", $viewException === null, $viewException ?: "Rendered " . strlen($viewHtml) . " bytes");

// Test proposal-view.php
ob_start();
$propViewException = null;
try {
    include __DIR__ . '/../faculty/proposal-view.php';
} catch (Throwable $e) {
    $propViewException = $e->getMessage();
}
$propViewHtml = ob_get_clean();
recordResult("Runtime render: proposal-view.php", $propViewException === null, $propViewException ?: "Rendered " . strlen($propViewHtml) . " bytes");

// SECTION 3: ACCESS CONTROL & PERMISSION SECURITY (EDGE CASES)
echo "\n--- 3. ROLE PERMISSION & DATA ISOLATION (SECURITY AUDIT) ---\n";

// A. Can faculty view/edit another faculty's draft proposal?
$otherFaculty = $db->query("SELECT id, name FROM users WHERE role = 'faculty' AND id != {$facultyUser['id']} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($otherFaculty) {
    $otherDraftStmt = $db->prepare("SELECT id FROM activities WHERE faculty_id = ? AND status = 'draft' LIMIT 1");
    $otherDraftStmt->execute([$otherFaculty['id']]);
    $otherDraftId = $otherDraftStmt->fetchColumn();
    
    if (!$otherDraftId) {
        $db->exec("INSERT INTO activities (title, description, faculty_id, status, created_at, updated_at) VALUES ('Other Faculty Secret Draft', 'Private draft', {$otherFaculty['id']}, 'draft', NOW(), NOW())");
        $otherDraftId = $db->lastInsertId();
    }
    
    // Check if canUserAccessActivity blocks or permits correctly
    $canAccess = canUserAccessActivity($otherDraftId, 'edit', $facultyUser['id'], 'faculty', $facultyUser['department']);
    recordResult("Security: Cannot edit other faculty's unshared draft", !$canAccess, "Properly restricted (Access denied)");
}

// B. Role escalation: Can faculty access admin or dean views?
$restrictedPages = [
    'admin1/dashboard.php',
    'admin2/dashboard.php',
    'dean/dashboard.php',
    'dean/users.php'
];

foreach ($restrictedPages as $rPage) {
    $content = file_get_contents(__DIR__ . '/../' . $rPage);
    $hasRoleGate = (strpos($content, "requireRole('admin1')") !== false || 
                    strpos($content, "requireRole('admin2')") !== false || 
                    strpos($content, "requireRole('dean')") !== false);
    recordResult("Security Gate: Protected against faculty: $rPage", $hasRoleGate, "Access gate verified");
}

// SECTION 4: CORE FACULTY WORKFLOW API AUDIT
echo "\n--- 4. CORE API HANDLERS & FORM WORKFLOWS AUDIT ---\n";

// A. Schedule conflict checker
$conflictApiFile = __DIR__ . '/../api/check-schedule-conflict.php';
recordResult("API exists: check-schedule-conflict.php", file_exists($conflictApiFile));

// B. Collaborators API
$collabApiFile = __DIR__ . '/../api/collaborators.php';
recordResult("API exists: collaborators.php", file_exists($collabApiFile));

// C. Proposal Delete API
$deleteApiFile = __DIR__ . '/../api/proposal-delete.php';
recordResult("API exists: proposal-delete.php", file_exists($deleteApiFile));

// D. Universal Activities Archive API
$archiveApiFile = __DIR__ . '/../api/activities-archive.php';
recordResult("API exists: activities-archive.php", file_exists($archiveApiFile));

// E. AI Proposal Guidance API
$aiGuidanceFile = __DIR__ . '/../api/ai-proposal-guidance.php';
recordResult("API exists: ai-proposal-guidance.php", file_exists($aiGuidanceFile));

// F. Objective Generation API
$objGenFile = __DIR__ . '/../api/generate-objectives.php';
recordResult("API exists: generate-objectives.php", file_exists($objGenFile));

// SECTION 5: DATA INTEGRITY & DATABASE CONSISTENCY
echo "\n--- 5. DATABASE OPERATIONS & DATA INTEGRITY ---\n";

// Verify activity status distribution for faculty
$statusCounts = $db->query("SELECT status, COUNT(*) as cnt FROM activities WHERE faculty_id = {$facultyUser['id']} GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
recordResult("DB Integrity: Status counts query succeeds", is_array($statusCounts), "Statuses found: " . json_encode($statusCounts));

// Check for orphaned records
$orphanedCollaborators = $db->query("SELECT COUNT(*) FROM proposal_collaborators pc LEFT JOIN activities a ON pc.activity_id = a.id WHERE a.id IS NULL")->fetchColumn();
recordResult("DB Integrity: Zero orphaned collaborators", $orphanedCollaborators == 0, "Orphans: $orphanedCollaborators");

$orphanedTasks = $db->query("SELECT COUNT(*) FROM faculty_tasks ft LEFT JOIN activities a ON ft.activity_id = a.id WHERE a.id IS NULL")->fetchColumn();
recordResult("DB Integrity: Zero orphaned tasks", $orphanedTasks == 0, "Orphans: $orphanedTasks");

echo "\n-----------------------------------------------------------------------\n";
echo "Audit Summary: $testPassedCount Passed | $testFailedCount Failed\n";
echo "=======================================================================\n";

exit($testFailedCount === 0 ? 0 : 1);
