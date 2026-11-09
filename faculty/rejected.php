<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty', 'program_head');

$user = currentUser();
$db   = getDB();

$hasCollabTable = true;
try {
    $db->query("SELECT 1 FROM proposal_collaborators LIMIT 1");
} catch (\PDOException $e) {
    $hasCollabTable = false;
}

// User department resolution for program head
$userDept = resolveUserDepartment($db, $user);
$isProgramHead = ($user['role'] === 'program_head');

$activities = [];
$totalRejected = 0;

try {
    $params = [];
    if ($isProgramHead) {
        $deptCond = buildDepartmentSqlCondition('u.department', $userDept);
        $collabClause = $hasCollabTable ? "OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active')" : "";
        $where = "WHERE (a.faculty_id = ? {$collabClause} OR ({$deptCond})) AND a.status = 'rejected'";
        $params = $hasCollabTable ? [(int)$user['id'], (int)$user['id']] : [(int)$user['id']];
    } else {
        $collabClause = $hasCollabTable ? "OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active')" : "";
        $where = "WHERE (a.faculty_id = ? {$collabClause}) AND a.status = 'rejected'";
        $params = $hasCollabTable ? [(int)$user['id'], (int)$user['id']] : [(int)$user['id']];
    }

    $stmt = $db->prepare("
        SELECT a.*, u.name as faculty_name, u.department as faculty_dept,
               COALESCE(
                   NULLIF(TRIM(a.revision_notes), ''),
                   (SELECT al.notes FROM approval_logs al WHERE al.activity_id = a.id AND al.action = 'rejected' ORDER BY al.id DESC LIMIT 1)
               ) as rejection_reason,
               (SELECT rev.name FROM approval_logs al JOIN users rev ON al.reviewer_id = rev.id WHERE al.activity_id = a.id AND al.action = 'rejected' ORDER BY al.id DESC LIMIT 1) as rejected_by_name,
               (SELECT al.acted_at FROM approval_logs al WHERE al.activity_id = a.id AND al.action = 'rejected' ORDER BY al.id DESC LIMIT 1) as rejected_at
        FROM activities a 
        JOIN users u ON a.faculty_id = u.id 
        {$where} 
        ORDER BY a.updated_at DESC
    ");
    $stmt->execute($params);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalRejected = count($activities);
} catch (\Throwable $e) {
    error_log("faculty/rejected.php query error: " . $e->getMessage());
    $activities = [];
    $totalRejected = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rejected Proposals – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    body, body *, h1, h2, h3, h4, h5, h6, .page-title, .card-header h2, .btn, .badge, .form-control, label {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }
    .rejection-reason-box {
      background: var(--bg-base, #f8fafc);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 6px;
      padding: 10px 14px;
      font-size: 0.82rem;
      color: var(--text-main, #0f172a);
      line-height: 1.45;
      margin-top: 6px;
    }
    [data-theme="dark"] .rejection-reason-box {
      background: rgba(255, 255, 255, 0.04);
      border-color: rgba(255, 255, 255, 0.1);
    }
    .table-wrap {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
  </style>
</head>
<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Rejected Proposals</div>
      <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">
      <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
          <h2 style="margin: 0; font-size: 1.15rem; font-weight: 700; display: flex; align-items: center; gap: 8px;">
            <span>🚫</span> Declined Proposals Register
          </h2>
          <span class="badge badge-danger" style="font-size: 0.78rem;">
            <?= $totalRejected ?> <?= $totalRejected === 1 ? 'Proposal' : 'Proposals' ?> Declined
          </span>
        </div>

        <div class="card-body" style="padding: 0;">
          <div class="table-wrap">
            <table class="table" style="margin: 0; width: 100%; border-collapse: collapse;">
              <thead>
                <tr style="background: var(--bg-base); border-bottom: 1px solid var(--border);">
                  <th style="padding: 12px 16px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Activity Title & Proponent</th>
                  <th style="padding: 12px 16px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Proposed Date</th>
                  <th style="padding: 12px 16px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; min-width: 280px;">Reviewer's Reason for Rejection</th>
                  <th style="padding: 12px 16px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Status</th>
                  <th style="padding: 12px 16px; font-size: 0.8rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; text-align: center; min-width: 140px;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="5" style="text-align: center; padding: 50px 20px;">
                      <div style="font-size: 2.5rem; margin-bottom: 10px;">📋</div>
                      <div style="font-weight: 700; font-size: 1rem; color: var(--text-main); margin-bottom: 4px;">No Rejected Proposals</div>
                      <div style="font-size: 0.85rem; color: var(--text-muted); max-width: 420px; margin: 0 auto;">
                        There are no rejected or declined proposals on record.
                      </div>
                      <a href="<?= BASE_URL ?>/faculty/activities.php" class="btn btn-outline btn-sm" style="margin-top: 14px;">View All Activities</a>
                    </td>
                  </tr>
                <?php else: foreach ($activities as $a): ?>
                  <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 14px 16px; vertical-align: top;">
                      <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= $a['id'] ?>" style="font-weight: 700; font-size: 0.92rem; color: var(--accent); text-decoration: none; display: block; margin-bottom: 3px;">
                        <?= htmlspecialchars($a['title']) ?>
                      </a>
                      <div style="font-size: 0.78rem; color: var(--text-muted);">
                        Proponent: <strong><?= htmlspecialchars($a['faculty_name']) ?></strong>
                        <?php if (!empty($a['faculty_dept'])): ?>
                          · <?= htmlspecialchars($a['faculty_dept']) ?>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td style="padding: 14px 16px; vertical-align: top; white-space: nowrap; font-size: 0.85rem; color: var(--text-main);">
                      <?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) : '—' ?>
                    </td>
                    <td style="padding: 14px 16px; vertical-align: top;">
                      <?php if (!empty($a['rejection_reason'])): ?>
                        <div class="rejection-reason-box">
                          <div style="font-weight: 700; font-size: 0.75rem; color: #dc2626; margin-bottom: 3px; display: flex; align-items: center; gap: 4px;">
                            <span>❌</span> Declined by <?= htmlspecialchars($a['rejected_by_name'] ?? 'Reviewer') ?>:
                          </div>
                          <div style="color: var(--text-main); font-size: 0.82rem; white-space: pre-wrap;"><?= htmlspecialchars($a['rejection_reason']) ?></div>
                          <?php if (!empty($a['rejected_at'])): ?>
                            <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 4px;">
                              Declined on <?= date('M j, Y g:i A', strtotime($a['rejected_at'])) ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span style="font-size: 0.8rem; color: var(--text-muted); font-style: italic;">
                          No specific remarks attached. Check activity history for details.
                        </span>
                      <?php endif; ?>
                    </td>
                    <td style="padding: 14px 16px; vertical-align: top; white-space: nowrap;">
                      <span class="badge badge-danger" style="font-size: 0.75rem;">Rejected</span>
                    </td>
                    <td style="padding: 14px 16px; vertical-align: top; text-align: center; white-space: nowrap;">
                      <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= $a['id'] ?>" class="btn btn-outline btn-sm" style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px;">
                        👁 View Details & Reason
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

