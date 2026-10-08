<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin2');
$user=$db=null; $user=currentUser(); $db=getDB();
$search=$filterStat=''; $page=1; $perPage=15; $offset=0;
if(isset($_GET['q']))      $search    =sanitize($_GET['q']);
if(isset($_GET['status'])) $filterStat=sanitize($_GET['status']);
if(isset($_GET['page']))   $page      =max(1,(int)$_GET['page']);
$offset=($page-1)*$perPage;
$where="1=1"; $params=[];
if($search)     {$where.=" AND a.title LIKE ?"; $params[]="%$search%";}
if($filterStat) {$where.=" AND a.status=?";     $params[]=$filterStat;}
$total=$db->prepare("SELECT COUNT(*) FROM activities a WHERE $where"); $total->execute($params); $totalCount=$total->fetchColumn(); $totalPages=ceil($totalCount/$perPage);
$acts=$db->prepare("SELECT a.*,u.name as fn FROM activities a JOIN users u ON a.faculty_id=u.id WHERE $where ORDER BY a.updated_at DESC LIMIT $perPage OFFSET $offset"); $acts->execute($params); $activities=$acts->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>All Activities – STI</title><link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
</head>
<body class="theme-ian">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar"><div class="page-title">All Activities</div>
    <div class="topbar-right">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">
    <form method="GET" class="filters">
      <input type="text" name="q" class="form-control" placeholder="Search…" value="<?= htmlspecialchars($search) ?>" style="max-width:280px;">
      <select name="status" class="form-control" style="max-width:200px;"><option value="">All Statuses</option>
        <?php foreach(['draft','submitted','under_review','endorsed','pending_final_approval','returned_for_revision','resubmitted','approved','completed','rejected'] as $s): ?>
        <option value="<?= $s ?>" <?= $filterStat===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Filter</button>
      <?php if($search||$filterStat): ?><a href="activities.php" class="btn btn-outline">Clear</a><?php endif; ?>
      <span class="text-sm text-muted" style="margin-left:auto;"><?= $totalCount ?> total</span>
    </form>
    <div class="card"><div class="card-body" style="padding:0;"><div class="table-wrap">
      <table><thead><tr><th>Title</th><th>Faculty</th><th>Source</th><th>Date</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php if(empty($activities)): ?><tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">No activities found.</td></tr>
      <?php else: foreach($activities as $a): ?>
      <tr>
        <td><strong><?= htmlspecialchars($a['title']) ?></strong></td>
        <td><?= htmlspecialchars($a['fn']) ?></td>
        <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$a['source'])) ?></span></td>
        <td><?= $a['event_date']?date('M j, Y',strtotime($a['event_date'])):'—' ?></td>
        <td><?= getStatusBadge($a['status']) ?></td>
        <td>
          <a href="<?= BASE_URL ?>/admin2/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a>
          <?php if(in_array($a['status'],['endorsed','pending_final_approval','resubmitted'])): ?>
          <a href="<?= BASE_URL ?>/admin2/review.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">Review</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody></table>
    </div></div></div>
  </div>
</div></body></html>
