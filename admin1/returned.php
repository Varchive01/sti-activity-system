<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin1');
$user = currentUser();
$db   = getDB();

// Admin 1 (Student Organization Head) Returned for Revision Proposals
$search = sanitize($_GET['q'] ?? '');
$where = "al.reviewer_id = ? AND al.action IN ('returned', 'returned_for_revision') AND a.source = 'student_org' AND a.status = 'returned_for_revision'";
$params = [$user['id']];

if ($search) {
    $where .= " AND (a.title LIKE ? OR u.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$activities = [];
try {
    $stmt = $db->prepare("
        SELECT a.*, u.name as faculty_name, u.department as faculty_dept,
               COALESCE(
                   NULLIF(TRIM(a.revision_notes), ''),
                   (SELECT al2.notes FROM approval_logs al2 WHERE al2.activity_id = a.id AND al2.action IN ('returned', 'returned_for_revision') ORDER BY al2.id DESC LIMIT 1)
               ) as reviewer_notes,
               (SELECT rev.name FROM approval_logs al2 JOIN users rev ON al2.reviewer_id = rev.id WHERE al2.activity_id = a.id AND al2.action IN ('returned', 'returned_for_revision') ORDER BY al2.id DESC LIMIT 1) as returned_by_name,
               (SELECT rev.role FROM approval_logs al2 JOIN users rev ON al2.reviewer_id = rev.id WHERE al2.activity_id = a.id AND al2.action IN ('returned', 'returned_for_revision') ORDER BY al2.id DESC LIMIT 1) as returned_by_role,
               MAX(al.acted_at) as returned_at
        FROM activities a 
        JOIN users u ON a.faculty_id = u.id 
        JOIN approval_logs al ON a.id = al.activity_id 
        WHERE {$where} 
        GROUP BY a.id, u.name, u.department
        ORDER BY returned_at DESC
    ");
    $stmt->execute($params);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("admin1/returned.php query error: " . $e->getMessage());
    $activities = [];
}
$totalReturned = count($activities);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Returned for Revision – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.8">
  <style>
    body, body *, h1, h2, h3, h4, h5, h6, .page-title, .card-header h2, .btn, .badge, .form-control, label {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }
    .reviewer-note-box {
      background: var(--bg-base, #f8fafc);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 6px;
      padding: 10px 14px;
      font-size: 0.82rem;
      color: var(--text-main, #0f172a);
      line-height: 1.45;
      margin-top: 6px;
    }
    [data-theme="dark"] .reviewer-note-box {
      background: rgba(255, 255, 255, 0.04);
      border-color: rgba(255, 255, 255, 0.1);
    }
    .table-wrap {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .activity-table th {
      padding: 11px 16px;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted);
      border-bottom: 1px solid var(--border);
      background: var(--border-light, #F8FAFD);
      text-align: left;
      white-space: nowrap;
    }
  </style>
</head>
<body class="theme-arjay">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Returned for Revision</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">
      <!-- Search Toolbar -->
      <form method="GET" class="filters" style="margin-bottom: 16px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <input type="text" name="q" class="form-control" placeholder="Search by title or proponent…" value="<?= htmlspecialchars($search) ?>" style="max-width: 360px;">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search): ?><a href="returned.php" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
        <span style="margin-left: auto; font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">
          Showing <?= $totalReturned ?> proposal<?= $totalReturned === 1 ? '' : 's' ?>
        </span>
      </form>

      <div class="card" style="border-radius: 12px; border: 1px solid var(--border); box-shadow: var(--shadow-sm); background: var(--bg-card);">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border);">
          <h2 style="margin: 0; font-size: 1.1rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
            <span>📋</span> Student Org Proposals Returned for Revision
          </h2>
        </div>

        <div class="card-body" style="padding: 0;">
          <div class="table-wrap">
            <table class="activity-table">
              <thead>
                <tr>
                  <th style="min-width: 220px;">Activity Title &amp; Proponent</th>
                  <th style="width: 140px;">Dept &amp; Source</th>
                  <th style="width: 120px;">Event Date</th>
                  <th style="min-width: 280px;">Reviewer Revision Notes</th>
                  <th style="width: 150px;">Status</th>
                  <th style="width: 90px; text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="6" style="text-align: center; padding: 50px 20px;">
                      <div style="font-size: 2.5rem; margin-bottom: 10px;">🎉</div>
                      <div style="font-weight: 700; font-size: 1rem; color: var(--text-main); margin-bottom: 4px;">No Returned Proposals</div>
                      <div style="font-size: 0.85rem; color: var(--text-muted); max-width: 440px; margin: 0 auto;">
                        There are currently no Student Organization activity proposals marked as returned for revision in your queue.
                      </div>
                    </td>
                  </tr>
                <?php else: foreach ($activities as $a): ?>
                  <tr>
                    <td>
                      <a href="<?= BASE_URL ?>/admin1/view-activity.php?id=<?= $a['id'] ?>" style="font-weight: 700; font-size: 0.92rem; color: var(--accent); text-decoration: none; display: block; margin-bottom: 3px;">
                        <?= htmlspecialchars($a['title']) ?>
                      </a>
                      <div style="font-size: 0.78rem; color: var(--text-muted);">
                        Proponent: <strong><?= htmlspecialchars($a['faculty_name']) ?></strong>
                      </div>
                    </td>
                    <td>
                      <div style="font-weight: 600; font-size: 0.82rem; color: var(--text-main); margin-bottom: 3px;">
                        <?= htmlspecialchars($a['faculty_dept'] ?: '—') ?>
                      </div>
                      <span class="badge badge-secondary" style="font-size: 0.7rem;">
                        <?= ucfirst(str_replace('_', ' ', $a['source'])) ?>
                      </span>
                    </td>
                    <td style="white-space: nowrap; font-size: 0.85rem;">
                      <?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?>
                    </td>
                    <td>
                      <?php if (!empty($a['reviewer_notes'])): ?>
                        <div class="reviewer-note-box">
                          <div style="font-weight: 700; font-size: 0.75rem; color: #d97706; margin-bottom: 3px; display: flex; align-items: center; gap: 4px;">
                            <span>⚠️</span> Notes from <?= htmlspecialchars($a['returned_by_name'] ?? 'Reviewer') ?>
                            <?php if (!empty($a['returned_by_role'])): ?>
                              <span style="opacity: 0.85; font-weight: 500;">(<?= htmlspecialchars(ucwords(str_replace('_', ' ', $a['returned_by_role']))) ?>)</span>
                            <?php endif; ?>:
                          </div>
                          <div style="color: var(--text-main); font-size: 0.82rem; white-space: pre-wrap;"><?= htmlspecialchars($a['reviewer_notes']) ?></div>
                          <?php if (!empty($a['returned_at'])): ?>
                            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 4px;">
                              Returned on <?= date('M j, Y g:i A', strtotime($a['returned_at'])) ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">
                          No specific remarks attached. Check proposal history for details.
                        </span>
                      <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                      <span class="badge badge-warning" style="font-size: 0.75rem;">Returned for Revision</span>
                    </td>
                    <td style="text-align: right; white-space: nowrap;">
                      <a href="<?= BASE_URL ?>/admin1/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm">
                        View
                      </a>
                    </td>
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
