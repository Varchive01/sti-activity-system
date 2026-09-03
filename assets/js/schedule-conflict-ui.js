/**
 * schedule-conflict-ui.js
 *
 * Frontend Schedule Conflict check handler.
 */

window.ScheduleConflictUI = (function () {
  let settings = {};
  let modalInstance = null;
  let selectedAlternative = null;
  let successTimer = null;

  function init(opts) {
    settings = Object.assign({
      baseUrl: '',
      activityId: 0,
      venueId: 'f1_venue',
      dateId: 'f1_event_date',
      startId: 'f1_start_time',
      endId: 'f1_end_time',
      formId: 'proposalForm',
      successMessageId: 'scheduleSuccessMessage'
    }, opts);

    // Bootstrap Modal initialization helper
    const modalEl = document.getElementById('scheduleConflictModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
      modalInstance = new bootstrap.Modal(modalEl, { keyboard: false });
    }

    // Bind event listeners
    const form = document.getElementById(settings.formId);
    if (form) {
      form.addEventListener('submit', handleSubmit);
    }

    // Automatically check on input/change of schedule fields
    const schedFields = [settings.venueId, settings.dateId, settings.startId, settings.endId];
    let debounceTimer = null;
    schedFields.forEach(id => {
      const el = document.getElementById(id);
      if (el) {
        el.addEventListener('change', () => {
          if (debounceTimer) clearTimeout(debounceTimer);
          debounceTimer = setTimeout(checkSchedule, 300);
        });
        if (el.tagName === 'INPUT' && el.type === 'text') {
          el.addEventListener('input', () => {
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(checkSchedule, 1000);
          });
        }
      }
    });

    // Redefine window.nextStep to intercept Step 1 transition
    if (window.nextStep) {
      const originalNextStep = window.nextStep;
      window.nextStep = async function () {
        if (window.currentStep === 1) {
          // Verify fields are valid locally first
          if (typeof validateStep === 'function' && !validateStep(1)) {
            return;
          }

          // Disable Next button and show loading state
          const nextBtn = document.querySelector('#panel-1 .nav-btns button');
          const originalText = nextBtn ? nextBtn.innerHTML : 'Next: Objectives →';
          if (nextBtn) {
            nextBtn.disabled = true;
            nextBtn.innerHTML = 'Checking conflicts...';
          }

          try {
            const hasConflict = await checkSchedule();
            if (hasConflict) {
              return; // block advancing
            }
          } finally {
            if (nextBtn) {
              nextBtn.disabled = false;
              nextBtn.innerHTML = originalText;
            }
          }
        }
        originalNextStep();
      };
    }

    // Set up Suggested Slot Selection handlers
    const useBtn = document.getElementById('scuUseBtn');
    if (useBtn) {
      useBtn.addEventListener('click', () => {
        if (selectedAlternative) {
          document.getElementById(settings.dateId).value = selectedAlternative.date;
          document.getElementById(settings.startId).value = selectedAlternative.start_time;
          document.getElementById(settings.endId).value = selectedAlternative.end_time;
          if (selectedAlternative.venue) {
            const venueEl = document.getElementById(settings.venueId);
            if (venueEl) {
              venueEl.value = selectedAlternative.venue;
            }
          }
          if (modalInstance) {
            modalInstance.hide();
          }
          // Highlight fields briefly
          const fields = [settings.dateId, settings.startId, settings.endId, settings.venueId];
          fields.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
              el.style.transition = 'background 0.5s';
              el.style.background = '#e8f5e9';
              setTimeout(() => el.style.background = '', 1500);
            }
          });
        }
      });
    }
  }

  function showCheckingIndicator(show) {
    let indicator = document.getElementById('scuCheckingIndicator');
    if (show) {
      if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'scuCheckingIndicator';
        indicator.style.cssText = 'font-size:0.8rem; color:#d97706; font-weight:600; display:flex; align-items:center; gap:6px; margin-bottom:6px;';
        indicator.innerHTML = `
          <div class="spinner-border text-warning spinner-border-sm" role="status" style="border: 0.12em solid currentColor; border-right-color: transparent; width: 0.75rem; height: 0.75rem;"></div>
          <span>Checking availability...</span>
        `;
        const dateInput = document.getElementById(settings.dateId);
        if (dateInput && dateInput.parentNode) {
          dateInput.parentNode.insertBefore(indicator, dateInput);
        }
      }
    } else {
      if (indicator) {
        indicator.remove();
      }
    }
  }

  async function checkSchedule() {
    const venue = document.getElementById(settings.venueId)?.value || '';
    const date = document.getElementById(settings.dateId)?.value || '';
    const start = document.getElementById(settings.startId)?.value || '';
    const end = document.getElementById(settings.endId)?.value || '';

    // Guard: ensure all 4 fields are present
    if (!venue || !date || !start || !end) {
      return false;
    }

    // Clear previous success message
    const successMsg = document.getElementById(settings.successMessageId);
    if (successMsg) {
      successMsg.style.display = 'none';
      if (successTimer) clearTimeout(successTimer);
    }

    showCheckingIndicator(true);

    try {
      const response = await fetch(settings.baseUrl + '/api/check-schedule-conflict.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          venue: venue,
          event_date: date,
          start_time: start,
          end_time: end,
          activity_id: settings.activityId
        })
      });

      if (!response.ok) {
        throw new Error('API server returned error ' + response.status);
      }

      const data = await response.json();

      if (data.conflict === true) {
        showConflictModal(data, { venue, date, start, end });
        return true; // conflict exists
      } else {
        // Show success message
        if (successMsg) {
          successMsg.style.display = 'block';
          successTimer = setTimeout(() => {
            successMsg.style.display = 'none';
          }, 4000);
        }
        return false; // no conflict
      }
    } catch (e) {
      console.error('Schedule Conflict check failed:', e);
      // Fallback: don't block workflow on API failure
      return false;
    } finally {
      showCheckingIndicator(false);
    }
  }

  function showConflictModal(data, proposed) {
    // Populate Modal Comparison Fields
    const conf = data.conflicting_activity || {};
    document.getElementById('scuConfTitle').textContent = conf.title || 'Approved Event';
    document.getElementById('scuConfDate').textContent = formatDate(conf.event_date);
    document.getElementById('scuConfTime').textContent = conf.start_time + ' – ' + conf.end_time;
    document.getElementById('scuConfVenue').textContent = conf.venue;

    document.getElementById('scuPropDate').textContent = formatDate(proposed.date);
    document.getElementById('scuPropTime').textContent = formatTime12h(proposed.start) + ' – ' + formatTime12h(proposed.end);
    document.getElementById('scuPropVenue').textContent = proposed.venue;

    // Reset Suggestion UI
    const loadingEl = document.getElementById('scuAiLoading');
    const listEl = document.getElementById('scuAiRecommendationList');
    const useBtn = document.getElementById('scuUseBtn');

    loadingEl.style.display = 'block';
    listEl.style.display = 'none';
    listEl.innerHTML = '';
    if (useBtn) useBtn.disabled = true;
    selectedAlternative = null;

    if (modalInstance) {
      modalInstance.show();
    }

    // Populate alternatives list
    if (data.alternatives && data.alternatives.length > 0) {
      loadingEl.style.display = 'none';
      listEl.style.display = 'flex';
      data.alternatives.forEach((alt, idx) => {
        const item = document.createElement('div');
        item.className = 'p-2 px-3 border rounded mb-2 d-flex justify-content-between align-items-center';
        item.style.cursor = 'pointer';
        item.style.background = '#fff';
        item.style.transition = 'all 0.2s';
        item.style.fontSize = '0.85rem';
        item.innerHTML = `
          <div>
            <div class="fw-bold text-dark" style="font-size:0.85rem;">📅 ${formatDate(alt.date)} &nbsp; ⏰ ${formatTime12h(alt.start_time)} – ${formatTime12h(alt.end_time)}</div>
            <div class="small text-secondary mt-1" style="font-weight: 500;">📍 Venue: ${alt.venue || proposed.venue}</div>
            <div class="text-muted small mt-1" style="font-size: 0.75rem;">💡 ${alt.reason}</div>
          </div>
          <input type="radio" name="scu_alt_selection" value="${idx}" class="form-check-input" style="margin-left: 12px; flex-shrink: 0;">
        `;
        item.addEventListener('click', () => {
          const radio = item.querySelector('input[type="radio"]');
          if (radio) radio.checked = true;
          selectedAlternative = alt;
          if (useBtn) useBtn.disabled = false;
          // Apply active styling
          listEl.querySelectorAll('.border-primary').forEach(el => {
            el.classList.remove('border-primary');
            el.style.background = '#fff';
          });
          item.classList.add('border-primary');
          item.style.background = '#f1f8ff';
        });
        listEl.appendChild(item);
      });
    } else {
      loadingEl.style.display = 'none';
      listEl.style.display = 'flex';
      listEl.innerHTML = `<div class="text-muted text-center py-2" style="font-size:0.8rem;">No alternative slots available. Please edit your schedule manually.</div>`;
    }
  }

  async function handleSubmit(e) {
    // Check if submitting for approval
    const submitter = e.submitter;
    if (submitter && submitter.value === 'submit') {
      if (e.defaultPrevented) {
        return;
      }
      e.preventDefault();

      // Show loader on submit button
      const originalHtml = submitter.innerHTML;
      submitter.disabled = true;
      submitter.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Validating schedule...';

      const hasConflict = await checkSchedule();

      submitter.disabled = false;
      submitter.innerHTML = originalHtml;

      if (!hasConflict) {
        // If ProposalAIValidator is loaded, trigger AI validation
        if (typeof ProposalAIValidator !== 'undefined') {
          ProposalAIValidator.runAIValidation();
        } else {
          // Fallback: Ensure action=submit is included in form submission
          let hiddenAction = e.target.querySelector('input[name="action"][type="hidden"]');
          if (!hiddenAction) {
            hiddenAction = document.createElement('input');
            hiddenAction.type = 'hidden';
            hiddenAction.name = 'action';
            hiddenAction.value = 'submit';
            e.target.appendChild(hiddenAction);
          } else {
            hiddenAction.value = 'submit';
          }
          // Bypass listeners and submit
          e.target.submit();
        }
      }
    }
  }

  // Helpers
  function formatDate(dateStr) {
    if (!dateStr) return '';
    const date = new Date(dateStr + 'T00:00:00');
    return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
  }

  function formatTime12h(timeStr) {
    if (!timeStr) return '';
    const parts = timeStr.split(':');
    let hours = parseInt(parts[0]);
    const minutes = parts[1];
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12; // 0 should be 12
    return hours + ':' + minutes + ' ' + ampm;
  }

  return {
    init: init,
    checkSchedule: checkSchedule
  };
})();
