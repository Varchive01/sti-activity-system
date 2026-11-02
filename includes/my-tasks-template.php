<?php
// includes/my-tasks-template.php
// Reusable template for My Tasks page across Admin 1, Admin 2, and Faculty

$user = currentUser();
$db   = getDB();

// Trigger progress reminder checks for this user
checkTaskProgressReminders($db, $user['id'], $user['role']);

// Deterministic timing calculator
if (!function_exists('calculateTaskTiming')) {
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

        if (!empty($dueDate) && $dueDate < $today) {
            return [
                'key'      => 'Delayed',
                'label'    => 'Delayed',
                'badge'    => '<span class="badge badge-danger" style="background:#ef4444;color:#fff;">Delayed (Overdue)</span>',
                'color'    => '#ef4444',
                'bg_light' => 'rgba(239, 68, 68, 0.14)'
            ];
        }

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
            $diffDays = (int)ceil((strtotime($dueDate) - strtotime($today)) / 86400);
            if ($diffDays >= 0 && $diffDays <= 3) {
                return [
                    'key'      => 'At Risk',
                    'label'    => 'At Risk',
                    'badge'    => '<span class="badge badge-warning" style="background:#f59e0b;color:#fff;">At Risk (' . $diffDays . 'd left)</span>',
                    'color'    => '#f59e0b',
                    'bg_light' => 'rgba(245, 158, 11, 0.14)'
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
}

// Filters (Strictly scoped to session user or department if program_head)
$searchFilter   = sanitize($_GET['q'] ?? '');
$statusFilter   = sanitize($_GET['status'] ?? '');
$timingFilter   = sanitize($_GET['timing'] ?? '');
$activityFilter = (int)($_GET['activity_id'] ?? 0);

$isPH = (($user['role'] ?? '') === 'program_head');
if ($isPH) {
    $userDept = $user['dept'] ?? ($user['department'] ?? '');
    $sql = "
        SELECT ft.*,
               a.id as activity_id,
               a.title as activity_title,
               a.event_date,
               a.status as activity_status,
               u.name as leader_name,
               u.department as leader_dept,
               au.name as assigned_to_name,
               au.department as assigned_to_dept
        FROM faculty_tasks ft
        JOIN activities a ON ft.activity_id = a.id
        JOIN users u ON a.faculty_id = u.id
        LEFT JOIN users au ON ft.assigned_user_id = au.id
        WHERE (u.department = ? OR au.department = ? OR ft.assigned_user_id = ? OR a.faculty_id = ?)
    ";
    $params = [$userDept, $userDept, (int)$user['id'], (int)$user['id']];
} else {
    $sql = "
        SELECT ft.*,
               a.id as activity_id,
               a.title as activity_title,
               a.event_date,
               a.status as activity_status,
               u.name as leader_name,
               u.department as leader_dept,
               au.name as assigned_to_name,
               au.department as assigned_to_dept
        FROM faculty_tasks ft
        JOIN activities a ON ft.activity_id = a.id
        JOIN users u ON a.faculty_id = u.id
        LEFT JOIN users au ON ft.assigned_user_id = au.id
        WHERE ft.assigned_user_id = ?
    ";
    $params = [(int)$user['id']];
}

if ($activityFilter > 0) {
    $sql .= " AND ft.activity_id = ?";
    $params[] = $activityFilter;
}

if ($searchFilter !== '') {
    $sql .= " AND (ft.task_title LIKE ? OR ft.assigned_task LIKE ? OR a.title LIKE ?)";
    $params[] = "%{$searchFilter}%";
    $params[] = "%{$searchFilter}%";
    $params[] = "%{$searchFilter}%";
}

if ($statusFilter !== '') {
    $sql .= " AND ft.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY ft.due_date ASC, ft.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rawTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process & Enrich Rows for Gantt & Table views
$enrichedTasks  = [];
$activitiesList = [];

foreach ($rawTasks as $r) {
    $actId = (int)$r['activity_id'];
    $activitiesList[$actId] = $r['activity_title'];

    $tTitle  = $r['task_title'] ?: ($r['assigned_task'] ?: 'Untitled Task');
    $tDesc   = $r['task_description'] ?: ($r['contribution_desc'] ?: '');
    $tMember = !empty($r['assigned_to_name']) ? $r['assigned_to_name'] : (!empty($r['faculty_name']) ? $r['faculty_name'] : 'Unassigned');
    $tRole   = $r['role_in_event'] ?: '—';
    $tDue    = !empty($r['due_date']) ? date('Y-m-d', strtotime($r['due_date'])) : null;
    $tStatus = in_array($r['status'], ['Not Started', 'In Progress', 'Completed', 'Delayed']) ? $r['status'] : 'Not Started';
    $tPct    = max(0, min(100, (int)($r['completion_pct'] ?? 0)));
    $timing  = calculateTaskTiming($tStatus, $tDue);

    $createdAtDate = !empty($r['created_at']) ? date('Y-m-d', strtotime($r['created_at'])) : date('Y-m-d');
    $startDate     = $createdAtDate;
    $endDate       = $tDue ?: (!empty($r['event_date']) ? date('Y-m-d', strtotime($r['event_date'])) : $createdAtDate);
    if (strtotime($startDate) > strtotime($endDate)) {
        $startDate = $endDate;
    }

    $item = [
        'id'                  => (int)$r['id'],
        'activity_id'         => $actId,
        'activity_title'      => $r['activity_title'],
        'activity_status'     => $r['activity_status'],
        'leader_name'         => $r['leader_name'] ?? 'Faculty Leader',
        'leader_dept'         => $r['leader_dept'] ?? '',
        'task_title'          => $tTitle,
        'task_description'    => $tDesc,
        'committee'           => $r['committee'] ?: 'General',
        'assigned_user_id'    => $r['assigned_user_id'] ? (int)$r['assigned_user_id'] : null,
        'assigned_to_name'    => $r['assigned_to_name'] ?? null,
        'assigned_to_dept'    => $r['assigned_to_dept'] ?? null,
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

    if ($timingFilter !== '' && $timing['key'] !== $timingFilter) {
        continue;
    }

    $enrichedTasks[] = $item;
}
ksort($activitiesList);
$tasks = $enrichedTasks;

// Metrics calculation strictly for this authenticated user / department
if ($isPH) {
    $userDept = $user['dept'] ?? ($user['department'] ?? '');
    $allTasksStmt = $db->prepare("
        SELECT ft.status, ft.due_date 
        FROM faculty_tasks ft
        JOIN activities a ON ft.activity_id = a.id
        JOIN users u ON a.faculty_id = u.id
        LEFT JOIN users au ON ft.assigned_user_id = au.id
        WHERE (u.department = ? OR au.department = ? OR ft.assigned_user_id = ? OR a.faculty_id = ?)
    ");
    $allTasksStmt->execute([$userDept, $userDept, (int)$user['id'], (int)$user['id']]);
} else {
    $allTasksStmt = $db->prepare("SELECT status, due_date FROM faculty_tasks WHERE assigned_user_id = ?");
    $allTasksStmt->execute([(int)$user['id']]);
}
$allUserTasks = $allTasksStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCount      = count($allUserTasks);
$completedCount  = 0;
$inProgressCount = 0;
$delayedCount    = 0;
$todayStr        = date('Y-m-d');

foreach ($allUserTasks as $ut) {
    if ($ut['status'] === 'Completed') {
        $completedCount++;
    } elseif ($ut['status'] === 'In Progress') {
        $inProgressCount++;
    }
    if ($ut['status'] === 'Delayed' || (!empty($ut['due_date']) && $ut['due_date'] < $todayStr && $ut['status'] !== 'Completed')) {
        $delayedCount++;
    }
}

$pageTitle = $pageTitle ?? 'My Assigned Tasks';
$themeClass = $themeClass ?? 'theme-arjay';
$viewActivityBase = $viewActivityBase ?? (BASE_URL . '/' . $role . '/view-activity.php');
$allActivitiesBase = $allActivitiesBase ?? (BASE_URL . '/' . $role . '/activities.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.8">
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
  body, body * {
    font-family: 'Plus Jakarta Sans', sans-serif !important;
  }
  .task-metric-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
  }
  .task-metric-card {
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px 20px;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .task-metric-card .title {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--text-muted);
  }
  .task-metric-card .val {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1.1;
  }
  .tasks-table-card {
    background: var(--bg-card);
    border-radius: 14px;
    border: 1px solid var(--border);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
  }
  .task-table {
    width: 100%;
    border-collapse: collapse;
  }
  .task-table th {
    padding: 12px 16px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    background: var(--border-light, #F8FAFD);
    text-align: left;
  }
  [data-theme="dark"] .task-table th {
    background: rgba(255, 255, 255, 0.03);
  }
  .task-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    font-size: 0.85rem;
    color: var(--text-main);
    vertical-align: middle;
  }
  .task-table tr:hover td {
    background: rgba(2, 132, 199, 0.02);
  }
  [data-theme="dark"] .task-table tr:hover td {
    background: rgba(255, 255, 255, 0.015);
  }
  .progress-bar-bg {
    width: 100%;
    max-width: 90px;
    height: 7px;
    background: var(--border);
    border-radius: 4px;
    overflow: hidden;
    display: inline-block;
    vertical-align: middle;
    margin-right: 6px;
  }
  .progress-bar-fill {
    height: 100%;
    background: var(--accent, #0284c7);
    border-radius: 4px;
    transition: width 0.25s ease;
  }
  .empty-state-box {
    text-align: center;
    padding: 56px 20px;
  }
  .task-status-select {
    font-size: 0.78rem;
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid var(--border);
    background: var(--bg-card);
    color: var(--text-main);
    font-weight: 600;
    cursor: pointer;
  }
  .task-status-select:focus {
    outline: none;
    border-color: var(--accent, #0284c7);
  }
  .task-action-btns {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
  }
</style>
</head>
<body class="<?= htmlspecialchars($themeClass) ?>">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title"><?= htmlspecialchars($pageTitle) ?></div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/notification-topbar-widget.php'; ?>
      <?php include __DIR__ . '/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">

    <!-- Metric Cards -->
    <div class="task-metric-grid">
      <div class="task-metric-card">
        <div class="title">Total Assigned</div>
        <div class="val"><?= $totalCount ?></div>
      </div>
      <div class="task-metric-card">
        <div class="title">In Progress</div>
        <div class="val" style="color: #0284c7;"><?= $inProgressCount ?></div>
      </div>
      <div class="task-metric-card">
        <div class="title">Completed</div>
        <div class="val" style="color: #10b981;"><?= $completedCount ?></div>
      </div>
      <div class="task-metric-card">
        <div class="title">Delayed / Overdue</div>
        <div class="val" style="color: #ef4444;"><?= $delayedCount ?></div>
      </div>
    </div>

    <!-- Filters & View Jump Bar -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px;">
      <div style="display:flex;align-items:center;gap:8px;">
        <a href="#ganttSection" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:4px;">
          <span>📊</span> Gantt Timeline
        </a>
        <a href="#tableSection" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:4px;">
          <span>📋</span> Task Records Table
        </a>
      </div>
      <div style="font-size:0.82rem;color:var(--text-muted);font-weight:600;">
        <?= count($tasks) ?> <?= count($tasks) === 1 ? 'task' : 'tasks' ?> listed
      </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="filters" style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap;">
      <input type="text" name="q" class="form-control" placeholder="Search tasks or activities…" value="<?= htmlspecialchars($searchFilter) ?>" style="max-width:220px;">
      
      <?php if (!empty($activitiesList)): ?>
        <select name="activity_id" class="form-control" style="max-width:190px;">
          <option value="">All Activities</option>
          <?php foreach ($activitiesList as $aId => $aTitle): ?>
            <option value="<?= $aId ?>" <?= $activityFilter === $aId ? 'selected' : '' ?>>
              <?= htmlspecialchars(mb_strimwidth($aTitle, 0, 24, '...')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <select name="status" class="form-control" style="max-width:140px;">
        <option value="">All Statuses</option>
        <option value="Not Started" <?= $statusFilter === 'Not Started' ? 'selected' : '' ?>>Not Started</option>
        <option value="In Progress" <?= $statusFilter === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
        <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
        <option value="Delayed" <?= $statusFilter === 'Delayed' ? 'selected' : '' ?>>Delayed</option>
      </select>

      <select name="timing" class="form-control" style="max-width:140px;">
        <option value="">All Timing</option>
        <option value="Completed" <?= $timingFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
        <option value="On Track" <?= $timingFilter === 'On Track' ? 'selected' : '' ?>>On Track</option>
        <option value="At Risk" <?= $timingFilter === 'At Risk' ? 'selected' : '' ?>>At Risk</option>
        <option value="Delayed" <?= $timingFilter === 'Delayed' ? 'selected' : '' ?>>Delayed</option>
      </select>

      <button type="submit" class="btn btn-primary btn-sm">Filter</button>
      <?php if ($searchFilter !== '' || $statusFilter !== '' || $timingFilter !== '' || $activityFilter > 0): ?>
        <a href="tasks.php" class="btn btn-outline btn-sm">Clear</a>
      <?php endif; ?>
    </form>

    <!-- ── Task Timeline (Gantt Visualization) ── -->
    <?php
    $ganttTasks = $tasks;
    $ganttOptions = [
        'title'                 => 'Task Timeline (Gantt Visualization)',
        'subtitle'              => $isPH ? ('Department Activity Timeline — ' . ($userDept ?: 'Department')) : 'Personal Assigned Tasks Timeline',
        'viewActivityBase'      => $viewActivityBase,
        'emptyMessage'          => ($searchFilter !== '' || $statusFilter !== '' || $timingFilter !== '' || $activityFilter > 0) ? 'No task assignments matching the current filter criteria.' : ($isPH ? 'No department task assignments found.' : 'You currently have no tasks assigned to you across any activity proposals.'),
        'showNotice'            => true,
        'canManageDeliverables' => true
    ];
    include __DIR__ . '/gantt-timeline-component.php';
    ?>

    <!-- Table Card -->
    <div class="tasks-table-card" id="tableSection">
      <?php if (empty($tasks)): ?>
        <div class="empty-state-box">
          <div style="font-size: 2.6rem; margin-bottom: 10px;">📋</div>
          <h3 style="font-size: 1.15rem; margin-bottom: 6px; color: var(--text-main);">No Assigned Tasks Found</h3>
          <p style="color: var(--text-muted); font-size: 0.88rem; max-width: 440px; margin: 0 auto 16px;">
            <?= ($searchFilter !== '' || $statusFilter !== '') ? 'No assigned tasks match your current filter criteria.' : 'You currently have no tasks assigned to you across any activity proposals.' ?>
          </p>
          <a href="<?= htmlspecialchars($allActivitiesBase) ?>" class="btn btn-outline btn-sm">View Activities</a>
        </div>
      <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="task-table">
            <thead>
              <tr>
                <th style="min-width: 220px;">Task &amp; Description</th>
                <th style="min-width: 180px;">Activity</th>
                <th style="min-width: 110px;">Deadline</th>
                <th style="min-width: 110px;">Timing</th>
                <th style="min-width: 130px;">Status</th>
                <th style="min-width: 120px;">Progress</th>
                <th style="text-align: right; min-width: 190px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tasks as $t): 
                $tTitle = $t['task_title'] ?: ($t['assigned_task'] ?: 'Untitled Task');
                $tDesc  = $t['task_description'] ?: ($t['contribution_desc'] ?: '');
                $tDue   = !empty($t['due_date']) ? date('M j, Y', strtotime($t['due_date'])) : 'No deadline';
                $timing = calculateTaskTiming($t['status'], $t['due_date'] ?? null);
                $pct    = max(0, min(100, (int)($t['completion_pct'] ?? 0)));
                $viewUrl = $viewActivityBase . "?id=" . (int)$t['activity_id'] . "#task-row-" . (int)$t['id'];
                $tStatus = in_array($t['status'], ['Not Started', 'In Progress', 'Completed', 'Delayed'], true) ? $t['status'] : 'Not Started';
              ?>
                <tr id="task-row-<?= (int)$t['id'] ?>">
                  <td>
                    <div style="font-weight: 700; color: var(--text-main); margin-bottom: 2px;">
                      <?= htmlspecialchars($tTitle) ?>
                    </div>
                    <?php if ($tDesc !== ''): ?>
                      <div style="font-size: 0.78rem; color: var(--text-muted); max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($tDesc) ?>">
                        <?= htmlspecialchars($tDesc) ?>
                      </div>
                    <?php endif; ?>
                    <div style="display:flex;gap:6px;align-items:center;margin-top:3px;flex-wrap:wrap;">
                      <?php if (!empty($t['committee'])): ?>
                        <span class="badge badge-secondary" style="font-size:0.68rem;"><?= htmlspecialchars($t['committee']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($t['role_in_event'])): ?>
                        <span style="font-size: 0.72rem; color: var(--text-muted);">
                          Role: <span style="font-weight: 600;"><?= htmlspecialchars($t['role_in_event']) ?></span>
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <div style="font-weight: 600; color: var(--text-main);">
                      <a href="<?= htmlspecialchars($viewUrl) ?>" style="color:inherit;text-decoration:none;" class="hover-underline">
                        <?= htmlspecialchars($t['activity_title']) ?>
                      </a>
                    </div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">
                      Lead: <?= htmlspecialchars($t['leader_name'] ?? 'Faculty Leader') ?>
                    </div>
                    <?php if (!empty($t['assigned_to_name']) && ($user['role'] ?? '') === 'program_head'): ?>
                      <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">
                        Assigned: <strong style="color: var(--text-main);"><?= htmlspecialchars($t['assigned_to_name']) ?></strong>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span style="font-size: 0.82rem; font-weight: 600;">
                      <?= htmlspecialchars($tDue) ?>
                    </span>
                  </td>
                  <td>
                    <?= $timing['badge'] ?>
                  </td>
                  <td>
                    <select class="task-status-select" onchange="updateTaskStatus(<?= (int)$t['id'] ?>, <?= (int)$t['activity_id'] ?>, this.value)">
                      <option value="Not Started" <?= $tStatus === 'Not Started' ? 'selected' : '' ?>>Not Started</option>
                      <option value="In Progress" <?= $tStatus === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                      <option value="Completed" <?= $tStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
                      <option value="Delayed" <?= $tStatus === 'Delayed' ? 'selected' : '' ?>>Delayed</option>
                    </select>
                  </td>
                  <td>
                    <div class="progress-bar-bg">
                      <div class="progress-bar-fill" style="width: <?= $pct ?>%;"></div>
                    </div>
                    <span style="font-size: 0.76rem; font-weight: 700; color: var(--text-main);"><?= $pct ?>%</span>
                  </td>
                  <td style="text-align: right;">
                    <div class="task-action-btns">
                      <button type="button" class="btn btn-outline btn-sm" style="padding: 4px 8px; font-size: 0.75rem;" onclick="openDeliverablesModal(<?= (int)$t['id'] ?>, <?= (int)$t['activity_id'] ?>, '<?= htmlspecialchars(addslashes($tTitle), ENT_QUOTES) ?>')">
                        📁 Deliverables
                      </button>
                      <a href="<?= htmlspecialchars($viewUrl) ?>" class="btn btn-outline btn-sm" style="padding: 4px 8px; font-size: 0.75rem;">
                        View →
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

<!-- ── Deliverables & Progress Modal ── -->
<div id="deliverablesModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;">
  <div style="background:var(--bg-card);border-radius:14px;border:1px solid var(--border);box-shadow:var(--shadow-lg);width:100%;max-width:560px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);">
      <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:1.1rem;">📁</span>
        <h3 id="delivModalTaskTitle" style="margin:0;font-size:1.05rem;font-weight:700;color:var(--text-main);">Task Deliverables</h3>
      </div>
      <button type="button" onclick="closeDeliverablesModal()" style="border:none;background:transparent;font-size:1.3rem;color:var(--text-muted);cursor:pointer;line-height:1;">&times;</button>
    </div>
    
    <div style="padding:20px;overflow-y:auto;display:flex;flex-direction:column;gap:18px;">
      <!-- Existing Deliverables Section -->
      <div>
        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);margin-bottom:8px;">Submitted Deliverables</div>
        <div id="delivListContainer" style="font-size:0.85rem;color:var(--text-muted);">
          Loading deliverables...
        </div>
      </div>

      <hr style="border:0;border-top:1px solid var(--border);margin:0;">

      <!-- Upload New Deliverable Form -->
      <form id="uploadDeliverableForm" onsubmit="submitDeliverableUpload(event)">
        <input type="hidden" id="modalTaskId" name="task_id" value="">
        <input type="hidden" id="modalActivityId" name="activity_id" value="">
        
        <div style="font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--text-muted);margin-bottom:8px;">Submit New Deliverable</div>
        
        <div id="delivUploadError" style="display:none;padding:10px 14px;border-radius:8px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:#dc2626;font-size:0.82rem;margin-bottom:12px;"></div>

        <div style="margin-bottom:12px;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:4px;color:var(--text-main);">Deliverable File (PDF, Word, Excel, PNG, JPG, ZIP)</label>
          <input type="file" id="modalFileInput" name="deliverable_file" required class="form-control" style="font-size:0.82rem;">
        </div>

        <div style="margin-bottom:14px;">
          <label style="display:block;font-size:0.8rem;font-weight:600;margin-bottom:4px;color:var(--text-main);">Notes / Description (Optional)</label>
          <textarea id="modalNotesInput" name="notes" rows="2" class="form-control" placeholder="Add any comments or context about this deliverable…" style="font-size:0.82rem;"></textarea>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;">
          <button type="button" class="btn btn-outline btn-sm" onclick="closeDeliverablesModal()">Cancel</button>
          <button type="submit" id="delivSubmitBtn" class="btn btn-primary btn-sm">Upload Deliverable</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  // Status Update via AJAX
  async function updateTaskStatus(taskId, activityId, newStatus) {
    try {
      const res = await fetch('<?= BASE_URL ?>/api/task-assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'update_status',
          activity_id: activityId,
          id: taskId,
          status: newStatus
        })
      });
      const data = await res.json();
      if (data && data.success) {
        // Success feedback
        window.location.reload();
      } else {
        alert(data.error || 'Failed to update task status.');
        window.location.reload();
      }
    } catch (err) {
      alert('Network error while updating task status.');
      window.location.reload();
    }
  }

  // Deliverables Modal
  function openDeliverablesModal(taskId, activityId, taskTitle) {
    document.getElementById('modalTaskId').value = taskId;
    document.getElementById('modalActivityId').value = activityId;
    document.getElementById('delivModalTaskTitle').textContent = taskTitle || 'Task Deliverables';
    document.getElementById('modalFileInput').value = '';
    document.getElementById('modalNotesInput').value = '';
    document.getElementById('delivUploadError').style.display = 'none';
    document.getElementById('deliverablesModal').style.display = 'flex';
    loadDeliverables(taskId);
  }

  function closeDeliverablesModal() {
    document.getElementById('deliverablesModal').style.display = 'none';
  }

  async function loadDeliverables(taskId) {
    const container = document.getElementById('delivListContainer');
    container.innerHTML = '<div style="padding:10px 0;text-align:center;color:var(--text-muted);">Loading deliverables…</div>';
    try {
      const res = await fetch('<?= BASE_URL ?>/api/task-deliverables.php?action=list&task_id=' + taskId);
      const data = await res.json();
      if (!data || !data.success || !data.deliverables || data.deliverables.length === 0) {
        container.innerHTML = '<div style="padding:10px 0;color:var(--text-muted);font-style:italic;">No deliverables submitted yet for this task.</div>';
        return;
      }
      let html = '<div style="display:flex;flex-direction:column;gap:8px;">';
      data.deliverables.forEach(d => {
        const badgeColor = d.review_status === 'approved' ? '#10b981' : (d.review_status === 'returned_for_improvement' ? '#ef4444' : '#f59e0b');
        const badgeText = d.review_status === 'approved' ? 'Approved' : (d.review_status === 'returned_for_improvement' ? 'Returned' : 'Pending Review');
        html += `
          <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:8px;padding:10px 12px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
            <div style="min-width:0;flex:1;">
              <div style="font-weight:600;color:var(--text-main);font-size:0.84rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <a href="${d.download_url}" target="_blank" style="color:var(--accent,#0284c7);text-decoration:none;">📄 ${escapeHtml(d.file_name)}</a>
              </div>
              <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px;">
                Uploaded ${escapeHtml(d.uploaded_at)} ${d.review_notes ? '• Notes: ' + escapeHtml(d.review_notes) : ''}
              </div>
            </div>
            <div>
              <span class="badge" style="background:${badgeColor};color:#fff;font-size:0.68rem;padding:3px 7px;border-radius:6px;">${badgeText}</span>
            </div>
          </div>
        `;
      });
      html += '</div>';
      container.innerHTML = html;
    } catch (e) {
      container.innerHTML = '<div style="padding:10px 0;color:#ef4444;">Failed to load deliverables.</div>';
    }
  }

  async function submitDeliverableUpload(e) {
    e.preventDefault();
    const btn = document.getElementById('delivSubmitBtn');
    const err = document.getElementById('delivUploadError');
    err.style.display = 'none';
    btn.disabled = true;
    btn.textContent = 'Uploading…';

    try {
      const form = document.getElementById('uploadDeliverableForm');
      const formData = new FormData(form);
      formData.append('action', 'upload');

      const res = await fetch('<?= BASE_URL ?>/api/task-deliverables.php', {
        method: 'POST',
        body: formData
      });
      const data = await res.json();
      if (data && data.success) {
        loadDeliverables(document.getElementById('modalTaskId').value);
        document.getElementById('modalFileInput').value = '';
        document.getElementById('modalNotesInput').value = '';
        btn.textContent = 'Uploaded ✓';
        setTimeout(() => {
          btn.disabled = false;
          btn.textContent = 'Upload Deliverable';
        }, 1200);
      } else {
        err.textContent = data.error || 'Failed to upload deliverable.';
        err.style.display = 'block';
        btn.disabled = false;
        btn.textContent = 'Upload Deliverable';
      }
    } catch (error) {
      err.textContent = 'Network error while uploading deliverable.';
      err.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Upload Deliverable';
    }
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function(m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDeliverablesModal();
  });
  document.getElementById('deliverablesModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'deliverablesModal') closeDeliverablesModal();
  });
</script>
</body>
</html>
