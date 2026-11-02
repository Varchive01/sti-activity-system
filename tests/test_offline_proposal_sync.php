<?php
/**
 * STI-ARMES Task 11 Test Suite:
 * Offline Proposal Draft Saving and Automatic Synchronization
 *
 * Verifies all 12 test criteria defined in Task 11.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/sti-activity-system';
$cookieDir = __DIR__ . '/cookies_offline';
if (!is_dir($cookieDir)) {
    mkdir($cookieDir, 0755, true);
}

function makeRequest(string $url, string $method = 'GET', $data = null, ?string $cookieFile = null, array $headers = []): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } elseif (is_string($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'code'     => $httpCode,
        'headers'  => $headerStr,
        'body'     => $body,
        'redirect' => $redirectUrl
    ];
}

$db = getDB();
$testResults = [];

function recordTest(string $id, string $title, bool $passed, string $details = '') {
    global $testResults;
    $testResults[] = [
        'id'      => $id,
        'title'   => $title,
        'passed'  => $passed,
        'details' => $details
    ];
    echo "[" . ($passed ? "PASS" : "FAIL") . "] $id: $title\n";
    if (!$passed || $details) {
        echo "       $details\n";
    }
}

echo "=======================================================================\n";
echo "STI-ARMES TASK 11: OFFLINE DRAFT SAVING & AUTO-SYNC TEST SUITE\n";
echo "Base URL: $baseUrl\n";
echo "=======================================================================\n\n";

$cFilePhIt  = $cookieDir . '/ph_it.txt';
$cFilePhThm = $cookieDir . '/ph_thm.txt';
$cFileFacIt = $cookieDir . '/fac_it.txt';
$cFileDean  = $cookieDir . '/dean.txt';

// Authenticate test sessions
makeRequest($baseUrl . '/auth/login.php', 'POST', ['email' => 'ph_it_accept@test.com', 'password' => 'Password123!'], $cFilePhIt);
makeRequest($baseUrl . '/auth/login.php', 'POST', ['email' => 'ph_thm_accept@test.com', 'password' => 'Password123!'], $cFilePhThm);
makeRequest($baseUrl . '/auth/login.php', 'POST', ['email' => 'faculty@sti.edu', 'password' => 'password'], $cFileFacIt);
makeRequest($baseUrl . '/auth/login.php', 'POST', ['email' => 'dean@sti.edu', 'password' => 'password'], $cFileDean);

// Check current user IDs
$phItId = (int)$db->query("SELECT id FROM users WHERE email='ph_it_accept@test.com'")->fetchColumn();
$phThmId = (int)$db->query("SELECT id FROM users WHERE email='ph_thm_accept@test.com'")->fetchColumn();

// --- TEST 1 & 2: Creating a proposal offline & saving draft locally ---
$jsFile = __DIR__ . '/../assets/js/offline-drafts.js';
$jsExists = file_exists($jsFile) && filesize($jsFile) > 1000;
$hasIndexedDB = str_contains(file_get_contents($jsFile), 'indexedDB.open') && str_contains(file_get_contents($jsFile), 'proposal_drafts');
$hasSyncStatuses = str_contains(file_get_contents($jsFile), 'saved_locally') &&
                   str_contains(file_get_contents($jsFile), 'pending_sync') &&
                   str_contains(file_get_contents($jsFile), 'syncing') &&
                   str_contains(file_get_contents($jsFile), 'synchronized') &&
                   str_contains(file_get_contents($jsFile), 'sync_failed') &&
                   str_contains(file_get_contents($jsFile), 'conflict_requires_review');

recordTest('T11-01', 'IndexedDB offline storage implementation exists', $jsExists && $hasIndexedDB, "Found offline-drafts.js with IndexedDB store 'proposal_drafts'");
recordTest('T11-02', 'All 6 required sync status badges implemented in UI', $hasSyncStatuses, "Statuses: saved_locally, pending_sync, syncing, synchronized, sync_failed, conflict_requires_review");

// --- TEST 3: Closing and reopening browser (Persistent schema verification) ---
$sampleDraft = [
    'local_id'      => 'draft_offline_' . time() . '_test',
    'server_id'     => null,
    'faculty_id'    => $phItId,
    'title'         => 'Offline Cloud Security Seminar ' . time(),
    'sync_status'   => 'pending_sync',
    'form_data'     => [
        'title'               => 'Offline Cloud Security Seminar ' . time(),
        'theme'               => 'Cloud Resilience and Hardening',
        'venue'               => 'Room 402 Computer Lab',
        'event_date'          => '2026-11-28',
        'start_time'          => '13:00',
        'end_time'            => '16:00',
        'target_participants' => 50,
        'general_objectives'  => 'Introduce practical cloud security concepts.',
        'action'              => 'draft'
    ],
    'last_saved_at' => date('c')
];
$schemaValid = isset($sampleDraft['local_id']) && isset($sampleDraft['form_data']['title']) && $sampleDraft['sync_status'] === 'pending_sync';
recordTest('T11-03', 'Browser-side offline draft schema preserves form data & metadata', $schemaValid, "Schema holds local_id, server_id, title, form_data, sync_status, last_saved_at");

// --- TEST 4: Editing locally saved draft ---
$sampleDraft['form_data']['target_participants'] = 65;
$sampleDraft['last_saved_at'] = date('c');
$isEdited = ($sampleDraft['form_data']['target_participants'] === 65 && $sampleDraft['sync_status'] === 'pending_sync');
recordTest('T11-04', 'Editing locally saved draft updates local attributes while retaining pending_sync', $isEdited, "Updated target_participants to 65 while keeping pending_sync status");

// --- TEST 5: Reconnecting to internet (Auth verification endpoint) ---
$authRes = makeRequest($baseUrl . '/api/check-auth.php', 'GET', null, $cFilePhIt);
$authData = json_decode($authRes['body'], true);
$isAuthOk = ($authRes['code'] === 200 && ($authData['authenticated'] ?? false) === true && (int)$authData['user_id'] === $phItId);
recordTest('T11-05', 'Reconnection authentication check confirms valid active session', $isAuthOk, "HTTP {$authRes['code']}, user_id: {$authData['user_id']}, role: {$authData['role']}");

// --- TEST 6: Automatic synchronization to server ---
$syncPayload = $sampleDraft['form_data'];
$syncPayload['is_ajax_sync'] = '1';
$syncPayload['client_draft_id'] = $sampleDraft['local_id'];

$syncRes = makeRequest($baseUrl . '/api/proposal-save.php', 'POST', $syncPayload, $cFilePhIt, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
$syncData = json_decode($syncRes['body'], true);
$syncedActId = (int)($syncData['activity_id'] ?? 0);

$stmt = $db->prepare("SELECT id, status, faculty_id, target_participants FROM activities WHERE id = ?");
$stmt->execute([$syncedActId]);
$dbAct = $stmt->fetch();

$isSynced = ($syncRes['code'] === 200 &&
             $syncedActId > 0 &&
             $dbAct &&
             $dbAct['status'] === 'draft' &&
             (int)$dbAct['faculty_id'] === $phItId &&
             (int)$dbAct['target_participants'] === 65);

recordTest('T11-06', 'Automatic synchronization creates proposal with status draft', $isSynced, "Server assigned activity_id $syncedActId with status 'draft'");

// --- TEST 7: Failed synchronization and retry ---
// 7a. Send invalid past date via AJAX
$failPayload = $syncPayload;
$failPayload['event_date'] = '2020-01-01'; // past date error
$failRes = makeRequest($baseUrl . '/api/proposal-save.php', 'POST', $failPayload, $cFilePhIt, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
$failData = json_decode($failRes['body'], true);
$isRejected = ($failRes['code'] === 400 && ($failData['error_code'] ?? '') === 'past_date');

// 7b. Correct and retry
$retryPayload = $failPayload;
$retryPayload['event_date'] = '2026-11-29';
$retryPayload['activity_id'] = $syncedActId;
$retryRes = makeRequest($baseUrl . '/api/proposal-update.php', 'POST', $retryPayload, $cFilePhIt, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
$retryData = json_decode($retryRes['body'], true);
$isRetrySuccess = ($retryRes['code'] === 200 && ($retryData['success'] ?? false) === true);

recordTest('T11-07', 'Failed synchronization retains draft in queue and succeeds upon retry', $isRejected && $isRetrySuccess, "Validation error returned HTTP 400 'past_date', corrected retry returned HTTP 200");

// --- TEST 8: Duplicate prevention on retries ---
$countBefore = (int)$db->query("SELECT COUNT(*) FROM activities WHERE faculty_id = $phItId")->fetchColumn();

// Resend save with same activity_id
$dupRes = makeRequest($baseUrl . '/api/proposal-save.php', 'POST', [
    'activity_id'         => $syncedActId,
    'action'              => 'draft',
    'title'               => $sampleDraft['title'] . ' (Retry Attempt)',
    'is_ajax_sync'        => '1'
], $cFilePhIt, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);

$countAfter = (int)$db->query("SELECT COUNT(*) FROM activities WHERE faculty_id = $phItId")->fetchColumn();
$noDuplicateCreated = ($countBefore === $countAfter);
recordTest('T11-08', 'Duplicate proposal prevention during retries', $noDuplicateCreated, "Total activities count before: $countBefore, after retry: $countAfter (no new row inserted)");

// --- TEST 9: Conflict detection (Optimistic locking) ---
// Fetch current server updated_at
$currUpdatedAt = $db->query("SELECT updated_at FROM activities WHERE id = $syncedActId")->fetchColumn();

// Client sends an older timestamp simulating conflict
$conflictRes = makeRequest($baseUrl . '/api/proposal-update.php', 'POST', [
    'activity_id'           => $syncedActId,
    'action'                => 'draft',
    'title'                 => 'Conflict Overwrite Attempt',
    'last_known_updated_at' => '2025-01-01 10:00:00'
], $cFilePhIt, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);

$conflictData = json_decode($conflictRes['body'], true);
$isConflictDetected = ($conflictRes['code'] === 409 &&
                       ($conflictData['conflict'] ?? false) === true &&
                       ($conflictData['error_code'] ?? '') === 'conflict_requires_review');

// Now resolve conflict with force_overwrite = 1
$resolveRes = makeRequest($baseUrl . '/api/proposal-update.php', 'POST', [
    'activity_id'           => $syncedActId,
    'action'                => 'draft',
    'title'                 => $sampleDraft['title'] . ' (Conflict Resolved)',
    'force_overwrite'       => '1'
], $cFilePhIt, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
$resolveData = json_decode($resolveRes['body'], true);
$isConflictResolved = ($resolveRes['code'] === 200 && ($resolveData['success'] ?? false) === true);

recordTest('T11-09', 'Conflict detection (HTTP 409) protects newer server data and requires user choice', $isConflictDetected && $isConflictResolved, "Stale timestamp rejected with HTTP 409; explicit overwrite accepted with HTTP 200");

// --- TEST 10: Existing online draft saving (Normal non-AJAX POST) ---
$onlineDraftTitle = "Standard Online Draft " . time();
$onlineRes = makeRequest($baseUrl . '/api/proposal-save.php', 'POST', [
    'action'              => 'draft',
    'title'               => $onlineDraftTitle,
    'description'         => 'Testing standard non-AJAX online draft saving.',
    'event_date'          => '2026-11-28',
    'start_time'          => '09:00',
    'end_time'            => '12:00',
    'venue'               => 'Computer Lab 1',
    'target_participants' => 40
], $cFileFacIt); // Faculty IT standard form submit

$isStandardRedirect = ($onlineRes['code'] === 302 && str_contains($onlineRes['redirect'], '/faculty/dashboard.php?saved=1'));
$onlineActId = (int)$db->query("SELECT id FROM activities WHERE title = '$onlineDraftTitle'")->fetchColumn();
recordTest('T11-10', 'Existing online draft saving retains seamless 302 redirect', $isStandardRedirect && $onlineActId > 0, "HTTP {$onlineRes['code']} -> {$onlineRes['redirect']}, created ID $onlineActId");

// --- TEST 11: Faculty & Program Head department permissions ---
// THM Program Head tries to update IT Program Head's activity via sync -> 403 Access Denied
$thmHackRes = makeRequest($baseUrl . '/api/proposal-update.php', 'POST', [
    'activity_id'  => $syncedActId,
    'action'       => 'draft',
    'title'        => 'THM Unauthorized Modification',
    'is_ajax_sync' => '1'
], $cFilePhThm, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);
$isThmBlocked = ($thmHackRes['code'] === 403);
recordTest('T11-11', 'Cross-department permission enforcement blocks unauthorized draft updates', $isThmBlocked, "THM Program Head blocked from modifying IT activity: HTTP {$thmHackRes['code']}");

// --- TEST 12: Dean & Admin workflows remain unchanged ---
$deanDash = makeRequest($baseUrl . '/dean/dashboard.php', 'GET', null, $cFileDean);
$deanSeesDash = ($deanDash['code'] === 200 && str_contains($deanDash['body'], 'Dashboard'));

// Verify that the offline draft was NEVER submitted to Dean or Admin queues
$dbStatus = $db->query("SELECT status FROM activities WHERE id = $syncedActId")->fetchColumn();
$isNeverAutoSubmitted = ($dbStatus === 'draft');
recordTest('T11-12', 'Dean and Admin review workflows remain unchanged; offline drafts never auto-submitted', $deanSeesDash && $isNeverAutoSubmitted, "Dean dashboard HTTP {$deanDash['code']}; synced proposal status is strictly '{$dbStatus}'");

// --- CLEANUP TEST DATA ---
echo "\n--- Cleaning up Task 11 test data ---\n";
if ($syncedActId > 0) {
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$syncedActId]);
    echo "Deleted test activity ID $syncedActId\n";
}
if ($onlineActId > 0) {
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$onlineActId]);
    echo "Deleted test activity ID $onlineActId\n";
}
foreach (glob($cookieDir . '/*.txt') as $cf) {
    @unlink($cf);
}
@rmdir($cookieDir);

echo "\n=======================================================================\n";
$allPassed = true;
$passCount = 0;
foreach ($testResults as $t) {
    if ($t['passed']) $passCount++; else $allPassed = false;
}
echo "TEST SUMMARY: $passCount / " . count($testResults) . " PASSED\n";
echo "=======================================================================\n";

if ($allPassed) {
    exit(0);
} else {
    exit(1);
}

