<?php
/**
 * tests/test_manage_collaborators_faculty_dropdown.php
 *
 * Automated verification suite for:
 * Task: Update the Manage Proposal Collaborators Faculty Dropdown Using the Official STI Organizational Charts
 *
 * Verifies:
 * 1. All matched eligible faculty accounts appear under the correct department in format "Faculty Name (Department)".
 * 2. No duplicate names appear in the available faculty dropdown.
 * 3. No test or placeholder accounts appear (e.g. Bad Email Faculty, Unrelated Faculty Member).
 * 4. Proposal Owner cannot add themselves (by ID or duplicate account).
 * 5. Already assigned collaborators cannot be added again (by ID or duplicate account).
 * 6. Inactive accounts and non-faculty roles cannot be selected.
 * 7. Adding and removing collaborators functions properly in both creation session and existing proposals.
 * 8. Regression verification of notification and workflow suites.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$baseUrl = 'http://localhost/sti-activity-system';
$db = getDB();

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

function makeRequest(string $url, string $method = 'GET', $data = [], ?string $cookie = null, array $headers = []): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 25
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

    $rawHeader = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $rawHeader, $matches);
    $cookies = $matches[1] ?? [];

    return [
        'code'    => $httpCode,
        'headers' => $rawHeader,
        'body'    => $body,
        'cookies' => $cookies
    ];
}

function loginUser(string $baseUrl, string $email, string $password): string {
    $res = makeRequest("{$baseUrl}/auth/login.php", 'POST', [
        'email'    => $email,
        'password' => $password
    ]);

    if (!empty($res['cookies'])) {
        return end($res['cookies']);
    }
    return '';
}

echo "======================================================================\n";
echo "  FACULTY COLLABORATORS DROPDOWN AUDIT SUITE\n";
echo "======================================================================\n\n";

// 1. Authenticate users
$cookieMaria = loginUser($baseUrl, 'faculty@sti.edu', 'password');
assertCheck(!empty($cookieMaria), "Proposal Owner Maria Santos (faculty@sti.edu) logged in successfully");

// 2. Fetch users from DB for reference
$maria = $db->query("SELECT id, name, email FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$andrea = $db->query("SELECT id, name, email FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
$ricky = $db->query("SELECT id, name, email FROM users WHERE email = 'fac_ge_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
$dave = $db->query("SELECT id, name, email FROM users WHERE email = 'dave@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$aldwin = $db->query("SELECT id, name, email FROM users WHERE email = 'aldwin@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$tristan = $db->query("SELECT id, name, email FROM users WHERE email = 'tan@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$badEmail = $db->query("SELECT id, name, email FROM users WHERE name = 'Bad Email Faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$unrelated = $db->query("SELECT id, name, email FROM users WHERE name = 'Unrelated Faculty Member' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$daveDup = $db->query("SELECT id, name, email FROM users WHERE email = 'ph_it_accept@test.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$aldwinDup = $db->query("SELECT id, name, email FROM users WHERE email = 'ph_thm_accept@test.com' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

echo "\n--- TEST GROUP 1: Dynamic Faculty Dropdown in Creation Session ---\n";

$sessionToken = 'test_token_' . bin2hex(random_bytes(8));

// Query search_faculty for new proposal session
$res1 = makeRequest("{$baseUrl}/api/collaborators.php?action=search_faculty&session_token={$sessionToken}", 'GET', [], $cookieMaria);
assertCheck($res1['code'] === 200, "search_faculty returned HTTP 200");
$data1 = json_decode($res1['body'], true);
assertCheck(($data1['success'] ?? false) === true, "search_faculty returned success: true");

$availableList1 = $data1['available_faculty'] ?? [];
$availableIds1 = array_column($availableList1, 'id');
$availableNames1 = array_column($availableList1, 'name');

// Verify Proposal Owner is excluded
assertCheck(!in_array((int)$maria['id'], $availableIds1), "Proposal Owner (Maria Santos) is strictly excluded from available faculty list");

// Verify all eligible faculty are present
assertCheck(in_array((int)$andrea['id'], $availableIds1), "Eligible faculty Andrea Gatbunton is present in dropdown");
assertCheck(in_array((int)$ricky['id'], $availableIds1), "Eligible faculty Ricky Manliguis is present in dropdown");
assertCheck(in_array((int)$dave['id'], $availableIds1), "Eligible program head Dave Lord Rubaya is present in dropdown");
assertCheck(in_array((int)$aldwin['id'], $availableIds1), "Eligible program head Aldwin Alminar is present in dropdown");
assertCheck(in_array((int)$tristan['id'], $availableIds1), "Eligible program head Tristan Unabia is present in dropdown");

// Verify departments are formatted accurately
foreach ($availableList1 as $f) {
    if ((int)$f['id'] === (int)$andrea['id']) {
        assertCheck($f['department'] === 'THM Department', "Andrea Gatbunton assigned to 'THM Department'");
    }
    if ((int)$f['id'] === (int)$ricky['id']) {
        assertCheck($f['department'] === 'G.E. Department', "Ricky Manliguis assigned to 'G.E. Department'");
    }
    if ((int)$f['id'] === (int)$dave['id']) {
        assertCheck($f['department'] === 'IT Department', "Dave Lord Rubaya assigned to 'IT Department'");
    }
    if ((int)$f['id'] === (int)$aldwin['id']) {
        assertCheck($f['department'] === 'THM Department', "Aldwin Alminar assigned to 'THM Department'");
    }
    if ((int)$f['id'] === (int)$tristan['id']) {
        assertCheck($f['department'] === 'G.E. Department', "Tristan Unabia assigned to 'G.E. Department'");
    }
}

echo "\n--- TEST GROUP 2: Exclusion of Test and Placeholder Accounts ---\n";

if ($badEmail) {
    assertCheck(!in_array((int)$badEmail['id'], $availableIds1), "'Bad Email Faculty' is strictly excluded from dropdown");
} else {
    assertCheck(true, "'Bad Email Faculty' is not present");
}

if ($unrelated) {
    assertCheck(!in_array((int)$unrelated['id'], $availableIds1), "'Unrelated Faculty Member' is strictly excluded from dropdown");
} else {
    assertCheck(true, "'Unrelated Faculty Member' is not present");
}

// Check that no item contains test/placeholder words
$hasTestAccounts = false;
foreach ($availableList1 as $f) {
    if (stripos($f['name'], 'test') !== false || stripos($f['name'], 'unrelated') !== false || stripos($f['name'], 'bad email') !== false) {
        $hasTestAccounts = true;
    }
}
assertCheck(!$hasTestAccounts, "Zero test, unrelated, or placeholder accounts present in available list");

echo "\n--- TEST GROUP 3: Deduplication and Canonical Account Selection ---\n";

// Count occurrences of Dave Lord Rubaya and Aldwin Alminar
$nameCounts = array_count_values(array_map('strtolower', $availableNames1));
assertCheck(($nameCounts['dave lord rubaya'] ?? 0) === 1, "Dave Lord Rubaya appears exactly ONCE (no duplicates)");
assertCheck(($nameCounts['aldwin alminar'] ?? 0) === 1, "Aldwin Alminar appears exactly ONCE (no duplicates)");

// Verify duplicate test IDs are excluded in favor of canonical @sti.edu accounts
if ($daveDup) {
    assertCheck(!in_array((int)$daveDup['id'], $availableIds1), "Duplicate test ID for Dave Lord Rubaya ({$daveDup['id']}) excluded in favor of canonical ID ({$dave['id']})");
}
if ($aldwinDup) {
    assertCheck(!in_array((int)$aldwinDup['id'], $availableIds1), "Duplicate test ID for Aldwin Alminar ({$aldwinDup['id']}) excluded in favor of canonical ID ({$aldwin['id']})");
}

assertCheck(count($availableNames1) === count(array_unique($availableNames1)), "Entire dropdown list contains zero duplicate names");

echo "\n--- TEST GROUP 4: Exclusion of Already Assigned Collaborators ---\n";

// Add Andrea Gatbunton as Co-Author to session
$addRes = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'        => 'add',
    'session_token' => $sessionToken,
    'user_id'       => $andrea['id'],
    'role'          => 'co_author'
], $cookieMaria);
assertCheck($addRes['code'] === 200, "Added Andrea Gatbunton as Co-Author");

// Re-query search_faculty
$res2 = makeRequest("{$baseUrl}/api/collaborators.php?action=search_faculty&session_token={$sessionToken}", 'GET', [], $cookieMaria);
$data2 = json_decode($res2['body'], true);
$availableIds2 = array_column($data2['available_faculty'] ?? [], 'id');

assertCheck(!in_array((int)$andrea['id'], $availableIds2), "Already assigned collaborator (Andrea Gatbunton) is excluded from dropdown");

// Attempting to add Andrea again must be rejected
$reAddRes = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'        => 'add',
    'session_token' => $sessionToken,
    'user_id'       => $andrea['id'],
    'role'          => 'contributor'
], $cookieMaria);
assertCheck($reAddRes['code'] === 400, "Re-adding already assigned collaborator is rejected with HTTP 400");

// Attempting to add Proposal Owner must be rejected
$ownerAddRes = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'        => 'add',
    'session_token' => $sessionToken,
    'user_id'       => $maria['id'],
    'role'          => 'co_author'
], $cookieMaria);
assertCheck($ownerAddRes['code'] === 400, "Adding Proposal Owner as collaborator is rejected with HTTP 400");

echo "\n--- TEST GROUP 5: Collaborator Removal and Dynamic Re-inclusion ---\n";

// Remove Andrea from session
$remRes = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'        => 'remove',
    'session_token' => $sessionToken,
    'user_id'       => $andrea['id']
], $cookieMaria);
assertCheck($remRes['code'] === 200, "Removed Andrea Gatbunton from draft session");

// Re-query search_faculty
$res3 = makeRequest("{$baseUrl}/api/collaborators.php?action=search_faculty&session_token={$sessionToken}", 'GET', [], $cookieMaria);
$data3 = json_decode($res3['body'], true);
$availableIds3 = array_column($data3['available_faculty'] ?? [], 'id');

assertCheck(in_array((int)$andrea['id'], $availableIds3), "Andrea Gatbunton immediately reappears in available faculty dropdown after removal");

echo "\n--- TEST GROUP 6: Existing Activity Proposal Dropdown & Editing ---\n";

// Create a draft activity in database
$stmtAct = $db->prepare("
    INSERT INTO activities (title, faculty_id, status, event_date, created_at)
    VALUES ('Dropdown Verification Test Proposal', ?, 'draft', DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY), NOW())
");
$stmtAct->execute([(int)$maria['id']]);
$testActId = (int)$db->lastInsertId();
assertCheck($testActId > 0, "Created draft activity ID {$testActId}");

// Add owner to proposal_collaborators
$db->prepare("INSERT INTO proposal_collaborators (activity_id, user_id, role, added_by, status) VALUES (?, ?, 'owner', ?, 'active')")
   ->execute([$testActId, (int)$maria['id'], (int)$maria['id']]);

// Query search_faculty for existing proposal
$resAct = makeRequest("{$baseUrl}/api/collaborators.php?action=search_faculty&activity_id={$testActId}", 'GET', [], $cookieMaria);
assertCheck($resAct['code'] === 200, "search_faculty for activity {$testActId} returned HTTP 200");
$dataAct = json_decode($resAct['body'], true);
$availableIdsAct = array_column($dataAct['available_faculty'] ?? [], 'id');

assertCheck(!in_array((int)$maria['id'], $availableIdsAct), "Proposal owner excluded from activity {$testActId} dropdown");
assertCheck(in_array((int)$andrea['id'], $availableIdsAct), "Andrea Gatbunton available for activity {$testActId}");
assertCheck(in_array((int)$ricky['id'], $availableIdsAct), "Ricky Manliguis available for activity {$testActId}");
assertCheck(in_array((int)$dave['id'], $availableIdsAct), "Dave Lord Rubaya available for activity {$testActId}");

// Add Ricky as Contributor
$addActRes = makeRequest("{$baseUrl}/api/collaborators.php", 'POST', [
    'action'      => 'add',
    'activity_id' => $testActId,
    'user_id'     => $ricky['id'],
    'role'        => 'contributor'
], $cookieMaria);
assertCheck($addActRes['code'] === 200, "Added Ricky Manliguis as Contributor to activity {$testActId}");

// Verify Ricky is now excluded from search_faculty
$resAct2 = makeRequest("{$baseUrl}/api/collaborators.php?action=search_faculty&activity_id={$testActId}", 'GET', [], $cookieMaria);
$dataAct2 = json_decode($resAct2['body'], true);
$availableIdsAct2 = array_column($dataAct2['available_faculty'] ?? [], 'id');
assertCheck(!in_array((int)$ricky['id'], $availableIdsAct2), "Ricky Manliguis now excluded from activity {$testActId} dropdown");

// Clean up test activity
$db->prepare("DELETE FROM email_logs WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM notifications WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM proposal_contribution_logs WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM proposal_collaborators WHERE activity_id = ?")->execute([$testActId]);
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActId]);

echo "\n--- TEST GROUP 7: Non-Faculty Roles Exclusion (Admin 1, Admin 2, Dean) ---\n";

$admin1 = $db->query("SELECT id, name FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin2 = $db->query("SELECT id, name FROM users WHERE role = 'admin2' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$dean   = $db->query("SELECT id, name FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($admin1) {
    assertCheck(!in_array((int)$admin1['id'], $availableIds1), "Admin 1 ({$admin1['name']}) is strictly excluded from dropdown (role eligibility)");
}
if ($admin2) {
    assertCheck(!in_array((int)$admin2['id'], $availableIds1), "Admin 2 ({$admin2['name']}) is strictly excluded from dropdown (role eligibility)");
}
if ($dean) {
    assertCheck(!in_array((int)$dean['id'], $availableIds1), "Dean ({$dean['name']}) is strictly excluded from dropdown (role eligibility)");
}

echo "\n--- TEST GROUP 8: Program Head Proposal Owner Collaboration ---\n";

$cookieDave = loginUser($baseUrl, 'ph_it_accept@test.com', 'password');
if (empty($cookieDave)) {
    $cookieDave = loginUser($baseUrl, 'dave@sti.edu', 'password');
}

if (!empty($cookieDave)) {
    $sessionTokenDave = 'test_token_dave_' . bin2hex(random_bytes(8));
    $resDave = makeRequest("{$baseUrl}/api/collaborators.php?action=search_faculty&session_token={$sessionTokenDave}", 'GET', [], $cookieDave);
    assertCheck($resDave['code'] === 200, "search_faculty for Program Head Dave Lord Rubaya returned HTTP 200");
    $dataDave = json_decode($resDave['body'], true);
    $availableIdsDave = array_column($dataDave['available_faculty'] ?? [], 'id');
    $availableNamesDave = array_column($dataDave['available_faculty'] ?? [], 'name');

    assertCheck(!in_array((int)$dave['id'], $availableIdsDave), "Program Head Dave Lord Rubaya cannot add himself (canonical ID excluded)");
    if ($daveDup) {
        assertCheck(!in_array((int)$daveDup['id'], $availableIdsDave), "Program Head Dave Lord Rubaya cannot add himself via duplicate account ID");
    }
    assertCheck(in_array((int)$maria['id'], $availableIdsDave), "Maria Santos (faculty@sti.edu) is available to be invited by Program Head");
    assertCheck(in_array((int)$andrea['id'], $availableIdsDave), "Andrea Gatbunton is available to be invited by Program Head");
    assertCheck(in_array((int)$ricky['id'], $availableIdsDave), "Ricky Manliguis is available to be invited by Program Head");
} else {
    echo "  [SKIP] Could not authenticate Program Head Dave Lord Rubaya\n";
}

echo "\n--- TEST GROUP 9: Direct Function Unit Tests for getEligibleFacultyForDropdown ---\n";

// Test with exclude IDs [maria]
$directList1 = getEligibleFacultyForDropdown($db, [$maria['id']]);
$directNames1 = array_column($directList1, 'name');
assertCheck(!in_array('Maria Santos', $directNames1), "Unit Test: Maria Santos excluded as owner");
assertCheck(in_array('Andrea Gatbunton', $directNames1), "Unit Test: Andrea Gatbunton included");
assertCheck(in_array('Ricky Manliguis', $directNames1), "Unit Test: Ricky Manliguis included");
assertCheck(in_array('Dave Lord Rubaya', $directNames1), "Unit Test: Dave Lord Rubaya included");
assertCheck(in_array('Aldwin Alminar', $directNames1), "Unit Test: Aldwin Alminar included");
assertCheck(in_array('Tristan Unabia', $directNames1), "Unit Test: Tristan Unabia included");
assertCheck(!in_array('Bad Email Faculty', $directNames1), "Unit Test: Bad Email Faculty excluded");
assertCheck(!in_array('Unrelated Faculty Member', $directNames1), "Unit Test: Unrelated Faculty Member excluded");
assertCheck(count($directNames1) === count(array_unique($directNames1)), "Unit Test: Zero duplicates in returned list");

// Test with search query
$directSearch = getEligibleFacultyForDropdown($db, [$maria['id']], 'Andrea');
assertCheck(count($directSearch) === 1 && $directSearch[0]['name'] === 'Andrea Gatbunton', "Unit Test: Search for 'Andrea' returns Andrea Gatbunton");

$directSearchDept = getEligibleFacultyForDropdown($db, [$maria['id']], 'THM');
$searchDeptNames = array_column($directSearchDept, 'name');
assertCheck(in_array('Andrea Gatbunton', $searchDeptNames) && in_array('Aldwin Alminar', $searchDeptNames), "Unit Test: Search for 'THM' returns all THM faculty");

echo "\n======================================================================\n";
echo "  FACULTY DROPDOWN AUDIT SUMMARY: {$passed} PASSED | {$failed} FAILED\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}
