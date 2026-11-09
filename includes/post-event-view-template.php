<?php
/**
 * Shared Read-Only Post-Event Results View Template
 * Used by Admin 1 (Student Org Head), Admin 2 (Events Committee Head), and Dean.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($role)) {
    $role = currentUser()['role'] ?? 'faculty';
}
requireRole($role);

$user = currentUser();
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: " . ($backUrl ?? BASE_URL . "/{$role}/post-event.php"));
    exit;
}

// Fetch Activity Details
$actStmt = $db->prepare("
    SELECT a.*, u.name AS faculty_name, u.email AS faculty_email, u.department AS faculty_dept
    FROM activities a
    LEFT JOIN users u ON a.faculty_id = u.id
    WHERE a.id = ?
");
$actStmt->execute([$id]);
$activity = $actStmt->fetch(PDO::FETCH_ASSOC);

// Role Scoping & Activity State Validation
$isAccessible = true;
$accessError = '';

if (!$activity) {
    $isAccessible = false;
    $accessError = 'Activity not found or accessible.';
} elseif ($activity['status'] !== 'completed') {
    $isAccessible = false;
    $accessError = 'This activity is still in progress and has not been marked as completed.';
} elseif ($role === 'admin1' && ($activity['source'] ?? '') !== 'student_org') {
    $isAccessible = false;
    $accessError = 'Activity not found or accessible.';
}

// Fetch Post-Event Submission Record
$postEvent = null;
if ($isAccessible) {
    if (function_exists('reconcilePostEventMetrics')) {
        reconcilePostEventMetrics($db, $id);
    }
    $peStmt = $db->prepare("SELECT * FROM post_event WHERE activity_id = ?");
    $peStmt->execute([$id]);
    $postEvent = $peStmt->fetch(PDO::FETCH_ASSOC);

    if (!$postEvent) {
        $isAccessible = false;
        $accessError = 'Post-event documentation has not been submitted for this activity.';
    }
}

// If inaccessible, show friendly notice
if (!$isAccessible) {
    $themeClass = $themeClass ?? ($role === 'admin1' ? 'theme-arjay' : ($role === 'dean' ? 'theme-dean' : 'theme-ian'));
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Post-Event Results – Access Notice</title>
      <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.8">
    </head>
    <body class="<?= htmlspecialchars($themeClass) ?>">
      <?php include __DIR__ . '/../includes/sidebar.php'; ?>
      <div class="main-wrap">
        <header class="topbar">
          <div class="page-title">Post-Event Results</div>
          <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
            <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
            <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
          </div>
        </header>
        <div class="content">
          <div class="card" style="max-width:680px; margin:40px auto; text-align:center; padding:36px 24px;">
            <div style="font-size:3rem; margin-bottom:12px;">📑</div>
            <h2 style="margin-bottom:10px; color:var(--text-main);">Record Unavailable</h2>
            <p style="color:var(--text-muted); font-size:0.95rem; margin-bottom:24px; line-height:1.5;">
              <?= htmlspecialchars($accessError) ?>
            </p>
            <div>
              <a href="<?= $backUrl ?? (BASE_URL . "/{$role}/post-event.php") ?>" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:6px;">
                <span>←</span> Back to Post-Event Results
              </a>
            </div>
          </div>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Fetch Attached Documents & Separate Photographs vs Files
$docStmt = $db->prepare("
    SELECT * FROM documents 
    WHERE activity_id = ? 
    ORDER BY uploaded_at DESC
");
$docStmt->execute([$id]);
$allDocs = $docStmt->fetchAll(PDO::FETCH_ASSOC);

$photos = [];
$supportingDocs = [];
$imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

foreach ($allDocs as $doc) {
    $ext = strtolower(pathinfo($doc['file_path'] ?: $doc['file_name'], PATHINFO_EXTENSION));
    if ($doc['doc_type'] === 'photo' || in_array($ext, $imageExtensions, true)) {
        $photos[] = $doc;
    } else {
        $supportingDocs[] = $doc;
    }
}

// Attendance Statistics
$targetAtt = (int)($postEvent['target_attendance'] ?? $activity['target_participants'] ?? 0);
$actualAtt = (int)($postEvent['actual_attendance'] ?? 0);
$turnoutPct = ($targetAtt > 0) ? min(100, round(($actualAtt / $targetAtt) * 100)) : 0;
$attMethod = $postEvent['attendance_method'] ?? 'paper_list';

$methodLabels = [
    'paper_list'  => '📄 Paper-Based Attendance Sheet Only',
    'survey_only' => '📊 Evaluation Survey Form / QR-Code Only',
    'both'        => '📑 Hybrid (Paper List & Evaluation Survey)'
];
$methodDisplay = $methodLabels[$attMethod] ?? 'Standard Attendance';

// Parse Survey Results CSV if present
$csvSummary = null;
$csvSampleRows = [];
$csvHeaders = [];
if (!empty($postEvent['survey_csv_path'])) {
    $csvFullPath = function_exists('resolveUploadedFilePath')
        ? resolveUploadedFilePath($postEvent['survey_csv_path'])
        : (__DIR__ . '/../uploads/' . ltrim($postEvent['survey_csv_path'], '/\\'));
    if ($csvFullPath && file_exists($csvFullPath) && is_readable($csvFullPath)) {
        if (function_exists('parseEvaluationCsvFile')) {
            $csvSummary = parseEvaluationCsvFile($csvFullPath);
        }
        
        // Read header and first 10 rows for read-only preview
        $handle = @fopen($csvFullPath, 'r');
        if ($handle) {
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $rowIdx = 0;
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false && $rowIdx <= 10) {
                if ($rowIdx === 0) {
                    $csvHeaders = $row;
                } else {
                    $csvSampleRows[] = $row;
                }
                $rowIdx++;
            }
            fclose($handle);
        }
    }
}

// Fetch KPI evaluations if any
$kpisStmt = $db->prepare("SELECT criteria, AVG(rating) as avg, COUNT(*) as count FROM kpi_evaluations WHERE activity_id=? GROUP BY criteria");
$kpisStmt->execute([$id]);
$kpiData = $kpisStmt->fetchAll(PDO::FETCH_ASSOC);

// Submitter Details
$subStmt = $db->prepare("SELECT name, email, role FROM users WHERE id = ?");
$subStmt->execute([(int)$postEvent['submitted_by']]);
$submitter = $subStmt->fetch(PDO::FETCH_ASSOC);

if (!function_exists('formatFileSizeBytes')) {
    function formatFileSizeBytes(int $bytes): string {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}

$themeClass = $themeClass ?? ($role === 'admin1' ? 'theme-arjay' : ($role === 'dean' ? 'theme-dean' : 'theme-ian'));
$roleTitle = ($role === 'admin1') ? 'Admin 1 — Student Org Head' : (($role === 'dean') ? 'Dean of Academic Affairs' : 'Admin 2 — Events Committee Head');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Post-Event Results: <?= htmlspecialchars($activity['title']) ?> – STI-ARMES</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.8">
  <style>
    .pe-metric-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 14px;
      margin-bottom: 20px;
    }
    .pe-metric-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 14px 16px;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .pe-metric-card .pe-lbl {
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-muted);
      font-weight: 600;
    }
    .pe-metric-card .pe-val {
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--text-main);
    }
    .pe-progress-bar-wrap {
      width: 100%;
      height: 8px;
      background: var(--border);
      border-radius: 4px;
      overflow: hidden;
      margin-top: 6px;
    }
    .pe-progress-bar {
      height: 100%;
      background: #10B981;
      border-radius: 4px;
      transition: width 0.3s ease;
    }
    .pe-photo-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 14px;
      margin-top: 14px;
    }
    .pe-photo-item {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 8px;
      overflow: hidden;
      position: relative;
      display: flex;
      flex-direction: column;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .pe-photo-item:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .pe-photo-thumb {
      width: 100%;
      height: 150px;
      object-fit: cover;
      cursor: pointer;
      display: block;
      background: var(--border);
    }
    .pe-photo-meta {
      padding: 10px 12px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      font-size: 0.78rem;
    }
    .pe-photo-title {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      color: var(--text-main);
      font-weight: 500;
    }
    /* Lightbox Modal */
    .pe-lightbox-modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.85);
      z-index: 99999;
      padding: 24px;
      align-items: center;
      justify-content: center;
      flex-direction: column;
    }
    .pe-lightbox-modal.active {
      display: flex;
    }
    .pe-lightbox-img {
      max-width: 90vw;
      max-height: 80vh;
      border-radius: 8px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
      object-fit: contain;
    }
    .pe-lightbox-bar {
      margin-top: 14px;
      display: flex;
      align-items: center;
      gap: 12px;
      color: #FFFFFF;
    }
    .pe-csv-table-wrap {
      max-height: 380px;
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 6px;
      margin-top: 10px;
    }
    .pe-csv-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.8rem;
    }
    .pe-csv-table th {
      position: sticky;
      top: 0;
      background: var(--bg-card);
      border-bottom: 2px solid var(--border);
      padding: 8px 12px;
      text-align: left;
      color: var(--text-muted);
      font-weight: 600;
      white-space: nowrap;
    }
    .pe-csv-table td {
      border-bottom: 1px solid var(--border);
      padding: 6px 12px;
      color: var(--text-main);
      white-space: nowrap;
    }
    @media print {
      .sidebar, .topbar, .btn, .pe-lightbox-modal { display: none !important; }
      .main-wrap { margin-left: 0 !important; }
    }
  </style>
</head>
<body class="<?= htmlspecialchars($themeClass) ?>">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div style="display:flex;align-items:center;gap:12px;">
        <a href="<?= $backUrl ?? (BASE_URL . "/{$role}/post-event.php") ?>" class="btn btn-outline btn-sm" title="Return to Post-Event Results list">
          <span>←</span> Back
        </a>
        <div class="page-title" style="font-size:1.15rem;font-weight:700;">
          Post-Event Results Review
        </div>
      </div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <button type="button" onclick="window.print()" class="btn btn-outline btn-sm" title="Print this post-event report summary">
          <span>🖨️</span> Print Record
        </button>
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
        <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
      </div>
    </header>

    <div class="content" style="max-width:1200px; margin:0 auto; padding:24px;">

      <!-- Activity Overview Card -->
      <div class="card detail-section-card" style="margin-bottom:20px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
          <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
              <span class="badge <?= ($activity['source'] ?? '') === 'student_org' ? 'badge-info' : 'badge-primary' ?>" style="font-size:0.75rem;">
                <?= ($activity['source'] ?? '') === 'student_org' ? 'Student Organization' : 'Faculty / Academic' ?>
              </span>
              <span style="font-size:0.8rem;color:var(--text-muted);">
                Activity ID: #<?= (int)$activity['id'] ?>
              </span>
            </div>
            <h2 style="font-size:1.45rem;font-weight:700;color:var(--text-main);margin:0;">
              <?= htmlspecialchars($activity['title']) ?>
            </h2>
          </div>
          <div style="text-align:right;">
            <div style="font-size:0.78rem;color:var(--text-muted);">Post-Event Submitted On</div>
            <strong style="font-size:0.9rem;color:var(--text-main);">
              <?= $postEvent['submitted_at'] ? date('M d, Y h:i A', strtotime($postEvent['submitted_at'])) : '—' ?>
            </strong>
          </div>
        </div>
        <div class="card-body">
          <div class="info-grid" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));">
            <div class="info-tile">
              <div class="lbl">Responsible Proponent / Faculty</div>
              <div class="val" style="font-size:0.95rem;font-weight:600;">
                <?= htmlspecialchars($activity['faculty_name'] ?? '—') ?>
              </div>
              <small style="color:var(--text-muted);font-size:0.76rem;">
                <?= htmlspecialchars($activity['faculty_dept'] ?? 'STI College') ?>
              </small>
            </div>
            <div class="info-tile">
              <div class="lbl">Activity Date &amp; Schedule</div>
              <div class="val" style="font-size:0.95rem;font-weight:600;">
                <?= $activity['event_date'] ? date('F j, Y', strtotime($activity['event_date'])) : '—' ?>
              </div>
              <small style="color:var(--text-muted);font-size:0.76rem;">
                <?= htmlspecialchars($activity['start_time'] ?? '') ?><?= !empty($activity['end_time']) ? ' – ' . htmlspecialchars($activity['end_time']) : '' ?>
              </small>
            </div>
            <div class="info-tile">
              <div class="lbl">Venue / Platform</div>
              <div class="val" style="font-size:0.95rem;font-weight:600;">
                <?= htmlspecialchars($activity['venue'] ?: 'On Campus') ?>
              </div>
            </div>
            <div class="info-tile">
              <div class="lbl">Documentation Submitter</div>
              <div class="val" style="font-size:0.95rem;font-weight:600;">
                <?= htmlspecialchars($submitter['name'] ?? $activity['faculty_name'] ?? 'Faculty') ?>
              </div>
              <small style="color:var(--text-muted);font-size:0.76rem;">
                <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $submitter['role'] ?? 'faculty'))) ?>
              </small>
            </div>
          </div>
        </div>
      </div>

      <!-- Attendance & Turnout Information -->
      <div class="card detail-section-card" style="margin-bottom:20px;">
        <div class="card-header">
          <h2><span>📊</span> Attendance &amp; Turnout Metrics</h2>
        </div>
        <div class="card-body">
          <div class="pe-metric-grid">
            <div class="pe-metric-card">
              <span class="pe-lbl">Actual Headcount</span>
              <span class="pe-val" style="color:#0284C7;"><?= number_format($actualAtt) ?></span>
              <small style="font-size:0.75rem;color:var(--text-muted);">Verified attendees</small>
            </div>
            <div class="pe-metric-card">
              <span class="pe-lbl">Target Attendance</span>
              <span class="pe-val"><?= number_format($targetAtt) ?></span>
              <small style="font-size:0.75rem;color:var(--text-muted);">Planned participants</small>
            </div>
            <div class="pe-metric-card">
              <span class="pe-lbl">Turnout Rate</span>
              <span class="pe-val" style="color:<?= $turnoutPct >= 80 ? '#10B981' : ($turnoutPct >= 50 ? '#F59E0B' : '#EF4444') ?>;">
                <?= $turnoutPct ?>%
              </span>
              <div class="pe-progress-bar-wrap">
                <div class="pe-progress-bar" style="width:<?= $turnoutPct ?>%; background:<?= $turnoutPct >= 80 ? '#10B981' : ($turnoutPct >= 50 ? '#F59E0B' : '#EF4444') ?>;"></div>
              </div>
            </div>
            <div class="pe-metric-card">
              <span class="pe-lbl">Satisfaction Score</span>
              <span class="pe-val" style="color:#8B5CF6;">
                <?= isset($postEvent['satisfaction_score']) && $postEvent['satisfaction_score'] !== null ? number_format((float)$postEvent['satisfaction_score'], 1) . '/5.0' : '—' ?>
              </span>
              <small style="font-size:0.75rem;color:var(--text-muted);">From evaluation surveys</small>
            </div>
          </div>

          <div style="padding:10px 14px; background:var(--bg-base); border:1px solid var(--border); border-radius:6px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
            <div style="font-size:0.84rem; color:var(--text-main);">
              <strong>Verification Method:</strong> <?= htmlspecialchars($methodDisplay) ?>
            </div>
            <?php if (!empty($postEvent['manual_attendance'])): ?>
              <div style="font-size:0.8rem; color:var(--text-muted);">
                Paper Sheet Count: <strong><?= (int)$postEvent['manual_attendance'] ?></strong>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Core Post-Event Documents (PDF Assessment Report & CSV Survey) -->
      <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(480px, 1fr)); gap:20px; margin-bottom:20px;">
        
        <!-- Assessment Report (PDF) -->
        <div class="card detail-section-card" style="margin-bottom:0;">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2><span>📑</span> Assessment Report (PDF)</h2>
            <?php if (!empty($postEvent['assessment_report_path'])):
              $pePdfUrl = BASE_URL . '/api/document-download.php?activity_id=' . (int)$activity['id'] . '&type=assessment';
              $pePdfDownloadUrl = BASE_URL . '/api/document-download.php?activity_id=' . (int)$activity['id'] . '&type=assessment&download=1';
            ?>
              <div style="display:flex;gap:6px;">
                <a href="<?= htmlspecialchars($pePdfUrl) ?>" target="_blank" class="btn btn-outline btn-xs" style="font-size:0.75rem;">
                  ↗ Open Full Tab
                </a>
                <a href="<?= htmlspecialchars($pePdfDownloadUrl) ?>" class="btn btn-primary btn-xs" style="font-size:0.75rem;">
                  ⬇ Download PDF
                </a>
              </div>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (!empty($postEvent['assessment_report_path'])): 
              $pdfFull = function_exists('resolveUploadedFilePath')
                ? resolveUploadedFilePath($postEvent['assessment_report_path'])
                : (__DIR__ . '/../uploads/' . ltrim($postEvent['assessment_report_path'], '/\\'));
              $pdfSize = ($pdfFull && file_exists($pdfFull)) ? formatFileSizeBytes((int)filesize($pdfFull)) : '';
              $peFriendlyPdf = function_exists('getFriendlyDocumentFilename')
                ? getFriendlyDocumentFilename($db, $id, $postEvent['assessment_report_path'])
                : basename($postEvent['assessment_report_path']);
            ?>
              <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.28);border-radius:6px;margin-bottom:12px;">
                <span style="font-size:0.84rem;font-weight:600;color:var(--text-main);display:flex;align-items:center;gap:6px;">
                  <span style="color:#059669;font-weight:bold;">[✓ PDF]</span> <?= htmlspecialchars($peFriendlyPdf) ?>
                  <?php if ($pdfSize): ?><small style="color:var(--text-muted);font-size:0.72rem;">(<?= $pdfSize ?>)</small><?php endif; ?>
                </span>
                <span class="badge badge-success" style="font-size:0.72rem;">Verified Document</span>
              </div>
              <div style="width:100%; height:460px; border:1px solid var(--border); border-radius:6px; overflow:hidden; background:var(--bg-base);">
                <iframe src="<?= htmlspecialchars($pePdfUrl) ?>#toolbar=1" style="width:100%; height:100%; border:none;" title="Assessment Report Preview">
                  <p style="padding:20px;text-align:center;color:var(--text-muted);">
                    Your browser does not support embedded PDF previews. 
                    <a href="<?= htmlspecialchars($pePdfUrl) ?>" target="_blank" class="btn btn-primary btn-sm">Download or View PDF</a>
                  </p>
                </iframe>
              </div>
            <?php else: ?>
              <div style="padding:36px;text-align:center;color:var(--text-muted);background:var(--bg-base);border:1px dashed var(--border);border-radius:6px;">
                <span style="font-size:2rem;display:block;margin-bottom:6px;">📄</span>
                No official Assessment Report PDF was uploaded for this activity.
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Survey Results (CSV) -->
        <div class="card detail-section-card" style="margin-bottom:0;">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <h2><span>📋</span> Survey Results (CSV)</h2>
            <?php if (!empty($postEvent['survey_csv_path'])):
              $peCsvUrl = BASE_URL . '/api/document-download.php?activity_id=' . (int)$activity['id'] . '&type=survey_csv&download=1';
            ?>
              <div style="display:flex;gap:6px;">
                <a href="<?= htmlspecialchars($peCsvUrl) ?>" class="btn btn-primary btn-xs" style="font-size:0.75rem;">
                  ⬇ Download CSV
                </a>
              </div>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (!empty($postEvent['survey_csv_path'])): 
              $csvFull = function_exists('resolveUploadedFilePath')
                ? resolveUploadedFilePath($postEvent['survey_csv_path'])
                : (__DIR__ . '/../uploads/' . ltrim($postEvent['survey_csv_path'], '/\\'));
              $csvSize = ($csvFull && file_exists($csvFull)) ? formatFileSizeBytes((int)filesize($csvFull)) : '';
              $peFriendlyCsv = function_exists('getFriendlyDocumentFilename')
                ? getFriendlyDocumentFilename($db, $id, $postEvent['survey_csv_path'])
                : basename($postEvent['survey_csv_path']);
            ?>
              <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;background:rgba(2,132,199,0.06);border:1px solid rgba(2,132,199,0.2);border-radius:6px;margin-bottom:12px;">
                <span style="font-size:0.84rem;font-weight:600;color:var(--text-main);display:flex;align-items:center;gap:6px;">
                  <span style="color:#0284c7;font-weight:bold;">[✓ CSV]</span> <?= htmlspecialchars($peFriendlyCsv) ?>
                  <?php if ($csvSize): ?><small style="color:var(--text-muted);font-size:0.72rem;">(<?= $csvSize ?>)</small><?php endif; ?>
                </span>
                <?php if ($csvSummary && !empty($csvSummary['respondent_count'])): ?>
                  <span class="badge badge-info" style="font-size:0.72rem;">
                    <?= (int)$csvSummary['respondent_count'] ?> Respondents
                  </span>
                <?php endif; ?>
              </div>

              <?php if (!empty($csvHeaders) && !empty($csvSampleRows)): ?>
                <div style="font-size:0.78rem;font-weight:600;color:var(--text-muted);margin-bottom:6px;">
                  Raw CSV Data Preview (Top <?= count($csvSampleRows) ?> Responses):
                </div>
                <div class="pe-csv-table-wrap">
                  <table class="pe-csv-table">
                    <thead>
                      <tr>
                        <?php foreach ($csvHeaders as $h): ?>
                          <th><?= htmlspecialchars($h) ?></th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($csvSampleRows as $row): ?>
                        <tr>
                          <?php foreach ($row as $cell): ?>
                            <td><?= htmlspecialchars($cell) ?></td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div style="padding:24px;text-align:center;color:var(--text-muted);background:var(--bg-base);border:1px dashed var(--border);border-radius:6px;">
                  Survey file is stored on the server. Click "Download CSV" to inspect all respondent submissions.
                </div>
              <?php endif; ?>

              <?php if ($csvSummary && !empty($csvSummary['criteria_averages'])): ?>
                <div style="margin-top:14px;">
                  <div style="font-size:0.78rem;font-weight:600;color:var(--text-muted);margin-bottom:8px;">Computed Criteria Breakdown:</div>
                  <div style="display:flex;flex-direction:column;gap:6px;">
                    <?php foreach ($csvSummary['criteria_averages'] as $ca): ?>
                      <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 10px;background:var(--bg-base);border:1px solid var(--border);border-radius:6px;font-size:0.8rem;">
                        <span style="color:var(--text-main);"><?= htmlspecialchars($ca['criteria']) ?></span>
                        <strong style="color:#0284C7;"><?= number_format($ca['avg_rating'], 2) ?> / 4.0</strong>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>

            <?php else: ?>
              <div style="padding:36px;text-align:center;color:var(--text-muted);background:var(--bg-base);border:1px dashed var(--border);border-radius:6px;">
                <span style="font-size:2rem;display:block;margin-bottom:6px;">📊</span>
                No evaluation survey CSV file was uploaded for this activity.
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>

      <!-- Event Photographs Gallery -->
      <div class="card detail-section-card" style="margin-bottom:20px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h2><span>📸</span> Event Photographs (<?= count($photos) ?>)</h2>
          <span style="font-size:0.78rem;color:var(--text-muted);">Click on any photograph to preview in full resolution</span>
        </div>
        <div class="card-body">
          <?php if (!empty($photos)): ?>
            <div class="pe-photo-grid">
              <?php foreach ($photos as $p): 
                $pPath = !empty($p['id'])
                  ? (BASE_URL . '/api/document-download.php?id=' . (int)$p['id'])
                  : (function_exists('resolveUploadedFileUrl') ? resolveUploadedFileUrl($p['file_path']) : (BASE_URL . '/uploads/' . ltrim($p['file_path'], '/\\')));
                $pDownloadUrl = !empty($p['id'])
                  ? (BASE_URL . '/api/document-download.php?id=' . (int)$p['id'] . '&download=1')
                  : $pPath;
                $pName = $p['file_name'] ?: basename($p['file_path']);
              ?>
                <div class="pe-photo-item">
                  <img src="<?= htmlspecialchars($pPath) ?>" alt="<?= htmlspecialchars($pName) ?>" class="pe-photo-thumb" onclick="openLightbox('<?= htmlspecialchars(addslashes($pPath)) ?>', '<?= htmlspecialchars(addslashes($pName)) ?>')" loading="lazy">
                  <div class="pe-photo-meta">
                    <span class="pe-photo-title" title="<?= htmlspecialchars($pName) ?>"><?= htmlspecialchars($pName) ?></span>
                    <a href="<?= htmlspecialchars($pDownloadUrl) ?>" class="btn btn-outline btn-xs" style="padding:2px 8px;font-size:0.7rem;" title="Download photo">
                      ⬇
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="padding:32px;text-align:center;color:var(--text-muted);background:var(--bg-base);border:1px dashed var(--border);border-radius:6px;">
              <span style="font-size:2rem;display:block;margin-bottom:6px;">📷</span>
              No event photographs were submitted for this activity.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Other Supporting Documentation -->
      <div class="card detail-section-card" style="margin-bottom:20px;">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h2><span>📎</span> Supporting Documentation &amp; Attachments (<?= count($supportingDocs) ?>)</h2>
          <span style="font-size:0.78rem;color:var(--text-muted);">Signed attendance sheets, narrative summaries, liquidations, and certificates</span>
        </div>
        <div class="card-body">
          <?php if (!empty($supportingDocs)): ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <?php foreach ($supportingDocs as $doc): 
                $dName = $doc['file_name'] ?: basename($doc['file_path']);
                $dExt = strtolower(pathinfo($doc['file_path'] ?: $doc['file_name'], PATHINFO_EXTENSION));
                $icon = in_array($dExt, ['doc','docx'], true) ? '📝' : (in_array($dExt, ['xls','xlsx'], true) ? '📊' : '📄');
              ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--bg-base);border:1px solid var(--border);border-radius:6px;gap:12px;flex-wrap:wrap;">
                  <div style="display:flex;align-items:center;gap:10px;min-width:0;overflow:hidden;">
                    <span style="font-size:1.3rem;"><?= $icon ?></span>
                    <div style="min-width:0;overflow:hidden;">
                      <div style="font-size:0.86rem;font-weight:600;color:var(--text-main);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($dName) ?>
                      </div>
                      <small style="color:var(--text-muted);font-size:0.74rem;">
                        Uploaded <?= date('M d, Y h:i A', strtotime($doc['uploaded_at'])) ?>
                      </small>
                    </div>
                  </div>
                  <div style="display:flex;gap:6px;flex-shrink:0;">
                    <a href="<?= BASE_URL ?>/api/document-download.php?id=<?= (int)$doc['id'] ?>" target="_blank" class="btn btn-outline btn-xs" style="font-size:0.75rem;">
                      View
                    </a>
                    <a href="<?= BASE_URL ?>/api/document-download.php?id=<?= (int)$doc['id'] ?>&download=1" class="btn btn-primary btn-xs" style="font-size:0.75rem;">
                      ⬇ Download
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div style="padding:28px;text-align:center;color:var(--text-muted);background:var(--bg-base);border:1px dashed var(--border);border-radius:6px;">
              No additional supporting documentation files were attached.
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Observations & Recommendations -->
      <?php if (!empty($postEvent['observations']) || !empty($postEvent['recommendations'])): ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:20px; margin-bottom:20px;">
          <?php if (!empty($postEvent['observations'])): ?>
            <div class="card detail-section-card" style="margin-bottom:0;">
              <div class="card-header">
                <h2><span>🔍</span> Observations</h2>
              </div>
              <div class="card-body">
                <div style="font-size:0.88rem; line-height:1.6; color:var(--text-main); white-space:pre-wrap;">
                  <?= nl2br(htmlspecialchars($postEvent['observations'])) ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($postEvent['recommendations'])): ?>
            <div class="card detail-section-card" style="margin-bottom:0;">
              <div class="card-header">
                <h2><span>💡</span> Recommendations for Future Activities</h2>
              </div>
              <div class="card-body">
                <div style="font-size:0.88rem; line-height:1.6; color:var(--text-main); white-space:pre-wrap;">
                  <?= nl2br(htmlspecialchars($postEvent['recommendations'])) ?>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Lightbox Modal for Photo Previews -->
  <div id="peLightboxModal" class="pe-lightbox-modal" onclick="closeLightbox(event)">
    <div style="display:flex;justify-content:flex-end;width:100%;max-width:90vw;margin-bottom:8px;">
      <button type="button" onclick="closeLightboxDirect()" style="background:rgba(255,255,255,0.2);border:none;color:#FFF;padding:6px 14px;border-radius:4px;cursor:pointer;font-size:1.1rem;font-weight:700;">
        ✕ Close
      </button>
    </div>
    <img id="peLightboxImg" src="" alt="Full preview" class="pe-lightbox-img" onclick="event.stopPropagation()">
    <div class="pe-lightbox-bar" onclick="event.stopPropagation()">
      <span id="peLightboxTitle" style="font-size:0.88rem;font-weight:500;"></span>
      <a id="peLightboxDownload" href="#" download class="btn btn-primary btn-sm" style="font-size:0.75rem;">
        ⬇ Download Full Resolution
      </a>
    </div>
  </div>

  <script>
    function openLightbox(url, title) {
      const modal = document.getElementById('peLightboxModal');
      const img = document.getElementById('peLightboxImg');
      const titleSpan = document.getElementById('peLightboxTitle');
      const dlLink = document.getElementById('peLightboxDownload');
      if (!modal || !img) return;

      img.src = url;
      titleSpan.textContent = title;
      dlLink.href = url;
      modal.classList.add('active');
    }

    function closeLightbox(e) {
      if (e.target.id === 'peLightboxModal') {
        closeLightboxDirect();
      }
    }

    function closeLightboxDirect() {
      const modal = document.getElementById('peLightboxModal');
      if (modal) {
        modal.classList.remove('active');
      }
    }

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        closeLightboxDirect();
      }
    });
  </script>
</body>
</html>
