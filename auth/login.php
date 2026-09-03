<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

startSession();

// Already logged in? Redirect.
if (!empty($_SESSION['user_id'])) {
  $role = $_SESSION['user_role'];
  $map  = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean'];
  $dir  = $map[$role] ?? 'faculty';
  header("Location: " . BASE_URL . "/{$dir}/dashboard.php");
  exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $user = login(trim($_POST['email'] ?? ''), $_POST['password'] ?? '');
  if ($user) {
    $map = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean'];
    $dir = $map[$user['role']] ?? 'faculty';
    header("Location: " . BASE_URL . "/{$dir}/dashboard.php");
    exit;
  }
  $error = 'Invalid email or password.';
}
$timeout = isset($_GET['timeout']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login – STI Marikina Activity System</title>
  <!-- Load Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
  <!-- Load Bootstrap 5.3.3 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Load Font Awesome 6.4.0 -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <!-- Load App Custom CSS -->
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  
  <style>
    :root {
      --primary-color: #1976d2;
      --primary-hover: #1565c0;
      --bg-color: #f3f4f6;
      --card-bg: #ffffff;
      --text-main: #1f2937;
      --text-muted: #6b7280;
      --border-color: #e5e7eb;
      --radius: 16px;
      --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.08), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

    body {
      background: var(--sti-navy, #0A1628);
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
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
      max-width: 370px;
    }

    .auth-card {
      background: var(--card-bg);
      border-radius: var(--radius);
      box-shadow: var(--shadow-lg);
      padding: 44px 30px;
      border: 1px solid var(--border-color);
    }

    .auth-brand {
      font-family: 'Outfit', sans-serif;
      font-size: 2.2rem;
      font-weight: 800;
      letter-spacing: -1px;
      color: #0f172a;
    }

    .auth-brand span {
      color: var(--sti-gold, #F4A900);
    }

    .auth-tagline {
      color: var(--text-muted);
      font-size: 0.8rem;
      font-weight: 500;
      line-height: 1.4;
    }

    .form-label {
      color: var(--text-main);
      font-weight: 600;
      font-size: 0.85rem;
    }

    .form-control {
      border: 1px solid #d1d5db;
      border-radius: 8px;
      padding: 9px 12px;
      font-size: 0.9rem;
      transition: all 0.2s;
    }

    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(25, 118, 210, 0.15);
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
      padding: 10px;
      font-size: 1.05rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color 0.15s;
    }

    .auth-password-toggle:hover {
      color: var(--text-main);
    }

    .login-form-link {
      font-size: 0.85rem;
      color: var(--primary-color);
      text-decoration: none;
      font-weight: 500;
      transition: color 0.15s;
    }

    .login-form-link:hover {
      color: var(--primary-hover);
      text-decoration: underline;
    }

    .auth-submit {
      background-color: var(--sti-slate, #1E2D45);
      border: none;
      border-radius: 8px;
      padding: 12px;
      font-size: 0.975rem;
      font-weight: 600;
      color: #ffffff;
      transition: all 0.2s;
      display: flex;
      align-items: center;
      justify-content: center;
      text-align: center;
      width: 100%;
    }

    .auth-submit:hover {
      background-color: #121C2B;
      color: #ffffff;
      transform: translateY(-1px);
    }

    .auth-submit:active {
      transform: translateY(0);
    }

    .auth-divider {
      display: flex;
      align-items: center;
      text-align: center;
      color: var(--text-muted);
      font-size: 0.775rem;
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
      border-radius: 8px;
      padding: 10px;
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-main);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: all 0.2s;
    }

    .auth-oauth-btn:hover {
      background: #f9fafb;
      border-color: #d1d5db;
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
      transition: color 0.15s;
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
      font-size: 0.65rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      margin-bottom: 4px;
    }

    .demo-chip {
      display: inline-block;
      background: #f3f4f6;
      border: 1px solid var(--border-color);
      border-radius: 6px;
      padding: 4px 8px;
      font-size: 0.7rem;
      font-weight: 600;
      color: var(--text-main);
      cursor: pointer;
      margin: 2px;
      transition: all 0.2s;
    }

    .demo-chip:hover {
      border-color: var(--primary-color);
      background-color: #eff6ff;
      color: var(--primary-color);
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

      <form novalidate="" class="" method="POST">
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