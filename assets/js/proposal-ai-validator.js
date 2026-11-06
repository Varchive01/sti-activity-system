/**
 * assets/js/proposal-ai-validator.js
 *
 * Hardcoded, Rule-Based Proposal Validation Engine for STI-ARMES.
 * Completely independent of Google Gemini API or external AI services (100% offline).
 *
 * Features:
 * 1. Automatic Capitalization Engine:
 *    - Corrects every occurrence of the name "john" to "John", regardless of position, count, or sentence length.
 *    - Ensures every sentence begins with a capital letter (start of text, after . ? ! and newlines).
 * 2. Instant Field Validation & Red Highlighting:
 *    - Immediately highlights missing or incomplete required fields/sections in red.
 *    - Displays clear, contextual validation messages directly underneath affected fields.
 *    - Automatically clears red highlights as soon as valid content is entered.
 * 3. Submission Workflow Integration:
 *    - Validates all proposal parameters deterministically before submission without blocking drafts.
 *    - Smoothly navigates to the first invalid step and focuses the affected field.
 */

const ProposalAIValidator = (function () {
  'use strict';

  let _formEl = null;
  let _modalEl = null;
  let _bsModal = null;
  let _baseUrl = '';
  let _isInitialized = false;

  /**
   * Required field configuration with human-readable labels and step associations.
   */
  const REQUIRED_FIELDS = [
    { id: 'f1_title', name: 'title', label: 'Activity Title', step: 1, minLength: 3 },
    { id: 'f1_venue', name: 'venue', label: 'Venue', step: 1, minLength: 2 },
    { id: 'f1_event_date', name: 'event_date', label: 'Event Date', step: 1 },
    { id: 'f1_start_time', name: 'start_time', label: 'Start Time', step: 1 },
    { id: 'f1_end_time', name: 'end_time', label: 'End Time', step: 1 },
    { id: 'f1_theme', name: 'theme', label: 'Activity Theme', step: 1, minLength: 2 },
    { id: 'f1_target_participants', name: 'target_participants', label: 'Target Participants', step: 1, isNumeric: true, minVal: 1 },
    { id: 'f2_general_objectives', name: 'general_objectives', label: 'General Objectives', step: 2, minLength: 5 },
    { id: 'f2_specific_objectives', name: 'specific_objectives', label: 'Specific Objectives', step: 2, minLength: 5 },
    { id: 'f2_involved_subjects', name: 'involved_subjects', label: 'Involved Subjects', step: 2, minLength: 2 },
    { id: 'f2_rationale', name: 'rationale', label: 'Rationale', step: 2, minLength: 5 }
  ];

  /**
   * Applies rule-based text capitalization:
   * 1. Replaces every occurrence of "john" (case-insensitive word) with "John".
   * 2. Capitalizes the first letter of every sentence (start of string, after . ? ! and line breaks).
   *
   * @param {string} text
   * @return {string}
   */
  function autoCapitalizeText(text) {
    if (!text || typeof text !== 'string') return text;

    // 1. Correct any occurrence of the name "john" to "John" regardless of position
    let result = text.replace(/\bjohn\b/gi, 'John');

    // 2. Capitalize first letter of string (skipping leading whitespace, quotes, dashes, bullets)
    result = result.replace(/^(\s*["'“‘(\[-]*)(\p{L})/u, function (match, prefix, char) {
      return prefix + char.toUpperCase();
    });

    // 3. Capitalize first letter after terminal punctuation (. ? !) followed by whitespace
    result = result.replace(/([.?!]\s+["'“‘(\[-]*)(\p{L})/gu, function (match, prefix, char) {
      return prefix + char.toUpperCase();
    });

    // 4. Capitalize first letter after newlines / bullet points
    result = result.replace(/([\r\n]+\s*[-*•\d.)\]]*\s*["'“‘(\[-]*)(\p{L})/gu, function (match, prefix, char) {
      return prefix + char.toUpperCase();
    });

    return result;
  }

  /**
   * Applies capitalization to all text inputs and textareas within the form.
   */
  function applyCapitalizationToForm() {
    if (!_formEl) return;
    const textEls = _formEl.querySelectorAll('input[type="text"], textarea');
    textEls.forEach(function (el) {
      if (el.readOnly || el.disabled) return;
      const original = el.value;
      const capitalized = autoCapitalizeText(original);
      if (original !== capitalized) {
        el.value = capitalized;
      }
    });
  }

  /**
   * Highlights a field in red and displays a clear validation message.
   *
   * @param {HTMLElement} el
   * @param {string} message
   */
  function highlightFieldError(el, message) {
    if (!el) return;

    el.classList.add('rule-field-error');
    el.classList.add('is-invalid');

    // Locate or create message container
    const fieldId = el.id || el.name || 'field';
    const errId = 'rule_err_' + fieldId;
    let msgEl = document.getElementById(errId);

    if (!msgEl) {
      msgEl = document.createElement('div');
      msgEl.id = errId;
      msgEl.className = 'rule-error-msg';
      msgEl.setAttribute('role', 'alert');

      // Place after field or field's parent input-group
      const parent = el.closest('.input-group') || el.parentNode;
      if (parent) {
        parent.appendChild(msgEl);
      } else {
        el.insertAdjacentElement('afterend', msgEl);
      }
    }

    msgEl.innerHTML = '<span style="font-weight:700;">⚠️</span> ' + escapeHtml(message);
    msgEl.style.display = 'flex';
  }

  /**
   * Removes red highlighting and hides the validation message.
   *
   * @param {HTMLElement} el
   */
  function clearFieldError(el) {
    if (!el) return;

    el.classList.remove('rule-field-error');
    el.classList.remove('is-invalid');

    const fieldId = el.id || el.name || 'field';
    const errId = 'rule_err_' + fieldId;
    const msgEl = document.getElementById(errId);
    if (msgEl) {
      msgEl.style.display = 'none';
      msgEl.innerHTML = '';
    }
  }

  /**
   * Escapes HTML characters for safe injection.
   */
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
   * Validates a single field rule.
   *
   * @param {Object} cfg
   * @param {boolean} showHighlight
   * @return {boolean}
   */
  function validateFieldRule(cfg, showHighlight = true) {
    if (!_formEl) return true;

    const el = document.getElementById(cfg.id) || _formEl.querySelector(`[name="${cfg.name}"]`);
    if (!el) return true;

    const val = (el.value || '').trim();
    let isValid = true;
    let errMsg = '';

    if (val === '') {
      isValid = false;
      errMsg = `${cfg.label} is required.`;
    } else if (cfg.minLength && val.length < cfg.minLength) {
      isValid = false;
      errMsg = `${cfg.label} must be at least ${cfg.minLength} characters.`;
    } else if (cfg.isNumeric) {
      const num = parseInt(val, 10);
      if (isNaN(num) || (cfg.minVal !== undefined && num < cfg.minVal)) {
        isValid = false;
        errMsg = `${cfg.label} must be a number greater than 0.`;
      }
    }

    if (isValid && cfg.id === 'f1_end_time') {
      const startEl = document.getElementById('f1_start_time') || _formEl.querySelector('[name="start_time"]');
      if (startEl && startEl.value.trim()) {
        const startVal = startEl.value.trim();
        const endVal = val;
        if (startVal >= endVal) {
          isValid = false;
          errMsg = 'End Time must be later than Start Time.';
        }
      }
    }

    if (!isValid) {
      if (showHighlight) {
        highlightFieldError(el, errMsg);
      }
      return false;
    } else {
      clearFieldError(el);
      return true;
    }
  }

  /**
   * Validates all required proposal form rules.
   *
   * @param {boolean} showHighlights
   * @return {{ isValid: boolean, firstInvalidStep: number|null, firstInvalidEl: HTMLElement|null }}
   */
  function validateFormRules(showHighlights = true) {
    if (!_formEl) return { isValid: true, firstInvalidStep: null, firstInvalidEl: null };

    // Apply auto-capitalization across all text fields first
    applyCapitalizationToForm();

    let allValid = true;
    let firstInvalidStep = null;
    let firstInvalidEl = null;

    REQUIRED_FIELDS.forEach(function (cfg) {
      const ok = validateFieldRule(cfg, showHighlights);
      if (!ok) {
        allValid = false;
        const el = document.getElementById(cfg.id) || _formEl.querySelector(`[name="${cfg.name}"]`);
        if (firstInvalidStep === null && cfg.step) {
          firstInvalidStep = cfg.step;
          firstInvalidEl = el;
        }
      }
    });

    return {
      isValid: allValid,
      firstInvalidStep: firstInvalidStep,
      firstInvalidEl: firstInvalidEl
    };
  }

  /**
   * Sets up real-time event listeners on proposal fields for auto-capitalization and red highlighting.
   */
  function setupFieldListeners() {
    if (!_formEl) return;

    // 1. Text capitalization listeners on all inputs and textareas
    const textEls = _formEl.querySelectorAll('input[type="text"], textarea');
    textEls.forEach(function (el) {
      // On blur: format capitalization
      el.addEventListener('blur', function () {
        if (!el.readOnly && !el.disabled) {
          const formatted = autoCapitalizeText(el.value);
          if (el.value !== formatted) {
            el.value = formatted;
          }
        }
      });

      // On input: automatically correct "john" to "John" if typed as a completed word
      el.addEventListener('input', function () {
        if (!el.readOnly && !el.disabled && el.value) {
          // If word boundary "john " is detected, capitalize immediately
          if (/\bjohn(\s|[.,!?:;])/i.test(el.value)) {
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const updated = el.value.replace(/\bjohn(\b|[.,!?:;])/gi, function (m) {
              return 'John' + m.slice(4);
            });
            if (el.value !== updated) {
              el.value = updated;
              if (start !== null && end !== null) {
                try { el.setSelectionRange(start, end); } catch (e) {}
              }
            }
          }
        }
      });
    });

    // 2. Real-time validation listeners for required fields
    REQUIRED_FIELDS.forEach(function (cfg) {
      const el = document.getElementById(cfg.id) || _formEl.querySelector(`[name="${cfg.name}"]`);
      if (!el) return;

      el.addEventListener('blur', function () {
        validateFieldRule(cfg, true);
      });

      el.addEventListener('input', function () {
        if (el.classList.contains('rule-field-error')) {
          validateFieldRule(cfg, true);
        }
      });

      el.addEventListener('change', function () {
        validateFieldRule(cfg, true);
      });
    });
  }

  /**
   * Initializes the rule-based proposal validator module.
   */
  function init(options) {
    options = options || {};
    _baseUrl = options.baseUrl || '';

    _formEl = typeof options.formSelector === 'string'
      ? document.querySelector(options.formSelector)
      : (options.formSelector || document.getElementById('proposalForm'));

    _modalEl = typeof options.modalSelector === 'string'
      ? document.querySelector(options.modalSelector)
      : (options.modalSelector || document.getElementById('aiProposalValidationModal'));

    if (!_formEl) return;

    if (!_isInitialized) {
      setupFieldListeners();
      _isInitialized = true;
    }
  }

  /**
   * Runs the hardcoded, rule-based proposal validation.
   * Completely offline, instantaneous, and independent of external AI services.
   *
   * @param {boolean} forceRefresh
   */
  async function runAIValidation(forceRefresh = false) {
    if (!_formEl) return;

    // 1. Run full rule-based validation
    const result = validateFormRules(true);

    if (!result.isValid) {
      // If there are invalid fields, navigate to the first invalid step
      if (result.firstInvalidStep && typeof window.showStep === 'function') {
        window.showStep(result.firstInvalidStep);
      }

      if (result.firstInvalidEl) {
        result.firstInvalidEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        try { result.firstInvalidEl.focus(); } catch (e) {}
      }

      const alertMsg = '⚠️ Please complete all required fields highlighted in red before submitting.';
      if (window.STIModal && typeof window.STIModal.alert === 'function') {
        window.STIModal.alert(alertMsg, { title: 'Required Fields Incomplete', variant: 'danger' });
      } else {
        alert(alertMsg);
      }
      return false;
    }

    // 2. Apply capitalization across all text fields
    applyCapitalizationToForm();

    // 3. Close validation modal if open
    closeModal();

    // 4. Ensure action is submit and proceed
    proceedWithSubmission();
    return true;
  }

  /**
   * Submits the proposal form cleanly.
   */
  async function proceedWithSubmission() {
    if (!_formEl) return;

    if (typeof window.serializeEvaluationQuestions === 'function') {
      try { window.serializeEvaluationQuestions(); } catch (e) {}
    }
    if (typeof window.fpSaveToHidden === 'function') {
      try { window.fpSaveToHidden(); } catch (e) {}
    }

    // Ensure action=submit
    const actionInputs = _formEl.querySelectorAll('input[name="action"]');
    if (actionInputs.length > 0) {
      actionInputs.forEach(inp => { inp.value = 'submit'; });
    } else {
      const hiddenAction = document.createElement('input');
      hiddenAction.type = 'hidden';
      hiddenAction.name = 'action';
      hiddenAction.id = 'proposalFormAction';
      hiddenAction.value = 'submit';
      _formEl.appendChild(hiddenAction);
    }

    // Clear local offline drafts
    if (window.OfflineDraftManager && typeof window.OfflineDraftManager.clearDraftForForm === 'function') {
      try {
        await window.OfflineDraftManager.clearDraftForForm(_formEl);
      } catch (e) {}
    }

    HTMLFormElement.prototype.submit.call(_formEl);
  }

  function closeModal() {
    if (_bsModal) {
      _bsModal.hide();
    } else if (_modalEl) {
      _modalEl.style.display = 'none';
      _modalEl.classList.remove('show');
    }
  }

  return {
    init: init,
    autoCapitalizeText: autoCapitalizeText,
    applyCapitalizationToForm: applyCapitalizationToForm,
    validateFormRules: validateFormRules,
    validateFieldRule: validateFieldRule,
    highlightFieldError: highlightFieldError,
    clearFieldError: clearFieldError,
    runAIValidation: runAIValidation,
    proceedWithSubmission: proceedWithSubmission,
    closeModal: closeModal
  };
})();

// Auto-initialize when DOM is ready
if (typeof document !== 'undefined') {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      ProposalAIValidator.init();
    });
  } else {
    ProposalAIValidator.init();
  }
}
