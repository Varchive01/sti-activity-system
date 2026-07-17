<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean');
$db=getDB();
$search=sanitize($_GET['q']??''); $filterStat=sanitize($_GET['status']??''); $filterSrc=sanitize($_GET['source']??'');
$page=max(1,(int)($_GET['page']??1)); $perPage=15; $offset=($page-1)*$perPage;
$where="1=1"; $params=[];
if($search)     {$where.=" AND a.title LIKE ?";  $params[]="%$search%";}
if($filterStat) {$where.=" AND a.status=?";       $params[]=$filterStat;}
if($filterSrc)  {$where.=" AND a.source=?";       $params[]=$filterSrc;}
$total=$db->prepare("SELECT COUNT(*) FROM activities a WHERE $where"); $total->execute($params); $totalCount=$total->fetchColumn(); $totalPages=ceil($totalCount/$perPage);
$acts=$db->prepare("SELECT a.*,u.name as fn FROM activities a JOIN users u ON a.faculty_id=u.id WHERE $where ORDER BY a.updated_at DESC LIMIT $perPage OFFSET $offset"); $acts->execute($params); $activities=$acts->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>All Activities – STI</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center;}.pagination{display:flex;gap:6px;margin-top:20px;justify-content:center;}.pagination a,.pagination span{padding:7px 13px;border-radius:7px;font-size:.78rem;font-weight:600;border:1px solid var(--border);text-decoration:none;color:var(--text-main);}.pagination a:hover{border-color:var(--accent);color:var(--accent);}.pagination .active{background:var(--accent);color:#fff;border-color:var(--accent);}</style>
</head>
<body class="theme-dean">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar"><div class="page-title">All Activities — System View</div>
    <div class="topbar-right"><a href="<?= BASE_URL ?>/dean/generate-report.php" class="btn btn-primary btn-sm">Generate Report</a></div>
  </header>
  <div class="content">
    <form method="GET" class="filters">
      <input type="text" name="q" class="form-control" placeholder="Search title…" value="<?= htmlspecialchars($search) ?>" style="max-width:260px;">
      <select name="status" class="form-control" style="max-width:170px;"><option value="">All Statuses</option>
        <?php foreach(['draft','submitted','under_review','endorsed','pending_final_approval','returned_for_revision','approved','completed','rejected'] as $s): ?>
        <option value="<?= $s ?>" <?= $filterStat===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option><?php endforeach; ?>
      </select>
      <select name="source" class="form-control" style="max-width:170px;"><option value="">All Sources</option>
        <option value="student_org" <?= $filterSrc==='student_org'?'selected':'' ?>>Student Org</option>
        <option value="faculty" <?= $filterSrc==='faculty'?'selected':'' ?>>Faculty</option>
      </select>
      <button type="submit" class="btn btn-primary">Filter</button>
      <?php if($search||$filterStat||$filterSrc): ?><a href="activities.php" class="btn btn-outline">Clear</a><?php endif; ?>
      <span class="text-sm text-muted" style="margin-left:auto;"><?= $totalCount ?> total</span>
    </form>
    <div class="card"><div class="card-body" style="padding:0;"><div class="table-wrap">
      <table><thead><tr><th>#</th><th>Title</th><th>Faculty</th><th>Source</th><th>Date</th><th>Status</th><th>KPI</th><th></th></tr></thead>
      <tbody>
      <?php if(empty($activities)): ?><tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No activities found.</td></tr>
      <?php else: $i=($page-1)*$perPage; foreach($activities as $a):
        $kpi=$db->prepare("SELECT AVG(rating) as avg FROM kpi_evaluations WHERE activity_id=?"); $kpi->execute([$a['id']]); $kpiVal=$kpi->fetchColumn();
        $i++;
      ?>
      <tr>
        <td class="text-muted"><?= $i ?></td>
        <td><strong><a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $a['id'] ?>" style="color:var(--text-main);text-decoration:none;"><?= htmlspecialchars($a['title']) ?></a></strong></td>
        <td><?= htmlspecialchars($a['fn']) ?></td>
        <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$a['source'])) ?></span></td>
        <td><?= $a['event_date']?date('M j, Y',strtotime($a['event_date'])):'—' ?></td>
        <td><?= getStatusBadge($a['status']) ?></td>
        <td><?= $kpiVal?'<strong>'.number_format($kpiVal,1).'</strong>/4':'—' ?></td>
        <td>
          <?php if ($a['status'] === 'pending_final_approval'): ?>
            <a href="<?= BASE_URL ?>/dean/review.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">Review</a>
          <?php else: ?>
            <a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody></table>
    </div></div></div>
    <?php if($totalPages>1): ?>
    <div class="pagination">
      <?php if($page>1): ?><a href="?page=<?= $page-1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&source=<?= urlencode($filterSrc) ?>">← Prev</a><?php endif; ?>
      <?php for($p=1;$p<=$totalPages;$p++): if(abs($p-$page)<=2||$p===1||$p===$totalPages): ?>
      <<?= $p===$page?'span class="active"':'a href="?page='.$p.'&q='.urlencode($search).'&status='.urlencode($filterStat).'&source='.urlencode($filterSrc).'"' ?>><?= $p ?></<?= $p===$page?'span':'a' ?>>
      <?php elseif(abs($p-$page)===3): echo '<span>…</span>'; endif; endfor; ?>
      <?php if($page<$totalPages): ?><a href="?page=<?= $page+1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>&source=<?= urlencode($filterSrc) ?>">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div></body></html>
