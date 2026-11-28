/**
 * Google Calendar-Style Floating Event Details Popup
 * STI-ARMES Activity Management System
 * 
 * Provides an untruncated, dark-themed floating event details popup with
 * smart viewport positioning, rounded corners, icons, and action handlers.
 */
(function() {
  'use strict';

  let activeAnchor = null;
  let activePopupEl = null;

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatStandardTime(timeStr) {
    if (!timeStr) return '';
    const clean = String(timeStr).trim();
    if (!clean || clean === 'N/A' || clean === '—' || clean === '–') return '';
    if (/(AM|PM)$/i.test(clean)) return clean;
    const parts = clean.split(':');
    if (parts.length < 2) return clean;
    let hours = parseInt(parts[0], 10);
    if (isNaN(hours)) return clean;
    const minutes = parts[1].slice(0, 2);
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    return `${hours}:${minutes} ${ampm}`;
  }

  function formatStandardTimeSlot(timeSlot) {
    if (!timeSlot) return '';
    const clean = String(timeSlot).trim();
    if (!clean || clean === '—' || clean === '–' || clean === 'N/A') return '';
    const match = clean.match(/^(.*?)\s*(\-|–|\bto\b)\s*(.*)$/i);
    if (match) {
      const st = formatStandardTime(match[1]);
      const et = formatStandardTime(match[3]);
      if (st && et) return `${st} – ${et}`;
      if (st) return st;
      if (et) return et;
    }
    return formatStandardTime(clean);
  }

  function createPopupElement() {
    let el = document.getElementById('gcalEventPopup');
    if (el) return el;

    el = document.createElement('div');
    el.id = 'gcalEventPopup';
    el.className = 'gcal-event-popup';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.style.display = 'none';

    el.innerHTML = `
      <div class="gcal-popup-topbar">
        <div class="gcal-popup-badge-wrap">
          <span class="gcal-popup-status-chip" id="gcalPopupStatusChip" style="display:none;"></span>
        </div>
        <div class="gcal-popup-actions-top">
          <button type="button" class="gcal-action-btn gcal-action-edit" id="gcalPopupEditTopBtn" title="Edit Event" style="display:none;" aria-label="Edit">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
          </button>
          <button type="button" class="gcal-action-btn gcal-action-delete" id="gcalPopupDeleteTopBtn" title="Delete Event" style="display:none;" aria-label="Delete">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
              <polyline points="3 6 5 6 21 6"/>
              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
            </svg>
          </button>
          <button type="button" class="gcal-action-btn gcal-action-close" id="gcalPopupCloseBtn" title="Close (Esc)" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
              <line x1="18" y1="6" x2="6" y2="18"/>
              <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="gcal-popup-header">
        <div class="gcal-popup-color-marker" id="gcalPopupColorMarker"></div>
        <div class="gcal-popup-header-content">
          <h3 class="gcal-popup-title" id="gcalPopupTitle"></h3>
          <div class="gcal-popup-subtitle" id="gcalPopupSubtitle"></div>
        </div>
      </div>

      <div class="gcal-popup-body">
        <div class="gcal-popup-row" id="gcalPopupDescRow" style="display:none;">
          <div class="gcal-row-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
              <line x1="3" y1="6" x2="21" y2="6"/>
              <line x1="3" y1="12" x2="21" y2="12"/>
              <line x1="3" y1="18" x2="15" y2="18"/>
            </svg>
          </div>
          <div class="gcal-row-content">
            <span class="gcal-row-primary" id="gcalPopupDescText"></span>
          </div>
        </div>

        <div class="gcal-popup-row" id="gcalPopupCategoryRow">
          <div class="gcal-row-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
              <line x1="16" y1="2" x2="16" y2="6"/>
              <line x1="8" y1="2" x2="8" y2="6"/>
              <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
          </div>
          <div class="gcal-row-content">
            <span class="gcal-row-primary" id="gcalPopupCategoryText"></span>
          </div>
        </div>

        <div class="gcal-popup-row" id="gcalPopupVisibilityRow">
          <div class="gcal-row-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
              <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
          </div>
          <div class="gcal-row-content">
            <span class="gcal-row-primary" id="gcalPopupVisibilityText"></span>
          </div>
        </div>

        <div class="gcal-popup-row" id="gcalPopupVenueRow">
          <div class="gcal-row-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
              <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
              <circle cx="12" cy="10" r="3"/>
            </svg>
          </div>
          <div class="gcal-row-content">
            <span class="gcal-row-primary" id="gcalPopupVenueText"></span>
          </div>
        </div>

        <div class="gcal-popup-row" id="gcalPopupProponentRow">
          <div class="gcal-row-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
              <circle cx="12" cy="7" r="4"/>
            </svg>
          </div>
          <div class="gcal-row-content">
            <span class="gcal-row-primary" id="gcalPopupProponentText"></span>
          </div>
        </div>
      </div>

      <div class="gcal-popup-footer" id="gcalPopupFooter">
        <a href="#" id="gcalPopupViewBtn" class="gcal-btn gcal-btn-primary" style="display:none;">View Details</a>
        <button type="button" id="gcalPopupEditBtn" class="gcal-btn gcal-btn-secondary" style="display:none;">Edit</button>
        <button type="button" id="gcalPopupDeleteBtn" class="gcal-btn gcal-btn-danger" style="display:none;">Delete</button>
        <button type="button" id="gcalPopupCloseFooterBtn" class="gcal-btn gcal-btn-ghost">Close</button>
      </div>
    `;

    document.body.appendChild(el);

    // Event listeners
    const closeBtn = el.querySelector('#gcalPopupCloseBtn');
    if (closeBtn) closeBtn.onclick = closeGCalPopup;

    const closeFooterBtn = el.querySelector('#gcalPopupCloseFooterBtn');
    if (closeFooterBtn) closeFooterBtn.onclick = closeGCalPopup;

    return el;
  }

  function positionPopup(anchorRect) {
    const popup = createPopupElement();
    const pw = popup.offsetWidth || 340;
    const ph = popup.offsetHeight || 280;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const margin = 12;

    if (vw <= 640) {
      // Mobile responsive containment
      popup.style.width = 'calc(100vw - 24px)';
      popup.style.maxWidth = '380px';
      popup.style.left = '50%';
      popup.style.right = 'auto';
      popup.style.transform = 'translateX(-50%)';

      // Keep within vertical screen boundaries
      let top = anchorRect.bottom + 8;
      if (top + ph > vh - margin) {
        top = Math.max(margin, anchorRect.top - ph - 8);
      }
      if (top < margin || top + ph > vh) {
        top = Math.max(margin, (vh - ph) / 2);
      }
      popup.style.top = `${top}px`;
      return;
    }

    // Desktop: floating next to anchor
    popup.style.width = '340px';
    popup.style.maxWidth = 'calc(100vw - 24px)';
    popup.style.transform = 'none';

    let left = anchorRect.right + 10;
    if (left + pw > vw - margin) {
      left = anchorRect.left - pw - 10;
    }
    if (left < margin) {
      left = Math.max(margin, (vw - pw) / 2);
    }

    let top = anchorRect.top - 10;
    if (top + ph > vh - margin) {
      top = Math.max(margin, vh - ph - margin);
    }
    if (top < margin) {
      top = margin;
    }

    popup.style.left = `${left}px`;
    popup.style.right = 'auto';
    popup.style.top = `${top}px`;
  }

  function openGCalPopup(anchorEl, data) {
    if (!anchorEl || !data) return;
    activeAnchor = anchorEl;
    const popup = createPopupElement();
    activePopupEl = popup;

    // 1. Title (untruncated, complete title)
    const titleEl = popup.querySelector('#gcalPopupTitle');
    if (titleEl) {
      titleEl.textContent = data.title || 'Untitled Event';
    }

    // 2. Color Marker & Status
    const colorMarker = popup.querySelector('#gcalPopupColorMarker');
    const statusChip = popup.querySelector('#gcalPopupStatusChip');
    const primaryColor = data.color || data.categoryColor || '#22c55e';

    if (colorMarker) {
      colorMarker.style.background = primaryColor;
    }

    if (statusChip) {
      if (data.status) {
        statusChip.style.display = 'inline-block';
        statusChip.textContent = data.status;
        statusChip.style.background = data.statusBg || '';
        statusChip.style.color = data.statusColor || '';
      } else {
        statusChip.style.display = 'none';
      }
    }

    // 3. Subtitle (Formatted Date & Time underneath title: e.g. "Tuesday, December 8 · 8:00 AM – 12:00 PM")
    const subtitleEl = popup.querySelector('#gcalPopupSubtitle');
    if (subtitleEl) {
      let formattedDate = data.date || '';
      try {
        if (data.date && !data.date.includes('to') && !data.date.includes('–')) {
          const dObj = new Date(data.date);
          if (!isNaN(dObj.getTime())) {
            formattedDate = dObj.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' });
          }
        }
      } catch (e) {}

      if (data.endDate && data.endDate !== data.date) {
        formattedDate += ` – ${data.endDate}`;
      }

      const formattedTime = formatStandardTimeSlot(data.time);
      if (formattedTime && formattedTime.trim() && formattedTime !== '—' && formattedTime !== '–') {
        subtitleEl.textContent = `${formattedDate} · ${formattedTime}`;
      } else {
        subtitleEl.textContent = formattedDate || 'Date TBD';
      }
    }

    // 4. Description / Details (Row 1)
    const descRow = popup.querySelector('#gcalPopupDescRow');
    const descText = popup.querySelector('#gcalPopupDescText');
    const descContent = (data.description || data.contribution || data.notes || '').trim();
    if (descRow && descText) {
      if (descContent) {
        descText.textContent = descContent;
        descRow.style.display = 'flex';
      } else {
        descRow.style.display = 'none';
      }
    }

    // 5. Category / Calendar Source (Row 2)
    const catRow = popup.querySelector('#gcalPopupCategoryRow');
    const catText = popup.querySelector('#gcalPopupCategoryText');
    if (catRow && catText) {
      const catVal = data.category || 'General Activity';
      catText.textContent = catVal;
      catRow.style.display = 'flex';
    }

    // 6. Visibility (Row 3)
    const visRow = popup.querySelector('#gcalPopupVisibilityRow');
    const visText = popup.querySelector('#gcalPopupVisibilityText');
    if (visRow && visText) {
      const visVal = data.visibility || 'Public (STI College)';
      visText.textContent = visVal;
      visRow.style.display = 'flex';
    }

    // 7. Venue / Location (Row 4)
    const venueRow = popup.querySelector('#gcalPopupVenueRow');
    const venueText = popup.querySelector('#gcalPopupVenueText');
    if (venueRow && venueText) {
      const venVal = (data.venue || '').trim();
      if (venVal && venVal !== '—') {
        venueText.textContent = venVal;
        venueRow.style.display = 'flex';
      } else {
        venueRow.style.display = 'none';
      }
    }

    // 8. Proponent / Assigned Person (Row 5)
    const propRow = popup.querySelector('#gcalPopupProponentRow');
    const propText = popup.querySelector('#gcalPopupProponentText');
    if (propRow && propText) {
      const propVal = (data.proponent || data.organizer || '').trim();
      if (propVal && propVal !== '—' && propVal !== 'STI College') {
        propText.textContent = propVal;
        propRow.style.display = 'flex';
      } else {
        propRow.style.display = 'none';
      }
    }

    // 9. Actions Configuration
    const viewBtn = popup.querySelector('#gcalPopupViewBtn');
    const editBtn = popup.querySelector('#gcalPopupEditBtn');
    const deleteBtn = popup.querySelector('#gcalPopupDeleteBtn');
    const editTopBtn = popup.querySelector('#gcalPopupEditTopBtn');
    const deleteTopBtn = popup.querySelector('#gcalPopupDeleteTopBtn');

    // Reset buttons
    if (viewBtn) { viewBtn.style.display = 'none'; viewBtn.onclick = null; }
    if (editBtn) { editBtn.style.display = 'none'; editBtn.onclick = null; }
    if (deleteBtn) { deleteBtn.style.display = 'none'; deleteBtn.onclick = null; }
    if (editTopBtn) { editTopBtn.style.display = 'none'; editTopBtn.onclick = null; }
    if (deleteTopBtn) { deleteTopBtn.style.display = 'none'; deleteTopBtn.onclick = null; }

    if (data.viewUrl && viewBtn) {
      viewBtn.href = data.viewUrl;
      viewBtn.style.display = 'inline-flex';
    }

    // Edit Action Handler
    if (data.onEdit || data.editUrl) {
      const handleEdit = function(e) {
        e.stopPropagation();
        closeGCalPopup();
        if (typeof data.onEdit === 'function') {
          data.onEdit();
        } else if (data.editUrl) {
          window.location.href = data.editUrl;
        }
      };
      if (editBtn) {
        editBtn.style.display = 'inline-flex';
        editBtn.onclick = handleEdit;
      }
      if (editTopBtn) {
        editTopBtn.style.display = 'inline-flex';
        editTopBtn.onclick = handleEdit;
      }
    }

    // Delete Action Handler
    if (data.onDelete) {
      const handleDelete = function(e) {
        e.stopPropagation();
        if (confirm('Are you sure you want to delete this event/task?')) {
          closeGCalPopup();
          data.onDelete();
        }
      };
      if (deleteBtn) {
        deleteBtn.style.display = 'inline-flex';
        deleteBtn.onclick = handleDelete;
      }
      if (deleteTopBtn) {
        deleteTopBtn.style.display = 'inline-flex';
        deleteTopBtn.onclick = handleDelete;
      }
    }

    // Show and position
    popup.style.display = 'block';
    const anchorRect = anchorEl.getBoundingClientRect();
    positionPopup(anchorRect);
  }

  function closeGCalPopup() {
    const popup = document.getElementById('gcalEventPopup');
    if (popup) {
      popup.style.display = 'none';
    }
    activeAnchor = null;
    activePopupEl = null;
  }

  // Global document dismiss listeners
  document.addEventListener('click', function(e) {
    if (!activePopupEl || activePopupEl.style.display === 'none') return;
    if (activePopupEl.contains(e.target)) return;
    if (activeAnchor && (activeAnchor === e.target || activeAnchor.contains(e.target))) return;
    closeGCalPopup();
  });

  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' || e.key === 'Esc') {
      closeGCalPopup();
    }
  });

  window.addEventListener('resize', function() {
    if (activeAnchor && activePopupEl && activePopupEl.style.display !== 'none') {
      positionPopup(activeAnchor.getBoundingClientRect());
    }
  });

  // Export functions to global scope
  window.openGCalPopup = openGCalPopup;
  window.closeGCalPopup = closeGCalPopup;
})();
