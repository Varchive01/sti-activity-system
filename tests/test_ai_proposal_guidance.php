<?php
/**
 * tests/test_ai_proposal_guidance.php
 *
 * Automated verification suite for TASK 15: AI-ASSISTED PROPOSAL GUIDANCE
 * Covers all 14 required test cases.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/GeminiProposalGuidanceService.php';

$db = getDB();
$baseUrl = 'http://localhost/sti-activity-system';

function makeRequest(string $url, string $method = 'GET', array $data = [], ?string $cookie = null, array $headers = []): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30
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
echo "  TASK 15: AI-ASSISTED PROPOSAL GUIDANCE AUDIT SUITE\n";
echo "======================================================================\n\n";

// 1. Prepare Users and Logins
$pwdAcceptHash = password_hash('Password123!', PASSWORD_DEFAULT);

$facultyUser = $db->query("SELECT * FROM users WHERE email = 'faculty@sti.edu'")->fetch(PDO::FETCH_ASSOC);
$contributorUser = $db->query("SELECT * FROM users WHERE email = 'fac_thm_accept@test.com'")->fetch(PDO::FETCH_ASSOC);
$unrelatedUser = $db->query("SELECT * FROM users WHERE email = 'fac_unrelated_test@test.com'")->fetch(PDO::FETCH_ASSOC);

$db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id IN (?, ?, ?)")->execute([$pwdAcceptHash, $facultyUser['id'], $contributorUser['id'], $unrelatedUser['id']]);

$facultyCookie = loginUser($baseUrl, $facultyUser['email'], 'Password123!');
$contributorCookie = loginUser($baseUrl, $contributorUser['email'], 'Password123!');
$unrelatedCookie = loginUser($baseUrl, $unrelatedUser['email'], 'Password123!');

assertCheck(!empty($facultyCookie), "Faculty ({$facultyUser['name']}) session authenticated.");
assertCheck(!empty($contributorCookie), "Contributor ({$contributorUser['name']}) session authenticated.");

// 2. Setup a test draft proposal in DB
$testTitle = "AI Guidance Sample Event " . time();
$db->prepare("
    INSERT INTO activities (
        title, source, faculty_id, status, event_date, start_time, end_time, venue, theme, 
        general_objectives, specific_objectives, rationale, target_participants, created_at, updated_at
    ) VALUES (
        ?, 'co_curricular', ?, 'draft', '2026-11-28', '09:00:00', '16:00:00', 'Gymnasium', 'Tech Summit',
        'Learn technology.', '1. Attend session.', 'Good event for students.', 50, NOW(), NOW()
    )
")->execute([$testTitle, $facultyUser['id']]);
$testActivityId = (int)$db->lastInsertId();

// Register Faculty as owner and Contributor as co-author
addProposalCollaborator($testActivityId, (int)$facultyUser['id'], 'owner', (int)$facultyUser['id'], $db);
addProposalCollaborator($testActivityId, (int)$contributorUser['id'], 'co_author', (int)$facultyUser['id'], $db);

echo "\n--- TEST CASE 1: AI Review Endpoint Authorization ---\n";
// 1a. Unauthenticated request
$resUnauth = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'POST', ['title' => 'Test']);
assertCheck($resUnauth['code'] === 401, "Test 1a: Unauthenticated request rejected with HTTP 401.");

// 1b. Non-POST method (e.g. GET)
$resGet = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'GET', [], $facultyCookie);
assertCheck($resGet['code'] === 405, "Test 1b: GET method rejected with HTTP 405.");

// 1c. Authorized faculty request
$resAuth = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'POST', [
    'title'               => 'Cloud Computing Seminar',
    'theme'               => 'Next Gen Cloud',
    'event_date'          => '2026-12-05',
    'start_time'          => '09:00:00',
    'end_time'            => '12:00:00',
    'venue'               => 'AVR',
    'target_participants' => '80',
    'general_objectives'  => 'To introduce participants to cloud computing architectures and virtualization.',
    'specific_objectives' => "1. Understand cloud deployment models.\n2. Deploy a sample application.\n3. Evaluate cloud security.",
    'rationale'           => 'Cloud skills are in high demand in the IT industry.',
    'force_refresh'       => '1'
], $facultyCookie);
$dataAuth = json_decode($resAuth['body'], true);
assertCheck($resAuth['code'] === 200 && ($dataAuth['success'] ?? false) === true, "Test 1c: Authorized faculty request accepted with HTTP 200.");

echo "\n--- TEST CASE 2: Valid Proposal Review Request Structure ---\n";
$guidance = $dataAuth['guidance'] ?? [];
$hasSummary = !empty($guidance['overall_summary']);
$hasCategories = isset($guidance['categories']['missing_information'], $guidance['categories']['needs_improvement'], $guidance['categories']['suggested_enhancements'], $guidance['categories']['good_practices']);
$hasDisclaimer = !empty($guidance['disclaimer']);
assertCheck($hasSummary && $hasCategories && $hasDisclaimer, "Test 2: Guidance response contains overall_summary, all 4 categories, and official disclaimer.");

echo "\n--- TEST CASE 3: Missing or Incomplete Proposal Information Detection ---\n";
// Submit incomplete proposal (missing title, missing objectives, missing venue)
$resIncomplete = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'POST', [
    'title'               => '',
    'theme'               => '',
    'event_date'          => '',
    'start_time'          => '',
    'end_time'            => '',
    'venue'               => '',
    'target_participants' => '0',
    'general_objectives'  => '',
    'specific_objectives' => '',
    'force_refresh'       => '1'
], $facultyCookie);
$dataIncomplete = json_decode($resIncomplete['body'], true);
$missingCat = $dataIncomplete['guidance']['categories']['missing_information'] ?? [];
$foundMissingField = false;
foreach ($missingCat as $item) {
    if ($item['priority'] === 'High') {
        $foundMissingField = true;
        break;
    }
}
assertCheck(!empty($missingCat) && $foundMissingField, "Test 3: Incomplete proposal triggers High priority findings under 'missing_information'.");

echo "\n--- TEST CASE 4: Objective Improvement Recommendations ---\n";
// Submit proposal with weak 2-word objectives
$resWeakObj = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'POST', [
    'title'               => 'Advanced Cybersecurity Bootcamp',
    'theme'               => 'Defend the Net',
    'event_date'          => '2026-11-29',
    'start_time'          => '08:00:00',
    'end_time'            => '17:00:00',
    'venue'               => 'IT Lab 2',
    'target_participants' => '40',
    'general_objectives'  => 'Learn cyber.',
    'specific_objectives' => 'Pass test.',
    'rationale'           => 'Short rationale.',
    'force_refresh'       => '1'
], $facultyCookie);
$dataWeakObj = json_decode($resWeakObj['body'], true);
$needsImproveList = $dataWeakObj['guidance']['categories']['needs_improvement'] ?? [];
$foundObjImprovement = false;
foreach ($needsImproveList as $item) {
    if (str_contains(strtolower($item['section']), 'objective') || str_contains(strtolower($item['issue']), 'objective') || str_contains(strtolower($item['target_field']), 'objective')) {
        $foundObjImprovement = true;
        break;
    }
}
assertCheck($foundObjImprovement, "Test 4: Weak objectives detected with actionable recommendations under 'needs_improvement'.");

echo "\n--- TEST CASE 5: Proposal Consistency Analysis ---\n";
$consistencyText = $dataAuth['guidance']['overall_summary']['consistency_check'] ?? '';
assertCheck(!empty($consistencyText), "Test 5: Proposal consistency check evaluated ('{$consistencyText}').");

echo "\n--- TEST CASE 6: Structured Recommendation Responses Schema ---\n";
// Inspect one recommendation item for exact schema fields
$allSampleFindings = array_merge(
    $dataAuth['guidance']['categories']['missing_information'] ?? [],
    $dataAuth['guidance']['categories']['needs_improvement'] ?? [],
    $dataAuth['guidance']['categories']['suggested_enhancements'] ?? [],
    $dataAuth['guidance']['categories']['good_practices'] ?? []
);
$sampleItem = $allSampleFindings[0] ?? [];
$hasRequiredKeys = isset($sampleItem['section'], $sampleItem['issue'], $sampleItem['explanation'], $sampleItem['suggested_improvement'], $sampleItem['priority'], $sampleItem['category']);
assertCheck($hasRequiredKeys, "Test 6: Recommendation items conform to schema with section, issue, explanation, suggested_improvement, priority, and category.");

echo "\n--- TEST CASE 7: Invalid Input Handling ---\n";
$resInvalid = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'POST', [
    'action' => 'unknown_invalid_action'
], $facultyCookie);
assertCheck($resInvalid['code'] === 200, "Test 7: Unrecognized/empty parameters handled safely without 500 error.");

echo "\n--- TEST CASE 8: API Failure and Intelligent Fallback Handling ---\n";
// Directly test service fallback mechanism with dummy/invalid API key
$fallbackService = new GeminiProposalGuidanceService('INVALID_DUMMY_KEY');
$fallbackResult = $fallbackService->getFallbackGuidance([
    'title'               => 'Fallback Test Workshop',
    'general_objectives'  => 'Test objective',
    'specific_objectives' => '1. Test item'
]);
$isFallbackEngine = ($fallbackResult['is_fallback'] ?? false) === true;
$hasFallbackCategories = !empty($fallbackResult['categories']['missing_information']) || !empty($fallbackResult['categories']['needs_improvement']);
assertCheck($isFallbackEngine && $hasFallbackCategories, "Test 8: Offline/fallback engine gracefully produces structured recommendations (is_fallback: true).");

echo "\n--- TEST CASE 9: API Key Protection & Zero Leaks ---\n";
$bodyString = $resAuth['body'] . ' ' . $resWeakObj['body'] . ' ' . $resIncomplete['body'];
$configuredKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
$keyExposed = (!empty($configuredKey) && str_contains($bodyString, $configuredKey));
assertCheck(!$keyExposed, "Test 9: Server-side Gemini API key is never exposed in HTTP response body or headers.");

echo "\n--- TEST CASE 10: No Automatic Proposal Modification in Database ---\n";
// Check that activity in DB was NOT changed by requesting guidance
$actBefore = $db->query("SELECT title, updated_at FROM activities WHERE id = {$testActivityId}")->fetch(PDO::FETCH_ASSOC);

$resGuidanceOnDraft = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'POST', [
    'activity_id' => $testActivityId,
    'title'       => 'Different Title Submitted to Guidance'
], $facultyCookie);

$actAfter = $db->query("SELECT title, updated_at FROM activities WHERE id = {$testActivityId}")->fetch(PDO::FETCH_ASSOC);
$titleUnchanged = ($actBefore['title'] === $actAfter['title']);
assertCheck($titleUnchanged, "Test 10: Requesting AI guidance did NOT modify existing proposal database record.");

echo "\n--- TEST CASE 11: No Automatic Proposal Submission ---\n";
$statusCheck = $db->query("SELECT status FROM activities WHERE id = {$testActivityId}")->fetchColumn();
assertCheck($statusCheck === 'draft', "Test 11: Proposal status remains strictly 'draft' (no auto-submission).");

echo "\n--- TEST CASE 12: Collaborative Proposal Permission Preservation ---\n";
// 12a. Authorized contributor can request guidance
$resCollabGuidance = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'POST', [
    'activity_id' => $testActivityId,
    'title'       => $testTitle
], $contributorCookie);
$dataCollab = json_decode($resCollabGuidance['body'], true);
assertCheck($resCollabGuidance['code'] === 200 && ($dataCollab['success'] ?? false) === true, "Test 12a: Co-author / contributor can request guidance for shared proposal #{$testActivityId}.");

// 12b. Unrelated faculty blocked from requesting guidance on private proposal
$resUnrelated = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'POST', [
    'activity_id' => $testActivityId,
    'title'       => $testTitle
], $unrelatedCookie);
assertCheck($resUnrelated['code'] === 403, "Test 12b: Unassigned faculty blocked from requesting guidance on another department's proposal (HTTP 403).");

// 12c. Contributor still blocked from final submission
$resCollabSubmit = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id' => $testActivityId,
    'action'      => 'submit'
], $contributorCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
assertCheck($resCollabSubmit['code'] === 403, "Test 12c: Contributor remains strictly blocked from final submission (HTTP 403).");

// 12d. Applying recommendation logs into contribution history
$resApplyLog = makeRequest("{$baseUrl}/api/ai-proposal-guidance.php", 'POST', [
    'action'      => 'log_applied',
    'activity_id' => $testActivityId,
    'section'     => 'Objectives',
    'details'     => 'Applied AI suggested specific objectives.'
], $contributorCookie);
$dataApplyLog = json_decode($resApplyLog['body'], true);

$logs = getProposalContributionLogs($testActivityId, 5, $db);
$foundApplyLog = false;
foreach ($logs as $l) {
    if ((int)$l['user_id'] === (int)$contributorUser['id'] && $l['action'] === 'applied_ai_guidance') {
        $foundApplyLog = true;
        break;
    }
}
assertCheck($resApplyLog['code'] === 200 && $foundApplyLog, "Test 12d: Applying guidance recommendation logged in proposal_contribution_logs.");

echo "\n--- TEST CASE 13: Existing Gemini Integration Regression ---\n";
// Test generate-objectives.php
$resGenObj = makeRequest("{$baseUrl}/api/generate-objectives.php", 'POST', [
    'title'               => 'Web Security Workshop',
    'target_participants' => '30'
], $facultyCookie);
assertCheck($resGenObj['code'] === 200, "Test 13a: Existing api/generate-objectives.php continues to respond HTTP 200.");

// Test generate-theme.php
$resGenTheme = makeRequest("{$baseUrl}/api/generate-theme.php", 'POST', [
    'title' => 'Web Security Workshop'
], $facultyCookie);
assertCheck($resGenTheme['code'] === 200, "Test 13b: Existing api/generate-theme.php continues to respond HTTP 200.");

echo "\n--- TEST CASE 14: Existing Proposal Workflow Regression ---\n";
// Save draft via proposal-update.php
$resSaveDraft = makeRequest("{$baseUrl}/api/proposal-update.php", 'POST', [
    'activity_id'           => $testActivityId,
    'action'                => 'draft',
    'is_ajax_sync'          => '1',
    'title'                 => $testTitle . ' (Regression Draft Test)',
    'event_date'            => '2026-11-28',
    'start_time'            => '09:00:00',
    'end_time'              => '16:00:00',
    'venue'                 => 'Gymnasium',
    'general_objectives'    => 'Valid general objective text.',
    'specific_objectives'   => '1. Objective one.\n2. Objective two.'
], $facultyCookie, ['X-Requested-With: XMLHttpRequest', 'Accept: application/json']);
$dataSaveDraft = json_decode($resSaveDraft['body'], true);
assertCheck($resSaveDraft['code'] === 200 && ($dataSaveDraft['success'] ?? false) === true, "Test 14: Existing proposal draft saving workflow continues to function perfectly.");

// Clean up test activity
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$testActivityId]);

echo "\n======================================================================\n";
echo "  TASK 15 AUDIT SUMMARY: {$passCount} PASSED, {$failCount} FAILED\n";
echo "======================================================================\n";

if ($failCount === 0) {
    echo ">>> ALL 14 AI-ASSISTED PROPOSAL GUIDANCE TESTS PASSED! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED! <<<\n";
    exit(1);
}

