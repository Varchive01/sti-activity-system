<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/kpi_helper.php';
requireRole('dean', 'admin1', 'admin2', 'faculty');
$user = currentUser();
$db   = getDB();

$activityId = (int)($_GET['activity_id'] ?? 0);

if ($activityId > 0) {
    // Mode A: Individual Activity KPI Report
    $actStmt = $db->prepare("
        SELECT a.*, u.name as faculty_name, u.department as leader_dept 
        FROM activities a 
        JOIN users u ON a.faculty_id=u.id 
        WHERE a.id = ?
    ");
    $actStmt->execute([$activityId]);
    $activity = $actStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        http_response_code(404);
        die("Activity not found.");
    }

    // Role-based access control for individual report:
    // Faculty can ONLY access if they own the activity AND the activity is completed
    if ($user['role'] === 'faculty') {
        if ((int)$activity['faculty_id'] !== (int)$user['id'] || $activity['status'] !== 'completed') {
            http_response_code(403);
            die("Access denied.");
        }
    }

    // Compute KPI results
    $kpiResults = calculateActivityKpis($activityId, $db);

    // Fetch cached AI interpretation
    $aiStmt = $db->prepare("SELECT analytics_json FROM activity_kpi_analytics WHERE activity_id = ?");
    $aiStmt->execute([$activityId]);
    $aiRow = $aiStmt->fetch(PDO::FETCH_ASSOC);
    $aiInsights = $aiRow ? json_decode($aiRow['analytics_json'], true) : null;

    // 1. Retrieve Approval & Endorsement History
    $logsStmt = $db->prepare("
        SELECT l.*, u.name as reviewer_name, u.role as reviewer_role 
        FROM approval_logs l 
        LEFT JOIN users u ON l.reviewer_id = u.id 
        WHERE l.activity_id = ? 
        ORDER BY l.acted_at ASC, l.id ASC
    ");
    $logsStmt->execute([$activityId]);
    $approvalLogs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Retrieve Centralized Activity Documentation
    $docsStmt = $db->prepare("
        SELECT d.id, d.activity_id, d.doc_type, d.file_name, d.uploaded_at, u.name as uploader_name 
        FROM documents d 
        LEFT JOIN users u ON d.uploaded_by = u.id 
        WHERE d.activity_id = ? 
        ORDER BY d.uploaded_at ASC, d.id ASC
    ");
    $docsStmt->execute([$activityId]);
    $activityDocs = $docsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Retrieve Participant Feedback Comments
    $commentsStmt = $db->prepare("
        SELECT criteria, rating, comments, evaluator_name, evaluated_at 
        FROM kpi_evaluations 
        WHERE activity_id = ? AND comments IS NOT NULL AND TRIM(comments) != '' 
        ORDER BY id ASC
    ");
    $commentsStmt->execute([$activityId]);
    $participantComments = $commentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Retrieve Qualitative AI Feedback Analysis (advisory; do not regenerate)
    $fbStmt = $db->prepare("SELECT analysis_json, updated_at FROM activity_feedback_analysis WHERE activity_id = ?");
    $fbStmt->execute([$activityId]);
    $fbRow = $fbStmt->fetch(PDO::FETCH_ASSOC);
    $feedbackAnalysis = $fbRow ? json_decode($fbRow['analysis_json'], true) : null;

    // Retrieve all participant evaluations for report/export
    $evalStmt = $db->prepare("
        SELECT criteria, rating, comments, evaluator_name, evaluated_at 
        FROM kpi_evaluations 
        WHERE activity_id = ? 
        ORDER BY id ASC
    ");
    $evalStmt->execute([$activityId]);
    $allEvaluations = $evalStmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute KPI rows
    $kpis = $kpiResults['kpis'] ?? [];
    $allKpiRows = [];
    if (!empty($kpis['attendance'])) $allKpiRows[] = $kpis['attendance'];
    if (!empty($kpis['satisfaction'])) $allKpiRows[] = $kpis['satisfaction'];
    if (!empty($kpis['criteria'])) {
        foreach ($kpis['criteria'] as $cr) {
            $allKpiRows[] = $cr;
        }
    }

    // ── Timing & Completion Calculation Helpers for Task Progress ─────────
    if (!function_exists('calculateTaskTiming')) {
        function calculateTaskTiming(string $status, ?string $dueDate): array {
            if ($status === 'Completed') {
                return [
                    'key'      => 'Completed',
                    'label'    => 'Completed',
                    'badge'    => '<span class="badge badge-success" style="background:#10b981;color:#fff;">Completed</span>',
                    'color'    => '#10b981',
                    'bg_light' => 'rgba(16, 185, 129, 0.12)'
                ];
            }

            $today = date('Y-m-d');

            // Explicit Delayed status or past due date
            if ($status === 'Delayed') {
                return [
                    'key'      => 'Delayed',
                    'label'    => 'Delayed',
                    'badge'    => '<span class="badge badge-danger" style="background:#ef4444;color:#fff;">Delayed</span>',
                    'color'    => '#ef4444',
                    'bg_light' => 'rgba(239, 68, 68, 0.14)'
                ];
            }

            if (!empty($dueDate)) {
                if ($dueDate < $today) {
                    return [
                        'key'      => 'Delayed',
                        'label'    => 'Delayed',
                        'badge'    => '<span class="badge badge-danger" style="background:#ef4444;color:#fff;">Delayed (Overdue)</span>',
                        'color'    => '#ef4444',
                        'bg_light' => 'rgba(239, 68, 68, 0.14)'
                    ];
                }

                // Target date approaching within 3 days
                $diffDays = (int)ceil((strtotime($dueDate) - strtotime($today)) / 86400);
                if ($diffDays >= 0 && $diffDays <= 3) {
                    return [
                        'key'      => 'At Risk',
                        'label'    => 'At Risk',
                        'badge'    => '<span class="badge badge-warning" style="background:#f59e0b;color:#fff;">At Risk (' . $diffDays . 'd left)</span>',
                        'color'    => '#f59e0b',
                        'bg_light' => 'rgba(245, 158, 11, 0.15)'
                    ];
                }
            }

            return [
                'key'      => 'On Track',
                'label'    => 'On Track',
                'badge'    => '<span class="badge badge-primary" style="background:#0284c7;color:#fff;">On Track</span>',
                'color'    => '#0284c7',
                'bg_light' => 'rgba(2, 132, 199, 0.12)'
            ];
        }
    }

    if (!function_exists('getTaskCompletionPct')) {
        function getTaskCompletionPct(string $status, ?string $desc = '', ?string $title = '', ?int $explicitPct = null): int {
            if ($status === 'Completed') {
                return 100;
            }
            if ($explicitPct !== null && $explicitPct > 0 && $explicitPct <= 100) {
                return $explicitPct;
            }

            if (!empty($desc) && preg_match('/(?:progress|completion)?\s*[:=\-]?\s*(\d{1,3})%/i', $desc, $m)) {
                return max(0, min(100, (int)$m[1]));
            }
            if (!empty($title) && preg_match('/(?:progress|completion)?\s*[:=\-]?\s*(\d{1,3})%/i', $title, $m)) {
                return max(0, min(100, (int)$m[1]));
            }

            if ($status === 'Not Started') {
                return 0;
            }
            if ($status === 'Delayed') {
                return 25;
            }

            return 50;
        }
    }

    // ── Retrieve Task & Role Assignment Progress Data ────────────────────
    $tasksStmt = $db->prepare("
        SELECT ft.*,
               au.name as assigned_user_name,
               au.department as assigned_user_dept
        FROM faculty_tasks ft
        LEFT JOIN users au ON ft.assigned_user_id = au.id
        WHERE ft.activity_id = ?
        ORDER BY ft.due_date ASC, ft.id DESC
    ");
    $tasksStmt->execute([$activityId]);
    $rawReportTasks = $tasksStmt->fetchAll(PDO::FETCH_ASSOC);

    $spearheadDept = (!empty($activity['leader_dept']) && $activity['leader_dept'] !== 'Unassigned Dept') ? $activity['leader_dept'] : 'Unassigned';

    $reportTasks = [];
    $reportTasksTotal = count($rawReportTasks);
    $reportTasksCompleted = 0;
    $reportTasksInProgress = 0;
    $reportTasksDelayed = 0;
    $reportTasksSumPct = 0;

    foreach ($rawReportTasks as $r) {
        $tTitle = $r['task_title'] ?: ($r['assigned_task'] ?: 'Untitled Task');
        $tDesc  = $r['task_description'] ?: ($r['contribution_desc'] ?: '');
        $tMember = !empty($r['assigned_user_name']) ? $r['assigned_user_name'] : (!empty($r['faculty_name']) ? $r['faculty_name'] : 'Unassigned');
        $cRaw = trim($r['committee'] ?? '');
        $tComm = ($cRaw !== '') ? $cRaw : 'Unassigned';
        $tDue   = !empty($r['due_date']) ? date('Y-m-d', strtotime($r['due_date'])) : null;
        $tStatus = in_array($r['status'], ['Not Started', 'In Progress', 'Completed', 'Delayed']) ? $r['status'] : 'Not Started';
        $rawPct  = (isset($r['completion_pct']) && $r['completion_pct'] !== null && $r['completion_pct'] !== '') ? (int)$r['completion_pct'] : null;
        $tPct    = getTaskCompletionPct($tStatus, $tDesc, $tTitle, $rawPct);
        $timing  = calculateTaskTiming($tStatus, $tDue);

        if ($tStatus === 'Completed') {
            $reportTasksCompleted++;
        } elseif ($tStatus === 'In Progress') {
            $reportTasksInProgress++;
        }

        if ($timing['key'] === 'Delayed') {
            $reportTasksDelayed++;
        }

        $reportTasksSumPct += $tPct;

        $reportTasks[] = [
            'id'             => (int)$r['id'],
            'task_title'     => $tTitle,
            'task_description' => $tDesc,
            'committee'      => $tComm,
            'member'         => $tMember,
            'due_date'       => $tDue,
            'completion_pct' => $tPct,
            'status'         => $tStatus,
            'timing_key'     => $timing['key'],
            'timing_label'   => $timing['label'],
            'timing_badge'   => $timing['badge'],
            'timing_color'   => $timing['color'] ?? '#0284c7',
        ];
    }

    $reportTasksOverallPct = $reportTasksTotal > 0 ? (int)round($reportTasksSumPct / $reportTasksTotal) : 0;

    // CSV Export Handler (?export=csv)
    if (strtolower((string)($_GET['export'] ?? '')) === 'csv') {
        $safeTitle = preg_replace('/[^a-zA-Z0-9_-]/', '_', $activity['title']);
        $filename = 'Activity_Report_' . $activityId . '_' . substr($safeTitle, 0, 30) . '_' . date('Ymd_His') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Excel compatibility
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

        // 1. Report Header Information
        fputcsv($out, ['STI College Marikina - Academic Activity KPI Report']);
        fputcsv($out, ['Report Mode', 'Individual Activity Report']);
        fputcsv($out, ['Generated Date', date('Y-m-d H:i:s')]);
        fputcsv($out, ['Generated By', ($user['name'] ?? 'Administrator') . ' (' . ($user['role'] ?? 'dean') . ')']);
        fputcsv($out, []);

        // 2. Activity Information
        fputcsv($out, ['--- ACTIVITY INFORMATION ---']);
        fputcsv($out, ['Activity ID', $activity['id']]);
        fputcsv($out, ['Title', $activity['title']]);
        fputcsv($out, ['Faculty In-Charge', $activity['faculty_name']]);
        fputcsv($out, ['Status', ucfirst($activity['status'])]);
        fputcsv($out, ['Event Date', $activity['event_date'] ?? 'N/A']);
        fputcsv($out, ['Venue', $activity['venue'] ?? 'N/A']);
        fputcsv($out, ['Venue Address', $activity['venue_address'] ?? 'N/A']);
        fputcsv($out, ['Target Participants', $activity['target_participants'] ?? 'N/A']);
        fputcsv($out, ['Source', ucfirst(str_replace('_', ' ', $activity['source'] ?? 'faculty'))]);
        fputcsv($out, ['Overall Performance Score', ($kpiResults['overall_performance'] !== null ? $kpiResults['overall_performance'] . '%' : 'N/A') . ' (' . $kpiResults['overall_status'] . ')']);
        fputcsv($out, []);

        // Task & Performance Summary
        fputcsv($out, ['--- TASK & PERFORMANCE SUMMARY ---']);
        fputcsv($out, ['Total Tasks', $reportTasksTotal]);
        fputcsv($out, ['Completed', $reportTasksCompleted]);
        fputcsv($out, ['In Progress', $reportTasksInProgress]);
        fputcsv($out, ['Delayed', $reportTasksDelayed]);
        fputcsv($out, ['Overall Completion %', $reportTasksOverallPct . '%']);
        fputcsv($out, []);
        fputcsv($out, ['Activity', 'Spearheading Department', 'Committee', 'Member', 'Task', 'Due Date', 'Completion %', 'Status', 'Timing']);
        if (empty($reportTasks)) {
            fputcsv($out, ['No assigned tasks recorded for this activity.']);
        } else {
            foreach ($reportTasks as $t) {
                fputcsv($out, [
                    $activity['title'],
                    $spearheadDept,
                    $t['committee'],
                    $t['member'],
                    $t['task_title'],
                    $t['due_date'] ?? 'N/A',
                    $t['completion_pct'] . '%',
                    $t['status'],
                    $t['timing_label']
                ]);
            }
        }
        fputcsv($out, []);

        // 3. KPI Results & Achievement Values
        fputcsv($out, ['--- KPI RESULTS & ACHIEVEMENT VALUES ---']);
        fputcsv($out, ['KPI / Indicator', 'Target', 'Actual', 'Achievement', 'Status']);
        if (empty($allKpiRows)) {
            fputcsv($out, ['No deterministic KPI calculation records available for this activity.']);
        } else {
            foreach ($allKpiRows as $k) {
                fputcsv($out, [
                    $k['indicator'],
                    $k['target'],
                    $k['actual'],
                    $k['achievement'],
                    $k['status']
                ]);
            }
        }
        fputcsv($out, []);

        // 4. Participant Evaluation Results & Comments
        fputcsv($out, ['--- PARTICIPANT EVALUATION RESULTS & COMMENTS ---']);
        fputcsv($out, ['#', 'Criteria / Question', 'Rating (1-4)', 'Evaluator', 'Date', 'Comments']);
        if (empty($allEvaluations)) {
            fputcsv($out, ['No participant evaluation records recorded for this activity.']);
        } else {
            foreach ($allEvaluations as $idx => $ev) {
                $ratingStr = $ev['rating'] !== null ? $ev['rating'] . ' / 4' : 'N/A';
                $commentStr = trim((string)($ev['comments'] ?? ''));
                fputcsv($out, [
                    $idx + 1,
                    $ev['criteria'] ?? 'General',
                    $ratingStr,
                    $ev['evaluator_name'] ?? 'Anonymous',
                    $ev['evaluated_at'] ? date('Y-m-d H:i', strtotime($ev['evaluated_at'])) : 'N/A',
                    $commentStr !== '' ? $commentStr : '-'
                ]);
            }
        }
        fputcsv($out, []);

        // 5. Approval & Endorsement History
        fputcsv($out, ['--- APPROVAL & ENDORSEMENT HISTORY ---']);
        fputcsv($out, ['Reviewer', 'Role', 'Action', 'Date & Time', 'Remarks / Notes']);
        if (empty($approvalLogs)) {
            fputcsv($out, ['No approval history recorded for this activity.']);
        } else {
            foreach ($approvalLogs as $log) {
                fputcsv($out, [
                    $log['reviewer_name'] ?? 'System',
                    ucfirst($log['reviewer_role'] ?? 'N/A'),
                    ucfirst($log['action']),
                    $log['acted_at'] ? date('Y-m-d H:i:s', strtotime($log['acted_at'])) : 'N/A',
                    $log['notes'] ?? '-'
                ]);
            }
        }
        fputcsv($out, []);

        // 6. Centralized Activity Documentation
        fputcsv($out, ['--- CENTRALIZED ACTIVITY DOCUMENTATION ---']);
        fputcsv($out, ['Document Name', 'Type', 'Uploaded By', 'Upload Date', 'Secure Download URL']);
        if (empty($activityDocs)) {
            fputcsv($out, ['No attached documents recorded for this activity.']);
        } else {
            foreach ($activityDocs as $doc) {
                fputcsv($out, [
                    $doc['file_name'],
                    ucwords(str_replace('_', ' ', $doc['doc_type'] ?? 'document')),
                    $doc['uploader_name'] ?? 'N/A',
                    $doc['uploaded_at'] ? date('Y-m-d H:i:s', strtotime($doc['uploaded_at'])) : 'N/A',
                    BASE_URL . '/api/document-download.php?id=' . (int)$doc['id']
                ]);
            }
        }
        fputcsv($out, []);

        // 7. AI Qualitative Feedback Analysis Summary
        fputcsv($out, ['--- AI QUALITATIVE FEEDBACK ANALYSIS ---']);
        if (empty($feedbackAnalysis)) {
            fputcsv($out, ['No qualitative participant feedback analysis generated yet.']);
        } else {
            $statusStr = 'Unreviewed AI Draft';
            if (!empty($feedbackAnalysis['is_finalized'])) {
                $statusStr = 'Finalized by ' . ($feedbackAnalysis['finalized_by']['name'] ?? 'Administrator') . ' (' . ($feedbackAnalysis['finalized_by']['role'] ?? 'Admin') . ') on ' . ($feedbackAnalysis['finalized_at'] ?? 'N/A');
            } elseif (!empty($feedbackAnalysis['is_reviewed'])) {
                $statusStr = 'Administrator Reviewed Draft';
            }
            fputcsv($out, ['Analysis Status', $statusStr]);
            if (!empty($feedbackAnalysis['overall_summary'])) {
                fputcsv($out, ['Thematic Summary', $feedbackAnalysis['overall_summary']]);
            }
            if (!empty($feedbackAnalysis['positive_themes'])) {
                fputcsv($out, ['Positive Themes']);
                foreach ($feedbackAnalysis['positive_themes'] as $i => $pt) {
                    $freq = isset($pt['frequency']) ? ' (' . (int)$pt['frequency'] . ' responses)' : '';
                    fputcsv($out, ['  ' . ($i + 1) . '. ' . $pt['theme'] . $freq, $pt['summary'] ?? '']);
                }
            }
            if (!empty($feedbackAnalysis['improvement_themes'])) {
                fputcsv($out, ['Areas for Improvement']);
                foreach ($feedbackAnalysis['improvement_themes'] as $i => $it) {
                    $freq = isset($it['frequency']) ? ' (' . (int)$it['frequency'] . ' responses)' : '';
                    fputcsv($out, ['  ' . ($i + 1) . '. ' . $it['theme'] . $freq, $it['summary'] ?? '']);
                }
            }
            if (!empty($feedbackAnalysis['key_findings'])) {
                fputcsv($out, ['Key Findings']);
                foreach ($feedbackAnalysis['key_findings'] as $kf) {
                    fputcsv($out, ['  - ' . $kf]);
                }
            }
            if (!empty($feedbackAnalysis['common_suggestions'])) {
                fputcsv($out, ['Common Suggestions']);
                foreach ($feedbackAnalysis['common_suggestions'] as $cs) {
                    fputcsv($out, ['  - ' . $cs]);
                }
            }
            if (!empty($feedbackAnalysis['recommendations'])) {
                fputcsv($out, ['Actionable Recommendations']);
                foreach ($feedbackAnalysis['recommendations'] as $ar) {
                    fputcsv($out, ['  - ' . $ar]);
                }
            }
        }
        fputcsv($out, []);

        // 8. AI Numerical KPI Insights Summary
        fputcsv($out, ['--- AI NUMERICAL KPI INTERPRETATION (ADVISORY ONLY) ---']);
        if (empty($aiInsights)) {
            fputcsv($out, ['No AI interpretation generated yet.']);
        } else {
            if (!empty($aiInsights['overall_insight'])) {
                fputcsv($out, ['Overall Analysis Summary', $aiInsights['overall_insight']]);
            }
            if (!empty($aiInsights['strengths'])) {
                fputcsv($out, ['Strengths']);
                foreach ($aiInsights['strengths'] as $s) {
                    fputcsv($out, ['  - ' . $s]);
                }
            }
            if (!empty($aiInsights['areas_of_attention'])) {
                fputcsv($out, ['Areas of Attention']);
                foreach ($aiInsights['areas_of_attention'] as $a) {
                    fputcsv($out, ['  - ' . $a]);
                }
            }
            if (!empty($aiInsights['key_findings'])) {
                fputcsv($out, ['Key Findings']);
                foreach ($aiInsights['key_findings'] as $f) {
                    fputcsv($out, ['  - ' . $f]);
                }
            }
            if (!empty($aiInsights['recommendations'])) {
                fputcsv($out, ['Recommendations']);
                foreach ($aiInsights['recommendations'] as $r) {
                    fputcsv($out, ['  - ' . $r]);
                }
            }
        }
        fputcsv($out, ['Advisory Note', 'AI-generated insights are advisory only and should be reviewed by authorized administrators before making decisions.']);

        fclose($out);
        exit;
    }
    
    // Render individual report page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="UTF-8">
      <title>KPI Report - <?= htmlspecialchars($activity['title']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script>
    (function() {
      try {
        var savedTheme = localStorage.getItem('sti-theme');
        if (savedTheme === 'dark') {
          document.documentElement.dataset.theme = 'dark';
        } else {
          document.documentElement.dataset.theme = 'light';
        }
      } catch (e) {}
    })();
  </script>
      <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
      <style>
        .print-only { display: block; }
        .no-print { display: block; }
        @media print {
          .no-print { display: none !important; }
          .sidebar { display: none !important; }
          body { font-size: 12px; background: white; color: black; }
          .main-wrap { margin-left: 0 !important; }
          .content { padding: 0 !important; }
          .card { border: 1px solid #ddd !important; box-shadow: none !important; margin-bottom: 16px !important; page-break-inside: avoid; }
        }
        .report-header { border-bottom: 2px solid var(--accent); padding-bottom: 12px; margin-bottom: 20px; }
        .report-section { margin-bottom: 20px; }
        .kpi-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .kpi-table th, .kpi-table td { border: 1px solid var(--border); padding: 8px 12px; text-align: left; }
        .kpi-table th { background: var(--bg-base); }
      </style>
    </head>
    <?php
    $themeMap = ['faculty' => 'theme-faculty', 'admin1' => 'theme-arjay', 'admin2' => 'theme-ian', 'dean' => 'theme-dean'];
    $bodyTheme = $themeMap[$user['role']] ?? 'theme-dean';
    ?>
    <body class="<?= $bodyTheme ?>">
      <?php include __DIR__ . '/../includes/sidebar.php'; ?>
      <div class="main-wrap">
        <header class="topbar no-print">
          <div class="page-title">KPI Report Generator</div>
          <div class="topbar-right">
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
            <button class="btn btn-primary" onclick="window.print()">🖨 Print / Export PDF</button>
            <a href="?activity_id=<?= $activityId ?>&export=csv" class="btn btn-outline">📥 Export CSV</a>
            <a href="javascript:window.close()" class="btn btn-outline">Close</a>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
        </header>
        <div class="content">
          <div class="report-header">
            <div style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.35rem; font-weight:800;">STI College Marikina</div>
            <div style="font-size:1.1rem; font-weight:700; color:var(--accent); margin-top:4px;">Academic Activity KPI Report</div>
            <div class="text-sm text-muted" style="margin-top:6px;">Generated: <?= date('F j, Y g:i A') ?></div>
          </div>

          <div class="card report-section">
            <div class="card-header"><h2>Activity Information</h2></div>
            <div class="card-body">
              <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div><strong>Title:</strong> <?= htmlspecialchars($activity['title']) ?></div>
                <div><strong>Faculty In-Charge:</strong> <?= htmlspecialchars($activity['faculty_name']) ?></div>
                <div><strong>Event Date:</strong> <?= $activity['event_date'] ? date('F j, Y', strtotime($activity['event_date'])) : '—' ?></div>
                <div><strong>Venue:</strong> <?= htmlspecialchars($activity['venue'] ?? '—') ?></div>
                <div><strong>Status:</strong> <?= ucfirst($activity['status']) ?></div>
                <div><strong>Overall Performance Score:</strong> <?= $kpiResults['overall_performance'] !== null ? $kpiResults['overall_performance'] . '%' : '—' ?> (<?= $kpiResults['overall_status'] ?>)</div>
              </div>
            </div>
          </div>

          <!-- Section: Task & Performance Summary -->
          <div class="card report-section" id="taskPerformanceSummarySection">
            <div class="card-header"><h2>Task &amp; Performance Summary</h2></div>
            <div class="card-body">
              <div class="task-summary-strip" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:12px; margin-bottom:18px;">
                <div class="task-summary-card" style="background:var(--bg-base); border:1px solid var(--border); border-radius:8px; padding:12px 14px; text-align:center;">
                  <div style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.4px;">Total Tasks</div>
                  <div style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.4rem; font-weight:800; color:var(--text-main); margin-top:2px;"><?= $reportTasksTotal ?></div>
                </div>
                <div class="task-summary-card" style="background:var(--bg-base); border:1px solid var(--border); border-radius:8px; padding:12px 14px; text-align:center;">
                  <div style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.4px;">Completed</div>
                  <div style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.4rem; font-weight:800; color:#10b981; margin-top:2px;"><?= $reportTasksCompleted ?></div>
                </div>
                <div class="task-summary-card" style="background:var(--bg-base); border:1px solid var(--border); border-radius:8px; padding:12px 14px; text-align:center;">
                  <div style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.4px;">In Progress</div>
                  <div style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.4rem; font-weight:800; color:#0284c7; margin-top:2px;"><?= $reportTasksInProgress ?></div>
                </div>
                <div class="task-summary-card" style="background:var(--bg-base); border:1px solid var(--border); border-radius:8px; padding:12px 14px; text-align:center;">
                  <div style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.4px;">Delayed</div>
                  <div style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.4rem; font-weight:800; color:<?= $reportTasksDelayed > 0 ? '#ef4444' : 'var(--text-muted)' ?>; margin-top:2px;"><?= $reportTasksDelayed ?></div>
                </div>
                <div class="task-summary-card" style="background:var(--bg-base); border:1px solid var(--border); border-radius:8px; padding:12px 14px; text-align:center;">
                  <div style="font-size:0.7rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); letter-spacing:0.4px;">Overall Completion %</div>
                  <div style="font-family:'Plus Jakarta Sans',sans-serif; font-size:1.4rem; font-weight:800; color:<?= $reportTasksOverallPct >= 100 ? '#10b981' : ($reportTasksDelayed > 0 ? '#ef4444' : 'var(--accent)') ?>; margin-top:2px;"><?= $reportTasksOverallPct ?>%</div>
                </div>
              </div>

              <?php if (empty($reportTasks)): ?>
                <div class="text-muted text-sm" style="text-align:center; padding:20px; background:var(--bg-base); border:1px dashed var(--border); border-radius:8px;">
                  No task assignments or progress records recorded for this activity.
                </div>
              <?php else: ?>
                <div class="table-wrap" style="overflow-x:auto;">
                  <table class="kpi-table" id="taskPerformanceTable" style="margin-top:0; width:100%;">
                    <thead>
                      <tr>
                        <th>Activity</th>
                        <th>Spearheading Department</th>
                        <th>Committee</th>
                        <th>Member</th>
                        <th>Task</th>
                        <th>Due Date</th>
                        <th>Completion %</th>
                        <th>Status</th>
                        <th>Timing</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($reportTasks as $t): ?>
                      <tr>
                        <td><strong><?= htmlspecialchars($activity['title']) ?></strong></td>
                        <td>
                          <span class="badge badge-secondary" style="font-size:0.75rem;">
                            <?= htmlspecialchars($spearheadDept) ?>
                          </span>
                        </td>
                        <td><?= htmlspecialchars($t['committee']) ?></td>
                        <td><strong><?= htmlspecialchars($t['member']) ?></strong></td>
                        <td>
                          <div style="font-weight:600;"><?= htmlspecialchars($t['task_title']) ?></div>
                          <?php if (!empty($t['task_description'])): ?>
                            <small class="text-muted" style="display:block; font-size:0.72rem; margin-top:2px;">
                              <?= htmlspecialchars(mb_strimwidth($t['task_description'], 0, 75, '...')) ?>
                            </small>
                          <?php endif; ?>
                        </td>
                        <td>
                          <?= !empty($t['due_date']) ? date('M j, Y', strtotime($t['due_date'])) : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
                          <div style="display:inline-flex; align-items:center; gap:6px;">
                            <div style="width:48px; height:6px; background:var(--border); border-radius:3px; overflow:hidden; display:inline-block; vertical-align:middle;">
                              <div style="width:<?= min(100, max(0, $t['completion_pct'])) ?>%; height:100%; background:<?= $t['timing_color'] ?>; border-radius:3px;"></div>
                            </div>
                            <span style="font-weight:700; font-size:0.78rem; min-width:32px;"><?= $t['completion_pct'] ?>%</span>
                          </div>
                        </td>
                        <td><?= getTaskStatusBadge($t['status']) ?></td>
                        <td><?= $t['timing_badge'] ?></td>
                      </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Section 2: Approval & Endorsement History -->
          <div class="card report-section">
            <div class="card-header"><h2>Approval &amp; Endorsement History</h2></div>
            <div class="card-body" style="padding:0;">
              <?php if (empty($approvalLogs)): ?>
                <div class="text-muted text-sm" style="padding:16px 20px;">No approval history recorded for this activity.</div>
              <?php else: ?>
                <table class="kpi-table" style="margin-top:0;">
                  <thead>
                    <tr>
                      <th>Reviewer</th>
                      <th>Role</th>
                      <th>Action</th>
                      <th>Date &amp; Time</th>
                      <th>Remarks / Notes</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($approvalLogs as $log): 
                      $actionCls = 'badge-secondary';
                      if ($log['action'] === 'approved') $actionCls = 'badge-success';
                      elseif ($log['action'] === 'forwarded') $actionCls = 'badge-info';
                      elseif ($log['action'] === 'returned') $actionCls = 'badge-warning';
                      elseif ($log['action'] === 'rejected') $actionCls = 'badge-danger';
                    ?>
                    <tr>
                      <td><strong><?= htmlspecialchars($log['reviewer_name'] ?? 'System') ?></strong></td>
                      <td><?= htmlspecialchars(ucfirst($log['reviewer_role'] ?? '—')) ?></td>
                      <td><span class="badge <?= $actionCls ?>"><?= htmlspecialchars(ucfirst($log['action'])) ?></span></td>
                      <td class="text-sm"><?= $log['acted_at'] ? date('M j, Y g:i A', strtotime($log['acted_at'])) : '—' ?></td>
                      <td class="text-sm"><?= !empty($log['notes']) ? nl2br(htmlspecialchars($log['notes'])) : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>

          <!-- Section 3: Centralized Activity Documentation -->
          <div class="card report-section">
            <div class="card-header"><h2>Centralized Activity Documentation</h2></div>
            <div class="card-body" style="padding:0;">
              <?php if (empty($activityDocs)): ?>
                <div class="text-muted text-sm" style="padding:16px 20px;">No attached documents recorded for this activity.</div>
              <?php else: ?>
                <table class="kpi-table" style="margin-top:0;">
                  <thead>
                    <tr>
                      <th>Document Name</th>
                      <th>Type</th>
                      <th>Uploaded By</th>
                      <th>Upload Date</th>
                      <th class="no-print">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($activityDocs as $doc): ?>
                    <tr>
                      <td><strong><?= htmlspecialchars($doc['file_name']) ?></strong></td>
                      <td><span class="badge badge-secondary"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $doc['doc_type'] ?? 'document'))) ?></span></td>
                      <td class="text-sm"><?= htmlspecialchars($doc['uploader_name'] ?? '—') ?></td>
                      <td class="text-sm"><?= $doc['uploaded_at'] ? date('M j, Y g:i A', strtotime($doc['uploaded_at'])) : '—' ?></td>
                      <td class="no-print">
                        <a href="<?= BASE_URL ?>/api/document-download.php?id=<?= (int)$doc['id'] ?>" class="btn btn-outline btn-sm" target="_blank" style="padding:3px 10px; font-size:0.75rem;">
                          📥 Download
                        </a>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>

          <!-- Section 4: Deterministic KPI Calculations -->
          <div class="card report-section">
            <div class="card-header"><h2>Deterministic KPI Calculations</h2></div>
            <div class="card-body" style="padding:0;">
              <table class="kpi-table" style="margin-top:0;">
                <thead>
                  <tr>
                    <th>KPI / Indicator</th>
                    <th>Target</th>
                    <th>Actual</th>
                    <th>Achievement</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $kpis = $kpiResults['kpis'];
                  $allKpiRows = [];
                  if ($kpis['attendance']) $allKpiRows[] = $kpis['attendance'];
                  if ($kpis['satisfaction']) $allKpiRows[] = $kpis['satisfaction'];
                  if (!empty($kpis['criteria'])) {
                      foreach ($kpis['criteria'] as $cr) {
                          $allKpiRows[] = $cr;
                      }
                  }

                  foreach ($allKpiRows as $k):
                      $cls = getKpiStatusClass($k['status']);
                  ?>
                  <tr>
                    <td><strong><?= htmlspecialchars($k['indicator']) ?></strong></td>
                    <td><?= htmlspecialchars($k['target']) ?></td>
                    <td><?= htmlspecialchars($k['actual']) ?></td>
                    <td><strong><?= htmlspecialchars($k['achievement']) ?></strong></td>
                    <td><span class="badge <?= $cls ?>"><?= htmlspecialchars($k['status']) ?></span></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Section 5: Participant Feedback Comments -->
          <div class="card report-section">
            <div class="card-header"><h2>Participant Feedback Comments</h2></div>
            <div class="card-body" style="padding:0;">
              <?php if (empty($participantComments)): ?>
                <div class="text-muted text-sm" style="padding:16px 20px;">No participant written comments recorded for this activity.</div>
              <?php else: ?>
                <table class="kpi-table" style="margin-top:0;">
                  <thead>
                    <tr>
                      <th style="width:40px;">#</th>
                      <th style="width:160px;">Criteria / Question</th>
                      <th style="width:90px;">Rating</th>
                      <th>Comment</th>
                      <th style="width:120px;">Evaluator</th>
                      <th style="width:110px;">Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($participantComments as $idx => $pc): ?>
                    <tr>
                      <td class="text-muted"><?= $idx + 1 ?></td>
                      <td><strong><?= htmlspecialchars($pc['criteria'] ?? 'General') ?></strong></td>
                      <td>
                        <?php if ($pc['rating'] !== null): ?>
                          <span class="badge badge-info"><?= htmlspecialchars($pc['rating']) ?> / 4</span>
                        <?php else: ?>
                          <span class="text-muted">—</span>
                        <?php endif; ?>
                      </td>
                      <td style="line-height:1.5; font-style:italic;">
                        "<?= htmlspecialchars($pc['comments']) ?>"
                      </td>
                      <td class="text-sm text-muted"><?= htmlspecialchars($pc['evaluator_name'] ?? 'Anonymous') ?></td>
                      <td class="text-sm text-muted"><?= $pc['evaluated_at'] ? date('M j, Y', strtotime($pc['evaluated_at'])) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>

          <!-- Section 6: AI Qualitative Feedback Analysis -->
          <div class="card report-section">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
              <h2 style="margin:0;">AI Qualitative Feedback Analysis</h2>
              <?php if ($feedbackAnalysis): ?>
                <?php if (!empty($feedbackAnalysis['is_finalized'])): ?>
                  <span style="font-size:0.75rem; color:#15803d; font-weight:700; background:#ecfdf5; border:1px solid #a7f3d0; padding:3px 8px; border-radius:4px;">
                    🔒 Finalized by <?= htmlspecialchars($feedbackAnalysis['finalized_by']['name'] ?? 'Administrator') ?> (<?= htmlspecialchars($feedbackAnalysis['finalized_by']['role'] ?? 'Admin') ?>) on <?= htmlspecialchars(date('M j, Y', strtotime($feedbackAnalysis['finalized_at'] ?? 'now'))) ?>
                  </span>
                <?php elseif (!empty($feedbackAnalysis['is_reviewed'])): ?>
                  <span style="font-size:0.75rem; color:#d97706; font-weight:700; background:#fffbeb; border:1px solid #fef3c7; padding:3px 8px; border-radius:4px;">
                    ✏️ Administrator Reviewed Draft
                  </span>
                <?php else: ?>
                  <span style="font-size:0.75rem; color:#64748b; font-weight:600; background:var(--bg-base); border:1px solid var(--border); padding:3px 8px; border-radius:4px;">
                    🤖 Unreviewed AI Draft
                  </span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <?php if ($feedbackAnalysis): ?>
                <?php if (!empty($feedbackAnalysis['overall_summary'])): ?>
                <div style="margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid var(--border);">
                  <strong style="display:block; margin-bottom:4px; font-size:0.9rem;">Thematic Summary:</strong>
                  <p style="color:var(--text-main); margin:0; line-height:1.5; font-size:0.88rem; font-style:italic;">
                    "<?= htmlspecialchars($feedbackAnalysis['overall_summary']) ?>"
                  </p>
                </div>
                <?php endif; ?>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
                  <div>
                    <strong style="display:block; margin-bottom:6px; font-size:0.85rem; color:#16a34a;">🟢 Positive Themes</strong>
                    <?php if (!empty($feedbackAnalysis['positive_themes'])): ?>
                      <?php foreach ($feedbackAnalysis['positive_themes'] as $i => $pt): ?>
                        <div style="margin-bottom:8px; font-size:0.82rem;">
                          <strong><?= $i+1 ?>. <?= htmlspecialchars($pt['theme']) ?></strong>
                          <?php if (isset($pt['frequency'])): ?>
                            <span class="text-muted">(<?= (int)$pt['frequency'] ?> responses)</span>
                          <?php endif; ?><br>
                          <span style="color:var(--text-muted);"><?= htmlspecialchars($pt['summary']) ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="text-muted text-sm">No positive themes recorded.</div>
                    <?php endif; ?>
                  </div>

                  <div>
                    <strong style="display:block; margin-bottom:6px; font-size:0.85rem; color:#d97706;">Area for Improvement</strong>
                    <?php if (!empty($feedbackAnalysis['improvement_themes'])): ?>
                      <?php foreach ($feedbackAnalysis['improvement_themes'] as $i => $it): ?>
                        <div style="margin-bottom:8px; font-size:0.82rem;">
                          <strong><?= $i+1 ?>. <?= htmlspecialchars($it['theme']) ?></strong>
                          <?php if (isset($it['frequency'])): ?>
                            <span class="text-muted">(<?= (int)$it['frequency'] ?> responses)</span>
                          <?php endif; ?><br>
                          <span style="color:var(--text-muted);"><?= htmlspecialchars($it['summary']) ?></span>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="text-muted text-sm">No improvement areas recorded.</div>
                    <?php endif; ?>
                  </div>
                </div>

                <?php if (!empty($feedbackAnalysis['key_findings'])): ?>
                <div style="margin-bottom:14px;">
                  <strong style="display:block; margin-bottom:4px; font-size:0.85rem;">🔍 Key Findings</strong>
                  <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                    <?php foreach ($feedbackAnalysis['key_findings'] as $kf): ?>
                      <li><?= htmlspecialchars($kf) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <?php endif; ?>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                  <div>
                    <strong style="display:block; margin-bottom:4px; font-size:0.85rem;">💡 Common Suggestions</strong>
                    <?php if (!empty($feedbackAnalysis['common_suggestions'])): ?>
                      <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                        <?php foreach ($feedbackAnalysis['common_suggestions'] as $cs): ?>
                          <li><?= htmlspecialchars($cs) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php else: ?>
                      <div class="text-muted text-sm">No common suggestions recorded.</div>
                    <?php endif; ?>
                  </div>

                  <div>
                    <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:var(--accent);">🎯 Actionable Recommendations</strong>
                    <?php if (!empty($feedbackAnalysis['recommendations'])): ?>
                      <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                        <?php foreach ($feedbackAnalysis['recommendations'] as $ar): ?>
                          <li><?= htmlspecialchars($ar) ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php else: ?>
                      <div class="text-muted text-sm">No recommendations recorded.</div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php else: ?>
                <div class="text-muted text-sm" style="text-align:center; padding:10px;">
                  No qualitative participant feedback analysis has been generated for this activity yet.
                </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Section 7: AI-Generated Insight — Advisory Only (Quantitative Interpretation) -->
          <div class="card report-section">
            <div class="card-header"><h2>AI Numerical KPI Interpretation — Advisory Only</h2></div>
            <div class="card-body">
              <?php if ($aiInsights): ?>
                <div style="margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid var(--border);">
                  <strong style="display:block; margin-bottom:4px; font-size:0.9rem;">Overall Analysis Summary:</strong>
                  <p style="color:var(--text-muted); margin:0; line-height:1.5; font-size:0.88rem;"><?= htmlspecialchars($aiInsights['overall_insight']) ?></p>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
                  <div>
                    <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:#16A34A;">✓ Strengths</strong>
                    <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                      <?php foreach ($aiInsights['strengths'] as $s): ?>
                        <li><?= htmlspecialchars($s) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                  <div>
                    <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:#D97706;">⚠ Areas of Attention</strong>
                    <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                      <?php foreach ($aiInsights['areas_of_attention'] as $a): ?>
                        <li><?= htmlspecialchars($a) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
                <div style="margin-bottom:14px;">
                  <strong style="display:block; margin-bottom:4px; font-size:0.85rem;">• Key Findings</strong>
                  <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                    <?php foreach ($aiInsights['key_findings'] as $f): ?>
                      <li><?= htmlspecialchars($f) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
                <div>
                  <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:var(--accent);">💡 Recommendations</strong>
                  <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
                    <?php foreach ($aiInsights['recommendations'] as $r): ?>
                      <li><?= htmlspecialchars($r) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <div class="text-muted text-sm" style="text-align:center; padding:10px;">
                  No AI interpretation has been generated for this activity yet. The report contains numerical KPI values only.
                </div>
              <?php endif; ?>
            </div>
          </div>


          <div style="font-size:0.75rem; color:var(--text-muted); text-align:center; margin-top:30px; font-style:italic;">
            AI-generated insights are advisory only and should be reviewed by authorized administrators before making decisions.
          </div>
        </div>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// Mode B: Aggregate Institutional Report (Dean, Admin1, and Admin2 only)
if ($user['role'] === 'faculty') {
    http_response_code(403);
    die("Access denied.");
}

// Filters
$filterYear   = (int)($_GET['year']   ?? date('Y'));
$filterStatus = sanitize($_GET['status'] ?? '');
$filterSource = sanitize($_GET['source'] ?? '');

$where = "1=1";
$params = [];
if ($filterYear)   { $where .= " AND YEAR(a.event_date)=?";  $params[] = $filterYear; }
if ($filterStatus) { $where .= " AND a.status=?";             $params[] = $filterStatus; }
if ($filterSource) { $where .= " AND a.source=?";             $params[] = $filterSource; }

$acts = $db->prepare("
    SELECT a.*, u.name as faculty_name,
           pe.actual_attendance, pe.target_attendance, pe.satisfaction_score,
           AVG(k.rating) as avg_kpi
    FROM activities a
    JOIN users u ON a.faculty_id=u.id
    LEFT JOIN post_event pe ON a.id=pe.activity_id
    LEFT JOIN kpi_evaluations k ON a.id=k.activity_id
    WHERE $where
    GROUP BY a.id
    ORDER BY a.event_date DESC
");
$acts->execute($params);
$activities = $acts->fetchAll();

// Aggregate totals
$total = count($activities);
$totalAtt = array_sum(array_column($activities,'actual_attendance'));
$totalTarget = array_sum(array_column($activities,'target_attendance'));
$avgKpi = $total > 0 ? array_sum(array_column($activities,'avg_kpi')) / max(count(array_filter($activities,fn($a)=>$a['avg_kpi'])),1) : 0;
$avgSat = $total > 0 ? array_sum(array_column($activities,'satisfaction_score')) / max(count(array_filter($activities,fn($a)=>$a['satisfaction_score'])),1) : 0;

$years = range(date('Y'), date('Y')-4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script>
    (function() {
      try {
        var savedTheme = localStorage.getItem('sti-theme');
        if (savedTheme === 'dark') {
          document.documentElement.dataset.theme = 'dark';
        } else {
          document.documentElement.dataset.theme = 'light';
        }
      } catch (e) {}
    })();
  </script>
<title>Generate Report – STI Activity System</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.7">
<style>
.filter-bar{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:18px 22px;margin-bottom:20px;display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap;}
.filter-bar .form-group{margin-bottom:0;min-width:160px;}
.summary-strip{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;}
.summary-item{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;text-align:center;}
.summary-item .n{font-family:'Plus Jakarta Sans',sans-serif !important;font-size:1.5rem;font-weight:800;color:var(--accent, var(--sti-blue));line-height:1.1;}
.summary-item .l{font-size:.7rem;text-transform:uppercase;letter-spacing:.3px;color:var(--text-muted);font-weight:700;}
@media print{
  .sidebar,.topbar,.filter-bar,.print-btn{display:none!important;}
  .main-wrap{margin-left:0!important;}
  .content{padding:20px!important;}
  body{font-size:12px;}
}
</style>
</head>
<?php
$themeMap = ['faculty' => 'theme-faculty', 'admin1' => 'theme-arjay', 'admin2' => 'theme-ian', 'dean' => 'theme-dean'];
$bodyTheme = $themeMap[$user['role']] ?? 'theme-dean';
?>
<body class="<?= $bodyTheme ?>">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">
  <header class="topbar">
    <div class="page-title">Generate Report</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <button class="btn btn-primary print-btn" onclick="window.print()">🖨 Print / Export PDF</button>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>
  <div class="content">

    <!-- Filters -->
    <form method="GET" class="filter-bar">
      <div class="form-group">
        <label class="form-label">Year</label>
        <select name="year" class="form-control">
          <?php foreach ($years as $y): ?>
          <option value="<?= $y ?>" <?= $filterYear===$y?'selected':'' ?>><?= $y ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="">All Statuses</option>
          <?php foreach(['approved','completed','rejected','returned_for_revision'] as $s): ?>
          <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Source</label>
        <select name="source" class="form-control">
          <option value="">All Sources</option>
          <option value="student_org" <?= $filterSource==='student_org'?'selected':'' ?>>Student Organization</option>
          <option value="faculty" <?= $filterSource==='faculty'?'selected':'' ?>>Faculty</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Apply Filters</button>
      <a href="generate-report.php" class="btn btn-outline">Reset</a>
    </form>

    <!-- Report Header (print-visible) -->
    <div style="margin-bottom:20px;">
      <div style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.3rem;font-weight:800;color:var(--text-main);">STI College Marikina — Activity Report</div>
      <div class="text-sm text-muted">Generated: <?= date('F j, Y g:i A') ?> · Year: <?= $filterYear ?><?= $filterStatus ? " · Status: ".ucfirst(str_replace('_',' ',$filterStatus)) : '' ?><?= $filterSource ? " · Source: ".ucfirst(str_replace('_',' ',$filterSource)) : '' ?></div>
    </div>

    <!-- Summary -->
    <div class="summary-strip">
      <div class="summary-item"><div class="n"><?= $total ?></div><div class="l">Total Activities</div></div>
      <div class="summary-item"><div class="n"><?= $totalTarget > 0 ? round(($totalAtt/$totalTarget)*100).'%' : '—' ?></div><div class="l">Attendance Rate</div></div>
      <div class="summary-item"><div class="n"><?= $avgKpi > 0 ? number_format($avgKpi,2) : '—' ?></div><div class="l">Avg KPI / 4.0</div></div>
      <div class="summary-item"><div class="n"><?= $avgSat > 0 ? number_format($avgSat,1) : '—' ?></div><div class="l">Avg Satisfaction</div></div>
      <div class="summary-item"><div class="n"><?= count(array_filter($activities,fn($a)=>$a['status']==='completed')) ?></div><div class="l">Completed</div></div>
    </div>

    <!-- Detail Table -->
    <div class="card">
      <div class="card-header"><h2>Activity Details — <?= $filterYear ?></h2></div>
      <div class="card-body" style="padding:0;">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Activity Title</th>
                <th>Faculty</th>
                <th>Date</th>
                <th>Source</th>
                <th>Status</th>
                <th>Attendance</th>
                <th>KPI Avg</th>
                <th>Satisfaction</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($activities)): ?>
            <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text-muted);">No activities match the selected filters.</td></tr>
            <?php else: foreach ($activities as $i=>$a):
              $attPct = $a['target_attendance'] > 0 ? round(($a['actual_attendance']/$a['target_attendance'])*100) : null;
            ?>
            <tr>
              <td class="text-muted"><?= $i+1 ?></td>
              <td><strong><?= htmlspecialchars($a['title']) ?></strong><br>
                <?php if ($a['venue']): ?><span class="text-sm text-muted"><?= htmlspecialchars($a['venue']) ?></span><?php endif; ?></td>
              <td><?= htmlspecialchars($a['faculty_name']) ?></td>
              <td><?= $a['event_date'] ? date('M j, Y',strtotime($a['event_date'])) : '—' ?></td>
              <td><span class="badge badge-secondary"><?= ucfirst(str_replace('_',' ',$a['source'])) ?></span></td>
              <td><?= getStatusBadge($a['status']) ?></td>
              <td>
                <?php if ($a['actual_attendance'] !== null): ?>
                  <?= $a['actual_attendance'] ?>/<?= $a['target_attendance'] ?>
                  <?php if ($attPct !== null): ?>
                  <br><small style="color:<?= $attPct>=90?'#16A34A':($attPct>=70?'#D97706':'#DC2626') ?>;font-weight:700;"><?= $attPct ?>%</small>
                  <?php endif; ?>
                <?php else: ?>—<?php endif; ?>
              </td>
              <td><?= $a['avg_kpi'] ? '<strong>'.number_format($a['avg_kpi'],2).'</strong>' : '—' ?></td>
              <td><?= $a['satisfaction_score'] ? number_format($a['satisfaction_score'],1).'/5' : '—' ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Recommendations summary -->
    <?php
    $withRecs = array_filter($activities, fn($a) => !empty($a['avg_kpi']) && $a['avg_kpi'] < 2.5);
    if (!empty($withRecs)):
    ?>
    <div class="card" style="margin-top:20px;">
      <div class="card-header"><h2>⚠️ Events Needing Attention (KPI &lt; 2.5)</h2></div>
      <div class="card-body" style="padding:0;">
        <table>
          <thead><tr><th>Activity</th><th>KPI</th><th>Attendance Rate</th><th>Recommendations</th></tr></thead>
          <tbody>
          <?php foreach ($withRecs as $a):
            $attPct2 = $a['target_attendance'] > 0 ? round(($a['actual_attendance']/$a['target_attendance'])*100) : 0;
            $rec = $db->prepare("SELECT recommendations FROM post_event WHERE activity_id=?");
            $rec->execute([$a['id']]);
            $recRow = $rec->fetch();
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($a['title']) ?></strong><br><span class="text-sm text-muted"><?= $a['event_date'] ? date('M j, Y',strtotime($a['event_date'])) : '—' ?></span></td>
            <td><span class="badge badge-danger"><?= number_format($a['avg_kpi'],2) ?></span></td>
            <td><?= $attPct2 ?>%</td>
            <td class="text-sm"><?= htmlspecialchars($recRow['recommendations'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php
    $yearInsights = null;
    try {
        $yearStmt = $db->prepare("SELECT analytics_json, updated_at FROM institutional_kpi_analytics WHERE year = ? AND period = 'all'");
        $yearStmt->execute([$filterYear]);
        $yearRow = $yearStmt->fetch();
        if ($yearRow) {
            $yearInsights = json_decode($yearRow['analytics_json'], true);
        }
    } catch (Exception $e) {}
    if ($yearInsights):
    ?>
    <div class="card report-section" style="margin-top:20px; page-break-inside:avoid;">
      <div class="card-header"><h2>🤖 AI Institutional Insights — Year: <?= $filterYear ?> (Advisory Only)</h2></div>
      <div class="card-body">
        <div style="margin-bottom:14px; padding-bottom:14px; border-bottom:1px solid var(--border);">
          <strong style="display:block; margin-bottom:4px; font-size:0.9rem;">Overall Analysis Summary:</strong>
          <p style="color:var(--text-muted); margin:0; line-height:1.5; font-size:0.88rem;"><?= htmlspecialchars($yearInsights['overall_insight']) ?></p>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:14px;">
          <div>
            <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:#16A34A;">✓ Strengths</strong>
            <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
              <?php foreach ($yearInsights['strengths'] as $s): ?>
                <li><?= htmlspecialchars($s) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div>
            <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:#D97706;">⚠ Areas of Attention</strong>
            <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
              <?php foreach ($yearInsights['areas_of_attention'] as $a): ?>
                <li><?= htmlspecialchars($a) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <div>
          <strong style="display:block; margin-bottom:4px; font-size:0.85rem; color:var(--accent);">💡 Recommendations</strong>
          <ul style="font-size:0.82rem; color:var(--text-muted); padding-left:16px; margin:0; line-height:1.4;">
            <?php foreach ($yearInsights['recommendations'] as $r): ?>
              <li><?= htmlspecialchars($r) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <div style="font-size:0.75rem; color:var(--text-muted); text-align:center; padding-bottom:12px; font-style:italic;">
        AI-generated insights are advisory only and should be reviewed by authorized administrators before making decisions.
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
