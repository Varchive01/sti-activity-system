<?php
/**
 * tests/verify_real_onedrive_e2e.php
 *
 * Real Live End-to-End Verification of the OneDrive Non-Blocking Archive Integration.
 * Uses the live credentials configured in .env against real Microsoft Graph API.
 *
 * Steps verified:
 * 1. File written to existing local protected storage.
 * 2. Archive copy reaches Microsoft Graph with live bearer token.
 * 3. File exists in the designated OneDrive account.
 * 4. File is located inside STI_Activity_System_Documents/posters/.
 * 5. Item ID, name, size, webUrl, and metadata are returned.
 * 6. Local document retrieval works with local storage.
 * 7. Zero leakage of tokens, client secrets, or refresh tokens.
 */

define('TEST_RUNNER', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/onedrive_config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/OneDriveAuthService.php';
require_once __DIR__ . '/../includes/OneDriveService.php';

use GuzzleHttp\Client;

echo "========================================================================\n";
echo "  REAL END-TO-END ONEDRIVE ARCHIVE INTEGRATION VERIFICATION\n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $title, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$title}\n";
        $passed++;
    } else {
        echo " [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

$config = getOneDriveConfig();
assertTest("0.1 ONEDRIVE_ENABLED is true in .env", $config['enabled'] === true);
assertTest("0.2 Root folder is STI_Activity_System_Documents", $config['root_folder'] === 'STI_Activity_System_Documents');
assertTest("0.3 ONEDRIVE_REFRESH_TOKEN is present", !empty($config['refresh_token']));

// Create ONE small harmless sample file (1x1 PNG image, 67 bytes)
$samplePngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAA=');
$tempSample = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'e2e_real_sample_' . time() . '.png';
file_put_contents($tempSample, $samplePngData);

$simulatedFile = [
    'name'     => 'e2e_real_poster_' . time() . '.png',
    'type'     => 'image/png',
    'tmp_name' => $tempSample,
    'error'    => UPLOAD_ERR_OK,
    'size'     => strlen($samplePngData),
];

$testActivityId = 99998;

echo "\n--- 1. Executing Live uploadFile() Flow ---\n";

// Execute live uploadFile() without mock service — uses live .env credentials!
$localRelPath = uploadFile($simulatedFile, 'posters', $testActivityId);

assertTest(
    "1.1 uploadFile() returns relative local path",
    is_string($localRelPath) && str_starts_with($localRelPath, 'posters/'),
    "Returned: " . var_export($localRelPath, true)
);

$fullLocalPath = UPLOAD_PATH . $localRelPath;
assertTest(
    "1.2 File is successfully written to authoritative local storage",
    file_exists($fullLocalPath) && filesize($fullLocalPath) === strlen($samplePngData),
    "Local file missing at: {$fullLocalPath}"
);

// Retrieve cached sync metadata from attemptOneDriveArchiveCopy
$generatedFileName = basename($localRelPath);
$syncMetadata = attemptOneDriveArchiveCopy($fullLocalPath, $generatedFileName, 'posters', $testActivityId);

echo "\n--- 2. OneDrive Microsoft Graph Archive Verification ---\n";

assertTest(
    "2.1 OneDrive archive copy succeeded (sync status is 'synced')",
    is_array($syncMetadata) && ($syncMetadata['status'] ?? '') === 'synced',
    "Sync metadata: " . json_encode($syncMetadata)
);

assertTest(
    "2.2 Returned item_id is valid non-empty string",
    !empty($syncMetadata['item_id']),
    "item_id: " . ($syncMetadata['item_id'] ?? 'null')
);

assertTest(
    "2.3 Returned folder_path is 'posters'",
    ($syncMetadata['folder_path'] ?? '') === 'posters',
    "folder_path: " . ($syncMetadata['folder_path'] ?? 'null')
);

assertTest(
    "2.4 Returned web_url points to OneDrive",
    !empty($syncMetadata['web_url']) && str_contains($syncMetadata['web_url'], 'onedrive.live.com'),
    "web_url: " . ($syncMetadata['web_url'] ?? 'null')
);

// Verify directly with Microsoft Graph API using a fresh GET request
echo "\n--- 3. Verifying Live Item Directly via Microsoft Graph REST API ---\n";

$authService = new OneDriveAuthService($config);
$tokenRes = $authService->getAccessToken();
assertTest("3.1 Live Graph access token acquired", $tokenRes['success'] === true);

$liveToken = $tokenRes['access_token'] ?? '';
$itemId = $syncMetadata['item_id'] ?? '';

$verifyCa = true;
if (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
    $verifyCa = 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem';
} elseif (file_exists('C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem')) {
    $verifyCa = 'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem';
}

$httpClient = new Client(['timeout' => 20.0, 'http_errors' => false, 'verify' => $verifyCa]);
$verifyGraphRes = $httpClient->request('GET', "https://graph.microsoft.com/v1.0/me/drive/items/{$itemId}", [
    'headers' => [
        'Authorization' => "Bearer {$liveToken}",
        'Accept'        => 'application/json',
    ],
]);

$graphStatus = $verifyGraphRes->getStatusCode();
$graphItem = json_decode((string)$verifyGraphRes->getBody(), true);

assertTest(
    "3.2 Microsoft Graph confirms item exists (HTTP 200 OK)",
    $graphStatus === 200 && is_array($graphItem) && ($graphItem['id'] ?? '') === $itemId,
    "HTTP {$graphStatus}: " . substr((string)$verifyGraphRes->getBody(), 0, 200)
);

assertTest(
    "3.3 Item name matches generated filename in OneDrive",
    ($graphItem['name'] ?? '') === $generatedFileName,
    "Name in Graph: " . ($graphItem['name'] ?? 'null') . " vs {$generatedFileName}"
);

$parentPath = $graphItem['parentReference']['path'] ?? '';
assertTest(
    "3.4 File resides inside STI_Activity_System_Documents/posters",
    str_contains($parentPath, 'STI_Activity_System_Documents') && str_contains($parentPath, 'posters'),
    "Parent path: {$parentPath}"
);

assertTest(
    "3.5 File size in Graph matches uploaded binary (67 bytes)",
    ($graphItem['size'] ?? 0) === strlen($samplePngData),
    "Size in Graph: " . ($graphItem['size'] ?? 0)
);

// -------------------------------------------------------------------------
// SECTION 4: Local Document Retrieval
// -------------------------------------------------------------------------
echo "\n--- 4. Local Document Retrieval Verification ---\n";

// Confirm local file can be read and matches original binary exactly
$localContent = file_get_contents($fullLocalPath);
assertTest(
    "4.1 Local document content is intact and matches byte-for-byte",
    $localContent === $samplePngData
);

// -------------------------------------------------------------------------
// SECTION 5: Zero Credential Leakage
// -------------------------------------------------------------------------
echo "\n--- 5. Zero Leakage Verification ---\n";

$metaJson = json_encode($syncMetadata);
assertTest(
    "5.1 Sync metadata does NOT contain access tokens",
    !str_contains($metaJson, $liveToken)
);
assertTest(
    "5.2 Sync metadata does NOT contain refresh tokens",
    !str_contains($metaJson, $config['refresh_token'])
);
assertTest(
    "5.3 Sync metadata does NOT contain client secrets",
    !str_contains($metaJson, $config['client_secret'])
);

// -------------------------------------------------------------------------
// SECTION 6: Safe Cleanup of Test Artifacts
// -------------------------------------------------------------------------
echo "\n--- 6. Cleanup of Test Artifacts ---\n";

// Delete the test item from OneDrive via Microsoft Graph DELETE
$deleteGraphRes = $httpClient->request('DELETE', "https://graph.microsoft.com/v1.0/me/drive/items/{$itemId}", [
    'headers' => [
        'Authorization' => "Bearer {$liveToken}",
    ],
]);
$deleteStatus = $deleteGraphRes->getStatusCode();
assertTest(
    "6.1 Test item cleanly removed from OneDrive after verification (HTTP 204)",
    $deleteStatus === 204 || $deleteStatus === 200,
    "Delete returned HTTP {$deleteStatus}"
);

// Clean up local test file
if (file_exists($fullLocalPath)) {
    @unlink($fullLocalPath);
}
if (file_exists($tempSample)) {
    @unlink($tempSample);
}

assertTest("6.2 Local temporary files cleanly removed", !file_exists($fullLocalPath) && !file_exists($tempSample));

echo "\n========================================================================\n";
echo " VERIFICATION SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

exit($failed > 0 ? 1 : 0);
