<?php
/**
 * notification-topbar-widget.php
 *
 * Reusable topbar notification component for the STI Activity System.
 * Renders the topbar notification bell, unread badge, slide-in drawer,
 * and detail modal for use inside `.topbar-right`.
 *
 * Reuses:
 * - getUnreadNotificationCount()
 * - getUserNotifications()
 * - assets/js/notifications.js
 * - existing notification API (/api/notifications.php)
 * - existing dashboard notification CSS
 */

// 1. Resolve User Context
if (!isset($topbarUser)) {
  $topbarUser = isset($user) ? $user : (function_exists('currentUser') ? currentUser() : null);
}

// 2. Resolve Database Connection
if (!isset($topbarDb)) {
  $topbarDb = (isset($db) && ($db instanceof PDO)) ? $db : (function_exists('getDB') ? getDB() : null);
}

// Ensure functions.php is available for notification helpers
if (!function_exists('getUnreadNotificationCount')) {
  $functionsPath = __DIR__ . '/functions.php';
  if (file_exists($functionsPath)) {
    require_once $functionsPath;
  }
}

// 3. Resolve Unread Notification Count
if (!isset($topbarUnreadCount)) {
  if (isset($unreadCount)) {
    $topbarUnreadCount = (int)$unreadCount;
  } elseif ($topbarDb && !empty($topbarUser['id']) && function_exists('getUnreadNotificationCount')) {
    $topbarUnreadCount = getUnreadNotificationCount($topbarDb, (int)$topbarUser['id']);
  } else {
    $topbarUnreadCount = 0;
  }
}

// 4. Resolve Notifications List
if (!isset($topbarNotifications)) {
  if (isset($notifications) && is_array($notifications)) {
    $topbarNotifications = $notifications;
  } elseif ($topbarDb && !empty($topbarUser['id']) && function_exists('getUserNotifications')) {
    $topbarNotifications = getUserNotifications($topbarDb, (int)$topbarUser['id'], 10);
  } else {
    $topbarNotifications = [];
  }
}

// 5. User Initial for Modal Avatar
$topbarInitial = strtoupper(substr($topbarUser['name'] ?? 'S', 0, 1));

// 6. Helper for formatting relative time
if (!function_exists('topbarWidgetTimeAgo')) {
  function topbarWidgetTimeAgo($datetime)
  {
    if (function_exists('timeAgo')) {
      return timeAgo($datetime);
    }
    if (function_exists('sidebarTimeAgo')) {
      return sidebarTimeAgo($datetime);
    }
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j', strtotime($datetime));
  }
}
?>

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

<?php if (!defined('NOTIF_TOPBAR_STYLES_LOADED')): define('NOTIF_TOPBAR_STYLES_LOADED', true); ?>
<style>
/* ── Topbar Theme Toggle & Bell ── */
#themeToggleBtn,
#notifBellBtn,
.theme-toggle-btn,
.notif-bell-btn {
  width: 38px;
  height: 38px;
  min-width: 38px;
  min-height: 38px;
  border-radius: 50%;
  background: transparent;
  border: none;
  box-shadow: none;
  color: var(--text-muted);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background .18s ease, color .18s ease, transform .15s ease;
  position: relative;
  outline: none;
  padding: 0;
  box-sizing: border-box;
  line-height: 1;
}
#themeToggleBtn:hover,
#notifBellBtn:hover,
.theme-toggle-btn:hover,
.notif-bell-btn:hover {
  background: var(--border-light);
  color: var(--text-main);
  border: none;
  box-shadow: none;
  transform: translateY(-1px);
}
#themeToggleBtn:active,
#notifBellBtn:active,
.theme-toggle-btn:active,
.notif-bell-btn:active {
  transform: translateY(0) scale(0.92);
  box-shadow: none;
}
#themeToggleBtn:focus-visible,
#notifBellBtn:focus-visible,
.theme-toggle-btn:focus-visible,
.notif-bell-btn:focus-visible {
  outline: 2px solid var(--sti-blue);
  outline-offset: 2px;
  border-radius: 50%;
}
.theme-toggle-btn svg,
.notif-bell-btn svg {
  width: 19px;
  height: 19px;
  display: block;
  stroke-width: 2;
  transition: transform 0.2s ease, color 0.15s ease;
}
.theme-toggle-btn:hover svg {
  transform: rotate(15deg);
}
[data-theme="dark"] .theme-toggle-btn .theme-icon-moon {
  display: none !important;
}
[data-theme="dark"] .theme-toggle-btn .theme-icon-sun {
  display: block !important;
  color: var(--sti-gold, #FBBF24);
}
:root:not([data-theme="dark"]) .theme-toggle-btn .theme-icon-moon,
[data-theme="light"] .theme-toggle-btn .theme-icon-moon {
  display: block !important;
}
:root:not([data-theme="dark"]) .theme-toggle-btn .theme-icon-sun,
[data-theme="light"] .theme-toggle-btn .theme-icon-sun {
  display: none !important;
}
[data-theme="dark"] .notif-dropdown {
  background: var(--bg-card, #0F1B2E);
  border-color: var(--border, #1E2D45);
  box-shadow: 0 16px 36px rgba(0, 0, 0, 0.45);
}
[data-theme="dark"] .notif-header {
  background: var(--bg-card, #0F1B2E);
  border-bottom-color: var(--border, #1E2D45);
}
[data-theme="dark"] .notif-header h3,
[data-theme="dark"] .notif-header-title {
  color: var(--text-main, #F8FAFC);
}
[data-theme="dark"] .notif-count-pill {
  color: #38BDF8;
  background: rgba(56, 189, 248, 0.15);
}
[data-theme="dark"] .notif-mark-all {
  color: #38BDF8;
}
[data-theme="dark"] .notif-mark-all:hover {
  background: rgba(56, 189, 248, 0.12);
  color: #7DD3FC;
}
[data-theme="dark"] .notif-delete-all {
  color: #F87171;
}
[data-theme="dark"] .notif-delete-all:hover {
  background: rgba(248, 113, 113, 0.15);
}
[data-theme="dark"] .notif-close-btn {
  color: var(--text-muted, #94A3B8);
}
[data-theme="dark"] .notif-close-btn:hover {
  background: var(--bg-base, #070E18);
  color: var(--text-main, #F8FAFC);
}
[data-theme="dark"] .notif-item {
  border-bottom-color: var(--border, #1E2D45);
}
[data-theme="dark"] .notif-item:hover {
  background: var(--bg-base, #070E18);
}
[data-theme="dark"] .notif-item.unread {
  background: rgba(2, 132, 199, 0.12);
  border-left-color: #38BDF8;
}
[data-theme="dark"] .notif-item.unread:hover {
  background: rgba(2, 132, 199, 0.20);
}
[data-theme="dark"] .notif-msg {
  color: var(--text-main, #F8FAFC);
}
[data-theme="dark"] .notif-time {
  color: var(--text-muted, #94A3B8);
}
[data-theme="dark"] .notif-unread-dot {
  background: #38BDF8;
}
[data-theme="dark"] .notif-modal {
  background: var(--bg-card);
  box-shadow: 0 20px 60px rgba(0, 0, 0, .6);
}
[data-theme="dark"] .notif-modal-header,
[data-theme="dark"] .notif-modal-from {
  background: var(--bg-card);
  border-bottom-color: var(--border);
}
[data-theme="dark"] .notif-modal-header h3,
[data-theme="dark"] .from-name,
[data-theme="dark"] .subject-val {
  color: var(--text-main);
}
[data-theme="dark"] .notif-modal-message {
  color: var(--text-secondary);
}
[data-theme="dark"] .notif-modal-close {
  background: var(--bg-base);
  color: var(--text-muted);
}
[data-theme="dark"] .notif-modal-close:hover {
  background: var(--bg-card-elevated);
  color: var(--text-main);
}
[data-theme="dark"] .notif-modal-btn.ghost {
  background: var(--bg-base);
  color: var(--text-muted);
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

/* ── Hidden Backdrop (No full-screen overlay) ── */
.notif-panel-backdrop {
  display: none !important;
}

/* ── Notification Popover Wrapper ── */
.notif-topbar-wrapper {
  position: relative;
  display: inline-flex;
  align-items: center;
}

/* ── Floating Popover Card ── */
.notif-dropdown {
  display: none;
  position: absolute;
  top: calc(100% + 8px);
  right: -36px;
  width: 400px;
  max-width: calc(100vw - 24px);
  background: var(--bg-card, #FFFFFF);
  border: 1px solid var(--border, #E2E8F0);
  border-radius: var(--radius-sm, 12px);
  box-shadow: 0 12px 32px rgba(10, 22, 40, 0.18);
  z-index: 1050;
  font-family: 'Plus Jakarta Sans', sans-serif;
  animation: notifPopoverSlide 0.18s cubic-bezier(0.16, 1, 0.3, 1);
  box-sizing: border-box;
  flex-direction: column;
  overflow: hidden;
  height: auto !important;
  min-height: 0 !important;
}

.notif-dropdown::before, .notif-dropdown::after {
  display: none !important;
  content: none !important;
}

.notif-dropdown.open {
  display: flex !important;
}

@keyframes notifPopoverSlide {
  from { opacity: 0; transform: translateY(-6px) scale(0.98); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* Popover Header */
.notif-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  border-bottom: 1px solid var(--border, #E2E8F0);
  background: var(--bg-card, #FFFFFF);
  flex-shrink: 0;
  box-sizing: border-box;
}

.notif-header h3,
.notif-header-title {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.90rem;
  font-weight: 700;
  color: var(--text-main, #0A1628);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 6px;
  letter-spacing: -0.01em;
}

.notif-count-pill {
  font-size: 0.70rem;
  font-weight: 600;
  color: var(--sti-blue, #0284C7);
  background: rgba(2, 132, 199, 0.1);
  padding: 2px 7px;
  border-radius: 999px;
  margin-left: 4px;
}

.notif-header-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}

.notif-mark-all,
.notif-delete-all,
.notif-action-btn {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.72rem;
  font-weight: 600;
  padding: 4px 8px;
  border-radius: 6px;
  border: none;
  background: transparent;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
  line-height: 1;
}

.notif-mark-all {
  color: var(--sti-blue, #0284C7);
}

.notif-mark-all:hover {
  background: rgba(2, 132, 199, 0.08);
  color: var(--sti-blue-hover, #0369A1);
}

.notif-delete-all {
  color: var(--sti-red, #EF4444);
}

.notif-delete-all:hover {
  background: rgba(239, 68, 68, 0.1);
  color: #DC2626;
}

.notif-close-btn {
  background: transparent;
  border: none;
  cursor: pointer;
  color: var(--text-muted, #64748B);
  padding: 4px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background 0.15s ease, color 0.15s ease;
  line-height: 1;
}

.notif-close-btn:hover {
  background: var(--bg-base, #F1F5F9);
  color: var(--text-main, #0A1628);
}

.notif-close-btn svg {
  width: 16px;
  height: 16px;
}

/* Scrollable Notification List */
.notif-list {
  max-height: 380px;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 4px 0;
  display: flex;
  flex-direction: column;
}

.notif-list::-webkit-scrollbar {
  width: 5px;
}

.notif-list::-webkit-scrollbar-track {
  background: transparent;
}

.notif-list::-webkit-scrollbar-thumb {
  background: rgba(148, 163, 184, 0.35);
  border-radius: 10px;
}

[data-theme="dark"] .notif-list::-webkit-scrollbar-thumb {
  background: rgba(148, 163, 184, 0.2);
}

.notif-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 36px 20px;
  color: var(--text-muted, #64748B);
  gap: 8px;
  text-align: center;
}

.notif-empty svg {
  width: 36px;
  height: 36px;
  opacity: .4;
}

.notif-empty p {
  font-size: .82rem;
  line-height: 1.4;
  margin: 0;
}

.notif-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 16px;
  cursor: pointer;
  border-bottom: 1px solid var(--border-light, #F1F5F9);
  border-left: 3px solid transparent;
  transition: background .15s ease, border-color .15s ease;
  background: transparent;
  box-sizing: border-box;
}

.notif-item:last-child {
  border-bottom: none;
}

.notif-item:hover {
  background: var(--bg-base, #F8FAFC);
}

.notif-item.unread {
  background: rgba(2, 132, 199, 0.05);
  border-left-color: var(--sti-blue, #0284C7);
}

.notif-item.unread:hover {
  background: rgba(2, 132, 199, 0.10);
}

.notif-icon {
  width: 32px;
  height: 32px;
  min-width: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: .85rem;
  line-height: 1;
}

.notif-icon.type-submitted { background: #DBEAFE; }
.notif-icon.type-approved { background: #DCFCE7; }
.notif-icon.type-forwarded { background: #E0E7FF; }
.notif-icon.type-returned { background: #FEF3C7; }
.notif-icon.type-rejected { background: #FEE2E2; }
.notif-icon.type-completed { background: #F3F4F6; }
.notif-icon.type-default { background: #F3F4F6; }

[data-theme="dark"] .notif-icon.type-submitted { background: rgba(59, 130, 246, 0.2); }
[data-theme="dark"] .notif-icon.type-approved  { background: rgba(34, 197, 94, 0.2); }
[data-theme="dark"] .notif-icon.type-forwarded { background: rgba(99, 102, 241, 0.2); }
[data-theme="dark"] .notif-icon.type-returned  { background: rgba(245, 158, 11, 0.2); }
[data-theme="dark"] .notif-icon.type-rejected  { background: rgba(239, 68, 68, 0.2); }
[data-theme="dark"] .notif-icon.type-completed { background: rgba(100, 116, 139, 0.2); }
[data-theme="dark"] .notif-icon.type-default   { background: rgba(100, 116, 139, 0.2); }

.notif-body {
  flex: 1;
  min-width: 0;
}

.notif-msg {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: .80rem;
  color: var(--text-main, #0A1628);
  line-height: 1.4;
  margin: 0 0 3px;
  word-break: break-word;
}

.notif-item.unread .notif-msg {
  font-weight: 600;
}

.notif-time {
  font-size: .68rem;
  color: var(--text-muted, #64748B);
  font-weight: 500;
}

.notif-unread-dot {
  width: 7px;
  height: 7px;
  background: var(--sti-blue, #0284C7);
  border-radius: 50%;
  margin-top: 6px;
  flex-shrink: 0;
}

@media (max-width: 640px) {
  .notif-dropdown {
    width: calc(100vw - 24px);
    max-width: 380px;
    right: -60px;
  }
}
@media (max-width: 480px) {
  .notif-dropdown {
    right: -80px;
    width: calc(100vw - 20px);
  }
}

/* ── Notification Detail Modal ── */
.notif-modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  z-index: 950;
  background: rgba(0, 0, 0, .45);
  align-items: center;
  justify-content: center;
}
.notif-modal-overlay.open {
  display: flex;
}
.notif-modal {
  background: #fff;
  border-radius: 14px;
  width: 100%;
  max-width: 480px;
  margin: 16px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
  overflow: hidden;
  animation: modalPop .2s ease;
}
@keyframes modalPop {
  from { transform: scale(.94); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
.notif-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  border-bottom: 1px solid #F3F4F6;
}
.notif-modal-header h3 {
  font-size: 1rem;
  font-weight: 700;
  margin: 0;
  color: #111;
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
<?php endif; ?>

<!-- Theme Toggle Button -->
<button type="button" class="theme-toggle-btn" id="themeToggleBtn" title="Switch to dark mode" aria-label="Switch to dark mode">
  <svg class="theme-icon-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
  </svg>
  <svg class="theme-icon-sun" fill="none" stroke="currentColor" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
  </svg>
</button>

<!-- Topbar Notification Component Wrapper -->
<div class="notif-topbar-wrapper" id="notifTopbarWrapper">
  <!-- 1. Notification Bell Button for Topbar -->
  <button type="button" class="notif-bell-btn" id="notifBellBtn" title="Notifications" aria-label="Notifications" aria-haspopup="true" aria-expanded="false">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
        d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
    </svg>
    <?php if ($topbarUnreadCount > 0): ?>
      <span class="notif-badge" id="notifBadge"><?= $topbarUnreadCount > 99 ? '99+' : $topbarUnreadCount ?></span>
    <?php endif; ?>
  </button>

  <!-- 2. Hidden Backdrop placeholder (preserves ID for test compatibility without showing full-screen overlay) -->
  <div class="notif-panel-backdrop" id="notifBackdrop" style="display:none !important;" aria-hidden="true"></div>

  <!-- 3. Floating Popover Menu -->
  <div class="notif-dropdown" id="notifDropdown" role="dialog" aria-label="Notifications">
    <div class="notif-header">
      <h3 class="notif-header-title">🔔 Notifications
        <?php if ($topbarUnreadCount > 0): ?>
          <span class="notif-count-pill" id="notifCountPill">(<?= $topbarUnreadCount ?> new)</span>
        <?php endif; ?>
      </h3>
      <div class="notif-header-actions">
        <?php if ($topbarUnreadCount > 0): ?>
          <button type="button" class="notif-action-btn notif-mark-all" id="markAllBtn">Mark all read</button>
        <?php endif; ?>
        <?php if (!empty($topbarNotifications)): ?>
          <button type="button" class="notif-action-btn notif-delete-all" id="deleteAllBtn">Delete all</button>
        <?php endif; ?>
        <button type="button" class="notif-close-btn" id="notifCloseBtn" aria-label="Close notifications">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>
    <div class="notif-list" id="notifList">
      <?php if (empty($topbarNotifications)): ?>
        <div class="notif-empty">
          <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
              d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
          </svg>
          <p>You're all caught up!<br><span style="font-size:.75rem;">No notifications yet.</span></p>
        </div>
      <?php else: foreach ($topbarNotifications as $n):
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
              <div class="notif-time"><?= topbarWidgetTimeAgo($n['created_at']) ?></div>
            </div>
            <?php if (!$n['is_read']): ?><div class="notif-unread-dot"></div><?php endif; ?>
          </div>
      <?php endforeach;
      endif; ?>
    </div>
  </div>
</div>

<!-- 4. Notification Detail Modal -->
<div class="notif-modal-overlay" id="notifModal">
  <div class="notif-modal">
    <div class="notif-modal-header">
      <h3>Notification Detail</h3>
      <button type="button" class="notif-modal-close" id="modalClose" aria-label="Close modal">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>
    <div class="notif-modal-from">
      <div class="from-row">
        <span class="from-label">From:</span>
        <div class="from-avatar"><?= $topbarInitial ?></div>
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
        <button type="button" class="notif-modal-btn primary" id="modalViewBtn" style="display:none">View Activity</button>
        <button type="button" class="notif-modal-btn danger" id="modalDeleteBtn">Delete</button>
        <button type="button" class="notif-modal-btn ghost" id="modalDismissBtn">Dismiss</button>
      </div>
    </div>
  </div>
</div>

<!-- 5. Reusable notifications.js Script -->
<script src="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/js/notifications.js"></script>
