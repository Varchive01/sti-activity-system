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
    return data;
  }

  function restoreForm(formEl, draftData) {
    if (!formEl || !draftData) return;

    for (const key in draftData) {
      if (!Object.prototype.hasOwnProperty.call(draftData, key)) continue;
      const val = draftData[key];

      // Handle arrays (e.g. kpi_indicator, ft_title, etc.)
      if (Array.isArray(val)) {
        const arrayInputs = formEl.querySelectorAll(`[name="${key}[]"]`);
        if (arrayInputs.length >= val.length) {
          val.forEach((itemVal, idx) => {
            if (arrayInputs[idx]) arrayInputs[idx].value = itemVal;
          });
        }
        continue;
      }

      // Handle standard inputs
      const input = formEl.elements[key];
      if (!input) continue;

      if (input instanceof RadioNodeList) {
        input.value = val;
      } else if (input.type === 'checkbox') {
        input.checked = Boolean(val && val !== '0');
      } else {
        input.value = val;
      }
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

  /**
   * 6. Save Draft Locally (IndexedDB)
   */
  async function saveDraftLocally(formEl, options = {}) {
    if (!formEl) return null;

    const formData = serializeForm(formEl);
    const serverIdVal = formEl.elements['activity_id'] ? parseInt(formEl.elements['activity_id'].value, 10) : null;
    const serverId = (serverIdVal && serverIdVal > 0) ? serverIdVal : null;

    let localId = activeLocalDraftId;
    if (!localId) {
      if (serverId) {
        localId = 'draft_srv_' + serverId;
      } else {
        localId = 'draft_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
      }
      activeLocalDraftId = localId;
    }

    const existingDraft = await getDraft(localId);

    const draftRecord = {
      local_id: localId,
      server_id: serverId,
      faculty_id: currentUserId || (existingDraft ? existingDraft.faculty_id : 0),
      user_role: currentUserRole,
      title: formData['title'] || 'Untitled Proposal Draft',
      form_data: formData,
      sync_status: options.sync_status || 'saved_locally',
      sync_error: null,
      created_at: existingDraft ? existingDraft.created_at : new Date().toISOString(),
      last_saved_at: new Date().toISOString(),
      server_updated_at: existingDraft ? existingDraft.server_updated_at : (formEl.dataset.serverUpdatedAt || null),
      last_sync_attempt: null
    };

    await putDraft(draftRecord);
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

    // Build form payload
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

    // Force action = draft (STRICT RESTRICTION: Never auto-submit offline drafts)
    payload.set('action', 'draft');
    payload.set('is_ajax_sync', '1');

    let endpoint = baseUrl + '/api/proposal-save.php';
    if (draft.server_id && draft.server_id > 0) {
      endpoint = baseUrl + '/api/proposal-update.php';
      payload.set('activity_id', draft.server_id.toString());
      if (draft.server_updated_at) {
        payload.set('last_known_updated_at', draft.server_updated_at);
      }
    }

    if (draft.force_overwrite) {
      payload.set('force_overwrite', '1');
    }

    try {
      const resp = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: payload.toString()
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
        if (confirm('Are you sure you want to discard this local draft?')) {
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
    }

    if (matchingDraft) {
      activeLocalDraftId = matchingDraft.local_id;
      updateStatusUI(matchingDraft.sync_status);

      // Offer to resume if local draft is newer or uncommitted
      if (paramLocalId || matchingDraft.sync_status !== 'synchronized') {
        const resumeNotice = document.createElement('div');
        resumeNotice.className = 'alert alert-info';
        resumeNotice.style.display = 'flex';
        resumeNotice.style.justifyContent = 'space-between';
        resumeNotice.style.alignItems = 'center';
        resumeNotice.style.marginBottom = '16px';
        resumeNotice.innerHTML = `
          <div>
            <strong>💾 Local Draft Found:</strong> Saved locally on ${new Date(matchingDraft.last_saved_at).toLocaleString()}.
          </div>
          <div style="display:flex;gap:8px;">
            <button type="button" id="btnApplyLocalDraft" class="btn btn-sm btn-primary" style="padding:4px 10px;">Load Local Edits</button>
            <button type="button" id="btnDismissLocalDraft" class="btn btn-sm btn-outline" style="padding:4px 8px;">Dismiss</button>
          </div>
        `;
        formEl.parentNode.insertBefore(resumeNotice, formEl);

        document.getElementById('btnApplyLocalDraft').onclick = () => {
          restoreForm(formEl, matchingDraft.form_data);
          resumeNotice.remove();
          showToast('Local draft loaded successfully.', 'success');
        };
        document.getElementById('btnDismissLocalDraft').onclick = () => {
          resumeNotice.remove();
        };
      }
    } else {
      updateStatusUI(navigator.onLine ? 'synchronized' : 'saved_locally');
    }

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
          alert("⚠️ Offline Mode: Proposals cannot be submitted for formal review while offline.\n\nPlease connect to the internet to submit. You may continue editing and click 'Save as Draft' to store your work locally.");
          return false;
        }
      });
    }

    // Manual sync button
    const manualSyncBtn = document.getElementById('btnManualSync');
    if (manualSyncBtn) {
      manualSyncBtn.onclick = async () => {
        if (!navigator.onLine) {
          alert('Cannot synchronize while offline. Please verify your internet connection.');
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

  // Expose module methods to window
  window.OfflineDraftManager = {
    openDB,
    saveDraftLocally,
    getDraft,
    getAllDrafts,
    deleteDraft,
    syncDraft,
    syncAllPendingDrafts,
    renderLocalDraftsWidget,
    checkServerConnection,
    updateStatusUI
  };

})(window, document);

