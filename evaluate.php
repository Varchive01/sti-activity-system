<?php
/**
 * evaluate.php
 *
 * Panel 1 Module 5.1 & 5.2: Built-In QR Code & Link-Based Participant Feedback Portal.
 * Allows event attendees and stakeholders to evaluate an activity after completion,
 * measuring Event Success, Speaker Effectiveness, Logistics, Overall Satisfaction,
 * and activity-specific stakeholder evaluation questions, saving directly to `kpi_evaluations`.
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/QrCodeService.php';

$db = getDB();
$activityId = (int)($_GET['activity_id'] ?? $_POST['activity_id'] ?? 0);
if ($activityId <= 0) {
    $activityId = (int)$db->query("SELECT id FROM activities WHERE status IN ('approved', 'completed') ORDER BY id DESC LIMIT 1")->fetchColumn();
}

$stmt = $db->prepare("SELECT a.*, u.name AS faculty_name, u.department AS faculty_dept FROM activities a LEFT JOIN users u ON a.faculty_id = u.id WHERE a.id = ? LIMIT 1");
$stmt->execute([$activityId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    http_response_code(404);
    die('Activity not found.');
}

// Parse custom stakeholder questions or default 4 core dimensions (Event Success, Speaker Effectiveness, Logistics, Overall Satisfaction)
$customQuestions = !empty($activity['evaluation_questions']) ? (json_decode($activity['evaluation_questions'], true) ?: []) : [];
$coreRatingItems = [
    ['id' => 'core_event_success',         'question' => 'Event Success & Achievement of Stated Objectives'],
    ['id' => 'core_speaker_effectiveness', 'question' => 'Speaker / Facilitator Effectiveness & Topic Delivery'],
    ['id' => 'core_logistics',             'question' => 'Venue, Program Flow, Equipment & Logistics Organization'],
    ['id' => 'core_overall_satisfaction',  'question' => 'Overall Satisfaction with the Conduct of the Activity'],
];
foreach ($customQuestions as $cq) {
    if (($cq['type'] ?? 'rating') === 'rating' && !empty($cq['question'])) {
        $coreRatingItems[] = [
            'id'       => 'custom_' . ($cq['id'] ?? count($coreRatingItems)),
            'question' => $cq['question']
        ];
    }
}

$submittedSuccess = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_participant_feedback'])) {
    $evaluatorName = trim((string)($_POST['evaluator_name'] ?? 'Anonymous Participant'));
    $stakeholderRole = trim((string)($_POST['stakeholder_role'] ?? 'Student / Attendee'));
    $evaluatorLabel = ($evaluatorName !== '' ? $evaluatorName : 'Anonymous') . " ({$stakeholderRole})";
    $qualitativeComment = trim((string)($_POST['comments'] ?? ''));

    $insStmt = $db->prepare("
        INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $ratingsGiven = [];
    foreach ($coreRatingItems as $idx => $item) {
        $rVal = isset($_POST['rating_' . $idx]) ? max(1, min(4, (int)$_POST['rating_' . $idx])) : 4;
        $ratingsGiven[] = $rVal;
        $cText = ($idx === 0 && $qualitativeComment !== '') ? $qualitativeComment : null;
        $insStmt->execute([$activityId, mb_substr($item['question'], 0, 240, 'UTF-8'), $rVal, $cText, $evaluatorLabel]);
    }

    if (function_exists('reconcilePostEventMetrics')) {
        reconcilePostEventMetrics($db, $activityId);
    }
    $submittedSuccess = true;
}

$portalUrl = (defined('BASE_URL') ? BASE_URL : '') . '/evaluate.php?activity_id=' . $activityId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Participant Feedback Portal – <?= htmlspecialchars($activity['title']) ?></title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.2.0">
</head>
<body style="background:#f1f5f9; min-height:100vh; padding:28px 16px; font-family:'Plus Jakarta Sans', sans-serif;">
  <div style="width:100%; max-width:760px; margin:0 auto; box-sizing:border-box;">
    <div class="card" style="border-radius:16px; overflow:hidden; box-shadow:0 12px 30px rgba(15,23,42,0.08);">
      <div style="background:linear-gradient(135deg, #003876 0%, #0284c7 100%); color:#ffffff; padding:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div style="flex:1; min-width:240px;">
          <div style="font-size:0.75rem; font-weight:800; text-transform:uppercase; letter-spacing:0.08em; color:#fde047; margin-bottom:4px;">
            STI-ARMES • Participant Feedback &amp; Evaluation Portal
          </div>
          <h1 style="margin:0 0 6px 0; font-size:1.35rem; font-weight:800; color:#ffffff;"><?= htmlspecialchars($activity['title']) ?></h1>
          <div style="font-size:0.82rem; opacity:0.9;">
            📅 <?= $activity['event_date'] ? date('F j, Y', strtotime($activity['event_date'])) : 'Scheduled Date' ?> &bull; 📍 <?= htmlspecialchars($activity['venue'] ?: 'STI College Marikina') ?>
          </div>
        </div>
        <div style="background:#ffffff; padding:8px; border-radius:10px; text-align:center;">
          <?= QrCodeService::generateSvg($portalUrl, 84) ?>
          <div style="font-size:0.65rem; font-weight:700; color:#003876; margin-top:2px;">Scan QR to Evaluate</div>
        </div>
      </div>

      <div class="card-body" style="padding:24px;">
        <?php if ($submittedSuccess): ?>
          <div style="text-align:center; padding:32px 16px;">
            <div style="font-size:3rem; margin-bottom:10px;">🎉</div>
            <h2 style="margin:0 0 8px 0; color:#166534;">Thank You for Your Feedback!</h2>
            <p style="color:#475569; font-size:0.92rem; max-width:480px; margin:0 auto 18px;">
              Your evaluation has been recorded and automatically aggregated into the activity's quantitative KPI analytics and AI-assisted post-event assessment report.
            </p>
            <a href="evaluate.php?activity_id=<?= $activityId ?>" class="btn btn-primary btn-sm">Submit Another Response</a>
          </div>
        <?php else: ?>
          <form method="POST" style="display:flex; flex-direction:column; gap:16px;">
            <input type="hidden" name="submit_participant_feedback" value="1">
            <input type="hidden" name="activity_id" value="<?= $activityId ?>">

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
              <div>
                <label class="form-label" style="font-weight:700; font-size:0.82rem;">Your Name (Optional)</label>
                <input type="text" name="evaluator_name" class="form-control" placeholder="Leave blank for Anonymous">
              </div>
              <div>
                <label class="form-label" style="font-weight:700; font-size:0.82rem;">Stakeholder Role</label>
                <select name="stakeholder_role" class="form-control" style="font-weight:600;">
                  <option value="Student / Attendee">🎓 Student / Attendee</option>
                  <option value="Faculty / Adviser">👩‍🏫 Faculty / Adviser</option>
                  <option value="Guest Speaker / Judge">🎤 Guest Speaker / Judge</option>
                  <option value="Organizing Committee">🛠️ Organizing Committee / Peer</option>
                </select>
              </div>
            </div>

            <div style="padding:10px 14px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; font-size:0.8rem; color:#1e40af;">
              <strong>Rating Scale:</strong> <strong>4</strong> = Strongly Agree / Excellent &bull; <strong>3</strong> = Agree / Good &bull; <strong>2</strong> = Fair &bull; <strong>1</strong> = Needs Improvement
            </div>

            <?php foreach (array_slice($coreRatingItems, 0, 10) as $idx => $qItem): ?>
              <div style="padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                <div style="font-weight:600; font-size:0.86rem; color:#0f172a; margin-bottom:8px;">
                  <?= ($idx + 1) ?>. <?= htmlspecialchars($qItem['question']) ?> <span style="color:#dc2626;">*</span>
                </div>
                <div style="display:flex; gap:14px; flex-wrap:wrap; font-size:0.82rem;">
                  <?php foreach ([4 => '4 - Excellent', 3 => '3 - Good', 2 => '2 - Fair', 1 => '1 - Poor'] as $val => $lbl): ?>
                    <label style="display:inline-flex; align-items:center; gap:5px; cursor:pointer; font-weight:600;">
                      <input type="radio" name="rating_<?= $idx ?>" value="<?= $val ?>" <?= $val === 4 ? 'checked' : '' ?> required>
                      <span><?= $lbl ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>

            <div>
              <label class="form-label" style="font-weight:700; font-size:0.85rem;">💬 Comments, Suggestions &amp; Recommendations for Future Events <span style="color:#dc2626;">*</span></label>
              <textarea name="comments" class="form-control" rows="3" required placeholder="Share your comments and suggestions to help organizers improve future events..."></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center; padding:12px; font-weight:700; font-size:0.95rem;">
              ✅ Submit Event Evaluation
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
