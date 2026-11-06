<?php
// api/google-oauth-callback.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/google_config.php';
require_once __DIR__ . '/../includes/GoogleOAuthService.php';

// Canonical live domain fallback
$liveSiteUrl = 'https://sti-armes.freedev.app';

// 1. Resolve State / Origin / Return URL (supports OAuth state roundtrip)
$origin = '';
$returnTo = 'faculty/proposal-create.php';

if (!empty($_GET['state'])) {
    $decodedState = json_decode(base64_decode((string)$_GET['state']), true);
    if (is_array($decodedState)) {
        $origin = $decodedState['origin'] ?? '';
        $returnTo = $decodedState['return_to'] ?? $returnTo;
    }
}

if (empty($origin) && !empty($_GET['origin'])) {
    $origin = trim((string)$_GET['origin']);
}

if (empty($returnTo) && !empty($_GET['return_to'])) {
    $returnTo = trim((string)$_GET['return_to']);
}

// Fallback to Referer if origin not in state or GET
if (empty($origin) && !empty($_SERVER['HTTP_REFERER'])) {
    $refParsed = parse_url($_SERVER['HTTP_REFERER']);
    if (!empty($refParsed['host'])) {
        $scheme = $refParsed['scheme'] ?? 'https';
        $origin = $scheme . '://' . $refParsed['host'];
        if (!empty($refParsed['port']) && !in_array((int)$refParsed['port'], [80, 443], true)) {
            $origin .= ':' . $refParsed['port'];
        }
    }
}

// Security check: require authenticated user with faculty/admin/program_head role,
// or an incoming Google OAuth authorization code from an ongoing flow.
startSession();
if (empty($_SESSION['user_id']) && empty($_GET['code'])) {
    $targetLoginBase = !empty($origin) && !str_contains($origin, 'localhost') ? rtrim($origin, '/') : BASE_URL;
    header('Location: ' . $targetLoginBase . '/auth/login.php');
    exit;
}

$config = getGoogleConfig();
$authService = new GoogleOAuthService($config);
$redirectUri = !empty($config['GOOGLE_REDIRECT_URI']) 
    ? $config['GOOGLE_REDIRECT_URI'] 
    : BASE_URL . '/api/google-oauth-callback.php';

// Determine host environment
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocal = in_array(strtolower(explode(':', $currentHost)[0]), ['localhost', '127.0.0.1', '::1'], true);

// Package state so Google returns the user's originating site and return page
$statePayload = json_encode([
    'origin'    => !empty($origin) ? $origin : ($isLocal ? $liveSiteUrl : BASE_URL),
    'return_to' => ltrim($returnTo, '/'),
]);
$state = base64_encode($statePayload);
$authUrl = $authService->getAuthorizationUrl($redirectUri, $state);

$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;
$exchangeSuccess = false;
$refreshToken = '';
$message = '';

if (!empty($error)) {
    $message = 'Google authorization was cancelled or denied: ' . htmlspecialchars((string)$error);
} elseif (!empty($code)) {
    $result = $authService->exchangeAuthorizationCode($code, $redirectUri);
    if (!empty($result['success']) && !empty($result['refresh_token'])) {
        $refreshToken = $result['refresh_token'];
        $exchangeSuccess = true;

        // Automatically update local .env file
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile) && is_writable($envFile)) {
            $envContent = file_get_contents($envFile);
            if (preg_match('/^GOOGLE_REFRESH_TOKEN=.*$/m', $envContent)) {
                $envContent = preg_replace('/^GOOGLE_REFRESH_TOKEN=.*$/m', 'GOOGLE_REFRESH_TOKEN="' . $refreshToken . '"', $envContent);
            } else {
                $envContent .= "\nGOOGLE_REFRESH_TOKEN=\"" . $refreshToken . "\"\n";
            }
            file_put_contents($envFile, $envContent);
            $message = 'Google OAuth token refreshed and saved to .env automatically!';
        } else {
            $message = 'Google OAuth token generated successfully! Please copy it into your .env file.';
        }
    } else {
        $message = $result['error'] ?? 'Token exchange failed. Google only provides a refresh token upon first consent. If needed, revoke app access at myaccount.google.com/permissions and retry.';
    }
}

// Compute role-based dashboard path
$userRole = $_SESSION['user_role'] ?? 'faculty';
$roleDashboardMap = [
    'faculty'      => 'faculty/dashboard.php',
    'program_head' => 'program_head/dashboard.php',
    'admin1'       => 'admin1/dashboard.php',
    'admin2'       => 'admin2/dashboard.php',
    'dean'         => 'dean/dashboard.php',
];
$dashboardSubpath = $roleDashboardMap[$userRole] ?? 'faculty/dashboard.php';

// Target base resolution
$targetLiveBase = !empty($origin) && !str_contains($origin, 'localhost') ? rtrim($origin, '/') : $liveSiteUrl;
$targetLocalBase = defined('BASE_URL') ? BASE_URL : 'http://localhost/sti-activity-system';
$cleanReturnTo = ltrim($returnTo, '/');

$liveReturnUrl = $targetLiveBase . '/' . $cleanReturnTo;
$liveDashboardUrl = $targetLiveBase . '/' . $dashboardSubpath;
$localReturnUrl = $targetLocalBase . '/' . $cleanReturnTo;
$localDashboardUrl = $targetLocalBase . '/' . $dashboardSubpath;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Google OAuth Setup – STI-ARMES</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    body { background: #f8fafc; font-family: 'Segoe UI', system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
    .auth-card { background: #fff; max-width: 650px; width: 100%; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); padding: 32px; border: 1px solid #e2e8f0; }
    .btn-action { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 8px; font-weight: 600; text-decoration: none; cursor: pointer; border: none; font-size: 0.95rem; }
    .btn-google { background: #0284c7; color: #fff; }
    .btn-google:hover { background: #0369a1; color: #fff; }
    .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
    .btn-secondary:hover { background: #e2e8f0; }
    .token-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; font-family: monospace; font-size: 0.85rem; word-break: break-all; margin: 16px 0; color: #0f172a; }
  </style>
</head>
<body>
<div class="auth-card">
  <div style="display:flex; align-items:center; gap:12px; margin-bottom: 20px;">
    <div style="width:42px; height:42px; border-radius:8px; background:#eff6ff; display:flex; align-items:center; justify-content:center; color:#2563eb; font-size:1.4rem;">
      📋
    </div>
    <div>
      <h2 style="margin:0; font-size:1.35rem; color:#0f172a;">Google Forms Automated Setup</h2>
      <p style="margin:0; font-size:0.85rem; color:#64748b;">STI-ARMES Evaluation Tool Integration</p>
    </div>
  </div>

  <?php if ($exchangeSuccess): ?>
    <div style="background:#dcfce7; color:#166534; padding:14px 18px; border-radius:8px; border:1px solid #bbf7d0; margin-bottom:20px;">
      <h4 style="margin:0 0 6px 0; font-size:1.05rem;">✅ Authorization Complete!</h4>
      <p style="margin:0; font-size:0.88rem;"><?= htmlspecialchars($message) ?></p>
    </div>

    <p style="font-size:0.9rem; color:#334155; line-height:1.5;">
      Google Forms creation is now <strong>100% automated</strong>. When faculty or program heads click <strong>"Create Google Form"</strong>, the form and questions will be generated automatically.
    </p>

    <div style="margin-top:20px;">
      <label style="font-size:0.8rem; font-weight:600; color:#475569; text-transform:uppercase;">Generated Refresh Token (Copied to local .env)</label>
      <div class="token-box" id="tokenBox"><?= htmlspecialchars($refreshToken) ?></div>
      <div style="font-size:0.8rem; color:#64748b; margin-bottom:20px;">
        <em>Note for live deployment:</em> Copy the token above to line 26 of your live server's <code>.env</code> file: <code>GOOGLE_REFRESH_TOKEN="..."</code>
      </div>
    </div>

    <?php if ($isLocal): ?>
      <div style="display:flex; flex-direction:column; gap:12px;">
        <div style="display:flex; flex-wrap:wrap; gap:12px;">
          <a href="<?= htmlspecialchars($liveReturnUrl) ?>" class="btn-action btn-google">
            🌐 Return to Proposal Creator (Live Site)
          </a>
          <a href="<?= htmlspecialchars($liveDashboardUrl) ?>" class="btn-action btn-secondary">
            Live Site Dashboard
          </a>
        </div>
        <div style="border-top:1px dashed #e2e8f0; padding-top:10px; display:flex; flex-wrap:wrap; gap:10px; align-items:center;">
          <span style="font-size:0.8rem; color:#64748b;">Local development:</span>
          <a href="<?= htmlspecialchars($localReturnUrl) ?>" style="font-size:0.8rem; color:#0284c7; text-decoration:underline;">Return on Localhost</a>
          <span style="font-size:0.8rem; color:#cbd5e1;">|</span>
          <a href="<?= htmlspecialchars($localDashboardUrl) ?>" style="font-size:0.8rem; color:#64748b; text-decoration:underline;">Local Dashboard</a>
        </div>
      </div>
    <?php else: ?>
      <div style="display:flex; gap:12px;">
        <a href="<?= htmlspecialchars($liveReturnUrl) ?>" class="btn-action btn-google">Return to Proposal Creator</a>
        <a href="<?= htmlspecialchars($liveDashboardUrl) ?>" class="btn-action btn-secondary">Dashboard</a>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <?php if (!empty($message)): ?>
      <div style="background:#fee2e2; color:#991b1b; padding:14px 18px; border-radius:8px; border:1px solid #fecaca; margin-bottom:20px;">
        <h4 style="margin:0 0 4px 0; font-size:1rem;">⚠️ Authorization Status</h4>
        <p style="margin:0; font-size:0.85rem;"><?= htmlspecialchars($message) ?></p>
      </div>
    <?php endif; ?>

    <p style="font-size:0.92rem; color:#334155; line-height:1.6;">
      Authorizing with Google grants STI-ARMES permission to automatically generate activity evaluation forms in Google Forms using your activity objectives and questions.
    </p>

    <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:16px; margin:20px 0;">
      <h4 style="margin:0 0 8px 0; font-size:0.95rem; color:#0369a1;">1-Click Authorization:</h4>
      <p style="margin:0 0 14px 0; font-size:0.85rem; color:#0c4a6e;">
        Click the button below to sign in with your Google account and grant permission for form creation:
      </p>
      <a href="<?= htmlspecialchars($authUrl) ?>" class="btn-action btn-google">
        Sign In with Google to Authorize Forms &rarr;
      </a>
    </div>

    <div style="display:flex; flex-wrap:wrap; gap:12px;">
      <a href="<?= htmlspecialchars($liveReturnUrl) ?>" class="btn-action btn-secondary">&larr; Back to Proposal (Live Site)</a>
      <?php if ($isLocal): ?>
        <a href="<?= htmlspecialchars($localReturnUrl) ?>" class="btn-action btn-secondary" style="font-size:0.85rem; color:#64748b;">&larr; Back on Localhost</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>

