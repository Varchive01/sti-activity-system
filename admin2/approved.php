<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin2');
$user = currentUser();
$db = getDB();
$acts = $db->prepare("
    SELECT DISTINCT a.*, u.name as fn FROM activities a
    JOIN users u ON a.faculty_id=u.id
    JOIN approval_logs al ON a.id = al.activity_id
    WHERE al.reviewer_id = ?
    ORDER BY a.updated_at DESC
");
$acts->execute([$user['id']]);
$acts = $acts->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Approved – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css"></head>
<body class="theme-ian">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar"><div class="page-title">Forwarded &amp; Approved</div>
    <div class="topbar-right">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">
    <div class="card"><div class="card-body" style="padding:0;"><div class="table-wrap">
    <table><thead><tr><th>Title</th><th>Faculty</th><th>Source</th><th>Date</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if(empty($acts)): ?>
    <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No approved activities yet.</td></tr>
    <?php else: foreach($acts as $a): ?>
    <tr>
      <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
      <td><?= htmlspecialchars($a['fn']) ?></td>
      <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$a['source'])) ?></span></td>
      <td><?= $a['event_date'] ? date('M j, Y',strtotime($a['event_date'])) : '—' ?></td>
      <td><?= getStatusBadge($a['status']) ?></td>
      <td><a href="<?= BASE_URL ?>/admin2/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody></table>
    </div></div></div>
  </div>
</div></body></html>
