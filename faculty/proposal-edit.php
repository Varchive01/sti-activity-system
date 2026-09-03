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
  <style>
    .proposal-layout-grid {
      display: grid;
      grid-template-columns: 1.8fr 1.2fr;
      gap: 24px;
      align-items: start;
    }
    @media (max-width: 1200px) {
      .proposal-layout-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
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

            <form method="POST" id="proposalForm" action="<?= BASE_URL ?>/api/proposal-update.php" enctype="multipart/form-data">
        <input type="hidden" name="activity_id" value="<?= $id ?>">
        <div class="proposal-layout-grid">
          <div class="proposal-wizard-container">

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
    let questionsList = <?= !empty($activity['evaluation_questions']) ? $activity['evaluation_questions'] : '[]' ?>;
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

    // Override init for edit page
    setTimeout(() => {
      setupEvaluationAutoUpdateListeners();
      serializeEvaluationQuestions();
      renderQuestions();
      if (questionsList.length > 0) {
        setEvaluationState('READY');
      } else {
        setEvaluationState('EMPTY');
      }
      // Set last state to prevent instant warning trigger on edit load
      lastEvaluationState = getObjectivesAndKpisState();
    }, 100);
    
  </script>
<script src="https://unpkg.com/konva@9/konva.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/floorplan.js"></script>
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