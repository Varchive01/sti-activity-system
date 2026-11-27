<?php
/**
 * faculty/post-event.php
 * Post-Event Reports Hub & Dashboard
 * Full-width, spacious layout displaying Pending Approved Activities and Submitted Reports.
 * Clicking "Upload" or "Edit" navigates to the dedicated post-event-form.php page.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/PostEventAssessmentHelper.php';

requireRole('faculty', 'program_head', 'admin1', 'admin2');
$user = currentUser();
$db   = getDB();

// Backward compatibility: If an activity_id query parameter is present, redirect to dedicated form page
if (!empty($_GET['activity_id'])) {
    $forwardUrl = BASE_URL . '/faculty/post-event-form.php?activity_id=' . (int)$_GET['activity_id'];
    if (!empty($_GET['saved'])) $forwardUrl .= '&saved=1';
    if (!empty($_GET['error'])) $forwardUrl .= '&error=' . urlencode($_GET['error']);
    header("Location: $forwardUrl");
    exit;
}

// Self-healing database schema: ensure post_event & faculty_tasks tables have Phase 3 expanded fields
try {
    $existingCols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);
    $requiredPeCols = [
        'target_attendance'      => "INT NULL DEFAULT 0",
        'actual_attendance'      => "INT NULL DEFAULT 0",
        'satisfaction_score'     => "DECIMAL(3,1) NULL DEFAULT NULL",
        'recommendations'        => "TEXT NULL DEFAULT NULL",
        'manual_attendance'      => "INT NULL DEFAULT NULL",
        'assessment_report_path' => "VARCHAR(500) NULL DEFAULT NULL",
        'survey_csv_path'        => "VARCHAR(500) NULL DEFAULT NULL",
        'attendance_method'      => "VARCHAR(50) NOT NULL DEFAULT 'paper_list'",
        'actual_expenses'        => "DECIMAL(12,2) NULL DEFAULT NULL",
        'approved_budget'        => "DECIMAL(12,2) NULL DEFAULT NULL",
        'budget_variance'        => "DECIMAL(12,2) NULL DEFAULT NULL",
        'budget_status'          => "VARCHAR(40) NULL DEFAULT NULL",
        'expense_breakdown_path' => "VARCHAR(500) NULL DEFAULT NULL",
        'equipment_report_json'  => "TEXT NULL DEFAULT NULL",
        'media_drive_link'       => "VARCHAR(500) NULL DEFAULT NULL",
        'comments_summary'       => "TEXT NULL DEFAULT NULL",
        'ai_recommendations'     => "TEXT NULL DEFAULT NULL",
        'ai_assessment_summary'  => "TEXT NULL DEFAULT NULL",
    ];
    foreach ($requiredPeCols as $cName => $cDef) {
        if (!in_array($cName, $existingCols, true)) {
            $db->exec("ALTER TABLE post_event ADD COLUMN {$cName} {$cDef}");
        }
    }

    $ftCols = $db->query("SHOW COLUMNS FROM faculty_tasks")->fetchAll(PDO::FETCH_COLUMN);
    $requiredFtCols = [
        'verified_by_head'    => "TINYINT(1) NOT NULL DEFAULT 0",
        'verified_at'         => "DATETIME NULL DEFAULT NULL",
        'verification_status' => "VARCHAR(40) NOT NULL DEFAULT 'pending'",
        'performance_rating'  => "INT NULL DEFAULT NULL",
        'performance_remarks' => "TEXT NULL DEFAULT NULL",
    ];
    foreach ($requiredFtCols as $cName => $cDef) {
        if (!in_array($cName, $ftCols, true)) {
            $db->exec("ALTER TABLE faculty_tasks ADD COLUMN {$cName} {$cDef}");
        }
    }
} catch (\Throwable $migEx) {
    // Gracefully continue even if DDL permissions are restricted
}

if (function_exists('reconcilePostEventMetrics')) {
    reconcilePostEventMetrics($db);
}

// Fetch activities based on role
if ($user['role'] === 'admin1') {
    $approved = $db->prepare("
        SELECT a.* FROM activities a
        LEFT JOIN post_event pe ON a.id = pe.activity_id
        WHERE (a.faculty_id = ? OR a.source = 'student_org') AND a.status IN ('approved', 'completed') AND pe.id IS NULL
        ORDER BY a.event_date DESC
    ");
    $approved->execute([$user['id']]);
    $eligibleActivities = $approved->fetchAll(PDO::FETCH_ASSOC);

    $submitted = $db->prepare("
        SELECT a.id AS activity_id, a.title, a.event_date, a.venue, pe.*
        FROM post_event pe
        JOIN activities a ON pe.activity_id=a.id
        WHERE (a.faculty_id = ? OR a.source = 'student_org')
        ORDER BY pe.submitted_at DESC
    ");
    $submitted->execute([$user['id']]);
    $reports = $submitted->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user['role'] === 'admin2') {
    $approved = $db->prepare("
        SELECT a.* FROM activities a
        LEFT JOIN post_event pe ON a.id = pe.activity_id
        WHERE a.status IN ('approved', 'completed') AND pe.id IS NULL
        ORDER BY a.event_date DESC
    ");
    $approved->execute();
    $eligibleActivities = $approved->fetchAll(PDO::FETCH_ASSOC);

    $submitted = $db->prepare("
        SELECT a.id AS activity_id, a.title, a.event_date, a.venue, pe.*
        FROM post_event pe
        JOIN activities a ON pe.activity_id=a.id
        ORDER BY pe.submitted_at DESC
    ");
    $submitted->execute();
    $reports = $submitted->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user['role'] === 'program_head') {
    $userDept = resolveUserDepartment($db, $user);
    $deptCond = buildDepartmentSqlCondition('u.department', $userDept);
    $approved = $db->prepare("
        SELECT a.* FROM activities a
        JOIN users u ON a.faculty_id = u.id
        LEFT JOIN post_event pe ON a.id = pe.activity_id
        WHERE ({$deptCond} OR a.faculty_id = ?) AND a.status IN ('approved', 'completed') AND pe.id IS NULL
        ORDER BY a.event_date DESC
    ");
    $approved->execute([$user['id']]);
    $eligibleActivities = $approved->fetchAll(PDO::FETCH_ASSOC);

    $submitted = $db->prepare("
        SELECT a.id AS activity_id, a.title, a.event_date, a.venue, pe.*
        FROM post_event pe
        JOIN activities a ON pe.activity_id=a.id
        JOIN users u ON a.faculty_id = u.id
        WHERE ({$deptCond} OR a.faculty_id = ?)
        ORDER BY pe.submitted_at DESC
    ");
    $submitted->execute([$user['id']]);
    $reports = $submitted->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Faculty
    $approved = $db->prepare("
        SELECT a.* FROM activities a
        LEFT JOIN post_event pe ON a.id = pe.activity_id
        WHERE a.faculty_id=? AND a.status IN ('approved', 'completed') AND pe.id IS NULL
        ORDER BY a.event_date DESC
    ");
    $approved->execute([$user['id']]);
    $eligibleActivities = $approved->fetchAll(PDO::FETCH_ASSOC);

    $submitted = $db->prepare("
        SELECT a.id AS activity_id, a.title, a.event_date, a.venue, pe.*
        FROM post_event pe
        JOIN activities a ON pe.activity_id=a.id
        WHERE a.faculty_id=?
        ORDER BY pe.submitted_at DESC
    ");
    $submitted->execute([$user['id']]);
    $reports = $submitted->fetchAll(PDO::FETCH_ASSOC);
}

$success = isset($_GET['saved']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upload Post-Event Report – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.2.0">
  <style>
    .main-wrap,
    .content {
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }

    .post-event-hub-container {
      max-width: 1200px;
      margin: 0 auto;
      width: 100%;
      display: flex;
      flex-direction: column;
      gap: 24px;
    }

    .activity-row-card {
      background: var(--bg-card, #ffffff);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 10px;
      padding: 16px 20px;
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    }

    .activity-row-card:hover {
      border-color: #0284c7;
      box-shadow: 0 4px 12px rgba(2, 132, 199, 0.08);
    }

    .activity-row-card .activity-title {
      font-weight: 700;
      font-size: 0.95rem;
      color: var(--text-main, #0f172a);
      line-height: 1.35;
      margin-bottom: 4px;
    }

    .activity-row-card .activity-meta {
      font-size: 0.82rem;
      color: var(--text-muted, #64748b);
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .submitted-reports-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: auto;
    }

    .submitted-reports-table th,
    .submitted-reports-table td {
      padding: 12px 16px !important;
      vertical-align: middle;
      border-bottom: 1px solid var(--border, #e2e8f0);
    }

    .submitted-reports-table th {
      background: var(--bg-base, #f8fafc);
      font-size: 0.78rem;
      font-weight: 700;
      color: var(--text-muted, #475569);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .submitted-reports-actions {
      display: inline-flex;
      align-items: center;
      justify-content: flex-end;
      gap: 8px;
      flex-wrap: wrap;
    }
  </style>
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Upload Post-Event Report</div>
      <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">
      <div class="post-event-hub-container">

        <?php if ($success): ?>
          <div class="alert alert-success" style="margin-bottom: 6px;">
            ✓ Post-event report saved and recorded successfully!
          </div>
        <?php endif; ?>

        <!-- CARD 1: Select Approved Activity (Full Width) -->
        <div class="card" style="box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 12px; overflow: hidden;">
          <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; padding: 18px 24px; background: #003876; color: #ffffff;">
            <h2 style="margin:0; font-size:1.05rem; display:flex; align-items:center; gap:8px; color:#ffffff;">
              📋 Select Approved Activity
            </h2>
            <span class="badge" style="background:rgba(255,255,255,0.2); color:#ffffff; font-size:0.8rem; font-weight:700; padding:6px 12px; border-radius:20px;">
              <?= count($eligibleActivities) ?> Pending Upload
            </span>
          </div>
          <div class="card-body" style="padding: 24px;">
            <?php if (empty($eligibleActivities)): ?>
              <div style="text-align:center; padding:36px 20px; color:var(--text-muted);">
                <div style="font-size:2.5rem; margin-bottom:10px;">🎉</div>
                <strong style="font-size:1rem; color:var(--text-main); display:block; margin-bottom:4px;">All Caught Up!</strong>
                <p class="text-sm" style="margin:0;">All approved activities currently have their post-event documentation and reports uploaded.</p>
              </div>
            <?php else: ?>
              <p class="text-sm text-muted" style="margin-top:0; margin-bottom:16px;">
                Choose an approved activity below and click <strong>"Upload Report"</strong> to enter attendance, budget liquidation, equipment reports, and evaluation documentation on the dedicated submission page.
              </p>
              <div style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($eligibleActivities as $a): ?>
                  <div class="activity-row-card">
                    <div style="flex:1; min-width:0;">
                      <div class="activity-title"><?= htmlspecialchars($a['title']) ?></div>
                      <div class="activity-meta">
                        <span>📅 <?= $a['event_date'] ? date('F j, Y', strtotime($a['event_date'])) : '—' ?></span>
                        <span>📍 <?= htmlspecialchars($a['venue'] ?? 'STI Campus') ?></span>
                        <span>👥 Target: <strong><?= (int)$a['target_participants'] ?></strong> participants</span>
                        <span>🏛️ Headed by: <strong><?= ($a['source'] ?? '') === 'student_org' ? 'Student Organization' : 'Faculty' ?></strong></span>
                      </div>
                    </div>
                    <a href="<?= BASE_URL ?>/faculty/post-event-form.php?activity_id=<?= (int)$a['id'] ?>" class="btn btn-primary btn-sm" style="flex-shrink:0; padding:8px 18px; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                      Upload Report →
                    </a>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- CARD 2: Submitted Reports (Full Width Table) -->
        <div class="card" style="box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-radius: 12px; overflow: hidden;">
          <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; padding: 18px 24px; border-bottom: 1px solid var(--border);">
            <h2 style="margin:0; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
              📑 Submitted Post-Event Reports
            </h2>
            <span class="badge badge-success" style="font-size:0.8rem; font-weight:700; padding:6px 12px; border-radius:20px;">
              <?= count($reports) ?> Submitted
            </span>
          </div>
          <div class="card-body" style="padding:0;">
            <?php if (empty($reports)): ?>
              <div style="text-align:center; padding:36px 20px; color:var(--text-muted);">
                <div style="font-size:2rem; margin-bottom:8px;">📂</div>
                <div class="text-sm">No post-event reports have been submitted yet.</div>
              </div>
            <?php else: ?>
              <div class="table-wrap">
                <table class="submitted-reports-table">
                  <thead>
                    <tr>
                      <th style="min-width:240px;">Activity Details</th>
                      <th style="width:160px; text-align:center;">Attendance</th>
                      <th style="width:140px; text-align:center;">Score</th>
                      <th style="width:160px;">Submitted On</th>
                      <th style="width:170px; text-align:right;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($reports as $r): ?>
                      <tr>
                        <td>
                          <strong style="font-size:0.92rem; color:var(--text-main); line-height:1.35; display:block; margin-bottom:2px;">
                            <?= htmlspecialchars($r['title']) ?>
                          </strong>
                          <span class="text-sm text-muted">
                            📅 <?= $r['event_date'] ? date('M j, Y', strtotime($r['event_date'])) : '—' ?>
                            <?php if (!empty($r['venue'])): ?>
                              · 📍 <?= htmlspecialchars($r['venue']) ?>
                            <?php endif; ?>
                          </span>
                        </td>
                        <td style="text-align:center; white-space:nowrap;">
                          <strong style="color:#0284c7;"><?= isset($r['actual_attendance']) ? htmlspecialchars($r['actual_attendance']) : '0' ?></strong>
                          <span style="color:var(--text-muted); font-size:0.82rem;">/ <?= isset($r['target_attendance']) ? htmlspecialchars($r['target_attendance']) : '0' ?></span>
                          <?php if (!empty($r['manual_attendance'])): ?>
                            <div style="color:var(--text-muted); font-size:0.74rem; margin-top:2px;">(Paper: <?= (int)$r['manual_attendance'] ?>)</div>
                          <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                          <span class="badge badge-success" style="font-size:0.8rem; padding:4px 10px;">
                            <?= isset($r['satisfaction_score']) && $r['satisfaction_score'] !== null ? number_format($r['satisfaction_score'], 1) : '—' ?><?= isset($r['satisfaction_score']) && $r['satisfaction_score'] !== null ? '/5' : '' ?>
                          </span>
                        </td>
                        <td style="font-size:0.84rem; color:var(--text-muted);">
                          <?= !empty($r['submitted_at']) ? date('M j, Y g:i A', strtotime($r['submitted_at'])) : '—' ?>
                        </td>
                        <td style="text-align:right;">
                          <div class="submitted-reports-actions">
                            <a href="<?= BASE_URL ?>/faculty/post-event-form.php?activity_id=<?= (int)$r['activity_id'] ?>" class="btn btn-outline btn-sm" style="padding:6px 12px; font-size:0.8rem; display:inline-flex; align-items:center; gap:4px;">
                              ✏️ Edit
                            </a>
                            <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= (int)$r['activity_id'] ?>" class="btn btn-secondary btn-sm" style="padding:6px 12px; font-size:0.8rem; display:inline-flex; align-items:center; gap:4px;" target="_blank">
                              👁️ View
                            </a>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </div>
</body>

</html>
