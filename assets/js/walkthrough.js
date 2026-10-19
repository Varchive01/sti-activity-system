/**
 * STI Guided Walkthrough Foundation
 *
 * Reusable guided tour engine for STI Activity Management System.
 * Compatible with Faculty, Admin1, Admin2, and Dean interfaces.
 * Purely presentation-based, non-destructive, supports light and dark themes.
 */

(function (root, factory) {
  if (typeof define === 'function' && define.amd) {
    define([], factory);
  } else if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.STIWalkthrough = factory();
  }
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  // State
  let currentSteps = [];
  let currentIndex = -1;
  let isRunning = false;
  const registeredStepSets = {};

  // DOM references
  let containerEl = null;
  let svgOverlayEl = null;
  let cutoutRectEl = null;
  let highlightRingEl = null;
  let popoverEl = null;
  let boundKeyHandler = null;
  let boundResizeHandler = null;
  let boundScrollHandler = null;

  // Shared Foundation Walkthrough Steps (Shared shell elements across all roles)
  const defaultFoundationSteps = [
    {
      target: '#sidebar-toggle-btn',
      title: 'Sidebar Navigation Control',
      description: 'Click this button to collapse or expand the navigation bar at any time to give yourself more workspace.'
    },
    {
      target: '.sidebar-nav',
      title: 'Main Navigation Menu',
      description: 'Easily navigate through your role-specific workspaces, review queues, activities, calendars, and reports.'
    },
    {
      target: '.topbar-right, .topbar',
      title: 'Topbar & Quick Tools',
      description: 'Switch between light and dark themes, view recent notifications, and manage your account and profile settings.'
    },
    {
      target: '#sidebar-help-btn',
      title: 'Guided Help & Tours',
      description: 'You can restart this walkthrough or access contextual guidance whenever you need assistance by clicking Help here.'
    }
  ];

  /**
   * Builds the DOM elements for overlay, spotlight highlight, and popover card.
   */
  function buildDOM() {
    if (containerEl && document.body.contains(containerEl)) {
      return;
    }

    // Outer Container
    containerEl = document.createElement('div');
    containerEl.className = 'sti-walkthrough-container';
    containerEl.id = 'sti-walkthrough-container';
    containerEl.setAttribute('role', 'dialog');
    containerEl.setAttribute('aria-modal', 'true');
    containerEl.setAttribute('aria-label', 'Guided Walkthrough');

    // SVG Mask Overlay
    const svgNS = 'http://www.w3.org/2000/svg';
    svgOverlayEl = document.createElementNS(svgNS, 'svg');
    svgOverlayEl.setAttribute('class', 'sti-walkthrough-svg-overlay');

    const defs = document.createElementNS(svgNS, 'defs');
    const mask = document.createElementNS(svgNS, 'mask');
    mask.setAttribute('id', 'sti-walkthrough-mask');

    const maskWhiteBg = document.createElementNS(svgNS, 'rect');
    maskWhiteBg.setAttribute('x', '0');
    maskWhiteBg.setAttribute('y', '0');
    maskWhiteBg.setAttribute('width', '100%');
    maskWhiteBg.setAttribute('height', '100%');
    maskWhiteBg.setAttribute('fill', 'white');

    cutoutRectEl = document.createElementNS(svgNS, 'rect');
    cutoutRectEl.setAttribute('id', 'sti-walkthrough-cutout');
    cutoutRectEl.setAttribute('x', '0');
    cutoutRectEl.setAttribute('y', '0');
    cutoutRectEl.setAttribute('width', '0');
    cutoutRectEl.setAttribute('height', '0');
    cutoutRectEl.setAttribute('rx', '8');
    cutoutRectEl.setAttribute('ry', '8');
    cutoutRectEl.setAttribute('fill', 'black');

    mask.appendChild(maskWhiteBg);
    mask.appendChild(cutoutRectEl);
    defs.appendChild(mask);
    svgOverlayEl.appendChild(defs);

    const maskRect = document.createElementNS(svgNS, 'rect');
    maskRect.setAttribute('x', '0');
    maskRect.setAttribute('y', '0');
    maskRect.setAttribute('width', '100%');
    maskRect.setAttribute('height', '100%');
    maskRect.setAttribute('class', 'sti-walkthrough-mask-rect');
    maskRect.setAttribute('mask', 'url(#sti-walkthrough-mask)');
    svgOverlayEl.appendChild(maskRect);

    // Backdrop click: pulse popover card
    svgOverlayEl.addEventListener('click', function (e) {
      if (popoverEl) {
        popoverEl.classList.remove('pulse');
        void popoverEl.offsetWidth; // trigger reflow
        popoverEl.classList.add('pulse');
      }
    });

    // Highlight Ring
    highlightRingEl = document.createElement('div');
    highlightRingEl.className = 'sti-walkthrough-highlight';
    highlightRingEl.id = 'sti-walkthrough-highlight';

    // Popover Card
    popoverEl = document.createElement('div');
    popoverEl.className = 'sti-walkthrough-popover';
    popoverEl.id = 'sti-walkthrough-popover';

    popoverEl.innerHTML = `
      <div class="sti-walkthrough-header">
        <span class="sti-walkthrough-badge" id="sti-walkthrough-step-badge">Step 1 of 1</span>
        <button type="button" class="sti-walkthrough-close" id="sti-walkthrough-close-btn" aria-label="Close Walkthrough">✕</button>
      </div>
      <div class="sti-walkthrough-body">
        <h4 class="sti-walkthrough-title" id="sti-walkthrough-title">Tour Step</h4>
        <p class="sti-walkthrough-description" id="sti-walkthrough-desc">Tour description</p>
      </div>
      <div class="sti-walkthrough-footer">
        <button type="button" class="sti-walkthrough-btn-skip" id="sti-walkthrough-skip-btn">Skip Tour</button>
        <div class="sti-walkthrough-actions">
          <button type="button" class="sti-walkthrough-btn-prev" id="sti-walkthrough-prev-btn">Previous</button>
          <button type="button" class="sti-walkthrough-btn-next" id="sti-walkthrough-next-btn">Next →</button>
        </div>
      </div>
      <div class="sti-walkthrough-arrow" id="sti-walkthrough-arrow" style="display:none;"></div>
    `;

    containerEl.appendChild(svgOverlayEl);
    containerEl.appendChild(highlightRingEl);
    containerEl.appendChild(popoverEl);
    document.body.appendChild(containerEl);

    // Bind inner button actions
    document.getElementById('sti-walkthrough-close-btn').addEventListener('click', stop);
    document.getElementById('sti-walkthrough-skip-btn').addEventListener('click', stop);
    document.getElementById('sti-walkthrough-prev-btn').addEventListener('click', prev);
    document.getElementById('sti-walkthrough-next-btn').addEventListener('click', next);
  }

  /**
   * Positions spotlight and popover around the target element.
   */
  function updatePosition() {
    if (!isRunning || currentIndex < 0 || currentIndex >= currentSteps.length) {
      return;
    }

    const step = currentSteps[currentIndex];
    let target = null;
    if (step.target) {
      target = document.querySelector(step.target);
    }

    const arrowEl = document.getElementById('sti-walkthrough-arrow');
    const badgeEl = document.getElementById('sti-walkthrough-step-badge');
    const titleEl = document.getElementById('sti-walkthrough-title');
    const descEl = document.getElementById('sti-walkthrough-desc');
    const prevBtn = document.getElementById('sti-walkthrough-prev-btn');
    const nextBtn = document.getElementById('sti-walkthrough-next-btn');

    // Update text
    badgeEl.textContent = `Step ${currentIndex + 1} of ${currentSteps.length}`;
    titleEl.textContent = step.title || '';
    descEl.textContent = step.description || '';

    // Prev Button State
    if (currentIndex === 0) {
      prevBtn.disabled = true;
      prevBtn.style.opacity = '0.4';
      prevBtn.style.cursor = 'not-allowed';
    } else {
      prevBtn.disabled = false;
      prevBtn.style.opacity = '1';
      prevBtn.style.cursor = 'pointer';
    }

    // Next Button State
    if (currentIndex === currentSteps.length - 1) {
      nextBtn.innerHTML = 'Done ✓';
      nextBtn.classList.add('done');
    } else {
      nextBtn.innerHTML = 'Next →';
      nextBtn.classList.remove('done');
    }

    // If target is found on the screen
    if (target && target.getBoundingClientRect) {
      const rect = target.getBoundingClientRect();
      const padding = 8;
      const targetX = Math.max(0, rect.left - padding);
      const targetY = Math.max(0, rect.top - padding);
      const targetW = rect.width + padding * 2;
      const targetH = rect.height + padding * 2;
      const borderRadius = 8;

      // Update SVG Cutout
      cutoutRectEl.setAttribute('x', String(targetX));
      cutoutRectEl.setAttribute('y', String(targetY));
      cutoutRectEl.setAttribute('width', String(targetW));
      cutoutRectEl.setAttribute('height', String(targetH));
      cutoutRectEl.setAttribute('rx', String(borderRadius));
      cutoutRectEl.setAttribute('ry', String(borderRadius));

      // Update Highlight Ring
      highlightRingEl.style.left = `${targetX}px`;
      highlightRingEl.style.top = `${targetY}px`;
      highlightRingEl.style.width = `${targetW}px`;
      highlightRingEl.style.height = `${targetH}px`;
      highlightRingEl.classList.add('visible');

      // Popover Positioning
      popoverEl.classList.add('visible');
      const popoverRect = popoverEl.getBoundingClientRect();
      const popW = popoverRect.width || 360;
      const popH = popoverRect.height || 180;
      const margin = 14;

      const spaceBelow = window.innerHeight - (rect.bottom + padding);
      const spaceAbove = rect.top - padding;
      const spaceRight = window.innerWidth - (rect.right + padding);
      const spaceLeft = rect.left - padding;

      let top = 0;
      let left = 0;
      let placement = 'bottom';

      // Smart placement
      if (spaceBelow >= popH + margin) {
        placement = 'bottom';
        top = rect.bottom + padding + margin;
        left = rect.left + (rect.width / 2) - (popW / 2);
      } else if (spaceAbove >= popH + margin) {
        placement = 'top';
        top = rect.top - padding - popH - margin;
        left = rect.left + (rect.width / 2) - (popW / 2);
      } else if (spaceRight >= popW + margin) {
        placement = 'right';
        top = rect.top + (rect.height / 2) - (popH / 2);
        left = rect.right + padding + margin;
      } else if (spaceLeft >= popW + margin) {
        placement = 'left';
        top = rect.top + (rect.height / 2) - (popH / 2);
        left = rect.left - padding - popW - margin;
      } else {
        // Fallback: center in viewport
        placement = 'center';
        top = (window.innerHeight - popH) / 2;
        left = (window.innerWidth - popW) / 2;
      }

      // Clamp inside viewport
      left = Math.max(16, Math.min(left, window.innerWidth - popW - 16));
      top = Math.max(16, Math.min(top, window.innerHeight - popH - 16));

      popoverEl.style.left = `${left}px`;
      popoverEl.style.top = `${top}px`;
      popoverEl.style.transform = 'scale(1)';

      if (arrowEl) {
        arrowEl.style.display = 'none'; // Clean, minimal look without clipping arrow
      }
    } else {
      // No target found / center modal mode
      cutoutRectEl.setAttribute('x', '0');
      cutoutRectEl.setAttribute('y', '0');
      cutoutRectEl.setAttribute('width', '0');
      cutoutRectEl.setAttribute('height', '0');
      highlightRingEl.classList.remove('visible');

      popoverEl.classList.add('visible');
      const popW = popoverEl.offsetWidth || 360;
      const popH = popoverEl.offsetHeight || 180;
      const left = Math.max(16, (window.innerWidth - popW) / 2);
      const top = Math.max(16, (window.innerHeight - popH) / 2);

      popoverEl.style.left = `${left}px`;
      popoverEl.style.top = `${top}px`;
      popoverEl.style.transform = 'scale(1)';

      if (arrowEl) {
        arrowEl.style.display = 'none';
      }
    }
  }

  /**
   * Navigates to a specific step index.
   */
  function goTo(index) {
    if (!isRunning || !currentSteps || currentSteps.length === 0) {
      return;
    }

    if (index < 0 || index >= currentSteps.length) {
      stop();
      return;
    }

    currentIndex = index;
    const step = currentSteps[currentIndex];

    // Scroll into view if target element exists
    if (step.target) {
      const target = document.querySelector(step.target);
      if (target && typeof target.scrollIntoView === 'function') {
        target.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
      }
    }

    // Update positions and text immediately
    updatePosition();
    requestAnimationFrame(updatePosition);
    // Double check after scroll completes
    setTimeout(updatePosition, 160);
  }

  /**
   * Advances to next step.
   */
  function next() {
    if (currentIndex < currentSteps.length - 1) {
      goTo(currentIndex + 1);
    } else {
      stop();
    }
  }

  /**
   * Navigates to previous step.
   */
  function prev() {
    if (currentIndex > 0) {
      goTo(currentIndex - 1);
    }
  }

  /**
   * Starts the guided walkthrough.
   *
   * @param {Array|string|null} customSteps - Array of step objects, or registered key name, or null for default.
   */
  function start(customSteps) {
    buildDOM();

    let stepsToUse = defaultFoundationSteps;

    if (Array.isArray(customSteps) && customSteps.length > 0) {
      stepsToUse = customSteps;
    } else if (typeof customSteps === 'string' && registeredStepSets[customSteps]) {
      stepsToUse = registeredStepSets[customSteps];
    } else if (
      registeredStepSets['dean'] &&
      ((document.body && document.body.classList.contains('theme-dean')) ||
        document.querySelector('.sidebar.theme-dean'))
    ) {
      stepsToUse = registeredStepSets['dean'];
    } else if (
      registeredStepSets['admin2'] &&
      ((document.body && document.body.classList.contains('theme-ian')) ||
        document.querySelector('.sidebar.theme-ian'))
    ) {
      stepsToUse = registeredStepSets['admin2'];
    } else if (
      registeredStepSets['admin1'] &&
      ((document.body && document.body.classList.contains('theme-arjay')) ||
        document.querySelector('.sidebar.theme-arjay'))
    ) {
      stepsToUse = registeredStepSets['admin1'];
    } else if (
      registeredStepSets['faculty'] &&
      ((document.body && document.body.classList.contains('theme-faculty')) ||
        document.querySelector('.sidebar.theme-faculty'))
    ) {
      stepsToUse = registeredStepSets['faculty'];
    } else if (currentSteps && currentSteps.length > 0) {
      stepsToUse = currentSteps;
    }

    // Skip any target that genuinely does not exist rather than breaking the tour
    const validSteps = stepsToUse.filter(function (s) {
      if (!s.target) return true;
      try {
        const el = document.querySelector(s.target);
        return el !== null && (el.offsetWidth > 0 || el.offsetHeight > 0 || el.getClientRects().length > 0);
      } catch (err) {
        return false;
      }
    });

    if (validSteps.length > 0) {
      stepsToUse = validSteps;
    }

    currentSteps = stepsToUse;
    currentIndex = 0;
    isRunning = true;

    // Show container
    containerEl.classList.add('active');

    // Attach keyboard and viewport listeners
    if (!boundKeyHandler) {
      boundKeyHandler = function (e) {
        if (!isRunning) return;
        if (e.key === 'Escape') {
          e.preventDefault();
          stop();
        } else if (e.key === 'ArrowRight' || e.key === 'Enter') {
          e.preventDefault();
          next();
        } else if (e.key === 'ArrowLeft') {
          e.preventDefault();
          prev();
        }
      };
      window.addEventListener('keydown', boundKeyHandler);
    }

    if (!boundResizeHandler) {
      boundResizeHandler = function () {
        if (isRunning) requestAnimationFrame(updatePosition);
      };
      window.addEventListener('resize', boundResizeHandler);
    }

    if (!boundScrollHandler) {
      boundScrollHandler = function () {
        if (isRunning) requestAnimationFrame(updatePosition);
      };
      window.addEventListener('scroll', boundScrollHandler, { passive: true });
    }

    // Go to first step
    goTo(0);
  }

  /**
   * Stops the walkthrough and completely restores normal page interactions.
   */
  function stop() {
    isRunning = false;
    currentIndex = -1;

    if (containerEl) {
      containerEl.classList.remove('active');
    }
    if (highlightRingEl) {
      highlightRingEl.classList.remove('visible');
    }
    if (popoverEl) {
      popoverEl.classList.remove('visible');
    }
    if (cutoutRectEl) {
      cutoutRectEl.setAttribute('width', '0');
      cutoutRectEl.setAttribute('height', '0');
    }

    // Clean up event listeners
    if (boundKeyHandler) {
      window.removeEventListener('keydown', boundKeyHandler);
      boundKeyHandler = null;
    }
    if (boundResizeHandler) {
      window.removeEventListener('resize', boundResizeHandler);
      boundResizeHandler = null;
    }
    if (boundScrollHandler) {
      window.removeEventListener('scroll', boundScrollHandler);
      boundScrollHandler = null;
    }
  }

  /**
   * Registers a named set of steps for future role or page extensions.
   */
  function registerSteps(key, stepsArray) {
    if (typeof key === 'string' && Array.isArray(stepsArray)) {
      registeredStepSets[key] = stepsArray;
    }
  }

  /**
   * Overrides current steps directly.
   */
  function setSteps(stepsArray) {
    if (Array.isArray(stepsArray)) {
      currentSteps = stepsArray;
    }
  }

  /**
   * Returns current active step definitions.
   */
  function getSteps() {
    return currentSteps.length > 0 ? currentSteps : defaultFoundationSteps;
  }

  /**
   * Checks if walkthrough is currently active.
   */
  function getIsRunning() {
    return isRunning;
  }

  /**
   * Automatically bind to Help button in sidebar when DOM is loaded.
   */
  function initAutoBinding() {
    const bindBtn = function () {
      const helpBtn = document.getElementById('sidebar-help-btn');
      if (helpBtn && !helpBtn.dataset.walkthroughBound) {
        helpBtn.dataset.walkthroughBound = 'true';
        helpBtn.addEventListener('click', function (e) {
          e.preventDefault();
          start();
        });
      }

      // Also support any element with data-action="start-walkthrough"
      document.querySelectorAll('[data-action="start-walkthrough"]').forEach(function (el) {
        if (!el.dataset.walkthroughBound) {
          el.dataset.walkthroughBound = 'true';
          el.addEventListener('click', function (e) {
            e.preventDefault();
            start();
          });
        }
      });
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', bindBtn);
    } else {
      bindBtn();
    }
  }

  // Initialize auto-binding
  initAutoBinding();

  // Public API
  return {
    start: start,
    stop: stop,
    next: next,
    prev: prev,
    goTo: goTo,
    registerSteps: registerSteps,
    setSteps: setSteps,
    getSteps: getSteps,
    isRunning: getIsRunning,
    defaultSteps: defaultFoundationSteps
  };
});
