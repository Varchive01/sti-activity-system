<?php
// api/onedrive-oauth-callback.php

/**
 * Dedicated OAuth 2.0 Callback Endpoint for Microsoft OneDrive Token Generation.
 *
 * Receives the authorization code from Microsoft Entra ID consent redirect,
 * verifies state using constant-time comparison against the pending local transaction,
 * and securely bridges the code to the CLI generator outside the public web root.
 *
 * SECURITY REQUIREMENTS:
 * 1. Accepts ONLY GET requests.
 * 2. Constant-time verification of the OAuth state parameter.
 * 3. Never outputs authorization codes, client secrets, or refresh tokens in HTML, JSON, or headers.
 * 4. Never writes authorization codes, client secrets, or refresh tokens to application logs.
 * 5. Does NOT authenticate a local application user.
 * 6. Does NOT create a session for the STI application login.
 * 7. Does NOT redirect into auth/microsoft-callback.php.
 */

// 1. Accept only GET requests
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Method Not Allowed. This callback accepts only GET requests.\n";
    exit;
}

require_once __DIR__ . '/../includes/OneDriveAuthService.php';

$isJson = (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
    || (isset($_GET['format']) && $_GET['format'] === 'json');

/**
 * Renders a secure response without exposing any secrets, tokens, or codes.
 */
function sendCallbackResponse(int $statusCode, bool $success, string $title, string $message, bool $isJson, string $errorType = ''): void {
    http_response_code($statusCode);

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        $payload = [
            'success' => $success,
            'message' => $message,
        ];
        if (!$success && $errorType !== '') {
            $payload['error'] = $errorType;
        }
        echo json_encode($payload);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $statusColor = $success ? '#10b981' : '#ef4444';
    $badgeText = $success ? 'Ready in Terminal' : 'Action Required';
    $badgeBg = $success ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)';
    $iconSvg = $success
        ? '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'
        : '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $safeTitle ?> — STI Activity System</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 40px;
            max-width: 520px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .icon-wrapper {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: <?= $badgeBg ?>;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
        }
        h1 {
            font-size: 22px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 12px;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: <?= $statusColor ?>;
            background: <?= $badgeBg ?>;
            margin-bottom: 16px;
        }
        p {
            font-size: 15px;
            line-height: 1.6;
            color: #94a3b8;
            margin-bottom: 24px;
        }
        .notice-box {
            background: rgba(15, 23, 42, 0.6);
            border: 1px dashed rgba(255, 255, 255, 0.15);
            border-radius: 8px;
            padding: 14px;
            font-size: 13px;
            color: #64748b;
            text-align: left;
        }
        .notice-box strong {
            color: #cbd5e1;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrapper">
            <?= $iconSvg ?>
        </div>
        <div class="badge"><?= $badgeText ?></div>
        <h1><?= $safeTitle ?></h1>
        <p><?= $safeMessage ?></p>
        <div class="notice-box">
            <strong>Security Notice:</strong> Authorization codes and tokens are stored locally outside public web access. They are never rendered in browser output or logs.
        </div>
    </div>
</body>
</html>
<?php
    exit;
}

// 2. Handle Microsoft OAuth errors
if (!empty($_GET['error'])) {
    $err = (string)$_GET['error'];
    $desc = (string)($_GET['error_description'] ?? 'Authorization was cancelled or denied.');
    $state = (string)($_GET['state'] ?? '');
    if ($state !== '') {
        OneDriveAuthService::clearPendingTransaction($state);
    }
    // Sanitize error description — never leak secrets
    $safeDesc = preg_replace('/[^\w\s\.\-_:\(\)]/', '', strtok($desc, "\r\n"));
    sendCallbackResponse(400, false, 'OneDrive Authorization Denied', 'Microsoft Entra ID returned: ' . $safeDesc, $isJson, $err);
}

// 3. Validate state parameter
$state = trim((string)($_GET['state'] ?? ''));
if ($state === '') {
    sendCallbackResponse(400, false, 'Invalid State Parameter', 'The OAuth state parameter is missing from the request. Authorization cannot be verified.', $isJson, 'missing_state');
}

// 4. Validate authorization code
$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    sendCallbackResponse(400, false, 'Missing Authorization Code', 'No authorization code was provided in the Microsoft redirect response.', $isJson, 'missing_code');
}

// 5. Store authorization code with constant-time state verification
$storeResult = OneDriveAuthService::storeAuthorizationCode($state, $code);

if (!$storeResult['success']) {
    $errType = $storeResult['error'] ?? 'invalid_request';
    $errMsg = $storeResult['message'] ?? 'Failed to verify or store authorization transaction.';
    $statusCode = ($errType === 'state_mismatch') ? 403 : 400;
    sendCallbackResponse($statusCode, false, 'Authorization Rejected', $errMsg, $isJson, $errType);
}

// 6. Success: Prompt user to return to terminal. Never expose the code in HTML or JSON.
sendCallbackResponse(
    200,
    true,
    'OneDrive Authorization Received',
    'The authorization code has been captured securely. Please return to your terminal or command prompt window to complete token generation.',
    $isJson
);
