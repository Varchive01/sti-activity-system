<?php

$cssBlock = <<<CSS

/* ============================================================
   IGN-Style Notification Dropdown Redesign (Popover)
   ============================================================ */
:root {
    --ign-bg: #ffffff;
    --ign-text: #111111;
    --ign-muted: #6B7280;
    --ign-blue: #3b82f6;
    --ign-border: #E5E7EB;
    --ign-hover: #F9FAFB;
}

/* Wrapper for positioning the popover relative to the bell */
.notif-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

/* Override old bell button style slightly to ensure it looks good */
.notif-bell-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 50%;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .15s, color .15s;
    position: relative;
    outline: none;
}
.notif-bell-btn:hover {
    background: var(--bg-base);
    color: var(--text-main);
}
.notif-badge {
    position: absolute;
    top: 0px;
    right: 0px;
    background: #EF4444;
    color: #fff;
    font-size: .65rem;
    font-weight: 700;
    min-width: 18px;
    height: 18px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    pointer-events: none;
    border: 2px solid #fff;
    box-sizing: content-box;
}

/* Hidden overlay backdrop to capture outside clicks */
.notif-panel-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 900;
    background: transparent;
}
.notif-panel-backdrop.open {
    display: block;
}

/* Popover Dropdown Card */
.notif-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 14px);
    right: -10px; /* Shift slightly to align caret nicely with bell */
    width: 380px;
    max-width: calc(100vw - 20px);
    background-color: var(--ign-bg);
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1), 0 2px 10px rgba(0,0,0,0.06);
    border: 1px solid var(--ign-border);
    z-index: 901;
    flex-direction: column;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    transform-origin: top right;
}

/* Caret (Arrow) pointing up to the bell */
.notif-dropdown::before, .notif-dropdown::after {
    content: '';
    position: absolute;
    bottom: 100%;
    right: 18px; /* Position under the bell */
    border: solid transparent;
    height: 0;
    width: 0;
    pointer-events: none;
}
.notif-dropdown::after {
    border-color: rgba(255, 255, 255, 0);
    border-bottom-color: var(--ign-bg);
    border-width: 8px;
    margin-right: 1px;
}
.notif-dropdown::before {
    border-color: rgba(229, 231, 235, 0);
    border-bottom-color: var(--ign-border);
    border-width: 9px;
}

.notif-dropdown.open {
    display: flex;
    animation: popoverFade .2s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes popoverFade {
    from { opacity: 0; transform: translateY(-8px) scale(0.96); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

/* Header */
.notif-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--ign-border);
    background: var(--ign-bg);
    border-radius: 12px 12px 0 0;
}

.notif-header h3 {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--ign-text);
    letter-spacing: -0.01em;
}

.notif-mark-all {
    color: var(--ign-muted);
    font-weight: 600;
    font-size: 0.85rem;
    background: none;
    border: none;
    cursor: pointer;
    transition: color 0.2s ease;
    padding: 0;
}
.notif-mark-all:hover {
    color: var(--ign-text);
}

/* Scrollable List */
.notif-list {
    max-height: 420px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
}
.notif-list::-webkit-scrollbar { width: 6px; }
.notif-list::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 3px; }

/* Individual Item */
.notif-item {
    display: flex;
    align-items: flex-start;
    padding: 16px 20px;
    border-bottom: 1px solid var(--ign-border);
    cursor: pointer;
    transition: background-color 0.2s ease;
    position: relative;
    text-decoration: none;
}
.notif-item:last-child {
    border-bottom: none;
}
.notif-item:hover {
    background-color: var(--ign-hover);
}

/* Left Icon / Avatar */
.notif-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-right: 14px;
    font-size: 1.1rem;
}
.avatar-gray { background-color: #F3F4F6; }
.avatar-green { background-color: #DCFCE7; }
.avatar-blue { background-color: #DBEAFE; }
.avatar-red { background-color: #FEE2E2; }

/* Content Area */
.notif-content {
    flex-grow: 1;
    min-width: 0;
    padding-right: 24px; /* Room for unread dot & action menu */
}

/* Title & Preview */
.notif-msg {
    font-size: 0.9rem;
    line-height: 1.4;
    color: var(--ign-muted);
    margin: 0 0 6px 0;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.unread .notif-msg {
    color: var(--ign-text);
}
.notif-msg strong {
    color: var(--ign-text);
    font-weight: 600;
}

/* Footer info inside row (Timestamp) */
.notif-meta {
    display: flex;
    align-items: center;
    font-size: 0.75rem;
    color: #9CA3AF;
    font-weight: 500;
}

/* Unread Dot Indicator */
.notif-unread-dot {
    position: absolute;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    width: 8px;
    height: 8px;
    background-color: var(--ign-blue);
    border-radius: 50%;
    display: none;
}
.unread .notif-unread-dot {
    display: block;
}

/* Three Dot Options Menu (appears on hover) */
.notif-item-options {
    position: absolute;
    right: 14px;
    top: 14px;
    color: #9CA3AF;
    opacity: 0;
    transition: opacity 0.2s;
    font-size: 14px;
    letter-spacing: 1px;
    font-weight: bold;
}
.notif-item:hover .notif-item-options {
    opacity: 1;
}

/* Empty State */
.notif-empty {
    padding: 60px 20px;
    text-align: center;
    color: #9CA3AF;
    font-size: 0.95rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.notif-empty svg { width: 32px; height: 32px; opacity: 0.5; }

/* Dropdown Footer */
.notif-footer {
    border-top: 1px solid var(--ign-border);
    padding: 12px 20px;
    text-align: center;
    background: var(--ign-bg);
    border-radius: 0 0 12px 12px;
}
.notif-view-all {
    display: block;
    width: 100%;
    background: none;
    border: none;
    color: var(--ign-blue);
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: color 0.15s;
}
.notif-view-all:hover {
    color: #2563EB;
}
CSS;

$htmlBlock = <<<HTML
    <!-- Notification Wrapper Context -->
    <div class="notif-wrapper">
        <!-- Notification Bell -->
        <button class="notif-bell-btn" id="notifBellBtn" title="Notifications">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
            </svg>
            <?php if (\$unreadCount > 0): ?>
                <span class="notif-badge" id="notifBadge"><?= \$unreadCount > 99 ? '99+' : \$unreadCount ?></span>
            <?php endif; ?>
        </button>

        <!-- Backdrop -->
        <div class="notif-panel-backdrop" id="notifBackdrop"></div>

        <!-- Floating Popover Dropdown -->
        <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-header">
                <h3>Notifications</h3>
                <?php if (\$unreadCount > 0): ?>
                    <button class="notif-mark-all" id="markAllBtn" title="Mark all as read">Mark all as read</button>
                <?php endif; ?>
            </div>
            
            <div class="notif-list" id="notifList">
                <?php if (empty(\$notifications)): ?>
                    <div class="notif-empty">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                        You're all caught up!
                    </div>
                <?php else: foreach (\$notifications as \$n):
                    \$msg = strtolower(\$n['message']);
                    if (str_contains(\$msg, 'submitted')) {
                        \$avatar_bg = 'avatar-gray'; \$avatar_txt = '📋';
                    } elseif (str_contains(\$msg, 'approved') || str_contains(\$msg, 'congratulations') || str_contains(\$msg, 'completed')) {
                        \$avatar_bg = 'avatar-green'; \$avatar_txt = '✅';
                    } elseif (str_contains(\$msg, 'forwarded')) {
                        \$avatar_bg = 'avatar-blue'; \$avatar_txt = '📤';
                    } elseif (str_contains(\$msg, 'revision') || str_contains(\$msg, 'returned')) {
                        \$avatar_bg = 'avatar-red'; \$avatar_txt = '↩️';
                    } elseif (str_contains(\$msg, 'rejected')) {
                        \$avatar_bg = 'avatar-red'; \$avatar_txt = '❌';
                    } else {
                        \$avatar_bg = 'avatar-gray'; \$avatar_txt = '🔔';
                    }
                    
                    // Formatting title logic based on activity type for "IGN style" highlighting
                    // Assuming \$n['activity_title'] exists and is the focus
                    \$title_part = \$n['activity_title'] ? "on <strong>" . htmlspecialchars(\$n['activity_title']) . "</strong>" : "";
                    // Remove activity title from the raw message if we want to format it distinctly,
                    // but for safety, since messages are fully formed in DB, we will just output message directly and highlight words.
                    // Instead, we just output the message with bolding for 'strong' emphasis on titles if possible.
                    // Easiest is to output message directly.
                ?>
                    <!-- Notification Item -->
                    <div class="notif-item <?= \$n['is_read'] ? '' : 'unread' ?>"
                        data-id="<?= \$n['id'] ?>"
                        data-message="<?= htmlspecialchars(\$n['message']) ?>"
                        data-time="<?= htmlspecialchars(\$n['created_at']) ?>"
                        data-activity-id="<?= \$n['activity_id'] ?>"
                        data-activity-title="<?= htmlspecialchars(\$n['activity_title'] ?? '') ?>">
                        
                        <div class="notif-avatar <?= \$avatar_bg ?>"><?= \$avatar_txt ?></div>
                        
                        <div class="notif-content">
                            <!-- In a real system, we'd break down the sender, action, and target. Here we bold keywords. -->
                            <p class="notif-msg"><?= htmlspecialchars(\$n['message']) ?></p>
                            <div class="notif-meta">
                                <?= timeAgo(\$n['created_at']) ?>
                            </div>
                        </div>
                        
                        <div class="notif-item-options" title="Options">•••</div>
                        <div class="notif-unread-dot"></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            
            <div class="notif-footer">
                <a href="notifications.php" class="notif-view-all">View All Notifications</a>
            </div>
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

    // 1. Remove old inline CSS block
    $content = preg_replace('/\/\*\s*── Bell ──\s*\*\/.*?(?=<\/style>)/s', '', $content);

    // 2. Remove the old Dropdown and Backdrop from their current location
    $content = preg_replace('/(\s*<!-- Backdrop -->.*?(?=<!-- Detail Modal -->))/s', '', $content);
    $content = preg_replace('/(\s*<!-- Backdrop -->.*?(?=<\/div>\s*<\/header>))/s', '', $content);

    // 3. We need to replace the original Bell Button with our wrapped version
    // The bell button starts with <!-- Notification Bell --> and ends with </button>
    // It is located inside .topbar-right
    if (preg_match('/(<!-- Notification Bell -->.*?<\/button>)/s', $content, $matches)) {
        // Just completely replace the bell with our $htmlBlock, because our $htmlBlock INCLUDES the bell.
        $content = preg_replace('/<!-- Notification Bell -->.*?<\/button>/s', $htmlBlock, $content);
    }
    
    file_put_contents($fp, $content);
}

// Write the CSS block to main.css
file_put_contents('c:/xampp/htdocs/sti-activity-system/assets/css/main.css', "\n" . $cssBlock, FILE_APPEND);

echo "Applied floating popover redesign to all dashboards\n";
?>
