<?php
/**
 * notifications.php
 *
 * Dedicated Notifications Center for STI-ARMES.
 * Displays all notifications for the authenticated user, allowing comprehensive
 * filtering and organization by date, time, read status, and keywords.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$user = currentUser();
$db   = getDB();

// Determine theme based on user role
$themeMap = [
    'faculty'      => 'theme-faculty',
    'admin1'       => 'theme-arjay',
    'admin2'       => 'theme-ian',
    'dean'         => 'theme-dean',
    'program_head' => 'theme-faculty'
];
$bodyClass = $themeMap[$user['role']] ?? 'theme-faculty';

// ── 1. Parse Filter & Sort Inputs ─────────────────────────────────────────────
$dateRange   = trim($_GET['date_range'] ?? 'all');
$fromDate    = trim($_GET['from_date'] ?? '');
$toDate      = trim($_GET['to_date'] ?? '');
$sortOrder   = strtolower(trim($_GET['sort'] ?? 'newest'));
$readStatus  = trim($_GET['status'] ?? 'all');
$searchQuery = trim($_GET['q'] ?? '');

// ── 2. Build Database Query (Strict User Scoping) ─────────────────────────────
$whereClauses = ["n.user_id = ?"];
$params = [(int)$user['id']];

// Filter by Date
if ($dateRange === 'today') {
    $whereClauses[] = "DATE(n.created_at) = CURDATE()";
} elseif ($dateRange === 'yesterday') {
    $whereClauses[] = "DATE(n.created_at) = (CURDATE() - INTERVAL 1 DAY)";
} elseif ($dateRange === 'week') {
    $whereClauses[] = "n.created_at >= (NOW() - INTERVAL 7 DAY)";
} elseif ($dateRange === 'month') {
    $whereClauses[] = "n.created_at >= (NOW() - INTERVAL 30 DAY)";
} elseif ($dateRange === 'custom') {
    if (!empty($fromDate) && !empty($toDate)) {
        $whereClauses[] = "DATE(n.created_at) BETWEEN ? AND ?";
        $params[] = $fromDate;
        $params[] = $toDate;
    } elseif (!empty($fromDate)) {
        $whereClauses[] = "DATE(n.created_at) >= ?";
        $params[] = $fromDate;
    } elseif (!empty($toDate)) {
        $whereClauses[] = "DATE(n.created_at) <= ?";
        $params[] = $toDate;
    }
}

// Filter by Read Status
if ($readStatus === 'unread') {
    $whereClauses[] = "n.is_read = 0";
} elseif ($readStatus === 'read') {
    $whereClauses[] = "n.is_read = 1";
}

// Search by Keyword
if ($searchQuery !== '') {
    $whereClauses[] = "(n.message LIKE ? OR a.title LIKE ?)";
    $params[] = '%' . $searchQuery . '%';
    $params[] = '%' . $searchQuery . '%';
}

// Organize by Date & Time (Sort Order)
$orderSql = ($sortOrder === 'oldest') ? 'n.created_at ASC, n.id ASC' : 'n.created_at DESC, n.id DESC';

$sqlWhere = "WHERE " . implode(' AND ', $whereClauses);

$stmt = $db->prepare("
    SELECT n.*, a.title AS activity_title, a.status AS activity_status
    FROM notifications n
    LEFT JOIN activities a ON n.activity_id = a.id
    {$sqlWhere}
    ORDER BY {$orderSql}
");
$stmt->execute($params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$filteredCount = count($notifications);

// Overall User Statistics
$statStmt = $db->prepare("
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count
    FROM notifications 
    WHERE user_id = ?
");
$statStmt->execute([(int)$user['id']]);
$userStats = $statStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_count' => 0, 'unread_count' => 0];
$totalCount  = (int)($userStats['total_count'] ?? 0);
$unreadCount = (int)($userStats['unread_count'] ?? 0);

// Helper for relative time display
function formatNotifRelativeTime($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', $time);
}

// Helper to resolve view activity link by user role
function getActivityViewUrl($activityId, $userRole) {
    if (!$activityId) return '#';
    $roleFolder = match($userRole) {
        'admin1' => 'admin1',
        'admin2' => 'admin2',
        'dean'   => 'dean',
        default  => 'faculty'
    };
    return BASE_URL . "/{$roleFolder}/view-activity.php?id=" . (int)$activityId;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>All Notifications – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    body, body *, h1, h2, h3, h4, h5, h6, .page-title, .card-header h2, .btn, .badge, .form-control, label {
      font-family: 'Plus Jakarta Sans', sans-serif !important;
    }

    .notif-page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
      margin-bottom: 20px;
    }

    .notif-stats-strip {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .notif-stat-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 9999px;
      font-size: 0.84rem;
      font-weight: 600;
      background: var(--bg-card, #ffffff);
      border: 1px solid var(--border, #e2e8f0);
      color: var(--text-main, #0f172a);
    }
    .notif-stat-pill.unread-pill {
      background: rgba(2, 132, 199, 0.1);
      border-color: rgba(2, 132, 199, 0.3);
      color: #0284c7;
    }
    [data-theme="dark"] .notif-stat-pill.unread-pill {
      background: rgba(56, 189, 248, 0.15);
      border-color: rgba(56, 189, 248, 0.35);
      color: #38bdf8;
    }

    /* Filter & Organization Bar */
    .notif-filter-card {
      background: var(--bg-card, #ffffff);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: var(--radius, 12px);
      padding: 16px 20px;
      margin-bottom: 22px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    }
    [data-theme="dark"] .notif-filter-card {
      background: #111e33;
      border-color: #1e2d45;
    }

    .filter-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 14px;
      align-items: flex-end;
    }

    .filter-group label {
      display: block;
      font-size: 0.78rem;
      font-weight: 600;
      color: var(--text-muted, #64748b);
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .custom-date-fields {
      display: flex;
      gap: 8px;
    }

    /* Notification Items */
    .page-notif-list {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .page-notif-item {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      padding: 16px 20px;
      background: var(--bg-card, #ffffff);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: var(--radius, 12px);
      transition: all 0.2s ease;
      position: relative;
    }
    [data-theme="dark"] .page-notif-item {
      background: #111e33;
      border-color: #1e2d45;
    }

    .page-notif-item:hover {
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
      border-color: rgba(2, 132, 199, 0.4);
    }

    .page-notif-item.unread {
      background: #f0f9ff;
      border-left: 5px solid #0284c7;
    }
    [data-theme="dark"] .page-notif-item.unread {
      background: rgba(2, 132, 199, 0.12);
      border-left: 5px solid #38bdf8;
    }

    .page-notif-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      flex-shrink: 0;
      background: var(--bg-base, #f1f5f9);
    }
    [data-theme="dark"] .page-notif-icon {
      background: rgba(255, 255, 255, 0.06);
    }

    .page-notif-content {
      flex: 1;
      min-width: 0;
    }

    .page-notif-msg {
      margin: 0 0 6px 0;
      font-size: 0.93rem;
      font-weight: 500;
      color: var(--text-main, #0f172a);
      line-height: 1.45;
      word-break: break-word;
    }
    .page-notif-item.unread .page-notif-msg {
      font-weight: 600;
    }

    .page-notif-meta {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 12px;
      font-size: 0.8rem;
      color: var(--text-muted, #64748b);
    }

    .page-notif-meta .time-tag {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-weight: 500;
    }

    .page-notif-meta .activity-tag {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 2px 10px;
      border-radius: 6px;
      font-weight: 600;
      font-size: 0.76rem;
      background: var(--bg-base, #f1f5f9);
      color: var(--text-main, #0f172a);
      text-decoration: none;
      transition: background 0.15s ease;
    }
    .page-notif-meta .activity-tag:hover {
      background: rgba(2, 132, 199, 0.15);
      color: #0284c7;
    }

    .badge-unread {
      background: #0284c7;
      color: #ffffff;
      padding: 2px 8px;
      border-radius: 9999px;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.02em;
    }

    .page-notif-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }

    .btn-notif-action {
      background: transparent;
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 8px;
      padding: 6px 12px;
      font-size: 0.78rem;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      color: var(--text-muted, #64748b);
      transition: all 0.15s ease;
    }
    [data-theme="dark"] .btn-notif-action {
      border-color: #1e2d45;
    }
    .btn-notif-action:hover {
      background: var(--bg-base, #f1f5f9);
      color: var(--text-main, #0f172a);
    }
    .btn-notif-action.btn-mark-single-read:hover {
      background: rgba(2, 132, 199, 0.1);
      color: #0284c7;
      border-color: #0284c7;
    }
    .btn-notif-action.btn-delete-single:hover {
      background: rgba(239, 68, 68, 0.1);
      color: #ef4444;
      border-color: #ef4444;
    }

    /* Empty state */
    .notif-empty-state {
      text-align: center;
      padding: 60px 20px;
      background: var(--bg-card, #ffffff);
      border: 1px dashed var(--border, #cbd5e1);
      border-radius: var(--radius, 12px);
      margin-top: 10px;
    }
    [data-theme="dark"] .notif-empty-state {
      background: #111e33;
      border-color: #1e2d45;
    }
    .notif-empty-icon {
      font-size: 2.8rem;
      margin-bottom: 12px;
      opacity: 0.85;
    }
  </style>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Notifications Center</div>
      <div class="topbar-right">
        <?php include __DIR__ . '/includes/notification-topbar-widget.php'; ?>
        <?php include __DIR__ . '/includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">
      <!-- Page Header & Stats -->
      <div class="notif-page-header">
        <div>
          <h1 style="margin: 0 0 6px 0; font-size: 1.45rem; font-weight: 700; color: var(--text-main);">
            🔔 All Notifications
          </h1>
          <p style="margin: 0; font-size: 0.88rem; color: var(--text-muted);">
            View, filter, and organize all system notifications and activity updates by date and time.
          </p>
        </div>

        <div class="notif-stats-strip">
          <div class="notif-stat-pill">
            <span>Total:</span>
            <strong><?= number_format($totalCount) ?></strong>
          </div>
          <div class="notif-stat-pill unread-pill" id="pageUnreadCountPill">
            <span>Unread:</span>
            <strong><?= number_format($unreadCount) ?></strong>
          </div>
          <?php if ($unreadCount > 0): ?>
            <button type="button" class="btn btn-outline" id="pageMarkAllBtn" style="font-size: 0.84rem; padding: 6px 14px; font-weight: 600;">
              ✓ Mark All as Read
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Filter and Organize By Date/Time Controls -->
      <div class="notif-filter-card">
        <form method="GET" action="<?= BASE_URL ?>/notifications.php" id="notifFilterForm">
          <div class="filter-grid">
            <!-- 1. Date Range Filter -->
            <div class="filter-group">
              <label for="f_date_range">Filter by Date</label>
              <select name="date_range" id="f_date_range" class="form-control" onchange="toggleCustomDates(this.value)">
                <option value="all" <?= ($dateRange === 'all') ? 'selected' : '' ?>>All Dates</option>
                <option value="today" <?= ($dateRange === 'today') ? 'selected' : '' ?>>Today</option>
                <option value="yesterday" <?= ($dateRange === 'yesterday') ? 'selected' : '' ?>>Yesterday</option>
                <option value="week" <?= ($dateRange === 'week') ? 'selected' : '' ?>>Last 7 Days</option>
                <option value="month" <?= ($dateRange === 'month') ? 'selected' : '' ?>>Last 30 Days</option>
                <option value="custom" <?= ($dateRange === 'custom') ? 'selected' : '' ?>>Custom Date Range</option>
              </select>
            </div>

            <!-- Custom Date Inputs (if custom chosen) -->
            <div class="filter-group" id="customDateRangeWrap" style="<?= ($dateRange === 'custom') ? '' : 'display:none;' ?>">
              <label>Custom Date Range</label>
              <div class="custom-date-fields">
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>" placeholder="From">
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>" placeholder="To">
              </div>
            </div>

            <!-- 2. Organize / Sort by Date and Time -->
            <div class="filter-group">
              <label for="f_sort">Organize by Date & Time</label>
              <select name="sort" id="f_sort" class="form-control">
                <option value="newest" <?= ($sortOrder === 'newest') ? 'selected' : '' ?>>⏱️ Newest First (Descending)</option>
                <option value="oldest" <?= ($sortOrder === 'oldest') ? 'selected' : '' ?>>⏳ Oldest First (Ascending)</option>
              </select>
            </div>

            <!-- 3. Read Status Filter -->
            <div class="filter-group">
              <label for="f_status">Read Status</label>
              <select name="status" id="f_status" class="form-control">
                <option value="all" <?= ($readStatus === 'all') ? 'selected' : '' ?>>All Statuses</option>
                <option value="unread" <?= ($readStatus === 'unread') ? 'selected' : '' ?>>🔵 Unread Only</option>
                <option value="read" <?= ($readStatus === 'read') ? 'selected' : '' ?>>⚪ Read Only</option>
              </select>
            </div>

            <!-- 4. Keyword Search -->
            <div class="filter-group" style="grid-column: span 1;">
              <label for="f_q">Search Keyword</label>
              <input type="text" name="q" id="f_q" class="form-control" placeholder="Search message or title..." value="<?= htmlspecialchars($searchQuery) ?>">
            </div>

            <!-- 5. Action Buttons -->
            <div class="filter-group" style="display: flex; gap: 8px;">
              <button type="submit" class="btn btn-primary" style="flex: 1;">Filter</button>
              <?php if ($dateRange !== 'all' || $sortOrder !== 'newest' || $readStatus !== 'all' || $searchQuery !== ''): ?>
                <a href="<?= BASE_URL ?>/notifications.php" class="btn btn-outline" style="line-height: 1.8;">Clear</a>
              <?php endif; ?>
            </div>
          </div>
        </form>
      </div>

      <!-- Notifications Results Strip -->
      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
        <span style="font-size: 0.88rem; font-weight: 600; color: var(--text-muted);">
          Showing <?= number_format($filteredCount) ?> <?= ($filteredCount === 1) ? 'Notification' : 'Notifications' ?>
        </span>
      </div>

      <!-- Notifications List -->
      <?php if (empty($notifications)): ?>
        <div class="notif-empty-state">
          <div class="notif-empty-icon">📭</div>
          <h3 style="margin: 0 0 6px 0; font-size: 1.15rem; font-weight: 700; color: var(--text-main);">
            No Notifications Found
          </h3>
          <p style="margin: 0; font-size: 0.88rem; color: var(--text-muted);">
            <?php if ($dateRange !== 'all' || $readStatus !== 'all' || $searchQuery !== ''): ?>
              No notifications match your current filter criteria. Try adjusting or clearing the filters above.
            <?php else: ?>
              You're all caught up! You have no notifications at this time.
            <?php endif; ?>
          </p>
        </div>
      <?php else: ?>
        <div class="page-notif-list">
          <?php foreach ($notifications as $n):
              $msg = strtolower($n['message']);
              if (str_contains($msg, 'submitted')) {
                  $emoji = '📋';
              } elseif (str_contains($msg, 'approved') || str_contains($msg, 'congratulations')) {
                  $emoji = '✅';
              } elseif (str_contains($msg, 'forwarded')) {
                  $emoji = '📤';
              } elseif (str_contains($msg, 'revision') || str_contains($msg, 'returned')) {
                  $emoji = '↩️';
              } elseif (str_contains($msg, 'rejected')) {
                  $emoji = '❌';
              } elseif (str_contains($msg, 'completed')) {
                  $emoji = '🎉';
              } else {
                  $emoji = '🔔';
              }
              $isUnread = !$n['is_read'];
              $fullDateTime = date('M j, Y \a\t g:i A', strtotime($n['created_at']));
              $relTime = formatNotifRelativeTime($n['created_at']);
          ?>
            <div class="page-notif-item <?= $isUnread ? 'unread' : '' ?>" id="notif-card-<?= (int)$n['id'] ?>">
              <div class="page-notif-icon">
                <?= $emoji ?>
              </div>

              <div class="page-notif-content">
                <p class="page-notif-msg"><?= htmlspecialchars($n['message']) ?></p>

                <div class="page-notif-meta">
                  <span class="time-tag" title="<?= htmlspecialchars($fullDateTime) ?>">
                    📅 <?= htmlspecialchars($fullDateTime) ?> (<?= htmlspecialchars($relTime) ?>)
                  </span>

                  <?php if (!empty($n['activity_id'])): ?>
                    <a href="<?= getActivityViewUrl($n['activity_id'], $user['role']) ?>" class="activity-tag">
                      <span>📄</span>
                      <span><?= !empty($n['activity_title']) ? htmlspecialchars($n['activity_title']) : ('Activity #' . (int)$n['activity_id']) ?></span>
                      <span>&rarr;</span>
                    </a>
                  <?php endif; ?>

                  <?php if ($isUnread): ?>
                    <span class="badge-unread">Unread</span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="page-notif-actions">
                <?php if ($isUnread): ?>
                  <button type="button" class="btn-notif-action btn-mark-single-read" onclick="markSingleAsRead(<?= (int)$n['id'] ?>)" title="Mark as Read">
                    ✓ Read
                  </button>
                <?php endif; ?>
                <button type="button" class="btn-notif-action btn-delete-single" onclick="deleteSingleNotification(<?= (int)$n['id'] ?>)" title="Delete Notification">
                  🗑️
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    function toggleCustomDates(val) {
      const wrap = document.getElementById('customDateRangeWrap');
      if (wrap) {
        wrap.style.display = (val === 'custom') ? 'block' : 'none';
      }
    }

    async function markSingleAsRead(id) {
      if (!id) return;
      try {
        const resp = await fetch('<?= BASE_URL ?>/api/notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'mark_read', id: id })
        });
        const res = await resp.json();
        if (res.success) {
          const card = document.getElementById('notif-card-' + id);
          if (card) {
            card.classList.remove('unread');
            card.querySelector('.badge-unread')?.remove();
            card.querySelector('.btn-mark-single-read')?.remove();
          }
          // Update topbar unread count
          const badge = document.getElementById('notifBadge');
          if (badge) {
            const count = parseInt(badge.textContent, 10) - 1;
            if (count <= 0) badge.remove();
            else badge.textContent = count;
          }
          const pagePill = document.getElementById('pageUnreadCountPill');
          if (pagePill) {
            const cur = parseInt(pagePill.querySelector('strong')?.textContent || '0', 10);
            const next = Math.max(0, cur - 1);
            pagePill.querySelector('strong').textContent = next;
          }
        }
      } catch (e) {
        console.error('Error marking as read:', e);
      }
    }

    async function deleteSingleNotification(id) {
      if (!id) return;
      if (!confirm('Are you sure you want to delete this notification?')) return;
      try {
        const resp = await fetch('<?= BASE_URL ?>/api/notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', id: id })
        });
        const res = await resp.json();
        if (res.success) {
          const card = document.getElementById('notif-card-' + id);
          if (card) {
            card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
            card.style.opacity = '0';
            card.style.transform = 'translateY(-10px)';
            setTimeout(() => card.remove(), 300);
          }
        }
      } catch (e) {
        console.error('Error deleting notification:', e);
      }
    }
  </script>
</body>
</html>

