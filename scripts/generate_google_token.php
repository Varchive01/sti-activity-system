<?php
/**
 * scripts/generate_google_token.php
 *
 * Local CLI Utility to Authorize and Generate a Google OAuth Refresh Token
 * for the Google Forms evaluation integration.
 *
 * Usage:
 *   php scripts/generate_google_token.php                  (Interactive setup guide)
 *   php scripts/generate_google_token.php --check          (Validate current .env Google config)
 *   php scripts/generate_google_token.php --code=<code>    (Exchange code non-interactively)
 *   php scripts/generate_google_token.php --help           (Show options and scopes)
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

require_once __DIR__ . '/../config/google_config.php';
require_once __DIR__ . '/../includes/GoogleOAuthService.php';

$options = getopt('', ['check', 'help', 'code:', 'redirect-uri:']);

if (isset($options['help'])) {
    echo "========================================================================\n";
    echo "  Google Forms OAuth Refresh Token Generator Utility\n";
    echo "========================================================================\n\n";
    echo "Usage:\n";
    echo "  php scripts/generate_google_token.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --check              Validate current Google configuration in .env\n";
    echo "  --code=<code>        Authorization code from Google consent redirect\n";
    echo "  --redirect-uri=<uri> Custom redirect URI matching Google Cloud Console\n";
    echo "  --help               Show this help message\n\n";
    echo "Required Google OAuth Scopes:\n";
    echo "  - https://www.googleapis.com/auth/forms.body\n";
    echo "  - https://www.googleapis.com/auth/forms.responses.readonly\n\n";
    exit(0);
}

$config = getGoogleConfig();
$authService = new GoogleOAuthService($config);

if (isset($options['check'])) {
    echo "========================================================================\n";
    echo "  Google Forms Configuration Status Check\n";
    echo "========================================================================\n";
    echo " Client ID:      " . (!empty($config['GOOGLE_CLIENT_ID']) ? substr($config['GOOGLE_CLIENT_ID'], 0, 12) . '...' : '[MISSING]') . "\n";
    echo " Client Secret:  " . (!empty($config['GOOGLE_CLIENT_SECRET']) ? '********' : '[MISSING]') . "\n";
    echo " Redirect URI:   " . (!empty($config['GOOGLE_REDIRECT_URI']) ? $config['GOOGLE_REDIRECT_URI'] : '[NOT SET] (defaults to http://localhost/sti-activity-system/api/google-oauth-callback.php)') . "\n";
    echo " Refresh Token:  " . (!empty($config['GOOGLE_REFRESH_TOKEN']) ? '[CONFIGURED] (' . substr($config['GOOGLE_REFRESH_TOKEN'], 0, 10) . '...)' : '[NOT CONFIGURED]') . "\n";
    echo " Scopes:         " . GoogleOAuthService::SCOPES . "\n\n";

    $clientVal = $authService->validateClientCredentials();
    if (!$clientVal['valid']) {
        echo " STATUS: INCOMPLETE — " . $clientVal['error'] . "\n";
        echo " Action: Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to your .env file.\n";
        exit(1);
    }

    $fullVal = $authService->validateCredentials();
    if ($fullVal['valid']) {
        echo " STATUS: READY — All required Google credentials and refresh token are present.\n";
        exit(0);
    } else {
        echo " STATUS: READY FOR AUTHORIZATION — Client credentials present, refresh token needed.\n";
        echo " Action: Run 'php scripts/generate_google_token.php' to generate your refresh token.\n";
        exit(0);
    }
}

echo "========================================================================\n";
echo "  STI ACTIVITY SYSTEM — GOOGLE FORMS OAUTH SETUP\n";
echo "========================================================================\n\n";

$clientVal = $authService->validateClientCredentials();
if (!$clientVal['valid']) {
    echo "[ERROR] Missing Google Cloud OAuth Client credentials in .env!\n\n";
    echo "Prerequisite Steps:\n";
    echo "1. Go to Google Cloud Console (https://console.cloud.google.com).\n";
    echo "2. Enable the Google Forms API ('forms.googleapis.com').\n";
    echo "3. Create OAuth 2.0 Client IDs (Application type: Web application).\n";
    echo "4. Add the following to your .env file:\n\n";
    echo "   GOOGLE_CLIENT_ID=\"your_client_id.apps.googleusercontent.com\"\n";
    echo "   GOOGLE_CLIENT_SECRET=\"your_client_secret\"\n";
    echo "   GOOGLE_REDIRECT_URI=\"http://localhost/sti-activity-system/api/google-oauth-callback.php\"\n\n";
    exit(1);
}

// Determine redirect URI
$defaultRedirect = 'http://localhost/sti-activity-system/api/google-oauth-callback.php';
$redirectUri = $options['redirect-uri'] ?? (!empty($config['GOOGLE_REDIRECT_URI']) ? $config['GOOGLE_REDIRECT_URI'] : $defaultRedirect);

$authUrl = $authService->getAuthorizationUrl($redirectUri);

$code = $options['code'] ?? null;

if (empty($code)) {
    echo "STEP 1: Verify Google Cloud Console Configuration\n";
    echo "------------------------------------------------------------------------\n";
    echo "1. In Google Cloud Console -> APIs & Services -> Credentials:\n";
    echo "   Open your OAuth 2.0 Web Client.\n";
    echo "2. Ensure 'Authorized redirect URIs' contains:\n";
    echo "   {$redirectUri}\n";
    echo "3. Ensure OAuth consent screen has scopes:\n";
    echo "   - .../auth/forms.body\n";
    echo "   - .../auth/forms.responses.readonly\n\n";

    echo "STEP 2: Open Authorization URL in Your Browser\n";
    echo "------------------------------------------------------------------------\n";
    echo "Visit this URL in your web browser:\n\n";
    echo "{$authUrl}\n\n";

    echo "STEP 3: Paste Returned Authorization Code\n";
    echo "------------------------------------------------------------------------\n";
    echo "After authorizing, your browser will redirect to:\n";
    echo "{$redirectUri}?code=<AUTHORIZATION_CODE>&scope=...\n\n";
    echo "Copy the 'code' query parameter value from the browser's address bar.\n";
    echo "Enter authorization code: ";

    $handle = fopen('php://stdin', 'r');
    $code = trim((string)fgets($handle));
    fclose($handle);
    echo "\n";
}

if (empty($code)) {
    echo "[ERROR] No authorization code provided. Exiting.\n";
    exit(1);
}

echo "Exchanging authorization code for tokens...\n";
$exchangeResult = $authService->exchangeAuthorizationCode($code, $redirectUri);

if (empty($exchangeResult['success'])) {
    echo "[ERROR] Token exchange failed: " . ($exchangeResult['error'] ?? 'Unknown error') . "\n";
    exit(1);
}

echo "========================================================================\n";
echo "  AUTHORIZATION SUCCESSFUL!\n";
echo "========================================================================\n\n";

if (!empty($exchangeResult['refresh_token'])) {
    echo "Copy the following refresh token into your .env file:\n\n";
    echo "GOOGLE_REFRESH_TOKEN=\"{$exchangeResult['refresh_token']}\"\n\n";
    echo "Once added, the system can automatically create Google Forms and sync responses.\n";
} else {
    echo "[NOTICE] Google returned an access token, but no refresh_token was included.\n";
    echo "Google only returns a refresh_token on the first authorization consent.\n";
    echo "If you already authorized this application previously, revoke access at:\n";
    echo "https://myaccount.google.com/permissions\n";
    echo "and then run this script again to receive a fresh refresh_token.\n\n";
}

exit(0);
