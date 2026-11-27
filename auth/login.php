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

// Self-heal defense accounts globally on login page load and POST submission
// Includes self-contained fallback in case auth/login.php is deployed before includes/auth.php
if (!function_exists('ensureOfficialDefenseAccounts')) {
  function ensureOfficialDefenseAccounts(?PDO $db = null, bool $forceResetMustChange = false): void {
    try {
      $db = $db ?? getDB();
      $roleCol = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
      if ($roleCol && isset($roleCol['Type']) && stripos($roleCol['Type'], 'program_head') === false) {
        $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('faculty','admin1','admin2','dean','program_head') NOT NULL DEFAULT 'faculty'");
      }
      $accounts = [
        'faculty@sti.edu' => ['name' => 'Maria Santos', 'role' => 'faculty', 'department' => 'IT Department', 'pref_id' => 387, 'aliases' => ['faculty@sti.edu', 'maria.santos@marikina.sti.edu.ph', 'payod.332445@marikina.sti.edu.ph'], 'name_like' => '%maria%santos%'],
        'dave@sti.edu'    => ['name' => 'Dave Lord Rubaya', 'role' => 'program_head', 'department' => 'IT Department', 'pref_id' => 599, 'aliases' => ['dave@sti.edu', 'dave.rubaya@marikina.sti.edu.ph', 'hular.370125@marikina.sti.edu.ph'], 'name_like' => '%dave%rubaya%'],
        'tan@sti.edu'     => ['name' => 'Tristan Unabia', 'role' => 'program_head', 'department' => 'G.E. Department', 'pref_id' => 601, 'aliases' => ['tan@sti.edu', 'tristan.unabia@marikina.sti.edu.ph'], 'name_like' => '%tristan%unabia%'],
        'aldwin@sti.edu'  => ['name' => 'Aldwin Alminar', 'role' => 'program_head', 'department' => 'THM Department', 'pref_id' => 600, 'aliases' => ['aldwin@sti.edu', 'aldwin.alminar@marikina.sti.edu.ph'], 'name_like' => '%aldwin%alminar%'],
        'arjay@sti.edu'   => ['name' => 'Ar-jay Agabayani', 'role' => 'admin1', 'department' => 'IT Department', 'pref_id' => 2, 'aliases' => ['arjay@sti.edu', 'ar-jay.agbayani@marikina.sti.edu.ph', 'arjay.agbayani@marikina.sti.edu.ph', 'guaniso.372499@marikina.sti.edu.ph'], 'name_like' => '%ag%bayani%'],
        'ian@sti.edu'     => ['name' => 'Ian Jade Barangan', 'role' => 'admin2', 'department' => 'G.E. Department', 'pref_id' => 704, 'aliases' => ['ian@sti.edu', 'ian.barangan@marikina.sti.edu.ph', 'ianjade.barangan@marikina.sti.edu.ph', 'regulacion.376867@marikina.sti.edu.ph'], 'name_like' => '%ian%barangan%'],
        'dean@sti.edu'    => ['name' => 'Frederic Yulo', 'role' => 'dean', 'department' => 'Administration', 'pref_id' => 4, 'aliases' => ['dean@sti.edu', 'frederic.yulo@marikina.sti.edu.ph', 'robles.376003@marikina.sti.edu.ph'], 'name_like' => '%frederic%yulo%'],
      ];
      $defaultPassHash = password_hash('password', PASSWORD_DEFAULT);
      foreach ($accounts as $targetEmail => $cfg) {
        try {
          $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
          $stmt->execute([$targetEmail]);
          $exactUser = $stmt->fetch(PDO::FETCH_ASSOC);
          if ($exactUser) {
            if (empty($exactUser['password']) || !password_verify('password', $exactUser['password']) || ($exactUser['role'] ?? '') !== $cfg['role'] || $forceResetMustChange) {
              $db->prepare("UPDATE users SET password = ?, role = ?, department = ?, must_change_password = 0 WHERE id = ?")
                 ->execute([$defaultPassHash, $cfg['role'], $exactUser['department'] ?: $cfg['department'], (int)$exactUser['id']]);
            }
          } else {
            $placeholders = implode(',', array_fill(0, count($cfg['aliases']), '?'));
            $aStmt = $db->prepare("SELECT * FROM users WHERE id = ? OR LOWER(TRIM(email)) IN ($placeholders) OR LOWER(name) LIKE ? ORDER BY (id = ?) DESC, id ASC LIMIT 1");
            $params = array_merge([(int)$cfg['pref_id']], $cfg['aliases'], [$cfg['name_like'], (int)$cfg['pref_id']]);
            $aStmt->execute($params);
            $cand = $aStmt->fetch(PDO::FETCH_ASSOC);
            if ($cand && !isset($accounts[strtolower(trim((string)($cand['email'] ?? '')))])) {
              $db->prepare("UPDATE users SET email = ?, password = ?, role = ?, department = ?, must_change_password = 0 WHERE id = ?")
                 ->execute([$targetEmail, $defaultPassHash, $cfg['role'], $cand['department'] ?: $cfg['department'], (int)$cand['id']]);
            } else {
              $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())")
                 ->execute([$cfg['name'], $targetEmail, $defaultPassHash, $cfg['role'], $cfg['department']]);
            }
          }
        } catch (\Throwable $innerEx) {}
      }
    } catch (\Throwable $e) {}
  }
}
ensureOfficialDefenseAccounts(null, false);

// Traditional Email/Password Form Login Handler
$emailValue = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $emailInput = trim($_POST['email'] ?? '');
  $passwordInput = $_POST['password'] ?? '';
  $emailValue = $emailInput;

  // If a Quick Test Account button was used and a browser password manager cleared the password field, restore 'password'
  $quickTestEmails = ['faculty@sti.edu', 'dave@sti.edu', 'tan@sti.edu', 'aldwin@sti.edu', 'arjay@sti.edu', 'ian@sti.edu', 'dean@sti.edu'];
  if ($passwordInput === '' && (!empty($_POST['quick_test_selected']) || in_array(strtolower($emailInput), $quickTestEmails, true))) {
    $passwordInput = 'password';
  }

  if ($emailInput === '' || $passwordInput === '') {
    $error = 'Please enter both your institutional email and password.';
  } else {
    $user = login($emailInput, $passwordInput);
    if ($user) {
      if (!empty($user['must_change_password'])) {
        header("Location: " . BASE_URL . "/auth/change-password.php");
        exit;
      }
      $map = [
        'faculty'      => 'faculty',
        'admin1'       => 'admin1',
        'admin2'       => 'admin2',
        'dean'         => 'dean',
        'program_head' => 'program_head'
      ];
      $dir = $map[$user['role']] ?? 'faculty';
      header("Location: " . BASE_URL . "/{$dir}/dashboard.php");
      exit;
    } else {
      $error = 'Invalid institutional email or password. Please try again.';
    }
  }
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
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@800&display=swap" rel="stylesheet">
  
  <style>
    :root {
      --sti-navy: #0A1628;
      --sti-gold: #F5A623;
      --sti-blue: #0078D4;
      --sti-primary: #003876;
      --sti-primary-hover: #002855;
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
        linear-gradient(rgba(10, 24, 48, 0.72), rgba(10, 24, 48, 0.72)),
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
      padding: 24px 16px;
    }

    @media (max-width: 768px) {
      body {
        background-position: center 15%;
        padding: 16px 12px;
      }
    }

    .auth-page-body {
      width: 100%;
      max-width: 440px;
      margin: auto;
    }

    .auth-card {
      position: relative;
      background: #ffffff;
      border-radius: 24px;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35), 0 4px 16px rgba(0, 0, 0, 0.12);
      padding: 32px 32px 28px 32px;
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
      height: 90px;
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
      font-size: clamp(1.85rem, 5vw, 2.15rem);
      font-weight: 800;
      letter-spacing: -0.015em;
      color: #0A192F;
      white-space: nowrap;
      display: inline-block;
      line-height: 1.15;
      margin-bottom: 4px;
    }

    .auth-brand span {
      color: var(--sti-gold);
    }

    .auth-tagline {
      color: var(--text-muted);
      font-size: 0.81rem;
      font-weight: 500;
      line-height: 1.4;
      margin-bottom: 14px;
      letter-spacing: -0.01em;
    }

    /* Illustration container */
    .illustration-wrap {
      width: 100%;
      max-width: 180px;
      height: 110px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 14px;
    }

    .illustration-wrap svg {
      width: 100%;
      height: 100%;
      display: block;
    }

    /* Traditional Login Form */
    .auth-form {
      width: 100%;
      text-align: left;
      margin-top: 4px;
    }

    .form-group {
      margin-bottom: 14px;
      width: 100%;
    }

    .form-label {
      display: block;
      font-size: 0.80rem;
      font-weight: 600;
      color: #334155;
      margin-bottom: 5px;
      letter-spacing: -0.01em;
    }

    .input-wrapper {
      position: relative;
      width: 100%;
    }

    .input-icon {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
      pointer-events: none;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .form-control {
      width: 100%;
      height: 42px;
      padding: 8px 14px 8px 36px;
      font-family: inherit;
      font-size: 0.90rem;
      color: #1e293b;
      background: #f8fafc;
      border: 1.5px solid #cbd5e1;
      border-radius: 9px;
      transition: all 0.2s ease;
      outline: none;
    }

    .form-control:focus {
      background: #ffffff;
      border-color: #0078D4;
      box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.15);
    }

    .password-input-wrapper .form-control {
      padding-right: 40px;
    }

    .btn-toggle-password {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #64748b;
      cursor: pointer;
      padding: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color 0.15s ease;
    }

    .btn-toggle-password:hover {
      color: #0f172a;
    }

    .btn-submit-login {
      width: 100%;
      height: 44px;
      background: var(--sti-primary);
      color: #ffffff;
      border: none;
      border-radius: 9px;
      font-family: inherit;
      font-size: 0.94rem;
      font-weight: 700;
      letter-spacing: 0.01em;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(0, 56, 118, 0.28);
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      margin-top: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .btn-submit-login:hover {
      background: var(--sti-primary-hover);
      box-shadow: 0 6px 16px rgba(0, 56, 118, 0.38);
      transform: translateY(-1px);
    }

    .btn-submit-login:active {
      transform: translateY(0);
      box-shadow: 0 2px 6px rgba(0, 56, 118, 0.25);
    }

    /* Centered "or" Divider */
    .auth-divider {
      position: relative;
      width: 100%;
      text-align: center;
      margin: 16px 0;
    }

    .auth-divider::before {
      content: "";
      position: absolute;
      left: 0;
      top: 50%;
      width: 100%;
      height: 1px;
      background: #e2e8f0;
      z-index: 0;
    }

    .auth-divider span {
      position: relative;
      z-index: 1;
      background: #ffffff;
      padding: 0 10px;
      font-size: 0.76rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #94a3b8;
    }

    /* Prominent Microsoft button matching reference */
    .btn-microsoft {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 12px;
      width: 100%;
      height: 44px;
      background: #ffffff;
      border: 1px solid #D5DAE2;
      border-radius: 9px;
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      font-size: 0.92rem;
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
      width: 19px;
      height: 19px;
    }

    /* Notification & Alert Banners */
    .alert {
      border-radius: 9px;
      font-size: 0.82rem;
      line-height: 1.45;
      padding: 10px 14px;
      margin-bottom: 14px;
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

    /* Quick Demo Account Selector (Helpful for defense/evaluators) */
    .demo-accounts-wrap {
      margin-top: 18px;
      width: 100%;
      padding-top: 14px;
      border-top: 1px dashed #cbd5e1;
      text-align: center;
    }

    .demo-accounts-title {
      font-size: 0.74rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: #64748b;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 5px;
    }

    .demo-chips {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;
      gap: 5px;
    }

    .demo-chip {
      background: #f1f5f9;
      border: 1px solid #cbd5e1;
      border-radius: 6px;
      padding: 3px 8px;
      font-size: 0.74rem;
      font-weight: 600;
      color: #334155;
      cursor: pointer;
      transition: all 0.15s ease;
      user-select: none;
    }

    .demo-chip:hover {
      background: #003876;
      border-color: #003876;
      color: #ffffff;
      transform: translateY(-1px);
    }

    .demo-chip:active {
      transform: translateY(0);
    }

    @media (max-width: 480px) {
      .auth-card {
        padding: 26px 20px 22px 20px;
        border-radius: 20px;
      }
      .illustration-wrap {
        max-width: 160px;
        height: 96px;
        margin-bottom: 12px;
      }
      .btn-microsoft, .btn-submit-login {
        height: 42px;
        font-size: 0.90rem;
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

        <!-- Illustration: Laptop with Calendar, Bar Chart & Team Avatars -->
        <div class="illustration-wrap" aria-hidden="true">
          <svg viewBox="0 0 280 200" fill="none" xmlns="http://www.w3.org/2000/svg">
            <!-- Soft organic cloud / blob background -->
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
              <circle cx="0" cy="-6" r="6" fill="#0088DD"/>
              <path d="M-10 14 C-10 6, 10 6, 10 14 Z" fill="#0088DD"/>
              <circle cx="-16" cy="-2" r="5" fill="#0088DD"/>
              <path d="M-24 14 C-24 8, -8 8, -8 14 Z" fill="#0088DD"/>
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

        <!-- Alerts -->
        <?php if ($error): ?>
          <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($timeout): ?>
          <div class="alert alert-warning" role="alert">Session expired. Please sign in again.</div>
        <?php endif; ?>

        <!-- Traditional Email & Password Form -->
        <form action="<?= BASE_URL ?>/auth/login.php" method="POST" class="auth-form" autocomplete="on">
          <div class="form-group">
            <label for="loginEmail" class="form-label">Institutional Email</label>
            <div class="input-wrapper">
              <span class="input-icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                  <polyline points="22,6 12,13 2,6"></polyline>
                </svg>
              </span>
              <input type="email" name="email" id="loginEmail" class="form-control" placeholder="e.g. user@sti.edu" value="<?= htmlspecialchars($emailValue) ?>" required autocomplete="username">
            </div>
          </div>

          <div class="form-group">
            <label for="loginPassword" class="form-label">Password</label>
            <div class="input-wrapper password-input-wrapper">
              <span class="input-icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
              </span>
              <input type="password" name="password" id="loginPassword" class="form-control" placeholder="Enter your password" required autocomplete="current-password">
              <button type="button" id="togglePasswordBtn" class="btn-toggle-password" title="Show/hide password" aria-label="Show password" aria-pressed="false">
                <svg id="eyeIcon" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
              </button>
            </div>
          </div>

          <!-- Sign In Button -->
          <input type="hidden" name="quick_test_selected" id="quickTestSelected" value="0">
          <button type="submit" id="btnSignIn" class="btn-submit-login">
            <span>Sign In</span>
          </button>
        </form>

        <!-- Centered "or" Divider -->
        <div class="auth-divider">
          <span>or</span>
        </div>

        <!-- Microsoft Single Sign-On Button -->
        <a href="<?= BASE_URL ?>/auth/microsoft-login.php" id="btnMicrosoftLogin" class="btn-microsoft" role="button" aria-label="Sign in with Microsoft">
          <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 21 21" aria-hidden="true" focusable="false">
            <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
            <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
            <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
            <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
          </svg>
          <span>Sign in with Microsoft</span>
        </a>

        <!-- Quick Demo Accounts (For Panel Evaluation / Testing) -->
        <div class="demo-accounts-wrap">
          <div class="demo-accounts-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            <span>Quick Test Accounts</span>
          </div>
          <div class="demo-chips">
            <button type="button" class="demo-chip" onclick="fillLogin('faculty@sti.edu', 'password', this)">Faculty</button>
            <button type="button" class="demo-chip" onclick="fillLogin('dave@sti.edu', 'password', this)">Sir Dave</button>
            <button type="button" class="demo-chip" onclick="fillLogin('tan@sti.edu', 'password', this)">Sir Tan</button>
            <button type="button" class="demo-chip" onclick="fillLogin('aldwin@sti.edu', 'password', this)">Sir Aldwin</button>
            <button type="button" class="demo-chip" onclick="fillLogin('arjay@sti.edu', 'password', this)">Sir Arjay</button>
            <button type="button" class="demo-chip" onclick="fillLogin('ian@sti.edu', 'password', this)">Sir Ian</button>
            <button type="button" class="demo-chip" onclick="fillLogin('dean@sti.edu', 'password', this)">Dean</button>
          </div>
        </div>

      </div>

    </main>
  </div>

  <script>
    // Quick Demo Accounts filler
    let selectedQuickPassword = '';
    function fillLogin(email, password, chipEl) {
      const emailField = document.getElementById('loginEmail');
      const passField = document.getElementById('loginPassword');
      const quickFlag = document.getElementById('quickTestSelected');
      const signInBtn = document.getElementById('btnSignIn');
      selectedQuickPassword = password;

      // Clear any previous error banner when selecting a quick test account
      document.querySelectorAll('.alert-danger').forEach(el => el.style.display = 'none');

      // Highlight selected chip
      document.querySelectorAll('.demo-chip').forEach(c => {
        c.style.background = '';
        c.style.borderColor = '';
        c.style.color = '';
      });
      if (chipEl) {
        chipEl.style.background = '#003876';
        chipEl.style.borderColor = '#003876';
        chipEl.style.color = '#ffffff';
      }

      if (quickFlag) quickFlag.value = '1';
      if (emailField) {
        emailField.value = email;
        emailField.dispatchEvent(new Event('input', { bubbles: true }));
        emailField.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (passField) {
        passField.value = password;
        passField.dispatchEvent(new Event('input', { bubbles: true }));
        passField.dispatchEvent(new Event('change', { bubbles: true }));
      }
      // Guard against browser password autofill clearing the password asynchronously after focus changes
      setTimeout(() => {
        if (passField && !passField.value && selectedQuickPassword) {
          passField.value = selectedQuickPassword;
        }
      }, 60);
      signInBtn?.focus();
    }

    // Ensure password isn't blank on submit if a Quick Test Account was chosen
    const authForm = document.querySelector('.auth-form');
    if (authForm) {
      authForm.addEventListener('submit', function () {
        const passField = document.getElementById('loginPassword');
        if (passField && !passField.value && selectedQuickPassword) {
          passField.value = selectedQuickPassword;
        }
      });
    }

    // Toggle password visibility
    const togglePasswordBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('loginPassword');
    const eyeIcon = document.getElementById('eyeIcon');

    if (togglePasswordBtn && passwordInput && eyeIcon) {
      togglePasswordBtn.addEventListener('click', function () {
        const isPassword = passwordInput.getAttribute('type') === 'password';
        passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
        togglePasswordBtn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
        togglePasswordBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
        
        if (isPassword) {
          // Eye-slash SVG
          eyeIcon.innerHTML = `
            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
            <line x1="1" y1="1" x2="23" y2="23"></line>
          `;
        } else {
          // Standard Eye SVG
          eyeIcon.innerHTML = `
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
            <circle cx="12" cy="12" r="3"></circle>
          `;
        }
      });
    }

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
