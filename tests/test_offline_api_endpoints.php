<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/sti-activity-system';
$cookieFile = __DIR__ . '/offline_test_cookie.txt';
@unlink($cookieFile);

function httpCall($url, $method = 'GET', $data = null, $cookie = null, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
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
    $resp = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerStr = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);
    return ['code' => $httpCode, 'headers' => $headerStr, 'body' => $body];
}

echo "=== Testing Offline API Endpoints ===\n";

// 1. Check check-auth without login -> 401
$res = httpCall($baseUrl . '/api/check-auth.php');
echo "1. Unauthenticated check-auth: HTTP {$res['code']} (expected 401)\n";
assert($res['code'] === 401);

// 2. Login as Program Head IT
$res = httpCall($baseUrl . '/auth/login.php', 'POST', [
    'email' => 'ph_it_accept@test.com',
    'password' => 'Password123!'
], $cookieFile);
echo "2. Login: HTTP {$res['code']}\n";

// 3. Check check-auth with login -> 200
$res = httpCall($baseUrl . '/api/check-auth.php', 'GET', null, $cookieFile);
echo "3. Authenticated check-auth: HTTP {$res['code']}, body: {$res['body']}\n";
$authData = json_decode($res['body'], true);
assert($authData['authenticated'] === true);
assert($authData['role'] === 'program_head');

// 4. Save Draft via AJAX -> 200 JSON
$draftTitle = "Offline Test Draft " . time();
$res = httpCall($baseUrl . '/api/proposal-save.php', 'POST', [
    'action'              => 'draft',
    'title'               => $draftTitle,
    'description'         => 'Test draft created via AJAX JSON.',
    'event_date'          => '2026-11-28',
    'start_time'          => '10:00',
    'end_time'            => '15:00',
    'venue'               => 'Test Lab',
    'target_participants' => 30
], $cookieFile, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);

echo "4. AJAX Draft Save: HTTP {$res['code']}, body: {$res['body']}\n";
$saveData = json_decode($res['body'], true);
assert($res['code'] === 200);
assert($saveData['success'] === true);
$actId = (int)$saveData['activity_id'];
assert($actId > 0);

// 5. Update Draft via AJAX -> 200 JSON
$updatedTitle = $draftTitle . " (Updated)";
$res = httpCall($baseUrl . '/api/proposal-update.php', 'POST', [
    'activity_id'         => $actId,
    'action'              => 'draft',
    'title'               => $updatedTitle,
    'target_participants' => 45,
    'last_known_updated_at' => $saveData['updated_at']
], $cookieFile, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);

echo "5. AJAX Draft Update: HTTP {$res['code']}, body: {$res['body']}\n";
$upData = json_decode($res['body'], true);
assert($res['code'] === 200);
assert($upData['success'] === true);

// 6. Test Conflict Detection (passing old last_known_updated_at) -> 409 JSON
$res = httpCall($baseUrl . '/api/proposal-update.php', 'POST', [
    'activity_id'           => $actId,
    'action'                => 'draft',
    'title'                 => 'Conflict Attempt',
    'last_known_updated_at' => '2026-01-01 00:00:00' // obviously older than server updated_at
], $cookieFile, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);

echo "6. Conflict Detection (409): HTTP {$res['code']}, body: {$res['body']}\n";
$confData = json_decode($res['body'], true);
assert($res['code'] === 409);
assert($confData['conflict'] === true);
assert($confData['error_code'] === 'conflict_requires_review');

// 7. Test Duplicate Prevention on proposal-save.php with existing activity_id
$res = httpCall($baseUrl . '/api/proposal-save.php', 'POST', [
    'activity_id'         => $actId,
    'action'              => 'draft',
    'title'               => $updatedTitle . " (Deduplicated)",
    'last_known_updated_at' => $upData['updated_at'],
    'force_overwrite'     => 1
], $cookieFile, ['Accept: application/json', 'X-Requested-With: XMLHttpRequest']);

echo "7. Duplicate Prevention on Save: HTTP {$res['code']}, body: {$res['body']}\n";
$dedupData = json_decode($res['body'], true);
assert($res['code'] === 200);
assert((int)$dedupData['activity_id'] === $actId);

// Cleanup
$db = getDB();
$db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId]);
@unlink($cookieFile);
echo "=== All Offline API Tests Passed Successfully! ===\n";

