/**
 * proposal-ai-validator.js
 *
 * Vanilla JS module for AI Proposal Validation using Google Gemini API.
 * Intercepts "Submit for Approval" button clicks, validates required PHP fields,
 * and renders a Bootstrap 5 modal with comprehensive AI evaluation across 9 criteria.
 */

const ProposalAIValidator = (function () {
  let _formEl = null;
  let _modalEl = null;
  let _bsModal = null;
  let _baseUrl = '';
  let _lastValidationHash = '';
  let _aiValidationApproved = false;
  let _aiValidationStatus = '';
  let _isValRunning = false;

  function init(options) {
    _baseUrl = options.baseUrl || '';
    _formEl = typeof options.formSelector === 'string'
      ? document.querySelector(options.formSelector)
      : options.formSelector;

    _modalEl = typeof options.modalSelector === 'string'
      ? document.querySelector(options.modalSelector)
      : options.modalSelector;

    if (!_formEl) {
      console.warn('ProposalAIValidator: Form element not found.');
      return;
    }
  }

  async function runAIValidation() {
    if (_isValRunning) return;
    _isValRunning = true;
    showLoadingOverlay(true);

    try {
      const formData = new FormData(_formEl);

      const resp = await fetch(`${_baseUrl}/api/validate-proposal-ai.php`, {
        method: 'POST',
        body: formData
      });

      if (!resp.ok) {
        throw new Error(`HTTP Error: ${resp.status}`);
      }

      const data = await resp.json();
      showLoadingOverlay(false);

      // Handle standard required-field failures (returns validation_passed: false)
      if (!data.validation_passed) {
        renderMissingFieldsAlert(data.missing_fields || [], data.error);
        return;
      }

      if (!data.success || !data.ai_validation) {
        throw new Error(data.error || 'Failed to retrieve AI Proposal Validation report.');
      }

      _lastValidationHash = data.data_hash || '';
      renderAIValidationModal(data.ai_validation);

    } catch (err) {
      console.error('ProposalAIValidator Exception:', err.message || err);
      showLoadingOverlay(false);
      
      // If Gemini is unavailable, do not block the faculty; ask if they want to proceed using local validation
      if (confirm('⚠️ AI Proposal Validation is temporarily unavailable.\n\nWould you like to proceed with the submission anyway?')) {
        _aiValidationStatus = 'unavailable';
        
        let aiHashInput = _formEl.querySelector('input[name="ai_validation_hash"]');
        if (!aiHashInput) {
          aiHashInput = document.createElement('input');
          aiHashInput.type = 'hidden';
          aiHashInput.name = 'ai_validation_hash';
          _formEl.appendChild(aiHashInput);
        }
        aiHashInput.value = ''; // empty hash forces backend fallback without blocking

        proceedWithSubmission();
      }
    } finally {
      _isValRunning = false;
    }
  }

  function renderMissingFieldsAlert(missingFields, errorMsg) {
    const list = missingFields.map(f => `• ${f}`).join('\n');
    alert(`⚠️ Incomplete Proposal Fields\n\n${errorMsg || 'Please complete all required fields:'}\n\n${list}`);
  }

  function renderAIValidationModal(ai) {
    if (!_modalEl) return;

    const contentEl = _modalEl.querySelector('#aiProposalValidationContent') || _modalEl.querySelector('.modal-content');
    if (!contentEl) return;

    // 1. Dynamic Timestamp
    const now = new Date();
    const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    const month = months[now.getMonth()];
    const day = now.getDate();
    const year = now.getFullYear();
    let hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const timestampStr = `${month} ${day}, ${year} · ${hours}:${minutes} ${ampm}`;

    // 2. Count Statuses
    let criticalCount = 0;
    let warningCount = 0;
    let goodCount = 0;
    let totalCount = 0;
    for (const key in ai.sections) {
      const status = ai.sections[key].status;
      if (status === 'error') criticalCount++;
      else if (status === 'warning') warningCount++;
      else if (status === 'good') goodCount++;
      totalCount++;
    }

    // 3. Dynamic Score (Weighted percentage based on sections)
    const score = totalCount > 0 ? Math.round(((goodCount * 100) + (warningCount * 50)) / (totalCount * 100) * 100) : 100;

    let strokeColor = "#EF4444";
    if (score >= 80) strokeColor = "#10B981";
    else if (score >= 50) strokeColor = "#F59E0B";

    // 4. Overall Assessment Config
    const q = ai.overall_quality || 'Good';
    let assessmentTitle = 'Good Quality';
    let assessmentDesc = 'This proposal is of good quality and is ready to be submitted for approval.';
    let assessmentClass = 'good';
    let assessmentIcon = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #10B981;"><circle cx="12" cy="12" r="10" fill="#10B981" stroke="#10B981"></circle><polyline points="16 9 11 14 8 11" stroke="white" stroke-width="2.5"></polyline></svg>`;

    if (q === 'Excellent') {
      assessmentTitle = 'Excellent Quality';
      assessmentDesc = 'This proposal meets or exceeds all academic guidelines. Ready for submission!';
      assessmentClass = 'excellent';
      assessmentIcon = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #10B981;"><circle cx="12" cy="12" r="10" fill="#10B981" stroke="#10B981"></circle><polyline points="16 9 11 14 8 11" stroke="white" stroke-width="2.5"></polyline></svg>`;
    } else if (q === 'Fair') {
      assessmentTitle = 'Needs Revision';
      assessmentDesc = 'This proposal is fair but has warnings that should be reviewed before submission.';
      assessmentClass = 'fair';
      assessmentIcon = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #F59E0B;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`;
    } else if (q === 'Needs Improvement') {
      assessmentTitle = 'Needs Major Improvement';
      assessmentDesc = 'This proposal requires significant revision before it can be recommended.';
      assessmentClass = 'needs-improvement';
      assessmentIcon = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #EF4444;"><circle cx="12" cy="12" r="10" fill="#EF4444" stroke="#EF4444"></circle><line x1="15" y1="9" x2="9" y2="15" stroke="white" stroke-width="2.5"></line><line x1="9" y1="9" x2="15" y2="15" stroke="white" stroke-width="2.5"></line></svg>`;
    }

    // 5. Sections Config Map
    const sectionConfig = {
      completeness: {
        title: "Proposal Completeness",
        icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><path d="M9 14l2 2 4-4"></path></svg>`,
        primaryColor: "#8B5CF6",
        bgLight: "#F5F3FF"
      },
      title_evaluation: {
        title: "Title Relevance & Quality",
        icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>`,
        primaryColor: "#EC4899",
        bgLight: "#FDF2F8"
      },
      objective_alignment: {
        title: "Objective Clarity & Alignment",
        icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><circle cx="12" cy="12" r="6"></circle><circle cx="12" cy="12" r="2"></circle></svg>`,
        primaryColor: "#EF4444",
        bgLight: "#FEF2F2"
      },
      kpi_alignment: {
        title: "KPI / Success Indicator Alignment",
        icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>`,
        primaryColor: "#6366F1",
        bgLight: "#EEF2FF"
      },
      description_completeness: {
        title: "Description Completeness & Quality",
        icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>`,
        primaryColor: "#D97706",
        bgLight: "#FEF3C7"
      },
      consistency_analysis: {
        title: "Consistency & Alignment",
        icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>`,
        primaryColor: "#2563EB",
        bgLight: "#EFF6FF"
      },
      grammar_clarity: {
        title: "Grammar & Clarity",
        icon: `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>`,
        primaryColor: "#10B981",
        bgLight: "#EFFDF4"
      }
    };

    // 6. Build Left Column Accordion Sections HTML
    let sectionsHtml = '';
    for (const key in sectionConfig) {
      const data = ai.sections ? ai.sections[key] : null;
      if (!data) continue;
      const config = sectionConfig[key];
      const status = data.status || 'good';
      const feedback = data.feedback || 'Checked and no issues identified.';

      let statusText = '';
      let statusClass = '';
      let badgeText = '';
      let badgeStyle = '';

      if (status === 'error') {
        statusText = 'Needs Review'; // Match style of Objectives in screenshot
        if (key === 'completeness' || key === 'title_evaluation' || key === 'kpi_alignment' || key === 'description_completeness' || key === 'grammar_clarity') {
          statusText = 'Critical / Missing'; // Match screenshot tags
        }
        statusClass = 'status-error';
        badgeText = 'Critical';
        badgeStyle = 'background: #FEE2E2; color: #EF4444;';
      } else if (status === 'warning') {
        statusText = 'Needs Review';
        statusClass = 'status-warning';
        badgeText = 'Medium';
        badgeStyle = 'background: #FEF3C7; color: #D97706;';
      } else {
        statusText = 'Good';
        statusClass = 'status-good';
        badgeText = 'Good';
        badgeStyle = 'background: #D1FAE5; color: #059669;';
      }

      // Open critical/warnings by default
      const isOpenClass = (status === 'error' || status === 'warning') ? 'open' : '';

      sectionsHtml += `
        <div class="ai-val-card ${isOpenClass}" data-section="${key}">
          <div class="ai-val-card-header" onclick="this.parentElement.classList.toggle('open')">
            <div class="ai-val-card-header-left">
              <div class="ai-val-card-icon" style="background: ${config.bgLight}; color: ${config.primaryColor};">
                ${config.icon}
              </div>
              <div class="ai-val-card-title-container">
                <div class="ai-val-card-title">
                  ${config.title}
                  <span style="font-weight: normal; font-size: 0.82rem; margin-left: 6px; display: inline-flex; align-items: center; gap: 4px;">
                    <span class="status-dot ${statusClass}"></span>
                    <span class="status-label ${statusClass}">${statusText}</span>
                  </span>
                </div>
              </div>
            </div>
            <div class="ai-val-card-header-right">
              <span class="priority-badge" style="${badgeStyle}">${badgeText}</span>
              <span class="chevron-icon">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </span>
            </div>
          </div>
          <div class="ai-val-card-body">
            ${escapeHtml(feedback)}
          </div>
        </div>
      `;
    }

    // 7. Recommendations Section HTML
    let recommendationsHtml = '';
    if (Array.isArray(ai.recommendations) && ai.recommendations.length > 0) {
      const firstRec = ai.recommendations[0];
      recommendationsHtml += `
        <div class="ai-val-recs-box">
          ${escapeHtml(firstRec)}
        </div>
      `;

      if (ai.recommendations.length > 1) {
        recommendationsHtml += `
          <div class="collapse" id="allRecommendationsCollapse" style="display:none; transition: all 0.3s ease;">
            <ul class="ai-val-recs-list">
              ${ai.recommendations.map(rec => `<li>${escapeHtml(rec)}</li>`).join('')}
            </ul>
          </div>
          <a href="#" class="ai-val-recs-link" onclick="event.preventDefault(); const col = document.getElementById('allRecommendationsCollapse'); if (col.style.display === 'none') { col.style.display = 'block'; this.innerHTML = 'Hide recommendations &uarr;'; } else { col.style.display = 'none'; this.innerHTML = 'View full recommendations &rarr;'; }">
            View full recommendations &rarr;
          </a>
        `;
      }
    } else {
      recommendationsHtml = `<div class="ai-val-recs-box text-muted">No specific recommendations provided. Proposal looks clean!</div>`;
    }

    // 8. Dynamic CSS Injection
    const cssStyles = `
      <style>
        #aiProposalValidationContent {
          background: #f8fafc;
          font-family: 'Inter', system-ui, -apple-system, sans-serif !important;
        }
        #aiProposalValidationContent ::-webkit-scrollbar {
          width: 6px;
        }
        #aiProposalValidationContent ::-webkit-scrollbar-track {
          background: transparent;
        }
        #aiProposalValidationContent ::-webkit-scrollbar-thumb {
          background: #CBD5E1;
          border-radius: 4px;
        }
        #aiProposalValidationContent ::-webkit-scrollbar-thumb:hover {
          background: #94A3B8;
        }

        .ai-val-modal-header {
          background: #ffffff;
          padding: 20px 28px;
          border-bottom: 1px solid #E2E8F0;
          display: flex;
          justify-content: space-between;
          align-items: center;
        }
        .ai-val-header-left {
          display: flex;
          align-items: center;
          gap: 16px;
        }
        .ai-val-logo-container {
          width: 44px;
          height: 44px;
          border-radius: 12px;
          background: #EEF2FF;
          color: #4F46E5;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 1.3rem;
        }
        .ai-val-header-title {
          margin: 0;
          font-size: 1.25rem;
          font-weight: 800;
          color: #0F172A;
          letter-spacing: -0.3px;
        }
        .ai-val-header-subtitle {
          margin: 2px 0 0 0;
          font-size: 0.85rem;
          color: #64748B;
          font-weight: 500;
        }
        .ai-val-reviewed-badge {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          padding: 5px 12px;
          border-radius: 99px;
          background: #F3E8FF;
          color: #7E22CE;
          font-size: 0.75rem;
          font-weight: 700;
          margin-bottom: 2px;
        }
        .ai-val-timestamp {
          font-size: 0.75rem;
          color: #64748B;
          font-weight: 500;
        }

        .ai-val-body-grid {
          display: grid;
          grid-template-columns: 1.5fr 1fr;
          gap: 24px;
          padding: 24px;
          background: #F8FAFC;
        }
        @media (max-width: 850px) {
          .ai-val-body-grid {
            grid-template-columns: 1fr;
            padding: 16px;
            gap: 20px;
          }
        }

        .ai-val-section-title {
          font-size: 0.82rem;
          font-weight: 700;
          color: #475569;
          margin-bottom: 12px;
          text-transform: uppercase;
          letter-spacing: 0.5px;
        }
        .ai-val-accordion {
          display: flex;
          flex-direction: column;
          gap: 12px;
          margin-bottom: 16px;
        }
        .ai-val-card {
          background: #ffffff;
          border-radius: 14px;
          border: 1px solid #E2E8F0;
          box-shadow: 0 2px 8px rgba(0,0,0,0.01);
          transition: all 0.2s ease;
          overflow: hidden;
        }
        .ai-val-card:hover {
          box-shadow: 0 4px 14px rgba(0,0,0,0.03);
          border-color: #CBD5E1;
        }
        .ai-val-card-header {
          padding: 14px 18px;
          display: flex;
          align-items: center;
          justify-content: space-between;
          cursor: pointer;
          user-select: none;
        }
        .ai-val-card-header-left {
          display: flex;
          align-items: center;
          gap: 14px;
          flex: 1;
          min-width: 0;
        }
        .ai-val-card-icon {
          width: 36px;
          height: 36px;
          border-radius: 10px;
          display: flex;
          align-items: center;
          justify-content: center;
          flex-shrink: 0;
        }
        .ai-val-card-title-container {
          display: flex;
          flex-direction: column;
          min-width: 0;
        }
        .ai-val-card-title {
          font-size: 0.88rem;
          font-weight: 700;
          color: #1E293B;
          display: flex;
          align-items: center;
          gap: 8px;
          flex-wrap: wrap;
        }
        .status-dot {
          width: 6px;
          height: 6px;
          border-radius: 50%;
          display: inline-block;
        }
        .status-dot.status-error { background: #EF4444; }
        .status-dot.status-warning { background: #F59E0B; }
        .status-dot.status-good { background: #10B981; }

        .status-label {
          font-size: 0.78rem;
          font-weight: 600;
        }
        .status-label.status-error { color: #EF4444; }
        .status-label.status-warning { color: #D97706; }
        .status-label.status-good { color: #059669; }

        .ai-val-card-header-right {
          display: flex;
          align-items: center;
          gap: 12px;
        }
        .priority-badge {
          padding: 3px 8px;
          border-radius: 6px;
          font-size: 0.7rem;
          font-weight: 700;
          text-transform: capitalize;
        }
        .chevron-icon {
          color: #94A3B8;
          transition: transform 0.2s ease;
          display: flex;
          align-items: center;
          justify-content: center;
        }
        .ai-val-card.open .chevron-icon {
          transform: rotate(180deg);
        }

        .ai-val-card-body {
          padding: 0 18px 16px 68px;
          display: none;
          font-size: 0.84rem;
          color: #475569;
          line-height: 1.45;
        }
        .ai-val-card.open .ai-val-card-body {
          display: block;
        }

        .ai-val-info-card {
          display: flex;
          align-items: flex-start;
          gap: 12px;
          padding: 14px 18px;
          border-radius: 12px;
          background: #EFF6FF;
          border: 1px solid #DBEAFE;
          color: #1E40AF;
          font-size: 0.8rem;
          line-height: 1.4;
          font-weight: 500;
        }
        .ai-val-info-icon {
          color: #2563EB;
          flex-shrink: 0;
          margin-top: 1px;
        }

        .ai-val-right-pane {
          display: flex;
          flex-direction: column;
          gap: 20px;
        }
        .ai-val-panel {
          background: #ffffff;
          border-radius: 14px;
          border: 1px solid #E2E8F0;
          padding: 20px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.01);
        }
        .ai-val-panel-title {
          font-size: 0.8rem;
          font-weight: 700;
          color: #475569;
          margin-bottom: 10px;
          text-transform: uppercase;
          letter-spacing: 0.5px;
        }

        .ai-val-assessment-box {
          display: flex;
          align-items: flex-start;
          gap: 12px;
          padding: 14px;
          border-radius: 10px;
        }
        .ai-val-assessment-box.needs-improvement { background: #FEF2F2; border: 1px solid #FEE2E2; color: #991B1B; }
        .ai-val-assessment-box.good { background: #F0FDF4; border: 1px solid #DCFCE7; color: #166534; }
        .ai-val-assessment-box.excellent { background: #F0FDF4; border: 1px solid #DCFCE7; color: #166534; }
        .ai-val-assessment-box.fair { background: #FEF3C7; border: 1px solid #FEF3C7; color: #92400E; }
        .ai-val-assessment-icon-wrap { flex-shrink: 0; }
        .ai-val-assessment-title { font-weight: 700; font-size: 0.88rem; margin-bottom: 2px; }
        .ai-val-assessment-desc { font-size: 0.78rem; color: #475569; line-height: 1.35; font-weight: 500; }

        .ai-val-score-widget {
          display: flex;
          align-items: center;
          justify-content: space-between;
          gap: 16px;
        }
        .ai-val-score-gauge-wrap {
          position: relative;
          width: 80px;
          height: 80px;
          display: flex;
          align-items: center;
          justify-content: center;
        }
        .ai-val-score-gauge-wrap path.circle {
          transition: stroke-dasharray 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .ai-val-score-text {
          position: absolute;
          text-align: center;
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          line-height: 1;
        }
        .ai-val-score-val { font-size: 1.25rem; font-weight: 800; color: #1E293B; }
        .ai-val-score-total { font-size: 0.65rem; color: #64748B; font-weight: 600; margin-top: 1px; }

        .ai-val-score-legend {
          flex: 1;
          display: flex;
          flex-direction: column;
          gap: 6px;
        }
        .legend-item {
          display: flex;
          align-items: center;
          justify-content: space-between;
          font-size: 0.78rem;
          font-weight: 600;
          color: #475569;
        }
        .legend-label-wrap { display: flex; align-items: center; gap: 8px; }
        .legend-dot { width: 8px; height: 8px; border-radius: 50%; }
        .legend-dot.critical { background: #EF4444; }
        .legend-dot.needs-review { background: #F59E0B; }
        .legend-dot.good { background: #10B981; }
        .legend-val { color: #1E293B; font-weight: 700; }

        .ai-val-recs-panel { background: #F5F3FF; border: 1px solid #EDE9FE; }
        .ai-val-recs-header { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        .ai-val-recs-sparkle { color: #8B5CF6; display: flex; align-items: center; }
        .ai-val-recs-title { font-size: 0.82rem; font-weight: 700; color: #5B21B6; text-transform: uppercase; letter-spacing: 0.5px; }
        .ai-val-recs-box { font-size: 0.8rem; color: #4C1D95; line-height: 1.45; font-weight: 500; }
        .ai-val-recs-link { display: inline-flex; align-items: center; gap: 4px; color: #6D28D9; font-size: 0.78rem; font-weight: 700; text-decoration: none; margin-top: 8px; transition: opacity 0.2s ease; }
        .ai-val-recs-link:hover { opacity: 0.8; text-decoration: underline; }
        .ai-val-recs-list { margin: 8px 0 0 0; padding-left: 14px; list-style-type: decimal; font-size: 0.78rem; color: #4C1D95; }
        .ai-val-recs-list li { margin-bottom: 4px; }

        .ai-val-actions-wrap { display: flex; flex-direction: column; gap: 10px; margin-top: 4px; }
        .ai-val-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px; font-size: 0.84rem; font-weight: 700; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; width: 100%; }
        .ai-val-btn-edit { background: #ffffff; border: 1.5px solid #4F46E5; color: #4F46E5; }
        .ai-val-btn-edit:hover { background: #F5F3FF; }
        .ai-val-btn-proceed { background: #4F46E5; border: 1.5px solid #4F46E5; color: #ffffff; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); }
        .ai-val-btn-proceed:hover { background: #4338CA; box-shadow: 0 6px 14px rgba(79, 70, 229, 0.3); }
        .ai-val-actions-caption { font-size: 0.72rem; color: #64748B; text-align: center; line-height: 1.35; margin-top: 2px; font-weight: 500; }
      </style>
    `;

    // 9. Put everything together into the new layout HTML
    contentEl.innerHTML = `
      ${cssStyles}
      
      <!-- Premium Modal Header -->
      <div class="ai-val-modal-header">
        <div class="ai-val-header-left">
          <div class="ai-val-logo-container">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275Z"></path><path d="m5 3 1 2.5L8.5 6 6 7 5 9.5 4 7 1.5 6 4 5.5Z" fill="currentColor" stroke="none"></path><path d="m19 17 1 2.5 2.5.5-2.5 1-1 2.5-1-2.5-2.5-1 2.5-1Z" fill="currentColor" stroke="none"></path></svg>
          </div>
          <div>
            <h5 class="ai-val-header-title">AI Proposal Validation</h5>
            <p class="ai-val-header-subtitle">AI-powered review to help improve the quality and completeness of your proposal.</p>
          </div>
        </div>
        <div class="ai-val-header-right">
          <span class="ai-val-reviewed-badge">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275Z"></path></svg>
            AI Reviewed
          </span>
          <div class="ai-val-timestamp">${timestampStr}</div>
        </div>
      </div>

      <!-- Premium Modal Body -->
      <div class="ai-val-body-grid">
        <!-- Left Column: Review Summary -->
        <div>
          <div class="ai-val-section-title">Review Summary</div>
          <div class="ai-val-accordion">
            ${sectionsHtml}
          </div>
          
          <!-- Bottom advisory note -->
          <div class="ai-val-info-card">
            <span class="ai-val-info-icon">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
            </span>
            <div>
              <strong>Note:</strong> AI findings are advisory only. Final administrative approval/rejection remains with the authorized approver.
            </div>
          </div>
        </div>

        <!-- Right Column: Overall Assessment & Score -->
        <div class="ai-val-right-pane">
          <!-- Overall Assessment Card -->
          <div class="ai-val-panel" style="padding: 16px;">
            <div class="ai-val-panel-title">Overall Assessment</div>
            <div class="ai-val-assessment-box ${assessmentClass}">
              <div class="ai-val-assessment-icon-wrap">${assessmentIcon}</div>
              <div>
                <div class="ai-val-assessment-title">${assessmentTitle}</div>
                <div class="ai-val-assessment-desc">${assessmentDesc}</div>
              </div>
            </div>
          </div>

          <!-- Review Score Card -->
          <div class="ai-val-panel">
            <div class="ai-val-panel-title">Review Score</div>
            <div class="ai-val-score-widget">
              <!-- Radial SVG Progress -->
              <div class="ai-val-score-gauge-wrap">
                <svg width="80" height="80" viewBox="0 0 36 36">
                  <path class="circle-bg"
                    d="M18 2.0845
                      a 15.9155 15.9155 0 0 1 0 31.831
                      a 15.9155 15.9155 0 0 1 0 -31.831"
                    fill="none"
                    stroke="#E2E8F0"
                    stroke-width="3"
                  />
                  <!-- Dasharray starts at 0 for animation -->
                  <path class="circle"
                    stroke-dasharray="0, 100"
                    d="M18 2.0845
                      a 15.9155 15.9155 0 0 1 0 31.831
                      a 15.9155 15.9155 0 0 1 0 -31.831"
                    fill="none"
                    stroke="${strokeColor}"
                    stroke-width="3"
                    stroke-linecap="round"
                  />
                </svg>
                <div class="ai-val-score-text">
                  <span class="ai-val-score-val">${score}</span>
                  <span class="ai-val-score-total">/ 100</span>
                </div>
              </div>

              <!-- Legend Counts -->
              <div class="ai-val-score-legend">
                <div class="legend-item">
                  <span class="legend-label-wrap">
                    <span class="legend-dot critical"></span>
                    Critical
                  </span>
                  <span class="legend-val">${criticalCount}</span>
                </div>
                <div class="legend-item">
                  <span class="legend-label-wrap">
                    <span class="legend-dot needs-review"></span>
                    Needs Review
                  </span>
                  <span class="legend-val">${warningCount}</span>
                </div>
                <div class="legend-item">
                  <span class="legend-label-wrap">
                    <span class="legend-dot good"></span>
                    Good
                  </span>
                  <span class="legend-val">${goodCount}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Recommendations Panel -->
          <div class="ai-val-panel ai-val-recs-panel">
            <div class="ai-val-recs-header">
              <span class="ai-val-recs-sparkle">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275Z"></path></svg>
              </span>
              <span class="ai-val-recs-title">AI Recommendations</span>
            </div>
            ${recommendationsHtml}
          </div>

          <!-- Actions Wrap (Submit & Edit) -->
          <div class="ai-val-actions-wrap">
            <button type="button" class="ai-val-btn ai-val-btn-edit" id="aiValBtnEdit">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
              Edit Proposal
            </button>
            <button type="button" class="ai-val-btn ai-val-btn-proceed" id="aiValBtnProceed">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transform: rotate(45deg);"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
              Proceed with Submission
            </button>
            <p class="ai-val-actions-caption">You can still proceed, but the proposal may be returned for revision.</p>
          </div>
        </div>
      </div>
    `;

    // Trigger score gauge entrance animation
    setTimeout(() => {
      const circleEl = contentEl.querySelector('.ai-val-score-gauge-wrap path.circle');
      if (circleEl) {
        circleEl.setAttribute('stroke-dasharray', `${score}, 100`);
      }
    }, 120);

    // Wire buttons click actions
    const btnEdit = contentEl.querySelector('#aiValBtnEdit');
    if (btnEdit) {
      btnEdit.onclick = function () {
        closeModal();
      };
    }

    const btnProceed = contentEl.querySelector('#aiValBtnProceed');
    if (btnProceed) {
      btnProceed.onclick = function () {
        _aiValidationApproved = true;
        closeModal();

        // Inject the valid data hash into a hidden form input
        let aiHashInput = _formEl.querySelector('input[name="ai_validation_hash"]');
        if (!aiHashInput) {
          aiHashInput = document.createElement('input');
          aiHashInput.type = 'hidden';
          aiHashInput.name = 'ai_validation_hash';
          _formEl.appendChild(aiHashInput);
        }
        aiHashInput.value = _lastValidationHash || '';

        proceedWithSubmission();
      };
    }

    // Show modal using Bootstrap 5 API or fallback selector classes
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
      _bsModal = bootstrap.Modal.getOrCreateInstance(_modalEl);
      _bsModal.show();
    } else {
      _modalEl.style.display = 'block';
      _modalEl.classList.add('show');
    }
  }

  function renderListItems(items) {
    if (!Array.isArray(items) || items.length === 0) {
      return `<li class="list-group-item text-muted small">No specific notes.</li>`;
    }
    return items.map(item => {
      return `<li class="list-group-item small" style="border-color: rgba(0,0,0,0.06);">${escapeHtml(item)}</li>`;
    }).join('');
  }

  function proceedWithSubmission() {
    if (!_formEl) return;

    let hiddenAction = _formEl.querySelector('input[name="action"][type="hidden"]');
    if (!hiddenAction) {
      hiddenAction = document.createElement('input');
      hiddenAction.type = 'hidden';
      hiddenAction.name = 'action';
      hiddenAction.value = 'submit';
      _formEl.appendChild(hiddenAction);
    } else {
      hiddenAction.value = 'submit';
    }

    _formEl.submit();
  }

  function closeModal() {
    if (_bsModal) {
      _bsModal.hide();
    } else if (_modalEl) {
      _modalEl.style.display = 'none';
      _modalEl.classList.remove('show');
    }
  }

  function showLoadingOverlay(show) {
    let overlay = document.getElementById('aiProposalValidationLoading');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'aiProposalValidationLoading';
      overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.65); z-index: 3000; display: none; align-items: center; justify-content: center;';
      overlay.innerHTML = `
        <div style="background: white; padding: 28px 36px; border-radius: 12px; display: flex; flex-direction: column; align-items: center; gap: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); max-width: 420px; text-align: center;">
          <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;"></div>
          <div>
            <h6 class="fw-bold mb-1">🤖 Validating Proposal...</h6>
            <div class="small text-muted">Evaluating proposal completeness, objectives, KPIs, and alignment with Google Gemini...</div>
          </div>
        </div>
      `;
      document.body.appendChild(overlay);
    }
    overlay.style.display = show ? 'flex' : 'none';
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
    runAIValidation: runAIValidation
  };
})();
