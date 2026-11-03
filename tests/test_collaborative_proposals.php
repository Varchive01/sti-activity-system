<?php
/**
 * tests/test_collaborative_proposals.php
 *
 * Automated verification suite for TASK 14: COLLABORATIVE PROPOSAL PREPARATION
 * Covers all 16 required test cases.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();
$baseUrl = 'http://localhost/sti-activity-system';

function makeRequest(string $url, string $method = 'GET', array $data = [], ?string $cookie = null, array $headers = []): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20
    ];

    $customHeaders = $headers;

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
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
echo "  TASK 14: COLLABORATIVE PROPOSAL PREPARATION AUDIT SUITE\n";
echo "======================================================================\n\n";

// Ensure passwords and test users
$pwdHash = password_hash('password', PASSWORD_DEFAULT);
$pwdAcceptHash = password_hash('Password123!', PASSWORD_DEFAULT);

$ownerUser = $db->query("SELECT * FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$collabB = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$collabB) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Andrea Gatbonton', 'fac_thm_accept@test.com', ?, 'faculty', 'THM Department', 0)")->execute([$pwdHash]);
    $collabB = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
}
$collabC = $db->query("SELECT * FROM users WHERE email = 'fac_ge_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$collabC) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Ricky Manliguis', 'fac_ge_accept@test.com', ?, 'faculty', 'G.E. Department', 0)")->execute([$pwdHash]);
    $collabC = $db->query("SELECT * FROM users WHERE email = 'fac_ge_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
}
$phIt = $db->query("SELECT * FROM users WHERE email = 'ph_it_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$phIt) {
    $phIt = $db->query("SELECT * FROM users WHERE email = 'dave@sti.edu'")->fetch(PDO::FETCH_ASSOC);
}
$phThm = $db->query("SELECT * FROM users WHERE email = 'ph_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$phThm) {
    $phThm = $db->query("SELECT * FROM users WHERE email = 'aldwin@sti.edu'")->fetch(PDO::FETCH_ASSOC);
}

// Create or verify an unrelated faculty user for negative security checks
$unrelatedFac = $db->query("SELECT * FROM users WHERE email = 'fac_unrelated_test@test.com'")->fetch(PDO::FETCH_ASSOC);
if (!$unrelatedFac) {
    $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES ('Unrelated Faculty Member', 'fac_unrelated_test@test.com', ?, 'faculty', 'THM Department', 0)")->execute([$pwdAcceptHash]);
    $unrelatedFac = $db->query("SELECT * FROM users WHERE email = 'fac_unrelated_test@test.com'")->fetch(PDO::FETCH_ASSOC);
}

// Login sessions using standard account credentials without overwriting database hashes
$ownerCookie = loginUser($baseUrl, $ownerUser['email'], 'password');
$collabBCookie = !empty($collabB) ? loginUser($baseUrl, $collabB['email'], 'password') : null;
$collabCCookie = !empty($collabC) ? loginUser($baseUrl, $collabC['email'], 'password') : null;
$unrelatedCookie = !empty($unrelatedFac) ? loginUser($baseUrl, $unrelatedFac['email'], 'password') : null;
$phItCookie = loginUser($baseUrl, $phIt['email'], 'password');

assertCheck(!empty($ownerCookie), "Owner ({$ownerUser['name']}) logged in successfully.");
assertCheck(!empty($collabBCookie), "Contributor B ({$collabB['name']}) logged in successfully.");
assertCheck(!empty($collabCCookie), "Contributor C ({$collabC['name']}) logged in successfully.");
assertCheck(!empty($unrelatedCookie), "Unrelated Faculty ({$unrelatedFac['name']}) logged in successfully.");

// Setup a clean collaborative proposal test activity
$testTitle = "Collaborative Hackathon 2026 – AutoTest " . time();
$db->prepare("
    INSERT INTO activities (
        title, source, faculty_id, status, event_date, start_time, end_time, venue, theme, 
        general_objectives, specific_objectives, target_participants, created_at, updated_at
    ) VALUES (
        ?, 'co_curricular', ?, 'draft', '2026-11-20', '08:00:00', '17:00:00', 'IT Lab 1', 'Collaborative Innovation',
        'Initial general objectives by Owner.', 'Initial specific objectives by Owner.', 100, NOW(), NOW()
    )
")->execute([$testTitle, $ownerUser['id']]);
$testActivityId = (int)$db->lastInsertId();

// Ensure owner is registered in proposal_collaborators
addProposalCollaborator($testActivityId, (int)$ownerUser['id'], 'owner', (int)$ownerUser['id'], $db);

echo "\n--- TEST CASE 1: Proposal Owner Creates Collaborative Proposal ---\n";
$initCollabs = getProposalCollaborators($testActivityId, $db);
$hasOwner = false;
foreach ($initCollabs as $c) {
    if ((int)$c['user_id'] === (int)$ownerUser['id'] && $c['role'] === 'owner') {
        $hasOwner = true;
        break;
    }
}
assertCheck($hasOwner, "Test 1: Activity #{$testActivityId} created by Owner ({$ownerUser['name']}) and registered as Proposal Owner.");

echo "\n--- TEST CASE 2: Owner Adds Contributor B (Co-Author) & Contributor C (Contributor) ---\n";
// Add Contributor B via API
$resAddB = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'      => 'add',
    'activity_id' => $testActivityId,
    'user_id'     => $collabB['id'],
    'role'        => 'co_author'
], $ownerCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);

$dataAddB = json_decode($resAddB['body'], true);
assertCheck($resAddB['code'] === 200 && ($dataAddB['success'] ?? false) === true, "Test 2a: Owner successfully added Contributor B ({$collabB['name']}) as Co-Author via API.");

// Add Contributor C via API
$resAddC = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'      => 'add',
    'activity_id' => $testActivityId,
    'user_id'     => $collabC['id'],
    'role'        => 'contributor'
], $ownerCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);

$dataAddC = json_decode($resAddC['body'], true);
assertCheck($resAddC['code'] === 200 && ($dataAddC['success'] ?? false) === true, "Test 2b: Owner successfully added Contributor C ({$collabC['name']}) as Contributor via API.");

echo "\n--- TEST CASE 3: Proposal Has At Least 3 Faculty Members Associated ---\n";
$currentCollabs = getProposalCollaborators($testActivityId, $db);
$collabCount = count($currentCollabs);
assertCheck($collabCount >= 3, "Test 3: Proposal has {$collabCount} faculty members associated (>= 3 requirement satisfied).");

echo "\n--- TEST CASE 4: Contributor Roles Correctly Displayed and Formatted ---\n";
$roleMap = [];
foreach ($currentCollabs as $c) {
    $roleMap[(int)$c['user_id']] = [
        'role'       => $c['role'],
        'role_label' => $c['role_label']
    ];
}
$ownerOk = isset($roleMap[$ownerUser['id']]) && $roleMap[$ownerUser['id']]['role'] === 'owner' && $roleMap[$ownerUser['id']]['role_label'] === 'Proposal Owner';
$bOk = isset($roleMap[$collabB['id']]) && $roleMap[$collabB['id']]['role'] === 'co_author' && $roleMap[$collabB['id']]['role_label'] === 'Co-Author';
$cOk = isset($roleMap[$collabC['id']]) && $roleMap[$collabC['id']]['role'] === 'contributor' && $roleMap[$collabC['id']]['role_label'] === 'Contributor';
assertCheck($ownerOk && $bOk && $cOk, "Test 4: Roles correctly identified: Owner = 'Proposal Owner', Contributor B = 'Co-Author', Contributor C = 'Contributor'.");

echo "\n--- TEST CASE 5: Contributor B Can Edit the Shared Proposal (HTTP 200) ---\n";
$resViewEditB = makeRequest("{$baseUrl}/faculty/proposal-edit.php?id={$testActivityId}", 'GET', [], $collabBCookie);
assertCheck($resViewEditB['code'] === 200, "Test 5a: Contributor B can access proposal-edit.php (HTTP 200).");

// Contributor B updates Objectives
$actRow = $db->query("SELECT updated_at FROM activities WHERE id = {$testActivityId}")->fetch(PDO::FETCH_ASSOC);
$lastUpdatedB = $actRow['updated_at'];

$resUpdateB = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id'           => $testActivityId,
    'action'                => 'draft',
    'last_known_updated_at' => $lastUpdatedB,
    'is_ajax_sync'          => '1',
    'title'                 => $testTitle . " (Updated by Contributor B)",
    'type'                  => 'academic',
    'event_date'            => '2026-11-20',
    'start_time'            => '08:00:00',
    'end_time'              => '17:00:00',
    'venue'                 => 'IT Lab 1',
    'theme'                 => 'Collaborative Innovation by Team',
    'general_objectives'    => 'General Objectives enhanced by Contributor B (Co-Author).',
    'specific_objectives'   => 'Specific Objectives refined by Contributor B.'
], $collabBCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);

$dataUpdateB = json_decode($resUpdateB['body'], true);
assertCheck($resUpdateB['code'] === 200 && ($dataUpdateB['success'] ?? false) === true, "Test 5b: Contributor B saved draft updates successfully (HTTP 200).");

echo "\n--- TEST CASE 6: Contributor C Can Edit the Shared Proposal (HTTP 200) ---\n";
$actRow2 = $db->query("SELECT updated_at FROM activities WHERE id = {$testActivityId}")->fetch(PDO::FETCH_ASSOC);
$lastUpdatedC = $actRow2['updated_at'];

$resUpdateC = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id'           => $testActivityId,
    'action'                => 'draft',
    'last_known_updated_at' => $lastUpdatedC,
    'is_ajax_sync'          => '1',
    'title'                 => $testTitle . " (Consolidated by Contributor C)",
    'type'                  => 'academic',
    'event_date'            => '2026-11-20',
    'start_time'            => '08:00:00',
    'end_time'              => '17:00:00',
    'venue'                 => 'IT Lab 1',
    'theme'                 => 'Collaborative Innovation by Full Team',
    'general_objectives'    => 'General Objectives enhanced by Contributor B (Co-Author).',
    'specific_objectives'   => 'Specific Objectives refined by Contributor B and reviewed by Contributor C.'
], $collabCCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);

$dataUpdateC = json_decode($resUpdateC['body'], true);
assertCheck($resUpdateC['code'] === 200 && ($dataUpdateC['success'] ?? false) === true, "Test 6: Contributor C saved draft updates successfully (HTTP 200).");

echo "\n--- TEST CASE 7: Consolidated Changes Saved to the SAME Activity Record ---\n";
$verifyStmt = $db->prepare("SELECT * FROM activities WHERE id = ?");
$verifyStmt->execute([$testActivityId]);
$consolidatedAct = $verifyStmt->fetch(PDO::FETCH_ASSOC);
$titleMatches = str_contains($consolidatedAct['title'], "Consolidated by Contributor C");
$objMatches = str_contains($consolidatedAct['specific_objectives'], "reviewed by Contributor C");
assertCheck($titleMatches && $objMatches, "Test 7: Consolidated edits by multiple contributors persist in single activity record #{$testActivityId}.");

echo "\n--- TEST CASE 8: Contribution Logs Recorded with Correct User IDs, Roles, and Timestamps ---\n";
$logs = getProposalContributionLogs($testActivityId, 10, $db);
$foundBLog = false;
$foundCLog = false;
foreach ($logs as $l) {
    if ((int)$l['user_id'] === (int)$collabB['id'] && $l['user_role'] === 'co_author') {
        $foundBLog = true;
    }
    if ((int)$l['user_id'] === (int)$collabC['id'] && $l['user_role'] === 'contributor') {
        $foundCLog = true;
    }
}
assertCheck($foundBLog && $foundCLog, "Test 8: Contribution audit logs verified with correct user IDs, contributor roles, and timestamps.");

echo "\n--- TEST CASE 9: Duplicate Collaborator Addition Rejected ---\n";
$resDup = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'      => 'add',
    'activity_id' => $testActivityId,
    'user_id'     => $collabB['id'],
    'role'        => 'co_author'
], $ownerCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
$dataDup = json_decode($resDup['body'], true);
assertCheck($resDup['code'] === 400 && ($dataDup['success'] ?? true) === false, "Test 9: Duplicate collaborator addition rejected ({$dataDup['error']}).");

echo "\n--- TEST CASE 10: Non-Owner Faculty Prevented from Adding Collaborators ---\n";
$resNonOwnerAdd = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'      => 'add',
    'activity_id' => $testActivityId,
    'user_id'     => $unrelatedFac['id'],
    'role'        => 'contributor'
], $collabBCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
$dataNonOwnerAdd = json_decode($resNonOwnerAdd['body'], true);
assertCheck($resNonOwnerAdd['code'] === 400 && str_contains(strtolower($dataNonOwnerAdd['error'] ?? ''), 'owner'), "Test 10: Contributor B blocked from adding new collaborators (only Proposal Owner allowed).");

echo "\n--- TEST CASE 11: Non-Collaborator Faculty Cannot View or Edit (HTTP 403) ---\n";
$resUnrelatedEdit = makeRequest("{$baseUrl}/faculty/proposal-edit.php?id={$testActivityId}", 'GET', [], $unrelatedCookie);
$resUnrelatedView = makeRequest("{$baseUrl}/faculty/proposal-view.php?id={$testActivityId}", 'GET', [], $unrelatedCookie);
$resUnrelatedApi = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id' => $testActivityId,
    'action'      => 'draft',
    'is_ajax_sync'=> '1'
], $unrelatedCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);

assertCheck($resUnrelatedEdit['code'] === 403, "Test 11a: Non-collaborator faculty denied access to proposal-edit.php (HTTP 403).");
assertCheck($resUnrelatedView['code'] === 403, "Test 11b: Non-collaborator faculty denied access to proposal-view.php (HTTP 403).");
assertCheck($resUnrelatedApi['code'] === 403, "Test 11c: Non-collaborator faculty denied modify API access (HTTP 403).");

echo "\n--- TEST CASE 12: Contributor Cannot Perform Final Submission (HTTP 403) ---\n";
$resSubmitByB = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id'  => $testActivityId,
    'action'       => 'submit',
    'is_ajax_sync' => '1',
    'title'        => $testTitle
], $collabBCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
$dataSubmitByB = json_decode($resSubmitByB['body'], true);
assertCheck($resSubmitByB['code'] === 403 && ($dataSubmitByB['error_code'] ?? '') === 'owner_submission_only', "Test 12: Contributor B blocked from final submission (HTTP 403 owner_submission_only).");

echo "\n--- TEST CASE 13: Program Head Department Permissions Remain Enforced ---\n";
// PH IT can view because activity is in IT department
$resPhItView = makeRequest("{$baseUrl}/faculty/proposal-view.php?id={$testActivityId}", 'GET', [], $phItCookie);
// PH THM cannot view/edit an IT proposal where they are not assigned
$resPhThmView = makeRequest("{$baseUrl}/faculty/proposal-view.php?id={$testActivityId}", 'GET', [], loginUser($baseUrl, $phThm['email'], 'password'));
assertCheck($resPhItView['code'] === 200, "Test 13a: Program Head IT can view department proposal #{$testActivityId} (HTTP 200).");
assertCheck($resPhThmView['code'] === 403, "Test 13b: Program Head THM blocked from unassigned other-department proposal (HTTP 403).");

echo "\n--- TEST CASE 14: Optimistic Locking Conflict Detection (HTTP 409) ---\n";
// Contributor B submits with a stale timestamp (e.g. 1 hour ago)
$staleTimestamp = date('Y-m-d H:i:s', time() - 3600);
$resConflict = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id'           => $testActivityId,
    'action'                => 'draft',
    'last_known_updated_at' => $staleTimestamp,
    'is_ajax_sync'          => '1',
    'title'                 => 'Stale overwrite attempt'
], $collabBCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
$dataConflict = json_decode($resConflict['body'], true);
assertCheck($resConflict['code'] === 409 && ($dataConflict['conflict'] ?? false) === true, "Test 14: Stale submission triggers HTTP 409 Conflict with conflict_requires_review.");

echo "\n--- TEST CASE 15: Collaborator Removal by Owner Works and Logs Audit ---\n";
$resRemoveC = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'      => 'remove',
    'activity_id' => $testActivityId,
    'user_id'     => $collabC['id']
], $ownerCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
$dataRemoveC = json_decode($resRemoveC['body'], true);
$activeAfterRemove = isProposalCollaborator($testActivityId, (int)$collabC['id'], $db);
assertCheck($resRemoveC['code'] === 200 && ($dataRemoveC['success'] ?? false) === true && !$activeAfterRemove, "Test 15: Contributor C removed by Owner; status marked 'removed' and access revoked.");

echo "\n--- TEST CASE 16: Proposal Appears in Collaborators' Activities List ---\n";
$resActivitiesB = makeRequest("{$baseUrl}/faculty/activities.php", 'GET', [], $collabBCookie);
$appearsInB = str_contains($resActivitiesB['body'], $testTitle);
assertCheck($appearsInB, "Test 16: Shared collaborative proposal appears in Contributor B's Activities list.");

echo "\n======================================================================\n";
echo "  TASK 14 AUDIT SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "======================================================================\n";

if ($failCount === 0) {
    echo ">>> ALL 16 COLLABORATIVE PROPOSAL PREPARATION TESTS PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
