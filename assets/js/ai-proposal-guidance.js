/**
 * assets/js/ai-proposal-guidance.js
 *
 * Frontend engine for AI-Assisted Proposal Guidance (Task 15).
 * Analyzes proposal completeness, objectives quality, and internal consistency.
 * Renders categorized findings with user-controlled action buttons (Apply, Modify, Dismiss).
 */

const AIProposalGuidance = (function () {
  let _baseUrl = '';
  let _formEl = null;
  let _modalEl = null;
  let _isLoading = false;
  let _activeFilter = 'all';
  let _currentGuidanceData = null;

  function init(options) {
    _baseUrl = (options.baseUrl || '').replace(/\/$/, '');
    _formEl = typeof options.formSelector === 'string'
      ? document.querySelector(options.formSelector)
      : options.formSelector;

    ensureModalDom();
  }

  function ensureModalDom() {
    let modal = document.getElementById('aiProposalGuidanceModal');
    if (!modal) {
      modal = document.createElement('div');
      modal.id = 'aiProposalGuidanceModal';
      modal.className = 'modal fade';
      modal.tabIndex = -1;
      modal.setAttribute('aria-labelledby', 'aiProposalGuidanceModalLabel');
      modal.setAttribute('aria-hidden', 'true');
      modal.style.display = 'none';
      modal.style.background = 'rgba(10, 22, 40, 0.65)';
      modal.style.zIndex = '1060';

      modal.innerHTML = `
        <div class="modal-dialog modal-xl modal-dialog-centered" style="margin: 1.5rem auto; max-width: 1050px; width: calc(100% - 2rem);">
          <div class="modal-content" style="border-radius:14px; overflow:hidden; border:none; box-shadow:0 14px 40px rgba(0,0,0,0.25); background:var(--bg-card, #fff); color:var(--text-main, #1e293b);">
            <div class="modal-header" style="padding: 16px 24px; border-bottom:1px solid var(--border, #e2e8f0); display:flex; align-items:center; justify-content:space-between; background:var(--bg-base, #f8fafc);">
              <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:1.4rem;">✨</span>
                <div>
                  <h5 class="modal-title fw-bold" id="aiProposalGuidanceModalLabel" style="font-size:1.15rem; margin:0; color:var(--text-main, #1e293b);">
                    AI-Assisted Proposal Guidance
                  </h5>
                  <span style="font-size:0.75rem; color:var(--text-muted, #64748b);">Pre-submission quality, completeness, and consistency advisor</span>
                </div>
              </div>
              <button type="button" class="btn-close" onclick="AIProposalGuidance.closeModal()" aria-label="Close" style="background:none; border:none; font-size:1.4rem; color:var(--text-muted, #64748b); cursor:pointer; line-height:1;">&times;</button>
            </div>
            <div class="modal-body" id="aiGuidanceModalBody" style="padding: 24px; max-height: calc(100vh - 200px); overflow-y: auto;">
              <!-- Dynamic content populated by renderGuidance() -->
            </div>
            <div class="modal-footer" style="padding: 14px 24px; border-top:1px solid var(--border, #e2e8f0); display:flex; justify-content:space-between; align-items:center; background:var(--bg-base, #f8fafc);">
              <div style="display:flex; align-items:center; gap:8px;">
                <button type="button" class="btn btn-outline btn-sm" id="btnGuidanceReanalyze" onclick="AIProposalGuidance.requestGuidance(true)" style="display:inline-flex; align-items:center; gap:5px; font-size:0.82rem; padding:6px 14px;">
                  <span>🔄</span> Re-Analyze Proposal
                </button>
                <span id="aiGuidanceEngineBadge" style="font-size:0.72rem; color:var(--text-muted, #64748b); font-style:italic;"></span>
              </div>
              <button type="button" class="btn btn-primary btn-sm" onclick="AIProposalGuidance.closeModal()" style="font-size:0.85rem; padding:7px 20px;">
                Done Reviewing
              </button>
            </div>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    }
    _modalEl = modal;
  }

  function openModal() {
    ensureModalDom();
    if (!_modalEl) return;
    _modalEl.style.display = 'block';
    _modalEl.classList.add('show');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    if (!_modalEl) return;
    _modalEl.style.display = 'none';
    _modalEl.classList.remove('show');
    document.body.style.overflow = '';
  }

  function showLoadingState() {
    openModal();
    const body = document.getElementById('aiGuidanceModalBody');
    if (!body) return;

    body.innerHTML = `
      <div style="text-align:center; padding:50px 20px;">
        <div class="spinner-border text-primary" role="status" style="width:3rem; height:3rem; border-width:0.25em; margin-bottom:16px; color:var(--sti-blue, #0284C7);">
          <span class="visually-hidden">Loading...</span>
        </div>
        <h5 style="font-weight:700; margin-bottom:8px; color:var(--text-main, #1e293b);">Analyzing Proposal with AI Advisor...</h5>
        <p style="color:var(--text-muted, #64748b); font-size:0.88rem; max-width:520px; margin:0 auto 12px auto; line-height:1.5;">
          Evaluating proposal completeness, objective clarity, internal schedule alignment, and educational justifications.
        </p>
        <span style="font-size:0.75rem; color:var(--text-muted, #94a3b8); display:inline-block; background:rgba(0,0,0,0.03); padding:4px 10px; border-radius:12px;">
          * Existing proposal inputs will NOT be modified automatically.
        </span>
      </div>
    `;
  }

  function showErrorState(errorMsg) {
    openModal();
    const body = document.getElementById('aiGuidanceModalBody');
    if (!body) return;

    body.innerHTML = `
      <div class="alert alert-danger" style="margin-bottom:0; border-radius:10px; padding:20px; border-left:6px solid #dc2626;">
        <h6 style="font-weight:700; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
          <span>⚠️</span> AI Proposal Guidance Error
        </h6>
        <p style="margin-bottom:14px; font-size:0.88rem; line-height:1.5;">
          ${escapeHtml(errorMsg || 'Unable to retrieve AI proposal guidance. Please verify your connection or try again.')}
        </p>
        <div style="display:flex; gap:10px;">
          <button type="button" class="btn btn-primary btn-sm" onclick="AIProposalGuidance.requestGuidance(true)">
            Try Again
          </button>
          <button type="button" class="btn btn-outline btn-sm" onclick="AIProposalGuidance.closeModal()">
            Close
          </button>
        </div>
      </div>
    `;
  }

  async function requestGuidance(forceRefresh = false) {
    if (_isLoading) return;

    if (!_formEl) {
      _formEl = document.getElementById('proposalForm');
    }

    if (!_formEl) {
      alert('Proposal form not found.');
      return;
    }

    _isLoading = true;
    showLoadingState();

    try {
      const formData = new FormData(_formEl);
      if (forceRefresh) {
        formData.append('force_refresh', '1');
      }

      const resp = await fetch(`${_baseUrl}/api/ai-proposal-guidance.php`, {
        method: 'POST',
        body: formData
      });

      const data = await resp.json();

      if (!resp.ok || !data.success || !data.guidance) {
        throw new Error(data.error || `HTTP ${resp.status}: Failed to generate proposal guidance.`);
      }

      _currentGuidanceData = data.guidance;
      renderGuidance(data.guidance, data.activity_id || 0);

      const engineBadge = document.getElementById('aiGuidanceEngineBadge');
      if (engineBadge) {
        engineBadge.textContent = data.guidance.is_fallback
          ? 'Rule-based offline guidance engine'
          : (data.cached ? 'Loaded from session cache' : 'Powered by Google Gemini');
      }

    } catch (err) {
      console.error('AIProposalGuidance Error:', err);
      showErrorState(err.message || 'Network error while contacting AI guidance service.');
    } finally {
      _isLoading = false;
    }
  }

  function renderGuidance(guidance, activityId) {
    const body = document.getElementById('aiGuidanceModalBody');
    if (!body) return;

    const summary = guidance.overall_summary || {};
    const categories = guidance.categories || {};
    const missing = categories.missing_information || [];
    const needsImprove = categories.needs_improvement || [];
    const enhancements = categories.suggested_enhancements || [];
    const goodPractices = categories.good_practices || [];

    const totalCount = missing.length + needsImprove.length + enhancements.length + goodPractices.length;

    let html = '';

    // 1. Overall Summary Banner
    html += `
      <div style="background:var(--bg-base, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:10px; padding:18px; margin-bottom:20px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
          <div>
            <h6 style="font-weight:700; margin:0 0 4px 0; font-size:1rem; color:var(--text-main, #1e293b); display:flex; align-items:center; gap:6px;">
              <span>📋</span> Proposal Quality &amp; Completeness Summary
            </h6>
            <div style="font-size:0.8rem; color:var(--text-muted, #64748b);">
              Review completed with <strong>${totalCount} findings</strong> identified across 4 key evaluation categories.
            </div>
          </div>
          <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <span class="badge" style="background:${missing.length === 0 ? 'rgba(16, 185, 129, 0.12)' : 'rgba(239, 68, 68, 0.12)'}; color:${missing.length === 0 ? '#10B981' : '#EF4444'}; border:1px solid ${missing.length === 0 ? '#6EE7B7' : '#FCA5A5'}; font-size:0.75rem; padding:4px 8px; border-radius:12px;">
              Missing Info: ${missing.length}
            </span>
            <span class="badge" style="background:${needsImprove.length === 0 ? 'rgba(16, 185, 129, 0.12)' : 'rgba(245, 158, 11, 0.12)'}; color:${needsImprove.length === 0 ? '#10B981' : '#D97706'}; border:1px solid ${needsImprove.length === 0 ? '#6EE7B7' : '#FCD34D'}; font-size:0.75rem; padding:4px 8px; border-radius:12px;">
              Needs Work: ${needsImprove.length}
            </span>
            <span class="badge" style="background:rgba(2, 132, 199, 0.12); color:#0284C7; border:1px solid #7DD3FC; font-size:0.75rem; padding:4px 8px; border-radius:12px;">
              Enhancements: ${enhancements.length}
            </span>
            <span class="badge" style="background:rgba(16, 185, 129, 0.12); color:#10B981; border:1px solid #6EE7B7; font-size:0.75rem; padding:4px 8px; border-radius:12px;">
              Good Practices: ${goodPractices.length}
            </span>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:12px; font-size:0.83rem; line-height:1.45; margin-bottom:12px;">
          <div style="background:var(--bg-card, #fff); padding:10px 12px; border-radius:8px; border:1px solid var(--border, #e2e8f0);">
            <strong style="display:block; color:var(--text-main, #1e293b); margin-bottom:2px;">Completeness:</strong>
            <span style="color:var(--text-muted, #475569);">${escapeHtml(summary.completeness_assessment || 'Assessment completed.')}</span>
          </div>
          <div style="background:var(--bg-card, #fff); padding:10px 12px; border-radius:8px; border:1px solid var(--border, #e2e8f0);">
            <strong style="display:block; color:var(--text-main, #1e293b); margin-bottom:2px;">Objectives &amp; Outcomes:</strong>
            <span style="color:var(--text-muted, #475569);">${escapeHtml(summary.objectives_alignment || 'Alignment verified.')}</span>
          </div>
          <div style="background:var(--bg-card, #fff); padding:10px 12px; border-radius:8px; border:1px solid var(--border, #e2e8f0);">
            <strong style="display:block; color:var(--text-main, #1e293b); margin-bottom:2px;">Consistency:</strong>
            <span style="color:var(--text-muted, #475569);">${escapeHtml(summary.consistency_check || 'Internal alignment confirmed.')}</span>
          </div>
        </div>

        <!-- Official Disclaimer (Mandatory Requirement) -->
        <div style="background:rgba(245, 158, 11, 0.08); border-left:3px solid #F59E0B; border-radius:6px; padding:8px 12px; font-size:0.75rem; color:#92400E; line-height:1.4;">
          <strong>Notice:</strong> ${escapeHtml(guidance.disclaimer || 'These findings are recommendations to assist faculty in refining proposal quality prior to submission. They do NOT constitute an official Dean review, evaluation score, or approval decision.')}
        </div>
      </div>
    `;

    // 2. Category Filter Tabs
    html += `
      <div style="display:flex; gap:8px; overflow-x:auto; padding-bottom:6px; margin-bottom:16px; border-bottom:1px solid var(--border, #e2e8f0);">
        <button type="button" class="btn btn-sm ${!_activeFilter || _activeFilter === 'all' ? 'btn-primary' : 'btn-outline'}" onclick="AIProposalGuidance.setFilter('all')" style="border-radius:20px; font-size:0.78rem; padding:4px 12px; white-space:nowrap;">
          All Findings (${totalCount})
        </button>
        <button type="button" class="btn btn-sm ${_activeFilter === 'missing_information' ? 'btn-primary' : 'btn-outline'}" onclick="AIProposalGuidance.setFilter('missing_information')" style="border-radius:20px; font-size:0.78rem; padding:4px 12px; white-space:nowrap;">
          🔴 Missing Information (${missing.length})
        </button>
        <button type="button" class="btn btn-sm ${_activeFilter === 'needs_improvement' ? 'btn-primary' : 'btn-outline'}" onclick="AIProposalGuidance.setFilter('needs_improvement')" style="border-radius:20px; font-size:0.78rem; padding:4px 12px; white-space:nowrap;">
          🟠 Needs Improvement (${needsImprove.length})
        </button>
        <button type="button" class="btn btn-sm ${_activeFilter === 'suggested_enhancements' ? 'btn-primary' : 'btn-outline'}" onclick="AIProposalGuidance.setFilter('suggested_enhancements')" style="border-radius:20px; font-size:0.78rem; padding:4px 12px; white-space:nowrap;">
          🔵 Suggested Enhancements (${enhancements.length})
        </button>
        <button type="button" class="btn btn-sm ${_activeFilter === 'good_practices' ? 'btn-primary' : 'btn-outline'}" onclick="AIProposalGuidance.setFilter('good_practices')" style="border-radius:20px; font-size:0.78rem; padding:4px 12px; white-space:nowrap;">
          🟢 Good Practices (${goodPractices.length})
        </button>
      </div>
    `;

    // 3. Render Cards Container
    html += `<div id="aiGuidanceCardsContainer" style="display:flex; flex-direction:column; gap:14px;">`;

    const allItems = [
      ...missing.map(i => ({ ...i, catType: 'missing_information' })),
      ...needsImprove.map(i => ({ ...i, catType: 'needs_improvement' })),
      ...enhancements.map(i => ({ ...i, catType: 'suggested_enhancements' })),
      ...goodPractices.map(i => ({ ...i, catType: 'good_practices' }))
    ];

    if (allItems.length === 0) {
      html += `
        <div style="text-align:center; padding:30px; color:var(--text-muted); font-size:0.9rem;">
          🎉 No specific issues identified. Your proposal appears well-prepared!
        </div>
      `;
    } else {
      allItems.forEach(item => {
        html += renderRecommendationCard(item, activityId);
      });
    }

    html += `</div>`;
    body.innerHTML = html;

    applyFilterVisibility();
  }

  function renderRecommendationCard(item, activityId) {
    let catBadgeColor = '#64748B';
    let catBadgeBg = 'rgba(100, 116, 139, 0.12)';
    let catLabel = 'Recommendation';
    let borderLeftColor = '#CBD5E1';

    if (item.catType === 'missing_information') {
      catBadgeColor = '#DC2626';
      catBadgeBg = 'rgba(239, 68, 68, 0.12)';
      catLabel = 'Missing Information';
      borderLeftColor = '#EF4444';
    } else if (item.catType === 'needs_improvement') {
      catBadgeColor = '#D97706';
      catBadgeBg = 'rgba(245, 158, 11, 0.12)';
      catLabel = 'Needs Improvement';
      borderLeftColor = '#F59E0B';
    } else if (item.catType === 'suggested_enhancements') {
      catBadgeColor = '#0284C7';
      catBadgeBg = 'rgba(2, 132, 199, 0.12)';
      catLabel = 'Suggested Enhancement';
      borderLeftColor = '#38BDF8';
    } else if (item.catType === 'good_practices') {
      catBadgeColor = '#10B981';
      catBadgeBg = 'rgba(16, 185, 129, 0.12)';
      catLabel = 'Good Practice';
      borderLeftColor = '#10B981';
    }

    const priorityColor = item.priority === 'High' ? '#DC2626' : (item.priority === 'Medium' ? '#D97706' : '#64748B');

    const canApply = item.can_auto_apply && item.suggested_improvement && item.target_field;
    const cardId = `guidanceCard_${item.id || Math.random().toString(36).substr(2, 9)}`;

    return `
      <div class="guidance-item-card" id="${cardId}" data-category="${item.catType}" style="border:1px solid var(--border, #e2e8f0); border-left:4px solid ${borderLeftColor}; border-radius:8px; padding:16px; background:var(--bg-card, #fff);">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
          <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <span class="badge" style="background:${catBadgeBg}; color:${catBadgeColor}; font-size:0.75rem; padding:3px 8px; border-radius:10px; font-weight:700;">
              ${catLabel}
            </span>
            <span style="font-size:0.78rem; font-weight:600; color:var(--text-muted, #64748b); background:var(--bg-base, #f1f5f9); padding:2px 8px; border-radius:6px;">
              Section: ${escapeHtml(item.section || 'General')}
            </span>
          </div>
          <span style="font-size:0.75rem; font-weight:600; color:${priorityColor};">
            Priority: ${escapeHtml(item.priority || 'Medium')}
          </span>
        </div>

        <h6 style="font-weight:700; margin:0 0 6px 0; font-size:0.92rem; color:var(--text-main, #1e293b);">
          ${escapeHtml(item.issue || 'Observation')}
        </h6>

        <p style="font-size:0.83rem; color:var(--text-muted, #64748b); line-height:1.45; margin:0 0 10px 0;">
          ${escapeHtml(item.explanation || '')}
        </p>

        ${item.suggested_improvement ? `
          <div style="background:var(--bg-base, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:0.83rem;">
            <strong style="color:var(--text-main, #1e293b); display:block; margin-bottom:3px; font-size:0.78rem;">Suggested Improvement:</strong>
            <span style="color:var(--text-main, #334155); white-space:pre-line;" id="suggestionText_${cardId}">${escapeHtml(item.suggested_improvement)}</span>
          </div>
        ` : ''}

        <div style="display:flex; justify-content:flex-end; align-items:center; gap:8px; margin-top:8px;" id="actionBtnGroup_${cardId}">
          <button type="button" class="btn btn-outline btn-sm" onclick="AIProposalGuidance.dismissCard('${cardId}')" style="font-size:0.78rem; padding:4px 10px; color:var(--text-muted, #64748b);">
            Dismiss
          </button>
          ${canApply ? `
            <button type="button" class="btn btn-primary btn-sm" id="btnApply_${cardId}" onclick="AIProposalGuidance.applySuggestion('${cardId}', '${escapeHtml(item.target_field)}', '${escapeHtml(item.section || 'Guidance')}', ${activityId})" style="font-size:0.78rem; padding:4px 12px; display:inline-flex; align-items:center; gap:4px;">
              <span>✓</span> Apply to Proposal
            </button>
          ` : ''}
        </div>
      </div>
    `;
  }

  function setFilter(catKey) {
    _activeFilter = catKey;
    if (_currentGuidanceData) {
      renderGuidance(_currentGuidanceData, 0);
    }
  }

  function applyFilterVisibility() {
    const cards = document.querySelectorAll('.guidance-item-card');
    cards.forEach(c => {
      if (!_activeFilter || _activeFilter === 'all' || c.getAttribute('data-category') === _activeFilter) {
        c.style.display = 'block';
      } else {
        c.style.display = 'none';
      }
    });
  }

  function dismissCard(cardId) {
    const card = document.getElementById(cardId);
    if (!card) return;
    card.style.transition = 'all 0.25s ease';
    card.style.opacity = '0';
    card.style.transform = 'scale(0.95)';
    setTimeout(() => {
      card.remove();
    }, 250);
  }

  async function applySuggestion(cardId, targetField, sectionName, activityId) {
    const textEl = document.getElementById(`suggestionText_${cardId}`);
    const btn = document.getElementById(`btnApply_${cardId}`);
    if (!textEl) return;

    const newText = textEl.innerText.trim();
    if (!newText) return;

    if (!confirm(`Apply this suggested improvement to the "${targetField}" field? Existing text will be updated.`)) {
      return;
    }

    // Locate target input/textarea field in proposalForm
    let targetInput = null;

    // Field ID mappings
    const idMap = {
      'title': 'f1_title',
      'theme': 'f1_theme',
      'venue': 'f1_venue',
      'venue_address': 'f1_venue_address',
      'general_objectives': 'f2_general_objectives',
      'specific_objectives': 'f2_specific_objectives',
      'rationale': 'f2_rationale'
    };

    if (idMap[targetField]) {
      targetInput = document.getElementById(idMap[targetField]);
    }

    if (!targetInput) {
      targetInput = document.querySelector(`[name="${targetField}"]`) || document.getElementById(targetField);
    }

    if (!targetInput) {
      alert(`Could not locate the field for "${targetField}". Please copy the suggested text manually.`);
      return;
    }

    // Apply value
    targetInput.value = newText;
    targetInput.dispatchEvent(new Event('input', { bubbles: true }));
    targetInput.dispatchEvent(new Event('change', { bubbles: true }));

    // Highlight modified field
    targetInput.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
    targetInput.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.3)';
    targetInput.style.borderColor = '#10B981';
    setTimeout(() => {
      targetInput.style.boxShadow = '';
      targetInput.style.borderColor = '';
    }, 3000);

    // Disable button & mark applied
    if (btn) {
      btn.disabled = true;
      btn.className = 'btn btn-outline btn-sm';
      btn.style.color = '#10B981';
      btn.style.borderColor = '#10B981';
      btn.innerHTML = '<span>✓</span> Applied to Proposal';
    }

    // Log contribution if on an existing proposal
    const propActId = activityId || (document.getElementById('proposalActivityId')?.value) || 0;
    if (propActId && parseInt(propActId, 10) > 0) {
      try {
        const fd = new FormData();
        fd.append('action', 'log_applied');
        fd.append('activity_id', propActId);
        fd.append('section', sectionName || 'Proposal Guidance');
        fd.append('details', `Applied AI guidance improvement to ${targetField}.`);

        await fetch(`${_baseUrl}/api/ai-proposal-guidance.php`, {
          method: 'POST',
          body: fd
        });
      } catch (logErr) {
        console.warn('Could not log contribution for applied suggestion:', logErr);
      }
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

  return {
    init: init,
    requestGuidance: requestGuidance,
    openModal: openModal,
    closeModal: closeModal,
    setFilter: setFilter,
    dismissCard: dismissCard,
    applySuggestion: applySuggestion
  };
})();

