<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$user = currentUser();
$db   = getDB();

$stats = $db->prepare("
    SELECT
        COUNT(*) as total,
        SUM(status='draft') as drafts,
        SUM(status='submitted' OR status='under_review' OR status='endorsed' OR status='pending_final_approval' OR status='resubmitted') as pending,
        SUM(status='approved') as approved,
        SUM(status='returned_for_revision') as revisions,
        SUM(status='completed') as completed
    FROM activities WHERE faculty_id = ?
");
$stats->execute([$user['id']]);
$s = $stats->fetch();

$recent = $db->prepare("
    SELECT a.*, u.name as faculty_name FROM activities a
    JOIN users u ON a.faculty_id = u.id
    WHERE a.faculty_id = ?
    ORDER BY a.updated_at DESC LIMIT 8
");
$recent->execute([$user['id']]);
$activities = $recent->fetchAll();

// Stagnant proposals check (3 days without update in pending review status)
$stagnantQuery = $db->prepare("
    SELECT id, title, updated_at 
    FROM activities 
    WHERE faculty_id = ? 
      AND status IN ('submitted', 'under_review', 'endorsed', 'pending_final_approval', 'resubmitted') 
      AND updated_at < NOW() - INTERVAL 3 DAY
");
$stagnantQuery->execute([$user['id']]);
$stagnantActs = $stagnantQuery->fetchAll();

foreach ($stagnantActs as $act) {
    // Check if already notified for this activity's current update state
    $checkNotif = $db->prepare("
        SELECT COUNT(*) 
        FROM notifications 
        WHERE activity_id = ? 
          AND message LIKE '%stagnant%' 
          AND created_at > ?
    ");
    $checkNotif->execute([$act['id'], $act['updated_at']]);
    if ((int)$checkNotif->fetchColumn() === 0) {
        $msg = "Your proposal '" . sanitize($act['title']) . "' has been stagnant under review for 3 days.";
        $ins = $db->prepare("INSERT INTO notifications (user_id, activity_id, message) VALUES (?, ?, ?)");
        $ins->execute([$user['id'], $act['id'], $msg]);
    }
}

// Notifications
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
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
                        /* ── Bell ── */
    .notif-bell-btn {
      background: none;
      border: none;
      cursor: pointer;
      padding: 7px;
      border-radius: 9px;
      color: var(--text-muted);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background .15s, color .15s;
      position: relative;
    }

    .notif-bell-btn:hover {
      background: var(--bg-base);
      color: var(--text);
    }

    .notif-bell-btn svg {
      width: 20px;
      height: 20px;
    }

    .notif-badge {
      position: absolute;
      top: 2px;
      right: 2px;
      background: #EF4444;
      color: #fff;
      font-size: .58rem;
      font-weight: 800;
      min-width: 16px;
      height: 16px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 3px;
      pointer-events: none;
      line-height: 1;
    }

    /* ── Backdrop ── */
    .notif-panel-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 900;
      background: rgba(0, 0, 0, .3);
    }

    .notif-panel-backdrop.open {
      display: block;
    }

    /* ── Slide Panel ── */
    .notif-dropdown {
      display: none;
      position: fixed;
      top: 0;
      right: 0;
      width: 380px;
      height: 100vh;
      background: #fff;
      box-shadow: -4px 0 30px rgba(0, 0, 0, .15);
      z-index: 901;
      flex-direction: column;
    }

    .notif-dropdown.open {
      display: flex;
      animation: slideInPanel .22s ease;
    }

    @keyframes slideInPanel {
      from {
        transform: translateX(100%);
        opacity: 0;
      }

      to {
        transform: translateX(0);
        opacity: 1;
      }
    }

    .notif-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 18px 20px 14px;
      border-bottom: 1px solid #F0F0F0;
      flex-shrink: 0;
    }

    .notif-header h3 {
      font-size: .95rem;
      font-weight: 800;
      color: #111;
      margin: 0;
    }

    .notif-header-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .notif-mark-all {
      font-size: .72rem;
      color: #3B82F6;
      background: none;
      border: none;
      cursor: pointer;
      font-weight: 600;
      padding: 4px 8px;
      border-radius: 6px;
      transition: background .15s;
    }

    .notif-mark-all:hover {
      background: #EFF6FF;
    }

    .notif-close-btn {
      background: none;
      border: none;
      cursor: pointer;
      color: #9CA3AF;
      padding: 4px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      transition: background .15s, color .15s;
    }

    .notif-close-btn:hover {
      background: #F3F4F6;
      color: #111;
    }

    .notif-close-btn svg {
      width: 18px;
      height: 18px;
    }

    .notif-list {
      flex: 1;
      overflow-y: auto;
      padding: 6px 0;
    }

    .notif-empty {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100%;
      color: #9CA3AF;
      gap: 10px;
      padding: 40px;
      text-align: center;
    }

    .notif-empty svg {
      width: 40px;
      height: 40px;
      opacity: .4;
    }

    .notif-empty p {
      font-size: .85rem;
      margin: 0;
    }

    .notif-item {
      display: flex;
      gap: 12px;
      padding: 13px 20px;
      cursor: pointer;
      border-left: 3px solid transparent;
      transition: background .12s, border-color .12s;
    }

    .notif-item:hover {
      background: #F9FAFB;
    }

    .notif-item.unread {
      background: #EFF6FF;
      border-left-color: #3B82F6;
    }

    .notif-item.unread:hover {
      background: #DBEAFE;
    }

    .notif-icon {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      font-size: .85rem;
    }

    .notif-icon.type-submitted {
      background: #DBEAFE;
    }

    .notif-icon.type-approved {
      background: #DCFCE7;
    }

    .notif-icon.type-forwarded {
      background: #E0E7FF;
    }

    .notif-icon.type-returned {
      background: #FEF3C7;
    }

    .notif-icon.type-rejected {
      background: #FEE2E2;
    }

    .notif-icon.type-completed {
      background: #F3F4F6;
    }

    .notif-icon.type-default {
      background: #F3F4F6;
    }

    .notif-body {
      flex: 1;
      min-width: 0;
    }

    .notif-msg {
      font-size: .81rem;
      color: #111;
      line-height: 1.45;
      margin: 0 0 3px;
      word-break: break-word;
    }

    .notif-item.unread .notif-msg {
      font-weight: 600;
    }

    .notif-time {
      font-size: .7rem;
      color: #6B7280;
    }

    .notif-unread-dot {
      width: 8px;
      height: 8px;
      background: #3B82F6;
      border-radius: 50%;
      margin-top: 6px;
    }
  
    .notif-modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 902;
      background: rgba(0, 0, 0, .4);
      align-items: center;
      justify-content: center;
      padding: 20px;
    }

    .notif-modal-overlay.open {
      display: flex;
    }

    .notif-modal {
      background: #fff;
      width: 100%;
      max-width: 440px;
      border-radius: 16px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, .2);
      transform: scale(.95);
      opacity: 0;
      transition: all .2s cubic-bezier(.16, 1, .3, 1);
      overflow: hidden;
    }

    .notif-modal-overlay.open .notif-modal {
      transform: scale(1);
      opacity: 1;
    }

    .notif-modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 20px;
      border-bottom: 1px solid #F3F4F6;
      background: #F9FAFB;
    }

    .notif-modal-header h3 {
      font-size: 1rem;
      font-weight: 700;
      color: #111;
      margin: 0;
    }

    .notif-modal-close {
      background: #E5E7EB;
      border: none;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      color: #4B5563;
      transition: .15s;
    }

    .notif-modal-close:hover {
      background: #D1D5DB;
      color: #111;
    }

    .notif-modal-close svg {
      width: 16px;
      height: 16px;
    }

    .notif-modal-from {
      background: #fff;
      padding: 16px 20px;
      border-bottom: 1px solid #F3F4F6;
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .from-row {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .from-label {
      font-size: .8rem;
      color: #6B7280;
      width: 60px;
    }

    .from-avatar {
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: #3B82F6;
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: .75rem;
      font-weight: 700;
    }

    .from-name {
      font-size: .9rem;
      font-weight: 600;
      color: #111;
    }

    .from-time {
      font-size: .75rem;
      color: #9CA3AF;
      margin-left: 70px;
      margin-top: -6px;
    }

    .subject-row {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      margin-top: 4px;
    }

    .subject-val {
      font-size: .9rem;
      font-weight: 600;
      color: #111;
      line-height: 1.4;
      flex: 1;
    }

    .notif-modal-body {
      padding: 24px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    .notif-modal-icon-wrap {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      margin: 0 auto;
    }

    .notif-modal-message {
      font-size: .95rem;
      color: #374151;
      line-height: 1.6;
      margin: 0;
      text-align: center;
    }

    .notif-modal-actions {
      display: flex;
      gap: 10px;
      margin-top: 10px;
      justify-content: center;
    }

    .notif-modal-btn {
      padding: 10px 16px;
      border-radius: 8px;
      font-size: .85rem;
      font-weight: 600;
      border: none;
      cursor: pointer;
      transition: .15s;
      flex: 1;
      max-width: 140px;
    }

    .notif-modal-btn.primary {
      background: #3B82F6;
      color: #fff;
    }

    .notif-modal-btn.danger {
      background: #EF4444;
      color: #fff;
    }

    .notif-modal-btn.ghost {
      background: #F3F4F6;
      color: #6B7280;
    }
  </style>
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">

    <!-- Topbar -->
    <header class="topbar">
      <div class="page-title">Faculty Dashboard</div>
      <div class="topbar-right">

            <!-- Notification Wrapper Context -->
    <!-- Notification Bell -->
        <button class="notif-bell-btn" id="notifBellBtn" title="Notifications">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <?php if ($unreadCount > 0): ?>
                <span class="notif-badge" id="notifBadge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
            <?php endif; ?>
        </button>
        <span class="text-sm text-muted">Welcome, <strong><?= htmlspecialchars($user['name']) ?></strong></span>
    </header>
    <!-- Backdrop -->
    <div class="notif-panel-backdrop" id="notifBackdrop"></div>

    <!-- Slide Panel -->
    <div class="notif-dropdown" id="notifDropdown">
      <div class="notif-header">
        <h3>🔔 Notifications
          <?php if ($unreadCount > 0): ?>
            <span style="font-size:.73rem;color:#3B82F6;font-weight:600;margin-left:4px;">(<?= $unreadCount ?> new)</span>
          <?php endif; ?>
        </h3>
        <div class="notif-header-actions">
          <?php if ($unreadCount > 0): ?>
            <button class="notif-mark-all" id="markAllBtn">Mark all as read</button>
          <?php endif; ?>
        <?php if (!empty($notifications)): ?>
          <button class="notif-mark-all" id="deleteAllBtn" style="color: #EF4444; margin-left: 8px;">Delete all</button>
        <?php endif; ?>
          <button class="notif-close-btn" id="notifCloseBtn">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
      <div class="notif-list" id="notifList">
        <?php if (empty($notifications)): ?>
          <div class="notif-empty">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <p>You're all caught up!<br><span style="font-size:.75rem;">No notifications yet.</span></p>
          </div>
          <?php else: foreach ($notifications as $n):
            $msg = strtolower($n['message']);
            if (str_contains($msg, 'submitted')) {
              $type = 'submitted';
              $emoji = '📋';
            } elseif (str_contains($msg, 'approved') || str_contains($msg, 'congratulations')) {
              $type = 'approved';
              $emoji = '✅';
            } elseif (str_contains($msg, 'forwarded')) {
              $type = 'forwarded';
              $emoji = '📤';
            } elseif (str_contains($msg, 'revision') || str_contains($msg, 'returned')) {
              $type = 'returned';
              $emoji = '↩️';
            } elseif (str_contains($msg, 'rejected')) {
              $type = 'rejected';
              $emoji = '❌';
            } elseif (str_contains($msg, 'completed')) {
              $type = 'completed';
              $emoji = '🎉';
            } else {
              $type = 'default';
              $emoji = '🔔';
            }
          ?>
            <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>"
              data-id="<?= $n['id'] ?>"
              data-message="<?= htmlspecialchars($n['message']) ?>"
              data-time="<?= htmlspecialchars($n['created_at']) ?>"
              data-activity-id="<?= $n['activity_id'] ?>"
              data-activity-title="<?= htmlspecialchars($n['activity_title'] ?? '') ?>"
              data-emoji="<?= $emoji ?>">
              <div class="notif-icon type-<?= $type ?>"><?= $emoji ?></div>
              <div class="notif-body">
                <p class="notif-msg"><?= htmlspecialchars($n['message']) ?></p>
                <div class="notif-time"><?= timeAgo($n['created_at']) ?></div>
              </div>
              <?php if (!$n['is_read']): ?><div class="notif-unread-dot"></div><?php endif; ?>
            </div>
        <?php endforeach;
        endif; ?>
      </div>
    </div><!-- Detail Modal -->
    <div class="notif-modal-overlay" id="notifModal">
      <div class="notif-modal">
        <div class="notif-modal-header">
          <h3>Notification Detail</h3>
          <button class="notif-modal-close" id="modalClose">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div class="notif-modal-from">
          <div class="from-row">
            <span class="from-label">From:</span>
            <div class="from-avatar"><?= $initial ?></div>
            <span class="from-name">WARMES System</span>
          </div>
          <div class="from-time" id="modalTime"></div>
          <div class="subject-row">
            <span class="from-label">Subject:</span>
            <span class="subject-val" id="modalSubject"></span>
          </div>
        </div>
        <div class="notif-modal-body">
          <div class="notif-modal-icon-wrap" id="modalIconWrap"></div>
          <p class="notif-modal-message" id="modalMessage"></p>
          <div class="notif-modal-actions">
            <button class="notif-modal-btn primary" id="modalViewBtn" style="display:none">View Activity</button>
            <button class="notif-modal-btn danger" id="modalDeleteBtn">Delete</button>
            <button class="notif-modal-btn ghost" id="modalDismissBtn">Dismiss</button>
          </div>
        </div>
      </div>
    </div>

    <div class="content">

      <!-- Stats -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
            </svg></div>
          <div>
            <div class="stat-val"><?= $s['total'] ?></div>
            <div class="stat-label">Total Activities</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg></div>
          <div>
            <div class="stat-val"><?= $s['pending'] ?></div>
            <div class="stat-label">Pending Review</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg></div>
          <div>
            <div class="stat-val"><?= $s['approved'] ?></div>
            <div class="stat-label">Approved</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg></div>
          <div>
            <div class="stat-val"><?= $s['revisions'] ?></div>
            <div class="stat-label">For Revision</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon"><svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
            </svg></div>
          <div>
            <div class="stat-val"><?= $s['completed'] ?></div>
            <div class="stat-label">Completed</div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="flex gap-3 mt-4" style="margin-bottom:24px;">
        <a href="<?= BASE_URL ?>/faculty/proposal-create.php" class="btn btn-primary">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:15px;height:15px">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          New Activity Proposal
        </a>
        <a href="<?= BASE_URL ?>/faculty/activities.php" class="btn btn-outline">View All Activities</a>
      </div>

      <!-- Recent Activities -->
      <div class="card">
        <div class="card-header">
          <h2>Recent Activities</h2>
          <a href="<?= BASE_URL ?>/faculty/activities.php" class="btn btn-outline btn-sm">View All</a>
        </div>
        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Activity Title</th>
                  <th>Date</th>
                  <th>Venue</th>
                  <th>Source</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activities)): ?>
                  <tr>
                    <td colspan="6" style="text-align:center;padding:32px;color:var(--text-muted);">
                      No activities yet. <a href="<?= BASE_URL ?>/faculty/proposal-create.php">Create your first proposal →</a>
                    </td>
                  </tr>
                  <?php else: foreach ($activities as $act): ?>
                    <tr>
                      <td><strong><?= htmlspecialchars($act['title']) ?></strong></td>
                      <td><?= $act['event_date'] ? date('M j, Y', strtotime($act['event_date'])) : '—' ?></td>
                      <td><?= htmlspecialchars($act['venue'] ?? '—') ?></td>
                      <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_', ' ', $act['source'])) ?></span></td>
                      <td><?= getStatusBadge($act['status']) ?></td>
                      <td>
                        <a href="<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $act['id'] ?>" class="btn btn-outline btn-sm">View</a>
                        <?php if (in_array($act['status'], ['draft', 'returned_for_revision'])): ?>
                          <a href="<?= BASE_URL ?>/faculty/proposal-edit.php?id=<?= $act['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                        <?php endif; ?>
                      </td>
                    </tr>
                <?php endforeach;
                endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div><!-- content -->
  </div><!-- main-wrap -->

  <script>
    (function() {
      const BASE_URL = '<?= BASE_URL ?>';

      const bell = document.getElementById('notifBellBtn');
      const panel = document.getElementById('notifDropdown');
      const backdrop = document.getElementById('notifBackdrop');
      const closeBtn = document.getElementById('notifCloseBtn');
      const markAllBtn = document.getElementById('markAllBtn');
      const deleteAllBtn = document.getElementById('deleteAllBtn');
      const modal = document.getElementById('notifModal');
      const modalClose = document.getElementById('modalClose');
      const modalDismiss = document.getElementById('modalDismissBtn');

      let activeId = null;

      function openPanel() {
        panel.classList.add('open');
        backdrop.classList.add('open');
      }

      function closePanel() {
        panel.classList.remove('open');
        backdrop.classList.remove('open');
      }

      function openModal() {
        modal.classList.add('open');
      }

      function closeModal() {
        modal.classList.remove('open');
        activeId = null;
      }

      bell.addEventListener('click', () => panel.classList.contains('open') ? closePanel() : openPanel());
      closeBtn.addEventListener('click', closePanel);
      backdrop.addEventListener('click', closePanel);
      modalClose.addEventListener('click', closeModal);
      modalDismiss.addEventListener('click', closeModal);
      modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
      });

      // Mark all as read
      if (markAllBtn) {
        markAllBtn.addEventListener('click', () => {
          fetch(BASE_URL + '/api/notifications.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              action: 'mark_all_read'
            })
          }).then(r => r.json()).then(d => {
            if (!d.success) return;
            document.querySelectorAll('.notif-item.unread').forEach(el => {
              el.classList.remove('unread');
              el.querySelector('.notif-unread-dot')?.remove();
            });
            document.getElementById('notifBadge')?.remove();
            markAllBtn.style.display = 'none';
            panel.querySelector('.notif-header h3 span')?.remove();
          });
        });
      }

      if (deleteAllBtn) {
        deleteAllBtn.addEventListener('click', () => {
          fetch(BASE_URL + '/api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_all' })
          }).then(r => r.json()).then(d => {
            if (!d.success) return;
            const list = document.getElementById('notifList');
            if (list) {
                list.innerHTML = `<div class="notif-empty">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
          <p>You're all caught up!<br><span style="font-size:.75rem;">No notifications yet.</span></p>
        </div>`;
            }
            document.getElementById('notifBadge')?.remove();
            if (markAllBtn) markAllBtn.style.display = 'none';
            deleteAllBtn.style.display = 'none';
            const hspan = document.getElementById('notifDropdown').querySelector('.notif-header h3 span');
            if(hspan) hspan.remove();
          });
        });
      }

      // Click item → mark read + open modal
      document.querySelectorAll('.notif-item').forEach(item => {
        item.addEventListener('click', function() {
          activeId = this.dataset.id;
          const actId = this.dataset.activityId;
          const actTitle = this.dataset.activityTitle;

          document.getElementById('modalSubject').textContent = actTitle || 'Activity Update';
          document.getElementById('modalTime').textContent = '@ ' + formatDate(this.dataset.time);
          document.getElementById('modalIconWrap').textContent = this.dataset.emoji;
          document.getElementById('modalMessage').textContent = this.dataset.message;

          const viewBtn = document.getElementById('modalViewBtn');
          if (actId && actId !== '0') {
            viewBtn.style.display = 'inline-block';
            viewBtn.onclick = () => {
              window.location.href = BASE_URL + '/faculty/view-activity.php?id=' + actId;
            };
          } else {
            viewBtn.style.display = 'none';
          }

          // Mark as read — update UI immediately (optimistic)
          if (this.classList.contains('unread')) {
            item.classList.remove('unread');
            item.querySelector('.notif-unread-dot')?.remove();
            const badge = document.getElementById('notifBadge');
            if (badge) {
              const n = parseInt(badge.textContent) - 1;
              n <= 0 ? badge.remove() : (badge.textContent = n);
            }
            // Sync to server in background
            fetch(BASE_URL + '/api/notifications.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json'
              },
              body: JSON.stringify({
                action: 'mark_read',
                id: activeId
              })
            });
          }

          closePanel();
          openModal();
        });
      });

      // Delete
      document.getElementById('modalDeleteBtn').addEventListener('click', () => {
        if (!activeId) return;
        fetch(BASE_URL + '/api/notifications.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            action: 'delete',
            id: activeId
          })
        }).then(r => r.json()).then(d => {
          if (!d.success) console.error('Delete action returned false');
          document.querySelector('.notif-item[data-id="' + activeId + '"]')?.remove();
          const list = document.getElementById('notifList');
          if (!list.querySelector('.notif-item')) {
            list.innerHTML = `<div class="notif-empty">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
          </svg>
          <p>You're all caught up!<br><span style="font-size:.75rem;">No notifications yet.</span></p>
        </div>`;
          }
          closeModal();
        });
      });

      function formatDate(str) {
        const d = new Date(str);
        return d.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric'
          }) + ', ' +
          d.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit'
          });
      }
    })();
  </script>
</body>

</html>