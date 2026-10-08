<?php
/**
 * dean/task-monitoring.php
 *
 * Centralized Dean Task Monitoring View
 * Provides the Dean with a centralized, read-only view of task assignments across all activities.
 *
 * Scope & Permissions:
 * - Read-only view of all authorized activity task assignments
 * - Summary statistics (Total Tasks, Completed, In Progress, Not Started, Delayed, Overall Completion %)
 * - Multi-criteria filtering (Activity, Spearheading Department / Source, Committee, Member, Status, Timing)
 * - Detail table with timing indicator (Completed, On Track, At Risk, Delayed)
 * - Read-only Gantt timeline visualization based on stored dates (created_at to due_date)
 * - Drill-down links to view-activity.php
 * - Dean CANNOT create, edit, delete, or change status/completion percentage.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('dean');
$user = currentUser();
$db   = getDB();

// Task progress reminders check
checkTaskProgressReminders($db, $user['id'], $user['role']);

// ── Timing & Progress Calculation Functions ─────────────────────────

/**
 * Deterministically calculate task timing status without inventing data.
 *
 * @param string $status
 * @param string|null $dueDate
 * @return array
 */
function calculateTaskTiming(string $status, ?string $dueDate): array {
    if ($status === 'Completed') {
        return [
            'key'      => 'Completed',
            'label'    => 'Completed',
            'badge'    => '<span class="badge badge-success" style="background:#10b981;color:#fff;">Completed</span>',
            'color'    => '#10b981',
            'bg_light' => 'rgba(16, 185, 129, 0.12)'
        ];
    }

    $today = date('Y-m-d');

    // Explicit Delayed status or past due date
    if ($status === 'Delayed') {
        return [
            'key'      => 'Delayed',
            'label'    => 'Delayed',
            'badge'    => '<span class="badge badge-danger" style="background:#ef4444;color:#fff;">Delayed</span>',
            'color'    => '#ef4444',
            'bg_light' => 'rgba(239, 68, 68, 0.14)'
        ];
    }

    if (!empty($dueDate)) {
        if ($dueDate < $today) {
            return [
                'key'      => 'Delayed',
                'label'    => 'Delayed',
                'badge'    => '<span class="badge badge-danger" style="background:#ef4444;color:#fff;">Delayed (Overdue)</span>',
                'color'    => '#ef4444',
                'bg_light' => 'rgba(239, 68, 68, 0.14)'
            ];
        }

        // Target date approaching within 3 days
        $diffDays = (int)ceil((strtotime($dueDate) - strtotime($today)) / 86400);
        if ($diffDays >= 0 && $diffDays <= 3) {
            return [
                'key'      => 'At Risk',
                'label'    => 'At Risk',
                'badge'    => '<span class="badge badge-warning" style="background:#f59e0b;color:#fff;">At Risk (' . $diffDays . 'd left)</span>',
                'color'    => '#f59e0b',
                'bg_light' => 'rgba(245, 158, 11, 0.15)'
            ];
        }
    }

    return [
        'key'      => 'On Track',
        'label'    => 'On Track',
        'badge'    => '<span class="badge badge-primary" style="background:#0284c7;color:#fff;">On Track</span>',
        'color'    => '#0284c7',
        'bg_light' => 'rgba(2, 132, 199, 0.12)'
    ];
}

/**
 * Return completion percentage strictly based on stored task data.
 * Checks for explicit progress in description/title, or defaults based on status.
 *
 * @param string $status
 * @param string|null $desc
 * @param string|null $title
 * @return int
 */
function getTaskCompletionPct(string $status, ?string $desc = '', ?string $title = '', ?int $explicitPct = null): int {
    if ($status === 'Completed') {
        return 100;
    }
    if ($explicitPct !== null && $explicitPct > 0 && $explicitPct <= 100) {
        return $explicitPct;
    }

    // Check if an explicit percentage is mentioned in description or title (e.g. "Progress: 25%", "60%", "25%")
    if (!empty($desc) && preg_match('/(?:progress|completion)?\s*[:=\-]?\s*(\d{1,3})%/i', $desc, $m)) {
        return max(0, min(100, (int)$m[1]));
    }
    if (!empty($title) && preg_match('/(?:progress|completion)?\s*[:=\-]?\s*(\d{1,3})%/i', $title, $m)) {
        return max(0, min(100, (int)$m[1]));
    }

    if ($status === 'Not Started') {
        return 0;
    }
    if ($status === 'Delayed') {
        return 25;
    }

    // Default In Progress
    return 50;
}

// ── Read Query Filters ──────────────────────────────────────────────
$filterActivityId = !empty($_GET['activity_id']) ? (int)$_GET['activity_id'] : 0;
$filterDeptSource = trim($_GET['dept_source'] ?? '');
$filterCommittee  = trim($_GET['committee'] ?? '');
$filterMember     = trim($_GET['member'] ?? '');
$filterStatus     = trim($_GET['status'] ?? '');
$filterTiming     = trim($_GET['timing'] ?? '');
$searchQuery      = trim($_GET['q'] ?? '');

// ── Fetch All Tasks with Relational Metadata ────────────────────────
$query = "
    SELECT ft.*,
           a.title as activity_title,
           a.event_date,
           a.source as activity_source,
           a.status as activity_status,
           u.id as leader_id,
           u.name as leader_name,
           u.department as leader_dept,
           u.email as leader_email,
           au.name as assigned_user_name,
           au.email as assigned_user_email,
           au.department as assigned_user_dept,
           cb.name as creator_name
    FROM faculty_tasks ft
    JOIN activities a ON ft.activity_id = a.id
    JOIN users u ON a.faculty_id = u.id
    LEFT JOIN users au ON ft.assigned_user_id = au.id
    LEFT JOIN users cb ON ft.created_by = cb.id
    ORDER BY ft.due_date ASC, ft.id DESC
";

$allRows = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Process & Enrich Rows
$enrichedTasks = [];
$activitiesList = [];
$committeesList = [];
$membersList = [];
$departmentsList = [];
$sourcesList = ['faculty' => 'Faculty Proponent', 'student_org' => 'Student Organization'];

foreach ($allRows as $r) {
    $actId = (int)$r['activity_id'];
    $activitiesList[$actId] = $r['activity_title'];

    $cRaw = trim($r['committee'] ?? '');
    $tComm = ($cRaw !== '') ? $cRaw : 'Unassigned';
    $committeesList[$tComm] = $tComm;

    if (!empty($r['assigned_user_name'])) {
        $membersList[$r['assigned_user_name']] = $r['assigned_user_name'];
    } elseif (!empty($r['faculty_name'])) {
        $membersList[$r['faculty_name']] = $r['faculty_name'];
    }
    if (!empty($r['leader_dept'])) {
        $departmentsList[$r['leader_dept']] = $r['leader_dept'];
    }

    $tTitle = $r['task_title'] ?: $r['assigned_task'] ?: 'Untitled Task';
    $tDesc  = $r['task_description'] ?: $r['contribution_desc'] ?: '';
    $tMember = $r['assigned_user_name'] ?: $r['faculty_name'] ?: 'Unassigned';
    $tRole  = $r['role_in_event'] ?: '—';
    $tDue   = !empty($r['due_date']) ? date('Y-m-d', strtotime($r['due_date'])) : null;
    $tStatus = in_array($r['status'], ['Not Started', 'In Progress', 'Completed', 'Delayed']) ? $r['status'] : 'Not Started';
    $rawPct  = (isset($r['completion_pct']) && $r['completion_pct'] !== null && $r['completion_pct'] !== '') ? (int)$r['completion_pct'] : null;
    $tPct    = getTaskCompletionPct($tStatus, $tDesc, $tTitle, $rawPct);
    $timing  = calculateTaskTiming($tStatus, $tDue);

    // Stored creation timestamp represents the available start timestamp
    $createdAtDate = !empty($r['created_at']) ? date('Y-m-d', strtotime($r['created_at'])) : date('Y-m-d');
    $startDate = $createdAtDate;
    $endDate   = $tDue ?: (!empty($r['event_date']) ? date('Y-m-d', strtotime($r['event_date'])) : $createdAtDate);

    $enrichedTasks[] = [
        'id'                  => (int)$r['id'],
        'activity_id'         => $actId,
        'activity_title'      => $r['activity_title'],
        'activity_source'     => $r['activity_source'],
        'activity_status'     => $r['activity_status'],
        'leader_id'           => (int)$r['leader_id'],
        'leader_name'         => $r['leader_name'],
        'leader_dept'         => $r['leader_dept'] ?: 'Unassigned Dept',
        'task_title'          => $tTitle,
        'task_description'    => $tDesc,
        'committee'           => $tComm,
        'assigned_user_id'    => $r['assigned_user_id'] ? (int)$r['assigned_user_id'] : null,
        'member'              => $tMember,
        'role'                => $tRole,
        'due_date'            => $tDue,
        'start_date'          => $startDate,
        'end_date'            => $endDate,
        'status'              => $tStatus,
        'completion_pct'      => $tPct,
        'timing_key'          => $timing['key'],
        'timing_label'        => $timing['label'],
        'timing_badge'        => $timing['badge'],
        'timing_color'        => $timing['color'],
        'timing_bg'           => $timing['bg_light'],
        'created_at'          => $r['created_at'],
    ];
}

ksort($activitiesList);
sort($committeesList);
sort($membersList);
sort($departmentsList);

// ── Filter Data ─────────────────────────────────────────────────────
$filteredTasks = array_filter($enrichedTasks, function($t) use (
    $filterActivityId, $filterDeptSource, $filterCommittee, $filterMember, $filterStatus, $filterTiming, $searchQuery
) {
    if ($filterActivityId && $t['activity_id'] !== $filterActivityId) {
        return false;
    }
    if ($filterDeptSource !== '') {
        if (str_starts_with($filterDeptSource, 'src:')) {
            $src = substr($filterDeptSource, 4);
            if ($t['activity_source'] !== $src) return false;
        } elseif (str_starts_with($filterDeptSource, 'dept:')) {
            $dept = substr($filterDeptSource, 5);
            if ($t['leader_dept'] !== $dept) return false;
        }
    }
    if ($filterCommittee !== '' && strcasecmp($t['committee'], $filterCommittee) !== 0) {
        return false;
    }
    if ($filterMember !== '' && strcasecmp($t['member'], $filterMember) !== 0) {
        return false;
    }
    if ($filterStatus !== '' && strcasecmp($t['status'], $filterStatus) !== 0) {
        return false;
    }
    if ($filterTiming !== '' && strcasecmp($t['timing_key'], $filterTiming) !== 0) {
        return false;
    }
    if ($searchQuery !== '') {
        $q = mb_strtolower($searchQuery);
        $searchCorpus = mb_strtolower(
            $t['task_title'] . ' ' .
            $t['task_description'] . ' ' .
            $t['activity_title'] . ' ' .
            $t['leader_name'] . ' ' .
            $t['committee'] . ' ' .
            $t['member'] . ' ' .
            $t['role']
        );
        if (!str_contains($searchCorpus, $q)) {
            return false;
        }
    }
    return true;
});

// Re-index array
$filteredTasks = array_values($filteredTasks);

// ── Calculate Summary Metrics ───────────────────────────────────────
$totalTasksCount = count($filteredTasks);
$completedCount  = 0;
$inProgressCount = 0;
$notStartedCount = 0;
$delayedCount    = 0;
$pctSum          = 0;

foreach ($filteredTasks as $t) {
    if ($t['status'] === 'Completed') {
        $completedCount++;
    } elseif ($t['status'] === 'In Progress') {
        $inProgressCount++;
    } elseif ($t['status'] === 'Not Started') {
        $notStartedCount++;
    }
    
    if ($t['timing_key'] === 'Delayed') {
        $delayedCount++;
    }
    $pctSum += $t['completion_pct'];
}

$overallCompletionPct = $totalTasksCount > 0 ? round(($completedCount / $totalTasksCount) * 100, 1) : 0;
$averageProgressPct   = $totalTasksCount > 0 ? round($pctSum / $totalTasksCount, 1) : 0;

// ── Committee Monitoring Summary Aggregation ─────────────────────────
$committeeSummary = [];

foreach ($filteredTasks as $t) {
    $cName = !empty(trim($t['committee'] ?? '')) ? trim($t['committee']) : 'Unassigned';
    if (!isset($committeeSummary[$cName])) {
        $committeeSummary[$cName] = [
            'committee'      => $cName,
            'total'          => 0,
            'completed'      => 0,
            'in_progress'    => 0,
            'not_started'    => 0,
            'delayed'        => 0,
            'at_risk'        => 0,
            'pct_sum'        => 0,
            'avg_completion' => 0.0,
            'status'         => 'On Track',
        ];
    }

    $committeeSummary[$cName]['total']++;

    if ($t['status'] === 'Completed') {
        $committeeSummary[$cName]['completed']++;
    } elseif ($t['status'] === 'In Progress') {
        $committeeSummary[$cName]['in_progress']++;
    } elseif ($t['status'] === 'Not Started') {
        $committeeSummary[$cName]['not_started']++;
    }

    // Delayed: explicit Delayed status OR overdue (past due date & incomplete)
    if ($t['timing_key'] === 'Delayed') {
        $committeeSummary[$cName]['delayed']++;
    } elseif ($t['timing_key'] === 'At Risk') {
        $committeeSummary[$cName]['at_risk']++;
    }

    $committeeSummary[$cName]['pct_sum'] += (float)$t['completion_pct'];
}

foreach ($committeeSummary as &$c) {
    if ($c['total'] > 0) {
        $c['avg_completion'] = round($c['pct_sum'] / $c['total'], 1);
    } else {
        $c['avg_completion'] = 0.0;
    }

    // Performance status determination:
    // - Delayed: If committee has any delayed tasks
    // - At Risk: If no delayed tasks, but has at-risk tasks
    // - On Track: All tasks completed or on schedule
    if ($c['delayed'] > 0) {
        $c['status'] = 'Delayed';
    } elseif ($c['at_risk'] > 0) {
        $c['status'] = 'At Risk';
    } else {
        $c['status'] = 'On Track';
    }
}
unset($c);

// Sort committees alphabetically, with 'Unassigned' placed at the end
ksort($committeeSummary, SORT_NATURAL | SORT_FLAG_CASE);
if (isset($committeeSummary['Unassigned'])) {
    $unassignedGroup = $committeeSummary['Unassigned'];
    unset($committeeSummary['Unassigned']);
    $committeeSummary['Unassigned'] = $unassignedGroup;
}

// ── Member Performance Summary Aggregation ──────────────────────────
$memberSummary = [];

foreach ($filteredTasks as $t) {
    $mName = !empty(trim($t['member'] ?? '')) ? trim($t['member']) : 'Unassigned';
    if (!isset($memberSummary[$mName])) {
        $memberSummary[$mName] = [
            'member'         => $mName,
            'committees'     => [],
            'total'          => 0,
            'completed'      => 0,
            'in_progress'    => 0,
            'not_started'    => 0,
            'delayed'        => 0,
            'at_risk'        => 0,
            'pct_sum'        => 0,
            'avg_completion' => 0.0,
            'status'         => 'On Track',
        ];
    }

    $memberSummary[$mName]['total']++;

    // Track committee association
    $cVal = !empty(trim($t['committee'] ?? '')) ? trim($t['committee']) : 'Unassigned';
    if (!in_array($cVal, $memberSummary[$mName]['committees'], true)) {
        $memberSummary[$mName]['committees'][] = $cVal;
    }

    if ($t['status'] === 'Completed') {
        $memberSummary[$mName]['completed']++;
    } elseif ($t['status'] === 'In Progress') {
        $memberSummary[$mName]['in_progress']++;
    } elseif ($t['status'] === 'Not Started') {
        $memberSummary[$mName]['not_started']++;
    }

    // Delayed: explicit Delayed status OR overdue (past due date & incomplete)
    if ($t['timing_key'] === 'Delayed') {
        $memberSummary[$mName]['delayed']++;
    } elseif ($t['timing_key'] === 'At Risk') {
        $memberSummary[$mName]['at_risk']++;
    }

    $memberSummary[$mName]['pct_sum'] += (float)$t['completion_pct'];
}

foreach ($memberSummary as &$m) {
    if ($m['total'] > 0) {
        $m['avg_completion'] = round($m['pct_sum'] / $m['total'], 1);
    } else {
        $m['avg_completion'] = 0.0;
    }

    // Performance status determination:
    // - Delayed: member has one or more delayed/overdue tasks
    // - At Risk: no delayed tasks, but one or more tasks are due within 3 days
    // - On Track: no delayed/at-risk tasks, or all tasks completed
    if ($m['delayed'] > 0) {
        $m['status'] = 'Delayed';
    } elseif ($m['at_risk'] > 0) {
        $m['status'] = 'At Risk';
    } else {
        $m['status'] = 'On Track';
    }
}
unset($m);

// Sort members alphabetically, with 'Unassigned' placed at the end
ksort($memberSummary, SORT_NATURAL | SORT_FLAG_CASE);
if (isset($memberSummary['Unassigned'])) {
    $unassignedMemberGroup = $memberSummary['Unassigned'];
    unset($memberSummary['Unassigned']);
    $memberSummary['Unassigned'] = $unassignedMemberGroup;
}

// ── Spearhead / Department Performance Summary Aggregation ───────────
$departmentSummary = [];

foreach ($filteredTasks as $t) {
    $rawDept = trim($t['leader_dept'] ?? '');
    $dName = (!empty($rawDept) && $rawDept !== 'Unassigned Dept') ? $rawDept : 'Unassigned';
    if (!isset($departmentSummary[$dName])) {
        $departmentSummary[$dName] = [
            'department'     => $dName,
            'activities'     => [],
            'total_tasks'    => 0,
            'completed'      => 0,
            'in_progress'    => 0,
            'not_started'    => 0,
            'delayed'        => 0,
            'at_risk'        => 0,
            'pct_sum'        => 0,
            'avg_completion' => 0.0,
            'status'         => 'On Track',
        ];
    }

    $departmentSummary[$dName]['total_tasks']++;

    $actId = (int)$t['activity_id'];
    if (!in_array($actId, $departmentSummary[$dName]['activities'], true)) {
        $departmentSummary[$dName]['activities'][] = $actId;
    }

    if ($t['status'] === 'Completed') {
        $departmentSummary[$dName]['completed']++;
    } elseif ($t['status'] === 'In Progress') {
        $departmentSummary[$dName]['in_progress']++;
    } elseif ($t['status'] === 'Not Started') {
        $departmentSummary[$dName]['not_started']++;
    }

    // Delayed: explicit Delayed status OR overdue (past due date & incomplete)
    if ($t['timing_key'] === 'Delayed') {
        $departmentSummary[$dName]['delayed']++;
    } elseif ($t['timing_key'] === 'At Risk') {
        $departmentSummary[$dName]['at_risk']++;
    }

    $departmentSummary[$dName]['pct_sum'] += (float)$t['completion_pct'];
}

foreach ($departmentSummary as &$d) {
    if ($d['total_tasks'] > 0) {
        $d['avg_completion'] = round($d['pct_sum'] / $d['total_tasks'], 1);
    } else {
        $d['avg_completion'] = 0.0;
    }

    // Overall performance status determination:
    // - Delayed: one or more delayed/overdue tasks
    // - At Risk: no delayed tasks, but one or more tasks due within 3 days
    // - On Track: no delayed/at-risk tasks, or all tasks completed
    if ($d['delayed'] > 0) {
        $d['status'] = 'Delayed';
    } elseif ($d['at_risk'] > 0) {
        $d['status'] = 'At Risk';
    } else {
        $d['status'] = 'On Track';
    }
}
unset($d);

// Sort departments alphabetically, with 'Unassigned' placed at the end
ksort($departmentSummary, SORT_NATURAL | SORT_FLAG_CASE);
if (isset($departmentSummary['Unassigned'])) {
    $unassignedDeptGroup = $departmentSummary['Unassigned'];
    unset($departmentSummary['Unassigned']);
    $departmentSummary['Unassigned'] = $unassignedDeptGroup;
}

// ── Compute Timeline Window for Gantt Visualization ─────────────────
$minTimelineDate = date('Y-m-d');
$maxTimelineDate = date('Y-m-d');

if (!empty($filteredTasks)) {
    $allStartDates = array_filter(array_column($filteredTasks, 'start_date'));
    $allEndDates   = array_filter(array_column($filteredTasks, 'end_date'));
    
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
        'ts' => $timelineStartTs + ($d * 86400),
        'offsetPct' => round(($d / $totalTimelineDays) * 100, 2)
    ];
}
// Add final edge marker if last gap is significant
$lastMarker = end($timelineMarkers);
if ($totalTimelineDays - (($lastMarker['ts'] - $timelineStartTs) / 86400) >= ($markerInterval * 0.6)) {
    $timelineMarkers[] = [
        'ts' => $timelineEndTs,
        'offsetPct' => 100
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Task Monitoring – STI Activity System</title>
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
    body, body *, h1, h2, h3, h4, h5, h6,
    .page-title, .topbar .page-title, .card-header h2,
    .btn, .badge, .form-control, label, select, input {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    /* ── 6-Column Stat Grid ── */
    .stat-grid-6 {
      display: grid;
      grid-template-columns: repeat(6, 1fr);
      gap: 12px;
      margin-bottom: 20px;
    }

    @media (max-width: 1200px) {
      .stat-grid-6 {
        grid-template-columns: repeat(3, 1fr);
      }
    }
    @media (max-width: 768px) {
      .stat-grid-6 {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    .stat-card-widget {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 12px 14px;
      box-shadow: var(--shadow-sm);
      display: flex;
      align-items: center;
      gap: 12px;
      position: relative;
      overflow: hidden;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .stat-card-widget:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }
    .stat-card-widget .stat-icon-wrap {
      width: 38px;
      height: 38px;
      min-width: 38px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .stat-card-widget .stat-icon-wrap svg {
      width: 18px;
      height: 18px;
    }
    .stat-card-widget .stat-val-text {
      font-size: 1.35rem;
      font-weight: 800;
      line-height: 1.1;
      color: var(--text-main);
    }
    .stat-card-widget .stat-lbl-text {
      font-size: 0.70rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted);
      margin-top: 3px;
    }
    .stat-card-widget .stat-accent-bar {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 3px;
    }

    /* Metric Card Accents */
    .metric-total .stat-icon-wrap { background: rgba(2, 132, 199, 0.12); color: #0284c7; }
    .metric-total .stat-accent-bar { background: #0284c7; }

    .metric-completed .stat-icon-wrap { background: rgba(16, 185, 129, 0.14); color: #10b981; }
    .metric-completed .stat-accent-bar { background: #10b981; }

    .metric-inprogress .stat-icon-wrap { background: rgba(14, 165, 233, 0.14); color: #0ea5e9; }
    .metric-inprogress .stat-accent-bar { background: #0ea5e9; }

    .metric-notstarted .stat-icon-wrap { background: rgba(100, 116, 139, 0.14); color: #64748b; }
    .metric-notstarted .stat-accent-bar { background: #64748b; }

    .metric-delayed .stat-icon-wrap { background: rgba(239, 68, 68, 0.14); color: #ef4444; }
    .metric-delayed .stat-accent-bar { background: #ef4444; }

    .metric-pct .stat-icon-wrap { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
    .metric-pct .stat-accent-bar { background: #f59e0b; }

    /* ── Filter Bar Styling ── */
    .filter-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 14px 16px;
      margin-bottom: 20px;
      box-shadow: var(--shadow-sm);
    }
    .filter-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 10px;
      align-items: flex-end;
    }
    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .filter-group label {
      font-size: 0.72rem;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.4px;
    }
    .filter-group select,
    .filter-group input {
      height: 34px;
      padding: 4px 10px;
      font-size: 0.80rem;
      border: 1px solid var(--border);
      border-radius: 7px;
      background: var(--bg-card);
      color: var(--text-main);
      outline: none;
      width: 100%;
    }
    .filter-group select:focus,
    .filter-group input:focus {
      border-color: var(--accent, #0284c7);
      box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.15);
    }

    /* ── Progress Bar Component ── */
    .progress-bar-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .progress-bar-track {
      flex: 1;
      height: 7px;
      background: var(--border-light, #f1f5f9);
      border-radius: 999px;
      overflow: hidden;
      min-width: 60px;
    }
    [data-theme="dark"] .progress-bar-track {
      background: #1e293b;
    }
    .progress-bar-fill {
      height: 100%;
      border-radius: 999px;
      transition: width 0.3s ease;
    }

    /* ── Gantt Chart Visualization Styling ── */
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
      scrollbar-width: thin;
      max-height: 480px;
    }
    .gantt-chart-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }
    .gantt-col-meta {
      width: 280px;
      padding: 10px 14px;
      border-right: 1px solid var(--border);
      background: var(--bg-card);
      vertical-align: middle;
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
    [data-theme="dark"] .gantt-timeline-scale {
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
      background: rgba(10, 22, 40, 0.6);
      backdrop-filter: blur(3px);
      z-index: 9999;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }
    .task-inspect-modal {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      width: 100%;
      max-width: 560px;
      box-shadow: var(--shadow-lg);
      overflow: hidden;
      animation: modalFadeIn 0.2s ease-out;
    }
    @keyframes modalFadeIn {
      from { opacity: 0; transform: scale(0.96); }
      to   { opacity: 1; transform: scale(1); }
    }
    .inspect-modal-header {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: var(--bg-card);
    }
    .inspect-modal-body {
      padding: 20px;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
      max-height: 70vh;
      overflow-y: auto;
    }
    .inspect-info-tile {
      background: var(--bg-subtle, #f8fafd);
      border: 1px solid var(--border-light, #f1f5f9);
      border-radius: 8px;
      padding: 10px 12px;
    }
    [data-theme="dark"] .inspect-info-tile {
      background: #0d1726;
      border-color: #162438;
    }
    .inspect-info-tile .tile-label {
      font-size: 0.68rem;
      font-weight: 700;
      text-transform: uppercase;
      color: var(--text-muted);
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }
    .inspect-info-tile .tile-value {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--text-main);
      word-break: break-word;
    }
  </style>
</head>

<body class="theme-dean">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  
  <div class="main-wrap">
    <!-- Topbar -->
    <header class="topbar">
      <div class="page-title">Task Monitoring — Centralized View</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <a href="<?= BASE_URL ?>/dean/activities.php" class="btn btn-outline btn-sm">All Activities</a>
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">

      <!-- 1. SUMMARY METRICS SECTION -->
      <div class="stat-grid-6">
        <!-- Total Tasks -->
        <div class="stat-card-widget metric-total">
          <div class="stat-icon-wrap">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
          </div>
          <div>
            <div class="stat-val-text" id="statTotal"><?= $totalTasksCount ?></div>
            <div class="stat-lbl-text">Total Tasks</div>
          </div>
          <div class="stat-accent-bar"></div>
        </div>

        <!-- Completed Tasks -->
        <div class="stat-card-widget metric-completed">
          <div class="stat-icon-wrap">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <div>
            <div class="stat-val-text" id="statCompleted"><?= $completedCount ?></div>
            <div class="stat-lbl-text">Completed</div>
          </div>
          <div class="stat-accent-bar"></div>
        </div>

        <!-- In Progress Tasks -->
        <div class="stat-card-widget metric-inprogress">
          <div class="stat-icon-wrap">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          </div>
          <div>
            <div class="stat-val-text" id="statInProgress"><?= $inProgressCount ?></div>
            <div class="stat-lbl-text">In Progress</div>
          </div>
          <div class="stat-accent-bar"></div>
        </div>

        <!-- Not Started Tasks -->
        <div class="stat-card-widget metric-notstarted">
          <div class="stat-icon-wrap">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 7v5"/></svg>
          </div>
          <div>
            <div class="stat-val-text" id="statNotStarted"><?= $notStartedCount ?></div>
            <div class="stat-lbl-text">Not Started</div>
          </div>
          <div class="stat-accent-bar"></div>
        </div>

        <!-- Delayed Tasks -->
        <div class="stat-card-widget metric-delayed">
          <div class="stat-icon-wrap">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          </div>
          <div>
            <div class="stat-val-text" id="statDelayed"><?= $delayedCount ?></div>
            <div class="stat-lbl-text">Delayed</div>
          </div>
          <div class="stat-accent-bar"></div>
        </div>

        <!-- Overall Completion % -->
        <div class="stat-card-widget metric-pct">
          <div class="stat-icon-wrap">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
          </div>
          <div>
            <div class="stat-val-text" id="statOverallPct"><?= $overallCompletionPct ?>%</div>
            <div class="stat-lbl-text">Completion Rate</div>
          </div>
          <div class="stat-accent-bar"></div>
        </div>
      </div>

      <!-- 2. MULTI-CRITERIA FILTERS SECTION -->
      <div class="filter-card">
        <form method="GET" action="" id="taskFilterForm" class="filter-grid">
          <!-- Activity Filter -->
          <div class="filter-group">
            <label for="filterActivity">Activity</label>
            <select name="activity_id" id="filterActivity" onchange="this.form.submit()">
              <option value="">All Activities (<?= count($activitiesList) ?>)</option>
              <?php foreach ($activitiesList as $aid => $atitle): ?>
                <option value="<?= $aid ?>" <?= $filterActivityId === $aid ? 'selected' : '' ?>>
                  <?= htmlspecialchars(mb_strimwidth($atitle, 0, 42, '...')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Spearheading Dept / Source -->
          <div class="filter-group">
            <label for="filterDeptSource">Spearhead / Source</label>
            <select name="dept_source" id="filterDeptSource" onchange="this.form.submit()">
              <option value="">All Departments & Sources</option>
              <optgroup label="Activity Source">
                <option value="src:faculty" <?= $filterDeptSource === 'src:faculty' ? 'selected' : '' ?>>Faculty Proponent</option>
                <option value="src:student_org" <?= $filterDeptSource === 'src:student_org' ? 'selected' : '' ?>>Student Organization</option>
              </optgroup>
              <?php if (!empty($departmentsList)): ?>
                <optgroup label="Proponent Department">
                  <?php foreach ($departmentsList as $dept): ?>
                    <option value="dept:<?= htmlspecialchars($dept) ?>" <?= $filterDeptSource === "dept:$dept" ? 'selected' : '' ?>>
                      <?= htmlspecialchars($dept) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
          </div>

          <!-- Committee Filter -->
          <div class="filter-group">
            <label for="filterCommittee">Committee</label>
            <select name="committee" id="filterCommittee" onchange="this.form.submit()">
              <option value="">All Committees</option>
              <?php foreach ($committeesList as $cm): ?>
                <option value="<?= htmlspecialchars($cm) ?>" <?= $filterCommittee === $cm ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cm) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Assigned Member Filter -->
          <div class="filter-group">
            <label for="filterMember">Assigned Member</label>
            <select name="member" id="filterMember" onchange="this.form.submit()">
              <option value="">All Members</option>
              <?php foreach ($membersList as $mb): ?>
                <option value="<?= htmlspecialchars($mb) ?>" <?= $filterMember === $mb ? 'selected' : '' ?>>
                  <?= htmlspecialchars($mb) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Status Filter -->
          <div class="filter-group">
            <label for="filterStatus">Status</label>
            <select name="status" id="filterStatus" onchange="this.form.submit()">
              <option value="">All Statuses</option>
              <option value="Not Started" <?= $filterStatus === 'Not Started' ? 'selected' : '' ?>>Not Started</option>
              <option value="In Progress" <?= $filterStatus === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
              <option value="Completed" <?= $filterStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
              <option value="Delayed" <?= $filterStatus === 'Delayed' ? 'selected' : '' ?>>Delayed</option>
            </select>
          </div>

          <!-- Timing Filter -->
          <div class="filter-group">
            <label for="filterTiming">Timing Indicator</label>
            <select name="timing" id="filterTiming" onchange="this.form.submit()">
              <option value="">All Timing Indicators</option>
              <option value="Completed" <?= $filterTiming === 'Completed' ? 'selected' : '' ?>>Completed</option>
              <option value="On Track" <?= $filterTiming === 'On Track' ? 'selected' : '' ?>>On Track</option>
              <option value="At Risk" <?= $filterTiming === 'At Risk' ? 'selected' : '' ?>>At Risk (<=3 days)</option>
              <option value="Delayed" <?= $filterTiming === 'Delayed' ? 'selected' : '' ?>>Delayed / Overdue</option>
            </select>
          </div>

          <!-- Search Query Input -->
          <div class="filter-group" style="grid-column: span 2;">
            <label for="filterSearch">Search Task, Activity, Role</label>
            <div style="display:flex;gap:6px;">
              <input type="text" name="q" id="filterSearch" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Type keyword to filter...">
              <button type="submit" class="btn btn-primary btn-sm" style="white-space:nowrap;padding:0 14px;">Filter</button>
              <?php if ($filterActivityId || $filterDeptSource !== '' || $filterCommittee !== '' || $filterMember !== '' || $filterStatus !== '' || $filterTiming !== '' || $searchQuery !== ''): ?>
                <a href="<?= BASE_URL ?>/dean/task-monitoring.php" class="btn btn-outline btn-sm" style="white-space:nowrap;padding:0 12px;">Reset</a>
              <?php endif; ?>
            </div>
          </div>
        </form>
      </div>

      <!-- 3. GANTT VISUALIZATION SECTION -->
      <div class="gantt-container">
        <div class="gantt-header-title">
          <div style="display:flex;align-items:center;gap:10px;">
            <h2 style="font-size:1rem;font-weight:700;color:var(--text-main);margin:0;">📅 Task Timeline (Gantt Visualization)</h2>
            <span class="badge badge-secondary" style="font-size:0.7rem;padding:2px 7px;">Read-Only</span>
          </div>
          <div style="display:flex;align-items:center;gap:14px;font-size:0.75rem;">
            <span style="display:inline-flex;align-items:center;gap:5px;">
              <span style="width:10px;height:10px;border-radius:2px;background:#10b981;display:inline-block;"></span> Completed
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;">
              <span style="width:10px;height:10px;border-radius:2px;background:#0284c7;display:inline-block;"></span> On Track
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;">
              <span style="width:10px;height:10px;border-radius:2px;background:#f59e0b;display:inline-block;"></span> At Risk
            </span>
            <span style="display:inline-flex;align-items:center;gap:5px;">
              <span style="width:10px;height:10px;border-radius:2px;background:#ef4444;display:inline-block;"></span> Delayed
            </span>
          </div>
        </div>

        <!-- Clear Documentation of Schema Dates Limitation -->
        <div class="gantt-limitation-notice">
          <svg style="width:16px;height:16px;min-width:16px;color:#0284c7;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <div>
            <strong>Timeline Date Reference:</strong> Task bars span from <em>Creation Date</em> (<code>created_at</code>) to <em>Target Deadline</em> (<code>due_date</code>). If no deadline is specified, the parent activity date is referenced. Start dates are not artificially invented.
          </div>
        </div>

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
              <?php if (empty($filteredTasks)): ?>
                <tr>
                  <td colspan="2" style="text-align:center;padding:36px;color:var(--text-muted);font-size:0.85rem;">
                    No task assignments matching the current filter criteria.
                  </td>
                </tr>
              <?php else: foreach ($filteredTasks as $t): 
                $sTs = strtotime($t['start_date']);
                $eTs = strtotime($t['end_date']);
                if ($eTs < $sTs) $eTs = $sTs;
                
                // Calculate percentage offsets
                $offsetDays = max(0, ($sTs - $timelineStartTs) / 86400);
                $durationDays = max(1, (($eTs - $sTs) / 86400) + 1);
                
                $leftPct = min(96, max(0, round(($offsetDays / $totalTimelineDays) * 100, 2)));
                $widthPct = max(3.5, min(100 - $leftPct, round(($durationDays / $totalTimelineDays) * 100, 2)));
                $statusClass = strtolower(str_replace(' ', '-', $t['status']));
              ?>
                <tr class="gantt-row">
                  <td class="gantt-col-meta">
                    <div style="font-weight:700;color:var(--text-main);font-size:0.82rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                      <?= htmlspecialchars($t['task_title']) ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;display:flex;align-items:center;gap:6px;">
                      <a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $t['activity_id'] ?>" style="color:#0284c7;text-decoration:none;" title="View Activity">
                        <?= htmlspecialchars(mb_strimwidth($t['activity_title'], 0, 24, '...')) ?>
                      </a>
                      &bull; <span><?= htmlspecialchars($t['member']) ?></span>
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

                      <!-- Gantt Bar: Total length = scheduled duration, fill = completion %, border/tint = timing status -->
                      <div class="gantt-bar-item gantt-status-<?= $statusClass ?> gantt-timing-<?= strtolower(str_replace(' ', '-', $t['timing_key'])) ?>" 
                           style="left:<?= $leftPct ?>%;width:<?= $widthPct ?>%;border-color:<?= $t['timing_color'] ?>;background:<?= $t['timing_bg'] ?>;"
                           onclick="openTaskInspection(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>)"
                           title="<?= htmlspecialchars($t['task_title']) ?> &bull; <?= $t['timing_label'] ?> (<?= $t['completion_pct'] ?>%) &bull; <?= date('M j', $sTs) ?> - <?= date('M j', $eTs) ?>">
                        
                        <?php if ($t['completion_pct'] > 0): ?>
                          <div class="gantt-bar-pct-fill <?= $t['completion_pct'] >= 100 ? 'is-complete' : 'has-divider' ?>" 
                               style="width:<?= $t['completion_pct'] ?>%;background:<?= $t['timing_color'] ?>;"></div>
                        <?php endif; ?>

                        <span class="gantt-bar-label <?= $t['completion_pct'] === 0 ? 'gantt-label-zero' : '' ?>"
                              <?= ($t['completion_pct'] === 0 && $t['timing_key'] === 'Delayed') ? 'style="color:#ef4444;"' : '' ?>>
                          <?php if ($t['status'] === 'Completed' || $t['completion_pct'] === 100): ?>
                            ✓ 100%
                          <?php elseif ($t['completion_pct'] === 0): ?>
                            <?= $t['timing_key'] === 'Delayed' ? '⏱ 0%' : '0%' ?>
                          <?php elseif ($t['timing_key'] === 'Delayed'): ?>
                            ⏱ <?= $t['completion_pct'] ?>%
                          <?php else: ?>
                            <?= $t['completion_pct'] ?>%
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

      <!-- 4. COMMITTEE MONITORING SECTION -->
      <div class="card" style="box-shadow:var(--shadow-sm);border-radius:12px;overflow:hidden;margin-bottom:24px;" id="committeeMonitoringSection">
        <div class="card-header" style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <div>
            <h2 style="font-size:1rem;font-weight:700;margin:0;color:var(--text-main);display:flex;align-items:center;gap:8px;">
              <span>🏛️</span> Committee Monitoring (<?= count($committeeSummary) ?>)
            </h2>
            <small style="color:var(--text-muted);font-size:0.75rem;">Compact committee-level performance summary and completion tracking</small>
          </div>
          <div style="font-size:0.78rem;color:var(--text-muted);">
            Committees: <strong style="color:var(--text-main);"><?= count($committeeSummary) ?></strong>
          </div>
        </div>

        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table class="activity-table" id="committeeMonitoringTable">
              <thead>
                <tr>
                  <th style="width:24%;">Committee</th>
                  <th style="width:11%;text-align:center;">Total</th>
                  <th style="width:12%;text-align:center;">Completed</th>
                  <th style="width:13%;text-align:center;">In Progress</th>
                  <th style="width:12%;text-align:center;">Delayed</th>
                  <th style="width:15%;text-align:center;">Avg Completion</th>
                  <th style="width:13%;text-align:center;">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($committeeSummary)): ?>
                  <tr>
                    <td colspan="7" style="text-align:center;padding:32px;color:var(--text-muted);">
                      No committee tasks found matching your filter selection.
                    </td>
                  </tr>
                <?php else: foreach ($committeeSummary as $c): ?>
                  <tr data-committee="<?= htmlspecialchars($c['committee']) ?>"
                      data-total="<?= $c['total'] ?>"
                      data-completed="<?= $c['completed'] ?>"
                      data-in-progress="<?= $c['in_progress'] ?>"
                      data-not-started="<?= $c['not_started'] ?>"
                      data-delayed="<?= $c['delayed'] ?>"
                      data-avg-completion="<?= $c['avg_completion'] ?>"
                      data-status="<?= $c['status'] ?>">
                    <td>
                      <div style="font-weight:600;color:var(--text-main);display:flex;align-items:center;gap:8px;">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $c['status'] === 'Delayed' ? '#ef4444' : ($c['status'] === 'At Risk' ? '#f59e0b' : '#10b981') ?>;"></span>
                        <?= htmlspecialchars($c['committee']) ?>
                      </div>
                    </td>
                    <td style="text-align:center;font-weight:600;" title="<?= $c['not_started'] ?> not started, <?= $c['in_progress'] ?> in progress, <?= $c['completed'] ?> completed">
                      <?= $c['total'] ?>
                    </td>
                    <td style="text-align:center;">
                      <span style="font-weight:600;color:<?= $c['completed'] > 0 ? '#10b981' : 'var(--text-muted)' ?>;">
                        <?= $c['completed'] ?>
                      </span>
                    </td>
                    <td style="text-align:center;">
                      <span style="font-weight:600;color:<?= $c['in_progress'] > 0 ? '#0284c7' : 'var(--text-muted)' ?>;">
                        <?= $c['in_progress'] ?>
                      </span>
                    </td>
                    <td style="text-align:center;">
                      <?php if ($c['delayed'] > 0): ?>
                        <span style="font-weight:700;color:#ef4444;background:rgba(239,68,68,0.12);padding:2px 8px;border-radius:6px;">
                          <?= $c['delayed'] ?>
                        </span>
                      <?php else: ?>
                        <span style="color:var(--text-muted);">0</span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                      <div style="display:inline-flex;align-items:center;gap:6px;">
                        <div style="width:48px;height:6px;background:var(--border);border-radius:3px;overflow:hidden;display:inline-block;vertical-align:middle;">
                          <div style="width:<?= min(100, max(0, $c['avg_completion'])) ?>%;height:100%;background:<?= $c['status'] === 'Delayed' ? '#ef4444' : ($c['status'] === 'At Risk' ? '#f59e0b' : '#10b981') ?>;border-radius:3px;"></div>
                        </div>
                        <span style="font-weight:600;font-size:0.82rem;"><?= $c['avg_completion'] ?>%</span>
                      </div>
                    </td>
                    <td style="text-align:center;">
                      <?php if ($c['status'] === 'Delayed'): ?>
                        <span class="badge badge-danger" style="background:#ef4444;color:#fff;">Delayed</span>
                      <?php elseif ($c['status'] === 'At Risk'): ?>
                        <span class="badge badge-warning" style="background:#f59e0b;color:#fff;">At Risk</span>
                      <?php else: ?>
                        <span class="badge badge-success" style="background:#10b981;color:#fff;">On Track</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- 5. MEMBER PERFORMANCE SECTION -->
      <div class="card" style="box-shadow:var(--shadow-sm);border-radius:12px;overflow:hidden;margin-bottom:24px;" id="memberPerformanceSection">
        <div class="card-header" style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <div>
            <h2 style="font-size:1rem;font-weight:700;margin:0;color:var(--text-main);display:flex;align-items:center;gap:8px;">
              <span>👤</span> Member Performance (<?= count($memberSummary) ?>)
            </h2>
            <small style="color:var(--text-muted);font-size:0.75rem;">Compact individual-member performance summary and completion tracking</small>
          </div>
          <div style="font-size:0.78rem;color:var(--text-muted);">
            Members Active: <strong style="color:var(--text-main);"><?= count($memberSummary) ?></strong>
          </div>
        </div>

        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table class="activity-table" id="memberPerformanceTable">
              <thead>
                <tr>
                  <th style="width:20%;">Member</th>
                  <th style="width:18%;">Committee</th>
                  <th style="width:9%;text-align:center;">Total</th>
                  <th style="width:10%;text-align:center;">Completed</th>
                  <th style="width:11%;text-align:center;">In Progress</th>
                  <th style="width:10%;text-align:center;">Delayed</th>
                  <th style="width:12%;text-align:center;">Avg Completion</th>
                  <th style="width:10%;text-align:center;">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($memberSummary)): ?>
                  <tr>
                    <td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted);">
                      No member task assignments found matching your filter selection.
                    </td>
                  </tr>
                <?php else: foreach ($memberSummary as $m): ?>
                  <tr data-member="<?= htmlspecialchars($m['member']) ?>"
                      data-committee="<?= htmlspecialchars(implode(', ', $m['committees'])) ?>"
                      data-total="<?= $m['total'] ?>"
                      data-completed="<?= $m['completed'] ?>"
                      data-in-progress="<?= $m['in_progress'] ?>"
                      data-not-started="<?= $m['not_started'] ?>"
                      data-delayed="<?= $m['delayed'] ?>"
                      data-avg-completion="<?= $m['avg_completion'] ?>"
                      data-status="<?= $m['status'] ?>">
                    <td>
                      <div style="font-weight:600;color:var(--text-main);display:flex;align-items:center;gap:8px;">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $m['status'] === 'Delayed' ? '#ef4444' : ($m['status'] === 'At Risk' ? '#f59e0b' : '#10b981') ?>;"></span>
                        <?= htmlspecialchars($m['member']) ?>
                      </div>
                    </td>
                    <td>
                      <span style="font-size:0.82rem;color:var(--text-muted);">
                        <?= !empty($m['committees']) ? htmlspecialchars(implode(', ', $m['committees'])) : '—' ?>
                      </span>
                    </td>
                    <td style="text-align:center;font-weight:600;" title="<?= $m['not_started'] ?> not started, <?= $m['in_progress'] ?> in progress, <?= $m['completed'] ?> completed">
                      <?= $m['total'] ?>
                    </td>
                    <td style="text-align:center;">
                      <span style="font-weight:600;color:<?= $m['completed'] > 0 ? '#10b981' : 'var(--text-muted)' ?>;">
                        <?= $m['completed'] ?>
                      </span>
                    </td>
                    <td style="text-align:center;">
                      <span style="font-weight:600;color:<?= $m['in_progress'] > 0 ? '#0284c7' : 'var(--text-muted)' ?>;">
                        <?= $m['in_progress'] ?>
                      </span>
                    </td>
                    <td style="text-align:center;">
                      <?php if ($m['delayed'] > 0): ?>
                        <span style="font-weight:700;color:#ef4444;background:rgba(239,68,68,0.12);padding:2px 8px;border-radius:6px;">
                          <?= $m['delayed'] ?>
                        </span>
                      <?php else: ?>
                        <span style="color:var(--text-muted);">0</span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                      <div style="display:inline-flex;align-items:center;gap:6px;">
                        <div style="width:48px;height:6px;background:var(--border);border-radius:3px;overflow:hidden;display:inline-block;vertical-align:middle;">
                          <div style="width:<?= min(100, max(0, $m['avg_completion'])) ?>%;height:100%;background:<?= $m['status'] === 'Delayed' ? '#ef4444' : ($m['status'] === 'At Risk' ? '#f59e0b' : '#10b981') ?>;border-radius:3px;"></div>
                        </div>
                        <span style="font-weight:600;font-size:0.82rem;"><?= $m['avg_completion'] ?>%</span>
                      </div>
                    </td>
                    <td style="text-align:center;">
                      <?php if ($m['status'] === 'Delayed'): ?>
                        <span class="badge badge-danger" style="background:#ef4444;color:#fff;">Delayed</span>
                      <?php elseif ($m['status'] === 'At Risk'): ?>
                        <span class="badge badge-warning" style="background:#f59e0b;color:#fff;">At Risk</span>
                      <?php else: ?>
                        <span class="badge badge-success" style="background:#10b981;color:#fff;">On Track</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- 6. SPEARHEAD / DEPARTMENT PERFORMANCE SECTION -->
      <div class="card" style="box-shadow:var(--shadow-sm);border-radius:12px;overflow:hidden;margin-bottom:24px;" id="departmentPerformanceSection">
        <div class="card-header" style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <div>
            <h2 style="font-size:1rem;font-weight:700;margin:0;color:var(--text-main);display:flex;align-items:center;gap:8px;">
              <span>🏢</span> Spearheading Department Performance (<?= count($departmentSummary) ?>)
            </h2>
            <small style="color:var(--text-muted);font-size:0.75rem;">Compact department and spearhead-level performance summary and completion tracking</small>
          </div>
          <div style="font-size:0.78rem;color:var(--text-muted);">
            Departments Active: <strong style="color:var(--text-main);"><?= count($departmentSummary) ?></strong>
          </div>
        </div>

        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table class="activity-table" id="departmentPerformanceTable">
              <thead>
                <tr>
                  <th style="width:22%;">Spearheading Department</th>
                  <th style="width:10%;text-align:center;">Activities</th>
                  <th style="width:11%;text-align:center;">Total Tasks</th>
                  <th style="width:11%;text-align:center;">Completed</th>
                  <th style="width:12%;text-align:center;">In Progress</th>
                  <th style="width:10%;text-align:center;">Delayed</th>
                  <th style="width:13%;text-align:center;">Avg Completion</th>
                  <th style="width:11%;text-align:center;">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($departmentSummary)): ?>
                  <tr>
                    <td colspan="8" style="text-align:center;padding:32px;color:var(--text-muted);">
                      No spearheading department tasks found matching your filter selection.
                    </td>
                  </tr>
                <?php else: foreach ($departmentSummary as $d): ?>
                  <tr data-department="<?= htmlspecialchars($d['department']) ?>"
                      data-activities="<?= count($d['activities']) ?>"
                      data-total-tasks="<?= $d['total_tasks'] ?>"
                      data-completed="<?= $d['completed'] ?>"
                      data-in-progress="<?= $d['in_progress'] ?>"
                      data-not-started="<?= $d['not_started'] ?>"
                      data-delayed="<?= $d['delayed'] ?>"
                      data-avg-completion="<?= $d['avg_completion'] ?>"
                      data-status="<?= $d['status'] ?>">
                    <td>
                      <div style="font-weight:600;color:var(--text-main);display:flex;align-items:center;gap:8px;">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $d['status'] === 'Delayed' ? '#ef4444' : ($d['status'] === 'At Risk' ? '#f59e0b' : '#10b981') ?>;"></span>
                        <?= htmlspecialchars($d['department']) ?>
                      </div>
                    </td>
                    <td style="text-align:center;font-weight:600;"><?= count($d['activities']) ?></td>
                    <td style="text-align:center;font-weight:600;" title="<?= $d['not_started'] ?> not started, <?= $d['in_progress'] ?> in progress, <?= $d['completed'] ?> completed">
                      <?= $d['total_tasks'] ?>
                    </td>
                    <td style="text-align:center;">
                      <span style="font-weight:600;color:<?= $d['completed'] > 0 ? '#10b981' : 'var(--text-muted)' ?>;">
                        <?= $d['completed'] ?>
                      </span>
                    </td>
                    <td style="text-align:center;">
                      <span style="font-weight:600;color:<?= $d['in_progress'] > 0 ? '#0284c7' : 'var(--text-muted)' ?>;">
                        <?= $d['in_progress'] ?>
                      </span>
                    </td>
                    <td style="text-align:center;">
                      <?php if ($d['delayed'] > 0): ?>
                        <span style="font-weight:700;color:#ef4444;background:rgba(239,68,68,0.12);padding:2px 8px;border-radius:6px;">
                          <?= $d['delayed'] ?>
                        </span>
                      <?php else: ?>
                        <span style="color:var(--text-muted);">0</span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                      <div style="display:inline-flex;align-items:center;gap:6px;">
                        <div style="width:48px;height:6px;background:var(--border);border-radius:3px;overflow:hidden;display:inline-block;vertical-align:middle;">
                          <div style="width:<?= min(100, max(0, $d['avg_completion'])) ?>%;height:100%;background:<?= $d['status'] === 'Delayed' ? '#ef4444' : ($d['status'] === 'At Risk' ? '#f59e0b' : '#10b981') ?>;border-radius:3px;"></div>
                        </div>
                        <span style="font-weight:600;font-size:0.82rem;"><?= $d['avg_completion'] ?>%</span>
                      </div>
                    </td>
                    <td style="text-align:center;">
                      <?php if ($d['status'] === 'Delayed'): ?>
                        <span class="badge badge-danger" style="background:#ef4444;color:#fff;">Delayed</span>
                      <?php elseif ($d['status'] === 'At Risk'): ?>
                        <span class="badge badge-warning" style="background:#f59e0b;color:#fff;">At Risk</span>
                      <?php else: ?>
                        <span class="badge badge-success" style="background:#10b981;color:#fff;">On Track</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- 7. TASK MONITORING TABLE SECTION -->
      <div class="card" style="box-shadow:var(--shadow-sm);border-radius:12px;overflow:hidden;margin-bottom:30px;">
        <div class="card-header" style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <div>
            <h2 style="font-size:1rem;font-weight:700;margin:0;color:var(--text-main);">📋 Task Monitoring Details (<?= count($filteredTasks) ?>)</h2>
            <small style="color:var(--text-muted);font-size:0.75rem;">Centralized read-only tracking of distributed work across committees and members</small>
          </div>
          <div style="font-size:0.78rem;color:var(--text-muted);">
            Avg Progress: <strong style="color:var(--text-main);"><?= $averageProgressPct ?>%</strong>
          </div>
        </div>

        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table class="activity-table">
              <thead>
                <tr>
                  <th style="width:17%;">Activity</th>
                  <th style="width:14%;">Spearhead</th>
                  <th style="width:12%;">Committee</th>
                  <th style="width:13%;">Member</th>
                  <th style="width:18%;">Task</th>
                  <th style="width:11%;">Due Date</th>
                  <th style="width:11%;">Completion %</th>
                  <th style="width:10%;">Status</th>
                  <th style="width:11%;">Timing</th>
                  <th style="width:7%;text-align:right;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($filteredTasks)): ?>
                  <tr>
                    <td colspan="10" style="text-align:center;padding:40px;color:var(--text-muted);">
                      No task assignments found matching your filter selection.
                    </td>
                  </tr>
                <?php else: foreach ($filteredTasks as $t): ?>
                  <tr>
                    <!-- Activity with Drilldown Link -->
                    <td>
                      <strong>
                        <a href="<?= BASE_URL ?>/dean/view-activity.php?id=<?= $t['activity_id'] ?>" style="color:var(--text-main);text-decoration:none;" title="View Full Activity">
                          <?= htmlspecialchars($t['activity_title']) ?>
                        </a>
                      </strong>
                    </td>

                    <!-- Spearhead / Leader -->
                    <td>
                      <div style="font-weight:600;color:var(--text-main);"><?= htmlspecialchars($t['leader_name']) ?></div>
                      <span class="badge badge-secondary" style="font-size:0.65rem;padding:1px 6px;margin-top:2px;">
                        <?= htmlspecialchars($t['leader_dept']) ?>
                      </span>
                    </td>

                    <!-- Committee -->
                    <td>
                      <?php if ($t['committee'] !== 'General'): ?>
                        <span class="badge badge-secondary"><?= htmlspecialchars($t['committee']) ?></span>
                      <?php else: ?>
                        <span style="color:var(--text-muted);font-size:0.80rem;">General</span>
                      <?php endif; ?>
                    </td>

                    <!-- Member -->
                    <td>
                      <strong><?= htmlspecialchars($t['member']) ?></strong>
                      <?php if (!empty($t['role']) && $t['role'] !== '—'): ?>
                        <div style="font-size:0.72rem;color:var(--text-muted);"><?= htmlspecialchars($t['role']) ?></div>
                      <?php endif; ?>
                    </td>

                    <!-- Task Title & Description -->
                    <td>
                      <div style="font-weight:600;color:var(--text-main);"><?= htmlspecialchars($t['task_title']) ?></div>
                      <?php if (!empty($t['task_description'])): ?>
                        <small style="color:var(--text-muted);display:block;margin-top:2px;font-size:0.72rem;">
                          <?= htmlspecialchars(mb_strimwidth($t['task_description'], 0, 75, '...')) ?>
                        </small>
                      <?php endif; ?>
                    </td>

                    <!-- Due Date -->
                    <td>
                      <?php if (!empty($t['due_date'])): ?>
                        <span style="font-size:0.80rem;font-weight:600;color:var(--text-main);">
                          <?= date('M j, Y', strtotime($t['due_date'])) ?>
                        </span>
                      <?php else: ?>
                        <span style="color:var(--text-muted);font-size:0.80rem;">—</span>
                      <?php endif; ?>
                    </td>

                    <!-- Completion % with Bar -->
                    <td>
                      <div class="progress-bar-wrap">
                        <div class="progress-bar-track">
                          <div class="progress-bar-fill" style="width:<?= $t['completion_pct'] ?>%;background:<?= $t['timing_color'] ?>;"></div>
                        </div>
                        <span style="font-size:0.75rem;font-weight:700;color:var(--text-main);min-width:32px;">
                          <?= $t['completion_pct'] ?>%
                        </span>
                      </div>
                    </td>

                    <!-- Status -->
                    <td>
                      <?= getTaskStatusBadge($t['status']) ?>
                    </td>

                    <!-- Timing -->
                    <td>
                      <?= $t['timing_badge'] ?>
                    </td>

                    <!-- Drill-down Inspection Action -->
                    <td style="text-align:right;">
                      <button type="button" class="btn btn-outline btn-sm" 
                              style="font-size:0.72rem;padding:3px 8px;"
                              onclick="openTaskInspection(<?= htmlspecialchars(json_encode($t), ENT_QUOTES, 'UTF-8') ?>)">
                        Inspect
                      </button>
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

  <!-- READ-ONLY TASK INSPECTION MODAL -->
  <div id="taskInspectModal" class="task-inspect-modal-backdrop" onclick="closeTaskInspection(event)">
    <div class="task-inspect-modal" onclick="event.stopPropagation()">
      <div class="inspect-modal-header">
        <div style="display:flex;align-items:center;gap:8px;">
          <h3 style="font-size:1rem;font-weight:700;margin:0;color:var(--text-main);" id="inspectModalTitle">Task Details</h3>
          <span class="badge badge-secondary" style="font-size:0.68rem;">Read-Only</span>
        </div>
        <button type="button" onclick="closeTaskInspectionDirect()" style="background:none;border:none;font-size:1.25rem;cursor:pointer;color:var(--text-muted);padding:4px;">&times;</button>
      </div>

      <div class="inspect-modal-body">
        <div class="inspect-info-tile" style="grid-column:span 2;">
          <div class="tile-label">Task Title</div>
          <div class="tile-value" id="inspectTaskTitle" style="font-size:0.95rem;font-weight:700;">—</div>
          <div id="inspectTaskDesc" style="font-size:0.78rem;color:var(--text-secondary);margin-top:6px;line-height:1.4;"></div>
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
          <div class="tile-label">Deliverables & Evidence (Read-Only)</div>
          <div id="inspectDeliverablesList" style="font-size:0.82rem;margin-top:6px;">
            <span style="color:var(--text-muted);font-style:italic;">No deliverable files uploaded yet for this task.</span>
          </div>
        </div>
      </div>

      <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--bg-card);">
        <a href="#" id="inspectActivityBtn" class="btn btn-primary btn-sm">
          Open Full Activity &rarr;
        </a>
        <button type="button" class="btn btn-outline btn-sm" onclick="closeTaskInspectionDirect()">Close</button>
      </div>
    </div>
  </div>

  <script>
    function escapeHtml(str) {
      if (!str) return '';
      return String(str).replace(/[&<>"']/g, function(m) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[m];
      });
    }

    // ── Inspection Modal Logic (Strictly Read-Only) ──
    function openTaskInspection(task) {
      if (!task) return;
      document.getElementById('inspectModalTitle').textContent = task.task_title || 'Task Details';
      document.getElementById('inspectTaskTitle').textContent = task.task_title || 'Untitled Task';
      document.getElementById('inspectTaskDesc').textContent = task.task_description || 'No additional description provided.';
      
      const actLink = document.getElementById('inspectActivityLink');
      actLink.textContent = task.activity_title || 'View Activity';
      actLink.href = '<?= BASE_URL ?>/dean/view-activity.php?id=' + task.activity_id;

      const actBtn = document.getElementById('inspectActivityBtn');
      actBtn.href = '<?= BASE_URL ?>/dean/view-activity.php?id=' + task.activity_id;

      document.getElementById('inspectLeader').textContent = (task.leader_name || '—') + (task.leader_dept ? ' (' + task.leader_dept + ')' : '');
      document.getElementById('inspectCommittee').textContent = task.committee || 'General';
      document.getElementById('inspectMember').textContent = (task.member || 'Unassigned') + (task.role && task.role !== '—' ? ' — ' + task.role : '');
      document.getElementById('inspectDueDate').textContent = task.due_date ? task.due_date : 'No deadline set';
      document.getElementById('inspectCreatedAt').textContent = task.created_at ? task.created_at : '—';
      document.getElementById('inspectStatus').innerHTML = task.status;
      document.getElementById('inspectTiming').innerHTML = task.timing_badge || task.timing_label;

      // Load task deliverables
      const delivContainer = document.getElementById('inspectDeliverablesList');
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
                       '<div style="font-weight:700;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">📎 ' + escapeHtml(item.file_name) + '</div>' +
                       '<div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;">' +
                         'Uploaded by ' + escapeHtml(item.uploader_name) + ' on ' + item.uploaded_at + ' &bull; ' +
                         '<span class="badge ' + statusClass + '" style="font-size:0.68rem;padding:2px 6px;">' + statusLabel + '</span>' +
                       '</div>' +
                       (item.review_notes ? '<div style="font-size:0.72rem;color:var(--text-secondary);margin-top:2px;font-style:italic;">Review Note: ' + escapeHtml(item.review_notes) + '</div>' : '') +
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

      const modal = document.getElementById('taskInspectModal');
      modal.style.display = 'flex';
    }

    function closeTaskInspection(event) {
      if (event && event.target && event.target.id === 'taskInspectModal') {
        closeTaskInspectionDirect();
      }
    }

    function closeTaskInspectionDirect() {
      const modal = document.getElementById('taskInspectModal');
      modal.style.display = 'none';
    }

    // Keyboard navigation (ESC key closes modal)
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeTaskInspectionDirect();
      }
    });
  </script>
</body>
</html>
