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

    // Capture evaluation questions array if present in window or hidden field
    if (window.questionsList && Array.isArray(window.questionsList) && window.questionsList.length > 0) {
      data['eval_questions_json'] = JSON.stringify(window.questionsList);
    }

    return data;
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

    // 4. Trigger calculations & recalculate summaries
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
      if (conf.showRetry || statusKey === 'pending_sync' || statusKey === 'sync_failed') {
        retryBtn.style.display = 'inline-block';
      } else {
        retryBtn.style.display = 'none';
      }
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

    // Check connectivity & auth first
    const conn = await checkServerConnection();
    if (!conn.connected) {
      updateStatusUI('pending_sync', 'Offline. Will sync when reconnected.');
      return false;
    }
    if (!conn.authenticated) {
      updateStatusUI('sync_failed', 'Session unauthenticated. Please log in.');
      showToast('⚠️ Please log in to synchronize offline drafts.', 'warning');
      return false;
    }

    updateStatusUI('syncing');

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

    if (formEl) {
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
        body: requestBody
      });

      // 409 Conflict: Server has newer modifications
      if (resp.status === 409) {
        const confData = await resp.json();
        draft.sync_status = 'conflict_requires_review';
        draft.sync_error = confData.error || 'Server copy was modified more recently.';
        draft.server_updated_at = confData.server_updated_at || draft.server_updated_at;
        await putDraft(draft);

        updateStatusUI('conflict_requires_review');
        showConflictModal(draft, confData);
        return false;
      }

      if (resp.ok) {
        const result = await resp.json();
        if (result.success) {
          const newServerId = parseInt(result.activity_id, 10);
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

          updateStatusUI('synchronized');
          showToast('✓ Proposal draft synchronized with server.', 'success');

          // If current form is open on proposal-create, transition it to edit state without losing inputs
          const formEl = document.getElementById('proposalForm');
          if (formEl) {
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
      draft.sync_status = 'sync_failed';
      draft.sync_error = errResult.error || `Server returned error (${resp.status})`;
      draft.last_sync_attempt = new Date().toISOString();
      await putDraft(draft);
      updateStatusUI('sync_failed', draft.sync_error);
      return false;

    } catch (netErr) {
      console.warn('[OfflineDrafts] Sync network error:', netErr);
      draft.sync_status = 'pending_sync';
      draft.sync_error = 'Network connection interrupted during sync.';
      draft.last_sync_attempt = new Date().toISOString();
      await putDraft(draft);
      updateStatusUI('pending_sync', 'Offline. Retry scheduled.');
      return false;
    }
  }

  async function syncAllPendingDrafts() {
    if (isSyncInProgress) return;
    isSyncInProgress = true;

    try {
      const drafts = await getAllDrafts();
      const pending = drafts.filter(d => ['pending_sync', 'saved_locally', 'sync_failed'].includes(d.sync_status));

      for (const d of pending) {
        await syncDraft(d);
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
   * 9. Render Local Drafts Widget (for Activities & Dashboards)
   */
  async function renderLocalDraftsWidget(containerId = 'localDraftsContainer') {
    const container = document.getElementById(containerId);
    if (!container) return;

    const drafts = await getAllDrafts();
    if (!drafts || drafts.length === 0) {
      container.style.display = 'none';
      return;
    }

    container.style.display = 'block';
    let html = `
      <div class="card" style="margin-bottom:20px;border-left:4px solid #F59E0B;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;padding:12px 18px;background:rgba(245,158,11,0.08);">
          <div style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:1.15rem;">💾</span>
            <strong style="font-size:0.95rem;color:var(--text-main,#333);">Local Offline Proposal Drafts</strong>
            <span class="badge" style="background:#F59E0B;color:#fff;font-size:0.75rem;">${drafts.length} stored</span>
          </div>
          <button type="button" id="btnSyncAllLocalDrafts" class="btn btn-sm btn-primary" style="padding:4px 10px;font-size:0.78rem;">
            🔄 Sync All Now
          </button>
        </div>
        <div class="card-body" style="padding:0;">
          <div class="table-wrap">
            <table class="activity-table" style="width:100%;margin:0;">
              <thead>
                <tr>
                  <th>Proposal Title</th>
                  <th>Last Saved Locally</th>
                  <th>Sync Status</th>
                  <th style="text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody>
    `;

    drafts.forEach(d => {
      const conf = STATUS_CONFIGS[d.sync_status] || STATUS_CONFIGS['saved_locally'];
      const resumeUrl = d.server_id
        ? `${baseUrl}/faculty/proposal-edit.php?id=${d.server_id}&local_id=${encodeURIComponent(d.local_id)}`
        : `${baseUrl}/faculty/proposal-create.php?local_id=${encodeURIComponent(d.local_id)}`;

      html += `
        <tr>
          <td>
            <strong>${escapeHtml(d.title || 'Untitled Draft')}</strong>
            ${d.sync_error ? `<br><small style="color:#EF4444;">⚠️ ${escapeHtml(d.sync_error)}</small>` : ''}
          </td>
          <td><span style="color:var(--text-secondary,#666);font-size:0.82rem;">${new Date(d.last_saved_at).toLocaleString()}</span></td>
          <td>
            <span class="badge" style="background:${conf.bg};color:#fff;font-size:0.78rem;padding:4px 8px;border-radius:10px;">
              ${conf.icon} ${conf.text}
            </span>
          </td>
          <td style="text-align:right;">
            <div style="display:inline-flex;gap:6px;">
              <a href="${resumeUrl}" class="btn btn-sm btn-primary" style="padding:3px 10px;font-size:0.78rem;">Resume</a>
              <button type="button" class="btn btn-sm btn-outline btn-sync-single" data-id="${escapeHtml(d.local_id)}" style="padding:3px 10px;font-size:0.78rem;">Sync</button>
              <button type="button" class="btn btn-sm btn-outline btn-delete-draft" data-id="${escapeHtml(d.local_id)}" style="padding:3px 8px;font-size:0.78rem;color:#EF4444;" title="Discard local copy">✕</button>
            </div>
          </td>
        </tr>
      `;
    });

    html += `
              </tbody>
            </table>
          </div>
        </div>
      </div>
    `;

    container.innerHTML = html;

    // Attach listeners
    const syncAllBtn = container.querySelector('#btnSyncAllLocalDrafts');
    if (syncAllBtn) {
      syncAllBtn.onclick = async () => {
        syncAllBtn.disabled = true;
        syncAllBtn.innerText = 'Syncing...';
        await syncAllPendingDrafts();
        await renderLocalDraftsWidget(containerId);
      };
    }

    container.querySelectorAll('.btn-sync-single').forEach(btn => {
      btn.onclick = async () => {
        const dId = btn.getAttribute('data-id');
        const targetDraft = await getDraft(dId);
        if (targetDraft) {
          btn.disabled = true;
          await syncDraft(targetDraft);
          await renderLocalDraftsWidget(containerId);
        }
      };
    });

    container.querySelectorAll('.btn-delete-draft').forEach(btn => {
      btn.onclick = async () => {
        const dId = btn.getAttribute('data-id');
        const msg = 'Are you sure you want to discard this local draft?';
        const confirmed = window.STIModal
          ? await window.STIModal.confirm(msg, { title: 'Discard Local Draft?', confirmText: 'Discard Draft', cancelText: 'Cancel', variant: 'danger' })
          : confirm(msg);
        if (confirmed) {
          await deleteDraft(dId);
          await renderLocalDraftsWidget(containerId);
        }
      };
    });
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
      updateStatusUI(matchingDraft.sync_status);

      // Verify draft has non-empty contents
      const hasMeaningfulContent = Object.keys(matchingDraft.form_data).some(k => {
        if (['action', 'faculty_id', 'activity_id', 'proposal_session_token'].includes(k)) return false;
        const v = matchingDraft.form_data[k];
        if (Array.isArray(v)) return v.some(item => item && String(item).trim().length > 0);
        return v && String(v).trim().length > 0;
      });

      if (hasMeaningfulContent && (paramLocalId || matchingDraft.sync_status !== 'synchronized' || !serverId)) {
        const resumeNotice = document.createElement('div');
        resumeNotice.className = 'alert alert-info';
        resumeNotice.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;background:rgba(2,132,199,0.08);border:1px solid #bae6fd;border-left:4px solid #0284c7;border-radius:10px;padding:12px 18px;box-shadow:0 1px 3px rgba(0,0,0,0.05);';
        const formattedDate = new Date(matchingDraft.last_saved_at).toLocaleString();
        resumeNotice.innerHTML = `
          <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:1.35rem;">💾</span>
            <div>
              <strong style="color:#0369a1;font-size:0.92rem;">Auto-Saved Draft Found:</strong>
              <div style="font-size:0.82rem;color:#475569;">You have uncommitted edits from <strong>${formattedDate}</strong> (${escapeHtml(matchingDraft.title || 'Untitled Proposal')}).</div>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-shrink:0;">
            <button type="button" id="btnApplyLocalDraft" class="btn btn-sm btn-primary" style="padding:6px 14px;font-weight:600;font-size:0.82rem;">Resume Draft</button>
            <button type="button" id="btnDismissLocalDraft" class="btn btn-sm btn-outline" style="padding:6px 12px;font-size:0.82rem;color:#64748b;background:#fff;" title="Discard uncommitted draft and start fresh">Discard Draft</button>
          </div>
        `;
        formEl.parentNode.insertBefore(resumeNotice, formEl);

        document.getElementById('btnApplyLocalDraft').onclick = () => {
          restoreForm(formEl, matchingDraft.form_data);
          if (matchingDraft.server_id && parseInt(matchingDraft.server_id, 10) > 0) {
            const actInput = formEl.elements['activity_id'] || document.getElementById('proposalActivityId');
            if (actInput) {
              actInput.value = matchingDraft.server_id;
            }
            formEl.action = baseUrl + '/api/proposal-update.php';
          }
          resumeNotice.remove();
          isFormDirty = true;
          showToast('✓ Proposal progress resumed from auto-saved draft.', 'success');
        };

        document.getElementById('btnDismissLocalDraft').onclick = async () => {
          const msg = 'Are you sure you want to discard this auto-saved draft? You will start with a fresh blank proposal.';
          const confirmed = window.STIModal
            ? await window.STIModal.confirm(msg, {
                title: 'Discard Auto-Saved Draft?',
                icon: '🗑️',
                variant: 'danger',
                confirmText: 'Discard Draft',
                cancelText: 'Cancel'
              })
            : confirm(msg);
          if (confirmed) {
            await deleteDraft(matchingDraft.local_id);
            await deleteDraft('draft_new_active');
            try {
              localStorage.removeItem(serverId ? `sti_draft_meta_srv_${serverId}` : 'sti_draft_meta_new_active');
              localStorage.removeItem(serverId ? `sti_active_draft_full_srv_${serverId}` : 'sti_active_draft_full_new');
              localStorage.removeItem(serverId ? `sti_emergency_srv_${serverId}` : 'sti_emergency_new_active');
            } catch (e) {}
            activeLocalDraftId = null;
            isFormDirty = false;
            resumeNotice.remove();
            showToast('Auto-saved draft discarded.', 'info');
          }
        };
      }
    } else {
      updateStatusUI(navigator.onLine ? 'synchronized' : 'saved_locally');
    }

    // Debounced automatic draft saver
    function triggerAutoSave() {
      if (isSubmitting) return;
      isFormDirty = true;

      // 1. Immediately snapshot to localStorage synchronously (0ms delay, rock-solid persistence)
      saveDraftSynchronously(formEl, { sync_status: 'saved_locally' });
      updateStatusUI('saved_locally', 'Draft saved locally');

      // 2. Debounce heavier IndexedDB commit
      if (autoSaveDebounceTimer) clearTimeout(autoSaveDebounceTimer);
      autoSaveDebounceTimer = setTimeout(async () => {
        try {
          const rec = await saveDraftLocally(formEl, { sync_status: 'saved_locally' });
          if (rec) {
            updateStatusUI('saved_locally', `Draft auto-saved at ${new Date().toLocaleTimeString()}`);
          }
        } catch (err) {
          console.warn('[OfflineDrafts] Auto-save error:', err);
        }
      }, 1000);
    }

    // Attach input and change listeners for continuous auto-save
    formEl.addEventListener('input', triggerAutoSave);
    formEl.addEventListener('change', triggerAutoSave);

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

    // Manual sync button
    const manualSyncBtn = document.getElementById('btnManualSync');
    if (manualSyncBtn) {
      manualSyncBtn.onclick = async () => {
        if (!navigator.onLine) {
          const syncAlertMsg = 'Cannot synchronize while offline. Please verify your internet connection.';
        if (window.STIModal) {
          window.STIModal.alert(syncAlertMsg, { title: 'Offline Synchronization', variant: 'warning', icon: '📡' });
        } else {
          alert(syncAlertMsg);
        }
          return;
        }
        manualSyncBtn.disabled = true;
        manualSyncBtn.innerText = 'Syncing...';
        await saveDraftLocally(formEl, { sync_status: 'pending_sync' });
        await syncAllPendingDrafts();
        manualSyncBtn.disabled = false;
        manualSyncBtn.innerText = 'Sync Now';
      };
    }
  }

  /**
   * 11. Event Listeners for Online/Offline transitions
   */
  window.addEventListener('online', async function () {
    console.log('[OfflineDrafts] Network connection restored. Initiating automatic synchronization...');
    updateStatusUI('syncing', 'Reconnected! Syncing drafts...');
    showToast('🌐 Internet connection restored. Synchronizing pending drafts...', 'info');

    // Run automatic sync
    await syncAllPendingDrafts();

    // Re-render widget if present
    await renderLocalDraftsWidget('localDraftsContainer');
  });

  window.addEventListener('offline', function () {
    console.log('[OfflineDrafts] Network connection lost. Offline draft mode engaged.');
    updateStatusUI('saved_locally', 'Offline Mode');
    showToast('📡 You are currently offline. Local draft storage active.', 'warning');
  });

  // Periodic heartbeat when tab is active (every 45s)
  function startSyncHeartbeat() {
    if (syncHeartbeatTimer) clearInterval(syncHeartbeatTimer);
    syncHeartbeatTimer = setInterval(async () => {
      if (navigator.onLine && !isSyncInProgress) {
        const drafts = await getAllDrafts();
        const hasPending = drafts.some(d => ['pending_sync', 'sync_failed'].includes(d.sync_status));
        if (hasPending) {
          await syncAllPendingDrafts();
        }
      }
    }, 45000);
  }

  // DOM Ready bootstrap
  document.addEventListener('DOMContentLoaded', async function () {
    try {
      await openDB();
      await initProposalForm();
      await renderLocalDraftsWidget('localDraftsContainer');
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

})(window, document);

