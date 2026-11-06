<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
$user = currentUser();
if (($user['role'] ?? '') === 'program_head') {
    header('Location: ' . BASE_URL . '/program_head/dashboard.php');
    exit;
}
requireRole('faculty');

$user = currentUser();
$db   = getDB();

$stats = null;
$s = [];
try {
    $stats = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(status='draft') as drafts,
            SUM(status='submitted' OR status='under_review' OR status='endorsed' OR status='pending_final_approval' OR status='resubmitted') as pending,
            SUM(status='approved') as approved,
            SUM(status='returned_for_revision') as revisions,
            SUM(status='completed') as completed
        FROM activities 
        WHERE (faculty_id = ? OR id IN (SELECT activity_id FROM proposal_collaborators WHERE user_id = ? AND status = 'active'))
    ");
    $stats->execute([$user['id'], $user['id']]);
    $s = $stats->fetch() ?: [];
} catch (\Throwable $e) {
    // Graceful fallback if proposal_collaborators table does not exist
    try {
        $stats = $db->prepare("
            SELECT
                COUNT(*) as total,
                SUM(status='draft') as drafts,
                SUM(status='submitted' OR status='under_review' OR status='endorsed' OR status='pending_final_approval' OR status='resubmitted') as pending,
                SUM(status='approved') as approved,
                SUM(status='returned_for_revision') as revisions,
                SUM(status='completed') as completed
            FROM activities 
            WHERE faculty_id = ?
        ");
        $stats->execute([$user['id']]);
        $s = $stats->fetch() ?: [];
    } catch (\Throwable $e2) {
        $s = [];
    }
}

$tot = max(1, (int)($s['total'] ?? 0));
$pPending = round(((int)($s['pending'] ?? 0) / $tot) * 100, 1);
$pApproved = round(((int)($s['approved'] ?? 0) / $tot) * 100, 1);
$pCompleted = round(((int)($s['completed'] ?? 0) / $tot) * 100, 1);

$activities = [];
try {
    $recent = $db->prepare("
        SELECT a.*, u.name as faculty_name FROM activities a
        JOIN users u ON a.faculty_id = u.id
        WHERE (a.faculty_id = ? OR EXISTS (SELECT 1 FROM proposal_collaborators pc WHERE pc.activity_id = a.id AND pc.user_id = ? AND pc.status = 'active'))
        ORDER BY a.updated_at DESC LIMIT 8
    ");
    $recent->execute([$user['id'], $user['id']]);
    $activities = $recent->fetchAll();
} catch (\Throwable $e) {
    // Graceful fallback if proposal_collaborators table does not exist
    try {
        $recent = $db->prepare("
            SELECT a.*, u.name as faculty_name FROM activities a
            JOIN users u ON a.faculty_id = u.id
            WHERE a.faculty_id = ?
            ORDER BY a.updated_at DESC LIMIT 8
        ");
        $recent->execute([$user['id']]);
        $activities = $recent->fetchAll();
    } catch (\Throwable $e2) {
        $activities = [];
    }
}

// Upcoming activities derived from existing activities
$upcomingActivities = [];
foreach ($activities as $act) {
  if (in_array($act['status'], ['approved', 'endorsed', 'submitted', 'under_review', 'pending_final_approval', 'resubmitted'])) {
    $upcomingActivities[] = $act;
  }
}
usort($upcomingActivities, function($a, $b) {
  $tA = !empty($a['event_date']) ? strtotime($a['event_date']) : 9999999999;
  $tB = !empty($b['event_date']) ? strtotime($b['event_date']) : 9999999999;
  return $tA - $tB;
});
$upcomingList = array_slice($upcomingActivities, 0, 2);
if (empty($upcomingList) && !empty($activities)) {
  $upcomingList = array_slice($activities, 0, 2);
}

// Stagnant proposals check (3 days without update in pending review status)
try {
    checkProposalEscalations($db, $user['id'], $user['role']);
} catch (\Throwable $e) {
    error_log("Faculty dashboard escalation check error: " . $e->getMessage());
}

// Task progress reminders check (completed, about to be delayed, delayed)
try {
    checkTaskProgressReminders($db, $user['id'], $user['role']);
} catch (\Throwable $e) {
    error_log("Faculty dashboard task reminder check error: " . $e->getMessage());
}

// Notifications
$unreadCount = 0;
$notifications = [];
try {
    $unreadStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$user['id']]);
    $unreadCount = (int)$unreadStmt->fetchColumn();

    $notifStmt = $db->prepare("
        SELECT n.*, a.title as activity_title
        FROM notifications n
        LEFT JOIN activities a ON n.activity_id = a.id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 10
    ");
    $notifStmt->execute([$user['id']]);
    $notifications = $notifStmt->fetchAll();
} catch (\Throwable $e) {
    error_log("Faculty dashboard notifications error: " . $e->getMessage());
}

$initial = strtoupper(substr($user['name'], 0, 1));

function timeAgo($datetime)
{
  $diff = time() - strtotime($datetime);
  if ($diff < 60)     return 'Just now';
  if ($diff < 3600)   return floor($diff / 60) . 'm ago';
  if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
  if ($diff < 604800) return floor($diff / 86400) . 'd ago';
  return date('M j', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Faculty Dashboard – STI Activity System</title>
  <meta name="base-url" content="<?= BASE_URL ?>">
  <meta name="current-user" data-id="<?= (int)$user['id'] ?>" data-role="<?= htmlspecialchars($user['role']) ?>" data-dept="<?= htmlspecialchars($user['dept'] ?? '') ?>">
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
        // Force expanded sidebar on desktop as per visual design target
        if (window.innerWidth >= 1024) {
          localStorage.setItem('sidebar-collapsed', 'false');
          document.documentElement.classList.remove('sidebar-collapsed');
        }
      } catch (e) {}
    })();
  </script>
  <style>
    /* ── Desktop Sidebar Expanded Enforcement ── */
    @media (min-width: 1024px) {
      .sidebar {
        width: 260px !important;
      }
      .main-wrap {
        margin-left: 260px !important;
      }
      .sidebar .brand-container,
      .sidebar .nav-label,
      .sidebar .nav-label-text,
      .sidebar .btn-label-text {
        display: block !important;
        opacity: 1 !important;
        visibility: visible !important;
      }
    }

    /* ── Compact Dashboard Layout ── */
    .content {
      padding: var(--content-padding-y, 28px) var(--content-padding-x, 32px);
      max-width: 1440px;
      margin: 0 auto;
      box-sizing: border-box;
    }

    .dashboard-layout {
      display: grid;
      grid-template-columns: 1fr;
      gap: 14px;
      width: 100%;
    }

    .dashboard-main {
      display: flex;
      flex-direction: column;
      gap: 18px;
      width: 100%;
    }

    /* ── 1. Compact Hero Header with STI Building Image ── */
    .faculty-hero-banner {
      background: #0A192F;
      border-radius: 12px;
      padding: 18px 24px;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      box-shadow: 0 4px 16px rgba(10, 22, 40, 0.08);
      position: relative;
      overflow: hidden;
      min-height: 116px;
      box-sizing: border-box;
      margin-bottom: 18px;
    }

    .faculty-hero-bg-img {
      position: absolute;
      top: 0;
      right: 0;
      width: 44%;
      height: 100%;
      background-image: url('<?= BASE_URL ?>/assets/img/sti-marikina-building.jpg');
      background-size: cover;
      background-position: center 18%;
      pointer-events: none;
      z-index: 0;
      mask-image: linear-gradient(to right, transparent 0%, rgba(0,0,0,0.8) 35%, rgba(0,0,0,1) 100%);
      -webkit-mask-image: linear-gradient(to right, transparent 0%, rgba(0,0,0,0.8) 35%, rgba(0,0,0,1) 100%);
    }

    .faculty-hero-banner::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: linear-gradient(90deg, #0A192F 0%, #0A192F 50%, rgba(10, 25, 47, 0.65) 75%, rgba(10, 25, 47, 0.2) 100%);
      z-index: 1;
      pointer-events: none;
    }

    [data-theme="dark"] .faculty-hero-banner {
      background: #070E18;
      border: 1px solid rgba(255, 255, 255, 0.08);
    }
    [data-theme="dark"] .faculty-hero-banner::before {
      background: linear-gradient(90deg, #070E18 0%, #070E18 50%, rgba(7, 14, 24, 0.7) 75%, rgba(7, 14, 24, 0.3) 100%);
    }

    .faculty-hero-text {
      position: relative;
      z-index: 2;
    }

    .faculty-hero-greeting {
      font-size: 0.66rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #F59E0B;
      margin-bottom: 4px;
    }

    .faculty-hero-title {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 1.4rem;
      font-weight: 800;
      color: #ffffff;
      margin: 0 0 3px 0;
      letter-spacing: -0.02em;
      line-height: 1.2;
    }

    .faculty-hero-subtitle {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 0.8rem;
      color: rgba(255, 255, 255, 0.85);
      margin: 0;
      font-weight: 500;
      line-height: 1.35;
    }

    .faculty-hero-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-shrink: 0;
      position: relative;
      z-index: 2;
    }

    .btn-hero-primary {
      background: #F4A900;
      color: #0A1628 !important;
      font-weight: 700;
      font-size: 0.78rem;
      padding: 8px 15px;
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none !important;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
      border: none;
      transition: all 0.18s ease;
    }

    .btn-hero-primary:hover {
      background: #FFB914;
      transform: translateY(-1px);
    }

    .btn-hero-secondary {
      background: #FFFFFF;
      color: #0A1628 !important;
      font-weight: 600;
      font-size: 0.78rem;
      padding: 8px 14px;
      border-radius: 8px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none !important;
      border: 1px solid rgba(255, 255, 255, 0.9);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
      transition: all 0.18s ease;
    }

    .btn-hero-secondary:hover {
      background: #F8FAFC;
      transform: translateY(-1px);
    }

    /* ── 2. Five Summary Metric Cards (White Cards with Thin Bottom Accent) ── */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 12px;
      align-items: stretch;
      width: 100%;
      margin-bottom: 24px;
    }

    .stat-card,
    a.stat-card,
    .stat-grid a.stat-card {
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
      cursor: pointer;
      min-height: 114px;
      box-sizing: border-box;
      overflow: hidden !important;
      transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
      outline: none !important;
    }

    .stat-card:hover,
    a.stat-card:hover,
    .stat-grid a.stat-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(10, 22, 40, 0.08) !important;
      border-color: #CBD5E1 !important;
    }

    /* Thin colored bottom accent line */
    .stat-card::after,
    a.stat-card::after,
    .stat-grid a.stat-card::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 3px;
      z-index: 2;
      pointer-events: none;
    }

    /* 1. All Time: multi-segment accent (amber, green, blue) */
    .stat-card.stat-card-total::after,
    .stat-grid a.stat-card.stat-card-total::after {
      background: linear-gradient(90deg, #F59E0B 0%, #F59E0B 33.3%, #10B981 33.3%, #10B981 66.6%, #0284C7 66.6%, #0284C7 100%) !important;
    }

    /* 2. In Review: amber accent */
    .stat-card.stat-card-pending::after,
    .stat-grid a.stat-card.stat-card-pending::after {
      background: #F59E0B !important;
    }

    /* 3. Ready: green accent */
    .stat-card.stat-card-approved::after,
    .stat-grid a.stat-card.stat-card-approved::after {
      background: #10B981 !important;
    }

    /* 4. Action Required: slate accent */
    .stat-card.stat-card-revision::after,
    .stat-grid a.stat-card.stat-card-revision::after {
      background: #64748B !important;
    }

    /* 5. Finished: blue accent */
    .stat-card.stat-card-completed::after,
    .stat-grid a.stat-card.stat-card-completed::after {
      background: #0284C7 !important;
    }

    /* 6. Drafts: slate accent */
    .stat-card.stat-card-draft::after,
    .stat-grid a.stat-card.stat-card-draft::after {
      background: #94A3B8 !important;
    }

    /* Dark Mode Card Background & Borders */
    [data-theme="dark"] .stat-card,
    [data-theme="dark"] a.stat-card,
    [data-theme="dark"] .stat-grid a.stat-card {
      background: #0F172A !important;
      border-color: rgba(255, 255, 255, 0.08) !important;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25) !important;
    }
    [data-theme="dark"] .stat-card:hover,
    [data-theme="dark"] a.stat-card:hover,
    [data-theme="dark"] .stat-grid a.stat-card:hover {
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
    .stat-card-revision .stat-icon  { background: rgba(100, 116, 139, 0.12) !important; color: #475569 !important; }
    .stat-card-completed .stat-icon { background: rgba(2, 132, 199, 0.12) !important; color: #0284C7 !important; }
    .stat-card-draft .stat-icon     { background: rgba(148, 163, 184, 0.15) !important; color: #64748B !important; }

    [data-theme="dark"] .stat-card-total .stat-icon     { background: rgba(56, 189, 248, 0.15) !important; color: #38bdf8 !important; }
    [data-theme="dark"] .stat-card-pending .stat-icon   { background: rgba(251, 191, 36, 0.18) !important; color: #fbbf24 !important; }
    [data-theme="dark"] .stat-card-approved .stat-icon  { background: rgba(52, 211, 153, 0.15) !important; color: #34d399 !important; }
    [data-theme="dark"] .stat-card-revision .stat-icon  { background: rgba(148, 163, 184, 0.15) !important; color: #94a3b8 !important; }
    [data-theme="dark"] .stat-card-completed .stat-icon { background: rgba(56, 189, 248, 0.15) !important; color: #38bdf8 !important; }
    [data-theme="dark"] .stat-card-draft .stat-icon     { background: rgba(148, 163, 184, 0.20) !important; color: #94a3b8 !important; }

    .stat-card-info {
      display: flex !important;
      flex-direction: column !important;
      width: 100% !important;
      align-items: flex-start !important;
      text-align: left !important;
    }

    .stat-card-meta {
      font-size: 0.65rem !important;
      font-weight: 700 !important;
      letter-spacing: 0.04em !important;
      text-transform: uppercase !important;
      color: #64748B !important;
      margin-bottom: 3px !important;
      line-height: 1.2 !important;
      text-align: left !important;
    }
    [data-theme="dark"] .stat-card-meta {
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
      text-align: left !important;
      margin: 0 !important;
    }
    [data-theme="dark"] .stat-val {
      color: #F8FAFC !important;
    }

    .stat-label,
    .label-default,
    .label-amber,
    .label-green,
    .label-purple,
    .stat-sub {
      font-size: 0.72rem !important;
      font-weight: 500 !important;
      line-height: 1.25 !important;
      color: #64748B !important;
      white-space: nowrap !important;
      overflow: hidden !important;
      text-overflow: ellipsis !important;
      text-align: left !important;
    }
    [data-theme="dark"] .stat-label,
    [data-theme="dark"] .label-default,
    [data-theme="dark"] .label-amber,
    [data-theme="dark"] .label-green,
    [data-theme="dark"] .label-purple,
    [data-theme="dark"] .stat-sub {
      color: #94A3B8 !important;
    }

    /* ── 3. Upcoming Activities Card (Full Width) ── */
    .upcoming-card {
      background: var(--bg-card, #FFFFFF);
      border-radius: 12px;
      border: 1px solid var(--border, #E2E8F0);
      box-shadow: 0 1px 3px rgba(10, 22, 40, 0.04);
      padding: 14px 18px;
      display: flex;
      flex-direction: column;
      box-sizing: border-box;
      min-width: 0;
      width: 100%;
    }

    [data-theme="dark"] .upcoming-card {
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
    }

    .upcoming-card-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
      gap: 10px;
      flex-wrap: wrap;
    }

    .overview-header-left {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }

    .overview-header-icon {
      width: 30px;
      height: 30px;
      border-radius: 7px;
      background: rgba(2, 132, 199, 0.1);
      color: #0284c7;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    [data-theme="dark"] .overview-header-icon {
      background: rgba(56, 189, 248, 0.15);
      color: #38bdf8;
    }

    .overview-title {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
      font-size: 0.92rem;
      font-weight: 700;
      color: var(--text-main, #0A1628);
      margin: 0;
      line-height: 1.2;
    }

    .overview-subtitle {
      font-size: 0.72rem;
      color: var(--text-muted, #64748B);
      margin: 2px 0 0 0;
    }

    .btn-compact-outline {
      padding: 4px 11px;
      font-size: 0.72rem;
      font-weight: 600;
      color: #0284C7;
      background: transparent;
      border: 1px solid var(--border, #E2E8F0);
      border-radius: 6px;
      text-decoration: none !important;
      transition: all 0.16s ease;
      white-space: nowrap;
    }

    .btn-compact-outline:hover {
      border-color: #0284C7;
      background: rgba(2, 132, 199, 0.06);
    }

    /* Upcoming Activities List */
    .upcoming-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
      min-width: 0;
      width: 100%;
    }

    .upcoming-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 10px;
      border-radius: 8px;
      background: var(--border-light, #F8FAFD);
      border: 1px solid var(--border, #E2E8F0);
      gap: 10px;
      min-width: 0;
      width: 100%;
      box-sizing: border-box;
    }

    [data-theme="dark"] .upcoming-row {
      background: rgba(255, 255, 255, 0.03);
    }

    .upcoming-row-left {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
      flex: 1;
    }

    .upcoming-row-icon {
      width: 30px;
      height: 30px;
      border-radius: 6px;
      background: var(--bg-card, #FFFFFF);
      border: 1px solid var(--border, #E2E8F0);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-muted, #64748B);
      flex-shrink: 0;
    }

    .upcoming-row-info {
      min-width: 0;
      flex: 1;
    }

    .upcoming-row-title {
      font-size: 0.8rem;
      font-weight: 600;
      color: var(--text-main, #0A1628);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.25;
    }

    .upcoming-row-meta {
      font-size: 0.7rem;
      color: var(--text-muted, #64748B);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.3;
      margin-top: 1px;
    }

    .upcoming-row-status {
      flex-shrink: 0;
    }

    /* ── 4. Recent Activities Card & Table ── */
    .activity-card {
      background: var(--bg-card, #FFFFFF);
      border-radius: 12px;
      border: 1px solid var(--border, #E2E8F0);
      box-shadow: 0 1px 3px rgba(10, 22, 40, 0.04);
      overflow: hidden;
    }

    [data-theme="dark"] .activity-card {
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.25);
    }

    .activity-card-header {
      padding: 13px 18px;
      border-bottom: 1px solid var(--border, #E2E8F0);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      background: var(--bg-card, #FFFFFF);
    }

    .table-wrap {
      border: none;
      border-radius: 0;
      background: transparent;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
      width: 100%;
    }

    .activity-table {
      width: 100%;
      min-width: 760px;
      border-collapse: collapse;
      table-layout: fixed;
      box-sizing: border-box;
    }

    .activity-table th {
      padding: 9px 14px;
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-muted, #64748B);
      border-bottom: 1px solid var(--border, #E2E8F0);
      background: var(--border-light, #F8FAFD);
      text-align: left;
      white-space: nowrap;
    }

    [data-theme="dark"] .activity-table th {
      background: rgba(255, 255, 255, 0.025);
    }

    .activity-table td {
      padding: 10px 14px;
      border-bottom: 1px solid var(--border, #E2E8F0);
      vertical-align: middle;
      color: var(--text-main, #0A1628);
      font-size: 0.82rem;
      transition: background 0.12s ease;
    }

    .activity-table tbody tr:last-child td {
      border-bottom: none;
    }

    .activity-table tbody tr:hover td {
      background: rgba(2, 132, 199, 0.03);
    }

    /* Column widths */
    .col-title { width: 30%; min-width: 170px; }
    .col-date { width: 13%; min-width: 105px; }
    .col-venue { width: 23%; min-width: 145px; }
    .col-source { width: 10%; min-width: 80px; }
    .col-status { width: 11%; min-width: 95px; }
    .col-actions { width: 13%; min-width: 125px; text-align: right; }

    .activity-table th.col-actions,
    .activity-table td.col-actions {
      text-align: right;
      padding-right: 18px;
    }

    .act-title {
      font-size: 0.84rem;
      font-weight: 600;
      color: var(--text-main, #0A1628);
      line-height: 1.3;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      word-break: break-word;
    }

    .act-date {
      color: var(--text-secondary, #334155);
      font-size: 0.8rem;
      font-weight: 500;
      line-height: 1.25;
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }

    .act-venue {
      color: var(--text-muted, #64748B);
      font-size: 0.8rem;
      line-height: 1.35;
      display: flex;
      align-items: flex-start;
      gap: 6px;
      white-space: normal;
      word-break: break-word;
    }

    .act-venue svg {
      flex-shrink: 0;
      color: var(--text-muted, #64748B);
      opacity: 0.7;
      margin-top: 2px;
    }

    .act-actions {
      display: inline-flex;
      align-items: center;
      justify-content: flex-end;
      gap: 5px;
      flex-wrap: nowrap;
    }

    .activity-table td .btn {
      padding: 4px 10px;
      height: 27px;
      font-size: 0.72rem;
      font-weight: 600;
      border-radius: 6px;
      gap: 4px;
      box-sizing: border-box;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      transition: all 0.16s ease;
    }

    .activity-table td .btn:hover {
      transform: translateY(-1px);
    }

    .activity-table td .btn-outline {
      border: 1px solid var(--border, #E2E8F0);
      color: var(--text-main, #0A1628);
      background: var(--bg-card, #FFFFFF);
    }

    .activity-table td .btn-outline:hover {
      border-color: #0284C7;
      color: #0284C7;
      background: rgba(2, 132, 199, 0.06);
    }

    .activity-table td .btn-success {
      background: #16A34A;
      border: 1px solid #16A34A;
      color: #fff;
    }

    .activity-table td .btn-success:hover {
      background: #15803d;
    }

    .activity-table .badge {
      padding: 3px 8px;
      font-size: 0.7rem;
      font-weight: 700;
      border-radius: 999px;
      white-space: nowrap;
      line-height: 1.25;
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }

    .activity-table .badge-secondary {
      background: rgba(100, 116, 139, 0.1);
      color: #475569;
      border: 1px solid rgba(100, 116, 139, 0.15);
    }

    /* ── Responsive Rules ── */
    @media (max-width: 1024px) {
      .stat-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        gap: 12px;
      }
    }

    @media (max-width: 900px) {
      .content {
        padding: 14px 14px 24px;
      }
      .faculty-hero-banner {
        flex-direction: column;
        align-items: flex-start;
        padding: 16px;
        gap: 12px;
      }
      .faculty-hero-bg-img {
        opacity: 0.25;
        width: 100%;
      }
      .faculty-hero-actions {
        width: 100%;
      }
      .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 10px;
      }
    }

    @media (max-width: 640px) {
      .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 10px;
      }
      .faculty-hero-actions {
        flex-direction: column;
      }
      .btn-hero-primary,
      .btn-hero-secondary {
        width: 100%;
        justify-content: center;
      }
      .activity-table td .btn {
        height: 38px;
        min-height: 38px;
        padding: 6px 14px;
        font-size: 0.78rem;
      }
      .act-actions {
        gap: 8px;
      }
    }

    @media (max-width: 480px) {
      .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 8px;
      }
      .stat-card,
      a.stat-card,
      .stat-grid a.stat-card {
        padding: 12px 14px !important;
      }
      .upcoming-card {
        padding: 12px 14px;
      }
      .upcoming-row {
        padding: 8px 10px;
        gap: 8px;
      }
    }

    @media (max-width: 359.98px) {
      .stat-grid {
        grid-template-columns: 1fr !important;
      }
    }

    /* ── Typography Consistency ── */
    body.theme-faculty,
    body.theme-faculty *,
    body.theme-faculty *::before,
    body.theme-faculty *::after,
    body,
    h1, h2, h3, h4, h5, h6,
    .page-title,
    .topbar .page-title,
    .brand,
    .stat-val,
    .stat-label,
    th, td,
    .act-title,
    .act-date,
    .act-venue,
    .badge,
    .btn {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }
  </style>
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">

    <!-- Topbar -->
    <header class="topbar">
      <div class="page-title">Faculty Dashboard</div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">

      <!-- Hero Header Section with STI College Building Image (Shared Role Banner) -->
      <?php include __DIR__ . '/../includes/dashboard-welcome-banner.php'; ?>

      <div class="dashboard-layout">
        <div class="dashboard-main">

          <!-- 1. Five Summary Metric Cards -->
          <div class="stat-grid">
            <!-- All Time -->
            <a href="<?= BASE_URL ?>/faculty/activities.php" class="stat-card stat-card-total">
              <div class="stat-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
              </div>
              <div class="stat-card-info">
                <div class="stat-card-meta">ALL TIME</div>
                <div class="stat-card-row">
                  <div class="stat-val"><?= $s['total'] ?></div>
                </div>
                <div class="stat-label label-default">My Submitted Proposals</div>
              </div>
            </a>

            <!-- Draft Proposals -->
            <a href="<?= BASE_URL ?>/faculty/activities.php?status=draft" class="stat-card stat-card-draft">
              <div class="stat-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </div>
              <div class="stat-card-info">
                <div class="stat-card-meta">UNSUBMITTED</div>
                <div class="stat-card-row">
                  <div class="stat-val"><?= (int)($s['drafts'] ?? 0) ?></div>
                </div>
                <div class="stat-label label-default">Draft Proposals</div>
              </div>
            </a>

            <!-- In Review -->
            <a href="<?= BASE_URL ?>/faculty/pending.php" class="stat-card stat-card-pending">
              <div class="stat-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div class="stat-card-info">
                <div class="stat-card-meta">IN REVIEW</div>
                <div class="stat-card-row">
                  <div class="stat-val"><?= $s['pending'] ?></div>
                </div>
                <div class="stat-label label-amber">Pending Review</div>
              </div>
            </a>

            <!-- Ready / Approved -->
            <a href="<?= BASE_URL ?>/faculty/approved.php" class="stat-card stat-card-approved">
              <div class="stat-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div class="stat-card-info">
                <div class="stat-card-meta">READY</div>
                <div class="stat-card-row">
                  <div class="stat-val"><?= $s['approved'] ?></div>
                </div>
                <div class="stat-label label-green">Approved</div>
              </div>
            </a>

            <!-- Action Required -->
            <a href="<?= BASE_URL ?>/faculty/returned.php" class="stat-card stat-card-revision">
              <div class="stat-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </div>
              <div class="stat-card-info">
                <div class="stat-card-meta">ACTION REQUIRED</div>
                <div class="stat-card-row">
                  <div class="stat-val"><?= $s['revisions'] ?></div>
                </div>
                <div class="stat-label label-default">Revision Needed</div>
              </div>
            </a>

            <!-- Finished / Completed -->
            <a href="<?= BASE_URL ?>/faculty/activities.php?status=completed" class="stat-card stat-card-completed">
              <div class="stat-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                </svg>
              </div>
              <div class="stat-card-info">
                <div class="stat-card-meta">FINISHED</div>
                <div class="stat-card-row">
                  <div class="stat-val"><?= $s['completed'] ?></div>
                </div>
                <div class="stat-label label-purple">Completed</div>
              </div>
            </a>
          </div>

          <!-- 2. Upcoming Activities Card (Full Width) -->
          <div class="upcoming-card">
            <div class="upcoming-card-header">
              <div class="overview-header-left">
                <div class="overview-header-icon">
                  <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                  </svg>
                </div>
                <div>
                  <h2 class="overview-title">Upcoming Activities</h2>
                  <p class="overview-subtitle">Your nearest upcoming activities</p>
                </div>
              </div>
              <a href="<?= BASE_URL ?>/faculty/calendar.php" class="btn-compact-outline">View Calendar</a>
            </div>
            <div class="upcoming-list">
              <?php if (empty($upcomingList)): ?>
                <div style="text-align:center;padding:22px;color:var(--text-muted);font-size:0.82rem;">
                  No upcoming activities scheduled.
                </div>
              <?php else: foreach ($upcomingList as $uAct): ?>
                <div class="upcoming-row">
                  <div class="upcoming-row-left">
                    <div class="upcoming-row-icon">
                      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="15" height="15">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                      </svg>
                    </div>
                    <div class="upcoming-row-info">
                      <div class="upcoming-row-title"><?= htmlspecialchars($uAct['title']) ?></div>
                      <div class="upcoming-row-meta">
                        <?= $uAct['event_date'] ? date('M j, Y', strtotime($uAct['event_date'])) : 'TBD' ?>
                        <?php if (!empty($uAct['venue'])): ?>
                          • <?= htmlspecialchars($uAct['venue']) ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div class="upcoming-row-status">
                    <?= getStatusBadge($uAct['status']) ?>
                  </div>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>

          <!-- Local Offline Drafts Container -->
          <div id="localDraftsContainer" style="display:none;"></div>

          <!-- 3. Recent Activities Card -->
          <div class="activity-card">
            <div class="activity-card-header">
              <div class="overview-header-left">
                <div class="overview-header-icon">
                  <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="16" height="16">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                  </svg>
                </div>
                <div>
                  <h2 class="overview-title">Recent Activities</h2>
                  <p class="overview-subtitle">Track proposal progress, reviews, and post-event requirements</p>
                </div>
              </div>
              <div>
                <a href="<?= BASE_URL ?>/faculty/activities.php" class="btn-compact-outline">View All</a>
              </div>
            </div>
            <div class="card-body" style="padding:0;">
              <div class="table-wrap">
                <table class="activity-table">
                  <thead>
                    <tr>
                      <th class="col-title">Activity Title</th>
                      <th class="col-date">Date</th>
                      <th class="col-venue">Venue</th>
                      <th class="col-source">Source</th>
                      <th class="col-status">Status</th>
                      <th class="col-actions">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($activities)): ?>
                      <tr>
                        <td colspan="6" style="text-align:center;padding:36px 20px;color:var(--text-muted);">
                          <div style="font-size:1.8rem;margin-bottom:6px;opacity:0.5;">📋</div>
                          <div style="font-weight:600;font-size:0.95rem;color:var(--text-main);margin-bottom:2px;">No activities found</div>
                          <p style="margin:0 0 10px;font-size:0.8rem;">You haven't submitted any activity proposals yet.</p>
                          <a href="<?= BASE_URL ?>/faculty/proposal-create.php" class="btn btn-primary btn-sm">Create your first proposal →</a>
                        </td>
                      </tr>
                      <?php else: foreach ($activities as $act): ?>
                        <tr>
                          <td class="col-title"><div class="act-title"><?= htmlspecialchars($act['title']) ?></div></td>
                          <td class="col-date">
                            <span class="act-date">
                              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="13" height="13" style="opacity:0.6;flex-shrink:0;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                              </svg>
                              <?= $act['event_date'] ? date('M j, Y', strtotime($act['event_date'])) : '—' ?>
                            </span>
                          </td>
                          <td class="col-venue">
                            <span class="act-venue" title="<?= htmlspecialchars($act['venue'] ?? '') ?>">
                              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="13" height="13">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                              </svg>
                              <?= htmlspecialchars($act['venue'] ?? '—') ?>
                            </span>
                          </td>
                          <td class="col-source"><span class="badge badge-secondary"><?= ucfirst(str_replace('_', ' ', $act['source'])) ?></span></td>
                          <td class="col-status"><?= getStatusBadge($act['status']) ?></td>
                          <td class="col-actions">
                            <div class="act-actions">
                              <a href="<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $act['id'] ?>" class="btn btn-outline btn-sm">View</a>
                              <?php if (in_array($act['status'], ['draft', 'returned_for_revision'])): ?>
                                <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= $act['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                              <?php endif; ?>
                              <?php if ($act['status'] === 'draft'): ?>
                                <button type="button" class="btn btn-danger btn-sm" onclick="confirmDeleteDraft(<?= (int)$act['id'] ?>, '<?= htmlspecialchars(addslashes($act['title']), ENT_QUOTES) ?>')">Delete</button>
                              <?php endif; ?>
                              <?php if ($act['status'] === 'approved'): ?>
                                <a href="<?= BASE_URL ?>/faculty/post-event.php?activity_id=<?= $act['id'] ?>" class="btn btn-success btn-sm">Post-Event</a>
                              <?php endif; ?>
                            </div>
                          </td>
                        </tr>
                    <?php endforeach;
                    endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div><!-- dashboard-main -->
      </div><!-- dashboard-layout -->

    </div><!-- content -->
  </div><!-- main-wrap -->

  <script>
    // Ensure sidebar remains expanded on desktop
    if (window.innerWidth >= 1024) {
      document.documentElement.classList.remove('sidebar-collapsed');
      if (document.body) document.body.classList.remove('sidebar-collapsed');
    }
  </script>
  <script src="<?= BASE_URL ?>/assets/js/offline-drafts.js?v=<?= time() ?>"></script>
</body>

</html>