/**
 * STI-ARMES Global File Upload & Dropzone Validator
 *
 * Automatically validates every <input type="file" accept="..."> across the entire
 * system (including file picker "All Files" overrides and drag-and-drop onto inputs).
 * Rejects unsupported files immediately, clears the input, and displays a clear
 * inline warning banner + toast notification.
 */
(function () {
  'use strict';

  if (window.__stiFileUploadValidatorBound) return;
  window.__stiFileUploadValidatorBound = true;

  const MIME_TO_EXTENSIONS = {
    'text/csv': ['.csv'],
    'application/csv': ['.csv'],
    'application/vnd.ms-excel': ['.xls', '.csv'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'],
    'application/pdf': ['.pdf'],
    'application/msword': ['.doc'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': ['.docx'],
    'image/jpeg': ['.jpg', '.jpeg'],
    'image/png': ['.png'],
    'image/webp': ['.webp'],
    'image/gif': ['.gif'],
    'image/*': ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.svg', '.bmp']
  };

  function parseAllowedExtensions(acceptAttr) {
    if (!acceptAttr || !acceptAttr.trim()) return [];
    const tokens = acceptAttr
      .split(',')
      .map(t => t.trim().toLowerCase())
      .filter(Boolean);

    const exts = new Set();
    for (const token of tokens) {
      if (token.startsWith('.')) {
        exts.add(token);
      } else if (MIME_TO_EXTENSIONS[token]) {
        MIME_TO_EXTENSIONS[token].forEach(ext => exts.add(ext));
      }
    }
    return Array.from(exts);
  }

  function getFileExtension(filename) {
    if (!filename || typeof filename !== 'string') return '';
    const lastDot = filename.lastIndexOf('.');
    if (lastDot <= 0 || lastDot === filename.length - 1) return '';
    return filename.slice(lastDot).toLowerCase();
  }

  function clearInlineWarning(input) {
    if (!input) return;
    input.style.removeProperty('border-color');
    input.style.removeProperty('box-shadow');
    input.removeAttribute('aria-invalid');
    const parent = input.parentElement;
    if (parent) {
      const existing = parent.querySelector('.sti-file-type-warning');
      if (existing) existing.remove();
    }
  }

  function showInlineWarning(input, message) {
    clearInlineWarning(input);
    if (!input) return;

    input.style.setProperty('border-color', '#dc2626', 'important');
    input.style.setProperty('box-shadow', '0 0 0 3px rgba(220, 38, 38, 0.16)', 'important');
    input.setAttribute('aria-invalid', 'true');

    const banner = document.createElement('div');
    banner.className = 'sti-file-type-warning';
    banner.setAttribute('role', 'alert');
    banner.style.cssText =
      'margin-top:6px; padding:8px 12px; border-radius:8px; ' +
      'background:rgba(220,38,38,0.09); border:1px solid rgba(220,38,38,0.35); ' +
      'color:#b91c1c; font-size:0.8rem; font-weight:600; line-height:1.4; ' +
      'display:flex; align-items:flex-start; gap:8px;';

    const icon = document.createElement('span');
    icon.textContent = '⚠️';
    icon.style.flexShrink = '0';

    const text = document.createElement('span');
    text.textContent = message;

    banner.appendChild(icon);
    banner.appendChild(text);

    // Insert right after the file input
    if (input.parentNode) {
      input.parentNode.insertBefore(banner, input.nextSibling);
    }
  }

  function showFloatingToast(message) {
    let toast = document.getElementById('stiFileTypeToast');
    if (toast) toast.remove();

    toast = document.createElement('div');
    toast.id = 'stiFileTypeToast';
    toast.setAttribute('role', 'alert');
    toast.style.cssText =
      'position:fixed; bottom:20px; right:20px; z-index:999999; ' +
      'max-width:min(420px, calc(100vw - 32px)); padding:12px 16px; border-radius:10px; ' +
      'background:#dc2626; color:#ffffff; font-size:0.84rem; font-weight:600; ' +
      'line-height:1.4; box-shadow:0 12px 28px rgba(185,28,28,0.35); ' +
      'display:flex; align-items:flex-start; gap:10px;';

    const icon = document.createElement('span');
    icon.textContent = '⚠️';
    icon.style.flexShrink = '0';

    const text = document.createElement('span');
    text.textContent = message;

    toast.appendChild(icon);
    toast.appendChild(text);
    document.body.appendChild(toast);

    setTimeout(function () {
      if (toast && toast.parentNode) {
        toast.remove();
      }
    }, 5500);
  }

  function validateFileInput(input, event) {
    if (!input || input.tagName !== 'INPUT' || input.type !== 'file') return true;
    const acceptAttr = input.getAttribute('accept');
    const allowedExts = parseAllowedExtensions(acceptAttr);
    if (allowedExts.length === 0) return true;

    const files = input.files;
    if (!files || files.length === 0) {
      clearInlineWarning(input);
      return true;
    }

    const invalidFiles = [];
    for (let i = 0; i < files.length; i++) {
      const f = files[i];
      const ext = getFileExtension(f.name);
      if (!ext || !allowedExts.includes(ext)) {
        invalidFiles.push(f.name);
      }
    }

    if (invalidFiles.length > 0) {
      // Reject immediately before any onchange handler runs
      input.value = '';
      if (event) {
        event.stopImmediatePropagation();
      }

      const allowedLabel = allowedExts.map(e => e.toUpperCase()).join(', ');
      const fileLabel = invalidFiles.length === 1
        ? '"' + invalidFiles[0] + '"'
        : invalidFiles.length + ' selected files (' + invalidFiles.slice(0, 2).join(', ') + ')';

      const msg =
        'Invalid file type: ' + fileLabel + ' is not supported for this upload box. ' +
        'Allowed format(s): ' + allowedLabel + '.';

      showInlineWarning(input, msg);
      showFloatingToast(msg);
      return false;
    }

    clearInlineWarning(input);
    return true;
  }

  // Capture-phase listener guarantees validation runs BEFORE inline onchange="..." handlers
  document.addEventListener('change', function (e) {
    if (e.target && e.target.tagName === 'INPUT' && e.target.type === 'file') {
      validateFileInput(e.target, e);
    }
  }, true);

  // Also guard form resets to clear stale warning banners
  document.addEventListener('reset', function (e) {
    if (e.target && typeof e.target.querySelectorAll === 'function') {
      e.target.querySelectorAll('input[type="file"]').forEach(clearInlineWarning);
    }
  }, true);

  // Expose helper for manual / unit testing
  window.STIFileUploadValidator = {
    parseAllowedExtensions: parseAllowedExtensions,
    getFileExtension: getFileExtension,
    validateFileInput: validateFileInput
  };
})();
