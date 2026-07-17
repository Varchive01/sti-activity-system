<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');
$user = currentUser();
$db   = getDB();

$search     = sanitize($_GET['q']       ?? '');
$filterStat = sanitize($_GET['status']  ?? '');
$page       = max(1,(int)($_GET['page'] ?? 1));
$perPage    = 10;
$offset     = ($page-1)*$perPage;

$where  = "a.faculty_id=?";
$params = [$user['id']];
if ($search)     { $where .= " AND a.title LIKE ?"; $params[] = "%$search%"; }
if ($filterStat) { $where .= " AND a.status=?";     $params[] = $filterStat; }

$total = $db->prepare("SELECT COUNT(*) FROM activities a WHERE $where");
$total->execute($params); $totalCount = $total->fetchColumn();
$totalPages = ceil($totalCount/$perPage);

$acts = $db->prepare("SELECT a.* FROM activities a WHERE $where ORDER BY a.updated_at DESC LIMIT $perPage OFFSET $offset");
$acts->execute($params);
$activities = $acts->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Activities – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
.filters{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;align-items:center;}
.pagination{display:flex;gap:6px;margin-top:20px;justify-content:center;}
.pagination a,.pagination span{padding:7px 13px;border-radius:7px;font-size:.78rem;font-weight:600;border:1px solid var(--border);text-decoration:none;color:var(--text-main);}
.pagination a:hover{border-color:var(--accent);color:var(--accent);}
.pagination .active{background:var(--accent);color:#fff;border-color:var(--accent);}
</style>
</head>
<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">My Activities</div>
    <div class="topbar-right">
      <a href="<?= BASE_URL ?>/faculty/proposal-create.php" class="btn btn-primary btn-sm">+ New Proposal</a>
    </div>
  </header>
  <div class="content">

    <form method="GET" class="filters">
      <input type="text" name="q" class="form-control" placeholder="Search activities…" value="<?= htmlspecialchars($search) ?>" style="max-width:280px;">
      <select name="status" class="form-control" style="max-width:180px;">
        <option value="">All Statuses</option>
        <?php foreach(['draft','submitted','under_review','endorsed','pending_final_approval','returned_for_revision','approved','completed','rejected'] as $s): ?>
        <option value="<?= $s ?>" <?= $filterStat===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-primary">Filter</button>
      <?php if ($search||$filterStat): ?><a href="activities.php" class="btn btn-outline">Clear</a><?php endif; ?>
      <span class="text-sm text-muted" style="margin-left:auto;"><?= $totalCount ?> activities found</span>
    </form>

    <?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">✓ Proposal saved successfully!</div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table>
            <thead><tr><th>Title</th><th>Date</th><th>Venue</th><th>Source</th><th>Status</th><th>Last Updated</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if (empty($activities)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">
              <?= $search||$filterStat ? 'No activities match your search.' : 'No activities yet.' ?>
              <?php if (!$search && !$filterStat): ?><br><a href="<?= BASE_URL ?>/faculty/proposal-create.php" style="color:var(--accent);">Create your first proposal →</a><?php endif; ?>
            </td></tr>
            <?php else: foreach($activities as $a): ?>
            <tr>
              <td>
                <a href="<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $a['id'] ?>" style="font-weight:600;color:var(--text-main);text-decoration:none;">
                  <?= htmlspecialchars($a['title']) ?>
                </a>
                <?php if ($a['revision_notes']): ?><br><span class="text-sm" style="color:var(--warning);">⚠ Revision notes</span><?php endif; ?>
              </td>
              <td><?= $a['event_date'] ? date('M j, Y',strtotime($a['event_date'])) : '—' ?></td>
              <td><?= htmlspecialchars($a['venue']??'—') ?></td>
              <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$a['source'])) ?></span></td>
              <td><?= getStatusBadge($a['status']) ?></td>
              <td class="text-muted text-sm"><?= date('M j, Y',strtotime($a['updated_at'])) ?></td>
              <td>
                <a href="<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">View</a>
                <?php if (in_array($a['status'],['draft','returned_for_revision'])): ?>
                <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= $a['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                <?php endif; ?>
                <?php if ($a['status']==='approved'): ?>
                <a href="<?= BASE_URL ?>/faculty/post-event.php?activity_id=<?= $a['id'] ?>" class="btn btn-success btn-sm">Post-Event</a>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>">← Prev</a><?php endif; ?>
      <?php for ($p=1;$p<=$totalPages;$p++): ?>
      <?php if (abs($p-$page)<=2||$p===1||$p===$totalPages): ?>
      <<?= $p===$page?'span class="active"':'a href="?page='.$p.'&q='.urlencode($search).'&status='.urlencode($filterStat).'"' ?>><?= $p ?></<?= $p===$page?'span':'a' ?>>
      <?php elseif (abs($p-$page)===3): echo '<span>…</span>'; endif; ?>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?><a href="?page=<?= $page+1 ?>&q=<?= urlencode($search) ?>&status=<?= urlencode($filterStat) ?>">Next →</a><?php endif; ?>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
