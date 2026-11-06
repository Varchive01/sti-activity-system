/**
 * assets/js/ai-proposal-guidance.js
 *
 * Frontend engine for AI-Assisted Proposal Guidance with Preview, Edit, and Selective Apply.
 * STI Activity Request, Monitoring, and Evaluation System (STI-ARMES).
 *
 * Requirements:
 * - Activity Title is NEVER generated or modified by AI (manually entered only).
 * - Generates suggestions for 8 sections: Objectives, Materials, Floor Plan, People, Schedule, Guidelines, Faculty Tasks, Evaluation & KPI.
 * - Left Panel: Section selection checkboxes + Protected Activity Title context + "Generate with AI" button.
 * - Right Panel: Grouped section previews + Edit in-place + Individual Apply + Apply Selected + Apply All + Regenerate.
 * - Strict Data Preservation: Never auto-submits, never auto-populates without user action, warns before replacing existing content.
 */

const AIProposalGuidance = (function () {
  let _baseUrl = '';
  let _formEl = null;
  let _modalEl = null;
  let _isLoading = false;
  let _currentSuggestions = {};
  let _editingSections = {};
  let _selectedForApply = new Set();
  let _appliedSections = new Set();

  const SECTION_CONFIG = {
    objectives: {
      id: 'objectives',
      name: 'Objectives',
      icon: '🎯',
      fields: ['general_objectives', 'specific_objectives', 'rationale', 'involved_subjects']
    },
    materials: {
      id: 'materials',
      name: 'Materials & Budget',
      icon: '📦',
      fields: ['mat_item[]', 'mat_desc[]', 'mat_qty[]', 'mat_provider[]', 'mat_cost[]']
    },
    floor_plan: {
      id: 'floor_plan',
      name: 'Floor Plan Layout',
      icon: '🗺️',
      fields: ['floor_plan_notes']
    },
    people: {
      id: 'people',
      name: 'People & Manpower',
      icon: '👥',
      fields: ['mp_role[]', 'mp_person[]', 'mp_type[]']
    },
    schedule: {
      id: 'schedule',
      name: 'Schedule & Program Flow',
      icon: '⏰',
      fields: ['prog_time[]', 'prog_segment[]', 'prog_desc[]', 'prog_pic[]']
    },
    guidelines: {
      id: 'guidelines',
      name: 'Event Guidelines',
      icon: '📜',
      fields: ['guidelines_mechanics', 'guidelines_criteria', 'guidelines_scoring', 'guidelines_awards']
    },
    faculty_tasks: {
      id: 'faculty_tasks',
      name: 'Faculty Tasks',
      icon: '📋',
      fields: ['ft_task[]', 'ft_contribution[]', 'ft_role[]', 'ft_due_date[]']
    },
    evaluation_kpi: {
      id: 'evaluation_kpi',
      name: 'Evaluation & KPI',
      icon: '📈',
      fields: ['kpi_indicator[]', 'kpi_target[]', 'kpi_method[]']
    }
  };

  const SECTION_KEYS = Object.keys(SECTION_CONFIG);

  function getBaseUrl() {
    if (_baseUrl) return _baseUrl;
    if (typeof window.BASE_URL === 'string' && window.BASE_URL) return window.BASE_URL.replace(/\/$/, '');
    const meta = document.querySelector('meta[name="base-url"]');
    if (meta && meta.getAttribute('content')) return meta.getAttribute('content').replace(/\/$/, '');
    const match = window.location.pathname.match(/^(.*?\/sti-activity-system)/);
    if (match) return match[1];
    return '';
  }

  function getFormElement() {
    if (_formEl) return _formEl;
    _formEl = document.getElementById('proposalForm');
    return _formEl;
  }

  function getActivityTitle() {
    const form = getFormElement();
    if (!form) return '';
    const titleInput = form.querySelector('[name="title"]') || document.getElementById('f1_title');
    return titleInput ? titleInput.value.trim() : '';
  }

  function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function ensureModalDom() {
    if (_modalEl) return;

    let style = document.getElementById('aiGuidanceNewStyle');
    if (!style) {
      style = document.createElement('style');
      style.id = 'aiGuidanceNewStyle';
      style.textContent = `
        @keyframes aiGuidanceSpin {
          0% { transform: rotate(0deg); }
          100% { transform: rotate(360deg); }
        }
        .ai-guidance-spinner {
          width: 44px;
          height: 44px;
          margin: 0 auto 16px auto;
          border: 4px solid #E2E8F0;
          border-top-color: var(--sti-blue, #0284C7);
          border-radius: 50%;
          animation: aiGuidanceSpin 0.8s linear infinite;
        }
        .ai-guidance-layout {
          display: grid;
          grid-template-columns: 310px 1fr;
          gap: 20px;
          align-items: stretch;
          min-height: 540px;
        }
        @media (max-width: 860px) {
          .ai-guidance-layout {
            grid-template-columns: 1fr;
            min-height: auto;
          }
        }
        .ai-card-item {
          background: #FFFFFF;
          border: 1px solid #E2E8F0;
          border-radius: 10px;
          padding: 16px;
          margin-bottom: 14px;
          box-shadow: 0 1px 4px rgba(0,0,0,0.04);
          transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .ai-card-item:hover {
          border-color: #CBD5E1;
          box-shadow: 0 3px 10px rgba(0,0,0,0.06);
        }
        .ai-card-item.applied {
          border-color: #10B981;
          background: #F0FDF4;
        }
        .ai-section-check:checked + label {
          color: #0284C7;
          font-weight: 700;
        }
        .ai-toast-notify {
          position: fixed;
          bottom: 24px;
          right: 24px;
          background: #0A1628;
          color: #FFFFFF;
          padding: 12px 20px;
          border-radius: 8px;
          font-size: 0.86rem;
          font-weight: 600;
          box-shadow: 0 8px 24px rgba(0,0,0,0.28);
          z-index: 1080;
          display: flex;
          align-items: center;
          gap: 10px;
          animation: aiToastIn 0.25s ease;
        }
        @keyframes aiToastIn {
          from { opacity: 0; transform: translateY(12px); }
          to   { opacity: 1; transform: translateY(0); }
        }
      `;
      document.head.appendChild(style);
    }

    let modal = document.getElementById('aiProposalGuidanceModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'aiProposalGuidanceModal';
      modal.className = 'modal fade';
      modal.tabIndex = -1;
      modal.setAttribute('aria-hidden', 'true');
      modal.style.display = 'none';
      modal.style.position = 'fixed';
      modal.style.inset = '0';
      modal.style.background = 'rgba(10, 22, 40, 0.65)';
      modal.style.backdropFilter = 'blur(3px)';
      modal.style.webkitBackdropFilter = 'blur(3px)';
      modal.style.zIndex = '1060';
      modal.style.overflowY = 'auto';
      modal.style.padding = '16px';

      modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered" style="margin: 1.5rem auto; max-width: 1140px; width: 100%;">
          <div class="modal-content" style="border-radius: 14px; overflow: hidden; border: 1px solid #E2E8F0; box-shadow: 0 16px 48px rgba(10, 22, 40, 0.25); background: var(--bg-card, #FFFFFF); color: var(--text-main, #0A1628); font-family: 'Plus Jakarta Sans', sans-serif;">
            
            <!-- Modal Header -->
            <div class="modal-header" style="padding: 16px 22px; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between; background: var(--bg-base, #F8FAFC);">
              <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">✨</span>
                <div>
                  <h5 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: var(--text-main, #0A1628); letter-spacing: -0.01em;">
                    AI Proposal Guidance
                  </h5>
                  <span style="font-size: 0.76rem; color: var(--text-muted, #64748B);">
                    Generate, review, edit, and selectively apply AI suggestions to your proposal form
                  </span>
                </div>
              </div>
              <button type="button" onclick="AIProposalGuidance.closeModal()" aria-label="Close" style="background: none; border: none; font-size: 1.5rem; color: #64748B; cursor: pointer; padding: 2px 8px; border-radius: 6px; line-height: 1;">&times;</button>
            </div>

            <!-- Modal Body (Two-Panel Layout) -->
            <div class="modal-body" style="padding: 20px; max-height: calc(88vh - 120px); overflow-y: auto;">
              <div class="ai-guidance-layout">

                <!-- ── LEFT PANEL: Generate Proposal Content ── -->
                <div style="background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 12px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between; gap: 16px;">
                  <div>
                    <h6 style="font-size: 0.95rem; font-weight: 800; color: #0A1628; margin: 0 0 12px 0; display: flex; align-items: center; gap: 6px;">
                      <span>⚙️</span> Generate Proposal Content
                    </h6>

                    <!-- Activity Title Context Box (Manual, Protected) -->
                    <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                        <span style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #64748B; letter-spacing: 0.03em;">Activity Title</span>
                        <span style="font-size: 0.65rem; font-weight: 700; color: #0284C7; background: #E0F2FE; padding: 2px 6px; border-radius: 999px;">Manual (Protected)</span>
                      </div>
                      <div id="aiContextTitleDisplay" style="font-size: 0.85rem; font-weight: 700; color: #0A1628; line-height: 1.35;">
                        <em>Loading title...</em>
                      </div>
                      <div style="font-size: 0.70rem; color: #64748B; margin-top: 4px;">
                        * Title is manually provided and will NEVER be changed by AI.
                      </div>
                    </div>

                    <!-- Section Checkboxes -->
                    <div style="margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                      <span style="font-size: 0.78rem; font-weight: 700; color: #334155;">Sections to Generate:</span>
                      <div style="display: flex; gap: 8px; font-size: 0.72rem;">
                        <a href="javascript:void(0)" onclick="AIProposalGuidance.toggleAllSections(true)" style="color: #0284C7; text-decoration: none; font-weight: 600;">Select All</a>
                        <span style="color: #CBD5E1;">|</span>
                        <a href="javascript:void(0)" onclick="AIProposalGuidance.toggleAllSections(false)" style="color: #64748B; text-decoration: none; font-weight: 600;">Clear</a>
                      </div>
                    </div>

                    <div id="aiSectionCheckboxes" style="display: flex; flex-direction: column; gap: 8px;">
                      <!-- Populated dynamically -->
                    </div>
                  </div>

                  <!-- Generate Button -->
                  <div style="padding-top: 12px; border-top: 1px solid #E2E8F0;">
                    <button type="button" id="btnGenerateAI" class="btn btn-primary" onclick="AIProposalGuidance.generateSections()" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; padding: 11px 16px; font-size: 0.88rem; font-weight: 700; border-radius: 8px; background: var(--sti-blue, #0284C7); border: none; color: #FFFFFF; cursor: pointer; transition: background 0.15s ease;">
                      <span>✨</span> Generate with AI
                    </button>
                    <div style="font-size: 0.68rem; color: #64748B; text-align: center; margin-top: 6px;">
                      Suggestions will preview on the right before applying.
                    </div>
                  </div>
                </div>

                <!-- ── RIGHT PANEL: AI Suggestions for Your Proposal ── -->
                <div style="background: #FFFFFF; border: 1.5px solid #E2E8F0; border-radius: 12px; padding: 18px; display: flex; flex-direction: column; min-height: 480px;">
                  
                  <!-- Right Panel Toolbar -->
                  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding-bottom: 14px; border-bottom: 1px solid #E2E8F0; margin-bottom: 16px;">
                    <div>
                      <h6 style="font-size: 0.95rem; font-weight: 800; color: #0A1628; margin: 0 0 2px 0;">
                        AI Suggestions for Your Proposal
                      </h6>
                      <span id="aiSuggestionsStatus" style="font-size: 0.74rem; color: #64748B;">
                        Select sections and click "Generate with AI" to preview.
                      </span>
                    </div>

                    <!-- Action Controls -->
                    <div id="aiRightPanelActions" style="display: none; align-items: center; gap: 8px; flex-wrap: wrap;">
                      <button type="button" class="btn btn-outline btn-sm" onclick="AIProposalGuidance.generateSections(true)" style="font-size: 0.76rem; padding: 5px 10px; font-weight: 600; border-radius: 6px; display: inline-flex; align-items: center; gap: 5px;">
                        <span>🔄</span> Regenerate
                      </button>
                      <button type="button" id="btnApplySelected" class="btn btn-outline btn-sm" onclick="AIProposalGuidance.applySelected()" style="font-size: 0.76rem; padding: 5px 12px; font-weight: 600; border-radius: 6px; border-color: #0284C7; color: #0284C7;">
                        Apply Selected (0)
                      </button>
                      <button type="button" class="btn btn-primary btn-sm" onclick="AIProposalGuidance.applyAll()" style="font-size: 0.76rem; padding: 5px 14px; font-weight: 700; border-radius: 6px; background: #0284C7; border: none; color: #FFFFFF;">
                        ✓ Apply All Suggestions
                      </button>
                    </div>
                  </div>

                  <!-- Dynamic Suggestions Content Container -->
                  <div id="aiSuggestionsContainer" style="flex: 1 1 auto; overflow-y: auto; max-height: calc(88vh - 220px); padding-right: 4px;">
                    <!-- Rendered by renderSuggestions() -->
                  </div>

                </div>

              </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer" style="padding: 12px 22px; border-top: 1px solid #E2E8F0; display: flex; justify-content: space-between; align-items: center; background: var(--bg-base, #F8FAFC);">
              <div style="font-size: 0.75rem; color: #64748B;">
                🔒 Content is applied only when explicitly confirmed. Generating never auto-submits.
              </div>
              <button type="button" class="btn btn-secondary btn-sm" onclick="AIProposalGuidance.closeModal()" style="font-size: 0.82rem; padding: 6px 18px; border-radius: 6px;">
                Close
              </button>
            </div>

          </div>
        </div>
      `;

      // Close modal on backdrop click
      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
      });

      document.body.appendChild(modal);
    }
    _modalEl = modal;
  }

  function renderCheckboxes() {
    const container = document.getElementById('aiSectionCheckboxes');
    if (!container) return;

    let html = '';
    SECTION_KEYS.forEach((key) => {
      const sec = SECTION_CONFIG[key];
      html += `
        <label style="display: flex; align-items: center; gap: 8px; font-size: 0.82rem; color: #1E293B; cursor: pointer; user-select: none; margin: 0; padding: 4px 6px; border-radius: 6px; transition: background 0.15s ease;">
          <input type="checkbox" id="chk_sec_${key}" class="ai-section-check" value="${key}" checked style="width: 16px; height: 16px; accent-color: #0284C7; cursor: pointer;">
          <span style="font-size: 0.95rem;">${sec.icon}</span>
          <span>${sec.name}</span>
        </label>
      `;
    });
    container.innerHTML = html;
  }

  function toggleAllSections(checked) {
    SECTION_KEYS.forEach((key) => {
      const chk = document.getElementById(`chk_sec_${key}`);
      if (chk) chk.checked = !!checked;
    });
  }

  function updateContextTitle() {
    const el = document.getElementById('aiContextTitleDisplay');
    if (!el) return;
    const title = getActivityTitle();
    if (title) {
      el.textContent = title;
      el.style.color = '#0A1628';
      el.style.fontStyle = 'normal';
    } else {
      el.innerHTML = '<span style="color:#D97706; font-style:italic;">⚠️ No Activity Title entered yet. Please enter title in Step 1 for context.</span>';
    }
  }

  function showEmptyState() {
    const container = document.getElementById('aiSuggestionsContainer');
    const actions = document.getElementById('aiRightPanelActions');
    const status = document.getElementById('aiSuggestionsStatus');
    if (actions) actions.style.display = 'none';
    if (status) status.textContent = 'Select sections and click "Generate with AI" to preview.';
    if (!container) return;

    container.innerHTML = `
      <div style="text-align: center; padding: 60px 20px; color: #64748B;">
        <div style="font-size: 3rem; margin-bottom: 14px;">📋</div>
        <h6 style="font-size: 1rem; font-weight: 700; color: #1E293B; margin-bottom: 8px;">
          Ready to Generate Proposal Suggestions
        </h6>
        <p style="font-size: 0.84rem; max-width: 440px; margin: 0 auto 18px auto; line-height: 1.5;">
          Select the sections on the left that you would like AI to assist you with. Suggestions will appear here where you can review, edit, and apply them.
        </p>
        <span style="display: inline-block; background: #F1F5F9; border: 1px solid #E2E8F0; padding: 6px 14px; border-radius: 999px; font-size: 0.75rem; color: #475569;">
          Protected: The Activity Title is preserved and never modified by AI.
        </span>
      </div>
    `;
  }

  function showLoadingState() {
    const container = document.getElementById('aiSuggestionsContainer');
    const actions = document.getElementById('aiRightPanelActions');
    const status = document.getElementById('aiSuggestionsStatus');
    if (actions) actions.style.display = 'none';
    if (status) status.textContent = 'Generating suggestions...';
    if (!container) return;

    container.innerHTML = `
      <div style="text-align: center; padding: 80px 20px;">
        <div class="ai-guidance-spinner"></div>
        <h6 style="font-size: 1.05rem; font-weight: 800; color: #0A1628; margin-bottom: 6px;">
          Generating Tailored Suggestions...
        </h6>
        <p style="font-size: 0.84rem; color: #64748B; max-width: 420px; margin: 0 auto; line-height: 1.5;">
          Consulting academic guidelines, STI outcome standards, and logistical templates for your selected sections.
        </p>
      </div>
    `;
  }

  function openModal() {
    ensureModalDom();
    renderCheckboxes();
    updateContextTitle();
    _modalEl.style.display = 'block';
    _modalEl.classList.add('show');
    document.body.style.overflow = 'hidden';

    // If suggestions haven't been generated yet in this session, show empty state
    if (Object.keys(_currentSuggestions).length === 0) {
      showEmptyState();
    } else {
      renderSuggestions();
    }
  }

  function closeModal() {
    if (!_modalEl) return;
    _modalEl.style.display = 'none';
    _modalEl.classList.remove('show');
    document.body.style.overflow = '';
  }

  function requestGuidance() {
    openModal();
  }

  function getSelectedSectionsFromCheckboxes() {
    const selected = [];
    SECTION_KEYS.forEach((key) => {
      const chk = document.getElementById(`chk_sec_${key}`);
      if (chk && chk.checked) {
        selected.push(key);
      }
    });
    return selected;
  }

  async function generateSections(forceRefresh = false) {
    if (_isLoading) return;

    const selectedSections = getSelectedSectionsFromCheckboxes();
    if (selectedSections.length === 0) {
      alert('Please select at least one proposal section to generate.');
      return;
    }

    const title = getActivityTitle();
    if (!title) {
      const msg = 'Activity Title in Step 1 is currently blank.\n\nFor the best AI suggestions, entering a descriptive title first is highly recommended.\n\nDo you want to proceed anyway?';
      const proceed = window.STIModal
        ? await window.STIModal.confirm(msg, { title: 'Activity Title is Blank', confirmText: 'Proceed Anyway', cancelText: 'Cancel', variant: 'warning' })
        : confirm(msg);
      if (!proceed) return;
    }

    _isLoading = true;
    showLoadingState();

    try {
      const form = getFormElement();
      const payload = {
        action: 'generate_sections',
        sections: selectedSections,
        title: title,
        theme: form?.querySelector('[name="theme"]')?.value || '',
        venue: form?.querySelector('[name="venue"]')?.value || '',
        event_date: form?.querySelector('[name="event_date"]')?.value || '',
        target_participants: form?.querySelector('[name="target_participants"]')?.value || '50',
        involved_subjects: form?.querySelector('[name="involved_subjects"]')?.value || '',
        force_refresh: forceRefresh ? 1 : 0
      };

      const baseUrl = getBaseUrl();
      const resp = await fetch(`${baseUrl}/api/ai-proposal-guidance.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });

      const data = await resp.json();
      if (!resp.ok || !data.success || !data.suggestions) {
        throw new Error(data.error || `HTTP ${resp.status}: Failed to generate section suggestions.`);
      }

      // Merge new suggestions into session cache
      _currentSuggestions = Object.assign({}, _currentSuggestions, data.suggestions);
      _editingSections = {};

      // Initialize selection for Apply Selected to include all newly generated sections
      _selectedForApply.clear();
      selectedSections.forEach((s) => _selectedForApply.add(s));

      renderSuggestions();
      showToast('✨ AI suggestions generated successfully!');

    } catch (err) {
      console.error('AIProposalGuidance error:', err);
      const container = document.getElementById('aiSuggestionsContainer');
      if (container) {
        container.innerHTML = `
          <div class="alert alert-danger" style="margin-top: 20px; border-radius: 8px; padding: 16px; border-left: 5px solid #DC2626;">
            <strong style="display:block; margin-bottom: 6px;">⚠️ Unable to Generate Suggestions</strong>
            <div style="font-size: 0.85rem; line-height: 1.45;">${escapeHtml(err.message || 'An error occurred.')}</div>
            <button type="button" class="btn btn-outline btn-sm" onclick="AIProposalGuidance.generateSections(true)" style="margin-top: 12px;">
              Try Again
            </button>
          </div>
        `;
      }
    } finally {
      _isLoading = false;
    }
  }

  function renderSuggestions() {
    const container = document.getElementById('aiSuggestionsContainer');
    const actions = document.getElementById('aiRightPanelActions');
    const status = document.getElementById('aiSuggestionsStatus');
    if (!container) return;

    const availableKeys = Object.keys(_currentSuggestions);
    if (availableKeys.length === 0) {
      showEmptyState();
      return;
    }

    if (actions) actions.style.display = 'flex';
    if (status) status.textContent = `${availableKeys.length} section(s) ready for review and application.`;
    updateApplySelectedButton();

    let html = '';
    SECTION_KEYS.forEach((secKey) => {
      if (!_currentSuggestions[secKey]) return;

      const sec = SECTION_CONFIG[secKey];
      const data = _currentSuggestions[secKey];
      const isApplied = _appliedSections.has(secKey);
      const isSelected = _selectedForApply.has(secKey);
      const isEditing = !!_editingSections[secKey];

      html += `
        <div class="ai-card-item ${isApplied ? 'applied' : ''}" id="aiCard_${secKey}">
          <!-- Card Header -->
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #E2E8F0;">
            <div style="display: flex; align-items: center; gap: 8px;">
              <input type="checkbox" id="chk_apply_${secKey}" ${isSelected ? 'checked' : ''} onchange="AIProposalGuidance.toggleSectionSelection('${secKey}', this.checked)" style="width: 16px; height: 16px; accent-color: #0284C7; cursor: pointer;">
              <span style="font-size: 1.1rem;">${sec.icon}</span>
              <strong style="font-size: 0.92rem; color: #0A1628;">${sec.name}</strong>
              ${isApplied ? '<span style="font-size: 0.68rem; font-weight: 700; color: #16A34A; background: #DCFCE7; padding: 2px 8px; border-radius: 999px;">✓ Applied</span>' : '<span style="font-size: 0.68rem; font-weight: 600; color: #64748B; background: #F1F5F9; padding: 2px 8px; border-radius: 999px;">Ready</span>'}
            </div>

            <!-- Card Actions -->
            <div style="display: flex; align-items: center; gap: 6px;">
              <button type="button" class="btn btn-outline btn-xs" onclick="AIProposalGuidance.toggleEditSection('${secKey}')" style="font-size: 0.74rem; padding: 4px 10px; font-weight: 600; border-radius: 6px;">
                ${isEditing ? '✕ Cancel' : '✏️ Edit'}
              </button>
              <button type="button" class="btn btn-primary btn-xs" onclick="AIProposalGuidance.applySection('${secKey}')" style="font-size: 0.74rem; padding: 4px 12px; font-weight: 700; border-radius: 6px; background: #0284C7; border: none; color: #FFFFFF;">
                Apply
              </button>
            </div>
          </div>

          <!-- Card Content Body -->
          <div id="aiCardBody_${secKey}">
            ${isEditing ? renderEditView(secKey, data) : renderPreviewView(secKey, data)}
          </div>
        </div>
      `;
    });

    container.innerHTML = html;
  }

  function renderPreviewView(secKey, data) {
    if (!data) return '<em style="color:#94A3B8;">No content generated.</em>';

    switch (secKey) {
      case 'objectives':
        return `
          <div style="font-size: 0.84rem; display: flex; flex-direction: column; gap: 8px;">
            <div>
              <strong style="color:#0A1628; display:block; margin-bottom:2px;">General Objective:</strong>
              <div style="color:#334155; line-height:1.45; background:#F8FAFC; padding:8px 10px; border-radius:6px; border:1px solid #E2E8F0;">${escapeHtml(data.general_objectives)}</div>
            </div>
            <div>
              <strong style="color:#0A1628; display:block; margin-bottom:2px;">Specific Objectives:</strong>
              <div style="color:#334155; line-height:1.45; white-space:pre-line; background:#F8FAFC; padding:8px 10px; border-radius:6px; border:1px solid #E2E8F0;">${escapeHtml(data.specific_objectives)}</div>
            </div>
            <div>
              <strong style="color:#0A1628; display:block; margin-bottom:2px;">Rationale:</strong>
              <div style="color:#334155; line-height:1.45; background:#F8FAFC; padding:8px 10px; border-radius:6px; border:1px solid #E2E8F0;">${escapeHtml(data.rationale)}</div>
            </div>
          </div>
        `;

      case 'materials':
        if (!Array.isArray(data) || data.length === 0) return '<em style="color:#94A3B8;">No materials suggested.</em>';
        let matRows = data.map((m, i) => `
          <tr style="border-bottom: 1px solid #F1F5F9;">
            <td style="padding: 6px 8px; font-weight:600; color:#0A1628;">${escapeHtml(m.item)}</td>
            <td style="padding: 6px 8px; color:#64748B;">${escapeHtml(m.desc || '—')}</td>
            <td style="padding: 6px 8px; text-align:center;">${m.qty}</td>
            <td style="padding: 6px 8px; color:#64748B;">${escapeHtml(m.provider || '—')}</td>
            <td style="padding: 6px 8px; text-align:right; font-weight:600;">₱${parseFloat(m.cost || 0).toFixed(2)}</td>
          </tr>
        `).join('');
        return `
          <div style="overflow-x:auto;">
            <table style="width:100%; font-size:0.80rem; border-collapse:collapse;">
              <thead>
                <tr style="background:#F8FAFC; border-bottom:1px solid #E2E8F0; text-align:left;">
                  <th style="padding:6px 8px;">Item</th>
                  <th style="padding:6px 8px;">Description</th>
                  <th style="padding:6px 8px; text-align:center;">Qty</th>
                  <th style="padding:6px 8px;">Supplier</th>
                  <th style="padding:6px 8px; text-align:right;">Est. Cost</th>
                </tr>
              </thead>
              <tbody>${matRows}</tbody>
            </table>
          </div>
        `;

      case 'floor_plan':
        return `
          <div style="font-size: 0.84rem;">
            <strong style="color:#0A1628; display:block; margin-bottom:4px;">Layout &amp; Facility Plan Notes:</strong>
            <div style="color:#334155; line-height:1.5; background:#F8FAFC; padding:10px 12px; border-radius:6px; border:1px solid #E2E8F0;">
              ${escapeHtml(data.notes || '')}
            </div>
          </div>
        `;

      case 'people':
        if (!Array.isArray(data)) return '';
        let pplRows = data.map(p => `
          <div style="display:inline-flex; align-items:center; gap:6px; background:#F8FAFC; border:1px solid #E2E8F0; padding:4px 10px; border-radius:6px; font-size:0.78rem;">
            <span style="font-weight:700; color:#0284C7;">${escapeHtml(p.role)}:</span>
            <span style="color:#0A1628;">${escapeHtml(p.person)}</span>
            <span style="font-size:0.68rem; color:#64748B;">(${escapeHtml(p.type)})</span>
          </div>
        `).join(' ');
        return `<div style="display:flex; flex-wrap:wrap; gap:8px;">${pplRows}</div>`;

      case 'schedule':
        if (!Array.isArray(data)) return '';
        let schRows = data.map(s => `
          <div style="display:flex; gap:10px; align-items:flex-start; font-size:0.80rem; padding:4px 0; border-bottom:1px dashed #E2E8F0;">
            <span style="font-weight:700; color:#0284C7; min-width:55px;">${escapeHtml(s.time)}</span>
            <div style="flex:1;">
              <strong style="color:#0A1628;">${escapeHtml(s.segment)}</strong>
              <span style="color:#64748B; margin-left:4px;">— ${escapeHtml(s.desc || '')}</span>
            </div>
            <span style="color:#475569; font-size:0.75rem;">PIC: ${escapeHtml(s.pic || '—')}</span>
          </div>
        `).join('');
        return `<div style="display:flex; flex-direction:column; gap:4px;">${schRows}</div>`;

      case 'guidelines':
        return `
          <div style="font-size:0.82rem; display:flex; flex-direction:column; gap:6px;">
            <div>
              <strong style="color:#0A1628;">Mechanics:</strong>
              <div style="white-space:pre-line; color:#334155; margin-top:2px;">${escapeHtml(data.mechanics || '')}</div>
            </div>
            <div>
              <strong style="color:#0A1628;">Judging Criteria:</strong>
              <div style="white-space:pre-line; color:#334155; margin-top:2px;">${escapeHtml(data.criteria || '')}</div>
            </div>
            <div>
              <strong style="color:#0A1628;">Scoring &amp; Awards:</strong>
              <div style="color:#475569; margin-top:2px;">${escapeHtml(data.scoring || '')} · ${escapeHtml(data.awards || '')}</div>
            </div>
          </div>
        `;

      case 'faculty_tasks':
        if (!Array.isArray(data)) return '';
        let ftRows = data.map(t => `
          <div style="background:#F8FAFC; border:1px solid #E2E8F0; padding:8px 10px; border-radius:6px; font-size:0.80rem; margin-bottom:6px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <strong style="color:#0A1628;">${escapeHtml(t.task)}</strong>
              <span style="font-size:0.72rem; color:#64748B;">Due: ${escapeHtml(t.due_date || 'TBD')}</span>
            </div>
            <div style="color:#475569; margin-top:2px;">${escapeHtml(t.contribution || '')} (Role: ${escapeHtml(t.role || 'Faculty')})</div>
          </div>
        `).join('');
        return `<div>${ftRows}</div>`;

      case 'evaluation_kpi':
        if (!Array.isArray(data)) return '';
        let kpiRows = data.map(k => `
          <div style="display:flex; justify-content:space-between; align-items:center; background:#F8FAFC; border:1px solid #E2E8F0; padding:8px 12px; border-radius:6px; font-size:0.80rem; margin-bottom:6px;">
            <div>
              <strong style="color:#0A1628;">${escapeHtml(k.indicator)}</strong>
              <div style="font-size:0.74rem; color:#64748B;">Method: ${escapeHtml(k.method || 'Evaluation Form')}</div>
            </div>
            <span style="font-weight:700; color:#16A34A; background:#DCFCE7; padding:2px 8px; border-radius:6px; font-size:0.75rem;">
              Target: ${escapeHtml(k.target)}
            </span>
          </div>
        `).join('');
        return `<div>${kpiRows}</div>`;

      default:
        return `<pre style="font-size:0.75rem; background:#F8FAFC; padding:8px;">${escapeHtml(JSON.stringify(data, null, 2))}</pre>`;
    }
  }

  function renderEditView(secKey, data) {
    switch (secKey) {
      case 'objectives':
        return `
          <div style="display:flex; flex-direction:column; gap:8px;">
            <div>
              <label style="font-size:0.74rem; font-weight:700;">General Objective:</label>
              <textarea id="edit_gen_obj" class="form-control" rows="2" style="font-size:0.82rem;">${escapeHtml(data.general_objectives || '')}</textarea>
            </div>
            <div>
              <label style="font-size:0.74rem; font-weight:700;">Specific Objectives:</label>
              <textarea id="edit_spec_obj" class="form-control" rows="3" style="font-size:0.82rem;">${escapeHtml(data.specific_objectives || '')}</textarea>
            </div>
            <div>
              <label style="font-size:0.74rem; font-weight:700;">Rationale:</label>
              <textarea id="edit_rationale" class="form-control" rows="2" style="font-size:0.82rem;">${escapeHtml(data.rationale || '')}</textarea>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
              <button type="button" class="btn btn-outline btn-xs" onclick="AIProposalGuidance.toggleEditSection('${secKey}')">Cancel</button>
              <button type="button" class="btn btn-primary btn-xs" onclick="AIProposalGuidance.saveSectionEdits('${secKey}')">Save Changes</button>
            </div>
          </div>
        `;

      case 'floor_plan':
        return `
          <div>
            <label style="font-size:0.74rem; font-weight:700;">Floor Plan Layout Notes:</label>
            <textarea id="edit_fp_notes" class="form-control" rows="4" style="font-size:0.82rem;">${escapeHtml(data.notes || '')}</textarea>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
              <button type="button" class="btn btn-outline btn-xs" onclick="AIProposalGuidance.toggleEditSection('${secKey}')">Cancel</button>
              <button type="button" class="btn btn-primary btn-xs" onclick="AIProposalGuidance.saveSectionEdits('${secKey}')">Save Changes</button>
            </div>
          </div>
        `;

      case 'guidelines':
        return `
          <div style="display:flex; flex-direction:column; gap:8px;">
            <div>
              <label style="font-size:0.74rem; font-weight:700;">Mechanics:</label>
              <textarea id="edit_guidelines_mech" class="form-control" rows="3" style="font-size:0.82rem;">${escapeHtml(data.mechanics || '')}</textarea>
            </div>
            <div>
              <label style="font-size:0.74rem; font-weight:700;">Judging Criteria:</label>
              <textarea id="edit_guidelines_crit" class="form-control" rows="2" style="font-size:0.82rem;">${escapeHtml(data.criteria || '')}</textarea>
            </div>
            <div>
              <label style="font-size:0.74rem; font-weight:700;">Scoring System:</label>
              <input type="text" id="edit_guidelines_score" class="form-control" style="font-size:0.82rem;" value="${escapeHtml(data.scoring || '')}">
            </div>
            <div>
              <label style="font-size:0.74rem; font-weight:700;">Special Awards:</label>
              <input type="text" id="edit_guidelines_awards" class="form-control" style="font-size:0.82rem;" value="${escapeHtml(data.awards || '')}">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
              <button type="button" class="btn btn-outline btn-xs" onclick="AIProposalGuidance.toggleEditSection('${secKey}')">Cancel</button>
              <button type="button" class="btn btn-primary btn-xs" onclick="AIProposalGuidance.saveSectionEdits('${secKey}')">Save Changes</button>
            </div>
          </div>
        `;

      default:
        // Structured array editor via formatted JSON text
        return `
          <div>
            <label style="font-size:0.74rem; font-weight:700;">Edit Items (JSON Format):</label>
            <textarea id="edit_json_${secKey}" class="form-control" rows="6" style="font-family:monospace; font-size:0.78rem;">${escapeHtml(JSON.stringify(data, null, 2))}</textarea>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
              <button type="button" class="btn btn-outline btn-xs" onclick="AIProposalGuidance.toggleEditSection('${secKey}')">Cancel</button>
              <button type="button" class="btn btn-primary btn-xs" onclick="AIProposalGuidance.saveSectionEdits('${secKey}')">Save Changes</button>
            </div>
          </div>
        `;
    }
  }

  function toggleEditSection(secKey) {
    _editingSections[secKey] = !_editingSections[secKey];
    renderSuggestions();
  }

  function saveSectionEdits(secKey) {
    try {
      switch (secKey) {
        case 'objectives':
          _currentSuggestions.objectives.general_objectives = document.getElementById('edit_gen_obj')?.value || '';
          _currentSuggestions.objectives.specific_objectives = document.getElementById('edit_spec_obj')?.value || '';
          _currentSuggestions.objectives.rationale = document.getElementById('edit_rationale')?.value || '';
          break;

        case 'floor_plan':
          _currentSuggestions.floor_plan.notes = document.getElementById('edit_fp_notes')?.value || '';
          break;

        case 'guidelines':
          _currentSuggestions.guidelines.mechanics = document.getElementById('edit_guidelines_mech')?.value || '';
          _currentSuggestions.guidelines.criteria = document.getElementById('edit_guidelines_crit')?.value || '';
          _currentSuggestions.guidelines.scoring = document.getElementById('edit_guidelines_score')?.value || '';
          _currentSuggestions.guidelines.awards = document.getElementById('edit_guidelines_awards')?.value || '';
          break;

        default:
          const raw = document.getElementById(`edit_json_${secKey}`)?.value || '';
          _currentSuggestions[secKey] = JSON.parse(raw);
          break;
      }

      _editingSections[secKey] = false;
      renderSuggestions();
      showToast(`✓ Edits saved for ${SECTION_CONFIG[secKey].name}. Click Apply to update the form.`);
    } catch (err) {
      alert('Failed to save edits: ' + err.message);
    }
  }

  function toggleSectionSelection(secKey, checked) {
    if (checked) {
      _selectedForApply.add(secKey);
    } else {
      _selectedForApply.delete(secKey);
    }
    updateApplySelectedButton();
  }

  function updateApplySelectedButton() {
    const btn = document.getElementById('btnApplySelected');
    if (btn) {
      btn.textContent = `Apply Selected (${_selectedForApply.size})`;
    }
  }

  /**
   * Checks whether the target proposal form fields currently contain non-empty user content.
   */
  function checkExistingContent(secKey) {
    const form = getFormElement();
    if (!form) return false;

    switch (secKey) {
      case 'objectives':
        const gen = form.querySelector('[name="general_objectives"]')?.value.trim();
        const spec = form.querySelector('[name="specific_objectives"]')?.value.trim();
        const rat = form.querySelector('[name="rationale"]')?.value.trim();
        return !!(gen || spec || rat);

      case 'materials':
        const matItems = form.querySelectorAll('input[name="mat_item[]"]');
        for (let el of matItems) {
          if (el.value.trim() !== '') return true;
        }
        return false;

      case 'floor_plan':
        const fpNotes = form.querySelector('[name="floor_plan_notes"]')?.value.trim();
        return !!fpNotes;

      case 'people':
        const persons = form.querySelectorAll('input[name="mp_person[]"]');
        for (let el of persons) {
          if (el.value.trim() !== '') return true;
        }
        return false;

      case 'schedule':
        const segs = form.querySelectorAll('input[name="prog_segment[]"]');
        for (let el of segs) {
          if (el.value.trim() !== '') return true;
        }
        return false;

      case 'guidelines':
        const mech = form.querySelector('[name="guidelines_mechanics"]')?.value.trim();
        const crit = form.querySelector('[name="guidelines_criteria"]')?.value.trim();
        return !!(mech || crit);

      case 'faculty_tasks':
        const tasks = form.querySelectorAll('input[name="ft_task[]"]');
        for (let el of tasks) {
          if (el.value.trim() !== '') return true;
        }
        return false;

      case 'evaluation_kpi':
        const kpis = form.querySelectorAll('input[name="kpi_indicator[]"]');
        for (let el of kpis) {
          if (el.value.trim() !== '') return true;
        }
        return false;

      default:
        return false;
    }
  }

  /**
   * Applies a single section to the proposal wizard form fields without submitting.
   */
  async function applySection(secKey, skipConfirm = false) {
    const data = _currentSuggestions[secKey];
    if (!data) return false;

    const form = getFormElement();
    if (!form) {
      alert('Proposal form not found.');
      return false;
    }

    // Critical data preservation: Check for existing content and prompt user
    if (!skipConfirm && checkExistingContent(secKey)) {
      const msg = `The "${SECTION_CONFIG[secKey].name}" section in your proposal already contains content.\n\nDo you want to replace it with the AI suggestion?`;
      const confirmed = window.STIModal
        ? await window.STIModal.confirm(msg, { title: 'Replace Existing Content?', confirmText: 'Yes, Replace', cancelText: 'Cancel', variant: 'warning' })
        : confirm(msg);
      if (!confirmed) return false;
    }

    try {
      switch (secKey) {
        case 'objectives':
          const fGen = form.querySelector('[name="general_objectives"]');
          const fSpec = form.querySelector('[name="specific_objectives"]');
          const fRat = form.querySelector('[name="rationale"]');
          const fSubj = form.querySelector('[name="involved_subjects"]');

          if (fGen && data.general_objectives) { fGen.value = data.general_objectives; fGen.dispatchEvent(new Event('input', { bubbles: true })); }
          if (fSpec && data.specific_objectives) { fSpec.value = data.specific_objectives; fSpec.dispatchEvent(new Event('input', { bubbles: true })); }
          if (fRat && data.rationale) { fRat.value = data.rationale; fRat.dispatchEvent(new Event('input', { bubbles: true })); }
          if (fSubj && data.involved_subjects && !fSubj.value.trim()) { fSubj.value = data.involved_subjects; fSubj.dispatchEvent(new Event('input', { bubbles: true })); }
          break;

        case 'materials':
          if (Array.isArray(data) && data.length > 0) {
            const tbody = document.getElementById('materials-body');
            if (tbody) {
              tbody.innerHTML = '';
              data.forEach((m) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                  <td><input type="text" name="mat_item[]" class="form-control mat-field" placeholder="e.g. Chairs" value="${escapeHtml(m.item || '')}"></td>
                  <td><input type="text" name="mat_desc[]" class="form-control mat-field" placeholder="Description" value="${escapeHtml(m.desc || '')}"></td>
                  <td><input type="number" name="mat_qty[]" class="form-control mat-qty" value="${m.qty || 1}" min="1"></td>
                  <td><input type="text" name="mat_provider[]" class="form-control mat-field" placeholder="Supplier name" value="${escapeHtml(m.provider || '')}"></td>
                  <td><input type="number" name="mat_cost[]" class="form-control mat-cost" placeholder="0.00" step="0.01" min="0" value="${m.cost !== undefined ? m.cost : '0.00'}"></td>
                  <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                `;
                tbody.appendChild(tr);
              });
              if (typeof window.updateTotal === 'function') window.updateTotal();
            }
          }
          break;

        case 'floor_plan':
          const fFp = form.querySelector('[name="floor_plan_notes"]');
          if (fFp && data.notes) {
            fFp.value = data.notes;
            fFp.dispatchEvent(new Event('input', { bubbles: true }));
          }
          break;

        case 'people':
          if (Array.isArray(data) && data.length > 0) {
            const tbody = document.getElementById('manpower-body');
            if (tbody) {
              tbody.innerHTML = '';
              data.forEach((p) => {
                const rawType = String(p.type || 'student').toLowerCase().trim();
                const normType = (rawType === 'faculty' || rawType === 'staff' || rawType === 'student') ? rawType : 'faculty';
                const tr = document.createElement('tr');
                tr.innerHTML = `
                  <td>
                    <select name="mp_role[]" class="form-control" onchange="updateManpowerSummary()">
                      <option value="${escapeHtml(p.role)}" selected>${escapeHtml(p.role)}</option>
                      <option value="Medic">Medic</option>
                      <option value="Customer Service">Customer Service</option>
                      <option value="Usher">Usher</option>
                      <option value="Marshall">Marshall</option>
                      <option value="Emcee">Emcee</option>
                      <option value="Floor Director">Floor Director</option>
                      <option value="Sound & AVP Support">Sound & AVP Support</option>
                    </select>
                  </td>
                  <td><input type="text" name="mp_person[]" class="form-control mp-person-field" placeholder="Full name" value="${escapeHtml(p.person || '')}" oninput="updateManpowerSummary()"></td>
                  <td>
                    <select name="mp_type[]" class="form-control" onchange="updateManpowerSummary()">
                      <option value="faculty" ${normType === 'faculty' ? 'selected' : ''}>Faculty</option>
                      <option value="staff" ${normType === 'staff' ? 'selected' : ''}>Staff</option>
                      <option value="student" ${normType === 'student' ? 'selected' : ''}>Student</option>
                    </select>
                  </td>
                  <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this); updateManpowerSummary();">✕</button></td>
                `;
                tbody.appendChild(tr);
              });
              if (typeof window.updateManpowerSummary === 'function') window.updateManpowerSummary();
            }
          }
          break;

        case 'schedule':
          if (Array.isArray(data) && data.length > 0) {
            const tbody = document.getElementById('program-body');
            if (tbody) {
              tbody.innerHTML = '';
              data.forEach((s) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                  <td><input type="time" name="prog_time[]" class="form-control prog-field" value="${escapeHtml(s.time || '09:00')}"></td>
                  <td><input type="text" name="prog_segment[]" class="form-control prog-field" placeholder="Segment" value="${escapeHtml(s.segment || '')}"></td>
                  <td><input type="text" name="prog_desc[]" class="form-control prog-field" placeholder="Description" value="${escapeHtml(s.desc || '')}"></td>
                  <td><input type="text" name="prog_pic[]" class="form-control prog-field" placeholder="Name" value="${escapeHtml(s.pic || '')}"></td>
                  <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                `;
                tbody.appendChild(tr);
              });
            }
            const schedBody = document.getElementById('schedule-body');
            if (schedBody) {
              const firstSchedEvent = schedBody.querySelector('input[name="sched_event[]"]');
              if (firstSchedEvent && !firstSchedEvent.value.trim()) {
                const evDateVal = document.getElementById('f1_event_date')?.value || '';
                const evTitleVal = document.getElementById('f1_title')?.value || (data[0]?.segment || 'Main Activity');
                const evVenueVal = document.getElementById('f1_venue')?.value || 'Campus Venue';
                const evOrgVal = data[0]?.pic || 'Organizing Committee';
                const sDateInp = schedBody.querySelector('input[name="sched_date[]"]');
                const sVenueInp = schedBody.querySelector('input[name="sched_venue[]"]');
                const sOrgInp = schedBody.querySelector('input[name="sched_organizer[]"]');
                if (sDateInp && !sDateInp.value && evDateVal) sDateInp.value = evDateVal;
                firstSchedEvent.value = evTitleVal;
                if (sVenueInp && !sVenueInp.value) sVenueInp.value = evVenueVal;
                if (sOrgInp && !sOrgInp.value) sOrgInp.value = evOrgVal;
              }
            }
          }
          break;

        case 'guidelines':
          const fMech = form.querySelector('[name="guidelines_mechanics"]');
          const fCrit = form.querySelector('[name="guidelines_criteria"]');
          const fScore = form.querySelector('[name="guidelines_scoring"]');
          const fAwards = form.querySelector('[name="guidelines_awards"]');

          if (fMech && data.mechanics) { fMech.value = data.mechanics; fMech.dispatchEvent(new Event('input', { bubbles: true })); }
          if (fCrit && data.criteria) { fCrit.value = data.criteria; fCrit.dispatchEvent(new Event('input', { bubbles: true })); }
          if (fScore && data.scoring) { fScore.value = data.scoring; fScore.dispatchEvent(new Event('input', { bubbles: true })); }
          if (fAwards && data.awards) { fAwards.value = data.awards; fAwards.dispatchEvent(new Event('input', { bubbles: true })); }
          break;

        case 'faculty_tasks':
          if (Array.isArray(data) && data.length > 0) {
            const tbody = document.getElementById('faculty-tasks-body');
            if (tbody) {
              tbody.innerHTML = '';
              const facOpts = (typeof facultyOptionsHtml !== 'undefined') ? facultyOptionsHtml : '';
              data.forEach((t) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                  <td>
                    <select name="ft_user_id[]" class="form-control ft-user-select" onchange="syncFtName(this)">
                      <option value="">-- Unassigned / External --</option>
                      ${facOpts}
                    </select>
                    <input type="text" name="ft_name[]" class="form-control ft-name-field" style="margin-top: 4px; font-size: 0.8rem;" placeholder="External name (if unassigned)" value="${escapeHtml(t.role || '')}">
                  </td>
                  <td><input type="text" name="ft_task[]" class="form-control ft-field" placeholder="Assigned task" value="${escapeHtml(t.task || '')}"></td>
                  <td><input type="text" name="ft_contribution[]" class="form-control ft-field" placeholder="Contribution" value="${escapeHtml(t.contribution || '')}"></td>
                  <td><input type="text" name="ft_role[]" class="form-control ft-field" placeholder="Role" value="${escapeHtml(t.role || '')}"></td>
                  <td><input type="date" name="ft_due_date[]" class="form-control ft-date-field" value="${escapeHtml(t.due_date || '')}"></td>
                  <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                `;
                tbody.appendChild(tr);
              });
            }
          }
          break;

        case 'evaluation_kpi':
          if (Array.isArray(data) && data.length > 0) {
            const tbody = document.getElementById('kpi-body');
            if (tbody) {
              tbody.innerHTML = '';
              data.forEach((k) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                  <td style="padding: 10px 12px;"><input type="text" name="kpi_indicator[]" class="form-control kpi-field" placeholder="e.g. Attendance" value="${escapeHtml(k.indicator || '')}"></td>
                  <td style="padding: 10px 12px;"><input type="text" name="kpi_target[]" class="form-control kpi-field" placeholder="Target" value="${escapeHtml(k.target || '')}"></td>
                  <td style="padding: 10px 12px;"><input type="text" name="kpi_method[]" class="form-control kpi-field" placeholder="Method" value="${escapeHtml(k.method || '')}"></td>
                  <td style="text-align: center; padding: 10px 12px; vertical-align: middle;">
                    <button type="button" class="btn btn-outline btn-sm btn-delete-kpi" onclick="removeKpiRow(this)" style="padding: 4px 8px; color: #ef4444; border-color: #fca5a5;">✕</button>
                  </td>
                `;
                tbody.appendChild(tr);
              });
            }
          }
          break;
      }

      _appliedSections.add(secKey);
      renderSuggestions();
      showToast(`✓ Applied ${SECTION_CONFIG[secKey].name} to proposal form.`);
      return true;

    } catch (err) {
      console.error(`Error applying section ${secKey}:`, err);
      alert(`Failed to apply ${SECTION_CONFIG[secKey].name}: ` + err.message);
      return false;
    }
  }

  async function applySelected() {
    if (_selectedForApply.size === 0) {
      alert('Please check at least one section suggestion to apply.');
      return;
    }

    const selectedKeys = Array.from(_selectedForApply).filter((k) => !!_currentSuggestions[k]);
    if (selectedKeys.length === 0) {
      alert('No valid suggestions found for selected sections.');
      return;
    }

    // Check existing content across all selected sections
    const overwriteSections = selectedKeys.filter((k) => checkExistingContent(k));
    if (overwriteSections.length > 0) {
      const names = overwriteSections.map((k) => `• ${SECTION_CONFIG[k].name}`).join('\n');
      const msg = `The following sections currently contain existing content:\n\n${names}\n\nDo you want to replace them with the AI suggestions?`;
      const confirmed = window.STIModal
        ? await window.STIModal.confirm(msg, { title: 'Replace Existing Content?', confirmText: 'Yes, Replace Selected', cancelText: 'Cancel', variant: 'warning' })
        : confirm(msg);
      if (!confirmed) return;
    }

    let appliedCount = 0;
    for (const key of selectedKeys) {
      if (await applySection(key, true)) {
        appliedCount++;
      }
    }

    showToast(`✓ Applied ${appliedCount} section(s) to proposal form.`);
  }

  async function applyAll() {
    const availableKeys = Object.keys(_currentSuggestions);
    if (availableKeys.length === 0) {
      alert('No AI suggestions available to apply. Please generate suggestions first.');
      return;
    }

    const overwriteSections = availableKeys.filter((k) => checkExistingContent(k));
    if (overwriteSections.length > 0) {
      const names = overwriteSections.map((k) => `• ${SECTION_CONFIG[k].name}`).join('\n');
      const msg = `The following sections currently contain existing content:\n\n${names}\n\nDo you want to replace them with all AI suggestions?`;
      const confirmed = window.STIModal
        ? await window.STIModal.confirm(msg, { title: 'Replace All Existing Content?', confirmText: 'Yes, Replace All', cancelText: 'Cancel', variant: 'warning' })
        : confirm(msg);
      if (!confirmed) return;
    }

    let appliedCount = 0;
    for (const key of availableKeys) {
      if (await applySection(key, true)) {
        appliedCount++;
      }
    }

    showToast(`✓ Applied all ${appliedCount} section suggestions to proposal form.`);
  }

  function showToast(msg) {
    let existing = document.getElementById('aiGuidanceToast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.id = 'aiGuidanceToast';
    toast.className = 'ai-toast-notify';
    toast.innerHTML = `<span>✨</span><span>${escapeHtml(msg)}</span>`;
    document.body.appendChild(toast);

    setTimeout(() => {
      if (toast) toast.remove();
    }, 3800);
  }

  function init(options) {
    options = options || {};
    _baseUrl = (options.baseUrl || getBaseUrl() || '').replace(/\/$/, '');
    _formEl = typeof options.formSelector === 'string'
      ? document.querySelector(options.formSelector)
      : options.formSelector;

    ensureModalDom();
  }

  return {
    init,
    requestGuidance,
    openModal,
    closeModal,
    generateSections,
    applySection,
    applySelected,
    applyAll,
    toggleEditSection,
    saveSectionEdits,
    toggleSectionSelection,
    toggleAllSections
  };
})();

// Auto-initialize when DOM is ready
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => AIProposalGuidance.init());
  } else {
    AIProposalGuidance.init();
  }
}
