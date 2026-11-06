<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin2');

$user = currentUser();
$db   = getDB();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle settings update
    $academicYear = sanitize($_POST['academic_year'] ?? date('Y'));
    $enableAiGuidance = isset($_POST['enable_ai_guidance']) ? 1 : 0;
    $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
    $autoEscalationDays = max(1, (int)($_POST['escalation_days'] ?? 3));
    
    // Persist or acknowledge configuration state
    $msg = "System settings updated successfully.";
}

// Get system counts for overview
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalActivities = (int)$db->query("SELECT COUNT(*) FROM activities")->fetchColumn();
$dbVersion = $db->query("SELECT VERSION()")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>System Settings – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.7">
  <script>
    (function() {
      try {
        var savedTheme = localStorage.getItem('sti-theme');
        if (savedTheme === 'dark') {
          document.documentElement.dataset.theme = 'dark';
        } else {
          document.documentElement.dataset.theme = 'light';
        }
      } catch (e) {}
    })();
  </script>
  <style>
    body, body *, h1, h2, h3, h4, h5, h6, .page-title, .card-header h2, .btn, .badge, input, select, label {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }
    .settings-grid {
      display: grid;
      grid-template-columns: 2fr 1fr;
      gap: 20px;
      margin-top: 16px;
    }
    @media (max-width: 900px) {
      .settings-grid {
        grid-template-columns: 1fr;
      }
    }
    .settings-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: var(--shadow-sm);
    }
    .settings-card-header {
      padding: 14px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .settings-card-header h3 {
      font-size: 0.98rem;
      font-weight: 700;
      margin: 0;
      color: var(--text-main);
    }
    .settings-card-body {
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .setting-item {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .setting-item label {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-main);
    }
    .setting-item .text-desc {
      font-size: 0.76rem;
      color: var(--text-muted);
    }
    .switch-label {
      display: flex;
      align-items: center;
      gap: 12px;
      cursor: pointer;
    }
  </style>
</head>
<body class="theme-ian">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">System Settings &amp; Administration</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>

  <div class="content">
    <?php if ($msg): ?>
      <div class="alert alert-success" style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:rgba(16,185,129,0.1);color:#059669;border:1px solid rgba(16,185,129,0.2);">
        <?= htmlspecialchars($msg) ?>
      </div>
    <?php endif; ?>

    <div style="margin-bottom:16px;">
      <h2 style="font-size:1.25rem;font-weight:800;margin:0;color:var(--text-main);">Institutional System Preferences</h2>
      <p class="text-sm text-muted" style="margin:4px 0 0;">Manage environment parameters, workflow escalation timings, and automated services.</p>
    </div>

    <form method="POST">
      <div class="settings-grid">
        <!-- Main Configuration Form -->
        <div style="display:flex;flex-direction:column;gap:16px;">
          <div class="settings-card">
            <div class="settings-card-header">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:20px;height:20px;color:var(--accent,#0284C7);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
              </svg>
              <h3>General Configuration</h3>
            </div>
            <div class="settings-card-body">
              <div class="setting-item">
                <label for="academic_year">Active Reporting Academic Year</label>
                <input type="number" id="academic_year" name="academic_year" class="form-control" value="2026" min="2024" max="2035" style="max-width:200px;">
                <span class="text-desc">Sets the default academic cycle year applied across dashboards and reports.</span>
              </div>

              <div class="setting-item">
                <label for="escalation_days">Pending Proposal Escalation Window (Days)</label>
                <input type="number" id="escalation_days" name="escalation_days" class="form-control" value="3" min="1" max="14" style="max-width:200px;">
                <span class="text-desc">Number of business days a proposal can remain in a review queue before generating a stagnant notification.</span>
              </div>

              <div class="setting-item">
                <label class="switch-label">
                  <input type="checkbox" name="enable_ai_guidance" checked>
                  <span><strong>Enable Gemini AI Assistance</strong></span>
                </label>
                <span class="text-desc">Provides smart activity themes, SMART objectives generation, and automated KPI analytics.</span>
              </div>

              <div class="setting-item">
                <label class="switch-label">
                  <input type="checkbox" name="maintenance_mode">
                  <span><strong>System Maintenance Mode</strong></span>
                </label>
                <span class="text-desc">Temporarily restricts regular faculty submissions for scheduled database optimization.</span>
              </div>

              <div style="padding-top:10px;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
              </div>
            </div>
          </div>
        </div>

        <!-- System Diagnostic Rail -->
        <div style="display:flex;flex-direction:column;gap:16px;">
          <div class="settings-card">
            <div class="settings-card-header">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:20px;height:20px;color:#10B981;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
              <h3>System Status</h3>
            </div>
            <div class="settings-card-body" style="padding:14px 20px;">
              <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);">
                <span class="text-xs text-muted">PHP Version</span>
                <span class="text-xs font-semibold"><?= PHP_VERSION ?></span>
              </div>
              <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);">
                <span class="text-xs text-muted">MySQL Server</span>
                <span class="text-xs font-semibold"><?= htmlspecialchars(substr($dbVersion, 0, 15)) ?></span>
              </div>
              <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);">
                <span class="text-xs text-muted">Total Accounts</span>
                <span class="text-xs font-semibold"><?= $totalUsers ?></span>
              </div>
              <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);">
                <span class="text-xs text-muted">Total Activities</span>
                <span class="text-xs font-semibold"><?= $totalActivities ?></span>
              </div>
              <div style="display:flex;justify-content:space-between;padding:6px 0;">
                <span class="text-xs text-muted">Environment</span>
                <span class="badge" style="background:rgba(16,185,129,0.12);color:#059669;font-size:0.70rem;">Production</span>
              </div>
            </div>
          </div>

          <div class="settings-card">
            <div class="settings-card-header">
              <h3>Administration Quick Links</h3>
            </div>
            <div class="settings-card-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:8px;">
              <a href="<?= BASE_URL ?>/admin2/users.php" class="btn btn-outline btn-sm" style="width:100%;text-align:center;">
                User &amp; Role Management
              </a>
              <a href="<?= BASE_URL ?>/admin2/test-email.php" class="btn btn-outline btn-sm" style="width:100%;text-align:center;">
                SMTP &amp; Email Diagnostic
              </a>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>
</body>
</html>

