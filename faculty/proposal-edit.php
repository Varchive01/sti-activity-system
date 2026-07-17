<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');
$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
$act = $db->prepare("SELECT * FROM activities WHERE id=? AND faculty_id=? AND status IN ('draft','returned_for_revision')");
$act->execute([$id, $user['id']]);
$activity = $act->fetch();
if (!$activity) {
  header('Location: ' . BASE_URL . '/faculty/activities.php');
  exit;
}

// Parse section-specific reviewer comments from revision_sections JSON
$revisionSections = [];
if (!empty($activity['revision_sections'])) {
  $decoded = json_decode($activity['revision_sections'], true);
  if (is_array($decoded)) $revisionSections = $decoded;
}

// Map section key → wizard step number
$sectionStepMap = [
  'event_details'  => 1,
  'objectives'     => 2,
  'materials'      => 3,
  'floor_plan'     => 4,
  'people'         => 5,
  'schedule'       => 6,   // schedule & program
  'guidelines'     => 7,
  'faculty_tasks'  => 8,
  'kpi'            => 9,
];

// Build list of flagged step numbers (for JS auto-navigation)
$flaggedSteps = [];
foreach ($revisionSections as $key => $cmt) {
  if (isset($sectionStepMap[$key])) $flaggedSteps[] = $sectionStepMap[$key];
}
$flaggedSteps = array_values(array_unique($flaggedSteps));
sort($flaggedSteps);

$mats = $db->prepare("SELECT * FROM materials WHERE activity_id=?");
$mats->execute([$id]);
$materials = $mats->fetchAll();
$prog = $db->prepare("SELECT * FROM program_sequence WHERE activity_id=? ORDER BY sort_order");
$prog->execute([$id]);
$program = $prog->fetchAll();
$mp   = $db->prepare("SELECT * FROM manpower WHERE activity_id=?");
$mp->execute([$id]);
$manpower = $mp->fetchAll();
$sc   = $db->prepare("SELECT * FROM schedules WHERE activity_id=?");
$sc->execute([$id]);
$schedules = $sc->fetchAll();
$gl   = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?");
$gl->execute([$id]);
$guidelines = $gl->fetch();
$ft   = $db->prepare("SELECT * FROM faculty_tasks WHERE activity_id=?");
$ft->execute([$id]);
$ftasks = $ft->fetchAll();
$fp   = $db->prepare("SELECT * FROM floor_plans WHERE activity_id=?");
$fp->execute([$id]);
$floorplan = $fp->fetch();

$kpiStmt = $db->prepare("SELECT * FROM kpi_evaluations WHERE activity_id=?");
$kpiStmt->execute([$id]);
$kpis = $kpiStmt->fetchAll();

$isReturned = ($activity['status'] === 'returned_for_revision');
$hasSectionComments = !empty($revisionSections);

// Check editability for each section
$s1Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['event_details']);
$s2Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['objectives']);
$s3Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['materials']);
$s4Editable = true; // Always fully editable as requested
$s5Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['people']);
$s6Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['schedule']);
$s7Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['guidelines']);
$s8Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['faculty_tasks']);
$s9Editable = !$isReturned || !$hasSectionComments || !empty($revisionSections['kpi']);

// Attribute strings
$s1Attr = $s1Editable ? '' : 'readonly style="background:var(--bg-base); cursor:not-allowed;"';
$s1SelectStyle = $s1Editable ? '' : 'style="pointer-events: none; background:var(--bg-base); cursor:not-allowed;" tabindex="-1"';

$s2Attr = $s2Editable ? '' : 'readonly style="background:var(--bg-base); cursor:not-allowed;"';

$s3Attr = $s3Editable ? '' : 'readonly style="background:var(--bg-base); cursor:not-allowed;"';

$s4Attr = $s4Editable ? '' : 'readonly style="background:var(--bg-base); cursor:not-allowed;"';

$s5Attr = $s5Editable ? '' : 'readonly style="background:var(--bg-base); cursor:not-allowed;"';
$s5SelectStyle = $s5Editable ? '' : 'style="pointer-events: none; background:var(--bg-base); cursor:not-allowed;" tabindex="-1"';

$s6Attr = $s6Editable ? '' : 'readonly style="background:var(--bg-base); cursor:not-allowed;"';

$s7Attr = $s7Editable ? '' : 'readonly style="background:var(--bg-base); cursor:not-allowed;"';

$s8Attr = $s8Editable ? '' : 'readonly style="background:var(--bg-base); cursor:not-allowed;"';

$s9Attr = $s9Editable ? '' : 'readonly style="background:var(--bg-base); cursor:not-allowed;"';
$s9SelectStyle = $s9Editable ? '' : 'style="pointer-events: none; background:var(--bg-base); cursor:not-allowed;" tabindex="-1"';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Proposal – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    .wizard-panel { display: none }
    .wizard-panel.active { display: block }
    .nav-btns { display: flex; justify-content: space-between; margin-top: 24px }

    /* Flagged step indicator */
    .wizard-step.flagged .step-num { background: #F59E0B; color: #fff; }
    .wizard-step.flagged { color: #92400E; }

    /* Flagged card highlight */
    .card.section-flagged { border: 2px solid #F59E0B; box-shadow: 0 0 0 3px rgba(245,158,11,.15); }

    /* Reviewer comment banner inside flagged card */
    .reviewer-comment-banner {
      background: #FEF3C7;
      border-left: 4px solid #F59E0B;
      border-radius: 6px;
      padding: 12px 16px;
      margin-bottom: 16px;
      font-size: .84rem;
      color: #78350F;
      display: flex;
      gap: 10px;
      align-items: flex-start;
    }
    .reviewer-comment-banner .rc-icon { font-size: 1.1rem; flex-shrink:0; }
    .reviewer-comment-banner .rc-label { font-weight: 700; display: block; margin-bottom: 3px; }
  </style>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/floorplan.css">
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Edit Proposal</div>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $id ?>" class="btn btn-outline btn-sm">← Cancel</a>
      </div>
    </header>
    <div class="content">

      <?php if ($activity['status'] === 'returned_for_revision'): ?>
        <div class="alert alert-warning" style="margin-bottom:20px;">
          <strong>↩️ This proposal was returned for revision.</strong>
          <?php if (!empty($revisionSections)): ?>
            <span style="margin-left:6px;">Sections highlighted in amber below require your attention.</span>
          <?php elseif ($activity['revision_notes']): ?>
            <br><span><?= nl2br(htmlspecialchars($activity['revision_notes'])) ?></span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Wizard Steps -->
      <div class="wizard-steps" id="wizardSteps">
        <div class="wizard-step active <?= in_array(1, $flaggedSteps) ? 'flagged' : '' ?>" data-step="1">
          <div class="step-num">1</div>Event Details
        </div>
        <div class="wizard-step <?= in_array(2, $flaggedSteps) ? 'flagged' : '' ?>" data-step="2">
          <div class="step-num">2</div>Objectives
        </div>
        <div class="wizard-step <?= in_array(3, $flaggedSteps) ? 'flagged' : '' ?>" data-step="3">
          <div class="step-num">3</div>Materials
        </div>
        <div class="wizard-step <?= in_array(4, $flaggedSteps) ? 'flagged' : '' ?>" data-step="4">
          <div class="step-num">4</div>Floor Plan
        </div>
        <div class="wizard-step <?= in_array(5, $flaggedSteps) ? 'flagged' : '' ?>" data-step="5">
          <div class="step-num">5</div>People
        </div>
        <div class="wizard-step <?= in_array(6, $flaggedSteps) ? 'flagged' : '' ?>" data-step="6">
          <div class="step-num">6</div>Schedule
        </div>
        <div class="wizard-step <?= in_array(7, $flaggedSteps) ? 'flagged' : '' ?>" data-step="7">
          <div class="step-num">7</div>Guidelines
        </div>
        <div class="wizard-step <?= in_array(8, $flaggedSteps) ? 'flagged' : '' ?>" data-step="8">
          <div class="step-num">8</div>Faculty Tasks
        </div>
        <div class="wizard-step <?= in_array(9, $flaggedSteps) ? 'flagged' : '' ?>" data-step="9">
          <div class="step-num">9</div>KPI
        </div>
      </div>

      <form method="POST" action="<?= BASE_URL ?>/api/proposal-update.php" enctype="multipart/form-data">
        <input type="hidden" name="activity_id" value="<?= $id ?>">

        <!-- STEP 1 -->
        <div class="wizard-panel active" id="panel-1">
          <?php
            $s1Flagged = !empty($revisionSections['event_details']) || !empty($revisionSections['objectives']);
            $s1Comment = implode(' | ', array_filter([
              $revisionSections['event_details'] ?? '',
              $revisionSections['objectives'] ?? ''
            ]));
          ?>
          <div class="card <?= $s1Flagged ? 'section-flagged' : '' ?>">
            <div class="card-header">
              <h2>📄 Event Details</h2>
            </div>
            <div class="card-body">
              <?php if ($s1Flagged): ?>
                <div class="reviewer-comment-banner">
                  <span class="rc-icon">⚠️</span>
                  <div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s1Comment) ?></div>
                </div>
              <?php endif; ?>
              <div class="form-grid">
                <div class="form-group col-span-2">
                  <label class="form-label">Event Title *</label>
                  <input type="text" name="title" class="form-control" required value="<?= htmlspecialchars($activity['title']) ?>" <?= $s1Attr ?>>
                </div>
                <div class="form-group">
                  <label class="form-label">Date *</label>
                  <input type="date" name="event_date" class="form-control" required value="<?= $activity['event_date'] ?>" <?= $s1Attr ?>>
                </div>
                <div class="form-group">
                  <label class="form-label">Source *</label>
                  <select name="source" class="form-control" required <?= $s1SelectStyle ?>>
                    <option value="student_org" <?= $activity['source'] === 'student_org' ? 'selected' : '' ?>>Student Organization</option>
                    <option value="faculty" <?= $activity['source'] === 'faculty' ? 'selected' : '' ?>>Faculty</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Start Time</label>
                  <input type="time" name="start_time" class="form-control" value="<?= $activity['start_time'] ?>" <?= $s1Attr ?>>
                </div>
                <div class="form-group">
                  <label class="form-label">End Time</label>
                  <input type="time" name="end_time" class="form-control" value="<?= $activity['end_time'] ?>" <?= $s1Attr ?>>
                </div>
                <div class="form-group">
                  <label class="form-label">Venue *</label>
                  <input type="text" name="venue" class="form-control" required value="<?= htmlspecialchars($activity['venue'] ?? '') ?>" <?= $s1Attr ?>>
                </div>
                <div class="form-group">
                  <label class="form-label">Target Participants</label>
                  <input type="number" name="target_participants" class="form-control" value="<?= $activity['target_participants'] ?>" <?= $s1Attr ?>>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Venue Address</label>
                  <input type="text" name="venue_address" class="form-control" value="<?= htmlspecialchars($activity['venue_address'] ?? '') ?>" <?= $s1Attr ?>>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Theme</label>
                  <input type="text" name="theme" class="form-control" value="<?= htmlspecialchars($activity['theme'] ?? '') ?>" <?= $s1Attr ?>>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Event Poster <?php if(empty($activity['poster_path'])) echo '<span class="text-danger">*</span>'; ?></label>
                  <?php if (!empty($activity['poster_path'])): ?>
                    <div style="margin-bottom:8px;">
                      <?php $posterUrl = str_starts_with($activity['poster_path'], 'http') ? $activity['poster_path'] : BASE_URL . '/' . $activity['poster_path']; ?>
                      <a href="<?= htmlspecialchars($posterUrl) ?>" target="_blank" class="btn btn-outline btn-sm">View Current Poster</a>
                    </div>
                    <input type="file" name="poster_file" class="form-control" accept="image/*" <?= $s1Attr ?>>
                    <small style="color: var(--text-muted);">Upload a new poster to replace the current one.</small>
                  <?php else: ?>
                    <input type="file" name="poster_file" class="form-control" accept="image/*" required <?= $s1Attr ?>>
                    <small style="color: var(--text-muted);">Upload a poster for this event.</small>
                  <?php endif; ?>
                </div>
                </div>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <div></div><button type="button" class="btn btn-primary" onclick="nextStep()">Next →</button>
          </div>
        </div>

        <!-- STEP 2: Objectives -->
        <div class="wizard-panel" id="panel-2">
          <?php
            $s2Flagged = !empty($revisionSections['objectives']);
            $s2Comment = $revisionSections['objectives'] ?? '';
          ?>
          <div class="card <?= $s2Flagged ? 'section-flagged' : '' ?>">
            <div class="card-header">
              <h2>🎯 Objectives & Academic Alignment</h2>
            </div>
            <div class="card-body">
              <?php if ($s2Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s2Comment) ?></div></div>
              <?php endif; ?>
              <div class="form-grid">
                <div class="form-group col-span-2">
                  <label class="form-label">General Objectives *</label>
                  <textarea name="general_objectives" class="form-control" required <?= $s2Attr ?>><?= htmlspecialchars($activity['general_objectives'] ?? '') ?></textarea>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Specific Objectives</label>
                  <textarea name="specific_objectives" class="form-control" <?= $s2Attr ?>><?= htmlspecialchars($activity['specific_objectives'] ?? '') ?></textarea>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Involved Subjects (Optional)</label>
                  <input type="text" name="involved_subjects" class="form-control" placeholder="e.g. ITE314, GE102" value="<?= htmlspecialchars($activity['involved_subjects'] ?? '') ?>" <?= $s2Attr ?>>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Rationale (Optional)</label>
                  <textarea name="rationale" class="form-control" placeholder="Why is this activity necessary?" <?= $s2Attr ?>><?= htmlspecialchars($activity['rationale'] ?? '') ?></textarea>
                </div>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next →</button>
          </div>
        </div>

        <!-- STEP 3: Materials -->
        <div class="wizard-panel" id="panel-3">
          <?php $s2Flagged = !empty($revisionSections['materials']); $s2Comment = $revisionSections['materials'] ?? ''; ?>
          <div class="card <?= $s2Flagged ? 'section-flagged' : '' ?>">
            <div class="card-header">
              <h2>📄 Materials Needed</h2>
              <?php if ($s2Editable): ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="addRow('materials')">+ Add Item</button>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if ($s2Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s2Comment) ?></div></div>
              <?php endif; ?>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Item Name</th>
                      <th>Description</th>
                      <th>Qty</th>
                      <th>Provider</th>
                      <th>Est. Cost (₱)</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="materials-body">
                    <?php if (empty($materials)): ?>
                      <tr>
                        <td><input type="text" name="mat_item[]" class="form-control" <?= $s2Attr ?>></td>
                        <td><input type="text" name="mat_desc[]" class="form-control" <?= $s2Attr ?>></td>
                        <td><input type="number" name="mat_qty[]" class="form-control" value="1" <?= $s2Attr ?>></td>
                        <td><input type="text" name="mat_provider[]" class="form-control" <?= $s2Attr ?>></td>
                        <td><input type="number" name="mat_cost[]" class="form-control" value="0" step="0.01" <?= $s2Attr ?>></td>
                        <td>
                          <?php if ($s2Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php else: foreach ($materials as $m): ?>
                        <tr>
                          <td><input type="text" name="mat_item[]" class="form-control" value="<?= htmlspecialchars($m['item_name']) ?>" <?= $s2Attr ?>></td>
                          <td><input type="text" name="mat_desc[]" class="form-control" value="<?= htmlspecialchars($m['description'] ?? '') ?>" <?= $s2Attr ?>></td>
                          <td><input type="number" name="mat_qty[]" class="form-control" value="<?= $m['quantity'] ?>" <?= $s2Attr ?>></td>
                          <td><input type="text" name="mat_provider[]" class="form-control" value="<?= htmlspecialchars($m['provider'] ?? '') ?>" <?= $s2Attr ?>></td>
                          <td><input type="number" name="mat_cost[]" class="form-control" value="<?= $m['est_cost'] ?>" step="0.01" <?= $s2Attr ?>></td>
                          <td>
                            <?php if ($s2Editable): ?>
                              <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                            <?php endif; ?>
                          </td>
                        </tr>
                    <?php endforeach;
                    endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next →</button>
          </div>
        </div>

        <!-- STEP 4: Floor Plan -->
        <div class="wizard-panel" id="panel-4">
          <?php $s4Flagged = !empty($revisionSections['floor_plan']); $s4Comment = $revisionSections['floor_plan'] ?? ''; ?>
          <div class="card <?= $s4Flagged ? 'section-flagged' : '' ?>">
            <div class="card-header">
              <h2>📄 Floor Plan</h2>
            </div>
            <div class="card-body">
              <?php if ($s4Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s4Comment) ?></div></div>
              <?php endif; ?>
              <?php if ($floorplan && !empty($floorplan['canvas_json'])): ?>
                <script>window.FP_INITIAL_JSON = <?= json_encode($floorplan['canvas_json']) ?>;</script>
              <?php endif; ?>
              
              <?php if ($s4Editable): ?>
                <?php include __DIR__ . '/../includes/floorplan-ui.php'; ?>
              <?php else: ?>
                <?php if ($floorplan && !empty($floorplan['file_path'])): ?>
                  <div class="alert alert-info">Current floor plan: <strong><?= basename($floorplan['file_path']) ?></strong></div>
                  <img src="<?= BASE_URL ?>/<?= htmlspecialchars($floorplan['file_path']) ?>" style="max-width:100%; border-radius:8px; border:1px solid #dee2e6;">
                <?php endif; ?>
              <?php endif; ?>

              <div class="form-group" style="margin-top:16px;">
                <label class="form-label">Notes</label>
                <textarea name="floor_plan_notes" class="form-control" <?= $s4Attr ?>><?= htmlspecialchars($floorplan['notes'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next →</button>
          </div>
        </div>

        <!-- STEP 5: People / Manpower -->
        <div class="wizard-panel" id="panel-5">
          <?php $s5Flagged = !empty($revisionSections['people']); $s5Comment = $revisionSections['people'] ?? ''; ?>
          <?php $s5Editable = $s5Flagged || empty($revisionSections); ?>
          <div class="card <?= $s5Flagged ? 'section-flagged' : '' ?>">
            <div class="card-header">
              <h2>📄 People / Manpower</h2>
              <?php if ($s5Editable): ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="addRow('manpower')">+ Add Person</button>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if ($s5Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s5Comment) ?></div></div>
              <?php endif; ?>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Role / Position</th>
                      <th>Assigned Person</th>
                      <th>Type</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="manpower-body">
                    <?php if (empty($manpower)): ?>
                      <tr>
                        <td>
                          <select name="mp_role[]" class="form-control" <?= $s5Editable ? '' : 'disabled' ?>>
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
                        <td><input type="text" name="mp_person[]" class="form-control mp-person-field" <?= $s5Editable ? '' : 'readonly' ?>></td>
                        <td>
                          <select name="mp_type[]" class="form-control" <?= $s5Editable ? '' : 'disabled' ?>>
                            <option value="faculty">Faculty</option>
                            <option value="staff">Staff</option>
                            <option value="student">Student</option>
                          </select>
                        </td>
                        <td>
                          <?php if ($s5Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php else: foreach ($manpower as $m): ?>
                      <tr>
                        <td>
                          <select name="mp_role[]" class="form-control" <?= $s5Editable ? '' : 'disabled' ?>>
                            <option <?= $m['role']=='Medic'?'selected':'' ?>>Medic</option>
                            <option <?= $m['role']=='Customer Service'?'selected':'' ?>>Customer Service</option>
                            <option <?= $m['role']=='Usher'?'selected':'' ?>>Usher</option>
                            <option <?= $m['role']=='Marshall'?'selected':'' ?>>Marshall</option>
                            <option <?= $m['role']=='Emcee'?'selected':'' ?>>Emcee</option>
                            <option <?= $m['role']=='Floor Director'?'selected':'' ?>>Floor Director</option>
                            <option <?= $m['role']=='AV Support'?'selected':'' ?>>AV Support</option>
                            <option <?= $m['role']=='Tabulator'?'selected':'' ?>>Tabulator</option>
                            <option <?= !in_array($m['role'], ['Medic','Customer Service','Usher','Marshall','Emcee','Floor Director','AV Support','Tabulator'])?'selected':'' ?>>Other</option>
                          </select>
                        </td>
                        <td><input type="text" name="mp_person[]" class="form-control mp-person-field" value="<?= htmlspecialchars($m['assigned_person']) ?>" <?= $s5Editable ? '' : 'readonly' ?>></td>
                        <td>
                          <select name="mp_type[]" class="form-control" <?= $s5Editable ? '' : 'disabled' ?>>
                            <option value="faculty" <?= $m['type']=='faculty'?'selected':'' ?>>Faculty</option>
                            <option value="staff" <?= $m['type']=='staff'?'selected':'' ?>>Staff</option>
                            <option value="student" <?= $m['type']=='student'?'selected':'' ?>>Student</option>
                          </select>
                        </td>
                        <td>
                          <?php if ($s5Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next →</button>
          </div>
        </div>

        <!-- STEP 6: Schedule & Program -->
        <div class="wizard-panel" id="panel-6">
          <?php $s6Flagged = !empty($revisionSections['schedule']); $s6Comment = $revisionSections['schedule'] ?? ''; ?>
          
          <div class="card <?= $s6Flagged ? 'section-flagged' : '' ?>" style="margin-bottom: 20px;">
            <div class="card-header">
              <h2>📄 Activity Timeline (Schedule)</h2>
              <?php if ($s6Editable): ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="addRow('schedule')">+ Add Row</button>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if ($s6Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s6Comment) ?></div></div>
              <?php endif; ?>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Event</th>
                      <th>Venue</th>
                      <th>Organizer</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="schedule-body">
                    <?php if (empty($schedules)): ?>
                      <tr>
                        <td><input type="date" name="sched_date[]" class="form-control" <?= $s6Attr ?>></td>
                        <td><input type="text" name="sched_event[]" class="form-control" <?= $s6Attr ?>></td>
                        <td><input type="text" name="sched_venue[]" class="form-control" <?= $s6Attr ?>></td>
                        <td><input type="text" name="sched_organizer[]" class="form-control" <?= $s6Attr ?>></td>
                        <td>
                          <?php if ($s6Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php else: foreach ($schedules as $s): ?>
                        <tr>
                          <td><input type="date" name="sched_date[]" class="form-control" value="<?= $s['sched_date'] ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="sched_event[]" class="form-control" value="<?= htmlspecialchars($s['event_name']) ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="sched_venue[]" class="form-control" value="<?= htmlspecialchars($s['venue'] ?? '') ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="sched_organizer[]" class="form-control" value="<?= htmlspecialchars($s['organizer'] ?? '') ?>" <?= $s6Attr ?>></td>
                          <td>
                            <?php if ($s6Editable): ?>
                              <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                            <?php endif; ?>
                          </td>
                        </tr>
                    <?php endforeach;
                    endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="card <?= $s6Flagged ? 'section-flagged' : '' ?>">
            <div class="card-header">
              <h2>📄 Program Flow</h2>
              <?php if ($s6Editable): ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="addRow('program')">+ Add Segment</button>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Time</th>
                      <th>Segment</th>
                      <th>Description</th>
                      <th>Person In-Charge</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="program-body">
                    <?php if (empty($program)): ?>
                      <tr>
                        <td><input type="time" name="prog_time[]" class="form-control" <?= $s6Attr ?>></td>
                        <td><input type="text" name="prog_segment[]" class="form-control" <?= $s6Attr ?>></td>
                        <td><input type="text" name="prog_desc[]" class="form-control" <?= $s6Attr ?>></td>
                        <td><input type="text" name="prog_pic[]" class="form-control" <?= $s6Attr ?>></td>
                        <td>
                          <?php if ($s6Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php else: foreach ($program as $p): ?>
                        <tr>
                          <td><input type="time" name="prog_time[]" class="form-control" value="<?= $p['time_slot'] ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="prog_segment[]" class="form-control" value="<?= htmlspecialchars($p['segment']) ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="prog_desc[]" class="form-control" value="<?= htmlspecialchars($p['description'] ?? '') ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="prog_pic[]" class="form-control" value="<?= htmlspecialchars($p['person_ic'] ?? '') ?>" <?= $s6Attr ?>></td>
                          <td>
                            <?php if ($s6Editable): ?>
                              <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                            <?php endif; ?>
                          </td>
                        </tr>
                    <?php endforeach;
                    endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next →</button>
          </div>
        </div>

        <!-- STEP 7: Guidelines -->
        <div class="wizard-panel" id="panel-7">
          <?php $s7Flagged = !empty($revisionSections['guidelines']); $s7Comment = $revisionSections['guidelines'] ?? ''; ?>
          <div class="card <?= $s7Flagged ? 'section-flagged' : '' ?>">
            <div class="card-header">
              <h2>📄 Event Guidelines</h2>
            </div>
            <div class="card-body">
              <?php if ($s7Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s7Comment) ?></div></div>
              <?php endif; ?>
              <div class="form-group">
                <label class="form-label">Mechanics / Guidelines</label>
                <textarea name="guidelines_mechanics" class="form-control" style="min-height:100px" <?= $s7Attr ?>><?= htmlspecialchars($guidelines['mechanics'] ?? '') ?></textarea>
              </div>
              <div class="form-group">
                <label class="form-label">Criteria for Judging</label>
                <textarea name="guidelines_criteria" class="form-control" <?= $s7Attr ?>><?= htmlspecialchars($guidelines['criteria'] ?? '') ?></textarea>
              </div>
              <div class="form-group">
                <label class="form-label">Scoring System</label>
                <textarea name="guidelines_scoring" class="form-control" <?= $s7Attr ?>><?= htmlspecialchars($guidelines['scoring_system'] ?? '') ?></textarea>
              </div>
              <div class="form-group">
                <label class="form-label">Special Awards</label>
                <textarea name="guidelines_awards" class="form-control" <?= $s7Attr ?>><?= htmlspecialchars($guidelines['special_awards'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next →</button>
          </div>
        </div>

        <!-- STEP 8: Faculty Tasks -->
        <div class="wizard-panel" id="panel-8">
          <?php $s8Flagged = !empty($revisionSections['faculty_tasks']); $s8Comment = $revisionSections['faculty_tasks'] ?? ''; ?>
          <div class="card <?= $s8Flagged ? 'section-flagged' : '' ?>">
            <div class="card-header">
              <h2>📄 Faculty Tasks &amp; Contributions</h2>
              <?php if ($s8Editable): ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="addRow('faculty-tasks')">+ Add Faculty</button>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if ($s8Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s8Comment) ?></div></div>
              <?php endif; ?>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Faculty Name</th>
                      <th>Assigned Task</th>
                      <th>Contribution</th>
                      <th>Role</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="faculty-tasks-body">
                    <?php if (empty($ftasks)): ?>
                      <tr>
                        <td><input type="text" name="ft_name[]" class="form-control" <?= $s8Attr ?>></td>
                        <td><input type="text" name="ft_task[]" class="form-control" <?= $s8Attr ?>></td>
                        <td><input type="text" name="ft_contribution[]" class="form-control" <?= $s8Attr ?>></td>
                        <td><input type="text" name="ft_role[]" class="form-control" <?= $s8Attr ?>></td>
                        <td>
                          <?php if ($s8Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php else: foreach ($ftasks as $f): ?>
                        <tr>
                          <td><input type="text" name="ft_name[]" class="form-control" value="<?= htmlspecialchars($f['faculty_name']) ?>" <?= $s8Attr ?>></td>
                          <td><input type="text" name="ft_task[]" class="form-control" value="<?= htmlspecialchars($f['assigned_task'] ?? '') ?>" <?= $s8Attr ?>></td>
                          <td><input type="text" name="ft_contribution[]" class="form-control" value="<?= htmlspecialchars($f['contribution_desc'] ?? '') ?>" <?= $s8Attr ?>></td>
                          <td><input type="text" name="ft_role[]" class="form-control" value="<?= htmlspecialchars($f['role_in_event'] ?? '') ?>" <?= $s8Attr ?>></td>
                          <td>
                            <?php if ($s8Editable): ?>
                              <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                            <?php endif; ?>
                          </td>
                        </tr>
                    <?php endforeach;
                    endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <button type="button" class="btn btn-primary" onclick="nextStep()">Next →</button>
          </div>
        </div>

        <!-- STEP 9: KPI & Evaluation -->
        <div class="wizard-panel" id="panel-9">
          <?php $s9Flagged = !empty($revisionSections['kpi']); $s9Comment = $revisionSections['kpi'] ?? ''; ?>
          <div class="card <?= $s9Flagged ? 'section-flagged' : '' ?>">
            <div class="card-header">
              <h2>📈 Key Performance Indicators</h2>
              <?php if ($s9Editable): ?>
                <button type="button" class="btn btn-outline btn-sm" onclick="addRow('kpi')">+ Add KPI</button>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if ($s9Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s9Comment) ?></div></div>
              <?php endif; ?>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Criteria / Indicator</th>
                      <th>Target Metric</th>
                      <th>Evaluation Method</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="kpi-body">
                    <?php if (empty($kpis)): ?>
                      <tr>
                        <td><input type="text" name="kpi_indicator[]" class="form-control kpi-field" placeholder="e.g. Number of Attendees" <?= $s9Attr ?>></td>
                        <td><input type="text" name="kpi_target[]" class="form-control kpi-field" placeholder="e.g. 500 Participants" <?= $s9Attr ?>></td>
                        <td><input type="text" name="kpi_method[]" class="form-control kpi-field" placeholder="e.g. Attendance Sheet" <?= $s9Attr ?>></td>
                        <td>
                          <?php if ($s9Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php else: foreach ($kpis as $k): ?>
                      <tr>
                        <td><input type="text" name="kpi_indicator[]" class="form-control kpi-field" value="<?= htmlspecialchars($k['indicator']) ?>" <?= $s9Attr ?>></td>
                        <td><input type="text" name="kpi_target[]" class="form-control kpi-field" value="<?= htmlspecialchars($k['target_metric']) ?>" <?= $s9Attr ?>></td>
                        <td><input type="text" name="kpi_method[]" class="form-control kpi-field" value="<?= htmlspecialchars($k['evaluation_method']) ?>" <?= $s9Attr ?>></td>
                        <td>
                          <?php if ($s9Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>

              <div style="margin-top:20px;">
                <label class="form-label">Google Forms Evaluation Link *</label>
                <input type="url" name="evaluation_method" id="f9_eval_form_link" class="form-control" placeholder="https://forms.gle/..." required value="<?= htmlspecialchars($activity['evaluation_method'] ?? '') ?>" <?= $s9Attr ?>>
                <small style="color:var(--text-muted)">Required for attendees to provide feedback post-event.</small>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div class="flex gap-2">
              <button type="submit" name="action" value="draft" class="btn btn-outline" formnovalidate>💾 Save as Draft</button>
              <button type="submit" name="action" value="submit" class="btn btn-primary">🚀 Resubmit for Approval</button>
            </div>
          </div>
        </div>

      </form>
    </div>
  </div>
  <script>
    let currentStep = 1;
    const totalSteps = 9;
    const flaggedSteps = <?= json_encode($flaggedSteps) ?>;

    function showStep(n) {
      document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.wizard-step').forEach((s, i) => {
        s.classList.remove('active', 'done');
        if (i + 1 < n) s.classList.add('done');
        if (i + 1 === n) s.classList.add('active');
      });
      document.getElementById('panel-' + n).classList.add('active');
      currentStep = n;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function nextStep() {
      if (currentStep < totalSteps) showStep(currentStep + 1);
    }

    function prevStep() {
      if (currentStep > 1) showStep(currentStep - 1);
    }
    document.querySelectorAll('.wizard-step').forEach(s => s.addEventListener('click', () => showStep(parseInt(s.dataset.step))));

    // Mark flagged steps in the wizard indicator
    flaggedSteps.forEach(n => {
      const stepEl = document.querySelector(`.wizard-step[data-step="${n}"]`);
      if (stepEl) stepEl.classList.add('flagged');
    });

    // Auto-navigate to the first flagged step
    if (flaggedSteps.length > 0) showStep(flaggedSteps[0]);

    function addRow(table) {
      const tbody = document.getElementById(table + '-body');
      const clone = tbody.querySelector('tr').cloneNode(true);
      clone.querySelectorAll('input').forEach(el => el.value = '');
      tbody.appendChild(clone);
    }

    function removeRow(btn) {
      const tr = btn.closest('tr');
      if (tr.parentElement.querySelectorAll('tr').length > 1) tr.remove();
    }
  </script>
<script src="https://unpkg.com/konva@9/konva.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/floorplan.js"></script>
</body>

</html>