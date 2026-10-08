<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('dean');
$db = getDB();
$year = (int)($_GET['year'] ?? date('Y'));
$bySource = $db->query("SELECT a.source, AVG(k.rating) as avg, COUNT(DISTINCT a.id) as cnt FROM activities a LEFT JOIN kpi_evaluations k ON a.id=k.activity_id WHERE YEAR(a.event_date)=$year GROUP BY a.source")->fetchAll();
try {
    $topPerf = $db->query("SELECT a.title, a.event_date, u.name as fn, AVG(k.rating) as avg_kpi, pe.satisfaction_score, pe.actual_attendance, pe.target_attendance FROM activities a JOIN users u ON a.faculty_id=u.id LEFT JOIN kpi_evaluations k ON a.id=k.activity_id LEFT JOIN post_event pe ON a.id=pe.activity_id WHERE YEAR(a.event_date)=$year AND a.status IN ('approved', 'completed') GROUP BY a.id HAVING avg_kpi IS NOT NULL ORDER BY avg_kpi DESC LIMIT 10")->fetchAll();
} catch (PDOException $e) {
    // Legacy schema where post_event columns are removed
    $topPerf = $db->query("SELECT a.title, a.event_date, u.name as fn, AVG(k.rating) as avg_kpi, NULL AS satisfaction_score, NULL AS actual_attendance, NULL AS target_attendance FROM activities a JOIN users u ON a.faculty_id=u.id LEFT JOIN kpi_evaluations k ON a.id=k.activity_id LEFT JOIN post_event pe ON a.id=pe.activity_id WHERE YEAR(a.event_date)=$year AND a.status IN ('approved', 'completed') GROUP BY a.id HAVING avg_kpi IS NOT NULL ORDER BY avg_kpi DESC LIMIT 10")->fetchAll();
}
$monthly = $db->query("SELECT MONTH(a.event_date) as m, COUNT(*) as cnt, AVG(k.rating) as avg_kpi FROM activities a LEFT JOIN kpi_evaluations k ON a.id=k.activity_id WHERE YEAR(a.event_date)=$year AND a.status IN ('approved','completed') GROUP BY MONTH(a.event_date) ORDER BY m")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KPI Reports – STI</title>
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
    /* ── Typography Parity: Strict Plus Jakarta Sans ── */
    body, body *, h1, h2, h3, h4, h5, h6, .page-title, .card-header h2, .btn, .badge, .form-control, label, select {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .month-bar-wrap {
      display: flex;
      align-items: flex-end;
      gap: 8px;
      height: 120px;
      margin: 14px 0;
    }

    .month-bar {
      flex: 1;
      border-radius: 6px 6px 0 0;
      min-width: 0;
      transition: opacity .2s;
      cursor: default;
      position: relative;
    }

    .month-bar:hover {
      opacity: .85;
    }

    .month-label {
      text-align: center;
      font-size: .62rem;
      color: var(--text-muted);
      font-weight: 700;
      margin-top: 4px;
    }

    .month-val {
      position: absolute;
      top: -18px;
      left: 50%;
      transform: translateX(-50%);
      font-size: .63rem;
      font-weight: 700;
      white-space: nowrap;
    }
  </style>
</head>

<body class="theme-dean">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">KPI Reports</div>
      <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <form method="GET" style="display:flex;gap:8px;align-items:center;">
          <select name="year" class="form-control" style="width:100px;">
            <?php foreach (range(date('Y'), date('Y') - 4) as $y): ?>
              <option <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-primary btn-sm">Apply</button>
        </form>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
    </header>
    <div class="content">

      <!-- Individual Activity KPI Report & AI Insights -->
      <div class="card" style="margin-bottom:20px;">
        <div class="card-header flex justify-between align-center" style="display:flex; justify-content:space-between; align-items:center;">
          <h2>📊 Individual Activity KPI Analyzer</h2>
          <button id="update-activity-insights-btn" class="btn btn-outline btn-sm" style="display:none;">Update AI Insights</button>
        </div>
        <div class="card-body">
          <div style="display:flex; gap:12px; align-items:center; margin-bottom:20px; flex-wrap:wrap;">
            <div class="form-group" style="margin:0; min-width:300px;">
              <label class="form-label" style="font-weight:600; margin-bottom:4px; display:block;">Select Activity to Analyze</label>
              <select id="activity-selector" class="form-control">
                <option value="">-- Choose a Completed / Approved Activity --</option>
                <?php
                // Fetch completed/approved activities for selector
                $selectorStmt = $db->query("
                    SELECT id, title, event_date, status 
                    FROM activities 
                    WHERE status IN ('completed', 'approved') 
                    ORDER BY event_date DESC
                ");
                $selectorActs = $selectorStmt->fetchAll();
                foreach ($selectorActs as $sa) {
                    echo '<option value="' . $sa['id'] . '">' . htmlspecialchars($sa['title']) . ' (' . date('M j, Y', strtotime($sa['event_date'])) . ')</option>';
                }
                ?>
              </select>
            </div>
            <div style="margin-top:22px;">
              <button id="generate-pdf-btn" class="btn btn-outline btn-sm" style="display:none;">🖨 Print / Export PDF</button>
            </div>
          </div>

          <!-- KPI Table & AI Insights Container -->
          <div id="activity-report-container" style="display:none;">
            <!-- Renders dynamically via JavaScript -->
          </div>
          
          <div id="activity-report-empty" style="text-align:center; padding:20px; color:var(--text-muted);">
            Please select an activity from the dropdown to view its KPI report and AI-generated insights.
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
        <!-- By Source -->
        <div class="card">
          <div class="card-header">
            <h2>KPI by Source — <?= $year ?></h2>
          </div>
          <div class="card-body">
            <?php foreach ($bySource as $bs): $pct = round(($bs['avg'] / 4) * 100); ?>
              <div style="margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                  <span class="fw-bold"><?= ucfirst(str_replace('_', ' ', $bs['source'])) ?></span>
                  <span><?= number_format($bs['avg'], 2) ?>/4.0 &nbsp;<span class="text-muted text-sm">(<?= $bs['cnt'] ?> events)</span></span>
                </div>
                <div style="height:10px;background:var(--border);border-radius:999px;overflow:hidden;">
                  <div style="width:<?= $pct ?>%;height:100%;background:var(--accent);border-radius:999px;"></div>
                </div>
              </div>
            <?php endforeach; ?>
            <?php if (empty($bySource)): ?><div class="text-muted text-sm" style="text-align:center;padding:20px;">No data for <?= $year ?>.</div><?php endif; ?>
          </div>
        </div>

        <!-- Monthly Events -->
        <div class="card">
          <div class="card-header">
            <h2>Monthly Activity Count — <?= $year ?></h2>
          </div>
          <div class="card-body">
            <?php
            $monthlyMap = [];
            foreach ($monthly as $m) $monthlyMap[$m['m']] = $m;
            $maxCnt = max(array_map(fn($m) => $m['cnt'], $monthly ?: [['cnt' => 1]]), 1);
            ?>
            <div class="month-bar-wrap">
              <?php for ($m = 1; $m <= 12; $m++):
                $data = $monthlyMap[$m] ?? ['cnt' => 0, 'avg_kpi' => null];
                $cnt = isset($data['cnt']) ? (int)$data['cnt'] : 0;
                $max = isset($maxCnt) && !is_array($maxCnt) ? (int)$maxCnt : 1;

                $h = max(round(($cnt / $max) * 100), 4);
                $avgKpi = $data['avg_kpi'];
                $col = $avgKpi !== null
                  ? ($avgKpi >= 3.5 ? '#16A34A' : ($avgKpi >= 2.5 ? '#0284C7' : '#D97706'))
                  : '#E5E7EB';
              ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;">
                  <div style="flex:1;width:100%;display:flex;align-items:flex-end;">
                    <div class="month-bar" style="width:100%;height:<?= $h ?>%;background:<?= $col ?>;position:relative;" title="<?= date('F', mktime(0, 0, 0, $m, 1)) ?>: <?= $data['cnt'] ?> events<?= $data['avg_kpi'] ? ' · KPI ' . number_format($data['avg_kpi'], 1) : '' ?>">
                      <?php if ($data['cnt'] > 0): ?><span class="month-val"><?= $data['cnt'] ?></span><?php endif; ?>
                    </div>
                  </div>
                  <div class="month-label"><?= date('M', mktime(0, 0, 0, $m, 1)) ?></div>
                </div>
              <?php endfor; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Top Performing Events -->
      <div class="card">
        <div class="card-header">
          <h2>🏆 Top Performing Events — <?= $year ?></h2>
        </div>
        <div class="card-body" style="padding:0;">
          <table>
            <thead>
              <tr>
                <th>Rank</th>
                <th>Activity</th>
                <th>Faculty</th>
                <th>Date</th>
                <th>KPI Avg</th>
                <th>Satisfaction</th>
                <th>Attendance %</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($topPerf)): ?><tr>
                  <td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">No KPI data for <?= $year ?>.</td>
                </tr>
                <?php else: foreach ($topPerf as $i => $tp):
                  $attPct = $tp['target_attendance'] > 0 ? round(($tp['actual_attendance'] / $tp['target_attendance']) * 100) : null;
                  $medal = $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : ''));
                ?>
                  <tr>
                    <td style="text-align:center;font-size:1.1rem;"><?= $medal ?: $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($tp['title']) ?></strong></td>
                    <td><?= htmlspecialchars($tp['fn']) ?></td>
                    <td><?= $tp['event_date'] ? date('M j, Y', strtotime($tp['event_date'])) : '—' ?></td>
                    <td><?php
                        $k = $tp['avg_kpi'];
                        $cls = $k >= 3.5 ? 'badge-success' : ($k >= 2.5 ? 'badge-info' : ($k >= 1.5 ? 'badge-warning' : 'badge-danger'));
                        ?><span class="badge <?= $cls ?>"><?= number_format($k, 2) ?></span></td>
                    <td><?= $tp['satisfaction_score'] ? number_format($tp['satisfaction_score'], 1) . '/5' : '—' ?></td>
                    <td><?= $attPct !== null ? '<strong>' . $attPct . '%</strong>' : '—' ?></td>
                  </tr>
              <?php endforeach;
              endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>

  <script>
  (function() {
    const BASE_URL = '<?= BASE_URL ?>';
    const selector = document.getElementById('activity-selector');
    const container = document.getElementById('activity-report-container');
    const emptyMsg = document.getElementById('activity-report-empty');
    const updateBtn = document.getElementById('update-activity-insights-btn');
    const pdfBtn = document.getElementById('generate-pdf-btn');

    let activeActivityId = null;

    selector?.addEventListener('change', function() {
      const val = this.value;
      if (!val) {
        container.style.display = 'none';
        emptyMsg.style.display = 'block';
        updateBtn.style.display = 'none';
        pdfBtn.style.display = 'none';
        activeActivityId = null;
        return;
      }
      activeActivityId = val;
      loadActivityReport(val, 'check');
    });

    pdfBtn?.addEventListener('click', function() {
      if (!activeActivityId) return;
      window.open(BASE_URL + '/dean/generate-report.php?activity_id=' + activeActivityId, '_blank');
    });

    updateBtn?.addEventListener('click', function() {
      if (!activeActivityId) return;
      loadActivityReport(activeActivityId, 'analyze');
    });

    function loadActivityReport(activityId, action = 'check') {
      emptyMsg.style.display = 'none';
      
      if (action === 'analyze') {
        updateBtn.style.display = 'none';
        pdfBtn.style.display = 'none';
        let step = 0;
        const steps = [
          '🤖 Analyzing KPI performance...',
          '📊 Reviewing activity results...',
          '💡 Preparing insights...'
        ];
        container.style.display = 'block';
        container.innerHTML = `<div style="text-align:center; padding:40px;">
          <div class="spinner" style="margin: 0 auto 12px; width:24px; height:24px; border:2px solid var(--border); border-top-color:var(--accent); border-radius:50%; animation:spin 1s linear infinite;"></div>
          <div id="ai-kpi-loading-step" style="font-weight:600; color:var(--text-muted);">${steps[0]}</div>
        </div>`;

        const interval = setInterval(() => {
          step++;
          const el = document.getElementById('ai-kpi-loading-step');
          if (el && steps[step]) {
            el.textContent = steps[step];
          } else {
            clearInterval(interval);
          }
        }, 2000);
      }

      const params = new URLSearchParams({
        activity_id: activityId,
        action: action
      });

      fetch(BASE_URL + '/api/generate-kpi-analytics.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
      .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(d => {
        if (d.status === 'empty') {
          updateBtn.style.display = 'none';
          pdfBtn.style.display = 'none';
          container.innerHTML = `<div class="text-muted text-sm" style="text-align:center; padding:20px;">
            ${d.message}
          </div>`;
          return;
        }

        pdfBtn.style.display = 'inline-block';

        if (d.is_outdated) {
          updateBtn.style.display = 'inline-block';
          updateBtn.textContent = 'New Data Available - Update Insights';
        } else {
          updateBtn.style.display = 'none';
        }

        // Render calculated KPIs Table
        let kpiRows = '';
        
        // Add attendance
        if (d.kpis.attendance) {
          kpiRows += renderKpiRow(d.kpis.attendance);
        }
        // Add satisfaction
        if (d.kpis.satisfaction) {
          kpiRows += renderKpiRow(d.kpis.satisfaction);
        }
        // Add survey criteria ratings
        if (d.kpis.criteria && d.kpis.criteria.length > 0) {
          d.kpis.criteria.forEach(cr => {
            kpiRows += renderKpiRow(cr);
          });
        }

        const overallPerf = d.overall_performance !== null ? d.overall_performance + '%' : '—';
        const overallStatus = d.overall_status;
        const overallClass = getBadgeClass(overallStatus);

        let aiSection = '';
        if (d.status === 'not_generated') {
          aiSection = `<div style="text-align:center; padding:20px; border-top:1px solid var(--border); margin-top:20px;">
            <p class="text-muted text-sm" style="margin-bottom:12px;">AI analysis and interpretation is not yet generated for this activity.</p>
            <button id="generate-kpi-insights-btn" class="btn btn-primary btn-sm">Generate AI Insights</button>
          </div>`;
        } else {
          const insights = d.analytics;
          let strengthsList = insights.strengths.map(s => `<li>✓ ${escapeHtml(s)}</li>`).join('');
          let attentionList = insights.areas_of_attention.map(a => `<li>⚠ ${escapeHtml(a)}</li>`).join('');
          let keyFindingsList = insights.key_findings.map(f => `<li>• ${escapeHtml(f)}</li>`).join('');
          let recsList = insights.recommendations.map((r, i) => `<li>${i+1}. ${escapeHtml(r)}</li>`).join('');

          aiSection = `
            <div style="margin-top:20px; border-top:1px solid var(--border); padding-top:20px;">
              <div style="background:var(--bg-base); border-radius:8px; padding:16px; margin-bottom:16px;">
                <strong style="display:block; margin-bottom:6px; font-size:0.9rem; color:var(--text);">AI-GENERATED INSIGHT (Advisory Only)</strong>
                <p style="font-size:0.85rem; line-height:1.5; color:var(--text-muted); margin:0;">${escapeHtml(insights.overall_insight)}</p>
              </div>

              <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                <div>
                  <strong style="display:block; margin-bottom:6px; font-size:0.85rem; color:#16A34A;">STRENGTHS</strong>
                  <ul style="list-style:none; padding:0; margin:0; font-size:0.8rem; line-height:1.4; color:var(--text-muted); display:flex; flex-direction:column; gap:6px;">
                    ${strengthsList || '<li>No strengths reported.</li>'}
                  </ul>
                </div>
                <div>
                  <strong style="display:block; margin-bottom:6px; font-size:0.85rem; color:#D97706;">AREAS OF ATTENTION</strong>
                  <ul style="list-style:none; padding:0; margin:0; font-size:0.8rem; line-height:1.4; color:var(--text-muted); display:flex; flex-direction:column; gap:6px;">
                    ${attentionList || '<li>No areas requiring urgent attention.</li>'}
                  </ul>
                </div>
              </div>

              <div style="margin-bottom:16px;">
                <strong style="display:block; margin-bottom:6px; font-size:0.85rem; color:var(--text);">KEY FINDINGS</strong>
                <ul style="list-style:none; padding:0; margin:0; font-size:0.8rem; line-height:1.4; color:var(--text-muted); display:flex; flex-direction:column; gap:6px;">
                  ${keyFindingsList || '<li>No key findings reported.</li>'}
                </ul>
              </div>

              <div>
                <strong style="display:block; margin-bottom:6px; font-size:0.85rem; color:var(--accent);">RECOMMENDATIONS</strong>
                <ul style="list-style:none; padding:0; margin:0; font-size:0.8rem; line-height:1.4; color:var(--text-muted); display:flex; flex-direction:column; gap:6px;">
                  ${recsList || '<li>No recommendations generated.</li>'}
                </ul>
              </div>

              <div style="margin-top:16px; font-size:0.75rem; color:var(--text-muted); border-top:1px dashed var(--border); padding-top:8px; display:flex; justify-content:space-between;">
                <span><strong>Confidence:</strong> ${escapeHtml(insights.confidence_note)}</span>
                <span>Last updated: ${d.last_updated || 'Just now'}</span>
              </div>
            </div>
          `;
        }

        container.innerHTML = `
          <!-- Overall strip -->
          <div style="display:flex; justify-content:space-between; align-items:center; background:var(--bg-base); border-radius:10px; padding:16px 20px; margin-bottom:20px; border:1px solid var(--border);">
            <div>
              <div style="font-size:0.85rem; color:var(--text-muted); font-weight:600; text-transform:uppercase;">Overall KPI Performance</div>
              <div style="font-size:1.6rem; font-weight:800; font-family:'Plus Jakarta Sans',sans-serif; color:var(--accent); margin-top:2px;">${overallPerf}</div>
            </div>
            <div>
              <span class="badge ${overallClass}" style="font-size:0.85rem; padding:6px 12px;">${overallStatus}</span>
            </div>
          </div>

          <!-- KPI Details Table -->
          <div class="table-wrap">
            <table style="width:100%;">
              <thead>
                <tr>
                  <th style="width:40%;">KPI / Indicator</th>
                  <th style="width:15%;">Target</th>
                  <th style="width:15%;">Actual</th>
                  <th style="width:15%;">Achievement</th>
                  <th style="width:15%;">Status</th>
                </tr>
              </thead>
              <tbody>
                ${kpiRows}
              </tbody>
            </table>
          </div>

          <!-- AI generated section -->
          <div id="ai-insights-report-section">
            ${aiSection}
          </div>
          
          <div style="font-size:0.75rem; color:var(--text-muted); text-align:center; margin-top:20px; font-style:italic;">
            AI-generated insights are advisory only and should be reviewed by authorized administrators before making decisions.
          </div>
        `;

        document.getElementById('generate-kpi-insights-btn')?.addEventListener('click', () => {
          loadActivityReport(activityId, 'analyze');
        });
      })
      .catch(err => {
        console.error(err);
        updateBtn.style.display = 'none';
        pdfBtn.style.display = 'none';
        container.innerHTML = `<div style="text-align:center; padding:20px; color:var(--danger);">
          <p class="text-sm" style="margin-bottom:8px;">AI insights are temporarily unavailable.</p>
          <button id="retry-kpi-btn" class="btn btn-outline btn-sm">Retry</button>
        </div>`;
        document.getElementById('retry-kpi-btn')?.addEventListener('click', () => loadActivityReport(activityId, 'check'));
      });
    }

    function renderKpiRow(kpi) {
      const cls = getBadgeClass(kpi.status);
      return `
        <tr>
          <td><strong>${escapeHtml(kpi.indicator)}</strong></td>
          <td>${escapeHtml(kpi.target)}</td>
          <td>${escapeHtml(kpi.actual)}</td>
          <td><strong>${escapeHtml(kpi.achievement)}</strong></td>
          <td><span class="badge ${cls}">${escapeHtml(kpi.status)}</span></td>
        </tr>
      `;
    }

    function getBadgeClass(status) {
      switch (status) {
        case 'Exceeded Target': return 'badge-success';
        case 'Met Target': return 'badge-info';
        case 'Near Target': return 'badge-warning';
        case 'Below Target': return 'badge-danger';
        default: return 'badge-secondary';
      }
    }

    function escapeHtml(text) {
      if (!text) return '';
      return text.toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }
  })();
  </script>
</body>

</html>