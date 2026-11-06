<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('program_head');

$user = currentUser();
$db   = getDB();

$dept = resolveUserDepartment($db, $user);
$user['dept'] = $dept;
$deptConfig = getDepartmentDashboardConfig($dept);
$deptLabel = $deptConfig['short_label'];
$deptSql = buildDepartmentSqlCondition('u.department', $dept);

$search = sanitize($_GET['q'] ?? '');
$params = [];

$where = "u.role = 'faculty' AND {$deptSql}";
if ($search) {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// Fetch faculty members in this department with task and activity counts
$query = "
    SELECT u.id, u.name, u.email, u.department, u.created_at,
           (SELECT COUNT(*) FROM activities a WHERE a.faculty_id = u.id AND a.status != 'draft') as total_proposals,
           (SELECT COUNT(*) FROM activities a WHERE a.faculty_id = u.id AND a.status IN ('approved', 'completed')) as approved_proposals,
           (SELECT COUNT(*) FROM faculty_tasks ft WHERE ft.assigned_user_id = u.id AND ft.status != 'Completed') as active_tasks,
           (SELECT COUNT(*) FROM faculty_tasks ft WHERE ft.assigned_user_id = u.id AND ft.status = 'Completed') as completed_tasks
    FROM users u
    WHERE {$where}
    ORDER BY u.name ASC
";
$stmt = $db->prepare($query);
$stmt->execute($params);
$facultyList = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Faculty Contributors – <?= htmlspecialchars($deptLabel) ?> Department</title>
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
    body, body *, h1, h2, h3, h4, h5, h6, .page-title, .card-header h2, .btn, .badge, input, select {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }
    .faculty-card-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 16px;
      margin-top: 16px;
    }
    .faculty-contributor-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px 20px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      box-shadow: var(--shadow-sm);
      transition: transform 0.18s ease, box-shadow 0.18s ease;
    }
    .faculty-contributor-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
      border-color: var(--accent);
    }
    .f-card-header {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .f-avatar {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      background: var(--accent-lt, #E0F2FE);
      color: var(--accent, #0284C7);
      font-weight: 700;
      font-size: 1.1rem;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .f-info {
      min-width: 0;
      flex: 1;
    }
    .f-name {
      font-weight: 700;
      font-size: 0.95rem;
      color: var(--text-main);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .f-email {
      font-size: 0.78rem;
      color: var(--text-muted);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .f-stats-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
      background: var(--bg-base, rgba(0,0,0,0.02));
      border-radius: 8px;
      padding: 10px;
      border: 1px solid var(--border);
    }
    .f-stat-item {
      text-align: center;
    }
    .f-stat-val {
      font-size: 1.1rem;
      font-weight: 800;
      color: var(--text-main);
      line-height: 1.1;
    }
    .f-stat-lbl {
      font-size: 0.68rem;
      font-weight: 600;
      color: var(--text-muted);
      margin-top: 2px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
  </style>
</head>
<body class="theme-faculty">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Faculty Contributors — <?= htmlspecialchars($deptLabel) ?> Department</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>

  <div class="content">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
      <div>
        <h2 style="font-size:1.15rem;font-weight:800;margin:0;color:var(--text-main);">
          <?= htmlspecialchars($deptConfig['full_label']) ?> Roster
        </h2>
        <p class="text-sm text-muted" style="margin:2px 0 0;">
          All active faculty members contributing to proposals and activities within the <?= htmlspecialchars($deptLabel) ?> department.
        </p>
      </div>

      <form method="GET" style="display:flex;gap:8px;">
        <input type="text" name="q" class="form-control" placeholder="Search faculty name…" value="<?= htmlspecialchars($search) ?>" style="max-width:240px;height:34px;font-size:0.82rem;">
        <button type="submit" class="btn btn-primary btn-sm" style="height:34px;">Search</button>
        <?php if ($search): ?><a href="contributors.php" class="btn btn-outline btn-sm" style="height:34px;">Clear</a><?php endif; ?>
      </form>
    </div>

    <?php if (empty($facultyList)): ?>
      <div class="card" style="padding:48px 24px;text-align:center;color:var(--text-muted);">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:48px;height:48px;margin:0 auto 12px;opacity:0.4;">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
        </svg>
        <p style="font-weight:600;font-size:0.95rem;margin:0 0 4px;">No faculty members found</p>
        <p class="text-xs" style="margin:0;">There are no registered faculty members assigned to the <?= htmlspecialchars($deptLabel) ?> department matching your filter.</p>
      </div>
    <?php else: ?>
      <div class="faculty-card-grid">
        <?php foreach ($facultyList as $f): 
          $initial = strtoupper(substr($f['name'], 0, 1));
        ?>
          <div class="faculty-contributor-card">
            <div class="f-card-header">
              <div class="f-avatar"><?= $initial ?></div>
              <div class="f-info">
                <div class="f-name" title="<?= htmlspecialchars($f['name']) ?>"><?= htmlspecialchars($f['name']) ?></div>
                <div class="f-email" title="<?= htmlspecialchars($f['email']) ?>"><?= htmlspecialchars($f['email']) ?></div>
              </div>
            </div>

            <div class="f-stats-row">
              <div class="f-stat-item">
                <div class="f-stat-val"><?= (int)$f['total_proposals'] ?></div>
                <div class="f-stat-lbl">Proposals</div>
              </div>
              <div class="f-stat-item">
                <div class="f-stat-val" style="color:var(--accent);"><?= (int)$f['active_tasks'] ?></div>
                <div class="f-stat-lbl">Active Tasks</div>
              </div>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;padding-top:4px;">
              <span class="badge" style="background:rgba(2, 132, 199, 0.1);color:#0284c7;font-size:0.72rem;font-weight:700;">
                <?= htmlspecialchars($deptLabel) ?> Faculty
              </span>
              <a href="<?= BASE_URL ?>/faculty/tasks.php" class="btn btn-outline btn-sm" style="font-size:0.75rem;padding:4px 10px;">
                View Tasks
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

