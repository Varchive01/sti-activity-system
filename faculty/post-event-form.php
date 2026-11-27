<?php
/**
 * faculty/post-event-form.php
 * Dedicated Separate Page for Uploading & Editing Post-Event Reports
 * Optimized, full-width balanced grid layout eliminating excessive whitespace.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/PostEventAssessmentHelper.php';

requireRole('faculty', 'program_head', 'admin1', 'admin2');
$user = currentUser();
$db   = getDB();

$selectedId = (int)($_GET['activity_id'] ?? 0);
if (!$selectedId) {
    header('Location: ' . BASE_URL . '/faculty/post-event.php');
    exit;
}

if (!canUserAccessActivity($selectedId, 'modify', (int)$user['id'], $user['role'], $user['dept'] ?? '')) {
    http_response_code(403);
    die('Access denied: You do not have permission to upload or edit the post-event report for this activity.');
}

$sa = $db->prepare("SELECT * FROM activities WHERE id = ? AND status IN ('approved', 'completed') LIMIT 1");
$sa->execute([$selectedId]);
$selectedActivity = $sa->fetch(PDO::FETCH_ASSOC);

if (!$selectedActivity) {
    header('Location: ' . BASE_URL . '/faculty/post-event.php');
    exit;
}

$peStmt = $db->prepare("SELECT * FROM post_event WHERE activity_id = ? LIMIT 1");
$peStmt->execute([$selectedId]);
$existingPostEvent = $peStmt->fetch(PDO::FETCH_ASSOC);

$mStmt = $db->prepare("SELECT * FROM materials WHERE activity_id = ? ORDER BY id ASC");
$mStmt->execute([$selectedId]);
$activityMaterials = $mStmt->fetchAll(PDO::FETCH_ASSOC);
$approvedBudgetTotal = PostEventAssessmentHelper::computeApprovedBudget($db, $selectedId);

$ftStmt = $db->prepare("
    SELECT ft.*, u.name AS assigned_user_name
    FROM faculty_tasks ft
    LEFT JOIN users u ON ft.assigned_user_id = u.id
    WHERE ft.activity_id = ?
    ORDER BY ft.id ASC
");
$ftStmt->execute([$selectedId]);
$activityTasks = $ftStmt->fetchAll(PDO::FETCH_ASSOC);

$success   = isset($_GET['saved']);
$errorCode = $_GET['error'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $existingPostEvent ? 'Edit Post-Event Report' : 'Upload Post-Event Report' ?> – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.2.1">
  <style>
    .main-wrap,
    .content {
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }

    .post-event-container {
      width: 100%;
      max-width: 100%;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    .info-box {
      background: var(--bg-card, #ffffff);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 10px;
      padding: 18px 20px;
      margin-bottom: 0;
      box-shadow: 0 1px 3px rgba(0,0,0,0.03);
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }

    .info-box h3 {
      font-size: 0.94rem;
      font-weight: 700;
      margin: 0 0 8px 0;
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      color: var(--text-main, #0f172a);
    }

    .stat-tile {
      background: var(--bg-subtle, #f8fafc);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 8px;
      padding: 10px 14px;
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
    }

    .stat-tile .stat-label {
      font-size: 0.74rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-muted, #64748b);
    }

    .stat-tile .stat-value {
      font-size: 0.92rem;
      font-weight: 700;
      color: var(--text-main, #0f172a);
      overflow-wrap: break-word;
      word-break: break-word;
    }

    .file-pill-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 0.78rem;
      font-weight: 600;
      min-width: 0;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .file-pill-pdf {
      background: rgba(16, 185, 129, 0.12);
      color: #059669;
      border: 1px solid rgba(16, 185, 129, 0.3);
    }

    [data-theme="dark"] .file-pill-pdf {
      background: rgba(16, 185, 129, 0.18);
      color: #34d399;
      border-color: rgba(16, 185, 129, 0.35);
    }

    .file-pill-csv {
      background: rgba(2, 132, 199, 0.12);
      color: #0284c7;
      border: 1px solid rgba(2, 132, 199, 0.25);
    }

    [data-theme="dark"] .file-pill-csv {
      background: rgba(2, 132, 199, 0.18);
      color: #38bdf8;
      border-color: rgba(56, 189, 248, 0.35);
    }

    .stored-file-row {
      margin-bottom: 10px;
      padding: 8px 12px;
      background: var(--bg-subtle, #f8fafc);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 8px;
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }

    .doc-upload-row {
      padding: 6px 10px;
      background: var(--bg-subtle, #f8fafc);
      border: 1px solid var(--border, #e2e8f0);
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      font-size: 0.8rem;
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }

    .doc-upload-row .doc-name {
      flex: 1;
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .form-nav-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 0;
      flex-wrap: wrap;
    }

    .responsive-form-grid-2 {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
      gap: 18px;
    }

    @media (max-width: 768px) {
      .responsive-form-grid-2 {
        grid-template-columns: 1fr;
      }
      .post-event-container .info-box table {
        min-width: 580px !important;
      }
    }

    @media (max-width: 640px) {
      .post-event-action-bar {
        flex-direction: column-reverse !important;
        align-items: stretch !important;
        gap: 10px !important;
      }
      .post-event-action-bar .btn {
        width: 100% !important;
        min-width: 0 !important;
        justify-content: center !important;
      }
    }
  </style>
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title"><?= $existingPostEvent ? 'Edit Post-Event Report' : 'Upload Post-Event Report' ?></div>
      <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">
      <div class="post-event-container">

        <!-- Top Navigation Bar -->
        <div class="form-nav-header">
          <a href="<?= BASE_URL ?>/faculty/post-event.php" class="btn btn-outline btn-sm" style="display:inline-flex; align-items:center; gap:6px; font-weight:600;">
            ← Back to Post-Event Reports
          </a>
          <?php if ($existingPostEvent): ?>
            <span class="badge badge-success" style="font-size:0.8rem; padding:6px 12px;">✓ Report Currently Stored</span>
          <?php else: ?>
            <span class="badge badge-primary" style="font-size:0.8rem; padding:6px 12px;">New Report Submission</span>
          <?php endif; ?>
        </div>

        <?php if ($success): ?>
          <div class="alert alert-success" style="margin-bottom: 0;">
            ✓ Post-event report saved successfully! You may make further edits below or <a href="<?= BASE_URL ?>/faculty/post-event.php" style="text-decoration:underline; font-weight:700; color:inherit;">return to the Reports List</a>.
          </div>
        <?php endif; ?>

        <?php if ($errorCode === 'invalid_assessment_format'): ?>
          <div class="alert alert-danger" style="margin-bottom: 0;">
            <span>⚠️ <strong>Invalid File Format:</strong> The assessment report must be a valid PDF document (.pdf).</span>
          </div>
        <?php elseif ($errorCode === 'invalid_survey_format'): ?>
          <div class="alert alert-danger" style="margin-bottom: 0;">
            <span>⚠️ <strong>Invalid File Format:</strong> The survey-form results must be a valid CSV file (.csv).</span>
          </div>
        <?php elseif ($errorCode === 'invalid_doc_format'): ?>
          <div class="alert alert-danger" style="margin-bottom: 0;">
            <span>⚠️ <strong>Invalid File Format:</strong> Documentation files must be in PDF, Word (.doc, .docx), or Image (.jpg, .jpeg, .png) format.</span>
          </div>
        <?php elseif ($errorCode === 'invalid_attendance'): ?>
          <div class="alert alert-danger" style="margin-bottom: 0;">
            <span>⚠️ <strong>Invalid Attendance:</strong> Please enter a valid, non-negative participant count number.</span>
          </div>
        <?php elseif ($errorCode): ?>
          <div class="alert alert-danger" style="margin-bottom: 0;">
            <span>⚠️ An error occurred while saving the post-event report. Please verify your inputs and try again.</span>
          </div>
        <?php endif; ?>

        <!-- Activity Overview Banner Card (Compact, Full-Width Stat Grid) -->
        <div class="card" style="border-top: 4px solid #003876;">
          <div class="card-body" style="padding: 18px 20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
              <div>
                <span style="font-size:0.72rem; font-weight:700; color:#0284c7; text-transform:uppercase; letter-spacing:0.5px;">Activity Details</span>
                <h1 style="font-size:1.25rem; font-weight:700; margin:2px 0 0 0; color:var(--text-main, #0f172a); line-height:1.3;">
                  <?= htmlspecialchars($selectedActivity['title']) ?>
                </h1>
              </div>
              <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= (int)$selectedActivity['id'] ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:0.8rem; display:inline-flex; align-items:center; gap:6px;">
                👁️ View Activity Proposal
              </a>
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px;">
              <div class="stat-tile">
                <span class="stat-label">📅 Date of Event</span>
                <span class="stat-value"><?= $selectedActivity['event_date'] ? date('F j, Y', strtotime($selectedActivity['event_date'])) : '—' ?></span>
              </div>
              <div class="stat-tile">
                <span class="stat-label">📍 Venue</span>
                <span class="stat-value"><?= htmlspecialchars($selectedActivity['venue'] ?? 'STI Campus') ?></span>
              </div>
              <div class="stat-tile">
                <span class="stat-label">👥 Target Participants</span>
                <span class="stat-value"><?= (int)$selectedActivity['target_participants'] ?> attendees</span>
              </div>
              <div class="stat-tile">
                <span class="stat-label">💰 Approved Budget</span>
                <span class="stat-value" style="color:#059669;">₱<?= number_format($approvedBudgetTotal, 2) ?></span>
              </div>
              <div class="stat-tile">
                <span class="stat-label">🏛️ Headed By</span>
                <span class="stat-value"><?= ($selectedActivity['source'] ?? '') === 'student_org' ? 'Student Organization' : 'Faculty' ?></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Main Post-Event Form -->
        <form method="POST" action="<?= BASE_URL ?>/api/post-event-save.php?activity_id=<?= (int)$selectedActivity['id'] ?>" enctype="multipart/form-data" id="postEventMainForm" style="display:flex; flex-direction:column; gap:18px;">
          <input type="hidden" name="activity_id" value="<?= (int)$selectedActivity['id'] ?>">
          <input type="hidden" name="target_attendance" value="<?= (int)$selectedActivity['target_participants'] ?>">

          <!-- 1. Participant Attendance Count & Method Selection (Balanced 2-Column Grid) -->
          <div class="info-box">
            <h3>👥 Participant Attendance &amp; Recording Method</h3>
            <p class="text-sm text-muted" style="line-height:1.45; margin:0 0 14px 0;">
              Select how attendance was tracked for this activity (paper sign-in sheet, online survey responses, or both).
            </p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:18px; align-items:start;">
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="attendance_method">
                  <strong>Attendance Recording Method</strong> <span class="text-danger">*</span>
                </label>
                <select name="attendance_method" id="attendance_method" class="form-control" style="width:100%;" onchange="updateAttendanceMethodUI(this.value)" required>
                  <option value="paper_list" <?= ($existingPostEvent['attendance_method'] ?? 'paper_list') === 'paper_list' ? 'selected' : '' ?>>📄 Paper-Based Attendance List Only</option>
                  <option value="survey_only" <?= ($existingPostEvent['attendance_method'] ?? '') === 'survey_only' ? 'selected' : '' ?>>📊 Evaluation Survey Form / QR-Code Only</option>
                  <option value="both" <?= ($existingPostEvent['attendance_method'] ?? '') === 'both' ? 'selected' : '' ?>>🔀 Both (Paper Attendance List &amp; Online Survey)</option>
                </select>
                <small class="text-muted" style="font-size:0.75rem; margin-top:4px; display:block;">
                  Determines how participant numbers are validated and stored.
                </small>
              </div>

              <div id="paperAttendanceGroup" class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="manual_attendance">
                  <strong id="paperAttendanceLabel">Total Participants (Paper Headcount)</strong> <span class="text-danger" id="manualAttendanceReqAsterisk">*</span>
                </label>
                <div style="display:flex; align-items:center; gap:10px;">
                  <input type="number" id="manual_attendance" name="manual_attendance" class="form-control" style="flex:1;" min="0" step="1"
                         placeholder="e.g. <?= (int)$selectedActivity['target_participants'] ?>" value="<?= htmlspecialchars((string)($existingPostEvent['manual_attendance'] ?? ($existingPostEvent['actual_attendance'] ?? ''))) ?>">
                  <span class="badge" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; font-size:0.82rem; padding:8px 12px; white-space:nowrap;">
                    Target: <strong><?= (int)$selectedActivity['target_participants'] ?></strong>
                  </span>
                </div>
                <small class="text-muted" style="font-size:0.75rem; margin-top:4px; display:block;">
                  Enter the verified total headcount from your physical attendance sheet or sign-in log.
                </small>
              </div>
            </div>

            <div id="bothMethodDeduplicationNotice" class="alert alert-info" style="display:none; font-size:0.82rem; line-height:1.45; padding:8px 12px; border-radius:6px; background:var(--bg-subtle, #f0fdf4); border:1px solid #86efac; color:#166534; margin-top:12px; margin-bottom:0;">
              <strong>ℹ️ Overlapping Attendees Protection:</strong> When using both methods, the system automatically deduplicates participants who both signed the list and answered the online survey.
            </div>

            <div id="surveyOnlyNotice" class="alert alert-info" style="display:none; font-size:0.82rem; line-height:1.45; padding:8px 12px; border-radius:6px; background:var(--bg-subtle, #eff6ff); border:1px solid #bfdbfe; color:#1e40af; margin-top:12px; margin-bottom:0;">
              <strong>ℹ️ Automatic Survey Attendance:</strong> Attendance is automatically extracted from total verified participants who submitted the evaluation survey.
            </div>
          </div>

          <!-- 2 & 3. Evaluation Documents & Survey Data (Side-by-Side 2-Column Grid) -->
          <div class="responsive-form-grid-2">

            <!-- 2. Assessment Report (PDF) -->
            <div class="info-box">
              <h3>📄 Assessment Report (PDF)</h3>
              <p class="text-sm text-muted" style="line-height:1.45; margin:0 0 12px 0;">
                The official assessment report is auto-generated once metrics are uploaded. You may also upload a custom signed PDF report.
              </p>
              <?php if (!empty($existingPostEvent['assessment_report_path'])):
                $friendlyPdfName = function_exists('getFriendlyDocumentFilename')
                  ? getFriendlyDocumentFilename($db, (int)$selectedActivity['id'], $existingPostEvent['assessment_report_path'])
                  : basename($existingPostEvent['assessment_report_path']);
                $pdfHref = function_exists('resolveUploadedFileUrl')
                  ? resolveUploadedFileUrl($existingPostEvent['assessment_report_path'])
                  : (BASE_URL . '/uploads/' . ltrim($existingPostEvent['assessment_report_path'], '/\\'));
              ?>
                <div class="stored-file-row">
                  <span class="file-pill-badge file-pill-pdf" style="flex:1; min-width:0;">
                    <span>✓ 📄</span> <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($friendlyPdfName) ?></span>
                  </span>
                  <a href="<?= htmlspecialchars($pdfHref) ?>" target="_blank" class="btn btn-outline btn-xs" style="padding:3px 8px; flex-shrink:0;">
                    View PDF
                  </a>
                </div>
              <?php endif; ?>
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="assessment_report_file" style="font-size:0.82rem;">
                  <strong><?= empty($existingPostEvent['assessment_report_path']) ? 'Upload PDF Assessment Report' : 'Replace PDF Assessment Report' ?></strong>
                  <span class="text-muted" style="font-weight:normal;">(Auto-generates if omitted)</span>
                </label>
                <input type="file" id="assessment_report_file" name="assessment_report_file" class="form-control" accept=".pdf,application/pdf" style="font-size:0.82rem;">
              </div>
            </div>

            <!-- 3. Survey-Form Results (CSV) -->
            <div class="info-box">
              <h3>📊 Survey-Form Results (CSV)</h3>
              <p class="text-sm text-muted" style="line-height:1.45; margin:0 0 12px 0;">
                Upload evaluation responses exported from <strong>Google Forms</strong>, <strong>Microsoft Forms</strong>, or standard survey templates (.csv).
              </p>
              <?php if (!empty($existingPostEvent['survey_csv_path'])):
                $friendlyCsvName = function_exists('getFriendlyDocumentFilename')
                  ? getFriendlyDocumentFilename($db, (int)$selectedActivity['id'], $existingPostEvent['survey_csv_path'])
                  : basename($existingPostEvent['survey_csv_path']);
                $csvHref = function_exists('resolveUploadedFileUrl')
                  ? resolveUploadedFileUrl($existingPostEvent['survey_csv_path'])
                  : (BASE_URL . '/uploads/' . ltrim($existingPostEvent['survey_csv_path'], '/\\'));
              ?>
                <div class="stored-file-row">
                  <span class="file-pill-badge file-pill-csv" style="flex:1; min-width:0;">
                    <span>✓ 📊</span> <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($friendlyCsvName) ?></span>
                  </span>
                  <a href="<?= htmlspecialchars($csvHref) ?>" target="_blank" class="btn btn-outline btn-xs" style="padding:3px 8px; flex-shrink:0;">
                    Download CSV
                  </a>
                </div>
              <?php endif; ?>
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="survey_results_csv" style="font-size:0.82rem;">
                  <strong><?= empty($existingPostEvent['survey_csv_path']) ? 'Upload Survey Results (CSV)' : 'Replace Survey Results (CSV)' ?></strong>
                  <span class="text-muted" style="font-weight:normal;">(Accepts .csv only)</span>
                </label>
                <input type="file" id="survey_results_csv" name="survey_results_csv" class="form-control" accept=".csv,text/csv" onchange="triggerAutoAiAssessmentCheck()" style="font-size:0.82rem;">
              </div>
            </div>

          </div>

          <!-- 4. Breakdown of Expenses & Budget Utilization Module -->
          <?php
            $storedApprovedBudget = isset($existingPostEvent['approved_budget']) && $existingPostEvent['approved_budget'] !== null
              ? (float)$existingPostEvent['approved_budget']
              : $approvedBudgetTotal;
            $storedActualExpenses = isset($existingPostEvent['actual_expenses']) && $existingPostEvent['actual_expenses'] !== null
              ? (float)$existingPostEvent['actual_expenses']
              : '';
          ?>
          <div class="info-box" id="expenseBreakdownSection">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
              <h3 style="margin:0;">💰 Breakdown of Expenses &amp; Budget Utilization</h3>
              <span id="liveBudgetStatusBadge" class="badge badge-secondary" style="font-size:0.76rem; padding:4px 10px;">
                Enter actual expenses to compute budget status
              </span>
            </div>
            <p class="text-sm text-muted" style="line-height:1.45; margin:0 0 14px 0;">
              Encode the actual expenditure and attach your itemized liquidation breakdown to determine budget variance.
            </p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:14px; margin-bottom:12px;">
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="approved_budget_input">
                  <strong>Approved Budget (₱)</strong>
                </label>
                <input type="number" id="approved_budget_input" name="approved_budget" class="form-control" step="0.01" min="0"
                       value="<?= htmlspecialchars(number_format($storedApprovedBudget, 2, '.', '')) ?>" oninput="computeLiveBudgetUtilization()">
                <small class="text-muted" style="font-size:0.72rem;">From approved materials budget</small>
              </div>

              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="actual_expenses_input">
                  <strong>Actual Total Expenses (₱)</strong> <span class="text-danger">*</span>
                </label>
                <input type="number" id="actual_expenses_input" name="actual_expenses" class="form-control" step="0.01" min="0"
                       placeholder="e.g. <?= number_format($storedApprovedBudget, 2, '.', '') ?>"
                       value="<?= $storedActualExpenses !== '' ? htmlspecialchars(number_format((float)$storedActualExpenses, 2, '.', '')) : '' ?>"
                       oninput="computeLiveBudgetUtilization()">
                <small class="text-muted" style="font-size:0.72rem;" id="budgetVarianceText">Variance: —</small>
              </div>

              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="expense_breakdown_file">
                  <strong>Breakdown / Liquidation File</strong>
                </label>
                <input type="file" id="expense_breakdown_file" name="expense_breakdown_file" class="form-control"
                       accept=".pdf,.xls,.xlsx,.csv,.doc,.docx,.jpg,.jpeg,.png" onchange="triggerAutoAiAssessmentCheck()">
                <small class="text-muted" style="font-size:0.72rem;">PDF, Excel, CSV, Word, or Receipt Images</small>
              </div>
            </div>

            <?php if (!empty($existingPostEvent['expense_breakdown_path'])): ?>
              <div class="stored-file-row" style="margin-bottom:0;">
                <span class="file-pill-badge file-pill-csv" style="flex:1; min-width:0;">
                  <span>✓ 💰</span> <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Uploaded Breakdown: <?= htmlspecialchars(basename($existingPostEvent['expense_breakdown_path'])) ?></span>
                </span>
                <a href="<?= BASE_URL ?>/api/document-download.php?activity_id=<?= (int)$selectedActivity['id'] ?>&type=expense_breakdown" target="_blank" class="btn btn-outline btn-xs" style="padding:3px 8px; flex-shrink:0;">
                  View Breakdown
                </a>
              </div>
            <?php endif; ?>
          </div>

          <!-- 5. Equipment Return & Condition Status Report -->
          <?php
            $savedEquipReport = !empty($existingPostEvent['equipment_report_json'])
              ? (json_decode($existingPostEvent['equipment_report_json'], true) ?: [])
              : [];
            if (empty($savedEquipReport) && !empty($activityMaterials)) {
              foreach ($activityMaterials as $matItem) {
                $savedEquipReport[] = [
                  'item_name' => $matItem['item_name'] ?? 'Equipment Item',
                  'quantity'  => (int)($matItem['quantity'] ?? 1),
                  'provider'  => ($matItem['provider'] ?? 'in_house') === 'rental' ? 'Rental' : 'In-House',
                  'status'    => 'returned_properly',
                  'remarks'   => 'Returned in good condition'
                ];
              }
            }
            if (empty($savedEquipReport)) {
              $savedEquipReport[] = [
                'item_name' => 'Sound System & Microphones',
                'quantity'  => 1,
                'provider'  => 'In-House',
                'status'    => 'returned_properly',
                'remarks'   => 'Returned properly to custodian'
              ];
            }
          ?>
          <div class="info-box" id="equipmentReportSection">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
              <h3 style="margin:0;">🛠️ Equipment Return &amp; Condition Status Report</h3>
              <button type="button" class="btn btn-outline btn-xs" onclick="addEquipmentReportRow()" style="padding:4px 10px; font-size:0.76rem;">+ Add Equipment Item</button>
            </div>
            <p class="text-sm text-muted" style="line-height:1.45; margin:0 0 10px 0;">
              Record the post-event condition of borrowed or rented materials (returned properly, damaged, or needs replacement).
            </p>
            <div class="table-wrap" style="border:1px solid var(--border); border-radius:8px; overflow-x:auto; background:var(--bg-card);">
              <table style="width:100%; border-collapse:collapse; font-size:0.82rem;">
                <thead>
                  <tr style="background:var(--bg-base); border-bottom:1px solid var(--border);">
                    <th style="padding:8px 10px; text-align:left;">Equipment / Material Item</th>
                    <th style="padding:8px 10px; width:75px; text-align:center;">Qty</th>
                    <th style="padding:8px 10px; width:195px; text-align:left;">Return / Condition Status</th>
                    <th style="padding:8px 10px; text-align:left;">Condition Remarks / Action Needed</th>
                    <th style="padding:8px 10px; width:44px;"></th>
                  </tr>
                </thead>
                <tbody id="equipmentReportTableBody">
                  <?php foreach ($savedEquipReport as $eqRow):
                    $eqStatus = $eqRow['status'] ?? 'returned_properly';
                  ?>
                    <tr>
                      <td style="padding:6px 8px;">
                        <input type="text" name="eq_item_name[]" class="form-control" style="font-size:0.8rem;" value="<?= htmlspecialchars($eqRow['item_name'] ?? '') ?>" placeholder="Equipment name" required>
                      </td>
                      <td style="padding:6px 8px;">
                        <input type="number" name="eq_quantity[]" class="form-control" style="font-size:0.8rem; text-align:center;" min="1" value="<?= (int)($eqRow['quantity'] ?? 1) ?>">
                      </td>
                      <td style="padding:6px 8px;">
                        <select name="eq_status[]" class="form-control" style="font-size:0.8rem; font-weight:600;">
                          <option value="returned_properly" <?= $eqStatus === 'returned_properly' ? 'selected' : '' ?>>✅ Returned Properly</option>
                          <option value="damaged" <?= $eqStatus === 'damaged' ? 'selected' : '' ?>>⚠️ Damaged</option>
                          <option value="needs_replacement" <?= $eqStatus === 'needs_replacement' ? 'selected' : '' ?>>🔄 Needs Replacement</option>
                        </select>
                      </td>
                      <td style="padding:6px 8px;">
                        <input type="text" name="eq_remarks[]" class="form-control" style="font-size:0.8rem;" value="<?= htmlspecialchars($eqRow['remarks'] ?? '') ?>" placeholder="Condition notes / custodian remarks">
                      </td>
                      <td style="padding:6px 8px; text-align:center;">
                        <button type="button" class="btn btn-outline btn-xs" onclick="if(document.querySelectorAll('#equipmentReportTableBody tr').length>1)this.closest('tr').remove()">✕</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- 6. Committee Head Task Verification & Personnel Performance Measurement -->
          <?php if (!empty($activityTasks)): ?>
          <div class="info-box" id="personnelPerformanceSection">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
              <h3 style="margin:0;">✅ Committee Head Task Verification &amp; Personnel Performance</h3>
              <span class="badge badge-primary" style="font-size:0.74rem;"><?= count($activityTasks) ?> Assigned Personnel / Tasks</span>
            </div>
            <p class="text-sm text-muted" style="line-height:1.45; margin:0 0 10px 0;">
              Verify that each assigned task was completed correctly and rate personnel performance on a 1–5 scale.
            </p>
            <div class="table-wrap" style="border:1px solid var(--border); border-radius:8px; overflow-x:auto; background:var(--bg-card);">
              <table style="width:100%; border-collapse:collapse; font-size:0.8rem;">
                <thead>
                  <tr style="background:var(--bg-base); border-bottom:1px solid var(--border);">
                    <th style="padding:8px 10px; text-align:left;">Personnel &amp; Assigned Task</th>
                    <th style="padding:8px 10px; width:195px; text-align:left;">Committee Head Verification</th>
                    <th style="padding:8px 10px; width:175px; text-align:left;">Responsibility Fulfillment (1–5)</th>
                    <th style="padding:8px 10px; text-align:left;">Performance Notes</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($activityTasks as $ftRow):
                    $ftId = (int)$ftRow['id'];
                    $ftPerson = $ftRow['assigned_user_name'] ?: ($ftRow['faculty_name'] ?: 'Assigned Member');
                    $ftTitle = $ftRow['task_title'] ?: ($ftRow['assigned_task'] ?: 'Assigned Task');
                    $ftVer = $ftRow['verification_status'] ?? (($ftRow['status'] ?? '') === 'Completed' ? 'verified_correct' : 'pending');
                    $ftRating = isset($ftRow['performance_rating']) && $ftRow['performance_rating'] !== null ? (int)$ftRow['performance_rating'] : 5;
                  ?>
                    <tr style="border-bottom:1px solid var(--border);">
                      <td style="padding:8px 10px;">
                        <input type="hidden" name="task_verify_id[]" value="<?= $ftId ?>">
                        <strong><?= htmlspecialchars($ftPerson) ?></strong>
                        <div style="font-size:0.75rem; color:var(--text-muted);"><?= htmlspecialchars($ftTitle) ?> (<?= htmlspecialchars($ftRow['role_in_event'] ?: 'Committee Member') ?>)</div>
                      </td>
                      <td style="padding:8px 10px;">
                        <select name="task_verify_status[]" class="form-control" style="font-size:0.78rem; font-weight:600;">
                          <option value="verified_correct" <?= $ftVer === 'verified_correct' ? 'selected' : '' ?>>✅ Verified &amp; Done Correctly</option>
                          <option value="needs_correction" <?= $ftVer === 'needs_correction' ? 'selected' : '' ?>>⚠️ Incomplete / Needs Correction</option>
                          <option value="pending" <?= $ftVer === 'pending' ? 'selected' : '' ?>>⏳ Pending Verification</option>
                        </select>
                      </td>
                      <td style="padding:8px 10px;">
                        <select name="task_perf_rating[]" class="form-control" style="font-size:0.78rem; font-weight:600;">
                          <option value="5" <?= $ftRating === 5 ? 'selected' : '' ?>>★★★★★ (5 - Outstanding)</option>
                          <option value="4" <?= $ftRating === 4 ? 'selected' : '' ?>>★★★★☆ (4 - Exceeded)</option>
                          <option value="3" <?= $ftRating === 3 ? 'selected' : '' ?>>★★★☆☆ (3 - Fulfilled)</option>
                          <option value="2" <?= $ftRating === 2 ? 'selected' : '' ?>>★★☆☆☆ (2 - Partial)</option>
                          <option value="1" <?= $ftRating === 1 ? 'selected' : '' ?>>★☆☆☆☆ (1 - Unfulfilled)</option>
                        </select>
                      </td>
                      <td style="padding:8px 10px;">
                        <input type="text" name="task_perf_remarks[]" class="form-control" style="font-size:0.78rem;" value="<?= htmlspecialchars($ftRow['performance_remarks'] ?? 'Fulfilled assigned responsibilities accurately') ?>" placeholder="Performance measurement notes">
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

          <!-- 7. Event Photos (Minimum 5 Photos Required) & Cloud Drive Link -->
          <?php
            $existingDocs = $db->prepare("SELECT * FROM documents WHERE activity_id = ? AND doc_type IN ('post_event', 'photo') ORDER BY uploaded_at DESC");
            $existingDocs->execute([$selectedActivity['id']]);
            $docList = $existingDocs->fetchAll(PDO::FETCH_ASSOC);
            $existingPhotoCount = 0;
            foreach ($docList as $dItem) {
              $dExt = strtolower(pathinfo($dItem['file_path'] ?: $dItem['file_name'], PATHINFO_EXTENSION));
              if (($dItem['doc_type'] ?? '') === 'photo' || in_array($dExt, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                $existingPhotoCount++;
              }
            }
          ?>
          <div class="info-box" id="eventPhotosAndDriveSection">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
              <h3 style="margin:0;">📸 Event Photographs (At Least 5 Pictures) &amp; Cloud Drive Media Link</h3>
              <span id="photoRequirementBadge" class="badge <?= $existingPhotoCount >= 5 ? 'badge-success' : 'badge-warning' ?>" style="font-size:0.75rem;" data-existing-photos="<?= $existingPhotoCount ?>">
                <?= $existingPhotoCount ?> / 5 Minimum Photos Uploaded
              </span>
            </div>

            <?php if (!empty($docList)): ?>
              <div style="margin-bottom:12px;">
                <div style="font-size:0.8rem; font-weight:600; color:var(--text-muted); margin-bottom:6px;">Previously Uploaded Photos &amp; Documents (<?= count($docList) ?> files, <?= $existingPhotoCount ?> photos):</div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:6px; max-height:140px; overflow-y:auto;">
                  <?php foreach ($docList as $d): ?>
                    <div class="doc-upload-row">
                      <span class="doc-name">
                        <?= ($d['doc_type'] ?? '') === 'photo' ? '🖼️' : '📄' ?> <?= htmlspecialchars($d['file_name'] ?: basename($d['file_path'])) ?>
                      </span>
                      <a href="<?= BASE_URL ?>/api/document-download.php?id=<?= (int)$d['id'] ?>" target="_blank" class="btn btn-outline btn-xs" style="padding:2px 6px; font-size:0.7rem; flex-shrink:0;">View</a>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px;">
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="post_event_files_input">
                  <strong>Select Event Photos (Minimum 5 Pictures) &amp; Documents</strong>
                </label>
                <input type="file" id="post_event_files_input" name="post_event_files[]" class="form-control" multiple
                       accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv" onchange="updateSelectedPhotosCounter(this)">
                <small class="text-muted" style="font-size:.74rem; margin-top:4px; display:block;">
                  Hold <code>Ctrl</code> / <code>Shift</code> to select multiple photos (.jpg, .png, .webp).
                </small>
              </div>

              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="media_drive_link">
                  <strong>🔗 Cloud Drive Link for Event Videos &amp; Photo Albums</strong>
                </label>
                <input type="url" id="media_drive_link" name="media_drive_link" class="form-control"
                       placeholder="https://drive.google.com/drive/folders/... or OneDrive URL"
                       value="<?= htmlspecialchars($existingPostEvent['media_drive_link'] ?? '') ?>">
                <small class="text-muted" style="font-size:0.74rem; margin-top:4px; display:block;">
                  Shared folder link for large raw video files and high-res albums.
                </small>
              </div>
            </div>
          </div>

          <!-- 8. Comments & Suggestions Summary + AI-Assisted Assessment & Recommendations -->
          <div class="info-box" id="aiAssessmentAndRecommendationsSection" style="border:1.5px solid rgba(2,132,199,0.35);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
              <h3 style="margin:0;">🤖 Comments Summary &amp; AI-Assisted Assessment / Recommendations</h3>
              <button type="button" id="btnGeneratePostEventAi" class="btn btn-primary btn-xs" onclick="generatePostEventAiAssessment(true)" style="padding:5px 12px; font-size:0.78rem;">
                ✨ Auto-Generate AI Assessment &amp; Recommendations
              </button>
            </div>
            <p class="text-sm text-muted" style="line-height:1.45; margin:0 0 12px 0;">
              Summarize participant comments to identify areas of success and opportunities for future improvement.
            </p>

            <div id="postEventAiStatusBanner" style="display:none; padding:8px 12px; border-radius:6px; font-size:0.8rem; margin-bottom:12px; background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.3); color:#065f46;"></div>

            <div class="responsive-form-grid-2" style="margin-bottom:12px;">
              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="comments_summary">
                  <strong>💬 Summarized Participant Comments &amp; Suggestions</strong>
                </label>
                <textarea id="comments_summary" name="comments_summary" class="form-control" rows="3"
                          placeholder="Summarize key comments, feedback, and suggestions from participants..."><?= htmlspecialchars($existingPostEvent['comments_summary'] ?? ($existingPostEvent['observations'] ?? '')) ?></textarea>
              </div>

              <div class="form-group" style="margin-bottom:0;">
                <label class="form-label" for="ai_recommendations">
                  <strong>💡 AI-Generated Recommendations for Improvements</strong>
                </label>
                <textarea id="ai_recommendations" name="ai_recommendations" class="form-control" rows="3"
                          placeholder="AI-generated actionable recommendations for future iterations..."><?= htmlspecialchars($existingPostEvent['ai_recommendations'] ?? ($existingPostEvent['recommendations'] ?? '')) ?></textarea>
              </div>
            </div>

            <div class="form-group" style="margin-bottom:0;">
              <label class="form-label" for="ai_assessment_summary">
                <strong>📋 Assessment Report Narrative</strong>
              </label>
              <textarea id="ai_assessment_summary" name="ai_assessment_summary" class="form-control" rows="4"
                        placeholder="Auto-generated once final documents are uploaded or when you click Auto-Generate above..."><?= htmlspecialchars($existingPostEvent['ai_assessment_summary'] ?? '') ?></textarea>
            </div>
          </div>

          <!-- Bottom Action Buttons -->
          <div class="post-event-action-bar" style="display:flex; justify-content:space-between; align-items:center; gap:14px; margin-top:10px; padding-bottom:30px; flex-wrap:wrap;">
            <a href="<?= BASE_URL ?>/faculty/post-event.php" class="btn btn-outline" style="min-width:140px; justify-content:center;">
              Cancel &amp; Return
            </a>
            <button type="submit" class="btn btn-primary" style="min-width:280px; justify-content:center; padding:12px 24px; font-size:0.95rem; font-weight:700;">
              <?= $existingPostEvent ? '💾 Save Changes & Update Post-Event Report' : '✅ Confirm Event Conducted & Upload Report' ?>
            </button>
          </div>
        </form>

        <script>
          function updateAttendanceMethodUI(method) {
            const paperGroup = document.getElementById('paperAttendanceGroup');
            const paperInput = document.getElementById('manual_attendance');
            const paperReq = document.getElementById('manualAttendanceReqAsterisk');
            const bothNotice = document.getElementById('bothMethodDeduplicationNotice');
            const surveyNotice = document.getElementById('surveyOnlyNotice');

            if (method === 'survey_only') {
              if (paperGroup) paperGroup.style.display = 'none';
              if (paperInput) { paperInput.required = false; }
              if (paperReq) paperReq.style.display = 'none';
              if (bothNotice) bothNotice.style.display = 'none';
              if (surveyNotice) surveyNotice.style.display = 'block';
            } else if (method === 'both') {
              if (paperGroup) paperGroup.style.display = 'block';
              if (paperInput) { paperInput.required = true; }
              if (paperReq) paperReq.style.display = 'inline';
              if (bothNotice) bothNotice.style.display = 'block';
              if (surveyNotice) surveyNotice.style.display = 'none';
            } else {
              // paper_list
              if (paperGroup) paperGroup.style.display = 'block';
              if (paperInput) { paperInput.required = true; }
              if (paperReq) paperReq.style.display = 'inline';
              if (bothNotice) bothNotice.style.display = 'none';
              if (surveyNotice) surveyNotice.style.display = 'none';
            }
          }

          document.addEventListener('DOMContentLoaded', function () {
            const sel = document.getElementById('attendance_method');
            if (sel) updateAttendanceMethodUI(sel.value);
            computeLiveBudgetUtilization();
          });

          function computeLiveBudgetUtilization() {
            const appEl = document.getElementById('approved_budget_input');
            const actEl = document.getElementById('actual_expenses_input');
            const badge = document.getElementById('liveBudgetStatusBadge');
            const varText = document.getElementById('budgetVarianceText');
            if (!appEl || !actEl || !badge) return;

            const approved = parseFloat(appEl.value) || 0;
            const rawActual = actEl.value.trim();
            if (rawActual === '') {
              badge.className = 'badge badge-secondary';
              badge.textContent = 'Pending Actual Expense Entry';
              if (varText) varText.textContent = 'Variance: —';
              return;
            }
            const actual = parseFloat(rawActual) || 0;
            const diff = actual - approved;
            if (Math.abs(diff) < 0.01) {
              badge.className = 'badge badge-success';
              badge.textContent = '✓ Fully Utilized (100% On Budget)';
              if (varText) varText.textContent = 'Variance: ₱0.00 (Exact Match)';
            } else if (diff < 0) {
              badge.className = 'badge badge-info';
              badge.textContent = '⬇ Under Budget (Saved ₱' + Math.abs(diff).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}) + ')';
              if (varText) varText.textContent = 'Savings vs. Approved Budget: ₱' + Math.abs(diff).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
            } else {
              badge.className = 'badge badge-danger';
              badge.textContent = '⬆ Over Budget (+₱' + diff.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2}) + ')';
              if (varText) varText.textContent = 'Exceeded Approved Budget by: ₱' + diff.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
            }
          }

          function addEquipmentReportRow() {
            const tbody = document.getElementById('equipmentReportTableBody');
            if (!tbody) return;
            const tr = document.createElement('tr');
            tr.innerHTML = `
              <td style="padding:6px 8px;">
                <input type="text" name="eq_item_name[]" class="form-control" style="font-size:0.8rem;" placeholder="Equipment name" required>
              </td>
              <td style="padding:6px 8px;">
                <input type="number" name="eq_quantity[]" class="form-control" style="font-size:0.8rem; text-align:center;" min="1" value="1">
              </td>
              <td style="padding:6px 8px;">
                <select name="eq_status[]" class="form-control" style="font-size:0.8rem; font-weight:600;">
                  <option value="returned_properly">✅ Returned Properly</option>
                  <option value="damaged">⚠️ Damaged</option>
                  <option value="needs_replacement">🔄 Needs Replacement</option>
                </select>
              </td>
              <td style="padding:6px 8px;">
                <input type="text" name="eq_remarks[]" class="form-control" style="font-size:0.8rem;" placeholder="Condition notes / custodian remarks">
              </td>
              <td style="padding:6px 8px; text-align:center;">
                <button type="button" class="btn btn-outline btn-xs" onclick="if(document.querySelectorAll('#equipmentReportTableBody tr').length>1)this.closest('tr').remove()">✕</button>
              </td>
            `;
            tbody.appendChild(tr);
          }

          function updateSelectedPhotosCounter(input) {
            const badge = document.getElementById('photoRequirementBadge');
            if (!badge) return;
            const existingCount = parseInt(badge.getAttribute('data-existing-photos') || '0', 10) || 0;
            let newPhotoCount = 0;
            if (input && input.files) {
              Array.from(input.files).forEach(f => {
                const ext = (f.name.split('.').pop() || '').toLowerCase();
                if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
                  newPhotoCount++;
                }
              });
            }
            const totalPhotos = existingCount + newPhotoCount;
            badge.textContent = `${totalPhotos} / 5 Minimum Photos Selected`;
            badge.className = totalPhotos >= 5 ? 'badge badge-success' : 'badge badge-warning';
            triggerAutoAiAssessmentCheck();
          }

          function triggerAutoAiAssessmentCheck() {
            const assessEl = document.getElementById('ai_assessment_summary');
            const recsEl = document.getElementById('ai_recommendations');
            if (assessEl && !assessEl.value.trim()) {
              generatePostEventAiAssessment(false);
            }
          }

          async function generatePostEventAiAssessment(forced = true) {
            const btn = document.getElementById('btnGeneratePostEventAi');
            const banner = document.getElementById('postEventAiStatusBanner');
            const commentsEl = document.getElementById('comments_summary');
            const recsEl = document.getElementById('ai_recommendations');
            const assessEl = document.getElementById('ai_assessment_summary');
            const origText = btn ? btn.innerHTML : '✨ Auto-Generate AI Assessment & Recommendations';

            if (btn) {
              btn.disabled = true;
              btn.innerHTML = '⏳ Generating AI Assessment Report...';
            }

            const eqItems = [];
            document.querySelectorAll('#equipmentReportTableBody tr').forEach(tr => {
              const name = tr.querySelector('input[name="eq_item_name[]"]')?.value || '';
              const qty = parseInt(tr.querySelector('input[name="eq_quantity[]"]')?.value || '1', 10) || 1;
              const status = tr.querySelector('select[name="eq_status[]"]')?.value || 'returned_properly';
              const remarks = tr.querySelector('input[name="eq_remarks[]"]')?.value || '';
              if (name.trim()) {
                eqItems.push({ item_name: name.trim(), quantity: qty, status: status, remarks: remarks.trim() });
              }
            });

            try {
              const res = await fetch('<?= BASE_URL ?>/api/generate-post-event-assessment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                  activity_id: <?= (int)$selectedActivity['id'] ?>,
                  actual_attendance: document.getElementById('manual_attendance')?.value || '',
                  approved_budget: document.getElementById('approved_budget_input')?.value || '',
                  actual_expenses: document.getElementById('actual_expenses_input')?.value || '',
                  comments_summary: commentsEl?.value || '',
                  equipment_items: eqItems
                })
              });
              const data = await res.json();
              if (data && data.success) {
                if (commentsEl && (forced || !commentsEl.value.trim()) && data.comments_summary) {
                  commentsEl.value = data.comments_summary;
                }
                if (recsEl && (forced || !recsEl.value.trim()) && data.ai_recommendations) {
                  recsEl.value = data.ai_recommendations;
                }
                if (assessEl && (forced || !assessEl.value.trim()) && data.ai_assessment_summary) {
                  assessEl.value = data.ai_assessment_summary;
                }
                if (banner) {
                  banner.style.display = 'block';
                  banner.textContent = '✓ AI-Assisted Assessment Report & Improvement Recommendations auto-generated from activity metrics, equipment status, and stakeholder feedback.';
                }
              }
            } catch (e) {
              console.warn('AI post-event assessment generation error:', e);
            } finally {
              if (btn) {
                btn.disabled = false;
                btn.innerHTML = origText;
              }
            }
          }

          document.getElementById('postEventMainForm')?.addEventListener('submit', function (e) {
            const assessInput = document.getElementById('assessment_report_file');
            if (assessInput && assessInput.files && assessInput.files.length > 0) {
              const fname = assessInput.files[0].name.toLowerCase();
              if (!fname.endsWith('.pdf')) {
                e.preventDefault();
                alert('⚠️ Invalid File: The Assessment Report must be a PDF file (.pdf).');
                assessInput.focus();
                return false;
              }
            }

            const surveyInput = document.getElementById('survey_results_csv');
            if (surveyInput && surveyInput.files && surveyInput.files.length > 0) {
              const fname = surveyInput.files[0].name.toLowerCase();
              if (!fname.endsWith('.csv')) {
                e.preventDefault();
                alert('⚠️ Invalid File: Survey-form results must be in CSV format (.csv).');
                surveyInput.focus();
                return false;
              }
            }

            const methodSel = document.getElementById('attendance_method');
            const selectedMethod = methodSel ? methodSel.value : 'paper_list';
            const attInput = document.getElementById('manual_attendance');

            if (selectedMethod !== 'survey_only') {
              if (attInput && (attInput.value === '' || parseInt(attInput.value, 10) < 0)) {
                e.preventDefault();
                alert('⚠️ Please enter a valid, non-negative participant attendance count.');
                attInput.focus();
                return false;
              }
            }
          });
        </script>

      </div>
    </div>
  </div>
</body>

</html>
