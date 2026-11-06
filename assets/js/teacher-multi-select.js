/**
 * assets/js/teacher-multi-select.js
 *
 * Searchable multi-select dropdown component for Subject Teachers in STI-ARMES.
 * Allows searching eligible faculty members by name, selecting multiple teachers
 * per subject/section, rendering each as a removable chip/tag, preventing duplicates,
 * and maintaining responsive layout across mobile and desktop.
 */

(function () {
  'use strict';

  function getFacultyRoster() {
    if (Array.isArray(window.STI_FACULTY_ROSTER) && window.STI_FACULTY_ROSTER.length > 0) {
      return window.STI_FACULTY_ROSTER;
    }
    return [];
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function initTeacherMultiSelect(container, initialTeachers) {
    if (!container || container.dataset.multiSelectInitialized === 'true') {
      return;
    }
    container.dataset.multiSelectInitialized = 'true';

    const tagsContainer = container.querySelector('.teacher-tags-container');
    const searchInput = container.querySelector('.teacher-search-input');
    const hiddenInput = container.querySelector('.part-teacher-input');
    const dropdownList = container.querySelector('.teacher-dropdown-list');

    if (!tagsContainer || !searchInput || !hiddenInput || !dropdownList) {
      return;
    }

    // Initialize selection set
    const selectedNames = [];

    // Parse initial value from param or hidden input
    const initialRaw = (typeof initialTeachers === 'string' && initialTeachers.trim() !== '')
      ? initialTeachers
      : (hiddenInput.value || '');

    if (initialRaw.trim() !== '') {
      const parts = initialRaw.split(',').map(s => s.trim()).filter(s => s.length > 0);
      parts.forEach(name => {
        if (!selectedNames.some(existing => existing.toLowerCase() === name.toLowerCase())) {
          selectedNames.push(name);
        }
      });
    }

    function syncHiddenInput() {
      hiddenInput.value = selectedNames.join(', ');
      // Trigger change and input events for form dirty state tracking
      hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
      hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function renderTags() {
      // Remove all existing chips
      const existingChips = tagsContainer.querySelectorAll('.teacher-chip');
      existingChips.forEach(chip => chip.remove());

      // Insert chips before the search input
      selectedNames.forEach(name => {
        const chip = document.createElement('span');
        chip.className = 'teacher-chip';
        chip.dataset.name = name;
        chip.innerHTML = `
          <span class="teacher-chip-label">${escapeHtml(name)}</span>
          <button type="button" class="teacher-chip-remove" title="Remove ${escapeHtml(name)}" aria-label="Remove">&times;</button>
        `;

        const removeBtn = chip.querySelector('.teacher-chip-remove');
        removeBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          removeTeacher(name);
        });

        tagsContainer.insertBefore(chip, searchInput);
      });

      // Update placeholder
      if (selectedNames.length > 0) {
        searchInput.placeholder = '+ Add teacher...';
      } else {
        searchInput.placeholder = 'Search or select teacher...';
      }
    }

    function addTeacher(name) {
      const cleanName = name.trim();
      if (!cleanName) return;

      // Duplicate check (case-insensitive)
      const isDuplicate = selectedNames.some(existing => existing.toLowerCase() === cleanName.toLowerCase());
      if (isDuplicate) {
        searchInput.value = '';
        renderDropdown();
        return;
      }

      selectedNames.push(cleanName);
      syncHiddenInput();
      renderTags();
      searchInput.value = '';
      renderDropdown();
      searchInput.focus();
    }

    function removeTeacher(name) {
      const idx = selectedNames.findIndex(existing => existing.toLowerCase() === name.toLowerCase());
      if (idx !== -1) {
        selectedNames.splice(idx, 1);
        syncHiddenInput();
        renderTags();
        renderDropdown();
        searchInput.focus();
      }
    }

    let highlightedIdx = -1;

    function renderDropdown() {
      const query = (searchInput.value || '').trim().toLowerCase();
      const roster = getFacultyRoster();

      const filtered = roster.filter(f => {
        const nameMatch = (f.name || '').toLowerCase().includes(query);
        const deptMatch = (f.department || '').toLowerCase().includes(query);
        return nameMatch || deptMatch;
      });

      dropdownList.innerHTML = '';
      highlightedIdx = -1;

      if (filtered.length === 0) {
        if (query.length > 0) {
          const customItem = document.createElement('div');
          customItem.className = 'teacher-dropdown-item teacher-dropdown-custom';
          customItem.innerHTML = `
            <span>Add "<strong>${escapeHtml(searchInput.value.trim())}</strong>"</span>
            <span class="teacher-item-dept" style="background:#e0f2fe; color:#0369a1;">Custom</span>
          `;
          customItem.addEventListener('mousedown', (e) => {
            e.preventDefault();
            addTeacher(searchInput.value.trim());
          });
          dropdownList.appendChild(customItem);
        } else {
          const empty = document.createElement('div');
          empty.className = 'teacher-dropdown-empty';
          empty.textContent = 'No eligible faculty found';
          dropdownList.appendChild(empty);
        }
        dropdownList.style.display = 'block';
        return;
      }

      filtered.forEach((f, idx) => {
        const item = document.createElement('div');
        item.className = 'teacher-dropdown-item';
        item.dataset.idx = idx;

        const isSelected = selectedNames.some(existing => existing.toLowerCase() === f.name.toLowerCase());
        if (isSelected) {
          item.classList.add('selected');
        }

        item.innerHTML = `
          <div class="teacher-item-name" style="font-weight: 500;">
            ${isSelected ? '<span class="teacher-item-check" style="color:#0284c7; margin-right:6px; font-weight:700;">✓</span>' : ''}
            ${escapeHtml(f.name)}
          </div>
          <div class="teacher-item-dept" style="font-size:0.7rem; color:var(--text-muted); background:var(--bg-base); padding:2px 6px; border-radius:4px;">
            ${escapeHtml(f.department || 'General')}
          </div>
        `;

        item.addEventListener('mousedown', (e) => {
          e.preventDefault();
          if (isSelected) {
            removeTeacher(f.name);
          } else {
            addTeacher(f.name);
          }
        });

        dropdownList.appendChild(item);
      });

      dropdownList.style.display = 'block';
    }

    function hideDropdown() {
      dropdownList.style.display = 'none';
      highlightedIdx = -1;
    }

    // Events
    searchInput.addEventListener('focus', () => {
      renderDropdown();
    });

    searchInput.addEventListener('input', () => {
      renderDropdown();
    });

    searchInput.addEventListener('keydown', (e) => {
      const items = dropdownList.querySelectorAll('.teacher-dropdown-item');

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (items.length === 0) return;
        highlightedIdx = (highlightedIdx + 1) % items.length;
        updateHighlight(items);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (items.length === 0) return;
        highlightedIdx = (highlightedIdx - 1 + items.length) % items.length;
        updateHighlight(items);
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (highlightedIdx >= 0 && items[highlightedIdx]) {
          items[highlightedIdx].dispatchEvent(new MouseEvent('mousedown'));
        } else if (searchInput.value.trim().length > 0) {
          // If typed text, add it
          addTeacher(searchInput.value.trim());
        }
      } else if (e.key === 'Backspace' && searchInput.value === '' && selectedNames.length > 0) {
        // Remove last tag
        const lastTeacher = selectedNames[selectedNames.length - 1];
        removeTeacher(lastTeacher);
      } else if (e.key === 'Escape') {
        hideDropdown();
      }
    });

    function updateHighlight(items) {
      items.forEach((it, i) => {
        if (i === highlightedIdx) {
          it.classList.add('highlighted');
          it.scrollIntoView({ block: 'nearest' });
        } else {
          it.classList.remove('highlighted');
        }
      });
    }

    tagsContainer.addEventListener('click', (e) => {
      if (e.target !== searchInput) {
        searchInput.focus();
      }
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (!container.contains(e.target)) {
        hideDropdown();
      }
    });

    // Initial render
    renderTags();
    syncHiddenInput();
  }

  function initAllTeacherMultiSelects() {
    const containers = document.querySelectorAll('.teacher-multi-select');
    containers.forEach(c => {
      initTeacherMultiSelect(c);
    });
  }

  // Expose globally
  window.initTeacherMultiSelect = initTeacherMultiSelect;
  window.initAllTeacherMultiSelects = initAllTeacherMultiSelects;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllTeacherMultiSelects);
  } else {
    initAllTeacherMultiSelects();
  }
})();
