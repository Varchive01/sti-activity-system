<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean');
$user = currentUser();
$db   = getDB();

// Fetch all activities relevant to this role (Returned for Revision or Rejected)
$where = "WHERE a.status IN ('returned_for_revision', 'rejected')";

$activities = $db->query("SELECT a.*,u.name as faculty_name FROM activities a JOIN users u ON a.faculty_id=u.id $where ORDER BY a.updated_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Returned / Rejected – STI Activity System</title>
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
  body, body *, h1, h2, h3, h4, h5, h6, .page-title, .card-header h2, .btn, .badge {
    font-family: 'Plus Jakarta Sans', sans-serif !important;
  }
</style>
</head>
<body class="theme-dean">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Returned / Rejected</div>
  
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">
    <div class="card">
      <div class="card-header"><h2>Returned &amp; Rejected Proposals</h2></div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table class="activity-table">
            <thead><tr><th>Title</th><th>Faculty</th><th style="width:130px;">Date</th><th style="width:145px;">Status</th><th style="width:100px;text-align:right;">Actions</th></tr></thead>
            <tbody>
            <?php if (empty($activities)): ?>
            <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--text-muted);">No records found.</td></tr>
            <?php else: foreach ($activities as $a): ?>
            <tr>
              <td><strong><a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $a['id'] ?>" style="color:var(--text-main);text-decoration:none;"><?= htmlspecialchars($a['title']) ?></a></strong></td>
              <td><?= htmlspecialchars($a['faculty_name']) ?></td>
              <td><?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?></td>
              <td><?= getStatusBadge($a['status']) ?></td>
              <td style="text-align:right;"><a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
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
