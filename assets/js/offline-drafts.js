/**
 * assets/js/offline-drafts.js
 *
 * STI-ARMES Offline Proposal Draft Saving & Automatic Synchronization
 *
 * Features:
 * - Persistent browser-side storage using IndexedDB
 * - Offline draft saving when server is unavailable or network is down
 * - Automatic background synchronization when connectivity returns
 * - Duplicate prevention via server_id and idempotency tracking
 * - Optimistic locking conflict detection (HTTP 409) with user review resolution
 * - Strict restriction: offline drafts are NEVER automatically submitted for approval
 * - Standardized STI-ARMES UI status indicators and local drafts management
 */

(function (window, document) {
  'use strict';

  const DB_NAME = 'sti_armes_offline';
  const DB_VERSION = 1;
  const STORE_NAME = 'proposal_drafts';

  let dbInstance = null;
  let activeLocalDraftId = null;
  let isSyncInProgress = false;
  let syncHeartbeatTimer = null;

  // Retrieve current user metadata and BASE_URL from window / document
  const baseUrl = (window.BASE_URL || (document.querySelector('meta[name="base-url"]') ? document.querySelector('meta[name="base-url"]').getAttribute('content') : '') || '').replace(/\/$/, '');
  const currentUserMeta = document.querySelector('meta[name="current-user"]');
  const currentUserId = currentUserMeta ? parseInt(currentUserMeta.getAttribute('data-id'), 10) : 0;
  const currentUserRole = currentUserMeta ? currentUserMeta.getAttribute('data-role') : '';

  /**
   * 1. IndexedDB Initialization
   */
  function openDB() {
    return new Promise((resolve, reject) => {
      if (dbInstance) {
        return resolve(dbInstance);
      }
      if (!window.indexedDB) {
        console.warn('[OfflineDrafts] IndexedDB is not supported in this browser.');
        return reject(new Error('IndexedDB not supported'));
      }

      const request = window.indexedDB.open(DB_NAME, DB_VERSION);

      request.onupgradeneeded = function (event) {
        const db = event.target.result;
        if (!db.objectStoreNames.contains(STORE_NAME)) {
          const store = db.createObjectStore(STORE_NAME, { keyPath: 'local_id' });
          store.createIndex('server_id', 'server_id', { unique: false });
          store.createIndex('faculty_id', 'faculty_id', { unique: false });
          store.createIndex('sync_status', 'sync_status', { unique: false });
          store.createIndex('last_saved_at', 'last_saved_at', { unique: false });
        }
      };

      request.onsuccess = function (event) {
        dbInstance = event.target.result;
        resolve(dbInstance);
      };

      request.onerror = function (event) {
        console.error('[OfflineDrafts] Failed to open IndexedDB:', event.target.error);
        reject(event.target.error);
      };
    });
  }

  /**
   * 2. IndexedDB CRUD Operations
   */
  async function putDraft(draftObj) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction([STORE_NAME], 'readwrite');
      const store = tx.objectStore(STORE_NAME);
      const req = store.put(draftObj);
      req.onsuccess = () => resolve(draftObj);
      req.onerror = (e) => reject(e.target.error);
    });
  }

  async function getDraft(localId) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction([STORE_NAME], 'readonly');
      const store = tx.objectStore(STORE_NAME);
      const req = store.get(localId);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = (e) => reject(e.target.error);
    });
  }

  async function getDraftByServerId(serverId) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction([STORE_NAME], 'readonly');
      const store = tx.objectStore(STORE_NAME);
      const index = store.index('server_id');
      const req = index.get(parseInt(serverId, 10));
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = (e) => reject(e.target.error);
    });
  }

  async function getAllDrafts() {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction([STORE_NAME], 'readonly');
      const store = tx.objectStore(STORE_NAME);
      const req = store.getAll();
      req.onsuccess = () => {
        const results = req.result || [];
        if (currentUserId > 0) {
          resolve(results.filter(d => parseInt(d.faculty_id, 10) === currentUserId));
        } else {
          resolve(results);
        }
      };
      req.onerror = (e) => reject(e.target.error);
    });
  }

  async function deleteDraft(localId) {
    const db = await openDB();
    return new Promise((resolve, reject) => {
      const tx = db.transaction([STORE_NAME], 'readwrite');
      const store = tx.objectStore(STORE_NAME);
      const req = store.delete(localId);
      req.onsuccess = () => resolve(true);
      req.onerror = (e) => reject(e.target.error);
    });
  }

  /**
   * 3. Form Serialization & Restoration
   */
  function serializeForm(formEl) {
    const data = {};
    const formData = new FormData(formEl);

    // Group multi-value entries as arrays
    for (const [key, value] of formData.entries()) {
      if (value instanceof File) continue;
      if (key.endsWith('[]')) {
        const cleanKey = key.slice(0, -2);
        if (!data[cleanKey]) {
          data[cleanKey] = [];
        }
        data[cleanKey].push(value);
      } else {
        if (data[key] !== undefined) {
          if (!Array.isArray(data[key])) {
            data[key] = [data[key]];
          }
          data[key].push(value);
        } else {
          data[key] = value;
        }
      }
    }

    // Always ensure action is draft
    data['action'] = 'draft';

    // Capture current wizard step progress (Issue 4)
    if (typeof window.currentStep !== 'undefined') {
      data['wizard_current_step'] = window.currentStep;
    }
    if (typeof window.maxUnlockedStep !== 'undefined') {
      data['wizard_max_step'] = window.maxUnlockedStep;
    }

    // Capture evaluation questions array if present in window or hidden field
    if (window.questionsList && Array.isArray(window.questionsList) && window.questionsList.length > 0) {
      data['eval_questions_json'] = JSON.stringify(window.questionsList);
    }

    return data;
  }

  /**
   * Helper: Check whether draft data contains meaningful user entered content
   * (Prevents generating or syncing blank/empty dummy drafts)
   */
  function hasMeaningfulDraftData(formData) {
    if (!formData || typeof formData !== 'object') return false;
    const title = (formData['title'] || formData['activity_title'] || '').trim();
    if (title.length > 0 && title !== 'Untitled Proposal Draft' && title !== 'Untitled Draft') return true;
    for (const key in formData) {
      if (['action', 'faculty_id', 'activity_id', 'proposal_session_token', 'wizard_current_step', 'wizard_max_step', 'csrf_token', 'source', 'staged_collaborators_json', 'eval_questions_json', 'evaluation_questions_json', 'target_participants'].includes(key)) continue;
      const val = formData[key];
      if (Array.isArray(val)) {
        if (val.some(item => item && String(item).trim().length > 0)) return true;
      } else if (val && String(val).trim().length > 0) {
        return true;
      }
    }
    return false;
  }

  function restoreForm(formEl, draftData) {
    if (!formEl || !draftData) return;

    // 1. Pre-expand dynamic rows before setting values
    try {
      const dynamicRowSpecs = [
        { key: 'mat_item', table: 'materials', count: Array.isArray(draftData['mat_item']) ? draftData['mat_item'].length : 0 },
        { key: 'prog_time', table: 'program', count: Array.isArray(draftData['prog_time']) ? draftData['prog_time'].length : 0 },
        { key: 'mp_person', table: 'manpower', count: Array.isArray(draftData['mp_person']) ? draftData['mp_person'].length : 0 },
        { key: 'sched_date', table: 'schedule', count: Array.isArray(draftData['sched_date']) ? draftData['sched_date'].length : 0 },
        { key: 'ft_task', table: 'faculty-tasks', count: Array.isArray(draftData['ft_task']) ? draftData['ft_task'].length : 0 },
        { key: 'kpi_indicator', type: 'kpi', count: Array.isArray(draftData['kpi_indicator']) ? draftData['kpi_indicator'].length : 0 },
        { key: 'part_section', type: 'part_section', count: Array.isArray(draftData['part_section']) ? draftData['part_section'].length : 0 }
      ];

      dynamicRowSpecs.forEach(spec => {
        if (spec.count <= 0) return;
        if (spec.table && typeof window.addRow === 'function') {
          const tbody = document.getElementById(spec.table + '-body');
          if (tbody) {
            const currentRows = tbody.querySelectorAll('tr').length;
            for (let i = currentRows; i < spec.count; i++) {
              window.addRow(spec.table);
            }
          }
        } else if (spec.type === 'kpi' && typeof window.addKpiRow === 'function') {
          const tbody = document.getElementById('kpi-body');
          if (tbody) {
            const currentRows = tbody.querySelectorAll('tr').length;
            for (let i = currentRows; i < spec.count; i++) {
              window.addKpiRow();
            }
          }
        } else if (spec.type === 'part_section' && typeof window.addParticipantSectionRow === 'function') {
          const tbody = document.getElementById('participant-sections-body');
          if (tbody) {
            const currentRows = tbody.querySelectorAll('tr.participant-row').length;
            for (let i = currentRows; i < spec.count; i++) {
              window.addParticipantSectionRow();
            }
          }
        }
      });
    } catch (e) {
      console.warn('[OfflineDrafts] Dynamic row pre-expansion notice:', e);
    }

    // 2. Populate input values
    for (const key in draftData) {
      if (!Object.prototype.hasOwnProperty.call(draftData, key)) continue;
      // Never overwrite the form submission action or wipe an existing server activity_id
      if (key === 'action' || key === 'proposal_session_token' || key === 'eval_questions_json') continue;
      const val = draftData[key];
      if (key === 'activity_id' && (!val || val === '0')) continue;

      // Handle arrays (e.g. kpi_indicator, ft_task, mat_item, etc.)
      if (Array.isArray(val)) {
        const arrayInputs = formEl.querySelectorAll(`[name="${key}[]"]`);
        val.forEach((itemVal, idx) => {
          if (arrayInputs[idx]) {
            arrayInputs[idx].value = itemVal;
          }
        });
        continue;
      }

      // Handle standard inputs
      const input = formEl.elements[key];
      if (!input) continue;

      if (input instanceof RadioNodeList) {
        input.value = val;
      } else if (input.type === 'checkbox') {
        input.checked = Boolean(val && val !== '0');
      } else if (input.type === 'file') {
        continue;
      } else {
        input.value = val;
      }
    }

    // 3. Restore evaluation questionnaire if available
    let evalJson = draftData['eval_questions_json'] || draftData['f10_evaluation_questions'] || draftData['evaluation_questions'];
    if (evalJson) {
      try {
        const parsedQuestions = typeof evalJson === 'string' ? JSON.parse(evalJson) : evalJson;
        if (Array.isArray(parsedQuestions) && parsedQuestions.length > 0) {
          window.questionsList = parsedQuestions;
          if (typeof window.renderQuestions === 'function') {
            window.renderQuestions();
          }
          if (typeof window.serializeEvaluationQuestions === 'function') {
            window.serializeEvaluationQuestions();
          }
          if (typeof window.setEvaluationState === 'function') {
            window.setEvaluationState('READY');
          }
        }
      } catch (e) {
        console.warn('[OfflineDrafts] Evaluation questions restoration notice:', e);
      }
    }

    // 4. Re-initialize Teacher Multi-Select badges for dynamic participant rows (Issue 4)
    try {
      setTimeout(() => {
        document.querySelectorAll('.teacher-multiselect').forEach(container => {
          const hiddenInput = container.querySelector('input.part-teacher-input');
          if (hiddenInput && hiddenInput.value && typeof window.initTeacherMultiSelect === 'function') {
            container._tmsInitialized = false;
            window.initTeacherMultiSelect(container, hiddenInput.value);
          }
        });
      }, 50);
    } catch (tmsErr) {
      console.warn('[OfflineDrafts] Teacher multi-select restoration notice:', tmsErr);
    }

    // 5. Restore Floor Plan notes & radio choices
    try {
      if (typeof draftData['floor_plan_required'] !== 'undefined') {
        const fpRadios = formEl.querySelectorAll('input[name="floor_plan_required"]');
        fpRadios.forEach(r => {
          r.checked = (String(r.value) === String(draftData['floor_plan_required']));
        });
      }
      if (draftData['floor_plan_notes']) {
        const fpNotesEl = formEl.querySelector('[name="floor_plan_notes"]');
        if (fpNotesEl) fpNotesEl.value = draftData['floor_plan_notes'];
      }
    } catch (fpErr) {
      console.warn('[OfflineDrafts] Floor plan restoration notice:', fpErr);
    }

    // 6. Restore Wizard Step Progress (Issue 4)
    try {
      if (draftData['wizard_max_step'] && typeof window.maxUnlockedStep !== 'undefined') {
        const targetMax = Math.max(window.maxUnlockedStep, parseInt(draftData['wizard_max_step'], 10));
        window.maxUnlockedStep = targetMax;
        const maxInput = document.getElementById('maxUnlockedStep');
        if (maxInput) maxInput.value = targetMax;
      }
      if (draftData['wizard_current_step'] && typeof window.showStep === 'function') {
        const targetStep = parseInt(draftData['wizard_current_step'], 10);
        if (targetStep >= 1 && targetStep <= 9) {
          setTimeout(() => {
            window.showStep(targetStep);
          }, 100);
        }
      }
    } catch (stepErr) {
      console.warn('[OfflineDrafts] Wizard step progress restoration notice:', stepErr);
    }

    // 7. Trigger calculations & recalculate summaries
    try {
      if (typeof window.updateTotal === 'function') window.updateTotal();
      if (typeof window.updateManpowerSummary === 'function') window.updateManpowerSummary();
      if (typeof window.updateTotalTargetParticipants === 'function') window.updateTotalTargetParticipants();
      if (typeof window.syncKpiSectionTables === 'function') window.syncKpiSectionTables();
    } catch (e) {
      console.warn('[OfflineDrafts] Summary recalculation notice:', e);
    }
  }

  /**
   * 4. UI Status Badges & Alerts
   */
  const STATUS_CONFIGS = {
    'saved_locally': {
      text: 'Saved Locally',
      className: 'badge-secondary',
      bg: '#64748B',
      icon: '💾',
      showRetry: false
    },
    'pending_sync': {
      text: 'Pending Synchronization',
      className: 'badge-warning',
      bg: '#F59E0B',
      icon: '⏳',
      showRetry: true
    },
    'syncing': {
      text: 'Synchronizing...',
      className: 'badge-primary',
      bg: '#0284C7',
      icon: '🔄',
      showRetry: false
    },
    'synchronized': {
      text: 'Synchronized',
      className: 'badge-success',
      bg: '#10B981',
      icon: '✓',
      showRetry: false
    },
    'sync_failed': {
      text: 'Synchronization Failed',
      className: 'badge-danger',
      bg: '#EF4444',
      icon: '❌',
      showRetry: true
    },
    'conflict_requires_review': {
      text: 'Conflict Requires Review',
      className: 'badge-warning',
      bg: '#D97706',
      icon: '⚠️',
      showRetry: false
    }
  };

  function updateStatusUI(statusKey, message) {
    const badge = document.getElementById('offlineSyncBadge');
    const retryBtn = document.getElementById('btnManualSync');
    if (!badge) return;

    const conf = STATUS_CONFIGS[statusKey] || STATUS_CONFIGS['saved_locally'];
    badge.style.background = conf.bg;
    badge.style.color = '#fff';
    badge.innerHTML = `<span class="sync-icon">${conf.icon}</span> <span class="sync-text">${conf.text}</span>`;
    badge.setAttribute('title', message || conf.text);

    if (retryBtn) {
      retryBtn.style.display = 'none';
    }

    // Update banner if online/offline
    const banner = document.getElementById('offlineAlertBanner');
    if (banner) {
      if (!navigator.onLine) {
        banner.style.display = 'flex';
      } else {
        banner.style.display = 'none';
      }
    }
  }

  function showToast(msg, type = 'info') {
    const existing = document.getElementById('offlineToast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = 'offlineToast';
    toast.style.position = 'fixed';
    toast.style.bottom = '24px';
    toast.style.right = '24px';
    toast.style.padding = '12px 20px';
    toast.style.borderRadius = '8px';
    toast.style.color = '#fff';
    toast.style.fontWeight = '500';
    toast.style.fontSize = '0.88rem';
    toast.style.zIndex = '999999';
    toast.style.boxShadow = '0 6px 16px rgba(0,0,0,0.2)';
    toast.style.transition = 'all 0.3s ease';

    if (type === 'success') toast.style.background = '#10B981';
    else if (type === 'warning') toast.style.background = '#F59E0B';
    else if (type === 'error') toast.style.background = '#EF4444';
    else toast.style.background = '#0284C7';

    toast.innerText = msg;
    document.body.appendChild(toast);

    setTimeout(() => {
      toast.style.opacity = '0';
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  }

  /**
   * 5. Server Authentication and Connectivity Check
   */
  async function checkServerConnection() {
    if (!navigator.onLine) {
      return { connected: false, authenticated: false };
    }
    try {
      const resp = await fetch(baseUrl + '/api/check-auth.php', {
        method: 'GET',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      });
      if (resp.status === 401) {
        return { connected: true, authenticated: false };
      }
      if (resp.ok) {
        const data = await resp.json();
        return { connected: true, authenticated: Boolean(data.authenticated), user: data };
      }
      return { connected: false, authenticated: false };
    } catch (e) {
      return { connected: false, authenticated: false };
    }
  }

  let autoSaveDebounceTimer = null;
  let autoSaveIntervalTimer = null;
  let isFormDirty = false;
  let isSubmitting = false;
  let isNavigatingAway = false;
  let isPromptingUnsaved = false;

  /**
   * 6. Save Draft Synchronously & Locally (Synchronous LocalStorage + IndexedDB)
   */
  function saveDraftSynchronously(formEl, options = {}) {
    if (!formEl) return null;

    const formData = serializeForm(formEl);
    const serverIdVal = formEl.elements['activity_id'] ? parseInt(formEl.elements['activity_id'].value, 10) : null;
    const serverId = (serverIdVal && serverIdVal > 0) ? serverIdVal : null;

    // Guard: Prevent saving completely blank drafts for new proposals
    if (!serverId && !hasMeaningfulDraftData(formData)) {
      return null;
    }

    let localId = activeLocalDraftId;
    if (!localId) {
      if (serverId) {
        localId = 'draft_srv_' + serverId;
      } else {
        localId = 'draft_new_active';
      }
      activeLocalDraftId = localId;
    }

    const draftRecord = {
      local_id: localId,
      server_id: serverId,
      faculty_id: currentUserId || 0,
      user_role: currentUserRole,
      title: formData['title'] || (formData['activity_title'] || 'Untitled Proposal Draft'),
      form_data: formData,
      sync_status: options.sync_status || 'saved_locally',
      sync_error: null,
      created_at: new Date().toISOString(),
      last_saved_at: new Date().toISOString(),
      server_updated_at: formEl.dataset.serverUpdatedAt || null,
      last_sync_attempt: null
    };

    // 1. Synchronously commit full draft payload to localStorage (0ms delay, immune to sudden exit/tab close)
    try {
      const fullDataKey = serverId ? `sti_active_draft_full_srv_${serverId}` : 'sti_active_draft_full_new';
      const metaKey = serverId ? `sti_draft_meta_srv_${serverId}` : 'sti_draft_meta_new_active';
      const emergencyKey = serverId ? `sti_emergency_srv_${serverId}` : 'sti_emergency_new_active';

      const jsonStr = JSON.stringify(draftRecord);
      localStorage.setItem(fullDataKey, jsonStr);
      localStorage.setItem(emergencyKey, jsonStr);
      localStorage.setItem(metaKey, JSON.stringify({
        local_id: localId,
        server_id: serverId,
        title: draftRecord.title,
        last_saved_at: draftRecord.last_saved_at,
        faculty_id: draftRecord.faculty_id
      }));
    } catch (lsErr) {
      console.warn('[OfflineDrafts] Synchronous localStorage backup notice:', lsErr);
    }

    // 2. Non-blocking asynchronous IndexedDB put
    try {
      putDraft(draftRecord).catch(() => {});
    } catch (idbErr) {}

    return draftRecord;
  }

  async function saveDraftLocally(formEl, options = {}) {
    if (!formEl) return null;

    const draftRecord = saveDraftSynchronously(formEl, options);
    if (!draftRecord) return null;

    try {
      const existingDraft = await getDraft(draftRecord.local_id);
      if (existingDraft && existingDraft.created_at) {
        draftRecord.created_at = existingDraft.created_at;
      }
      await putDraft(draftRecord);
    } catch (idbErr) {
      console.warn('[OfflineDrafts] IndexedDB save warning:', idbErr);
    }

    updateStatusUI(draftRecord.sync_status);
    return draftRecord;
  }

  /**
   * 7. Automatic Synchronization Engine
   */
  async function syncDraft(draft) {
    if (!draft) return false;

    // Check offline connectivity first
    if (!navigator.onLine) {
      draft.sync_status = 'saved_locally';
      await putDraft(draft).catch(() => {});
      const formCheck = document.getElementById('proposalForm');
      const isCur = Boolean(formCheck && (
        (draft.server_id && formCheck.elements['activity_id'] && parseInt(formCheck.elements['activity_id'].value, 10) === parseInt(draft.server_id, 10)) ||
        (!draft.server_id && (!formCheck.elements['activity_id'] || !parseInt(formCheck.elements['activity_id'].value, 10)) && activeLocalDraftId === draft.local_id)
      ));
      if (isCur) {
        updateStatusUI('saved_locally', 'Offline: draft saved locally');
      }
      return false;
    }

    let endpoint = baseUrl + '/api/proposal-save.php';
    if (draft.server_id && draft.server_id > 0) {
      endpoint = baseUrl + '/api/proposal-update.php';
    }

    const formEl = document.getElementById('proposalForm');
    let requestBody;
    let requestHeaders = {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    };

    // Multi-draft safety: only use live FormData if the open form matches THIS draft (Issue 4)
    const isFormForThisDraft = Boolean(formEl && (
      (draft.server_id && formEl.elements['activity_id'] && parseInt(formEl.elements['activity_id'].value, 10) === parseInt(draft.server_id, 10)) ||
      (!draft.server_id && (!formEl.elements['activity_id'] || !parseInt(formEl.elements['activity_id'].value, 10)) && activeLocalDraftId === draft.local_id)
    ));

    if (isFormForThisDraft) {
      updateStatusUI('syncing');
      const fd = new FormData(formEl);
      fd.set('action', 'draft');
      fd.set('is_ajax_sync', '1');
      if (draft.server_id && draft.server_id > 0) {
        fd.set('activity_id', draft.server_id.toString());
        if (draft.server_updated_at) {
          fd.set('last_known_updated_at', draft.server_updated_at);
        }
      }
      if (draft.force_overwrite) {
        fd.set('force_overwrite', '1');
      }
      if (window.questionsList && Array.isArray(window.questionsList) && window.questionsList.length > 0) {
        fd.set('evaluation_questions', JSON.stringify(window.questionsList));
      }
      requestBody = fd;
    } else {
      const payload = new URLSearchParams();
      for (const k in draft.form_data) {
        if (!Object.prototype.hasOwnProperty.call(draft.form_data, k)) continue;
        const v = draft.form_data[k];
        if (Array.isArray(v)) {
          v.forEach(item => payload.append(k + '[]', item));
        } else {
          payload.append(k, v);
        }
      }
      payload.set('action', 'draft');
      payload.set('is_ajax_sync', '1');
      if (draft.server_id && draft.server_id > 0) {
        payload.set('activity_id', draft.server_id.toString());
        if (draft.server_updated_at) {
          payload.set('last_known_updated_at', draft.server_updated_at);
        }
      }
      if (draft.force_overwrite) {
        payload.set('force_overwrite', '1');
      }
      requestHeaders['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      requestBody = payload.toString();
    }

    try {
      const resp = await fetch(endpoint, {
        method: 'POST',
        headers: requestHeaders,
        credentials: 'same-origin',
        body: requestBody
      });

      // 401: Session unauthenticated / expired
      if (resp.status === 401) {
        draft.sync_status = 'pending_sync';
        draft.sync_error = 'Session expired. Please log in.';
        await putDraft(draft).catch(() => {});
        if (isFormForThisDraft) {
          updateStatusUI('sync_failed', 'Session expired. Please log in.');
          showToast('⚠️ Please log in to synchronize offline drafts.', 'warning');
        }
        return false;
      }

      // 409 Conflict: Server has newer modifications
      if (resp.status === 409) {
        const confData = await resp.json().catch(() => ({}));
        draft.sync_status = 'conflict_requires_review';
        draft.sync_error = confData.error || 'Server copy was modified more recently.';
        draft.server_updated_at = confData.server_updated_at || draft.server_updated_at;
        await putDraft(draft);

        if (isFormForThisDraft) {
          updateStatusUI('conflict_requires_review');
          showConflictModal(draft, confData);
        }
        return false;
      }

      if (resp.ok) {
        const result = await resp.json();
        if (result.success) {
          const newServerId = parseInt(result.activity_id, 10);
          const wasNewDraft = (!draft.server_id || draft.local_id === 'draft_new_active');

          if (wasNewDraft && newServerId > 0) {
            // Clean up temporary draft_new_active key from storage to prevent zombie duplicates (Issue 4)
            await deleteDraft('draft_new_active');
            try {
              localStorage.removeItem('sti_active_draft_full_new');
              localStorage.removeItem('sti_emergency_new_active');
              localStorage.removeItem('sti_draft_meta_new_active');
            } catch (e) {}

            draft.local_id = 'draft_srv_' + newServerId;
            activeLocalDraftId = draft.local_id;
          }

          draft.server_id = newServerId;
          if (draft.form_data) {
            draft.form_data['activity_id'] = String(newServerId);
          }
          draft.sync_status = 'synchronized';
          draft.sync_error = null;
          draft.server_updated_at = result.updated_at || new Date().toISOString();
          draft.last_sync_attempt = new Date().toISOString();
          delete draft.force_overwrite;
          await putDraft(draft);

          // Update localStorage
          try {
            const fullDataKey = `sti_active_draft_full_srv_${newServerId}`;
            const metaKey = `sti_draft_meta_srv_${newServerId}`;
            localStorage.setItem(fullDataKey, JSON.stringify(draft));
            localStorage.setItem(metaKey, JSON.stringify({
              local_id: draft.local_id,
              server_id: newServerId,
              title: draft.title,
              last_saved_at: draft.last_saved_at,
              faculty_id: draft.faculty_id
            }));
          } catch (e) {}

          if (isFormForThisDraft) {
            updateStatusUI('synchronized');
            showToast('✓ Proposal draft synchronized with server.', 'success');
          }

          // Continuously update client timestamp references to prevent false optimistic locking conflicts
          if (isFormForThisDraft && formEl) {
            if (result.updated_at) {
              formEl.dataset.serverUpdatedAt = result.updated_at;
              const lastKnownInput = formEl.elements['last_known_updated_at'] || document.getElementById('proposalLastKnownUpdatedAt');
              if (lastKnownInput) lastKnownInput.value = result.updated_at;
            }

            const actInput = formEl.elements['activity_id'];
            if (actInput && (!actInput.value || actInput.value === '0')) {
              actInput.value = newServerId;
              formEl.action = baseUrl + '/api/proposal-update.php';
              if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', baseUrl + '/faculty/proposal-edit.php?id=' + newServerId + '&synced=1');
              }
            }
            if (result.source && formEl.elements['source']) {
              formEl.elements['source'].value = result.source;
            }
            if (result.poster_path) {
              const posterErr = document.getElementById('e_poster_file');
              if (posterErr) posterErr.style.display = 'none';
              const posterInput = document.getElementById('f1_poster_file');
              if (posterInput) {
                posterInput.removeAttribute('required');
                posterInput.classList.remove('is-invalid');
              }
              if (typeof window.hasExistingPoster !== 'undefined') {
                window.hasExistingPoster = true;
              }
            }
          }
          return true;
        }
      }

      // Non-ok response
      const errResult = await resp.json().catch(() => ({}));
      console.warn('[OfflineDrafts] Server response not ok:', resp.status, errResult);

      // If the activity was deleted from the server (404) or user lost permission (403), purge the orphaned draft
      if (resp.status === 404 || resp.status === 403 || (errResult && (errResult.error_code === 'not_found' || errResult.error_code === 'access_denied'))) {
        await deleteDraft(draft.local_id).catch(() => {});
        try {
          if (draft.server_id) {
            localStorage.removeItem(`sti_active_draft_full_srv_${draft.server_id}`);
            localStorage.removeItem(`sti_draft_meta_srv_${draft.server_id}`);
            localStorage.removeItem(`sti_emergency_srv_${draft.server_id}`);
          }
        } catch (e) {}
        if (isFormForThisDraft) {
          updateStatusUI('synchronized');
        }
        return true;
      }

      draft.sync_status = 'sync_failed';
      draft.sync_error = errResult.error || `Server returned error (${resp.status})`;
      draft.last_sync_attempt = new Date().toISOString();
      await putDraft(draft);
      if (isFormForThisDraft) {
        updateStatusUI('sync_failed', draft.sync_error);
      }
      return false;

    } catch (netErr) {
      console.warn('[OfflineDrafts] Sync network error:', netErr);
      draft.sync_status = 'saved_locally';
      draft.sync_error = 'Network connection interrupted during sync.';
      draft.last_sync_attempt = new Date().toISOString();
      await putDraft(draft).catch(() => {});
      if (isFormForThisDraft) {
        updateStatusUI('saved_locally', 'Offline. Changes saved locally.');
      }
      return false;
    }
  }

  async function syncAllPendingDrafts() {
    if (isSyncInProgress) return;
    if (!navigator.onLine) return;
    isSyncInProgress = true;

    try {
      const drafts = await getAllDrafts();
      if (!drafts || drafts.length === 0) return;

      for (const d of drafts) {
        // Automatically prune empty drafts with no meaningful content (e.g. blank "Untitled Proposal Draft")
        if (!hasMeaningfulDraftData(d.form_data)) {
          await deleteDraft(d.local_id);
          try {
            if (d.server_id) {
              localStorage.removeItem(`sti_active_draft_full_srv_${d.server_id}`);
              localStorage.removeItem(`sti_draft_meta_srv_${d.server_id}`);
              localStorage.removeItem(`sti_emergency_srv_${d.server_id}`);
            } else {
              localStorage.removeItem('sti_active_draft_full_new');
              localStorage.removeItem('sti_draft_meta_new_active');
              localStorage.removeItem('sti_emergency_new_active');
            }
          } catch (e) {}
          continue;
        }

        // Automatically sync drafts that are pending, saved locally, or previously failed
        if (['pending_sync', 'saved_locally', 'sync_failed'].includes(d.sync_status)) {
          await syncDraft(d);
        }
      }
    } finally {
      isSyncInProgress = false;
    }
  }

  /**
   * 8. Conflict Review Modal
   */
  function showConflictModal(draft, conflictDetails) {
    let modal = document.getElementById('conflictModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'conflictModal';
      modal.style.position = 'fixed';
      modal.style.top = '0';
      modal.style.left = '0';
      modal.style.width = '100%';
      modal.style.height = '100%';
      modal.style.background = 'rgba(0,0,0,0.55)';
      modal.style.zIndex = '999999';
      modal.style.display = 'flex';
      modal.style.alignItems = 'center';
      modal.style.justifyContent = 'center';

      modal.innerHTML = `
        <div style="background:var(--card-bg, #fff);color:var(--text-main, #333);max-width:540px;width:92%;border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,0.3);overflow:hidden;border:1px solid rgba(0,0,0,0.1);">
          <div style="padding:16px 20px;background:#F59E0B;color:#fff;font-weight:700;font-size:1.05rem;display:flex;align-items:center;gap:10px;">
            <span style="font-size:1.25rem;">⚠️</span> Conflict Requires Review
          </div>
          <div style="padding:22px;">
            <p style="margin-bottom:14px;font-size:0.92rem;line-height:1.5;">
              The server version of this proposal was modified by another session or user after your local draft was saved. Automatic sync was paused to prevent accidental data loss.
            </p>
            <div style="background:rgba(0,0,0,0.04);padding:14px;border-radius:8px;margin-bottom:18px;font-size:0.85rem;line-height:1.6;">
              <div><strong>Server Modified:</strong> <span id="conflictServerTime">—</span></div>
              <div><strong>Your Local Draft:</strong> <span id="conflictLocalTime">—</span></div>
              <div><strong>Proposal Title:</strong> <span id="conflictProposalTitle">—</span></div>
            </div>
            <div style="display:flex;flex-direction:column;gap:10px;">
              <button type="button" id="btnResolveKeepLocal" class="btn btn-warning" style="padding:10px 14px;text-align:left;">
                <strong>Keep Local Draft (Overwrite Server)</strong><br>
                <small style="opacity:0.9;">Upload your local offline edits and replace the current server version.</small>
              </button>
              <button type="button" id="btnResolveDiscardLocal" class="btn btn-outline" style="padding:10px 14px;text-align:left;">
                <strong>Discard Local Draft (Load Server Version)</strong><br>
                <small style="opacity:0.8;">Discard uncommitted local edits and reload the newest copy from the server.</small>
              </button>
              <button type="button" id="btnResolveDismiss" class="btn btn-sm" style="align-self:flex-end;margin-top:6px;color:#64748B;">
                Close and Decide Later
              </button>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    }

    const sTimeEl = document.getElementById('conflictServerTime');
    const lTimeEl = document.getElementById('conflictLocalTime');
    const tEl = document.getElementById('conflictProposalTitle');
    if (sTimeEl) sTimeEl.innerText = conflictDetails.server_updated_at || 'Recently';
    if (lTimeEl) lTimeEl.innerText = new Date(draft.last_saved_at).toLocaleString();
    if (tEl) tEl.innerText = draft.title || 'Untitled Proposal';

    modal.style.display = 'flex';

    document.getElementById('btnResolveKeepLocal').onclick = async () => {
      modal.style.display = 'none';
      draft.force_overwrite = true;
      draft.sync_status = 'pending_sync';
      await putDraft(draft);
      await syncDraft(draft);
    };

    document.getElementById('btnResolveDiscardLocal').onclick = async () => {
      modal.style.display = 'none';
      await deleteDraft(draft.local_id);
      showToast('Local draft discarded. Reloading server version...', 'info');
      setTimeout(() => window.location.reload(), 1000);
    };

    document.getElementById('btnResolveDismiss').onclick = () => {
      modal.style.display = 'none';
    };
  }

  /**
   * 9. Render Local Drafts Widget (Disabled in favor of 100% automatic background synchronization)
   */
  async function renderLocalDraftsWidget(containerId = 'localDraftsContainer') {
    const container = document.getElementById(containerId);
    if (container) {
      container.style.display = 'none';
      container.innerHTML = '';
    }
    // Automatically synchronize any pending drafts in background without manual clicks
    if (navigator.onLine && !isSyncInProgress) {
      syncAllPendingDrafts().catch(() => {});
    }
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

  /**
   * 10. Form Page Initialization (proposal-create & proposal-edit)
   */
  async function initProposalForm() {
    const formEl = document.getElementById('proposalForm');
    if (!formEl) return;

    // Determine if editing existing server activity
    const actInput = formEl.elements['activity_id'];
    const serverId = actInput && parseInt(actInput.value, 10) > 0 ? parseInt(actInput.value, 10) : null;

    // Check URL parameters for explicit local_id
    const urlParams = new URLSearchParams(window.location.search);
    const paramLocalId = urlParams.get('local_id');

    let matchingDraft = null;
    if (paramLocalId) {
      matchingDraft = await getDraft(paramLocalId);
    } else if (serverId) {
      matchingDraft = await getDraftByServerId(serverId);
      // Fallback to localStorage if IndexedDB has nothing
      if (!matchingDraft) {
        try {
          const fullDataKey = `sti_active_draft_full_srv_${serverId}`;
          const raw = localStorage.getItem(fullDataKey) || localStorage.getItem(`sti_emergency_srv_${serverId}`);
          if (raw) matchingDraft = JSON.parse(raw);
        } catch (e) {}
      }
    } else {
      // On new proposal creation without parameters, look for active local draft in IndexedDB
      matchingDraft = await getDraft('draft_new_active');

      // Check localStorage for the newest active draft
      try {
        const raw = localStorage.getItem('sti_active_draft_full_new') || localStorage.getItem('sti_emergency_new_active');
        if (raw) {
          const lsDraft = JSON.parse(raw);
          if (lsDraft && lsDraft.form_data) {
            const lsTime = new Date(lsDraft.last_saved_at || 0).getTime();
            const idbTime = matchingDraft ? new Date(matchingDraft.last_saved_at || 0).getTime() : 0;
            if (!matchingDraft || lsTime >= idbTime) {
              matchingDraft = lsDraft;
              // Sync back into IndexedDB so it's consistent
              await putDraft(lsDraft);
            }
          }
        }
      } catch (e) {}

      if (matchingDraft && matchingDraft.faculty_id && currentUserId > 0 && matchingDraft.faculty_id !== currentUserId) {
        // Belong to another user session
        matchingDraft = null;
      }
    }

    if (matchingDraft && matchingDraft.form_data) {
      activeLocalDraftId = matchingDraft.local_id;

      // On initial load of an existing server proposal, initialize with Synchronized (or Saved Locally if offline)
      if (serverId) {
        updateStatusUI(navigator.onLine ? 'synchronized' : 'saved_locally');
        if (matchingDraft.sync_status === 'sync_failed') {
          matchingDraft.sync_status = navigator.onLine ? 'synchronized' : 'saved_locally';
          matchingDraft.sync_error = null;
          await putDraft(matchingDraft).catch(() => {});
        }
      } else {
        updateStatusUI(matchingDraft.sync_status === 'synchronized' ? 'synchronized' : (navigator.onLine ? 'synchronized' : 'saved_locally'));
      }

      const hasContent = hasMeaningfulDraftData(matchingDraft.form_data);
      const isSubmissionError = urlParams.has('error');

      if (hasContent) {
        // Automatically restore entered information and wizard step progress without manual button clicks
        restoreForm(formEl, matchingDraft.form_data);
        if (matchingDraft.server_id && parseInt(matchingDraft.server_id, 10) > 0) {
          const actInput = formEl.elements['activity_id'] || document.getElementById('proposalActivityId');
          if (actInput) actInput.value = matchingDraft.server_id;
          formEl.action = baseUrl + '/api/proposal-update.php';
        }
        isFormDirty = false;

        if (isSubmissionError) {
          showToast('⚠️ Submission interrupted. Your progress and entered inputs were safely preserved.', 'warning');
        } else {
          showToast('✓ Auto-saved proposal progress automatically restored.', 'info');
        }

        // Automatically synchronize with server in the background if online and there are genuinely pending un-synced edits
        if (navigator.onLine && ['pending_sync'].includes(matchingDraft.sync_status)) {
          syncDraft(matchingDraft).catch(() => {});
        }
      } else {
        // Blank or meaningless draft (e.g. untouched "Untitled Proposal Draft"): prune immediately
        deleteDraft(matchingDraft.local_id).catch(() => {});
        try {
          if (matchingDraft.server_id) {
            localStorage.removeItem(`sti_active_draft_full_srv_${matchingDraft.server_id}`);
            localStorage.removeItem(`sti_draft_meta_srv_${matchingDraft.server_id}`);
            localStorage.removeItem(`sti_emergency_srv_${matchingDraft.server_id}`);
          } else {
            localStorage.removeItem('sti_active_draft_full_new');
            localStorage.removeItem('sti_draft_meta_new_active');
            localStorage.removeItem('sti_emergency_new_active');
          }
        } catch (e) {}
        activeLocalDraftId = null;
      }
    } else {
      updateStatusUI(navigator.onLine ? 'synchronized' : 'saved_locally');
    }

    let autoSyncDebounceTimer = null;

    // Debounced automatic draft saver
    function triggerAutoSave() {
      if (isSubmitting) return;
      isFormDirty = true;

      // 1. Immediately snapshot to localStorage synchronously (0ms delay, rock-solid persistence)
      saveDraftSynchronously(formEl, { sync_status: navigator.onLine ? 'syncing' : 'saved_locally' });
      updateStatusUI(navigator.onLine ? 'syncing' : 'saved_locally', navigator.onLine ? 'Auto-saving...' : 'Draft saved locally');

      // 2. Debounce heavier IndexedDB commit
      if (autoSaveDebounceTimer) clearTimeout(autoSaveDebounceTimer);
      autoSaveDebounceTimer = setTimeout(async () => {
        try {
          const rec = await saveDraftLocally(formEl, { sync_status: navigator.onLine ? 'pending_sync' : 'saved_locally' });
          if (!navigator.onLine) {
            updateStatusUI('saved_locally', 'Offline: draft saved locally');
          }
        } catch (err) {
          console.warn('[OfflineDrafts] Auto-save error:', err);
        }
      }, 800);

      // 3. Debounce automatic server sync in background (2000ms after user pauses typing)
      if (autoSyncDebounceTimer) clearTimeout(autoSyncDebounceTimer);
      if (navigator.onLine) {
        autoSyncDebounceTimer = setTimeout(async () => {
          if (isSubmitting) return;
          const currentDraft = await getDraft(activeLocalDraftId || (serverId ? 'draft_srv_' + serverId : 'draft_new_active'));
          if (currentDraft && hasMeaningfulDraftData(currentDraft.form_data)) {
            await syncDraft(currentDraft);
          }
        }, 2000);
      }
    }

    // Attach input and change listeners for continuous auto-save
    formEl.addEventListener('input', triggerAutoSave);
    formEl.addEventListener('change', triggerAutoSave);

    // Automatically trigger draft save & background sync when navigating wizard steps
    document.querySelectorAll('#nextStepBtn, #prevStepBtn, .step-item, .wizard-step-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        setTimeout(triggerAutoSave, 50);
      });
    });

    // Periodic 30-second recurring background backup when form is modified
    if (autoSaveIntervalTimer) clearInterval(autoSaveIntervalTimer);
    autoSaveIntervalTimer = setInterval(async () => {
      if (isFormDirty && !isSubmitting) {
        try {
          await saveDraftLocally(formEl, { sync_status: 'saved_locally' });
        } catch (e) {}
      }
    }, 30000);

    // Accidental Navigation Guard (beforeunload)
    // Retains native browser protection for refresh (F5 / Ctrl+R / reload button) and tab/window closing
    window.addEventListener('beforeunload', function (e) {
      if (isFormDirty && !isSubmitting && !isNavigatingAway) {
        saveDraftSynchronously(formEl, { sync_status: 'saved_locally' });
        const msg = 'You have unsaved changes in your proposal. Your draft has been auto-saved locally.';
        e.preventDefault();
        e.returnValue = msg;
        return msg;
      }
    });

    // Visibility change (fires when user minimizes browser, switches tabs, or switches apps on mobile)
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden' && isFormDirty && !isSubmitting) {
        saveDraftSynchronously(formEl, { sync_status: 'saved_locally' });
      }
    });

    // Pagehide (standard HTML5 lifecycle event on navigation away or closure)
    window.addEventListener('pagehide', function () {
      if (isFormDirty && !isSubmitting) {
        saveDraftSynchronously(formEl, { sync_status: 'saved_locally' });
      }
    });

    // Intercept internal navigation links (sidebar links, topbar links, breadcrumbs, logo) using capture phase
    // Replaces Chrome's native dialog with custom responsive STI-ARMES confirmation modal
    document.addEventListener('click', async function (e) {
      if (!isFormDirty || isSubmitting || isNavigatingAway) return;

      const link = e.target.closest('a[href]');
      if (!link) return;

      const href = link.getAttribute('href');
      // Ignore anchors, javascript links, target="_blank", downloads, modals, and protocols
      if (!href || href.startsWith('#') || href.startsWith('javascript:') || link.target === '_blank' || link.hasAttribute('download') || href.startsWith('mailto:') || href.startsWith('tel:') || link.closest('#conflictModal') || link.closest('.sti-sys-modal-card')) return;

      // Intercept navigation to prevent Chrome's native beforeunload dialog
      e.preventDefault();
      e.stopPropagation();

      if (isPromptingUnsaved) return;
      isPromptingUnsaved = true;

      // Synchronously backup latest changes to localStorage
      saveDraftSynchronously(formEl, { sync_status: 'saved_locally' });

      const targetUrl = link.href;

      let confirmed = false;
      const modalMsg = 'You have unsaved changes in your proposal.\n\nYour progress has been auto-saved locally as a draft, so you can safely resume it later.\n\nAre you sure you want to leave this page?';
      const modalOpts = {
        title: 'Unsaved Changes',
        icon: '💾',
        variant: 'warning',
        confirmText: 'Leave Page',
        cancelText: 'Stay & Continue Editing',
        confirmBtnClass: 'sti-sys-btn-warning',
        focusCancel: true
      };

      try {
        if (window.STIModal && typeof window.STIModal.confirmUnsavedChanges === 'function') {
          confirmed = await window.STIModal.confirmUnsavedChanges({
            message: modalMsg,
            confirmText: modalOpts.confirmText,
            cancelText: modalOpts.cancelText
          });
        } else if (window.STIModal && typeof window.STIModal.confirm === 'function') {
          confirmed = await window.STIModal.confirm(modalMsg, modalOpts);
        } else {
          confirmed = window.confirm('You have unsaved changes in your proposal.\n\nYour progress has been auto-saved locally as a draft.\n\nClick OK to leave this page, or Cancel to stay and continue editing.');
        }
      } catch (promptErr) {
        console.warn('[OfflineDrafts] Custom unsaved changes modal warning:', promptErr);
        confirmed = false;
      } finally {
        isPromptingUnsaved = false;
      }

      if (confirmed) {
        // User confirmed navigation: mark navigating away so beforeunload does NOT trigger Chrome's native prompt
        isNavigatingAway = true;
        saveDraftSynchronously(formEl, { sync_status: 'saved_locally' });
        window.location.href = targetUrl;
      } else {
        // User chose to stay: all form contents, unsaved edits, and isFormDirty remain 100% untouched!
        showToast('✓ You stayed on the page. All unsaved changes are preserved.', 'info');
      }
    }, true);

    // Mark submitting when form submits intentionally
    formEl.addEventListener('submit', function () {
      isSubmitting = true;
      isFormDirty = false;
    });

    // Attach Save as Draft offline interception
    const draftBtns = formEl.querySelectorAll('button[name="action"][value="draft"], button[type="submit"][value="draft"]');
    draftBtns.forEach(btn => {
      btn.addEventListener('click', async function (e) {
        // If offline: save locally immediately and prevent HTTP submission error
        if (!navigator.onLine) {
          e.preventDefault();
          e.stopPropagation();

          const savedDraft = await saveDraftLocally(formEl, { sync_status: 'pending_sync' });
          showToast('📡 You are offline. Proposal draft saved locally and queued for synchronization.', 'warning');
          updateStatusUI('pending_sync', 'Offline. Saved locally.');
          return false;
        }

        // If online: attempt AJAX save to preserve single-page editing flow with IndexedDB backup
        e.preventDefault();
        e.stopPropagation();

        btn.disabled = true;
        const origText = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving...';

        try {
          const localRecord = await saveDraftLocally(formEl, { sync_status: 'syncing' });
          const syncSuccess = await syncDraft(localRecord);
          if (syncSuccess) {
            updateStatusUI('synchronized');
          } else {
            updateStatusUI('pending_sync');
          }
        } catch (err) {
          console.warn('[OfflineDrafts] Online draft save failed, backed up to IndexedDB:', err);
          await saveDraftLocally(formEl, { sync_status: 'pending_sync' });
          showToast('⚠️ Server unreachable. Draft saved locally in offline storage.', 'warning');
          updateStatusUI('pending_sync');
        } finally {
          btn.disabled = false;
          btn.innerHTML = origText;
        }
        return false;
      });
    });

    // Attach Submit for Approval offline guard
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
      submitBtn.addEventListener('click', function (e) {
        if (!navigator.onLine) {
          e.preventDefault();
          e.stopPropagation();
          const offlineAlertMsg = "Offline Mode: Proposals cannot be submitted for formal review while offline.\n\nPlease connect to the internet to submit. You may continue editing and click 'Save as Draft' to store your work locally.";
          if (window.STIModal) {
            window.STIModal.alert(offlineAlertMsg, { title: 'Offline Mode', variant: 'warning', icon: '📡' });
          } else {
            alert("⚠️ " + offlineAlertMsg);
          }
          return false;
        }
      });
    }

    // Ensure any legacy manual sync button is completely removed
    const manualSyncBtn = document.getElementById('btnManualSync');
    if (manualSyncBtn) {
      manualSyncBtn.remove();
    }

    // Attach click handler on status badge to re-trigger automatic sync on click if ever needed
    const syncBadge = document.getElementById('offlineSyncBadge');
    if (syncBadge && !syncBadge._syncAttached) {
      syncBadge._syncAttached = true;
      syncBadge.style.cursor = 'pointer';
      syncBadge.addEventListener('click', async () => {
        if (!navigator.onLine) {
          showToast('📡 You are offline. Changes are saved locally on your device.', 'info');
          return;
        }
        updateStatusUI('syncing', 'Synchronizing with server...');
        const curDraft = await getDraft(activeLocalDraftId || (serverId ? 'draft_srv_' + serverId : 'draft_new_active'));
        if (curDraft) {
          curDraft.force_overwrite = true;
          const ok = await syncDraft(curDraft);
          if (ok) updateStatusUI('synchronized');
        } else {
          await syncAllPendingDrafts();
        }
      });
    }
  }

  /**
   * 11. Event Listeners for Online/Offline transitions
   */
  window.addEventListener('online', async function () {
    console.log('[OfflineDrafts] Network connection restored. Initiating automatic synchronization...');
    updateStatusUI('syncing', 'Reconnected! Syncing drafts...');
    showToast('🌐 Internet connection restored. Synchronizing pending drafts...', 'info');

    // Run automatic sync in background
    await syncAllPendingDrafts();
  });

  window.addEventListener('offline', function () {
    console.log('[OfflineDrafts] Network connection lost. Offline draft mode engaged.');
    updateStatusUI('saved_locally', 'Offline Mode');
    showToast('📡 You are currently offline. Local draft storage active.', 'warning');
  });

  // Periodic heartbeat when tab is active (every 20s)
  function startSyncHeartbeat() {
    if (syncHeartbeatTimer) clearInterval(syncHeartbeatTimer);
    syncHeartbeatTimer = setInterval(async () => {
      if (navigator.onLine && !isSyncInProgress) {
        const drafts = await getAllDrafts();
        const hasPending = drafts.some(d => ['pending_sync', 'saved_locally', 'sync_failed'].includes(d.sync_status));
        if (hasPending) {
          await syncAllPendingDrafts();
        }
      }
    }, 20000);
  }

  // DOM Ready bootstrap
  document.addEventListener('DOMContentLoaded', async function () {
    try {
      await openDB();
      await initProposalForm();
      if (navigator.onLine) {
        await syncAllPendingDrafts();
      }
      startSyncHeartbeat();
    } catch (e) {
      console.warn('[OfflineDrafts] Initialization notice:', e);
    }
  });

  async function clearDraftForForm(formEl) {
    try {
      if (activeLocalDraftId) {
        await deleteDraft(activeLocalDraftId);
      }
      await deleteDraft('draft_new_active');
      try {
        localStorage.removeItem('sti_active_draft_full_new');
        localStorage.removeItem('sti_draft_meta_new_active');
        localStorage.removeItem('sti_emergency_new_active');
      } catch (e) {}

      if (formEl && formEl.elements['activity_id']) {
        const srvId = parseInt(formEl.elements['activity_id'].value, 10);
        if (srvId > 0) {
          await deleteDraft('draft_srv_' + srvId);
          try {
            localStorage.removeItem(`sti_active_draft_full_srv_${srvId}`);
            localStorage.removeItem(`sti_draft_meta_srv_${srvId}`);
            localStorage.removeItem(`sti_emergency_srv_${srvId}`);
          } catch (e) {}
        }
      }
      activeLocalDraftId = null;
      isFormDirty = false;
    } catch (e) {
      console.warn('[OfflineDrafts] clearDraftForForm warning:', e);
    }
  }

  // Expose module methods to window
  window.OfflineDraftManager = {
    openDB,
    saveDraftLocally,
    saveDraftSynchronously,
    getDraft,
    getAllDrafts,
    deleteDraft,
    clearDraftForForm,
    syncDraft,
    syncAllPendingDrafts,
    renderLocalDraftsWidget,
    checkServerConnection,
    updateStatusUI
  };

  /**
   * 12. Universal Real-Time Client-Side Auto-Capitalization & Name Autocorrect
   *
   * Automatically corrects:
   * - Common lowercase names such as "john" -> "John"
   * - Title / Name fields (organizer, pic, person, teacher, etc.) capitalize each word
   * - General text/textarea sentences: capitalize sentence starters and post-punctuation
   */
  function autoCapitalizeValue(val, isNameField) {
    if (!val || typeof val !== 'string') return val;

    // 1. Correct specific lowercase names (e.g., \bjohn\b -> John)
    let res = val.replace(/\bjohn\b/gi, 'John');

    if (isNameField) {
      // Capitalize first letter of each word/name segment
      res = res.replace(/(?:^|[\s\/\-\.])\p{L}/gu, function(char) {
        return char.toUpperCase();
      });
    } else {
      // Capitalize first letter of the string (ignoring quotes/bullets)
      res = res.replace(/^(\s*["'“‘(\[-]*)(\p{L})/u, function(m, p, c) {
        return p + c.toUpperCase();
      });
      // Capitalize after terminal punctuation (. ! ?) followed by whitespace
      res = res.replace(/([.?!]\s+["'“‘(\[-]*)(\p{L})/gu, function(m, p, c) {
        return p + c.toUpperCase();
      });
      // Capitalize after newlines/bullet points
      res = res.replace(/([\r\n]+\s*[-*•\d.)\]]*\s*["'“‘(\[-]*)(\p{L})/gu, function(m, p, c) {
        return p + c.toUpperCase();
      });
    }
    return res;
  }

  function handleAutoCapitalizeTarget(target) {
    if (!target || target.readOnly || target.disabled) return;
    const tagName = target.tagName ? target.tagName.toUpperCase() : '';
    if (tagName !== 'INPUT' && tagName !== 'TEXTAREA') return;

    const inputType = (target.type || 'text').toLowerCase();
    if (['password', 'email', 'url', 'number', 'file', 'hidden', 'date', 'time', 'checkbox', 'radio'].includes(inputType)) {
      return;
    }

    const fieldName = (target.name || '').toLowerCase();
    const fieldId = (target.id || '').toLowerCase();

    // Skip evaluation form link or URL-like fields
    if (fieldName.includes('eval_form_link') || fieldName.includes('url') || fieldId.includes('eval_form_link')) {
      return;
    }

    const isName = Boolean(
      fieldName.includes('organizer') ||
      fieldName.includes('pic') ||
      fieldName.includes('person') ||
      fieldName.includes('teacher') ||
      fieldName.includes('faculty') ||
      fieldName.includes('name') ||
      target.classList.contains('sched-field') ||
      target.classList.contains('mp-person-field') ||
      target.classList.contains('prog-field')
    );

    const original = target.value;
    const updated = autoCapitalizeValue(original, isName);
    if (original !== updated) {
      target.value = updated;
      target.dispatchEvent(new Event('input', { bubbles: true }));
      target.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  // Global blur event listener (capture phase) for immediate correction on field exit
  document.addEventListener('blur', function (e) {
    handleAutoCapitalizeTarget(e.target);
  }, true);

  // Auto-correct all relevant inputs right before form submission
  document.addEventListener('submit', function (e) {
    const form = e.target;
    if (!form || !form.querySelectorAll) return;
    form.querySelectorAll('input[type="text"], textarea').forEach(inp => {
      handleAutoCapitalizeTarget(inp);
    });
  }, true);

})(window, document);

