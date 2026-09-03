<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>New Proposal – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    .wizard-panel {
      display: none;
    }

    .wizard-panel.active {
      display: block;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }
    .spinner-border {
      display: inline-block;
      width: 1rem;
      height: 1rem;
      vertical-align: text-bottom;
      border: 0.2em solid currentColor;
      border-right-color: transparent;
      border-radius: 50%;
      animation: spin .75s linear infinite;
    }

    /* Modal styling overrides to support Bootstrap 5 Modals without full Bootstrap CSS */
    .modal {
      position: fixed;
      top: 0;
      left: 0;
      z-index: 1055;
      display: none;
      width: 100%;
      height: 100%;
      overflow-x: hidden;
      overflow-y: auto;
      outline: 0;
      background: rgba(0, 0, 0, 0.5);
    }
    .modal.fade {
      transition: opacity 0.15s linear;
    }
    .modal.show {
      display: block !important;
    }
    .modal-dialog {
      position: relative;
      width: auto;
      margin: 0.5rem;
      pointer-events: none;
    }
    .modal-dialog-centered {
      display: flex;
      align-items: center;
      min-height: calc(100% - 1rem);
    }
    @media (min-width: 576px) {
      .modal-dialog {
        max-width: 500px;
        margin: 1.75rem auto;
      }
      .modal-dialog-centered {
        min-height: calc(100% - 3.5rem);
      }
    }
    @media (min-width: 992px) {
      .modal-lg {
        max-width: 800px;
      }
    }
    .modal-content {
      position: relative;
      display: flex;
      flex-direction: column;
      width: 100%;
      pointer-events: auto;
      background-color: #fff;
      background-clip: padding-box;
      border: 1px solid rgba(0,0,0,.2);
      border-radius: 0.5rem;
      outline: 0;
    }
    .modal-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      z-index: 1050;
      width: 100vw;
      height: 100vh;
      background-color: #000;
    }
    .modal-backdrop.fade {
      opacity: 0;
    }
    .modal-backdrop.show {
      opacity: 0.5;
    }

    .nav-btns {
      display: flex;
      justify-content: space-between;
      margin-top: 24px;
    }

    /* ── Validation styles ── */
    .form-control.is-invalid {
      border-color: #dc3545 !important;
      box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.15);
    }

    .field-error {
      color: #dc3545;
      font-size: .75rem;
      margin-top: 4px;
      display: none;
    }

    .field-error.visible {
      display: block;
    }

    .step-error-banner {
      display: none;
      background: #fff3cd;
      border: 1px solid #ffc107;
      border-radius: 6px;
      padding: 10px 14px;
      margin-bottom: 16px;
      color: #664d03;
      font-size: .85rem;
    }

    .step-error-banner.visible {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* ── Wizard step locked state ── */
    .wizard-step.locked {
      opacity: 0.45;
      cursor: not-allowed;
      pointer-events: none;
    }

    /* ── Materials total ── */
    .materials-total-row {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 12px;
      margin-top: 12px;
      padding: 10px 12px;
      background: var(--bg-subtle, #f8f9fa);
      border-radius: 6px;
      border: 1px solid var(--border, #dee2e6);
    }

    .materials-total-row label {
      font-weight: 600;
      font-size: .9rem;
      color: var(--text-muted, #6c757d);
    }

    .materials-total-row span {
      font-weight: 700;
      font-size: 1.05rem;
      color: var(--text, #212529);
      min-width: 100px;
      text-align: right;
    }

    .flex {
      display: flex;
    }

    .gap-2 {
      gap: 8px;
    }

    .mt-4 {
      margin-top: 16px;
    }

    /* ── Evaluation Tool styles ── */
    .eval-question-row {
      display: grid;
      grid-template-areas:
        "qtext qtext"
        "qtype qcat"
        "qdel qdel";
      grid-template-columns: 130px 1fr;
      gap: 10px;
      align-items: center;
      background: var(--bg-base, #ffffff);
      padding: 12px;
      border: 1px solid var(--border, #dee2e6);
      border-radius: 8px;
      transition: all 0.2s ease;
    }
    .eval-question-row:hover {
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
      border-color: #0284c7;
    }
    .eval-question-row .eval-question-text {
      grid-area: qtext;
      width: 100%;
    }
    .eval-question-row .eval-question-type {
      grid-area: qtype;
      width: 100%;
      min-width: 110px;
    }
    .eval-question-row .eval-question-category {
      grid-area: qcat;
      width: 100%;
      min-width: 140px;
    }
    .eval-question-row .form-control:focus {
      border-color: #0284c7 !important;
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15) !important;
    }
    .eval-question-row .form-control.is-invalid {
      border-color: #dc3545 !important;
      box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.15) !important;
    }
    .eval-question-row .eval-question-delete {
      grid-area: qdel;
      display: flex;
      justify-content: flex-end;
      width: 100%;
    }
    .eval-delete-btn {
      color: #dc3545;
      background: none;
      border: 1px solid #ffcccc;
      font-size: 0.85rem;
      cursor: pointer;
      padding: 6px 12px;
      border-radius: 6px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background-color 0.2s, border-color 0.2s;
    }
    .eval-delete-btn:hover {
      background-color: #ffe8e8;
      border-color: #ffb3b3;
    }
    .eval-delete-btn .delete-icon {
      font-size: 1rem;
    }
    .eval-delete-btn .delete-text {
      display: inline;
    }

    .proposal-layout-grid {
      display: grid;
      grid-template-columns: 1.8fr 1.2fr;
      gap: 24px;
      align-items: start;
    }

    @media (min-width: 1400px) {
      .eval-question-row {
        grid-template-areas: "qtext qtype qcat qdel";
        grid-template-columns: 1fr 120px 240px auto;
      }
      .eval-question-row .eval-question-delete {
        width: auto;
      }
      .eval-delete-btn {
        padding: 6px 8px;
        border: none;
      }
      .eval-delete-btn:hover {
        background-color: #ffe8e8;
      }
      .eval-delete-btn .delete-text {
        display: none;
      }
    }
    @media (max-width: 1200px) {
      .proposal-layout-grid {
        grid-template-columns: 1fr;
      }
    }
    .proposal-eval-side-panel {
      position: sticky;
      top: 90px;
      max-height: calc(100vh - 120px);
      display: flex;
      flex-direction: column;
    }
    .proposal-eval-side-panel .card {
      display: flex;
      flex-direction: column;
      height: 100%;
    }
    .proposal-eval-side-panel .card-body {
      overflow-y: auto;
      flex: 1;
    }
    </style>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/floorplan.css">
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">New Activity Proposal</div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/faculty/dashboard.php" class="btn btn-outline btn-sm">← Back</a>
      </div>
    </header>
    <div class="content">
      <?php if (isset($_GET['error']) && $_GET['error'] === 'conflict'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Schedule Conflict:</strong> The selected venue is already booked for an approved activity at this date and time. Please check and try again.
        </div>
      <?php endif; ?>

      <!-- Wizard Steps -->
      <div class="wizard-steps" id="wizardSteps">
        <div class="wizard-step active" data-step="1">
          <div class="step-num">1</div>Event Details
        </div>
        <div class="wizard-step locked" data-step="2">
          <div class="step-num">2</div>Objectives
        </div>
        <div class="wizard-step locked" data-step="3">
          <div class="step-num">3</div>Materials
        </div>
        <div class="wizard-step locked" data-step="4">
          <div class="step-num">4</div>Floor Plan
        </div>
        <div class="wizard-step locked" data-step="5">
          <div class="step-num">5</div>People
        </div>
        <div class="wizard-step locked" data-step="6">
          <div class="step-num">6</div>Schedule
        </div>
        <div class="wizard-step locked" data-step="7">
          <div class="step-num">7</div>Guidelines
        </div>
        <div class="wizard-step locked" data-step="8">
          <div class="step-num">8</div>Faculty Tasks
        </div>
        <div class="wizard-step locked" data-step="9">
          <div class="step-num">9</div>KPI
        </div>
      </div>

      <form id="proposalForm" method="POST" action="<?= BASE_URL ?>/api/proposal-save.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="faculty_id" value="<?= $user['id'] ?>">
        <!-- tracks the highest step the user has validly reached -->
        <input type="hidden" id="maxUnlockedStep" value="1">

        <div class="proposal-layout-grid">
          <div class="proposal-wizard-container">

        <!-- ── STEP 1: Event Details ── -->
        <div class="wizard-panel active" id="panel-1">
          <div class="card">
            <div class="card-header">
              <h2>📄 Event Proposal</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-1">⚠️ Please fill in all required fields before continuing.</div>
              <div class="form-grid">

                <div class="form-group col-span-2">
                  <label class="form-label">Event Name / Title <span class="text-danger">*</span></label>
                  <input type="text" name="title" id="f1_title" class="form-control" placeholder="e.g. Foundation Day Celebration 2025">
                  <span class="field-error" id="e_title">Event title is required.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">Date of Event <span class="text-danger">*</span></label>
                  <input type="date" name="event_date" id="f1_event_date" class="form-control">
                  <span class="field-error" id="e_event_date">Event date is required.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">Source <span class="text-danger">*</span></label>
                  <select name="source" id="f1_source" class="form-control">
                    <option value="">Select source...</option>
                    <option value="student_org">Student Organization</option>
                    <option value="faculty">Faculty</option>
                  </select>
                  <span class="field-error" id="e_source">Please select a source.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">Start Time <span class="text-danger">*</span></label>
                  <input type="time" name="start_time" id="f1_start_time" class="form-control">
                  <span class="field-error" id="e_start_time">Start time is required.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">End Time <span class="text-danger">*</span></label>
                  <input type="time" name="end_time" id="f1_end_time" class="form-control">
                  <span class="field-error" id="e_end_time">End time is required.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">Venue <span class="text-danger">*</span></label>
                  <input type="text" name="venue" id="f1_venue" class="form-control" placeholder="e.g. STI Marikina Auditorium">
                  <span class="field-error" id="e_venue">Venue is required.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">Target Participants <span class="text-danger">*</span></label>
                  <input type="number" name="target_participants" id="f1_target_participants" class="form-control" placeholder="e.g. 200" min="1">
                  <span class="field-error" id="e_target_participants">Target participants is required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Venue Address <span class="text-danger">*</span></label>
                  <input type="text" name="venue_address" id="f1_venue_address" class="form-control" placeholder="Full address">
                  <span class="field-error" id="e_venue_address">Venue address is required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Theme <span class="text-danger">*</span></label>
                  <input type="text" name="theme" id="f1_theme" class="form-control" placeholder="e.g. Igniting Excellence, Empowering Tomorrow">
                  <span class="field-error" id="e_theme">Theme is required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Event Poster <span class="text-danger">*</span></label>
                  <input type="file" name="poster_file" id="f1_poster_file" class="form-control" accept="image/*" required>
                  <span class="field-error" id="e_poster_file">Event poster is required.</span>
                  <small style="color: var(--text-muted);">Upload a poster for this event.</small>
                </div>
                </div>
            </div>
          </div>
          <div class="nav-btns">
            <div></div>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Objectives →</button>
          </div>
        </div>

        <!-- ── STEP 2: Objectives & Academic Alignment ── -->
        <div class="wizard-panel" id="panel-2">
          <div class="card">
            <div class="card-header">
              <h2>🎯 Objectives & Academic Alignment</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-2">⚠️ Please fill in all required fields before continuing.</div>

              <!-- AI Generation Status -->
              <div id="objGenStatus" style="display:none;margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:.875rem;border:1px solid transparent;"></div>

              <div class="form-grid">
                <div class="form-group col-span-2">
                  <label class="form-label">General Objectives <span class="text-danger">*</span></label>
                  <textarea name="general_objectives" id="f2_general_objectives" class="form-control" rows="4" placeholder="State the broad goals of this activity..."></textarea>
                  <span class="field-error" id="e_general_objectives">General objectives are required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Specific Objectives <span class="text-danger">*</span></label>
                  <textarea name="specific_objectives" id="f2_specific_objectives" class="form-control" rows="6" placeholder="List measurable specific goals..."></textarea>
                  <span class="field-error" id="e_specific_objectives">Specific objectives are required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Involved Subjects <span class="text-danger">*</span></label>
                  <input type="text" name="involved_subjects" id="f2_involved_subjects" class="form-control" placeholder="e.g. ITE314, GE102">
                  <span class="field-error" id="e_involved_subjects">Involved subjects are required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Rationale <span class="text-danger">*</span></label>
                  <textarea name="rationale" id="f2_rationale" class="form-control" rows="3" placeholder="Why is this activity necessary?"></textarea>
                  <span class="field-error" id="e_rationale">Rationale is required.</span>
                </div>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Materials →</button>
          </div>
        </div>



        <!-- ── STEP 3: Materials ── -->
        <div class="wizard-panel" id="panel-3">
          <div class="card">
            <div class="card-header">
              <h2>📄 Materials Needed</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('materials')">+ Add Item</button>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-2">⚠️ Please fill in all material fields before continuing.</div>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Item Name <span class="text-danger">*</span></th>
                      <th>Description / Type <span class="text-danger">*</span></th>
                      <th>Quantity <span class="text-danger">*</span></th>
                      <th>Provider / Supplier <span class="text-danger">*</span></th>
                      <th>Est. Cost (₱) <span class="text-danger">*</span></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="materials-body">
                    <tr>
                      <td><input type="text" name="mat_item[]" class="form-control mat-field" placeholder="e.g. Chairs"></td>
                      <td><input type="text" name="mat_desc[]" class="form-control mat-field" placeholder="Plastic monobloc"></td>
                      <td><input type="number" name="mat_qty[]" class="form-control mat-qty" value="" min="1" placeholder="1"></td>
                      <td><input type="text" name="mat_provider[]" class="form-control mat-field" placeholder="Supplier name"></td>
                      <td><input type="number" name="mat_cost[]" class="form-control mat-cost" placeholder="0.00" step="0.01" min="0"></td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <!-- Total cost display -->
              <div class="materials-total-row">
                <label>Total Estimated Cost:</label>
                <span id="materials-total">₱ 0.00</span>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Floor Plan →</button>
          </div>
        </div>

        <!-- ── STEP 4: Floor Plan ── -->
        <div class="wizard-panel" id="panel-4">
          <div class="card">
            <div class="card-header">
              <h2>🗺️ Floor Plan Layout</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-3">⚠️ Please draw at least one element on the floor plan and fill in the description before continuing.</div>

              <?php include __DIR__ . '/../includes/floorplan-ui.php'; ?>

              <div class="form-group" style="margin-top:16px;">
                <label class="form-label">Floor Plan Notes / Description <span class="text-danger">*</span></label>
                <textarea name="floor_plan_notes" id="f3_floor_plan_notes" class="form-control" rows="3" placeholder="Describe the layout — exits, important areas, stage direction, seating arrangement..."></textarea>
                <span class="field-error" id="e_floor_plan_notes">Floor plan notes are required.</span>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next: People →</button>
          </div>
        </div>

        <!-- ── STEP 5: People / Manpower ── -->
        <div class="wizard-panel" id="panel-5">
          <div class="card">
            <div class="card-header">
              <h2>📄 People / Manpower</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('manpower')">+ Add Person</button>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-5">⚠️ Please fill in all manpower fields before continuing.</div>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Role / Position <span class="text-danger">*</span></th>
                      <th>Assigned Person <span class="text-danger">*</span></th>
                      <th>Type <span class="text-danger">*</span></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="manpower-body">
                    <tr>
                      <td>
                        <select name="mp_role[]" class="form-control">
                          <option>Medic</option>
                          <option>Customer Service</option>
                          <option>Usher</option>
                          <option>Marshall</option>
                          <option>Emcee</option>
                          <option>Floor Director</option>
                          <option>AV Support</option>
                          <option>Tabulator</option>
                          <option>Other</option>
                        </select>
                      </td>
                      <td><input type="text" name="mp_person[]" class="form-control mp-person-field" placeholder="Full name"></td>
                      <td>
                        <select name="mp_type[]" class="form-control">
                          <option value="faculty">Faculty</option>
                          <option value="staff">Staff</option>
                          <option value="student">Student</option>
                        </select>
                      </td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Schedule →</button>
          </div>
        </div>

        <!-- ── STEP 6: Schedule & Program ── -->
        <div class="wizard-panel" id="panel-6">
          <div class="card" style="margin-bottom: 20px;">
            <div class="card-header">
              <h2>📄 Activity Timeline (Schedule)</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('schedule')">+ Add Schedule</button>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-6">⚠️ Please fill in all schedule and program fields before continuing.</div>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Date <span class="text-danger">*</span></th>
                      <th>Event Name <span class="text-danger">*</span></th>
                      <th>Venue <span class="text-danger">*</span></th>
                      <th>Organizer / Responsible <span class="text-danger">*</span></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="schedule-body">
                    <tr>
                      <td><input type="date" name="sched_date[]" class="form-control sched-field"></td>
                      <td><input type="text" name="sched_event[]" class="form-control sched-field" placeholder="Event name"></td>
                      <td><input type="text" name="sched_venue[]" class="form-control sched-field" placeholder="Venue"></td>
                      <td><input type="text" name="sched_organizer[]" class="form-control sched-field" placeholder="Name"></td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <h2>📄 Program Flow</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('program')">+ Add Segment</button>
            </div>
            <div class="card-body">
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Time <span class="text-danger">*</span></th>
                      <th>Activity / Segment <span class="text-danger">*</span></th>
                      <th>Description <span class="text-danger">*</span></th>
                      <th>Person In-Charge <span class="text-danger">*</span></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="program-body">
                    <tr>
                      <td><input type="time" name="prog_time[]" class="form-control prog-field"></td>
                      <td><input type="text" name="prog_segment[]" class="form-control prog-field" placeholder="e.g. Invocation"></td>
                      <td><input type="text" name="prog_desc[]" class="form-control prog-field" placeholder="Description"></td>
                      <td><input type="text" name="prog_pic[]" class="form-control prog-field" placeholder="Name"></td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Guidelines →</button>
          </div>
        </div>

        <!-- ── STEP 7: Guidelines ── -->
        <div class="wizard-panel" id="panel-7">
          <div class="card">
            <div class="card-header">
              <h2>📄 Event Guidelines</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-7">⚠️ Please fill in all guideline fields before continuing.</div>
              <div class="form-group">
                <label class="form-label">Mechanics / Guidelines <span class="text-danger">*</span></label>
                <textarea name="guidelines_mechanics" id="f7_mechanics" class="form-control" style="min-height:120px" placeholder="Describe event rules and procedures..."></textarea>
                <span class="field-error" id="e_mechanics">Mechanics / guidelines are required.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Criteria for Judging <span class="text-danger">*</span></label>
                <textarea name="guidelines_criteria" id="f7_criteria" class="form-control" placeholder="List judging criteria..."></textarea>
                <span class="field-error" id="e_criteria">Criteria for judging are required.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Scoring System <span class="text-danger">*</span></label>
                <textarea name="guidelines_scoring" id="f7_scoring" class="form-control" placeholder="Describe scoring system..."></textarea>
                <span class="field-error" id="e_scoring">Scoring system is required.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Special Awards <span class="text-danger">*</span></label>
                <textarea name="guidelines_awards" id="f7_awards" class="form-control" placeholder="List any special awards..."></textarea>
                <span class="field-error" id="e_awards">Special awards field is required.</span>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Faculty Tasks →</button>
          </div>
        </div>

        <!-- ── STEP 8: Faculty Tasks ── -->
        <div class="wizard-panel" id="panel-8">
          <div class="card">
            <div class="card-header">
              <h2>📄 Faculty Tasks & Contributions</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('faculty-tasks')">+ Add Faculty</button>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-8">⚠️ Please fill in all faculty task fields before continuing.</div>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Faculty Name <span class="text-danger">*</span></th>
                      <th>Assigned Task <span class="text-danger">*</span></th>
                      <th>Contribution <span class="text-danger">*</span></th>
                      <th>Role in Event <span class="text-danger">*</span></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="faculty-tasks-body">
                    <tr>
                      <td><input type="text" name="ft_name[]" class="form-control ft-field" placeholder="Full name"></td>
                      <td><input type="text" name="ft_task[]" class="form-control ft-field" placeholder="Assigned task"></td>
                      <td><input type="text" name="ft_contribution[]" class="form-control ft-field" placeholder="Contribution description"></td>
                      <td><input type="text" name="ft_role[]" class="form-control ft-field" placeholder="Role"></td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next: KPI →</button>
          </div>
        </div>

        <!-- ── STEP 9: KPI ── -->
        <div class="wizard-panel" id="panel-9">
          <div class="card">
            <div class="card-header">
              <h2>📄 KPI – Key Performance Indicators</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-9">⚠️ Please fill in all KPI fields before submitting.</div>
              <div class="alert alert-info">
                Define the KPI criteria for this activity. Ratings will be collected automatically from students via a QR code or evaluation link after the event.
                <br><strong>Rating Scale:</strong> 4 – Excellent &nbsp;|&nbsp; 3 – Very Satisfactory &nbsp;|&nbsp; 2 – Satisfactory &nbsp;|&nbsp; 1 – Needs Improvement
              </div>
              <div id="kpi-criteria">
                <div class="form-grid" style="margin-bottom:16px;">
                  <div class="form-group">
                    <label class="form-label">KPI Criteria <span class="text-danger">*</span></label>
                    <input type="text" name="kpi_criteria[]" class="form-control kpi-field" placeholder="e.g. Event Organization">
                  </div>
                  <div class="form-group">
                    <label class="form-label">Target Rating</label>
                    <select name="kpi_rating[]" class="form-control">
                      <option value="4">4 – Excellent</option>
                      <option value="3" selected>3 – Very Satisfactory</option>
                      <option value="2">2 – Satisfactory</option>
                      <option value="1">1 – Needs Improvement</option>
                    </select>
                  </div>
                </div>
              </div>
              <button type="button" class="btn btn-outline btn-sm" onclick="addKPI()">+ Add KPI Criterion</button>
              <div class="form-group mt-4">
                <label class="form-label">Evaluation Form Link / QR Code Source <span class="text-danger">*</span></label>
                <input type="url" name="eval_form_link" id="f9_eval_form_link" class="form-control" placeholder="https://forms.google.com/...">
                <span class="field-error" id="e_eval_form_link">A valid evaluation form link is required.</span>
                <small style="color:var(--text-muted);font-size:.75rem;margin-top:4px;display:block;">Students will scan a QR code or use this link to submit their evaluation after the event.</small>
              </div>

              <!-- ── SCHEDULE CONFLICT BANNER (Step 9 review) ── -->
              <div id="scheduleConflictBannerStep9" style="display:none; margin-top:16px; padding:16px 20px; border-radius:10px; background:linear-gradient(135deg,#fff5f5 0%,#fed7d7 100%); border:1px solid #feb2b2; border-left:6px solid #e53e3e; color:#742a2a;">
                <div class="scu-heading" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;"></div>
                <p class="scu-msg" style="font-size:.875rem;margin-bottom:10px;line-height:1.4;font-weight:500;"></p>
                <div class="scu-alt-wrap" style="display:none;margin-bottom:10px;">
                  <div style="font-size:.85rem;font-weight:700;margin-bottom:8px;color:#9b2c2c;">&#x1F916; AI-Suggested Alternative Slots:</div>
                  <div class="scu-alt-list" style="display:flex;flex-direction:column;gap:8px;"></div>
                </div>
                <div class="scu-advisory-wrap" style="display:none;margin-bottom:10px;"></div>
                <div class="scu-debug-wrap" style="display:none;"></div>
                <div class="scu-footer" style="font-size:.75rem;font-style:italic;color:inherit;border-top:1px dashed rgba(0,0,0,.15);padding-top:8px;">* Submission is blocked only when a real venue conflict is detected.</div>
              </div>

            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div class="flex gap-2">
              <button type="submit" name="action" value="draft" class="btn btn-outline" formnovalidate onclick="serializeEvaluationQuestions()">💾 Save as Draft</button>
              <button type="submit" name="action" value="submit" class="btn btn-primary" id="submitBtn" onclick="serializeEvaluationQuestions()">🚀 Submit for Approval</button>
            </div>
          </div>
        </div>

          </div> <!-- Close proposal-wizard-container -->
          
          <div class="proposal-eval-side-panel">
            <style>
              .eval-question-card {
                background: #ffffff;
                border: 1px solid var(--border-color, #e2e8f0);
                border-radius: 8px;
                padding: 12px;
                transition: all 0.2s ease;
                box-shadow: 0 1px 3px rgba(0,0,0,0.02);
                display: flex;
                flex-direction: column;
                gap: 6px;
                margin-bottom: 10px;
              }
              .eval-question-card:hover {
                box-shadow: 0 3px 8px rgba(0,0,0,0.04);
                border-color: #0284c7;
              }
              .eval-question-card.editing {
                border-color: #0284c7;
                box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
              }
              .badge-type-rating {
                background: #e0f2fe;
                color: #0369a1;
              }
              .badge-type-text {
                background: #f3f4f6;
                color: #4b5563;
              }
              .eval-action-link {
                cursor: pointer;
                transition: color 0.15s ease;
              }
              .eval-action-link:hover {
                text-decoration: underline !important;
              }
              .btn-xs {
                padding: 2px 8px;
                font-size: 0.75rem;
                border-radius: 4px;
              }
              .proposal-eval-side-panel .card-body {
                max-height: calc(100vh - 220px);
                overflow-y: auto;
              }
            </style>
            <div class="card" style="box-shadow: 0 4px 20px rgba(0,0,0,0.08); border-radius: 12px; border: 1px solid var(--border-color, #e5e7eb); overflow: hidden;">
              <div class="card-header" style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 14px 16px; display: flex; justify-content: space-between; align-items: center;">
                <h2 style="font-size: 0.95rem; font-weight: 700; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 8px;">
                  🤖 AI-Generated Evaluation Tool
                </h2>
                <span id="evalStatusBadge" style="font-size: 0.7rem; font-weight: 600; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 12px; display: flex; align-items: center; gap: 4px;">
                  <span id="evalStatusIndicator" style="width: 6px; height: 6px; background: #64748b; border-radius: 50%;"></span> <span id="evalStatusText">Empty</span>
                </span>
              </div>
              <div class="card-body" style="padding: 16px; display: flex; flex-direction: column; gap: 12px;">
                
                <!-- Needs Update Notice -->
                <div id="evalChangeNotice" class="alert alert-warning" style="display:none; flex-direction:column; gap:8px; font-size:0.75rem; padding:10px 12px; margin-bottom:4px; border-radius:6px; border-left: 4px solid #d97706; background: #fffbeb;">
                  <div>⚠️ <strong>Your activity details have changed.</strong> The evaluation questions may need to be updated.</div>
                  <div style="display:flex; gap:8px; margin-top:4px;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="dismissEvalChangeNotice()" style="padding: 2px 8px; font-size: 0.7rem; height:auto; line-height:1.2; background:#fff; border:1px solid #d97706; color:#d97706;">Keep Current</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="regenerateEvaluationWithAi(true)" style="padding: 2px 8px; font-size: 0.7rem; height:auto; line-height:1.2; background: #d97706; border: none; color: white;">Regenerate with AI</button>
                  </div>
                </div>
                
                <div class="step-error-banner" id="err-banner-eval-side" style="display:none; margin-bottom:4px; padding:10px 12px; border-radius:6px; background:#f8d7da; color:#842029; border:1px solid #f5c2c7; font-size:.75rem;">⚠️ Please review and fix evaluation questions.</div>
                
                <!-- AI Generation Loading & Status -->
                <div id="evalGenStatus" style="display:none; margin-bottom:4px; padding:10px 12px; border-radius:6px; font-size:.775rem;"></div>

                <div class="info-alert" style="font-size: 0.775rem; padding: 10px 12px; background: #f0f9ff; border: 1px solid #e0f2fe; color: #0369a1; border-radius: 6px; line-height: 1.45; margin-bottom: 4px;">
                  These evaluation questions are automatically generated based on your activity objectives and KPIs. Review them and make changes if needed.
                </div>

                <!-- Hidden JSON field -->
                <input type="hidden" name="evaluation_questions" id="f10_evaluation_questions">

                <!-- Questions list container -->
                <div id="eval-questions-list" style="display: flex; flex-direction: column; gap: 4px;">
                  <!-- Dynamically populated -->
                </div>

                <button type="button" class="btn btn-outline btn-sm" onclick="addManualQuestion()" style="border: 1px dashed var(--border-color, #cbd5e1); font-size: 0.8rem; padding: 6px 12px; border-radius: 6px; background: #fafafa; font-weight: 600; display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: 100%; height:auto;">
                  + Add Question
                </button>
                
                <div class="footer-actions" style="margin-top: 10px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; gap: 8px;">
                  <button type="button" class="btn btn-outline btn-sm" id="btn-regenerate-eval" onclick="regenerateEvaluationWithAi(true)" style="font-size: 0.75rem; padding: 6px 10px; display: inline-flex; align-items: center; gap: 4px; height:auto; line-height:1.2;">
                    ↻ Regenerate
                  </button>
                  <button type="button" class="btn btn-outline btn-sm" onclick="openPreviewModal()" style="font-size: 0.75rem; padding: 6px 10px; display: inline-flex; align-items: center; gap: 4px; height:auto; line-height:1.2;">
                    👁 Preview Tool
                  </button>
                </div>
              </div>
            </div>
          </div> <!-- Close proposal-eval-side-panel -->
        </div> <!-- Close proposal-layout-grid -->

      </form>
    </div>
  </div>

  <script>
    let currentStep = 1;
    const totalSteps = 9;
    let maxUnlocked = 1; // highest step the user has validly completed up to

    // ── Step Navigation ──────────────────────────────────────────────
    function showStep(n) {
      document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.wizard-step').forEach((s, i) => {
        s.classList.remove('active', 'done');
        if (i + 1 < n) s.classList.add('done');
        if (i + 1 === n) s.classList.add('active');
      });
      document.getElementById('panel-' + n).classList.add('active');
      currentStep = n;
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
      if (n >= 2 && typeof checkAndTriggerEvaluationAutoUpdate === 'function') {
        checkAndTriggerEvaluationAutoUpdate();
      }
    }

    function nextStep() {
      if (!validateStep(currentStep)) return;
      // Unlock the next step in the indicator bar
      if (currentStep + 1 <= totalSteps) {
        const nextIndicator = document.querySelector(`.wizard-step[data-step="${currentStep + 1}"]`);
        if (nextIndicator) nextIndicator.classList.remove('locked');
        if (currentStep + 1 > maxUnlocked) maxUnlocked = currentStep + 1;
      }
      if (currentStep < totalSteps) {
        const goingTo = currentStep + 1;
        showStep(goingTo);
        // Auto-generate objectives when advancing from Step 1 to Step 2
        if (goingTo === 2) generateObjectives(false);
      }
    }

    function prevStep() {
      if (currentStep > 1) showStep(currentStep - 1);
    }

    // ── AI Objectives Generation ───────────────────────────────────────
    /**
     * Calls /api/generate-objectives.php with Step 1 field values and
     * auto-fills the General & Specific Objectives textareas.
     *
     * @param {boolean} forced  When true (Re-generate button), always overwrites.
     *                          When false (auto on Step 1 → 2), skips if both
     *                          fields already have content (e.g. back-nav).
     */
    async function generateObjectives(forced = false) {
      const genEl   = document.getElementById('f2_general_objectives');
      const specEl  = document.getElementById('f2_specific_objectives');
      const statusEl = document.getElementById('objGenStatus');

      // Skip auto-generation if fields already have content (back-navigation scenario)
      if (!forced && genEl.value.trim() && specEl.value.trim()) return;

      // Collect Step 1 inputs
      const payload = {
        title:               (document.getElementById('f1_title')?.value || '').trim(),
        source:              (document.getElementById('f1_source')?.value || '').trim(),
        target_participants: (document.getElementById('f1_target_participants')?.value || '').trim(),
        theme:               (document.getElementById('f1_theme')?.value || '').trim(),
        involved_subjects:   (document.getElementById('f2_involved_subjects')?.value || '').trim(),
        rationale:           (document.getElementById('f2_rationale')?.value || '').trim()
      };

      if (!payload.title) return; // safety guard

      // Show loading state
      statusEl.style.cssText = 'display:block;margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:.875rem;background:#e7f1ff;color:#084298;border:1px solid #b6d4fe;';
      statusEl.innerHTML = '<span style="display:inline-flex;align-items:center;gap:8px;">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin 1s linear infinite;">' +
        '<path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>' +
        '&#x2728; Generating objectives with AI for <strong>"' + payload.title.replace(/</g,'&lt;') + '"</strong>…' +
        '</span>';

      // Disable textareas while generating
      genEl.disabled  = true;
      specEl.disabled = true;

      try {
        const res  = await fetch('<?= BASE_URL ?>/api/generate-objectives.php', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.success && data.general_objective && data.specific_objectives) {
          genEl.value  = data.general_objective;
          specEl.value = data.specific_objectives;
          if (typeof queueEvaluationAutoUpdate === 'function') queueEvaluationAutoUpdate();

          statusEl.style.cssText = 'display:block;margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:.875rem;background:#d1e7dd;color:#0f5132;border:1px solid #a3cfbb;';
          statusEl.innerHTML = '&#x2705; <strong>Objectives generated.</strong> Review and edit them below as needed.';

          // Auto-hide success notice after 6 seconds
          setTimeout(() => { if (statusEl) statusEl.style.display = 'none'; }, 6000);
        } else {
          throw new Error(data.error || 'Empty response from AI.');
        }
      } catch (err) {
        console.warn('generateObjectives error:', err);
        statusEl.style.cssText = 'display:block;margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:.875rem;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;';
        statusEl.innerHTML = '&#x26A0;&#xFE0F; Could not auto-generate objectives (' + (err.message || 'network error') + '). Please fill them in manually.';
      } finally {
        genEl.disabled  = false;
        specEl.disabled = false;
      }
    }

    // ── CSS for spinner ────────────────────────────────────────────────
    (function() {
      const s = document.createElement('style');
      s.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
      document.head.appendChild(s);
    })();

    // Clicking a step indicator: only allowed if already unlocked
    document.querySelectorAll('.wizard-step').forEach(s => {
      s.addEventListener('click', () => {
        const target = parseInt(s.dataset.step);
        if (s.classList.contains('locked')) return; // blocked
        // Also validate current step before jumping forward
        if (target > currentStep && !validateStep(currentStep)) return;
        showStep(target);
      });
    });

    // ── Step Validators ───────────────────────────────────────────────
    function validateStep(step) {
      let valid = true;
      hideBanner(step);

      if (step === 1) {
        const fields = [{
            id: 'f1_title',
            err: 'e_title'
          },
          {
            id: 'f1_event_date',
            err: 'e_event_date'
          },
          {
            id: 'f1_source',
            err: 'e_source'
          },
          {
            id: 'f1_start_time',
            err: 'e_start_time'
          },
          {
            id: 'f1_end_time',
            err: 'e_end_time'
          },
          {
            id: 'f1_venue',
            err: 'e_venue'
          },
          {
            id: 'f1_target_participants',
            err: 'e_target_participants'
          },
          {
            id: 'f1_venue_address',
            err: 'e_venue_address'
          },
          {
            id: 'f1_theme',
            err: 'e_theme'
          },
          {
            id: 'f1_poster_file',
            err: 'e_poster_file'
          }
        ];
        fields.forEach(f => {
          if (!checkField(f.id, f.err)) valid = false;
        });
      } else if (step === 2) {
        const fields = [{
            id: 'f2_general_objectives',
            err: 'e_general_objectives'
          },
          {
            id: 'f2_specific_objectives',
            err: 'e_specific_objectives'
          },
          {
            id: 'f2_involved_subjects',
            err: 'e_involved_subjects'
          },
          {
            id: 'f2_rationale',
            err: 'e_rationale'
          }
        ];
        fields.forEach(f => {
          if (!checkField(f.id, f.err)) valid = false;
        });
      } else if (step === 3) {
        // Every text/number input in materials rows must be filled
        const rows = document.querySelectorAll('#materials-body tr');
        rows.forEach(row => {
          row.querySelectorAll('input.mat-field, input.mat-qty, input.mat-cost').forEach(inp => {
            if (!inp.value.trim()) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        });
      } else if (step === 4) {
        // Check that at least one shape has been placed
        const canvasData = document.getElementById('fp-canvas-data').value;
        let hasShapes = false;
        try {
          const parsed = JSON.parse(canvasData);
          hasShapes = parsed && parsed.shapes && parsed.shapes.length > 0;
        } catch (e) {
          hasShapes = false;
        }

        const wrap = document.getElementById('fp-canvas-invalid-wrap');
        if (!hasShapes) {
          wrap.classList.add('fp-canvas-invalid');
          valid = false;
        } else {
          wrap.classList.remove('fp-canvas-invalid');
        }

        const notes = document.getElementById('f3_floor_plan_notes');
        if (!notes.value.trim()) {
          notes.classList.add('is-invalid');
          showError('e_floor_plan_notes');
          valid = false;
        } else {
          notes.classList.remove('is-invalid');
          hideError('e_floor_plan_notes');
        }
      } else if (step === 5) {
        const rows = document.querySelectorAll('#manpower-body tr');
        rows.forEach(row => {
          row.querySelectorAll('input.mp-person-field').forEach(inp => {
            if (!inp.value.trim()) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        });
      } else if (step === 6) {
        const schedRows = document.querySelectorAll('#schedule-body tr');
        schedRows.forEach(row => {
          row.querySelectorAll('input.sched-field').forEach(inp => {
            if (!inp.value.trim()) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        });
        const progRows = document.querySelectorAll('#program-body tr');
        progRows.forEach(row => {
          row.querySelectorAll('input.prog-field').forEach(inp => {
            if (!inp.value.trim()) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        });
      } else if (step === 7) {
        [{
            id: 'f7_mechanics',
            err: 'e_mechanics'
          },
          {
            id: 'f7_criteria',
            err: 'e_criteria'
          },
          {
            id: 'f7_scoring',
            err: 'e_scoring'
          },
          {
            id: 'f7_awards',
            err: 'e_awards'
          },
        ].forEach(f => {
          if (!checkField(f.id, f.err)) valid = false;
        });
      } else if (step === 8) {
        const rows = document.querySelectorAll('#faculty-tasks-body tr');
        rows.forEach(row => {
          row.querySelectorAll('input.ft-field').forEach(inp => {
            if (!inp.value.trim()) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        });
      } else if (step === 9) {
        // All KPI criteria inputs
        document.querySelectorAll('input.kpi-field').forEach(inp => {
          if (!inp.value.trim()) {
            inp.classList.add('is-invalid');
            valid = false;
          } else {
            inp.classList.remove('is-invalid');
          }
        });
        // Eval form link
        const evalLink = document.getElementById('f9_eval_form_link');
        if (!evalLink.value.trim() || !isValidUrl(evalLink.value.trim())) {
          evalLink.classList.add('is-invalid');
          showError('e_eval_form_link');
          valid = false;
        } else {
          evalLink.classList.remove('is-invalid');
          hideError('e_eval_form_link');
        }
      } else if (step === 10) {
        // All evaluation question text inputs
        const questionInputs = document.querySelectorAll('#eval-questions-list input[type="text"]');
        if (questionInputs.length === 0) {
          valid = false;
        } else {
          questionInputs.forEach(inp => {
            if (!inp.value.trim()) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        }
      }

      if (!valid) showBanner(step);
      return valid;
    }

    // ── Helpers ───────────────────────────────────────────────────────
    function checkField(fieldId, errId) {
      const el = document.getElementById(fieldId);
      if (!el) return true;
      if (!el.value.trim()) {
        el.classList.add('is-invalid');
        showError(errId);
        return false;
      } else {
        el.classList.remove('is-invalid');
        hideError(errId);
        return true;
      }
    }

    function showError(id) {
      const el = document.getElementById(id);
      if (el) el.classList.add('visible');
    }

    function hideError(id) {
      const el = document.getElementById(id);
      if (el) el.classList.remove('visible');
    }

    function showBanner(step) {
      const b = document.getElementById('err-banner-' + step);
      if (b) b.classList.add('visible');
    }

    function hideBanner(step) {
      const b = document.getElementById('err-banner-' + step);
      if (b) b.classList.remove('visible');
    }

    function isValidUrl(str) {
      try {
        new URL(str);
        return true;
      } catch {
        return false;
      }
    }

    // Clear invalid state on input
    document.addEventListener('input', e => {
      if (e.target.classList.contains('is-invalid') && e.target.value.trim()) {
        e.target.classList.remove('is-invalid');
      }
    });
    document.addEventListener('change', e => {
      if (e.target.classList.contains('is-invalid') && e.target.value.trim()) {
        e.target.classList.remove('is-invalid');
      }
    });

    function validateEvaluationSidePanel() {
      let valid = true;
      const questionInputs = document.querySelectorAll('#eval-questions-list .eval-question-input');
      const banner = document.getElementById('err-banner-eval-side');
      
      questionInputs.forEach(inp => {
        if (!inp.value.trim()) {
          inp.classList.add('is-invalid');
          valid = false;
        } else {
          inp.classList.remove('is-invalid');
        }
      });
      
      if (!valid) {
        if (banner) banner.style.display = 'block';
      } else {
        if (banner) banner.style.display = 'none';
      }
      return valid;
    }

    // ── Form submit validation ────────────────────────────────────────
    document.getElementById('proposalForm').addEventListener('submit', function(e) {
      serializeEvaluationQuestions();
      
      const stepValid = validateStep(currentStep);
      const evalValid = validateEvaluationSidePanel();
      
      if (!stepValid || !evalValid) {
        e.preventDefault();
        if (stepValid && !evalValid) {
          alert('⚠️ Please review the AI-Generated Evaluation Tool questions in the right-side panel before submitting.');
        }
      }
    });

    // ── Dynamic row management ────────────────────────────────────────
    const rowTemplates = {
      materials: `
        <tr>
          <td><input type="text" name="mat_item[]" class="form-control mat-field" placeholder="e.g. Chairs"></td>
          <td><input type="text" name="mat_desc[]" class="form-control mat-field" placeholder="Description"></td>
          <td><input type="number" name="mat_qty[]" class="form-control mat-qty" value="" min="1" placeholder="1"></td>
          <td><input type="text" name="mat_provider[]" class="form-control mat-field" placeholder="Supplier name"></td>
          <td><input type="number" name="mat_cost[]" class="form-control mat-cost" placeholder="0.00" step="0.01" min="0"></td>
          <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
        </tr>`,
      program: `
        <tr>
          <td><input type="time" name="prog_time[]" class="form-control prog-field"></td>
          <td><input type="text" name="prog_segment[]" class="form-control prog-field" placeholder="e.g. Invocation"></td>
          <td><input type="text" name="prog_desc[]" class="form-control prog-field" placeholder="Description"></td>
          <td><input type="text" name="prog_pic[]" class="form-control prog-field" placeholder="Name"></td>
          <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
        </tr>`,
      manpower: `
        <tr>
          <td>
            <select name="mp_role[]" class="form-control">
              <option>Medic</option><option>Customer Service</option><option>Usher</option>
              <option>Marshall</option><option>Emcee</option><option>Floor Director</option>
              <option>AV Support</option><option>Tabulator</option><option>Other</option>
            </select>
          </td>
          <td><input type="text" name="mp_person[]" class="form-control mp-person-field" placeholder="Full name"></td>
          <td>
            <select name="mp_type[]" class="form-control">
              <option value="faculty">Faculty</option>
              <option value="staff">Staff</option>
              <option value="student">Student</option>
            </select>
          </td>
          <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
        </tr>`,
      schedule: `
        <tr>
          <td><input type="date" name="sched_date[]" class="form-control sched-field"></td>
          <td><input type="text" name="sched_event[]" class="form-control sched-field" placeholder="Event name"></td>
          <td><input type="text" name="sched_venue[]" class="form-control sched-field" placeholder="Venue"></td>
          <td><input type="text" name="sched_organizer[]" class="form-control sched-field" placeholder="Name"></td>
          <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
        </tr>`,
      'faculty-tasks': `
        <tr>
          <td><input type="text" name="ft_name[]" class="form-control ft-field" placeholder="Full name"></td>
          <td><input type="text" name="ft_task[]" class="form-control ft-field" placeholder="Assigned task"></td>
          <td><input type="text" name="ft_contribution[]" class="form-control ft-field" placeholder="Contribution description"></td>
          <td><input type="text" name="ft_role[]" class="form-control ft-field" placeholder="Role"></td>
          <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
        </tr>`,
    };

    function addRow(table) {
      const tbody = document.getElementById(table + '-body');
      const tmp = document.createElement('tbody');
      tmp.innerHTML = rowTemplates[table];
      tbody.appendChild(tmp.firstElementChild);
      if (table === 'materials') updateTotal();
    }

    function removeRow(btn) {
      const tr = btn.closest('tr');
      const tbody = tr.parentElement;
      if (tbody.querySelectorAll('tr').length > 1) {
        tr.remove();
        if (tbody.id === 'materials-body') updateTotal();
      }
    }

    // ── Materials Total Cost ──────────────────────────────────────────
    function updateTotal() {
      let total = 0;
      document.querySelectorAll('#materials-body tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.mat-qty')?.value) || 0;
        const cost = parseFloat(row.querySelector('.mat-cost')?.value) || 0;
        total += qty * cost;
      });
      document.getElementById('materials-total').textContent =
        '₱ ' + total.toLocaleString('en-PH', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        });
    }

    // Live update on any qty/cost change inside materials
    document.getElementById('materials-body').addEventListener('input', e => {
      if (e.target.classList.contains('mat-qty') || e.target.classList.contains('mat-cost')) {
        updateTotal();
      }
    });

    // ── KPI ──────────────────────────────────────────────────────────
    function addKPI() {
      const container = document.getElementById('kpi-criteria');
      const div = document.createElement('div');
      div.className = 'form-grid';
      div.style.marginBottom = '16px';
      div.innerHTML = `
        <div class="form-group">
          <label class="form-label">KPI Criteria <span class="text-danger">*</span></label>
          <input type="text" name="kpi_criteria[]" class="form-control kpi-field" placeholder="e.g. Participant Satisfaction">
        </div>
        <div class="form-group">
          <label class="form-label">Target Rating</label>
          <select name="kpi_rating[]" class="form-control">
            <option value="4">4 – Excellent</option>
            <option value="3" selected>3 – Very Satisfactory</option>
            <option value="2">2 – Satisfactory</option>
            <option value="1">1 – Needs Improvement</option>
          </select>
        </div>`;
      container.appendChild(div);
      queueEvaluationAutoUpdate();
    }

    // ── Evaluation Tool ──────────────────────────────────────────────
    let questionsList = []; // Array of { question: "...", type: "rating"|"open_ended", category: "..." }
    let isEvaluationEdited = false;
    let isGeneratingEval = false;
    let lastEvaluationState = '';
    let autoUpdateTimer = null;
    let editingIndex = -1;

    function getObjectivesAndKpisState() {
      const title = (document.querySelector('[name="title"]')?.value || '').trim();
      const rationale = (document.querySelector('[name="rationale"]')?.value || '').trim();
      const genObj = (document.querySelector('[name="general_objectives"]')?.value || '').trim();
      const specObj = (document.querySelector('[name="specific_objectives"]')?.value || '').trim();
      
      const kpis = [];
      const criteriaInputs = document.querySelectorAll('input[name="kpi_criteria[]"], input[name="kpi_indicator[]"]');
      criteriaInputs.forEach(input => {
        if (input.value.trim()) {
          kpis.push(input.value.trim());
        }
      });
      return JSON.stringify({ title, rationale, genObj, specObj, kpis });
    }

    function checkAndTriggerEvaluationAutoUpdate() {
      if (typeof currentStep !== 'undefined' && currentStep === 1) {
        return; // Do not auto-generate while on Step 1
      }
      
      const currentState = getObjectivesAndKpisState();
      const isListEmpty = questionsList.length === 0;
      
      const parsed = JSON.parse(currentState);
      const hasMinData = parsed.title && (parsed.genObj || parsed.specObj || parsed.kpis.length > 0);
      
      if (!hasMinData) {
        setEvaluationState('EMPTY');
        return;
      }
      
      if (isListEmpty) {
        // Initial auto-generation: silent
        lastEvaluationState = currentState;
        regenerateEvaluationWithAi(false);
      } else {
        // Subsequent check
        if (currentState !== lastEvaluationState) {
          setEvaluationState('NEEDS_UPDATE');
        }
      }
    }

    function queueEvaluationAutoUpdate() {
      if (autoUpdateTimer) clearTimeout(autoUpdateTimer);
      autoUpdateTimer = setTimeout(() => {
        checkAndTriggerEvaluationAutoUpdate();
      }, 3000);
    }

    function dismissEvalChangeNotice() {
      const noticeEl = document.getElementById('evalChangeNotice');
      if (noticeEl) noticeEl.style.display = 'none';
      lastEvaluationState = getObjectivesAndKpisState();
      setEvaluationState('READY');
    }

    async function regenerateEvaluationWithAi(forced = false) {
      if (isGeneratingEval) return;

      if (forced && isEvaluationEdited && questionsList.length > 0) {
        if (!confirm("Regenerating will replace your current evaluation questions, including manual changes. Continue?")) {
          return;
        }
      }

      setEvaluationState('GENERATING');
      isGeneratingEval = true;

      // Gather KPIs
      const kpis = [];
      const criteriaInputs = document.querySelectorAll('input[name="kpi_criteria[]"], input[name="kpi_indicator[]"]');
      const ratingSelects = document.querySelectorAll('select[name="kpi_rating[]"], input[name="kpi_target[]"]');
      criteriaInputs.forEach((input, index) => {
        if (input.value.trim()) {
          kpis.push({
            criteria: input.value.trim(),
            rating: ratingSelects[index]?.value || '3'
          });
        }
      });

      const payload = {
        title: (document.querySelector('[name="title"]')?.value || '').trim(),
        source: (document.querySelector('[name="source"]')?.value || '').trim(),
        target_participants: (document.querySelector('[name="target_participants"]')?.value || '').trim(),
        theme: (document.querySelector('[name="theme"]')?.value || '').trim(),
        event_date: (document.querySelector('[name="event_date"]')?.value || '').trim(),
        start_time: (document.querySelector('[name="start_time"]')?.value || '').trim(),
        end_time: (document.querySelector('[name="end_time"]')?.value || '').trim(),
        venue: (document.querySelector('[name="venue"]')?.value || '').trim(),
        venue_address: (document.querySelector('[name="venue_address"]')?.value || '').trim(),
        involved_subjects: (document.querySelector('[name="involved_subjects"]')?.value || '').trim(),
        rationale: (document.querySelector('[name="rationale"]')?.value || '').trim(),
        general_objectives: (document.querySelector('[name="general_objectives"]')?.value || '').trim(),
        specific_objectives: (document.querySelector('[name="specific_objectives"]')?.value || '').trim(),
        kpis: kpis
      };

      try {
        const res = await fetch('<?= BASE_URL ?>/api/generate-evaluation.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        
        const data = await res.json();
        
        if (res.status === 429 || (data && data.error && (data.error.includes('429') || data.error.includes('RESOURCE_EXHAUSTED') || data.error.includes('limit')))) {
          throw new Error("RATE_LIMIT");
        } else if (!res.ok || (data && data.error)) {
          throw new Error(data.error || `API Error: HTTP ${res.status}`);
        }

        if (data.success && Array.isArray(data.questions)) {
          questionsList = data.questions;
          isEvaluationEdited = false;
          editingIndex = -1;
          
          renderQuestions();
          serializeEvaluationQuestions();
          
          setEvaluationState('READY');
          lastEvaluationState = getObjectivesAndKpisState();
        } else {
          throw new Error(data.error || 'Empty response from AI.');
        }
      } catch (err) {
        console.warn('generateEvaluation error:', err);
        let errMsg = err.message || 'Unknown error';
        if (errMsg === 'RATE_LIMIT') {
          errMsg = 'AI service rate limit reached. Please try again.';
        }
        setEvaluationState('ERROR', errMsg);
      } finally {
        isGeneratingEval = false;
      }
    }

    function renderQuestions() {
      const container = document.getElementById('eval-questions-list');
      if (!container) return;
      container.innerHTML = '';
      
      if (questionsList.length === 0) {
        container.innerHTML = '<div class="text-muted" style="text-align:center;font-size:0.8rem;padding:20px 0;">No evaluation questions generated yet.</div>';
        return;
      }
      
      questionsList.forEach((q, index) => {
        const card = document.createElement('div');
        card.className = 'eval-question-card';
        card.dataset.index = index;
        
        const isTextType = q.type === 'open_ended' || q.type === 'text';
        const typeLabel = isTextType ? 'Open-ended' : 'Rating Scale';
        const badgeClass = isTextType ? 'badge-type-text' : 'badge-type-rating';
        
        if (editingIndex === index) {
          card.classList.add('editing');
          card.innerHTML = `
            <div class="form-group" style="margin-bottom:8px; width: 100%;">
              <textarea class="form-control eval-question-input" rows="2" style="font-size: 0.85rem; width: 100%; box-sizing: border-box; resize: vertical;" placeholder="Question Text">${escapeHtml(q.question)}</textarea>
            </div>
            <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; width: 100%;">
              <select class="form-control form-control-sm eval-type-select" style="width:120px; font-size:0.75rem; padding: 2px 4px; height: auto;">
                <option value="rating" ${!isTextType ? 'selected' : ''}>Rating Scale</option>
                <option value="open_ended" ${isTextType ? 'selected' : ''}>Open-ended</option>
              </select>
              <div style="display:flex; gap:6px;">
                <button type="button" class="btn btn-outline btn-xs" onclick="cancelEdit(${index})" style="padding:2px 8px; font-size:0.75rem; border-radius:4px; height:auto; line-height:1.2;">Cancel</button>
                <button type="button" class="btn btn-primary btn-xs" onclick="saveEdit(${index})" style="padding:2px 8px; font-size:0.75rem; border-radius:4px; height:auto; line-height:1.2; background:var(--sti-red, #e30613); border:none; color:white;">Save</button>
              </div>
            </div>
          `;
        } else {
          card.innerHTML = `
            <div class="eval-card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 6px;">
              <span class="eval-question-num" style="font-weight:600; font-size:0.8rem; color:var(--text-muted, #64748b);">Question ${index + 1}</span>
              <span class="badge ${badgeClass}" style="font-size:0.7rem; padding: 2px 6px; border-radius:4px; font-weight:600;">${typeLabel}</span>
            </div>
            <div class="eval-question-body" style="font-size:0.85rem; line-height:1.45; color:var(--text, #1e293b); margin-bottom: 8px; font-weight:500; word-break: break-word;">
              ${escapeHtml(q.question)}
            </div>
            <div class="eval-card-actions" style="display:flex; gap:12px; font-size:0.75rem; border-top: 1px dashed #f1f5f9; padding-top:6px;">
              <a href="javascript:void(0)" class="eval-action-link" onclick="startEdit(${index})" style="color:var(--primary, #0284c7); text-decoration:none; font-weight:600;">Edit</a>
              <a href="javascript:void(0)" class="eval-action-link" onclick="confirmDeleteQuestion(${index})" style="color:#dc3545; text-decoration:none; font-weight:600;">Delete</a>
            </div>
          `;
        }
        container.appendChild(card);
      });
    }

    function setEvaluationState(state, errorMsg = '') {
      const badge = document.getElementById('evalStatusBadge');
      const indicator = document.getElementById('evalStatusIndicator');
      const text = document.getElementById('evalStatusText');
      const statusEl = document.getElementById('evalGenStatus');
      const noticeEl = document.getElementById('evalChangeNotice');
      
      if (!badge || !indicator || !text) return;
      
      if (statusEl) statusEl.style.display = 'none';
      if (noticeEl && state !== 'NEEDS_UPDATE') noticeEl.style.display = 'none';
      
      switch(state) {
        case 'EMPTY':
          text.innerText = 'Empty';
          badge.style.background = '#f1f5f9';
          badge.style.color = '#64748b';
          indicator.style.background = '#64748b';
          if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.style.cssText = 'padding:10px; font-size:0.75rem; border-radius:6px; background:#f0f9ff; color:#0369a1; border:1px solid #e0f2fe; margin-bottom:10px;';
            statusEl.innerText = 'Complete your activity objectives to generate evaluation questions.';
          }
          break;
        case 'GENERATING':
          text.innerText = 'Generating...';
          badge.style.background = '#eff6ff';
          badge.style.color = '#1d4ed8';
          indicator.style.background = '#1d4ed8';
          if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.style.cssText = 'padding:10px; font-size:0.75rem; border-radius:6px; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; margin-bottom:10px;';
            statusEl.innerHTML = '<span style="display:inline-flex; align-items:center; gap:6px;">' +
              '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="animation:spin 1s linear infinite;">' +
              '<path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>' +
              '🤖 Generating evaluation questions...' +
              '</span>';
          }
          break;
        case 'READY':
          text.innerText = isEvaluationEdited ? 'Custom' : 'Auto-generated';
          badge.style.background = '#dcfce7';
          badge.style.color = '#16a34a';
          indicator.style.background = '#16a34a';
          break;
        case 'NEEDS_UPDATE':
          text.innerText = 'Needs Update';
          badge.style.background = '#fef3c7';
          badge.style.color = '#d97706';
          indicator.style.background = '#d97706';
          if (noticeEl) {
            noticeEl.style.display = 'flex';
          }
          break;
        case 'ERROR':
          text.innerText = 'Error';
          badge.style.background = '#fee2e2';
          badge.style.color = '#dc2626';
          indicator.style.background = '#dc2626';
          if (statusEl) {
            statusEl.style.display = 'block';
            statusEl.style.cssText = 'padding:10px; font-size:0.75rem; border-radius:6px; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; margin-bottom:10px;';
            statusEl.innerHTML = `⚠️ <span>Unable to generate evaluation questions right now.</span> <br> <span style="font-size:0.7rem; opacity:0.85;">${escapeHtml(errorMsg || 'Network error')}</span>` +
              `<div style="margin-top:6px;"><button type="button" class="btn btn-outline btn-xs" style="background:#fff; border:1px solid #fca5a5; padding:2px 8px; font-size:0.7rem; height:auto; line-height:1.2;" onclick="regenerateEvaluationWithAi(false)">Retry</button></div>`;
          }
          break;
      }
    }

    function startEdit(index) {
      editingIndex = index;
      renderQuestions();
    }

    function cancelEdit(index) {
      if (questionsList[index] && !questionsList[index].question.trim()) {
        questionsList.splice(index, 1);
      }
      editingIndex = -1;
      renderQuestions();
    }

    function saveEdit(index) {
      const card = document.querySelector(`.eval-question-card[data-index="${index}"]`);
      if (!card) return;
      
      const textVal = card.querySelector('.eval-question-input')?.value.trim();
      const typeVal = card.querySelector('.eval-type-select')?.value || 'rating';
      
      if (!textVal) {
        card.querySelector('.eval-question-input')?.classList.add('is-invalid');
        return;
      }
      
      questionsList[index] = {
        id: questionsList[index]?.id || `q_${Date.now()}_${index}`,
        question: textVal,
        type: typeVal,
        required: questionsList[index]?.required ?? true,
        category: 'Overall Activity'
      };
      
      editingIndex = -1;
      isEvaluationEdited = true;
      renderQuestions();
      serializeEvaluationQuestions();
      setEvaluationState('READY');
    }

    function confirmDeleteQuestion(index) {
      if (confirm("Are you sure you want to delete this question?")) {
        questionsList.splice(index, 1);
        isEvaluationEdited = true;
        renderQuestions();
        serializeEvaluationQuestions();
        setEvaluationState('READY');
      }
    }

    function addManualQuestion() {
      if (editingIndex !== -1) {
        alert("Please save or cancel your current edit before adding a new question.");
        return;
      }
      
      questionsList.push({
        id: `q_${Date.now()}_${questionsList.length}`,
        question: '',
        type: 'rating',
        required: true,
        category: 'Overall Activity'
      });
      
      editingIndex = questionsList.length - 1;
      renderQuestions();
      
      const cardBody = document.querySelector('.proposal-eval-side-panel .card-body');
      if (cardBody) {
        setTimeout(() => {
          cardBody.scrollTop = cardBody.scrollHeight;
        }, 50);
      }
    }

    function serializeEvaluationQuestions() {
      const hiddenInput = document.getElementById('f10_evaluation_questions');
      if (hiddenInput) {
        hiddenInput.value = JSON.stringify(questionsList);
      }
    }

    function escapeHtml(str) {
      if (!str) return '';
      return str
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    }

    function setupEvaluationAutoUpdateListeners() {
      const fields = [
        '[name="title"]',
        '[name="rationale"]',
        '[name="general_objectives"]',
        '[name="specific_objectives"]'
      ];
      
      fields.forEach(sel => {
        const el = document.querySelector(sel);
        if (el) {
          el.addEventListener('input', queueEvaluationAutoUpdate);
          el.addEventListener('change', queueEvaluationAutoUpdate);
        }
      });
      
      const kpiContainer = document.getElementById('kpi-criteria') || document.getElementById('kpi-body');
      if (kpiContainer) {
        kpiContainer.addEventListener('input', e => {
          if (e.target.classList.contains('kpi-field') || e.target.name === 'kpi_criteria[]' || e.target.name === 'kpi_indicator[]') {
            queueEvaluationAutoUpdate();
          }
        });
      }
    }

    // Modal preview controls
    function openPreviewModal() {
      const modal = document.getElementById('previewModal');
      if (!modal) return;
      modal.style.display = 'block';
      modal.classList.add('show');
      
      const title = (document.querySelector('[name="title"]')?.value || 'Activity Proposal').trim();
      const previewTitle = document.getElementById('previewModalActivityTitle');
      if (previewTitle) previewTitle.innerText = title;

      const list = document.getElementById('preview-questions-list');
      if (!list) return;
      list.innerHTML = '';
      
      if (questionsList.length === 0) {
        list.innerHTML = '<div style="text-align:center; padding:20px; color:#64748b; font-size:0.9rem;">No evaluation questions generated to preview.</div>';
        return;
      }

      questionsList.forEach((q, idx) => {
        let qHtml = '';
        const isText = q.type === 'open_ended' || q.type === 'text';
        if (!isText) {
          qHtml = `
            <div style="margin-bottom: 16px; padding: 14px; background: #fafafa; border: 1px solid #f1f5f9; border-radius: 8px;">
              <div style="font-weight: 600; font-size: 0.85rem; margin-bottom: 8px; color: #1e293b;">Question ${idx+1}: ${escapeHtml(q.question)}</div>
              <div style="display: flex; gap: 14px; font-size: 0.8rem; color: #475569; flex-wrap: wrap;">
                <label style="display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="radio" name="pq_${idx}" disabled> 4 – Excellent</label>
                <label style="display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="radio" name="pq_${idx}" disabled> 3 – Very Satisfactory</label>
                <label style="display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="radio" name="pq_${idx}" disabled> 2 – Satisfactory</label>
                <label style="display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="radio" name="pq_${idx}" disabled> 1 – Needs Improvement</label>
              </div>
            </div>
          `;
        } else {
          qHtml = `
            <div style="margin-bottom: 16px; padding: 14px; background: #fafafa; border: 1px solid #f1f5f9; border-radius: 8px;">
              <div style="font-weight: 600; font-size: 0.85rem; margin-bottom: 8px; color: #1e293b;">Question ${idx+1}: ${escapeHtml(q.question)}</div>
              <textarea class="form-control" rows="2" style="font-size: 0.8rem; background:#fff;" placeholder="Type your answer here..." disabled></textarea>
            </div>
          `;
        }
        list.innerHTML += qHtml;
      });
    }

    function closePreviewModal() {
      const modal = document.getElementById('previewModal');
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
      }
    }
  </script>

  <!-- Floor Plan Scripts -->
  <script src="https://unpkg.com/konva@9/konva.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/floorplan.js"></script>

  <script>
    // ════════════════════════════════════════════════════════
    //  FLOOR PLAN DRAWING ENGINE
    // ════════════════════════════════════════════════════════
    (function() {
      const CANVAS_W = 720;
      const CANVAS_H = 480;
      const GRID = 20;

      let activeTool = 'select';
      let fillColor = '#dbeafe';
      let strokeWidth = 2;
      let gridOn = true;
      let selectedNode = null;

      let history = [];
      let redoStack = [];
      let isDrawing = false;
      let drawStart = {
        x: 0,
        y: 0
      };
      let ghostShape = null;

      // ── Konva init ───────────────────────────────────────
      const stage = new Konva.Stage({
        container: 'fpStageDiv',
        width: CANVAS_W,
        height: CANVAS_H,
      });

      const mainLayer = new Konva.Layer();
      const ghostLayer = new Konva.Layer();
      const trLayer = new Konva.Layer();
      stage.add(mainLayer);
      stage.add(ghostLayer);
      stage.add(trLayer);

      const tr = new Konva.Transformer({
        rotateEnabled: true,
        enabledAnchors: ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'middle-right', 'middle-left'],
        boundBoxFunc: (old, nb) => (nb.width < 10 || nb.height < 10) ? old : nb,
      });
      trLayer.add(tr);

      // ── Grid overlay ─────────────────────────────────────
      const gridCanvas = document.getElementById('fpGridCanvas');
      const gCtx = gridCanvas.getContext('2d');

      function drawGrid() {
        const sc = stage.scaleX();
        const W = CANVAS_W * sc,
          H = CANVAS_H * sc;
        gridCanvas.width = W;
        gridCanvas.height = H;
        gCtx.clearRect(0, 0, W, H);
        if (!gridOn) return;
        gCtx.strokeStyle = '#e5e7eb';
        gCtx.lineWidth = 0.5;
        const step = GRID * sc;
        for (let x = 0; x <= W; x += step) {
          gCtx.beginPath();
          gCtx.moveTo(x, 0);
          gCtx.lineTo(x, H);
          gCtx.stroke();
        }
        for (let y = 0; y <= H; y += step) {
          gCtx.beginPath();
          gCtx.moveTo(0, y);
          gCtx.lineTo(W, y);
          gCtx.stroke();
        }
      }

      // Set container dimensions
      const stageDiv = document.getElementById('fpStageDiv');
      stageDiv.style.width = CANVAS_W + 'px';
      stageDiv.style.height = CANVAS_H + 'px';
      document.getElementById('fpStageContainer').style.height = CANVAS_H + 'px';
      drawGrid();

      // ── Snap ─────────────────────────────────────────────
      function snap(v) {
        return Math.round(v / GRID) * GRID;
      }

      function snapPt(x, y) {
        return gridOn ? {
          x: snap(x),
          y: snap(y)
        } : {
          x,
          y
        };
      }

      // ── Tool palette ─────────────────────────────────────
      document.querySelectorAll('.fp-tool-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const tool = btn.dataset.tool;

          if (tool.startsWith('preset-')) {
            deselect();
            dropPreset(tool.replace('preset-', ''));
            return;
          }
          if (tool === 'eraser') {
            deleteSelected();
            return;
          }

          deselect();
          activeTool = tool;
          document.querySelectorAll('.fp-tool-btn').forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          stage.container().style.cursor = tool === 'select' ? 'default' : 'crosshair';
          setStatus(toolHint(tool));
        });
      });

      function toolHint(t) {
        return {
          select: 'Click a shape to select it. Drag to move. Use handles to resize.',
          rect: 'Click and drag to draw a room or area rectangle.',
          line: 'Click and drag to draw a wall or boundary.',
          arrow: 'Click and drag to draw a directional arrow.',
          ellipse: 'Click and drag to draw a circular table or feature.',
          text: 'Click anywhere to place a text label.',
        } [t] || '';
      }

      // ── Color / stroke toolbar ────────────────────────────
      document.querySelectorAll('.fp-color-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          fillColor = btn.dataset.color;
          document.querySelectorAll('.fp-color-btn').forEach(b => b.classList.remove('selected'));
          btn.classList.add('selected');
          document.getElementById('fpCustomColor').value = fillColor;
          applyToSelected();
        });
      });
      document.getElementById('fpCustomColor').addEventListener('input', e => {
        fillColor = e.target.value;
        document.querySelectorAll('.fp-color-btn').forEach(b => b.classList.remove('selected'));
        applyToSelected();
      });
      document.getElementById('fpStrokeWidth').addEventListener('change', e => {
        strokeWidth = parseInt(e.target.value);
      });

      function applyToSelected() {
        if (!selectedNode) return;
        if (typeof selectedNode.fill === 'function') selectedNode.fill(fillColor);
        mainLayer.batchDraw();
        saveToHidden();
      }

      // ── Drawing interactions ──────────────────────────────
      stage.on('mousedown touchstart', e => {
        if (activeTool === 'select') return;
        if (e.target !== stage && e.target.getLayer() !== ghostLayer) return;

        const pos = stage.getPointerPosition();
        if (!pos) return;
        const pt = snapPt(pos.x, pos.y);
        drawStart = pt;
        isDrawing = true;

        if (activeTool === 'text') {
          const label = prompt('Enter label text:', 'Label');
          if (!label) {
            isDrawing = false;
            return;
          }
          const txt = new Konva.Text({
            x: pt.x,
            y: pt.y,
            text: label,
            fontSize: 13,
            fontFamily: 'Inter, sans-serif',
            fill: '#1e2a3a',
            draggable: true,
            name: 'shape'
          });
          addShape(txt);
          isDrawing = false;
          return;
        }

        ghostShape = buildGhost(activeTool, pt);
        if (ghostShape) ghostLayer.add(ghostShape);
        ghostLayer.batchDraw();
      });

      stage.on('mousemove touchmove', () => {
        if (!isDrawing || !ghostShape) return;
        const pos = stage.getPointerPosition();
        if (!pos) return;
        const pt = snapPt(pos.x, pos.y);
        const w = pt.x - drawStart.x,
          h = pt.y - drawStart.y;

        if (activeTool === 'rect') {
          ghostShape.x(Math.min(drawStart.x, pt.x));
          ghostShape.y(Math.min(drawStart.y, pt.y));
          ghostShape.width(Math.abs(w));
          ghostShape.height(Math.abs(h));
        } else if (activeTool === 'ellipse') {
          ghostShape.x(drawStart.x + w / 2);
          ghostShape.y(drawStart.y + h / 2);
          ghostShape.radiusX(Math.abs(w / 2));
          ghostShape.radiusY(Math.abs(h / 2));
        } else if (activeTool === 'line' || activeTool === 'arrow') {
          ghostShape.points([drawStart.x, drawStart.y, pt.x, pt.y]);
        }
        ghostLayer.batchDraw();
      });

      stage.on('mouseup touchend', () => {
        if (!isDrawing) return;
        isDrawing = false;
        if (ghostShape) {
          ghostShape.destroy();
          ghostLayer.batchDraw();
        }

        const pos = stage.getPointerPosition();
        if (!pos) {
          ghostShape = null;
          return;
        }
        const pt = snapPt(pos.x, pos.y);
        const w = Math.abs(pt.x - drawStart.x);
        const h = Math.abs(pt.y - drawStart.y);

        if (w < 5 && h < 5) {
          ghostShape = null;
          return;
        }

        let shape = null;
        if (activeTool === 'rect') {
          shape = new Konva.Rect({
            x: Math.min(drawStart.x, pt.x),
            y: Math.min(drawStart.y, pt.y),
            width: w,
            height: h,
            fill: fillColor,
            stroke: '#1e2a3a',
            strokeWidth,
            cornerRadius: 3,
            draggable: true,
            name: 'shape'
          });
        } else if (activeTool === 'ellipse') {
          shape = new Konva.Ellipse({
            x: drawStart.x + (pt.x - drawStart.x) / 2,
            y: drawStart.y + (pt.y - drawStart.y) / 2,
            radiusX: w / 2,
            radiusY: h / 2,
            fill: fillColor,
            stroke: '#1e2a3a',
            strokeWidth,
            draggable: true,
            name: 'shape'
          });
        } else if (activeTool === 'line') {
          shape = new Konva.Line({
            points: [drawStart.x, drawStart.y, pt.x, pt.y],
            stroke: '#1e2a3a',
            strokeWidth,
            lineCap: 'round',
            draggable: true,
            name: 'shape'
          });
        } else if (activeTool === 'arrow') {
          shape = new Konva.Arrow({
            points: [drawStart.x, drawStart.y, pt.x, pt.y],
            stroke: '#1e2a3a',
            fill: '#1e2a3a',
            strokeWidth,
            pointerLength: 10,
            pointerWidth: 8,
            draggable: true,
            name: 'shape'
          });
        }

        if (shape) addShape(shape);
        ghostShape = null;
      });

      stage.on('click tap', e => {
        if (e.target === stage) deselect();
      });

      // ── Ghost preview factory ─────────────────────────────
      function buildGhost(tool, pt) {
        const cfg = {
          fill: 'rgba(59,130,246,0.12)',
          stroke: '#3b82f6',
          strokeWidth: 1,
          listening: false
        };
        if (tool === 'rect') return new Konva.Rect({
          ...cfg,
          x: pt.x,
          y: pt.y,
          width: 1,
          height: 1
        });
        if (tool === 'ellipse') return new Konva.Ellipse({
          ...cfg,
          x: pt.x,
          y: pt.y,
          radiusX: 1,
          radiusY: 1
        });
        if (tool === 'line') return new Konva.Line({
          ...cfg,
          fill: null,
          points: [pt.x, pt.y, pt.x, pt.y],
          lineCap: 'round'
        });
        if (tool === 'arrow') return new Konva.Arrow({
          ...cfg,
          fill: cfg.stroke,
          points: [pt.x, pt.y, pt.x, pt.y]
        });
        return null;
      }

      // ── Presets ───────────────────────────────────────────
      const PRESETS = {
        stage: {
          label: 'Stage',
          fill: '#1e2a3a',
          stroke: '#0f172a',
          w: 200,
          h: 60,
          shape: 'rect'
        },
        table: {
          label: 'Table',
          fill: '#dcfce7',
          stroke: '#16a34a',
          w: 40,
          h: 40,
          shape: 'ellipse'
        },
        booth: {
          label: 'Booth',
          fill: '#fef9c3',
          stroke: '#ca8a04',
          w: 80,
          h: 50,
          shape: 'rect'
        },
        exit: {
          label: 'Exit',
          fill: '#fce7f3',
          stroke: '#db2777',
          w: 40,
          h: 12,
          shape: 'rect'
        },
        restroom: {
          label: 'Restroom',
          fill: '#f3f4f6',
          stroke: '#4b5563',
          w: 50,
          h: 50,
          shape: 'rect'
        },
      };

      function dropPreset(key) {
        const p = PRESETS[key];
        if (!p) return;
        const cx = snap(CANVAS_W / 2 - p.w / 2);
        const cy = snap(CANVAS_H / 2 - p.h / 2);
        const txtFill = p.fill === '#1e2a3a' ? '#ffffff' : '#1e2a3a';

        const group = new Konva.Group({
          x: cx,
          y: cy,
          draggable: true,
          name: 'shape'
        });

        let body;
        if (p.shape === 'rect') {
          body = new Konva.Rect({
            width: p.w,
            height: p.h,
            fill: p.fill,
            stroke: p.stroke,
            strokeWidth: 2,
            cornerRadius: 3
          });
        } else {
          body = new Konva.Ellipse({
            x: p.w / 2,
            y: p.h / 2,
            radiusX: p.w / 2,
            radiusY: p.h / 2,
            fill: p.fill,
            stroke: p.stroke,
            strokeWidth: 2
          });
        }

        const lbl = new Konva.Text({
          x: 0,
          y: p.h / 2 - 7,
          width: p.w,
          align: 'center',
          text: p.label,
          fontSize: 11,
          fontFamily: 'Inter, sans-serif',
          fill: txtFill,
          listening: false
        });

        group.add(body);
        group.add(lbl);
        addShape(group);
        setStatus(`${p.label} placed at center — drag to position it.`);
      }

      // ── Add shape & wire events ───────────────────────────
      function addShape(node) {
        mainLayer.add(node);
        mainLayer.batchDraw();
        wireShape(node);
        saveHistory();
        saveToHidden();
        selectNode(node);
      }

      function wireShape(node) {
        node.on('click tap', e => {
          e.cancelBubble = true;
          selectNode(node);
        });
        node.on('dragend transformend', () => {
          saveHistory();
          saveToHidden();
        });
        node.on('dblclick dbltap', () => {
          if (node.getClassName() === 'Text') {
            const nv = prompt('Edit label:', node.text());
            if (nv !== null) {
              node.text(nv);
              mainLayer.batchDraw();
              saveToHidden();
            }
          }
        });
      }

      function selectNode(node) {
        selectedNode = node;
        tr.nodes([node]);
        trLayer.batchDraw();
        updateProps(node);
        setStatus('Shape selected. Drag to move, use handles to resize/rotate.');
      }

      function deselect() {
        selectedNode = null;
        tr.nodes([]);
        trLayer.batchDraw();
        clearProps();
      }

      function deleteSelected() {
        if (!selectedNode) {
          setStatus('Select a shape first, then click Delete.');
          return;
        }
        selectedNode.destroy();
        tr.nodes([]);
        trLayer.batchDraw();
        mainLayer.batchDraw();
        selectedNode = null;
        clearProps();
        saveHistory();
        saveToHidden();
      }

      // ── Props panel ───────────────────────────────────────
      function updateProps(node) {
        const cls = node.getClassName();
        document.getElementById('propRot').value = Math.round(node.rotation() || 0);
        document.getElementById('propOpac').value = node.opacity() || 1;
        if (cls === 'Rect' || cls === 'Group') {
          document.getElementById('propW').value = Math.round(typeof node.width === 'function' ? node.width() : 0);
          document.getElementById('propH').value = Math.round(typeof node.height === 'function' ? node.height() : 0);
        } else if (cls === 'Ellipse') {
          document.getElementById('propW').value = Math.round((node.radiusX ? node.radiusX() : 0) * 2);
          document.getElementById('propH').value = Math.round((node.radiusY ? node.radiusY() : 0) * 2);
        }
        if (cls === 'Text') document.getElementById('propLabel').value = node.text();
      }

      function clearProps() {
        ['propLabel', 'propW', 'propH', 'propRot', 'propOpac'].forEach(id => document.getElementById(id).value = '');
      }

      document.getElementById('propLabel').addEventListener('change', e => {
        if (selectedNode && selectedNode.getClassName() === 'Text') {
          selectedNode.text(e.target.value);
          mainLayer.batchDraw();
          saveToHidden();
        }
      });
      ['propW', 'propH'].forEach(id => {
        document.getElementById(id).addEventListener('change', e => {
          if (!selectedNode) return;
          const v = parseInt(e.target.value);
          if (id === 'propW') {
            if (typeof selectedNode.width === 'function') selectedNode.width(v);
            if (typeof selectedNode.radiusX === 'function') selectedNode.radiusX(v / 2);
          } else {
            if (typeof selectedNode.height === 'function') selectedNode.height(v);
            if (typeof selectedNode.radiusY === 'function') selectedNode.radiusY(v / 2);
          }
          tr.forceUpdate();
          mainLayer.batchDraw();
          saveToHidden();
        });
      });
      document.getElementById('propRot').addEventListener('change', e => {
        if (selectedNode) {
          selectedNode.rotation(parseFloat(e.target.value));
          tr.forceUpdate();
          mainLayer.batchDraw();
          saveToHidden();
        }
      });
      document.getElementById('propOpac').addEventListener('change', e => {
        if (selectedNode) {
          selectedNode.opacity(parseFloat(e.target.value));
          mainLayer.batchDraw();
          saveToHidden();
        }
      });

      // ── Toolbar buttons ───────────────────────────────────
      document.getElementById('fpClear').addEventListener('click', () => {
        if (!confirm('Clear the entire floor plan?')) return;
        mainLayer.destroyChildren();
        mainLayer.batchDraw();
        deselect();
        redoStack = [];
        history = [];
        saveToHidden();
        setStatus('Canvas cleared. Start drawing your floor plan.');
      });

      document.getElementById('fpUndo').addEventListener('click', undo);
      document.getElementById('fpRedo').addEventListener('click', redo);

      document.getElementById('fpGrid').addEventListener('click', function() {
        gridOn = !gridOn;
        this.textContent = gridOn ? '⊞ Grid: On' : '⊞ Grid: Off';
        drawGrid();
      });

      document.getElementById('fpZoomIn').addEventListener('click', () => {
        const sc = Math.min(stage.scaleX() * 1.2, 3);
        stage.scale({
          x: sc,
          y: sc
        });
        stage.batchDraw();
        drawGrid();
      });
      document.getElementById('fpZoomOut').addEventListener('click', () => {
        const sc = Math.max(stage.scaleX() / 1.2, 0.3);
        stage.scale({
          x: sc,
          y: sc
        });
        stage.batchDraw();
        drawGrid();
      });
      document.getElementById('fpZoomReset').addEventListener('click', () => {
        stage.scale({
          x: 1,
          y: 1
        });
        stage.position({
          x: 0,
          y: 0
        });
        stage.batchDraw();
        drawGrid();
      });

      // ── Keyboard shortcuts ───────────────────────────────
      document.addEventListener('keydown', e => {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if ((e.key === 'Delete' || e.key === 'Backspace') && selectedNode) deleteSelected();
        if (e.ctrlKey && e.key === 'z') {
          e.preventDefault();
          undo();
        }
        if (e.ctrlKey && e.key === 'y') {
          e.preventDefault();
          redo();
        }
      });

      // ── History ───────────────────────────────────────────
      function saveHistory() {
        history.push(mainLayer.toJSON());
        if (history.length > 50) history.shift();
        redoStack = [];
      }

      function undo() {
        if (history.length < 2) {
          setStatus('Nothing to undo.');
          return;
        }
        redoStack.push(history.pop());
        restoreLayer(history[history.length - 1]);
      }

      function redo() {
        if (!redoStack.length) {
          setStatus('Nothing to redo.');
          return;
        }
        const next = redoStack.pop();
        history.push(next);
        restoreLayer(next);
      }

      function restoreLayer(json) {
        mainLayer.destroyChildren();
        const parsed = JSON.parse(json);
        (parsed.children || []).forEach(child => {
          try {
            const node = Konva.Node.create(child);
            node.draggable(true);
            node.name('shape');
            mainLayer.add(node);
            wireShape(node);
          } catch (e) {}
        });
        mainLayer.batchDraw();
        deselect();
        saveToHidden();
      }

      // ── Serialize to hidden inputs ────────────────────────
      function saveToHidden() {
        const shapes = mainLayer.getChildren().map(n => ({
          type: n.getClassName(),
          attrs: n.getAttrs()
        }));
        document.getElementById('fp-canvas-data').value = JSON.stringify({
          shapes
        });
        // Remove invalid outline when user places a shape
        if (shapes.length > 0) document.getElementById('fp-canvas-invalid-wrap').classList.remove('fp-canvas-invalid');
        try {
          document.getElementById('fp-canvas-image').value = stage.toDataURL({
            pixelRatio: 1
          });
        } catch (e) {}
      }

      // ── Status ────────────────────────────────────────────
      function setStatus(msg) {
        document.getElementById('fpStatus').textContent = msg;
      }

      // ── Boot ─────────────────────────────────────────────
      saveHistory();
      setStatus('Select a tool from the left panel to start drawing your floor plan.');

    })();
  </script>
  <!-- ── Schedule Conflict Modal ── -->
  <div class="modal fade" id="scheduleConflictModal" tabindex="-1" aria-labelledby="scheduleConflictModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" style="margin: 1.75rem auto; max-width: 750px;">
      <div class="modal-content" style="border-radius:10px; overflow:hidden; border:none; box-shadow:0 8px 24px rgba(0,0,0,0.15);">
        <div class="modal-header bg-danger text-white" style="border-bottom:none; padding: 12px 20px;">
          <h5 class="modal-title d-flex align-items-center gap-2 fw-bold" id="scheduleConflictModalLabel" style="font-size:1.1rem; margin:0; line-height:1.2;">
            ⚠️ Schedule Conflict Detected
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" style="background-color: transparent; border: none; font-size: 1.3rem; color: #fff; line-height: 1; cursor: pointer; padding: 0;">&times;</button>
        </div>
        <div class="modal-body" style="background:#f8f9fa; padding: 16px 20px; max-height: 380px; overflow-y: auto;">
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
            <!-- Existing Activity -->
            <div style="background: #fff; padding: 10px 14px; border-radius: 8px; border-left: 4px solid #dc3545; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
              <div style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #dc3545; margin-bottom: 4px;">Existing Approved Activity</div>
              <div id="scuConfTitle" style="font-weight: 700; font-size: 0.9rem; color: #2d3748; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px;">Leadership Seminar</div>
              <div style="font-size: 0.8rem; color: #4a5568; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px;">
                <span>📅 <span id="scuConfDate">August 20, 2026</span></span>
                <span>⏰ <span id="scuConfTime">1:00 PM – 3:00 PM</span></span>
                <span>📍 <span id="scuConfVenue">AVR</span></span>
              </div>
            </div>
            <!-- Your Proposed Activity -->
            <div style="background: #fff; padding: 10px 14px; border-radius: 8px; border-left: 4px solid #718096; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
              <div style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #718096; margin-bottom: 4px;">Your Proposed Activity</div>
              <div style="font-weight: 700; font-size: 0.9rem; color: #4a5568;">(Proposed Slot)</div>
              <div style="font-size: 0.8rem; color: #4a5568; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px;">
                <span>📅 <span id="scuPropDate">August 20, 2026</span></span>
                <span>⏰ <span id="scuPropTime">2:00 PM – 4:00 PM</span></span>
                <span>📍 <span id="scuPropVenue">AVR</span></span>
              </div>
            </div>
          </div>

          <!-- AI Recommendation -->
          <div style="background: #fff; padding: 12px 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h6 class="text-primary fw-bold d-flex align-items-center gap-2" style="font-size:0.85rem; color: #0d6efd; margin: 0 0 10px 0; display: flex; align-items: center; gap: 6px;">
              🤖 AI Recommendation
            </h6>
            <div id="scuAiLoading" class="text-center py-2" style="text-align: center; padding: 10px 0;">
              <div class="spinner-border text-primary spinner-border-sm" role="status" style="display: inline-block; width: 0.9rem; height: 0.9rem; border: 0.15em solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spin .75s linear infinite;"></div>
              <span class="ms-2 text-muted" style="font-size:0.8rem; margin-left: 6px; color: #6c757d;">Finding alternative available dates and times...</span>
            </div>
            <div id="scuAiRecommendationList" class="d-flex flex-column gap-2" style="display:none; flex-direction: column; gap: 6px;">
              <!-- Alternative items -->
            </div>
          </div>
        </div>
        <div class="modal-footer bg-light" style="border-top:none; display: flex; justify-content: flex-end; gap: 8px; background: #f8f9fa; padding: 10px 20px;">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" style="padding: 5px 12px; font-size: 0.8rem; border-radius: 6px;">Edit Schedule</button>
          <button type="button" class="btn btn-primary btn-sm" id="scuUseBtn" disabled style="padding: 5px 12px; font-size: 0.8rem; border-radius: 6px;">Use Suggested Schedule</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── AI Proposal Validation Modal ── -->
  <div class="modal fade" id="aiProposalValidationModal" tabindex="-1" aria-labelledby="aiProposalValidationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="margin: 1.75rem auto; max-width: 1050px;">
      <div class="modal-content" style="border-radius:16px; overflow:hidden; border:none; box-shadow:0 12px 36px rgba(99, 102, 241, 0.25); font-family: 'Inter', sans-serif; background: #f8fafc;" id="aiProposalValidationContent">
        <!-- Inner content is rendered dynamically by proposal-ai-validator.js -->
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/schedule-conflict-ui.js?v=<?= time() ?>"></script>
  <script src="<?= BASE_URL ?>/assets/js/proposal-ai-validator.js?v=<?= time() ?>"></script>
  <script>
    if (typeof ScheduleConflictUI !== 'undefined') {
      ScheduleConflictUI.init({
        baseUrl:          '<?= BASE_URL ?>',
        activityId:       0,
        venueId:          'f1_venue',
        dateId:           'f1_event_date',
        startId:          'f1_start_time',
        endId:            'f1_end_time',
        formId:           'proposalForm',
        successMessageId: 'scheduleSuccessMessage'
      });
    }

    if (typeof ProposalAIValidator !== 'undefined') {
      ProposalAIValidator.init({
        baseUrl:       '<?= BASE_URL ?>',
        formSelector:  '#proposalForm',
        modalSelector: '#aiProposalValidationModal'
      });
    }

    // Dynamic auto-updates for AI Evaluation questions
    document.getElementById('f2_general_objectives')?.addEventListener('blur', queueEvaluationAutoUpdate);
    document.getElementById('f2_general_objectives')?.addEventListener('input', queueEvaluationAutoUpdate);
    document.getElementById('f2_specific_objectives')?.addEventListener('blur', queueEvaluationAutoUpdate);
    document.getElementById('f2_specific_objectives')?.addEventListener('input', queueEvaluationAutoUpdate);

    const kpiContainer = document.getElementById('kpi-criteria');
    if (kpiContainer) {
      kpiContainer.addEventListener('input', queueEvaluationAutoUpdate);
      kpiContainer.addEventListener('change', queueEvaluationAutoUpdate);
    }

    // Initialize baseline evaluation state and check for initial auto-generation
    lastEvaluationState = getObjectivesAndKpisState();
    checkAndTriggerEvaluationAutoUpdate();
  </script>
  <!-- AI Evaluation Preview Modal -->
  <div id="previewModal" class="modal fade" tabindex="-1" style="display: none;">
    <div class="modal-dialog modal-dialog-centered modal-lg" style="max-width: 600px;">
      <div class="modal-content" style="border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: none;">
        <div class="modal-header" style="border-bottom: 1px solid #e2e8f0; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;">
          <h5 class="modal-title" style="font-size: 1rem; font-weight: 700; color: #1e293b; margin: 0;">👁 Preview Evaluation Tool</h5>
          <button type="button" class="btn-close" onclick="closePreviewModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: #64748b;">✕</button>
        </div>
        <div class="modal-body" style="padding: 20px; max-height: calc(100vh - 200px); overflow-y: auto;">
          <div style="margin-bottom: 16px; font-size: 0.85rem; color: #64748b; line-height: 1.45;">
            This is how the participant evaluation questionnaire (Evaluation Form) will be displayed to students scanning the QR code post-event.
          </div>
          <div style="border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px; margin-bottom: 16px; background:#fff;">
            <h4 id="previewModalActivityTitle" style="margin:0 0 8px 0; font-size:1.05rem; font-weight:700; color: #0f172a;">Activity Title</h4>
            <div style="font-size:0.8rem; color:#64748b;">Please take a moment to evaluate the activity you attended. Your feedback helps us improve future events.</div>
          </div>
          <div id="preview-questions-list" style="display: flex; flex-direction: column; gap: 14px;">
            <!-- Dynamically populated -->
          </div>
        </div>
        <div class="modal-footer" style="border-top: 1px solid #e2e8f0; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; width:100%;">
          <span style="font-size:0.75rem; color:#94a3b8; font-style:italic;">* Submitting from preview is disabled</span>
          <button type="button" class="btn btn-outline" onclick="closePreviewModal()" style="font-size: 0.8rem; padding: 6px 14px; height:auto; line-height:1.2;">Close Preview</button>
        </div>
      </div>
    </div>
  </div>
</body>

</html>
