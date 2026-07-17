<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin1');
$user = currentUser();
$db   = getDB();

$search = sanitize($_GET['q'] ?? '');
$where  = "a.source='student_org' AND a.status IN ('under_review','resubmitted')";
$params = [];
if ($search) {
    $where .= " AND (a.title LIKE ? OR u.name LIKE ?)";
    $params = ["%$search%", "%$search%"];
}

$stmt = $db->prepare("
    SELECT a.*, u.name as faculty_name, u.email as faculty_email FROM activities a
    JOIN users u ON a.faculty_id=u.id
    WHERE $where
    ORDER BY a.submitted_at ASC
");
$stmt->execute($params);
$pending = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Pending Reviews – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
.proposal-row{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:18px 20px;margin-bottom:12px;display:grid;grid-template-columns:1fr auto;gap:16px;align-items:center;transition:box-shadow .2s;}
.proposal-row:hover{box-shadow:var(--shadow);}
.proposal-row .title{font-weight:700;font-size:.92rem;margin-bottom:4px;}
.proposal-row .meta{font-size:.76rem;color:var(--text-muted);display:flex;gap:14px;flex-wrap:wrap;}
.meta-pill{display:flex;align-items:center;gap:4px;}
.days-badge{background:#FEF3C7;color:#B45309;border-radius:6px;padding:4px 10px;font-size:.72rem;font-weight:700;}
.days-badge.urgent{background:#FEE2E2;color:#B91C1C;}
</style>
</head>
<body class="theme-arjay">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Pending Review Queue</div>
    <div class="topbar-right">
      <span class="badge badge-warning" style="font-size:.8rem;padding:6px 12px;"><?= count($pending) ?> awaiting review</span>
    </div>
  </header>
  <div class="content">

    <!-- Search -->
    <form method="GET" style="margin-bottom:20px;">
      <div style="display:flex;gap:10px;">
        <input type="text" name="q" class="form-control" placeholder="Search by title or faculty name…" value="<?= htmlspecialchars($search) ?>" style="max-width:400px;">
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($search): ?><a href="pending.php" class="btn btn-outline">Clear</a><?php endif; ?>
      </div>
    </form>

    <?php if (empty($pending)): ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:60px;">
        <div style="font-size:3rem;margin-bottom:12px;">🎉</div>
        <div style="font-weight:600;margin-bottom:6px;">Queue is Clear!</div>
        <div class="text-muted text-sm">No proposals are awaiting your review.</div>
      </div>
    </div>
    <?php else: ?>
    <?php foreach ($pending as $p):
      $daysWaiting = $p['submitted_at'] ? (int)((time()-strtotime($p['submitted_at']))/86400) : 0;
      $urgent = $daysWaiting >= 3;
    ?>
    <div class="proposal-row">
      <div>
        <div class="title"><?= htmlspecialchars($p['title']) ?></div>
        <div class="meta">
          <span class="meta-pill">👤 <?= htmlspecialchars($p['faculty_name']) ?></span>
          <span class="meta-pill">📅 <?= $p['event_date'] ? date('M j, Y', strtotime($p['event_date'])) : 'Date TBD' ?></span>
          <span class="meta-pill">📍 <?= htmlspecialchars($p['venue'] ?? '—') ?></span>
          <span class="meta-pill">👥 <?= number_format($p['target_participants']) ?> participants</span>
          <span class="days-badge <?= $urgent?'urgent':'' ?>"><?= $daysWaiting === 0 ? 'Today' : "{$daysWaiting}d waiting" ?></span>
        </div>
        <?php if ($p['theme']): ?><div class="text-sm text-muted" style="margin-top:4px;">Theme: <?= htmlspecialchars($p['theme']) ?></div><?php endif; ?>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;min-width:160px;">
        <a href="review.php?id=<?= $p['id'] ?>" class="btn btn-primary" style="justify-content:center;">Review Proposal</a>
        <div class="text-sm text-muted" style="text-align:center;">Submitted <?= $p['submitted_at'] ? date('M j', strtotime($p['submitted_at'])) : '—' ?></div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
