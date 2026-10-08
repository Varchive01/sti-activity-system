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

$todayManila = date('Y-m-d');
$tomorrowManila = date('Y-m-d', strtotime('+1 day'));

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
      width: 100% !important;
      height: 100% !important;
      max-width: none !important;
      max-height: none !important;
      margin: 0 !important;
      padding: 0 !important;
      border: none !important;
      border-radius: 0 !important;
      box-shadow: none !important;
      overflow-x: hidden;
      overflow-y: auto;
      outline: 0;
      background: rgba(10, 22, 40, 0.6) !important;
      animation: none !important;
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
      margin: 1.75rem auto;
      pointer-events: none;
    }
    .modal-dialog-centered {
      display: flex;
      align-items: center;
      min-height: calc(100% - 3.5rem);
    }
    .modal-dialog-centered::before {
      display: block;
      height: calc(100vh - 3.5rem);
      height: min-content;
      content: "";
    }
    @media (max-width: 575.98px) {
      .modal-dialog {
        margin: 0.5rem;
      }
      .modal-dialog-centered {
        min-height: calc(100% - 1rem);
      }
      .modal-dialog-centered::before {
        height: calc(100vh - 1rem);
      }
    }
    @media (min-width: 576px) {
      .modal-dialog {
        max-width: 500px;
      }
    }
    @media (min-width: 992px) {
      .modal-lg {
        max-width: 800px;
      }
    }
    @media (min-width: 1200px) {
      .modal-xl {
        max-width: 1050px;
      }
    }
    #aiProposalValidationModal .modal-dialog {
      width: calc(100% - 3.5rem);
      max-width: 1050px;
      margin: 1.75rem auto;
    }
    @media (max-width: 768px) {
      #aiProposalValidationModal .modal-dialog {
        width: calc(100% - 1.5rem);
        margin: 0.75rem auto;
      }
    }
    .modal-content {
      position: relative;
      display: flex;
      flex-direction: column;
      width: 100%;
      pointer-events: auto;
      background-color: var(--bg-card, #fff);
      background-clip: padding-box;
      border: 1px solid var(--border);
      border-radius: var(--radius);
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
      background: var(--warning-lt, #fff3cd);
      border: 1px solid var(--warning, #ffc107);
      color: var(--text-main, #856404);
      padding: 10px 16px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-size: .875rem;
    }

    .step-error-banner.visible {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    [data-theme="dark"] .step-error-banner {
      background: rgba(217, 119, 6, 0.15) !important;
      border-color: rgba(217, 119, 6, 0.35) !important;
      color: #FCD34D !important;
    }

    /* ── Wizard step navigation ── */
    .wizard-steps {
      background: var(--bg-card) !important;
      border: 1px solid var(--border) !important;
    }

    .wizard-step {
      background: var(--bg-card) !important;
      color: var(--text-muted) !important;
      border-right: 1px solid var(--border) !important;
      transition: background-color 0.2s ease, color 0.2s ease;
    }

    .wizard-step .step-num {
      background: var(--border) !important;
      color: var(--text-muted) !important;
    }

    .wizard-step.active {
      background: var(--sti-blue-lt, #E0F2FE) !important;
      color: var(--sti-blue) !important;
    }

    .wizard-step.active .step-num {
      background: var(--sti-blue) !important;
      color: #ffffff !important;
    }

    .wizard-step.done {
      color: var(--sti-blue) !important;
    }

    .wizard-step.done .step-num {
      background: var(--sti-blue) !important;
      color: #ffffff !important;
    }

    /* Flagged step indicator */
    .wizard-step.flagged .step-num { background: #F59E0B !important; color: #fff !important; }
    .wizard-step.flagged { color: #92400E !important; }

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

    [data-theme="dark"] .wizard-step.active {
      background: rgba(2, 132, 199, 0.18) !important;
      color: #38BDF8 !important;
    }

    [data-theme="dark"] .wizard-step.active .step-num {
      background: var(--sti-blue) !important;
      color: #ffffff !important;
    }

    [data-theme="dark"] .wizard-step.done {
      color: #38BDF8 !important;
    }

    [data-theme="dark"] .wizard-step.done .step-num {
      background: var(--sti-blue) !important;
      color: #ffffff !important;
    }

    [data-theme="dark"] .wizard-step.flagged {
      color: #FCD34D !important;
    }

    [data-theme="dark"] .wizard-step.flagged .step-num {
      background: #F59E0B !important;
      color: #ffffff !important;
    }

    [data-theme="dark"] .wizard-step:not(.locked):hover {
      background: rgba(255, 255, 255, 0.03) !important;
    }

    [data-theme="dark"] .reviewer-comment-banner {
      background: rgba(245, 158, 11, 0.15) !important;
      border-left-color: #F59E0B !important;
      color: #FCD34D !important;
    }

    /* ── Materials total ── */
    .materials-total-row {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 12px;
      margin-top: 12px;
      padding: 10px 12px;
      background: var(--bg-base);
      border-radius: var(--radius-sm, 6px);
      border: 1px solid var(--border);
    }
    .materials-total-row label {
      font-weight: 600;
      font-size: .9rem;
      color: var(--text-muted);
    }
    .materials-total-row span {
      font-weight: 700;
      font-size: 1.05rem;
      color: var(--text-main);
      min-width: 100px;
      text-align: right;
    }

    [data-theme="dark"] .materials-total-row {
      background: var(--bg-card-elevated) !important;
      border-color: var(--border) !important;
    }
    [data-theme="dark"] .materials-total-row label {
      color: var(--text-muted) !important;
    }
    [data-theme="dark"] .materials-total-row span {
      color: var(--text-main) !important;
    }

    /* ── Preset STI Address button ── */
    .preset-address-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--bg-base, #f8fafc);
      border: 1px solid var(--border, #cbd5e1);
      border-radius: var(--radius-sm, 6px);
      padding: 5px 12px;
      font-size: 0.78rem;
      color: var(--sti-blue, #0284c7);
      cursor: pointer;
      text-align: left;
      line-height: 1.35;
      transition: all 0.15s ease;
      font-family: inherit;
      font-weight: 500;
    }

    .preset-address-btn:hover {
      background: var(--sti-blue-lt, #e0f2fe);
      border-color: var(--sti-blue, #0284c7);
      color: var(--sti-blue-hover, #0369a1);
    }

    [data-theme="dark"] .preset-address-btn {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
      color: #38bdf8 !important;
    }

    [data-theme="dark"] .preset-address-btn:hover {
      background: rgba(2, 132, 199, 0.2) !important;
      border-color: #38bdf8 !important;
      color: #ffffff !important;
    }

    /* ── Form Controls Design System Consistency ── */
    .content .form-control,
    .content input[type="text"],
    .content input[type="email"],
    .content input[type="password"],
    .content input[type="date"],
    .content input[type="time"],
    .content input[type="number"],
    .content input[type="search"],
    .content select.form-control,
    .content select,
    .content textarea.form-control,
    .content textarea,
    .eval-question-card .form-control,
    .eval-question-card textarea,
    .eval-question-card select {
      background: var(--bg-card);
      color: var(--text-main);
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm, 8px);
      box-sizing: border-box;
      transition: border-color .18s ease, box-shadow .18s ease, background-color .18s ease;
    }

    [data-theme="dark"] .content .form-control,
    [data-theme="dark"] .content input[type="text"],
    [data-theme="dark"] .content input[type="email"],
    [data-theme="dark"] .content input[type="password"],
    [data-theme="dark"] .content input[type="date"],
    [data-theme="dark"] .content input[type="time"],
    [data-theme="dark"] .content input[type="number"],
    [data-theme="dark"] .content input[type="search"],
    [data-theme="dark"] .content select.form-control,
    [data-theme="dark"] .content select,
    [data-theme="dark"] .content textarea.form-control,
    [data-theme="dark"] .content textarea,
    [data-theme="dark"] .eval-question-card .form-control,
    [data-theme="dark"] .eval-question-card textarea,
    [data-theme="dark"] .eval-question-card select {
      background: var(--bg-card-elevated, #15243C) !important;
      color: var(--text-main, #F8FAFC) !important;
      border-color: var(--border, #1E2D45) !important;
    }

    [data-theme="dark"] .eval-question-card textarea.eval-question-input,
    [data-theme="dark"] .eval-question-card select.eval-type-select {
      background: var(--bg-card, #0F1B2E) !important;
      color: var(--text-main, #F8FAFC) !important;
      border-color: var(--border, #1E2D45) !important;
    }

    .content .form-control::placeholder,
    .content input::placeholder,
    .content textarea::placeholder,
    .eval-question-card textarea::placeholder {
      color: var(--text-muted);
      opacity: 0.75;
    }

    .content .form-control:focus,
    .content input:focus,
    .content select:focus,
    .content textarea:focus,
    .eval-question-card textarea:focus,
    .eval-question-card select:focus {
      border-color: var(--sti-blue) !important;
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.18) !important;
      outline: none;
    }

    .content select option,
    .eval-question-card select option {
      background: var(--bg-card, #FFFFFF);
      color: var(--text-main);
    }

    [data-theme="dark"] .content select option,
    [data-theme="dark"] .eval-question-card select option {
      background: var(--bg-card-elevated, #15243C);
      color: var(--text-main, #F8FAFC);
    }

    /* ── Dynamic Tables in Wizard Steps ── */
    .dynamic-table {
      border: 1px solid var(--border) !important;
      border-radius: var(--radius-sm, 8px) !important;
      overflow: hidden;
      background: var(--bg-card);
    }

    .dynamic-table table th {
      background: var(--bg-base) !important;
      color: var(--text-muted) !important;
      font-weight: 700 !important;
      font-size: 0.76rem !important;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: 1px solid var(--border) !important;
      padding: 10px 12px !important;
    }

    .dynamic-table table td {
      border-bottom: 1px solid var(--border) !important;
      background: var(--bg-card) !important;
      padding: 8px 10px !important;
      vertical-align: middle;
    }

    [data-theme="dark"] .dynamic-table table th {
      background: var(--bg-card-elevated, #15243C) !important;
      border-bottom-color: var(--border, #1E2D45) !important;
      color: var(--text-muted, #8899B2) !important;
    }

    [data-theme="dark"] .dynamic-table table td {
      background: var(--bg-card, #0F1B2E) !important;
      border-bottom-color: var(--border, #1E2D45) !important;
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
      background: var(--bg-card, #ffffff);
      padding: 12px;
      border: 1px solid var(--border, #dee2e6);
      border-radius: var(--radius-sm, 8px);
      transition: all 0.2s ease;
    }
    .eval-question-row:hover {
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
      border-color: var(--sti-blue);
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
      border-color: var(--sti-blue) !important;
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
    [data-theme="dark"] .eval-delete-btn {
      background: rgba(220, 38, 38, 0.1);
      border-color: rgba(220, 38, 38, 0.3);
      color: #f87171;
    }
    [data-theme="dark"] .eval-delete-btn:hover {
      background-color: rgba(220, 38, 38, 0.2);
      border-color: #f87171;
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
    @media (max-width: 1200px) {
      .proposal-layout-grid {
        grid-template-columns: 1fr;
      }
    }

    /* ── AI Evaluation Tool Side Panel ── */
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
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius, 14px);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
      padding: 0;
    }
    .proposal-eval-side-panel .card-header {
      background: var(--bg-card) !important;
      border-bottom: 1px solid var(--border) !important;
      padding: 14px 18px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0;
    }
    .proposal-eval-side-panel .card-header h2 {
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text-main) !important;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .proposal-eval-side-panel .card-body {
      overflow-y: auto;
      flex: 1;
      padding: 16px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      max-height: calc(100vh - 220px);
    }

    #evalStatusBadge {
      font-size: 0.7rem;
      font-weight: 600;
      color: var(--text-muted);
      background: var(--bg-base);
      border: 1px solid var(--border);
      padding: 2px 8px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    #evalStatusIndicator {
      width: 6px;
      height: 6px;
      background: var(--text-muted);
      border-radius: 50%;
      display: inline-block;
    }
    [data-theme="dark"] #evalStatusBadge {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
      color: var(--text-secondary, #CBD5E1) !important;
    }

    .proposal-eval-side-panel .info-alert {
      font-size: 0.775rem;
      padding: 10px 14px;
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      color: #0369a1;
      border-radius: var(--radius-sm, 8px);
      line-height: 1.45;
      margin-bottom: 4px;
    }
    [data-theme="dark"] .proposal-eval-side-panel .info-alert {
      background: rgba(2, 132, 199, 0.12) !important;
      border-color: rgba(2, 132, 199, 0.28) !important;
      color: #38bdf8 !important;
    }

    .proposal-eval-side-panel .btn-add-manual-q {
      border: 1px dashed var(--border);
      font-size: 0.8rem;
      padding: 7px 12px;
      border-radius: var(--radius-sm, 8px);
      background: var(--bg-base);
      color: var(--text-main);
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      width: 100%;
      height: auto;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .proposal-eval-side-panel .btn-add-manual-q:hover {
      border-color: var(--sti-blue);
      color: var(--sti-blue);
      background: rgba(2, 132, 199, 0.08);
    }
    [data-theme="dark"] .proposal-eval-side-panel .btn-add-manual-q {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
      color: var(--text-main, #F8FAFC) !important;
    }
    [data-theme="dark"] .proposal-eval-side-panel .btn-add-manual-q:hover {
      border-color: var(--sti-blue-hover, #38bdf8) !important;
      color: var(--sti-blue-hover, #38bdf8) !important;
      background: rgba(2, 132, 199, 0.15) !important;
    }

    [data-theme="dark"] .proposal-eval-side-panel .card {
      background: var(--bg-card) !important;
      border-color: var(--border) !important;
    }
    [data-theme="dark"] .proposal-eval-side-panel .card-header {
      background: var(--bg-card) !important;
      border-bottom-color: var(--border) !important;
    }
    [data-theme="dark"] .proposal-eval-side-panel .card-header h2 {
      color: var(--text-main) !important;
    }

    .eval-question-card {
      background: var(--bg-card, #ffffff);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: var(--radius-sm, 8px);
      padding: 12px 14px;
      transition: all 0.2s ease;
      box-shadow: var(--shadow-sm);
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 10px;
    }
    .eval-question-card:hover {
      box-shadow: 0 4px 12px rgba(0,0,0,0.06);
      border-color: var(--sti-blue);
    }
    .eval-question-card.editing {
      border-color: var(--sti-blue);
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
    }
    .eval-question-card .eval-question-body {
      font-size: 0.85rem;
      line-height: 1.45;
      color: var(--text-main);
      margin-bottom: 8px;
      font-weight: 500;
      word-break: break-word;
    }
    .eval-question-card .eval-question-num {
      font-weight: 600;
      font-size: 0.8rem;
      color: var(--text-muted);
    }
    .eval-question-card .eval-card-actions {
      display: flex;
      gap: 12px;
      font-size: 0.75rem;
      border-top: 1px dashed var(--border);
      padding-top: 6px;
    }
    .badge-type-rating {
      background: var(--sti-blue-lt, #e0f2fe);
      color: var(--sti-blue, #0369a1);
    }
    .badge-type-text {
      background: var(--border-light, #f3f4f6);
      color: var(--text-secondary, #4b5563);
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

    [data-theme="dark"] .eval-question-card {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] .eval-question-card:hover {
      border-color: var(--sti-blue-hover, #38bdf8) !important;
      box-shadow: 0 4px 12px rgba(0,0,0,0.3) !important;
    }
    [data-theme="dark"] .badge-type-rating {
      background: rgba(2, 132, 199, 0.2) !important;
      color: #38bdf8 !important;
    }
    [data-theme="dark"] .badge-type-text {
      background: rgba(148, 163, 184, 0.15) !important;
      color: #cbd5e1 !important;
    }

    [data-theme="dark"] #evalGenStatus {
      background: rgba(2, 132, 199, 0.15) !important;
      color: #38bdf8 !important;
      border: 1px solid rgba(2, 132, 199, 0.3) !important;
      border-radius: var(--radius-sm, 8px) !important;
    }
    [data-theme="dark"] #evalChangeNotice {
      background: rgba(217, 119, 6, 0.15) !important;
      border-color: rgba(217, 119, 6, 0.3) !important;
      border-left: 4px solid #f59e0b !important;
      color: #fcd34d !important;
    }
    [data-theme="dark"] #err-banner-eval-side {
      background: rgba(220, 38, 38, 0.15) !important;
      border-color: rgba(220, 38, 38, 0.3) !important;
      color: #f87171 !important;
    }

    /* ── Proposal Step 9 Conflict Banner ── */
    [data-theme="dark"] #scheduleConflictBannerStep9 {
      background: rgba(220, 38, 38, 0.12) !important;
      border-color: rgba(220, 38, 38, 0.3) !important;
      border-left-color: #ef4444 !important;
      color: #fca5a5 !important;
    }
    [data-theme="dark"] #scheduleConflictBannerStep9 .scu-alt-wrap > div:first-child {
      color: #fca5a5 !important;
    }

    /* ── Modals Consistency ── */
    [data-theme="dark"] #previewModal .modal-content {
      background: var(--bg-card, #0F1B2E) !important;
      border-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] #previewModal .modal-header,
    [data-theme="dark"] #previewModal .modal-footer {
      border-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] #previewModal .modal-title {
      color: var(--text-main, #F8FAFC) !important;
    }
    [data-theme="dark"] #previewModal .preview-modal-info-box {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] #previewModalActivityTitle {
      color: var(--text-main, #F8FAFC) !important;
    }
    [data-theme="dark"] .preview-question-card {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] .preview-question-card textarea {
      background: var(--bg-card, #0F1B2E) !important;
      border-color: var(--border, #1E2D45) !important;
      color: var(--text-main, #F8FAFC) !important;
    }

    [data-theme="dark"] #scheduleConflictModal .modal-content {
      background: var(--bg-card, #0F1B2E) !important;
    }
    [data-theme="dark"] #scheduleConflictModal .modal-body {
      background: var(--bg-base, #070E18) !important;
    }
    [data-theme="dark"] #scheduleConflictModal .modal-body > div {
      background: var(--bg-card, #0F1B2E) !important;
      border-color: var(--border, #1E2D45) !important;
    }
  </style>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/floorplan.css?v=<?= filemtime(__DIR__ . '/../assets/css/floorplan.css') ?>">
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title">Edit Proposal</div>
      <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <a href="<?= BASE_URL ?>/faculty/proposal-view.php?id=<?= $id ?>" class="btn btn-outline btn-sm">← Cancel</a>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
    </header>
    <div class="content">

      <?php if (isset($_GET['error']) && $_GET['error'] === 'past_date'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Invalid Date:</strong> Event date must be tomorrow or a future date. Today and past dates are not permitted.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'missing_date'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Missing Date:</strong> Please select an event date before submitting.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'conflict'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Schedule Conflict:</strong> The selected venue is already booked for an approved activity at this date and time. Please check and try again.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_time'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Invalid Time:</strong> End time must be later than the start time. Overnight activities are not supported.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'missing_time'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Missing Time:</strong> Both start time and end time are required before submitting.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_poster_format'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Invalid Poster Format:</strong> Event poster must be an image (JPG, PNG, WEBP, GIF). Videos and non-image files are not permitted.
        </div>
      <?php elseif (isset($_GET['error'])): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Error:</strong> An error occurred while saving the proposal. Please try again.
        </div>
      <?php endif; ?>

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

      <form method="POST" id="proposalForm" action="<?= BASE_URL ?>/api/proposal-update.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="activity_id" value="<?= $id ?>">
        <input type="hidden" name="action" id="proposalFormAction" value="draft">
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
              <h2>📄 Event Proposal</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-1">⚠️ Please fill in all required fields marked with * before continuing.</div>
              <?php if ($s1Flagged): ?>
                <div class="reviewer-comment-banner">
                  <span class="rc-icon">⚠️</span>
                  <div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s1Comment) ?></div>
                </div>
              <?php endif; ?>
              <div class="form-grid">
                <div class="form-group col-span-2">
                  <label class="form-label">Event Name / Title <span class="text-danger">*</span></label>
                  <input type="text" name="title" id="f1_title" class="form-control" placeholder="e.g. Foundation Day Celebration 2025" required value="<?= htmlspecialchars($activity['title'] ?? '') ?>" <?= $s1Attr ?>>
                  <span class="field-error" id="e_title">Event title is required.</span>
                </div>
                <div class="form-group">
                  <label class="form-label">Date of Event <span class="text-danger">*</span></label>
                  <input type="date" name="event_date" id="f1_event_date" class="form-control" required value="<?= $activity['event_date'] ?? '' ?>" <?= $s1Attr ?> <?= $s1Editable ? 'min="' . $tomorrowManila . '"' : '' ?>>
                  <span class="field-error" id="e_event_date">Event date is required.</span>
                  <span class="field-error" id="e_event_date_past">Event date must be tomorrow or a future date.</span>
                </div>
                <div class="form-group">
                  <label class="form-label">Source <span class="text-danger">*</span></label>
                  <select name="source" id="f1_source" class="form-control" required <?= $s1SelectStyle ?>>
                    <option value="" <?= empty($activity['source']) ? 'selected' : '' ?>>Select source...</option>
                    <option value="student_org" <?= ($activity['source'] ?? '') === 'student_org' ? 'selected' : '' ?>>Student Organization</option>
                    <option value="faculty" <?= ($activity['source'] ?? '') === 'faculty' ? 'selected' : '' ?>>Faculty</option>
                  </select>
                  <span class="field-error" id="e_source">Please select a source.</span>
                </div>
                <div class="form-group">
                  <label class="form-label">Start Time <span class="text-danger">*</span></label>
                  <input type="time" name="start_time" id="f1_start_time" class="form-control" value="<?= $activity['start_time'] ?? '' ?>" <?= $s1Attr ?>>
                  <span class="field-error" id="e_start_time">Start time is required.</span>
                </div>
                <div class="form-group">
                  <label class="form-label">End Time <span class="text-danger">*</span></label>
                  <input type="time" name="end_time" id="f1_end_time" class="form-control" value="<?= $activity['end_time'] ?? '' ?>" <?= $s1Attr ?>>
                  <span class="field-error" id="e_end_time">End time is required.</span>
                  <span class="field-error" id="e_end_time_order">End time must be later than the start time.</span>
                </div>
                <div class="form-group">
                  <label class="form-label">Venue <span class="text-danger">*</span></label>
                  <input type="text" name="venue" id="f1_venue" class="form-control" placeholder="e.g. STI Marikina Auditorium" required value="<?= htmlspecialchars($activity['venue'] ?? '') ?>" <?= $s1Attr ?>>
                  <span class="field-error" id="e_venue">Venue is required.</span>
                </div>
                <div class="form-group">
                  <label class="form-label">Target Participants <span class="text-danger">*</span></label>
                  <input type="number" name="target_participants" id="f1_target_participants" class="form-control" placeholder="e.g. 200" min="1" value="<?= $activity['target_participants'] ?? '' ?>" <?= $s1Attr ?>>
                  <span class="field-error" id="e_target_participants">Target participants is required.</span>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label" for="f1_venue_address">Venue Address <span class="text-danger">*</span></label>
                  <div style="margin-top: -2px; margin-bottom: 6px;">
                    <button type="button" 
                      id="preset_sti_address"
                      class="preset-address-btn" 
                      onclick="usePresetAddress('289 L. de Guzman Street, Concepcion I, Marikina City, 1807 Metro Manila')"
                      <?= $s1Editable ? '' : 'disabled' ?>
                      title="Click to insert default STI address">
                      <span style="font-size: 0.85rem;">📍</span> 289 L. de Guzman Street, Concepcion I, Marikina City, 1807 Metro Manila
                    </button>
                  </div>
                  <input type="text" name="venue_address" id="f1_venue_address" class="form-control" placeholder="Full address" value="<?= htmlspecialchars($activity['venue_address'] ?? '') ?>" <?= $s1Attr ?>>
                  <span class="field-error" id="e_venue_address">Venue address is required.</span>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Theme</label>
                  <div class="theme-input-wrapper" style="position: relative; width: 100%;">
                    <input type="text" name="theme" id="f1_theme" class="form-control" placeholder="e.g. Fostering Innovation Through Technology" value="<?= htmlspecialchars($activity['theme'] ?? '') ?>" <?= $s1Attr ?> style="padding-right: 42px;" aria-busy="false">
                    <?php if ($s1Editable): ?>
                    <div id="themeInputSpinner" style="display: none; position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #0284c7; align-items: center; justify-content: center;" aria-hidden="true">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="animation: spin 0.8s linear infinite;">
                        <circle cx="12" cy="12" r="9" stroke-opacity="0.25"></circle>
                        <path d="M12 3a9 9 0 0 1 9 9"></path>
                      </svg>
                    </div>
                    <?php endif; ?>
                  </div>
                  <?php if ($s1Editable): ?>
                  <!-- Automatic AI Theme Status -->
                  <div id="themeAiStatus" style="margin-top: 6px;"></div>
                  <?php endif; ?>
                  <span class="field-error" id="e_theme">Theme is required.</span>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Event Poster <?php if(empty($activity['poster_path'])) echo '<span class="text-danger">*</span>'; ?></label>
                  <?php if (!empty($activity['poster_path'])): ?>
                    <div style="margin-bottom:8px;">
                      <?php $posterUrl = str_starts_with($activity['poster_path'], 'http') ? $activity['poster_path'] : BASE_URL . '/' . $activity['poster_path']; ?>
                      <a href="<?= htmlspecialchars($posterUrl) ?>" target="_blank" class="btn btn-outline btn-sm">View Current Poster</a>
                    </div>
                    <input type="file" name="poster_file" id="f1_poster_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" <?= $s1Attr ?>>
                    <small style="color: var(--text-muted);">Upload a new image poster to replace the current one (JPG, PNG, WEBP, GIF). Videos are not allowed.</small>
                  <?php else: ?>
                    <input type="file" name="poster_file" id="f1_poster_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" required <?= $s1Attr ?>>
                    <small style="color: var(--text-muted);">Upload an image poster for this event (JPG, PNG, WEBP, GIF). Videos are not allowed.</small>
                  <?php endif; ?>
                  <span class="field-error" id="e_poster_file">Activity poster is required.</span>
                </div>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <div></div><button type="button" class="btn btn-primary" onclick="nextStep()">Next: Objectives →</button>
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
              <div class="step-error-banner" id="err-banner-2">⚠️ Please fill in all required fields before continuing.</div>
              <?php if ($s2Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s2Comment) ?></div></div>
              <?php endif; ?>

              <!-- AI Objectives Status -->
              <div id="objGenStatus" style="display:none;margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:.875rem;border:1px solid transparent;"></div>

              <div class="form-grid">
                <div class="form-group col-span-2">
                  <label class="form-label">
                    General Objectives <span class="text-danger">*</span>
                    <span id="objInputSpinner" style="display:none;margin-left:8px;vertical-align:middle;" title="Generating objectives with AI...">
                      <span class="spinner-border" style="width:0.875rem;height:0.875rem;border-width:0.18em;color:#0284c7;"></span>
                    </span>
                  </label>
                  <textarea name="general_objectives" id="f2_general_objectives" class="form-control" required <?= $s2Attr ?>><?= htmlspecialchars($activity['general_objectives'] ?? '') ?></textarea>
                  <span class="field-error" id="e_general_objectives">General objectives are required.</span>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Specific Objectives <span class="text-danger">*</span></label>
                  <textarea name="specific_objectives" id="f2_specific_objectives" class="form-control" <?= $s2Attr ?>><?= htmlspecialchars($activity['specific_objectives'] ?? '') ?></textarea>
                  <span class="field-error" id="e_specific_objectives">Specific objectives are required.</span>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Involved Subjects <span class="text-danger">*</span></label>
                  <input type="text" name="involved_subjects" id="f2_involved_subjects" class="form-control" placeholder="e.g. ITE314, GE102" value="<?= htmlspecialchars($activity['involved_subjects'] ?? '') ?>" <?= $s2Attr ?>>
                  <span class="field-error" id="e_involved_subjects">Involved subjects are required.</span>
                </div>
                <div class="form-group col-span-2">
                  <label class="form-label">Rationale <span class="text-danger">*</span></label>
                  <textarea name="rationale" id="f2_rationale" class="form-control" placeholder="Why is this activity necessary?" <?= $s2Attr ?>><?= htmlspecialchars($activity['rationale'] ?? '') ?></textarea>
                  <span class="field-error" id="e_rationale">Rationale is required.</span>
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
              <div class="step-error-banner" id="err-banner-3">⚠️ Please complete all material item fields before continuing.</div>
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
                        <td><input type="text" name="mat_item[]" class="form-control mat-field" <?= $s2Attr ?>></td>
                        <td><input type="text" name="mat_desc[]" class="form-control mat-field" <?= $s2Attr ?>></td>
                        <td><input type="number" name="mat_qty[]" class="form-control mat-qty" value="1" <?= $s2Attr ?>></td>
                        <td><input type="text" name="mat_provider[]" class="form-control mat-field" <?= $s2Attr ?>></td>
                        <td><input type="number" name="mat_cost[]" class="form-control mat-cost" value="0" step="0.01" <?= $s2Attr ?>></td>
                        <td>
                          <?php if ($s2Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php else: foreach ($materials as $m): ?>
                        <tr>
                          <td><input type="text" name="mat_item[]" class="form-control mat-field" value="<?= htmlspecialchars($m['item_name']) ?>" <?= $s2Attr ?>></td>
                          <td><input type="text" name="mat_desc[]" class="form-control mat-field" value="<?= htmlspecialchars($m['description'] ?? '') ?>" <?= $s2Attr ?>></td>
                          <td><input type="number" name="mat_qty[]" class="form-control mat-qty" value="<?= $m['quantity'] ?>" <?= $s2Attr ?>></td>
                          <td><input type="text" name="mat_provider[]" class="form-control mat-field" value="<?= htmlspecialchars($m['provider'] ?? '') ?>" <?= $s2Attr ?>></td>
                          <td><input type="number" name="mat_cost[]" class="form-control mat-cost" value="<?= $m['est_cost'] ?>" step="0.01" <?= $s2Attr ?>></td>
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
              <!-- Total cost display -->
              <div class="materials-total-row">
                <label>Total Estimated Cost:</label>
                <span id="materials-total">₱ 0.00</span>
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
              <h2>🗺️ Floor Plan Layout</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-4">⚠️ Please draw at least one element on the floor plan and fill in the description before continuing.</div>
              <?php if ($s4Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s4Comment) ?></div></div>
              <?php endif; ?>
              <?php if ($floorplan && !empty($floorplan['canvas_json'])): ?>
                <script>window.FP_INITIAL_JSON = <?= json_encode($floorplan['canvas_json']) ?>;</script>
              <?php endif; ?>
              
              <?php if ($s4Editable): ?>
                <?php include __DIR__ . '/../includes/floorplan-ui.php'; ?>
                <?php if ($floorplan && !empty($floorplan['file_path'])): ?>
                  <details style="margin-top:12px;">
                    <summary style="cursor:pointer; color:#475569; font-size:0.85rem; font-weight:600;">🖼️ View Last Saved Exported Floor Plan</summary>
                    <div style="margin-top:8px;">
                      <img src="<?= BASE_URL ?>/<?= htmlspecialchars($floorplan['file_path']) ?>" style="max-width:100%; border-radius:8px; border:1px solid #dee2e6;" alt="Current Floor Plan">
                    </div>
                  </details>
                <?php endif; ?>
              <?php else: ?>
                <?php if ($floorplan && !empty($floorplan['file_path'])): ?>
                  <div class="alert alert-info">Current floor plan: <strong><?= basename($floorplan['file_path']) ?></strong></div>
                  <img src="<?= BASE_URL ?>/<?= htmlspecialchars($floorplan['file_path']) ?>" style="max-width:100%; border-radius:8px; border:1px solid #dee2e6;" alt="Current Floor Plan">
                <?php endif; ?>
              <?php endif; ?>

              <div class="form-group" style="margin-top:16px;">
                <label class="form-label">Floor Plan Notes / Description <span class="text-danger">*</span></label>
                <textarea name="floor_plan_notes" id="f3_floor_plan_notes" class="form-control" rows="3" placeholder="Describe the layout — exits, important areas, stage direction, seating arrangement..." <?= $s4Attr ?>><?= htmlspecialchars($floorplan['notes'] ?? '') ?></textarea>
                <span class="field-error" id="e_floor_plan_notes">Floor plan notes are required.</span>
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
              <div class="step-error-banner" id="err-banner-5">⚠️ Please fill in all assigned person fields before continuing.</div>
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
              <div class="step-error-banner" id="err-banner-6">⚠️ Please fill in all schedule and program flow fields with valid future dates before continuing.</div>
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
                        <td><input type="date" name="sched_date[]" class="form-control" <?= $s6Attr ?> <?= $s6Editable ? 'min="' . $tomorrowManila . '"' : '' ?>></td>
                        <td><input type="text" name="sched_event[]" class="form-control sched-field" <?= $s6Attr ?>></td>
                        <td><input type="text" name="sched_venue[]" class="form-control sched-field" <?= $s6Attr ?>></td>
                        <td><input type="text" name="sched_organizer[]" class="form-control sched-field" <?= $s6Attr ?>></td>
                        <td>
                          <?php if ($s6Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php else: foreach ($schedules as $s): ?>
                        <tr>
                          <td><input type="date" name="sched_date[]" class="form-control" value="<?= $s['sched_date'] ?>" <?= $s6Attr ?> <?= $s6Editable ? 'min="' . $tomorrowManila . '"' : '' ?>></td>
                          <td><input type="text" name="sched_event[]" class="form-control sched-field" value="<?= htmlspecialchars($s['event_name']) ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="sched_venue[]" class="form-control sched-field" value="<?= htmlspecialchars($s['venue'] ?? '') ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="sched_organizer[]" class="form-control sched-field" value="<?= htmlspecialchars($s['organizer'] ?? '') ?>" <?= $s6Attr ?>></td>
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
                        <td><input type="text" name="prog_segment[]" class="form-control prog-field" <?= $s6Attr ?>></td>
                        <td><input type="text" name="prog_desc[]" class="form-control prog-field" <?= $s6Attr ?>></td>
                        <td><input type="text" name="prog_pic[]" class="form-control prog-field" <?= $s6Attr ?>></td>
                        <td>
                          <?php if ($s6Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php else: foreach ($program as $p): ?>
                        <tr>
                          <td><input type="time" name="prog_time[]" class="form-control" value="<?= $p['time_slot'] ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="prog_segment[]" class="form-control prog-field" value="<?= htmlspecialchars($p['segment']) ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="prog_desc[]" class="form-control prog-field" value="<?= htmlspecialchars($p['description'] ?? '') ?>" <?= $s6Attr ?>></td>
                          <td><input type="text" name="prog_pic[]" class="form-control prog-field" value="<?= htmlspecialchars($p['person_ic'] ?? '') ?>" <?= $s6Attr ?>></td>
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
              <div class="step-error-banner" id="err-banner-7">⚠️ Please complete all guidelines sections before continuing.</div>
              <?php if ($s7Flagged): ?>
                <div class="reviewer-comment-banner"><span class="rc-icon">⚠️</span><div><span class="rc-label">Reviewer Comment</span><?= htmlspecialchars($s7Comment) ?></div></div>
              <?php endif; ?>
              <div class="form-group">
                <label class="form-label">Mechanics / Guidelines <span class="text-danger">*</span></label>
                <textarea name="guidelines_mechanics" id="f7_mechanics" class="form-control" style="min-height:100px" <?= $s7Attr ?>><?= htmlspecialchars($guidelines['mechanics'] ?? '') ?></textarea>
                <span class="field-error" id="e_mechanics">Mechanics are required.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Criteria for Judging <span class="text-danger">*</span></label>
                <textarea name="guidelines_criteria" id="f7_criteria" class="form-control" <?= $s7Attr ?>><?= htmlspecialchars($guidelines['criteria'] ?? '') ?></textarea>
                <span class="field-error" id="e_criteria">Criteria for judging are required.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Scoring System <span class="text-danger">*</span></label>
                <textarea name="guidelines_scoring" id="f7_scoring" class="form-control" <?= $s7Attr ?>><?= htmlspecialchars($guidelines['scoring_system'] ?? '') ?></textarea>
                <span class="field-error" id="e_scoring">Scoring system is required.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Special Awards <span class="text-danger">*</span></label>
                <textarea name="guidelines_awards" id="f7_awards" class="form-control" <?= $s7Attr ?>><?= htmlspecialchars($guidelines['special_awards'] ?? '') ?></textarea>
                <span class="field-error" id="e_awards">Special awards are required.</span>
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
              <div class="step-error-banner" id="err-banner-8">⚠️ Please fill in all faculty task rows before continuing.</div>
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
                        <td><input type="text" name="ft_name[]" class="form-control ft-field" <?= $s8Attr ?>></td>
                        <td><input type="text" name="ft_task[]" class="form-control ft-field" <?= $s8Attr ?>></td>
                        <td><input type="text" name="ft_contribution[]" class="form-control ft-field" <?= $s8Attr ?>></td>
                        <td><input type="text" name="ft_role[]" class="form-control ft-field" <?= $s8Attr ?>></td>
                        <td>
                          <?php if ($s8Editable): ?>
                            <button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <?php else: foreach ($ftasks as $f): ?>
                        <tr>
                          <td><input type="text" name="ft_name[]" class="form-control ft-field" value="<?= htmlspecialchars($f['faculty_name']) ?>" <?= $s8Attr ?>></td>
                          <td><input type="text" name="ft_task[]" class="form-control ft-field" value="<?= htmlspecialchars($f['assigned_task'] ?? '') ?>" <?= $s8Attr ?>></td>
                          <td><input type="text" name="ft_contribution[]" class="form-control ft-field" value="<?= htmlspecialchars($f['contribution_desc'] ?? '') ?>" <?= $s8Attr ?>></td>
                          <td><input type="text" name="ft_role[]" class="form-control ft-field" value="<?= htmlspecialchars($f['role_in_event'] ?? '') ?>" <?= $s8Attr ?>></td>
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
              <div class="step-error-banner" id="err-banner-9">⚠️ Please fill in all KPI fields and a valid evaluation link before submitting.</div>
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
                    <?php else: foreach ($kpis as $k): 
                      $indVal = !empty($k['indicator']) ? $k['indicator'] : ($k['criteria'] ?? '');
                      $targetVal = !empty($k['target_metric']) ? $k['target_metric'] : (!empty($k['rating']) ? 'Rating: ' . $k['rating'] : '');
                      $methodVal = !empty($k['evaluation_method']) ? $k['evaluation_method'] : ((!empty($k['criteria']) || !empty($k['rating'])) ? 'Student Evaluation' : '');
                    ?>
                      <tr>
                        <td><input type="text" name="kpi_indicator[]" class="form-control kpi-field" value="<?= htmlspecialchars($indVal) ?>" <?= $s9Attr ?>></td>
                        <td><input type="text" name="kpi_target[]" class="form-control kpi-field" value="<?= htmlspecialchars($targetVal) ?>" <?= $s9Attr ?>></td>
                        <td><input type="text" name="kpi_method[]" class="form-control kpi-field" value="<?= htmlspecialchars($methodVal) ?>" <?= $s9Attr ?>></td>
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
                <label class="form-label">Google Forms Evaluation Link <span class="text-danger">*</span></label>
                <input type="url" name="eval_form_link" id="f9_eval_form_link" class="form-control" placeholder="https://forms.gle/..." required value="<?= htmlspecialchars($activity['evaluation_method'] ?? '') ?>" <?= $s9Attr ?>>
                <span class="field-error" id="e_eval_form_link">A valid evaluation form link is required.</span>
                <small style="color:var(--text-muted);display:block;margin-top:4px;">Required for attendees to provide feedback post-event.</small>
              </div>

              <!-- ── SCHEDULE CONFLICT BANNER (Step 9 review) ── -->
              <div id="scheduleConflictBannerStep9" style="display:none; margin-top:16px; padding:16px 20px; border-radius:10px; background:linear-gradient(135deg,#fff5f5 0%,#fed7d7 100%); border:1px solid #feb2b2; border-left:6px solid #e53e3e; color:#742a2a;">
                <div class="scu-heading" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;"></div>
                <p class="scu-msg" style="font-size:.875rem;margin-bottom:10px;line-height:1.4;font-weight:500;"></p>
                <div class="scu-alt-wrap" style="display:none;margin-bottom:10px;">
                  <div style="font-size:.85rem;font-weight:700;margin-bottom:8px;color:#9b2c2c;">🤖 AI-Suggested Alternative Slots:</div>
                  <div class="scu-alt-list" style="display:flex;flex-direction:column;gap:8px;"></div>
                </div>
                <div class="scu-advisory-wrap" style="display:none;margin-bottom:10px;"></div>
                <div class="scu-debug-wrap" style="display:none;"></div>
                <div style="margin-bottom:8px;">
                  <button type="button" class="btn btn-sm btn-outline-danger" id="scuStep9ResolveBtn" style="border-radius:6px;font-weight:600;font-size:0.8rem;">
                    View Conflict &amp; AI Suggestions
                  </button>
                </div>
                <div class="scu-footer" style="font-size:.75rem;font-style:italic;color:inherit;border-top:1px dashed rgba(0,0,0,.15);padding-top:8px;">* Submission is blocked only when a real venue conflict is detected.</div>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div class="flex gap-2">
              <button type="submit" name="action" value="draft" class="btn btn-outline" formnovalidate onclick="document.getElementById('proposalFormAction').value='draft'; serializeEvaluationQuestions();">💾 Save as Draft</button>
              <button type="submit" name="action" value="submit" class="btn btn-primary" id="submitBtn" onclick="document.getElementById('proposalFormAction').value='submit'; serializeEvaluationQuestions();"><?= ($activity['status'] === 'returned_for_revision') ? '🚀 Resubmit for Approval' : '🚀 Submit for Approval' ?></button>
            </div>
          </div>
        </div>

      </div> <!-- Close proposal-wizard-container -->
          <div class="proposal-eval-side-panel">
            <div class="card">
              <div class="card-header">
                <h2>
                  🤖 AI-Generated Evaluation Tool
                </h2>
                <span id="evalStatusBadge">
                  <span id="evalStatusIndicator"></span> <span id="evalStatusText">Empty</span>
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

                <div class="info-alert">
                  These evaluation questions are automatically generated based on your activity objectives and KPIs. Review them and make changes if needed.
                </div>

                <!-- Hidden JSON field -->
                <input type="hidden" name="evaluation_questions" id="f10_evaluation_questions">

                <!-- Questions list container -->
                <div id="eval-questions-list" style="display: flex; flex-direction: column; gap: 4px;">
                  <!-- Dynamically populated -->
                </div>

                <button type="button" class="btn btn-outline btn-sm btn-add-manual-q" onclick="addManualQuestion()">
                  + Add Question
                </button>
                
                <div class="footer-actions">
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
    const todayManila = <?= json_encode($todayManila) ?>;
    const tomorrowManila = <?= json_encode($tomorrowManila) ?>;
    const isS1Editable = <?= json_encode($s1Editable) ?>;
    const isS2Editable = <?= json_encode($s2Editable) ?>;
    const isS3Editable = <?= json_encode($s3Editable) ?>;
    const isS4Editable = <?= json_encode($s4Editable) ?>;
    const isS5Editable = <?= json_encode($s5Editable) ?>;
    const isS6Editable = <?= json_encode($s6Editable) ?>;
    const isS7Editable = <?= json_encode($s7Editable) ?>;
    const isS8Editable = <?= json_encode($s8Editable) ?>;
    const isS9Editable = <?= json_encode($s9Editable) ?>;
    const hasExistingPoster = <?= !empty($activity['poster_path']) ? 'true' : 'false' ?>;
    let questionsList = <?= !empty($activity['evaluation_questions']) ? $activity['evaluation_questions'] : '[]' ?>;
    let currentStep = 1;
    window.currentStep = 1;
    const totalSteps = 9;
    let maxUnlocked = totalSteps;
    const flaggedSteps = <?= json_encode($flaggedSteps) ?>;

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
      window.currentStep = n;
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function nextStep() {
      if (!validateStep(currentStep)) return;
      if (currentStep < totalSteps) showStep(currentStep + 1);
    }

    function prevStep() {
      if (currentStep > 1) showStep(currentStep - 1);
    }

    document.querySelectorAll('.wizard-step').forEach(s => {
      s.addEventListener('click', () => {
        const target = parseInt(s.dataset.step);
        if (target > currentStep && !validateStep(currentStep)) return;
        showStep(target);
      });
    });

    // Mark flagged steps in the wizard indicator
    flaggedSteps.forEach(n => {
      const stepEl = document.querySelector(`.wizard-step[data-step="${n}"]`);
      if (stepEl) stepEl.classList.add('flagged');
    });

    // Auto-navigate to the first flagged step
    if (flaggedSteps.length > 0) showStep(flaggedSteps[0]);

    // ── Validation Helpers ────────────────────────────────────────────
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

    function validatePosterInput(fieldId, errId, isOptional = false) {
      const el = document.getElementById(fieldId);
      const errEl = document.getElementById(errId);
      if (!el) return true;

      if (!el.value || !el.files || el.files.length === 0) {
        if (el.required || !isOptional) {
          el.classList.add('is-invalid');
          if (errEl) {
            errEl.textContent = 'Activity poster is required.';
            errEl.classList.add('visible');
          }
          return false;
        }
        el.classList.remove('is-invalid');
        if (errEl) errEl.classList.remove('visible');
        return true;
      }

      const file = el.files[0];
      const fileName = file.name || '';
      const ext = (fileName.split('.').pop() || '').toLowerCase();
      const mime = (file.type || '').toLowerCase();

      const allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
      const allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
      const videoExts = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv', 'wmv', 'm4v', '3gp', 'ogv'];

      const isVideo = mime.startsWith('video/') || videoExts.includes(ext);
      const isAllowed = allowedExts.includes(ext) && (!mime || allowedMimes.includes(mime));

      if (isVideo || !isAllowed) {
        el.value = '';
        el.classList.add('is-invalid');
        if (errEl) {
          errEl.textContent = 'Only image files (JPG, PNG, WEBP, GIF) are allowed. Videos are not accepted.';
          errEl.classList.add('visible');
        }
        return false;
      }

      el.classList.remove('is-invalid');
      if (errEl) {
        errEl.textContent = 'Activity poster is required.';
        errEl.classList.remove('visible');
      }
      return true;
    }

    const posterInputEdit = document.getElementById('f1_poster_file');
    if (posterInputEdit) {
      posterInputEdit.addEventListener('change', function() {
        validatePosterInput('f1_poster_file', 'e_poster_file', hasExistingPoster);
      });
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

    function checkEventDateField() {
      if (!isS1Editable) return true;
      const el = document.getElementById('f1_event_date');
      if (!el) return true;
      const val = el.value.trim();
      const errEmpty = document.getElementById('e_event_date');
      const errPast  = document.getElementById('e_event_date_past');
      if (!val) {
        el.classList.add('is-invalid');
        if (errEmpty) errEmpty.classList.add('visible');
        if (errPast) errPast.classList.remove('visible');
        return false;
      }
      if (val <= todayManila) {
        el.classList.add('is-invalid');
        if (errEmpty) errEmpty.classList.remove('visible');
        if (errPast) errPast.classList.add('visible');
        return false;
      }
      el.classList.remove('is-invalid');
      if (errEmpty) errEmpty.classList.remove('visible');
      if (errPast) errPast.classList.remove('visible');
      return true;
    }

    const eventDateInput = document.getElementById('f1_event_date');
    if (eventDateInput && isS1Editable) {
      eventDateInput.min = tomorrowManila;
      ['input', 'change'].forEach(evt => {
        eventDateInput.addEventListener(evt, () => {
          if (eventDateInput.value) {
            checkEventDateField();
          }
        });
      });
    }

    function parseTimeToMinutes(timeStr) {
      if (!timeStr || typeof timeStr !== 'string') return null;
      const str = timeStr.trim();
      if (!str) return null;

      // 1. 12-hour format with AM/PM (e.g., "10:00 am", "10:00 AM", "12:00 PM", "12:00 AM")
      const match12 = str.match(/^(\d{1,2}):(\d{2})(?::\d{2})?\s*([ap]m)$/i);
      if (match12) {
        let hours = parseInt(match12[1], 10);
        const minutes = parseInt(match12[2], 10);
        const meridiem = match12[3].toLowerCase();

        if (hours < 1 || hours > 12 || minutes < 0 || minutes > 59) {
          return null;
        }
        if (meridiem === 'am') {
          hours = (hours === 12) ? 0 : hours;
        } else {
          hours = (hours === 12) ? 12 : hours + 12;
        }
        return hours * 60 + minutes;
      }

      // 2. 24-hour format (e.g., "00:00", "10:00", "14:00", "14:00:00")
      const match24 = str.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
      if (match24) {
        const hours = parseInt(match24[1], 10);
        const minutes = parseInt(match24[2], 10);

        if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) {
          return null;
        }
        return hours * 60 + minutes;
      }

      return null;
    }

    function validateTimeFields(isRequired = true) {
      if (!isS1Editable) return true;
      const startEl = document.getElementById('f1_start_time');
      const endEl = document.getElementById('f1_end_time');
      if (!startEl || !endEl) return true;

      const startVal = startEl.value.trim();
      const endVal = endEl.value.trim();

      const errStart = document.getElementById('e_start_time');
      const errEnd = document.getElementById('e_end_time');
      const errOrder = document.getElementById('e_end_time_order');

      // Clear error indicators before re-evaluating
      if (errStart) errStart.classList.remove('visible');
      if (errEnd) errEnd.classList.remove('visible');
      if (errOrder) errOrder.classList.remove('visible');
      startEl.classList.remove('is-invalid');
      endEl.classList.remove('is-invalid');

      if (!startVal && !endVal) {
        if (isRequired) {
          startEl.classList.add('is-invalid');
          endEl.classList.add('is-invalid');
          if (errStart) errStart.classList.add('visible');
          if (errEnd) errEnd.classList.add('visible');
          return false;
        }
        return true; // Draft allows both empty
      }

      if (!startVal) {
        if (isRequired) {
          startEl.classList.add('is-invalid');
          if (errStart) errStart.classList.add('visible');
          return false;
        }
        const em = parseTimeToMinutes(endVal);
        if (em === null) {
          endEl.classList.add('is-invalid');
          return false;
        }
        return true;
      }

      if (!endVal) {
        if (isRequired) {
          endEl.classList.add('is-invalid');
          if (errEnd) errEnd.classList.add('visible');
          return false;
        }
        const sm = parseTimeToMinutes(startVal);
        if (sm === null) {
          startEl.classList.add('is-invalid');
          return false;
        }
        return true;
      }

      // Both are populated
      const startMinutes = parseTimeToMinutes(startVal);
      const endMinutes = parseTimeToMinutes(endVal);

      if (startMinutes === null) {
        startEl.classList.add('is-invalid');
        if (errStart) errStart.classList.add('visible');
        return false;
      }

      if (endMinutes === null) {
        endEl.classList.add('is-invalid');
        if (errEnd) errEnd.classList.add('visible');
        return false;
      }

      if (endMinutes <= startMinutes) {
        endEl.classList.add('is-invalid');
        if (errOrder) errOrder.classList.add('visible');
        return false;
      }

      return true;
    }

    function usePresetAddress(addr) {
      const input = document.getElementById('f1_venue_address');
      if (!input) return;
      input.value = addr;
      input.classList.remove('is-invalid');
      const err = document.getElementById('e_venue_address');
      if (err) err.style.display = 'none';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.focus();
    }

    const startTimeInputEl = document.getElementById('f1_start_time');
    const endTimeInputEl = document.getElementById('f1_end_time');
    ['input', 'change'].forEach(evt => {
      if (startTimeInputEl && isS1Editable) {
        startTimeInputEl.addEventListener(evt, () => validateTimeFields(false));
      }
      if (endTimeInputEl && isS1Editable) {
        endTimeInputEl.addEventListener(evt, () => validateTimeFields(false));
      }
    });

    document.addEventListener('input', e => {
      if (e.target.id === 'f1_event_date') {
        if (isS1Editable) checkEventDateField();
        return;
      }
      if (e.target.id === 'f1_start_time' || e.target.id === 'f1_end_time') {
        if (isS1Editable) validateTimeFields(false);
        return;
      }
      if (e.target.classList.contains('is-invalid') && e.target.value.trim()) {
        e.target.classList.remove('is-invalid');
      }
    });

    document.addEventListener('change', e => {
      if (e.target.id === 'f1_event_date') {
        if (isS1Editable) checkEventDateField();
        return;
      }
      if (e.target.id === 'f1_start_time' || e.target.id === 'f1_end_time') {
        if (isS1Editable) validateTimeFields(false);
        return;
      }
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

    // ── Step Validators ───────────────────────────────────────────────
    function validateStep(step) {
      let valid = true;
      hideBanner(step);

      if (step === 1) {
        if (isS1Editable) {
          if (!checkEventDateField()) valid = false;
          if (!validateTimeFields(true)) valid = false;
          const fields = [
            { id: 'f1_title', err: 'e_title' },
            { id: 'f1_source', err: 'e_source' },
            { id: 'f1_venue', err: 'e_venue' },
            { id: 'f1_target_participants', err: 'e_target_participants' },
            { id: 'f1_venue_address', err: 'e_venue_address' },
            { id: 'f1_theme', err: 'e_theme' }
          ];
          fields.forEach(f => {
            if (!checkField(f.id, f.err)) valid = false;
          });
          const posterEl = document.getElementById('f1_poster_file');
          if (!hasExistingPoster || (posterEl && posterEl.files && posterEl.files.length > 0)) {
            if (!validatePosterInput('f1_poster_file', 'e_poster_file', hasExistingPoster)) valid = false;
          }
        }
      } else if (step === 2) {
        if (isS2Editable) {
          const fields = [
            { id: 'f2_general_objectives', err: 'e_general_objectives' },
            { id: 'f2_specific_objectives', err: 'e_specific_objectives' },
            { id: 'f2_involved_subjects', err: 'e_involved_subjects' },
            { id: 'f2_rationale', err: 'e_rationale' }
          ];
          fields.forEach(f => {
            if (!checkField(f.id, f.err)) valid = false;
          });
        }
      } else if (step === 3) {
        if (isS3Editable) {
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
        }
      } else if (step === 4) {
        if (isS4Editable) {
          if (window.fpSaveToHidden) {
            window.fpSaveToHidden();
          }
          const canvasEl = document.getElementById('fp-canvas-data');
          if (canvasEl) {
            const canvasData = canvasEl.value;
            let hasShapes = false;
            try {
              const parsed = JSON.parse(canvasData);
              hasShapes = (parsed && parsed.shapes && parsed.shapes.length > 0) ||
                          (parsed && parsed.floors && Object.values(parsed.floors).some(f => f.shapes && f.shapes.length > 0));
            } catch (e) {
              hasShapes = false;
            }

            const wrap = document.getElementById('fp-canvas-invalid-wrap');
            if (wrap) {
              if (!hasShapes) {
                wrap.classList.add('fp-canvas-invalid');
                valid = false;
              } else {
                wrap.classList.remove('fp-canvas-invalid');
              }
            }
          }

          const notes = document.getElementById('f3_floor_plan_notes');
          if (notes) {
            if (!notes.value.trim()) {
              notes.classList.add('is-invalid');
              showError('e_floor_plan_notes');
              valid = false;
            } else {
              notes.classList.remove('is-invalid');
              hideError('e_floor_plan_notes');
            }
          }
        }
      } else if (step === 5) {
        if (isS5Editable) {
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
        }
      } else if (step === 6) {
        if (isS6Editable) {
          const schedRows = document.querySelectorAll('#schedule-body tr');
          schedRows.forEach(row => {
            row.querySelectorAll('input.sched-field').forEach(inp => {
              if (!inp.value.trim()) {
                inp.classList.add('is-invalid');
                valid = false;
              } else if (inp.type === 'date' && inp.value <= todayManila) {
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
        }
      } else if (step === 7) {
        if (isS7Editable) {
          [
            { id: 'f7_mechanics', err: 'e_mechanics' },
            { id: 'f7_criteria', err: 'e_criteria' },
            { id: 'f7_scoring', err: 'e_scoring' },
            { id: 'f7_awards', err: 'e_awards' }
          ].forEach(f => {
            if (!checkField(f.id, f.err)) valid = false;
          });
        }
      } else if (step === 8) {
        if (isS8Editable) {
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
        }
      } else if (step === 9) {
        if (isS9Editable) {
          const kpiFields = document.querySelectorAll('input.kpi-field');
          if (kpiFields.length === 0) {
            valid = false;
          } else {
            kpiFields.forEach(inp => {
              if (!inp.value.trim()) {
                inp.classList.add('is-invalid');
                valid = false;
              } else {
                inp.classList.remove('is-invalid');
              }
            });
          }
          const evalLink = document.getElementById('f9_eval_form_link');
          if (evalLink) {
            if (!evalLink.value.trim() || !isValidUrl(evalLink.value.trim())) {
              evalLink.classList.add('is-invalid');
              showError('e_eval_form_link');
              valid = false;
            } else {
              evalLink.classList.remove('is-invalid');
              hideError('e_eval_form_link');
            }
          }
        }
      }

      if (!valid) showBanner(step);
      return valid;
    }

    // ── Form submit validation ────────────────────────────────────────
    const proposalFormEl = document.getElementById('proposalForm');
    if (proposalFormEl) {
      proposalFormEl.addEventListener('submit', function(e) {
        serializeEvaluationQuestions();
        if (window.fpSaveToHidden) {
          window.fpSaveToHidden();
        }

        const isDraft = (e.submitter && e.submitter.value === 'draft') || (document.getElementById('proposalFormAction') && document.getElementById('proposalFormAction').value === 'draft');

        if (isDraft) {
          if (isS1Editable) {
            const timeValid = validateTimeFields(false);
            if (!timeValid) {
              e.preventDefault();
              showStep(1);
              const endEl = document.getElementById('f1_end_time');
              const startEl = document.getElementById('f1_start_time');
              if (endEl && endEl.classList.contains('is-invalid')) {
                endEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                endEl.focus();
              } else if (startEl && startEl.classList.contains('is-invalid')) {
                startEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                startEl.focus();
              }
            }
          }
          return;
        }

        // Non-draft submission: validate ALL wizard steps 1 through 9
        let firstInvalidStep = null;
        for (let s = 1; s <= 9; s++) {
          if (!validateStep(s)) {
            firstInvalidStep = s;
            break;
          }
        }

        const evalValid = validateEvaluationSidePanel();

        if (firstInvalidStep !== null) {
          e.preventDefault();
          showStep(firstInvalidStep);
          const firstInvalidEl = document.querySelector(`#panel-${firstInvalidStep} .is-invalid, #panel-${firstInvalidStep} .fp-canvas-invalid`);
          if (firstInvalidEl) {
            firstInvalidEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (typeof firstInvalidEl.focus === 'function') {
              firstInvalidEl.focus();
            }
          }
          return;
        }

        if (!evalValid) {
          e.preventDefault();
          alert('⚠️ Please review the AI-Generated Evaluation Tool questions in the right-side panel before submitting.');
          return;
        }
      });
    }

    // ── Dynamic row management ────────────────────────────────────────
    const rowTemplates = {
      materials: `
        <tr>
          <td><input type="text" name="mat_item[]" class="form-control mat-field" placeholder="e.g. Chairs"></td>
          <td><input type="text" name="mat_desc[]" class="form-control mat-field" placeholder="Description"></td>
          <td><input type="number" name="mat_qty[]" class="form-control mat-qty" value="1" min="1" placeholder="1"></td>
          <td><input type="text" name="mat_provider[]" class="form-control mat-field" placeholder="Supplier name"></td>
          <td><input type="number" name="mat_cost[]" class="form-control mat-cost" value="0" placeholder="0.00" step="0.01" min="0"></td>
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
          <td><input type="date" name="sched_date[]" class="form-control sched-field" min="${tomorrowManila}"></td>
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
      kpi: `
        <tr>
          <td><input type="text" name="kpi_indicator[]" class="form-control kpi-field" placeholder="e.g. Number of Attendees"></td>
          <td><input type="text" name="kpi_target[]" class="form-control kpi-field" placeholder="e.g. 500 Participants"></td>
          <td><input type="text" name="kpi_method[]" class="form-control kpi-field" placeholder="e.g. Attendance Sheet"></td>
          <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
        </tr>`,
    };

    function addRow(table) {
      const tbody = document.getElementById(table + '-body');
      if (!tbody) return;
      const tmp = document.createElement('tbody');
      tmp.innerHTML = rowTemplates[table];
      tbody.appendChild(tmp.firstElementChild);
      if (table === 'materials') updateTotal();
      if (table === 'kpi') {
        if (!isEvaluationEdited && typeof setEvaluationState === 'function') {
          setEvaluationState('GENERATING');
        }
        triggerAutomaticEvaluation(false);
      }
    }

    function removeRow(btn) {
      const tr = btn.closest('tr');
      const tbody = tr.parentElement;
      if (tbody.querySelectorAll('tr').length > 1) {
        tr.remove();
        if (tbody.id === 'materials-body') updateTotal();
        if (tbody.id === 'kpi-body') {
          if (!isEvaluationEdited && typeof setEvaluationState === 'function') {
            setEvaluationState('GENERATING');
          }
          triggerAutomaticEvaluation(false);
        }
      }
    }

    function addKPI() {
      addRow('kpi');
    }

    // ── Materials Total Cost ──────────────────────────────────────────
    function updateTotal() {
      let total = 0;
      document.querySelectorAll('#materials-body tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.mat-qty')?.value) || 0;
        const cost = parseFloat(row.querySelector('.mat-cost')?.value) || 0;
        total += qty * cost;
      });
      const totalEl = document.getElementById('materials-total');
      if (totalEl) {
        totalEl.textContent = '₱ ' + total.toLocaleString('en-PH', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        });
      }
    }

    const matBody = document.getElementById('materials-body');
    if (matBody) {
      matBody.addEventListener('input', e => {
        if (e.target.classList.contains('mat-qty') || e.target.classList.contains('mat-cost')) {
          updateTotal();
        }
      });
    }

    // ── Evaluation Tool ──────────────────────────────────────────────
    let isEvaluationEdited = false;
    let isGeneratingEval = false;
    let lastEvaluationState = '';
    let lastEvaluationHash = '';
    let currentEvalRequestId = 0;
    let currentEvalAbortController = null;
    let evalDebounceTimer = null;
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

    function cancelPendingEvaluationGeneration() {
      if (evalDebounceTimer) {
        clearTimeout(evalDebounceTimer);
        evalDebounceTimer = null;
      }
      if (currentEvalAbortController) {
        try { currentEvalAbortController.abort(); } catch (e) {}
        currentEvalAbortController = null;
      }
      isGeneratingEval = false;
    }

    function triggerAutomaticEvaluation(immediate = false) {
      if (evalDebounceTimer) {
        clearTimeout(evalDebounceTimer);
        evalDebounceTimer = null;
      }

      // If Theme is currently generating, wait for cascade to reach Objectives
      if (typeof isThemeGenerating !== 'undefined' && isThemeGenerating) return;
      // If Objectives are currently generating and this was NOT an immediate post-objectives trigger, wait for objectives to finish
      if (!immediate && typeof isObjGenerating !== 'undefined' && isObjGenerating) return;

      if (immediate) {
        evalDebounceTimer = setTimeout(() => {
          autoGenerateEvaluation(false);
        }, 50);
      } else {
        evalDebounceTimer = setTimeout(() => {
          autoGenerateEvaluation(false);
        }, 800);
      }
    }

    // Alias for backward compatibility
    function queueEvaluationAutoUpdate() {
      triggerAutomaticEvaluation(false);
    }
    function checkAndTriggerEvaluationAutoUpdate() {
      triggerAutomaticEvaluation(false);
    }

    function dismissEvalChangeNotice() {
      const noticeEl = document.getElementById('evalChangeNotice');
      if (noticeEl) noticeEl.style.display = 'none';
      lastEvaluationHash = JSON.stringify({
        curTitle: (document.getElementById('f1_title')?.value || '').trim(),
        curTheme: (document.getElementById('f1_theme')?.value || '').trim(),
        curGen: (document.getElementById('f2_general_objectives')?.value || '').trim(),
        curSpec: (document.getElementById('f2_specific_objectives')?.value || '').trim(),
        kpis: []
      });
      lastEvaluationState = getObjectivesAndKpisState();
      setEvaluationState('READY');
    }

    async function autoGenerateEvaluation(forced = false) {
      // If Theme or Objectives are still generating, do not run yet unless forced
      if (!forced && typeof isObjGenerating !== 'undefined' && isObjGenerating) return;
      if (!forced && typeof isThemeGenerating !== 'undefined' && isThemeGenerating) return;

      const titleEl = document.getElementById('f1_title') || document.querySelector('[name="title"]');
      const themeEl = document.getElementById('f1_theme') || document.querySelector('[name="theme"]');
      const genEl   = document.getElementById('f2_general_objectives') || document.querySelector('[name="general_objectives"]');
      const specEl  = document.getElementById('f2_specific_objectives') || document.querySelector('[name="specific_objectives"]');

      const curTitle = (titleEl?.value || '').trim();
      const curTheme = (themeEl?.value || '').trim();
      const curGen   = (genEl?.value || '').trim();
      const curSpec  = (specEl?.value || '').trim();

      // Minimum requirements: Title >= 4 chars, and at least some objectives
      if (curTitle.length < 4 || (!curGen && !curSpec)) {
        if (!questionsList || questionsList.length === 0) {
          setEvaluationState('EMPTY');
        }
        return;
      }

      // Never silently overwrite Faculty-customized evaluation questions for minor changes.
      // Preserve manual edits unless forced or unless the activity Title changes to a new activity context.
      if (!forced && isEvaluationEdited && questionsList && questionsList.length > 0) {
        const noticeEl = document.getElementById('evalChangeNotice');
        if (noticeEl) {
          noticeEl.style.display = 'flex';
          noticeEl.innerHTML = `
            <div>ℹ️ <strong>Activity details were updated.</strong> Your customized evaluation questions were preserved.</div>
            <div style="display:flex; gap:8px; margin-top:4px;">
              <button type="button" class="btn btn-outline btn-sm" onclick="dismissEvalChangeNotice()" style="padding: 2px 8px; font-size: 0.7rem; height:auto; line-height:1.2; background:#fff; border:1px solid #d97706; color:#d97706;">Keep Current</button>
              <button type="button" class="btn btn-primary btn-sm" onclick="regenerateEvaluationWithAi(true)" style="padding: 2px 8px; font-size: 0.7rem; height:auto; line-height:1.2; background: #d97706; border: none; color: white;">Regenerate with AI</button>
            </div>
          `;
        }
        return;
      }

      // Gather current KPIs
      const kpis = [];
      const criteriaInputs = document.querySelectorAll('input[name="kpi_criteria[]"], input[name="kpi_indicator[]"]');
      const ratingSelects  = document.querySelectorAll('select[name="kpi_rating[]"], input[name="kpi_target[]"]');
      criteriaInputs.forEach((input, index) => {
        if (input.value.trim()) {
          kpis.push({
            criteria: input.value.trim(),
            rating: ratingSelects[index]?.value || '3'
          });
        }
      });

      // Payload hash check to avoid duplicate calls with identical inputs
      const currentHash = JSON.stringify({ curTitle, curTheme, curGen, curSpec, kpis });
      if (!forced && currentHash === lastEvaluationHash && questionsList && questionsList.length > 0) {
        setEvaluationState('READY');
        return;
      }

      // Abort in-flight evaluation request
      if (currentEvalAbortController) {
        try { currentEvalAbortController.abort(); } catch (e) {}
      }
      currentEvalAbortController = new AbortController();
      const requestId = ++currentEvalRequestId;

      isGeneratingEval = true;
      setEvaluationState('GENERATING');

      const payload = {
        title: curTitle,
        theme: curTheme,
        source: (document.querySelector('[name="source"]')?.value || '').trim(),
        target_participants: (document.querySelector('[name="target_participants"]')?.value || '').trim(),
        event_date: (document.querySelector('[name="event_date"]')?.value || '').trim(),
        start_time: (document.querySelector('[name="start_time"]')?.value || '').trim(),
        end_time: (document.querySelector('[name="end_time"]')?.value || '').trim(),
        venue: (document.querySelector('[name="venue"]')?.value || '').trim(),
        venue_address: (document.querySelector('[name="venue_address"]')?.value || '').trim(),
        involved_subjects: (document.querySelector('[name="involved_subjects"]')?.value || '').trim(),
        rationale: (document.querySelector('[name="rationale"]')?.value || '').trim(),
        general_objectives: curGen,
        specific_objectives: curSpec,
        kpis: kpis
      };

      try {
        const res = await fetch('<?= BASE_URL ?>/api/generate-evaluation.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          signal: currentEvalAbortController.signal
        });

        // Stale check
        if (requestId !== currentEvalRequestId || currentEvalAbortController.signal.aborted) {
          return;
        }

        const data = await res.json();

        if (res.status === 429 || (data && data.error && (data.error.includes('429') || data.error.includes('RESOURCE_EXHAUSTED') || data.error.includes('limit')))) {
          throw new Error("RATE_LIMIT");
        } else if (!res.ok || (data && data.error)) {
          throw new Error(data.error || `API Error: HTTP ${res.status}`);
        }

        if (data.success && Array.isArray(data.questions)) {
          // Double check that title or theme didn't change while waiting
          const latestTitle = (titleEl?.value || '').trim();
          const latestTheme = (themeEl?.value || '').trim();
          if (latestTitle !== curTitle || latestTheme !== curTheme) return; // Stale

          questionsList = data.questions;
          isEvaluationEdited = false;
          editingIndex = -1;
          lastEvaluationHash = currentHash;
          lastEvaluationState = getObjectivesAndKpisState();

          renderQuestions();
          serializeEvaluationQuestions();
          setEvaluationState('READY');
        } else {
          throw new Error(data.error || 'Empty response from AI.');
        }
      } catch (err) {
        if (err.name === 'AbortError') return;
        if (requestId !== currentEvalRequestId) return;
        console.warn('autoGenerateEvaluation error:', err);
        let errMsg = err.message || 'Unknown error';
        if (errMsg === 'RATE_LIMIT') {
          errMsg = 'AI service rate limit reached. Please try again.';
        }
        setEvaluationState('ERROR', errMsg);
      } finally {
        if (requestId === currentEvalRequestId) {
          isGeneratingEval = false;
        }
      }
    }

    function regenerateEvaluationWithAi(forced = true) {
      autoGenerateEvaluation(forced);
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
              <span class="eval-question-num" style="font-weight:600; font-size:0.8rem; color:var(--text-muted);">Question ${index + 1}</span>
              <span class="badge ${badgeClass}" style="font-size:0.7rem; padding: 2px 6px; border-radius:4px; font-weight:600;">${typeLabel}</span>
            </div>
            <div class="eval-question-body" style="font-size:0.85rem; line-height:1.45; color:var(--text-main); margin-bottom: 8px; font-weight:500; word-break: break-word;">
              ${escapeHtml(q.question)}
            </div>
            <div class="eval-card-actions" style="display:flex; gap:12px; font-size:0.75rem; border-top: 1px dashed var(--border); padding-top:6px;">
              <a href="javascript:void(0)" class="eval-action-link" onclick="startEdit(${index})" style="color:var(--sti-blue); text-decoration:none; font-weight:600;">Edit</a>
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
      const questionsListEl = document.getElementById('eval-questions-list');
      const regenBtn = document.getElementById('btn-regenerate-eval');
      
      if (!badge || !indicator || !text) return;
      
      if (statusEl) statusEl.style.display = 'none';
      if (noticeEl && state !== 'NEEDS_UPDATE') noticeEl.style.display = 'none';
      
      switch(state) {
        case 'EMPTY':
          text.innerText = 'Empty';
          badge.style.background = '#f1f5f9';
          badge.style.color = '#64748b';
          indicator.style.background = '#64748b';
          if (questionsListEl) {
            questionsListEl.style.opacity = '1';
            questionsListEl.style.pointerEvents = 'auto';
          }
          if (regenBtn) regenBtn.disabled = false;
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
          if (noticeEl) noticeEl.style.display = 'none';
          if (questionsListEl) {
            questionsListEl.style.opacity = '0.45';
            questionsListEl.style.pointerEvents = 'none';
            questionsListEl.style.transition = 'opacity 0.2s ease';
          }
          if (regenBtn) regenBtn.disabled = true;
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
          if (questionsListEl) {
            questionsListEl.style.opacity = '1';
            questionsListEl.style.pointerEvents = 'auto';
          }
          if (regenBtn) regenBtn.disabled = false;
          break;
        case 'NEEDS_UPDATE':
          text.innerText = 'Needs Update';
          badge.style.background = '#fef3c7';
          badge.style.color = '#d97706';
          indicator.style.background = '#d97706';
          if (questionsListEl) {
            questionsListEl.style.opacity = '1';
            questionsListEl.style.pointerEvents = 'auto';
          }
          if (regenBtn) regenBtn.disabled = false;
          if (noticeEl) {
            noticeEl.style.display = 'flex';
          }
          break;
        case 'ERROR':
          text.innerText = 'Error';
          badge.style.background = '#fee2e2';
          badge.style.color = '#dc2626';
          indicator.style.background = '#dc2626';
          if (questionsListEl) {
            questionsListEl.style.opacity = '1';
            questionsListEl.style.pointerEvents = 'auto';
          }
          if (regenBtn) regenBtn.disabled = false;
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
      // NOTE: [name="title"] and [name="theme"] are intentionally excluded here.
      // Title and Theme trigger their own specific cascade coordinators.
      const fields = [
        '[name="rationale"]',
        '[name="general_objectives"]',
        '[name="specific_objectives"]'
      ];
      
      fields.forEach(sel => {
        const el = document.querySelector(sel);
        if (el) {
          const handler = () => {
            if (!isEvaluationEdited && typeof setEvaluationState === 'function') {
              setEvaluationState('GENERATING');
            }
            triggerAutomaticEvaluation(false);
          };
          el.addEventListener('input', handler);
          el.addEventListener('change', handler);
        }
      });
      
      const kpiContainer = document.getElementById('kpi-criteria') || document.getElementById('kpi-body');
      if (kpiContainer) {
        const kpiHandler = e => {
          if (e.target.classList.contains('kpi-field') || e.target.name === 'kpi_criteria[]' || e.target.name === 'kpi_indicator[]' || e.target.name === 'kpi_rating[]' || e.target.name === 'kpi_target[]') {
            if (!isEvaluationEdited && typeof setEvaluationState === 'function') {
              setEvaluationState('GENERATING');
            }
            triggerAutomaticEvaluation(false);
          }
        };
        kpiContainer.addEventListener('input', kpiHandler);
        kpiContainer.addEventListener('change', kpiHandler);
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
            <div class="preview-question-card" style="margin-bottom: 16px; padding: 14px; background: var(--bg-card-elevated); border: 1px solid var(--border); border-radius: 8px;">
              <div style="font-weight: 600; font-size: 0.85rem; margin-bottom: 8px; color: var(--text-main);">Question ${idx+1}: ${escapeHtml(q.question)}</div>
              <div style="display: flex; gap: 14px; font-size: 0.8rem; color: var(--text-secondary); flex-wrap: wrap;">
                <label style="display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="radio" name="pq_${idx}" disabled> 4 – Excellent</label>
                <label style="display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="radio" name="pq_${idx}" disabled> 3 – Very Satisfactory</label>
                <label style="display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="radio" name="pq_${idx}" disabled> 2 – Satisfactory</label>
                <label style="display:flex; align-items:center; gap:4px; cursor:pointer;"><input type="radio" name="pq_${idx}" disabled> 1 – Needs Improvement</label>
              </div>
            </div>
          `;
        } else {
          qHtml = `
            <div class="preview-question-card" style="margin-bottom: 16px; padding: 14px; background: var(--bg-card-elevated); border: 1px solid var(--border); border-radius: 8px;">
              <div style="font-weight: 600; font-size: 0.85rem; margin-bottom: 8px; color: var(--text-main);">Question ${idx+1}: ${escapeHtml(q.question)}</div>
              <textarea class="form-control" rows="2" style="font-size: 0.8rem; background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border);" placeholder="Type your answer here..." disabled></textarea>
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

    // ── AI Objectives Generation & Automatic Cascade ───────────────────
    let isObjectivesProtected = false;
    let lastAiGeneralObjective = '';
    let lastAiSpecificObjectives = '';
    let currentObjRequestId = 0;
    let currentObjAbortController = null;
    let objDebounceTimer = null;
    let isObjGenerating = false;

    function hashStr(str) {
      let hash = 0;
      for (let i = 0; i < str.length; i++) {
        hash = ((hash << 5) - hash) + str.charCodeAt(i);
        hash |= 0;
      }
      return String(hash);
    }

    function cancelPendingObjectivesGeneration() {
      if (objDebounceTimer) {
        clearTimeout(objDebounceTimer);
        objDebounceTimer = null;
      }
      if (currentObjAbortController) {
        try { currentObjAbortController.abort(); } catch (e) {}
        currentObjAbortController = null;
      }
      isObjGenerating = false;
      setObjectiveSpinner(false);
    }

    function setObjectiveSpinner(show) {
      const spinner = document.getElementById('objInputSpinner');
      if (spinner) {
        spinner.style.display = show ? 'inline-flex' : 'none';
      }
      const genEl = document.getElementById('f2_general_objectives');
      const specEl = document.getElementById('f2_specific_objectives');
      if (genEl) genEl.setAttribute('aria-busy', show ? 'true' : 'false');
      if (specEl) specEl.setAttribute('aria-busy', show ? 'true' : 'false');
    }

    function renderObjectivesStatus(state, errorMsg = '') {
      const statusEl = document.getElementById('objGenStatus');
      if (!statusEl) return;

      switch (state) {
        case 'GENERATING':
          setObjectiveSpinner(true);
          statusEl.style.cssText = 'display:block;margin-bottom:14px;padding:10px 14px;border-radius:6px;font-size:0.85rem;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;';
          statusEl.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="spinner-border" style="width:14px;height:14px;border-width:2px;color:#0284c7;"></span>
              <span>✨ Generating objectives with AI based on your title & theme…</span>
            </div>`;
          break;

        case 'UPDATED':
        case 'SUCCESS':
          setObjectiveSpinner(false);
          statusEl.style.display = 'none';
          statusEl.innerHTML = '';
          break;

        case 'OUTDATED':
          setObjectiveSpinner(false);
          statusEl.style.cssText = 'display:block;margin-bottom:14px;padding:10px 14px;border-radius:6px;font-size:0.85rem;background:#fffbeb;color:#92400e;border:1px solid #fde68a;';
          statusEl.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
              <span>⚠️ Title/theme changed. Your edited objectives were preserved.</span>
              <button type="button" onclick="forceRegenerateObjectives()" style="background:none;border:none;color:#0284c7;cursor:pointer;text-decoration:underline;font-size:0.85rem;font-weight:600;padding:0;">
                ↻ Regenerate Objectives
              </button>
            </div>`;
          break;

        case 'MANUAL':
          setObjectiveSpinner(false);
          statusEl.style.display = 'none';
          statusEl.innerHTML = '';
          break;

        case 'ERROR':
          setObjectiveSpinner(false);
          statusEl.style.cssText = 'display:block;margin-bottom:14px;padding:10px 14px;border-radius:6px;font-size:0.85rem;background:#fff1f2;color:#9f1239;border:1px solid #fecdd3;';
          statusEl.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
              <span>⚠️ Could not auto-generate objectives (${escapeHtml(errorMsg || 'service unavailable')}). You can enter them manually.</span>
              <button type="button" onclick="forceRegenerateObjectives()" style="background:none;border:none;color:#0284c7;cursor:pointer;text-decoration:underline;font-size:0.85rem;padding:0;">
                Retry
              </button>
            </div>`;
          break;

        case 'WAITING':
        default:
          setObjectiveSpinner(false);
          statusEl.style.display = 'none';
          break;
      }
    }

    function checkManualObjectiveEdit() {
      const genEl = document.getElementById('f2_general_objectives');
      const specEl = document.getElementById('f2_specific_objectives');
      if (!genEl || !specEl) return;

      const curGen = genEl.value.trim();
      const curSpec = specEl.value.trim();

      if (curGen === '' && curSpec === '') {
        isObjectivesProtected = false;
        renderObjectivesStatus('WAITING');
        return;
      }

      const isGenMatch = (lastAiGeneralObjective !== '' && curGen === lastAiGeneralObjective.trim());
      const isSpecMatch = (lastAiSpecificObjectives !== '' && curSpec === lastAiSpecificObjectives.trim());

      if (isGenMatch && isSpecMatch) {
        isObjectivesProtected = false;
        renderObjectivesStatus('UPDATED');
      } else {
        isObjectivesProtected = true;
        renderObjectivesStatus('MANUAL');
      }
    }

    function forceRegenerateObjectives() {
      isObjectivesProtected = false;
      autoGenerateObjectives(true);
    }

    async function autoGenerateObjectives(forced = false, contextOverride = null) {
      const genEl = document.getElementById('f2_general_objectives');
      const specEl = document.getElementById('f2_specific_objectives');
      const titleEl = document.getElementById('f1_title') || document.querySelector('[name="title"]');
      const themeEl = document.getElementById('f1_theme') || document.querySelector('[name="theme"]');
      if (!genEl || !specEl || !titleEl) return;

      const currentTitle = contextOverride?.title ?? titleEl.value.trim();
      const currentTheme = contextOverride?.theme ?? (themeEl ? themeEl.value.trim() : '');

      if (currentTitle.length < 4) return;

      // Protected manual content: never silently overwrite unless forced
      if (isObjectivesProtected && !forced) {
        renderObjectivesStatus('OUTDATED');
        return;
      }

      // Abort in-flight objective request & cancel pending evaluation
      if (typeof cancelPendingEvaluationGeneration === 'function') {
        cancelPendingEvaluationGeneration();
      }
      if (!isEvaluationEdited && typeof setEvaluationState === 'function') {
        setEvaluationState('GENERATING');
      }
      if (currentObjAbortController) {
        try { currentObjAbortController.abort(); } catch (e) {}
      }
      currentObjAbortController = new AbortController();

      const requestId = ++currentObjRequestId;
      isObjGenerating = true;
      renderObjectivesStatus('GENERATING');

      const payload = {
        title:               currentTitle,
        theme:               currentTheme,
        source:              (document.querySelector('[name="source"]')?.value || '').trim(),
        target_participants: (document.querySelector('[name="target_participants"]')?.value || '').trim(),
        involved_subjects:   (document.querySelector('[name="involved_subjects"]')?.value || '').trim(),
        rationale:           (document.querySelector('[name="rationale"]')?.value || '').trim()
      };

      try {
        const res = await fetch('<?= BASE_URL ?>/api/generate-objectives.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          signal: currentObjAbortController.signal
        });

        // Stale check
        if (requestId !== currentObjRequestId || currentObjAbortController.signal.aborted) {
          return;
        }

        // Verify title & theme haven't changed in the meantime
        const nowTitle = (titleEl.value || '').trim();
        const nowTheme = (themeEl ? themeEl.value.trim() : '');
        if (nowTitle.length >= 4 && (nowTitle !== currentTitle || nowTheme !== currentTheme)) {
          return;
        }

        const data = await res.json();
        if (res.ok && data.success && data.general_objective && data.specific_objectives) {
          const specVal = Array.isArray(data.specific_objectives)
            ? data.specific_objectives.join('\n')
            : (data.specific_objectives || '');

          lastAiGeneralObjective = data.general_objective;
          lastAiSpecificObjectives = specVal;
          isObjectivesProtected = false;

          try {
            sessionStorage.setItem('sti_ai_gen_obj_' + hashStr(data.general_objective.trim()), '1');
            sessionStorage.setItem('sti_ai_spec_obj_' + hashStr(specVal.trim()), '1');
          } catch (e) {}

          genEl.value = data.general_objective;
          specEl.value = specVal;

          // Subtle green pulse on objective textareas
          [genEl, specEl].forEach(el => {
            el.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
            el.style.borderColor = '#22c55e';
            el.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.2)';
            setTimeout(() => {
              el.style.borderColor = '';
              el.style.boxShadow = '';
            }, 1000);
          });

          // Clear validation errors if showing
          const eGen = document.getElementById('e_general_objectives');
          const eSpec = document.getElementById('e_specific_objectives');
          if (eGen) eGen.style.display = 'none';
          if (eSpec) eSpec.style.display = 'none';
          genEl.classList.remove('is-invalid');
          specEl.classList.remove('is-invalid');

          renderObjectivesStatus('UPDATED');

          if (requestId === currentObjRequestId) {
            isObjGenerating = false;
            setObjectiveSpinner(false);
          }

          // Automatic cascade step: trigger evaluation tool auto-generation
          if (typeof triggerAutomaticEvaluation === 'function') {
            triggerAutomaticEvaluation(true);
          }
        } else {
          throw new Error(data.error || 'Empty or invalid response from AI.');
        }
      } catch (err) {
        if (err.name === 'AbortError') return;
        if (requestId !== currentObjRequestId) return;
        console.warn('autoGenerateObjectives error:', err);
        renderObjectivesStatus('ERROR', err.message);
      } finally {
        if (requestId === currentObjRequestId) {
          isObjGenerating = false;
          setObjectiveSpinner(false);
        }
      }
    }

    // Connect Theme changes to Objectives cascade - Single authoritative handler
    document.addEventListener('activityThemeChanged', (e) => {
      const detail = e.detail || {};
      const titleEl = document.getElementById('f1_title') || document.querySelector('[name="title"]');
      const curTitle = detail.title || (titleEl ? titleEl.value.trim() : '');
      const curTheme = (detail.theme || '').trim();

      if (curTitle.length < 4) return;

      if (detail.triggerSource === 'auto_theme') {
        cancelPendingObjectivesGeneration();
        if (isObjectivesProtected) {
          renderObjectivesStatus('OUTDATED');
        } else {
          autoGenerateObjectives(false, { title: curTitle, theme: curTheme });
        }
      } else if (detail.triggerSource === 'manual_theme_regen') {
        cancelPendingObjectivesGeneration();
        if (typeof cancelPendingEvaluationGeneration === 'function') {
          cancelPendingEvaluationGeneration();
        }
        if (!isEvaluationEdited && typeof setEvaluationState === 'function') {
          setEvaluationState('GENERATING');
        }
        autoGenerateObjectives(true, { title: curTitle, theme: curTheme });
      } else if (detail.triggerSource === 'manual_theme') {
        cancelPendingObjectivesGeneration();
        if (typeof cancelPendingEvaluationGeneration === 'function') {
          cancelPendingEvaluationGeneration();
        }
        if (!isEvaluationEdited && typeof setEvaluationState === 'function') {
          setEvaluationState('GENERATING');
        }
        if (isObjectivesProtected) {
          renderObjectivesStatus('OUTDATED');
          if (typeof triggerAutomaticEvaluation === 'function') {
            triggerAutomaticEvaluation(false);
          }
        } else {
          objDebounceTimer = setTimeout(() => {
            autoGenerateObjectives(false, { title: curTitle, theme: curTheme });
          }, 800);
        }
      }
    });

    function setupAutomaticObjectivesOnEdit() {
      const genEl = document.getElementById('f2_general_objectives');
      const specEl = document.getElementById('f2_specific_objectives');
      if (!genEl || !specEl) return;

      const initialGen = genEl.value.trim();
      const initialSpec = specEl.value.trim();

      if (initialGen !== '' || initialSpec !== '') {
        lastAiGeneralObjective = initialGen;
        lastAiSpecificObjectives = initialSpec;
        isObjectivesProtected = false;
        renderObjectivesStatus('WAITING');
      } else {
        isObjectivesProtected = false;
        renderObjectivesStatus('WAITING');
      }

      genEl.addEventListener('input', checkManualObjectiveEdit);
      specEl.addEventListener('input', checkManualObjectiveEdit);
    }

    // Expose helpers globally
    window.generateObjectives = function(forced = false) {
      return autoGenerateObjectives(forced);
    };
    window.autoGenerateObjectives = autoGenerateObjectives;
    window.forceRegenerateObjectives = forceRegenerateObjectives;

    // ── Automatic AI Theme Generation ──────────────────────────────────
    let isThemeManuallyEdited = false;
    let lastAiGeneratedTheme = '';
    let lastGeneratedTitle = '';
    let currentThemeRequestId = 0;
    let currentThemeAbortController = null;
    let themeDebounceTimer = null;
    let isThemeGenerating = false;

    function cancelPendingThemeGeneration() {
      if (themeDebounceTimer) {
        clearTimeout(themeDebounceTimer);
        themeDebounceTimer = null;
      }
      if (currentThemeAbortController) {
        try { currentThemeAbortController.abort(); } catch (e) {}
        currentThemeAbortController = null;
      }
      isThemeGenerating = false;
      setThemeSpinner(false);
    }

    function setupAutomaticThemeGeneration() {
      const titleEl = document.getElementById('f1_title') || document.querySelector('[name="title"]');
      const themeEl = document.getElementById('f1_theme') || document.querySelector('[name="theme"]');
      if (!titleEl || !themeEl) return;

      const initialTheme = themeEl.value.trim();
      const initialTitle = titleEl.value.trim();
      lastGeneratedTitle = initialTitle;

      if (initialTheme !== '') {
        lastAiGeneratedTheme = initialTheme;
        isThemeManuallyEdited = false;
        renderThemeStatus('SUCCESS');
      } else {
        isThemeManuallyEdited = false;
        renderThemeStatus('WAITING');
      }

      themeEl.addEventListener('input', () => {
        const val = themeEl.value.trim();
        if (val === '') {
          isThemeManuallyEdited = false;
          if (titleEl.value.trim().length >= 4) {
            cancelPendingThemeGeneration();
            cancelPendingObjectivesGeneration();
            if (typeof cancelPendingEvaluationGeneration === 'function') cancelPendingEvaluationGeneration();
            themeDebounceTimer = setTimeout(() => autoGenerateTheme(true), 800);
          } else {
            renderThemeStatus('WAITING');
          }
        } else if (val !== lastAiGeneratedTheme) {
          isThemeManuallyEdited = true;
          renderThemeStatus('MANUAL');

          document.dispatchEvent(new CustomEvent('activityThemeChanged', {
            bubbles: true,
            detail: {
              theme: val,
              title: titleEl.value.trim(),
              isAiGenerated: false,
              isManual: true,
              triggerSource: 'manual_theme'
            }
          }));
        } else {
          isThemeManuallyEdited = false;
          renderThemeStatus('SUCCESS');
        }
      });

      titleEl.addEventListener('input', () => {
        const newTitle = titleEl.value.trim();
        cancelPendingThemeGeneration();
        cancelPendingObjectivesGeneration();
        if (typeof cancelPendingEvaluationGeneration === 'function') cancelPendingEvaluationGeneration();

        // If title changed to a new activity context, reset evaluation tool to generating and clear manual edit flag
        if (newTitle !== lastGeneratedTitle) {
          isEvaluationEdited = false; // Reset manual edit flag for new activity context
          lastEvaluationHash = '';    // Reset hash so new topic generates cleanly
          if (newTitle.length >= 4 && typeof setEvaluationState === 'function') {
            setEvaluationState('GENERATING');
          }
        }

        if (!isObjectivesProtected && newTitle !== lastGeneratedTitle) {
          const genEl = document.getElementById('f2_general_objectives') || document.querySelector('[name="general_objectives"]');
          const specEl = document.getElementById('f2_specific_objectives') || document.querySelector('[name="specific_objectives"]');
          if (genEl) genEl.value = '';
          if (specEl) specEl.value = '';
          lastAiGeneralObjective = '';
          lastAiSpecificObjectives = '';
          renderObjectivesStatus('WAITING');
        }

        document.dispatchEvent(new CustomEvent('activityTitleChanged', {
          bubbles: true,
          detail: { title: newTitle, previousTitle: lastGeneratedTitle }
        }));

        if (newTitle.length < 4) {
          if (!isThemeManuallyEdited && themeEl.value.trim() === '') {
            renderThemeStatus('WAITING');
          }
          return;
        }

        if (newTitle === lastGeneratedTitle) return;

        if (isThemeManuallyEdited) {
          renderThemeStatus('OUTDATED');
          if (isObjectivesProtected) {
            renderObjectivesStatus('OUTDATED');
            if (typeof triggerAutomaticEvaluation === 'function') {
              triggerAutomaticEvaluation(false);
            }
          } else {
            objDebounceTimer = setTimeout(() => {
              autoGenerateObjectives(false, { title: newTitle, theme: themeEl.value.trim() });
            }, 800);
          }
          return;
        }

        themeDebounceTimer = setTimeout(() => {
          autoGenerateTheme(false);
        }, 800);
      });
    }

    async function autoGenerateTheme(forced = false) {
      const titleEl = document.getElementById('f1_title') || document.querySelector('[name="title"]');
      const themeEl = document.getElementById('f1_theme') || document.querySelector('[name="theme"]');
      if (!titleEl || !themeEl) return;

      const titleVal = titleEl.value.trim();
      if (titleVal.length < 4) return;

      if (isThemeManuallyEdited && !forced) {
        renderThemeStatus('OUTDATED');
        return;
      }

      if (typeof cancelPendingEvaluationGeneration === 'function') {
        cancelPendingEvaluationGeneration();
      }
      if (currentThemeAbortController) {
        try { currentThemeAbortController.abort(); } catch (e) {}
      }
      currentThemeAbortController = new AbortController();

      const requestId = ++currentThemeRequestId;
      isThemeGenerating = true;
      renderThemeStatus('GENERATING');

      const payload = {
        activity_id:         <?= (int)$id ?>,
        title:               titleVal,
        source:              (document.querySelector('[name="source"]')?.value || '').trim(),
        target_participants: (document.querySelector('[name="target_participants"]')?.value || '').trim(),
        venue:               (document.querySelector('[name="venue"]')?.value || '').trim(),
        involved_subjects:   (document.querySelector('[name="involved_subjects"]')?.value || '').trim(),
        rationale:           (document.querySelector('[name="rationale"]')?.value || '').trim()
      };

      try {
        const res = await fetch('<?= BASE_URL ?>/api/generate-theme.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
          signal: currentThemeAbortController.signal
        });

        const currentTitleNow = (titleEl ? titleEl.value.trim() : '');
        if (requestId !== currentThemeRequestId || currentThemeAbortController.signal.aborted || currentTitleNow !== titleVal) {
          return;
        }

        const data = await res.json();
        if (res.ok && data.success && data.theme) {
          lastAiGeneratedTheme = data.theme;
          lastGeneratedTitle = titleVal;
          isThemeManuallyEdited = false;
          try { sessionStorage.setItem('sti_ai_theme_' + data.theme.trim(), '1'); } catch (e) {}

          themeEl.value = data.theme;
          themeEl.dispatchEvent(new Event('change', { bubbles: true }));

          const errEl = document.getElementById('e_theme');
          if (errEl) errEl.style.display = 'none';
          themeEl.classList.remove('is-invalid');

          themeEl.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
          themeEl.style.borderColor = '#22c55e';
          themeEl.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.2)';
          setTimeout(() => {
            themeEl.style.borderColor = '';
            themeEl.style.boxShadow = '';
          }, 1000);

          renderThemeStatus('SUCCESS');

          document.dispatchEvent(new CustomEvent('activityThemeChanged', {
            bubbles: true,
            detail: {
              theme: data.theme,
              title: titleVal,
              isAiGenerated: true,
              isManual: false,
              triggerSource: forced ? 'manual_theme_regen' : 'auto_theme'
            }
          }));
        } else {
          throw new Error(data.error || 'Empty or invalid response from AI.');
        }
      } catch (err) {
        if (err.name === 'AbortError') return;
        if (requestId !== currentThemeRequestId) return;
        console.warn('autoGenerateTheme error:', err);
        renderThemeStatus('ERROR', err.message);
      } finally {
        if (requestId === currentThemeRequestId) {
          isThemeGenerating = false;
          setThemeSpinner(false);
        }
      }
    }

    function setThemeSpinner(show) {
      const spinner = document.getElementById('themeInputSpinner');
      const input = document.getElementById('f1_theme');
      if (spinner) {
        spinner.style.display = show ? 'inline-flex' : 'none';
      }
      if (input) {
        input.setAttribute('aria-busy', show ? 'true' : 'false');
      }
    }

    function forceRegenerateTheme() {
      isThemeManuallyEdited = false;
      autoGenerateTheme(true);
    }

    function renderThemeStatus(state, errorMsg = '') {
      const statusEl = document.getElementById('themeAiStatus');
      if (!statusEl) return;

      switch (state) {
        case 'GENERATING':
          setThemeSpinner(true);
          statusEl.innerHTML = `<span class="visually-hidden" style="position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;" role="status">Generating theme with AI...</span>`;
          break;

        case 'DEBOUNCING':
          setThemeSpinner(false);
          break;

        case 'SUCCESS':
          setThemeSpinner(false);
          statusEl.innerHTML = `
            <div style="font-size: 0.8rem; display: flex; align-items: center; justify-content: flex-end; gap: 6px; flex-wrap: wrap;">
              <button type="button" onclick="forceRegenerateTheme()" style="background: none; border: none; color: #0284c7; cursor: pointer; text-decoration: underline; font-size: 0.8rem; padding: 0;">
                ↻ Regenerate
              </button>
            </div>`;
          break;

        case 'MANUAL':
          setThemeSpinner(false);
          statusEl.innerHTML = `
            <div style="font-size: 0.8rem; color: #64748b; display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap;">
              <span>Custom theme entered.</span>
              <button type="button" onclick="forceRegenerateTheme()" style="background: none; border: none; color: #0284c7; cursor: pointer; text-decoration: underline; font-size: 0.8rem; padding: 0;">
                ↻ Regenerate
              </button>
            </div>`;
          break;

        case 'OUTDATED':
          setThemeSpinner(false);
          statusEl.innerHTML = `
            <div style="font-size: 0.8rem; color: #b45309; display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap;">
              <span>⚠️ Activity title changed. Your edited theme was preserved.</span>
              <button type="button" onclick="forceRegenerateTheme()" style="background: none; border: none; color: #0284c7; cursor: pointer; text-decoration: underline; font-size: 0.8rem; padding: 0;">
                ↻ Regenerate
              </button>
            </div>`;
          break;

        case 'ERROR':
          setThemeSpinner(false);
          const themeInputEdit = document.getElementById('f1_theme');
          if (themeInputEdit) {
            themeInputEdit.readOnly = false;
            themeInputEdit.disabled = false;
          }

          let displayErrorText = '';
          const lowerMsg = (errorMsg || '').toLowerCase();
          if (lowerMsg.includes('unexpected response') || lowerMsg.includes('parse') || lowerMsg.includes('empty or invalid')) {
            displayErrorText = 'The AI returned an unexpected response. Please try again.';
          } else if (lowerMsg.includes('service') || lowerMsg.includes('curl') || lowerMsg.includes('http') || lowerMsg.includes('offline') || lowerMsg.includes('unavailable')) {
            displayErrorText = `AI theme service unavailable (${escapeHtml(errorMsg || 'service offline')}).`;
          } else {
            displayErrorText = escapeHtml(errorMsg || 'The AI returned an unexpected response. Please try again.');
          }

          statusEl.innerHTML = `
            <div style="font-size: 0.8rem; color: #b45309; display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap;">
              <span>⚠️ ${displayErrorText} You can enter your theme manually.</span>
              <button type="button" onclick="forceRegenerateTheme()" style="background: none; border: none; color: #0284c7; cursor: pointer; text-decoration: underline; font-size: 0.8rem; padding: 0;">
                Retry
              </button>
            </div>`;
          break;

        case 'WAITING':
        default:
          setThemeSpinner(false);
          statusEl.innerHTML = '';
          break;
      }
    }

    // Expose helpers globally
    window.autoGenerateTheme = autoGenerateTheme;
    window.forceRegenerateTheme = forceRegenerateTheme;

    // Initialize all listeners
    let isAutoCascadeInitialized = false;
    function initAutoThemeAndObjectives() {
      if (isAutoCascadeInitialized) return;
      isAutoCascadeInitialized = true;
      setupAutomaticThemeGeneration();
      setupAutomaticObjectivesOnEdit();
      setupEvaluationAutoUpdateListeners();
      serializeEvaluationQuestions();
      renderQuestions();
      updateTotal();

      // Zero Unsolicited Regeneration on Page Load:
      // If questions already exist, mark as READY and compute initial hash to prevent regeneration
      const curTitle = (document.getElementById('f1_title')?.value || '').trim();
      const curTheme = (document.getElementById('f1_theme')?.value || '').trim();
      const curGen   = (document.getElementById('f2_general_objectives')?.value || '').trim();
      const curSpec  = (document.getElementById('f2_specific_objectives')?.value || '').trim();
      const kpis = [];
      document.querySelectorAll('input[name="kpi_criteria[]"], input[name="kpi_indicator[]"]').forEach((inp, idx) => {
        if (inp.value.trim()) {
          kpis.push({
            criteria: inp.value.trim(),
            rating: document.querySelectorAll('select[name="kpi_rating[]"], input[name="kpi_target[]"]')[idx]?.value || '3'
          });
        }
      });
      lastEvaluationHash = JSON.stringify({ curTitle, curTheme, curGen, curSpec, kpis });

      if (questionsList && questionsList.length > 0) {
        setEvaluationState('READY');
      } else if (curTitle.length >= 4 && (curGen || curSpec)) {
        // If draft has objectives but no evaluation questions saved yet, set EMPTY until next change or manual trigger
        setEvaluationState('EMPTY');
      } else {
        setEvaluationState('EMPTY');
      }
      lastEvaluationState = getObjectivesAndKpisState();
    }

    if (document.readyState === 'interactive' || document.readyState === 'complete') {
      initAutoThemeAndObjectives();
    } else {
      document.addEventListener('DOMContentLoaded', initAutoThemeAndObjectives);
    }

    // CSS for spinner if not defined
    if (!document.getElementById('style-spin-animation')) {
      const s = document.createElement('style');
      s.id = 'style-spin-animation';
      s.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
      document.head.appendChild(s);
    }
  </script>
<script src="https://unpkg.com/konva@9/konva.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/floorplan.js?v=<?= filemtime(__DIR__ . '/../assets/js/floorplan.js') ?>"></script>
  <!-- AI Evaluation Preview Modal -->
  <div id="previewModal" class="modal fade" tabindex="-1" style="display: none;">
    <div class="modal-dialog modal-dialog-centered modal-lg" style="max-width: 600px;">
      <div class="modal-content" style="border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: none;">
        <div class="modal-header" style="border-bottom: 1px solid var(--border); padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;">
          <h5 class="modal-title" style="font-size: 1rem; font-weight: 700; color: var(--text-main); margin: 0;">👁 Preview Evaluation Tool</h5>
          <button type="button" class="btn-close" onclick="closePreviewModal()" style="background: none; border: none; font-size: 1.25rem; cursor: pointer; color: var(--text-muted);">✕</button>
        </div>
        <div class="modal-body" style="padding: 20px; max-height: calc(100vh - 200px); overflow-y: auto;">
          <div style="margin-bottom: 16px; font-size: 0.85rem; color: var(--text-muted); line-height: 1.45;">
            This is how the participant evaluation questionnaire (Evaluation Form) will be displayed to students scanning the QR code post-event.
          </div>
          <div class="preview-modal-info-box" style="border: 1px solid var(--border); border-radius: 8px; padding: 16px; margin-bottom: 16px; background: var(--bg-card-elevated);">
            <h4 id="previewModalActivityTitle" style="margin:0 0 8px 0; font-size:1.05rem; font-weight:700; color: var(--text-main);">Activity Title</h4>
            <div style="font-size:0.8rem; color: var(--text-muted);">Please take a moment to evaluate the activity you attended. Your feedback helps us improve future events.</div>
          </div>
          <div id="preview-questions-list" style="display: flex; flex-direction: column; gap: 14px;">
            <!-- Dynamically populated -->
          </div>
        </div>
        <div class="modal-footer" style="border-top: 1px solid var(--border); padding: 14px 20px; display: flex; justify-content: space-between; align-items: center; width:100%;">
          <span style="font-size:0.75rem; color: var(--text-muted); font-style:italic;">* Submitting from preview is disabled</span>
          <button type="button" class="btn btn-outline" onclick="closePreviewModal()" style="font-size: 0.8rem; padding: 6px 14px; height:auto; line-height:1.2;">Close Preview</button>
        </div>
      </div>
    </div>
  </div>

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
              <div id="scuConfTitle" style="font-weight: 700; font-size: 0.9rem; color: #2d3748; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px;">Approved Activity</div>
              <div style="font-size: 0.8rem; color: #4a5568; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px;">
                <span>📅 <span id="scuConfDate">—</span></span>
                <span>⏰ <span id="scuConfTime">—</span></span>
                <span>📍 <span id="scuConfVenue">—</span></span>
              </div>
            </div>
            <!-- Your Proposed Activity -->
            <div style="background: #fff; padding: 10px 14px; border-radius: 8px; border-left: 4px solid #718096; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
              <div style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #718096; margin-bottom: 4px;">Your Proposed Activity</div>
              <div style="font-weight: 700; font-size: 0.9rem; color: #4a5568;">(Proposed Slot)</div>
              <div style="font-size: 0.8rem; color: #4a5568; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px;">
                <span>📅 <span id="scuPropDate">—</span></span>
                <span>⏰ <span id="scuPropTime">—</span></span>
                <span>📍 <span id="scuPropVenue">—</span></span>
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
      <div class="modal-content" style="border-radius:16px; overflow:hidden; border:none; box-shadow:0 12px 36px rgba(10, 22, 40, 0.2); font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc;" id="aiProposalValidationContent">
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
        activityId:       <?= (int)$id ?>,
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
  </script>

</body>

</html>