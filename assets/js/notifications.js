/**
 * notifications.js
 *
 * Shared notification drawer, modal, and API interaction handler.
 * Used across dashboard pages and accessible system-wide.
 */
(function () {
  'use strict';

  function initNotifications() {
    const bell = document.getElementById('notifBellBtn');
    const panel = document.getElementById('notifDropdown');
    const backdrop = document.getElementById('notifBackdrop');
    if (!bell || !panel) {
      return;
    }

    const closeBtn = document.getElementById('notifCloseBtn');
    const markAllBtn = document.getElementById('markAllBtn');
    const deleteAllBtn = document.getElementById('deleteAllBtn');
    const modal = document.getElementById('notifModal');
    const modalClose = document.getElementById('modalClose');
    const modalDismiss = document.getElementById('modalDismissBtn');

    let activeId = null;

    // Resolve Base URL dynamically if not explicitly provided
    const baseUrl = (function () {
      if (typeof window.BASE_URL === 'string' && window.BASE_URL) {
        return window.BASE_URL;
      }
      const script = document.querySelector('script[src*="notifications.js"]');
      if (script && script.src) {
        try {
          const u = new URL(script.src, window.location.href);
          const idx = u.pathname.indexOf('/assets/js/notifications.js');
          if (idx !== -1) {
            return u.origin + u.pathname.substring(0, idx);
          }
        } catch (e) {
          // Fallback
        }
      }
      const match = window.location.pathname.match(/^(.*)\/(faculty|admin1|admin2|dean)\//);
      if (match) {
        return match[1];
      }
      return '';
    })();

    // Resolve user role context for activity view links
    const currentRole = (function () {
      if (typeof window.CURRENT_ROLE === 'string' && window.CURRENT_ROLE) {
        return window.CURRENT_ROLE;
      }
      const pathMatch = window.location.pathname.match(/\/(faculty|admin1|admin2|dean)\//);
      if (pathMatch) {
        return pathMatch[1];
      }
      const body = document.body;
      if (body) {
        if (body.classList.contains('theme-faculty')) return 'faculty';
        if (body.classList.contains('theme-arjay')) return 'admin1';
        if (body.classList.contains('theme-ian')) return 'admin2';
        if (body.classList.contains('theme-dean')) return 'dean';
      }
      return 'faculty';
    })();

    function clampPopoverPosition() {
      if (!panel) return;
      panel.style.transform = '';
      if (typeof window !== 'undefined' && typeof panel.getBoundingClientRect === 'function') {
        const rect = panel.getBoundingClientRect();
        if (rect && typeof rect.right === 'number') {
          if (rect.right > window.innerWidth - 12) {
            const shiftLeft = rect.right - (window.innerWidth - 12);
            panel.style.transform = 'translateX(-' + Math.ceil(shiftLeft) + 'px)';
          } else if (rect.left < 12) {
            const shiftRight = 12 - rect.left;
            panel.style.transform = 'translateX(' + Math.ceil(shiftRight) + 'px)';
          }
        }
      }
    }

    function openPanel() {
      // Close profile popover if open
      const profPopover = document.getElementById('topbarProfilePopover');
      if (profPopover && profPopover.classList.contains('open')) {
        profPopover.classList.remove('open');
        const tp = document.getElementById('topbarProfile');
        if (tp && typeof tp.setAttribute === 'function') tp.setAttribute('aria-expanded', 'false');
      }
      panel.classList.add('open');
      if (typeof bell.setAttribute === 'function') bell.setAttribute('aria-expanded', 'true');
      clampPopoverPosition();
      if (backdrop) backdrop.classList.add('open');
    }

    function closePanel() {
      panel.classList.remove('open');
      if (typeof bell.setAttribute === 'function') bell.setAttribute('aria-expanded', 'false');
      panel.style.transform = '';
      if (backdrop) backdrop.classList.remove('open');
    }

    function openModal() {
      if (modal) modal.classList.add('open');
    }

    function closeModal() {
      if (modal) modal.classList.remove('open');
      activeId = null;
    }

    function formatDate(str) {
      if (!str) return '';
      const d = new Date(str);
      if (isNaN(d.getTime())) return str;
      return d.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric'
      }) + ', ' +
      d.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit'
      });
    }

    function renderEmptyState() {
      const list = document.getElementById('notifList');
      if (list) {
        list.innerHTML = `
          <div class="notif-empty">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-5-5.917V4a1 1 0 10-2 0v1.083A6 6 0 006 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <p>You're all caught up!<br><span style="font-size:.75rem;">No notifications yet.</span></p>
          </div>
        `;
      }
    }

    // Toggle Panel
    bell.addEventListener('click', function (e) {
      e.stopPropagation();
      if (panel.classList.contains('open')) {
        closePanel();
      } else {
        openPanel();
      }
    });

    if (closeBtn) closeBtn.addEventListener('click', closePanel);
    if (backdrop) backdrop.addEventListener('click', closePanel);
    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modalDismiss) modalDismiss.addEventListener('click', closeModal);

    // Outside click closes floating popover
    document.addEventListener('click', function (e) {
      if (panel.classList.contains('open')) {
        if (!panel.contains(e.target) && !bell.contains(e.target)) {
          closePanel();
        }
      }
    });

    // Escape key closes floating popover
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && panel.classList.contains('open')) {
        closePanel();
      }
    });

    if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
      window.addEventListener('resize', function () {
        if (panel.classList.contains('open')) {
          clampPopoverPosition();
        }
      });
    }

    if (modal) {
      modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
      });
    }

    // Mark All As Read
    if (markAllBtn) {
      markAllBtn.addEventListener('click', function () {
        fetch(baseUrl + '/api/notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'mark_all_read' })
        })
          .then(r => r.json())
          .then(d => {
            if (!d.success) return;
            document.querySelectorAll('.notif-item.unread').forEach(el => {
              el.classList.remove('unread');
              el.querySelector('.notif-unread-dot')?.remove();
            });
            document.getElementById('notifBadge')?.remove();
            markAllBtn.style.display = 'none';
            const hspan = document.getElementById('notifCountPill') || panel.querySelector('.notif-header h3 span');
            if (hspan) hspan.remove();
          })
          .catch(err => console.error('Error marking all as read:', err));
      });
    }

    // Delete All
    if (deleteAllBtn) {
      deleteAllBtn.addEventListener('click', function () {
        fetch(baseUrl + '/api/notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete_all' })
        })
          .then(r => r.json())
          .then(d => {
            if (!d.success) return;
            renderEmptyState();
            document.getElementById('notifBadge')?.remove();
            if (markAllBtn) markAllBtn.style.display = 'none';
            deleteAllBtn.style.display = 'none';
            const hspan = document.getElementById('notifCountPill') || panel.querySelector('.notif-header h3 span');
            if (hspan) hspan.remove();
          })
          .catch(err => console.error('Error deleting all notifications:', err));
      });
    }

    // Item Click: Mark read & open modal
    document.querySelectorAll('.notif-item').forEach(item => {
      item.addEventListener('click', function () {
        activeId = this.dataset.id;
        const actId = this.dataset.activityId;
        const actTitle = this.dataset.activityTitle;

        const subjEl = document.getElementById('modalSubject');
        if (subjEl) subjEl.textContent = actTitle || 'Activity Update';

        const timeEl = document.getElementById('modalTime');
        if (timeEl) timeEl.textContent = '@ ' + formatDate(this.dataset.time);

        const iconEl = document.getElementById('modalIconWrap');
        if (iconEl) iconEl.textContent = this.dataset.emoji || '🔔';

        const msgEl = document.getElementById('modalMessage');
        if (msgEl) msgEl.textContent = this.dataset.message || '';

        const viewBtn = document.getElementById('modalViewBtn');
        if (viewBtn) {
          if (actId && actId !== '0') {
            viewBtn.style.display = 'inline-block';
            viewBtn.onclick = function () {
              window.location.href = baseUrl + '/' + currentRole + '/view-activity.php?id=' + encodeURIComponent(actId);
            };
          } else {
            viewBtn.style.display = 'none';
          }
        }

        // Optimistic UI update for unread status
        if (this.classList.contains('unread')) {
          this.classList.remove('unread');
          this.querySelector('.notif-unread-dot')?.remove();
          const badge = document.getElementById('notifBadge');
          if (badge) {
            const count = parseInt(badge.textContent, 10) - 1;
            if (count <= 0) {
              badge.remove();
            } else {
              badge.textContent = count;
            }
          }

          // Background sync
          fetch(baseUrl + '/api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_read', id: activeId })
          }).catch(err => console.error('Error syncing read status:', err));
        }

        closePanel();
        openModal();
      });
    });

    // Delete Single Notification
    const modalDeleteBtn = document.getElementById('modalDeleteBtn');
    if (modalDeleteBtn) {
      modalDeleteBtn.addEventListener('click', function () {
        if (!activeId) return;
        fetch(baseUrl + '/api/notifications.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'delete', id: activeId })
        })
          .then(r => r.json())
          .then(d => {
            if (!d.success) {
              console.error('Delete action returned false');
              return;
            }
            const item = document.querySelector('.notif-item[data-id="' + activeId + '"]');
            if (item) item.remove();

            const list = document.getElementById('notifList');
            if (list && !list.querySelector('.notif-item')) {
              renderEmptyState();
            }
            closeModal();
          })
          .catch(err => console.error('Error deleting notification:', err));
      });
    }
  }

  // Auto-initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNotifications);
  } else {
    initNotifications();
  }
})();

// ============================================================
// Light/Dark Theme Switcher Module (Phase 1B)
// ============================================================
(function () {
  'use strict';

  function getSavedTheme() {
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        var saved = window.localStorage.getItem('sti-theme');
        if (saved === 'dark') return 'dark';
      }
    } catch (e) {}
    return 'light';
  }

  function applyTheme(theme) {
    if (typeof document === 'undefined' || !document.documentElement) return;
    var finalTheme = (theme === 'dark') ? 'dark' : 'light';
    document.documentElement.dataset.theme = finalTheme;
    updateToggleButtons(finalTheme);
  }

  function updateToggleButtons(theme) {
    if (typeof document === 'undefined' || typeof document.querySelectorAll !== 'function') return;
    var btns = document.querySelectorAll('#themeToggleBtn, .theme-toggle-btn');
    if (!btns || typeof btns.forEach !== 'function') return;
    btns.forEach(function (btn) {
      if (theme === 'dark') {
        btn.setAttribute('title', 'Switch to light mode');
        btn.setAttribute('aria-label', 'Switch to light mode');
      } else {
        btn.setAttribute('title', 'Switch to dark mode');
        btn.setAttribute('aria-label', 'Switch to dark mode');
      }
    });
  }

  function toggleTheme(e) {
    if (e && typeof e.stopPropagation === 'function') e.stopPropagation();
    var current = (document.documentElement && document.documentElement.dataset.theme === 'dark') ? 'dark' : 'light';
    var next = (current === 'dark') ? 'light' : 'dark';
    try {
      if (typeof window !== 'undefined' && window.localStorage) {
        window.localStorage.setItem('sti-theme', next);
      }
    } catch (e) {}
    applyTheme(next);
  }

  function initThemeToggle() {
    if (typeof document === 'undefined' || !document.documentElement) return;

    var theme = getSavedTheme();
    applyTheme(theme);

    var toggleBtn = document.getElementById('themeToggleBtn');
    if (!toggleBtn && typeof document.createElement === 'function') {
      var bell = document.getElementById('notifBellBtn');
      if (bell && bell.parentNode) {
        toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'theme-toggle-btn';
        toggleBtn.id = 'themeToggleBtn';
        toggleBtn.setAttribute('title', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
        toggleBtn.setAttribute('aria-label', theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
        toggleBtn.innerHTML =
          '<svg class="theme-icon-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>' +
          '</svg>' +
          '<svg class="theme-icon-sun" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>' +
          '</svg>';
        bell.parentNode.insertBefore(toggleBtn, bell);
      }
    }

    if (toggleBtn) {
      updateToggleButtons(theme);
      toggleBtn.removeEventListener('click', toggleTheme);
      toggleBtn.addEventListener('click', toggleTheme);
    }
  }

  if (typeof document !== 'undefined' && document.documentElement) {
    applyTheme(getSavedTheme());
  }

  if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initThemeToggle);
    } else {
      initThemeToggle();
    }
  }
})();
