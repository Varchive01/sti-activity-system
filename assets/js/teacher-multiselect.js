/**
 * assets/js/teacher-multiselect.js
 * Searchable Multi-Select Component for STI-ARMES Subject Teachers
 *
 * Allows users to search faculty members by name, select multiple teachers,
 * displays removable tags within the field, and prevents duplicate entries.
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
      selectedNames = selectedNames.filter(n => n.toLowerCase() !== name.toLowerCase());
      renderTags();
      if (dropdown && dropdown.style.display !== 'none') {
        renderDropdownOptions(searchInput ? searchInput.value : '');
      }
    }

    function addTeacher(name) {
      const cleanName = (name || '').trim();
      if (!cleanName) return;
      const exists = selectedNames.some(n => n.toLowerCase() === cleanName.toLowerCase());
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

      let visibleCount = 0;
      roster.forEach(faculty => {
        const name = (faculty.name || '').trim();
        const dept = (faculty.department || '').trim();
        if (!name) return;

        const isMatch = !term || name.toLowerCase().includes(term) || dept.toLowerCase().includes(term);
        if (!isMatch) return;

        visibleCount++;
        const isSelected = selectedNames.some(n => n.toLowerCase() === name.toLowerCase());

        const opt = document.createElement('div');
        opt.className = 'tms-option' + (isSelected ? ' tms-selected' : '');
        opt.setAttribute('data-name', name);
        opt.setAttribute('role', 'option');
        opt.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        opt.innerHTML = `
          <div class="tms-opt-info">
            <span class="tms-opt-name">${escapeHtml(name)}</span>
            ${dept ? `<span class="tms-opt-dept">${escapeHtml(dept)}</span>` : ''}
          </div>
          ${isSelected ? '<span class="tms-opt-check" style="color: #0284c7; font-weight: bold; margin-left: 8px;">✓</span>' : ''}
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
      // Close other open dropdowns first
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
