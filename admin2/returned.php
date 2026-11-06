<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin2');
$db   = getDB();
$user = currentUser();
$acts = $db->prepare("
    SELECT a.*, u.name as fn, MAX(al.notes) as review_notes, MAX(al.acted_at) as last_acted_at
    FROM activities a
    JOIN users u ON a.faculty_id = u.id
    JOIN approval_logs al ON a.id = al.activity_id
    WHERE al.reviewer_id = ? AND al.action IN ('returned', 'rejected', 'returned_for_revision')
    GROUP BY a.id, u.name
    ORDER BY last_acted_at DESC
");
$acts->execute([$user['id']]);
$activities = $acts->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head>
<?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"><meta charset="UTF-8">
<title>Returned/Rejected – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css"></head>
<body class="theme-ian">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar"><div class="page-title">Returned &amp; Rejected</div>
    <div class="topbar-right">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">
    <div class="card"><div class="card-body" style="padding:0;"><div class="table-wrap">
    <table><thead><tr><th>Title</th><th>Faculty</th><th>Action</th><th>Notes</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php if(empty($activities)): ?>
    <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No returned or rejected activities.</td></tr>
    <?php else: foreach($activities as $a): ?>
    <tr>
      <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
      <td><?= htmlspecialchars($a['fn']) ?></td>
      <td><?= getStatusBadge($a['status']) ?></td>
      <td class="text-sm text-muted"><?= htmlspecialchars(mb_strimwidth($a['review_notes']??'—',0,80,'…')) ?></td>
      <td><?= date('M j, Y', strtotime($a['acted_at'])) ?></td>
      <td><a href="<?= BASE_URL ?>/admin2/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody></table>
    </div></div></div>
  </div>
</div></body></html>
