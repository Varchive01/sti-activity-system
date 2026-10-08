<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin2');
$user = currentUser();
$db   = getDB();

// KPI by criteria
$byCriteria = $db->query("
    SELECT criteria, AVG(rating) as avg, COUNT(*) as cnt,
           SUM(rating=4) as excellent, SUM(rating=3) as very_sat,
           SUM(rating=2) as sat, SUM(rating=1) as needs_imp
    FROM kpi_evaluations GROUP BY criteria ORDER BY avg DESC
")->fetchAll();

// Post-event stats
try {
  $peStats = $db->query("
        SELECT a.title, a.event_date,
               AVG(k.rating) as avg_kpi,
               COALESCE(pe.actual_attendance,0) AS actual_attendance,
               COALESCE(pe.target_attendance,0) AS target_attendance,
               COALESCE(pe.satisfaction_score,0) AS satisfaction_score,
               CASE WHEN COALESCE(pe.target_attendance,0) > 0
                    THEN ROUND((pe.actual_attendance / pe.target_attendance) * 100)
                    ELSE 0
               END AS att_pct
        FROM post_event pe
        JOIN activities a ON pe.activity_id=a.id
        LEFT JOIN kpi_evaluations k ON pe.activity_id=k.activity_id
        GROUP BY pe.id, a.id
        ORDER BY a.event_date DESC
    ")->fetchAll();
} catch (PDOException $e) {
  $peStats = $db->query("
        SELECT a.title, a.event_date,
               AVG(k.rating) as avg_kpi,
               0 AS actual_attendance,
               0 AS target_attendance,
               0 AS satisfaction_score,
               0 AS att_pct
        FROM post_event pe
        JOIN activities a ON pe.activity_id=a.id
        LEFT JOIN kpi_evaluations k ON pe.activity_id=k.activity_id
        GROUP BY pe.id, a.id
        ORDER BY a.event_date DESC
    ")->fetchAll();
}

// RCA entries
$rcas = $db->query("
    SELECT rca.*, a.title FROM root_cause_analysis rca
    JOIN activities a ON rca.activity_id=a.id
    ORDER BY rca.id DESC LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KPI Overview – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    .kpi-criteria-row {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 14px 0;
      border-bottom: 1px solid var(--border);
    }

    .kpi-criteria-row:last-child {
      border-bottom: none;
    }

    .kpi-criteria-row .name {
      min-width: 200px;
      font-weight: 600;
      font-size: .85rem;
    }

    .kpi-bar-wrap {
      flex: 1;
    }

    .kpi-bar-outer {
      height: 10px;
      background: var(--border);
      border-radius: 999px;
      overflow: hidden;
    }

    .kpi-bar-inner {
      height: 100%;
      border-radius: 999px;
      transition: width .5s;
    }

    .kpi-avg {
      min-width: 50px;
      text-align: right;
      font-family: 'Syne', sans-serif;
      font-weight: 800;
      font-size: .95rem;
    }

    .seg-breakdown {
      display: flex;
      gap: 4px;
      margin-top: 4px;
    }

    .seg-pip {
      height: 4px;
      border-radius: 999px;
    }

    .event-perf-row {
      padding: 14px 0;
      border-bottom: 1px solid var(--border);
    }

    .event-perf-row:last-child {
      border-bottom: none;
    }

    .att-bar {
      height: 6px;
      background: var(--border);
      border-radius: 999px;
      overflow: hidden;
      margin-top: 6px;
    }

    .att-fill {
      height: 100%;
      border-radius: 999px;
    }
  </style>
</head>

<body class="theme-ian">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">KPI Overview</div>
    
    <div class="topbar-right">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
    <div class="content">

      <!-- Summary stats -->
      <div class="stat-grid" style="margin-bottom:24px;">
        <?php
        $totals = $db->query("SELECT AVG(rating) as avg, COUNT(DISTINCT activity_id) as events FROM kpi_evaluations")->fetch();
        try {
          $peTotal = $db->query("SELECT COUNT(*) as c, AVG(satisfaction_score) as avgsat FROM post_event")->fetch();
        } catch (PDOException $e) {
          // Legacy schema where post_event.satisfaction_score is removed
          $peTotal = $db->query("SELECT COUNT(*) as c, 0 AS avgsat FROM post_event")->fetch();
        }
        ?>
        <div class="stat-card">
          <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg></div>
          <div>
            <div class="stat-val"><?= $totals['avg'] ? number_format($totals['avg'], 2) : '—' ?></div>
            <div class="stat-label">Overall Avg KPI / 4.0</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
            </svg></div>
          <div>
            <div class="stat-val"><?= $totals['events'] ?></div>
            <div class="stat-label">Events Evaluated</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg></div>
          <div>
            <div class="stat-val"><?= $peTotal['avgsat'] ? number_format($peTotal['avgsat'], 1) : '—' ?></div>
            <div class="stat-label">Avg Satisfaction / 5.0</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg></div>
          <div>
            <div class="stat-val"><?= $peTotal['c'] ?></div>
            <div class="stat-label">Post-Event Reports</div>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

        <!-- KPI by Criteria -->
        <div class="card">
          <div class="card-header">
            <h2>📊 KPI by Criteria</h2>
          </div>
          <div class="card-body">
            <?php if (empty($byCriteria)): ?>
              <div style="text-align:center;padding:30px;color:var(--text-muted);">No KPI data yet.</div>
              <?php else: foreach ($byCriteria as $c):
                $pct = round(($c['avg'] / 4) * 100);
                $col = $c['avg'] >= 3.5 ? '#16A34A' : ($c['avg'] >= 2.5 ? '#0284C7' : ($c['avg'] >= 1.5 ? '#D97706' : '#DC2626'));
                $total = max($c['cnt'], 1);
              ?>
                <div class="kpi-criteria-row">
                  <div class="name"><?= htmlspecialchars($c['criteria']) ?></div>
                  <div class="kpi-bar-wrap">
                    <div class="kpi-bar-outer">
                      <div class="kpi-bar-inner" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
                    </div>
                    <div class="seg-breakdown">
                      <div class="seg-pip" style="width:<?= round(($c['excellent'] / $total) * 100) ?>%;background:#16A34A"></div>
                      <div class="seg-pip" style="width:<?= round(($c['very_sat'] / $total) * 100) ?>%;background:#0284C7"></div>
                      <div class="seg-pip" style="width:<?= round(($c['sat'] / $total) * 100) ?>%;background:#D97706"></div>
                      <div class="seg-pip" style="width:<?= round(($c['needs_imp'] / $total) * 100) ?>%;background:#DC2626"></div>
                    </div>
                  </div>
                  <div class="kpi-avg" style="color:<?= $col ?>"><?= number_format($c['avg'], 2) ?></div>
                </div>
            <?php endforeach;
            endif; ?>

            <?php if (!empty($byCriteria)): ?>
              <div style="margin-top:14px;display:flex;gap:14px;flex-wrap:wrap;" class="text-sm text-muted">
                <span><span style="display:inline-block;width:10px;height:4px;background:#16A34A;border-radius:2px;margin-right:4px;"></span>Excellent</span>
                <span><span style="display:inline-block;width:10px;height:4px;background:#0284C7;border-radius:2px;margin-right:4px;"></span>Very Sat.</span>
                <span><span style="display:inline-block;width:10px;height:4px;background:#D97706;border-radius:2px;margin-right:4px;"></span>Satisfactory</span>
                <span><span style="display:inline-block;width:10px;height:4px;background:#DC2626;border-radius:2px;margin-right:4px;"></span>Needs Imp.</span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Event Performance -->
        <div class="card">
          <div class="card-header">
            <h2>📅 Event Performance</h2>
          </div>
          <div class="card-body" style="padding:0 22px;">
            <?php if (empty($peStats)): ?>
              <div style="text-align:center;padding:30px;color:var(--text-muted);">No post-event data yet.</div>
              <?php else: foreach ($peStats as $pe):
                $col2 = $pe['att_pct'] >= 100 ? '#16A34A' : ($pe['att_pct'] >= 90 ? '#0284C7' : ($pe['att_pct'] >= 70 ? '#D97706' : '#DC2626'));
              ?>
                <div class="event-perf-row">
                  <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                    <div>
                      <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($pe['title']) ?></div>
                      <div class="text-sm text-muted"><?= $pe['event_date'] ? date('M j, Y', strtotime($pe['event_date'])) : '—' ?></div>
                    </div>
                    <div style="text-align:right;">
                      <?php if ($pe['avg_kpi']): ?><span class="badge badge-info">KPI <?= number_format($pe['avg_kpi'], 1) ?>/4</span><?php endif; ?>
                      <?php if ($pe['satisfaction_score']): ?><span class="badge badge-success" style="margin-left:4px;"><?= number_format($pe['satisfaction_score'], 1) ?>★</span><?php endif; ?>
                    </div>
                  </div>
                  <div class="flex justify-between text-sm text-muted" style="margin-top:6px;margin-bottom:2px;">
                    <span>Attendance: <?= $pe['actual_attendance'] ?>/<?= $pe['target_attendance'] ?></span>
                    <span style="color:<?= $col2 ?>;font-weight:700;"><?= $pe['att_pct'] ?>%</span>
                  </div>
                  <div class="att-bar">
                    <div class="att-fill" style="width:<?= min($pe['att_pct'], 100) ?>%;background:<?= $col2 ?>"></div>
                  </div>
                </div>
            <?php endforeach;
            endif; ?>
          </div>
        </div>

      </div>

      <!-- Root Cause Analysis -->
      <?php if (!empty($rcas)): ?>
        <div class="card" style="margin-top:20px;">
          <div class="card-header">
            <h2>🔍 Root Cause Analysis Records</h2>
          </div>
          <div class="card-body" style="padding:0;">
            <table>
              <thead>
                <tr>
                  <th>Activity</th>
                  <th>Problem</th>
                  <th>Method</th>
                  <th>Causes</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rcas as $r): ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($r['title']) ?></strong></td>
                    <td><?= htmlspecialchars(mb_strimwidth($r['problem'] ?? '', 0, 80, '…')) ?></td>
                    <td><span class="badge badge-secondary"><?= strtoupper(str_replace('_', ' ', $r['method'] ?? '')) ?></span></td>
                    <td class="text-sm text-muted"><?= htmlspecialchars(mb_strimwidth($r['causes'] ?? '', 0, 80, '…')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>
</body>

</html>
