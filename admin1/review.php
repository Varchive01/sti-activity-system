<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai/proposal_validator.php';
requireRole('admin1');
$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: '.BASE_URL.'/admin1/dashboard.php'); exit; }

$act = $db->prepare("SELECT a.*,u.name as faculty_name,u.email as faculty_email FROM activities a JOIN users u ON a.faculty_id=u.id WHERE a.id=? AND source='student_org'");
$act->execute([$id]);
$activity = $act->fetch();
if (!$activity) { die('Activity not found or not accessible.'); }

$materials = $db->prepare("SELECT * FROM materials WHERE activity_id=?");
$materials->execute([$id]);
$mats = $materials->fetchAll();

$program = $db->prepare("SELECT * FROM program_sequence WHERE activity_id=? ORDER BY sort_order,time_slot");
$program->execute([$id]);
$prog = $program->fetchAll();

$manpower = $db->prepare("SELECT * FROM manpower WHERE activity_id=?");
$manpower->execute([$id]);
$mp = $manpower->fetchAll();

$schedules = $db->prepare("SELECT * FROM schedules WHERE activity_id=? ORDER BY sched_date");
$schedules->execute([$id]);
$scheds = $schedules->fetchAll();

$guideStmt = $db->prepare("SELECT * FROM guidelines WHERE activity_id=?");
$guideStmt->execute([$id]);
$guide = $guideStmt->fetch();

$ftStmt = $db->prepare("SELECT * FROM faculty_tasks WHERE activity_id=?");
$ftStmt->execute([$id]);
$ftasks = $ftStmt->fetchAll();

$fpStmt = $db->prepare("SELECT * FROM floor_plans WHERE activity_id=?");
$fpStmt->execute([$id]);
$fp = $fpStmt->fetch();

$kpiStmt = $db->prepare("SELECT * FROM kpi_evaluations WHERE activity_id=?");
$kpiStmt->execute([$id]);
$kpis = $kpiStmt->fetchAll();

$logs = $db->prepare("SELECT al.*,u.name FROM approval_logs al JOIN users u ON al.reviewer_id=u.id WHERE al.activity_id=? ORDER BY al.acted_at DESC");
$logs->execute([$id]);
$history = $logs->fetchAll();

// Load any staged section comments for this reviewer
$scStmt = $db->prepare("SELECT section_key,comment FROM section_comments WHERE activity_id=? AND reviewer_id=?");
$scStmt->execute([$id, $user['id']]);
$stagedComments = [];
foreach ($scStmt->fetchAll() as $sc) {
    $stagedComments[$sc['section_key']] = $sc['comment'];
}
$stagedCount = count($stagedComments);

$totalBudget = array_sum(array_column($mats, 'est_cost'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($activity['title'] ?? 'Review Proposal') ?> – STI</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css?v=1.0.7">
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
<style>
/* ── Plus Jakarta Sans Consistency: No Syne on this page ── */
body,
h1, h2, h3, h4, h5, h6,
.page-title,
.proposal-title,
.review-section-header h2,
.btn,
.badge,
.topbar-user-name,
.topbar-user-role,
.info-block,
.review-table,
.modal h3,
.ai-validation-card,
.ai-validation-card h3,
.ai-question-item,
label, input, button, select, textarea {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
}

/* ── Content & Container Spacing ── */
.content {
  padding: 24px;
}

/* ── Free-Standing Topbar Icons (No Permanent Box) ── */
#themeToggleBtn,
#notifBellBtn,
.theme-toggle-btn,
.notif-bell-btn {
  width: 38px;
  height: 38px;
  min-width: 38px;
  min-height: 38px;
  border-radius: 50%;
  background: transparent !important;
  border: none !important;
  box-shadow: none !important;
  color: var(--text-muted);
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: background .18s ease, color .18s ease, transform .15s ease;
  position: relative;
  outline: none;
  padding: 0;
  box-sizing: border-box;
  line-height: 1;
}

#themeToggleBtn:hover,
#notifBellBtn:hover,
.theme-toggle-btn:hover,
.notif-bell-btn:hover {
  background: var(--border-light) !important;
  color: var(--text-main);
  border: none !important;
  box-shadow: none !important;
  transform: translateY(-1px);
}

#themeToggleBtn:active,
#notifBellBtn:active,
.theme-toggle-btn:active,
.notif-bell-btn:active {
  transform: translateY(0) scale(0.92);
  box-shadow: none !important;
}

#themeToggleBtn:focus-visible,
#notifBellBtn:focus-visible,
.theme-toggle-btn:focus-visible,
.notif-bell-btn:focus-visible {
  outline: 2px solid var(--sti-blue);
  outline-offset: 2px;
  border-radius: 50%;
}

/* ── Sticky Action Bar (Directly below 64px Topbar) ── */
.review-action-bar {
  position: sticky;
  top: 64px;
  z-index: 45;
  background: var(--bg-card);
  border-bottom: 1px solid var(--border);
  padding: 12px 24px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  box-shadow: var(--shadow-sm);
  transition: background-color 0.2s ease, border-color 0.2s ease;
}
.review-action-bar .proposal-meta {
  flex: 1;
  min-width: 0;
}
.review-action-bar .proposal-title {
  font-size: 1.20rem;
  font-weight: 800;
  color: var(--text-main);
  letter-spacing: -0.015em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.3;
}
.review-action-bar .proposal-meta .text-sm {
  margin-top: 4px;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 0.82rem;
  color: var(--text-muted);
}
.review-action-bar .btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.82rem;
  font-weight: 600;
  padding: 0 16px;
  height: 36px;
  border-radius: 8px;
  white-space: nowrap;
  transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
  box-shadow: var(--shadow-sm);
  line-height: 1;
  cursor: pointer;
  text-decoration: none;
}
.review-action-bar .btn:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow);
}
.review-action-bar .btn-sm {
  height: 36px;
  padding: 0 14px;
}
.review-action-bar .btn-outline {
  border: 1px solid var(--border);
  color: var(--text-main);
  background: var(--bg-card);
}
.review-action-bar .btn-outline:hover {
  border-color: var(--accent, var(--sti-blue));
  color: var(--accent, var(--sti-blue));
  background: rgba(2, 132, 199, 0.06);
}
.review-action-bar button[onclick*="return"] {
  border: 1px solid rgba(245, 158, 11, 0.35);
  color: #B45309;
  background: rgba(245, 158, 11, 0.08);
}
.review-action-bar button[onclick*="return"]:hover {
  background: rgba(245, 158, 11, 0.16);
  border-color: #D97706;
  color: #92400E;
}
.review-action-bar .btn-danger {
  background: #DC2626;
  border: 1px solid #DC2626;
  color: #fff;
}
.review-action-bar .btn-danger:hover {
  background: #B91C1C;
  border-color: #B91C1C;
  filter: brightness(1.05);
}
.review-action-bar .btn-success {
  background: #16A34A;
  border: 1px solid #16A34A;
  color: #fff;
}
.review-action-bar .btn-success:hover {
  background: #15803D;
  border-color: #15803D;
  filter: brightness(1.05);
}

/* ── Section Card with Request Revision ── */
.review-section {
  margin-bottom: 20px;
  border-radius: var(--radius, 14px);
  border: 1px solid var(--border);
  background: var(--bg-card);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.review-section.flagged {
  border-color: var(--sti-gold, #F59E0B);
  box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.25), var(--shadow);
}
.review-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  background: var(--bg-card);
  border-bottom: 1px solid var(--border);
  gap: 12px;
}
.review-section-header h2 {
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--text-main);
  margin: 0;
  flex: 1;
  letter-spacing: -0.01em;
  display: flex;
  align-items: center;
  gap: 8px;
}
.btn-request-revision {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 6px 14px;
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 0.75rem;
  font-weight: 700;
  cursor: pointer;
  color: var(--text-muted);
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
}
.btn-request-revision:hover {
  background: rgba(245, 158, 11, 0.10);
  border-color: rgba(245, 158, 11, 0.4);
  color: #D97706;
  box-shadow: 0 2px 6px rgba(245, 158, 11, 0.15);
  transform: translateY(-1px);
}
.btn-request-revision.has-comment {
  background: rgba(245, 158, 11, 0.14);
  border-color: rgba(245, 158, 11, 0.4);
  color: #D97706;
  font-weight: 700;
}
.review-section-body {
  padding: 20px 22px;
}

/* ── Inline Comment Box ── */
.section-comment-box {
  display: none;
  background: var(--bg-base);
  border-top: 1px solid var(--border);
  padding: 16px 20px;
  gap: 12px;
}
.section-comment-box.open {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
  animation: commentSlideDown 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes commentSlideDown {
  from { opacity: 0; transform: translateY(-4px); }
  to { opacity: 1; transform: translateY(0); }
}
.section-comment-box textarea {
  flex: 1;
  min-width: 220px;
  min-height: 76px;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 10px 14px;
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 0.85rem;
  line-height: 1.45;
  resize: vertical;
  background: var(--bg-card);
  color: var(--text-main);
  transition: border-color 0.2s, box-shadow 0.2s;
  box-sizing: border-box;
}
.section-comment-box textarea:focus {
  outline: none;
  border-color: var(--sti-gold, #F59E0B);
  box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
}
.btn-save-comment {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-weight: 700;
  font-size: 0.78rem;
  padding: 8px 16px;
  border-radius: 8px;
  white-space: nowrap;
  background: var(--sti-gold, #F59E0B);
  color: #111;
  border: none;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.18s ease;
}
.btn-save-comment:hover {
  background: #D97706;
  color: #FFF;
  box-shadow: 0 2px 6px rgba(245, 158, 11, 0.25);
  transform: translateY(-1px);
}
.section-flagged-banner {
  background: rgba(245, 158, 11, 0.08);
  border-top: 1px solid rgba(245, 158, 11, 0.25);
  padding: 10px 20px;
  font-size: 0.82rem;
  font-weight: 500;
  color: #B45309;
  display: flex;
  align-items: center;
  gap: 8px;
}
.btn-remove-comment {
  background: none;
  border: none;
  cursor: pointer;
  color: #B45309 !important;
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 0.75rem;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 4px;
  transition: background 0.15s, color 0.15s;
}
.btn-remove-comment:hover {
  background: rgba(245, 158, 11, 0.15);
  color: #92400E !important;
}

/* ── Info Grid & Blocks ── */
.info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
}
.info-block {
  background: var(--bg-base);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px 16px;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}
.info-block:hover {
  border-color: var(--accent, var(--sti-blue));
}
.info-block .lbl {
  font-size: 0.70rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  font-weight: 700;
  margin-bottom: 4px;
}
.info-block .val {
  font-size: 0.90rem;
  font-weight: 600;
  color: var(--text-main);
  line-height: 1.45;
  word-break: break-word;
}

/* ── Flagged count badge ── */
.flagged-badge {
  background: rgba(245, 158, 11, 0.15);
  color: #B45309;
  border: 1px solid rgba(245, 158, 11, 0.35);
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  padding: 2px 9px;
  border-radius: 99px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  animation: badgePopIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
@keyframes badgePopIn {
  from { transform: scale(0.85); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

/* ── Table Styling ── */
.table-wrap {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.review-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.86rem;
  text-align: left;
}
.review-table th {
  background: var(--bg-base);
  padding: 12px 18px;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  border-bottom: 1.5px solid var(--border);
  white-space: nowrap;
}
.review-table td {
  padding: 13px 18px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
  color: var(--text-main);
  font-size: 0.86rem;
  transition: background 0.12s ease;
}
.review-table tbody tr:last-child td {
  border-bottom: none;
}
.review-table tbody tr:hover td {
  background: rgba(2, 132, 199, 0.03);
}

/* ── Action Modals ── */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.6);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  z-index: 200;
  align-items: center;
  justify-content: center;
  padding: 16px;
}
.modal-overlay.open {
  display: flex;
  animation: modalOverlayFade 0.2s ease-out;
}
@keyframes modalOverlayFade {
  from { opacity: 0; }
  to { opacity: 1; }
}
.modal {
  background: var(--bg-card);
  border-radius: var(--radius, 14px);
  padding: 26px 28px;
  max-width: 520px;
  width: 100%;
  box-shadow: var(--shadow-lg, 0 20px 40px rgba(0, 0, 0, 0.35));
  border: 1px solid var(--border);
  position: relative;
  animation: modalContentZoom 0.22s cubic-bezier(0.16, 1, 0.3, 1);
  box-sizing: border-box;
}
@keyframes modalContentZoom {
  from { transform: scale(0.96); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}
.modal h3 {
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--text-main);
  margin-bottom: 8px;
  letter-spacing: -0.01em;
}
.modal p {
  color: var(--text-muted);
  line-height: 1.5;
  font-size: 0.88rem;
}
.modal .form-label {
  font-weight: 600;
  font-size: 0.82rem;
  color: var(--text-main);
  margin-bottom: 6px;
  display: block;
}
.modal .form-control {
  width: 100%;
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 10px 14px;
  font-family: 'Plus Jakarta Sans', sans-serif !important;
  font-size: 0.85rem;
  background: var(--bg-base);
  color: var(--text-main);
  transition: border-color 0.2s, box-shadow 0.2s;
  box-sizing: border-box;
}
.modal .form-control:focus {
  outline: none;
  border-color: var(--accent, var(--sti-blue));
  box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15);
}
.modal .alert {
  border-radius: 8px;
  padding: 10px 14px;
  margin-bottom: 14px;
  font-size: 0.82rem;
  line-height: 1.45;
}
.modal .alert-warning {
  background: rgba(245, 158, 11, 0.12);
  border: 1px solid rgba(245, 158, 11, 0.35);
  color: #B45309;
}
.modal .alert-danger {
  background: rgba(239, 68, 68, 0.12);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #DC2626;
}
.modal .flex.gap-2 {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 20px;
}
#modal-return .modal { border-top: 4px solid var(--sti-gold, #F59E0B); }
#modal-reject .modal { border-top: 4px solid var(--danger, #EF4444); }
#modal-approve .modal,
#modal-forward .modal { border-top: 4px solid var(--accent, #0284C7); }

/* ── Section labels & text ── */
.sl {
  font-size: 0.70rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-muted);
  margin-bottom: 6px;
}
.review-section-body p {
  color: var(--text-main);
  line-height: 1.6;
  font-size: 0.88rem;
  margin-top: 0;
}

/* ── Budget total row ── */
.budget-total {
  display: flex;
  justify-content: flex-end;
  padding: 14px 18px;
  background: var(--bg-base);
  font-weight: 700;
  font-size: 0.92rem;
  color: var(--text-main);
  border-top: 1.5px solid var(--border);
}

.floor-badge {
  background: rgba(2, 132, 199, 0.10);
  color: var(--sti-blue, #0284C7);
  border: 1px solid rgba(2, 132, 199, 0.25);
  font-size: 0.75rem;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 6px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

/* ── AI Questions & Validation Styling ── */
.ai-questions-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 10px;
}
.ai-question-item {
  padding: 12px 16px !important;
  background: var(--bg-base) !important;
  border: 1px solid var(--border) !important;
  border-radius: 10px !important;
  display: flex;
  flex-direction: column;
  gap: 6px;
  box-shadow: none !important;
  transition: border-color 0.15s ease;
}
.ai-question-item:hover {
  border-color: var(--accent, var(--sti-blue)) !important;
}
.ai-question-item div[style*="color: var(--text"] {
  color: var(--text-main) !important;
}

/* AI Validation Card Overrides */
.ai-validation-card {
  border-radius: 14px !important;
  border: 1px solid var(--border) !important;
  background: var(--bg-card) !important;
  box-shadow: var(--shadow-sm) !important;
}
.ai-validation-card h3 {
  font-family: 'Plus Jakarta Sans', sans-serif !important;
}

/* ── Dark Mode Token Overrides & Contrast Protection ── */
[data-theme="dark"] .review-action-bar {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .review-action-bar .btn-outline {
  background: var(--bg-card);
  border-color: var(--border);
  color: var(--text-main);
}
[data-theme="dark"] .review-action-bar .btn-outline:hover {
  background: rgba(2, 132, 199, 0.15);
  border-color: var(--accent, var(--sti-blue));
  color: #38BDF8;
}
[data-theme="dark"] .review-action-bar button[onclick*="return"] {
  border-color: rgba(245, 158, 11, 0.35);
  color: #FBBF24;
  background: rgba(245, 158, 11, 0.12);
}
[data-theme="dark"] .review-action-bar button[onclick*="return"]:hover {
  background: rgba(245, 158, 11, 0.22);
  color: #FDE68A;
}
[data-theme="dark"] .review-action-bar .btn-danger {
  background: rgba(239, 68, 68, 0.2);
  border-color: rgba(239, 68, 68, 0.45);
  color: #FCA5A5;
}
[data-theme="dark"] .review-action-bar .btn-danger:hover {
  background: rgba(239, 68, 68, 0.3);
  color: #FFF;
}
[data-theme="dark"] .review-action-bar .btn-success {
  background: rgba(22, 163, 74, 0.25);
  border-color: rgba(34, 197, 94, 0.45);
  color: #86EFAC;
}
[data-theme="dark"] .review-action-bar .btn-success:hover {
  background: rgba(22, 163, 74, 0.35);
  color: #FFF;
}
[data-theme="dark"] .review-section {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .review-section-header {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .review-section.flagged {
  border-color: var(--sti-gold, #F59E0B);
  box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.35);
}
[data-theme="dark"] .btn-request-revision {
  background: var(--bg-card);
  border-color: var(--border);
  color: var(--text-muted);
}
[data-theme="dark"] .btn-request-revision:hover {
  background: rgba(245, 158, 11, 0.18);
  border-color: rgba(245, 158, 11, 0.5);
  color: #FBBF24;
}
[data-theme="dark"] .btn-request-revision.has-comment {
  background: rgba(245, 158, 11, 0.2);
  border-color: rgba(245, 158, 11, 0.5);
  color: #FBBF24;
}
[data-theme="dark"] .section-flagged-banner {
  background: rgba(245, 158, 11, 0.14);
  border-color: rgba(245, 158, 11, 0.3);
  color: #FBBF24;
}
[data-theme="dark"] .btn-remove-comment {
  color: #FBBF24 !important;
}
[data-theme="dark"] .btn-remove-comment:hover {
  background: rgba(245, 158, 11, 0.25);
  color: #FDE68A !important;
}
[data-theme="dark"] .flagged-badge {
  background: rgba(245, 158, 11, 0.2);
  color: #FBBF24;
  border-color: rgba(245, 158, 11, 0.4);
}
[data-theme="dark"] .info-block {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .review-table th {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .review-table td {
  border-color: var(--border);
}
[data-theme="dark"] .review-table tbody tr:hover td {
  background: rgba(255, 255, 255, 0.02);
}
[data-theme="dark"] .budget-total {
  background: var(--bg-base);
  border-color: var(--border);
  color: var(--text-main);
}
[data-theme="dark"] .floor-badge {
  background: rgba(56, 189, 248, 0.12);
  color: #38BDF8;
  border-color: rgba(56, 189, 248, 0.25);
}
[data-theme="dark"] .section-comment-box {
  background: var(--bg-base);
  border-color: var(--border);
}
[data-theme="dark"] .section-comment-box textarea {
  background: var(--bg-card);
  border-color: var(--border);
  color: var(--text-main);
}
[data-theme="dark"] .modal {
  background: var(--bg-card);
  border-color: var(--border);
}
[data-theme="dark"] .modal .form-control {
  background: var(--bg-base);
  border-color: var(--border);
  color: var(--text-main);
}
[data-theme="dark"] .modal .alert-warning {
  background: rgba(245, 158, 11, 0.18);
  border-color: rgba(245, 158, 11, 0.4);
  color: #FBBF24;
}
[data-theme="dark"] .modal .alert-danger {
  background: rgba(239, 68, 68, 0.18);
  border-color: rgba(239, 68, 68, 0.4);
  color: #F87171;
}
[data-theme="dark"] .ai-question-item {
  background: var(--bg-base) !important;
  border-color: var(--border) !important;
}
[data-theme="dark"] .ai-question-item * {
  color: var(--text-main);
}
[data-theme="dark"] .ai-question-item span[style*="color: var(--text-muted"] {
  color: var(--text-muted) !important;
}
[data-theme="dark"] .ai-validation-card {
  background: var(--bg-card) !important;
  border-color: var(--border) !important;
}
[data-theme="dark"] .ai-validation-card div[style*="color: #1E293B"] {
  color: var(--text-main) !important;
}
[data-theme="dark"] .ai-validation-card div[style*="color: #475569"] {
  color: var(--text-muted) !important;
}
[data-theme="dark"] .ai-validation-card ul[style*="color: #334155"] {
  color: var(--text-main) !important;
}
[data-theme="dark"] .ai-validation-card div[style*="background: #FEF3C7"] {
  background: rgba(245, 158, 11, 0.14) !important;
  border-color: rgba(245, 158, 11, 0.35) !important;
  color: #FBBF24 !important;
}

/* ── Responsive adjustments ── */
@media (max-width: 768px) {
  .review-action-bar { padding: 12px 16px; top: 64px; }
  .review-section-header { padding: 12px 16px; }
  .review-section-body { padding: 16px; }
  .info-grid { grid-template-columns: 1fr; }
}

/* ── Print styles ── */
@media print {
  .no-print, .sidebar, .topbar, .review-action-bar, .section-comment-box, .btn-request-revision {
    display: none !important;
  }
  .main-wrap {
    margin-left: 0 !important;
    width: 100% !important;
  }
  .content {
    padding: 0 !important;
  }
  .review-section {
    box-shadow: none !important;
    border: 1px solid #ccc !important;
  }
}
</style>
</head>
<body class="theme-arjay">
<?php include __DIR__ . '/../includes/sidebar.php'; ?>
<div class="main-wrap">

  <!-- Shared Topbar -->
  <header class="topbar">
    <div class="page-title">Review Proposal</div>
    <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
      <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
      <!-- User Profile Control -->
      <?php include __DIR__ . '/../includes/topbar-profile.php'; ?>
    </div>
  </header>

  <!-- Sticky Action Bar -->
  <div class="review-action-bar">
    <div class="proposal-meta">
      <div class="proposal-title"><?= htmlspecialchars($activity['title']) ?></div>
      <div class="text-sm text-muted">
        by <?= htmlspecialchars($activity['faculty_name']) ?>
        · Submitted <?= $activity['submitted_at'] ? date('M j, Y', strtotime($activity['submitted_at'])) : '—' ?>
        · <?= getStatusBadge($activity['status']) ?>
        <?php if ($stagedCount > 0): ?>
          &nbsp;<span class="flagged-badge"><?= $stagedCount ?> section<?= $stagedCount > 1 ? 's' : '' ?> flagged</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="flex gap-2">
      <a href="<?= BASE_URL ?>/admin1/dashboard.php" class="btn btn-outline btn-sm">← Back</a>
      <button class="btn btn-outline" onclick="openModal('return')">↩ Return for Revision</button>
      <button class="btn btn-danger"  onclick="openModal('reject')">✕ Reject</button>
      <button class="btn btn-success" onclick="openModal('forward')">✓ Approve &amp; Forward to Sir Ian</button>
    </div>
  </div>

  <div class="content">

    <!-- ── SECTION 1: Event Details ── -->
    <?php renderSectionHeader('event_details', '📋 Event Details', $stagedComments); ?>
      <div class="info-grid" style="margin-bottom:14px;">
        <div class="info-block"><div class="lbl">Event Title</div><div class="val"><?= htmlspecialchars($activity['title']) ?></div></div>
        <div class="info-block"><div class="lbl">Event Date</div><div class="val"><?= $activity['event_date'] ? date('F j, Y', strtotime($activity['event_date'])) : '—' ?></div></div>
        <div class="info-block"><div class="lbl">Time</div><div class="val"><?= $activity['start_time'] ? date('g:i A', strtotime($activity['start_time'])) : '—' ?> – <?= $activity['end_time'] ? date('g:i A', strtotime($activity['end_time'])) : '—' ?></div></div>
        <div class="info-block"><div class="lbl">Venue</div><div class="val"><?= htmlspecialchars($activity['venue'] ?? '—') ?></div></div>
        <div class="info-block"><div class="lbl">Venue Address</div><div class="val"><?= htmlspecialchars($activity['venue_address'] ?? '—') ?></div></div>
        <div class="info-block"><div class="lbl">Target Participants</div><div class="val"><?= number_format($activity['target_participants']) ?></div></div>
        <div class="info-block"><div class="lbl">Source</div><div class="val"><?= ucfirst(str_replace('_',' ',$activity['source'])) ?></div></div>
        <div class="info-block"><div class="lbl">Theme</div><div class="val"><?= htmlspecialchars($activity['theme'] ?? '—') ?></div></div>
      </div>
    <?php renderSectionClose('event_details', $stagedComments, $id); ?>

    <!-- ── SECTION 2: Objectives ── -->
    <?php renderSectionHeader('objectives', '🎯 Objectives & Academic Alignment', $stagedComments); ?>
      <div class="sl">General Objectives</div>
      <p style="margin-bottom:14px;"><?= nl2br(htmlspecialchars($activity['general_objectives'] ?? '—')) ?></p>
      <div class="sl">Specific Objectives</div>
      <p><?= nl2br(htmlspecialchars($activity['specific_objectives'] ?? '—')) ?></p>
      <?php if (!empty($activity['involved_subjects'])): ?>
      <div class="sl" style="margin-top:14px;">Involved Subjects</div>
      <p><?= htmlspecialchars($activity['involved_subjects']) ?></p>
      <?php endif; ?>
      <?php if (!empty($activity['rationale'])): ?>
      <div class="sl" style="margin-top:14px;">Rationale</div>
      <p><?= nl2br(htmlspecialchars($activity['rationale'])) ?></p>
      <?php endif; ?>
    <?php renderSectionClose('objectives', $stagedComments, $id); ?>

    <!-- ── SECTION 3: Materials & Budget ── -->
    <?php renderSectionHeader('materials', '💰 Materials & Budget', $stagedComments); ?>
      <?php if (!empty($mats)): ?>
        <div class="table-wrap">
          <table class="review-table">
            <thead><tr><th>Item</th><th>Description</th><th style="text-align:center;">Qty</th><th>Provider</th><th style="text-align:right;">Est. Cost</th></tr></thead>
            <tbody>
              <?php foreach ($mats as $m): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($m['item_name']) ?></td>
                <td style="color:var(--text-muted);"><?= htmlspecialchars($m['description'] ?? '—') ?></td>
                <td style="text-align:center; font-weight:600;"><?= $m['quantity'] ?></td>
                <td><?= htmlspecialchars($m['provider'] ?? '—') ?></td>
                <td style="text-align:right; font-weight:600; color:var(--text-main);">₱<?= number_format($m['est_cost'], 2) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="budget-total">Grand Total: ₱<?= number_format($totalBudget, 2) ?></div>
      <?php else: ?><p class="text-muted">No materials listed.</p><?php endif; ?>
    <?php renderSectionClose('materials', $stagedComments, $id); ?>

    <!-- ── SECTION 4: Floor Plan ── -->
    <?php renderSectionHeader('floor_plan', '🗺️ Floor Plan', $stagedComments); ?>
      <?php if ($fp): ?>
        <?php
          $floorsList = [];
          if (!empty($fp['canvas_json'])) {
              $parsedFp = json_decode($fp['canvas_json'], true);
              if ($parsedFp && !empty($parsedFp['floors'])) {
                  foreach ($parsedFp['floors'] as $fKey => $fVal) {
                      $floorsList[] = $fVal['name'] ?? ucfirst($fKey) . ' Floor';
                  }
              }
          }
          if (empty($floorsList) && (!empty($fp['file_path']) || !empty($fp['canvas_json']))) {
              $floorsList = ['Ground Floor'];
          }
        ?>
        <?php if (!empty($floorsList)): ?>
          <div style="margin-bottom:12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <span style="font-size:0.8rem; font-weight:600; color:var(--text-muted);">Included Floors:</span>
            <?php foreach ($floorsList as $flName): ?>
              <span class="floor-badge">🏢 <?= htmlspecialchars($flName) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="sl">Floor Plan File</div>
        <?php $ext = strtolower(pathinfo($fp['file_path'] ?? '', PATHINFO_EXTENSION)); $isImg = in_array($ext,['png','jpg','jpeg','gif','webp']); ?>
        <?php $fpUrl = !empty($fp['file_path']) ? (str_starts_with($fp['file_path'], 'http') ? $fp['file_path'] : BASE_URL . '/' . $fp['file_path']) : ''; ?>
        <?php if($isImg && $fpUrl): ?>
        <div style="text-align:center; padding:16px; background:var(--bg-base); border-radius:10px; border:1px solid var(--border); margin-bottom:12px;">
          <img src="<?= htmlspecialchars($fpUrl) ?>" style="max-width:100%; max-height:480px; border-radius:8px; object-fit:contain;" alt="Floor Plan">
        </div>
        <?php elseif($fpUrl): ?>
        <a href="<?= htmlspecialchars($fpUrl) ?>" target="_blank" class="btn btn-outline btn-sm" style="margin-bottom:12px;">📎 View Floor Plan File</a>
        <?php endif; ?>
        <?php if (!empty($fp['notes'])): ?>
          <div class="sl" style="margin-top:12px;">Notes</div>
          <p><?= nl2br(htmlspecialchars($fp['notes'])) ?></p>
        <?php endif; ?>
        <?php if (!empty($fp['canvas_json'])): ?>
          <div class="sl" style="margin-top:12px;">Interactive Layout Data</div>
          <p class="text-muted text-sm">Canvas layout saved (<?= strlen($fp['canvas_json']) ?> bytes)</p>
        <?php endif; ?>
      <?php else: ?><p class="text-muted">No floor plan uploaded.</p><?php endif; ?>
    <?php renderSectionClose('floor_plan', $stagedComments, $id); ?>

    <!-- ── SECTION 5: People & Manpower ── -->
    <?php renderSectionHeader('people', '👥 People & Manpower', $stagedComments); ?>
      <?php if (!empty($mp)): ?>
        <div class="table-wrap">
          <table class="review-table">
            <thead><tr><th>Role</th><th>Assigned Person</th><th>Type</th></tr></thead>
            <tbody>
              <?php foreach ($mp as $m): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($m['role']) ?></td>
                <td><?= htmlspecialchars($m['assigned_person'] ?? '—') ?></td>
                <td><span class="badge badge-secondary"><?= ucfirst($m['type']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?><p class="text-muted">No manpower assigned.</p><?php endif; ?>
    <?php renderSectionClose('people', $stagedComments, $id); ?>

    <!-- ── SECTION 6: Schedule ── -->
    <?php renderSectionHeader('schedule', '📅 Schedule', $stagedComments); ?>
      <?php if (!empty($scheds)): ?>
        <div class="table-wrap">
          <table class="review-table">
            <thead><tr><th>Date</th><th>Event</th><th>Venue</th><th>Organizer</th></tr></thead>
            <tbody>
              <?php foreach ($scheds as $s): ?>
              <tr>
                <td><span class="badge badge-outline"><?= $s['sched_date'] ? date('M j, Y', strtotime($s['sched_date'])) : '—' ?></span></td>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($s['event_name']) ?></td>
                <td><?= htmlspecialchars($s['venue'] ?? '—') ?></td>
                <td><?= htmlspecialchars($s['organizer'] ?? '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?><p class="text-muted">No schedule entries.</p><?php endif; ?>

      <?php if (!empty($prog)): ?>
        <div class="sl" style="margin-top:18px; margin-bottom:10px;">Program Sequence</div>
        <div class="table-wrap">
          <table class="review-table">
            <thead><tr><th style="width:18%;">Time</th><th style="width:25%;">Segment</th><th style="width:35%;">Description</th><th style="width:22%;">Person In-Charge</th></tr></thead>
            <tbody>
              <?php foreach ($prog as $p): ?>
              <tr>
                <td><span class="badge badge-outline"><?= $p['time_slot'] ? date('g:i A', strtotime($p['time_slot'])) : '—' ?></span></td>
                <td><strong style="color:var(--text-main); font-weight:600;"><?= htmlspecialchars($p['segment']) ?></strong></td>
                <td style="color:var(--text-muted);"><?= htmlspecialchars($p['description'] ?? '—') ?></td>
                <td><?= htmlspecialchars($p['person_ic'] ?? '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php renderSectionClose('schedule', $stagedComments, $id); ?>

    <!-- ── SECTION 7: Guidelines ── -->
    <?php renderSectionHeader('guidelines', '📎 Guidelines', $stagedComments); ?>
      <?php if ($guide): ?>
        <div class="info-grid" style="grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));">
          <?php foreach([
            'Mechanics / Guidelines' => $guide['mechanics'],
            'Criteria for Judging'   => $guide['criteria'],
            'Scoring System'         => $guide['scoring_system'],
            'Special Awards'         => $guide['special_awards']
          ] as $glLbl => $glVal): if(!$glVal) continue; ?>
          <div class="info-block" style="margin-bottom:0;">
            <div class="lbl"><?= $glLbl ?></div>
            <div style="font-size:0.88rem; color:var(--text-main); line-height:1.55; margin-top:4px;"><?= nl2br(htmlspecialchars($glVal)) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?><p class="text-muted">No guidelines entered.</p><?php endif; ?>
    <?php renderSectionClose('guidelines', $stagedComments, $id); ?>

    <!-- ── SECTION 8: Faculty Tasks ── -->
    <?php renderSectionHeader('faculty_tasks', '📋 Faculty Tasks & Responsibilities', $stagedComments); ?>
      <?php if (!empty($ftasks)): ?>
        <div class="table-wrap">
          <table class="review-table">
            <thead><tr><th>Faculty Name</th><th>Assigned Task</th><th>Contribution</th><th>Role in Event</th></tr></thead>
            <tbody>
              <?php foreach ($ftasks as $f): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($f['faculty_name']) ?></td>
                <td><?= htmlspecialchars($f['assigned_task'] ?? '—') ?></td>
                <td style="color:var(--text-muted);"><?= htmlspecialchars($f['contribution_desc'] ?? '—') ?></td>
                <td><?= htmlspecialchars($f['role_in_event'] ?? '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?><p class="text-muted">No faculty tasks assigned.</p><?php endif; ?>
    <?php renderSectionClose('faculty_tasks', $stagedComments, $id); ?>

    <!-- ── SECTION 9: KPI ── -->
    <?php renderSectionHeader('kpi', '📊 KPI & Evaluation', $stagedComments); ?>
      <?php if (!empty($kpis)): ?>
        <div class="table-wrap">
          <table class="review-table">
            <thead><tr><th>Indicator</th><th>Target Metric</th><th>Evaluation Method</th></tr></thead>
            <tbody>
              <?php foreach ($kpis as $k): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($k['indicator'] ?? '—') ?></td>
                <td><?= htmlspecialchars($k['target_metric'] ?? '—') ?></td>
                <td><?= htmlspecialchars($k['evaluation_method'] ?? '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?><p class="text-muted">No KPI criteria defined.</p><?php endif; ?>
      <?php if (!empty($activity['evaluation_method'])): ?>
        <div class="sl" style="margin-top:16px;">Google Form Link</div>
        <a href="<?= htmlspecialchars($activity['evaluation_method']) ?>" target="_blank" style="color:var(--accent, var(--sti-blue)); text-decoration:none; font-weight:600; word-break:break-all;"><?= htmlspecialchars($activity['evaluation_method']) ?></a>
      <?php endif; ?>

      <?php if (!empty($activity['evaluation_questions'])): ?>
        <div style="margin-top:20px; border-top: 1px dashed var(--border); padding-top: 16px;">
          <div class="sl" style="font-weight:700; margin-bottom:10px; color:var(--text-main);">📋 AI-Generated Evaluation Tool</div>
          <?= renderAiEvaluationQuestions($activity['evaluation_questions']) ?>
        </div>
      <?php endif; ?>
    <?php renderSectionClose('kpi', $stagedComments, $id); ?>

    <!-- ── Approval History ── -->
    <?php if (!empty($history)): ?>
    <div class="review-section">
      <div class="review-section-header">
        <h2>🕓 Approval History</h2>
        <span class="badge badge-secondary"><?= count($history) ?> log<?= count($history)>1?'s':'' ?></span>
      </div>
      <div class="review-section-body" style="padding:0;">
        <div class="table-wrap">
          <table class="review-table">
            <thead><tr><th>Reviewer</th><th>Action</th><th>Notes</th><th>Date</th></tr></thead>
            <tbody><?php foreach ($history as $h): ?>
              <tr>
                <td style="font-weight:600; color:var(--text-main);"><?= htmlspecialchars($h['name']) ?></td>
                <td><?= getStatusBadge($h['action']) ?></td>
                <td style="color:var(--text-muted); font-size:0.85rem; font-style:italic;"><?= htmlspecialchars($h['notes'] ?? '—') ?></td>
                <td style="white-space:nowrap; font-size:0.82rem; color:var(--text-muted);"><?= date('M j, Y g:i A', strtotime($h['acted_at'])) ?></td>
              </tr>
            <?php endforeach; ?></tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- content -->
</div><!-- main-wrap -->

<!-- Action Modals -->
<?php foreach ([
  'forward' => ['Approve & Forward to Sir Ian', 'success', 'approved', false],
  'return'  => ['Return for Revision',          'warning', 'returned', false],
  'reject'  => ['Reject Proposal',              'danger',  'rejected', true],
] as $key => [$label, $cls, $action, $required]): ?>
<div class="modal-overlay" id="modal-<?= $key ?>">
  <div class="modal">
    <h3 style="margin-bottom:8px;"><?= $label ?></h3>
      <?php if ($key === 'return'): ?>
      <?php if ($stagedCount > 0): ?>
      <div class="alert alert-warning" style="margin-bottom:12px;font-size:.82rem;">
        <strong><?= $stagedCount ?> section<?= $stagedCount > 1 ? 's' : '' ?> flagged</strong> — these section-specific comments will be sent to the faculty.
      </div>
      <?php else: ?>
      <div class="alert alert-danger" style="margin-bottom:12px;font-size:.82rem;">
        You cannot return this proposal without adding a comment to the section you want the faculty to revise.
      </div>
      <?php endif; ?>
    <?php endif; ?>
    <p class="text-sm text-muted" style="margin-bottom:16px;">Activity: <strong><?= htmlspecialchars($activity['title']) ?></strong></p>
    <form method="POST" action="<?= BASE_URL ?>/api/review-action.php">
      <input type="hidden" name="activity_id" value="<?= $id ?>">
      <input type="hidden" name="reviewer_id" value="<?= $user['id'] ?>">
      <input type="hidden" name="action" value="<?= $action ?>">
      <input type="hidden" name="role" value="arjay">
      <?php if ($action === 'rejected'): ?>
      <div class="form-group">
        <label class="form-label">Notes / Comments <?= $required ? '*' : '' ?></label>
        <textarea name="notes" class="form-control" <?= $required ? 'required' : '' ?> rows="4" placeholder="Add your review notes..."></textarea>
      </div>
      <?php elseif ($action === 'returned'): ?>
        <p class="text-sm" style="margin-bottom:16px;">Are you sure you want to return this proposal for revision?</p>
      <?php else: ?>
        <p class="text-sm" style="margin-bottom:16px;">Are you sure you want to approve this proposal? No comment is required.</p>
      <?php endif; ?>
      <div class="flex gap-2" style="justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeModal('<?= $key ?>')">Cancel</button>
        <?php if ($key === 'return' && $stagedCount === 0): ?>
        <button type="button" class="btn btn-<?= $cls ?>" disabled style="opacity: 0.5; cursor: not-allowed;"><?= $label ?></button>
        <?php else: ?>
        <button type="submit" class="btn btn-<?= $cls ?>"><?= $label ?></button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<script>
const BASE_URL = '<?= BASE_URL ?>';
const ACTIVITY_ID = <?= $id ?>;

// ── Modal helpers ──
function openModal(k) { document.getElementById('modal-'+k).classList.add('open'); }
function closeModal(k) { document.getElementById('modal-'+k).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', e => { if (e.target===m) m.classList.remove('open'); }));

// ── Section comment toggling ──
document.querySelectorAll('.btn-request-revision').forEach(btn => {
  btn.addEventListener('click', () => {
    const key = btn.dataset.section;
    const box = document.getElementById('cbox-' + key);
    box.classList.toggle('open');
    if (box.classList.contains('open')) {
      box.querySelector('textarea').focus();
    }
  });
});

// ── Save comment ──
document.querySelectorAll('.btn-save-comment').forEach(btn => {
  btn.addEventListener('click', () => {
    const key      = btn.dataset.section;
    const textarea = document.getElementById('ctxt-' + key);
    const comment  = textarea.value.trim();
    if (!comment) { textarea.focus(); return; }

    fetch(BASE_URL + '/api/section-comment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'save', activity_id: ACTIVITY_ID, section_key: key, comment })
    })
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      markSectionFlagged(key, comment);
      // Update flagged count badge
      updateFlaggedBadge();
    });
  });
});

// ── Remove comment ──
document.querySelectorAll('.btn-remove-comment').forEach(btn => {
  btn.addEventListener('click', () => {
    const key = btn.dataset.section;
    if (!confirm('Remove this section comment?')) return;
    fetch(BASE_URL + '/api/section-comment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'delete', activity_id: ACTIVITY_ID, section_key: key })
    })
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      clearSectionFlagged(key);
      updateFlaggedBadge();
    });
  });
});

function markSectionFlagged(key, comment) {
  const card    = document.getElementById('section-card-' + key);
  const revBtn  = document.getElementById('revbtn-' + key);
  const banner  = document.getElementById('flagged-banner-' + key);
  const bannerText = document.getElementById('flagged-text-' + key);
  card.classList.add('flagged');
  revBtn.classList.add('has-comment');
  revBtn.innerHTML = '✏️ Comment Added';
  if (banner) {
    banner.style.display = 'flex';
    if (bannerText) bannerText.textContent = comment;
  }
  // Close the box
  document.getElementById('cbox-' + key)?.classList.remove('open');
}

function clearSectionFlagged(key) {
  const card    = document.getElementById('section-card-' + key);
  const revBtn  = document.getElementById('revbtn-' + key);
  const banner  = document.getElementById('flagged-banner-' + key);
  const textarea = document.getElementById('ctxt-' + key);
  card.classList.remove('flagged');
  revBtn.classList.remove('has-comment');
  revBtn.innerHTML = '📝 Request Revision';
  if (banner) banner.style.display = 'none';
  if (textarea) textarea.value = '';
}

function updateFlaggedBadge() {
  const flagged = document.querySelectorAll('.review-section.flagged').length;
  let badge = document.querySelector('.flagged-badge');
  const metaDiv = document.querySelector('.proposal-meta .text-sm');
  if (flagged > 0) {
    if (!badge) {
      badge = document.createElement('span');
      badge.className = 'flagged-badge';
      metaDiv.appendChild(document.createTextNode('\u00a0'));
      metaDiv.appendChild(badge);
    }
    badge.textContent = flagged + ' section' + (flagged>1?'s':'') + ' flagged';
  } else if (badge) {
    badge.previousSibling?.nodeType === 3 && badge.previousSibling.remove();
    badge.remove();
  }

  // Toggle return modal state
  const returnModal = document.getElementById('modal-return');
  if (returnModal) {
    const alertBox = returnModal.querySelector('.alert');
    const returnBtn = returnModal.querySelector('form .flex button:last-child');
    if (flagged > 0) {
      if (alertBox) {
        alertBox.className = 'alert alert-warning';
        alertBox.style.marginBottom = '12px';
        alertBox.style.fontSize = '.82rem';
        alertBox.innerHTML = `<strong>${flagged} section${flagged>1?'s':''} flagged</strong> — these section-specific comments will be sent to the faculty.`;
      }
      if (returnBtn) {
        returnBtn.disabled = false;
        returnBtn.style.opacity = '1';
        returnBtn.style.cursor = 'pointer';
        returnBtn.type = 'submit';
      }
    } else {
      if (alertBox) {
        alertBox.className = 'alert alert-danger';
        alertBox.style.marginBottom = '12px';
        alertBox.style.fontSize = '.82rem';
        alertBox.innerHTML = 'You cannot return this proposal without adding a comment to the section you want the faculty to revise.';
      }
      if (returnBtn) {
        returnBtn.disabled = true;
        returnBtn.style.opacity = '0.5';
        returnBtn.style.cursor = 'not-allowed';
        returnBtn.type = 'button';
      }
    }
  }
}
</script>
</body>
</html>

<?php
// ── Helpers ──────────────────────────────────────────────────────────────────
function renderSectionHeader(string $key, string $title, array $staged): void {
    $hasCmt = isset($staged[$key]);
    $cardCls = $hasCmt ? ' flagged' : '';
    echo "<div class=\"review-section{$cardCls}\" id=\"section-card-{$key}\">";
    echo "<div class=\"review-section-header\">";
    echo "<h2>{$title}</h2>";
    $btnCls = $hasCmt ? ' has-comment' : '';
    $btnLabel = $hasCmt ? '✏️ Comment Added' : '📝 Request Revision';
    echo "<button class=\"btn-request-revision{$btnCls}\" id=\"revbtn-{$key}\" data-section=\"{$key}\">{$btnLabel}</button>";
    echo "</div>";
    // Pre-existing staged comment banner
    if ($hasCmt) {
        $cmt = htmlspecialchars($staged[$key]);
        echo "<div class=\"section-flagged-banner\" id=\"flagged-banner-{$key}\" style=\"display:flex;\">";
        echo "⚠️ <span id=\"flagged-text-{$key}\">{$cmt}</span>";
        echo "&nbsp;<button class=\"btn-remove-comment\" data-section=\"{$key}\" style=\"margin-left:auto;\">✕ Remove</button>";
        echo "</div>";
    } else {
        echo "<div class=\"section-flagged-banner\" id=\"flagged-banner-{$key}\" style=\"display:none;\">";
        echo "⚠️ <span id=\"flagged-text-{$key}\"></span>";
        echo "&nbsp;<button class=\"btn-remove-comment\" data-section=\"{$key}\" style=\"margin-left:auto;\">✕ Remove</button>";
        echo "</div>";
    }
    echo "<div class=\"review-section-body\">";
}

function renderSectionClose(string $key, array $staged, int $actId): void {
    $existing = htmlspecialchars($staged[$key] ?? '', ENT_QUOTES);
    echo "</div>"; // close review-section-body
    // Inline comment box
    echo "<div class=\"section-comment-box\" id=\"cbox-{$key}\">";
    echo "<textarea id=\"ctxt-{$key}\" placeholder=\"Describe what needs to be revised in this section...\" style=\"flex:1;\">{$existing}</textarea>";
    echo "<div style=\"display:flex;flex-direction:column;gap:6px;\">";
    echo "<button class=\"btn btn-sm btn-warning btn-save-comment\" data-section=\"{$key}\">💾 Save Comment</button>";
    echo "</div>";
    echo "</div>";
    echo "</div>"; // close review-section
}
?>
