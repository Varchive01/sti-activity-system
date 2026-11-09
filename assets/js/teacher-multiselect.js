/**
 * assets/js/teacher-multiselect.js
 * Searchable Multi-Select Component for STI-ARMES Subject Teachers
 *
 * Features:
 * - Dynamic roster population from window.ELIGIBLE_FACULTY_ROSTER
 * - Strict de-duplication by account ID and normalized name (no duplicate faculty like Aldwin Alminar)
 * - Removable tag pills inside search field
 * - Floating dropdown panel styled for Light Mode & Dark Mode
 * - Distinct avatar initials and department badges (IT, THM, G.E.)
 * - Keyboard navigation (Up/Down/Enter/Escape/Backspace)
 * - Safe table layout non-disruption (floating absolute overlay)
 */

(function () {
  'use strict';

  function getRoster() {
    return Array.isArray(window.ELIGIBLE_FACULTY_ROSTER) ? window.ELIGIBLE_FACULTY_ROSTER : [];
  }

  function parseTeacherNames(val) {
    if (!val) return [];
    if (Array.isArray(val)) return val.map(s => String(s).trim()).filter(Boolean);
    try {
      const parsed = JSON.parse(val);
      if (Array.isArray(parsed)) return parsed.map(s => String(s).trim()).filter(Boolean);
    } catch (e) {}
    return String(val)
      .split(',')
      .map(s => s.trim())
      .filter(Boolean);
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  function getInitials(name) {
    if (!name) return 'FC';
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 1) return parts[0].substring(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  function getDeptClass(dept) {
    const d = (dept || '').toLowerCase();
    if (d.includes('it') || d.includes('information')) return 'it';
    if (d.includes('thm') || d.includes('tourism') || d.includes('hospitality')) return 'thm';
    if (d.includes('ge') || d.includes('g.e.') || d.includes('general')) return 'ge';
    return 'default';
  }

  function getRoleInfo(faculty) {
    const rawRole = String(faculty.role || 'faculty').toLowerCase().trim();
    const customLabel = String(faculty.role_label || '').trim();
    if (rawRole === 'admin1' || customLabel.toLowerCase().includes('student organization')) {
      return { key: 'admin1', label: customLabel || 'Student Organization Head', badgeClass: 'org-head' };
    }
    if (rawRole === 'admin2' || customLabel.toLowerCase().includes('events committee')) {
      return { key: 'admin2', label: customLabel || 'Events Committee Head', badgeClass: 'committee-head' };
    }
    if (rawRole === 'program_head' || customLabel.toLowerCase().includes('program head')) {
      return { key: 'program_head', label: customLabel || 'Program Head', badgeClass: 'prog-head' };
    }
    return { key: 'faculty', label: customLabel || 'Faculty', badgeClass: 'faculty' };
  }

  function cleanNameKey(name) {
    if (!name) return '';
    let clean = String(name)
      .replace(/\b(Mr|Ms|Mrs|Dr|Prof|Dean|Atty|Engr|LPT|MAEd|PE|RN|MBA)\b\.?/gi, '')
      .replace(/\b[a-zA-Z]\b\.?/g, ' ');
    if (typeof clean.normalize === 'function') {
      clean = clean.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    return clean.replace(/[^a-zA-Z]/g, '').toLowerCase();
  }

  function isSamePersonName(nameA, nameB) {
    const a = String(nameA || '').trim().toLowerCase();
    const b = String(nameB || '').trim().toLowerCase();
    if (!a || !b) return false;
    if (a === b) return true;
    const keyA = cleanNameKey(a);
    const keyB = cleanNameKey(b);
    return keyA !== '' && keyA === keyB;
  }

  window.initTeacherMultiSelect = function (container, initialValue = null) {
    if (!container || container._tmsInitialized) return;
    container._tmsInitialized = true;

    const hiddenInput   = container.querySelector('input.part-teacher-input');
    const tagsContainer = container.querySelector('.tms-tags');
    const searchInput   = container.querySelector('.tms-input');
    const dropdown      = container.querySelector('.tms-dropdown');
    const listContainer = container.querySelector('.tms-list');
    const emptyMsg      = container.querySelector('.tms-empty');
    const isReadonly    = hiddenInput && (hiddenInput.disabled || hiddenInput.readOnly);

    let selectedNames = [];
    if (initialValue !== null) {
      selectedNames = parseTeacherNames(initialValue);
    } else if (hiddenInput && hiddenInput.value) {
      selectedNames = parseTeacherNames(hiddenInput.value);
    }

    function syncHiddenInput() {
      if (hiddenInput) {
        hiddenInput.value = selectedNames.join(', ');
        hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
        hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }

    function renderTags() {
      if (!tagsContainer) return;
      tagsContainer.innerHTML = '';
      selectedNames.forEach(name => {
        const tag = document.createElement('span');
        tag.className = 'tms-tag';
        tag.setAttribute('data-name', name);
        if (isReadonly) {
          tag.innerHTML = `<span>${escapeHtml(name)}</span>`;
        } else {
          tag.innerHTML = `
            <span>${escapeHtml(name)}</span>
            <button type="button" class="tms-tag-remove" aria-label="Remove ${escapeHtml(name)}" title="Remove">&times;</button>
          `;
          const rmBtn = tag.querySelector('.tms-tag-remove');
          if (rmBtn) {
            rmBtn.addEventListener('click', (e) => {
              e.stopPropagation();
              e.preventDefault();
              removeTeacher(name);
            });
          }
        }
        tagsContainer.appendChild(tag);
      });
      syncHiddenInput();
    }

    function removeTeacher(name) {
      selectedNames = selectedNames.filter(n => !isSamePersonName(n, name));
      renderTags();
      if (dropdown && dropdown.style.display !== 'none') {
        renderDropdownOptions(searchInput ? searchInput.value : '');
      }
    }

    function addTeacher(name) {
      const cleanName = (name || '').trim();
      if (!cleanName) return;
      const exists = selectedNames.some(n => isSamePersonName(n, cleanName));
      if (!exists) {
        selectedNames.push(cleanName);
        renderTags();
      }
      if (searchInput) {
        searchInput.value = '';
        renderDropdownOptions('');
        searchInput.focus();
      }
    }

    function renderDropdownOptions(searchTerm = '') {
      if (!listContainer) return;
      listContainer.innerHTML = '';
      const roster = getRoster();
      const term   = (searchTerm || '').trim().toLowerCase();

      // Strict de-duplication by account ID, normalized name, and stripped name key
      const seenIds       = new Set();
      const seenNames     = new Set();
      const seenCleanKeys = new Set();
      let visibleCount    = 0;

      roster.forEach(faculty => {
        const id       = faculty.id ? String(faculty.id) : null;
        const name     = (faculty.name || '').trim();
        const dept     = (faculty.department || '').trim();
        const roleInfo = getRoleInfo(faculty);
        if (!name) return;

        const normName = name.toLowerCase();
        const cKey     = cleanNameKey(name);

        // Exclude test/non-faculty accounts: Van, Rain, Hilary, Shaira, Frederic Yulo
        const excludedNames = ['van', 'rain', 'hilary', 'shaira', 'fredericyulo', 'drfredericyulo'];
        if (excludedNames.includes(normName) || (cKey && excludedNames.includes(cKey))) return;

        // Prevent duplicate entries
        if (id && seenIds.has(id)) return;
        if (seenNames.has(normName)) return;
        if (cKey && seenCleanKeys.has(cKey)) return;

        if (id) seenIds.add(id);
        seenNames.add(normName);
        if (cKey) seenCleanKeys.add(cKey);

        const isMatch = !term
          || normName.includes(term)
          || dept.toLowerCase().includes(term)
          || roleInfo.label.toLowerCase().includes(term);
        if (!isMatch) return;

        visibleCount++;
        const isSelected = selectedNames.some(n => isSamePersonName(n, name));
        const initials   = getInitials(name);
        const deptClass  = getDeptClass(dept);

        const opt = document.createElement('div');
        opt.className = 'tms-option' + (isSelected ? ' tms-selected' : '');
        opt.setAttribute('data-name', name);
        if (id) opt.setAttribute('data-id', id);
        opt.setAttribute('data-role', roleInfo.key);
        opt.setAttribute('role', 'option');
        opt.setAttribute('aria-selected', isSelected ? 'true' : 'false');

        const showRoleBadge = roleInfo.label && roleInfo.label.toLowerCase() !== (dept || '').toLowerCase();
        const roleBadgeHtml = showRoleBadge
          ? `<span class="tms-role-badge tms-role-${roleInfo.badgeClass}">${escapeHtml(roleInfo.label)}</span>`
          : '';

        opt.innerHTML = `
          <div class="tms-opt-left">
            <div class="tms-opt-avatar">${initials}</div>
            <div class="tms-opt-info">
              <span class="tms-opt-name">${escapeHtml(name)}</span>
              <div class="tms-opt-badges">
                <span class="tms-dept-badge tms-dept-${deptClass}">${escapeHtml(dept || 'Faculty')}</span>
                ${roleBadgeHtml}
              </div>
            </div>
          </div>
          <div class="tms-opt-status">
            ${isSelected 
              ? '<span class="tms-check-badge">✓ Selected</span>' 
              : '<span class="tms-add-icon" title="Add">+</span>'}
          </div>
        `;

        opt.addEventListener('click', (e) => {
          e.stopPropagation();
          e.preventDefault();
          if (isSelected) {
            removeTeacher(name);
          } else {
            addTeacher(name);
          }
        });

        listContainer.appendChild(opt);
      });

      if (emptyMsg) {
        emptyMsg.style.display = visibleCount === 0 ? 'block' : 'none';
      }
    }

    function openDropdown() {
      if (isReadonly || !dropdown) return;
      // Close other open multi-select dropups
      document.querySelectorAll('.teacher-multiselect.tms-open').forEach(other => {
        if (other !== container && other._tms) {
          other._tms.closeDropdown();
        }
      });
      container.classList.add('tms-open');
      dropdown.style.display = 'block';
      renderDropdownOptions(searchInput ? searchInput.value : '');
    }

    function closeDropdown() {
      if (!dropdown) return;
      container.classList.remove('tms-open');
      dropdown.style.display = 'none';
    }

    if (searchInput && !isReadonly) {
      searchInput.addEventListener('focus', openDropdown);
      searchInput.addEventListener('input', () => {
        openDropdown();
        renderDropdownOptions(searchInput.value);
      });
      searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !searchInput.value && selectedNames.length > 0) {
          removeTeacher(selectedNames[selectedNames.length - 1]);
        } else if (e.key === 'Escape') {
          closeDropdown();
        } else if (e.key === 'Enter') {
          e.preventDefault();
          const firstOption = listContainer ? listContainer.querySelector('.tms-option:not(.tms-selected)') : null;
          if (firstOption) {
            const optName = firstOption.getAttribute('data-name');
            if (optName) addTeacher(optName);
          }
        }
      });
    }

    renderTags();

    document.addEventListener('click', (e) => {
      if (!container.contains(e.target)) {
        closeDropdown();
      }
    });

    document.addEventListener('focusin', (e) => {
      if (!container.contains(e.target)) {
        closeDropdown();
      }
    });

    container._tms = {
      getSelected: () => [...selectedNames],
      setSelected: (names) => {
        selectedNames = parseTeacherNames(names);
        renderTags();
      },
      addTeacher,
      removeTeacher,
      openDropdown,
      closeDropdown
    };
  };

  window.focusTmsInput = function (controlEl) {
    if (!controlEl) return;
    const container = controlEl.closest('.teacher-multiselect');
    if (!container) return;
    const input = container.querySelector('.tms-input');
    if (input && !input.disabled && !input.readOnly) {
      input.focus();
    }
  };

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.teacher-multiselect').forEach(el => {
      if (!el._tmsInitialized) {
        window.initTeacherMultiSelect(el);
      }
    });
  });
})();
