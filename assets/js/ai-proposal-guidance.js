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
      name: 'Objectives & Rationale',
      icon: '🎯',
      fields: ['rationale', 'involved_subjects']
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
    // 1. Direct ID check (primary standard input in STI-ARMES)
    const byId = document.getElementById('f1_title');
    if (byId && byId.value && byId.value.trim()) {
      return byId.value.trim();
    }

    // 2. Query within form or whole document by name="title"
    const form = getFormElement();
    const byName = (form ? form.querySelector('input[name="title"]') : null) || document.querySelector('input[name="title"]') || (form ? form.querySelector('[name="title"]') : null) || document.querySelector('[name="title"]');
    if (byName && byName.value && byName.value.trim()) {
      return byName.value.trim();
    }

    // 3. Alternative name attributes (activity_title, event_title)
    const byAltName = (form ? form.querySelector('[name="activity_title"], [name="event_title"]') : null) || document.querySelector('[name="activity_title"], [name="event_title"]');
    if (byAltName && byAltName.value && byAltName.value.trim()) {
      return byAltName.value.trim();
    }

    // 4. Global JS variables if present
    if (typeof window !== 'undefined') {
      if (typeof window.ACTIVITY_TITLE === 'string' && window.ACTIVITY_TITLE.trim()) {
        return window.ACTIVITY_TITLE.trim();
      }
      if (typeof window.PROPOSAL_TITLE === 'string' && window.PROPOSAL_TITLE.trim()) {
        return window.PROPOSAL_TITLE.trim();
      }
    }

    // 5. Check offline draft title if available
    if (typeof OfflineDrafts !== 'undefined' && typeof OfflineDrafts.getCurrentDraft === 'function') {
      const draft = OfflineDrafts.getCurrentDraft();
      if (draft && draft.title && draft.title.trim() && draft.title !== 'Untitled Proposal Draft' && draft.title !== 'Untitled Draft') {
        return draft.title.trim();
      }
    }

    return '';
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
          border: 4px solid var(--aig-border, #E2E8F0);
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

        /* ── Modal & Theme Variables ── */
        #aiProposalGuidanceModal {
          --aig-bg-backdrop:    rgba(10, 22, 40, 0.65);
          --aig-bg-modal:       #FFFFFF;
          --aig-bg-header:      #F8FAFC;
          --aig-bg-panel-left:  #F8FAFC;
          --aig-bg-panel-right: #FFFFFF;
          --aig-bg-box:         #FFFFFF;
          --aig-bg-subtle:      #F8FAFC;
          --aig-text-main:      #0A1628;
          --aig-text-secondary: #334155;
          --aig-text-muted:     #64748B;
          --aig-border:         #E2E8F0;
          --aig-border-subtle:  #F1F5F9;
          --aig-card-bg:        #FFFFFF;
          --aig-badge-bg:       #E0F2FE;
          --aig-badge-color:    #0284C7;
          --aig-table-th-bg:    #F8FAFC;
          --aig-table-border:   #E2E8F0;
          --aig-table-td-border:#F1F5F9;
          --aig-input-bg:       #FFFFFF;
          --aig-input-color:    #0A1628;
          --aig-input-border:   #CBD5E1;
          --aig-pill-bg:        #F1F5F9;
          --aig-pill-color:     #475569;
        }

        [data-theme="dark"] #aiProposalGuidanceModal,
        html[data-theme="dark"] #aiProposalGuidanceModal,
        body.dark-mode #aiProposalGuidanceModal {
          --aig-bg-backdrop:    rgba(7, 14, 24, 0.85);
          --aig-bg-modal:       #0F1B2E;
          --aig-bg-header:      #0B1524;
          --aig-bg-panel-left:  #15243C;
          --aig-bg-panel-right: #0F1B2E;
          --aig-bg-box:         #0B1524;
          --aig-bg-subtle:      #0B1524;
          --aig-text-main:      #FFFFFF;
          --aig-text-secondary: #CBD5E1;
          --aig-text-muted:     #8899B2;
          --aig-border:         #1E2D45;
          --aig-border-subtle:  #162438;
          --aig-card-bg:        #15243C;
          --aig-badge-bg:       rgba(2, 132, 199, 0.22);
          --aig-badge-color:    #38BDF8;
          --aig-table-th-bg:    #0B1524;
          --aig-table-border:   #1E2D45;
          --aig-table-td-border:#162438;
          --aig-input-bg:       #070E18;
          --aig-input-color:    #FFFFFF;
          --aig-input-border:   #1E2D45;
          --aig-pill-bg:        #15243C;
          --aig-pill-color:     #CBD5E1;
        }

        #aiProposalGuidanceModal .modal-content {
          background: var(--aig-bg-modal) !important;
          color: var(--aig-text-main) !important;
          border: 1px solid var(--aig-border) !important;
          box-shadow: 0 16px 48px rgba(0, 0, 0, 0.35);
        }

        #aiProposalGuidanceModal .modal-header {
          background: var(--aig-bg-header) !important;
          border-bottom: 1px solid var(--aig-border) !important;
          color: var(--aig-text-main) !important;
        }

        #aiProposalGuidanceModal .modal-footer {
          background: var(--aig-bg-header) !important;
          border-top: 1px solid var(--aig-border) !important;
          color: var(--aig-text-muted) !important;
        }

        #aiProposalGuidanceModal .ai-guidance-left-panel {
          background: var(--aig-bg-panel-left) !important;
          border: 1.5px solid var(--aig-border) !important;
          color: var(--aig-text-main) !important;
          border-radius: 12px;
          padding: 18px;
          display: flex;
          flex-direction: column;
          justify-content: space-between;
          gap: 16px;
        }

        #aiProposalGuidanceModal .ai-guidance-right-panel {
          background: var(--aig-bg-panel-right) !important;
          border: 1.5px solid var(--aig-border) !important;
          color: var(--aig-text-main) !important;
          border-radius: 12px;
          padding: 18px;
          display: flex;
          flex-direction: column;
          min-height: 480px;
        }

        #aiProposalGuidanceModal .ai-context-title-box {
          background: var(--aig-bg-box) !important;
          border: 1px solid var(--aig-border) !important;
          border-radius: 8px;
          padding: 12px;
          margin-bottom: 16px;
        }

        #aiProposalGuidanceModal .ai-title-badge {
          background: var(--aig-badge-bg) !important;
          color: var(--aig-badge-color) !important;
          border: 1px solid var(--aig-border) !important;
          font-size: 0.65rem;
          font-weight: 700;
          padding: 2px 8px;
          border-radius: 999px;
        }

        #aiProposalGuidanceModal .ai-title-display {
          font-size: 0.85rem;
          font-weight: 700;
          color: var(--aig-text-main) !important;
          line-height: 1.35;
        }

        #aiProposalGuidanceModal .ai-section-item-label {
          display: flex;
          align-items: center;
          gap: 8px;
          font-size: 0.82rem;
          color: var(--aig-text-main) !important;
          cursor: pointer;
          user-select: none;
          margin: 0;
          padding: 5px 8px;
          border-radius: 6px;
          transition: background 0.15s ease;
        }

        #aiProposalGuidanceModal .ai-section-item-label:hover {
          background: rgba(2, 132, 199, 0.12) !important;
        }

        #aiProposalGuidanceModal .ai-card-item {
          background: var(--aig-card-bg) !important;
          border: 1px solid var(--aig-border) !important;
          color: var(--aig-text-main) !important;
          border-radius: 10px;
          padding: 16px;
          margin-bottom: 14px;
          box-shadow: 0 1px 4px rgba(0,0,0,0.06);
          transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        #aiProposalGuidanceModal .ai-card-item:hover {
          border-color: var(--sti-blue, #0284C7) !important;
          box-shadow: 0 4px 14px rgba(0,0,0,0.2) !important;
        }

        #aiProposalGuidanceModal .ai-card-item.applied {
          border-color: #10B981 !important;
          background: rgba(16, 185, 129, 0.12) !important;
        }

        #aiProposalGuidanceModal .ai-card-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 12px;
          padding-bottom: 10px;
          border-bottom: 1px solid var(--aig-border) !important;
        }

        #aiProposalGuidanceModal .ai-card-title {
          font-size: 0.92rem;
          font-weight: 700;
          color: var(--aig-text-main) !important;
        }

        #aiProposalGuidanceModal .ai-preview-box {
          background: var(--aig-bg-subtle) !important;
          border: 1px solid var(--aig-border) !important;
          color: var(--aig-text-secondary) !important;
          border-radius: 6px;
          padding: 10px 12px;
          line-height: 1.45;
        }

        #aiProposalGuidanceModal .ai-label-header {
          color: var(--aig-text-main) !important;
          font-weight: 700;
          display: block;
          margin-bottom: 3px;
          font-size: 0.82rem;
        }

        #aiProposalGuidanceModal .ai-preview-table {
          width: 100%;
          font-size: 0.80rem;
          border-collapse: collapse;
        }

        #aiProposalGuidanceModal .ai-preview-table th {
          background: var(--aig-table-th-bg) !important;
          color: var(--aig-text-main) !important;
          border-bottom: 1px solid var(--aig-table-border) !important;
          padding: 6px 8px;
          font-weight: 700;
        }

        #aiProposalGuidanceModal .ai-preview-table td {
          color: var(--aig-text-main) !important;
          border-bottom: 1px solid var(--aig-table-td-border) !important;
          padding: 6px 8px;
        }

        #aiProposalGuidanceModal .ai-person-pill {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          background: var(--aig-bg-subtle) !important;
          border: 1px solid var(--aig-border) !important;
          color: var(--aig-text-main) !important;
          padding: 4px 10px;
          border-radius: 6px;
          font-size: 0.78rem;
        }

        #aiProposalGuidanceModal .ai-schedule-row {
          display: flex;
          gap: 10px;
          align-items: flex-start;
          font-size: 0.80rem;
          padding: 6px 0;
          border-bottom: 1px dashed var(--aig-border) !important;
          color: var(--aig-text-main) !important;
        }

        #aiProposalGuidanceModal .ai-task-card {
          background: var(--aig-bg-subtle) !important;
          border: 1px solid var(--aig-border) !important;
          padding: 8px 10px;
          border-radius: 6px;
          font-size: 0.80rem;
          margin-bottom: 6px;
          color: var(--aig-text-main) !important;
        }

        #aiProposalGuidanceModal .ai-kpi-card {
          display: flex;
          justify-content: space-between;
          align-items: center;
          background: var(--aig-bg-subtle) !important;
          border: 1px solid var(--aig-border) !important;
          padding: 8px 12px;
          border-radius: 6px;
          font-size: 0.80rem;
          margin-bottom: 6px;
          color: var(--aig-text-main) !important;
        }

        #aiProposalGuidanceModal .ai-kpi-target-badge {
          font-weight: 700;
          color: #16A34A !important;
          background: #DCFCE7 !important;
          padding: 2px 8px;
          border-radius: 6px;
          font-size: 0.75rem;
        }

        [data-theme="dark"] #aiProposalGuidanceModal .ai-kpi-target-badge,
        html[data-theme="dark"] #aiProposalGuidanceModal .ai-kpi-target-badge {
          color: #4ADE80 !important;
          background: rgba(34, 197, 94, 0.18) !important;
          border: 1px solid rgba(34, 197, 94, 0.3) !important;
        }

        #aiProposalGuidanceModal .ai-applied-badge {
          font-size: 0.68rem;
          font-weight: 700;
          color: #16A34A !important;
          background: #DCFCE7 !important;
          padding: 2px 8px;
          border-radius: 999px;
        }

        [data-theme="dark"] #aiProposalGuidanceModal .ai-applied-badge,
        html[data-theme="dark"] #aiProposalGuidanceModal .ai-applied-badge {
          color: #4ADE80 !important;
          background: rgba(34, 197, 94, 0.18) !important;
        }

        #aiProposalGuidanceModal .ai-ready-badge {
          font-size: 0.68rem;
          font-weight: 600;
          color: var(--aig-text-muted) !important;
          background: var(--aig-bg-subtle) !important;
          border: 1px solid var(--aig-border) !important;
          padding: 2px 8px;
          border-radius: 999px;
        }

        #aiProposalGuidanceModal .ai-notice-box {
          background: var(--aig-badge-bg) !important;
          border: 1px solid var(--aig-border) !important;
          color: var(--aig-badge-color) !important;
          padding: 6px 10px;
          border-radius: 6px;
          font-size: 0.75rem;
          display: flex;
          align-items: center;
          gap: 6px;
        }

        #aiProposalGuidanceModal .ai-empty-title,
        #aiProposalGuidanceModal .ai-loading-title {
          font-size: 1rem;
          font-weight: 700;
          color: var(--aig-text-main) !important;
          margin-bottom: 8px;
        }

        #aiProposalGuidanceModal .ai-empty-desc,
        #aiProposalGuidanceModal .ai-loading-desc {
          font-size: 0.84rem;
          max-width: 440px;
          margin: 0 auto 18px auto;
          line-height: 1.5;
          color: var(--aig-text-muted) !important;
        }

        #aiProposalGuidanceModal .ai-empty-pill {
          display: inline-block;
          background: var(--aig-pill-bg) !important;
          border: 1px solid var(--aig-border) !important;
          color: var(--aig-pill-color) !important;
          padding: 6px 14px;
          border-radius: 999px;
          font-size: 0.75rem;
        }

        #aiProposalGuidanceModal .ai-text-main {
          color: var(--aig-text-main) !important;
        }

        #aiProposalGuidanceModal .ai-text-secondary {
          color: var(--aig-text-secondary) !important;
        }

        #aiProposalGuidanceModal .ai-text-muted {
          color: var(--aig-text-muted) !important;
        }

        #aiProposalGuidanceModal .form-control {
          background: var(--aig-input-bg) !important;
          color: var(--aig-input-color) !important;
          border: 1px solid var(--aig-input-border) !important;
        }

        #aiProposalGuidanceModal .ai-btn-action {
          font-size: 0.74rem;
          padding: 4px 10px;
          font-weight: 600;
          border-radius: 6px;
          background: var(--aig-card-bg) !important;
          border: 1px solid var(--aig-border) !important;
          color: var(--aig-text-main) !important;
          cursor: pointer;
        }

        #aiProposalGuidanceModal .ai-btn-close-footer {
          font-size: 0.82rem;
          padding: 6px 18px;
          border-radius: 6px;
          background: var(--aig-card-bg) !important;
          border: 1px solid var(--aig-border) !important;
          color: var(--aig-text-main) !important;
          cursor: pointer;
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
      modal.style.background = 'var(--aig-bg-backdrop, rgba(10, 22, 40, 0.75))';
      modal.style.backdropFilter = 'blur(8px)';
      modal.style.webkitBackdropFilter = 'blur(8px)';
      modal.style.zIndex = '1060';
      modal.style.overflowY = 'auto';
      modal.style.padding = '16px';

      modal.innerHTML = `
        <div class="modal-dialog modal-dialog-centered" style="margin: 1.5rem auto; max-width: 1140px; width: 100%;">
          <div class="modal-content" style="border-radius: 14px; overflow: hidden; font-family: 'Plus Jakarta Sans', sans-serif;">
            
            <!-- Modal Header -->
            <div class="modal-header" style="padding: 16px 22px; display: flex; align-items: center; justify-content: space-between;">
              <div style="display: flex; align-items: center; gap: 10px;">
                <span style="font-size: 1.4rem;">✨</span>
                <div>
                  <h5 class="ai-text-main" style="margin: 0; font-size: 1.15rem; font-weight: 800; letter-spacing: -0.01em;">
                    AI Proposal Guidance
                  </h5>
                  <span class="ai-text-muted" style="font-size: 0.76rem;">
                    Generate, review, edit, and selectively apply AI suggestions to your proposal form
                  </span>
                </div>
              </div>
              <button type="button" onclick="AIProposalGuidance.closeModal()" aria-label="Close" class="ai-text-muted" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; padding: 2px 8px; border-radius: 6px; line-height: 1;">&times;</button>
            </div>

            <!-- Modal Body (Two-Panel Layout) -->
            <div class="modal-body" style="padding: 20px; max-height: calc(88vh - 120px); overflow-y: auto;">
              <div class="ai-guidance-layout">

                <!-- ── LEFT PANEL: Generate Proposal Content ── -->
                <div class="ai-guidance-left-panel">
                  <div>
                    <h6 class="ai-text-main" style="font-size: 0.95rem; font-weight: 800; margin: 0 0 12px 0; display: flex; align-items: center; gap: 6px;">
                      <span>⚙️</span> Generate Proposal Content
                    </h6>

                    <!-- Activity Title Context Box (Manual, Protected) -->
                    <div class="ai-context-title-box">
                      <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                        <span class="ai-text-muted" style="font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em;">Activity Title</span>
                        <span class="ai-title-badge">Manual (Protected)</span>
                      </div>
                      <div id="aiContextTitleDisplay" class="ai-title-display">
                        <em>Loading title...</em>
                      </div>
                      <div class="ai-text-muted" style="font-size: 0.70rem; margin-top: 4px;">
                        * Title is manually provided and will NEVER be changed by AI.
                      </div>
                    </div>

                    <!-- Section Checkboxes -->
                    <div style="margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                      <span class="ai-text-secondary" style="font-size: 0.78rem; font-weight: 700;">Sections to Generate:</span>
                      <div style="display: flex; gap: 8px; font-size: 0.72rem;">
                        <a href="javascript:void(0)" onclick="AIProposalGuidance.toggleAllSections(true)" style="color: #0284C7; text-decoration: none; font-weight: 600;">Select All</a>
                        <span class="ai-text-muted">|</span>
                        <a href="javascript:void(0)" onclick="AIProposalGuidance.toggleAllSections(false)" class="ai-text-muted" style="text-decoration: none; font-weight: 600;">Clear</a>
                      </div>
                    </div>

                    <div id="aiSectionCheckboxes" style="display: flex; flex-direction: column; gap: 8px;">
                      <!-- Populated dynamically -->
                    </div>
                  </div>

                  <!-- Generate Button -->
                  <div style="padding-top: 12px; border-top: 1px solid var(--aig-border, #E2E8F0);">
                    <button type="button" id="btnGenerateAI" class="btn btn-primary" onclick="AIProposalGuidance.generateSections()" style="width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px; padding: 11px 16px; font-size: 0.88rem; font-weight: 700; border-radius: 8px; background: var(--sti-blue, #0284C7); border: none; color: #FFFFFF; cursor: pointer; transition: background 0.15s ease;">
                      <span>✨</span> Generate with AI
                    </button>
                    <div class="ai-text-muted" style="font-size: 0.68rem; text-align: center; margin-top: 6px;">
                      Suggestions will preview on the right before applying.
                    </div>
                  </div>
                </div>

                <!-- ── RIGHT PANEL: AI Suggestions for Your Proposal ── -->
                <div class="ai-guidance-right-panel">
                  
                  <!-- Right Panel Toolbar -->
                  <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding-bottom: 14px; border-bottom: 1px solid var(--aig-border, #E2E8F0); margin-bottom: 16px;">
                    <div>
                      <h6 class="ai-text-main" style="font-size: 0.95rem; font-weight: 800; margin: 0 0 2px 0;">
                        AI Suggestions for Your Proposal
                      </h6>
                      <span id="aiSuggestionsStatus" class="ai-text-muted" style="font-size: 0.74rem;">
                        Select sections and click "Generate with AI" to preview.
                      </span>
                    </div>

                    <!-- Action Controls -->
                    <div id="aiRightPanelActions" style="display: none; align-items: center; gap: 8px; flex-wrap: wrap;">
                      <button type="button" class="btn btn-outline btn-sm ai-btn-action" onclick="AIProposalGuidance.generateSections(true)" style="display: inline-flex; align-items: center; gap: 5px;">
                        <span>🔄</span> Regenerate
                      </button>
                      <button type="button" id="btnApplySelected" class="btn btn-outline btn-sm ai-btn-action" onclick="AIProposalGuidance.applySelected()" style="border-color: #0284C7; color: #0284C7;">
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
            <div class="modal-footer" style="padding: 12px 22px; display: flex; justify-content: space-between; align-items: center;">
              <div class="ai-text-muted" style="font-size: 0.75rem;">
                🔒 Content is applied only when explicitly confirmed. Generating never auto-submits.
              </div>
              <button type="button" class="ai-btn-close-footer" onclick="AIProposalGuidance.closeModal()">
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
        <label class="ai-section-item-label">
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
      <div style="text-align: center; padding: 60px 20px;">
        <div style="font-size: 3rem; margin-bottom: 14px;">📋</div>
        <h6 class="ai-empty-title">
          Ready to Generate Proposal Suggestions
        </h6>
        <p class="ai-empty-desc">
          Select the sections on the left that you would like AI to assist you with. Suggestions will appear here where you can review, edit, and apply them.
        </p>
        <span class="ai-empty-pill">
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
        <h6 class="ai-loading-title">
          Generating Tailored Suggestions...
        </h6>
        <p class="ai-loading-desc">
          Consulting academic guidelines, STI outcome standards, and logistical templates for your selected sections.
        </p>
      </div>
    `;
  }

  function getMissingEventDetails() {
    const form = getFormElement();

    const requiredFields = [
      { id: 'f1_title', name: 'title', label: 'Event Name / Title', getValue: () => getActivityTitle() },
      { id: 'f1_event_date', name: 'event_date', label: 'Date of Event' },
      { id: 'f1_source', name: 'source', label: 'Source' },
      { id: 'f1_start_time', name: 'start_time', label: 'Start Time' },
      { id: 'f1_end_time', name: 'end_time', label: 'End Time' },
      { id: 'f1_theme', name: 'theme', label: 'Theme' },
      { id: 'f1_venue', name: 'venue', label: 'Venue' },
      { id: 'f1_venue_address', name: 'venue_address', label: 'Venue Address' }
    ];

    const missing = [];
    requiredFields.forEach((field) => {
      let val = '';
      if (typeof field.getValue === 'function') {
        val = field.getValue();
      } else {
        const el = (form ? form.querySelector(`[name="${field.name}"]`) : null) || document.getElementById(field.id);
        val = el ? el.value.trim() : '';
      }
      if (!val) {
        missing.push(field);
      }
    });

    // Target participants check
    const targetParticipantsEl = (form ? form.querySelector('[name="target_participants"]') : null) || document.getElementById('f1_target_participants');
    const targetCount = targetParticipantsEl ? parseInt(targetParticipantsEl.value, 10) : 0;
    const partSectionInputs = (form ? form.querySelectorAll('.part-section-input') : null) || document.querySelectorAll('.part-section-input');
    let hasSection = false;
    if (partSectionInputs) {
      partSectionInputs.forEach((input) => {
        if (input.value.trim()) hasSection = true;
      });
    }

    if (targetCount <= 0 && !hasSection) {
      missing.push({ id: 'f1_target_participants', name: 'target_participants', label: 'Target Participants' });
    }

    return missing;
  }

  function bindTitleListeners() {
    const titleEl = document.getElementById('f1_title') || document.querySelector('input[name="title"]');
    if (titleEl && !titleEl._aiGuidanceBound && typeof titleEl.addEventListener === 'function') {
      titleEl._aiGuidanceBound = true;
      titleEl.addEventListener('input', () => {
        updateContextTitle();
      });
      titleEl.addEventListener('change', () => {
        updateContextTitle();
      });
    }
  }

  async function checkEventDetailsOrWarn() {
    const missing = getMissingEventDetails();
    if (missing.length > 0) {
      const warningMsg = 'You need to fill up the event details first before you can use the request ai guidance feature.\n\nPlease complete the required fields in Event Details (Step 1) before requesting AI guidance.';

      if (window.STIModal && typeof window.STIModal.alert === 'function') {
        await window.STIModal.alert(warningMsg, {
          title: 'Event Details Required',
          icon: '⚠️',
          variant: 'warning',
          confirmText: 'Fill Up Event Details'
        });
      } else {
        alert('You need to fill up the event details first before you can use the request ai guidance feature.');
      }

      // Automatically navigate user to Step 1 (Event Details)
      if (typeof window.showStep === 'function') {
        window.showStep(1);
      } else {
        const step1Btn = document.querySelector('.wizard-step[data-step="1"]');
        if (step1Btn && typeof step1Btn.click === 'function') {
          step1Btn.click();
        }
      }

      // Focus on the first missing field in Event Details
      const firstMissing = missing[0];
      const form = getFormElement();
      const targetEl = (form ? form.querySelector(`[name="${firstMissing.name}"]`) : null) || document.getElementById(firstMissing.id);
      if (targetEl && typeof targetEl.focus === 'function') {
        setTimeout(() => targetEl.focus(), 120);
      }

      return false;
    }
    return true;
  }

  async function openModal() {
    bindTitleListeners();
    const isReady = await checkEventDetailsOrWarn();
    if (!isReady) return;

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

  async function requestGuidance() {
    await openModal();
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

    const isReady = await checkEventDetailsOrWarn();
    if (!isReady) {
      closeModal();
      return;
    }

    const selectedSections = getSelectedSectionsFromCheckboxes();
    if (selectedSections.length === 0) {
      alert('Please select at least one proposal section to generate.');
      return;
    }

    _isLoading = true;
    showLoadingState();

    try {
      const form = getFormElement();
      const title = getActivityTitle();
      const theme = (form?.querySelector('[name="theme"]')?.value || document.getElementById('f1_theme')?.value || '').trim();
      const source = (form?.querySelector('[name="source"]')?.value || document.getElementById('f1_source')?.value || '').trim();
      const eventDate = (form?.querySelector('[name="event_date"]')?.value || document.getElementById('f1_event_date')?.value || '').trim();
      const startTime = (form?.querySelector('[name="start_time"]')?.value || document.getElementById('f1_start_time')?.value || '').trim();
      const endTime = (form?.querySelector('[name="end_time"]')?.value || document.getElementById('f1_end_time')?.value || '').trim();
      const venue = (form?.querySelector('[name="venue"]')?.value || document.getElementById('f1_venue')?.value || '').trim();
      const venueAddress = (form?.querySelector('[name="venue_address"]')?.value || document.getElementById('f1_venue_address')?.value || '').trim();
      const targetParticipants = (form?.querySelector('[name="target_participants"]')?.value || document.getElementById('f1_target_participants')?.value || '50').trim();
      const involvedSubjects = (form?.querySelector('[name="involved_subjects"]')?.value || document.getElementById('f2_involved_subjects')?.value || '').trim();
      const rationale = (form?.querySelector('[name="rationale"]')?.value || document.getElementById('f2_rationale')?.value || '').trim();
      const genObj = (form?.querySelector('[name="general_objectives"]')?.value || document.getElementById('f2_general_objectives')?.value || '').trim();
      const specObj = (form?.querySelector('[name="specific_objectives"]')?.value || document.getElementById('f2_specific_objectives')?.value || '').trim();
      const actIdInput = form?.querySelector('[name="activity_id"]') || form?.querySelector('[name="id"]');
      const activityId = parseInt(actIdInput?.value || window.ACTIVITY_ID || new URLSearchParams(window.location.search).get('id') || '0', 10);

      const payload = {
        action: 'generate_sections',
        activity_id: activityId,
        sections: selectedSections,
        title: title,
        activity_title: title,
        theme: theme,
        source: source,
        event_date: eventDate,
        start_time: startTime,
        end_time: endTime,
        venue: venue,
        venue_address: venueAddress,
        target_participants: targetParticipants,
        involved_subjects: involvedSubjects,
        rationale: rationale,
        general_objectives: genObj,
        specific_objectives: specObj,
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
          <div class="ai-card-header">
            <div style="display: flex; align-items: center; gap: 8px;">
              <input type="checkbox" id="chk_apply_${secKey}" ${isSelected ? 'checked' : ''} onchange="AIProposalGuidance.toggleSectionSelection('${secKey}', this.checked)" style="width: 16px; height: 16px; accent-color: #0284C7; cursor: pointer;">
              <span style="font-size: 1.1rem;">${sec.icon}</span>
              <strong class="ai-card-title">${sec.name}</strong>
              ${isApplied ? '<span class="ai-applied-badge">✓ Applied</span>' : '<span class="ai-ready-badge">Ready</span>'}
            </div>

            <!-- Card Actions -->
            <div style="display: flex; align-items: center; gap: 6px;">
              <button type="button" class="btn btn-outline btn-xs ai-btn-action" onclick="AIProposalGuidance.toggleEditSection('${secKey}')">
                ${isEditing ? '✕ Cancel' : '✏️ Edit'}
              </button>
              <button type="button" class="btn btn-primary btn-xs" onclick="AIProposalGuidance.applySection('${secKey}')" style="font-size: 0.74rem; padding: 4px 12px; font-weight: 700; border-radius: 6px; background: #0284C7; border: none; color: #FFFFFF; cursor: pointer;">
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
    if (!data) return '<em class="ai-text-muted">No content generated.</em>';

    switch (secKey) {
      case 'objectives':
        return `
          <div style="font-size: 0.84rem; display: flex; flex-direction: column; gap: 8px;">
            <div class="ai-notice-box">
              <span>ℹ️</span>
              <span><strong>Note:</strong> General and Specific Objectives are automatically generated upon clicking "Next" in Step 1.</span>
            </div>
            <div>
              <strong class="ai-label-header">Rationale / Educational Justification:</strong>
              <div class="ai-preview-box">${escapeHtml(data.rationale || '')}</div>
            </div>
            <div>
              <strong class="ai-label-header">Involved Subjects / Courses:</strong>
              <div class="ai-preview-box">${escapeHtml(data.involved_subjects || '')}</div>
            </div>
          </div>
        `;

      case 'materials':
        if (!Array.isArray(data) || data.length === 0) return '<em class="ai-text-muted">No materials suggested.</em>';
        let matRows = data.map((m, i) => `
          <tr>
            <td style="font-weight:600;">${escapeHtml(m.item)}</td>
            <td class="ai-text-muted">${escapeHtml(m.desc || '—')}</td>
            <td style="text-align:center;">${m.qty}</td>
            <td class="ai-text-muted">${escapeHtml(m.provider || '—')}</td>
            <td style="text-align:right; font-weight:600;">₱${parseFloat(m.cost || 0).toFixed(2)}</td>
          </tr>
        `).join('');
        return `
          <div style="overflow-x:auto;">
            <table class="ai-preview-table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Description</th>
                  <th style="text-align:center;">Qty</th>
                  <th>Supplier</th>
                  <th style="text-align:right;">Est. Cost</th>
                </tr>
              </thead>
              <tbody>${matRows}</tbody>
            </table>
          </div>
        `;

      case 'floor_plan':
        return `
          <div style="font-size: 0.84rem;">
            <strong class="ai-label-header">Layout &amp; Facility Plan Notes:</strong>
            <div class="ai-preview-box">
              ${escapeHtml(data.notes || '')}
            </div>
          </div>
        `;

      case 'people':
        if (!Array.isArray(data)) return '';
        let pplRows = data.map(p => `
          <div class="ai-person-pill">
            <span style="font-weight:700; color:#0284C7;">${escapeHtml(p.role)}:</span>
            <span>${escapeHtml(p.person)}</span>
            <span class="ai-text-muted" style="font-size:0.68rem;">(${escapeHtml(p.type)})</span>
          </div>
        `).join(' ');
        return `<div style="display:flex; flex-wrap:wrap; gap:8px;">${pplRows}</div>`;

      case 'schedule':
        if (!Array.isArray(data)) return '';
        let schRows = data.map(s => `
          <div class="ai-schedule-row">
            <span style="font-weight:700; color:#0284C7; min-width:55px;">${escapeHtml(s.time)}</span>
            <div style="flex:1;">
              <strong>${escapeHtml(s.segment)}</strong>
              <span class="ai-text-muted" style="margin-left:4px;">— ${escapeHtml(s.desc || '')}</span>
            </div>
            <span class="ai-text-muted" style="font-size:0.75rem;">PIC: ${escapeHtml(s.pic || '—')}</span>
          </div>
        `).join('');
        return `<div style="display:flex; flex-direction:column; gap:4px;">${schRows}</div>`;

      case 'guidelines':
        return `
          <div style="font-size:0.82rem; display:flex; flex-direction:column; gap:6px;">
            <div>
              <strong class="ai-label-header">Mechanics:</strong>
              <div class="ai-preview-box" style="white-space:pre-line;">${escapeHtml(data.mechanics || '')}</div>
            </div>
            <div>
              <strong class="ai-label-header">Judging Criteria:</strong>
              <div class="ai-preview-box" style="white-space:pre-line;">${escapeHtml(data.criteria || '')}</div>
            </div>
            <div>
              <strong class="ai-label-header">Scoring &amp; Awards:</strong>
              <div class="ai-preview-box">${escapeHtml(data.scoring || '')} · ${escapeHtml(data.awards || '')}</div>
            </div>
          </div>
        `;

      case 'faculty_tasks':
        if (!Array.isArray(data)) return '';
        let ftRows = data.map(t => `
          <div class="ai-task-card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <strong>${escapeHtml(t.task)}</strong>
              <span class="ai-text-muted" style="font-size:0.72rem;">Due: ${escapeHtml(t.due_date || 'TBD')}</span>
            </div>
            <div class="ai-text-secondary" style="margin-top:2px;">${escapeHtml(t.contribution || '')} (Role: ${escapeHtml(t.role || 'Faculty')})</div>
          </div>
        `).join('');
        return `<div>${ftRows}</div>`;

      case 'evaluation_kpi':
        if (!Array.isArray(data)) return '';
        let kpiRows = data.map(k => `
          <div class="ai-kpi-card">
            <div>
              <strong>${escapeHtml(k.indicator)}</strong>
              <div class="ai-text-muted" style="font-size:0.74rem;">Method: ${escapeHtml(k.method || 'Evaluation Form')}</div>
            </div>
            <span class="ai-kpi-target-badge">
              Target: ${escapeHtml(k.target)}
            </span>
          </div>
        `).join('');
        return `<div>${kpiRows}</div>`;

      default:
        return `<pre class="ai-preview-box" style="font-size:0.75rem;">${escapeHtml(JSON.stringify(data, null, 2))}</pre>`;
    }
  }

  function renderEditView(secKey, data) {
    switch (secKey) {
      case 'objectives':
        return `
          <div style="display:flex; flex-direction:column; gap:8px;">
            <div class="ai-notice-box">
              ℹ️ General and Specific Objectives are automatically generated upon clicking "Next" in Step 1. You can edit rationale and involved subjects below:
            </div>
            <div>
              <label class="ai-label-header">Rationale / Educational Justification:</label>
              <textarea id="edit_rationale" class="form-control" rows="3" style="font-size:0.82rem;">${escapeHtml(data.rationale || '')}</textarea>
            </div>
            <div>
              <label class="ai-label-header">Involved Subjects / Courses:</label>
              <input type="text" id="edit_involved_subjects" class="form-control" style="font-size:0.82rem;" value="${escapeHtml(data.involved_subjects || '')}">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
              <button type="button" class="btn btn-outline btn-xs ai-btn-action" onclick="AIProposalGuidance.toggleEditSection('${secKey}')">Cancel</button>
              <button type="button" class="btn btn-primary btn-xs" onclick="AIProposalGuidance.saveSectionEdits('${secKey}')">Save Changes</button>
            </div>
          </div>
        `;

      case 'floor_plan':
        return `
          <div>
            <label class="ai-label-header">Floor Plan Layout Notes:</label>
            <textarea id="edit_fp_notes" class="form-control" rows="4" style="font-size:0.82rem;">${escapeHtml(data.notes || '')}</textarea>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:8px;">
              <button type="button" class="btn btn-outline btn-xs ai-btn-action" onclick="AIProposalGuidance.toggleEditSection('${secKey}')">Cancel</button>
              <button type="button" class="btn btn-primary btn-xs" onclick="AIProposalGuidance.saveSectionEdits('${secKey}')">Save Changes</button>
            </div>
          </div>
        `;

      case 'guidelines':
        return `
          <div style="display:flex; flex-direction:column; gap:8px;">
            <div>
              <label class="ai-label-header">Mechanics:</label>
              <textarea id="edit_guidelines_mech" class="form-control" rows="3" style="font-size:0.82rem;">${escapeHtml(data.mechanics || '')}</textarea>
            </div>
            <div>
              <label class="ai-label-header">Judging Criteria:</label>
              <textarea id="edit_guidelines_crit" class="form-control" rows="2" style="font-size:0.82rem;">${escapeHtml(data.criteria || '')}</textarea>
            </div>
            <div>
              <label class="ai-label-header">Scoring System:</label>
              <input type="text" id="edit_guidelines_score" class="form-control" style="font-size:0.82rem;" value="${escapeHtml(data.scoring || '')}">
            </div>
            <div>
              <label class="ai-label-header">Special Awards:</label>
              <input type="text" id="edit_guidelines_awards" class="form-control" style="font-size:0.82rem;" value="${escapeHtml(data.awards || '')}">
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px;">
              <button type="button" class="btn btn-outline btn-xs ai-btn-action" onclick="AIProposalGuidance.toggleEditSection('${secKey}')">Cancel</button>
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
          _currentSuggestions.objectives.rationale = document.getElementById('edit_rationale')?.value || '';
          _currentSuggestions.objectives.involved_subjects = document.getElementById('edit_involved_subjects')?.value || '';
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
        const rat = (form.querySelector('[name="rationale"]') || document.getElementById('f2_rationale'))?.value.trim();
        const subj = (form.querySelector('[name="involved_subjects"]') || document.getElementById('f2_involved_subjects'))?.value.trim();
        return !!(rat || subj);

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
          const fRat = form.querySelector('[name="rationale"]') || document.getElementById('f2_rationale');
          const fSubj = form.querySelector('[name="involved_subjects"]') || document.getElementById('f2_involved_subjects');

          // Do NOT touch or overwrite general_objectives or specific_objectives
          if (fRat && data.rationale) {
            fRat.value = data.rationale;
            fRat.dispatchEvent(new Event('input', { bubbles: true }));
            fRat.dispatchEvent(new Event('change', { bubbles: true }));
          }
          if (fSubj && data.involved_subjects) {
            fSubj.value = data.involved_subjects;
            fSubj.dispatchEvent(new Event('input', { bubbles: true }));
            fSubj.dispatchEvent(new Event('change', { bubbles: true }));
          }
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

      // Record applied suggestion in proposal contribution / generation log
      const actIdInput = form.querySelector('[name="activity_id"]') || form.querySelector('[name="id"]');
      const actId = parseInt(actIdInput?.value || window.ACTIVITY_ID || new URLSearchParams(window.location.search).get('id') || '0', 10);
      if (actId > 0) {
        const baseUrl = getBaseUrl();
        fetch(`${baseUrl}/api/ai-proposal-guidance.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            action: 'log_applied',
            activity_id: actId,
            section: SECTION_CONFIG[secKey]?.name || secKey,
            details: `Applied AI proposal guidance for ${SECTION_CONFIG[secKey]?.name || secKey} based on Step 1 event details.`
          })
        }).catch(err => console.warn('Could not record proposal contribution log:', err));
      }

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
    if (typeof options.formSelector === 'string') {
      _formEl = document.querySelector(options.formSelector);
    } else if (options.formSelector) {
      _formEl = options.formSelector;
    } else {
      _formEl = document.getElementById('proposalForm');
    }

    ensureModalDom();
    bindTitleListeners();
  }

  return {
    init,
    requestGuidance,
    openModal,
    closeModal,
    getActivityTitle,
    getMissingEventDetails,
    checkEventDetailsOrWarn,
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
