<?php
/**
 * scripts/generate_onedrive_token.php
 *
 * Local CLI Utility to Authorize and Generate a Microsoft Graph OneDrive Refresh Token.
 *
 * Usage:
 *   php scripts/generate_onedrive_token.php           (Interactive setup guide)
 *   php scripts/generate_onedrive_token.php --check   (Validate current .env OneDrive config)
 *   php scripts/generate_onedrive_token.php --help    (Show options)
 *
 * SECURITY NOTICE:
 * This script runs locally on your machine. Never share your client secret or
 * generated refresh token in public chat windows or commit them to source control.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This utility can only be run via the command line.\n";
    exit(1);
}

require_once __DIR__ . '/../config/onedrive_config.php';
require_once __DIR__ . '/../includes/OneDriveAuthService.php';

$options = getopt('', ['check', 'help', 'code:', 'redirect-uri:', 'save']);

if (isset($options['help'])) {
    echo "========================================================================\n";
    echo "  Microsoft OneDrive (Graph) Refresh Token Generator Utility\n";
    echo "========================================================================\n\n";
    echo "Usage:\n";
    echo "  php scripts/generate_onedrive_token.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --check              Validate current OneDrive configuration in .env\n";
    echo "  --code=<code>        Authorization code from Microsoft consent redirect\n";
    echo "  --redirect-uri=<uri> Registered redirect URI (defaults to config or localhost)\n";
    echo "  --save               Automatically update ONEDRIVE_REFRESH_TOKEN in .env\n";
    echo "  --help               Show this help message\n\n";
    exit(0);
}

$config = getOneDriveConfig();
$authService = new OneDriveAuthService($config);

if (isset($options['check'])) {
    echo "========================================================================\n";
    echo "  OneDrive Configuration Status Check\n";
    echo "========================================================================\n";
    echo " Client ID:      " . (!empty($config['client_id']) ? substr($config['client_id'], 0, 8) . '...' : '[MISSING]') . "\n";
    echo " Client Secret:  " . (!empty($config['client_secret']) ? '********' : '[MISSING]') . "\n";
    echo " Tenant ID:      " . (!empty($config['tenant_id']) ? $config['tenant_id'] : '[MISSING]') . "\n";
    echo " Refresh Token:  " . (!empty($config['refresh_token']) ? '[CONFIGURED] (' . substr($config['refresh_token'], 0, 10) . '...)' : '[NOT CONFIGURED]') . "\n";
    echo " Root Folder:    " . $config['root_folder'] . "\n";
    echo " Sync Enabled:   " . ($config['enabled'] ? 'true' : 'false') . "\n";
    echo " Scopes:         " . $config['scopes'] . "\n\n";

    $val = $authService->validateConfig();
    if ($val['valid']) {
        echo " STATUS: All required credentials are present.\n";
        exit(0);
    } else {
        echo " STATUS: INCOMPLETE — " . $val['error'] . "\n";
        exit(1);
    }
}

echo "========================================================================\n";
echo "  STI ACTIVITY SYSTEM — ONEDRIVE / MICROSOFT GRAPH SETUP\n";
echo "========================================================================\n\n";

if (empty($config['client_id']) || empty($config['client_secret']) || empty($config['tenant_id'])) {
    echo "[ERROR] Missing Azure App Registration credentials in .env!\n";
    echo "Ensure OAUTH_CLIENT_ID, OAUTH_CLIENT_SECRET, and OAUTH_TENANT_ID are defined.\n\n";
    exit(1);
}

// Redirect URI selection — Dedicated OneDrive OAuth Callback
$defaultRedirect = defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/api/onedrive-oauth-callback.php' : 'http://localhost/sti-activity-system/api/onedrive-oauth-callback.php';
$redirectUri = $options['redirect-uri'] ?? $defaultRedirect;

// Generate cryptographically secure state for this transaction
$state = OneDriveAuthService::generateState();
OneDriveAuthService::savePendingTransaction($state);

$authUrl = $authService->getAuthorizationUrl($redirectUri, $state);

echo "STEP 1: Register Microsoft Graph Permissions in Azure Portal\n";
echo "------------------------------------------------------------------------\n";
echo "1. Go to Azure Portal -> Microsoft Entra ID -> App Registrations.\n";
echo "2. Open your App Registration (Client ID: {$config['client_id']}).\n";
echo "3. Under 'API Permissions' -> 'Add a permission' -> 'Microsoft Graph' -> 'Delegated permissions':\n";
echo "   - Check 'Files.ReadWrite' (Have full access to your files)\n";
echo "   - Check 'offline_access' (Maintain access to data you have given it access to)\n";
echo "4. Click 'Add permissions' and (if required by your tenant) click 'Grant admin consent'.\n";
echo "5. Ensure the Redirect URI matches:\n";
echo "   {$redirectUri}\n\n";

echo "STEP 2: Open Authorization URL in Browser\n";
echo "------------------------------------------------------------------------\n";
echo "Copy and open this URL in your web browser:\n\n";
echo "{$authUrl}\n\n";

echo "STEP 3: Grant Consent and Capture the Code\n";
echo "------------------------------------------------------------------------\n";
echo "1. Sign in with the Microsoft 365 / OneDrive account.\n";
echo "2. Accept the requested permissions (Files.ReadWrite, offline_access).\n";
echo "3. When redirected to the dedicated callback, the authorization code\n";
echo "   is automatically and securely transferred to this terminal.\n\n";

$inputCode = $options['code'] ?? null;
if (empty($inputCode)) {
    // Check if the callback already received the authorization code for this state
    $pendingData = OneDriveAuthService::retrievePendingTransaction($state);
    if (!empty($pendingData['code'])) {
        echo "[OK] Authorization code automatically captured via dedicated callback!\n";
        $inputCode = $pendingData['code'];
    } else {
        echo "Press ENTER once you have approved in the browser (or paste code/URL): ";
        $handle = fopen('php://stdin', 'r');
        $line = fgets($handle);
        fclose($handle);
        $entered = trim((string)$line);

        if (!empty($entered)) {
            $inputCode = $entered;
        } else {
            // Re-check pending transaction for the matching state
            $pendingData = OneDriveAuthService::retrievePendingTransaction($state);
            if (!empty($pendingData['code'])) {
                echo "[OK] Authorization code automatically captured via dedicated callback!\n";
                $inputCode = $pendingData['code'];
            }
        }
    }
}

if (empty($inputCode)) {
    echo "\n[ABORTED] No authorization code was provided or captured.\n";
    OneDriveAuthService::clearPendingTransaction($state);
    exit(1);
}

// Extract code parameter if full URL was pasted
if (str_contains($inputCode, 'code=')) {
    $parsed = parse_url($inputCode);
    $query = $parsed['query'] ?? $inputCode;
    parse_str($query, $queryParams);

    // Verify state if state is present in pasted URL
    if (!empty($queryParams['state']) && !hash_equals($state, (string)$queryParams['state'])) {
        echo "\n[ERROR] State mismatch in pasted URL. Expected state does not match.\n";
        OneDriveAuthService::clearPendingTransaction($state);
        exit(1);
    }

    $inputCode = $queryParams['code'] ?? $inputCode;
}

echo "\n[INFO] Exchanging authorization code with Microsoft Entra ID...\n";
$exchangeResult = $authService->exchangeCodeForTokens($inputCode, $redirectUri);

// Clear the pending transaction immediately after exchange attempt
OneDriveAuthService::clearPendingTransaction($state);

if (!$exchangeResult['success']) {
    echo "\n[ERROR] Token exchange failed: " . ($exchangeResult['error'] ?? 'Unknown error') . "\n";
    echo "Tip: Authorization codes expire after 10 minutes and can only be used once.\n";
    echo "Tip: Ensure the redirect URI used in Step 2 matches {$redirectUri}.\n\n";
    exit(1);
}

$refreshToken = $exchangeResult['refresh_token'] ?? '';
echo "\n========================================================================\n";
echo " [SUCCESS] OneDrive Refresh Token Generated Successfully!\n";
echo "========================================================================\n\n";

$envPath = __DIR__ . '/../.env';
$shouldSave = isset($options['save']);

if (!$shouldSave && file_exists($envPath)) {
    echo "Would you like to automatically update .env with ONEDRIVE_REFRESH_TOKEN? [y/N]: ";
    $handle = fopen('php://stdin', 'r');
    $confirm = trim(strtolower((string)fgets($handle)));
    fclose($handle);
    if ($confirm === 'y' || $confirm === 'yes') {
        $shouldSave = true;
    }
}

if ($shouldSave && file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    
    // Update or append ONEDRIVE_REFRESH_TOKEN
    if (preg_match('/^ONEDRIVE_REFRESH_TOKEN=.*$/m', $envContent)) {
        $envContent = preg_replace('/^ONEDRIVE_REFRESH_TOKEN=.*$/m', 'ONEDRIVE_REFRESH_TOKEN="' . addcslashes($refreshToken, '"$\\') . '"', $envContent);
    } else {
        $envContent .= "\n# Microsoft OneDrive Configuration\nONEDRIVE_REFRESH_TOKEN=\"" . addcslashes($refreshToken, '"$\\') . "\"\n";
    }

    // Enable OneDrive sync flag
    if (preg_match('/^ONEDRIVE_ENABLED=.*$/m', $envContent)) {
        $envContent = preg_replace('/^ONEDRIVE_ENABLED=.*$/m', 'ONEDRIVE_ENABLED=true', $envContent);
    } else {
        $envContent .= "ONEDRIVE_ENABLED=true\n";
    }

    // Ensure ONEDRIVE_ROOT_FOLDER is present
    if (!preg_match('/^ONEDRIVE_ROOT_FOLDER=.*$/m', $envContent)) {
        $envContent .= "ONEDRIVE_ROOT_FOLDER=\"STI_Activity_System_Documents\"\n";
    }

    file_put_contents($envPath, $envContent);
    echo "[OK] Updated .env with ONEDRIVE_REFRESH_TOKEN and ONEDRIVE_ENABLED=true.\n";
} else {
    echo "Add the following lines to your local .env file:\n\n";
    echo "ONEDRIVE_REFRESH_TOKEN=\"{$refreshToken}\"\n";
    echo "ONEDRIVE_ROOT_FOLDER=\"STI_Activity_System_Documents\"\n";
    echo "ONEDRIVE_ENABLED=true\n\n";
}

echo "NOTE: Mail.Send (OAUTH_REFRESH_TOKEN) and Microsoft Login configurations were left untouched.\n";
echo "OneDrive is now prepared for document backup integration.\n\n";
