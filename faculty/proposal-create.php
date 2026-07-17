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
              <div class="form-grid">
                <div class="form-group col-span-2">
                  <label class="form-label">General Objectives <span class="text-danger">*</span></label>
                  <textarea name="general_objectives" id="f2_general_objectives" class="form-control" placeholder="State the broad goals of this activity..."></textarea>
                  <span class="field-error" id="e_general_objectives">General objectives are required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Specific Objectives <span class="text-danger">*</span></label>
                  <textarea name="specific_objectives" id="f2_specific_objectives" class="form-control" placeholder="List measurable specific goals..."></textarea>
                  <span class="field-error" id="e_specific_objectives">Specific objectives are required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Involved Subjects (Optional)</label>
                  <input type="text" name="involved_subjects" id="f2_involved_subjects" class="form-control" placeholder="e.g. ITE314, GE102">
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Rationale (Optional)</label>
                  <textarea name="rationale" id="f2_rationale" class="form-control" placeholder="Why is this activity necessary?"></textarea>
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
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div class="flex gap-2">
              <button type="submit" name="action" value="draft" class="btn btn-outline" formnovalidate>💾 Save as Draft</button>
              <button type="submit" name="action" value="submit" class="btn btn-primary" id="submitBtn">🚀 Submit for Approval</button>
            </div>
          </div>
        </div>

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
    }

    function nextStep() {
      if (!validateStep(currentStep)) return;
      // Unlock the next step in the indicator bar
      if (currentStep + 1 <= totalSteps) {
        const nextIndicator = document.querySelector(`.wizard-step[data-step="${currentStep + 1}"]`);
        if (nextIndicator) nextIndicator.classList.remove('locked');
        if (currentStep + 1 > maxUnlocked) maxUnlocked = currentStep + 1;
      }
      if (currentStep < totalSteps) showStep(currentStep + 1);
    }

    function prevStep() {
      if (currentStep > 1) showStep(currentStep - 1);
    }

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

    // ── Form submit validation ────────────────────────────────────────
    document.getElementById('proposalForm').addEventListener('submit', function(e) {
      if (!validateStep(9)) {
        e.preventDefault();
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
</body>

</html>