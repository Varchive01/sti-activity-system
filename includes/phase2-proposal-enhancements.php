<!-- ======================================================================= -->
<!-- PHASE 2 CAPSTONE REVISIONS: SHARED PROPOSAL ENHANCEMENTS & MODALS       -->
<!-- ======================================================================= -->

<!-- Digital Invitation Card Preview Modal (for "Create Invitation" mode) -->
<div id="digitalInvitationPreviewModal" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.78); z-index:9999; align-items:center; justify-content:center; padding:20px;" onclick="if(event.target===this)closeDigitalInvitationPreview()">
  <div style="background:var(--bg-card, #ffffff); border-radius:16px; max-width:680px; width:100%; max-height:90vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 25px 50px -12px rgba(0,0,0,0.45); border:1px solid var(--border, #e2e8f0);">
    <div style="padding:14px 20px; background:#003876; color:#ffffff; display:flex; justify-content:space-between; align-items:center;">
      <strong style="font-size:0.96rem; display:flex; align-items:center; gap:8px;">✉️ Official Event Invitation &amp; Program Preview</strong>
      <button type="button" onclick="closeDigitalInvitationPreview()" style="background:none; border:none; color:#ffffff; font-size:1.2rem; cursor:pointer;">✕</button>
    </div>
    <div id="digitalInvitationPreviewBody" style="padding:24px; overflow-y:auto; background:linear-gradient(180deg, #f8fafc 0%, #ffffff 100%); color:#0f172a;">
      <!-- Dynamically populated -->
    </div>
  </div>
</div>

<!-- Bulk Add Sections Modal (Solves multiple subjects / sections entry) -->
<div id="bulkAddSectionsModal" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.75); z-index:9999; align-items:center; justify-content:center; padding:16px;" onclick="if(event.target===this)closeBulkAddSectionsModal()">
  <div style="background:var(--bg-card, #ffffff); border-radius:14px; max-width:620px; width:100%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); overflow:hidden; border:1px solid var(--border, #e2e8f0);">
    <div style="padding:14px 18px; background:#003876; color:#ffffff; display:flex; justify-content:space-between; align-items:center;">
      <strong style="font-size:0.96rem; display:flex; align-items:center; gap:8px;">⚡ Quick Bulk Add Sections / Subjects</strong>
      <button type="button" onclick="closeBulkAddSectionsModal()" style="background:none; border:none; color:#ffffff; font-size:1.2rem; cursor:pointer;">✕</button>
    </div>
    <div style="padding:18px 20px; color:#1e293b;">
      <p style="font-size:0.875rem; color:#64748b; margin:0 0 14px 0; line-height:1.45;">
        Select an academic cohort preset or enter your sections below.
      </p>

      <!-- Option 1: Quick Presets -->
      <div style="margin-bottom:14px;">
        <label for="bulkPresetSelect" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:6px;">Option 1: Quick Academic Group Preset</label>
        <select id="bulkPresetSelect" class="form-control" onchange="applyBulkPreset(this.value)" style="font-size:0.85rem; padding:8px 12px; border-radius:6px; border:1px solid #cbd5e1; width:100%;">
          <option value="">-- Choose a standard academic cohort preset --</option>
          <optgroup label="🎓 Tertiary / College Programs (Sections: 101, 201, 301, 401, 501, 601, 701, 801)">
            <option value="BSIT_ALL">BS Information Technology (BSIT: 101, 201, 301, 401, 501, 601, 701, 801)</option>
            <option value="BSCS_ALL">BS Computer Science (BSCS: 101, 201, 301, 401, 501, 601, 701, 801)</option>
            <option value="BSTM_ALL">BS Tourism Management (BSTM: 101, 201, 301, 401, 501, 601, 701, 801)</option>
            <option value="HRS_ALL">Hospitality &amp; Restaurant Services (HRS: 101, 201, 301, 401, 501, 601, 701, 801)</option>
            <option value="COLLEGE_ALL">All College Programs Combined (BSIT, BSCS, BSTM, HRS)</option>
          </optgroup>
          <optgroup label="🏫 Senior High School Programs (Grade 11 &amp; Grade 12)">
            <option value="SHS_ALL">All SHS Strands Combined (HTCUA, HTTO, ASSH, B&amp;E, ICT-SCPT)</option>
            <option value="SHS_ICT">ICT Support &amp; Computer Programming Technologies (ICT-SCPT)</option>
            <option value="SHS_HTCUA">Hospitality &amp; Tourism - Culinary Arts (HTCUA)</option>
            <option value="SHS_HTTO">Hospitality &amp; Tourism - Tourism Operations (HTTO)</option>
            <option value="SHS_ASSH">Arts, Social Sciences, and Humanities (ASSH)</option>
            <option value="SHS_BE">Business and Entrepreneurship (B&amp;E)</option>
          </optgroup>
        </select>
      </div>

      <!-- Option 2: Custom Paste Textarea -->
      <div style="margin-bottom:14px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; flex-wrap:wrap; gap:4px;">
          <label for="bulkPasteInput" style="font-size:0.8rem; font-weight:700; color:#475569; margin:0;">Option 2: Paste Multiple Sections / Subjects</label>
          <span style="font-size:0.75rem; color:#64748b;">(one per line or with comma: Section, Count)</span>
        </div>
        <textarea id="bulkPasteInput" rows="5" class="form-control" placeholder="e.g.&#10;BSIT 301, 35&#10;BSIT 302, 40&#10;BSIT 303, 38&#10;or just:&#10;BSIT 101&#10;BSIT 102&#10;BSIT 103" style="font-size:0.85rem; font-family:monospace; width:100%;"></textarea>
      </div>

      <!-- Default Count Setting -->
      <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:16px; background:#f8fafc; padding:10px 14px; border-radius:6px; border:1px solid #e2e8f0; flex-wrap:wrap;">
        <label for="bulkDefaultCount" style="font-size:0.8rem; font-weight:600; color:#475569; margin:0; flex:1; min-width:200px;">
          Default Participants per Section (if count omitted):
        </label>
        <input type="number" id="bulkDefaultCount" value="35" min="1" max="500" style="width:80px; padding:6px 8px; border:1px solid #cbd5e1; border-radius:6px; text-align:center; font-weight:700; font-size:0.9rem;">
      </div>

      <!-- Action Buttons -->
      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeBulkAddSectionsModal()" style="font-size:0.85rem;">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="processBulkAddSections()" style="font-size:0.85rem; background:#003876; border-color:#003876;">+ Insert All Sections</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Interactive Google Calendar-Style Event Schedule & Faculty Task Modal ── -->
<div id="modalScheduleEventEditor" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.75); z-index:9999; align-items:center; justify-content:center; padding:16px;" onclick="if(event.target===this)closeScheduleEventDialog()">
  <div style="background:var(--bg-card, #ffffff); border-radius:14px; max-width:560px; width:100%; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); overflow:hidden; border:1px solid var(--border, #e2e8f0); display:flex; flex-direction:column;">
    <div style="padding:14px 18px; background:#003876; color:#ffffff; display:flex; justify-content:space-between; align-items:center;">
      <strong id="modalScheduleTitle" style="font-size:0.96rem; display:flex; align-items:center; gap:8px;">📅 Schedule Item Editor</strong>
      <button type="button" onclick="closeScheduleEventDialog()" style="background:none; border:none; color:#ffffff; font-size:1.2rem; cursor:pointer;">✕</button>
    </div>
    
    <div style="padding:18px 20px; color:#1e293b; display:flex; flex-direction:column; gap:12px;">
      <!-- Type Switcher -->
      <div style="display:flex; background:#f1f5f9; padding:4px; border-radius:8px; gap:4px; border:1px solid #e2e8f0;">
        <button type="button" id="btnSchedModalTypeMilestone" class="btn btn-sm btn-primary" onclick="setSchedModalType('milestone')" style="flex:1; border-radius:6px; font-size:0.8rem; font-weight:700; padding:6px 10px;">📌 Event Schedule</button>
        <button type="button" id="btnSchedModalTypeTask" class="btn btn-sm btn-outline" onclick="setSchedModalType('task')" style="flex:1; border-radius:6px; font-size:0.8rem; font-weight:700; padding:6px 10px; border:none;">📋 Faculty Task / Deliverable</button>
      </div>

      <input type="hidden" id="schedModalTargetType" value="milestone">
      <input type="hidden" id="schedModalRowIndex" value="-1">
      
      <!-- EVENT SCHEDULE FIELDS -->
      <div id="schedModalMilestoneFields" style="display:flex; flex-direction:column; gap:12px;">
        <div>
          <label for="schedModalDate" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Activity Date <span style="color:#ef4444;">*</span></label>
          <input type="date" id="schedModalDate" class="form-control" style="width:100%; font-size:0.875rem;">
        </div>

        <div>
          <label for="schedModalCategory" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Event Stage / Category <span style="color:#ef4444;">*</span></label>
          <select id="schedModalCategory" class="form-control" style="width:100%; font-size:0.875rem;">
            <option value="main_event">🎯 Main Event (Plenary, Tournament, Official Program)</option>
            <option value="preparation">🛠️ Event Preparation (Ingress, Rehearsal, Dry-Run, Setup)</option>
            <option value="sub_event">🎪 Sub-Event (Breakout, Side Match, Awarding, Booths)</option>
          </select>
        </div>

        <div>
          <label for="schedModalEvent" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Event / Activity Name <span style="color:#ef4444;">*</span></label>
          <input type="text" id="schedModalEvent" class="form-control" placeholder="e.g. Opening Remarks, Technical Workshop, Awarding" style="width:100%; font-size:0.875rem;">
        </div>

        <div>
          <label for="schedModalVenue" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Venue / Room <span style="color:#ef4444;">*</span></label>
          <input type="text" id="schedModalVenue" class="form-control" placeholder="e.g. Multi-Purpose Hall / Computer Lab 1" style="width:100%; font-size:0.875rem;">
        </div>

        <div>
          <label for="schedModalOrganizer" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Responsible Organizer <span style="color:#ef4444;">*</span></label>
          <select id="schedModalOrganizer" class="form-control" style="width:100%; font-size:0.875rem;">
            <option value="">-- Select responsible organizer --</option>
          </select>
        </div>
      </div>

      <!-- FACULTY TASK FIELDS -->
      <div id="schedModalTaskFields" style="display:none; flex-direction:column; gap:12px;">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <div>
            <label for="schedModalTaskStartDate" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Start Date</label>
            <input type="date" id="schedModalTaskStartDate" class="form-control" style="width:100%; font-size:0.875rem;">
          </div>
          <div>
            <label for="schedModalTaskDueDate" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Target Deadline <span style="color:#ef4444;">*</span></label>
            <input type="date" id="schedModalTaskDueDate" class="form-control" style="width:100%; font-size:0.875rem;">
          </div>
        </div>

        <div>
          <label for="schedModalTaskUserId" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Assigned Faculty Member <span style="color:#ef4444;">*</span></label>
          <select id="schedModalTaskUserId" class="form-control" onchange="syncSchedModalTaskName(this)" style="width:100%; font-size:0.875rem;">
            <option value="">-- Unassigned / External --</option>
          </select>
          <input type="text" id="schedModalTaskNameField" class="form-control" style="margin-top:6px; font-size:0.82rem; display:none;" placeholder="External name (if unassigned)">
        </div>

        <div>
          <label for="schedModalTaskName" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Assigned Task / Deliverable <span style="color:#ef4444;">*</span></label>
          <input type="text" id="schedModalTaskName" class="form-control" placeholder="e.g. Stage Sound & Lighting Setup, Registration Desk Management" style="width:100%; font-size:0.875rem;">
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
          <div>
            <label for="schedModalTaskRole" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Role in Event <span style="color:#ef4444;">*</span></label>
            <input type="text" id="schedModalTaskRole" class="form-control" placeholder="e.g. Technical Lead" style="width:100%; font-size:0.85rem;">
          </div>
          <div>
            <label for="schedModalTaskContribution" style="display:block; font-size:0.8rem; font-weight:700; color:#475569; margin-bottom:5px;">Contribution Description <span style="color:#ef4444;">*</span></label>
            <input type="text" id="schedModalTaskContribution" class="form-control" placeholder="e.g. Audio & Mic Operations" style="width:100%; font-size:0.85rem;">
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; border-top:1px solid #f1f5f9; padding-top:14px;">
        <button type="button" id="btnSchedModalDelete" class="btn btn-outline btn-sm" onclick="deleteScheduleEventFromDialog()" style="color:#ef4444; border-color:#fca5a5; display:none;">🗑️ Remove</button>
        <div style="display:flex; gap:8px; margin-left:auto;">
          <button type="button" class="btn btn-outline btn-sm" onclick="closeScheduleEventDialog()">Cancel</button>
          <button type="button" class="btn btn-primary btn-sm" onclick="saveScheduleEventFromDialog()" style="background:#003876; border-color:#003876;">💾 Save Item</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ── Bulk Add Committees / Roles Modal (Single Practical Approach) ────────────── -->
<div id="bulkAddCommitteesModal" style="display:none; position:fixed; inset:0; background:rgba(15, 23, 42, 0.75); z-index:9999; align-items:center; justify-content:center; padding:16px;" onclick="if(event.target===this)closeBulkAddCommitteesModal()">
  <div style="background:var(--bg-card, #ffffff); border-radius:14px; max-width:660px; width:100%; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 25px 50px -12px rgba(0,0,0,0.35); overflow:hidden; border:1px solid var(--border, #e2e8f0);">
    <!-- Modal Header -->
    <div style="padding:14px 18px; background:#003876; color:#ffffff; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
      <strong style="font-size:0.96rem; display:flex; align-items:center; gap:8px;">⚡ Select Event Committees &amp; Roles</strong>
      <button type="button" onclick="closeBulkAddCommitteesModal()" style="background:none; border:none; color:#ffffff; font-size:1.2rem; cursor:pointer;">✕</button>
    </div>

    <!-- Subheader & Quick Filter -->
    <div style="padding:14px 18px 10px 18px; border-bottom:1px solid #e2e8f0; background:#f8fafc; flex-shrink:0;">
      <p style="font-size:0.84rem; color:#64748b; margin:0 0 10px 0; line-height:1.4;">
        Select the committees and working roles needed for this event and specify team headcount.
      </p>
      <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
        <input type="text" id="bulkRoleSearch" placeholder="Search roles..." oninput="filterBulkRoles(this.value)" style="padding:6px 12px; font-size:0.84rem; border:1px solid #cbd5e1; border-radius:6px; flex:1; min-width:180px;">
        <div style="display:flex; gap:8px;">
          <button type="button" class="btn btn-outline btn-sm" onclick="toggleAllBulkRoles(true)" style="font-size:0.78rem; padding:4px 10px;">Select All</button>
          <button type="button" class="btn btn-outline btn-sm" onclick="toggleAllBulkRoles(false)" style="font-size:0.78rem; padding:4px 10px;">Clear</button>
        </div>
      </div>
    </div>

    <!-- Scrollable Roles Checklist Table -->
    <div style="padding:0; overflow-y:auto; flex:1; max-height:400px;">
      <table style="width:100%; border-collapse:collapse; font-size:0.84rem;">
        <thead style="position:sticky; top:0; background:#f1f5f9; z-index:2; border-bottom:1px solid #cbd5e1;">
          <tr>
            <th style="padding:8px 12px; text-align:center; width:44px;"><input type="checkbox" id="bulkSelectAllHeader" onchange="toggleAllBulkRoles(this.checked)"></th>
            <th style="padding:8px 12px; text-align:left;">Role / Committee</th>
            <th style="padding:8px 12px; text-align:left; width:130px;">Type</th>
            <th style="padding:8px 12px; text-align:center; width:120px;">Members Supervised</th>
          </tr>
        </thead>
        <tbody id="bulkRolesTableBody">
          <!-- Populated by renderBulkRolesTable() -->
        </tbody>
      </table>
    </div>

    <!-- Modal Footer -->
    <div style="padding:12px 18px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
      <div style="font-size:0.82rem; color:#475569;">
        Selected: <strong id="bulkSelectedCount" style="color:#0284c7;">0</strong> roles &nbsp;|&nbsp; Total Headcount: <strong id="bulkTotalMembersCount" style="color:#0284c7;">0</strong>
      </div>
      <div style="display:flex; gap:10px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closeBulkAddCommitteesModal()" style="font-size:0.85rem;">Cancel</button>
        <button type="button" class="btn btn-primary btn-sm" onclick="processBulkAddCommittees()" style="font-size:0.85rem; background:#003876; border-color:#003876; font-weight:600;">+ Insert Selected Committees</button>
      </div>
    </div>
  </div>
</div>

<script>
// ── Universal Safe HTML Escaper ───────────────────────────────────────────
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
window.escapeHtml = escapeHtml;

// ── 1. AI-Assisted Objectives & Rationale Generator (Removes Redundancy) ────────────────
async function generateRationaleWithAi() {
  if (typeof forceRegenerateObjectives === 'function') {
    return forceRegenerateObjectives();
  }
  const titleEl = document.getElementById('f1_title');
  const themeEl = document.getElementById('f1_theme');
  const sourceEl = document.getElementById('f1_source');
  const partEl = document.getElementById('f1_target_participants');
  const subjEl = document.getElementById('f2_involved_subjects');
  const ratEl = document.getElementById('f2_rationale');
  const genEl = document.getElementById('f2_general_objectives');
  const specEl = document.getElementById('f2_specific_objectives');
  const btn = document.getElementById('btnGenerateAiRationale');

  const currentTitle = (titleEl?.value || '').trim();
  if (!currentTitle) {
    alert('Please enter an Event Name / Title in Step 1 first so AI can generate a tailored rationale.');
    return;
  }

  const origText = btn ? btn.innerHTML : '✨ Generate with AI';
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '⏳ Generating AI Rationale...';
  }

  try {
    const res = await fetch('<?= defined("BASE_URL") ? BASE_URL : "" ?>/api/generate-objectives.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({
        title: currentTitle,
        theme: (themeEl?.value || '').trim(),
        source: (sourceEl?.value || '').trim(),
        target_participants: (partEl?.value || '').trim(),
        involved_subjects: (subjEl?.value || '').trim(),
        rationale: (ratEl?.value || '').trim()
      })
    });
    const data = await res.json();
    if (data && data.success) {
      if (ratEl && data.rationale) {
        ratEl.value = data.rationale;
        ratEl.classList.remove('is-invalid');
        const errRat = document.getElementById('e_rationale');
        if (errRat) errRat.style.display = 'none';
        ratEl.dispatchEvent(new Event('input', { bubbles: true }));
        ratEl.dispatchEvent(new Event('change', { bubbles: true }));
        ratEl.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
        ratEl.style.borderColor = '#22c55e';
        ratEl.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.2)';
        setTimeout(() => {
          ratEl.style.borderColor = '';
          ratEl.style.boxShadow = '';
        }, 1200);
      }
      if (genEl && !genEl.value.trim() && data.general_objective) {
        genEl.value = data.general_objective;
        genEl.dispatchEvent(new Event('input', { bubbles: true }));
      }
      if (specEl && !specEl.value.trim() && data.specific_objectives) {
        specEl.value = Array.isArray(data.specific_objectives) ? data.specific_objectives.join('\n') : data.specific_objectives;
        specEl.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }
  } catch (err) {
    console.warn('AI Rationale generation error:', err);
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = origText;
    }
  }
}

// ── 2. Responsible Organizer Dropdown (Synced from Encoded Individuals) ────
function cleanTeacherDisplayName(raw) {
  if (!raw || typeof raw !== 'string') return '';
  let s = raw;
  // Remove titles like Mr., Ms., Mrs., Dr., Engr., Prof.
  s = s.replace(/^(Mr|Ms|Mrs|Dr|Engr|Prof)\.?\s+/i, '');
  // Remove academic and license suffixes: LPT, RN, MBA, MAEd, PE, CPA, MIT, MSCS, PhD, EdD
  s = s.replace(/,\s*(LPT|RN|MBA|MAEd|PE|CPA|MIT|MSCS|PhD|EdD)\b/gi, '');
  s = s.replace(/\b(LPT|RN|MBA|MAEd|PE)\b/g, '');
  // Clean trailing punctuation and multiple spaces
  s = s.replace(/[,\.]\s*$/, '').replace(/\s+/g, ' ').trim();
  return s;
}

function isDeanOrExcludedTeacher(name) {
  if (!name) return true;
  const lower = name.toLowerCase().replace(/[^a-z]/g, '');
  if (lower.includes('frederic') || lower.includes('yulo') || lower.includes('dean')) {
    return true;
  }
  const genericExcluded = ['van', 'rain', 'hilary', 'shaira', 'placeholder', 'dummy', 'unrelated', 'bademail', 'test'];
  return genericExcluded.includes(lower);
}

function getEncodedIndividualsList() {
  const namesSet = new Set();
  const addName = (raw) => {
    if (!raw || typeof raw !== 'string') return;
    raw.split(',').forEach(part => {
      const clean = cleanTeacherDisplayName(part);
      if (clean.length >= 2 && !clean.startsWith('--') && !isDeanOrExcludedTeacher(clean)) {
        namesSet.add(clean);
      }
    });
  };

  // 1. First prioritize official STI College Marikina faculty roster
  if (Array.isArray(window.ELIGIBLE_FACULTY_ROSTER)) {
    window.ELIGIBLE_FACULTY_ROSTER.forEach(f => {
      if (f && f.name) addName(f.name);
    });
  }

  // 2. Current logged-in proponent & collaborators
  if (window.PROPOSAL_CURRENT_USER_NAME) {
    addName(window.PROPOSAL_CURRENT_USER_NAME);
  }
  document.querySelectorAll('#selected-collaborators-chips .collab-chip-name, #collaborators-table-body td:first-child').forEach(el => {
    addName(el.textContent || '');
  });

  // 3. Step 1 Subject Teachers
  document.querySelectorAll('#participant-sections-body input[name^="part_teacher"], #participant-sections-body .teacher-chip-text').forEach(el => {
    addName(el.value || el.textContent || '');
  });

  // 4. Step 5 Manpower (Person in Charge)
  document.querySelectorAll('#manpower-body input[name="mp_person[]"]').forEach(inp => {
    addName(inp.value || '');
  });

  // 5. Step 8 Faculty Tasks
  document.querySelectorAll('#faculty-tasks-body select[name="ft_user_id[]"]').forEach(sel => {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
      addName(opt.getAttribute('data-name') || opt.textContent || '');
    }
  });
  document.querySelectorAll('#faculty-tasks-body input[name="ft_name[]"]').forEach(inp => {
    addName(inp.value || '');
  });

  // 6. Existing schedule organizer or program PIC values
  document.querySelectorAll('select.sched-organizer-select, input[name="sched_organizer[]"], input[name="prog_pic[]"]').forEach(el => {
    const v = (el.value || el.getAttribute('data-initial-value') || '').trim();
    if (v) addName(v);
  });

  // 7. Fallback official faculty options if none encoded yet
  document.querySelectorAll('#faculty-tasks-body select[name="ft_user_id[]"] option, select.ft-user-select option').forEach(opt => {
    if (opt.value) addName(opt.getAttribute('data-name') || opt.textContent || '');
  });

  return Array.from(namesSet).sort((a, b) => a.localeCompare(b));
}
window.getEncodedIndividualsList = getEncodedIndividualsList;
window.getEncodedIndividuals = getEncodedIndividualsList;

function refreshEncodedOrganizerDropdowns() {
  const individuals = getEncodedIndividualsList();
  document.querySelectorAll('select.sched-organizer-select').forEach(sel => {
    const currentVal = (sel.value || sel.getAttribute('data-initial-value') || '').trim();
    sel.innerHTML = '';

    const defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.textContent = 'Select encoded individual...';
    sel.appendChild(defaultOpt);

    let matched = false;
    individuals.forEach(personName => {
      const opt = document.createElement('option');
      opt.value = personName;
      opt.textContent = personName;
      if (currentVal && personName.toLowerCase() === currentVal.toLowerCase()) {
        opt.selected = true;
        matched = true;
      }
      sel.appendChild(opt);
    });

    if (currentVal && !matched) {
      const customOpt = document.createElement('option');
      customOpt.value = currentVal;
      customOpt.textContent = currentVal;
      customOpt.selected = true;
      sel.appendChild(customOpt);
    } else if (!currentVal && individuals.length > 0) {
      // Pre-select the first encoded individual if empty for convenience
      sel.selectedIndex = 1;
    }
  });
}

// ── 3. Interactive Activity Timeline & Faculty Tasks Calendar Format (Step 6) ─
let scheduleCalendarMonth = null;
let scheduleCalendarYear = null;
let scheduleCalendarFilter = 'all';

function setScheduleViewMode(mode) {
  const calWrap = document.getElementById('schedule-calendar-view-wrap');
  const agendaWrap = document.getElementById('schedule-agenda-wrap');
  const tblWrap = document.getElementById('schedule-table-wrap');
  const btnCal = document.getElementById('btnSchedViewCalendar');
  const btnTbl = document.getElementById('btnSchedViewTable');

  if (mode === 'table') {
    if (calWrap) calWrap.style.display = 'none';
    if (agendaWrap) agendaWrap.style.display = 'none';
    if (tblWrap) tblWrap.style.display = 'block';
    if (btnTbl) { btnTbl.classList.add('btn-primary'); btnTbl.classList.remove('btn-outline'); }
    if (btnCal) { btnCal.classList.add('btn-outline'); btnCal.classList.remove('btn-primary'); }
  } else {
    if (calWrap) calWrap.style.display = 'block';
    if (agendaWrap) agendaWrap.style.display = 'block';
    if (tblWrap) tblWrap.style.display = 'none';
    if (btnCal) { btnCal.classList.add('btn-primary'); btnCal.classList.remove('btn-outline'); }
    if (btnTbl) { btnTbl.classList.add('btn-outline'); btnTbl.classList.remove('btn-primary'); }
    renderScheduleTimelineCalendar();
  }
}

function setScheduleCalendarFilter(filter) {
  scheduleCalendarFilter = filter;
  const btnAll = document.getElementById('btnSchedFilterAll');
  const btnPrep = document.getElementById('btnSchedFilterPrep');
  const btnMain = document.getElementById('btnSchedFilterMain');
  const btnSub = document.getElementById('btnSchedFilterSub');
  const btnTk = document.getElementById('btnSchedFilterTasks');
  const btnMs = document.getElementById('btnSchedFilterMilestones');

  [btnAll, btnPrep, btnMain, btnSub, btnTk, btnMs].forEach(btn => {
    if (btn) {
      btn.classList.remove('btn-primary');
      btn.classList.add('btn-outline');
      btn.style.background = '';
      btn.style.borderColor = '';
      btn.style.color = '';
    }
  });

  if (filter === 'prep' && btnPrep) {
    btnPrep.classList.add('btn-primary');
    btnPrep.classList.remove('btn-outline');
    btnPrep.style.background = '#fef3c7';
    btnPrep.style.borderColor = '#f59e0b';
    btnPrep.style.color = '#92400e';
  } else if (filter === 'main' && btnMain) {
    btnMain.classList.add('btn-primary');
    btnMain.classList.remove('btn-outline');
    btnMain.style.background = '#eff6ff';
    btnMain.style.borderColor = '#2563eb';
    btnMain.style.color = '#1e40af';
  } else if (filter === 'sub' && btnSub) {
    btnSub.classList.add('btn-primary');
    btnSub.classList.remove('btn-outline');
    btnSub.style.background = '#f5f3ff';
    btnSub.style.borderColor = '#8b5cf6';
    btnSub.style.color = '#5b21b6';
  } else if (filter === 'tasks' && btnTk) {
    btnTk.classList.add('btn-primary');
    btnTk.classList.remove('btn-outline');
    btnTk.style.background = '#ecfdf5';
    btnTk.style.borderColor = '#10b981';
    btnTk.style.color = '#065f46';
  } else if (filter === 'milestones' && btnMs) {
    btnMs.classList.add('btn-primary');
    btnMs.classList.remove('btn-outline');
  } else if (btnAll) {
    btnAll.classList.add('btn-primary');
    btnAll.classList.remove('btn-outline');
  }

  renderScheduleTimelineCalendar();
}

function goToTodayCalendar() {
  const today = new Date();
  scheduleCalendarMonth = today.getMonth();
  scheduleCalendarYear = today.getFullYear();
  renderScheduleTimelineCalendar();
}
window.goToTodayCalendar = goToTodayCalendar;

function setSchedModalType(type) {
  const typeInp = document.getElementById('schedModalTargetType');
  const btnMs = document.getElementById('btnSchedModalTypeMilestone');
  const btnTk = document.getElementById('btnSchedModalTypeTask');
  const msFields = document.getElementById('schedModalMilestoneFields');
  const tkFields = document.getElementById('schedModalTaskFields');
  const titleEl = document.getElementById('modalScheduleTitle');

  if (typeInp) typeInp.value = type;

  if (type === 'task') {
    if (msFields) msFields.style.display = 'none';
    if (tkFields) tkFields.style.display = 'flex';
    if (btnTk) { btnTk.classList.add('btn-primary'); btnTk.classList.remove('btn-outline'); btnTk.style.background = '#065f46'; btnTk.style.borderColor = '#065f46'; btnTk.style.color = '#fff'; }
    if (btnMs) { btnMs.classList.add('btn-outline'); btnMs.classList.remove('btn-primary'); btnMs.style.background = ''; btnMs.style.borderColor = ''; btnMs.style.color = ''; }
    if (titleEl) titleEl.innerHTML = '📋 Schedule Faculty Task Deliverable';
    populateSchedModalFacultyOptions();
  } else {
    if (msFields) msFields.style.display = 'flex';
    if (tkFields) tkFields.style.display = 'none';
    if (btnMs) { btnMs.classList.add('btn-primary'); btnMs.classList.remove('btn-outline'); btnMs.style.background = '#003876'; btnMs.style.borderColor = '#003876'; btnMs.style.color = '#fff'; }
    if (btnTk) { btnTk.classList.add('btn-outline'); btnTk.classList.remove('btn-primary'); btnTk.style.background = ''; btnTk.style.borderColor = ''; btnTk.style.color = ''; }
    if (titleEl) titleEl.innerHTML = '📅 Schedule Event / Activity';
    populateScheduleModalOrganizers();
  }
}

function populateSchedModalFacultyOptions() {
  const facSel = document.getElementById('schedModalTaskUserId');
  if (!facSel) return;
  const currentVal = facSel.value;
  const sampleSel = document.querySelector('#faculty-tasks-body select.ft-user-select');
  if (sampleSel && sampleSel.innerHTML.trim()) {
    facSel.innerHTML = sampleSel.innerHTML;
  }
  if (currentVal) {
    facSel.value = currentVal;
  }
}

function syncSchedModalTaskName(sel) {
  const nameField = document.getElementById('schedModalTaskNameField');
  if (!nameField) return;
  const opt = sel.options[sel.selectedIndex];
  if (opt && opt.dataset && opt.dataset.name) {
    nameField.value = opt.dataset.name;
    nameField.style.display = 'none';
  } else if (!sel.value) {
    nameField.style.display = 'block';
  } else {
    nameField.style.display = 'none';
  }
}

function shiftScheduleCalendarMonth(delta) {
  if (scheduleCalendarMonth === null || scheduleCalendarYear === null) {
    initScheduleCalendarDate();
  }
  scheduleCalendarMonth += delta;
  if (scheduleCalendarMonth < 0) {
    scheduleCalendarMonth = 11;
    scheduleCalendarYear--;
  } else if (scheduleCalendarMonth > 11) {
    scheduleCalendarMonth = 0;
    scheduleCalendarYear++;
  }
  renderScheduleTimelineCalendar();
}

function initScheduleCalendarDate() {
  const evDateVal = document.getElementById('f1_event_date')?.value || '';
  const firstSchedDate = document.querySelector('#schedule-body input[name="sched_date[]"]')?.value || '';
  const refStr = evDateVal || firstSchedDate;
  const refDate = refStr ? new Date(refStr + 'T00:00:00') : new Date();
  if (!isNaN(refDate.getTime())) {
    scheduleCalendarMonth = refDate.getMonth();
    scheduleCalendarYear = refDate.getFullYear();
  } else {
    const now = new Date();
    scheduleCalendarMonth = now.getMonth();
    scheduleCalendarYear = now.getFullYear();
  }
}

function formatDatePretty(isoStr) {
  if (!isoStr) return '';
  const parts = isoStr.split('-');
  if (parts.length !== 3) return isoStr;
  const d = new Date(parts[0], parseInt(parts[1], 10) - 1, parts[2]);
  if (isNaN(d.getTime())) return isoStr;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function populateScheduleModalOrganizers() {
  const orgSel = document.getElementById('schedModalOrganizer');
  if (!orgSel) return;
  const currentVal = (orgSel.value || '').trim();
  const individuals = (typeof getEncodedIndividualsList === 'function') 
    ? getEncodedIndividualsList() 
    : ((typeof getEncodedIndividuals === 'function') ? getEncodedIndividuals() : []);
  
  let html = '<option value="">-- Select responsible organizer --</option>';
  individuals.forEach(name => {
    const safe = name.replace(/</g, '&lt;').replace(/>/g, '&gt;');
    html += `<option value="${safe}">${safe}</option>`;
  });
  orgSel.innerHTML = html;
  if (currentVal && individuals.includes(currentVal)) {
    orgSel.value = currentVal;
  } else if (window.PROPOSAL_CURRENT_USER_NAME && individuals.includes(window.PROPOSAL_CURRENT_USER_NAME)) {
    orgSel.value = window.PROPOSAL_CURRENT_USER_NAME;
  } else if (individuals.length > 0) {
    orgSel.selectedIndex = 1;
  }
}
window.populateScheduleModalOrganizers = populateScheduleModalOrganizers;

function openAddScheduleEventDialog(isoDate, initialType) {
  const modal = document.getElementById('modalScheduleEventEditor');
  if (!modal) return;

  const rowIdxInp = document.getElementById('schedModalRowIndex');
  const dateInp = document.getElementById('schedModalDate');
  const taskStartDateInp = document.getElementById('schedModalTaskStartDate');
  const taskDateInp = document.getElementById('schedModalTaskDueDate');
  const evInp = document.getElementById('schedModalEvent');
  const venInp = document.getElementById('schedModalVenue');
  const taskInp = document.getElementById('schedModalTaskName');
  const roleInp = document.getElementById('schedModalTaskRole');
  const contribInp = document.getElementById('schedModalTaskContribution');
  const btnDel = document.getElementById('btnSchedModalDelete');

  if (rowIdxInp) rowIdxInp.value = '-1';
  if (btnDel) btnDel.style.display = 'none';

  setSchedModalType(initialType === 'task' ? 'task' : 'milestone');
  populateScheduleModalOrganizers();

  const defaultDate = isoDate || document.getElementById('f1_event_date')?.value || '';
  if (dateInp) {
    dateInp.value = defaultDate;
    if (typeof todayManila !== 'undefined') {
      dateInp.min = todayManila;
    }
  }
  if (taskStartDateInp) {
    taskStartDateInp.value = defaultDate;
  }
  if (taskDateInp) {
    taskDateInp.value = defaultDate;
    if (typeof todayManila !== 'undefined') {
      taskDateInp.min = todayManila;
    }
  }

  if (evInp) {
    evInp.value = '';
    const propTitle = document.getElementById('f1_title')?.value || '';
    if (propTitle) {
      evInp.placeholder = `e.g. ${propTitle}`;
    }
  }

  const catSel = document.getElementById('schedModalCategory');
  if (catSel) catSel.value = 'main_event';

  if (venInp) {
    venInp.value = document.getElementById('f1_venue')?.value || '';
  }
  if (taskInp) taskInp.value = '';
  if (roleInp) roleInp.value = '';
  if (contribInp) contribInp.value = '';

  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  const targetFocus = initialType === 'task' ? taskInp : evInp;
  if (targetFocus) setTimeout(() => targetFocus.focus(), 80);
}

function editScheduleEventFromCalendar(rowIndex) {
  const rows = document.querySelectorAll('#schedule-body tr');
  if (rowIndex < 0 || rowIndex >= rows.length) return;
  const tr = rows[rowIndex];

  const modal = document.getElementById('modalScheduleEventEditor');
  if (!modal) return;

  setSchedModalType('milestone');

  const rowIdxInp = document.getElementById('schedModalRowIndex');
  const dateInp = document.getElementById('schedModalDate');
  const evInp = document.getElementById('schedModalEvent');
  const venInp = document.getElementById('schedModalVenue');
  const orgSel = document.getElementById('schedModalOrganizer');
  const titleEl = document.getElementById('modalScheduleTitle');
  const btnDel = document.getElementById('btnSchedModalDelete');

  if (rowIdxInp) rowIdxInp.value = rowIndex;
  if (titleEl) titleEl.innerHTML = '✏️ Edit Event Schedule';
  if (btnDel) { btnDel.style.display = 'inline-flex'; btnDel.textContent = '🗑️ Remove Item'; }

  populateScheduleModalOrganizers();

  const dVal = tr.querySelector('input[name="sched_date[]"]')?.value || '';
  const evVal = tr.querySelector('input[name="sched_event[]"]')?.value || '';
  const venVal = tr.querySelector('input[name="sched_venue[]"]')?.value || '';
  const orgVal = tr.querySelector('[name="sched_organizer[]"]')?.value || '';
  const catVal = tr.querySelector('[name="sched_type[]"]')?.value || 'main_event';

  if (dateInp) dateInp.value = dVal;
  if (evInp) evInp.value = evVal;
  if (venInp) venInp.value = venVal;
  if (orgSel && orgVal) {
    let exists = false;
    for (let opt of orgSel.options) {
      if (opt.value === orgVal) { exists = true; break; }
    }
    if (!exists) {
      const newOpt = new Option(orgVal, orgVal, true, true);
      orgSel.add(newOpt);
    }
    orgSel.value = orgVal;
  }
  const catSel = document.getElementById('schedModalCategory');
  if (catSel) catSel.value = catVal;

  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  if (evInp) setTimeout(() => evInp.focus(), 80);
}

function editFacultyTaskFromCalendar(rowIndex) {
  const rows = document.querySelectorAll('#faculty-tasks-body tr');
  if (rowIndex < 0 || rowIndex >= rows.length) return;
  const tr = rows[rowIndex];

  const modal = document.getElementById('modalScheduleEventEditor');
  if (!modal) return;

  setSchedModalType('task');

  const rowIdxInp = document.getElementById('schedModalRowIndex');
  const sDateInp = document.getElementById('schedModalTaskStartDate');
  const dateInp = document.getElementById('schedModalTaskDueDate');
  const facSel = document.getElementById('schedModalTaskUserId');
  const nameInp = document.getElementById('schedModalTaskNameField');
  const taskInp = document.getElementById('schedModalTaskName');
  const roleInp = document.getElementById('schedModalTaskRole');
  const contribInp = document.getElementById('schedModalTaskContribution');
  const titleEl = document.getElementById('modalScheduleTitle');
  const btnDel = document.getElementById('btnSchedModalDelete');

  if (rowIdxInp) rowIdxInp.value = rowIndex;
  if (titleEl) titleEl.innerHTML = '✏️ Edit Faculty Task Deliverable';
  if (btnDel) { btnDel.style.display = 'inline-flex'; btnDel.textContent = '🗑️ Remove Task'; }

  populateSchedModalFacultyOptions();

  const sVal = tr.querySelector('input[name="ft_start_date[]"]')?.value || '';
  const dVal = tr.querySelector('input[name="ft_due_date[]"]')?.value || '';
  const uVal = tr.querySelector('select[name="ft_user_id[]"]')?.value || '';
  const nVal = tr.querySelector('input.ft-name-field')?.value || '';
  const tVal = tr.querySelector('input[name="ft_task[]"]')?.value || '';
  const rVal = tr.querySelector('input[name="ft_role[]"]')?.value || '';
  const cVal = tr.querySelector('input[name="ft_contribution[]"]')?.value || '';

  if (sDateInp) sDateInp.value = sVal;
  if (dateInp) dateInp.value = dVal;
  if (facSel) {
    facSel.value = uVal;
    syncSchedModalTaskName(facSel);
  }
  if (nameInp) nameInp.value = nVal;
  if (taskInp) taskInp.value = tVal;
  if (roleInp) roleInp.value = rVal;
  if (contribInp) contribInp.value = cVal;

  modal.style.display = 'flex';
  document.body.style.overflow = 'hidden';
  if (taskInp) setTimeout(() => taskInp.focus(), 80);
}

function closeScheduleEventDialog() {
  const modal = document.getElementById('modalScheduleEventEditor');
  if (modal) {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

function saveScheduleEventFromDialog() {
  const typeInp = document.getElementById('schedModalTargetType');
  const targetType = typeInp ? typeInp.value : 'milestone';
  const rowIdxInp = document.getElementById('schedModalRowIndex');
  const rowIdx = parseInt(rowIdxInp?.value || '-1', 10);

  if (targetType === 'task') {
    // ── Save Faculty Task (Step 8) ──────────────────────────
    const sDateInp = document.getElementById('schedModalTaskStartDate');
    const dateInp = document.getElementById('schedModalTaskDueDate');
    const facSel = document.getElementById('schedModalTaskUserId');
    const nameInp = document.getElementById('schedModalTaskNameField');
    const taskInp = document.getElementById('schedModalTaskName');
    const roleInp = document.getElementById('schedModalTaskRole');
    const contribInp = document.getElementById('schedModalTaskContribution');

    const sVal = (sDateInp?.value || '').trim();
    const dVal = (dateInp?.value || '').trim();
    const tVal = (taskInp?.value || '').trim();
    const rVal = (roleInp?.value || '').trim();
    const cVal = (contribInp?.value || '').trim();
    const uVal = (facSel?.value || '').trim();
    const nVal = (nameInp?.value || '').trim();

    if (!dVal) {
      alert('Please choose a target deadline date for this faculty task.');
      if (dateInp) dateInp.focus();
      return;
    }
    if (sVal && dVal && sVal > dVal) {
      alert('The start date cannot be later than the target deadline.');
      if (sDateInp) sDateInp.focus();
      return;
    }
    if (!tVal) {
      alert('Please enter an assigned task or deliverable description.');
      if (taskInp) taskInp.focus();
      return;
    }

    let targetRow = null;
    const rows = document.querySelectorAll('#faculty-tasks-body tr');

    if (rowIdx >= 0 && rowIdx < rows.length) {
      targetRow = rows[rowIdx];
    } else {
      if (rows.length === 1) {
        const t = (rows[0].querySelector('input[name="ft_task[]"]')?.value || '').trim();
        const d = (rows[0].querySelector('input[name="ft_due_date[]"]')?.value || '').trim();
        if (!t && !d) targetRow = rows[0];
      }
      if (!targetRow && typeof addRow === 'function') {
        addRow('faculty-tasks');
        const rowsNow = document.querySelectorAll('#faculty-tasks-body tr');
        targetRow = rowsNow[rowsNow.length - 1];
      }
    }

    if (targetRow) {
      const sField = targetRow.querySelector('input[name="ft_start_date[]"]');
      const dField = targetRow.querySelector('input[name="ft_due_date[]"]');
      const uField = targetRow.querySelector('select[name="ft_user_id[]"]');
      const nField = targetRow.querySelector('input.ft-name-field');
      const tField = targetRow.querySelector('input[name="ft_task[]"]');
      const rField = targetRow.querySelector('input[name="ft_role[]"]');
      const cField = targetRow.querySelector('input[name="ft_contribution[]"]');

      if (sField) { sField.value = sVal; }
      if (dField) { dField.value = dVal; dField.classList.remove('is-invalid'); }
      if (uField) {
        uField.value = uVal;
        if (typeof syncFtName === 'function') syncFtName(uField);
      }
      if (nField && nVal) { nField.value = nVal; }
      if (tField) { tField.value = tVal; tField.classList.remove('is-invalid'); }
      if (rField) { rField.value = rVal; rField.classList.remove('is-invalid'); }
      if (cField) { cField.value = cVal; cField.classList.remove('is-invalid'); }
    }
  } else {
    // ── Save Milestone (Step 6) ──────────────────────────────
    const dateInp = document.getElementById('schedModalDate');
    const evInp = document.getElementById('schedModalEvent');
    const venInp = document.getElementById('schedModalVenue');
    const orgSel = document.getElementById('schedModalOrganizer');
    const catSel = document.getElementById('schedModalCategory');

    const dVal = (dateInp?.value || '').trim();
    const evVal = (evInp?.value || '').trim();
    const venVal = (venInp?.value || '').trim();
    const orgVal = (orgSel?.value || '').trim();
    const catVal = (catSel?.value || 'main_event').trim() || 'main_event';

    if (!dVal) {
      alert('Please choose a valid date for this event schedule.');
      if (dateInp) dateInp.focus();
      return;
    }
    if (!evVal) {
      alert('Please enter an event or activity name.');
      if (evInp) evInp.focus();
      return;
    }

    let targetRow = null;
    const rows = document.querySelectorAll('#schedule-body tr');

    if (rowIdx >= 0 && rowIdx < rows.length) {
      targetRow = rows[rowIdx];
    } else {
      if (rows.length === 1) {
        const d = (rows[0].querySelector('input[name="sched_date[]"]')?.value || '').trim();
        const ev = (rows[0].querySelector('input[name="sched_event[]"]')?.value || '').trim();
        if (!d && !ev) {
          targetRow = rows[0];
        }
      }
      if (!targetRow && typeof addRow === 'function') {
        addRow('schedule');
        const rowsNow = document.querySelectorAll('#schedule-body tr');
        targetRow = rowsNow[rowsNow.length - 1];
      }
    }

    if (targetRow) {
      const dField = targetRow.querySelector('input[name="sched_date[]"]');
      const evField = targetRow.querySelector('input[name="sched_event[]"]');
      const venField = targetRow.querySelector('input[name="sched_venue[]"]');
      const orgField = targetRow.querySelector('[name="sched_organizer[]"]');

      if (dField) { dField.value = dVal; dField.classList.remove('is-invalid'); }
      if (evField) { evField.value = evVal; evField.classList.remove('is-invalid'); }
      if (venField) { venField.value = venVal; venField.classList.remove('is-invalid'); }

      let typeField = targetRow.querySelector('[name="sched_type[]"]');
      if (!typeField) {
        typeField = document.createElement('input');
        typeField.type = 'hidden';
        typeField.name = 'sched_type[]';
        typeField.className = 'sched-type-field';
        targetRow.appendChild(typeField);
      }
      typeField.value = catVal;
      if (orgField) {
        let exists = false;
        for (let opt of orgField.options) {
          if (opt.value === orgVal) { exists = true; break; }
        }
        if (!exists && orgVal) {
          const newOpt = new Option(orgVal, orgVal, true, true);
          orgField.add(newOpt);
        }
        orgField.value = orgVal;
        orgField.classList.remove('is-invalid');
      }
    }
  }

  closeScheduleEventDialog();
  renderScheduleTimelineCalendar();
}

function deleteScheduleEventFromDialog() {
  const typeInp = document.getElementById('schedModalTargetType');
  const targetType = typeInp ? typeInp.value : 'milestone';
  const rowIdxInp = document.getElementById('schedModalRowIndex');
  const rowIdx = parseInt(rowIdxInp?.value || '-1', 10);

  if (rowIdx >= 0) {
    if (targetType === 'task') {
      deleteFacultyTaskDirect(rowIdx);
    } else {
      deleteScheduleEventDirect(rowIdx);
    }
  }
  closeScheduleEventDialog();
}

function deleteScheduleEventDirect(rowIndex) {
  const rows = document.querySelectorAll('#schedule-body tr');
  if (rowIndex < 0 || rowIndex >= rows.length) return;

  if (rows.length > 1) {
    rows[rowIndex].remove();
  } else {
    const tr = rows[0];
    const d = tr.querySelector('input[name="sched_date[]"]');
    const ev = tr.querySelector('input[name="sched_event[]"]');
    const ven = tr.querySelector('input[name="sched_venue[]"]');
    const org = tr.querySelector('[name="sched_organizer[]"]');
    if (d) d.value = '';
    if (ev) ev.value = '';
    if (ven) ven.value = '';
    if (org) org.selectedIndex = 0;
  }
  renderScheduleTimelineCalendar();
}

function deleteFacultyTaskDirect(rowIndex) {
  const rows = document.querySelectorAll('#faculty-tasks-body tr');
  if (rowIndex < 0 || rowIndex >= rows.length) return;

  if (rows.length > 1) {
    rows[rowIndex].remove();
  } else {
    const tr = rows[0];
    const d = tr.querySelector('input[name="ft_due_date[]"]');
    const t = tr.querySelector('input[name="ft_task[]"]');
    const r = tr.querySelector('input[name="ft_role[]"]');
    const c = tr.querySelector('input[name="ft_contribution[]"]');
    const u = tr.querySelector('select[name="ft_user_id[]"]');
    const n = tr.querySelector('input.ft-name-field');
    if (d) d.value = '';
    if (t) t.value = '';
    if (r) r.value = '';
    if (c) c.value = '';
    if (u) u.selectedIndex = 0;
    if (n) n.value = '';
  }
  renderScheduleTimelineCalendar();
}

function renderScheduleAgendaList() {
  const container = document.getElementById('schedule-agenda-wrap');
  if (!container) return;

  const items = [];

  // 1. Gather Schedule Events
  document.querySelectorAll('#schedule-body tr').forEach((tr, idx) => {
    const dVal = (tr.querySelector('input[name="sched_date[]"]')?.value || '').trim();
    const evVal = (tr.querySelector('input[name="sched_event[]"]')?.value || '').trim();
    const venVal = (tr.querySelector('input[name="sched_venue[]"]')?.value || '').trim();
    const orgVal = (tr.querySelector('[name="sched_organizer[]"]')?.value || '').trim();
    const catVal = (tr.querySelector('[name="sched_type[]"]')?.value || 'main_event').trim();

    // Filter check
    if (scheduleCalendarFilter === 'prep' && catVal !== 'preparation') return;
    if (scheduleCalendarFilter === 'main' && catVal !== 'main_event') return;
    if (scheduleCalendarFilter === 'sub' && catVal !== 'sub_event') return;
    if (scheduleCalendarFilter === 'tasks') return;

    if (dVal || evVal || venVal || orgVal) {
      items.push({
        type: 'event',
        category: catVal || 'main_event',
        rowIndex: idx,
        date: dVal,
        event: evVal,
        venue: venVal,
        organizer: orgVal
      });
    }
  });

  // 2. Gather Faculty Tasks
  if (scheduleCalendarFilter === 'all' || scheduleCalendarFilter === 'tasks') {
    document.querySelectorAll('#faculty-tasks-body tr').forEach((tr, idx) => {
      const sVal = (tr.querySelector('input[name="ft_start_date[]"]')?.value || '').trim();
      const dVal = (tr.querySelector('input[name="ft_due_date[]"]')?.value || '').trim();
      const taskVal = (tr.querySelector('input[name="ft_task[]"]')?.value || '').trim();
      const userSel = tr.querySelector('select[name="ft_user_id[]"]');
      const nameInp = tr.querySelector('input.ft-name-field');
      let facultyName = '';
      if (userSel && userSel.value && userSel.options[userSel.selectedIndex]) {
        facultyName = userSel.options[userSel.selectedIndex].text.trim();
      } else if (nameInp && nameInp.value.trim()) {
        facultyName = nameInp.value.trim();
      } else {
        facultyName = 'Unassigned Faculty';
      }
      const roleVal = (tr.querySelector('input[name="ft_role[]"]')?.value || '').trim();
      const contribVal = (tr.querySelector('input[name="ft_contribution[]"]')?.value || '').trim();
      if (sVal || dVal || taskVal || roleVal) {
        items.push({
          type: 'task',
          category: 'task',
          rowIndex: idx,
          startDate: sVal,
          dueDate: dVal,
          date: dVal || sVal,
          task: taskVal,
          faculty: facultyName,
          role: roleVal,
          contribution: contribVal
        });
      }
    });
  }

  items.sort((a, b) => (a.date > b.date ? 1 : a.date < b.date ? -1 : 0));

  let html = `
    <div style="margin-top:14px; border:1px solid var(--border, #e2e8f0); border-radius:10px; background:var(--bg-card, #ffffff); overflow:hidden;">
      <div style="padding:10px 14px; background:var(--bg-base, #f8fafc); border-bottom:1px solid var(--border, #e2e8f0); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <span style="font-size:0.86rem; font-weight:700; color:var(--text-main, #0f172a); display:flex; align-items:center; gap:6px;">
          📋 Plotted Timeline &amp; Faculty Deliverables (${items.length})
        </span>
      </div>
      <div style="padding:12px; display:flex; flex-direction:column; gap:8px;">
  `;

  if (items.length === 0) {
    html += `
      <div style="text-align:center; padding:18px 10px; color:var(--text-muted, #64748b); font-size:0.84rem;">
        No items scheduled yet. Click on any date on the calendar above to schedule an event or faculty task.
      </div>
    `;
  } else {
    items.forEach(item => {
      let dateDisplay = item.date ? formatDatePretty(item.date) : 'No date set';
      if (item.type === 'task') {
        if (item.startDate && item.dueDate && item.startDate !== item.dueDate) {
          dateDisplay = `${formatDatePretty(item.startDate)} – ${formatDatePretty(item.dueDate)} (Due)`;
        } else if (item.dueDate) {
          dateDisplay = `Due: ${formatDatePretty(item.dueDate)}`;
        } else if (item.startDate) {
          dateDisplay = `Starts: ${formatDatePretty(item.startDate)}`;
        }
        const safeTask = (item.task || 'Untitled Task').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const safeFac = (item.faculty || 'Unassigned').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const safeRole = (item.role || 'Committee Member').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        html += `
          <div style="display:flex; justify-content:space-between; align-items:center; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; padding:9px 12px; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:240px;">
              <div style="background:#10b981; color:#ffffff; font-weight:700; font-size:0.76rem; border-radius:6px; padding:4px 8px; text-align:center; min-width:90px; flex-shrink:0;">
                📋 ${dateDisplay}
              </div>
              <div>
                <div style="font-weight:700; font-size:0.88rem; color:#065f46; display:flex; align-items:center; gap:6px;">
                  <span>📋 Task: ${safeTask}</span>
                  <span style="font-size:0.7rem; font-weight:600; padding:1px 6px; border-radius:4px; background:#d1fae5; color:#065f46; border:1px solid #a7f3d0;">Faculty Task</span>
                </div>
                <div style="font-size:0.76rem; color:#047857; margin-top:2px; display:flex; gap:12px; flex-wrap:wrap;">
                  <span>👤 ${safeFac}</span>
                  <span>🎯 Role: ${safeRole}</span>
                </div>
              </div>
            </div>
            <div style="display:flex; gap:6px; align-items:center;">
              <button type="button" class="btn btn-outline btn-xs" onclick="editFacultyTaskFromCalendar(${item.rowIndex})" style="font-size:0.75rem; padding:3px 8px; color:#065f46; border-color:#86efac;">✏️ Edit</button>
              <button type="button" class="btn btn-outline btn-xs text-danger" onclick="deleteFacultyTaskDirect(${item.rowIndex})" style="font-size:0.75rem; padding:3px 8px; border-color:#fca5a5; color:#ef4444;" title="Remove this task">✕</button>
            </div>
          </div>
        `;
      } else {
        const safeEv = (item.event || 'Untitled Event').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const safeVen = (item.venue || 'Campus Venue').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const safeOrg = (item.organizer || 'Unassigned').replace(/</g, '&lt;').replace(/>/g, '&gt;');

        let catBadgeBg = '#2563eb';
        let catBorder = '#bfdbfe';
        let catTextBg = '#eff6ff';
        let catTitle = '🎯 Main Event';
        let pillColor = '#1e40af';
        let pillBadgeBg = '#dbeafe';

        if (item.category === 'preparation') {
          catBadgeBg = '#d97706';
          catBorder = '#fde68a';
          catTextBg = '#fef3c7';
          catTitle = '🛠️ Event Prep';
          pillColor = '#92400e';
          pillBadgeBg = '#fef9c3';
        } else if (item.category === 'sub_event') {
          catBadgeBg = '#7c3aed';
          catBorder = '#ddd6fe';
          catTextBg = '#f5f3ff';
          catTitle = '🎪 Sub-Event';
          pillColor = '#5b21b6';
          pillBadgeBg = '#ede9fe';
        }

        html += `
          <div style="display:flex; justify-content:space-between; align-items:center; background:${catTextBg}; border:1px solid ${catBorder}; border-radius:8px; padding:9px 12px; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:240px;">
              <div style="background:${catBadgeBg}; color:#ffffff; font-weight:700; font-size:0.76rem; border-radius:6px; padding:4px 8px; text-align:center; min-width:90px; flex-shrink:0;">
                📌 ${dateDisplay}
              </div>
              <div>
                <div style="font-weight:700; font-size:0.88rem; color:${pillColor}; display:flex; align-items:center; gap:6px;">
                  <span>${safeEv}</span>
                  <span style="font-size:0.7rem; font-weight:600; padding:1px 6px; border-radius:4px; background:${pillBadgeBg}; color:${pillColor}; border:1px solid ${catBorder};">${catTitle}</span>
                </div>
                <div style="font-size:0.76rem; color:var(--text-muted, #64748b); margin-top:2px; display:flex; gap:12px; flex-wrap:wrap;">
                  <span>📍 ${safeVen}</span>
                  <span>👤 ${safeOrg}</span>
                </div>
              </div>
            </div>
            <div style="display:flex; gap:6px; align-items:center;">
              <button type="button" class="btn btn-outline btn-xs" onclick="editScheduleEventFromCalendar(${item.rowIndex})" style="font-size:0.75rem; padding:3px 8px;">✏️ Edit</button>
              <button type="button" class="btn btn-outline btn-xs text-danger" onclick="deleteScheduleEventDirect(${item.rowIndex})" style="font-size:0.75rem; padding:3px 8px; border-color:#fca5a5; color:#ef4444;" title="Remove this item">✕</button>
            </div>
          </div>
        `;
      }
    });
  }

  html += `</div></div>`;
  container.innerHTML = html;
}

function renderScheduleTimelineCalendar() {
  const gridEl = document.getElementById('schedule-calendar-grid');
  const labelEl = document.getElementById('schedule-calendar-month-label');
  if (!gridEl) return;

  if (scheduleCalendarMonth === null || scheduleCalendarYear === null) {
    initScheduleCalendarDate();
  }

  const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  if (labelEl) {
    labelEl.textContent = monthNames[scheduleCalendarMonth] + ' ' + scheduleCalendarYear;
  }

  // Gather scheduled items by YYYY-MM-DD
  const itemsByDate = {};

  // 1. Event Schedules (Preparation, Main Event, Sub-Event)
  document.querySelectorAll('#schedule-body tr').forEach((tr, idx) => {
    const dVal = (tr.querySelector('input[name="sched_date[]"]')?.value || '').trim();
    const evVal = (tr.querySelector('input[name="sched_event[]"]')?.value || '').trim();
    const venVal = (tr.querySelector('input[name="sched_venue[]"]')?.value || '').trim();
    const orgEl = tr.querySelector('[name="sched_organizer[]"]');
    const orgVal = (orgEl?.value || '').trim();
    const catVal = (tr.querySelector('[name="sched_type[]"]')?.value || 'main_event').trim();

    if (!dVal) return;

    // Filter check
    if (scheduleCalendarFilter === 'prep' && catVal !== 'preparation') return;
    if (scheduleCalendarFilter === 'main' && catVal !== 'main_event') return;
    if (scheduleCalendarFilter === 'sub' && catVal !== 'sub_event') return;
    if (scheduleCalendarFilter === 'tasks') return;

    if (!itemsByDate[dVal]) itemsByDate[dVal] = [];
    itemsByDate[dVal].push({
      type: 'event',
      category: catVal || 'main_event',
      rowIndex: idx,
      event: evVal || 'Scheduled Activity',
      venue: venVal || (document.getElementById('f1_venue')?.value || 'Campus Venue'),
      organizer: orgVal || 'Organizer'
    });
  });

  // 2. Faculty Tasks
  if (scheduleCalendarFilter === 'all' || scheduleCalendarFilter === 'tasks') {
    document.querySelectorAll('#faculty-tasks-body tr').forEach((tr, idx) => {
      const sVal = (tr.querySelector('input[name="ft_start_date[]"]')?.value || '').trim();
      const dVal = (tr.querySelector('input[name="ft_due_date[]"]')?.value || '').trim();
      const taskVal = (tr.querySelector('input[name="ft_task[]"]')?.value || '').trim();
      const userSel = tr.querySelector('select[name="ft_user_id[]"]');
      const nameInp = tr.querySelector('input.ft-name-field');
      let facultyName = '';
      if (userSel && userSel.value && userSel.options[userSel.selectedIndex]) {
        facultyName = userSel.options[userSel.selectedIndex].text.trim();
      } else if (nameInp && nameInp.value.trim()) {
        facultyName = nameInp.value.trim();
      } else {
        facultyName = 'Unassigned Faculty';
      }
      const roleVal = (tr.querySelector('input[name="ft_role[]"]')?.value || '').trim();
      const contribVal = (tr.querySelector('input[name="ft_contribution[]"]')?.value || '').trim();
      if (!dVal && !sVal) return;

      const baseTaskObj = {
        type: 'task',
        category: 'task',
        rowIndex: idx,
        startDate: sVal,
        dueDate: dVal,
        task: taskVal || 'Faculty Task',
        faculty: facultyName,
        role: roleVal || 'Committee Member',
        contribution: contribVal
      };

      if (sVal && dVal && sVal < dVal) {
        const startDt = new Date(sVal + 'T00:00:00');
        const endDt = new Date(dVal + 'T00:00:00');
        const diffDays = Math.round((endDt - startDt) / (1000 * 60 * 60 * 24));
        if (diffDays <= 60) {
          for (let dayOffset = 0; dayOffset <= diffDays; dayOffset++) {
            const curDate = new Date(startDt.getTime() + dayOffset * 86400000);
            const curIso = `${curDate.getFullYear()}-${String(curDate.getMonth() + 1).padStart(2, '0')}-${String(curDate.getDate()).padStart(2, '0')}`;
            if (!itemsByDate[curIso]) itemsByDate[curIso] = [];
            let phasePrefix = '';
            if (dayOffset === 0) phasePrefix = '▶ ';
            else if (dayOffset === diffDays) phasePrefix = '🏁 Due: ';

            itemsByDate[curIso].push({
              ...baseTaskObj,
              displayTitle: `${phasePrefix}${baseTaskObj.task}`
            });
          }
          return;
        }
      }

      const targetIso = dVal || sVal;
      if (!itemsByDate[targetIso]) itemsByDate[targetIso] = [];
      itemsByDate[targetIso].push({
        ...baseTaskObj,
        displayTitle: dVal ? `🏁 Due: ${baseTaskObj.task}` : baseTaskObj.task
      });
    });
  }

  const startEventDate = (document.getElementById('f1_event_date')?.value || '').trim();
  const endEventDate = (document.getElementById('f1_end_date')?.value || startEventDate).trim();

  const firstDay = new Date(scheduleCalendarYear, scheduleCalendarMonth, 1).getDay();
  const daysInMonth = new Date(scheduleCalendarYear, scheduleCalendarMonth + 1, 0).getDate();

  const today = new Date();
  const todayIso = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

  let html = '';
  const dayHeaders = ['SUN','MON','TUE','WED','THU','FRI','SAT'];
  dayHeaders.forEach(dh => {
    html += `<div class="schedule-cal-day-header">${dh}</div>`;
  });

  for (let i = 0; i < firstDay; i++) {
    html += `<div class="schedule-cal-cell" style="background:var(--bg-base, #fafafa); opacity:0.35;"></div>`;
  }

  const maxShow = 3;

  const esc = typeof escapeHtml === 'function' ? escapeHtml : function(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  };

  for (let d = 1; d <= daysInMonth; d++) {
    const mm = String(scheduleCalendarMonth + 1).padStart(2, '0');
    const dd = String(d).padStart(2, '0');
    const iso = `${scheduleCalendarYear}-${mm}-${dd}`;
    const isInEventRange = (startEventDate && iso >= startEventDate && iso <= (endEventDate || startEventDate));
    const isToday = (iso === todayIso);
    const dayItems = itemsByDate[iso] || [];

    const cellBg = isToday
      ? 'background: #ffffff;'
      : (isInEventRange ? 'background: rgba(2, 132, 199, 0.04);' : '');

    html += `<div class="schedule-cal-cell ${isInEventRange ? 'event-range' : ''} ${isToday ? 'today' : ''}" ${cellBg ? `style="${cellBg}"` : ''} onclick="openAddScheduleEventDialog('${iso}')" title="Click to schedule on ${iso}">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:2px; flex-shrink:0;">
        ${isToday 
          ? `<span class="day-num" style="background:#0284c7; color:#ffffff; font-weight:700;">${d}</span>` 
          : `<span class="day-num" style="font-weight:${isInEventRange || dayItems.length ? '700' : '500'}; color:${isInEventRange ? '#0284c7' : 'var(--text-main, #334155)'};">${d}</span>`
        }
        ${isInEventRange ? '<span class="sched-event-day-badge" title="Scheduled Event Day">Event</span>' : ''}
      </div>`;

    dayItems.slice(0, maxShow).forEach(item => {
      if (item.type === 'task') {
        const itemTitle = (item.displayTitle || item.task || 'Faculty Task');
        const safeFac = item.faculty || 'Unassigned';
        const safeRole = item.role || 'Committee Member';
        const itemJson = esc(JSON.stringify({
          title: item.task || 'Faculty Task',
          date: item.dueDate || item.startDate || iso,
          time: (item.startDate && item.dueDate && item.startDate !== item.dueDate) ? `${item.startDate} to ${item.dueDate}` : '',
          category: 'Faculty Task',
          categoryColor: '#10b981',
          categoryBg: '#ecfdf5',
          status: 'Assigned',
          statusColor: '#065f46',
          statusBg: '#a7f3d0',
          venue: 'Campus',
          proponent: item.faculty || 'Faculty Member',
          visibility: item.role || 'Task Assignment',
          description: item.contribution || '',
          rowIndex: item.rowIndex,
          itemType: 'task'
        }));

        html += `<div class="sched-cal-event-pill" 
                     data-timeline-item='${itemJson}'
                     onclick="handleTimelinePillClick(this, event)" 
                     title="Faculty Task: ${esc(itemTitle)} (${esc(safeFac)} - ${esc(safeRole)})" 
                     style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; height:22px; max-height:22px; overflow:hidden; white-space:nowrap;">
          <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#10b981; flex-shrink:0;"></span>
          <span class="sched-cal-event-text" style="overflow:hidden; white-space:nowrap; text-overflow:clip; flex:1; min-width:0; width:0;">${esc(itemTitle)}</span>
        </div>`;
      } else {
        const safeEv = item.event || 'Scheduled Activity';
        const safeVen = item.venue || 'Campus Venue';
        const safeOrg = item.organizer || 'Organizer';

        let pillBg = '#eff6ff';
        let pillBorder = '#bfdbfe';
        let pillColor = '#1e40af';
        let dotColor = '#2563eb';
        let catLabel = 'Main Event';

        if (item.category === 'preparation') {
          pillBg = '#fef3c7';
          pillBorder = '#fde68a';
          pillColor = '#92400e';
          dotColor = '#f59e0b';
          catLabel = 'Prep';
        } else if (item.category === 'sub_event') {
          pillBg = '#f5f3ff';
          pillBorder = '#ddd6fe';
          pillColor = '#5b21b6';
          dotColor = '#8b5cf6';
          catLabel = 'Sub-Event';
        }

        const itemJson = esc(JSON.stringify({
          title: item.event || 'Scheduled Activity',
          date: iso,
          venue: item.venue || 'Campus Venue',
          category: catLabel,
          categoryColor: dotColor,
          categoryBg: pillBg,
          organizer: item.organizer || 'Organizer',
          proponent: item.organizer || 'Activity Proponent',
          visibility: 'Campus / Department',
          description: '',
          rowIndex: item.rowIndex,
          itemType: 'event'
        }));

        html += `<div class="sched-cal-event-pill" 
                     data-timeline-item='${itemJson}'
                     onclick="handleTimelinePillClick(this, event)" 
                     title="[${esc(catLabel)}] ${esc(safeEv)} (${esc(safeVen)} - ${esc(safeOrg)})" 
                     style="background:${pillBg}; color:${pillColor}; border:1px solid ${pillBorder}; height:22px; max-height:22px; overflow:hidden; white-space:nowrap;">
          <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:${dotColor}; flex-shrink:0;"></span>
          <span class="sched-cal-event-text" style="overflow:hidden; white-space:nowrap; text-overflow:clip; flex:1; min-width:0; width:0;">${esc(safeEv)}</span>
        </div>`;
      }
    });

    if (dayItems.length > maxShow) {
      html += `<div class="cal-more" onclick="event.stopPropagation();">+${dayItems.length - maxShow} more</div>`;
    }

    html += `</div>`;
  }

  // Trailing empty cells to complete the 7-column calendar week
  const trailing = (7 - ((firstDay + daysInMonth) % 7)) % 7;
  for (let i = 0; i < trailing; i++) {
    html += `<div class="schedule-cal-cell other-month" style="background:var(--bg-base, #fafafa); opacity:0.35;"></div>`;
  }

  gridEl.innerHTML = html;

  // Re-render agenda summary below calendar
  renderScheduleAgendaList();
}
window.renderScheduleTimelineCalendar = renderScheduleTimelineCalendar;

// ── 4. Invitation Mode ("Create Invitation" vs. "Upload Invitation") ───────
let currentInvitationObjectUrl = null;

function handleInvitationModeChange(mode) {
  const modeInput = document.getElementById('invitation_mode_input');
  if (modeInput) modeInput.value = mode;

  const createWrap = document.getElementById('invitation-create-mode-wrap');
  const uploadWrap = document.getElementById('invitation-upload-mode-wrap');
  const btnAddSeg = document.getElementById('btnAddProgramSegment');
  const btnCreateTab = document.getElementById('btnInvModeCreate');
  const btnUploadTab = document.getElementById('btnInvModeUpload');

  if (mode === 'upload') {
    if (createWrap) createWrap.style.display = 'none';
    if (uploadWrap) uploadWrap.style.display = 'block';
    if (btnAddSeg) btnAddSeg.style.display = 'none';
    if (btnUploadTab) { btnUploadTab.classList.add('btn-primary'); btnUploadTab.classList.remove('btn-outline'); }
    if (btnCreateTab) { btnCreateTab.classList.add('btn-outline'); btnCreateTab.classList.remove('btn-primary'); }
  } else {
    if (createWrap) createWrap.style.display = 'block';
    if (uploadWrap) uploadWrap.style.display = 'none';
    if (btnAddSeg) btnAddSeg.style.display = 'inline-flex';
    if (btnCreateTab) { btnCreateTab.classList.add('btn-primary'); btnCreateTab.classList.remove('btn-outline'); }
    if (btnUploadTab) { btnUploadTab.classList.add('btn-outline'); btnUploadTab.classList.remove('btn-primary'); }
  }
}

function handleInvitationFileSelect(input) {
  const previewWrap = document.getElementById('invitation-upload-preview-wrap');
  const previewImg = document.getElementById('invitation-live-preview-img');
  const previewPdf = document.getElementById('invitation-live-preview-pdf');
  const fileNameBadge = document.getElementById('invitation-selected-filename');

  if (currentInvitationObjectUrl) {
    try { URL.revokeObjectURL(currentInvitationObjectUrl); } catch (e) {}
    currentInvitationObjectUrl = null;
  }

  if (input && input.files && input.files[0]) {
    const file = input.files[0];
    if (fileNameBadge) fileNameBadge.textContent = file.name;
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    const isPdf = (ext === 'pdf' || file.type === 'application/pdf');
    currentInvitationObjectUrl = URL.createObjectURL(file);

    if (previewWrap) previewWrap.style.display = 'block';
    if (isPdf) {
      if (previewImg) { previewImg.style.display = 'none'; previewImg.src = ''; }
      if (previewPdf) { previewPdf.src = currentInvitationObjectUrl; previewPdf.style.display = 'block'; }
    } else {
      if (previewPdf) { previewPdf.style.display = 'none'; previewPdf.src = ''; }
      if (previewImg) { previewImg.src = currentInvitationObjectUrl; previewImg.style.display = 'block'; }
    }
  } else {
    if (fileNameBadge) fileNameBadge.textContent = 'No file selected';
    if (previewWrap) previewWrap.style.display = 'none';
  }
}

function formatTime12h(timeStr) {
  if (!timeStr) return '';
  const clean = String(timeStr).trim();
  if (!clean || clean === 'N/A' || clean === '—' || clean === '–' || clean === 'TBA' || clean === 'TBD') return '';
  if (/(AM|PM)$/i.test(clean)) return clean;
  const parts = clean.split(':');
  if (parts.length < 2) return clean;
  let hours = parseInt(parts[0], 10);
  if (isNaN(hours)) return clean;
  const minutes = parts[1].slice(0, 2);
  const ampm = hours >= 12 ? 'PM' : 'AM';
  hours = hours % 12;
  hours = hours ? hours : 12;
  return `${hours}:${minutes} ${ampm}`;
}

function formatTimeRange12h(st, et) {
  const s = formatTime12h(st);
  const e = formatTime12h(et);
  if (s && e) return `${s} – ${e}`;
  if (s) return s;
  if (e) return e;
  return 'TBA';
}

function openDigitalInvitationPreview() {
  const modal = document.getElementById('digitalInvitationPreviewModal');
  const body = document.getElementById('digitalInvitationPreviewBody');
  if (!modal || !body) return;

  const title = (document.getElementById('f1_title')?.value || 'Upcoming Campus Activity').trim();
  const theme = (document.getElementById('f1_theme')?.value || '').trim();
  const startDate = (document.getElementById('f1_event_date')?.value || 'TBA').trim();
  const endDate = (document.getElementById('f1_end_date')?.value || startDate).trim();
  const startTime = (document.getElementById('f1_start_time')?.value || '').trim();
  const endTime = (document.getElementById('f1_end_time')?.value || '').trim();
  const venue = (document.getElementById('f1_venue')?.value || 'STI College Marikina').trim();
  const dateDisplay = (endDate && endDate !== startDate) ? `${startDate} to ${endDate}` : startDate;

  let progRowsHtml = '';
  document.querySelectorAll('#program-body tr').forEach(tr => {
    const t = (tr.querySelector('input[name="prog_time[]"]')?.value || '').trim();
    const s = (tr.querySelector('input[name="prog_segment[]"]')?.value || '').trim();
    const d = (tr.querySelector('input[name="prog_desc[]"]')?.value || '').trim();
    const p = (tr.querySelector('[name="prog_pic[]"]')?.value || '').trim();
    if (!s && !d) return;
    progRowsHtml += `<tr style="border-bottom:1px solid #e2e8f0;">
      <td style="padding:8px 10px; font-weight:700; color:#003876; white-space:nowrap;">${formatTime12h(t) || '—'}</td>
      <td style="padding:8px 10px;"><strong>${s}</strong>${d ? `<div style="font-size:0.78rem; color:#64748b;">${d}</div>` : ''}</td>
      <td style="padding:8px 10px; font-size:0.82rem; color:#334155;">${p || '—'}</td>
    </tr>`;
  });

  if (!progRowsHtml) {
    progRowsHtml = `<tr><td colspan="3" style="padding:14px; text-align:center; color:#64748b;">Add program segments to populate the invitation schedule.</td></tr>`;
  }

  body.innerHTML = `
    <div style="border:2px solid #003876; border-radius:14px; padding:24px; background:#ffffff; text-align:center; box-shadow:0 10px 25px rgba(0,56,118,0.08);">
      <div style="font-size:0.75rem; font-weight:800; letter-spacing:0.12em; text-transform:uppercase; color:#d97706; margin-bottom:6px;">STI College Marikina • Official Event Invitation</div>
      <h2 style="font-size:1.45rem; font-weight:800; color:#003876; margin:0 0 8px 0;">${title}</h2>
      ${theme ? `<p style="font-size:0.9rem; font-style:italic; color:#475569; margin:0 0 16px 0;">"${theme}"</p>` : ''}
      <div style="display:flex; justify-content:center; gap:16px; flex-wrap:wrap; font-size:0.84rem; background:#f8fafc; padding:10px 16px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:18px;">
        <div>📅 <strong>Date:</strong> ${dateDisplay}</div>
        <div>⏰ <strong>Time:</strong> ${formatTimeRange12h(startTime, endTime)}</div>
        <div>📍 <strong>Venue:</strong> ${venue}</div>
      </div>
      <div style="text-align:left;">
        <div style="font-size:0.82rem; font-weight:700; text-transform:uppercase; color:#003876; margin-bottom:8px; border-bottom:1.5px solid #003876; padding-bottom:4px;">Program of Activities</div>
        <table style="width:100%; border-collapse:collapse; font-size:0.84rem;">
          <thead>
            <tr style="background:#f1f5f9; text-align:left;">
              <th style="padding:8px 10px;">Time</th>
              <th style="padding:8px 10px;">Segment / Activity</th>
              <th style="padding:8px 10px;">Person In-Charge</th>
            </tr>
          </thead>
          <tbody>${progRowsHtml}</tbody>
        </table>
      </div>
    </div>
  `;
  modal.style.display = 'flex';
}

function closeDigitalInvitationPreview() {
  const modal = document.getElementById('digitalInvitationPreviewModal');
  if (modal) modal.style.display = 'none';
}

// ── 5. Combined Judging Criteria & Scoring System (List Format) ────────────
function addCriteriaListRow(criterion = '', weight = '', skipSync = false) {
  const container = document.getElementById('combined-criteria-list-body');
  if (!container) return;

  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td style="width:44px; text-align:center; font-weight:700; color:var(--text-muted, #64748b);" class="crit-row-num">#</td>
    <td>
      <input type="text" class="form-control crit-item-name" placeholder="e.g. Creativity & Technical Execution" value="${criterion.replace(/"/g, '&quot;')}" oninput="syncCombinedCriteriaToTextareas()">
    </td>
    <td style="width:160px;">
      <input type="text" class="form-control crit-item-score" placeholder="e.g. 40% or 40 pts" value="${weight.replace(/"/g, '&quot;')}" oninput="syncCombinedCriteriaToTextareas()">
    </td>
    <td style="width:44px; text-align:center;">
      <button type="button" class="btn btn-outline btn-sm" onclick="removeCriteriaListRow(this)">✕</button>
    </td>
  `;
  container.appendChild(tr);
  renumberCriteriaListRows();
  if (!skipSync) {
    syncCombinedCriteriaToTextareas();
  }
}

function removeCriteriaListRow(btn) {
  const container = document.getElementById('combined-criteria-list-body');
  if (!container) return;
  if (container.querySelectorAll('tr').length > 1) {
    btn.closest('tr')?.remove();
    renumberCriteriaListRows();
    syncCombinedCriteriaToTextareas();
  }
}

function renumberCriteriaListRows() {
  const rows = document.querySelectorAll('#combined-criteria-list-body tr');
  rows.forEach((tr, idx) => {
    const numCell = tr.querySelector('.crit-row-num');
    if (numCell) numCell.textContent = (idx + 1) + '.';
  });
}

function syncCombinedCriteriaToTextareas() {
  const rows = document.querySelectorAll('#combined-criteria-list-body tr');
  if (!rows.length) return;

  const criteriaLines = [];
  const scoringLines = [];
  let numericSum = 0;

  rows.forEach((tr, idx) => {
    const nameVal = (tr.querySelector('.crit-item-name')?.value || '').trim();
    const scoreVal = (tr.querySelector('.crit-item-score')?.value || '').trim();
    if (!nameVal && !scoreVal) return;

    const num = idx + 1;
    const numMatch = scoreVal.match(/(\d+(?:\.\d+)?)/);
    if (numMatch) {
      numericSum += parseFloat(numMatch[1]);
    }

    if (nameVal && scoreVal) {
      criteriaLines.push(`${num}. ${nameVal} (${scoreVal})`);
      scoringLines.push(`${num}. ${nameVal}: ${scoreVal}`);
    } else if (nameVal) {
      criteriaLines.push(`${num}. ${nameVal}`);
      scoringLines.push(`${num}. ${nameVal}: Standard Rubric Evaluation`);
    }
  });

  const totalBadge = document.getElementById('combined-criteria-total-badge');
  if (totalBadge) {
    totalBadge.textContent = numericSum > 0 ? `Total Weight / Points: ${numericSum}% / pts` : 'List Format Active';
  }

  const critEl = document.getElementById('f7_criteria');
  const scoreEl = document.getElementById('f7_scoring');
  if (critEl && criteriaLines.length > 0) {
    critEl.value = criteriaLines.join('\n');
    critEl.classList.remove('is-invalid');
    const eCrit = document.getElementById('e_criteria');
    if (eCrit) eCrit.style.display = 'none';
  }
  if (scoreEl && scoringLines.length > 0) {
    const currentScore = scoreEl.value.trim();
    const isDescriptiveSummary = currentScore && !currentScore.match(/^\d+[\.\)]/m);
    if (!isDescriptiveSummary) {
      scoreEl.value = scoringLines.join('\n');
      scoreEl.classList.remove('is-invalid');
      const eScore = document.getElementById('e_scoring');
      if (eScore) eScore.style.display = 'none';
    }
  }
}

function hydrateCombinedCriteriaListFromTextareas() {
  const container = document.getElementById('combined-criteria-list-body');
  if (!container) return;

  const currentRows = container.querySelectorAll('tr');
  const isOnlyBlankRow = currentRows.length === 0 || (currentRows.length === 1 &&
    !(currentRows[0].querySelector('.crit-item-name')?.value || '').trim() &&
    !(currentRows[0].querySelector('.crit-item-score')?.value || '').trim());

  if (currentRows.length > 0 && !isOnlyBlankRow) return;

  const critVal = (document.getElementById('f7_criteria')?.value || '').trim();
  const scoreVal = (document.getElementById('f7_scoring')?.value || '').trim();

  if (!critVal && !scoreVal) {
    if (currentRows.length === 0) {
      addCriteriaListRow('', '', true);
    }
    return;
  }

  const critLines = critVal.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
  const scoreLines = scoreVal.split(/\r?\n/).map(l => l.trim()).filter(Boolean);

  if (critLines.length > 0) {
    container.innerHTML = '';
    critLines.forEach((line, idx) => {
      const cleaned = line.replace(/^\d+[\.\)]\s*/, '');
      const parenMatch = cleaned.match(/^(.*?)\s*\(([^)]+)\)\s*$/);
      const colonMatch = cleaned.match(/^(.*?)\s*[:–—\-]\s*(\d+(?:\.\d+)?%?(?:\s*(?:pts|points|%))?)$/i);
      if (parenMatch) {
        addCriteriaListRow(parenMatch[1].trim(), parenMatch[2].trim(), true);
      } else if (colonMatch) {
        addCriteriaListRow(colonMatch[1].trim(), colonMatch[2].trim(), true);
      } else {
        const sLine = (scoreLines[idx] || '').replace(/^\d+[\.\)]\s*/, '');
        const colonParts = sLine.split(':');
        const extractedScore = colonParts.length > 1 ? colonParts.slice(1).join(':').trim() : sLine;
        addCriteriaListRow(cleaned, extractedScore, true);
      }
    });
    renumberCriteriaListRows();
  } else if (currentRows.length === 0) {
    addCriteriaListRow('', '', true);
  }
}

// Global exports for accessibility across wizard tools & AI guidance
window.addCriteriaListRow = addCriteriaListRow;
window.removeCriteriaListRow = removeCriteriaListRow;
window.renumberCriteriaListRows = renumberCriteriaListRows;
window.syncCombinedCriteriaToTextareas = syncCombinedCriteriaToTextareas;
window.hydrateCombinedCriteriaListFromTextareas = hydrateCombinedCriteriaListFromTextareas;

// Initialize Phase 2 & Phase 3 listeners on DOMContentLoaded
function injectStakeholderEvaluationSelector() {
  injectSupportingDocumentsAttachmentSection();
  const evalCardBody = document.querySelector('#ai-evaluation-tool-card .card-body');
  if (!evalCardBody || document.getElementById('eval_stakeholder_type_select')) return;

  const wrapper = document.createElement('div');
  wrapper.style.cssText = 'padding:12px 14px; background:var(--bg-base, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:8px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;';
  wrapper.innerHTML = `
    <div style="flex:1; min-width:220px;">
      <label for="eval_stakeholder_type_select" style="font-size:0.82rem; font-weight:700; color:var(--text-main, #0f172a); display:block; margin-bottom:2px;">
        👥 Target Stakeholder Evaluation Tool
      </label>
      <span style="font-size:0.75rem; color:var(--text-muted, #64748b);">
        Provide tailored evaluation tools based on the specific individuals or stakeholders involved in the event.
      </span>
    </div>
    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
      <select name="evaluation_stakeholder" id="eval_stakeholder_type_select" class="form-control" style="min-width:250px; font-size:0.82rem; font-weight:600;" onchange="handleStakeholderEvaluationChange(this.value)">
        <option value="students">🎓 Students / Attendees Tool</option>
        <option value="faculty">👩‍🏫 Faculty / Advisers &amp; Evaluators Tool</option>
        <option value="speakers_judges">🎤 Guest Speakers / Judges &amp; Panelists Tool</option>
        <option value="committee_peers">🛠️ Organizing Committee / Personnel &amp; Peers Tool</option>
        <option value="multi_stakeholder">🔀 Multi-Stakeholder Combined Evaluation Tool</option>
      </select>
    </div>
  `;
  evalCardBody.insertBefore(wrapper, evalCardBody.firstChild);
}

// Panel 1 Module 2.2: File Attachment Handler for Supporting Documents (Letter requests, Safety guidelines, Design layouts, Parental consents)
function injectSupportingDocumentsAttachmentSection() {
  const glInput = document.getElementById('f7_guidelines_attachment');
  if (!glInput || document.getElementById('supportingDocsHandlerBox')) return;
  const parentBox = glInput.closest('.form-group');
  if (!parentBox || !parentBox.parentNode) return;

  const box = document.createElement('div');
  box.id = 'supportingDocsHandlerBox';
  box.className = 'form-group';
  box.style.cssText = 'margin-top:14px; padding:14px 16px; background:var(--bg-base, #f8fafc); border:1px solid var(--border, #cbd5e1); border-radius:8px;';
  box.innerHTML = `
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
      <label class="form-label" style="font-weight:700; margin:0;">📁 Supporting Documents Attachment Handler <span style="font-size:0.78rem; font-weight:normal; color:var(--text-muted);">(Letter Requests, Safety Guidelines, Design Layouts, Parental Consents)</span></label>
      <button type="button" class="btn btn-outline btn-sm" onclick="addSupportingDocUploadRow()" style="padding:3px 10px; font-size:0.76rem;">+ Add Supporting Document</button>
    </div>
    <p style="font-size:0.78rem; color:var(--text-muted, #64748b); margin:0 0 10px 0;">
      Attach official supporting documents required for institutional compliance (PDF, Word, Excel, or Image files).
    </p>
    <div id="supportingDocsRowsContainer" style="display:flex; flex-direction:column; gap:8px;">
      <div class="supporting-doc-row" style="display:grid; grid-template-columns:220px 1fr auto; gap:8px; align-items:center;">
        <select name="supporting_doc_category[]" class="form-control" style="font-size:0.8rem; font-weight:600;">
          <option value="Letter Request">📄 Letter Request</option>
          <option value="Safety Guidelines">🦺 Safety Guidelines</option>
          <option value="Design Layout">🎨 Design Layout</option>
          <option value="Parental Consent">👪 Parental Consent</option>
          <option value="Other Supporting Document">📎 Other Supporting Document</option>
        </select>
        <input type="file" name="supporting_doc_files[]" class="form-control" style="font-size:0.8rem;" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp,.xls,.xlsx">
        <button type="button" class="btn btn-outline btn-sm" onclick="if(document.querySelectorAll('#supportingDocsRowsContainer .supporting-doc-row').length>1)this.closest('.supporting-doc-row').remove()">✕</button>
      </div>
    </div>
  `;
  parentBox.parentNode.appendChild(box);
}

function addSupportingDocUploadRow() {
  const container = document.getElementById('supportingDocsRowsContainer');
  if (!container) return;
  const row = document.createElement('div');
  row.className = 'supporting-doc-row';
  row.style.cssText = 'display:grid; grid-template-columns:220px 1fr auto; gap:8px; align-items:center;';
  row.innerHTML = `
    <select name="supporting_doc_category[]" class="form-control" style="font-size:0.8rem; font-weight:600;">
      <option value="Letter Request">📄 Letter Request</option>
      <option value="Safety Guidelines">🦺 Safety Guidelines</option>
      <option value="Design Layout">🎨 Design Layout</option>
      <option value="Parental Consent">👪 Parental Consent</option>
      <option value="Other Supporting Document">📎 Other Supporting Document</option>
    </select>
    <input type="file" name="supporting_doc_files[]" class="form-control" style="font-size:0.8rem;" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg,.webp,.xls,.xlsx">
    <button type="button" class="btn btn-outline btn-sm" onclick="if(document.querySelectorAll('#supportingDocsRowsContainer .supporting-doc-row').length>1)this.closest('.supporting-doc-row').remove()">✕</button>
  `;
  container.appendChild(row);
}

async function handleStakeholderEvaluationChange(stakeholderType) {
  const titleEl = document.getElementById('f1_title') || document.querySelector('[name="title"]');
  const themeEl = document.getElementById('f1_theme') || document.querySelector('[name="theme"]');
  const genEl   = document.getElementById('f2_general_objectives') || document.querySelector('[name="general_objectives"]');
  const specEl  = document.getElementById('f2_specific_objectives') || document.querySelector('[name="specific_objectives"]');

  const curTitle = (titleEl?.value || '').trim();
  const curTheme = (themeEl?.value || '').trim();
  const curGen   = (genEl?.value || '').trim();
  const curSpec  = (specEl?.value || '').trim();

  if (curTitle.length < 4 || !curTheme || (!curGen && !curSpec)) {
    return;
  }

  if (typeof setEvaluationState === 'function') {
    setEvaluationState('GENERATING');
  }

  try {
    const res = await fetch('<?= defined("BASE_URL") ? BASE_URL : "" ?>/api/generate-evaluation.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        title: curTitle,
        theme: curTheme,
        general_objectives: curGen,
        specific_objectives: curSpec,
        stakeholder_type: stakeholderType
      })
    });
    const data = await res.json();
    if (data && data.success && Array.isArray(data.questions) && data.questions.length > 0) {
      if (typeof window.questionsList !== 'undefined') {
        window.questionsList = data.questions;
      }
      if (typeof questionsList !== 'undefined') {
        questionsList = data.questions;
      }
      if (typeof renderQuestions === 'function') renderQuestions();
      if (typeof serializeEvaluationQuestions === 'function') serializeEvaluationQuestions();
      if (typeof setEvaluationState === 'function') setEvaluationState('READY');
    }
  } catch (e) {
    if (typeof setEvaluationState === 'function') setEvaluationState('READY');
  }
}

function initPhase2ProposalEnhancements() {
  injectStakeholderEvaluationSelector();
  refreshEncodedOrganizerDropdowns();
  hydrateCombinedCriteriaListFromTextareas();
  renderScheduleTimelineCalendar();

  // Keep organizer dropdowns and schedule calendar synced when inputs change
  document.addEventListener('input', function(e) {
    if (!e.target) return;
    const name = e.target.getAttribute('name') || '';
    if (name === 'mp_person[]' || name === 'ft_name[]' || name.startsWith('part_teacher')) {
      refreshEncodedOrganizerDropdowns();
    }
    if (name === 'sched_date[]' || name === 'sched_event[]' || name === 'sched_venue[]' || name === 'sched_organizer[]' || name === 'ft_task[]' || name === 'ft_role[]' || name === 'ft_due_date[]') {
      renderScheduleTimelineCalendar();
    }
    if (e.target.id === 'f1_event_date' || e.target.id === 'f1_end_date') {
      initScheduleCalendarDate();
      renderScheduleTimelineCalendar();
    }
    if (e.target.id === 'f7_criteria') {
      const container = document.getElementById('combined-criteria-list-body');
      if (container) {
        const rows = container.querySelectorAll('tr');
        const isOnlyBlank = rows.length === 0 || (rows.length === 1 &&
          !(rows[0].querySelector('.crit-item-name')?.value || '').trim() &&
          !(rows[0].querySelector('.crit-item-score')?.value || '').trim());
        if (isOnlyBlank && e.target.value.trim()) {
          hydrateCombinedCriteriaListFromTextareas();
        }
      }
    }
  });

  document.addEventListener('change', function(e) {
    if (!e.target) return;
    const name = e.target.getAttribute('name') || '';
    if (name === 'ft_user_id[]' || name === 'mp_person[]') {
      refreshEncodedOrganizerDropdowns();
      renderScheduleTimelineCalendar();
    }
    if (name === 'sched_date[]' || name === 'sched_organizer[]' || name === 'ft_due_date[]' || e.target.id === 'f1_event_date' || e.target.id === 'f1_end_date') {
      renderScheduleTimelineCalendar();
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initPhase2ProposalEnhancements);
} else {
  initPhase2ProposalEnhancements();
}
// ── Bulk Add Sections & Academic Presets ────────────────────────────
function openBulkAddSectionsModal() {
  const modal = document.getElementById('bulkAddSectionsModal');
  if (modal) {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    const input = document.getElementById('bulkPasteInput');
    if (input) setTimeout(() => input.focus(), 80);
  }
}

function closeBulkAddSectionsModal() {
  const modal = document.getElementById('bulkAddSectionsModal');
  if (modal) {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

function applyBulkPreset(key) {
  const input = document.getElementById('bulkPasteInput');
  if (!input) return;

  const presets = {
    // Tertiary / College: 101, 201, 301, 401, 501, 601, 701, 801
    'BSIT_ALL': "BSIT 101, 35\nBSIT 201, 35\nBSIT 301, 35\nBSIT 401, 35\nBSIT 501, 35\nBSIT 601, 35\nBSIT 701, 35\nBSIT 801, 35",
    'BSCS_ALL': "BSCS 101, 35\nBSCS 201, 35\nBSCS 301, 35\nBSCS 401, 35\nBSCS 501, 35\nBSCS 601, 35\nBSCS 701, 35\nBSCS 801, 35",
    // Tourism Management (BSTM) & Hospitality (HRS) separated
    'BSTM_ALL': "BSTM 101, 35\nBSTM 201, 35\nBSTM 301, 35\nBSTM 401, 35\nBSTM 501, 35\nBSTM 601, 35\nBSTM 701, 35\nBSTM 801, 35",
    'HRS_ALL': "HRS 101, 35\nHRS 201, 35\nHRS 301, 35\nHRS 401, 35\nHRS 501, 35\nHRS 601, 35\nHRS 701, 35\nHRS 801, 35",
    'COLLEGE_ALL': "BSIT 101, 35\nBSIT 201, 35\nBSIT 301, 35\nBSIT 401, 35\nBSIT 501, 35\nBSIT 601, 35\nBSIT 701, 35\nBSIT 801, 35\nBSCS 101, 35\nBSCS 201, 35\nBSCS 301, 35\nBSCS 401, 35\nBSCS 501, 35\nBSCS 601, 35\nBSCS 701, 35\nBSCS 801, 35\nBSTM 101, 35\nBSTM 201, 35\nBSTM 301, 35\nBSTM 401, 35\nBSTM 501, 35\nBSTM 601, 35\nBSTM 701, 35\nBSTM 801, 35\nHRS 101, 35\nHRS 201, 35\nHRS 301, 35\nHRS 401, 35\nHRS 501, 35\nHRS 601, 35\nHRS 701, 35\nHRS 801, 35",

    // Senior High School: Grade 11 & Grade 12 for each strand
    'SHS_ALL': "HTCUA 11, 35\nHTCUA 12, 35\nHTTO 11, 35\nHTTO 12, 35\nASSH 11, 35\nASSH 12, 35\nB&E 11, 35\nB&E 12, 35\nICT-SCPT 11, 35\nICT-SCPT 12, 35",
    'SHS_ICT': "ICT-SCPT 11-A, 35\nICT-SCPT 11-B, 35\nICT-SCPT 12-A, 35\nICT-SCPT 12-B, 35",
    'SHS_HTCUA': "HTCUA 11-A, 35\nHTCUA 11-B, 35\nHTCUA 12-A, 35\nHTCUA 12-B, 35",
    'SHS_HTTO': "HTTO 11-A, 35\nHTTO 11-B, 35\nHTTO 12-A, 35\nHTTO 12-B, 35",
    'SHS_ASSH': "ASSH 11-A, 35\nASSH 11-B, 35\nASSH 12-A, 35\nASSH 12-B, 35",
    'SHS_BE': "B&E 11-A, 35\nB&E 11-B, 35\nB&E 12-A, 35\nB&E 12-B, 35"
  };

  if (presets[key]) {
    input.value = presets[key];
  }
}

function processBulkAddSections() {
  try {
    const presetSelect = document.getElementById('bulkPresetSelect');
    let pasteInput = document.getElementById('bulkPasteInput');
    let pasteVal = (pasteInput?.value || '').trim();
    const defaultCountInput = document.getElementById('bulkDefaultCount');
    const defaultCount = parseInt(defaultCountInput?.value, 10) || 35;

    // Auto-apply preset if textarea is empty but preset is selected
    if (!pasteVal && presetSelect && presetSelect.value) {
      applyBulkPreset(presetSelect.value);
      pasteVal = (pasteInput?.value || '').trim();
    }

    if (!pasteVal) {
      alert('Please enter or select at least one section/subject to add.');
      if (pasteInput) pasteInput.focus();
      return;
    }

    const lines = pasteVal.split(/[\r\n]+/);
    const itemsToAdd = [];

    lines.forEach(rawLine => {
      const line = rawLine.trim();
      if (!line) return;

      let sectionName = line;
      let countVal = defaultCount;

      // Check common delimiters: comma, tab, semicolon, colon, pipe, dash/hyphen, parentheses
      if (line.includes('\t')) {
        const parts = line.split('\t');
        sectionName = parts[0].trim();
        const parsed = parseInt(parts[1]?.trim(), 10);
        if (!isNaN(parsed) && parsed > 0) countVal = parsed;
      } else if (line.includes(',')) {
        const parts = line.split(',');
        sectionName = parts[0].trim();
        const parsed = parseInt(parts[1]?.trim(), 10);
        if (!isNaN(parsed) && parsed > 0) countVal = parsed;
      } else if (line.includes(';')) {
        const parts = line.split(';');
        sectionName = parts[0].trim();
        const parsed = parseInt(parts[1]?.trim(), 10);
        if (!isNaN(parsed) && parsed > 0) countVal = parsed;
      } else if (line.includes('|')) {
        const parts = line.split('|');
        sectionName = parts[0].trim();
        const parsed = parseInt(parts[1]?.trim(), 10);
        if (!isNaN(parsed) && parsed > 0) countVal = parsed;
      } else if (/\s*:\s*\d+$/.test(line)) {
        const match = line.match(/^(.*?)\s*:\s*(\d+)$/);
        if (match) {
          sectionName = match[1].trim();
          countVal = parseInt(match[2], 10) || defaultCount;
        }
      } else if (/\s*[-–—]\s*\d+$/.test(line)) {
        const match = line.match(/^(.*?)\s*[-–—]\s*(\d+)$/);
        if (match) {
          sectionName = match[1].trim();
          countVal = parseInt(match[2], 10) || defaultCount;
        }
      } else if (/\s*\(\s*\d+\s*\)$/.test(line)) {
        const match = line.match(/^(.*?)\s*\(\s*(\d+)\s*\)$/);
        if (match) {
          sectionName = match[1].trim();
          countVal = parseInt(match[2], 10) || defaultCount;
        }
      }

      if (sectionName) {
        itemsToAdd.push({ section: sectionName, count: countVal });
      }
    });

    if (itemsToAdd.length === 0) {
      alert('No valid sections found in your input. Please check your text.');
      return;
    }

    const tbody = document.getElementById('participant-sections-body');
    if (!tbody) {
      alert('Target participants table body was not found on this page.');
      return;
    }

    const existingRows = tbody.querySelectorAll('tr.participant-row');
    let isFirstRowEmpty = false;

    if (existingRows.length === 1) {
      const secInput = existingRows[0].querySelector('.part-section-input');
      const cntInput = existingRows[0].querySelector('.part-count-input');
      const secVal = (secInput?.value || '').trim();
      const cntVal = parseInt(cntInput?.value, 10) || 0;
      if (secVal === '' && cntVal === 0) {
        isFirstRowEmpty = true;
      }
    }

    // Direct DOM fallback row injector in case function is missing or throws
    const insertRowFallback = function(section, count, teacher) {
      const currentRows = tbody.querySelectorAll('tr.participant-row');
      const newIdx = currentRows.length + 1;
      const tr = document.createElement('tr');
      tr.className = 'participant-row';
      tr.style.borderBottom = '1px solid #f1f5f9';
      
      const safeSec = (section || '').replace(/"/g, '&quot;');
      const safeCount = (count !== undefined && count !== null) ? count : '';
      const safeTeacher = (teacher || '').replace(/"/g, '&quot;');

      tr.innerHTML = `
        <td class="part-row-num" style="text-align: center; padding: 6px 8px; vertical-align: middle;">
          <span class="part-num-badge" style="display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; border-radius: 6px; background: #f1f5f9; color: #475569; font-weight: 700; font-size: 0.78rem;">${newIdx}</span>
        </td>
        <td style="padding: 6px 10px; vertical-align: middle;">
          <input type="text" name="part_section[]" class="form-control part-section-input" placeholder="e.g. BSIT 301 / Data Structures" value="${safeSec}" style="height: 36px; font-size: 0.85rem; border-radius: 6px; padding: 4px 10px;">
        </td>
        <td style="padding: 6px 10px; vertical-align: middle; width: 140px;">
          <input type="number" name="part_count[]" class="form-control part-count-input" placeholder="0" min="0" value="${safeCount}" oninput="if(typeof window.updateTotalTargetParticipants==='function'){window.updateTotalTargetParticipants();}else if(typeof updateTotalTargetParticipants==='function'){updateTotalTargetParticipants();}" style="height: 36px; font-size: 0.85rem; font-weight: 600; text-align: center; border-radius: 6px; padding: 4px 8px;">
        </td>
        <td style="padding: 6px 10px; min-width: 270px; vertical-align: middle; position: relative;">
          <div class="teacher-multiselect" data-row-idx="${newIdx}">
            <input type="hidden" name="part_teacher[]" class="part-teacher-input" value="${safeTeacher}">
            <div class="tms-control" onclick="if(typeof focusTmsInput==='function'){focusTmsInput(this);}" style="min-height: 36px; padding: 3px 8px; border-radius: 6px;">
              <div class="tms-tags"></div>
              <input type="text" class="tms-input" placeholder="Search and select faculty...">
            </div>
            <div class="tms-dropdown" style="display: none;">
              <div class="tms-list"></div>
              <div class="tms-empty" style="display: none;">No matching faculty found</div>
            </div>
          </div>
        </td>
        <td style="text-align: center; padding: 6px 8px; vertical-align: middle;">
          <button type="button" class="btn btn-outline btn-sm btn-delete-section" onclick="if(typeof window.removeParticipantSectionRow==='function'){window.removeParticipantSectionRow(this);}else if(typeof removeParticipantSectionRow==='function'){removeParticipantSectionRow(this);}else{this.closest('tr')?.remove();}" title="Delete section" style="width: 28px; height: 28px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 6px; color: #ef4444; border-color: #fca5a5; line-height: 1;">✕</button>
        </td>
      `;
      tbody.appendChild(tr);

      const newTms = tr.querySelector('.teacher-multiselect');
      if (newTms && typeof window.initTeacherMultiSelect === 'function') {
        try {
          window.initTeacherMultiSelect(newTms, teacher);
        } catch (tmsErr) {
          console.warn('TeacherMultiSelect init warning:', tmsErr);
        }
      }
    };

    const addRowFn = (typeof window.addParticipantSectionRow === 'function')
      ? window.addParticipantSectionRow
      : (typeof addParticipantSectionRow === 'function' ? addParticipantSectionRow : insertRowFallback);

    itemsToAdd.forEach((item, idx) => {
      if (idx === 0 && isFirstRowEmpty && existingRows[0]) {
        const firstRow = existingRows[0];
        const secInput = firstRow.querySelector('.part-section-input');
        const cntInput = firstRow.querySelector('.part-count-input');
        if (secInput) {
          secInput.value = item.section;
          secInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (cntInput) {
          cntInput.value = item.count;
          cntInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const tms = firstRow.querySelector('.teacher-multiselect');
        if (tms && !tms._tmsInitialized && typeof window.initTeacherMultiSelect === 'function') {
          try {
            window.initTeacherMultiSelect(tms);
          } catch (tmsErr) {
            console.warn('First row TMS init:', tmsErr);
          }
        }
      } else {
        try {
          addRowFn(item.section, item.count, '');
        } catch (callErr) {
          console.warn('addRowFn encountered error, using fallback:', callErr);
          insertRowFallback(item.section, item.count, '');
        }
      }
    });

    // Safely renumber rows
    if (typeof window.renumberParticipantRows === 'function') {
      window.renumberParticipantRows();
    } else if (typeof renumberParticipantRows === 'function') {
      renumberParticipantRows();
    }

    // Safely update participant count totals
    if (typeof window.updateTotalTargetParticipants === 'function') {
      window.updateTotalTargetParticipants();
    } else if (typeof updateTotalTargetParticipants === 'function') {
      updateTotalTargetParticipants();
    }

    // Safely sync with KPI Section Tables
    if (typeof window.syncKpiSectionTables === 'function') {
      window.syncKpiSectionTables();
    } else if (typeof syncKpiSectionTables === 'function') {
      syncKpiSectionTables();
    }

    // Scroll table container to top
    const scrollBox = document.getElementById('target-participants-scroll-box');
    if (scrollBox) {
      scrollBox.scrollTop = 0;
    }

    // Close modal cleanly
    closeBulkAddSectionsModal();

    // Reset inputs for next use
    if (presetSelect) presetSelect.value = '';
    if (pasteInput) pasteInput.value = '';

    // Scroll to the Target Participants card
    const card = document.getElementById('target-participants-card');
    if (card && typeof card.scrollIntoView === 'function') {
      card.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  } catch (err) {
    console.error('Error during processBulkAddSections:', err);
    alert('An unexpected error occurred while adding sections: ' + (err.message || err));
  }
}

window.openBulkAddSectionsModal = openBulkAddSectionsModal;
window.closeBulkAddSectionsModal = closeBulkAddSectionsModal;
window.applyBulkPreset = applyBulkPreset;
window.processBulkAddSections = processBulkAddSections;

// ── Bulk Add Committees & Event Manpower Selector ────────────────────
const STANDARD_EVENT_ROLES = [
  { role: 'Marshall', defaultType: 'student', defaultCount: 15 },
  { role: 'Medic', defaultType: 'staff', defaultCount: 2 },
  { role: 'Usher', defaultType: 'student', defaultCount: 10 },
  { role: 'Customer Service', defaultType: 'student', defaultCount: 5 },
  { role: 'Sound & AVP Support', defaultType: 'student', defaultCount: 4 },
  { role: 'Floor Director', defaultType: 'staff', defaultCount: 1 },
  { role: 'Emcee', defaultType: 'student', defaultCount: 2 },
  { role: 'Tabulators', defaultType: 'faculty', defaultCount: 4 },
  { role: 'Photographers', defaultType: 'student', defaultCount: 2 },
  { role: 'Videographers', defaultType: 'student', defaultCount: 2 },
  { role: 'Adviser(s) / Teacher(s) / Staff(s)', defaultType: 'faculty', defaultCount: 2 },
  { role: 'Expected Guest(s)', defaultType: 'faculty', defaultCount: 5 },
  { role: 'VIP', defaultType: 'faculty', defaultCount: 3 },
  { role: 'Sponsor(s)', defaultType: 'staff', defaultCount: 2 },
  { role: 'Students\' Audience', defaultType: 'student', defaultCount: 50 }
];

let isBulkRolesTableRendered = false;

function renderBulkRolesTable() {
  const tbody = document.getElementById('bulkRolesTableBody');
  if (!tbody) return;
  tbody.innerHTML = '';

  STANDARD_EVENT_ROLES.forEach((item, idx) => {
    const tr = document.createElement('tr');
    tr.className = 'bulk-role-row';
    tr.dataset.role = item.role;
    tr.style.borderBottom = '1px solid #e2e8f0';
    tr.innerHTML = `
      <td style="padding:8px 12px; text-align:center;">
        <input type="checkbox" class="bulk-role-cb" id="bulk_cb_${idx}" onchange="handleBulkRoleCheckboxChange(this)">
      </td>
      <td style="padding:8px 12px;">
        <label for="bulk_cb_${idx}" style="cursor:pointer; font-weight:600; color:#1e293b; display:block; margin:0;">${item.role}</label>
      </td>
      <td style="padding:6px 12px;">
        <select class="form-control bulk-role-type" style="padding:4px 8px; font-size:0.8rem; height:32px; border-radius:4px;">
          <option value="student" ${item.defaultType === 'student' ? 'selected' : ''}>Student</option>
          <option value="faculty" ${item.defaultType === 'faculty' ? 'selected' : ''}>Faculty</option>
          <option value="staff" ${item.defaultType === 'staff' ? 'selected' : ''}>Staff</option>
        </select>
      </td>
      <td style="padding:6px 12px; text-align:center;">
        <input type="number" class="form-control bulk-role-count" value="${item.defaultCount}" min="1" step="1" oninput="updateBulkRoleCounters()" style="padding:4px 8px; font-size:0.8rem; height:32px; text-align:center; font-weight:600; border-radius:4px; max-width:90px; margin:0 auto;">
      </td>
    `;
    tbody.appendChild(tr);
  });
  isBulkRolesTableRendered = true;
  updateBulkRoleCounters();
}

function handleBulkRoleCheckboxChange(cb) {
  const tr = cb.closest('tr');
  if (tr) {
    if (cb.checked) {
      tr.style.backgroundColor = '#f0f9ff';
    } else {
      tr.style.backgroundColor = '';
    }
  }
  updateBulkRoleCounters();
}

function openBulkAddCommitteesModal() {
  const modal = document.getElementById('bulkAddCommitteesModal');
  if (modal) {
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    if (!isBulkRolesTableRendered) {
      renderBulkRolesTable();
    }
    const search = document.getElementById('bulkRoleSearch');
    if (search) {
      search.value = '';
      filterBulkRoles('');
      setTimeout(() => search.focus(), 80);
    }
  }
}

function closeBulkAddCommitteesModal() {
  const modal = document.getElementById('bulkAddCommitteesModal');
  if (modal) {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }
}

function filterBulkRoles(query) {
  const q = (query || '').toLowerCase().trim();
  const rows = document.querySelectorAll('#bulkRolesTableBody tr.bulk-role-row');
  rows.forEach(tr => {
    const roleName = (tr.dataset.role || '').toLowerCase();
    if (!q || roleName.includes(q)) {
      tr.style.display = '';
    } else {
      tr.style.display = 'none';
    }
  });
}

function toggleAllBulkRoles(checked) {
  const headerCb = document.getElementById('bulkSelectAllHeader');
  if (headerCb) headerCb.checked = checked;
  const rows = document.querySelectorAll('#bulkRolesTableBody tr.bulk-role-row');
  rows.forEach(tr => {
    if (tr.style.display !== 'none') {
      const cb = tr.querySelector('.bulk-role-cb');
      if (cb) {
        cb.checked = checked;
        tr.style.backgroundColor = checked ? '#f0f9ff' : '';
      }
    }
  });
  updateBulkRoleCounters();
}

function updateBulkRoleCounters() {
  const cbs = document.querySelectorAll('#bulkRolesTableBody .bulk-role-cb:checked');
  let totalRoles = cbs.length;
  let totalMembers = 0;

  cbs.forEach(cb => {
    const tr = cb.closest('tr');
    if (tr) {
      const countInput = tr.querySelector('.bulk-role-count');
      const countVal = parseInt(countInput?.value || '1', 10) || 1;
      totalMembers += countVal;
    }
  });

  const selCountEl = document.getElementById('bulkSelectedCount');
  const totCountEl = document.getElementById('bulkTotalMembersCount');
  if (selCountEl) selCountEl.textContent = totalRoles;
  if (totCountEl) totCountEl.textContent = totalMembers;
}

function processBulkAddCommittees() {
  const cbs = document.querySelectorAll('#bulkRolesTableBody .bulk-role-cb:checked');
  if (cbs.length === 0) {
    alert('Please check at least one role/committee to insert.');
    return;
  }

  const itemsToAdd = [];
  cbs.forEach(cb => {
    const tr = cb.closest('tr');
    if (!tr) return;
    const role = tr.dataset.role;
    const typeSel = tr.querySelector('.bulk-role-type');
    const countInp = tr.querySelector('.bulk-role-count');
    const type = typeSel ? typeSel.value : 'student';
    const count = parseInt(countInp?.value || '1', 10) || 1;
    if (role) {
      itemsToAdd.push({ role, type, count });
    }
  });

  const tbody = document.getElementById('manpower-body');
  if (!tbody) return;

  const existingRows = tbody.querySelectorAll('tr');
  let isFirstRowEmpty = false;

  if (existingRows.length === 1) {
    const personInp = existingRows[0].querySelector('input.mp-person-field');
    const personVal = (personInp?.value || '').trim();
    if (personVal === '') {
      isFirstRowEmpty = true;
    }
  }

  itemsToAdd.forEach((item, idx) => {
    let targetRow = null;
    if (idx === 0 && isFirstRowEmpty) {
      targetRow = existingRows[0];
    } else {
      if (typeof addRow === 'function') {
        addRow('manpower');
        const rowsNow = tbody.querySelectorAll('tr');
        targetRow = rowsNow[rowsNow.length - 1];
      }
    }

    if (targetRow) {
      const roleSel = targetRow.querySelector('select[name="mp_role[]"]');
      const typeSel = targetRow.querySelector('select[name="mp_type[]"]');
      const countInp = targetRow.querySelector('input[name="mp_member_count[]"]');

      if (roleSel) {
        for (let opt of roleSel.options) {
          if (opt.value.toLowerCase() === item.role.toLowerCase() || opt.value.toLowerCase().includes(item.role.toLowerCase())) {
            roleSel.value = opt.value;
            break;
          }
        }
      }
      if (typeSel && item.type) {
        typeSel.value = item.type;
      }
      if (countInp) {
        countInp.value = item.count;
      }
    }
  });

  if (typeof updateManpowerSummary === 'function') {
    updateManpowerSummary();
  }
  if (typeof refreshEncodedOrganizerDropdowns === 'function') {
    refreshEncodedOrganizerDropdowns();
  }

  closeBulkAddCommitteesModal();

  const panel5 = document.getElementById('panel-5');
  if (panel5) {
    panel5.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

function handleTimelinePillClick(el, e) {
  if (e) e.stopPropagation();
  const raw = el.getAttribute('data-timeline-item');
  if (!raw) return;
  try {
    const data = JSON.parse(raw);
    if (data.itemType === 'task') {
      data.onEdit = function() {
        editFacultyTaskFromCalendar(data.rowIndex);
      };
      data.onDelete = function() {
        deleteFacultyTaskDirect(data.rowIndex);
      };
    } else {
      data.onEdit = function() {
        editScheduleEventFromCalendar(data.rowIndex);
      };
      data.onDelete = function() {
        deleteScheduleEventDirect(data.rowIndex);
      };
    }
    if (typeof openGCalPopup === 'function') {
      openGCalPopup(el, data);
    }
  } catch (err) {
    console.error('Error opening timeline event details:', err);
  }
}

window.handleTimelinePillClick = handleTimelinePillClick;
window.openBulkAddCommitteesModal = openBulkAddCommitteesModal;
window.closeBulkAddCommitteesModal = closeBulkAddCommitteesModal;
window.filterBulkRoles = filterBulkRoles;
window.toggleAllBulkRoles = toggleAllBulkRoles;
window.updateBulkRoleCounters = updateBulkRoleCounters;
window.processBulkAddCommittees = processBulkAddCommittees;
window.openAddScheduleEventDialog = openAddScheduleEventDialog;
window.editScheduleEventFromCalendar = editScheduleEventFromCalendar;
window.editFacultyTaskFromCalendar = editFacultyTaskFromCalendar;
window.closeScheduleEventDialog = closeScheduleEventDialog;
window.saveScheduleEventFromDialog = saveScheduleEventFromDialog;
window.deleteScheduleEventFromDialog = deleteScheduleEventFromDialog;
window.deleteScheduleEventDirect = deleteScheduleEventDirect;
window.deleteFacultyTaskDirect = deleteFacultyTaskDirect;
window.renderScheduleAgendaList = renderScheduleAgendaList;
window.setScheduleViewMode = setScheduleViewMode;
window.setScheduleCalendarFilter = setScheduleCalendarFilter;
window.setSchedModalType = setSchedModalType;
window.populateSchedModalFacultyOptions = populateSchedModalFacultyOptions;
window.syncSchedModalTaskName = syncSchedModalTaskName;
window.shiftScheduleCalendarMonth = shiftScheduleCalendarMonth;
window.renderScheduleTimelineCalendar = renderScheduleTimelineCalendar;
</script>
<!-- Unified Google Calendar Floating Popup Script for Proposal Pages -->
<script src="<?= BASE_URL ?>/assets/js/gcal-popup.js?v=<?= time() ?>"></script>
