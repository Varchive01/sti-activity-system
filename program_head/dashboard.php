<?php
/**
 * program_head/dashboard.php
 *
 * Dedicated Program Head Dashboard for STI-ARMES.
 * Displays department-specific activities, task monitoring for department faculty,
 * and cross-department shared activities where department faculty are assigned.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Protect page: strictly program_head role
requireRole('program_head');

$user = currentUser();
$db   = getDB();

// Auto-deduplicate any redundant proposal submissions (e.g. concurrent submits or draft sync desyncs)
try {
    if (empty($_SESSION['last_dedup_check']) || (time() - $_SESSION['last_dedup_check']) > 120) {
        $_SESSION['last_dedup_check'] = time();
        cleanupDuplicateProposals($db);
    }
} catch (\Throwable $dedupEx) {
    error_log("Deduplication notice: " . $dedupEx->getMessage());
}

// 1. Identify authenticated Program Head's assigned department (IT, THM, or G.E.)
$dept = resolveUserDepartment($db, $user);
$user['dept'] = $dept;
$normDept = normalizeDepartmentName($dept);
$deptConfig = getDepartmentDashboardConfig($dept);
$deptLabel = $deptConfig['short_label'];
$deptFullLabel = $deptConfig['full_label'];
$dashboardTitle = $deptConfig['dashboard_title'];
$deptSqlU = buildDepartmentSqlCondition('u.department', $dept);
$deptSqlCreator = buildDepartmentSqlCondition('u_creator.department', $dept);

// Task progress reminders check
if (function_exists('checkTaskProgressReminders')) {
    try {
        checkTaskProgressReminders($db, $user['id'], $user['role']);
    } catch (\Throwable $e) {
        error_log("Program Head dashboard task reminder check error: " . $e->getMessage());
    }
}

// 2. Department Overview Statistics (Section A — Strictly Scoped to Program Head's Department)
$stats = [];
try {
    $userId = (int)$user['id'];
    $statsStmt = $db->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN a.status NOT IN ('draft', 'submitted', 'under_review', 'resubmitted') THEN a.id END) as total,
            SUM(CASE WHEN a.status IN ('submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted') AND (a.faculty_id = {$userId} OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$userId} AND pc.status = 'active')) THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN a.status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN a.status = 'draft' AND a.faculty_id = {$userId} THEN 1 ELSE 0 END) as drafts,
            SUM(CASE WHEN a.status = 'returned_for_revision' AND (a.faculty_id = {$userId} OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = {$userId} AND pc.status = 'active')) THEN 1 ELSE 0 END) as revisions
        FROM activities a
        JOIN users u ON a.faculty_id = u.id
        WHERE (
            a.faculty_id = ?
            OR ({$deptSqlU} AND a.status != 'draft')
            OR EXISTS (
                SELECT 1 FROM proposal_collaborators pc
                WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active'
            )
        )
    ");
    $statsStmt->execute([$userId, $userId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    error_log("Program Head dashboard stats error: " . $e->getMessage());
    $stats = [];
}

$totalActivities = (int)($stats['total'] ?? 0);
$pendingProposals = (int)($stats['pending'] ?? 0);
$approvedActivities = (int)($stats['approved'] ?? 0);
$completedActivities = (int)($stats['completed'] ?? 0);
$draftProposals = (int)($stats['drafts'] ?? 0);
$returnedProposals = (int)($stats['revisions'] ?? 0);

// 3. Department Proposals & Activities (Section B — Strictly Scoped to Program Head's Department)
$departmentActivities = [];
try {
    $deptActsStmt = $db->prepare("
        SELECT a.*, u.name as faculty_name, u.email as faculty_email, u.department as creator_department,
               COUNT(ft.id) as task_count,
               ROUND(AVG(ft.completion_pct)) as avg_progress,
               SUM(CASE WHEN ft.status = 'Delayed' OR (ft.due_date IS NOT NULL AND ft.due_date < CURDATE() AND ft.status != 'Completed') THEN 1 ELSE 0 END) as delayed_tasks
        FROM activities a
        JOIN users u ON a.faculty_id = u.id
        LEFT JOIN faculty_tasks ft ON a.id = ft.activity_id
        WHERE (
            a.faculty_id = ?
            OR ({$deptSqlU} AND a.status != 'draft')
            OR EXISTS (
                SELECT 1 FROM proposal_collaborators pc
                WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active'
            )
        )
        GROUP BY a.id
        ORDER BY a.updated_at DESC
        LIMIT 20
    ");
    $deptActsStmt->execute([$user['id'], $user['id']]);
    $departmentActivities = $deptActsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Program Head dashboard dept activities error: " . $e->getMessage());
    $departmentActivities = [];
}

// 4. Department Faculty Task Monitoring (Section C — Strictly Scoped to Department Activities & Faculty)
$departmentTasks = [];
try {
    $tasksStmt = $db->prepare("
        SELECT ft.*, a.id as activity_id, a.title as activity_title, a.status as activity_status,
               u.name as assigned_name, u.department as assigned_dept
        FROM faculty_tasks ft
        JOIN users u ON ft.assigned_user_id = u.id
        JOIN activities a ON ft.activity_id = a.id
        JOIN users u_creator ON a.faculty_id = u_creator.id
        WHERE ({$deptSqlU} OR ft.assigned_user_id = ?)
          AND ({$deptSqlCreator} OR a.faculty_id = ?)
          AND (a.status != 'draft' OR a.faculty_id = ?)
        ORDER BY 
            CASE WHEN ft.status = 'Delayed' THEN 1
                 WHEN ft.status = 'In Progress' THEN 2
                 WHEN ft.status = 'Not Started' THEN 3
                 ELSE 4 END,
            ft.due_date ASC, ft.id DESC
        LIMIT 20
    ");
    $tasksStmt->execute([$user['id'], $user['id'], $user['id']]);
    $departmentTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Program Head dashboard dept tasks error: " . $e->getMessage());
    $departmentTasks = [];
}

// 5. Department Schedules & Upcoming Events (Section D — Strictly Scoped to Program Head's Department)
$departmentSchedules = [];
try {
    $schedStmt = $db->prepare("
        SELECT a.id, a.title, a.event_date, a.start_time, a.end_time, a.venue, a.status,
               u.name as faculty_name, u.department as creator_dept,
               COUNT(s.id) as program_item_count
        FROM activities a
        JOIN users u ON a.faculty_id = u.id
        LEFT JOIN schedules s ON s.activity_id = a.id
        WHERE (
            a.faculty_id = ?
            OR ({$deptSqlU} AND a.status != 'draft')
            OR EXISTS (
                SELECT 1 FROM proposal_collaborators pc
                WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active'
            )
        )
        AND a.status NOT IN ('rejected')
        AND a.event_date IS NOT NULL
        GROUP BY a.id
        ORDER BY a.event_date ASC, a.start_time ASC
        LIMIT 15
    ");
    $schedStmt->execute([$user['id'], $user['id']]);
    $departmentSchedules = $schedStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log("Program Head dashboard schedules error: " . $e->getMessage());
    $departmentSchedules = [];
}

// 6. Department Faculty Roster & Workload (Section E — Strictly Scoped to Program Head's Department)
$departmentFacultyList = [];
try {
    $facStmt = $db->prepare("
        SELECT u.id, u.name, u.email, u.role, u.department, u.is_active,
               (SELECT COUNT(*) FROM activities a WHERE a.faculty_id = u.id AND (a.status != 'draft' OR a.faculty_id = ?)) as proposal_count,
               (SELECT COUNT(*) FROM faculty_tasks ft JOIN activities a2 ON ft.activity_id = a2.id JOIN users uc ON a2.faculty_id = uc.id WHERE ft.assigned_user_id = u.id AND ({$deptSqlU} OR a2.faculty_id = ?) AND a2.status != 'draft') as active_task_count
        FROM users u
        WHERE u.role IN ('faculty', 'program_head')
          AND {$deptSqlU}
        ORDER BY CASE WHEN u.role = 'program_head' THEN 0 ELSE 1 END, u.name ASC
    ");
    $facStmt->execute([$user['id'], $user['id']]);
    $dbFaculty = $facStmt->fetchAll(PDO::FETCH_ASSOC);

    $seenNames = [];
    foreach ($dbFaculty as $fRow) {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$fRow['name']));
        $seenNames[$key] = true;
        $departmentFacultyList[] = [
            'id' => (int)$fRow['id'],
            'name' => $fRow['name'],
            'email' => $fRow['email'],
            'role' => $fRow['role'],
            'department' => $deptLabel,
            'proposal_count' => (int)$fRow['proposal_count'],
            'task_count' => (int)$fRow['active_task_count'],
            'registered' => true,
        ];
    }

    // Merge official department faculty roster members so the department's full faculty list is always visible
    foreach ($deptConfig['roster'] as $rosterName) {
        $cleanRoster = preg_replace('/\b(Mr|Ms|Mrs|Dr|LPT|MAEd|PE|RN|MBA)\b\.?/i', '', $rosterName);
        $rKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$cleanRoster));
        $fullKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)$rosterName));
        $matched = false;
        foreach ($seenNames as $existingKey => $_) {
            if ($existingKey === $rKey || $existingKey === $fullKey || ($rKey !== '' && str_contains($existingKey, $rKey))) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $departmentFacultyList[] = [
                'id' => 0,
                'name' => $rosterName,
                'email' => '—',
                'role' => 'faculty',
                'department' => $deptLabel,
                'proposal_count' => 0,
                'task_count' => 0,
                'registered' => false,
            ];
        }
    }
} catch (\Throwable $e) {
    error_log("Program Head dashboard faculty roster error: " . $e->getMessage());
}

/**
 * Helper to calculate timing and health badge for tasks without fabricating data.
 */
function getTaskTimingBadge(string $status, ?string $dueDate): array {
    if ($status === 'Completed') {
        return [
            'label' => 'Completed',
            'badge' => '<span class="badge" style="background:#10B981;color:#fff;">Completed</span>'
        ];
    }
    $today = date('Y-m-d');
    if (!empty($dueDate) && $dueDate < $today) {
        return [
            'label' => 'Delayed',
            'badge' => '<span class="badge" style="background:#EF4444;color:#fff;">Delayed (Overdue)</span>'
        ];
    }
    if ($status === 'Delayed') {
        return [
            'label' => 'Delayed',
            'badge' => '<span class="badge" style="background:#EF4444;color:#fff;">Delayed</span>'
        ];
    }
    if (!empty($dueDate)) {
        $diffDays = (int)ceil((strtotime($dueDate) - strtotime($today)) / 86400);
        if ($diffDays >= 0 && $diffDays <= 3) {
            return [
                'label' => 'At Risk',
                'badge' => '<span class="badge" style="background:#F59E0B;color:#fff;">At Risk (' . $diffDays . 'd left)</span>'
            ];
        }
    }
    return [
        'label' => 'On Track',
        'badge' => '<span class="badge" style="background:#0284C7;color:#fff;">On Track</span>'
    ];
}

/**
 * Status badge styling helper
 */
function renderActivityStatusBadge(string $status): string {
    $map = [
        'draft'                  => ['label' => 'Draft', 'bg' => '#64748B', 'color' => '#fff'],
        'submitted'              => ['label' => 'Under Review', 'bg' => '#F59E0B', 'color' => '#fff'],
        'under_review'           => ['label' => 'Under Review', 'bg' => '#F59E0B', 'color' => '#fff'],
        'endorsed'               => ['label' => 'Endorsed', 'bg' => '#3B82F6', 'color' => '#fff'],
        'pending_final_approval' => ['label' => 'Pending Dean', 'bg' => '#8B5CF6', 'color' => '#fff'],
        'resubmitted'            => ['label' => 'Resubmitted', 'bg' => '#F59E0B', 'color' => '#fff'],
        'returned_for_revision'  => ['label' => 'For Revision', 'bg' => '#DC2626', 'color' => '#fff'],
        'approved'               => ['label' => 'Approved', 'bg' => '#10B981', 'color' => '#fff'],
        'completed'              => ['label' => 'Completed', 'bg' => '#059669', 'color' => '#fff'],
        'rejected'               => ['label' => 'Rejected', 'bg' => '#991B1B', 'color' => '#fff'],
    ];
    $conf = $map[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'bg' => '#64748B', 'color' => '#fff'];
    return '<span class="badge" style="background:' . $conf['bg'] . ';color:' . $conf['color'] . ';padding:4px 9px;border-radius:999px;font-size:0.72rem;font-weight:600;">' . htmlspecialchars($conf['label']) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="base-url" content="<?= BASE_URL ?>">
  <meta name="current-user" data-id="<?= (int)$user['id'] ?>" data-role="<?= htmlspecialchars($user['role']) ?>" data-dept="<?= htmlspecialchars($user['dept'] ?? '') ?>">
  <title><?= htmlspecialchars($dashboardTitle) ?> – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.4">
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
    /* ── Typography Standardization: Plus Jakarta Sans Parity ── */
    body, body *,
    h1, h2, h3, h4, h5, h6,
    .page-title,
    .topbar .page-title,
    .card-header h2,
    .card-header h3,
    .section-title,
    .overview-title,
    .stat-val,
    .stat-card-meta,
    .stat-label,
    .stat-sub,
    th, td,
    .data-table th,
    .data-table td,
    .activity-table th,
    .activity-table td,
    .item-title,
    .act-title,
    .item-meta,
    .act-date,
    .badge,
    .btn,
    .topbar-user-name,
    .topbar-user-role,
    label, input, button, select, textarea {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .page-title,
    .topbar .page-title {
      font-size: 1.15rem !important;
      font-weight: 800 !important;
      color: #0A1628 !important;
      line-height: 1.2 !important;
      letter-spacing: -0.02em !important;
    }
    [data-theme="dark"] .page-title,
    [data-theme="dark"] .topbar .page-title {
      color: #F8FAFC !important;
    }

    /* ── Content & Container Spacing ── */
    .content {
      padding: var(--content-padding-y, 28px) var(--content-padding-x, 32px);
      box-sizing: border-box;
      width: 100%;
      max-width: 100%;
      min-width: 0;
      margin: 0;
    }

    /* Program Head Hero Header */
    .ph-hero-banner {
      position: relative;
      background: linear-gradient(135deg, #0A1628 0%, #0F284E 50%, #163E72 100%);
      border-radius: 14px;
      padding: 24px 28px;
      color: #FFFFFF;
      margin-bottom: 24px;
      box-shadow: 0 4px 16px rgba(10, 22, 40, 0.12);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      overflow: hidden;
    }

    .ph-hero-tag {
      display: inline-block;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--sti-gold, #F4A900);
      background: rgba(244, 169, 0, 0.16);
      padding: 3px 10px;
      border-radius: 20px;
      margin-bottom: 8px;
    }

    .ph-hero-title {
      font-size: 1.45rem;
      font-weight: 800;
      margin: 0 0 6px 0;
      color: #FFFFFF;
      line-height: 1.2;
    }

    .ph-hero-subtitle {
      font-size: 0.85rem;
      color: #CBD5E1;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .ph-dept-pill {
      background: rgba(255, 255, 255, 0.18);
      color: #FFFFFF;
      font-weight: 700;
      padding: 2px 10px;
      border-radius: 999px;
      font-size: 0.8rem;
      border: 1px solid rgba(255, 255, 255, 0.25);
    }

    .ph-hero-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-shrink: 0;
    }

    .btn-ph-primary {
      background: #0284C7;
      color: #FFFFFF !important;
      padding: 9px 18px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.85rem;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      transition: background 0.15s ease;
      border: none;
    }
    .btn-ph-primary:hover {
      background: #0369A1;
    }

    .btn-ph-secondary {
      background: rgba(255, 255, 255, 0.12);
      color: #FFFFFF !important;
      padding: 9px 16px;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.85rem;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      border: 1px solid rgba(255, 255, 255, 0.2);
      transition: background 0.15s ease;
    }
    .btn-ph-secondary:hover {
      background: rgba(255, 255, 255, 0.2);
    }

    /* Stat Cards Grid (Replicating Faculty Original Summary Card Styling) */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 12px;
      align-items: stretch;
      width: 100%;
      margin-bottom: 24px;
    }

    .stat-card {
      position: relative;
      background: #FFFFFF !important;
      border: 1px solid var(--border, #E2E8F0) !important;
      border-radius: 12px !important;
      box-shadow: 0 1px 3px rgba(10, 22, 40, 0.04) !important;
      padding: 14px 16px 13px !important;
      display: flex !important;
      flex-direction: column !important;
      justify-content: flex-start !important;
      align-items: flex-start !important;
      text-decoration: none !important;
      color: inherit !important;
      min-height: 114px;
      box-sizing: border-box;
      overflow: hidden !important;
      transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
      outline: none !important;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(10, 22, 40, 0.08) !important;
      border-color: #CBD5E1 !important;
    }

    /* Thin colored bottom accent line */
    .stat-card::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 3px;
      z-index: 2;
      pointer-events: none;
    }

    /* 1. Total: multi-segment accent (amber, green, blue) */
    .stat-card.stat-card-total::after {
      background: linear-gradient(90deg, #F59E0B 0%, #F59E0B 33.3%, #10B981 33.3%, #10B981 66.6%, #0284C7 66.6%, #0284C7 100%) !important;
    }

    /* 2. Pending: amber accent */
    .stat-card.stat-card-pending::after {
      background: #F59E0B !important;
    }

    /* 3. Approved: green accent */
    .stat-card.stat-card-approved::after {
      background: #10B981 !important;
    }

    /* 4. Completed: purple accent */
    .stat-card.stat-card-completed::after {
      background: #8B5CF6 !important;
    }
    .stat-card.stat-card-draft::after {
      background: #94A3B8 !important;
    }
    .stat-card.stat-card-returned::after {
      background: #EF4444 !important;
    }
    .stat-card-draft .stat-icon     { background: rgba(148, 163, 184, 0.15) !important; color: #64748B !important; }
    .stat-card-returned .stat-icon  { background: rgba(239, 68, 68, 0.12) !important; color: #DC2626 !important; }
    [data-theme="dark"] .stat-card-draft .stat-icon     { background: rgba(148, 163, 184, 0.20) !important; color: #94a3b8 !important; }
    [data-theme="dark"] .stat-card-returned .stat-icon  { background: rgba(239, 68, 68, 0.18) !important; color: #f87171 !important; }

    /* Dark Mode Card Background & Borders */
    [data-theme="dark"] .stat-card {
      background: #0F172A !important;
      border-color: rgba(255, 255, 255, 0.08) !important;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25) !important;
    }
    [data-theme="dark"] .stat-card:hover {
      border-color: rgba(255, 255, 255, 0.16) !important;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35) !important;
    }

    /* Compact Soft-Tinted Icon Containers */
    .stat-icon {
      width: 28px !important;
      height: 28px !important;
      min-width: 28px !important;
      border-radius: 7px !important;
      display: flex !important;
      align-items: center !important;
      justify-content: center !important;
      margin-bottom: 10px !important;
      flex-shrink: 0 !important;
    }

    .stat-icon svg {
      width: 15px !important;
      height: 15px !important;
    }

    .stat-card-total .stat-icon     { background: rgba(2, 132, 199, 0.12) !important; color: #0284C7 !important; }
    .stat-card-pending .stat-icon   { background: rgba(245, 158, 11, 0.14) !important; color: #D97706 !important; }
    .stat-card-approved .stat-icon  { background: rgba(16, 185, 129, 0.14) !important; color: #059669 !important; }
    .stat-card-completed .stat-icon { background: rgba(139, 92, 246, 0.14) !important; color: #7C3AED !important; }

    [data-theme="dark"] .stat-card-total .stat-icon     { background: rgba(56, 189, 248, 0.15) !important; color: #38bdf8 !important; }
    [data-theme="dark"] .stat-card-pending .stat-icon   { background: rgba(251, 191, 36, 0.18) !important; color: #fbbf24 !important; }
    [data-theme="dark"] .stat-card-approved .stat-icon  { background: rgba(52, 211, 153, 0.15) !important; color: #34d399 !important; }
    [data-theme="dark"] .stat-card-completed .stat-icon { background: rgba(196, 181, 253, 0.18) !important; color: #c4b5fd !important; }

    .stat-card-info {
      display: flex !important;
      flex-direction: column !important;
      width: 100% !important;
      align-items: flex-start !important;
      text-align: left !important;
    }

    .stat-card-meta,
    .stat-label {
      font-size: 0.72rem !important;
      font-weight: 700 !important;
      letter-spacing: 0.04em !important;
      text-transform: uppercase !important;
      color: #64748B !important;
      margin-bottom: 3px !important;
      line-height: 1.2 !important;
      text-align: left !important;
    }
    [data-theme="dark"] .stat-card-meta,
    [data-theme="dark"] .stat-label {
      color: #94A3B8 !important;
    }

    .stat-card-row {
      display: flex !important;
      align-items: center !important;
      justify-content: flex-start !important;
      width: 100% !important;
      margin-bottom: 2px !important;
    }

    .stat-val {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 1.65rem !important;
      font-weight: 800 !important;
      line-height: 1.1 !important;
      color: #0A1628 !important;
      letter-spacing: -0.025em !important;
      margin: 0 !important;
      text-align: left !important;
    }
    [data-theme="dark"] .stat-val {
      color: #F8FAFC !important;
    }

    .stat-sub {
      font-size: 0.75rem !important;
      font-weight: 500 !important;
      line-height: 1.25 !important;
      color: #64748B !important;
      text-align: left !important;
    }
    [data-theme="dark"] .stat-sub {
      color: #94A3B8 !important;
    }

    /* Section Cards & Tables */
    .section-card {
      background: var(--bg-card, #FFFFFF);
      border: 1px solid var(--border, #E2E8F0);
      border-radius: 12px;
      box-shadow: 0 1px 3px rgba(10, 22, 40, 0.04);
      margin-bottom: 24px;
      overflow: hidden;
    }

    .section-header {
      padding: 14px 20px;
      border-bottom: 1px solid var(--border, #E2E8F0);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      background: var(--bg-card, #FFFFFF);
    }

    .section-title-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .section-icon {
      width: 30px;
      height: 30px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .section-icon-blue   { background: rgba(2, 132, 199, 0.12); color: #0284C7; }
    .section-icon-amber  { background: rgba(245, 158, 11, 0.14); color: #D97706; }
    .section-icon-purple { background: rgba(139, 92, 246, 0.14); color: #7C3AED; }

    .section-title {
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text-main, #0A1628);
      margin: 0;
    }

    .section-desc {
      font-size: 0.75rem;
      color: var(--text-muted, #64748B);
      margin: 1px 0 0 0;
    }

    .table-wrap {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      width: 100%;
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.82rem;
    }

    .data-table th {
      padding: 10px 16px;
      background: var(--border-light, #F8FAFD);
      color: var(--text-muted, #64748B);
      font-weight: 700;
      text-transform: uppercase;
      font-size: 0.72rem;
      letter-spacing: 0.05em;
      border-bottom: 1px solid var(--border, #E2E8F0);
      text-align: left;
      white-space: nowrap;
    }

    .data-table td {
      padding: 12px 16px;
      border-bottom: 1px solid var(--border, #E2E8F0);
      vertical-align: middle;
      color: var(--text-main, #0A1628);
    }

    .data-table tr:last-child td {
      border-bottom: none;
    }

    .data-table tr:hover td {
      background: rgba(2, 132, 199, 0.02);
    }

    .item-title {
      font-size: 0.84rem;
      font-weight: 600;
      color: var(--text-main, #0A1628);
      text-decoration: none;
      display: block;
      margin-bottom: 2px;
    }
    .item-title:hover {
      color: #0284C7;
    }

    .item-meta {
      font-size: 0.75rem;
      color: var(--text-muted, #64748B);
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .progress-bar-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 110px;
    }

    .progress-track {
      flex: 1;
      height: 6px;
      background: var(--border, #E2E8F0);
      border-radius: 999px;
      overflow: hidden;
    }

    .progress-fill {
      height: 100%;
      background: #0284C7;
      border-radius: 999px;
      transition: width 0.3s ease;
    }

    .progress-text {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--text-muted, #64748B);
      min-width: 32px;
      text-align: right;
    }

    .empty-state {
      padding: 32px 18px;
      text-align: center;
      color: var(--text-muted, #64748B);
      font-size: 0.85rem;
    }
    .empty-state svg {
      margin-bottom: 8px;
      opacity: 0.45;
    }

    /* Responsive adjustments */
    @media (max-width: 1024px) {
      .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 10px;
      }
    }
    @media (max-width: 768px) {
      .content {
        padding: 16px;
      }
      .ph-hero-banner {
        flex-direction: column;
        align-items: flex-start;
      }
      .ph-hero-actions {
        width: 100%;
        flex-direction: column;
      }
      .btn-ph-primary, .btn-ph-secondary {
        width: 100%;
        justify-content: center;
      }
      .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 10px;
      }
    }
    @media (max-width: 480px) {
      .stat-grid {
        grid-template-columns: 1fr !important;
        gap: 8px;
      }
      .stat-card {
        padding: 12px 14px !important;
      }
    }
  </style>
</head>
<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">

    <!-- Topbar -->
    <header class="topbar">
      <div class="page-title"><?= htmlspecialchars($dashboardTitle) ?></div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">

      <!-- Section A: Department Overview Banner (Shared Role Banner) -->
      <?php include __DIR__ . '/../includes/dashboard-welcome-banner.php'; ?>

      <!-- Stat Cards: Department Overview -->
      <div class="stat-grid">
        <!-- 1. Total Department Activities -->
        <a href="<?= BASE_URL ?>/faculty/activities.php" class="stat-card stat-card-total" style="cursor:pointer;">
          <div class="stat-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
          </div>
          <div class="stat-card-info">
            <div class="stat-card-meta"><?= htmlspecialchars($deptLabel) ?> Activities</div>
            <div class="stat-card-row">
              <div class="stat-val"><?= number_format($totalActivities) ?></div>
            </div>
            <div class="stat-sub"><?= htmlspecialchars($deptLabel) ?> proposals &amp; activities</div>
          </div>
        </a>

        <!-- 2. Pending Proposals -->
        <a href="<?= BASE_URL ?>/faculty/pending.php" class="stat-card stat-card-pending" style="cursor:pointer;">
          <div class="stat-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div class="stat-card-info">
            <div class="stat-card-meta">Pending Proposals</div>
            <div class="stat-card-row">
              <div class="stat-val"><?= number_format($pendingProposals) ?></div>
            </div>
            <div class="stat-sub">Proposals under review</div>
          </div>
        </a>

        <!-- 3. Approved Activities -->
        <a href="<?= BASE_URL ?>/faculty/approved.php" class="stat-card stat-card-approved" style="cursor:pointer;">
          <div class="stat-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div class="stat-card-info">
            <div class="stat-card-meta">Approved</div>
            <div class="stat-card-row">
              <div class="stat-val"><?= number_format($approvedActivities) ?></div>
            </div>
            <div class="stat-sub"><?= htmlspecialchars($deptLabel) ?> approved events</div>
          </div>
        </a>

        <!-- 4. Completed Activities -->
        <a href="<?= BASE_URL ?>/faculty/activities.php?status=completed" class="stat-card stat-card-completed" style="cursor:pointer;">
          <div class="stat-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
          </div>
          <div class="stat-card-info">
            <div class="stat-card-meta">Completed</div>
            <div class="stat-card-row">
              <div class="stat-val"><?= number_format($completedActivities) ?></div>
            </div>
            <div class="stat-sub"><?= htmlspecialchars($deptLabel) ?> concluded events</div>
          </div>
        </a>

        <!-- 5. Draft Proposals -->
        <a href="<?= BASE_URL ?>/faculty/activities.php?status=draft" class="stat-card stat-card-draft" style="cursor:pointer;">
          <div class="stat-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
          </div>
          <div class="stat-card-info">
            <div class="stat-card-meta">Draft Proposals</div>
            <div class="stat-card-row">
              <div class="stat-val"><?= number_format($draftProposals) ?></div>
            </div>
            <div class="stat-sub">Unsubmitted drafts</div>
          </div>
        </a>

        <!-- 6. Returned Proposals -->
        <a href="<?= BASE_URL ?>/faculty/returned.php" class="stat-card stat-card-returned" style="cursor:pointer;">
          <div class="stat-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div class="stat-card-info">
            <div class="stat-card-meta">Returned Proposals</div>
            <div class="stat-card-row">
              <div class="stat-val"><?= number_format($returnedProposals) ?></div>
            </div>
            <div class="stat-sub">Needs revision</div>
          </div>
        </a>
      </div>

      <!-- Local Offline Drafts Container -->
      <div id="localDraftsContainer" style="display:none;"></div>

      <!-- Section B: Department Proposals & Activities -->
      <div class="section-card">
        <div class="section-header">
          <div class="section-title-wrap">
            <div class="section-icon section-icon-blue">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
            </div>
            <div>
              <h2 class="section-title"><?= htmlspecialchars($deptLabel) ?> Proposals &amp; Activities</h2>
              <p class="section-desc">Proposals and activities spearheaded by <?= htmlspecialchars($deptFullLabel) ?> faculty</p>
            </div>
          </div>
          <div>
            <a href="<?= BASE_URL ?>/faculty/activities.php" class="btn btn-sm btn-outline">View All (<?= count($departmentActivities) ?>)</a>
          </div>
        </div>

        <div class="table-wrap">
          <?php if (empty($departmentActivities)): ?>
            <div class="empty-state">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="36" height="36">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <div>No <?= htmlspecialchars($deptLabel) ?> proposals or activities found yet. Create a proposal to get started.</div>
            </div>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width:36%;">Activity Title &amp; Proponent</th>
                  <th style="width:22%;">Schedule &amp; Venue</th>
                  <th style="width:14%;">Status</th>
                  <th style="width:16%;">Task Progress</th>
                  <th style="width:12%;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($departmentActivities as $act): 
                  $hasProgress = ($act['task_count'] > 0 && $act['avg_progress'] !== null);
                  $progVal = $hasProgress ? (int)$act['avg_progress'] : 0;
                  $isDelayed = ((int)$act['delayed_tasks'] > 0);
                ?>
                  <tr>
                    <td>
                      <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= (int)$act['id'] ?>" class="item-title">
                        <?= htmlspecialchars($act['title']) ?>
                      </a>
                      <div class="item-meta">
                        <span>Lead: <strong><?= htmlspecialchars($act['faculty_name'] ?? 'Faculty') ?></strong></span>
                        <span>• <?= htmlspecialchars($deptLabel) ?></span>
                      </div>
                    </td>
                    <td>
                      <div><?= !empty($act['event_date']) ? date('M j, Y', strtotime($act['event_date'])) : 'TBD' ?></div>
                      <div class="item-meta">
                        <?= !empty($act['venue']) ? htmlspecialchars($act['venue']) : 'Venue TBD' ?>
                        <?php if (!empty($act['start_time'])): ?>
                          • <?= date('g:i A', strtotime($act['start_time'])) ?>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <?= renderActivityStatusBadge((string)$act['status']) ?>
                      <?php if ($isDelayed): ?>
                        <div style="margin-top:3px;">
                          <span class="badge" style="background:#EF4444;color:#fff;font-size:0.72rem;padding:3px 8px;font-weight:600;">Tasks Delayed</span>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($hasProgress): ?>
                        <div class="progress-bar-wrap">
                          <div class="progress-track">
                            <div class="progress-fill" style="width: <?= min(100, max(0, $progVal)) ?>%;"></div>
                          </div>
                          <span class="progress-text"><?= $progVal ?>%</span>
                        </div>
                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
                          <?= (int)$act['task_count'] ?> assigned task<?= (int)$act['task_count'] === 1 ? '' : 's' ?>
                        </div>
                      <?php else: ?>
                        <span style="color:var(--text-muted);font-size:0.75rem;">—</span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                      <div style="display:inline-flex;gap:6px;">
                        <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= (int)$act['id'] ?>" class="btn btn-sm btn-outline">View</a>
                        <?php if (in_array($act['status'], ['draft', 'returned_for_revision'], true)): ?>
                        <?php if ($act['status'] === 'draft'): ?>
                          <button type="button" class="btn btn-sm btn-danger" onclick="confirmDeleteDraft(<?= (int)$act['id'] ?>, '<?= htmlspecialchars(addslashes($act['title']), ENT_QUOTES) ?>')">Delete</button>
                        <?php endif; ?>
                        
                          <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= (int)$act['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- Section C: Department Faculty Task Monitoring -->
      <div class="section-card">
        <div class="section-header">
          <div class="section-title-wrap">
            <div class="section-icon section-icon-amber">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
              </svg>
            </div>
            <div>
              <h2 class="section-title"><?= htmlspecialchars($deptLabel) ?> Faculty Task Monitoring</h2>
              <p class="section-desc">Task assignments allocated to <?= htmlspecialchars($deptFullLabel) ?> faculty and activities</p>
            </div>
          </div>
          <div>
            <a href="<?= BASE_URL ?>/faculty/tasks.php" class="btn btn-sm btn-outline">Department Tasks</a>
          </div>
        </div>

        <div class="table-wrap">
          <?php if (empty($departmentTasks)): ?>
            <div class="empty-state">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="36" height="36">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 13l4 4L19 7" />
              </svg>
              <div>No faculty task assignments found for <?= htmlspecialchars($deptLabel) ?>.</div>
            </div>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width:30%;">Task &amp; Parent Activity</th>
                  <th style="width:22%;">Assigned <?= htmlspecialchars($deptLabel) ?> Faculty</th>
                  <th style="width:16%;">Due Date &amp; Timing</th>
                  <th style="width:18%;">Progress</th>
                  <th style="width:14%;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($departmentTasks as $task): 
                  $tTitle = !empty($task['task_title']) ? $task['task_title'] : (!empty($task['assigned_task']) ? $task['assigned_task'] : 'Assigned Task');
                  $timing = getTaskTimingBadge((string)$task['status'], $task['due_date']);
                  $pct = (int)($task['completion_pct'] ?? 0);
                ?>
                  <tr>
                    <td>
                      <div class="item-title"><?= htmlspecialchars($tTitle) ?></div>
                      <div class="item-meta">
                        <span>Activity: <strong><?= htmlspecialchars($task['activity_title']) ?></strong></span>
                      </div>
                    </td>
                    <td>
                      <strong><?= htmlspecialchars($task['assigned_name'] ?? 'Faculty Member') ?></strong>
                      <?php if (!empty($task['role_in_event'])): ?>
                        <div class="item-meta"><?= htmlspecialchars($task['role_in_event']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div><?= !empty($task['due_date']) ? date('M j, Y', strtotime($task['due_date'])) : 'No Due Date' ?></div>
                      <div style="margin-top:3px;"><?= $timing['badge'] ?></div>
                    </td>
                    <td>
                      <div class="progress-bar-wrap">
                        <div class="progress-track">
                          <div class="progress-fill" style="width: <?= min(100, max(0, $pct)) ?>%;"></div>
                        </div>
                        <span class="progress-text"><?= $pct ?>%</span>
                      </div>
                      <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
                        Status: <strong><?= htmlspecialchars($task['status']) ?></strong>
                      </div>
                    </td>
                    <td style="text-align:right;">
                      <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= (int)$task['activity_id'] ?>" class="btn btn-sm btn-outline">View Activity</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- Section D: Department Schedules & Upcoming Events -->
      <div class="section-card">
        <div class="section-header">
          <div class="section-title-wrap">
            <div class="section-icon section-icon-purple">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </div>
            <div>
              <h2 class="section-title"><?= htmlspecialchars($deptLabel) ?> Schedules &amp; Event Timeline</h2>
              <p class="section-desc">Scheduled dates, times, and venues exclusively for <?= htmlspecialchars($deptFullLabel) ?> activities</p>
            </div>
          </div>
          <div>
            <a href="<?= BASE_URL ?>/faculty/calendar.php" class="btn btn-sm btn-outline">Department Calendar (<?= count($departmentSchedules) ?>)</a>
          </div>
        </div>

        <div class="table-wrap">
          <?php if (empty($departmentSchedules)): ?>
            <div class="empty-state">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="36" height="36">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
              <div>No scheduled events found for <?= htmlspecialchars($deptLabel) ?>.</div>
            </div>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width:34%;">Activity &amp; Lead Faculty</th>
                  <th style="width:22%;">Event Date &amp; Time</th>
                  <th style="width:20%;">Venue</th>
                  <th style="width:14%;">Status</th>
                  <th style="width:10%;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($departmentSchedules as $sched): ?>
                  <tr>
                    <td>
                      <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= (int)$sched['id'] ?>" class="item-title">
                        <?= htmlspecialchars($sched['title']) ?>
                      </a>
                      <div class="item-meta">
                        <span>Lead: <strong><?= htmlspecialchars($sched['faculty_name'] ?? 'Faculty') ?></strong></span>
                        <?php if ((int)$sched['program_item_count'] > 0): ?>
                          <span>• <?= (int)$sched['program_item_count'] ?> program item<?= (int)$sched['program_item_count'] === 1 ? '' : 's' ?></span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div><strong><?= !empty($sched['event_date']) ? date('M j, Y', strtotime($sched['event_date'])) : 'TBD' ?></strong></div>
                      <div class="item-meta">
                        <?php if (!empty($sched['start_time'])): ?>
                          <?= date('g:i A', strtotime($sched['start_time'])) ?>
                          <?= !empty($sched['end_time']) ? ' – ' . date('g:i A', strtotime($sched['end_time'])) : '' ?>
                        <?php else: ?>
                          Time TBD
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <div><?= !empty($sched['venue']) ? htmlspecialchars($sched['venue']) : 'Venue TBD' ?></div>
                    </td>
                    <td>
                      <?= renderActivityStatusBadge((string)$sched['status']) ?>
                    </td>
                    <td style="text-align:right;">
                      <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= (int)$sched['id'] ?>" class="btn btn-sm btn-outline">View</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

      <!-- Section E: Department Faculty Roster -->
      <div class="section-card">
        <div class="section-header">
          <div class="section-title-wrap">
            <div class="section-icon section-icon-blue">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </div>
            <div>
              <h2 class="section-title"><?= htmlspecialchars($deptLabel) ?> Faculty Roster</h2>
              <p class="section-desc">Faculty members belonging exclusively to <?= htmlspecialchars($deptFullLabel) ?></p>
            </div>
          </div>
          <div>
            <span class="badge" style="background:rgba(2, 132, 199, 0.14);color:#0284C7;padding:4px 10px;border-radius:999px;font-size:0.72rem;font-weight:700;">
              <?= count($departmentFacultyList) ?> <?= htmlspecialchars($deptLabel) ?> Members
            </span>
          </div>
        </div>

        <div class="table-wrap">
          <?php if (empty($departmentFacultyList)): ?>
            <div class="empty-state">
              <div>No faculty members found for <?= htmlspecialchars($deptLabel) ?>.</div>
            </div>
          <?php else: ?>
            <table class="data-table">
              <thead>
                <tr>
                  <th style="width:38%;">Faculty Name</th>
                  <th style="width:24%;">Department</th>
                  <th style="width:18%;">Role</th>
                  <th style="width:10%;text-align:center;">Proposals</th>
                  <th style="width:10%;text-align:center;">Tasks</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($departmentFacultyList as $fMember): ?>
                  <tr>
                    <td>
                      <div class="item-title" style="cursor:default;"><?= htmlspecialchars($fMember['name']) ?></div>
                      <?php if (!empty($fMember['email']) && $fMember['email'] !== '—'): ?>
                        <div class="item-meta"><?= htmlspecialchars($fMember['email']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span><?= htmlspecialchars($fMember['department']) ?></span>
                    </td>
                    <td>
                      <span class="badge" style="background:<?= $fMember['role'] === 'program_head' ? '#0284C7' : '#64748B' ?>;color:#fff;padding:3px 8px;border-radius:999px;font-size:0.7rem;font-weight:600;">
                        <?= $fMember['role'] === 'program_head' ? 'Program Head' : 'Faculty' ?>
                      </span>
                    </td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$fMember['proposal_count'] ?></td>
                    <td style="text-align:center;font-weight:700;"><?= (int)$fMember['task_count'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /.content -->
  </div><!-- /.main-wrap -->
  <script src="<?= BASE_URL ?>/assets/js/offline-drafts.js?v=<?= time() ?>"></script>
</body>
</html>

