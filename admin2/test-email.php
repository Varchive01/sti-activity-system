<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/EmailService.php';

requireRole('admin2'); // Restrict to admin2 or appropriate role
$user = currentUser();

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $testEmail = trim($_POST['test_email'] ?? '');
    
    if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        $emailService = new EmailService();
        $sent = $emailService->sendNotification(
            $testEmail,
            "Test User",
            "STI Activity System - SMTP Test",
            "Test Proposal",
            "Testing",
            "This is a test notification to verify that the Outlook SMTP configuration is working correctly.",
            BASE_URL,
            null,
            "test_email"
        );
        
        if ($sent) {
            $result = "Test email sent successfully to $testEmail. Please check your inbox (and spam folder).";
        } else {
            $error = "Failed to send email. Please check your .env configuration and the error logs.";
        }
    } else {
        $error = "Invalid email address.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test SMTP Configuration - STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
.container { max-width: 600px; margin: 40px auto; padding: 30px; background: #fff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
</style>
</head>
<body class="theme-admin2">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Test Email Configuration</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">
    <div class="container">
      <h2>Send Test Email</h2>
      <p class="text-muted">Use this form to verify that your Outlook SMTP settings in the <code>.env</code> file are configured correctly.</p>
      
      <?php if ($result): ?>
          <div class="alert alert-success"><?= htmlspecialchars($result) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label class="form-label">Recipient Email Address</label>
          <input type="email" name="test_email" class="form-control" placeholder="test@example.com" required value="<?= htmlspecialchars($_POST['test_email'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-primary">Send Test Email</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
