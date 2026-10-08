<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

startSession();

// Already logged in? Redirect.
if (!empty($_SESSION['user_id'])) {
  if (!empty($_SESSION['must_change_password'])) {
    header("Location: " . BASE_URL . "/auth/change-password.php");
    exit;
  }
  $role = $_SESSION['user_role'];
  $map  = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean'];
  $dir  = $map[$role] ?? 'faculty';
  header("Location: " . BASE_URL . "/{$dir}/dashboard.php");
  exit;
}

// Failed-login throttle configuration
$maxThrottleAttempts = 5;
$throttleLockoutSeconds = 60;
$throttleDecaySeconds = 300;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $emailInput = trim($_POST['email'] ?? '');
  $passwordInput = $_POST['password'] ?? '';

  // Composite key per client IP and submitted email
  $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
  $throttleKey = hash('sha256', $clientIp . '|' . strtolower($emailInput));
  $throttleDir = session_save_path();
  if (empty($throttleDir) || !is_dir($throttleDir) || !is_writable($throttleDir)) {
    $throttleDir = (is_dir('C:/xampp/tmp') && is_writable('C:/xampp/tmp')) ? 'C:/xampp/tmp' : sys_get_temp_dir();
  }
  $throttleFile = rtrim($throttleDir, '/\\') . DIRECTORY_SEPARATOR . 'sti_throttle_' . $throttleKey . '.json';

  // Read current throttle state
  $throttleData = ['count' => 0, 'locked_until' => 0, 'last_attempt' => 0];
  if (file_exists($throttleFile)) {
    $raw = @file_get_contents($throttleFile);
    if ($raw) {
      $parsed = @json_decode($raw, true);
      if (is_array($parsed)) {
        $throttleData = array_merge($throttleData, $parsed);
      }
    }
  }
  if (!empty($_SESSION['login_throttle'][$throttleKey]) && is_array($_SESSION['login_throttle'][$throttleKey])) {
    if (($_SESSION['login_throttle'][$throttleKey]['count'] ?? 0) > $throttleData['count']) {
      $throttleData = array_merge($throttleData, $_SESSION['login_throttle'][$throttleKey]);
    }
  }

  $now = time();
  $isLocked = false;

  // Check lockout or decay expiry
  if ($throttleData['locked_until'] > 0) {
    if ($now < $throttleData['locked_until']) {
      $isLocked = true;
    } else {
      $throttleData = ['count' => 0, 'locked_until' => 0, 'last_attempt' => 0];
      if (file_exists($throttleFile)) {
        @unlink($throttleFile);
      }
      unset($_SESSION['login_throttle'][$throttleKey]);
    }
  } elseif ($throttleData['last_attempt'] > 0 && ($now - $throttleData['last_attempt']) > $throttleDecaySeconds) {
    $throttleData = ['count' => 0, 'locked_until' => 0, 'last_attempt' => 0];
    if (file_exists($throttleFile)) {
      @unlink($throttleFile);
    }
    unset($_SESSION['login_throttle'][$throttleKey]);
  }

  if ($isLocked) {
    http_response_code(429);
    $error = 'Too many failed login attempts. Please try again later.';
  } else {
    $user = login($emailInput, $passwordInput);
    if ($user) {
      // Clear throttle on successful login
      if (file_exists($throttleFile)) {
        @unlink($throttleFile);
      }
      unset($_SESSION['login_throttle'][$throttleKey]);

      if (!empty($user['must_change_password'])) {
        header("Location: " . BASE_URL . "/auth/change-password.php");
        exit;
      }
      $map = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean'];
      $dir = $map[$user['role']] ?? 'faculty';
      header("Location: " . BASE_URL . "/{$dir}/dashboard.php");
      exit;
    }

    // Record failed attempt
    $throttleData['count'] = ($throttleData['count'] ?? 0) + 1;
    $throttleData['last_attempt'] = $now;
    if ($throttleData['count'] >= $maxThrottleAttempts) {
      $throttleData['locked_until'] = $now + $throttleLockoutSeconds;
      http_response_code(429);
      $error = 'Too many failed login attempts. Please try again later.';
    } else {
      $error = 'Invalid email or password.';
    }
    @file_put_contents($throttleFile, json_encode($throttleData), LOCK_EX);
    $_SESSION['login_throttle'][$throttleKey] = $throttleData;
  }
}
$timeout = isset($_GET['timeout']);

if (empty($error) && isset($_GET['error'])) {
  $errCode = (string)$_GET['error'];
  $errorMap = [
    'microsoft_account_not_found' => 'Your Microsoft account is not registered in the system. Please contact your administrator.',
    'microsoft_oauth_failed'      => 'Microsoft authentication was cancelled or encountered an error. Please try again.',
    'invalid_state'               => 'Security verification failed (invalid OAuth state). Please try again.',
    'missing_email'               => 'Unable to retrieve an email address from your Microsoft profile.',
    'missing_config'              => 'Microsoft login is not currently configured on this server.',
  ];
  $error = $errorMap[$errCode] ?? htmlspecialchars($errCode);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login – STI Marikina Activity System</title>
  <!-- Load Bootstrap 5.3.3 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Load Font Awesome 6.4.0 -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <!-- Load App Custom CSS -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  
  <style>
    :root {
      --primary-color: var(--sti-blue, #0072CE);
      --primary-hover: var(--sti-blue-hover, #005FA3);
      --bg-color: var(--bg-page, #F4F6FA);
      --card-bg: #ffffff;
      --text-main: var(--text-main, #1A202C);
      --text-muted: var(--text-muted, #718096);
      --border-color: var(--border, #E2E8F0);
      --radius: var(--radius-md, 12px);
      --shadow-lg: 0 20px 40px rgba(10, 22, 40, 0.25);
    }

    body {
      background: var(--sti-navy, #0A1628);
      font-family: 'Plus Jakarta Sans', sans-serif;
      color: var(--text-main);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      margin: 0;
      padding: 20px;
    }

    .auth-page-body {
      width: 100%;
      max-width: 390px;
    }

    .auth-card {
      background: var(--card-bg);
      border-radius: var(--radius-md, 12px);
      box-shadow: 0 20px 40px rgba(10, 22, 40, 0.25);
      padding: 40px 32px;
      border: 1px solid var(--border-color);
    }

    .auth-brand {
      font-family: 'Syne', sans-serif;
      font-size: 2.2rem;
      font-weight: 800;
      letter-spacing: -0.02em;
      color: var(--sti-navy, #0A1628);
    }

    .auth-brand span {
      color: var(--sti-gold, #F4A900);
    }

    .auth-tagline {
      color: var(--text-muted);
      font-size: 0.82rem;
      font-weight: 500;
      line-height: 1.45;
    }

    .form-label {
      color: var(--sti-navy, #0A1628);
      font-weight: 600;
      font-size: 0.82rem;
      margin-bottom: 6px;
    }

    .form-control {
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm, 6px);
      padding: 10px 14px;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 0.88rem;
      color: var(--text-main);
      background: #ffffff;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.15);
      outline: none;
    }

    .auth-password-wrapper {
      position: relative;
    }

    .auth-password-input {
      padding-right: 48px;
    }

    .auth-password-toggle {
      position: absolute;
      right: 6px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      border: none;
      background: transparent;
      padding: 8px 10px;
      font-size: 1rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color 0.15s ease;
      text-decoration: none;
    }

    .auth-password-toggle:hover {
      color: var(--sti-navy, #0A1628);
    }

    .login-form-link {
      font-size: 0.8rem;
      color: var(--primary-color);
      text-decoration: none;
      font-weight: 600;
      transition: color 0.15s ease;
    }

    .login-form-link:hover {
      color: var(--primary-hover);
      text-decoration: underline;
    }

    .auth-submit {
      background: var(--primary-color);
      border: none;
      border-radius: var(--radius-sm, 6px);
      padding: 11px 16px;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 0.92rem;
      font-weight: 700;
      color: #ffffff;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      width: 100%;
      box-shadow: 0 2px 6px rgba(0, 114, 206, 0.25);
      cursor: pointer;
    }

    .auth-submit:hover {
      background: var(--primary-hover);
      color: #ffffff;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0, 114, 206, 0.35);
    }

    .auth-submit:active {
      transform: translateY(0);
      box-shadow: 0 2px 4px rgba(0, 114, 206, 0.2);
    }

    .auth-divider {
      display: flex;
      align-items: center;
      text-align: center;
      color: var(--text-muted);
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .auth-divider::before,
    .auth-divider::after {
      content: '';
      flex: 1;
      border-bottom: 1px solid var(--border-color);
    }

    .auth-divider:not(:empty)::before {
      margin-right: 1rem;
    }

    .auth-divider:not(:empty)::after {
      margin-left: 1rem;
    }

    .auth-oauth-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
    }

    .auth-oauth-btn {
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm, 6px);
      padding: 10px;
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-main);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: all 0.2s ease;
    }

    .auth-oauth-btn:hover {
      background: var(--bg-base, #F8FAFD);
      border-color: #cbd5e1;
    }

    #btnMicrosoftLogin {
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm, 6px);
      font-weight: 600;
      font-size: 0.85rem;
      color: var(--sti-navy, #0A1628);
      background: #ffffff;
      text-decoration: none;
      transition: all 0.2s ease;
      box-shadow: var(--shadow-sm);
    }

    #btnMicrosoftLogin:hover {
      background: var(--bg-base, #F8FAFD);
      border-color: #cbd5e1;
      color: var(--sti-navy, #0A1628);
      transform: translateY(-1px);
      box-shadow: var(--shadow);
    }

    .alert {
      border-radius: var(--radius-sm, 6px);
      font-size: 0.82rem;
      line-height: 1.45;
    }

    .alert-danger {
      background: #FEF2F2;
      border: 1px solid #FCA5A5;
      color: #991B1B;
    }

    .alert-warning {
      background: var(--sti-gold-lt, #FEF9E7);
      border: 1px solid rgba(244, 169, 0, 0.4);
      color: #78350F;
    }

    .auth-footer {
      font-size: 0.8rem;
      color: var(--text-muted);
    }

    .auth-footer-nav {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
    }

    .auth-footer-icons {
      display: flex;
      gap: 16px;
    }

    .auth-footer-icon-link,
    .auth-footer-portal-link {
      color: var(--text-muted);
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: color 0.15s ease;
    }

    .auth-footer-icon-link:hover,
    .auth-footer-portal-link:hover {
      color: var(--text-main);
    }

    .login-policy-note {
      font-size: 0.75rem;
      color: var(--text-muted);
      margin-top: 16px;
      line-height: 1.45;
    }

    .login-policy-note a {
      color: var(--primary-color);
      text-decoration: none;
      margin-left: 4px;
      font-weight: 500;
    }

    .login-policy-note a:hover {
      text-decoration: underline;
    }

    /* Demo logins style */
    .demo-logins {
      margin-top: 22px;
      padding-top: 14px;
      border-top: 1px dashed var(--border-color);
      text-align: center;
    }

    .demo-logins p {
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      margin-bottom: 6px;
    }

    .demo-chip {
      display: inline-block;
      background: var(--bg-base, #F8FAFD);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-sm, 6px);
      padding: 4px 10px;
      font-size: 0.72rem;
      font-weight: 600;
      color: var(--sti-navy, #0A1628);
      cursor: pointer;
      margin: 2px;
      transition: all 0.2s ease;
    }

    .demo-chip:hover {
      border-color: var(--primary-color);
      background-color: var(--sti-blue-lt, #EBF5FB);
      color: var(--primary-color);
      transform: translateY(-1px);
    }
  </style>
</head>

<body>
  <div class="auth-page-body" data-label-id="0">
    <main class="auth-card auth-form-container">
      <header class="mb-4 text-center">
        <!-- Brand Title and Subtitle -->
        <h1 class="auth-brand mb-1"><span>STI</span>-ARMES</h1>
        <p class="auth-tagline mb-1 small">Activity Request, Monitoring, and Evaluation System</p>
      </header>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2 px-3 small mb-3"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($timeout): ?>
        <div class="alert alert-warning py-2 px-3 small mb-3">Session expired. Please log in again.</div>
      <?php endif; ?>

      <form novalidate="" class="" method="POST" id="loginForm">
        <!-- Email Input -->
        <div class="mb-2">
          <label class="fw-bold small mb-1 form-label" for="loginEmail">Email</label>
          <input autocomplete="email" type="email" id="loginEmail" name="email" class="form-control" placeholder="you@sti.edu" required value="">
        </div>

        <!-- Password Input -->
        <div class="mb-3">
          <label class="fw-bold small mb-1 form-label" for="loginPassword">Password</label>
          <div class="auth-password-wrapper position-relative">
            <input autocomplete="current-password" type="password" id="loginPassword" name="password" class="auth-password-input form-control" placeholder="••••••••" required value="">
            <button type="button" id="togglePasswordBtn" aria-label="Show password" aria-pressed="false" tabindex="-1" class="auth-password-toggle btn btn-link">
              <i aria-hidden="true" id="togglePasswordIcon" class="fa-solid fa-eye-slash"></i>
            </button>
          </div>
          <div class="d-flex justify-content-end mt-1">
            <a class="login-form-link" href="#">Forgot your password?</a>
          </div>
        </div>



        <!-- Sign In Button -->
        <button type="submit" aria-busy="false" class="auth-submit w-100 btn btn-primary">Sign In</button>
      </form>

      <!-- OAuth Separator -->
      <div class="d-flex align-items-center my-3">
        <hr class="flex-grow-1 my-0" style="border-color: #e5e7eb;">
        <span class="px-2 text-muted small" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">or</span>
        <hr class="flex-grow-1 my-0" style="border-color: #e5e7eb;">
      </div>

      <!-- Sign in with Microsoft Button -->
      <a href="<?= BASE_URL ?>/auth/microsoft-login.php" class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 py-2" id="btnMicrosoftLogin">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 21 21">
          <rect x="1" y="1" width="9" height="9" fill="#f25022"/>
          <rect x="11" y="1" width="9" height="9" fill="#7fba00"/>
          <rect x="1" y="11" width="9" height="9" fill="#00a4ef"/>
          <rect x="11" y="11" width="9" height="9" fill="#ffb900"/>
        </svg>
        Sign in with Microsoft
      </a>

      <!-- Quick Demo Login Section -->
      <div class="demo-logins">
        <p>Quick Demo Login</p>
        <span class="demo-chip" onclick="setLogin('faculty@sti.edu','password')">Faculty</span>
        <span class="demo-chip" onclick="setLogin('arjay@sti.edu','password')">Sir Ar-jay</span>
        <span class="demo-chip" onclick="setLogin('ian@sti.edu','password')">Sir Ian</span>
        <span class="demo-chip" onclick="setLogin('dean@sti.edu','password')">Dean</span>
      </div>
    </main>
  </div>

  <script>
    // Autofill helper for development and demo environments
    function setLogin(email, password) {
      document.getElementById('loginEmail').value = email;
      document.getElementById('loginPassword').value = password;
    }

    // Show/Hide password toggle logic
    const togglePasswordBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('loginPassword');
    const togglePasswordIcon = document.getElementById('togglePasswordIcon');

    if (togglePasswordBtn && passwordInput && togglePasswordIcon) {
      togglePasswordBtn.addEventListener('click', function () {
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);
        
        // Toggle icon class between eye and eye-slash
        if (type === 'password') {
          togglePasswordIcon.classList.remove('fa-eye');
          togglePasswordIcon.classList.add('fa-eye-slash');
          togglePasswordBtn.setAttribute('aria-pressed', 'false');
        } else {
          togglePasswordIcon.classList.remove('fa-eye-slash');
          togglePasswordIcon.classList.add('fa-eye');
          togglePasswordBtn.setAttribute('aria-pressed', 'true');
        }
      });
    }
  </script>
</body>

</html>