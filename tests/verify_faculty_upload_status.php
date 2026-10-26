<?php
/**
 * Verification Script for Faculty OneDrive Upload
 * Checks database record, local file, and cloud OneDrive presence.
 * NEVER prints secret tokens or credentials.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/onedrive_config.php';
require_once __DIR__ . '/../includes/OneDriveAuthService.php';

$db = getDB();

echo "========================================================================\n";
echo "  FACULTY ONEDRIVE UPLOAD VERIFICATION STATUS\n";
echo "========================================================================\n\n";

// 1. Account Check
$user = $db->query("SELECT id, name, email, role, department FROM users WHERE id = 387")->fetch(PDO::FETCH_ASSOC);
echo "[ACCOUNT] Faculty User Record:\n";
echo "  - ID:         {$user['id']}\n";
echo "  - Name:       {$user['name']}\n";
echo "  - Email:      {$user['email']}\n";
echo "  - Role:       {$user['role']}\n";
echo "  - Department: {$user['department']}\n\n";

// 2. Check for uploaded deliverable or document
$deliv = $db->query("
    SELECT * FROM task_deliverables 
    WHERE file_name LIKE '%STI_ARMES_Faculty_OneDrive_Test%' OR file_path LIKE '%STI_ARMES_Faculty_OneDrive_Test%'
    ORDER BY id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$doc = $db->query("
    SELECT * FROM documents 
    WHERE file_name LIKE '%STI_ARMES_Faculty_OneDrive_Test%' OR file_path LIKE '%STI_ARMES_Faculty_OneDrive_Test%'
    ORDER BY id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$record = $deliv ?: $doc;

if (!$record) {
    echo "[STATUS] Waiting for upload: No record found yet for 'STI_ARMES_Faculty_OneDrive_Test.pdf'.\n";
    echo "         Please complete the upload in the browser and re-run this verification.\n";
    exit(2);
}

$sourceTable = $deliv ? 'task_deliverables' : 'documents';
echo "[DATABASE RECORD] Found in table '{$sourceTable}' (ID: {$record['id']}):\n";
echo "  - File Name:       {$record['file_name']}\n";
echo "  - Local Path:      {$record['file_path']}\n";
echo "  - OneDrive Status: " . ($record['onedrive_status'] ?? 'pending') . "\n";
echo "  - OneDrive Item ID: " . ($record['onedrive_item_id'] ?? 'none') . "\n";
echo "  - OneDrive Web URL: " . ($record['onedrive_web_url'] ?? 'none') . "\n";
echo "  - OneDrive Synced: " . ($record['onedrive_synced_at'] ?? 'none') . "\n\n";

// 3. Verify Local File Exists
$fullLocal = __DIR__ . '/../' . ltrim($record['file_path'], '/\\');
if (file_exists($fullLocal)) {
    echo "[LOCAL FILE] Local file is available: PASS (Path: {$record['file_path']}, Size: " . filesize($fullLocal) . " bytes)\n";
} else {
    echo "[LOCAL FILE] Local file check: FAIL (Not found at {$fullLocal})\n";
}

// 4. Verify in Cloud if Item ID exists
if (!empty($record['onedrive_item_id'])) {
    $auth = new OneDriveAuthService();
    $tokenRes = $auth->getAccessToken();
    if ($tokenRes['success']) {
        $token = $tokenRes['access_token'];
        $verify = true;
        if (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
            $verify = 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem';
        }
        $guzzle = new \GuzzleHttp\Client(['timeout' => 30.0, 'verify' => $verify]);
        $graphUrl = "https://graph.microsoft.com/v1.0/me/drive/items/" . rawurlencode($record['onedrive_item_id']);
        $resp = $guzzle->request('GET', $graphUrl, [
            'http_errors' => false,
            'headers' => ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']
        ]);
        if ($resp->getStatusCode() === 200) {
            $itemData = json_decode((string)$resp->getBody(), true);
            echo "[CLOUD VERIFICATION] Microsoft Graph confirmed file exists in OneDrive: PASS\n";
            echo "  - Cloud Name: {$itemData['name']}\n";
            echo "  - Cloud Size: {$itemData['size']} bytes\n";
            echo "  - Cloud URL:  {$itemData['webUrl']}\n";
        } else {
            echo "[CLOUD VERIFICATION] Microsoft Graph returned HTTP " . $resp->getStatusCode() . "\n";
        }
    }
}
