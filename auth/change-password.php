<?php
/**
 * auth/change-password.php
 *
 * Dedicated, forced first-login password change page.
 * Accessible only to authenticated users who have must_change_password = 1.
 * Requires only New Password and Confirm New Password per user specification.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

$isForced = !empty($_SESSION['must_change_password']);
$map = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean'];
$dir = $map[$_SESSION['user_role'] ?? 'faculty'] ?? 'faculty';
$dashboardUrl = BASE_URL . "/{$dir}/dashboard.php";

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid or expired security token. Please try again.';
    } else {
        $db = getDB();
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $currentPassword = $_POST['current_password'] ?? '';

        // If voluntary change, verify current password if submitted
        if (!$isForced && !empty($currentPassword)) {
            $stmtUser = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $stmtUser->execute([(int)$_SESSION['user_id']]);
            $currentHash = $stmtUser->fetchColumn();
            if (!$currentHash || !password_verify($currentPassword, $currentHash)) {
                $error = 'Your current password is incorrect. Please try again.';
            }
        }

        if (!$error) {
            if (strlen($newPassword) < 6) {
                $error = 'New password must be at least 6 characters long.';
            } elseif ($newPassword !== $confirmPassword) {
                $error = 'Passwords do not match. Please re-enter your password.';
            } else {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?');
                $stmt->execute([$hashed, (int)$_SESSION['user_id']]);

                // Clear must_change_password in active session
                $_SESSION['must_change_password'] = 0;

                header("Location: " . $dashboardUrl . "?password_changed=1");
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set Permanent Password – STI Activity System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    :root {
      --primary-color: var(--sti-blue, #0072CE);
      --primary-hover: var(--sti-blue-hover, #005FA3);
      --sti-navy: var(--sti-navy, #0A1628);
    }
    body {
      background: var(--sti-navy, #0A1628);
      font-family: 'Plus Jakarta Sans', sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0;
      padding: 24px;
    }
    .auth-card {
      background: #ffffff;
      border-radius: var(--radius-md, 12px);
      box-shadow: 0 20px 40px rgba(10, 22, 40, 0.25);
      border: 1px solid var(--border, #E2E8F0);
      width: 100%;
      max-width: 440px;
      padding: 36px 32px;
    }
    .auth-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--sti-blue-lt, #EBF5FB);
      color: var(--sti-blue, #0072CE);
      border: 1px solid rgba(0, 114, 206, 0.25);
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 700;
      padding: 4px 12px;
      margin-bottom: 14px;
    }
    .auth-title {
      font-family: 'Syne', sans-serif;
      font-size: 1.45rem;
      font-weight: 800;
      color: var(--sti-navy, #0A1628);
      margin-bottom: 6px;
      letter-spacing: -0.01em;
    }
    .auth-subtitle {
      font-size: 0.85rem;
      color: var(--text-muted, #718096);
      margin-bottom: 20px;
      line-height: 1.5;
    }
    .form-label {
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--sti-navy, #0A1628);
      margin-bottom: 6px;
    }
    .form-control {
      border-radius: var(--radius-sm, 6px);
      padding: 10px 14px;
      border: 1px solid var(--border, #E2E8F0);
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-size: 0.88rem;
      color: var(--text-main, #1A202C);
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(0, 114, 206, 0.15);
      outline: none;
    }
    .btn-submit {
      background: var(--primary-color);
      color: #ffffff;
      font-family: 'Plus Jakarta Sans', sans-serif;
      font-weight: 700;
      padding: 11px 16px;
      border-radius: var(--radius-sm, 6px);
      width: 100%;
      border: none;
      font-size: 0.92rem;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      box-shadow: 0 2px 6px rgba(0, 114, 206, 0.25);
      cursor: pointer;
      margin-top: 8px;
    }
    .btn-submit:hover {
      background: var(--primary-hover);
      color: #ffffff;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(0, 114, 206, 0.35);
    }
    .btn-submit:active {
      transform: translateY(0);
      box-shadow: 0 2px 4px rgba(0, 114, 206, 0.2);
    }
    .user-pill {
      background: var(--bg-base, #F8FAFD);
      border: 1px solid var(--border, #E2E8F0);
      border-radius: var(--radius-sm, 6px);
      padding: 10px 14px;
      margin-bottom: 20px;
      font-size: 0.82rem;
      color: var(--text-muted-dark, #4A5568);
    }
    .user-pill strong {
      color: var(--sti-navy, #0A1628);
    }
    .auth-cancel-link {
      font-size: 0.82rem;
      color: var(--text-muted, #718096);
      text-decoration: none;
      font-weight: 500;
      transition: color 0.15s ease;
    }
    .auth-cancel-link:hover {
      color: var(--sti-navy, #0A1628);
      text-decoration: underline;
    }
  </style>
</head>
<body>
  <div class="auth-card">
    <div class="auth-badge">
      <i class="fa-solid <?= $isForced ? 'fa-shield-halved' : 'fa-key' ?>"></i> <?= $isForced ? 'First Login Security' : 'Account Security' ?>
    </div>
    <h1 class="auth-title"><?= $isForced ? 'Set Permanent Password' : 'Change Account Password' ?></h1>
    <p class="auth-subtitle">
      <?= $isForced
        ? 'Welcome to the STI Activity Management System. You are logged in with a temporary password. Please choose a new, secure password to continue.'
        : 'Update your account password below. Choose a strong password of at least 6 characters.' ?>
    </p>

    <div class="user-pill">
      <div>Account: <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></strong></div>
      <div style="font-size:0.75rem; color:#64748b;"><?= htmlspecialchars($_SESSION['user_email'] ?? '') ?> &bull; Role: <?= htmlspecialchars(ucfirst($_SESSION['user_role'] ?? 'faculty')) ?></div>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger" style="font-size:0.85rem; padding:10px 14px; border-radius:var(--radius-sm, 6px); margin-bottom:18px;">
        <i class="fa-solid fa-circle-exclamation me-1"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>/auth/change-password.php" id="changePasswordForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">

      <?php if (!$isForced): ?>
      <div class="mb-3">
        <label for="current_password" class="form-label">Current Password</label>
        <input type="password" name="current_password" id="current_password" class="form-control" placeholder="Enter current password" autocomplete="current-password">
      </div>
      <?php endif; ?>

      <div class="mb-3">
        <label for="new_password" class="form-label">New Password</label>
        <input type="password" name="new_password" id="new_password" class="form-control" placeholder="At least 6 characters" minlength="6" required autocomplete="new-password">
      </div>

      <div class="mb-3">
        <label for="confirm_password" class="form-label">Confirm New Password</label>
        <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Re-type new password" minlength="6" required autocomplete="new-password">
      </div>

      <button type="submit" class="btn btn-submit" id="btnSubmitPassword">
        <?= $isForced ? 'Update Password &amp; Continue' : 'Save New Password' ?>
      </button>

      <div class="text-center mt-3">
        <?php if ($isForced): ?>
          <a href="<?= BASE_URL ?>/auth/logout.php" class="auth-cancel-link">
            <i class="fa-solid fa-arrow-right-from-bracket me-1"></i> Cancel and sign out
          </a>
        <?php else: ?>
          <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="auth-cancel-link">
            <i class="fa-solid fa-arrow-left me-1"></i> Cancel and return to dashboard
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <script>
    document.getElementById('changePasswordForm')?.addEventListener('submit', function(e) {
      const p1 = document.getElementById('new_password')?.value || '';
      const p2 = document.getElementById('confirm_password')?.value || '';
      if (p1.length < 6) {
        alert('Password must be at least 6 characters long.');
        e.preventDefault();
        return;
      }
      if (p1 !== p2) {
        alert('Passwords do not match. Please re-enter your password.');
        e.preventDefault();
      }
    });
  </script>
</body>
</html>
