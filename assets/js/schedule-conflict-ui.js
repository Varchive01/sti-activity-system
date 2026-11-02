/**
 * schedule-conflict-ui.js
 *
 * Frontend Schedule Conflict check handler.
 * Strict Contract: Availability checks are ONLY triggered on user-initiated Event Date changes.
 * Clicking Next or loading the page NEVER triggers an availability API request.
 */

window.ScheduleConflictUI = (function () {
  let settings = {};
  let modalInstance = null;
  let selectedAlternative = null;
  let successTimer = null;
  let currentRequestId = 0;
  let lastCheckedDate = null;
  let cachedKey = null; // Key format: `${date}|${venue}|${start}|${end}|${activityId}`
  let cachedResult = null; // { conflict: boolean, is_preliminary: boolean, availability_confirmed: boolean, data: object, error: boolean, errorMessage: string }
  let inFlightPromise = null;
  let inFlightKey = null;
  let inFlightAbortController = null;
  let debounceTimer = null;

  function getNormalizedDate(dateStr) {
    if (!dateStr || typeof dateStr !== 'string') return '';
    return dateStr.trim();
  }

  function getScheduleKey(date, venue, start, end) {
    return `${date || ''}|${venue || ''}|${start || ''}|${end || ''}|${settings.activityId || 0}`;
  }

  function isValidEventDate(dateStr) {
    if (!dateStr || typeof dateStr !== 'string') return false;
    const trimmed = dateStr.trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) return false;

    const parts = trimmed.split('-');
    const year = parseInt(parts[0], 10);
    const month = parseInt(parts[1], 10);
    const day = parseInt(parts[2], 10);
    if (year < 2020 || year > 2100 || month < 1 || month > 12 || day < 1 || day > 31) {
      return false;
    }

    const d = new Date(year, month - 1, day);
    if (d.getFullYear() !== year || d.getMonth() !== month - 1 || d.getDate() !== day) {
      return false;
    }

    // Check min attribute if present on date input (e.g. must be tomorrow or future)
    const dateEl = document.getElementById(settings.dateId);
    if (dateEl && dateEl.min) {
      if (trimmed < dateEl.min) {
        return false;
      }
    }

    return true;
  }

  function invalidateCache() {
    cachedKey = null;
    cachedResult = null;
  }

  function showCheckingIndicator(show) {
    let indicator = document.getElementById('scuCheckingIndicator');
    if (show) {
      if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'scuCheckingIndicator';
        indicator.style.cssText = 'font-size:0.8rem; color:#d97706; font-weight:600; display:flex; align-items:center; gap:6px; margin-top:4px; margin-bottom:4px;';
        indicator.innerHTML = `
          <div class="spinner-border text-warning spinner-border-sm" role="status" style="border: 0.12em solid currentColor; border-right-color: transparent; width: 0.75rem; height: 0.75rem;"></div>
          <span>Checking availability...</span>
        `;
        const dateInput = document.getElementById(settings.dateId);
        if (dateInput && dateInput.parentNode) {
          dateInput.parentNode.appendChild(indicator);
        }
      }
    } else {
      if (indicator) {
        indicator.remove();
      }
    }
  }

  function showSuccessMessage(msg = 'Schedule is available.') {
    let successMsg = document.getElementById(settings.successMessageId);
    if (!successMsg) {
      successMsg = document.createElement('div');
      successMsg.id = settings.successMessageId;
      successMsg.style.cssText = 'font-size:0.8rem; color:#166534; font-weight:600; display:flex; align-items:center; gap:6px; margin-top:4px; margin-bottom:4px;';
      const dateInput = document.getElementById(settings.dateId);
      if (dateInput && dateInput.parentNode) {
        dateInput.parentNode.appendChild(successMsg);
      }
    }
    successMsg.innerHTML = `<span>✅ ${escapeHtml(msg)}</span>`;
    successMsg.style.display = 'flex';
    if (successTimer) clearTimeout(successTimer);
    successTimer = setTimeout(() => {
      if (successMsg) successMsg.style.display = 'none';
    }, 4000);
  }

  function hideSuccessMessage() {
    const successMsg = document.getElementById(settings.successMessageId);
    if (successMsg) {
      successMsg.style.display = 'none';
    }
    if (successTimer) {
      clearTimeout(successTimer);
      successTimer = null;
    }
  }

  function showPreliminaryMessage(msg = 'Date selected. Complete the venue and event time to check availability.') {
    let prelimMsg = document.getElementById('scuPreliminaryMessage');
    if (!prelimMsg) {
      prelimMsg = document.createElement('div');
      prelimMsg.id = 'scuPreliminaryMessage';
      prelimMsg.style.cssText = 'font-size:0.8rem; color:#475569; font-weight:500; display:flex; align-items:center; gap:6px; margin-top:4px; margin-bottom:4px;';
      const dateInput = document.getElementById(settings.dateId);
      if (dateInput && dateInput.parentNode) {
        dateInput.parentNode.appendChild(prelimMsg);
      }
    }
    prelimMsg.innerHTML = `<span>ℹ️ ${escapeHtml(msg)}</span>`;
    prelimMsg.style.display = 'flex';
  }

  function hidePreliminaryMessage() {
    const prelimMsg = document.getElementById('scuPreliminaryMessage');
    if (prelimMsg) {
      prelimMsg.style.display = 'none';
    }
  }

  function showErrorMessage(msg) {
    let errMsg = document.getElementById('scuErrorMessage');
    if (!errMsg) {
      errMsg = document.createElement('div');
      errMsg.id = 'scuErrorMessage';
      errMsg.style.cssText = 'font-size:0.8rem; color:#dc2626; font-weight:600; display:flex; align-items:center; gap:6px; margin-top:4px; margin-bottom:4px;';
      const dateInput = document.getElementById(settings.dateId);
      if (dateInput && dateInput.parentNode) {
        dateInput.parentNode.appendChild(errMsg);
      }
    }
    errMsg.innerHTML = `<span>⚠️ ${escapeHtml(msg)}</span>`;
    errMsg.style.display = 'flex';
    setTimeout(() => {
      if (errMsg) errMsg.remove();
    }, 6000);
  }

  function hideErrorMessage() {
    const errMsg = document.getElementById('scuErrorMessage');
    if (errMsg) {
      errMsg.remove();
    }
  }

  async function checkScheduleForDate(targetDate, force = false) {
    const normDate = getNormalizedDate(targetDate);
    if (!isValidEventDate(normDate)) {
      showCheckingIndicator(false);
      return null;
    }

    const venue = (document.getElementById(settings.venueId)?.value || '').trim();
    const start = (document.getElementById(settings.startId)?.value || '').trim();
    const end   = (document.getElementById(settings.endId)?.value || '').trim();
    const key = getScheduleKey(normDate, venue, start, end);

    // 1. If already checked and cached for this exact key: reuse cached result
    if (!force && cachedKey === key && cachedResult !== null) {
      if (cachedResult.is_preliminary) {
        hideSuccessMessage();
        showPreliminaryMessage(cachedResult.data?.message || 'Date selected. Complete the venue and event time to check availability.');
      } else if (cachedResult.conflict) {
        hidePreliminaryMessage();
        hideSuccessMessage();
      } else {
        hidePreliminaryMessage();
        showSuccessMessage(cachedResult.data?.message || 'Schedule is available.');
      }
      return cachedResult;
    }

    // 2. If a request for this exact key is ALREADY in-flight: reuse existing promise
    if (inFlightKey === key && inFlightPromise) {
      return await inFlightPromise;
    }

    // 3. If a request for an older/different key was running, abort it
    if (inFlightAbortController) {
      try { inFlightAbortController.abort(); } catch (e) {}
    }

    const abortController = new AbortController();
    inFlightAbortController = abortController;
    inFlightKey = key;
    const thisRequestId = ++currentRequestId;

    hideSuccessMessage();
    hideErrorMessage();
    hidePreliminaryMessage();
    showCheckingIndicator(true);

    const execPromise = (async () => {
      try {
        const response = await fetch(settings.baseUrl + '/api/check-schedule-conflict.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          signal: abortController.signal,
          body: JSON.stringify({
            venue: venue,
            event_date: normDate,
            start_time: start,
            end_time: end,
            activity_id: settings.activityId || 0
          })
        });

        // Stale check: ignore if aborted or superseded by newer request
        if (thisRequestId !== currentRequestId || abortController.signal.aborted) {
          return null;
        }

        // Verify current DOM date hasn't changed to something else while fetch was in flight
        const curDateInDOM = (document.getElementById(settings.dateId)?.value || '').trim();
        if (curDateInDOM !== normDate) {
          return null;
        }

        if (!response.ok) {
          throw new Error('Server returned HTTP ' + response.status);
        }

        const data = await response.json();

        // Second stale check
        if (thisRequestId !== currentRequestId || abortController.signal.aborted) {
          return null;
        }

        lastCheckedDate = normDate;
        cachedKey = key;
        const isPreliminary = (data.is_preliminary === true);
        const isConflict = (data.conflict === true);
        const isConfirmed = (data.availability_confirmed === true);
        cachedResult = {
          conflict: isConflict,
          is_preliminary: isPreliminary,
          availability_confirmed: isConfirmed,
          data: data,
          error: false
        };

        if (isPreliminary) {
          hideSuccessMessage();
          hideErrorMessage();
          showPreliminaryMessage(data.message || 'Date selected. Complete the venue and event time to check availability.');
        } else if (isConflict) {
          hidePreliminaryMessage();
          hideSuccessMessage();
          showConflictModal(data, { venue, date: normDate, start, end });
        } else {
          hidePreliminaryMessage();
          hideErrorMessage();
          showSuccessMessage(data.message || 'Schedule is available.');
        }

        return cachedResult;
      } catch (err) {
        if (err.name === 'AbortError') {
          return null;
        }
        console.error('Schedule availability check failed:', err);
        if (thisRequestId === currentRequestId) {
          cachedKey = key;
          cachedResult = { conflict: false, is_preliminary: false, availability_confirmed: false, error: true, errorMessage: err.message || 'API error' };
          hidePreliminaryMessage();
          hideSuccessMessage();
          showErrorMessage(err.message || 'Failed to check schedule availability.');
          return cachedResult;
        }
        return null;
      } finally {
        if (thisRequestId === currentRequestId) {
          showCheckingIndicator(false);
          inFlightPromise = null;
          inFlightKey = null;
          inFlightAbortController = null;
        }
      }
    })();

    inFlightPromise = execPromise;
    return await execPromise;
  }

  async function checkScheduleDefinitive(venue, date, start, end) {
    const key = getScheduleKey(date, venue, start, end);
    const response = await fetch(settings.baseUrl + '/api/check-schedule-conflict.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        venue: venue,
        event_date: date,
        start_time: start,
        end_time: end,
        activity_id: settings.activityId || 0
      })
    });
    if (!response.ok) {
      throw new Error('Server returned HTTP ' + response.status);
    }
    const data = await response.json();
    cachedKey = key;
    cachedResult = {
      conflict: data.conflict === true,
      is_preliminary: data.is_preliminary === true,
      availability_confirmed: data.availability_confirmed === true,
      data: data,
      error: false
    };
    return cachedResult;
  }

  function handleScheduleFieldChange() {
    invalidateCache();
    hideSuccessMessage();
    hideErrorMessage();
    hidePreliminaryMessage();
  }

  function handleDateInput(immediate = false) {
    const dateEl = document.getElementById(settings.dateId);
    if (!dateEl) return;
    const curVal = (dateEl.value || '').trim();

    // 1. If date has not changed from the last checked/verified date:
    if (curVal === lastCheckedDate && curVal !== '') {
      const curVenue = (document.getElementById(settings.venueId)?.value || '').trim();
      const curStart = (document.getElementById(settings.startId)?.value || '').trim();
      const curEnd   = (document.getElementById(settings.endId)?.value || '').trim();
      const key = getScheduleKey(curVal, curVenue, curStart, curEnd);
      if (cachedKey === key || inFlightKey === key) {
        return;
      }
    }

    // 2. If date changed: invalidate previous cache & hide previous messages
    invalidateCache();
    hideSuccessMessage();
    hideErrorMessage();
    hidePreliminaryMessage();

    // 3. Clear any pending debounce timer
    if (debounceTimer) {
      clearTimeout(debounceTimer);
      debounceTimer = null;
    }

    // 4. If date is invalid, incomplete, or empty:
    if (!isValidEventDate(curVal)) {
      if (inFlightAbortController) {
        try { inFlightAbortController.abort(); } catch (e) {}
        inFlightAbortController = null;
      }
      inFlightPromise = null;
      inFlightKey = null;
      showCheckingIndicator(false);
      return;
    }

    // 5. Date is valid and different: trigger check
    if (immediate) {
      checkScheduleForDate(curVal);
    } else {
      debounceTimer = setTimeout(() => {
        checkScheduleForDate(curVal);
      }, 350);
    }
  }

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

    // Record initial date value on load to prevent unsolicited request on initial load
    const dateEl = document.getElementById(settings.dateId);
    if (dateEl) {
      lastCheckedDate = (dateEl.value || '').trim();
      // Availability check is ONLY triggered when user enters/selects/changes Event Date
      dateEl.addEventListener('change', () => handleDateInput(true));
      dateEl.addEventListener('input', () => handleDateInput(false));
    }

    // Venue, start time, and end time changes invalidate cached availability without initiating new API requests
    ['venueId', 'startId', 'endId'].forEach(fieldKey => {
      const el = document.getElementById(settings[fieldKey]);
      if (el) {
        el.addEventListener('input', handleScheduleFieldChange);
        el.addEventListener('change', handleScheduleFieldChange);
      }
    });

    // Do NOT automatically check availability on page load! (0 requests on load)

    // Redefine window.nextStep to intercept Step 1 transition: NEVER initiate a new request on Next!
    if (window.nextStep) {
      const originalNextStep = window.nextStep;
      window.nextStep = async function () {
        const isStep1 = (typeof currentStep !== 'undefined' && currentStep === 1) ||
                        (window.currentStep === 1) ||
                        (document.getElementById('panel-1') && document.getElementById('panel-1').classList.contains('active'));

        if (!isStep1) {
          originalNextStep();
          return;
        }

        // 1. Verify required fields locally first
        if (typeof validateStep === 'function' && !validateStep(1)) {
          return;
        }

        // 2. If an availability check is already in-flight from a date change, await THAT SAME request
        if (inFlightPromise) {
          const nextBtn = document.querySelector('#panel-1 .nav-btns button.btn-primary') ||
                          document.querySelector('#panel-1 .nav-btns button');
          const originalText = nextBtn ? nextBtn.innerHTML : 'Next: Objectives →';
          if (nextBtn) {
            nextBtn.disabled = true;
            nextBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Checking availability...';
          }
          try {
            const res = await inFlightPromise;
            // Only block if a definitive conflict was found (preliminary results never block)
            if (res && !res.is_preliminary && res.conflict) {
              return; // block advancing
            }
            if (res && res.error) {
              showErrorMessage(res.errorMessage || 'Schedule check failed. Please verify availability before continuing.');
              return; // block advancing on API failure
            }
          } finally {
            if (nextBtn) {
              nextBtn.disabled = false;
              nextBtn.innerHTML = originalText;
            }
          }
          originalNextStep();
          return;
        }

        // 3. If no request is running: NEVER call checkSchedule() or initiate a new request!
        const curDate  = (document.getElementById(settings.dateId)?.value || '').trim();
        const curVenue = (document.getElementById(settings.venueId)?.value || '').trim();
        const curStart = (document.getElementById(settings.startId)?.value || '').trim();
        const curEnd   = (document.getElementById(settings.endId)?.value || '').trim();
        const currentKey = getScheduleKey(curDate, curVenue, curStart, curEnd);

        if (cachedKey === currentKey && cachedResult !== null) {
          // Never block on preliminary date-only results
          if (!cachedResult.is_preliminary && cachedResult.conflict) {
            showConflictModal(cachedResult.data, { venue: curVenue, date: curDate, start: curStart, end: curEnd });
            return; // block advancing
          }
          if (cachedResult.error) {
            showErrorMessage(cachedResult.errorMessage || 'Please verify schedule availability before continuing.');
            return; // block advancing
          }
        }

        // Normal progression without initiating any availability request
        originalNextStep();
      };
    }

    // Set up Suggested Slot Selection handlers
    const useBtn = document.getElementById('scuUseBtn');
    if (useBtn) {
      useBtn.addEventListener('click', () => {
        if (selectedAlternative) {
          const dateInput = document.getElementById(settings.dateId);
          if (dateInput) {
            dateInput.value = selectedAlternative.date;
          }
          const startInput = document.getElementById(settings.startId);
          if (startInput) {
            startInput.value = selectedAlternative.start_time;
          }
          const endInput = document.getElementById(settings.endId);
          if (endInput) {
            endInput.value = selectedAlternative.end_time;
          }
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
          handleDateInput(true);
        }
      });
    }

    // Step 9 Conflict Review button if present
    const step9Btn = document.getElementById('scuStep9ResolveBtn');
    if (step9Btn) {
      step9Btn.addEventListener('click', () => {
        checkSchedule(true);
      });
    }
  }

  async function checkSchedule(force = false) {
    const venue = (document.getElementById(settings.venueId)?.value || '').trim();
    const date  = (document.getElementById(settings.dateId)?.value || '').trim();
    const start = (document.getElementById(settings.startId)?.value || '').trim();
    const end   = (document.getElementById(settings.endId)?.value || '').trim();

    if (!venue || !date || !start || !end || !isValidEventDate(date)) {
      return false;
    }
    const res = await checkScheduleDefinitive(venue, date, start, end);
    return res && res.conflict === true;
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
                <div class="small text-secondary mt-1" style="font-weight: 500;">📍 Venue: ${escapeHtml(alt.venue || propVenue)}</div>
                <div class="text-muted small mt-1" style="font-size: 0.75rem;">💡 ${escapeHtml(alt.reason || 'Available slot')}</div>
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

      const venueEl = document.getElementById(settings.venueId);
      const dateEl  = document.getElementById(settings.dateId);
      const startEl = document.getElementById(settings.startId);
      const endEl   = document.getElementById(settings.endId);

      const venue = (venueEl?.value || '').trim();
      const date  = (dateEl?.value || '').trim();
      const start = (startEl?.value || '').trim();
      const end   = (endEl?.value || '').trim();

      // Show loader on submit button
      const originalHtml = submitter.innerHTML;
      submitter.disabled = true;
      submitter.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Validating schedule...';

      let hasConflict = false;
      let checkFailed = false;

      // Authoritative definitive check if all schedule inputs are present
      if (venue && date && start && end && isValidEventDate(date)) {
        try {
          const res = await checkScheduleDefinitive(venue, date, start, end);
          if (res && res.conflict) {
            hasConflict = true;
            showConflictModal(res.data || res, { venue, date, start, end });
          } else if (res && res.error) {
            checkFailed = true;
            showErrorMessage(res.errorMessage || 'Schedule validation failed. Please check your network connection.');
          }
        } catch (err) {
          checkFailed = true;
          showErrorMessage('Schedule validation failed: ' + (err.message || 'Network error'));
        }
      }

      submitter.disabled = false;
      submitter.innerHTML = originalHtml;

      if (hasConflict || checkFailed) {
        return; // Block submission on conflict or API failure
      }

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
    let hours = parseInt(parts[0], 10);
    const minutes = parts[1];
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12; // 0 should be 12
    return hours + ':' + minutes + ' ' + ampm;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  return {
    init: init,
    checkSchedule: checkSchedule,
    checkScheduleForDate: checkScheduleForDate,
    checkScheduleDefinitive: checkScheduleDefinitive,
    getInFlightPromise: () => inFlightPromise,
    getCachedResult: () => cachedResult,
    getCachedKey: () => cachedKey,
    getLastCheckedDate: () => lastCheckedDate,
    isValidEventDate: isValidEventDate
  };
})();
