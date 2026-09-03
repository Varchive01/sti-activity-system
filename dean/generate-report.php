<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
requireRole('dean', 'admin1', 'admin2');
$user = currentUser();
$db   = getDB();

$activityId = (int)($_GET['activity_id'] ?? 0);

if ($activityId > 0) {
    // Mode A: Individual Activity KPI Report
    $actStmt = $db->prepare("
        SELECT a.*, u.name as faculty_name 
        FROM activities a 
        JOIN users u ON a.faculty_id=u.id 
        WHERE a.id = ?
    ");
    $actStmt->execute([$activityId]);
    $activity = $actStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        die("Activity not found.");
    }

    // Compute KPI results
    $kpiResults = calculateActivityKpis($activityId, $db);

    // Fetch cached AI interpretation
    $aiStmt = $db->prepare("SELECT analytics_json FROM activity_kpi_analytics WHERE activity_id = ?");
    $aiStmt->execute([$activityId]);
    $aiRow = $aiStmt->fetch(PDO::FETCH_ASSOC);
    $aiInsights = $aiRow ? json_decode($aiRow['analytics_json'], true) : null;
    
    // Render individual report page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>KPI Report - <?= htmlspecialchars($activity['title']) ?></title>
      <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
      <style>
        .print-only { display: block; }
        .no-print { display: block; }
        @media print {
          .no-print { display: none !important; }
          .sidebar { display: none !important; }
          body { font-size: 12px; background: white; color: black; }
          .main-wrap { margin-left: 0 !important; }
          .content { padding: 0 !important; }
        }
        .report-header { border-bottom: 2px solid var(--accent); padding-bottom: 12px; margin-bottom: 20px; }
        .report-section { margin-bottom: 20px; }
        .kpi-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .kpi-table th, .kpi-table td { border: 1px solid var(--border); padding: 8px 12px; text-align: left; }
        .kpi-table th { background: var(--bg-base); }
      </style>
    </head>
    <body class="theme-dean">
      <?php include __DIR__ . '/../includes/sidebar.php'; ?>
      <div class="main-wrap">
        <header class="topbar no-print">
          <div class="page-title">KPI Report Generator</div>
          <div class="topbar-right">
            <button class="btn btn-primary" onclick="window.print()">🖨 Print / Export PDF</button>
            <a href="javascript:window.close()" class="btn btn-outline">Close</a>
          </div>
        </header>
        <div class="content">
          <div class="report-header">
            <div style="font-family:'Syne',sans-serif; font-size:1.4rem; font-weight:800;">STI College Marikina</div>
            <div style="font-size:1.1rem; font-weight:700; color:var(--accent); margin-top:4px;">Academic Activity KPI Report</div>
            <div class="text-sm text-muted" style="margin-top:6px;">Generated: <?= date('F j, Y g:i A') ?></div>
          </div>

          <div class="card report-section">
            <div class="card-header"><h2>Activity Information</h2></div>
            <div class="card-body">
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div><strong>Title:</strong> <?= htmlspecialchars($activity['title']) ?></div>
                <div><strong>Faculty In-Charge:</strong> <?= htmlspecialchars($activity['faculty_name']) ?></div>
                <div><strong>Event Date:</strong> <?= $activity['event_date'] ? date('F j, Y', strtotime($activity['event_date'])) : '—' ?></div>
                <div><strong>Venue:</strong> <?= htmlspecialchars($activity['venue'] ?? '—') ?></div>
                <div><strong>Status:</strong> <?= ucfirst($activity['status']) ?></div>
                <div><strong>Overall Performance Score:</strong> <?= $kpiResults['overall_performance'] !== null ? $kpiResults['overall_performance'] . '%' : '—' ?> (<?= $kpiResults['overall_status'] ?>)</div>
              </div>
            </div>
          </div>

          <div class="card report-section">
            <div class="card-header"><h2>Deterministic KPI Calculations</h2></div>
            <div class="card-body" style="padding:0;">
              <table class="kpi-table">
                <thead>
                  <tr>
                    <th>KPI / Indicator</th>
                    <th>Target</th>
                    <th>Actual</th>
                    <th>Achievement</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $kpis = $kpiResults['kpis'];
                  $allKpiRows = [];
                  if ($kpis['attendance']) $allKpiRows[] = $kpis['attendance'];
                  if ($kpis['satisfaction']) $allKpiRows[] = $kpis['satisfaction'];
                  if (!empty($kpis['criteria'])) {
                      foreach ($kpis['criteria'] as $cr) {
                          $allKpiRows[] = $cr;
                      }
                  }

                  foreach ($allKpiRows as $k):
                      $cls = getKpiStatusClass($k['status']);
                  ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($k['indicator']) ?></strong></td>
                    <td><?= htmlspecialchars($k['target']) ?></td>
                    <td><?= htmlspecialchars($k['actual']) ?></td>
                    <td><strong><?= htmlspecialchars($k['achievement']) ?></strong></td>
                    <td><span class="badge <?= $cls ?>"><?= htmlspecialchars($k['status']) ?></span></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card report-section">
            <div class="card-header"><h2>AI-Generated Insight — Advisory Only</h2></div>
            <div class="card-body">
              <?php if ($aiInsights): ?>
                <div style="margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid var(--border);">
                  <strong style="display:block; margin-bottom:4px; font-size:0.9rem;">Overall Analysis Summary:</strong>
                  <p style="color:var(--text-muted); margin:0; line-height:1.5; font-size:0.88rem;"><?= htmlspecialchars($aiInsights['overall_insight']) ?></p>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
                  <div>
                    <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:#16A34A;">✓ Strengths</strong>
                    <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                      <?php foreach ($aiInsights['strengths'] as $s): ?>
                        <li><?= htmlspecialchars($s) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                  <div>
                    <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:#D97706;">⚠ Areas of Attention</strong>
                    <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                      <?php foreach ($aiInsights['areas_of_attention'] as $a): ?>
                        <li><?= htmlspecialchars($a) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
                <div style="margin-bottom:14px;">
                  <strong style="display:block; margin-bottom:4px; font-size:0.85rem;">• Key Findings</strong>
                  <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                    <?php foreach ($aiInsights['key_findings'] as $f): ?>
                      <li><?= htmlspecialchars($f) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <div>
                  <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:var(--accent);">💡 Recommendations</strong>
                  <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                    <?php foreach ($aiInsights['recommendations'] as $r): ?>
                      <li><?= htmlspecialchars($r) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <div class="text-muted text-sm" style="text-align:center; padding:10px;">
                  No AI interpretation has been generated for this activity yet. The report contains numerical KPI values only.
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div style="font-size:0.75rem; color:var(--text-muted); text-align:center; margin-top:30px; font-style:italic;">
            AI-generated insights are advisory only and should be reviewed by authorized administrators before making decisions.
          </div>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Filters
$filterYear   = (int)($_GET['year']   ?? date('Y'));
$filterStatus = sanitize($_GET['status'] ?? '');
$filterSource = sanitize($_GET['source'] ?? '');

$where = "1=1";
$params = [];
if ($filterYear)   { $where .= " AND YEAR(a.event_date)=?";  $params[] = $filterYear; }
if ($filterStatus) { $where .= " AND a.status=?";             $params[] = $filterStatus; }
if ($filterSource) { $where .= " AND a.source=?";             $params[] = $filterSource; }

$acts = $db->prepare("
    SELECT a.*, u.name as faculty_name,
           pe.actual_attendance, pe.target_attendance, pe.satisfaction_score,
           AVG(k.rating) as avg_kpi
    FROM activities a
    JOIN users u ON a.faculty_id=u.id
    LEFT JOIN post_event pe ON a.id=pe.activity_id
    LEFT JOIN kpi_evaluations k ON a.id=k.activity_id
    WHERE $where
    GROUP BY a.id
    ORDER BY a.event_date DESC
");
$acts->execute($params);
$activities = $acts->fetchAll();

// Aggregate totals
$total = count($activities);
$totalAtt = array_sum(array_column($activities,'actual_attendance'));
$totalTarget = array_sum(array_column($activities,'target_attendance'));
$avgKpi = $total > 0 ? array_sum(array_column($activities,'avg_kpi')) / max(count(array_filter($activities,fn($a)=>$a['avg_kpi'])),1) : 0;
$avgSat = $total > 0 ? array_sum(array_column($activities,'satisfaction_score')) / max(count(array_filter($activities,fn($a)=>$a['satisfaction_score'])),1) : 0;

$years = range(date('Y'), date('Y')-4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Generate Report – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
<style>
.filter-bar{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:18px 22px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.filter-bar .form-group{margin-bottom:0;min-width:160px;}
.summary-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;}
.summary-item{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;}
.summary-item .n{font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--accent);}
.summary-item .l{font-size:.7rem;text-transform:uppercase;letter-spacing:.3px;color:var(--text-muted);font-weight:700;}
@media print{
  .sidebar,.topbar,.filter-bar,.print-btn{display:none!important;}
  .main-wrap{margin-left:0!important;}
  .content{padding:20px!important;}
  body{font-size:12px;}
}
</style>
</head>
<body class="theme-dean">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Generate Report</div>
    <div class="topbar-right">
      <button class="btn btn-primary print-btn" onclick="window.print()">🖨 Print / Export PDF</button>
    </div>
  </header>
  <div class="content">

    <!-- Filters -->
    <form method="GET" class="filter-bar">
      <div class="form-group">
        <label class="form-label">Year</label>
        <select name="year" class="form-control">
          <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>" <?= $filterYear===$y?'selected':'' ?>><?= $y ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="">All Statuses</option>
          <?php foreach(['approved','completed','rejected','for_revision'] as $s): ?>
          <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Source</label>
        <select name="source" class="form-control">
          <option value="">All Sources</option>
          <option value="student_org" <?= $filterSource==='student_org'?'selected':'' ?>>Student Organization</option>
          <option value="faculty" <?= $filterSource==='faculty'?'selected':'' ?>>Faculty</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Apply Filters</button>
      <a href="generate-report.php" class="btn btn-outline">Reset</a>
    </form>

    <!-- Report Header (print-visible) -->
    <div style="margin-bottom:20px;">
      <div style="font-family:'Syne',sans-serif;font-size:1.3rem;font-weight:800;">STI College Marikina — Activity Report</div>
      <div class="text-sm text-muted">Generated: <?= date('F j, Y g:i A') ?> · Year: <?= $filterYear ?><?= $filterStatus ? " · Status: ".ucfirst(str_replace('_',' ',$filterStatus)) : '' ?><?= $filterSource ? " · Source: ".ucfirst(str_replace('_',' ',$filterSource)) : '' ?></div>
    </div>

    <!-- Summary -->
    <div class="summary-strip">
      <div class="summary-item"><div class="n"><?= $total ?></div><div class="l">Total Activities</div></div>
      <div class="summary-item"><div class="n"><?= $totalTarget > 0 ? round(($totalAtt/$totalTarget)*100).'%' : '—' ?></div><div class="l">Attendance Rate</div></div>
      <div class="summary-item"><div class="n"><?= $avgKpi > 0 ? number_format($avgKpi,2) : '—' ?></div><div class="l">Avg KPI / 4.0</div></div>
      <div class="summary-item"><div class="n"><?= $avgSat > 0 ? number_format($avgSat,1) : '—' ?></div><div class="l">Avg Satisfaction</div></div>
      <div class="summary-item"><div class="n"><?= count(array_filter($activities,fn($a)=>$a['status']==='completed')) ?></div><div class="l">Completed</div></div>
    </div>

    <!-- Detail Table -->
    <div class="card">
      <div class="card-header"><h2>Activity Details — <?= $filterYear ?></h2></div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Activity Title</th>
                <th>Faculty</th>
                <th>Date</th>
                <th>Source</th>
                <th>Status</th>
                <th>Attendance</th>
                <th>KPI Avg</th>
                <th>Satisfaction</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($activities)): ?>
            <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted);">No activities match the selected filters.</td></tr>
            <?php else: foreach ($activities as $i=>$a):
              $attPct = $a['target_attendance'] > 0 ? round(($a['actual_attendance']/$a['target_attendance'])*100) : null;
            ?>
            <tr>
              <td class="text-muted"><?= $i+1 ?></td>
              <td><strong><?= htmlspecialchars($a['title']) ?></strong><br>
                <?php if ($a['venue']): ?><span class="text-sm text-muted"><?= htmlspecialchars($a['venue']) ?></span><?php endif; ?></td>
              <td><?= htmlspecialchars($a['faculty_name']) ?></td>
              <td><?= $a['event_date'] ? date('M j, Y',strtotime($a['event_date'])) : '—' ?></td>
              <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$a['source'])) ?></span></td>
              <td><?= getStatusBadge($a['status']) ?></td>
              <td>
                <?php if ($a['actual_attendance'] !== null): ?>
                  <?= $a['actual_attendance'] ?>/<?= $a['target_attendance'] ?>
                  <?php if ($attPct !== null): ?>
                  <br><small style="color:<?= $attPct>=90?'#16A34A':($attPct>=70?'#D97706':'#DC2626') ?>;font-weight:700;"><?= $attPct ?>%</small>
                  <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td><?= $a['avg_kpi'] ? '<strong>'.number_format($a['avg_kpi'],2).'</strong>' : '—' ?></td>
              <td><?= $a['satisfaction_score'] ? number_format($a['satisfaction_score'],1).'/5' : '—' ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Recommendations summary -->
    <?php
    $withRecs = array_filter($activities, fn($a) => !empty($a['avg_kpi']) && $a['avg_kpi'] < 2.5);
    if (!empty($withRecs)):
    ?>
    <div class="card" style="margin-top:20px;">
      <div class="card-header"><h2>⚠️ Events Needing Attention (KPI &lt; 2.5)</h2></div>
      <div class="card-body" style="padding:0;">
        <table>
          <thead><tr><th>Activity</th><th>KPI</th><th>Attendance Rate</th><th>Recommendations</th></tr></thead>
          <tbody>
          <?php foreach ($withRecs as $a):
            $attPct2 = $a['target_attendance'] > 0 ? round(($a['actual_attendance']/$a['target_attendance'])*100) : 0;
            $rec = $db->prepare("SELECT recommendations FROM post_event WHERE activity_id=?");
            $rec->execute([$a['id']]);
            $recRow = $rec->fetch();
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($a['title']) ?></strong><br><span class="text-sm text-muted"><?= $a['event_date'] ? date('M j, Y',strtotime($a['event_date'])) : '—' ?></span></td>
            <td><span class="badge badge-danger"><?= number_format($a['avg_kpi'],2) ?></span></td>
            <td><?= $attPct2 ?>%</td>
            <td class="text-sm"><?= htmlspecialchars($recRow['recommendations'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php
    $yearInsights = null;
    try {
        $yearStmt = $db->prepare("SELECT analytics_json, updated_at FROM institutional_kpi_analytics WHERE year = ? AND period = 'all'");
        $yearStmt->execute([$filterYear]);
        $yearRow = $yearStmt->fetch();
        if ($yearRow) {
            $yearInsights = json_decode($yearRow['analytics_json'], true);
        }
    } catch (Exception $e) {}
    if ($yearInsights):
    ?>
    <div class="card report-section" style="margin-top:20px; page-break-inside:avoid;">
      <div class="card-header"><h2>🤖 AI Institutional Insights — Year: <?= $filterYear ?> (Advisory Only)</h2></div>
      <div class="card-body">
        <div style="margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid var(--border);">
          <strong style="display:block; margin-bottom:4px; font-size:0.9rem;">Overall Analysis Summary:</strong>
          <p style="color:var(--text-muted); margin:0; line-height:1.5; font-size:0.88rem;"><?= htmlspecialchars($yearInsights['overall_insight']) ?></p>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
          <div>
            <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:#16A34A;">✓ Strengths</strong>
            <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
              <?php foreach ($yearInsights['strengths'] as $s): ?>
                <li><?= htmlspecialchars($s) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div>
            <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:#D97706;">⚠ Areas of Attention</strong>
            <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
              <?php foreach ($yearInsights['areas_of_attention'] as $a): ?>
                <li><?= htmlspecialchars($a) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <div>
          <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:var(--accent);">💡 Recommendations</strong>
          <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
            <?php foreach ($yearInsights['recommendations'] as $r): ?>
              <li><?= htmlspecialchars($r) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <div style="font-size:0.75rem; color:var(--text-muted); text-align:center; padding-bottom:12px; font-style:italic;">
        AI-generated insights are advisory only and should be reviewed by authorized administrators before making decisions.
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
