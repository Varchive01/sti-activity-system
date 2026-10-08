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
  let currentRequestId = 0;

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
        const isStep1 = (typeof currentStep !== 'undefined' && currentStep === 1) ||
                        (window.currentStep === 1) ||
                        (document.getElementById('panel-1') && document.getElementById('panel-1').classList.contains('active'));
        if (isStep1) {
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

    // Bind Step 9 Conflict Review button if present
    const step9Btn = document.getElementById('scuStep9ResolveBtn');
    if (step9Btn) {
      step9Btn.addEventListener('click', () => {
        checkSchedule();
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

    const thisRequestId = ++currentRequestId;

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

      // Discard stale responses: check request sequence ID
      if (thisRequestId !== currentRequestId) {
        return false;
      }

      // Verify that current DOM values still match the values used by this request
      const currentVenue = document.getElementById(settings.venueId)?.value || '';
      const currentDate = document.getElementById(settings.dateId)?.value || '';
      const currentStart = document.getElementById(settings.startId)?.value || '';
      const currentEnd = document.getElementById(settings.endId)?.value || '';

      if (currentVenue !== venue || currentDate !== date || currentStart !== start || currentEnd !== end) {
        return false;
      }

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
      if (thisRequestId === currentRequestId) {
        showCheckingIndicator(false);
      }
    }
  }

  function showConflictModal(data, proposed) {
    if (!modalInstance) {
      const modalEl = document.getElementById('scheduleConflictModal');
      if (modalEl && typeof bootstrap !== 'undefined') {
        modalInstance = new bootstrap.Modal(modalEl, { keyboard: false });
      }
    }

    // Populate Modal Comparison Fields defensively
    const conf = data.conflicting_activity || {};
    const confTitle = conf.title || 'Approved Activity';
    const confDate = formatDate(conf.event_date) || conf.event_date || 'N/A';
    const confTime = (conf.start_time && conf.end_time)
      ? (conf.start_time + ' – ' + conf.end_time)
      : (conf.start_time || 'N/A');
    const confVenue = conf.venue || 'N/A';

    const propDate = formatDate(proposed?.date) || proposed?.date || 'N/A';
    const propTime = (proposed?.start && proposed?.end)
      ? (formatTime12h(proposed.start) + ' – ' + formatTime12h(proposed.end))
      : 'N/A';
    const propVenue = proposed?.venue || 'N/A';

    const elConfTitle = document.getElementById('scuConfTitle');
    const elConfDate  = document.getElementById('scuConfDate');
    const elConfTime  = document.getElementById('scuConfTime');
    const elConfVenue = document.getElementById('scuConfVenue');
    const elPropDate  = document.getElementById('scuPropDate');
    const elPropTime  = document.getElementById('scuPropTime');
    const elPropVenue = document.getElementById('scuPropVenue');

    if (elConfTitle) elConfTitle.textContent = confTitle;
    if (elConfDate)  elConfDate.textContent = confDate;
    if (elConfTime)  elConfTime.textContent = confTime;
    if (elConfVenue) elConfVenue.textContent = confVenue;

    if (elPropDate)  elPropDate.textContent = propDate;
    if (elPropTime)  elPropTime.textContent = propTime;
    if (elPropVenue) elPropVenue.textContent = propVenue;

    // Reset Suggestion UI
    const loadingEl = document.getElementById('scuAiLoading');
    const listEl = document.getElementById('scuAiRecommendationList');
    const useBtn = document.getElementById('scuUseBtn');

    if (useBtn) useBtn.disabled = true;
    selectedAlternative = null;

    if (modalInstance) {
      modalInstance.show();
    }

    // Safety timeout: ensure loading spinner never spins indefinitely
    setTimeout(() => {
      if (loadingEl && loadingEl.style.display !== 'none') {
        loadingEl.style.display = 'none';
        if (listEl && listEl.style.display === 'none') {
          listEl.style.display = 'flex';
          listEl.innerHTML = `<div class="text-muted text-center py-2" style="font-size:0.8rem;">Alternatives check timed out. Please choose an alternate slot manually.</div>`;
        }
      }
    }, 10000);

    // Populate alternatives list with error protection
    try {
      if (data.alternatives && data.alternatives.length > 0) {
        if (loadingEl) loadingEl.style.display = 'none';
        if (listEl) {
          listEl.style.display = 'flex';
          listEl.innerHTML = '';
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
                <div class="small text-secondary mt-1" style="font-weight: 500;">📍 Venue: ${alt.venue || propVenue}</div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">💡 ${alt.reason || 'Available slot'}</div>
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
        }
      } else {
        if (loadingEl) loadingEl.style.display = 'none';
        if (listEl) {
          listEl.style.display = 'flex';
          listEl.innerHTML = `<div class="text-muted text-center py-2" style="font-size:0.8rem;">No alternative slots available. Please edit your schedule manually.</div>`;
        }
      }
    } catch (renderErr) {
      console.error('Error rendering AI alternatives:', renderErr);
      if (loadingEl) loadingEl.style.display = 'none';
      if (listEl) {
        listEl.style.display = 'flex';
        listEl.innerHTML = `<div class="text-muted text-center py-2" style="font-size:0.8rem;">Unable to load alternative slots. Please edit your schedule manually.</div>`;
      }
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
