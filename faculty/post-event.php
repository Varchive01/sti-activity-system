<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty', 'program_head');
$user = currentUser();
$db   = getDB();

// Self-healing database schema: ensure post_event table has expanded fields
try {
    $existingCols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('target_attendance', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN target_attendance INT NULL DEFAULT 0");
    }
    if (!in_array('actual_attendance', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN actual_attendance INT NULL DEFAULT 0");
    }
    if (!in_array('satisfaction_score', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN satisfaction_score DECIMAL(3,1) NULL DEFAULT NULL");
    }
    if (!in_array('recommendations', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN recommendations TEXT NULL DEFAULT NULL");
    }
    if (!in_array('manual_attendance', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN manual_attendance INT NULL DEFAULT NULL");
    }
    if (!in_array('assessment_report_path', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN assessment_report_path VARCHAR(500) NULL DEFAULT NULL");
    }
    if (!in_array('survey_csv_path', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN survey_csv_path VARCHAR(500) NULL DEFAULT NULL");
    }
    if (!in_array('attendance_method', $existingCols, true)) {
        $db->exec("ALTER TABLE post_event ADD COLUMN attendance_method VARCHAR(50) NOT NULL DEFAULT 'paper_list'");
    }
} catch (\Throwable $migEx) {
    // Gracefully continue even if DDL permissions are restricted
}

if (function_exists('reconcilePostEventMetrics')) {
    reconcilePostEventMetrics($db);
}

if ($user['role'] === 'program_head') {
    $userDept = resolveUserDepartment($db, $user);
    $deptCond = buildDepartmentSqlCondition('u.department', $userDept);
    $approved = $db->prepare("
        SELECT a.* FROM activities a
        JOIN users u ON a.faculty_id = u.id
        LEFT JOIN post_event pe ON a.id = pe.activity_id
        WHERE ({$deptCond} OR a.faculty_id = ?) AND a.status='approved' AND pe.id IS NULL
        ORDER BY a.event_date DESC
    ");
    $approved->execute([$user['id']]);
    $eligibleActivities = $approved->fetchAll();

    $submitted = $db->prepare("
        SELECT a.id AS activity_id, a.title, a.event_date, pe.*
        FROM post_event pe
        JOIN activities a ON pe.activity_id=a.id
        JOIN users u ON a.faculty_id = u.id
        WHERE ({$deptCond} OR a.faculty_id = ?)
        ORDER BY pe.submitted_at DESC
    ");
    $submitted->execute([$user['id']]);
    $reports = $submitted->fetchAll();
} else {
    // Approved activities without a post-event report
    $approved = $db->prepare("
        SELECT a.* FROM activities a
        LEFT JOIN post_event pe ON a.id = pe.activity_id
        WHERE a.faculty_id=? AND a.status='approved' AND pe.id IS NULL
        ORDER BY a.event_date DESC
    ");
    $approved->execute([$user['id']]);
    $eligibleActivities = $approved->fetchAll();

    // Already submitted reports
    $submitted = $db->prepare("
        SELECT a.id AS activity_id, a.title, a.event_date, pe.*
        FROM post_event pe
        JOIN activities a ON pe.activity_id=a.id
        WHERE a.faculty_id=?
        ORDER BY pe.submitted_at DESC
    ");
    $submitted->execute([$user['id']]);
    $reports = $submitted->fetchAll();
}

$success    = isset($_GET['saved']);
$errorCode  = $_GET['error'] ?? null;
$selectedId = (int)($_GET['activity_id'] ?? 0);
$selectedActivity = null;
$existingPostEvent = null;

if ($selectedId) {
  if (canUserAccessActivity($selectedId, 'modify', (int)$user['id'], $user['role'], $user['dept'] ?? '')) {
    $sa = $db->prepare("SELECT * FROM activities WHERE id = ? AND status IN ('approved', 'completed') LIMIT 1");
    $sa->execute([$selectedId]);
    $selectedActivity = $sa->fetch();
    if ($selectedActivity) {
      $peStmt = $db->prepare("SELECT * FROM post_event WHERE activity_id = ? LIMIT 1");
      $peStmt->execute([$selectedId]);
      $existingPostEvent = $peStmt->fetch(PDO::FETCH_ASSOC);
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
<?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upload Post-Event Report – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.9">
  <style>
    .main-wrap,
    .content {
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }

    .post-event-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1.45fr);
      gap: 24px;
      align-items: start;
      width: 100%;
      max-width: 100%;
      box-sizing: border-box;
    }

    .post-event-grid > * {
      min-width: 0;
      max-width: 100%;
    }

    .post-event-grid .card,
    .post-event-grid .card-header,
    .post-event-grid .card-body {
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }

    @media (max-width: 1180px) {
      .post-event-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 20px;
      }
    }

    .activity-card {
      background: var(--bg-base);
      border: 2px solid var(--border);
      border-radius: 10px;
      padding: 14px 16px;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      transition: border-color .2s;
      min-width: 0;
    }

    .activity-card > div {
      min-width: 0;
      flex: 1;
    }

    .activity-card.selected {
      border-color: var(--accent);
      background: var(--accent-lt);
    }

    .activity-card .title {
      font-weight: 600;
      font-size: .88rem;
      overflow-wrap: break-word;
      word-break: break-word;
    }

    .activity-card .meta {
      font-size: .75rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .submitted-reports-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: auto;
    }

    .submitted-reports-table th,
    .submitted-reports-table td {
      padding: 10px 12px !important;
      vertical-align: middle;
    }

    .submitted-reports-table td:first-child {
      min-width: 130px;
      overflow-wrap: break-word;
    }

    .submitted-reports-actions {
      display: inline-flex;
      align-items: center;
      justify-content: flex-end;
      gap: 5px;
      flex-wrap: wrap;
    }

    .info-box {
      background: var(--bg-base);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 18px 20px;
      margin-bottom: 18px;
      min-width: 0;
      max-width: 100%;
      box-sizing: border-box;
    }

    .info-box h3 {
      font-size: .88rem;
      font-weight: 700;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
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
      margin-bottom: 12px;
      padding: 10px 14px;
      background: var(--bg-subtle, rgba(0,0,0,0.03));
      border: 1px solid var(--border);
      border-radius: 8px;
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
      padding: 7px 12px;
      background: var(--bg-subtle, rgba(0,0,0,0.03));
      border: 1px solid var(--border);
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      font-size: 0.82rem;
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
  </style>
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title"><?= $existingPostEvent ? 'Edit Post-Event Report' : 'Upload Post-Event Report' ?></div>
    
      <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <!-- User Profile Control -->
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content">
      <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom: 18px;">
          ✓ Post-event report saved successfully!
        </div>
      <?php endif; ?>

      <?php if ($errorCode === 'invalid_assessment_format'): ?>
        <div class="alert alert-danger" style="margin-bottom: 18px;">
          <span>⚠️ <strong>Invalid File Format:</strong> The assessment report must be a valid PDF document (.pdf).</span>
        </div>
      <?php elseif ($errorCode === 'invalid_survey_format'): ?>
        <div class="alert alert-danger" style="margin-bottom: 18px;">
          <span>⚠️ <strong>Invalid File Format:</strong> The survey-form results must be a valid CSV file (.csv).</span>
        </div>
      <?php elseif ($errorCode === 'invalid_doc_format'): ?>
        <div class="alert alert-danger" style="margin-bottom: 18px;">
          <span>⚠️ <strong>Invalid File Format:</strong> Documentation files must be in PDF, Word (.doc, .docx), or Image (.jpg, .jpeg, .png) format.</span>
        </div>
      <?php elseif ($errorCode === 'invalid_attendance'): ?>
        <div class="alert alert-danger" style="margin-bottom: 18px;">
          <span>⚠️ <strong>Invalid Attendance:</strong> Please enter a valid, non-negative participant count number.</span>
        </div>
      <?php elseif ($errorCode): ?>
        <div class="alert alert-danger" style="margin-bottom: 18px;">
          <span>⚠️ An error occurred while saving the post-event report. Please verify your inputs and try again.</span>
        </div>
      <?php endif; ?>

      <div class="post-event-grid">

        <!-- Left: Select activity -->
        <div>
          <div class="card">
            <div class="card-header">
              <h2>Select Approved Activity</h2>
            </div>
            <div class="card-body">
              <?php if (empty($eligibleActivities)): ?>
                <div style="text-align:center;padding:24px;color:var(--text-muted);">
                  <div style="font-size:2rem;margin-bottom:8px;">✓</div>
                  <div class="text-sm">All approved activities have reports uploaded.</div>
                </div>
              <?php else: foreach ($eligibleActivities as $a): ?>
                <div class="activity-card <?= $selectedId === (int)$a['id'] ? 'selected' : '' ?>">
                  <div>
                    <div class="title"><?= htmlspecialchars($a['title']) ?></div>
                    <div class="meta"><?= $a['event_date'] ? date('F j, Y', strtotime($a['event_date'])) : '—' ?> · <?= htmlspecialchars($a['venue'] ?? '') ?></div>
                  </div>
                  <a href="?activity_id=<?= (int)$a['id'] ?>" class="btn btn-primary btn-sm" style="flex-shrink:0;">Upload</a>
                </div>
              <?php endforeach;
              endif; ?>
            </div>
          </div>

          <!-- Submitted Reports -->
          <?php if (!empty($reports)): ?>
            <div class="card" style="margin-top:16px;">
              <div class="card-header">
                <h2>Submitted Reports</h2>
              </div>
              <div class="card-body" style="padding:0;">
                <div class="table-wrap">
                  <table class="submitted-reports-table">
                    <thead>
                      <tr>
                        <th>Activity</th>
                        <th>Attendance</th>
                        <th>Score</th>
                        <th style="text-align:right;">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($reports as $r): ?>
                        <tr style="<?= ($selectedId === (int)$r['activity_id']) ? 'background: var(--accent-lt);' : '' ?>">
                          <td>
                            <strong style="line-height:1.35; display:inline-block;"><?= htmlspecialchars($r['title']) ?></strong><br>
                            <span class="text-sm text-muted"><?= $r['event_date'] ? date('M j, Y', strtotime($r['event_date'])) : '—' ?></span>
                          </td>
                          <td style="white-space:nowrap;">
                            <?= isset($r['actual_attendance']) ? htmlspecialchars($r['actual_attendance']) : '0' ?> / <?= isset($r['target_attendance']) ? htmlspecialchars($r['target_attendance']) : '0' ?>
                            <?php if (!empty($r['manual_attendance'])): ?>
                              <br><small style="color:var(--text-muted);font-size:0.72rem;">(Paper: <?= (int)$r['manual_attendance'] ?>)</small>
                            <?php endif; ?>
                          </td>
                          <td><span class="badge badge-success"><?= isset($r['satisfaction_score']) && $r['satisfaction_score'] !== null ? number_format($r['satisfaction_score'], 1) : '—' ?><?= isset($r['satisfaction_score']) && $r['satisfaction_score'] !== null ? '/5' : '' ?></span></td>
                          <td style="text-align:right;">
                            <div class="submitted-reports-actions">
                              <a href="?activity_id=<?= (int)$r['activity_id'] ?>" class="btn btn-outline btn-xs" style="padding:4px 8px;font-size:0.75rem;">✏️ Edit</a>
                              <a href="<?= BASE_URL ?>/faculty/view-activity.php?id=<?= (int)$r['activity_id'] ?>" class="btn btn-secondary btn-xs" style="padding:4px 8px;font-size:0.75rem;" target="_blank">👁️ View</a>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Right: Upload / Edit Form -->
        <div>
          <?php if ($selectedActivity): ?>
            <div class="card">
              <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                <h2 style="min-width:0; flex:1; overflow-wrap:break-word;"><?= $existingPostEvent ? '✏️ Edit Report:' : '📋 Upload Report:' ?> <?= htmlspecialchars($selectedActivity['title']) ?></h2>
                <?php if ($existingPostEvent): ?>
                  <span class="badge badge-success" style="font-size:0.75rem; flex-shrink:0;">Report Stored</span>
                <?php endif; ?>
              </div>
              <div class="card-body">

                <?php if ($existingPostEvent): ?>
                  <div class="alert alert-info" style="margin-bottom:16px; font-size:0.84rem; line-height:1.5; align-items:flex-start;">
                    <span style="flex-shrink:0;">ℹ️</span>
                    <span style="min-width:0; flex:1;"><strong>Edit Mode:</strong> A post-event report has already been recorded for this activity. You may update the participant count or upload replacement files below.</span>
                  </div>
                <?php else: ?>
                  <div class="info-box">
                    <h3>ℹ️ Post-Event Documentation Guidelines</h3>
                    <p class="text-sm text-muted" style="line-height:1.6; margin:0;">
                      Upload the documentation collected during the event, including the official assessment report (PDF), survey response results (CSV), and event photos or attendance sheets.
                    </p>
                  </div>
                <?php endif; ?>

                <form method="POST" action="<?= BASE_URL ?>/api/post-event-save.php?activity_id=<?= (int)$selectedActivity['id'] ?>" enctype="multipart/form-data" id="postEventMainForm">
                  <input type="hidden" name="activity_id" value="<?= (int)$selectedActivity['id'] ?>">
                  <input type="hidden" name="target_attendance" value="<?= (int)$selectedActivity['target_participants'] ?>">

                  <!-- 1. Participant Attendance Count & Method Selection -->
                  <div class="info-box">
                    <h3>👥 Participant Attendance & Recording Method</h3>
                    <p class="text-sm text-muted" style="line-height:1.5; margin-bottom:14px;">
                      Select how attendance was tracked for this activity. Attendance may be based on the number of participants who answered the survey, the number recorded through a paper-based attendance list, or both.
                    </p>

                    <div class="form-group" style="margin-bottom:14px;">
                      <label class="form-label" for="attendance_method">
                        <strong>Attendance Recording Method</strong> <span class="text-danger">*</span>
                      </label>
                      <select name="attendance_method" id="attendance_method" class="form-control" style="max-width:440px; width:100%;" onchange="updateAttendanceMethodUI(this.value)" required>
                        <option value="paper_list" <?= ($existingPostEvent['attendance_method'] ?? 'paper_list') === 'paper_list' ? 'selected' : '' ?>>📄 Paper-Based Attendance List Only</option>
                        <option value="survey_only" <?= ($existingPostEvent['attendance_method'] ?? '') === 'survey_only' ? 'selected' : '' ?>>📊 Evaluation Survey Form / QR-Code Only</option>
                        <option value="both" <?= ($existingPostEvent['attendance_method'] ?? '') === 'both' ? 'selected' : '' ?>>🔀 Both (Paper Attendance List & Online Survey)</option>
                      </select>
                    </div>

                    <div id="paperAttendanceGroup" class="form-group" style="margin-bottom:0;">
                      <label class="form-label" for="manual_attendance">
                        <strong id="paperAttendanceLabel">Total Participants (Paper-Based Attendance List / Headcount)</strong> <span class="text-danger" id="manualAttendanceReqAsterisk">*</span>
                      </label>
                      <div style="display:flex; align-items:center; gap:12px; max-width:320px; flex-wrap:wrap;">
                        <input type="number" id="manual_attendance" name="manual_attendance" class="form-control" style="flex:1; min-width:120px;" min="0" step="1"
                               placeholder="e.g. 45" value="<?= htmlspecialchars((string)($existingPostEvent['manual_attendance'] ?? ($existingPostEvent['actual_attendance'] ?? ''))) ?>">
                        <span style="font-size:0.85rem; color:var(--text-muted); white-space:nowrap;">
                          Target: <strong><?= (int)$selectedActivity['target_participants'] ?></strong>
                        </span>
                      </div>
                      <small class="text-muted" style="font-size:0.75rem; margin-top:4px; display:block;">
                        Enter the verified total headcount from your physical attendance sheet or sign-in log.
                      </small>
                    </div>

                    <!-- Notice when Both methods are selected to explain duplicate prevention -->
                    <div id="bothMethodDeduplicationNotice" class="alert alert-info" style="display:none; font-size:0.82rem; line-height:1.5; padding:10px 14px; border-radius:6px; background:var(--bg-subtle, #f0fdf4); border:1px solid #86efac; color:#166534; margin-top:10px;">
                      <strong>ℹ️ Overlapping Attendees Protection:</strong> When using both paper attendance and online survey responses, the system automatically eliminates duplicate counting so participants who both signed the list and answered the survey are not counted twice.
                    </div>

                    <div id="surveyOnlyNotice" class="alert alert-info" style="display:none; font-size:0.82rem; line-height:1.5; padding:10px 14px; border-radius:6px; background:var(--bg-subtle, #eff6ff); border:1px solid #bfdbfe; color:#1e40af; margin-top:10px;">
                      <strong>ℹ️ Automatic Survey Attendance:</strong> Attendance count is automatically determined from the total verified participants who submitted the evaluation survey (via uploaded CSV or online responses).
                    </div>
                  </div>

                  <!-- 2. Assessment Report (PDF format) -->
                  <div class="info-box">
                    <h3>📄 Assessment Report (PDF)</h3>
                    <p class="text-sm text-muted" style="line-height:1.5; margin-bottom:12px;">
                      Upload the post-activity assessment, evaluation summary, or narrative assessment report in <strong>PDF format</strong>.
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
                        <span class="file-pill-badge file-pill-pdf" style="flex:1; min-width:0;" title="Uploaded and verified PDF report (not an error)">
                          <span>✓ 📄</span> <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Uploaded PDF: <?= htmlspecialchars($friendlyPdfName) ?></span>
                        </span>
                        <a href="<?= htmlspecialchars($pdfHref) ?>" target="_blank" class="btn btn-outline btn-xs" style="padding:4px 10px; flex-shrink:0;">
                          View PDF
                        </a>
                      </div>
                    <?php endif; ?>
                    <div class="form-group" style="margin-bottom:0;">
                      <label class="form-label" for="assessment_report_file">
                        <strong><?= empty($existingPostEvent['assessment_report_path']) ? 'Upload Assessment Report (PDF)' : 'Replace Assessment Report (PDF)' ?></strong>
                        <span class="text-muted" style="font-weight:normal; font-size:0.78rem;">(Accepts .pdf only)</span>
                      </label>
                      <input type="file" id="assessment_report_file" name="assessment_report_file" class="form-control" accept=".pdf,application/pdf">
                      <small class="text-muted" style="font-size:0.75rem; margin-top:4px; display:block;">
                        Format: PDF (.pdf) only. Maximum allowed size: 15 MB.
                      </small>
                    </div>
                  </div>

                  <!-- 3. Survey-Form Results (CSV format) -->
                  <div class="info-box">
                    <h3>📊 Survey-Form Results (CSV)</h3>
                    <p class="text-sm text-muted" style="line-height:1.5; margin-bottom:12px;">
                      Upload participant evaluation responses exported from <strong>Google Forms</strong>, <strong>Microsoft Forms</strong>, or standard survey templates in <strong>CSV format</strong>.
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
                        <span class="file-pill-badge file-pill-csv" style="flex:1; min-width:0;" title="Uploaded and verified survey CSV">
                          <span>✓ 📊</span> <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">Uploaded Survey CSV: <?= htmlspecialchars($friendlyCsvName) ?></span>
                        </span>
                        <a href="<?= htmlspecialchars($csvHref) ?>" target="_blank" class="btn btn-outline btn-xs" style="padding:4px 10px; flex-shrink:0;">
                          Download CSV
                        </a>
                      </div>
                    <?php endif; ?>
                    <div class="form-group" style="margin-bottom:0;">
                      <label class="form-label" for="survey_results_csv">
                        <strong><?= empty($existingPostEvent['survey_csv_path']) ? 'Upload Survey-Form Results (CSV)' : 'Replace Survey-Form Results (CSV)' ?></strong>
                        <span class="text-muted" style="font-weight:normal; font-size:0.78rem;">(Accepts .csv only)</span>
                      </label>
                      <input type="file" id="survey_results_csv" name="survey_results_csv" class="form-control" accept=".csv,text/csv">
                      <small class="text-muted" style="font-size:0.75rem; margin-top:4px; display:block;">
                        Format: CSV (.csv) exported from survey tools. Maximum allowed size: 10 MB.
                      </small>
                    </div>
                  </div>

                  <!-- 4. Event Documentation & Photos -->
                  <div class="info-box">
                    <h3>📎 Event Documentation &amp; Photos</h3>
                    <p class="text-sm text-muted" style="line-height:1.5; margin-bottom:12px;">
                      Upload documentation collected during the event, including event photos, narrative summaries, signed attendance sheets, or certificates.
                    </p>
                    <?php
                    $existingDocs = $db->prepare("SELECT * FROM documents WHERE activity_id = ? AND doc_type IN ('post_event', 'photo') ORDER BY uploaded_at DESC");
                    $existingDocs->execute([$selectedActivity['id']]);
                    $docList = $existingDocs->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($docList)):
                    ?>
                      <div style="margin-bottom:14px;">
                        <div style="font-size:0.8rem; font-weight:600; color:var(--text-muted); margin-bottom:6px;">Previously Uploaded Documentation (<?= count($docList) ?> files):</div>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                          <?php foreach ($docList as $d): ?>
                            <div class="doc-upload-row">
                              <span class="doc-name">
                                📄 <?= htmlspecialchars($d['file_name'] ?: basename($d['file_path'])) ?>
                                <small style="color:var(--text-muted); font-size:0.72rem; margin-left:6px;"><?= date('M d, Y', strtotime($d['uploaded_at'])) ?></small>
                              </span>
                              <a href="<?= BASE_URL ?>/api/document-download.php?id=<?= (int)$d['id'] ?>" target="_blank" class="btn btn-outline btn-xs" style="padding:2px 8px;font-size:0.72rem;flex-shrink:0;">View</a>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                    <div class="form-group" style="margin-bottom:0;">
                      <label class="form-label">
                        <strong>Upload Additional Documentation</strong>
                      </label>
                      <input type="file" name="post_event_files[]" class="form-control" multiple
                             accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv">
                      <small class="text-muted" style="font-size:.75rem;margin-top:4px;display:block;">
                        Accepted formats: Photos (.jpg, .jpeg, .png), Documents (.pdf, .doc, .docx), Spreadsheets (.xls, .xlsx).
                      </small>
                    </div>
                  </div>

                  <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;font-size:0.95rem;font-weight:600;">
                    <?= $existingPostEvent ? '💾 Save Changes & Update Post-Event Report' : '✅ Confirm Event Conducted & Upload Report' ?>
                  </button>
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
                  });

                  // Client-side file format validation before submission
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

          <?php else: ?>
            <div class="card">
              <div class="card-body" style="text-align:center;padding:60px;">
                <div style="font-size:3rem;margin-bottom:12px;">📋</div>
                <div style="font-weight:600;margin-bottom:6px;">Select an Activity</div>
                <div class="text-muted text-sm">Choose an approved activity on the left to upload or edit its post-event report.</div>
              </div>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</body>

</html>
