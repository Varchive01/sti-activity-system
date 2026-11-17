<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

startSession();

// Already logged in? Redirect to role dashboard.
if (!empty($_SESSION['user_id'])) {
  $isSso = (($_SESSION['auth_method'] ?? '') === 'microsoft');
  if (!empty($_SESSION['must_change_password']) && !$isSso) {
    header("Location: " . BASE_URL . "/auth/change-password.php");
    exit;
  }
  $role = $_SESSION['user_role'] ?? 'faculty';
  $map  = [
    'faculty'      => 'faculty',
    'admin1'       => 'admin1',
    'admin2'       => 'admin2',
    'dean'         => 'dean',
    'program_head' => 'program_head'
  ];
  $dir  = $map[$role] ?? 'faculty';
  header("Location: " . BASE_URL . "/{$dir}/dashboard.php");
  exit;
}

$timeout = false;
if (isset($_GET['timeout'])) {
  if (empty($_SESSION['shown_timeout'])) {
    $timeout = true;
    $_SESSION['shown_timeout'] = true;
  }
} else {
  unset($_SESSION['shown_timeout']);
}

// Flash message handler for OAuth errors
$error = '';
$unmatchedMsEmail = $_SESSION['unmatched_ms_email'] ?? '';
unset($_SESSION['unmatched_ms_email']);
$notFoundMsg = $unmatchedMsEmail !== ''
  ? 'Your Microsoft account (' . htmlspecialchars($unmatchedMsEmail) . ') is not registered in the system. Please contact your administrator.'
  : 'Your Microsoft account is not registered in the system. Please contact your administrator.';

$errorMap = [
  'microsoft_account_not_found' => $notFoundMsg,
  'microsoft_oauth_failed'      => 'Microsoft authentication was cancelled or encountered an error. Please try again.',
  'invalid_state'               => 'Security verification failed (invalid OAuth state). Please try again.',
  'missing_email'               => 'Unable to retrieve an email address from your Microsoft profile.',
  'missing_config'              => 'Microsoft login is not currently configured on this server.',
];

if (isset($_GET['error'])) {
  $errCode = (string)$_GET['error'];
  if (empty($_SESSION['shown_oauth_error']) || $_SESSION['shown_oauth_error'] !== $errCode) {
    $error = $errorMap[$errCode] ?? htmlspecialchars($errCode);
    $_SESSION['shown_oauth_error'] = $errCode;
  } else {
    unset($_SESSION['shown_oauth_error']);
  }
} elseif (!empty($_SESSION['flash_error'])) {
  $errCode = (string)$_SESSION['flash_error'];
  $error = $errorMap[$errCode] ?? htmlspecialchars($errCode);
  unset($_SESSION['flash_error']);
} else {
  unset($_SESSION['shown_oauth_error']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login – STI Marikina Activity System</title>
  
  <!-- Preconnect & Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Syne:wght@800&display=swap" rel="stylesheet">
  
  <style>
    :root {
      --sti-navy: #0A1628;
      --sti-gold: #F5A623;
      --sti-blue: #0078D4;
      --text-main: #1A202C;
      --text-muted: #556987;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      background-color: var(--sti-navy);
      background-image: 
        linear-gradient(rgba(10, 24, 48, 0.70), rgba(10, 24, 48, 0.70)),
        url('<?= BASE_URL ?>/assets/img/sti-marikina-building.jpg');
      background-size: cover;
      background-position: center 26%;
      background-repeat: no-repeat;
      background-attachment: fixed;
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: var(--text-main);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      padding: 20px;
    }

    @media (max-width: 768px) {
      body {
        background-position: center 15%;
        padding: 16px;
      }
    }

    .auth-page-body {
      width: 100%;
      max-width: 430px;
      margin: auto;
    }

    .auth-card {
      position: relative;
      background: #ffffff;
      border-radius: 24px;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.32), 0 4px 16px rgba(0, 0, 0, 0.1);
      padding: 36px 36px 32px 36px;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }

    /* Subtle decorative light-blue wave across card bottom */
    .card-bottom-wave {
      position: absolute;
      bottom: 0;
      left: 0;
      width: 100%;
      height: 88px;
      pointer-events: none;
      z-index: 0;
      overflow: hidden;
    }

    .card-bottom-wave svg {
      width: 100%;
      height: 100%;
      display: block;
    }

    .card-content {
      position: relative;
      z-index: 1;
      width: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
    }

    .auth-brand {
      font-family: 'Syne', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      font-size: clamp(1.9rem, 5.5vw, 2.2rem);
      font-weight: 800;
      letter-spacing: -0.015em;
      color: #0A192F;
      white-space: nowrap;
      display: inline-block;
      line-height: 1.15;
      margin-bottom: 5px;
    }

    .auth-brand span {
      color: var(--sti-gold);
    }

    .auth-tagline {
      color: var(--text-muted);
      font-size: 0.82rem;
      font-weight: 500;
      line-height: 1.45;
      margin-bottom: 20px;
      letter-spacing: -0.01em;
    }

    /* Illustration container */
    .illustration-wrap {
      width: 100%;
      max-width: 240px;
      height: 152px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 22px;
    }

    .illustration-wrap svg {
      width: 100%;
      height: 100%;
      display: block;
    }

    /* Prominent Microsoft button matching reference */
    .btn-microsoft {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      width: 100%;
      padding: 12px 20px;
      background: #ffffff;
      border: 1px solid #D5DAE2;
      border-radius: 11px;
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      font-size: 0.94rem;
      font-weight: 600;
      color: #111827;
      text-decoration: none;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      cursor: pointer;
    }

    .btn-microsoft:hover {
      background-color: #F9FAFB;
      border-color: #9CA3AF;
      color: #111827;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
      transform: translateY(-1px);
      text-decoration: none;
    }

    .btn-microsoft:active {
      background-color: #F3F4F6;
      transform: translateY(0);
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }

    .btn-microsoft:focus-visible {
      outline: none;
      border-color: #0078D4;
      box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.25);
    }

    .btn-microsoft svg {
      flex-shrink: 0;
      width: 21px;
      height: 21px;
    }

    /* Notification & Alert Banners */
    .alert {
      border-radius: 10px;
      font-size: 0.83rem;
      line-height: 1.45;
      padding: 11px 16px;
      margin-bottom: 20px;
      width: 100%;
      text-align: left;
    }

    .alert-danger {
      background: #FEF2F2;
      border: 1px solid #FCA5A5;
      color: #991B1B;
    }

    .alert-warning {
      background: #FEF9E7;
      border: 1px solid rgba(244, 169, 0, 0.4);
      color: #78350F;
    }

    @media (max-width: 480px) {
      .auth-card {
        padding: 36px 24px 32px 24px;
        border-radius: 22px;
      }
      .illustration-wrap {
        max-width: 235px;
        height: 155px;
        margin-bottom: 24px;
      }
      .btn-microsoft {
        padding: 12px 18px;
        font-size: 0.92rem;
      }
    }
  </style>
</head>

<body>
  <div class="auth-page-body">
    <main class="auth-card">
      
      <!-- Subtle decorative bottom wave matching the reference design -->
      <div class="card-bottom-wave">
        <svg viewBox="0 0 450 110" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M0,45 C120,85 240,15 450,55 L450,110 L0,110 Z" fill="#E8F3FD" opacity="0.85"/>
          <path d="M0,70 C160,35 300,95 450,65 L450,110 L0,110 Z" fill="#D7ECFB" opacity="0.6"/>
        </svg>
      </div>

      <div class="card-content">
        <!-- Brand Title and Subtitle -->
        <h1 class="auth-brand"><span>STI</span>-ARMES</h1>
        <p class="auth-tagline">Activity Request, Monitoring, and Evaluation System</p>

        <!-- OAuth Error / Timeout Alerts -->
        <?php if ($error): ?>
          <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($timeout): ?>
          <div class="alert alert-warning" role="alert">Session expired. Please sign in again.</div>
        <?php endif; ?>

        <!-- Illustration: Laptop with Calendar, Bar Chart & Team Avatars inside organic cloud blob -->
        <div class="illustration-wrap" aria-hidden="true">
          <svg viewBox="0 0 280 200" fill="none" xmlns="http://www.w3.org/2000/svg">
            <!-- Soft organic cloud / blob background in light sky blue -->
            <path d="M52 108C38 108 26 96 26 82C26 71 33 61 43 57C42 54 41 50 41 47C41 31 54 18 70 18C75 18 80 19 84 22C92 10 106 2 122 2C143 2 161 16 166 35C171 32 177 30 184 30C202 30 216 44 216 62C216 65 215 68 214 71C226 75 234 86 234 99C234 115 221 128 205 128C201 128 198 127 194 126C186 137 173 144 158 144C151 144 145 142 139 139C132 147 121 152 110 152C94 152 80 143 74 130C67 133 60 135 52 135C33 135 18 120 18 101C18 97 19 93 21 89C19 95 18 101 18 108Z" fill="#E8F4FE"/>
            <path d="M72 130C52 130 36 114 36 94C36 78 47 64 62 60C61 56 60 52 60 48C60 30 75 15 93 15C99 15 105 17 110 20C119 8 134 0 151 0C174 0 194 15 199 36C205 33 212 31 220 31C240 31 256 47 256 67C256 70 255 73 254 76C267 81 276 93 276 107C276 125 261 140 243 140C239 140 235 139 231 138C222 150 208 158 191 158C183 158 176 156 169 152C161 161 149 166 137 166C119 166 103 156 96 142C89 145 81 147 72 147Z" fill="#E2F1FD" opacity="0.75"/>

            <!-- Laptop Shadow -->
            <ellipse cx="140" cy="182" rx="90" ry="6" fill="#0078D4" fill-opacity="0.12"/>

            <!-- Laptop Screen Outer Shell -->
            <rect x="52" y="38" width="176" height="118" rx="8" fill="#0088DD"/>
            <rect x="54" y="40" width="172" height="114" rx="6" fill="#0082D3"/>

            <!-- Laptop Inner Screen Display (White canvas) -->
            <rect x="58" y="44" width="164" height="106" rx="4" fill="#FFFFFF"/>

            <!-- Screen Content: Calendar (Top Left) -->
            <g transform="translate(74, 56)">
              <rect x="0" y="2" width="38" height="34" rx="4" stroke="#0088DD" stroke-width="2.5" fill="none"/>
              <line x1="0" y1="11" x2="38" y2="11" stroke="#0088DD" stroke-width="2.5"/>
              <rect x="7" y="0" width="3" height="4" rx="1.5" fill="#0088DD"/>
              <rect x="28" y="0" width="3" height="4" rx="1.5" fill="#0088DD"/>
              <path d="M11 22L16 27L27 16" stroke="#0088DD" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </g>

            <!-- Screen Content: Bar Chart (Top Right) -->
            <g transform="translate(162, 58)">
              <line x1="0" y1="32" x2="42" y2="32" stroke="#0088DD" stroke-width="2" stroke-linecap="round"/>
              <rect x="6" y="20" width="6" height="12" rx="1" fill="#0088DD"/>
              <rect x="18" y="11" width="6" height="21" rx="1" fill="#0088DD"/>
              <rect x="30" y="2" width="6" height="30" rx="1" fill="#0088DD"/>
            </g>

            <!-- Screen Content: Team / People Avatars (Center Bottom) -->
            <g transform="translate(140, 115)">
              <!-- Center Person (Leader) -->
              <circle cx="0" cy="-6" r="6" fill="#0088DD"/>
              <path d="M-10 14 C-10 6, 10 6, 10 14 Z" fill="#0088DD"/>

              <!-- Left Teammate -->
              <circle cx="-16" cy="-2" r="5" fill="#0088DD"/>
              <path d="M-24 14 C-24 8, -8 8, -8 14 Z" fill="#0088DD"/>

              <!-- Right Teammate -->
              <circle cx="16" cy="-2" r="5" fill="#0088DD"/>
              <path d="M8 14 C8 8, 24 8, 24 14 Z" fill="#0088DD"/>
            </g>

            <!-- Laptop Base & Keyboard Lower Half -->
            <path d="M38 156 L242 156 L226 172 L54 172 Z" fill="#0077C8"/>
            <path d="M40 157 L240 157 L225 171 L55 171 Z" fill="#0088DD"/>

            <!-- Front Lip & Trackpad Notch -->
            <path d="M36 172 L244 172 C245 172, 246 173, 245 175 L242 178 C241.5 179, 240 179.5, 238 179.5 L42 179.5 C40 179.5, 38.5 179, 38 178 L35 175 C34 173, 35 172, 36 172 Z" fill="#0071BF"/>
            <rect x="124" y="171.5" width="32" height="3" rx="1.5" fill="#FFFFFF" fill-opacity="0.8"/>
          </svg>
        </div>

        <!-- Single Primary Action: Sign in with Microsoft -->
        <a href="<?= BASE_URL ?>/auth/microsoft-login.php" id="btnMicrosoftLogin" class="btn-microsoft" role="button" aria-label="Sign in with Microsoft">
          <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 21 21" aria-hidden="true" focusable="false">
            <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
            <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
            <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
            <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
          </svg>
          <span>Sign in with Microsoft</span>
        </a>
      </div>

    </main>
  </div>

  <script>
    // Space key activation for Microsoft Sign-In button (WAI-ARIA button pattern)
    const btnMicrosoftLogin = document.getElementById('btnMicrosoftLogin');
    if (btnMicrosoftLogin) {
      btnMicrosoftLogin.addEventListener('keydown', function (e) {
        if (e.key === ' ' || e.key === 'Spacebar') {
          e.preventDefault();
          btnMicrosoftLogin.click();
        }
      });
    }

    // Clean stale error and timeout query parameters from the browser address bar
    if (window.history && window.history.replaceState) {
      const currentUrl = new URL(window.location.href);
      if (currentUrl.searchParams.has('error') || currentUrl.searchParams.has('timeout')) {
        currentUrl.searchParams.delete('error');
        currentUrl.searchParams.delete('timeout');
        window.history.replaceState({}, document.title, currentUrl.pathname + (currentUrl.search || ''));
      }
    }
  </script>
</body>

</html>
