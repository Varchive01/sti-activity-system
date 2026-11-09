<?php
/**
 * includes/activity-print-document.php
 *
 * Global Institutional Print & PDF Document Template for STI-ARMES
 * Renders a formal, standardized institutional proposal document layout matching "Export PDF"
 * when printed or exported across all user roles (Dean, Admin 1, Admin 2, Faculty, Program Head).
 */

if (!isset($db)) {
    $db = getDB();
}

$actId = (int)($activity['id'] ?? ($id ?? 0));

// Ensure all proposal datasets are safely loaded if not already provided
if (!isset($materials) && $actId > 0) {
    try {
        $mStmt = $db->prepare("SELECT * FROM materials WHERE activity_id=?");
        $mStmt->execute([$actId]);
        $materials = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $materials = []; }
}

if (!isset($schedules) && $actId > 0) {
    try {
        $scStmt = $db->prepare("SELECT * FROM schedules WHERE activity_id=? ORDER BY sched_date ASC, start_time ASC");
        $scStmt->execute([$actId]);
        $schedules = $scStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $schedules = []; }
}

if (!isset($program) && $actId > 0) {
    try {
        $pStmt = $db->prepare("SELECT * FROM program_sequence WHERE activity_id=? ORDER BY sort_order ASC, id ASC");
        $pStmt->execute([$actId]);
        $program = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $program = []; }
}

if (!isset($manpower) && $actId > 0) {
    try {
        $mpStmt = $db->prepare("SELECT * FROM manpower WHERE activity_id=?");
        $mpStmt->execute([$actId]);
        $manpower = $mpStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $manpower = []; }
}

if (!isset($ftasks) && $actId > 0) {
    try {
        $ftStmt = $db->prepare("SELECT ft.*, u.name as assigned_user_name FROM faculty_tasks ft LEFT JOIN users u ON ft.assigned_user_id = u.id WHERE ft.activity_id=? ORDER BY ft.id ASC");
        $ftStmt->execute([$actId]);
        $ftasks = $ftStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $ftasks = []; }
}

if (!isset($guidelines) && $actId > 0) {
    try {
        $glStmt = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?");
        $glStmt->execute([$actId]);
        $guidelines = $glStmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $guidelines = []; }
}

if (!isset($kpiData) && !isset($kpis) && $actId > 0) {
    try {
        $kpiStmt = $db->prepare("SELECT criteria, target_metric, evaluation_method, AVG(rating) as avg FROM kpi_evaluations WHERE activity_id=? GROUP BY criteria");
        $kpiStmt->execute([$actId]);
        $kpiData = $kpiStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $kpiData = []; }
}
$activeKpis = $kpiData ?? ($kpis ?? []);

if (!isset($history) && $actId > 0) {
    try {
        $lStmt = $db->prepare("
            SELECT al.*, u.name as reviewer_name, u.role as reviewer_role 
            FROM approval_logs al 
            JOIN users u ON al.reviewer_id = u.id 
            WHERE al.activity_id = ? 
            ORDER BY al.acted_at ASC, al.id ASC
        ");
        $lStmt->execute([$actId]);
        $history = $lStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $history = []; }
}

// Format basic data points
$printTitle = trim($activity['title'] ?? 'Activity Proposal');
$printStatusRaw = $activity['status'] ?? 'draft';
$printStatus = ($printStatusRaw === 'submitted') ? 'Under Review' : ucfirst(str_replace('_', ' ', $printStatusRaw));
$printProponent = $activity['faculty_name'] ?? ($activity['fn'] ?? 'Faculty Proponent');
$printDept = $activity['department'] ?? ($activity['faculty_dept'] ?? 'Academic Department');
$printSource = ($activity['source'] ?? '') === 'student_org' ? 'Student Organization' : 'Faculty Initiated';
$printEventDate = !empty($activity['event_date']) ? date('F j, Y', strtotime($activity['event_date'])) : 'TBA';
$printStartTime = !empty($activity['start_time']) ? date('h:i A', strtotime($activity['start_time'])) : '';
$printEndTime   = !empty($activity['end_time'])   ? date('h:i A', strtotime($activity['end_time']))   : '';
$printTimeStr   = ($printStartTime && $printEndTime) ? "{$printStartTime} – {$printEndTime}" : ($printStartTime ?: 'TBA');
$printVenue = $activity['venue'] ?? 'TBA';
$printAddress = $activity['venue_address'] ?? ($activity['address'] ?? 'STI College Campus');
$printParticipants = number_format((int)($activity['target_participants'] ?? 0));
$printClearance = !empty($activity['clearance_required']) ? 'Required' : (!empty($activity['clearance']) ? $activity['clearance'] : 'Not Required');
$printTheme = $activity['theme'] ?? '—';
$printNotes = trim($activity['revision_notes'] ?? '');
$isRejected = (strtolower($printStatusRaw) === 'rejected');

// Total budget calculation
$totalBudget = 0.0;
$totalQty = 0;
if (!empty($materials)) {
    foreach ($materials as $m) {
        $totalBudget += (float)($m['est_cost'] ?? 0);
        $totalQty += (int)($m['quantity'] ?? 1);
    }
}

// Build client-side PDF export dataset
$exportProposalData = [
    'id' => $actId,
    'title' => $printTitle,
    'status' => $printStatus,
    'proponent' => $printProponent,
    'department' => $printDept,
    'source' => $printSource,
    'event_date' => $printEventDate,
    'time' => $printTimeStr,
    'venue' => $printVenue,
    'theme' => $printTheme,
    'target_participants' => $printParticipants . ' participants',
    'clearance' => $printClearance,
    'revision_notes' => $printNotes,
    'general_objectives' => $activity['general_objectives'] ?? '',
    'specific_objectives' => $activity['specific_objectives'] ?? '',
    'involved_subjects' => $activity['involved_subjects'] ?? '',
    'rationale' => $activity['rationale'] ?? '',
    'materials' => array_map(function ($m) {
        return [
            $m['item_name'] ?? '',
            $m['description'] ?? '—',
            (string)($m['quantity'] ?? 1),
            $m['provider'] ?? '—',
            'PHP ' . number_format((float)($m['est_cost'] ?? 0), 2)
        ];
    }, $materials ?? []),
    'manpower' => array_map(function ($m) {
        return [
            $m['role'] ?? ($m['role_name'] ?? 'Committee'),
            $m['assigned_person'] ?? '—',
            ucfirst($m['type'] ?? 'Member')
        ];
    }, $manpower ?? []),
    'schedules' => array_map(function ($s) {
        return [
            !empty($s['sched_date']) ? date('M j, Y', strtotime($s['sched_date'])) : '—',
            $s['event_name'] ?? ($s['activity_description'] ?? '—'),
            $s['venue'] ?? '—',
            $s['organizer'] ?? '—'
        ];
    }, $schedules ?? []),
    'program' => array_map(function ($p) {
        return [
            $p['time_slot'] ?? '—',
            trim(($p['segment'] ?? '') . ' ' . ($p['description'] ?? '')),
            $p['person_ic'] ?? ($p['person_in_charge'] ?? 'Unassigned')
        ];
    }, $program ?? []),
    'tasks' => array_map(function ($f) {
        return [
            $f['task_title'] ?: ($f['assigned_task'] ?: 'Task'),
            $f['committee'] ?: '—',
            $f['assigned_user_name'] ?: '—',
            $f['role_in_event'] ?: '—',
            !empty($f['due_date']) ? date('M j, Y', strtotime($f['due_date'])) : '—',
            $f['status'] ?? 'Not Started'
        ];
    }, $ftasks ?? []),
    'kpis' => array_map(function ($k) {
        return [
            $k['criteria'] ?? 'Criteria',
            $k['target_value'] ?? ($k['target_metric'] ?? ($k['avg'] ? number_format($k['avg'], 2) : '—')),
            $k['evaluation_method'] ?? '—'
        ];
    }, $activeKpis ?? []),
    'history' => array_map(function ($h) {
        return [
            $h['reviewer_name'] ?? ($h['name'] ?? 'Reviewer'),
            ucfirst(str_replace('_', ' ', $h['reviewer_role'] ?? ($h['reviewer_role'] ?? 'Approver'))),
            ucfirst(str_replace('_', ' ', $h['action'] ?? '')),
            !empty($h['acted_at']) ? date('M j, Y g:i A', strtotime($h['acted_at'])) : '—',
            $h['notes'] ?? '—'
        ];
    }, $history ?? [])
];
?>

<!-- ======================================================================= -->
<!-- GLOBAL FORMAL PRINT STYLESHEET (Layout of Export PDF)                   -->
<!-- ======================================================================= -->
<style id="global-proposal-print-css">
  /* Hidden on interactive screen */
  #proposal-print-document {
    display: none;
  }

  @media print {
    /* 1. Page Configuration */
    @page {
      size: A4 portrait;
      margin: 12mm 14mm 14mm 14mm;
    }

    /* 2. Global Document Reset */
    html, body {
      background: #ffffff !important;
      color: #0f172a !important;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif !important;
      font-size: 8.5pt !important;
      line-height: 1.4 !important;
      margin: 0 !important;
      padding: 0 !important;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }

    /* 3. Hide Screen Web Chrome Completely */
    .sidebar,
    .topbar,
    .tabs-nav,
    .no-print,
    .btn,
    button,
    .modal,
    .alert,
    #theme-toggle-btn,
    .content,
    .main-wrap > header {
      display: none !important;
    }

    .main-wrap {
      margin-left: 0 !important;
      width: 100% !important;
      padding: 0 !important;
    }

    /* 4. Display Formal Print Document Container */
    #proposal-print-document {
      display: block !important;
      width: 100% !important;
      margin: 0 !important;
      padding: 0 !important;
      color: #0f172a !important;
      background: #ffffff !important;
    }

    /* Header Banner */
    .print-header-stripe {
      height: 3px;
      background: #003876 !important;
      margin-bottom: 6px;
    }

    .print-brand-tagline {
      font-size: 8pt;
      font-weight: 800;
      letter-spacing: 0.06em;
      color: #003876 !important;
      text-transform: uppercase;
      margin-bottom: 4px;
    }

    .print-title-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 6px;
    }

    .print-doc-title {
      font-size: 15pt;
      font-weight: 800;
      color: #0f172a !important;
      margin: 0;
      line-height: 1.25;
      flex: 1;
    }

    .print-status-badge {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 8pt;
      font-weight: 700;
      border: 1px solid #cbd5e1;
      white-space: nowrap;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .print-status-badge.status-approved {
      background: #dcfce7 !important;
      color: #15803d !important;
      border-color: #86efac !important;
    }
    .print-status-badge.status-completed {
      background: #e2e8f0 !important;
      color: #0f172a !important;
      border-color: #94a3b8 !important;
    }
    .print-status-badge.status-for-revision {
      background: #fef3c7 !important;
      color: #b45309 !important;
      border-color: #fcd34d !important;
    }
    .print-status-badge.status-rejected {
      background: #fee2e2 !important;
      color: #b91c1c !important;
      border-color: #fca5a5 !important;
    }
    .print-status-badge.status-under-review {
      background: #e0f2fe !important;
      color: #0369a1 !important;
      border-color: #7dd3fc !important;
    }

    .print-header-divider {
      height: 1px;
      background: #cbd5e1 !important;
      margin: 6px 0 12px 0;
    }

    /* Section Headings */
    .print-section-heading {
      background: #f1f5f9 !important;
      border-left: 4px solid #003876 !important;
      padding: 4.5px 8px;
      border-radius: 2px;
      font-size: 9pt;
      font-weight: 800;
      color: #003876 !important;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin: 12px 0 6px 0;
      page-break-after: avoid;
    }

    /* Overview Grid Table */
    .print-overview-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 8px;
      font-size: 8.5pt;
    }

    .print-overview-table td {
      padding: 4.5px 8px;
      border: 1px solid #e2e8f0;
      vertical-align: top;
    }

    .print-ov-label {
      font-weight: 700;
      color: #475569 !important;
      width: 18%;
      background: #f8fafc !important;
    }

    .print-ov-val {
      color: #0f172a !important;
      width: 32%;
    }

    /* Data Tables */
    .print-data-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 10px;
      font-size: 8pt;
      page-break-inside: auto;
    }

    .print-data-table tr {
      page-break-inside: avoid;
      page-break-after: auto;
    }

    .print-data-table th {
      background: #003876 !important;
      color: #ffffff !important;
      padding: 4.5px 6.5px;
      font-weight: 700;
      text-align: left;
      border: 1px solid #002855;
    }

    .print-data-table td {
      padding: 4px 6.5px;
      border: 1px solid #e2e8f0;
      color: #1e293b !important;
      vertical-align: top;
    }

    .print-data-table tbody tr:nth-child(even) {
      background: #f8fafc !important;
    }

    .print-total-row {
      background: #f1f5f9 !important;
      font-weight: 700;
    }

    .print-total-row td {
      border-top: 2px solid #003876 !important;
      color: #003876 !important;
    }

    /* Narrative Text Blocks */
    .print-text-block {
      margin-bottom: 6px;
      page-break-inside: avoid;
    }

    .print-block-label {
      font-weight: 700;
      font-size: 8pt;
      color: #475569 !important;
      text-transform: uppercase;
      letter-spacing: 0.02em;
      margin-bottom: 1.5px;
    }

    .print-block-content {
      font-size: 8.5pt;
      color: #1e293b !important;
      line-height: 1.45;
      white-space: pre-line;
      margin: 0;
    }

    /* Feedback Box */
    .print-notes-box {
      border: 1px solid #f59e0b;
      background: #fef3c7 !important;
      color: #b45309 !important;
      padding: 6px 10px;
      border-radius: 4px;
      margin-bottom: 10px;
      font-size: 8pt;
      page-break-inside: avoid;
    }

    .print-notes-box.rejected {
      border-color: #ef4444;
      background: #fef2f2 !important;
      color: #b91c1c !important;
    }

    /* Signatory Endorsements */
    .print-signatories-wrap {
      margin-top: 18px;
      page-break-inside: avoid;
    }

    .print-signatories-grid {
      display: flex;
      justify-content: space-between;
      gap: 20px;
      margin-top: 24px;
    }

    .print-signatory-cell {
      flex: 1;
      text-align: center;
      padding-top: 8px;
      border-top: 1px solid #0f172a;
    }

    .print-signatory-name {
      font-weight: 700;
      font-size: 8.5pt;
      color: #0f172a !important;
    }

    .print-signatory-role {
      font-size: 7.5pt;
      color: #64748b !important;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
  }
</style>

<!-- ======================================================================= -->
<!-- FORMAL INSTITUTIONAL PROPOSAL PRINT CONTAINER                           -->
<!-- ======================================================================= -->
<div id="proposal-print-document" class="proposal-print-document">

  <!-- Header Banner -->
  <div class="print-header-stripe"></div>
  <div class="print-brand-tagline">STI ACTIVITY &amp; RESOURCE MANAGEMENT SYSTEM (STI-ARMES)</div>
  <div class="print-title-row">
    <h1 class="print-doc-title"><?= htmlspecialchars($printTitle) ?></h1>
    <?php
      $badgeCls = match(strtolower($printStatusRaw)) {
        'approved' => 'status-approved',
        'completed' => 'status-completed',
        'returned_for_revision' => 'status-for-revision',
        'rejected' => 'status-rejected',
        default => 'status-under-review'
      };
    ?>
    <span class="print-status-badge <?= $badgeCls ?>"><?= htmlspecialchars($printStatus) ?></span>
  </div>
  <div style="font-size:8pt;color:#64748b;margin-bottom:4px;">
    Submitted by: <strong><?= htmlspecialchars($printProponent) ?></strong> (<?= htmlspecialchars($printDept) ?>) &bull; <?= htmlspecialchars($printSource) ?> &bull; Activity ID: #<?= $actId ?>
  </div>
  <div class="print-header-divider"></div>

  <!-- Rejection / Revision Notes if present -->
  <?php if (!empty($printNotes)): ?>
    <div class="print-notes-box <?= $isRejected ? 'rejected' : '' ?>">
      <strong><?= $isRejected ? 'Disapproval / Rejection Reason:' : 'Feedback & Revision Notes:' ?></strong>
      <div><?= htmlspecialchars($printNotes) ?></div>
    </div>
  <?php endif; ?>

  <!-- 1. Activity Overview -->
  <div class="print-section-heading">1. Activity General Information</div>
  <table class="print-overview-table">
    <tr>
      <td class="print-ov-label">Event Date:</td>
      <td class="print-ov-val"><?= htmlspecialchars($printEventDate) ?></td>
      <td class="print-ov-label">Lead Proponent:</td>
      <td class="print-ov-val"><?= htmlspecialchars($printProponent) ?></td>
    </tr>
    <tr>
      <td class="print-ov-label">Time Schedule:</td>
      <td class="print-ov-val"><?= htmlspecialchars($printTimeStr) ?></td>
      <td class="print-ov-label">Department / Unit:</td>
      <td class="print-ov-val"><?= htmlspecialchars($printDept) ?></td>
    </tr>
    <tr>
      <td class="print-ov-label">Venue / Location:</td>
      <td class="print-ov-val"><?= htmlspecialchars($printVenue) ?></td>
      <td class="print-ov-label">Proposal Source:</td>
      <td class="print-ov-val"><?= htmlspecialchars($printSource) ?></td>
    </tr>
    <tr>
      <td class="print-ov-label">Target Participants:</td>
      <td class="print-ov-val"><?= htmlspecialchars($printParticipants) ?> attendees</td>
      <td class="print-ov-label">Clearance Status:</td>
      <td class="print-ov-val"><?= htmlspecialchars($printClearance) ?></td>
    </tr>
    <tr>
      <td class="print-ov-label">Venue Address:</td>
      <td class="print-ov-val" colspan="3"><?= htmlspecialchars($printAddress) ?></td>
    </tr>
    <tr>
      <td class="print-ov-label">Institutional Theme:</td>
      <td class="print-ov-val" colspan="3"><?= htmlspecialchars($printTheme) ?></td>
    </tr>
  </table>

  <!-- 2. Objectives & Academic Alignment -->
  <?php if (!empty($activity['general_objectives']) || !empty($activity['specific_objectives']) || !empty($activity['rationale'])): ?>
    <div class="print-section-heading">2. Objectives &amp; Academic Alignment</div>
    <?php if (!empty($activity['general_objectives'])): ?>
      <div class="print-text-block">
        <div class="print-block-label">General Objectives:</div>
        <div class="print-block-content"><?= htmlspecialchars($activity['general_objectives']) ?></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($activity['specific_objectives'])): ?>
      <div class="print-text-block">
        <div class="print-block-label">Specific Objectives:</div>
        <div class="print-block-content"><?= htmlspecialchars($activity['specific_objectives']) ?></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($activity['involved_subjects'])): ?>
      <div class="print-text-block">
        <div class="print-block-label">Involved Academic Subjects:</div>
        <div class="print-block-content"><?= htmlspecialchars($activity['involved_subjects']) ?></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($activity['rationale'])): ?>
      <div class="print-text-block">
        <div class="print-block-label">Rationale &amp; Strategic Justification:</div>
        <div class="print-block-content"><?= htmlspecialchars($activity['rationale']) ?></div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- 3. Event Guidelines (if present) -->
  <?php if (!empty($guidelines) && array_filter($guidelines)): ?>
    <div class="print-section-heading">3. Event Guidelines &amp; Mechanics</div>
    <?php if (!empty($guidelines['mechanics'])): ?>
      <div class="print-text-block">
        <div class="print-block-label">Mechanics:</div>
        <div class="print-block-content"><?= htmlspecialchars($guidelines['mechanics']) ?></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($guidelines['criteria'])): ?>
      <div class="print-text-block">
        <div class="print-block-label">Criteria for Judging:</div>
        <div class="print-block-content"><?= htmlspecialchars($guidelines['criteria']) ?></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($guidelines['scoring_system'])): ?>
      <div class="print-text-block">
        <div class="print-block-label">Scoring System:</div>
        <div class="print-block-content"><?= htmlspecialchars($guidelines['scoring_system']) ?></div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- 4. Schedule of Activities -->
  <?php if (!empty($schedules)): ?>
    <div class="print-section-heading">4. Schedule of Activities</div>
    <table class="print-data-table">
      <thead>
        <tr>
          <th style="width:20%;">Date</th>
          <th style="width:40%;">Event Name / Description</th>
          <th style="width:20%;">Venue</th>
          <th style="width:20%;">Organizer</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($schedules as $s): ?>
          <tr>
            <td><?= !empty($s['sched_date']) ? date('M j, Y', strtotime($s['sched_date'])) : '—' ?></td>
            <td><?= htmlspecialchars($s['event_name'] ?? ($s['activity_description'] ?? '—')) ?></td>
            <td><?= htmlspecialchars($s['venue'] ?? '—') ?></td>
            <td><?= htmlspecialchars($s['organizer'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- 5. Program Sequence / Flow -->
  <?php if (!empty($program)): ?>
    <div class="print-section-heading">5. Program Sequence / Flow</div>
    <table class="print-data-table">
      <thead>
        <tr>
          <th style="width:22%;">Time Slot</th>
          <th style="width:52%;">Program Segment / Description</th>
          <th style="width:26%;">Person-in-Charge</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($program as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['time_slot'] ?? '—') ?></td>
            <td><?= htmlspecialchars(trim(($p['segment'] ?? '') . ' ' . ($p['description'] ?? ''))) ?></td>
            <td><?= htmlspecialchars($p['person_ic'] ?? ($p['person_in_charge'] ?? 'Unassigned')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- 6. Working Committees & Manpower -->
  <?php if (!empty($manpower)): ?>
    <div class="print-section-heading">6. Working Committees &amp; Manpower Allocation</div>
    <table class="print-data-table">
      <thead>
        <tr>
          <th style="width:45%;">Committee / Role Assignment</th>
          <th style="width:35%;">Assigned Member</th>
          <th style="width:20%;">Member Type</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($manpower as $m): ?>
          <tr>
            <td><?= htmlspecialchars($m['role'] ?? ($m['role_name'] ?? 'Committee')) ?></td>
            <td><?= htmlspecialchars($m['assigned_person'] ?? '—') ?></td>
            <td><?= htmlspecialchars(ucfirst($m['type'] ?? 'Member')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- 7. Tasks & Deliverables -->
  <?php if (!empty($ftasks)): ?>
    <div class="print-section-heading">7. Tasks &amp; Deliverables Monitoring</div>
    <table class="print-data-table">
      <thead>
        <tr>
          <th style="width:30%;">Task Title</th>
          <th style="width:22%;">Committee</th>
          <th style="width:22%;">Assigned Member</th>
          <th style="width:14%;">Target Due</th>
          <th style="width:12%;">Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ftasks as $t): ?>
          <tr>
            <td><?= htmlspecialchars($t['task_title'] ?: ($t['assigned_task'] ?: 'Task')) ?></td>
            <td><?= htmlspecialchars($t['committee'] ?: '—') ?></td>
            <td><?= htmlspecialchars($t['assigned_user_name'] ?: '—') ?></td>
            <td><?= !empty($t['due_date']) ? date('M j, Y', strtotime($t['due_date'])) : '—' ?></td>
            <td><?= htmlspecialchars($t['status'] ?? 'Not Started') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- 8. Budget & Materials Breakdown -->
  <?php if (!empty($materials)): ?>
    <div class="print-section-heading">8. Budget &amp; Materials Breakdown</div>
    <table class="print-data-table">
      <thead>
        <tr>
          <th style="width:38%;">Item Description</th>
          <th style="width:22%;">Provider / Source</th>
          <th style="width:12%;text-align:center;">Quantity</th>
          <th style="width:28%;text-align:right;">Estimated Cost</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($materials as $mat): ?>
          <tr>
            <td><?= htmlspecialchars($mat['item_name'] ?? 'Item') ?></td>
            <td><?= htmlspecialchars($mat['provider'] ?? '—') ?></td>
            <td style="text-align:center;"><?= (int)($mat['quantity'] ?? 1) ?></td>
            <td style="text-align:right;">PHP <?= number_format((float)($mat['est_cost'] ?? 0), 2) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="print-total-row">
          <td colspan="2">TOTAL ESTIMATED BUDGET</td>
          <td style="text-align:center;"><?= $totalQty ?></td>
          <td style="text-align:right;">PHP <?= number_format($totalBudget, 2) ?></td>
        </tr>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- 9. KPI & Evaluation Alignment -->
  <?php if (!empty($activeKpis)): ?>
    <div class="print-section-heading">9. KPI &amp; Evaluation Alignment</div>
    <table class="print-data-table">
      <thead>
        <tr>
          <th style="width:45%;">Criteria / Evaluation Indicator</th>
          <th style="width:25%;">Target Metric</th>
          <th style="width:30%;">Evaluation Method</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($activeKpis as $k): ?>
          <tr>
            <td><?= htmlspecialchars($k['criteria'] ?? 'Criteria') ?></td>
            <td><?= htmlspecialchars($k['target_value'] ?? ($k['target_metric'] ?? (!empty($k['avg']) ? number_format($k['avg'], 2) : '—'))) ?></td>
            <td><?= htmlspecialchars($k['evaluation_method'] ?? 'Online Evaluation Survey') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- 10. Institutional Signatories & Approval Review History -->
  <div class="print-section-heading">10. Institutional Approval History &amp; Signatures</div>
  <?php if (!empty($history)): ?>
    <table class="print-data-table">
      <thead>
        <tr>
          <th style="width:25%;">Reviewer / Signatory</th>
          <th style="width:20%;">Designation / Role</th>
          <th style="width:18%;">Action Taken</th>
          <th style="width:22%;">Date &amp; Time</th>
          <th style="width:15%;">Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><strong><?= htmlspecialchars($h['reviewer_name'] ?? ($h['name'] ?? 'Reviewer')) ?></strong></td>
            <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $h['reviewer_role'] ?? 'Approver'))) ?></td>
            <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $h['action'] ?? 'Reviewed'))) ?></td>
            <td><?= !empty($h['acted_at']) ? date('M j, Y g:i A', strtotime($h['acted_at'])) : '—' ?></td>
            <td><?= htmlspecialchars($h['notes'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Official Endorsement Signatures -->
  <div class="print-signatories-wrap">
    <div class="print-signatories-grid">
      <div class="print-signatory-cell">
        <div class="print-signatory-name"><?= htmlspecialchars($printProponent) ?></div>
        <div class="print-signatory-role">Prepared by / Proponent</div>
      </div>
      <div class="print-signatory-cell">
        <div class="print-signatory-name"><?= htmlspecialchars($printDept) ?> Head</div>
        <div class="print-signatory-role">Endorsed by / Program Head</div>
      </div>
      <div class="print-signatory-cell">
        <div class="print-signatory-name">Academic Head / Dean</div>
        <div class="print-signatory-role">Approved by / Academic Head</div>
      </div>
    </div>
  </div>

</div><!-- /#proposal-print-document -->

<!-- ======================================================================= -->
<!-- CLIENT-SIDE PDF EXPORT ENGINE & SCRIPT                                  -->
<!-- ======================================================================= -->
<!-- jsPDF & AutoTable CDNs (Ensures PDF generation is globally available) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

<script>
  window.GLOBAL_PROPOSAL_PDF_DATA = <?= json_encode($exportProposalData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

  if (typeof window.exportProposalPdf === 'undefined') {
    window.exportProposalPdf = function() {
      const data = window.GLOBAL_PROPOSAL_PDF_DATA;
      if (!data) {
        alert('Proposal data is not available.');
        return;
      }

      if (typeof window.jspdf === 'undefined' || !window.jspdf.jsPDF) {
        // Fallback directly to native print dialog with the formal layout
        window.print();
        return;
      }

      const { jsPDF } = window.jspdf;
      const doc = new jsPDF({
        orientation: 'portrait',
        unit: 'mm',
        format: 'a4'
      });

      const pageWidth = 210;
      const pageHeight = 297;
      const marginX = 14;
      const contentWidth = pageWidth - (marginX * 2);
      let currentY = 16;

      function ensureSpace(needed) {
        if (currentY + needed > pageHeight - 16) {
          doc.addPage();
          currentY = 16;
        }
      }

      function addHeading(text) {
        ensureSpace(14);
        currentY += 3;
        doc.setFillColor(241, 245, 249);
        doc.roundedRect(marginX, currentY - 4.5, contentWidth, 8, 1, 1, 'F');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(9.5);
        doc.setTextColor(0, 56, 118);
        doc.text(text, marginX + 3, currentY + 1);
        currentY += 7;
        doc.setTextColor(30, 41, 59);
        doc.setFont('helvetica', 'normal');
      }

      function addField(label, val) {
        if (!val || !val.trim() || val.trim() === '—') return;
        ensureSpace(10);
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8.5);
        doc.setTextColor(71, 85, 105);
        doc.text(label, marginX, currentY);
        currentY += 3.8;
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8.5);
        doc.setTextColor(30, 41, 59);
        const lines = doc.splitTextToSize(val, contentWidth);
        for (let i = 0; i < lines.length; i++) {
          ensureSpace(4.5);
          doc.text(lines[i], marginX, currentY);
          currentY += 4.2;
        }
        currentY += 1.5;
      }

      // Top banner
      doc.setFillColor(0, 56, 118);
      doc.rect(marginX, currentY - 2, contentWidth, 2, 'F');
      currentY += 4;

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(8);
      doc.setTextColor(0, 56, 118);
      doc.text('STI ACTIVITY & RESOURCE MANAGEMENT SYSTEM (STI-ARMES)', marginX, currentY);
      currentY += 6;

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(15);
      doc.setTextColor(15, 23, 42);
      const titleLines = doc.splitTextToSize(data.title || 'Activity Proposal', contentWidth - 45);
      for (let i = 0; i < titleLines.length; i++) {
        doc.text(titleLines[i], marginX, currentY);
        currentY += 6;
      }

      // Status
      doc.setFontSize(9);
      doc.setTextColor(71, 85, 105);
      doc.text('Status: ' + (data.status || 'Draft'), pageWidth - marginX, currentY - (titleLines.length * 6) + 4, { align: 'right' });

      doc.setDrawColor(226, 232, 240);
      doc.setLineWidth(0.4);
      doc.line(marginX, currentY, pageWidth - marginX, currentY);
      currentY += 4;

      // Notes
      if (data.revision_notes && data.revision_notes.trim()) {
        ensureSpace(14);
        const isRej = (data.status && data.status.toLowerCase() === 'rejected');
        const boxFill = isRej ? [254, 242, 242] : [254, 243, 199];
        const boxStroke = isRej ? [239, 68, 68] : [245, 158, 11];
        const textColor = isRej ? [185, 28, 28] : [180, 83, 9];
        const heading = isRej ? 'Disapproval Reason: ' : 'Revision Notes: ';

        doc.setFillColor(boxFill[0], boxFill[1], boxFill[2]);
        doc.setDrawColor(boxStroke[0], boxStroke[1], boxStroke[2]);
        const revLines = doc.splitTextToSize(heading + data.revision_notes, contentWidth - 6);
        const boxH = (revLines.length * 4) + 6;
        doc.roundedRect(marginX, currentY, contentWidth, boxH, 1.5, 1.5, 'FD');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(8);
        doc.setTextColor(textColor[0], textColor[1], textColor[2]);
        for (let i = 0; i < revLines.length; i++) {
          doc.text(revLines[i], marginX + 3, currentY + 4 + (i * 4));
        }
        currentY += boxH + 4;
      }

      // Overview
      addHeading('1. Activity Overview');
      const ovRows = [
        [
          { content: 'Event Date:', styles: { fontStyle: 'bold', textColor: [71, 85, 105], cellWidth: 26 } },
          { content: data.event_date || '—', styles: { cellWidth: 65 } },
          { content: 'Proponent:', styles: { fontStyle: 'bold', textColor: [71, 85, 105], cellWidth: 26 } },
          { content: data.proponent || '—', styles: { cellWidth: 65 } }
        ],
        [
          { content: 'Time Slot:', styles: { fontStyle: 'bold', textColor: [71, 85, 105] } },
          { content: data.time || '—' },
          { content: 'Department:', styles: { fontStyle: 'bold', textColor: [71, 85, 105] } },
          { content: data.department || '—' }
        ],
        [
          { content: 'Venue:', styles: { fontStyle: 'bold', textColor: [71, 85, 105] } },
          { content: data.venue || '—' },
          { content: 'Source:', styles: { fontStyle: 'bold', textColor: [71, 85, 105] } },
          { content: data.source || '—' }
        ],
        [
          { content: 'Participants:', styles: { fontStyle: 'bold', textColor: [71, 85, 105] } },
          { content: data.target_participants || '—' },
          { content: 'Clearance:', styles: { fontStyle: 'bold', textColor: [71, 85, 105] } },
          { content: data.clearance || 'Not Required' }
        ],
        [
          { content: 'Theme:', styles: { fontStyle: 'bold', textColor: [71, 85, 105] } },
          { content: data.theme || '—', colSpan: 3 }
        ]
      ];

      doc.autoTable({
        startY: currentY,
        margin: { left: marginX, right: marginX },
        body: ovRows,
        theme: 'plain',
        styles: { fontSize: 8.5, cellPadding: 1.8, textColor: [30, 41, 59] }
      });
      currentY = doc.lastAutoTable.finalY + 4;

      // Objectives
      if (data.general_objectives || data.specific_objectives || data.rationale) {
        addHeading('2. Objectives & Academic Alignment');
        addField('General Objectives:', data.general_objectives);
        addField('Specific Objectives:', data.specific_objectives);
        addField('Involved Subjects:', data.involved_subjects);
        addField('Rationale:', data.rationale);
      }

      // Schedules
      if (data.schedules && data.schedules.length > 0) {
        addHeading('3. Schedule of Activities');
        doc.autoTable({
          startY: currentY,
          margin: { left: marginX, right: marginX },
          head: [['Date', 'Event Name', 'Venue', 'Organizer']],
          body: data.schedules,
          theme: 'grid',
          styles: { fontSize: 8, cellPadding: 2, textColor: [30, 41, 59] },
          headStyles: { fillColor: [0, 56, 118], textColor: 255, fontStyle: 'bold' }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // Program Flow
      if (data.program && data.program.length > 0) {
        addHeading('4. Program Sequence / Flow');
        doc.autoTable({
          startY: currentY,
          margin: { left: marginX, right: marginX },
          head: [['Time Slot', 'Program Segment / Description', 'Person-in-Charge']],
          body: data.program,
          theme: 'grid',
          styles: { fontSize: 8, cellPadding: 2, textColor: [30, 41, 59] },
          headStyles: { fillColor: [0, 56, 118], textColor: 255, fontStyle: 'bold' }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // Manpower
      if (data.manpower && data.manpower.length > 0) {
        addHeading('5. Working Committees & Manpower Allocation');
        doc.autoTable({
          startY: currentY,
          margin: { left: marginX, right: marginX },
          head: [['Committee / Role Assignment', 'Assigned Person', 'Member Type']],
          body: data.manpower,
          theme: 'grid',
          styles: { fontSize: 8, cellPadding: 2, textColor: [30, 41, 59] },
          headStyles: { fillColor: [0, 56, 118], textColor: 255, fontStyle: 'bold' }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // Tasks
      if (data.tasks && data.tasks.length > 0) {
        addHeading('6. Tasks & Deliverables Monitoring');
        doc.autoTable({
          startY: currentY,
          margin: { left: marginX, right: marginX },
          head: [['Task Title', 'Committee', 'Assigned Member', 'Role', 'Target Due', 'Status']],
          body: data.tasks,
          theme: 'grid',
          styles: { fontSize: 8, cellPadding: 2, textColor: [30, 41, 59] },
          headStyles: { fillColor: [0, 56, 118], textColor: 255, fontStyle: 'bold' }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // Materials
      if (data.materials && data.materials.length > 0) {
        addHeading('7. Budget & Materials Breakdown');
        doc.autoTable({
          startY: currentY,
          margin: { left: marginX, right: marginX },
          head: [['Item Description', 'Description', 'Qty', 'Provider', 'Est. Cost']],
          body: data.materials,
          theme: 'grid',
          styles: { fontSize: 8, cellPadding: 2, textColor: [30, 41, 59] },
          headStyles: { fillColor: [0, 56, 118], textColor: 255, fontStyle: 'bold' }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // KPIs
      if (data.kpis && data.kpis.length > 0) {
        addHeading('8. KPI & Evaluation Alignment');
        doc.autoTable({
          startY: currentY,
          margin: { left: marginX, right: marginX },
          head: [['Indicator / Criteria', 'Target Metric', 'Evaluation Method']],
          body: data.kpis,
          theme: 'grid',
          styles: { fontSize: 8, cellPadding: 2, textColor: [30, 41, 59] },
          headStyles: { fillColor: [0, 56, 118], textColor: 255, fontStyle: 'bold' }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      // History & Signatures
      if (data.history && data.history.length > 0) {
        addHeading('9. Institutional Signatures & Approval History');
        doc.autoTable({
          startY: currentY,
          margin: { left: marginX, right: marginX },
          head: [['Reviewer / Signatory', 'Role', 'Action', 'Date & Time', 'Remarks']],
          body: data.history,
          theme: 'grid',
          styles: { fontSize: 8, cellPadding: 2, textColor: [30, 41, 59] },
          headStyles: { fillColor: [0, 56, 118], textColor: 255, fontStyle: 'bold' }
        });
        currentY = doc.lastAutoTable.finalY + 4;
      }

      let safeTitle = (data.title || 'Proposal').trim().replace(/[^a-zA-Z0-9_\-\s]/g, '').replace(/\s+/g, '_').substring(0, 50);
      doc.save(safeTitle + '_Proposal.pdf');
    };
  }
</script>

