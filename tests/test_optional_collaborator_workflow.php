<?php
/**
 * tests/test_optional_collaborator_workflow.php
 *
 * Automated verification suite for:
 * OPTIONAL COLLABORATOR INVITATION WORKFLOW IN NEW PROPOSAL PAGE
 *
 * Verifies:
 * - Scenario A: Owner chooses Yes, adds Co-Author & Contributor, verifies "Send to Collaborators"
 *   in Steps 1-8, absent in Step 9, verifies no notifications sent before sending,
 *   verifies in-system & email notifications sent upon click, verifies collaborator access & owner-only submission.
 * - Scenario B: Owner chooses No, normal proposal creation, no Send to Collaborators button,
 *   no collaborator records or notifications.
 * - Scenario C: Welcome Banner and Sidebar entry points for Faculty & Program Head.
 * - Scenario D: Validations (missing title, no collaborators staged) & regression suite.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/EmailService.php';

$db = getDB();
$baseUrl = 'http://localhost/sti-activity-system';

function makeRequest(string $url, string $method = 'GET', $data = [], ?string $cookie = null, array $headers = []): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60
    ];

    $customHeaders = $headers;

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if (is_array($data) && (isset($data['poster_file']) || isset($data['floor_plan_file']))) {
            $opts[CURLOPT_POSTFIELDS] = $data;
        } else {
            $opts[CURLOPT_POSTFIELDS] = is_array($data) ? http_build_query($data) : $data;
        }
    }

    if ($cookie) {
        $opts[CURLOPT_COOKIE] = $cookie;
    }

    if (!empty($customHeaders)) {
        $opts[CURLOPT_HTTPHEADER] = $customHeaders;
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headerStr, $matches);
    $cookies = $matches[1] ?? [];

    return [
        'code' => $httpCode,
        'headers' => $headerStr,
        'body' => $body,
        'cookies' => $cookies
    ];
}

function loginUser(string $baseUrl, string $email, string $password): string {
    $res = makeRequest("{$baseUrl}/auth/login.php", 'POST', [
        'email' => $email,
        'password' => $password
    ]);

    if (!empty($res['cookies'])) {
        return end($res['cookies']);
    }
    return '';
}

$passCount = 0;
$failCount = 0;

function assertCheck(bool $condition, string $msg) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$msg}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failCount++;
    }
}

echo "======================================================================\n";
echo "  OPTIONAL COLLABORATOR INVITATION WORKFLOW AUDIT SUITE\n";
echo "======================================================================\n\n";

// 1. Setup & Resolve Accounts
$pwdHash = password_hash('password', PASSWORD_DEFAULT);

$facultyA = $db->query("SELECT * FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$facultyB = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$facultyB) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Andrea Gatbonton', 'fac_thm_accept@test.com', ?, 'faculty', 'THM Department', 0)")->execute([$pwdHash]);
    $facultyB = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
}
$facultyC = $db->query("SELECT * FROM users WHERE email = 'fac_ge_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$facultyC) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Ricky Manliguis', 'fac_ge_accept@test.com', ?, 'faculty', 'G.E. Department', 0)")->execute([$pwdHash]);
    $facultyC = $db->query("SELECT * FROM users WHERE email = 'fac_ge_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
}
$progHead = $db->query("SELECT * FROM users WHERE role = 'program_head' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$unrelatedFac = $db->query("SELECT * FROM users WHERE email = 'fac_unrelated_test@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$unrelatedFac) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Unrelated Faculty Member', 'fac_unrelated_test@test.com', ?, 'faculty', 'THM Department', 0)")->execute([$pwdHash]);
    $unrelatedFac = $db->query("SELECT * FROM users WHERE email = 'fac_unrelated_test@test.com'")->fetch(PDO::FETCH_ASSOC);
}

// Establish authenticated sessions using standard credentials without overwriting database hashes
$cookieA = loginUser($baseUrl, $facultyA['email'], 'password');
$cookieB = loginUser($baseUrl, $facultyB['email'], 'password');
$cookieC = loginUser($baseUrl, $facultyC['email'], 'password');
$cookieProgHead = loginUser($baseUrl, $progHead['email'], 'password');
$cookieUnrelated = loginUser($baseUrl, $unrelatedFac['email'], 'password');

assertCheck(!empty($cookieA), "Owner (Faculty A) logged in successfully");
assertCheck(!empty($cookieB), "Co-Author candidate (Faculty B) logged in successfully");
assertCheck(!empty($cookieC), "Contributor candidate (Faculty C) logged in successfully");
assertCheck(!empty($cookieProgHead), "Program Head logged in successfully");

function deleteActivitiesSafely(array $ids, PDO $db) {
    if (empty($ids)) return;
    $in = implode(',', array_map('intval', $ids));
    $db->exec("DELETE FROM notifications WHERE activity_id IN ({$in})");
    $db->exec("DELETE FROM email_logs WHERE activity_id IN ({$in})");
    $db->exec("DELETE FROM proposal_collaborators WHERE activity_id IN ({$in})");
    $db->exec("DELETE FROM proposal_contribution_logs WHERE activity_id IN ({$in})");
    $db->exec("DELETE FROM kpi_evaluations WHERE activity_id IN ({$in})");
    $db->exec("DELETE FROM floor_plans WHERE activity_id IN ({$in})");
    $db->exec("DELETE FROM documents WHERE activity_id IN ({$in})");
    $db->exec("DELETE FROM activities WHERE id IN ({$in})");
}

// Clean up any lingering prior test records safely
$oldIds = $db->query("SELECT id FROM activities WHERE title LIKE 'Test Collab Workflow%' OR title LIKE 'Test Standard Workflow%'")->fetchAll(PDO::FETCH_COLUMN);
deleteActivitiesSafely($oldIds, $db);

// Track activities created for clean teardown
$createdActivityIds = [];

// ============================================================================
// SCENARIO A: Owner Chooses YES (Invites Collaborators, Sends Draft)
// ============================================================================
echo "\n--- SCENARIO A: Owner Chooses Yes (Collaborators Workflow) ---\n";

// 1. Owner opens proposal-create.php
$createPageRes = makeRequest("{$baseUrl}/faculty/proposal-create.php", 'GET', [], $cookieA);
assertCheck($createPageRes['code'] === 200, "Proposal create page loads with HTTP 200");

// Check modal presence
assertCheck(strpos($createPageRes['body'], 'id="initCollabPromptModal"') !== false, "Initial popup modal (#initCollabPromptModal) is present in HTML");
assertCheck(strpos($createPageRes['body'], 'Add Collaborators?') !== false, "Popup title 'Add Collaborators?' is present");
assertCheck(strpos($createPageRes['body'], 'Do you want to add a Co-Author or Contributor to this proposal?') !== false, "Popup message prompt is present");
assertCheck(strpos($createPageRes['body'], 'id="btnInitCollabNo"') !== false, "Button 'No' is present");
assertCheck(strpos($createPageRes['body'], 'id="btnInitCollabYes"') !== false, "Button 'Yes, Add Collaborator' is present");

// Check collaborators management modal
assertCheck(strpos($createPageRes['body'], 'id="collaboratorsModal"') !== false, "Manage Collaborators modal (#collaboratorsModal) is present in HTML");
assertCheck(strpos($createPageRes['body'], 'Manage Proposal Collaborators') !== false, "Modal header 'Manage Proposal Collaborators' is present");

// Check Send to Collaborators button in Steps 1-8
preg_match_all('/<button[^>]*class="[^"]*send-to-collab-btn[^"]*"/', $createPageRes['body'], $btnMatches);
assertCheck(count($btnMatches[0]) === 8, "Send to Collaborators button exists exactly 8 times (Steps 1 through 8)");

// Check Step 9 strictly untouched (does NOT have send-to-collab-btn)
// Find panel-9 content
$panel9Start = strpos($createPageRes['body'], 'id="panel-9"');
$panel9End = strpos($createPageRes['body'], 'proposal-wizard-container', $panel9Start);
$panel9Html = ($panel9Start !== false && $panel9End !== false)
    ? substr($createPageRes['body'], $panel9Start, $panel9End - $panel9Start)
    : '';
assertCheck(strpos($panel9Html, 'send-to-collab-btn') === false, "Step 9 does NOT contain 'Send to Collaborators' button");
assertCheck(strpos($panel9Html, 'Save as Draft') !== false, "Step 9 contains standard 'Save as Draft' button");
assertCheck(strpos($panel9Html, 'Submit for Approval') !== false, "Step 9 contains standard 'Submit for Approval' button");

// Extract proposal session token
preg_match('/name="proposal_session_token"\s+id="proposal_session_token"\s+value="([^"]+)"/', $createPageRes['body'], $tokenMatch);
$sessionTokenA = $tokenMatch[1] ?? '';
assertCheck(!empty($sessionTokenA), "Generated session token found in page: {$sessionTokenA}");

// 2. Query initial temporary collaborator list
$collabListRes = makeRequest("{$baseUrl}/api/collaborators.php?action=list&session_token={$sessionTokenA}", 'GET', [], $cookieA);
$collabListData = json_decode($collabListRes['body'], true);
assertCheck($collabListRes['code'] === 200 && ($collabListData['success'] ?? false), "Initial staged collaborators list returns HTTP 200 with success");
assertCheck(($collabListData['collaborator_count'] ?? 0) === 1, "Initial list has count 1 (Owner)");
assertCheck(($collabListData['staged_count'] ?? -1) === 0, "Initial staged collaborators count is 0");

// 3. Search available faculty
$searchFacRes = makeRequest("{$baseUrl}/api/collaborators.php?action=search_faculty&session_token={$sessionTokenA}", 'GET', [], $cookieA);
$searchFacData = json_decode($searchFacRes['body'], true);
assertCheck($searchFacRes['code'] === 200 && ($searchFacData['success'] ?? false), "Available faculty search returns HTTP 200 with success");
$availableIds = array_column($searchFacData['available_faculty'] ?? [], 'id');
assertCheck(!in_array((int)$facultyA['id'], $availableIds), "Owner (Faculty A) is excluded from available faculty list");
assertCheck(in_array((int)$facultyB['id'], $availableIds), "Faculty B is available to be added");
assertCheck(in_array((int)$facultyC['id'], $availableIds), "Faculty C is available to be added");

// 4. Add Faculty B as Co-Author
$addBRes = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action' => 'add',
    'session_token' => $sessionTokenA,
    'user_id' => $facultyB['id'],
    'role' => 'co_author'
], $cookieA);
$addBData = json_decode($addBRes['body'], true);
assertCheck($addBRes['code'] === 200 && ($addBData['success'] ?? false), "Faculty B added as Co-Author in draft session");

// 5. Add Faculty C as Contributor
$addCRes = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action' => 'add',
    'session_token' => $sessionTokenA,
    'user_id' => $facultyC['id'],
    'role' => 'contributor'
], $cookieA);
$addCData = json_decode($addCRes['body'], true);
assertCheck($addCRes['code'] === 200 && ($addCData['success'] ?? false), "Faculty C added as Contributor in draft session");

// 6. Query updated list
$collabListUpdated = makeRequest("{$baseUrl}/api/collaborators.php?action=list&session_token={$sessionTokenA}", 'GET', [], $cookieA);
$updatedListData = json_decode($collabListUpdated['body'], true);
assertCheck(($updatedListData['collaborator_count'] ?? 0) === 3, "Total collaborators in session is now 3 (Owner + 2 staged)");
assertCheck(($updatedListData['staged_count'] ?? -1) === 2, "Staged collaborators count is 2");

// 7. Test removing and re-adding
$removeCRes = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action' => 'remove',
    'session_token' => $sessionTokenA,
    'user_id' => $facultyC['id']
], $cookieA);
$removeCData = json_decode($removeCRes['body'], true);
assertCheck($removeCRes['code'] === 200 && ($removeCData['success'] ?? false), "Faculty C temporarily removed from session");

// Re-add Faculty C as contributor
makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action' => 'add',
    'session_token' => $sessionTokenA,
    'user_id' => $facultyC['id'],
    'role' => 'contributor'
], $cookieA);

// 8. VERIFY: NO database records or notifications were created yet!
$chkActivities = $db->query("SELECT COUNT(*) FROM activities WHERE title LIKE 'Test Collab Workflow%'")->fetchColumn();
assertCheck((int)$chkActivities === 0, "Zero activities created in database prior to explicit Send to Collaborators");

$chkNotifsB = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)");
$chkNotifsB->execute([$facultyB['id']]);
assertCheck((int)$chkNotifsB->fetchColumn() === 0, "Zero in-system notifications received by Faculty B before proposal is sent");

$chkNotifsC = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)");
$chkNotifsC->execute([$facultyC['id']]);
assertCheck((int)$chkNotifsC->fetchColumn() === 0, "Zero in-system notifications received by Faculty C before proposal is sent");

// 9. OWNER CLICKS "SEND TO COLLABORATORS"
$eventDate = date('Y-m-d', strtotime('+7 days'));
$proposalPayload = [
    'action' => 'send_to_collaborators',
    'send_to_collaborators' => '1',
    'proposal_session_token' => $sessionTokenA,
    'title' => 'Test Collab Workflow: Campus Tech Symposium 2026',
    'theme' => 'Innovations in Applied Artificial Intelligence',
    'venue' => 'Main Auditorium',
    'event_date' => $eventDate,
    'start_time' => '09:00:00',
    'end_time' => '17:00:00',
    'general_objectives' => 'To demonstrate collaborative workflow for academic events.',
    'specific_objectives' => 'To verify notifications, role permissions, and access controls.',
    'rationale' => 'Essential collaborative initiative for student professional readiness.',
    'target_participants' => 150,
    'part_section' => ['BSIT 301'],
    'part_count' => [150],
    'part_teacher' => ['Prof. Maria Santos'],
    'kpi_indicator' => ['Participant Attendance Rate'],
    'kpi_target' => ['150 Participants'],
    'kpi_method' => ['Digital Attendance Sheet']
];

$sendRes = makeRequest("{$baseUrl}/api/proposal-save.php", 'POST', $proposalPayload, $cookieA);
assertCheck($sendRes['code'] === 302, "Send to Collaborators redirects with HTTP 302");
assertCheck(strpos($sendRes['headers'], 'faculty/proposal-edit.php?id=') !== false && strpos($sendRes['headers'], 'shared=1') !== false, "Redirects to proposal-edit.php with id and shared=1");

// Extract newly created activity ID from redirect Location
preg_match('/id=(\d+)/', $sendRes['headers'], $idMatch);
$actIdA = (int)($idMatch[1] ?? 0);
assertCheck($actIdA > 0, "Created activity ID is {$actIdA}");
if ($actIdA > 0) $createdActivityIds[] = $actIdA;

// 10. VERIFY DATABASE RECORD & COLLABORATORS
$actRow = $db->query("SELECT * FROM activities WHERE id = {$actIdA}")->fetch(PDO::FETCH_ASSOC);
assertCheck($actRow && $actRow['status'] === 'draft', "Proposal saved with status 'draft' (NOT submitted for Dean/Admin approval)");
assertCheck((int)$actRow['faculty_id'] === (int)$facultyA['id'], "Proposal owner is Faculty A");

$savedCollabs = getProposalCollaborators($actIdA, $db);
$savedRoles = [];
foreach ($savedCollabs as $sc) {
    $savedRoles[(int)$sc['user_id']] = $sc['role'];
}
assertCheck(count($savedCollabs) === 3, "Database proposal_collaborators has exactly 3 records");
assertCheck(($savedRoles[(int)$facultyA['id']] ?? '') === 'owner', "Faculty A is registered as 'owner'");
assertCheck(($savedRoles[(int)$facultyB['id']] ?? '') === 'co_author', "Faculty B is registered as 'co_author'");
assertCheck(($savedRoles[(int)$facultyC['id']] ?? '') === 'contributor', "Faculty C is registered as 'contributor'");

// 11. VERIFY NOTIFICATIONS & EMAILS AFTER SENDING
// Faculty B invitation notifications
$notifB = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND activity_id = ? AND message LIKE '%added as a Co-Author%' LIMIT 1");
$notifB->execute([$facultyB['id'], $actIdA]);
$notifBRow = $notifB->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifBRow), "Faculty B received in-system notification");
assertCheck(strpos($notifBRow['message'] ?? '', 'Co-Author') !== false, "Faculty B notification message identifies role 'Co-Author'");
assertCheck(strpos($notifBRow['message'] ?? '', $facultyA['name']) !== false, "Faculty B notification identifies inviting user (Faculty A)");

// Faculty C notifications
$notifC = $db->prepare("SELECT * FROM notifications WHERE user_id = ? AND activity_id = ? ORDER BY id DESC LIMIT 1");
$notifC->execute([$facultyC['id'], $actIdA]);
$notifCRow = $notifC->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($notifCRow), "Faculty C received in-system notification");
assertCheck(strpos($notifCRow['message'] ?? '', 'Contributor') !== false, "Faculty C notification message identifies role 'Contributor'");

// Email logs
$emailB = $db->prepare("SELECT * FROM email_logs WHERE recipient_email = ? AND activity_id = ? AND notification_type LIKE '%collab_invite%' ORDER BY id DESC LIMIT 1");
$emailB->execute([$facultyB['email'], $actIdA]);
$emailBRow = $emailB->fetch(PDO::FETCH_ASSOC);
assertCheck(!empty($emailBRow), "Faculty B received email notification record in email_logs");
assertCheck(strpos($emailBRow['notification_type'] ?? '', 'collab_invite') !== false, "Faculty B email notification_type is 'collab_invite'");

// Verify Owner (Faculty A) did NOT receive self-notification
$notifA = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND activity_id = ?");
$notifA->execute([$facultyA['id'], $actIdA]);
assertCheck((int)$notifA->fetchColumn() === 0, "Faculty A (Owner) received 0 self-notifications");

// Verify Unrelated Faculty received NO notifications
$notifUnrelated = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND activity_id = ?");
$notifUnrelated->execute([$unrelatedFac['id'], $actIdA]);
assertCheck((int)$notifUnrelated->fetchColumn() === 0, "Unrelated faculty member received 0 notifications");

// 12. VERIFY COLLABORATOR ACCESS & SECURITY
// Faculty B can view proposal edit page
$editResB = makeRequest("{$baseUrl}/faculty/proposal-edit.php?id={$actIdA}", 'GET', [], $cookieB);
assertCheck($editResB['code'] === 200, "Faculty B (Co-Author) can open shared proposal edit page with HTTP 200");

// Faculty C can view proposal edit page
$editResC = makeRequest("{$baseUrl}/faculty/proposal-edit.php?id={$actIdA}", 'GET', [], $cookieC);
assertCheck($editResC['code'] === 200, "Faculty C (Contributor) can open shared proposal edit page with HTTP 200");

// Unrelated Faculty is BLOCKED (HTTP 403)
$editResUnrelated = makeRequest("{$baseUrl}/faculty/proposal-edit.php?id={$actIdA}", 'GET', [], $cookieUnrelated);
assertCheck($editResUnrelated['code'] === 403, "Unrelated faculty is blocked with HTTP 403 from editing shared proposal");

// Faculty B contributes an edit
$updateResB = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id' => $actIdA,
    'action' => 'draft',
    'title' => 'Test Collab Workflow: Campus Tech Symposium 2026',
    'theme' => 'Innovations in Applied Artificial Intelligence',
    'venue' => 'Main Auditorium',
    'event_date' => $eventDate,
    'start_time' => '09:00:00',
    'end_time' => '17:00:00',
    'general_objectives' => 'Updated by Co-Author Faculty B to enhance student participation.',
    'specific_objectives' => 'Specific objective updated by Faculty B.',
    'rationale' => 'Essential collaborative initiative for student professional readiness.',
    'target_participants' => 150
], $cookieB);
assertCheck($updateResB['code'] === 302 || $updateResB['code'] === 200, "Faculty B can save collaborative changes to the shared proposal");

// Faculty B cannot submit for approval (owner-only submission)
$submitResB = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id' => $actIdA,
    'action' => 'submit',
    'title' => 'Test Collab Workflow: Campus Tech Symposium 2026',
    'theme' => 'Innovations in Applied Artificial Intelligence',
    'venue' => 'Main Auditorium',
    'event_date' => $eventDate,
    'start_time' => '09:00:00',
    'end_time' => '17:00:00',
    'general_objectives' => 'Updated objectives',
    'specific_objectives' => 'Updated specific objectives',
    'rationale' => 'Essential collaborative initiative for student professional readiness.',
    'target_participants' => 150
], $cookieB);
// Verify proposal status remained 'draft'
$actStatusCheck = $db->query("SELECT status FROM activities WHERE id = {$actIdA}")->fetchColumn();
assertCheck($actStatusCheck === 'draft', "Proposal remains 'draft' after unauthorized collaborator submit attempt (owner-only rule enforced)");


// ============================================================================
// SCENARIO B: Owner Chooses NO (Standard Proposal Creation)
// ============================================================================
echo "\n--- SCENARIO B: Owner Chooses No (Standard Workflow) ---\n";

// 1. Owner opens proposal-create.php
$createPageResB = makeRequest("{$baseUrl}/faculty/proposal-create.php", 'GET', [], $cookieA);
preg_match('/name="proposal_session_token"\s+id="proposal_session_token"\s+value="([^"]+)"/', $createPageResB['body'], $tokenMatchB);
$sessionTokenB = $tokenMatchB[1] ?? '';

// 2. Owner chooses No: does NOT add any collaborators. Saves proposal normally as draft.
$eventDateB = date('Y-m-d', strtotime('+8 days'));
$standardPayload = [
    'action' => 'draft',
    'proposal_session_token' => $sessionTokenB,
    'title' => 'Test Standard Workflow: Single Faculty Proposal',
    'theme' => 'Standard Non-Collaborative Activity',
    'venue' => 'Computer Lab 301',
    'event_date' => $eventDateB,
    'start_time' => '10:00:00',
    'end_time' => '15:00:00',
    'general_objectives' => 'Standard non-collaborative general objectives.',
    'specific_objectives' => 'Standard non-collaborative specific objectives.',
    'rationale' => 'Standard initiative created without collaborators.',
    'target_participants' => 50,
    'part_section' => ['BSIT 201'],
    'part_count' => [50],
    'part_teacher' => ['Prof. Maria Santos'],
    'kpi_indicator' => ['Attendance'],
    'kpi_target' => ['50 Participants'],
    'kpi_method' => ['Sign-in sheet']
];

$standardSaveRes = makeRequest("{$baseUrl}/api/proposal-save.php", 'POST', $standardPayload, $cookieA);
assertCheck($standardSaveRes['code'] === 302, "Standard draft proposal saves with HTTP 302");

// Find created proposal
$actRowB = $db->query("SELECT id, faculty_id, status FROM activities WHERE title = 'Test Standard Workflow: Single Faculty Proposal' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$actIdB = (int)($actRowB['id'] ?? 0);
assertCheck($actIdB > 0, "Created standard activity ID is {$actIdB}");
if ($actIdB > 0) $createdActivityIds[] = $actIdB;

// 3. Verify ONLY Owner exists in proposal_collaborators
$collabsB = getProposalCollaborators($actIdB, $db);
assertCheck(count($collabsB) === 1, "Only 1 collaborator record exists for standard proposal");
assertCheck((int)$collabsB[0]['user_id'] === (int)$facultyA['id'] && $collabsB[0]['role'] === 'owner', "The sole collaborator is the Proposal Owner");

// 4. Verify no collaborator invitation notifications were created
$notifsCountStandard = $db->query("SELECT COUNT(*) FROM notifications WHERE activity_id = {$actIdB}")->fetchColumn();
assertCheck((int)$notifsCountStandard === 0, "Zero collaborator notifications generated for standard proposal");


// ============================================================================
// SCENARIO C: Welcome Banner and Sidebar Navigation Check
// ============================================================================
echo "\n--- SCENARIO C: Welcome Banner and Sidebar Navigation ---\n";

// Sidebar link verification
$sidebarFile = file_get_contents(__DIR__ . '/../includes/sidebar.php');
assertCheck(strpos($sidebarFile, "['icon' => 'plus-circle', 'label' => 'New Proposal', 'href' => BASE_URL . '/faculty/proposal-create.php']") !== false, "Sidebar contains 'New Proposal' linking to /faculty/proposal-create.php");

// Welcome banner verification
$bannerFile = file_get_contents(__DIR__ . '/../includes/dashboard-welcome-banner.php');
assertCheck(strpos($bannerFile, "'href'               => \$baseUrl . '/faculty/proposal-create.php'") !== false, "Welcome banner contains New Proposal button linking to /faculty/proposal-create.php");

// Program Head access to proposal-create.php
$progHeadRes = makeRequest("{$baseUrl}/faculty/proposal-create.php", 'GET', [], $cookieProgHead);
assertCheck($progHeadRes['code'] === 200, "Program Head can access proposal-create.php with HTTP 200");
assertCheck(strpos($progHeadRes['body'], 'id="initCollabPromptModal"') !== false, "Initial collaborator popup appears for Program Head");
assertCheck(strpos($progHeadRes['body'], 'send-to-collab-btn') !== false, "Program Head creation form includes Send to Collaborators button");


// ============================================================================
// SCENARIO D: Validation and Regression Testing
// ============================================================================
echo "\n--- SCENARIO D: Validation and Regression Testing ---\n";

// 1. Send to Collaborators with missing title
$invalidTitlePayload = [
    'action' => 'send_to_collaborators',
    'send_to_collaborators' => '1',
    'proposal_session_token' => 'invalid_token_test',
    'title' => '', // empty title
    'theme' => 'Testing validation'
];
$errTitleRes = makeRequest("{$baseUrl}/api/proposal-save.php", 'POST', $invalidTitlePayload, $cookieA, [
    'Accept: application/json',
    'X-Requested-With: XMLHttpRequest'
]);
$errTitleData = json_decode($errTitleRes['body'], true);
assertCheck($errTitleRes['code'] === 400 && ($errTitleData['success'] ?? true) === false, "Send to Collaborators without title is rejected with HTTP 400");
assertCheck(strpos($errTitleData['error'] ?? '', 'Title is required') !== false, "Error message correctly identifies missing Activity Title");

// 2. Send to Collaborators with empty collaborators list
$emptyCollabToken = bin2hex(random_bytes(16));
$errEmptyCollabPayload = [
    'action' => 'send_to_collaborators',
    'send_to_collaborators' => '1',
    'proposal_session_token' => $emptyCollabToken,
    'title' => 'Proposal with no collaborators staged',
    'theme' => 'Testing empty collab'
];
$errEmptyCollabRes = makeRequest("{$baseUrl}/api/proposal-save.php", 'POST', $errEmptyCollabPayload, $cookieA, [
    'Accept: application/json',
    'X-Requested-With: XMLHttpRequest'
]);
$errEmptyCollabData = json_decode($errEmptyCollabRes['body'], true);
assertCheck($errEmptyCollabRes['code'] === 400 && ($errEmptyCollabData['success'] ?? true) === false, "Send to Collaborators with 0 staged collaborators is rejected with HTTP 400");
assertCheck(strpos($errEmptyCollabData['error'] ?? '', 'No collaborators') !== false, "Error message correctly identifies no collaborators added");

// 3. Verify optimistic locking on shared proposal
$optimisticConflict = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id' => $actIdA,
    'action' => 'draft',
    'title' => 'Conflicting concurrent edit',
    'last_known_updated_at' => date('Y-m-d H:i:s', strtotime('-1 hour'))
], $cookieB, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
$conflictData = json_decode($optimisticConflict['body'], true);
assertCheck($optimisticConflict['code'] === 409 || ($conflictData['conflict'] ?? false) === true, "Optimistic locking / 409 conflict detection works for concurrent edits");


// ============================================================================
// CLEANUP TEST DATA
// ============================================================================
echo "\n--- Cleaning up test records ---\n";
deleteActivitiesSafely($createdActivityIds, $db);
echo "Cleaned up " . count($createdActivityIds) . " test activity records.\n";

echo "\n======================================================================\n";
echo "  RESULTS: {$passCount} PASSED, {$failCount} FAILED\n";
echo "======================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
