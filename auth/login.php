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
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: var(--sti-slate);
    }

    .login-wrap {
      display: grid;
      grid-template-columns: 1fr 1fr;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: var(--shadow-lg);
      max-width: 860px;
      width: 100%;
    }

    .login-left {
      background: linear-gradient(145deg, #0A1628, #0A1628);
      padding: 48px 40px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      color: #fff;
    }

    .login-left .brand {
      font-family: 'Syne', sans-serif;
      font-size: 1.6rem;
      font-weight: 800;
      line-height: 1.2;
    }

    .login-left .brand span {
      color: var(--sti-gold);
    }

    .login-left .tagline {
      font-size: .85rem;
      opacity: .7;
      margin-top: 8px;
    }

    .login-left .features {
      margin-top: auto;
    }

    .login-left .feat {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 14px;
      font-size: .82rem;
      opacity: .85;
    }

    .login-left .feat svg {
      width: 18px;
      height: 18px;
      opacity: .7;
    }

    .login-right {
      background: #fff;
      padding: 48px 40px;
    }

    .login-right h2 {
      font-family: 'Syne', sans-serif;
      font-size: 1.4rem;
      font-weight: 800;
      margin-bottom: 6px;
    }

    .login-right p {
      font-size: .82rem;
      color: var(--text-muted);
      margin-bottom: 28px;
    }

    .login-right .form-control {
      padding: 12px 14px;
    }

    .login-right .btn {
      width: 100%;
      justify-content: center;
      padding: 13px;
      font-size: .9rem;
      margin-top: 6px;
      background-color: #0A1628;
    }

    .demo-logins {
      margin-top: 24px;
      padding-top: 18px;
      border-top: 1px solid var(--border);
    }

    .demo-logins p {
      font-size: .72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      color: var(--text-muted);
      margin-bottom: 8px;
    }

    .demo-chip {
      display: inline-block;
      background: var(--bg-base);
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 5px 10px;
      font-size: .72rem;
      cursor: pointer;
      margin: 3px;
      transition: all .2s;
    }

    .demo-chip:hover {
      border-color: var(--sti-red);
      color: var(--sti-red);
    }
  </style>
</head>

<body>
  <div class="login-wrap">
    <div class="login-left">
      <div>
        <div class="brand">STI College<br><span>Marikina</span></div>
        <div class="tagline">Activity Request, Monitoring & Evaluation System</div>
      </div>
      <div class="features">
        <div class="feat">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Multi-stage approval workflows
        </div>
        <div class="feat">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
          Activity calendar & scheduling
        </div>
        <div class="feat">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
          KPI tracking & evaluation reports
        </div>
      </div>
    </div>
    <div class="login-right">
      <h2>Welcome Back</h2>
      <p>Sign in to access your dashboard</p>
      <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($timeout): ?><div class="alert alert-warning">Session expired. Please log in again.</div><?php endif; ?>
      <form method="POST">
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="you@sti.edu" required>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" placeholder="••••••••" required>
        </div>
        <button type="submit" class="btn btn-primary">Sign In</button>
      </form>
      <div class="demo-logins">
        <p>Quick Demo Login</p>
        <span class="demo-chip" onclick="setLogin('faculty@sti.edu','password')">Faculty</span>
        <span class="demo-chip" onclick="setLogin('arjay@sti.edu','password')">Sir Ar-jay</span>
        <span class="demo-chip" onclick="setLogin('ian@sti.edu','password')">Sir Ian</span>
        <span class="demo-chip" onclick="setLogin('dean@sti.edu','password')">Dean</span>
      </div>
    </div>
  </div>
  <script>
    function setLogin(e, p) {
      document.querySelector('[name=email]').value = e;
      document.querySelector('[name=password]').value = p;
    }
  </script>
</body>

</html>