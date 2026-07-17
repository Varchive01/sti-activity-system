<?php

$cssBlock = <<<CSS
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
CSS;

$htmlBlock = <<<HTML
    <!-- Backdrop -->
    <div class="notif-panel-backdrop" id="notifBackdrop"></div>

    <!-- Slide Panel -->
    <div class="notif-dropdown" id="notifDropdown">
      <div class="notif-header">
        <h3>🔔 Notifications
          <?php if (\$unreadCount > 0): ?>
            <span style="font-size:.73rem;color:#3B82F6;font-weight:600;margin-left:4px;">(<?= \$unreadCount ?> new)</span>
          <?php endif; ?>
        </h3>
        <div class="notif-header-actions">
          <?php if (\$unreadCount > 0): ?>
            <button class="notif-mark-all" id="markAllBtn">Mark all as read</button>
          <?php endif; ?>
          <button class="notif-close-btn" id="notifCloseBtn">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
      <div class="notif-list" id="notifList">
        <?php if (empty(\$notifications)): ?>
          <div class="notif-empty">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <p>You're all caught up!<br><span style="font-size:.75rem;">No notifications yet.</span></p>
          </div>
          <?php else: foreach (\$notifications as \$n):
            \$msg = strtolower(\$n['message']);
            if (str_contains(\$msg, 'submitted')) {
              \$type = 'submitted';
              \$emoji = '📋';
            } elseif (str_contains(\$msg, 'approved') || str_contains(\$msg, 'congratulations')) {
              \$type = 'approved';
              \$emoji = '✅';
            } elseif (str_contains(\$msg, 'forwarded')) {
              \$type = 'forwarded';
              \$emoji = '📤';
            } elseif (str_contains(\$msg, 'revision') || str_contains(\$msg, 'returned')) {
              \$type = 'returned';
              \$emoji = '↩️';
            } elseif (str_contains(\$msg, 'rejected')) {
              \$type = 'rejected';
              \$emoji = '❌';
            } elseif (str_contains(\$msg, 'completed')) {
              \$type = 'completed';
              \$emoji = '🎉';
            } else {
              \$type = 'default';
              \$emoji = '🔔';
            }
          ?>
            <div class="notif-item <?= \$n['is_read'] ? '' : 'unread' ?>"
              data-id="<?= \$n['id'] ?>"
              data-message="<?= htmlspecialchars(\$n['message']) ?>"
              data-time="<?= htmlspecialchars(\$n['created_at']) ?>"
              data-activity-id="<?= \$n['activity_id'] ?>"
              data-activity-title="<?= htmlspecialchars(\$n['activity_title'] ?? '') ?>"
              data-emoji="<?= \$emoji ?>">
              <div class="notif-icon type-<?= \$type ?>"><?= \$emoji ?></div>
              <div class="notif-body">
                <p class="notif-msg"><?= htmlspecialchars(\$n['message']) ?></p>
                <div class="notif-time"><?= timeAgo(\$n['created_at']) ?></div>
              </div>
              <?php if (!\$n['is_read']): ?><div class="notif-unread-dot"></div><?php endif; ?>
            </div>
        <?php endforeach;
        endif; ?>
      </div>
    </div>
HTML;

$files = [
    'c:/xampp/htdocs/sti-activity-system/faculty/dashboard.php',
    'c:/xampp/htdocs/sti-activity-system/dean/dashboard.php',
    'c:/xampp/htdocs/sti-activity-system/admin2/dashboard.php',
    'c:/xampp/htdocs/sti-activity-system/admin1/dashboard.php',
];

foreach ($files as $fp) {
    if (!file_exists($fp)) continue;
    $content = file_get_contents($fp);

    // 1. Unwrap the bell button: remove <div class="notif-wrapper" ...> and the corresponding ending div
    // The wrapper contains the bell, the backdrop, and the notification card.
    if (preg_match('/<div class="notif-wrapper"[^>]*>\s*(<!-- Notification Bell -->.*?<\/button>).*?<\/div>\s*<span/s', $content, $matches)) {
        // Extract the bell
        $bellHtml = $matches[1];
        
        // Replace the whole wrapper block (down to but not including <span class="text-sm...) with just the bell button
        $content = preg_replace(
            '/<div class="notif-wrapper"[^>]*>.*?<\/div>\s*(<span class="text-sm)/s', 
            $bellHtml . "\n        $1", 
            $content
        );
    }
    
    // In case the wrapper isn't exactly matching (e.g. they modified it), just ensure we strip out any existing IGN UI backdrop/dropdown
    $content = preg_replace('/(\s*<!-- Backdrop -->.*?(?=<\/div>\s*<\/header>))/s', '', $content);
    $content = preg_replace('/(\s*<!-- Backdrop -->.*?(?=<!-- Detail Modal -->))/s', '', $content);

    // 2. Inject original CSS back into <style> if missing
    if (strpos($content, '/* ── Bell ── */') === false) {
        $content = preg_replace('/<\/style>/', $cssBlock . "\n  </style>", $content);
    }

    // 3. Inject the original HTML back before <!-- Detail Modal -->
    if (strpos($content, 'id="notifDropdown"') === false) {
        $content = preg_replace('/(\s*<!-- Detail Modal -->)/', "\n" . $htmlBlock . '$1', $content);
    }

    file_put_contents($fp, $content);
}

// Clean up main.css
$mainCssPath = 'c:/xampp/htdocs/sti-activity-system/assets/css/main.css';
if (file_exists($mainCssPath)) {
    $mainCss = file_get_contents($mainCssPath);
    // Remove the whole IGN block from main.css
    $mainCss = preg_replace('/\/\* ============================================================\s*User-Provided IGN Style Notification Dropdown.*?\.notif-empty\s*\{[^}]*\}\s*/s', '', $mainCss);
    file_put_contents($mainCssPath, trim($mainCss) . "\n");
}

echo "Reverted to the original UI completely.\n";
?>
