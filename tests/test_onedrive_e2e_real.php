<?php
/**
 * tests/test_onedrive_e2e_real.php
 *
 * Real OneDrive End-to-End Verification using existing STI-ARMES integration.
 * Performs a real Faculty proposal creation with a test poster file,
 * verifies archive in Microsoft OneDrive, downloads and checks file integrity,
 * verifies the local STI-ARMES record, and checks the connection status.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/onedrive_config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/OneDriveAuthService.php';
require_once __DIR__ . '/../includes/OneDriveService.php';

use GuzzleHttp\Client;

echo "========================================================================\n";
echo "   STI-ARMES REAL ONEDRIVE END-TO-END VERIFICATION                      \n";
echo "========================================================================\n\n";

$results = [
    'auth_success'       => false,
    'upload_success'     => false,
    'onedrive_exists'    => false,
    'integrity_success'  => false,
    'record_success'     => false,
    'status_page_success'=> false,
];

$details = [];

// -------------------------------------------------------------------------
// STEP 1: Verify Status / Connection Check
// -------------------------------------------------------------------------
echo "--- 1. Checking Integration Status via CLI / Config ---\n";
$checkOutput = shell_exec('php ' . escapeshellarg(__DIR__ . '/../scripts/generate_onedrive_token.php') . ' --check');
$statusOk = str_contains((string)$checkOutput, 'STATUS: All required credentials are present');
if ($statusOk) {
    echo "  [PASS] Status check reports all required credentials are present and valid.\n";
    $results['status_page_success'] = true;
} else {
    echo "  [FAIL] Status check failed.\n";
}

// -------------------------------------------------------------------------
// STEP 2: Live OAuth / Authentication Check
// -------------------------------------------------------------------------
echo "\n--- 2. Live Microsoft OAuth / Graph Authentication Check ---\n";
$authService = new OneDriveAuthService();
$tokenRes = $authService->getAccessToken();

if ($tokenRes['success']) {
    echo "  [PASS] OAuth token acquisition succeeded.\n";
    echo "  Access token acquired (length: " . strlen($tokenRes['access_token']) . " bytes, expires in: " . ($tokenRes['expires_in'] ?? 0) . "s)\n";
    $results['auth_success'] = true;
    $liveToken = $tokenRes['access_token'];
} else {
    echo "  [FAIL] OAuth token acquisition failed: " . ($tokenRes['error'] ?? 'Unknown error') . "\n";
    exit(1);
}

// -------------------------------------------------------------------------
// STEP 3: Login as Faculty and Upload via Normal Workflow
// -------------------------------------------------------------------------
echo "\n--- 3. Uploading Test Document via Normal Proposal Workflow ---\n";

$sourceFile = __DIR__ . '/../uploads/floorplans/fp_100_1786612067.png';
if (!file_exists($sourceFile)) {
    echo "  [FAIL] Sample source file not found at {$sourceFile}\n";
    exit(1);
}

$sampleData = file_get_contents($sourceFile);
$localSha256 = hash('sha256', $sampleData);
$localSize = strlen($sampleData);
echo "  Original test document size: {$localSize} bytes, SHA256: " . substr($localSha256, 0, 16) . "...\n";

// Authenticate Faculty session via login endpoint
$loginUrl = BASE_URL . '/auth/login.php';
$postData = http_build_query([
    'email'    => 'faculty@sti.edu',
    'password' => 'password',
]);

$cookieFile = tempnam(sys_get_temp_dir(), 'sti_cookie_');

$ch = curl_init($loginUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
]);
$loginResp = curl_exec($ch);
$loginHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

preg_match_all('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $loginResp, $matches);
$sessionId = !empty($matches[1]) ? end($matches[1]) : '';
echo "  Faculty login HTTP response code: {$loginHttpCode} (Regenerated Session: " . substr($sessionId, 0, 8) . "...)\n";

if (empty($sessionId)) {
    echo "  [FAIL] Failed to extract PHPSESSID from login response.\n";
    exit(1);
}

// Now submit a draft proposal with the test poster file
$testTitle = "OneDrive E2E Verification Activity " . date('Y-m-d H:i:s');
$eventDate = date('Y-m-d', strtotime('+7 days'));

$saveUrl = BASE_URL . '/api/proposal-save.php';
$cFile = new CURLFile($sourceFile, 'image/png', 'test_onedrive_poster.png');

$proposalFields = [
    'action'              => 'draft',
    'title'               => $testTitle,
    'theme'               => 'Academic Excellence',
    'venue'               => 'Computer Laboratory 1',
    'venue_address'       => 'STI Academic Center',
    'event_date'          => $eventDate,
    'start_time'          => '09:00',
    'end_time'            => '11:00',
    'target_participants' => '50',
    'general_objectives'  => 'Test OneDrive archive sync integration end-to-end.',
    'specific_objectives' => 'Verify cloud file storage and file integrity.',
    'involved_subjects'   => 'Information Technology',
    'rationale'           => 'Verification of cloud integration.',
    'poster_file'         => $cFile,
];

$ch2 = curl_init($saveUrl);
curl_setopt_array($ch2, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $proposalFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_HTTPHEADER     => ["Cookie: PHPSESSID={$sessionId}"],
    CURLOPT_FOLLOWLOCATION => false, // Capture redirect to get created ID
]);
$saveResp = curl_exec($ch2);
$saveHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
$redirectUrl = curl_getinfo($ch2, CURLINFO_REDIRECT_URL);

echo "  Proposal submission HTTP code: {$saveHttpCode}\n";
// Retrieve created activity ID from database by unique title
$db = getDB();
$stmt = $db->prepare("SELECT id FROM activities WHERE title = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$testTitle]);
$actId = (int)$stmt->fetchColumn();

if (!$actId) {
    echo "  [FAIL] Could not find created activity in database by title: {$testTitle}\n";
    echo "  Redirect was: {$redirectUrl}\n";
    echo "  Response headers:\n" . substr($saveResp, 0, 500) . "\n";
    exit(1);
}

echo "  Created test activity ID: {$actId}\n";

// -------------------------------------------------------------------------
// STEP 4: Verify Local Database & Local File Storage
// -------------------------------------------------------------------------
echo "\n--- 4. Checking STI-ARMES Local System Record ---\n";
$db = getDB();
$stmt = $db->prepare("SELECT id, title, status, poster_path FROM activities WHERE id = ?");
$stmt->execute([$actId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    echo "  [FAIL] Activity record not found in database.\n";
    exit(1);
}

echo "  Activity Title: " . $activity['title'] . "\n";
echo "  Activity Status: " . $activity['status'] . "\n";
echo "  Activity Poster Path: " . ($activity['poster_path'] ?? 'null') . "\n";

$localPosterRel = $activity['poster_path'] ?? '';
$localPosterFull = UPLOAD_PATH . $localPosterRel;

$localFileExists = !empty($localPosterRel) && file_exists($localPosterFull);
if ($localFileExists) {
    $savedLocalSize = filesize($localPosterFull);
    $savedLocalSha256 = hash_file('sha256', $localPosterFull);
    echo "  [PASS] Local file exists at: {$localPosterFull}\n";
    echo "  Local file size: {$savedLocalSize} bytes | SHA256: " . substr($savedLocalSha256, 0, 16) . "...\n";

    if ($savedLocalSize === $localSize && $savedLocalSha256 === $localSha256) {
        echo "  [PASS] Local file matches original binary exactly.\n";
        $results['upload_success'] = true;
        $results['record_success'] = true;
    } else {
        echo "  [FAIL] Local file size/checksum mismatch.\n";
    }
} else {
    echo "  [FAIL] Local file does not exist at {$localPosterFull}\n";
}

$generatedFileName = basename($localPosterRel);
echo "  Target generated filename in OneDrive: {$generatedFileName}\n";

// -------------------------------------------------------------------------
// STEP 5: Verify Live File in Microsoft OneDrive
// -------------------------------------------------------------------------
echo "\n--- 5. Verifying Live File in Microsoft OneDrive via Microsoft Graph ---\n";

$verifyCa = true;
if (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
    $verifyCa = 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem';
} elseif (file_exists('C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem')) {
    $verifyCa = 'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem';
}

$httpClient = new Client(['timeout' => 25.0, 'http_errors' => false, 'verify' => $verifyCa]);

// Query item by path: /me/drive/root:/STI_Activity_System_Documents/posters/{generatedFileName}
$itemPathUrl = "https://graph.microsoft.com/v1.0/me/drive/root:/STI_Activity_System_Documents/posters/" . rawurlencode($generatedFileName);

$graphRes = $httpClient->request('GET', $itemPathUrl, [
    'headers' => [
        'Authorization' => "Bearer {$liveToken}",
        'Accept'        => 'application/json',
    ],
]);

$graphHttp = $graphRes->getStatusCode();
$graphItem = json_decode((string)$graphRes->getBody(), true);

echo "  Microsoft Graph item lookup HTTP code: {$graphHttp}\n";

if ($graphHttp === 200 && !empty($graphItem['id'])) {
    echo "  [PASS] File exists in configured Microsoft OneDrive account.\n";
    echo "  OneDrive Item ID: " . $graphItem['id'] . "\n";
    echo "  OneDrive Item Name: " . $graphItem['name'] . "\n";
    echo "  OneDrive Item Size: " . $graphItem['size'] . " bytes\n";
    echo "  OneDrive Web URL: " . ($graphItem['webUrl'] ?? 'N/A') . "\n";
    echo "  OneDrive Parent Path: " . ($graphItem['parentReference']['path'] ?? 'N/A') . "\n";

    $results['onedrive_exists'] = true;
    $itemId = $graphItem['id'];
    $downloadUrl = $graphItem['@microsoft.graph.downloadUrl'] ?? '';
    $webUrl = $graphItem['webUrl'] ?? '';
    $details['item_id'] = $itemId;
    $details['web_url'] = $webUrl;
    $details['download_url'] = $downloadUrl;
    $details['name'] = $graphItem['name'];
} else {
    echo "  [FAIL] File not found in Microsoft OneDrive. Response was:\n" . substr((string)$graphRes->getBody(), 0, 400) . "\n";
    exit(1);
}

// -------------------------------------------------------------------------
// STEP 6: Verify File-Open & Content Integrity from OneDrive
// -------------------------------------------------------------------------
echo "\n--- 6. Verifying File Open & Binary Content Integrity from OneDrive ---\n";

// Download the actual file content directly from Microsoft OneDrive
$contentUrl = "https://graph.microsoft.com/v1.0/me/drive/items/{$itemId}/content";
$contentRes = $httpClient->request('GET', $contentUrl, [
    'headers' => [
        'Authorization' => "Bearer {$liveToken}",
    ],
    'allow_redirects' => true,
]);

$contentHttp = $contentRes->getStatusCode();
$remoteBinary = (string)$contentRes->getBody();
$remoteSize = strlen($remoteBinary);
$remoteSha256 = hash('sha256', $remoteBinary);

echo "  OneDrive direct download HTTP status: {$contentHttp}\n";
echo "  Downloaded binary size: {$remoteSize} bytes\n";
echo "  Downloaded SHA256: " . substr($remoteSha256, 0, 16) . "...\n";

if ($remoteSize === $localSize && $remoteSha256 === $localSha256) {
    echo "  [PASS] File content from OneDrive is INTACT and matches byte-for-byte with local file!\n";
    $results['integrity_success'] = true;
} else {
    echo "  [FAIL] Downloaded content mismatch! Expected {$localSize} bytes, got {$remoteSize}.\n";
}

// Confirm PNG magic bytes
$pngHeader = substr($remoteBinary, 0, 8);
if ($pngHeader === "\x89PNG\r\n\x1a\n") {
    echo "  [PASS] Valid PNG image format verified from downloaded OneDrive stream.\n";
} else {
    echo "  [FAIL] Invalid PNG header from downloaded stream.\n";
}

// -------------------------------------------------------------------------
// STEP 7: Save summary data for Chrome Browser Verification
// -------------------------------------------------------------------------
$summary = [
    'actId'             => $actId,
    'title'             => $testTitle,
    'localPosterPath'   => $localPosterRel,
    'generatedFileName' => $generatedFileName,
    'itemId'            => $itemId,
    'webUrl'            => $webUrl,
    'downloadUrl'       => $downloadUrl,
    'results'           => $results,
];

file_put_contents(__DIR__ . '/onedrive_e2e_result.json', json_encode($summary, JSON_PRETTY_PRINT));
echo "\n  Saved test metadata to tests/onedrive_e2e_result.json\n";

echo "\n========================================================================\n";
echo "   PRELIMINARY E2E STATUS                                               \n";
echo "========================================================================\n";
foreach ($results as $k => $v) {
    echo "  " . str_pad($k, 22) . ": " . ($v ? "PASS [OK]" : "FAIL") . "\n";
}
echo "========================================================================\n";
