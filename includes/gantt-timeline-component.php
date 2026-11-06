<?php
/**
 * includes/gantt-timeline-component.php
 *
 * Reusable Gantt Chart and Activity Timeline Component for STI-ARMES.
 * Shared across Dean, Program Head, and Faculty roles.
 *
 * Expected parameters:
 *   $ganttTasks   - Array of enriched task records
 *   $ganttOptions - Optional configuration array:
 *       'title'                 => Title string (default: 'Task Timeline (Gantt Visualization)')
 *       'subtitle'              => Subtitle string
 *       'viewActivityBase'      => Base URL for view activity (default: BASE_URL . '/faculty/view-activity.php')
 *       'emptyMessage'          => Message when tasks list is empty
 *       'showNotice'            => Boolean whether to show date limitation note (default: true)
 *       'canManageDeliverables' => Boolean whether user can upload/manage deliverables (default: false)
 */

if (!isset($ganttTasks) || !is_array($ganttTasks)) {
    $ganttTasks = [];
}
if (!isset($ganttOptions) || !is_array($ganttOptions)) {
    $ganttOptions = [];
}

$gTitle            = $ganttOptions['title'] ?? 'Task Timeline (Gantt Visualization)';
$gSubtitle         = $ganttOptions['subtitle'] ?? '';
$viewActivityBase  = $ganttOptions['viewActivityBase'] ?? (defined('BASE_URL') ? BASE_URL . '/faculty/view-activity.php' : 'view-activity.php');
$emptyMessage      = $ganttOptions['emptyMessage'] ?? 'No task assignments matching the current filter criteria.';
$showNotice        = $ganttOptions['showNotice'] ?? true;
$canManageDeliv    = !empty($ganttOptions['canManageDeliverables']);

// ── Compute Timeline Window ─────────────────────────────────────────
$minTimelineDate = date('Y-m-d');
$maxTimelineDate = date('Y-m-d');

if (!empty($ganttTasks)) {
    $allStartDates = array_filter(array_column($ganttTasks, 'start_date'));
    $allEndDates   = array_filter(array_column($ganttTasks, 'end_date'));

    $minTimelineDate = !empty($allStartDates) ? min($allStartDates) : date('Y-m-d');
    $maxTimelineDate = !empty($allEndDates) ? max($allEndDates) : $minTimelineDate;
    if ($maxTimelineDate < $minTimelineDate) {
        $maxTimelineDate = $minTimelineDate;
    }

    $startTs  = strtotime($minTimelineDate);
    $endTs    = strtotime($maxTimelineDate);
    $spanDays = max(1, (int)round(($endTs - $startTs) / 86400));

    // Snug scaling: adapt timeline bounds to actual visible date range without excessive empty space
    if ($spanDays <= 2) {
        $timelineStartTs = strtotime($minTimelineDate . ' -1 day');
        $timelineEndTs   = strtotime($maxTimelineDate . ' +1 day');
    } else {
        $timelineStartTs = strtotime($minTimelineDate);
        $timelineEndTs   = strtotime($maxTimelineDate . ' +1 day');
    }
} else {
    $timelineStartTs = strtotime(date('Y-m-d'));
    $timelineEndTs   = strtotime(date('Y-m-d', strtotime('+7 days')));
}

$totalTimelineDays = max(1, (int)round(($timelineEndTs - $timelineStartTs) / 86400));

// Scale marker interval: automatically adapt based on total days to keep markers legible
if ($totalTimelineDays <= 7) {
    $markerInterval = 1; // Daily
} elseif ($totalTimelineDays <= 16) {
    $markerInterval = 2; // Every 2 days
} elseif ($totalTimelineDays <= 32) {
    $markerInterval = 4; // Every 4 days
} else {
    $markerInterval = max(7, (int)ceil($totalTimelineDays / 8)); // Weekly or evenly distributed
}

$timelineMarkers = [];
for ($d = 0; $d < $totalTimelineDays; $d += $markerInterval) {
    $timelineMarkers[] = [
        'ts'        => $timelineStartTs + ($d * 86400),
        'offsetPct' => round(($d / $totalTimelineDays) * 100, 2)
    ];
}
// Add final edge marker if last gap is significant
$lastMarker = end($timelineMarkers);
if ($lastMarker && ($totalTimelineDays - (($lastMarker['ts'] - $timelineStartTs) / 86400) >= ($markerInterval * 0.6))) {
    $timelineMarkers[] = [
        'ts'        => $timelineEndTs,
        'offsetPct' => 100
    ];
}
?>

<?php if (!defined('GANTT_TIMELINE_STYLES_LOADED')): define('GANTT_TIMELINE_STYLES_LOADED', true); ?>
<style>
  /* ── Gantt Chart Visualization Reusable Styling ── */
  .gantt-container {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: var(--shadow-sm);
    overflow: hidden;
  }
  .gantt-header-title {
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
  }
  .gantt-limitation-notice {
    padding: 10px 16px;
    background: rgba(2, 132, 199, 0.06);
    border-bottom: 1px solid var(--border);
    font-size: 0.78rem;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    gap: 8px;
  }
  [data-theme="dark"] .gantt-limitation-notice {
    background: rgba(56, 189, 248, 0.08);
  }
  .gantt-scroll-area {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    max-height: 520px;
    position: relative;
  }
  .gantt-chart-table {
    width: 100%;
    min-width: 720px;
    border-collapse: collapse;
    table-layout: fixed;
  }
  .gantt-col-meta {
    width: 280px;
    padding: 10px 14px;
    border-right: 1px solid var(--border);
    background: var(--bg-card);
    vertical-align: middle;
    position: sticky;
    left: 0;
    z-index: 4;
  }
  .gantt-col-timeline {
    padding: 10px 16px;
    position: relative;
    vertical-align: middle;
  }
  .gantt-timeline-scale {
    border-bottom: 1px solid var(--border);
    background: var(--border-light, #f8fafd);
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .gantt-timeline-scale th.gantt-col-meta {
    z-index: 5;
    background: var(--border-light, #f8fafd);
  }
  [data-theme="dark"] .gantt-timeline-scale {
    background: #0d1726;
  }
  [data-theme="dark"] .gantt-timeline-scale th.gantt-col-meta {
    background: #0d1726;
  }
  .gantt-timeline-scale-wrap {
    position: relative;
    height: 26px;
    width: 100%;
  }
  .gantt-scale-marker {
    position: absolute;
    transform: translateX(-50%);
    font-size: 0.68rem;
    font-weight: 700;
    color: var(--text-muted);
    white-space: nowrap;
    user-select: none;
  }
  .gantt-row {
    border-bottom: 1px solid var(--border-light, #f1f5f9);
    transition: background 0.15s ease;
  }
  [data-theme="dark"] .gantt-row {
    border-bottom-color: #162438;
  }
  .gantt-row:hover {
    background: rgba(2, 132, 199, 0.04);
  }
  .gantt-bar-lane {
    position: relative;
    height: 30px;
    background: transparent;
    display: flex;
    align-items: center;
    width: 100%;
  }
  .gantt-grid-guides {
    position: absolute;
    inset: 0;
    pointer-events: none;
  }
  .gantt-grid-line {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 1px;
    border-right: 1px dashed var(--border);
    opacity: 0.45;
  }
  [data-theme="dark"] .gantt-grid-line {
    border-right-color: #1e2d45;
  }
  .gantt-bar-item {
    position: absolute;
    height: 22px;
    border-radius: 6px;
    border: 1.5px solid var(--bar-border-color, #0284c7);
    box-sizing: border-box;
    cursor: pointer;
    display: flex;
    align-items: center;
    padding: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    transition: transform 0.15s ease, filter 0.15s ease, box-shadow 0.15s ease;
    overflow: hidden;
    white-space: nowrap;
    user-select: none;
    z-index: 2;
  }
  .gantt-bar-item:hover {
    transform: translateY(-1px);
    filter: brightness(1.08);
    z-index: 10;
    box-shadow: 0 3px 8px rgba(0,0,0,0.18);
  }
  .gantt-bar-pct-fill {
    position: absolute;
    top: 0;
    left: 0;
    bottom: 0;
    pointer-events: none;
    z-index: 1;
    transition: width 0.2s ease;
  }
  .gantt-bar-pct-fill.has-divider {
    border-right: 2px solid rgba(255, 255, 255, 0.85);
    border-radius: 4px 0 0 4px;
  }
  .gantt-bar-pct-fill.is-complete {
    border-radius: 4px;
    border-right: none;
  }
  .gantt-bar-item.gantt-status-not-started {
    border-style: dashed;
  }
  .gantt-bar-label {
    position: relative;
    font-size: 0.68rem;
    font-weight: 800;
    color: #ffffff;
    text-shadow: 0 1px 2px rgba(0,0,0,0.65);
    z-index: 3;
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 0 6px;
    line-height: 1;
    pointer-events: none;
  }
  .gantt-bar-label.gantt-label-zero {
    color: var(--text-main);
    text-shadow: none;
  }

  /* ── Inspection Modal Styling ── */
  .task-inspect-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    padding: 16px;
  }
  .task-inspect-modal-dialog {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-lg, 0 10px 25px rgba(0,0,0,0.2));
    width: 100%;
    max-width: 620px;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
  }
  .task-inspect-modal-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .task-inspect-modal-body {
    padding: 20px;
    overflow-y: auto;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
  }
  .inspect-info-tile {
    background: var(--border-light, #f8fafd);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
  }
  [data-theme="dark"] .inspect-info-tile {
    background: #0d1726;
  }
  .tile-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-muted);
    letter-spacing: 0.4px;
    margin-bottom: 4px;
  }
  .tile-value {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-main);
    word-break: break-word;
  }

  @media (max-width: 640px) {
    .gantt-col-meta {
      width: 170px;
      padding: 8px 10px;
    }
    .task-inspect-modal-body {
      grid-template-columns: 1fr;
    }
  }
  @media (max-width: 480px) {
    .gantt-col-meta {
      width: 135px;
      padding: 6px 8px;
      font-size: 0.72rem;
    }
    .gantt-task-name {
      font-size: 0.76rem !important;
    }
    .gantt-chart-table {
      min-width: 580px;
    }
    .task-inspect-modal-dialog {
      max-height: 92dvh;
    }
    .task-inspect-modal-header {
      padding: 12px 14px;
    }
    .task-inspect-modal-body {
      padding: 12px;
      gap: 10px;
    }
  }
</style>
<?php endif; ?>

<div class="gantt-container" id="ganttSection">
  <div class="gantt-header-title">
    <div style="display:flex;align-items:center;gap:10px;">
      <span style="font-size:1.15rem;">📊</span>
      <div>
        <h3 style="margin:0;font-size:0.95rem;font-weight:700;color:var(--text-main);">
          <?= htmlspecialchars($gTitle) ?>
        </h3>
        <?php if (!empty($gSubtitle)): ?>
          <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars($gSubtitle) ?></div>
        <?php endif; ?>
      </div>
    </div>
    <div style="font-size:0.75rem;color:var(--text-muted);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
      <span style="display:inline-flex;align-items:center;gap:4px;">
        <span style="width:8px;height:8px;border-radius:50%;background:#10b981;"></span> Completed
      </span>
      <span style="display:inline-flex;align-items:center;gap:4px;">
        <span style="width:8px;height:8px;border-radius:50%;background:#0284c7;"></span> On Track
      </span>
      <span style="display:inline-flex;align-items:center;gap:4px;">
        <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;"></span> At Risk
      </span>
      <span style="display:inline-flex;align-items:center;gap:4px;">
        <span style="width:8px;height:8px;border-radius:50%;background:#ef4444;"></span> Delayed
      </span>
    </div>
  </div>

  <?php if ($showNotice): ?>
  <!-- Clear Documentation of Schema Dates Limitation -->
  <div class="gantt-limitation-notice">
    <svg style="width:16px;height:16px;min-width:16px;color:#0284c7;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <div>
      <strong>Timeline Date Reference:</strong> Task bars span from <em>Creation Date</em> (<code>created_at</code>) to <em>Target Deadline</em> (<code>due_date</code>). If no deadline is specified, the parent activity date is referenced. Start dates are not artificially invented.
    </div>
  </div>
  <?php endif; ?>

  <div class="gantt-scroll-area">
    <table class="gantt-chart-table">
      <thead>
        <tr class="gantt-timeline-scale">
          <th class="gantt-col-meta" style="text-align:left;">Task / Proponent Details</th>
          <th class="gantt-col-timeline" style="padding:0;">
            <div class="gantt-timeline-scale-wrap">
              <?php foreach ($timelineMarkers as $tm): ?>
                <div class="gantt-scale-marker" style="left:<?= $tm['offsetPct'] ?>%;">
                  <?= date('M j', $tm['ts']) ?>
                </div>
              <?php endforeach; ?>
            </div>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($ganttTasks)): ?>
          <tr>
            <td colspan="2" style="text-align:center;padding:36px;color:var(--text-muted);font-size:0.85rem;">
              <?= htmlspecialchars($emptyMessage) ?>
            </td>
          </tr>
        <?php else: foreach ($ganttTasks as $t):
          $sTs = strtotime($t['start_date'] ?? date('Y-m-d'));
          $eTs = strtotime($t['end_date'] ?? date('Y-m-d'));
          if ($eTs < $sTs) $eTs = $sTs;

          // Calculate percentage offsets
          $offsetDays   = max(0, ($sTs - $timelineStartTs) / 86400);
          $durationDays = max(1, (($eTs - $sTs) / 86400) + 1);

          $leftPct     = min(96, max(0, round(($offsetDays / $totalTimelineDays) * 100, 2)));
          $widthPct    = max(3.5, min(100 - $leftPct, round(($durationDays / $totalTimelineDays) * 100, 2)));
          $statusClass = strtolower(str_replace(' ', '-', $t['status'] ?? 'not-started'));
          $tTimingKey  = strtolower(str_replace(' ', '-', $t['timing_key'] ?? 'on-track'));
          $timingColor = $t['timing_color'] ?? '#0284c7';
          $timingBg    = $t['timing_bg'] ?? 'rgba(2, 132, 199, 0.12)';
          $compPct     = (int)($t['completion_pct'] ?? 0);
        ?>
          <tr class="gantt-row">
            <td class="gantt-col-meta">
              <div style="font-weight:700;color:var(--text-main);font-size:0.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?= htmlspecialchars($t['task_title']) ?>">
                <?= htmlspecialchars($t['task_title']) ?>
              </div>
              <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;display:flex;align-items:center;gap:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <a href="<?= htmlspecialchars($viewActivityBase . '?id=' . (int)$t['activity_id']) ?>" style="color:#0284c7;text-decoration:none;" title="View Activity">
                  <?= htmlspecialchars(mb_strimwidth($t['activity_title'] ?? 'Activity', 0, 22, '...')) ?>
                </a>
                <?php if (!empty($t['member'])): ?>
                  &bull; <span title="Assigned Member"><?= htmlspecialchars(mb_strimwidth($t['member'], 0, 18, '...')) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="gantt-col-timeline">
              <div class="gantt-bar-lane">
                <!-- Vertical Guide Lines -->
                <div class="gantt-grid-guides">
                  <?php foreach ($timelineMarkers as $tm): ?>
                    <div class="gantt-grid-line" style="left:<?= $tm['offsetPct'] ?>%;"></div>
                  <?php endforeach; ?>
                </div>

                <!-- Gantt Bar -->
                <div class="gantt-bar-item gantt-status-<?= $statusClass ?> gantt-timing-<?= $tTimingKey ?>"
                     style="left:<?= $leftPct ?>%;width:<?= $widthPct ?>%;border-color:<?= $timingColor ?>;background:<?= $timingBg ?>;"
                     onclick="openTaskInspection(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>)"
                     title="<?= htmlspecialchars($t['task_title']) ?> &bull; <?= htmlspecialchars($t['timing_label'] ?? '') ?> (<?= $compPct ?>%) &bull; <?= date('M j', $sTs) ?> - <?= date('M j', $eTs) ?>">

                  <?php if ($compPct > 0): ?>
                    <div class="gantt-bar-pct-fill <?= $compPct >= 100 ? 'is-complete' : 'has-divider' ?>"
                         style="width:<?= $compPct ?>%;background:<?= $timingColor ?>;"></div>
                  <?php endif; ?>

                  <span class="gantt-bar-label <?= $compPct === 0 ? 'gantt-label-zero' : '' ?>"
                        <?= ($compPct === 0 && ($t['timing_key'] ?? '') === 'Delayed') ? 'style="color:#ef4444;"' : '' ?>>
                    <?php if (($t['status'] ?? '') === 'Completed' || $compPct === 100): ?>
                      ✓ 100%
                    <?php elseif ($compPct === 0): ?>
                      <?= ($t['timing_key'] ?? '') === 'Delayed' ? '⏱ 0%' : '0%' ?>
                    <?php elseif (($t['timing_key'] ?? '') === 'Delayed'): ?>
                      ⏱ <?= $compPct ?>%
                    <?php else: ?>
                      <?= $compPct ?>%
                    <?php endif; ?>
                  </span>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── Inspection Modal (Strictly Read-Only with Optional Deliverable Quick-Action) ── -->
<div class="task-inspect-modal-backdrop" id="taskInspectModal" onclick="closeTaskInspection(event)">
  <div class="task-inspect-modal-dialog">
    <div class="task-inspect-modal-header">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:1.2rem;">🔍</span>
        <div>
          <h3 style="margin:0;font-size:1.05rem;font-weight:700;color:var(--text-main);" id="inspectModalTitle">Task Details</h3>
          <div style="font-size:0.75rem;color:var(--text-muted);">Inspection &amp; Deliverables History</div>
        </div>
      </div>
      <button type="button" class="btn-close" style="background:transparent;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-muted);" onclick="closeTaskInspectionDirect()">&times;</button>
    </div>

    <div class="task-inspect-modal-body">
      <div class="inspect-info-tile" style="grid-column: 1 / -1;">
        <div class="tile-label">Task Assignment</div>
        <div class="tile-value" id="inspectTaskTitle" style="font-size:0.95rem;font-weight:800;color:#0284c7;">—</div>
        <div style="font-size:0.78rem;color:var(--text-secondary);margin-top:4px;" id="inspectTaskDesc">—</div>
      </div>

      <div class="inspect-info-tile">
        <div class="tile-label">Parent Activity</div>
        <div class="tile-value">
          <a href="#" id="inspectActivityLink" style="color:#0284c7;text-decoration:none;font-weight:700;">—</a>
        </div>
      </div>

      <div class="inspect-info-tile">
        <div class="tile-label">Activity Spearhead</div>
        <div class="tile-value" id="inspectLeader">—</div>
      </div>

      <div class="inspect-info-tile">
        <div class="tile-label">Committee</div>
        <div class="tile-value" id="inspectCommittee">—</div>
      </div>

      <div class="inspect-info-tile">
        <div class="tile-label">Assigned Member</div>
        <div class="tile-value" id="inspectMember">—</div>
      </div>

      <div class="inspect-info-tile">
        <div class="tile-label">Target Due Date</div>
        <div class="tile-value" id="inspectDueDate">—</div>
      </div>

      <div class="inspect-info-tile">
        <div class="tile-label">Creation Timestamp</div>
        <div class="tile-value" id="inspectCreatedAt">—</div>
      </div>

      <div class="inspect-info-tile">
        <div class="tile-label">Current Status</div>
        <div class="tile-value" id="inspectStatus">—</div>
      </div>

      <div class="inspect-info-tile">
        <div class="tile-label">Timing Indicator</div>
        <div class="tile-value" id="inspectTiming">—</div>
      </div>

      <div class="inspect-info-tile" style="grid-column: 1 / -1;">
        <div class="tile-label">Deliverables &amp; Evidence</div>
        <div id="inspectDeliverablesList" style="font-size:0.82rem;margin-top:6px;">
          <span style="color:var(--text-muted);font-style:italic;">No deliverable files uploaded yet for this task.</span>
        </div>
      </div>
    </div>

    <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--bg-card);flex-wrap:wrap;gap:8px;">
      <div style="display:flex;align-items:center;gap:8px;">
        <a href="#" id="inspectActivityBtn" class="btn btn-primary btn-sm">
          Open Full Activity &rarr;
        </a>
        <button type="button" id="inspectManageDelivBtn" class="btn btn-outline btn-sm" style="display:none;">
          📁 Submit Deliverable
        </button>
      </div>
      <button type="button" class="btn btn-outline btn-sm" onclick="closeTaskInspectionDirect()">Close</button>
    </div>
  </div>
</div>

<script>
  if (typeof window.escapeGanttHtml !== 'function') {
    window.escapeGanttHtml = function(str) {
      if (!str) return '';
      return String(str).replace(/[&<>"']/g, function(m) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
      });
    };
  }

  // ── Inspection Modal Logic ──
  function openTaskInspection(task) {
    if (!task) return;
    const viewBase = '<?= addslashes($viewActivityBase) ?>';

    const titleEl = document.getElementById('inspectModalTitle');
    if (titleEl) titleEl.textContent = task.task_title || 'Task Details';

    const taskTitleEl = document.getElementById('inspectTaskTitle');
    if (taskTitleEl) taskTitleEl.textContent = task.task_title || 'Untitled Task';

    const taskDescEl = document.getElementById('inspectTaskDesc');
    if (taskDescEl) taskDescEl.textContent = task.task_description || 'No additional description provided.';

    const actLink = document.getElementById('inspectActivityLink');
    if (actLink) {
      actLink.textContent = task.activity_title || 'View Activity';
      actLink.href = viewBase + '?id=' + encodeURIComponent(task.activity_id);
    }

    const actBtn = document.getElementById('inspectActivityBtn');
    if (actBtn) {
      actBtn.href = viewBase + '?id=' + encodeURIComponent(task.activity_id);
    }

    const leaderEl = document.getElementById('inspectLeader');
    if (leaderEl) {
      leaderEl.textContent = (task.leader_name || '—') + (task.leader_dept ? ' (' + task.leader_dept + ')' : '');
    }

    const commEl = document.getElementById('inspectCommittee');
    if (commEl) commEl.textContent = task.committee || 'General';

    const memberEl = document.getElementById('inspectMember');
    if (memberEl) {
      memberEl.textContent = (task.member || 'Unassigned') + (task.role && task.role !== '—' ? ' — ' + task.role : '');
    }

    const dueEl = document.getElementById('inspectDueDate');
    if (dueEl) dueEl.textContent = task.due_date ? task.due_date : 'No deadline set';

    const createdEl = document.getElementById('inspectCreatedAt');
    if (createdEl) createdEl.textContent = task.created_at ? task.created_at : '—';

    const statusEl = document.getElementById('inspectStatus');
    if (statusEl) statusEl.innerHTML = task.status || 'Not Started';

    const timingEl = document.getElementById('inspectTiming');
    if (timingEl) timingEl.innerHTML = task.timing_badge || task.timing_label || 'On Track';

    // Quick deliverable action button if user can manage deliverables
    const manageDelivBtn = document.getElementById('inspectManageDelivBtn');
    if (manageDelivBtn) {
      if (typeof window.openDeliverablesModal === 'function') {
        manageDelivBtn.style.display = 'inline-block';
        manageDelivBtn.onclick = function() {
          closeTaskInspectionDirect();
          window.openDeliverablesModal(task.id, task.activity_id, task.task_title);
        };
      } else {
        manageDelivBtn.style.display = 'none';
      }
    }

    // Load task deliverables
    const delivContainer = document.getElementById('inspectDeliverablesList');
    if (delivContainer) {
      delivContainer.innerHTML = '<span style="color:var(--text-muted);font-style:italic;">Loading deliverables...</span>';
      fetch('<?= BASE_URL ?>/api/task-deliverables.php?action=list&task_id=' + encodeURIComponent(task.id))
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.success && d.deliverables && d.deliverables.length > 0) {
            delivContainer.innerHTML = d.deliverables.map(function(item) {
              const statusClass = item.review_status === 'approved' ? 'badge-success' : (item.review_status === 'returned_for_improvement' ? 'badge-danger' : 'badge-warning');
              const statusLabel = item.review_status === 'returned_for_improvement' ? 'Returned for Improvement' : (item.review_status === 'approved' ? 'Approved' : 'Pending Review');
              return '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;margin-bottom:6px;gap:10px;">' +
                     '<div style="min-width:0;flex:1;">' +
                       '<div style="font-weight:700;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">📎 ' + escapeGanttHtml(item.file_name) + '</div>' +
                       '<div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;">' +
                         'Uploaded by ' + escapeGanttHtml(item.uploader_name) + ' on ' + item.uploaded_at + ' &bull; ' +
                         '<span class="badge ' + statusClass + '" style="font-size:0.68rem;padding:2px 6px;">' + statusLabel + '</span>' +
                       '</div>' +
                       (item.review_notes ? '<div style="font-size:0.72rem;color:var(--text-secondary);margin-top:2px;font-style:italic;">Review Note: ' + escapeGanttHtml(item.review_notes) + '</div>' : '') +
                     '</div>' +
                     '<a href="' + item.download_url + '" target="_blank" class="btn btn-outline btn-sm" style="font-size:0.72rem;padding:3px 8px;white-space:nowrap;">Open File &rarr;</a>' +
                     '</div>';
            }).join('');
          } else {
            delivContainer.innerHTML = '<span style="color:var(--text-muted);font-style:italic;">No deliverable files uploaded yet for this task.</span>';
          }
        })
        .catch(function() {
          delivContainer.innerHTML = '<span style="color:var(--text-muted);font-style:italic;">Unable to load deliverables.</span>';
        });
    }

    const modal = document.getElementById('taskInspectModal');
    if (modal) modal.style.display = 'flex';
  }

  function closeTaskInspection(event) {
    if (event && event.target && event.target.id === 'taskInspectModal') {
      closeTaskInspectionDirect();
    }
  }

  function closeTaskInspectionDirect() {
    const modal = document.getElementById('taskInspectModal');
    if (modal) modal.style.display = 'none';
  }

  if (!window.ganttKeydownListenerAttached) {
    window.ganttKeydownListenerAttached = true;
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeTaskInspectionDirect();
      }
    });
  }
</script>

