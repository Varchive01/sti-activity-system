<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin1');
$db = getDB();
$user = currentUser();
$acts = $db->prepare("
  SELECT a.*, u.name as fn 
  FROM activities a 
  JOIN users u ON a.faculty_id = u.id 
  JOIN approval_logs al ON a.id = al.activity_id 
  WHERE a.source = 'student_org' 
    AND al.reviewer_id = ? 
    AND al.action IN ('returned', 'rejected') 
  ORDER BY al.acted_at DESC
");
$acts->execute([$user['id']]);
$activities = $acts->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Returned/Rejected – STI Activity System</title>
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
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.7">
</head>

<body class="theme-arjay">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Returned &amp; Rejected</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>
    <div class="content">
      <div class="card">
        <div class="card-header">
          <h2>↩ Returned &amp; Rejected Activities</h2>
        </div>
        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table class="activity-table">
              <thead>
                <tr>
                  <th style="width:36%;">Title</th>
                  <th style="width:22%;">Faculty</th>
                  <th style="width:14%;">Status</th>
                  <th style="width:20%;">Notes</th>
                  <th style="width:8%;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">No returned or rejected activities.</td>
                  </tr>
                <?php else: foreach ($activities as $a): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
                    <td><?= htmlspecialchars($a['fn']) ?></td>
                    <td><?= getStatusBadge($a['status']) ?></td>
                    <td class="text-sm text-muted"><?= htmlspecialchars(mb_strimwidth($a['revision_notes'] ?? '—', 0, 80, '…')) ?></td>
                    <td style="text-align:right;"><a href="<?= BASE_URL ?>/admin1/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
                  </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>

</html>
