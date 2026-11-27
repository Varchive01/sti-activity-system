<?php
/**
 * includes/phase3-post-event-details.php
 *
 * Shared Phase 3 Capstone Revision Component:
 * Renders across Post-Event Review and View-Activity pages (Faculty, Program Head, Admin 1, Admin 2, Dean):
 * 1. Expense Breakdown & Budget Utilization Module (Under Budget / Fully Utilized / Over Budget)
 * 2. Equipment Return & Condition Status Report (Returned Properly / Damaged / Needs Replacement)
 * 3. Committee Head Task Verification & Personnel Performance Measurement (with interactive Verify & Rate modal)
 * 4. Cloud Drive Media Link (Event Videos & Additional Photos)
 * 5. Summarized Comments/Suggestions & AI-Assisted Auto-Generated Assessment Report + Improvement Recommendations
 */
require_once __DIR__ . '/PostEventAssessmentHelper.php';

$p3ActId = (int)($activity['id'] ?? $id ?? 0);
$p3User  = isset($user) && is_array($user) ? $user : (function_exists('currentUser') ? currentUser() : []);
$p3Role  = $p3User['role'] ?? '';
$p3CanVerifyTasks = (
    in_array($p3Role, ['admin1', 'admin2'], true)
    || (in_array($p3Role, ['faculty', 'program_head'], true) && (int)($activity['faculty_id'] ?? 0) === (int)($p3User['id'] ?? 0))
    || ($p3Role === 'program_head' && function_exists('canUserAccessActivity') && canUserAccessActivity($p3ActId, 'modify', (int)($p3User['id'] ?? 0), $p3Role, $p3User['dept'] ?? ''))
);

$p3Tasks = isset($ftasks) && is_array($ftasks) ? $ftasks : [];
if (empty($p3Tasks) && isset($db) && $p3ActId > 0) {
    try {
        $p3FtStmt = $db->prepare("SELECT ft.*, u.name AS assigned_user_name FROM faculty_tasks ft LEFT JOIN users u ON ft.assigned_user_id = u.id WHERE ft.activity_id = ? ORDER BY ft.id ASC");
        $p3FtStmt->execute([$p3ActId]);
        $p3Tasks = $p3FtStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $p3Tasks = [];
    }
}

$p3Pe = isset($postEvent) && is_array($postEvent) ? $postEvent : [];
$p3ApprovedBudget = isset($p3Pe['approved_budget']) && $p3Pe['approved_budget'] !== null
    ? (float)$p3Pe['approved_budget']
    : (isset($db) && $p3ActId > 0 ? PostEventAssessmentHelper::computeApprovedBudget($db, $p3ActId) : 0.0);
$p3ActualExpenses = isset($p3Pe['actual_expenses']) && $p3Pe['actual_expenses'] !== null
    ? (float)$p3Pe['actual_expenses']
    : null;
$p3BudgetEval = PostEventAssessmentHelper::evaluateBudgetStatus($p3ApprovedBudget, $p3ActualExpenses);

$p3EquipItems = !empty($p3Pe['equipment_report_json'])
    ? (json_decode($p3Pe['equipment_report_json'], true) ?: [])
    : [];

$p3MilestonesRaw = !empty($activity['implementation_milestones_json'])
    ? (json_decode($activity['implementation_milestones_json'], true) ?: [])
    : [];
$p3IsApprovedOrCompleted = in_array($activity['status'] ?? '', ['approved', 'completed'], true);
$p3IsCompleted = (($activity['status'] ?? '') === 'completed' || !empty($p3Pe));
$p3DefaultMilestones = [
    'pre_proposal_approved'   => $p3IsApprovedOrCompleted,
    'pre_venue_equipment'     => $p3IsApprovedOrCompleted,
    'pre_consents_safety'     => $p3IsApprovedOrCompleted,
    'day_registration_active' => $p3IsCompleted,
    'day_equipment_checked'   => $p3IsCompleted,
    'day_program_executed'    => $p3IsCompleted,
    'post_equipment_returned' => $p3IsCompleted,
    'post_expenses_uploaded'  => !empty($p3Pe),
    'post_assessment_done'    => !empty($p3Pe),
];
$p3Milestones = array_merge($p3DefaultMilestones, $p3MilestonesRaw);
?>

<!-- Panel 1 Module 4.3 & 5.2: Implementation Milestone Tracker (Pre-Activity, On-the-Day, Post-Activity) & Participant Feedback Portal Link -->
<div class="card detail-section-card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div>
      <h2 style="margin:0;"><span>🏁</span> Implementation Milestone Tracker &amp; Participant Feedback Portal</h2>
      <span style="font-size:0.76rem; color:var(--text-muted);">Check off compliance milestones for Pre-Activity, On-the-Day, and Post-Activity monitoring.</span>
    </div>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
      <a href="<?= BASE_URL ?>/evaluate.php?activity_id=<?= $p3ActId ?>" target="_blank" class="btn btn-outline btn-xs" style="padding:5px 10px; font-size:0.76rem;">
        📲 Open Built-In Participant Feedback Portal (QR / Link)
      </a>
      <?php if ($p3CanVerifyTasks): ?>
        <button type="button" class="btn btn-primary btn-xs" onclick="savePhase3Milestones(<?= $p3ActId ?>)" id="btnSaveP3Milestones" style="padding:5px 12px; font-size:0.76rem;">
          💾 Save Milestone Checklist
        </button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px;">
      <!-- Pre-Activity Milestones -->
      <div style="padding:12px 14px; background:var(--bg-base); border:1px solid var(--border); border-radius:8px;">
        <strong style="font-size:0.84rem; color:#0284c7; display:block; margin-bottom:8px;">1. Pre-Activity Compliance</strong>
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; margin-bottom:6px; cursor:pointer;">
          <input type="checkbox" class="p3-milestone-cb" data-key="pre_proposal_approved" <?= !empty($p3Milestones['pre_proposal_approved']) ? 'checked' : '' ?> <?= !$p3CanVerifyTasks ? 'disabled' : '' ?>>
          <span>Multi-level proposal approval &amp; signatories completed</span>
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; margin-bottom:6px; cursor:pointer;">
          <input type="checkbox" class="p3-milestone-cb" data-key="pre_venue_equipment" <?= !empty($p3Milestones['pre_venue_equipment']) ? 'checked' : '' ?> <?= !$p3CanVerifyTasks ? 'disabled' : '' ?>>
          <span>Venue reservation &amp; equipment allocation verified</span>
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;">
          <input type="checkbox" class="p3-milestone-cb" data-key="pre_consents_safety" <?= !empty($p3Milestones['pre_consents_safety']) ? 'checked' : '' ?> <?= !$p3CanVerifyTasks ? 'disabled' : '' ?>>
          <span>Safety guidelines, letter requests &amp; parental consents checked</span>
        </label>
      </div>

      <!-- On-the-Day Milestones -->
      <div style="padding:12px 14px; background:var(--bg-base); border:1px solid var(--border); border-radius:8px;">
        <strong style="font-size:0.84rem; color:#f59e0b; display:block; margin-bottom:8px;">2. On-the-Day Compliance</strong>
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; margin-bottom:6px; cursor:pointer;">
          <input type="checkbox" class="p3-milestone-cb" data-key="day_registration_active" <?= !empty($p3Milestones['day_registration_active']) ? 'checked' : '' ?> <?= !$p3CanVerifyTasks ? 'disabled' : '' ?>>
          <span>Participant attendance &amp; registration tracking active</span>
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; margin-bottom:6px; cursor:pointer;">
          <input type="checkbox" class="p3-milestone-cb" data-key="day_equipment_checked" <?= !empty($p3Milestones['day_equipment_checked']) ? 'checked' : '' ?> <?= !$p3CanVerifyTasks ? 'disabled' : '' ?>>
          <span>Audio-visual, floor plan layout &amp; safety protocols deployed</span>
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;">
          <input type="checkbox" class="p3-milestone-cb" data-key="day_program_executed" <?= !empty($p3Milestones['day_program_executed']) ? 'checked' : '' ?> <?= !$p3CanVerifyTasks ? 'disabled' : '' ?>>
          <span>Program invitation sequence &amp; committee roles executed</span>
        </label>
      </div>

      <!-- Post-Activity Milestones -->
      <div style="padding:12px 14px; background:var(--bg-base); border:1px solid var(--border); border-radius:8px;">
        <strong style="font-size:0.84rem; color:#10b981; display:block; margin-bottom:8px;">3. Post-Activity Compliance</strong>
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; margin-bottom:6px; cursor:pointer;">
          <input type="checkbox" class="p3-milestone-cb" data-key="post_equipment_returned" <?= !empty($p3Milestones['post_equipment_returned']) ? 'checked' : '' ?> <?= !$p3CanVerifyTasks ? 'disabled' : '' ?>>
          <span>Equipment return &amp; condition status report submitted</span>
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; margin-bottom:6px; cursor:pointer;">
          <input type="checkbox" class="p3-milestone-cb" data-key="post_expenses_uploaded" <?= !empty($p3Milestones['post_expenses_uploaded']) ? 'checked' : '' ?> <?= !$p3CanVerifyTasks ? 'disabled' : '' ?>>
          <span>Breakdown of expenses &amp; budget utilization liquidated</span>
        </label>
        <label style="display:flex; align-items:center; gap:8px; font-size:0.8rem; cursor:pointer;">
          <input type="checkbox" class="p3-milestone-cb" data-key="post_assessment_done" <?= !empty($p3Milestones['post_assessment_done']) ? 'checked' : '' ?> <?= !$p3CanVerifyTasks ? 'disabled' : '' ?>>
          <span>5+ event photos, drive link &amp; AI assessment report completed</span>
        </label>
      </div>
    </div>
  </div>
</div>
<?php if ($p3CanVerifyTasks): ?>
<script>
  async function savePhase3Milestones(actId) {
    const btn = document.getElementById('btnSaveP3Milestones');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
    const milestones = {};
    document.querySelectorAll('.p3-milestone-cb').forEach(cb => {
      milestones[cb.getAttribute('data-key')] = cb.checked ? 1 : 0;
    });
    try {
      const res = await fetch('<?= BASE_URL ?>/api/task-assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_milestones', activity_id: actId, milestones: milestones })
      });
      const data = await res.json();
      if (data && data.success) {
        if (btn) btn.textContent = '✓ Saved!';
        setTimeout(() => { if (btn) { btn.disabled = false; btn.textContent = '💾 Save Milestone Checklist'; } }, 1200);
      }
    } catch (e) {
      if (btn) { btn.disabled = false; btn.textContent = '💾 Save Milestone Checklist'; }
    }
  }
</script>
<?php endif; ?>

<!-- Phase 3.1: Committee Head Task Verification & Personnel Performance Measurement -->
<?php if (!empty($p3Tasks)): ?>
<div class="card detail-section-card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <h2><span>✅</span> Committee Head Task Verification &amp; Personnel Performance</h2>
    <span class="badge badge-primary" style="font-size:0.75rem;">
      <?= count($p3Tasks) ?> Assigned Personnel / Tasks
    </span>
  </div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrap" style="margin:0; border:none;">
      <table class="detail-table" style="width:100%; border-collapse:collapse;">
        <thead>
          <tr>
            <th>Assigned Personnel &amp; Role</th>
            <th>Task &amp; Committee</th>
            <th>Committee Head Verification</th>
            <th>Responsibility Fulfillment Rating</th>
            <th>Performance Remarks</th>
            <?php if ($p3CanVerifyTasks): ?>
              <th style="text-align:right;">Verify / Rate</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($p3Tasks as $p3t):
            $tId = (int)$p3t['id'];
            $tPerson = $p3t['assigned_user_name'] ?: ($p3t['faculty_name'] ?: 'Assigned Member');
            $tRole = $p3t['role_in_event'] ?: 'Committee Member';
            $tTitle = $p3t['task_title'] ?: ($p3t['assigned_task'] ?: 'Assigned Task');
            $tComm = $p3t['committee'] ?: 'General Committee';
            $verSt = $p3t['verification_status'] ?? (($p3t['status'] ?? '') === 'Completed' ? 'verified_correct' : 'pending');
            $pRating = isset($p3t['performance_rating']) && $p3t['performance_rating'] !== null ? (int)$p3t['performance_rating'] : null;
            $pRemarks = $p3t['performance_remarks'] ?? '';
          ?>
            <tr>
              <td>
                <strong style="color:var(--text-main);"><?= htmlspecialchars($tPerson) ?></strong>
                <div style="font-size:0.74rem; color:var(--text-muted);"><?= htmlspecialchars($tRole) ?></div>
              </td>
              <td>
                <div style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($tTitle) ?></div>
                <span class="badge badge-secondary" style="font-size:0.68rem; margin-top:2px;"><?= htmlspecialchars($tComm) ?></span>
              </td>
              <td>
                <?php if ($verSt === 'verified_correct'): ?>
                  <span class="badge badge-success" style="font-size:0.74rem;">✓ Verified &amp; Done Correctly</span>
                <?php elseif ($verSt === 'needs_correction'): ?>
                  <span class="badge badge-danger" style="font-size:0.74rem;">⚠️ Needs Correction</span>
                <?php else: ?>
                  <span class="badge badge-warning" style="font-size:0.74rem;">⏳ Pending Head Verification</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($pRating !== null): ?>
                  <span style="color:#f59e0b; font-weight:700; font-size:0.88rem;"><?= str_repeat('★', $pRating) . str_repeat('☆', max(0, 5 - $pRating)) ?></span>
                  <strong style="font-size:0.8rem; color:var(--text-main); margin-left:4px;"><?= $pRating ?>/5</strong>
                <?php else: ?>
                  <span style="font-size:0.78rem; color:var(--text-muted);">Not yet rated</span>
                <?php endif; ?>
              </td>
              <td style="font-size:0.8rem; color:var(--text-muted);">
                <?= htmlspecialchars($pRemarks ?: '—') ?>
              </td>
              <?php if ($p3CanVerifyTasks): ?>
                <td style="text-align:right;">
                  <button type="button" class="btn btn-outline btn-xs" style="padding:4px 9px; font-size:0.74rem;"
                          onclick='openPhase3TaskVerifyModal(<?= $tId ?>, <?= $p3ActId ?>, <?= json_encode($tTitle) ?>, <?= json_encode($tPerson) ?>, <?= json_encode($verSt) ?>, <?= json_encode($pRating ?? 5) ?>, <?= json_encode($pRemarks) ?>)'>
                    ✓ Verify &amp; Rate
                  </button>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($p3Pe)): ?>
<!-- Phase 3.2: Breakdown of Expenses & Budget Utilization Analysis -->
<div class="card detail-section-card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <h2><span>💰</span> Breakdown of Expenses &amp; Budget Utilization</h2>
    <?php
      $bBadgeCls = match($p3BudgetEval['status']) {
          'fully_utilized' => 'badge-success',
          'under_budget'   => 'badge-info',
          'over_budget'    => 'badge-danger',
          default          => 'badge-secondary'
      };
    ?>
    <span class="badge <?= $bBadgeCls ?>" style="font-size:0.78rem; padding:4px 10px;">
      <?= htmlspecialchars($p3BudgetEval['label']) ?>
    </span>
  </div>
  <div class="card-body">
    <div class="info-grid" style="grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:14px;">
      <div class="info-tile">
        <div class="lbl">Approved / Planned Budget</div>
        <div class="val" style="font-size:1.1rem; font-weight:700;">₱<?= number_format($p3ApprovedBudget, 2) ?></div>
      </div>
      <div class="info-tile">
        <div class="lbl">Actual Total Expenses</div>
        <div class="val" style="font-size:1.1rem; font-weight:700; color:#0284c7;">
          <?= $p3ActualExpenses !== null ? '₱' . number_format($p3ActualExpenses, 2) : '—' ?>
        </div>
      </div>
      <div class="info-tile">
        <div class="lbl">Budget Variance</div>
        <div class="val" style="font-size:1.05rem; font-weight:700;">
          <?= $p3ActualExpenses !== null ? (($p3BudgetEval['variance'] > 0 ? '+' : '') . '₱' . number_format($p3BudgetEval['variance'], 2)) : '—' ?>
        </div>
      </div>
    </div>

    <?php if (!empty($p3Pe['expense_breakdown_path'])): ?>
      <div style="margin-top:14px; padding:10px 14px; background:var(--bg-base); border:1px solid var(--border); border-radius:8px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div>
          <strong style="font-size:0.86rem; color:var(--text-main);">📎 Uploaded Breakdown of Expenses Document</strong>
          <div style="font-size:0.76rem; color:var(--text-muted);"><?= htmlspecialchars(basename($p3Pe['expense_breakdown_path'])) ?></div>
        </div>
        <div style="display:flex; gap:8px;">
          <a href="<?= BASE_URL ?>/api/document-download.php?activity_id=<?= $p3ActId ?>&type=expense_breakdown" target="_blank" class="btn btn-outline btn-xs" style="padding:4px 10px; font-size:0.75rem;">View Breakdown</a>
          <a href="<?= BASE_URL ?>/api/document-download.php?activity_id=<?= $p3ActId ?>&type=expense_breakdown&download=1" class="btn btn-primary btn-xs" style="padding:4px 10px; font-size:0.75rem;">⬇ Download</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Phase 3.3: Equipment Return & Condition Status Report -->
<?php if (!empty($p3EquipItems)): ?>
<div class="card detail-section-card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <h2><span>🛠️</span> Equipment Return &amp; Condition Status Report</h2>
    <span class="badge badge-secondary" style="font-size:0.75rem;"><?= count($p3EquipItems) ?> Equipment Item<?= count($p3EquipItems) === 1 ? '' : 's' ?></span>
  </div>
  <div class="card-body" style="padding:0;">
    <div class="table-wrap" style="margin:0; border:none;">
      <table class="detail-table" style="width:100%; border-collapse:collapse;">
        <thead>
          <tr>
            <th>Equipment / Material Item</th>
            <th style="text-align:center; width:90px;">Quantity</th>
            <th style="width:210px;">Return / Condition Status</th>
            <th>Condition Remarks / Custodian Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($p3EquipItems as $eq):
            $eqSt = $eq['status'] ?? 'returned_properly';
          ?>
            <tr>
              <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($eq['item_name'] ?? 'Equipment') ?></td>
              <td style="text-align:center; font-weight:600;"><?= (int)($eq['quantity'] ?? 1) ?></td>
              <td>
                <?php if ($eqSt === 'damaged'): ?>
                  <span class="badge badge-warning" style="font-size:0.74rem;">⚠️ Damaged</span>
                <?php elseif ($eqSt === 'needs_replacement'): ?>
                  <span class="badge badge-danger" style="font-size:0.74rem;">🔄 Needs Replacement</span>
                <?php else: ?>
                  <span class="badge badge-success" style="font-size:0.74rem;">✅ Returned Properly</span>
                <?php endif; ?>
              </td>
              <td style="font-size:0.82rem; color:var(--text-muted);"><?= htmlspecialchars($eq['remarks'] ?: 'Returned in good condition') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Phase 3.4: Cloud Drive Media Link for Event Videos & Additional Photos -->
<?php if (!empty($p3Pe['media_drive_link'])): ?>
<div class="card detail-section-card" style="margin-bottom:20px;">
  <div class="card-header">
    <h2><span>🎬</span> Cloud Drive Media Repository (Videos &amp; Full Photo Album)</h2>
  </div>
  <div class="card-body" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
    <div>
      <div style="font-weight:600; color:var(--text-main); font-size:0.9rem;">Shared Cloud Drive Folder (Videos &amp; Additional High-Resolution Photos)</div>
      <a href="<?= htmlspecialchars($p3Pe['media_drive_link']) ?>" target="_blank" rel="noopener" style="font-size:0.82rem; color:#0284c7; word-break:break-all;">
        <?= htmlspecialchars($p3Pe['media_drive_link']) ?>
      </a>
    </div>
    <a href="<?= htmlspecialchars($p3Pe['media_drive_link']) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm">
      ↗ Open Media Drive Folder
    </a>
  </div>
</div>
<?php endif; ?>

<!-- Phase 3.5: Summarized Comments & Suggestions + AI-Assisted Assessment & Recommendations -->
<?php if (!empty($p3Pe['ai_assessment_summary']) || !empty($p3Pe['comments_summary']) || !empty($p3Pe['ai_recommendations'])): ?>
<div class="card detail-section-card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
    <h2><span>🤖</span> AI-Assisted Auto-Generated Assessment Report &amp; Improvement Recommendations</h2>
    <span class="badge badge-info" style="font-size:0.74rem;">AI-Assisted Synthesis</span>
  </div>
  <div class="card-body" style="display:flex; flex-direction:column; gap:14px;">
    <?php if (!empty($p3Pe['ai_assessment_summary'])): ?>
      <div class="info-tile">
        <div class="lbl">📋 Auto-Generated Post-Activity Assessment Narrative</div>
        <div style="font-size:0.86rem; line-height:1.6; color:var(--text-main); margin-top:6px; white-space:pre-wrap;"><?= htmlspecialchars($p3Pe['ai_assessment_summary']) ?></div>
      </div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:14px;">
      <?php if (!empty($p3Pe['comments_summary'])): ?>
        <div class="info-tile">
          <div class="lbl">💬 Summarized Participant Comments &amp; Suggestions</div>
          <div style="font-size:0.85rem; line-height:1.6; color:var(--text-main); margin-top:6px; white-space:pre-wrap;"><?= htmlspecialchars($p3Pe['comments_summary']) ?></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($p3Pe['ai_recommendations'])): ?>
        <div class="info-tile">
          <div class="lbl">💡 AI-Generated Recommendations for Future Corrections &amp; Improvements</div>
          <div style="font-size:0.85rem; line-height:1.6; color:var(--text-main); margin-top:6px; white-space:pre-wrap;"><?= htmlspecialchars($p3Pe['ai_recommendations']) ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($p3CanVerifyTasks): ?>
<!-- Interactive Committee Head Task Verification & Personnel Performance Modal -->
<div id="phase3TaskVerifyModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.65); z-index:99999; align-items:center; justify-content:center; padding:16px;">
  <div style="background:var(--bg-card, #ffffff); border:1px solid var(--border, #e2e8f0); border-radius:12px; max-width:480px; width:100%; overflow:hidden; box-shadow:0 20px 40px rgba(0,0,0,0.3);">
    <div style="padding:14px 18px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
      <strong style="font-size:0.98rem; color:var(--text-main);">✅ Verify Task &amp; Rate Personnel Performance</strong>
      <button type="button" onclick="closePhase3TaskVerifyModal()" style="background:none; border:none; font-size:1.2rem; color:var(--text-muted); cursor:pointer;">&times;</button>
    </div>
    <form onsubmit="submitPhase3TaskVerify(event)" style="padding:18px; display:flex; flex-direction:column; gap:14px; margin:0;">
      <input type="hidden" id="p3VerifyTaskId" value="">
      <input type="hidden" id="p3VerifyActivityId" value="">
      <div>
        <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase; font-weight:700;">Personnel &amp; Task</div>
        <div id="p3VerifyTaskLabel" style="font-weight:700; color:var(--text-main); font-size:0.92rem; margin-top:2px;">—</div>
      </div>
      <div>
        <label class="form-label" style="font-size:0.82rem; font-weight:700;">Committee Head Verification</label>
        <select id="p3VerifyStatusSelect" class="form-control" style="font-weight:600;">
          <option value="verified_correct">✅ Verified Completed &amp; Done Correctly</option>
          <option value="needs_correction">⚠️ Incomplete / Needs Correction</option>
          <option value="pending">⏳ Pending Verification</option>
        </select>
      </div>
      <div>
        <label class="form-label" style="font-size:0.82rem; font-weight:700;">Personnel Responsibility Fulfillment Rating (1–5)</label>
        <select id="p3VerifyRatingSelect" class="form-control" style="font-weight:600;">
          <option value="5">★★★★★ (5 - Outstanding Fulfillment)</option>
          <option value="4">★★★★☆ (4 - Exceeded Expectations)</option>
          <option value="3">★★★☆☆ (3 - Fulfilled Responsibilities)</option>
          <option value="2">★★☆☆☆ (2 - Partially Fulfilled)</option>
          <option value="1">★☆☆☆☆ (1 - Did Not Fulfill)</option>
        </select>
      </div>
      <div>
        <label class="form-label" style="font-size:0.82rem; font-weight:700;">Performance Measurement Remarks</label>
        <textarea id="p3VerifyRemarksInput" class="form-control" rows="2" placeholder="Confirm quality of work and responsibility fulfillment..."></textarea>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="closePhase3TaskVerifyModal()">Cancel</button>
        <button type="submit" id="p3VerifySubmitBtn" class="btn btn-primary btn-sm">Save Verification &amp; Rating</button>
      </div>
    </form>
  </div>
</div>
<script>
  function openPhase3TaskVerifyModal(taskId, actId, taskTitle, personName, verStatus, rating, remarks) {
    document.getElementById('p3VerifyTaskId').value = taskId;
    document.getElementById('p3VerifyActivityId').value = actId;
    document.getElementById('p3VerifyTaskLabel').textContent = (personName || 'Member') + ' — ' + (taskTitle || 'Task');
    document.getElementById('p3VerifyStatusSelect').value = verStatus || 'verified_correct';
    document.getElementById('p3VerifyRatingSelect').value = rating || 5;
    document.getElementById('p3VerifyRemarksInput').value = remarks || 'Task verified completed and done correctly.';
    document.getElementById('phase3TaskVerifyModal').style.display = 'flex';
  }
  function closePhase3TaskVerifyModal() {
    const m = document.getElementById('phase3TaskVerifyModal');
    if (m) m.style.display = 'none';
  }
  async function submitPhase3TaskVerify(e) {
    e.preventDefault();
    const btn = document.getElementById('p3VerifySubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Saving...';
    try {
      const res = await fetch('<?= BASE_URL ?>/api/task-assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'verify_task',
          id: parseInt(document.getElementById('p3VerifyTaskId').value, 10),
          activity_id: parseInt(document.getElementById('p3VerifyActivityId').value, 10),
          verification_status: document.getElementById('p3VerifyStatusSelect').value,
          performance_rating: parseInt(document.getElementById('p3VerifyRatingSelect').value, 10),
          performance_remarks: document.getElementById('p3VerifyRemarksInput').value.trim()
        })
      });
      const data = await res.json();
      if (data && data.success) {
        closePhase3TaskVerifyModal();
        window.location.reload();
      } else {
        alert(data.error || 'Failed to save verification.');
      }
    } catch (err) {
      alert('Network error while saving task verification.');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Save Verification & Rating';
    }
  }
</script>
<?php endif; ?>
