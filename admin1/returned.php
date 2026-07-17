<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin1');
$db=getDB(); $user=currentUser();
$acts=$db->prepare("SELECT a.*,u.name as fn FROM activities a JOIN users u ON a.faculty_id=u.id JOIN approval_logs al ON a.id=al.activity_id WHERE a.source='student_org' AND al.reviewer_id=? AND al.action IN ('returned','rejected') ORDER BY al.acted_at DESC");
$acts->execute([$user['id']]); $activities=$acts->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Returned/Rejected – STI</title><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css"></head>
<body class="theme-arjay">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar"><div class="page-title">Returned &amp; Rejected</div></header>
  <div class="content"><div class="card"><div class="card-body" style="padding:0;"><div class="table-wrap">
    <table><thead><tr><th>Title</th><th>Faculty</th><th>Action</th><th>Notes</th><th></th></tr></thead>
    <tbody>
    <?php if(empty($activities)): ?><tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">No returned/rejected activities.</td></tr>
    <?php else: foreach($activities as $a): ?>
    <tr><td><strong><?= htmlspecialchars($a['title']) ?></strong></td><td><?= htmlspecialchars($a['fn']) ?></td>
    <td><?= getStatusBadge($a['status']) ?></td>
    <td class="text-sm text-muted"><?= htmlspecialchars(mb_strimwidth($a['revision_notes']??'—',0,80,'…')) ?></td>
    <td><a href="<?= BASE_URL ?>/admin1/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a></td></tr>
    <?php endforeach; endif; ?>
    </tbody></table>
  </div></div></div></div>
</div></body></html>
