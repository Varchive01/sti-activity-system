<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty', 'program_head');
$user = currentUser();
$db   = getDB();

// Fetch all activities relevant to this role
$where = '';
if ($user['role'] === 'faculty') $where = "WHERE a.faculty_id = {$user['id']}";
elseif ($user['role'] === 'program_head') { $deptEsc = $db->quote($user['dept'] ?? ''); $where = "WHERE (u.department = $deptEsc OR a.faculty_id = {$user['id']})"; }
elseif ($user['role'] === 'admin1') $where = "WHERE a.source = 'student_org'";

$activities = $db->query("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id $where ORDER BY a.updated_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Generate Report – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
/* ── Typography Standardization: Strict Plus Jakarta Sans ── */
body, body *,
h1, h2, h3, h4, h5, h6,
.page-title,
.topbar .page-title,
.card-header h2,
.card-header h3,
th, td, table th, table td,
.badge, .btn,
.topbar-user-name, .topbar-user-role,
label, .form-label, input, button, select, textarea {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
}

.page-title,
.topbar .page-title {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 1.15rem !important;
  font-weight: 800 !important;
  color: #0A1628 !important;
  line-height: 1.2 !important;
  letter-spacing: -0.02em !important;
}
[data-theme="dark"] .page-title,
[data-theme="dark"] .topbar .page-title {
  color: #F8FAFC !important;
}
</style>
</head>
<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Generate Report</div>
  
    <div class="topbar-right">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">
    <div class="card">
      <div class="card-header"><h2>Generate Report</h2></div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Title</th><th>Faculty</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($activities)): ?>
            <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted);">No records found.</td></tr>
            <?php else: foreach ($activities as $a): ?>
            <tr>
              <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
              <td><?= htmlspecialchars($a['faculty_name']) ?></td>
              <td><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></td>
              <td><?= getStatusBadge($a['status']) ?></td>
              <td><a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
</body>
</html>
