<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty', 'program_head');
$user = currentUser();
$db = getDB();
$eligibleFacultyList = function_exists('getEligibleFacultyMembers') ? getEligibleFacultyMembers($db) : [];
$facultyUsersStmt = $db->query("SELECT id, name, department FROM users WHERE role = 'faculty' ORDER BY name ASC");
$facultyUsers = $facultyUsersStmt ? $facultyUsersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$facultyDepartmentGroups = [
  'THM Department' => [
    'Mr. Aldwin D. Alminar, LPT',
    'Ms. Andrea J. Gatbunton, LPT',
    'Ms. Divine Grace E. Ibarrola, LPT',
    'Ms. Mary Darlene A. Mariano, LPT',
    'Ms. Julieta Caridad C. Cruz',
    'Mr. Exequiel L. Magnipis',
    'Mr. Frankie Chiu, RN, MBA'
  ],
  'Information Technology Department' => [
    'Mr. Dave Lord M. Rubaya',
    'Ms. Michelle Carlos, MAEd, LPT',
    'Mr. Ar-Jay C. Agbayani',
    'Ms. Emilou G. Magnaye, LPT',
    'Mr. John Cedrick Agapito',
    'Mr. Jedekiah Veloso',
    'Mr. Shintaro Lagare',
    'Mr. Geo V. De Leon',
    'Ms. Johainna Kristel T. Montejo',
    'Mr. Felmar Solbita',
    'Mr. Michael André Sayson'
  ],
  'General Education Department' => [
    'Mr. Tristan S. Unabia',
    'Mr. Ian Jade C. Barangan, LPT',
    'Mr. Eulysses Neil Patrick B. Edquilag, LPT',
    'Mr. Gregg Anthony J. Rana',
    'Mr. Ricky E. Manliguis, LPT',
    'Ms. Regina Kate D. Dominguez, LPT',
    'Mr. Benedicto A. Arnoco, LPT',
    'Ms. Jennylyn Q. Abarra, MAEd PE, LPT',
    'Ms. Ruby Ann G. Dasalla, LPT',
    'Ms. Perine O. Dela Cruz, LPT'
  ]
];

if (!function_exists('renderFacultyTaskOptgroups')) {
  function renderFacultyTaskOptgroups(array $groups, array $facultyUsers = [], $selectedUserId = null, $selectedName = null): string {
    $simplify = function(string $n): string {
      $clean = preg_replace('/\b(Mr|Ms|Mrs|Dr|LPT|MAEd|PE|RN|MBA)\b\.?/i', '', $n);
      return preg_replace('/[^a-zA-Z0-9]/', '', strtolower($clean));
    };
    $html = '';
    foreach ($groups as $dept => $names) {
      $html .= '<optgroup label="' . htmlspecialchars($dept, ENT_QUOTES) . '">';
      foreach ($names as $facName) {
        $matchedId = '';
        $target = $simplify($facName);
        if (!empty($facultyUsers)) {
          foreach ($facultyUsers as $fu) {
            $uName = $fu['name'] ?? '';
            if (strcasecmp(trim($uName), trim($facName)) === 0 || ($target !== '' && $simplify($uName) === $target)) {
              $matchedId = (int)$fu['id'];
              break;
            }
          }
        }
        $isSel = false;
        if (!empty($selectedUserId) && $matchedId && (int)$selectedUserId === (int)$matchedId) {
          $isSel = true;
        } elseif (!empty($selectedName)) {
          $selTarget = $simplify($selectedName);
          if (strcasecmp(trim($selectedName), trim($facName)) === 0 || ($target !== '' && $selTarget === $target)) {
            $isSel = true;
          }
        }
        $selAttr = $isSel ? ' selected' : '';
        $valAttr = $matchedId ? (string)$matchedId : '';
        $html .= '<option value="' . htmlspecialchars($valAttr, ENT_QUOTES) . '" data-name="' . htmlspecialchars($facName, ENT_QUOTES) . '"' . $selAttr . '>' . htmlspecialchars($facName, ENT_QUOTES) . '</option>';
      }
      $html .= '</optgroup>';
    }
    return $html;
  }
}
$todayManila = date('Y-m-d');
$tomorrowManila = date('Y-m-d', strtotime('+1 day'));

// Initialize temporary workspace session token for proposal collaborator invitation
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (empty($_SESSION['proposal_create_token'])) {
  $_SESSION['proposal_create_token'] = bin2hex(random_bytes(16));
}
$sessionToken = $_SESSION['proposal_create_token'];
if (!isset($_SESSION['temp_collaborators'][$sessionToken]) || !is_array($_SESSION['temp_collaborators'][$sessionToken])) {
  $_SESSION['temp_collaborators'][$sessionToken] = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
<?php include __DIR__ . '/../includes/head-theme-init.php'; ?>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="base-url" content="<?= BASE_URL ?>">
  <meta name="current-user" data-id="<?= (int)$user['id'] ?>" data-role="<?= htmlspecialchars($user['role']) ?>" data-dept="<?= htmlspecialchars($user['dept'] ?? '') ?>">
  <title>New Proposal – STI Activity System</title>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/main.css">
  <style>
    .wizard-panel {
      display: none;
    }

    .wizard-panel.active {
      display: block;
    }

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
      border: 1px solid var(--border, rgba(0,0,0,.2));
      border-radius: var(--radius, 14px);
      outline: 0;
      color: var(--text-main);
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

    .nav-btns {
      display: flex;
      justify-content: space-between;
      margin-top: 24px;
    }

    /* ── Validation styles ── */
    .form-control.is-invalid {
      border-color: #dc3545 !important;
      box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.15);
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
      border-radius: var(--radius-sm, 6px);
      padding: 10px 14px;
      margin-bottom: 16px;
      color: var(--warning, #664d03);
      font-size: .85rem;
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
      max-width: 100%;
      overflow-x: auto;
      overflow-y: hidden;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
    }

    .wizard-step {
      background: var(--bg-card) !important;
      color: var(--text-muted) !important;
      border-right: 1px solid var(--border) !important;
      transition: background-color 0.2s ease, color 0.2s ease;
      white-space: nowrap;
      flex: 1 0 auto;
      min-width: 72px;
      box-sizing: border-box;
    }

    .wizard-step .step-num {
      background: var(--border) !important;
      color: var(--text-muted) !important;
    }

    .wizard-step.active {
      background: #E0F2FE !important;
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

    .wizard-step.locked {
      opacity: 0.45;
      cursor: not-allowed;
      pointer-events: none;
    }

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

    [data-theme="dark"] .wizard-step:not(.locked):hover {
      background: rgba(255, 255, 255, 0.03) !important;
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

    .flex {
      display: flex;
    }

    .gap-2 {
      gap: 8px;
    }

    .mt-4 {
      margin-top: 16px;
    }

    /* ── Venue Address Dropdown ── */
    .venue-address-wrapper {
      position: relative;
      width: 100%;
    }

    .venue-address-dropdown {
      position: absolute;
      top: calc(100% + 4px);
      left: 0;
      right: 0;
      background: var(--bg-card, #FFFFFF);
      border: 1px solid var(--border, #E2E8F0);
      border-radius: 8px;
      box-shadow: 0 4px 16px -2px rgba(15, 23, 42, 0.08), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
      z-index: 50;
      padding: 4px;
      display: none;
    }

    .venue-address-item {
      padding: 10px 14px;
      font-size: 0.85rem;
      line-height: 1.4;
      color: var(--text-secondary, #1E293B);
      border-radius: 6px;
      cursor: pointer;
      user-select: none;
      transition: background 0.15s ease, color 0.15s ease;
      font-family: inherit;
      font-weight: 500;
    }

    .venue-address-item:hover,
    .venue-address-item:focus {
      background: #EFF6FF;
      color: #0284C7;
      outline: none;
    }

    /* Dark Mode */
    [data-theme="dark"] .venue-address-dropdown,
    [data-theme="dark"] #venue_address_dropdown {
      background: #15243C;
      border-color: #1E2D45;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.4);
    }

    [data-theme="dark"] .venue-address-item,
    [data-theme="dark"] .venue-address-dropdown .venue-address-item,
    [data-theme="dark"] #venue_address_dropdown .venue-address-item {
      color: #F8FAFC;
    }

    [data-theme="dark"] .venue-address-item:hover,
    [data-theme="dark"] .venue-address-item:focus,
    [data-theme="dark"] .venue-address-dropdown .venue-address-item:hover,
    [data-theme="dark"] .venue-address-dropdown .venue-address-item:focus,
    [data-theme="dark"] #venue_address_dropdown .venue-address-item:hover,
    [data-theme="dark"] #venue_address_dropdown .venue-address-item:focus {
      background: #1E2D45;
      color: #38BDF8;
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
    .content input[type="url"],
    .content input[type="file"],
    .content select.form-control,
    .content select,
    .content textarea.form-control,
    .content textarea,
    .eval-question-card .form-control,
    .eval-question-card textarea,
    .eval-question-card select {
      background: #FFFFFF;
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
    [data-theme="dark"] .content input[type="url"],
    [data-theme="dark"] .content input[type="file"],
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

    [data-theme="dark"] .content input[type="date"]::-webkit-calendar-picker-indicator,
    [data-theme="dark"] .content input[type="time"]::-webkit-calendar-picker-indicator {
      cursor: pointer;
      opacity: 0.8;
    }

    /* ── Dynamic Tables in Wizard Steps ── */
    .dynamic-table {
      border: 1px solid var(--border);
      border-radius: var(--radius-sm, 8px);
      overflow-x: auto;
      overflow-y: hidden;
      -webkit-overflow-scrolling: touch;
      background: var(--bg-card);
      max-width: 100%;
    }

    .dynamic-table table {
      width: 100%;
      min-width: 580px;
    }

    .dynamic-table table th {
      background: var(--bg-base);
      color: var(--text-muted);
      border-bottom: 1px solid var(--border);
      font-size: 0.75rem;
      font-weight: 700;
      padding: 10px 12px;
      letter-spacing: 0.5px;
    }

    .dynamic-table table td {
      border-bottom: 1px solid var(--border);
      padding: 8px 10px;
      vertical-align: middle;
      background: var(--bg-card);
    }

    [data-theme="dark"] .dynamic-table table th {
      background: var(--bg-card-elevated, #15243C);
      color: var(--text-secondary, #CBD5E1);
      border-bottom-color: var(--border, #1E2D45);
    }

    [data-theme="dark"] .dynamic-table table td {
      background: var(--bg-card, #0F1B2E);
      border-bottom-color: var(--border, #1E2D45);
    }

    /* Target Participants Table & Header Controls */
    .target-participants-controls {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .total-participants-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 600;
      font-size: 0.875rem;
      color: var(--text-main, #1e293b);
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      padding: 6px 12px;
      border-radius: 6px;
    }
    .target-participants-panel .dynamic-table {
      background: #ffffff !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 8px !important;
    }
    .target-participants-panel .dynamic-table table {
      width: 100%;
      border-collapse: collapse;
      background: #ffffff !important;
    }
    .target-participants-panel .dynamic-table table th {
      background: #f8fafc !important;
      color: #475569 !important;
      border-bottom: 1px solid #e2e8f0 !important;
      font-size: 0.8rem !important;
      font-weight: 600 !important;
      padding: 10px 12px !important;
      text-transform: none !important;
      letter-spacing: normal !important;
    }
    .target-participants-panel .dynamic-table table td {
      background: #ffffff !important;
      border-bottom: 1px solid #f1f5f9 !important;
      padding: 8px 12px !important;
      vertical-align: middle;
    }
    [data-theme="dark"] .target-participants-panel .dynamic-table {
      background: var(--bg-card, #0F1B2E) !important;
      border-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] .target-participants-panel .dynamic-table table {
      background: var(--bg-card, #0F1B2E) !important;
    }
    [data-theme="dark"] .target-participants-panel .dynamic-table table th {
      background: var(--bg-card-elevated, #15243C) !important;
      color: var(--text-secondary, #CBD5E1) !important;
      border-bottom-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] .target-participants-panel .dynamic-table table td {
      background: var(--bg-card, #0F1B2E) !important;
      border-bottom-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] .total-participants-badge {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
      color: var(--text-main, #F8FAFC) !important;
    }

    /* ── Activity Evaluation and KPI Measurement Styles ── */
    .kpi-component-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 20px;
      transition: box-shadow 0.2s ease;
    }
    .kpi-component-card .dynamic-table {
      background: #ffffff !important;
      border: 1px solid #e2e8f0 !important;
      border-radius: 8px !important;
      overflow-x: auto;
    }
    .kpi-component-card .dynamic-table table {
      width: 100%;
      border-collapse: collapse;
      background: #ffffff !important;
      margin: 0;
    }
    .kpi-component-card .dynamic-table table th {
      background: #f8fafc !important;
      color: #475569 !important;
      border-bottom: 1px solid #e2e8f0 !important;
      font-size: 0.8rem !important;
      font-weight: 600 !important;
      padding: 10px 12px !important;
      text-transform: none !important;
    }
    .kpi-component-card .dynamic-table table td {
      background: #ffffff !important;
      border-bottom: 1px solid #f1f5f9 !important;
      padding: 8px 12px !important;
      vertical-align: middle;
    }
    .kpi-legend-card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 16px;
    }
    [data-theme="dark"] .kpi-component-card {
      background: var(--bg-card, #0F1B2E) !important;
      border-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] .kpi-component-card h3 {
      color: var(--text-main, #F8FAFC) !important;
    }
    [data-theme="dark"] .kpi-component-card .dynamic-table {
      background: var(--bg-card, #0F1B2E) !important;
      border-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] .kpi-component-card .dynamic-table table {
      background: var(--bg-card, #0F1B2E) !important;
    }
    [data-theme="dark"] .kpi-component-card .dynamic-table table th {
      background: var(--bg-card-elevated, #15243C) !important;
      color: var(--text-secondary, #CBD5E1) !important;
      border-bottom-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] .kpi-component-card .dynamic-table table td {
      background: var(--bg-card, #0F1B2E) !important;
      border-bottom-color: var(--border, #1E2D45) !important;
      color: var(--text-main, #F8FAFC) !important;
    }
    [data-theme="dark"] .kpi-legend-card {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
    }
    [data-theme="dark"] .kpi-legend-card div {
      color: var(--text-main, #F8FAFC) !important;
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
      box-shadow: var(--shadow-sm);
      border-color: var(--sti-blue, #0284c7);
    }

    [data-theme="dark"] .eval-question-row {
      background: var(--bg-card-elevated, #15243C);
      border-color: var(--border, #1E2D45);
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
      border-color: var(--sti-blue, #0284c7) !important;
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
      display: block;
      width: 100%;
    }

    @media (min-width: 992px) {
      .eval-question-row {
        grid-template-areas: "qtext qtype qcat qdel";
        grid-template-columns: 1fr 140px 240px auto;
      }
      .eval-question-row .eval-question-delete {
        width: auto;
      }
      .eval-delete-btn {
        padding: 6px 8px;
        border: none;
      }
      .eval-delete-btn:hover {
        background-color: #ffe8e8;
      }
      .eval-delete-btn .delete-text {
        display: none;
      }
    }

    @media (max-width: 1200px) {
      .proposal-layout-grid {
        width: 100%;
      }
    }

        @media (max-width: 768px) {
      .form-grid,
      .form-grid.three {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
      }
      .col-span-2,
      .col-span-3 {
        grid-column: span 1 !important;
      }
      .wizard-steps {
        gap: 0 !important;
      }
      .wizard-step {
        min-width: 68px !important;
        padding: 8px 4px !important;
        font-size: 0.65rem !important;
      }
      .wizard-step .step-num {
        width: 22px !important;
        height: 22px !important;
        font-size: 0.65rem !important;
      }
      .proposal-layout-grid {
        grid-template-columns: 1fr !important;
      }
    }

    @media (max-width: 640px) {
      .nav-btns {
        flex-direction: column-reverse !important;
        gap: 10px !important;
      }
      .nav-btns > div,
      .nav-btns > div:last-child {
        width: 100% !important;
        display: flex !important;
        flex-direction: column !important;
        gap: 8px !important;
      }
      .nav-btns button,
      .nav-btns a.btn {
        width: 100% !important;
        text-align: center !important;
        justify-content: center !important;
        padding: 10px 16px !important;
      }
      #addCollaboratorForm {
        grid-template-columns: 1fr !important;
      }
      #addCollaboratorForm button {
        width: 100% !important;
      }
    }

    @media (max-width: 480px) {
      .topbar .page-title {
        font-size: 0.90rem;
      }
      .topbar-right .btn-sm {
        padding: 4px 8px;
        font-size: 0.72rem;
      }
      .card-header {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 8px !important;
      }
      .card-header .btn {
        width: 100% !important;
      }
    }

    @media (max-width: 360px) {
      .wizard-step {
        min-width: 58px !important;
        padding: 6px 2px !important;
        font-size: 0.6rem !important;
      }
      .wizard-step .step-num {
        width: 20px !important;
        height: 20px !important;
      }
    }
      .topbar-right .btn-sm {
        padding: 4px 8px;
        font-size: 0.72rem;
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
      background: var(--bg-card);
      border-bottom: 1px solid var(--border);
      padding: 14px 18px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 0;
    }

    .proposal-eval-side-panel .card-header h2 {
      font-size: 0.95rem;
      font-weight: 700;
      color: var(--text-main);
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

    .info-alert,
    .proposal-eval-side-panel .info-alert {
      font-size: 0.85rem;
      padding: 12px 16px;
      background: #f0f9ff;
      border: 1px solid #bae6fd;
      color: #0369a1;
      border-radius: var(--radius-sm, 8px);
      line-height: 1.45;
      margin-bottom: 4px;
    }

    [data-theme="dark"] .info-alert,
    [data-theme="dark"] .proposal-eval-side-panel .info-alert {
      background: rgba(2, 132, 199, 0.12) !important;
      border-color: rgba(2, 132, 199, 0.28) !important;
      color: #38bdf8 !important;
    }

    .btn-add-manual-q,
    .proposal-eval-side-panel .btn-add-manual-q {
      border: 1px dashed var(--border);
      font-size: 0.85rem;
      padding: 9px 16px;
      border-radius: var(--radius-sm, 8px);
      background: var(--bg-base);
      color: var(--text-main);
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      width: auto;
      height: auto;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-add-manual-q:hover,
    .proposal-eval-side-panel .btn-add-manual-q:hover {
      border-color: var(--sti-blue);
      color: var(--sti-blue);
      background: rgba(2, 132, 199, 0.08);
    }

    [data-theme="dark"] .btn-add-manual-q,
    [data-theme="dark"] .proposal-eval-side-panel .btn-add-manual-q {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
      color: var(--text-main, #F8FAFC) !important;
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

    [data-theme="dark"] .proposal-eval-side-panel .btn-add-manual-q:hover {
      border-color: var(--sti-blue-hover, #38bdf8) !important;
      color: var(--sti-blue-hover, #38bdf8) !important;
      background: rgba(2, 132, 199, 0.15) !important;
    }

    .proposal-eval-side-panel .footer-actions {
      margin-top: 10px;
      padding-top: 12px;
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      gap: 8px;
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

    [data-theme="dark"] #previewModal .modal-body > div:nth-child(2) {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
    }

    [data-theme="dark"] #previewModalActivityTitle {
      color: var(--text-main, #F8FAFC) !important;
    }

    [data-theme="dark"] #preview-questions-list > div {
      background: var(--bg-card-elevated, #15243C) !important;
      border-color: var(--border, #1E2D45) !important;
    }

    [data-theme="dark"] #preview-questions-list > div > div:first-child {
      color: var(--text-main, #F8FAFC) !important;
    }

    [data-theme="dark"] #preview-questions-list .form-control {
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

    [data-theme="dark"] #scheduleConflictModal .modal-footer {
      background: var(--bg-card, #0F1B2E) !important;
    }
    [data-theme="dark"] #scheduleConflictModal #scuConfTitle {
      color: #F8FAFC !important;
    }
    [data-theme="dark"] #scheduleConflictModal [style*="color: #4a5568"],
    [data-theme="dark"] #scheduleConflictModal [style*="color:#4a5568"] {
      color: #CBD5E1 !important;
    }
    [data-theme="dark"] #scheduleConflictModal .modal-body div[style*="background: #fff"],
    [data-theme="dark"] #scheduleConflictModal .modal-body div[style*="background:#fff"] {
      background: var(--bg-card, #0F1B2E) !important;
      border-color: var(--border, #1E2D45) !important;
      color: var(--text-main, #F8FAFC) !important;
    }
    [data-theme="dark"] #scheduleConflictModal .modal-footer {
      background: var(--bg-card, #0F1B2E) !important;
      border-top: 1px solid var(--border, #1E2D45) !important;
    }
    </style>
  <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/floorplan.css?v=<?= filemtime(__DIR__ . '/../assets/css/floorplan.css') ?>">
</head>

<body class="theme-faculty">
  <?php include __DIR__ . '/../includes/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <div class="page-title" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
        <span>New Activity Proposal</span>
      </div>
      <div class="topbar-right" style="display:flex;align-items:center;gap:10px;">
        <span id="offlineSyncBadge" class="badge" style="font-size:0.75rem;padding:5px 10px;border-radius:12px;background:#10B981;color:#fff;display:inline-flex;align-items:center;gap:5px;font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,0.1);"><span class="sync-icon">✓</span> <span class="sync-text">Synchronized</span></span>
        <button type="button" id="btnManualSync" class="btn btn-outline btn-sm" style="display:none;font-size:0.75rem;padding:4px 8px;">Sync Now</button>
        <a href="<?= BASE_URL ?>/faculty/dashboard.php" class="btn btn-outline btn-sm">← Back</a>
        <?php include __DIR__ . '/../includes/notification-topbar-widget.php'; ?>
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
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_floor_plan_format'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Invalid Floor Plan File:</strong> The uploaded floor plan must be a valid image (PNG, JPG/JPEG) or PDF document, and must not exceed the maximum file size of 10 MB. Videos, scripts, and other file types are not permitted.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'upload_failed'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ File Upload Failed:</strong> The floor plan file could not be uploaded or saved. Please ensure the file is not corrupted, is within the 10 MB size limit, and try again.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'floor_plan_too_large'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Floor Plan File Too Large:</strong> The selected floor plan file exceeds the maximum allowed size of 10 MB. Please compress or optimize the file and try again.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_text_input'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Meaningful Input Required:</strong> One or more fields contain random characters, keyboard mashing, or insufficient text. Please provide valid information before submitting.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_score'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Invalid Assessment Score:</strong> Test scores and attendance values cannot be negative. Please enter valid non-negative values.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'no_collaborators'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ No Collaborators Selected:</strong> Please add at least one Co-Author or Contributor before sending the proposal.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'missing_title'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Activity Title Required:</strong> Please enter an Activity Title before sharing this proposal with collaborators.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'missing_fields'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Required Fields Incomplete:</strong> Please ensure all required proposal fields (Title, Theme, Venue, Date, Time, Target Participants, Objectives, and Rationale) are filled out before submitting.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'missing_kpi'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Key Performance Indicators (KPIs) Required:</strong> Please specify at least one Success Indicator or Evaluation Criteria in Step 8 before submitting for approval.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'validation_incomplete'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Rule Validation Check Failed:</strong> One or more required proposal sections are incomplete or have formatting issues. Please review highlighted fields in red before submitting.
        </div>
      <?php elseif (isset($_GET['error']) && $_GET['error'] === 'invalid_faculty_assignment'): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Invalid Faculty Assignment:</strong> One of the assigned faculty members in Committee Tasks does not exist or is inactive. Please select a valid faculty member.
        </div>
      <?php elseif (isset($_GET['error'])): ?>
        <div class="alert alert-danger" style="margin-bottom:20px; border-left:6px solid #dc3545; background:#fff5f5; color:#742a2a; border-radius:8px; padding:16px 20px; border:1px solid #feb2b2;">
          <strong>⚠️ Database / Server Error:</strong> An error occurred while saving the proposal. If you are on the live hosting server, please ensure the database migration script has been executed or try saving as a draft first.
        </div>
      <?php endif; ?>

      <!-- Active Collaborators Staging Banner (visible when collaborators workflow is chosen) -->
      <div id="collabActiveHeaderBar" style="display:none; margin-bottom:16px; background:rgba(2, 132, 199, 0.08); border:1px solid #bae6fd; border-left:4px solid #0284c7; border-radius:8px; padding:12px 16px; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div style="display:flex; align-items:center; gap:8px;">
          <span style="font-size:1.1rem;">👥</span>
          <div>
            <strong style="color:#0369a1; font-size:0.9rem;">Collaborative Proposal Session:</strong>
            <span id="collabHeaderSummary" style="font-size:0.85rem; color:#334155;">1 Owner, 0 Collaborators</span>
          </div>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
          <button type="button" class="btn btn-outline btn-sm" onclick="openCollaboratorsModal()" style="font-size:0.8rem; padding:4px 12px; background:#fff;">
            ⚙️ Manage Collaborators
          </button>
        </div>
      </div>

      <!-- Offline & Sync Status Banner -->
      <div id="offlineAlertBanner" class="alert alert-warning" style="display:none;margin-bottom:16px;align-items:center;gap:10px;border-left:4px solid #F59E0B;">
        <span style="font-size:1.3rem;">📡</span>
        <div style="flex:1;">
          <strong>Offline Mode:</strong> Internet connection is currently unavailable. Changes will be saved locally to this device and automatically synchronized with the server when connectivity returns.
        </div>
      </div>

      <!-- AI Proposal Guidance Toolbar (Available across all wizard steps) -->
      <div class="ai-guidance-toolbar" style="margin-bottom:16px; background:var(--bg-card, #ffffff); border:1px solid var(--border, #e2e8f0); border-left:4px solid var(--sti-blue, #0284C7); border-radius:10px; padding:12px 18px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
        <div style="display:flex; align-items:center; gap:10px;">
          <span style="font-size:1.4rem;">✨</span>
          <div>
            <strong style="color:var(--text-main, #1e293b); font-size:0.92rem;">AI Proposal Guidance:</strong>
            <span style="font-size:0.84rem; color:var(--text-muted, #64748b);">Analyze completeness, SMART objectives, and schedule alignment with actionable suggestions before submission.</span>
          </div>
        </div>
        <button type="button" class="btn btn-primary btn-sm" id="btnGuidanceToolbar" onclick="AIProposalGuidance.requestGuidance()" style="display:inline-flex; align-items:center; gap:6px; font-weight:600; padding:7px 16px; border-radius:8px; white-space:nowrap; background:var(--sti-blue, #0284C7); border-color:var(--sti-blue, #0284C7); color:#fff; cursor:pointer;">
          <span>✨</span> Request AI Guidance
        </button>
      </div>

      <!-- Wizard Steps -->
      <div class="wizard-steps" id="wizardSteps">
        <div class="wizard-step active" data-step="1">
          <div class="step-num">1</div>Event Details
        </div>
        <div class="wizard-step locked" data-step="2">
          <div class="step-num">2</div>Objectives
        </div>
        <div class="wizard-step locked" data-step="3">
          <div class="step-num">3</div>Materials
        </div>
        <div class="wizard-step locked" data-step="4">
          <div class="step-num">4</div>Floor Plan
        </div>
        <div class="wizard-step locked" data-step="5">
          <div class="step-num">5</div>People
        </div>
        <div class="wizard-step locked" data-step="6">
          <div class="step-num">6</div>Schedule
        </div>
        <div class="wizard-step locked" data-step="7">
          <div class="step-num">7</div>Guidelines
        </div>
        <div class="wizard-step locked" data-step="8">
          <div class="step-num">8</div>Faculty Tasks
        </div>
        <div class="wizard-step locked" data-step="9">
          <div class="step-num">9</div>Evaluation & KPI
        </div>
      </div>

      <form id="proposalForm" method="POST" action="<?= BASE_URL ?>/api/proposal-save.php" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="faculty_id" value="<?= $user['id'] ?>">
        <input type="hidden" name="activity_id" id="proposalActivityId" value="<?= htmlspecialchars((string)($_GET['id'] ?? $_GET['activity_id'] ?? '')) ?>">
        <input type="hidden" name="action" id="proposalFormAction" value="draft">
        <input type="hidden" name="proposal_session_token" id="proposal_session_token" value="<?= htmlspecialchars($sessionToken) ?>">
        <input type="hidden" name="send_to_collaborators" id="send_to_collaborators" value="0">
        <!-- tracks the highest step the user has validly reached -->
        <input type="hidden" id="maxUnlockedStep" value="1">

        <div class="proposal-layout-grid">
          <div class="proposal-wizard-container">

        <!-- ── STEP 1: Event Details ── -->
        <div class="wizard-panel active" id="panel-1">
          <div class="card">
            <div class="card-header">
              <h2>📄 Event Proposal</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-1">⚠️ Please fill in all required fields before continuing.</div>
              <div class="form-grid">

                <div class="form-group col-span-2">
                  <label class="form-label">Event Name / Title <span class="text-danger">*</span></label>
                  <input type="text" name="title" id="f1_title" class="form-control" placeholder="e.g. Foundation Day Celebration 2025">
                  <span class="field-error" id="e_title">Event title is required.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">Date of Event <span class="text-danger">*</span></label>
                  <input type="date" name="event_date" id="f1_event_date" class="form-control" min="<?= $tomorrowManila ?>">
                  <span class="field-error" id="e_event_date">Event date is required.</span>
                  <span class="field-error" id="e_event_date_past">Event date must be tomorrow or a future date.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">Source <span class="text-danger">*</span></label>
                  <select name="source" id="f1_source" class="form-control">
                    <option value="">Select source...</option>
                    <option value="student_org">Student Organization</option>
                    <option value="faculty">Faculty</option>
                  </select>
                  <span class="field-error" id="e_source">Please select a source.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">Start Time <span class="text-danger">*</span></label>
                  <input type="time" name="start_time" id="f1_start_time" class="form-control">
                  <span class="field-error" id="e_start_time">Start time is required.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">End Time <span class="text-danger">*</span></label>
                  <input type="time" name="end_time" id="f1_end_time" class="form-control">
                  <span class="field-error" id="e_end_time">End time is required.</span>
                  <span class="field-error" id="e_end_time_order">End time must be later than the start time.</span>
                </div>

                <div class="form-group">
                  <label class="form-label">Theme <span class="text-danger">*</span></label>
                  <div class="theme-input-wrapper" style="position: relative; width: 100%;">
                    <input type="text" name="theme" id="f1_theme" class="form-control" placeholder="e.g. Igniting Excellence, Empowering Tomorrow" style="padding-right: 42px;" aria-busy="false">
                    <div id="themeInputSpinner" style="display: none; position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #0284c7; align-items: center; justify-content: center;" aria-hidden="true">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="animation: spin 0.8s linear infinite;">
                        <circle cx="12" cy="12" r="9" stroke-opacity="0.25"></circle>
                        <path d="M12 3a9 9 0 0 1 9 9"></path>
                      </svg>
                    </div>
                  </div>
                  <span class="field-error" id="e_theme">Theme is required.</span>

                  <!-- Automatic AI Theme Status -->
                  <div id="themeAiStatus" style="margin-top: 6px;"></div>
                </div>

                <div class="form-group">
                  <label class="form-label">Venue <span class="text-danger">*</span></label>
                  <input type="text" name="venue" id="f1_venue" class="form-control" placeholder="e.g. STI Marikina Auditorium">
                  <span class="field-error" id="e_venue">Venue is required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label" for="f1_venue_address">Venue Address <span class="text-danger">*</span></label>
                  <div class="venue-address-wrapper">
                    <input type="text" name="venue_address" id="f1_venue_address" class="form-control" placeholder="Full address" autocomplete="off">
                    <div id="venue_address_dropdown" class="venue-address-dropdown" role="listbox" aria-label="Suggested venue address">
                      <div class="venue-address-item" role="option" tabindex="0" onmousedown="selectVenueAddress('289 L. de Guzman Street, Concepcion I, Marikina City, 1807 Metro Manila', event)" onclick="selectVenueAddress('289 L. de Guzman Street, Concepcion I, Marikina City, 1807 Metro Manila', event)">
                        289 L. de Guzman Street, Concepcion I, Marikina City, 1807 Metro Manila
                      </div>
                    </div>
                  </div>
                  <span class="field-error" id="e_venue_address">Venue address is required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Event Poster <span class="text-danger">*</span></label>
                  <input type="file" name="poster_file" id="f1_poster_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" required>
                  <span class="field-error" id="e_poster_file">Event poster is required.</span>
                  <small style="color: var(--text-muted);">Upload an image poster for this event (JPG, PNG, WEBP, GIF). Videos are not allowed.</small>
                </div>
              </div>
            </div>
          </div>

          <!-- Target Participants Panel -->
          <div class="card target-participants-panel" id="target-participants-card" style="margin-top: 20px;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
              <h2 style="margin: 0; font-size: 1.05rem; display: flex; align-items: center; gap: 6px;">
                👥 Target Participants <span class="text-danger">*</span>
              </h2>
              <div class="target-participants-controls" style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <button type="button" class="btn btn-outline btn-sm" id="btn-add-section" onclick="addParticipantSectionRow()" style="font-weight: 600;">+ Add Section</button>
                <div class="total-participants-badge" style="display: inline-flex; align-items: center; gap: 6px; font-weight: 600; font-size: 0.875rem; color: var(--text-main, #1e293b); background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px 12px; border-radius: 6px;">
                  <span>Total Target Participants:</span>
                  <span id="target-participants-total-display" style="color: var(--primary, #0284c7); font-size: 1rem; font-weight: 700;">0</span>
                </div>
              </div>
            </div>
            <div class="card-body" style="padding: 20px;">
              <input type="hidden" name="target_participants" id="f1_target_participants" value="0">
              <span class="field-error" id="e_target_participants" style="margin-bottom: 12px;">Target participants is required and must have at least 1 participant.</span>

              <div class="dynamic-table" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow-x: auto;">
                <table class="table" style="margin: 0; width: 100%; border-collapse: collapse; background: #ffffff;">
                  <thead>
                    <tr style="border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                      <th style="width: 50px; text-align: center; padding: 10px 12px; font-size: 0.8rem; font-weight: 600; color: #475569;">No.</th>
                      <th style="padding: 10px 12px; font-size: 0.8rem; font-weight: 600; color: #475569;">Involved Subject / Section <span class="text-danger">*</span></th>
                      <th style="width: 180px; padding: 10px 12px; font-size: 0.8rem; font-weight: 600; color: #475569;">No. of Participants <span class="text-danger">*</span></th>
                      <th style="min-width: 250px; padding: 10px 12px; font-size: 0.8rem; font-weight: 600; color: #475569;">Subject Teacher(s)</th>
                      <th style="width: 70px; text-align: center; padding: 10px 12px; font-size: 0.8rem; font-weight: 600; color: #475569;">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="participant-sections-body">
                    <tr class="participant-row" style="border-bottom: 1px solid #f1f5f9;">
                      <td class="part-row-num" style="text-align: center; font-weight: 600; color: #64748b; font-size: 0.85rem; padding: 10px 12px; vertical-align: middle;">
                        1
                      </td>
                      <td style="padding: 10px 12px;">
                        <input type="text" name="part_section[]" class="form-control part-section-input" placeholder="e.g. BSIT 301 / Data Structures" value="">
                      </td>
                      <td style="padding: 10px 12px;">
                        <input type="number" name="part_count[]" class="form-control part-count-input" placeholder="0" min="0" value="" oninput="updateTotalTargetParticipants()">
                      </td>
                      <td style="padding: 10px 12px; min-width: 250px;">
                        <div class="teacher-multiselect" data-row-idx="1">
                          <input type="hidden" name="part_teacher[]" class="part-teacher-input" value="">
                          <div class="tms-control" onclick="focusTmsInput(this)">
                            <div class="tms-tags"></div>
                            <input type="text" class="tms-input" placeholder="Search and select faculty...">
                          </div>
                          <div class="tms-dropdown" style="display: none;">
                            <div class="tms-list"></div>
                            <div class="tms-empty" style="display: none;">No matching faculty found</div>
                          </div>
                        </div>
                      </td>
                      <td style="text-align: center; padding: 10px 12px; vertical-align: middle;">
                        <button type="button" class="btn btn-outline btn-sm btn-delete-section" onclick="removeParticipantSectionRow(this)" title="Cannot delete the only row" disabled style="padding: 4px 8px; color: #ef4444; border-color: #fca5a5; line-height: 1; opacity: 0.4; cursor: not-allowed;">✕</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <div>
              <a href="<?= BASE_URL ?>/faculty/dashboard.php" class="btn btn-outline">Cancel</a>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
              <button type="button" class="btn btn-info send-to-collab-btn" onclick="handleSendToCollaborators()" style="display:none; background:#0284c7; color:#fff; border:none; padding:8px 16px; font-weight:600; border-radius:6px; cursor:pointer;">
                Send to Collaborators
              </button>
              <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Objectives →</button>
            </div>
          </div>
        </div>

        <!-- ── STEP 2: Objectives & Academic Alignment ── -->
        <div class="wizard-panel" id="panel-2">
          <div class="card">
            <div class="card-header">
              <h2>🎯 Objectives & Academic Alignment</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-2" data-default-msg="⚠️ Please fill in all required fields before continuing.">⚠️ Please fill in all required fields before continuing.</div>

              <!-- AI Generation Status -->
              <div id="objGenStatus" style="display:none;margin-bottom:16px;padding:12px 16px;border-radius:8px;font-size:.875rem;border:1px solid transparent;"></div>

              <div class="form-grid">
                <div class="form-group col-span-2">
                  <label class="form-label">
                    General Objectives <span class="text-danger">*</span>
                    <span id="objInputSpinner" style="display:none;margin-left:8px;vertical-align:middle;" title="Generating objectives with AI...">
                      <span class="spinner-border" style="width:0.875rem;height:0.875rem;border-width:0.18em;color:#0284c7;"></span>
                    </span>
                  </label>
                  <textarea name="general_objectives" id="f2_general_objectives" class="form-control" rows="4" placeholder="State the broad goals of this activity..."></textarea>
                  <span class="field-error" id="e_general_objectives">General objectives are required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Specific Objectives <span class="text-danger">*</span></label>
                  <textarea name="specific_objectives" id="f2_specific_objectives" class="form-control" rows="6" placeholder="List measurable specific goals..."></textarea>
                  <span class="field-error" id="e_specific_objectives">Specific objectives are required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Involved Subjects <span class="text-danger">*</span></label>
                  <input type="text" name="involved_subjects" id="f2_involved_subjects" class="form-control" placeholder="e.g. ITE314, GE102">
                  <span class="field-error" id="e_involved_subjects">Involved subjects are required.</span>
                </div>

                <div class="form-group col-span-2">
                  <label class="form-label">Rationale <span class="text-danger">*</span></label>
                  <textarea name="rationale" id="f2_rationale" class="form-control" rows="3" placeholder="Why is this activity necessary?"></textarea>
                  <span class="field-error" id="e_rationale">Rationale is required.</span>
                </div>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div style="display:flex; gap:10px; align-items:center;">
              <button type="button" class="btn btn-info send-to-collab-btn" onclick="handleSendToCollaborators()" style="display:none; background:#0284c7; color:#fff; border:none; padding:8px 16px; font-weight:600; border-radius:6px; cursor:pointer;">
                Send to Collaborators
              </button>
              <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Materials →</button>
            </div>
          </div>
        </div>



        <!-- ── STEP 3: Materials ── -->
        <div class="wizard-panel" id="panel-3">
          <div class="card">
            <div class="card-header">
              <h2>📄 Materials Needed</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('materials')">+ Add Item</button>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-3">⚠️ Please fill in all material fields before continuing.</div>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Item Name <span class="text-danger">*</span></th>
                      <th>Description / Type <span class="text-danger">*</span></th>
                      <th>Quantity <span class="text-danger">*</span></th>
                      <th>Provider / Supplier <span class="text-danger">*</span></th>
                      <th>Est. Cost (₱) <span class="text-danger">*</span></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="materials-body">
                    <tr>
                      <td><input type="text" name="mat_item[]" class="form-control mat-field" placeholder="e.g. Chairs"></td>
                      <td><input type="text" name="mat_desc[]" class="form-control mat-field" placeholder="Plastic monobloc"></td>
                      <td><input type="number" name="mat_qty[]" class="form-control mat-qty" value="" min="1" placeholder="1"></td>
                      <td><input type="text" name="mat_provider[]" class="form-control mat-field" placeholder="Supplier name"></td>
                      <td><input type="number" name="mat_cost[]" class="form-control mat-cost" placeholder="0.00" step="0.01" min="0"></td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                    </tr>
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
            <div style="display:flex; gap:10px; align-items:center;">
              <button type="button" class="btn btn-info send-to-collab-btn" onclick="handleSendToCollaborators()" style="display:none; background:#0284c7; color:#fff; border:none; padding:8px 16px; font-weight:600; border-radius:6px; cursor:pointer;">
                Send to Collaborators
              </button>
              <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Floor Plan →</button>
            </div>
          </div>
        </div>

        <!-- ── STEP 4: Floor Plan ── -->
        <div class="wizard-panel" id="panel-4">
          <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center;">
              <h2>🗺️ Floor Plan Layout</h2>
              <button type="button" class="btn btn-outline btn-xs" onclick="openFloorPlanRequirementModal()" style="font-size:0.8rem; padding:4px 10px;">Change Requirement</button>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-4">⚠️ Please upload a floor plan file or draw your layout on the canvas before continuing.</div>

              <!-- Hidden input carries floor plan requirement choice -->
              <input type="hidden" name="floor_plan_required" id="floor_plan_required" value="yes">

              <!-- Collapsible Floor Plan Interface -->
              <div id="floor-plan-interface-wrap">
                <?php include __DIR__ . '/../includes/floorplan-ui.php'; ?>

                <div class="form-group" style="margin-top:16px;">
                  <label class="form-label">Floor Plan Notes / Description <span class="text-danger" id="fp_notes_asterisk">*</span> <span id="fp_notes_optional_hint" style="display:none; font-size:0.8rem; font-weight:normal; color:var(--text-muted);">(Optional when floor plan file is uploaded)</span></label>
                  <textarea name="floor_plan_notes" id="f3_floor_plan_notes" class="form-control" rows="3" placeholder="Describe the layout — exits, important areas, stage direction, seating arrangement..."></textarea>
                  <span class="field-error" id="e_floor_plan_notes">Floor plan notes / description are required when using the floor plan creator.</span>
                </div>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div style="display:flex; gap:10px; align-items:center;">
              <button type="button" class="btn btn-info send-to-collab-btn" onclick="handleSendToCollaborators()" style="display:none; background:#0284c7; color:#fff; border:none; padding:8px 16px; font-weight:600; border-radius:6px; cursor:pointer;">
                Send to Collaborators
              </button>
              <button type="button" class="btn btn-primary" onclick="nextStep()">Next: People →</button>
            </div>
          </div>
        </div>

        <!-- ── STEP 5: People / Manpower ── -->
        <div class="wizard-panel" id="panel-5">
          <div class="card">
            <div class="card-header">
              <h2>📄 People / Manpower</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('manpower')">+ Add Person</button>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-5">⚠️ Please fill in all manpower fields before continuing.</div>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Role / Position <span class="text-danger">*</span></th>
                      <th>Assigned Person <span class="text-danger">*</span></th>
                      <th>Type <span class="text-danger">*</span></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="manpower-body">
                    <tr>
                      <td>
                        <select name="mp_role[]" class="form-control" onchange="updateManpowerSummary()">
                          <option value="Medic">Medic</option>
                          <option value="Customer Service">Customer Service</option>
                          <option value="Usher">Usher</option>
                          <option value="Marshall">Marshall</option>
                          <option value="Emcee">Emcee</option>
                          <option value="Floor Director">Floor Director</option>
                          <option value="Sound & AVP Support">Sound & AVP Support</option>
                          <option value="Tabulators">Tabulators</option>
                          <option value="Photographers">Photographers</option>
                          <option value="Videographers">Videographers</option>
                          <option value="Expected Guest(s)">Expected Guest(s)</option>
                          <option value="Students' Audience">Students' Audience</option>
                          <option value="Adviser(s) / Teacher(s) / Staff(s)">Adviser(s) / Teacher(s) / Staff(s)</option>
                          <option value="VIP">VIP</option>
                          <option value="Sponsor(s)">Sponsor(s)</option>
                        </select>
                      </td>
                      <td><input type="text" name="mp_person[]" class="form-control mp-person-field" placeholder="Full name" oninput="updateManpowerSummary()"></td>
                      <td>
                        <select name="mp_type[]" class="form-control" onchange="updateManpowerSummary()">
                          <option value="faculty">Faculty</option>
                          <option value="staff">Staff</option>
                          <option value="student">Student</option>
                        </select>
                      </td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this); updateManpowerSummary();">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Manpower Summary Table -->
              <div class="manpower-summary-wrap" style="margin-top: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; flex-wrap: wrap; gap: 8px;">
                  <h3 style="margin: 0; font-size: 0.95rem; font-weight: 700; color: var(--text-main);">
                    Manpower Summary
                  </h3>
                  <div style="font-size: 0.85rem; font-weight: 600; color: var(--text-main); background: var(--bg-card); border: 1px solid var(--border); padding: 4px 10px; border-radius: 6px;">
                    Total Persons Involved: <span id="mp-summary-unique-total" style="font-weight: 700;">0</span> <span style="font-size: 0.75rem; font-weight: 400; color: var(--text-muted);">(distinct individuals)</span>
                  </div>
                </div>
                <div class="dynamic-table" style="background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; overflow-x: auto;">
                  <table class="table" id="manpower-summary-table" style="width: 100%; border-collapse: collapse; margin: 0; background: var(--bg-card);">
                    <thead>
                      <tr style="border-bottom: 1px solid var(--border); background: var(--bg-card-elevated);">
                        <th style="padding: 10px 14px; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-align: left; background: var(--bg-card-elevated); border-bottom: 1px solid var(--border);">Role / Position</th>
                        <th style="padding: 10px 14px; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-align: center; width: 100px; background: var(--bg-card-elevated); border-bottom: 1px solid var(--border);">Faculty</th>
                        <th style="padding: 10px 14px; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-align: center; width: 100px; background: var(--bg-card-elevated); border-bottom: 1px solid var(--border);">Staff</th>
                        <th style="padding: 10px 14px; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-align: center; width: 100px; background: var(--bg-card-elevated); border-bottom: 1px solid var(--border);">Students</th>
                        <th style="padding: 10px 14px; font-size: 0.8rem; font-weight: 600; color: var(--text-muted); text-align: center; width: 110px; background: var(--bg-card-elevated); border-bottom: 1px solid var(--border);">Total #</th>
                      </tr>
                    </thead>
                    <tbody id="manpower-summary-body" style="background: var(--bg-card);">
                      <tr>
                        <td colspan="5" style="text-align: center; color: var(--text-muted); font-size: 0.82rem; padding: 14px; background: var(--bg-card);">
                          No manpower assigned yet.
                        </td>
                      </tr>
                    </tbody>
                    <tfoot id="manpower-summary-foot" style="background: var(--bg-card); border-top: 1px solid var(--border);">
                      <tr style="border-top: 1px solid #e2e8f0; background: #ffffff; font-weight: 600;">
                        <td style="padding: 10px 14px; font-size: 0.82rem; color: #1e293b; text-align: left; background: #ffffff;">Total</td>
                        <td id="mp-sum-faculty" style="padding: 10px 14px; font-size: 0.82rem; color: #1e293b; text-align: center; background: #ffffff;">0</td>
                        <td id="mp-sum-staff" style="padding: 10px 14px; font-size: 0.82rem; color: #1e293b; text-align: center; background: #ffffff;">0</td>
                        <td id="mp-sum-students" style="padding: 10px 14px; font-size: 0.82rem; color: #1e293b; text-align: center; background: #ffffff;">0</td>
                        <td id="mp-sum-total" style="padding: 10px 14px; font-size: 0.82rem; color: #1e293b; text-align: center; font-weight: 700; background: #ffffff;">0</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div style="display:flex; gap:10px; align-items:center;">
              <button type="button" class="btn btn-info send-to-collab-btn" onclick="handleSendToCollaborators()" style="display:none; background:#0284c7; color:#fff; border:none; padding:8px 16px; font-weight:600; border-radius:6px; cursor:pointer;">
                Send to Collaborators
              </button>
              <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Schedule →</button>
            </div>
          </div>
        </div>

        <!-- ── STEP 6: Schedule & Program ── -->
        <div class="wizard-panel" id="panel-6">
          <div class="card" style="margin-bottom: 20px;">
            <div class="card-header">
              <h2>📄 Activity Timeline (Schedule)</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('schedule')">+ Add Schedule</button>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-6">⚠️ Please fill in all schedule and program fields before continuing.</div>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Date <span class="text-danger">*</span></th>
                      <th>Event Name <span class="text-danger">*</span></th>
                      <th>Venue <span class="text-danger">*</span></th>
                      <th>Organizer / Responsible <span class="text-danger">*</span></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="schedule-body">
                    <tr>
                      <td><input type="date" name="sched_date[]" class="form-control sched-field" min="<?= $tomorrowManila ?>"></td>
                      <td><input type="text" name="sched_event[]" class="form-control sched-field" placeholder="Event name"></td>
                      <td><input type="text" name="sched_venue[]" class="form-control sched-field" placeholder="Venue"></td>
                      <td><input type="text" name="sched_organizer[]" class="form-control sched-field" placeholder="Name"></td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-header">
              <h2>📄 Program Flow</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('program')">+ Add Segment</button>
            </div>
            <div class="card-body">
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th>Time <span class="text-danger">*</span></th>
                      <th>Activity / Segment <span class="text-danger">*</span></th>
                      <th>Description <span class="text-danger">*</span></th>
                      <th>Person In-Charge <span class="text-danger">*</span></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="program-body">
                    <tr>
                      <td><input type="time" name="prog_time[]" class="form-control prog-field"></td>
                      <td><input type="text" name="prog_segment[]" class="form-control prog-field" placeholder="e.g. Invocation"></td>
                      <td><input type="text" name="prog_desc[]" class="form-control prog-field" placeholder="Description"></td>
                      <td><input type="text" name="prog_pic[]" class="form-control prog-field" placeholder="Name"></td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div style="display:flex; gap:10px; align-items:center;">
              <button type="button" class="btn btn-info send-to-collab-btn" onclick="handleSendToCollaborators()" style="display:none; background:#0284c7; color:#fff; border:none; padding:8px 16px; font-weight:600; border-radius:6px; cursor:pointer;">
                Send to Collaborators
              </button>
              <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Guidelines →</button>
            </div>
          </div>
        </div>

        <!-- ── STEP 7: Guidelines ── -->
        <div class="wizard-panel" id="panel-7">
          <div class="card">
            <div class="card-header">
              <h2>📄 Event Guidelines</h2>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-7">⚠️ Please fill in all guideline fields before continuing.</div>
              <div class="form-group">
                <label class="form-label">Mechanics / Guidelines <span class="text-danger">*</span></label>
                <textarea name="guidelines_mechanics" id="f7_mechanics" class="form-control" style="min-height:120px" placeholder="Describe event rules and procedures..."></textarea>
                <span class="field-error" id="e_mechanics">Mechanics / guidelines are required.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Criteria for Judging <span class="text-danger">*</span></label>
                <textarea name="guidelines_criteria" id="f7_criteria" class="form-control" placeholder="List judging criteria..."></textarea>
                <span class="field-error" id="e_criteria">Criteria for judging are required.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Scoring System <span class="text-danger">*</span></label>
                <textarea name="guidelines_scoring" id="f7_scoring" class="form-control" placeholder="Describe scoring system..."></textarea>
                <span class="field-error" id="e_scoring">Scoring system is required.</span>
              </div>
              <div class="form-group">
                <label class="form-label">Special Awards <span class="text-danger">*</span></label>
                <textarea name="guidelines_awards" id="f7_awards" class="form-control" placeholder="List any special awards..."></textarea>
                <span class="field-error" id="e_awards">Special awards field is required.</span>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div style="display:flex; gap:10px; align-items:center;">
              <button type="button" class="btn btn-info send-to-collab-btn" onclick="handleSendToCollaborators()" style="display:none; background:#0284c7; color:#fff; border:none; padding:8px 16px; font-weight:600; border-radius:6px; cursor:pointer;">
                Send to Collaborators
              </button>
              <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Faculty Tasks →</button>
            </div>
          </div>
        </div>

        <!-- ── STEP 8: Faculty Tasks ── -->
        <div class="wizard-panel" id="panel-8">
          <div class="card">
            <div class="card-header">
              <h2>📄 Faculty Tasks & Contributions</h2>
              <button type="button" class="btn btn-outline btn-sm" onclick="addRow('faculty-tasks')">+ Add Faculty</button>
            </div>
            <div class="card-body">
              <div class="step-error-banner" id="err-banner-8">⚠️ Please fill in all faculty task fields before continuing.</div>
              <div class="dynamic-table">
                <table>
                  <thead>
                    <tr>
                      <th style="min-width: 190px;">Assigned Faculty</th>
                      <th>Assigned Task <span class="text-danger">*</span></th>
                      <th>Contribution <span class="text-danger">*</span></th>
                      <th>Role in Event <span class="text-danger">*</span></th>
                      <th style="min-width: 140px;">Target Deadline</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody id="faculty-tasks-body">
                    <tr>
                      <td>
                        <select name="ft_user_id[]" class="form-control ft-user-select" onchange="syncFtName(this)">
                          <option value="">-- Unassigned / External --</option>
                          <?= renderFacultyTaskOptgroups($facultyDepartmentGroups, $facultyUsers) ?>
                        </select>
                        <input type="text" name="ft_name[]" class="form-control ft-name-field" style="margin-top: 4px; font-size: 0.8rem;" placeholder="External name (if unassigned)">
                      </td>
                      <td><input type="text" name="ft_task[]" class="form-control ft-field" placeholder="Assigned task"></td>
                      <td><input type="text" name="ft_contribution[]" class="form-control ft-field" placeholder="Contribution description"></td>
                      <td><input type="text" name="ft_role[]" class="form-control ft-field" placeholder="Role"></td>
                      <td><input type="date" name="ft_due_date[]" class="form-control ft-date-field"></td>
                      <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this)">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div style="display:flex; gap:10px; align-items:center;">
              <button type="button" class="btn btn-info send-to-collab-btn" onclick="handleSendToCollaborators()" style="display:none; background:#0284c7; color:#fff; border:none; padding:8px 16px; font-weight:600; border-radius:6px; cursor:pointer;">
                Send to Collaborators
              </button>
              <button type="button" class="btn btn-primary" onclick="nextStep()">Next: Evaluation →</button>
            </div>
          </div>
        </div>

        <!-- ── STEP 9: Key Performance Indicators & Evaluation ── -->
        <div class="wizard-panel" id="panel-9">
          <!-- SECTION 1: Key Performance Indicators -->
          <div class="card" id="kpi-table-card">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
              <h2 style="margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                📈 Key Performance Indicators
              </h2>
              <button type="button" class="btn btn-outline btn-sm" id="btn-add-kpi" onclick="addKpiRow()">+ Add KPI</button>
            </div>
            <div class="card-body" style="padding: 20px;">
              <div class="dynamic-table" style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow-x: auto;">
                <table class="table" style="margin: 0; width: 100%; border-collapse: collapse; background: #ffffff;">
                  <thead>
                    <tr style="border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                      <th style="padding: 10px 12px; font-size: 0.8rem; font-weight: 600; color: #475569;">Criteria / Indicator</th>
                      <th style="padding: 10px 12px; font-size: 0.8rem; font-weight: 600; color: #475569;">Target Metric</th>
                      <th style="padding: 10px 12px; font-size: 0.8rem; font-weight: 600; color: #475569;">Evaluation Method</th>
                      <th style="width: 70px; text-align: center; padding: 10px 12px; font-size: 0.8rem; font-weight: 600; color: #475569;"></th>
                    </tr>
                  </thead>
                  <tbody id="kpi-body">
                    <tr>
                      <td style="padding: 10px 12px;"><input type="text" name="kpi_indicator[]" class="form-control kpi-field" placeholder="e.g. Number of Attendees"></td>
                      <td style="padding: 10px 12px;"><input type="text" name="kpi_target[]" class="form-control kpi-field" placeholder="e.g. 500 Participants"></td>
                      <td style="padding: 10px 12px;"><input type="text" name="kpi_method[]" class="form-control kpi-field" placeholder="e.g. Attendance Sheet"></td>
                      <td style="text-align: center; padding: 10px 12px; vertical-align: middle;">
                        <button type="button" class="btn btn-outline btn-sm btn-delete-kpi" onclick="removeKpiRow(this)" title="Delete KPI" disabled style="padding: 4px 8px; color: #ef4444; border-color: #fca5a5; line-height: 1; opacity: 0.4; cursor: not-allowed;">✕</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- SECTION 2: AI-Generated Evaluation Tool -->
          <div class="card" id="ai-evaluation-tool-card" style="margin-top: 20px;">
            <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
              <h2 style="margin: 0; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                🤖 AI-Generated Evaluation Tool
              </h2>
              <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <span id="evalStatusBadge">
                  <span id="evalStatusIndicator"></span> <span id="evalStatusText">Empty</span>
                </span>
                <button type="button" class="btn btn-outline btn-sm" id="btn-regenerate-eval" onclick="regenerateEvaluationWithAi(true)" style="font-size: 0.8rem; padding: 6px 12px; display: inline-flex; align-items: center; gap: 4px; height:auto; line-height:1.2;">
                  ↻ Regenerate with AI
                </button>
                <button type="button" class="btn btn-outline btn-sm" onclick="openPreviewModal()" style="font-size: 0.8rem; padding: 6px 12px; display: inline-flex; align-items: center; gap: 4px; height:auto; line-height:1.2;">
                  👁 Preview Tool
                </button>
              </div>
            </div>
            <div class="card-body" style="padding: 24px; display: flex; flex-direction: column; gap: 16px;">

              <!-- Needs Update Notice -->
              <div id="evalChangeNotice" class="alert alert-warning" style="display:none; flex-direction:column; gap:8px; font-size:0.8rem; padding:10px 14px; margin-bottom:4px; border-radius:6px; border-left: 4px solid #d97706; background: #fffbeb;">
                <div>⚠️ <strong>Your activity details have changed.</strong> The evaluation questions may need to be updated.</div>
                <div style="display:flex; gap:8px; margin-top:4px;">
                  <button type="button" class="btn btn-outline btn-sm" onclick="dismissEvalChangeNotice()" style="padding: 3px 10px; font-size: 0.75rem; height:auto; line-height:1.2; background:#fff; border:1px solid #d97706; color:#d97706;">Keep Current</button>
                  <button type="button" class="btn btn-primary btn-sm" onclick="regenerateEvaluationWithAi(true)" style="padding: 3px 10px; font-size: 0.75rem; height:auto; line-height:1.2; background: #d97706; border: none; color: white;">Regenerate with AI</button>
                </div>
              </div>

              <div class="step-error-banner" id="err-banner-9" style="display:none; margin-bottom:4px; padding:10px 12px; border-radius:6px; background:#f8d7da; color:#842029; border:1px solid #f5c2c7; font-size:.8rem;">⚠️ Please review evaluation questions and enter a valid evaluation form link.</div>
              <div class="step-error-banner" id="err-banner-eval-side" style="display:none; margin-bottom:4px; padding:10px 12px; border-radius:6px; background:#f8d7da; color:#842029; border:1px solid #f5c2c7; font-size:.8rem;">⚠️ Please review and fix evaluation questions.</div>

              <!-- AI Generation Loading & Status -->
              <div id="evalGenStatus" style="display:none; margin-bottom:4px; padding:10px 14px; border-radius:6px; font-size:.85rem;"></div>

              <div class="info-alert" style="margin-bottom: 4px;">
                These evaluation questions are automatically generated based on your activity objectives. You can customize them, add manual rating/open-ended questions, and generate the Google Form for student responses.
              </div>

              <!-- Hidden JSON field -->
              <input type="hidden" name="evaluation_questions" id="f10_evaluation_questions">

              <!-- Questions list container -->
              <div id="eval-questions-list" style="display: flex; flex-direction: column; gap: 8px;">
                <!-- Dynamically populated -->
              </div>

              <button type="button" class="btn btn-outline btn-sm btn-add-manual-q" onclick="addManualQuestion()" style="align-self: flex-start;">
                + Add Question
              </button>

              <!-- Google Forms Evaluation Link section -->
              <div style="margin-top: 10px; padding-top: 16px; border-top: 1px solid var(--border, #e2e8f0);">
                <label class="form-label" style="font-weight: 600;">Google Forms Evaluation Link <span class="text-danger">*</span></label>
                <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
                  <div style="flex:1; min-width:240px;">
                    <input type="url" name="eval_form_link" id="f9_eval_form_link" class="form-control" placeholder="https://forms.gle/..." required>
                  </div>
                  <button type="button" class="btn btn-outline" id="btnCreateGoogleForm" onclick="handleCreateGoogleForm()" style="white-space:nowrap; display:inline-flex; align-items:center; gap:6px; height:42px; font-weight:500;">
                    <span id="btnCreateGoogleFormSpinner" class="spinner-border" style="display:none; width:0.9rem; height:0.9rem;"></span>
                    <span id="btnCreateGoogleFormText">Create Google Form</span>
                  </button>
                </div>
                <div id="googleFormStatusMessage" style="display:none; margin-top:8px; font-size:0.85rem; padding:8px 12px; border-radius:6px;"></div>
                <span class="field-error" id="e_eval_form_link">A valid evaluation form link is required.</span>
                <small style="color:var(--text-muted);display:block;margin-top:4px;">Required for attendees to provide feedback post-event. You can create one from AI questions or paste your own.</small>
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
                <div class="scu-footer" style="font-size:.75rem;font-style:italic;color:inherit;border-top:1px dashed rgba(0,0,0,.15);padding-top:8px;">* Submission is blocked only when a real venue conflict is detected.</div>
              </div>

            </div>
          </div>
          <div class="nav-btns">
            <button type="button" class="btn btn-outline" onclick="prevStep()">← Back</button>
            <div class="flex gap-2">
              <button type="submit" name="action" value="draft" class="btn btn-outline" formnovalidate onclick="document.getElementById('proposalFormAction').value='draft'; serializeEvaluationQuestions();">💾 Save as Draft</button>
              <button type="submit" name="action" value="submit" class="btn btn-primary" id="submitBtn" onclick="document.getElementById('proposalFormAction').value='submit'; serializeEvaluationQuestions();">🚀 Submit for Approval</button>
            </div>
          </div>
        </div>

          </div> <!-- Close proposal-wizard-container -->
        </div> <!-- Close proposal-layout-grid -->

      </form>
    </div>
  </div>

  <script>
    const todayManila = <?= json_encode($todayManila) ?>;
    const tomorrowManila = <?= json_encode($tomorrowManila) ?>;
    let currentStep = 1;
    const totalSteps = 9;
    let maxUnlocked = 1; // highest step the user has validly completed up to

    // ── Floor Plan Requirement Modal & Navigation ─────────────────
    function openFloorPlanRequirementModal() {
      const modal = document.getElementById('floorPlanRequirementModal');
      if (!modal) return;
      const curChoice = document.getElementById('floor_plan_required')?.value || 'yes';
      const btnYes = document.getElementById('btnFpModalYes');
      const btnNo = document.getElementById('btnFpModalNo');
      if (curChoice === 'no') {
        if (btnNo) { btnNo.classList.add('btn-primary'); btnNo.classList.remove('btn-outline'); }
        if (btnYes) { btnYes.classList.add('btn-outline'); btnYes.classList.remove('btn-primary'); }
      } else {
        if (btnYes) { btnYes.classList.add('btn-primary'); btnYes.classList.remove('btn-outline'); }
        if (btnNo) { btnNo.classList.add('btn-outline'); btnNo.classList.remove('btn-primary'); }
      }
      modal.style.display = 'block';
      modal.classList.add('show');
    }

    function closeFloorPlanRequirementModal() {
      const modal = document.getElementById('floorPlanRequirementModal');
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
      }
    }

    function handleFloorPlanChoice(choice) {
      closeFloorPlanRequirementModal();
      const fpInput = document.getElementById('floor_plan_required');
      if (fpInput) {
        fpInput.value = choice;
      }
      if (typeof toggleFloorPlanRequirement === 'function') {
        toggleFloorPlanRequirement(choice);
      }
      if (choice === 'no') {
        // Unlock Step 4 & 5
        const s4 = document.querySelector('.wizard-step[data-step="4"]');
        const s5 = document.querySelector('.wizard-step[data-step="5"]');
        if (s4) s4.classList.remove('locked');
        if (s5) s5.classList.remove('locked');
        if (maxUnlocked < 5) maxUnlocked = 5;
        // Automatically skip Step 4 and navigate directly to Step 5
        showStep(5);
      } else {
        // Unlock Step 4 and open Step 4
        const s4 = document.querySelector('.wizard-step[data-step="4"]');
        if (s4) s4.classList.remove('locked');
        if (maxUnlocked < 4) maxUnlocked = 4;
        showStep(4);
      }
    }

    window.openFloorPlanRequirementModal = openFloorPlanRequirementModal;
    window.closeFloorPlanRequirementModal = closeFloorPlanRequirementModal;
    window.handleFloorPlanChoice = handleFloorPlanChoice;

    function toggleFloorPlanRequirement(val) {
      const iface = document.getElementById('floor-plan-interface-wrap');
      const errBanner = document.getElementById('err-banner-4');
      const wrap = document.getElementById('fp-canvas-invalid-wrap');
      if (val === 'no') {
        if (iface) iface.style.display = 'none';
        if (errBanner) errBanner.style.display = 'none';
        if (wrap) wrap.classList.remove('fp-canvas-invalid');
        const notesErr = document.getElementById('e_floor_plan_notes');
        if (notesErr) notesErr.style.display = 'none';
        const notesEl = document.getElementById('f3_floor_plan_notes');
        if (notesEl) notesEl.classList.remove('is-invalid');
      } else {
        if (iface) iface.style.display = 'block';
      }
    }
    window.toggleFloorPlanRequirement = toggleFloorPlanRequirement;

    // ── Step Navigation ──────────────────────────────────────────────
    function showStep(n) {
      document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.wizard-step').forEach((s, i) => {
        s.classList.remove('active', 'done');
        if (i + 1 < n) s.classList.add('done');
        if (i + 1 === n) s.classList.add('active');
      });
      const activeStepEl = document.querySelector(`.wizard-step[data-step="${n}"]`);
      if (activeStepEl && typeof activeStepEl.scrollIntoView === 'function') {
        const wizardContainer = document.getElementById('wizardSteps');
        if (wizardContainer && wizardContainer.scrollWidth > wizardContainer.clientWidth) {
          activeStepEl.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
        }
      }
      document.getElementById('panel-' + n).classList.add('active');
      currentStep = n;
      if (n === 9 && typeof syncKpiSectionTables === 'function') {
        syncKpiSectionTables();
      }
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    }

    function nextStep() {
      if (!validateStep(currentStep)) return;

      // Intercept Step 3 -> Step 4 transition to prompt for physical floor plan requirement
      if (currentStep === 3) {
        openFloorPlanRequirementModal();
        return;
      }

      // Unlock the next step in the indicator bar
      if (currentStep + 1 <= totalSteps) {
        const nextIndicator = document.querySelector(`.wizard-step[data-step="${currentStep + 1}"]`);
        if (nextIndicator) nextIndicator.classList.remove('locked');
        if (currentStep + 1 > maxUnlocked) maxUnlocked = currentStep + 1;
      }
      if (currentStep < totalSteps) {
        const goingTo = currentStep + 1;
        showStep(goingTo);
      }
    }

    function prevStep() {
      if (currentStep === 5) {
        const fpInput = document.getElementById('floor_plan_required');
        if (fpInput && (fpInput.value === 'no' || fpInput.value === '0')) {
          // Skip Step 4 on backward navigation when floor plan is not required
          showStep(3);
          return;
        }
      }
      if (currentStep > 1) showStep(currentStep - 1);
    }

    function showVenueAddressDropdown() {
      const input = document.getElementById('f1_venue_address');
      const dropdown = document.getElementById('venue_address_dropdown');
      if (!input || !dropdown) return;
      if (input.disabled || input.readOnly) return;
      if (input.value.trim() === '') {
        dropdown.style.display = 'block';
      } else {
        dropdown.style.display = 'none';
      }
    }

    function hideVenueAddressDropdown() {
      const dropdown = document.getElementById('venue_address_dropdown');
      if (dropdown) dropdown.style.display = 'none';
    }

    function selectVenueAddress(addr, e) {
      if (e) {
        e.preventDefault();
        e.stopPropagation();
      }
      const input = document.getElementById('f1_venue_address');
      if (!input) return;
      input.value = addr;
      input.classList.remove('is-invalid');
      const errReq = document.getElementById('e_venue_address');
      if (errReq) {
        errReq.style.display = 'none';
        errReq.classList.remove('visible');
      }
      hideVenueAddressDropdown();
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.focus();
    }

    function usePresetAddress(addr) {
      selectVenueAddress(addr);
    }

    const venueAddressInput = document.getElementById('f1_venue_address');
    const venueAddressDropdown = document.getElementById('venue_address_dropdown');
    if (venueAddressInput && venueAddressDropdown) {
      venueAddressInput.addEventListener('focus', showVenueAddressDropdown);
      venueAddressInput.addEventListener('click', showVenueAddressDropdown);
      venueAddressInput.addEventListener('input', () => {
        if (venueAddressInput.value.trim() === '') {
          showVenueAddressDropdown();
        } else {
          hideVenueAddressDropdown();
        }
      });
      venueAddressInput.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          hideVenueAddressDropdown();
        } else if (e.key === 'ArrowDown' && venueAddressDropdown.style.display !== 'none') {
          e.preventDefault();
          const firstItem = venueAddressDropdown.querySelector('.venue-address-item');
          if (firstItem) firstItem.focus();
        }
      });
      venueAddressInput.addEventListener('blur', () => {
        setTimeout(() => {
          const wrapper = venueAddressInput.closest('.venue-address-wrapper');
          if (!wrapper || !wrapper.contains(document.activeElement)) {
            hideVenueAddressDropdown();
          }
        }, 150);
      });

      const firstItem = venueAddressDropdown.querySelector('.venue-address-item');
      if (firstItem) {
        firstItem.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            selectVenueAddress('289 L. de Guzman Street, Concepcion I, Marikina City, 1807 Metro Manila', e);
          } else if (e.key === 'Escape') {
            hideVenueAddressDropdown();
            venueAddressInput.focus();
          }
        });
      }

      document.addEventListener('click', (e) => {
        const wrapper = venueAddressInput.closest('.venue-address-wrapper');
        if (wrapper && !wrapper.contains(e.target)) {
          hideVenueAddressDropdown();
        }
      });
    }

    // ── AI Objectives Generation & Automatic Cascade ───────────────────
    let isObjectivesProtected = false;
    let lastAiGeneralObjective = '';
    let lastAiSpecificObjectives = '';
    let lastRequestedObjTitle = '';
    let lastRequestedObjTheme = '';
    let currentObjRequestId = 0;
    let currentObjAbortController = null;
    let objDebounceTimer = null;
    let isObjGenerating = false;
    let currentObjPromise = null;

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
      currentObjPromise = null;
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
          statusEl.style.cssText = 'display:block;margin-bottom:14px;padding:10px 14px;border-radius:6px;font-size:0.85rem;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;';
          statusEl.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
              <span>✅ SMART Objectives generated by AI.</span>
              <button type="button" onclick="forceRegenerateObjectives()" style="background:none;border:none;color:#0284c7;cursor:pointer;text-decoration:underline;font-size:0.85rem;font-weight:600;padding:0;">
                ↻ Regenerate Objectives
              </button>
            </div>`;
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
          statusEl.style.cssText = 'display:block;margin-bottom:14px;padding:10px 14px;border-radius:6px;font-size:0.85rem;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;';
          statusEl.innerHTML = `
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
              <span>✏️ Custom objectives entered.</span>
              <button type="button" onclick="forceRegenerateObjectives()" style="background:none;border:none;color:#0284c7;cursor:pointer;text-decoration:underline;font-size:0.85rem;font-weight:600;padding:0;">
                ↻ Regenerate Objectives with AI
              </button>
            </div>`;
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
          const titleElWait = document.getElementById('f1_title') || document.querySelector('[name="title"]');
          const genElWait = document.getElementById('f2_general_objectives');
          const specElWait = document.getElementById('f2_specific_objectives');
          const curTitleWait = (titleElWait?.value || '').trim();
          const hasAnyObj = (genElWait && genElWait.value.trim() !== '') || (specElWait && specElWait.value.trim() !== '');
          if (!hasAnyObj && curTitleWait.length >= 4) {
            statusEl.style.cssText = 'display:block;margin-bottom:14px;padding:12px 16px;border-radius:6px;font-size:0.875rem;background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;';
            statusEl.innerHTML = `
              <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
                <span>💡 You can automatically generate SMART objectives based on your Title & Theme.</span>
                <button type="button" onclick="forceRegenerateObjectives()" style="background:#0284c7;color:#fff;border:none;border-radius:4px;padding:5px 12px;font-size:0.85rem;cursor:pointer;font-weight:600;">
                  ⚡ Generate Objectives with AI
                </button>
              </div>`;
          } else {
            statusEl.style.display = 'none';
            statusEl.innerHTML = '';
          }
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
      const titleEl = document.getElementById('f1_title');
      const themeEl = document.getElementById('f1_theme');
      if (!genEl || !specEl || !titleEl) return null;

      const currentTitle = contextOverride?.title ?? titleEl.value.trim();
      const currentTheme = contextOverride?.theme ?? (themeEl ? themeEl.value.trim() : '');

      if (currentTitle.length < 4) return null;

      // Protected manual content: never silently overwrite unless forced
      if (isObjectivesProtected && !forced) {
        renderObjectivesStatus('OUTDATED');
        return null;
      }

      // If already generating for this exact title & theme and not forced, return existing in-flight promise
      if (isObjGenerating && currentObjPromise && !forced && lastRequestedObjTitle === currentTitle && lastRequestedObjTheme === currentTheme) {
        return currentObjPromise;
      }

      // Clear pending debounce timer
      if (objDebounceTimer) {
        clearTimeout(objDebounceTimer);
        objDebounceTimer = null;
      }

      // Abort in-flight objective request and cancel any pending evaluation generation
      if (typeof cancelPendingEvaluationGeneration === 'function') {
        cancelPendingEvaluationGeneration();
      }
      if (currentObjAbortController) {
        try { currentObjAbortController.abort(); } catch (e) {}
      }
      currentObjAbortController = new AbortController();

      const requestId = ++currentObjRequestId;
      lastRequestedObjTitle = currentTitle;
      lastRequestedObjTheme = currentTheme;
      isObjGenerating = true;
      renderObjectivesStatus('GENERATING');

      const payload = {
        title:               currentTitle,
        theme:               currentTheme,
        source:              (document.getElementById('f1_source')?.value || '').trim(),
        target_participants: (document.getElementById('f1_target_participants')?.value || '').trim(),
        involved_subjects:   (document.getElementById('f2_involved_subjects')?.value || '').trim(),
        rationale:           (document.getElementById('f2_rationale')?.value || '').trim()
      };

      const execPromise = (async () => {
        try {
          const res = await fetch('<?= BASE_URL ?>/api/generate-objectives.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            signal: currentObjAbortController.signal
          });

          // Stale check
          if (requestId !== currentObjRequestId || currentObjAbortController.signal.aborted) {
            return null;
          }

          // Verify title & theme haven't changed in the meantime
          const nowTitle = (titleEl.value || '').trim();
          const nowTheme = (themeEl ? themeEl.value.trim() : '');
          if (nowTitle.length >= 4 && (nowTitle !== currentTitle || nowTheme !== currentTheme)) {
            return null;
          }

          const data = await res.json();
          if (res.ok && data.success && data.general_objective && data.specific_objectives) {
            // Check manual edit protection again before writing (in case user edited while fetch was in flight)
            if (isObjectivesProtected && !forced) {
              renderObjectivesStatus('OUTDATED');
              return null;
            }

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
            if (typeof data.specific_objectives === 'string') {
              specEl.value = data.specific_objectives;
            } else {
              specEl.value = specVal;
            }

            // Dispatch input and change events so all listeners and validation recognize the new values
            genEl.dispatchEvent(new Event('input', { bubbles: true }));
            genEl.dispatchEvent(new Event('change', { bubbles: true }));
            specEl.dispatchEvent(new Event('input', { bubbles: true }));
            specEl.dispatchEvent(new Event('change', { bubbles: true }));

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

            // Also clear banner if step 2 was invalid
            const banner2 = document.getElementById('err-banner-2');
            if (banner2 && !document.querySelector('#panel-2 .is-invalid')) {
              banner2.style.display = 'none';
            }

            renderObjectivesStatus('UPDATED');

            if (requestId === currentObjRequestId) {
              isObjGenerating = false;
              setObjectiveSpinner(false);
            }

            if (typeof triggerAutomaticEvaluation === 'function') {
              triggerAutomaticEvaluation(true);
            }

            return {
              general_objective: data.general_objective,
              specific_objectives: specVal
            };
          } else {
            throw new Error(data.error || 'Empty or invalid response from AI.');
          }
        } catch (err) {
          if (err.name === 'AbortError') return null;
          if (requestId !== currentObjRequestId) return null;
          console.warn('autoGenerateObjectives error:', err);
          renderObjectivesStatus('ERROR', err.message);
          return null;
        } finally {
          if (requestId === currentObjRequestId) {
            isObjGenerating = false;
            currentObjPromise = null;
            setObjectiveSpinner(false);
          }
        }
      })();

      currentObjPromise = execPromise;
      return execPromise;
    }

    // Keep backwards compatibility for generateObjectives(forced)
    window.generateObjectives = function(forced = false) {
      return autoGenerateObjectives(forced);
    };
    window.autoGenerateObjectives = autoGenerateObjectives;
    window.forceRegenerateObjectives = forceRegenerateObjectives;

    // ── Automatic AI Theme Generation & Cascade Coordinator ───────────
    let isThemeManuallyEdited = false;
    let lastAiGeneratedTheme = '';
    let lastGeneratedTitle = '';
    let lastRequestedThemeTitle = '';
    let currentThemeRequestId = 0;
    let currentThemeAbortController = null;
    let themeDebounceTimer = null;
    let isThemeGenerating = false;
    let currentThemePromise = null;
    let isDraftSavingPending = false;
    let isBypassingThemeWaitOnDraft = false;

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
      currentThemePromise = null;
      setThemeSpinner(false);
    }

    function setupAutomaticThemeGeneration() {
      const titleEl = document.getElementById('f1_title');
      const themeEl = document.getElementById('f1_theme');
      if (!titleEl || !themeEl) return;

      const initialTheme = themeEl.value.trim();
      const initialTitle = titleEl.value.trim();
      lastGeneratedTitle = initialTitle;

      // Preserve existing theme on initial load without assuming manual edit
      if (initialTheme !== '') {
        lastAiGeneratedTheme = initialTheme;
        isThemeManuallyEdited = false;
        renderThemeStatus('SUCCESS');
      } else {
        isThemeManuallyEdited = false;
        renderThemeStatus('WAITING');
      }

      // Track manual edits by Faculty on the Theme input
      themeEl.addEventListener('input', () => {
        const val = themeEl.value.trim();
        if (val === '') {
          isThemeManuallyEdited = false;
          if (titleEl.value.trim().length >= 4) {
            cancelPendingThemeGeneration();
            cancelPendingObjectivesGeneration();
            themeDebounceTimer = setTimeout(() => autoGenerateTheme(true), 800);
          } else {
            renderThemeStatus('WAITING');
          }
        } else if (val !== lastAiGeneratedTheme) {
          isThemeManuallyEdited = true;
          renderThemeStatus('MANUAL');

          // Clean event hook with triggerSource: 'manual_theme'
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

      // Listen for typing on Event Title with 800ms debounce
      titleEl.addEventListener('input', () => {
        const newTitle = titleEl.value.trim();
        cancelPendingThemeGeneration();
        cancelPendingObjectivesGeneration(); // Cancel pending objectives when title changes!
        if (typeof cancelPendingEvaluationGeneration === 'function') {
          cancelPendingEvaluationGeneration();
        }

        // If title changed to a new activity context, reset evaluation tool to generating and clear manual edit flag
        if (newTitle !== lastGeneratedTitle) {
          isEvaluationEdited = false; // Reset manual edit flag for new activity context
          lastEvaluationHash = '';    // Reset hash so new topic generates cleanly
          if (newTitle.length >= 4 && typeof setEvaluationState === 'function') {
            setEvaluationState('GENERATING');
          }
        }

        // If title changed and objectives were AI generated (not manually protected), reset them so stale text doesn't linger
        if (!isObjectivesProtected && newTitle !== lastGeneratedTitle) {
          const genEl = document.getElementById('f2_general_objectives');
          const specEl = document.getElementById('f2_specific_objectives');
          if (genEl) genEl.value = '';
          if (specEl) specEl.value = '';
          lastAiGeneralObjective = '';
          lastAiSpecificObjectives = '';
          renderObjectivesStatus('WAITING');
        }

        // Clean event hook for Revision #4 & other listeners
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

        // If title hasn't changed, no action needed
        if (newTitle === lastGeneratedTitle) return;

        // If Faculty manually edited the theme, do NOT automatically overwrite!
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

        // Untouched AI theme or empty: debounce auto-generation (~800ms)
        themeDebounceTimer = setTimeout(() => {
          autoGenerateTheme(false);
        }, 800);
      });
    }

    async function autoGenerateTheme(forced = false) {
      const titleEl = document.getElementById('f1_title');
      const themeEl = document.getElementById('f1_theme');
      if (!titleEl || !themeEl) return null;

      const titleVal = titleEl.value.trim();
      if (titleVal.length < 4) return null;

      // Never overwrite manually edited theme unless forced by Faculty
      if (isThemeManuallyEdited && !forced) {
        renderThemeStatus('OUTDATED');
        return null;
      }

      // If already generating for this exact title and not forced, return existing in-flight promise
      if (isThemeGenerating && currentThemePromise && !forced && lastRequestedThemeTitle === titleVal) {
        return currentThemePromise;
      }

      // Clear any pending debounce timer
      if (themeDebounceTimer) {
        clearTimeout(themeDebounceTimer);
        themeDebounceTimer = null;
      }

      // Abort any in-flight request to prevent race conditions and cancel pending evaluation
      if (typeof cancelPendingEvaluationGeneration === 'function') {
        cancelPendingEvaluationGeneration();
      }
      if (currentThemeAbortController) {
        try { currentThemeAbortController.abort(); } catch (e) {}
      }
      currentThemeAbortController = new AbortController();

      const requestId = ++currentThemeRequestId;
      lastRequestedThemeTitle = titleVal;
      isThemeGenerating = true;
      renderThemeStatus('GENERATING');

      // Zero stale context: Theme generation strictly depends on Title and non-objective context.
      const payload = {
        title:               titleVal,
        source:              (document.getElementById('f1_source')?.value || '').trim(),
        target_participants: (document.getElementById('f1_target_participants')?.value || '').trim(),
        venue:               (document.getElementById('f1_venue')?.value || '').trim(),
        involved_subjects:   (document.getElementById('f2_involved_subjects')?.value || '').trim(),
        rationale:           (document.getElementById('f2_rationale')?.value || '').trim()
      };

      const actIdEl = document.querySelector('[name="activity_id"]');
      if (actIdEl && actIdEl.value) {
        payload.activity_id = parseInt(actIdEl.value, 10);
      }

      console.log('[TRACE autoGenerateTheme] Request #' + requestId + ' Payload:', JSON.stringify(payload));

      const execPromise = (async () => {
        try {
          const res = await fetch('<?= BASE_URL ?>/api/generate-theme.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            signal: currentThemeAbortController.signal
          });

          // Ignore stale response if superseded by a newer request or if title changed in the meantime
          const currentTitleNow = (titleEl ? titleEl.value.trim() : '');
          if (requestId !== currentThemeRequestId || currentThemeAbortController.signal.aborted || currentTitleNow !== titleVal) {
            return null;
          }

          const data = await res.json();
          if (res.ok && data.success && data.theme) {
            // Check again: if user manually edited the theme while fetch was in flight, do not overwrite!
            if (isThemeManuallyEdited && !forced) {
              renderThemeStatus('OUTDATED');
              return null;
            }

            lastAiGeneratedTheme = data.theme;
            lastGeneratedTitle = titleVal;
            isThemeManuallyEdited = false;
            try { sessionStorage.setItem('sti_ai_theme_' + data.theme.trim(), '1'); } catch (e) {}

            // Automatically populate / replace Theme input
            themeEl.value = data.theme;
            themeEl.dispatchEvent(new Event('change', { bubbles: true }));

            // Hide validation error if showing
            const errEl = document.getElementById('e_theme');
            if (errEl) errEl.style.display = 'none';
            themeEl.classList.remove('is-invalid');

            // Subtle green pulse on input
            themeEl.style.transition = 'box-shadow 0.3s ease, border-color 0.3s ease';
            themeEl.style.borderColor = '#22c55e';
            themeEl.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.2)';
            setTimeout(() => {
              themeEl.style.borderColor = '';
              themeEl.style.boxShadow = '';
            }, 1000);

            renderThemeStatus('SUCCESS');

            // Event hook: Dispatched ONCE for completed Title -> Theme cascade
            const currentTriggerSource = forced ? 'manual_theme_regen' : 'auto_theme';
            document.dispatchEvent(new CustomEvent('activityThemeChanged', {
              bubbles: true,
              detail: {
                theme: data.theme,
                title: titleVal,
                isAiGenerated: true,
                isManual: false,
                triggerSource: currentTriggerSource // triggerSource: 'auto_theme'
              }
            }));

            return data.theme;
          } else {
            throw new Error(data.error || 'Empty or invalid response from AI.');
          }
        } catch (err) {
          if (err.name === 'AbortError') return null;
          if (requestId !== currentThemeRequestId) return null;
          console.warn('autoGenerateTheme error:', err);
          renderThemeStatus('ERROR', err.message);
          return null;
        } finally {
          if (requestId === currentThemeRequestId) {
            isThemeGenerating = false;
            currentThemePromise = null;
            setThemeSpinner(false);
          }
        }
      })();

      currentThemePromise = execPromise;
      return execPromise;
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
          const themeInputCreate = document.getElementById('f1_theme');
          if (themeInputCreate) {
            themeInputCreate.readOnly = false;
            themeInputCreate.disabled = false;
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
          const titleElWaiting = document.getElementById('f1_title') || document.querySelector('[name="title"]');
          const themeElWaiting = document.getElementById('f1_theme') || document.querySelector('[name="theme"]');
          const curTitleWaiting = (titleElWaiting?.value || '').trim();
          const curThemeWaiting = (themeElWaiting?.value || '').trim();
          if (curTitleWaiting.length >= 4 && curThemeWaiting === '') {
            statusEl.innerHTML = `
              <div style="font-size: 0.8rem; display: flex; align-items: center; justify-content: flex-end; gap: 6px; flex-wrap: wrap;">
                <button type="button" onclick="forceRegenerateTheme()" style="background: none; border: none; color: #0284c7; cursor: pointer; text-decoration: underline; font-size: 0.8rem; padding: 0;">
                  ⚡ Generate Theme with AI
                </button>
              </div>`;
          } else {
            statusEl.innerHTML = '';
          }
          break;
      }
    }

    // Connect Theme changes to Objectives cascade
    document.addEventListener('activityThemeChanged', (e) => {
      const detail = e.detail || {};
      const titleEl = document.getElementById('f1_title');
      const curTitle = detail.title || (titleEl ? titleEl.value.trim() : '');
      const curTheme = (detail.theme || '').trim();

      if (curTitle.length < 4) return;

      if (detail.triggerSource === 'auto_theme') {
        // Title -> Theme completed. Exactly ONE objective generation call.
        cancelPendingObjectivesGeneration();
        if (isObjectivesProtected) {
          renderObjectivesStatus('OUTDATED');
        } else {
          autoGenerateObjectives(false, { title: curTitle, theme: curTheme });
        }
      } else if (detail.triggerSource === 'manual_theme_regen') {
        // Manual Theme regenerate -> exactly ONE objective generation call.
        cancelPendingObjectivesGeneration();
        if (typeof cancelPendingEvaluationGeneration === 'function') {
          cancelPendingEvaluationGeneration();
        }
        if (!isEvaluationEdited && typeof setEvaluationState === 'function') {
          setEvaluationState('GENERATING');
        }
        autoGenerateObjectives(true, { title: curTitle, theme: curTheme });
      } else if (detail.triggerSource === 'manual_theme') {
        // Manual theme edit -> debounced objective generation
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

    // Initialize listeners for manual objective editing
    function setupAutomaticObjectives() {
      const genEl = document.getElementById('f2_general_objectives');
      const specEl = document.getElementById('f2_specific_objectives');
      if (!genEl || !specEl) return;

      const initialGen = genEl.value.trim();
      const initialSpec = specEl.value.trim();

      // Preserve existing objectives on initial load without generating
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

    // Expose helpers globally for cross-module integration
    window.autoGenerateTheme = autoGenerateTheme;
    window.forceRegenerateTheme = forceRegenerateTheme;

    let isAutoCascadeInitialized = false;
    function initAutoThemeAndObjectives() {
      if (isAutoCascadeInitialized) return;
      isAutoCascadeInitialized = true;
      setupAutomaticThemeGeneration();
      setupAutomaticObjectives();
      setupEvaluationAutoUpdateListeners();
      const curTitle = (document.getElementById('f1_title')?.value || '').trim();
      const curTheme = (document.getElementById('f1_theme')?.value || '').trim();
      const curGen   = (document.getElementById('f2_general_objectives')?.value || '').trim();
      const curSpec  = (document.getElementById('f2_specific_objectives')?.value || '').trim();
      lastEvaluationHash = JSON.stringify({ curTitle, curTheme, curGen, curSpec });
      if (!curTitle || curTitle.length < 4 || !curTheme || (!curGen && !curSpec)) {
        if (!questionsList || questionsList.length === 0) {
          setEvaluationState('EMPTY', 'Please complete the activity title, theme, and objectives to generate evaluation questions.');
        }
      }
    }

    if (document.readyState === 'interactive' || document.readyState === 'complete') {
      initAutoThemeAndObjectives();
    } else {
      document.addEventListener('DOMContentLoaded', initAutoThemeAndObjectives);
    }

    // ── CSS for spinner ────────────────────────────────────────────────
    (function() {
      const s = document.createElement('style');
      s.textContent = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
      document.head.appendChild(s);
    })();

    // Clicking a step indicator: only allowed if already unlocked
    document.querySelectorAll('.wizard-step').forEach(s => {
      s.addEventListener('click', () => {
        const target = parseInt(s.dataset.step);
        if (s.classList.contains('locked')) return; // blocked
        // Also validate current step before jumping forward
        if (target > currentStep && !validateStep(currentStep)) return;
        showStep(target);
      });
    });

    // ── Step Validators ───────────────────────────────────────────────
    function validateStep(step) {
      let valid = true;
      hideBanner(step);

      if (step === 1) {
        if (!checkEventDateField()) valid = false;
        if (!validateTimeFields(true)) valid = false;
        const fields = [{
            id: 'f1_title',
            err: 'e_title'
          },
          {
            id: 'f1_source',
            err: 'e_source'
          },
          {
            id: 'f1_venue',
            err: 'e_venue'
          },
          {
            id: 'f1_venue_address',
            err: 'e_venue_address'
          },
          {
            id: 'f1_theme',
            err: 'e_theme'
          }
        ];
        fields.forEach(f => {
          if (!checkField(f.id, f.err)) valid = false;
        });

        // Validate target participants dynamic table
        updateTotalTargetParticipants();
        const partRows = document.querySelectorAll('#participant-sections-body tr.participant-row');
        if (partRows.length === 0) {
          valid = false;
          showError('e_target_participants');
        } else {
          let hasEmptyPart = false;
          partRows.forEach(row => {
            const secInput = row.querySelector('.part-section-input');
            const countInput = row.querySelector('.part-count-input');
            if (secInput && !secInput.value.trim()) {
              secInput.classList.add('is-invalid');
              hasEmptyPart = true;
              valid = false;
            } else if (secInput) {
              secInput.classList.remove('is-invalid');
            }
            if (countInput) {
              const cnt = parseInt(countInput.value, 10);
              if (isNaN(cnt) || cnt < 0 || countInput.value.trim() === '') {
                countInput.classList.add('is-invalid');
                hasEmptyPart = true;
                valid = false;
              } else {
                countInput.classList.remove('is-invalid');
              }
            }
          });

          const totalVal = parseInt(document.getElementById('f1_target_participants')?.value || '0', 10);
          if (totalVal <= 0 || hasEmptyPart) {
            valid = false;
            showError('e_target_participants');
          } else {
            hideError('e_target_participants');
          }
        }

        if (!validatePosterInput('f1_poster_file', 'e_poster_file')) valid = false;
      } else if (step === 2) {
        let hasEmptyRequired = false;
        let hasContentError = false;
        const fields = [{
            id: 'f2_general_objectives',
            err: 'e_general_objectives'
          },
          {
            id: 'f2_specific_objectives',
            err: 'e_specific_objectives'
          },
          {
            id: 'f2_involved_subjects',
            err: 'e_involved_subjects'
          },
          {
            id: 'f2_rationale',
            err: 'e_rationale'
          }
        ];
        fields.forEach(f => {
          const el = document.getElementById(f.id);
          const val = el ? el.value.trim() : '';
          if (!val) {
            hasEmptyRequired = true;
          }
          if (!checkField(f.id, f.err)) {
            valid = false;
            if (val) {
              hasContentError = true;
            }
          }
        });
        const banner2 = document.getElementById('err-banner-2');
        if (banner2) {
          if (!banner2.hasAttribute('data-default-msg')) {
            banner2.setAttribute('data-default-msg', banner2.textContent);
          }
          if (!valid && hasContentError && !hasEmptyRequired) {
            banner2.textContent = '⚠️ Please review your objectives and enter valid, meaningful text before continuing.';
          } else {
            banner2.textContent = banner2.getAttribute('data-default-msg');
          }
        }
      } else if (step === 3) {
        // Every text/number input in materials rows must be filled
        const rows = document.querySelectorAll('#materials-body tr');
        rows.forEach(row => {
          row.querySelectorAll('input.mat-field, input.mat-qty, input.mat-cost').forEach(inp => {
            const v = inp.value.trim();
            if (!v) {
              inp.classList.add('is-invalid');
              valid = false;
            } else if (inp.classList.contains('mat-field') && hasGibberishText(v)) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        });
      } else if (step === 4) {
        const fpInput = document.getElementById('floor_plan_required');
        const isFpNotRequired = fpInput && (fpInput.value === 'no' || fpInput.value === '0');
        if (isFpNotRequired) {
          hideBanner(4);
          const wrap = document.getElementById('fp-canvas-invalid-wrap');
          if (wrap) wrap.classList.remove('fp-canvas-invalid');
          const notesEl = document.getElementById('f3_floor_plan_notes');
          if (notesEl) notesEl.classList.remove('is-invalid');
          const notesErr = document.getElementById('e_floor_plan_notes');
          if (notesErr) notesErr.style.display = 'none';
          return true;
        }

        const fileInput = document.getElementById('floor_plan_file');
        const hasSelectedFile = fileInput && fileInput.files && fileInput.files.length > 0;
        const hasExistingFile = document.getElementById('has_existing_floor_plan_file')
          ? document.getElementById('has_existing_floor_plan_file').value === '1'
          : false;
        const hasFpFile = hasSelectedFile || hasExistingFile;

        if (window.fpSaveToHidden) {
          window.fpSaveToHidden();
        }
        const canvasData = document.getElementById('fp-canvas-data') ? document.getElementById('fp-canvas-data').value : '';
        let hasShapes = false;
        try {
          const parsed = JSON.parse(canvasData);
          hasShapes = (parsed && parsed.shapes && parsed.shapes.length > 0) ||
                      (parsed && parsed.floors && Object.values(parsed.floors).some(f => f.shapes && f.shapes.length > 0));
        } catch (e) {
          hasShapes = false;
        }

        const wrap = document.getElementById('fp-canvas-invalid-wrap');
        const banner4 = document.getElementById('err-banner-4');
        const notesEl = document.getElementById('f3_floor_plan_notes');
        const notesErr = document.getElementById('e_floor_plan_notes');

        // Case 1: User uploaded a valid floor plan file -> notes are not required
        if (hasFpFile) {
          hideBanner(4);
          if (wrap) wrap.classList.remove('fp-canvas-invalid');
          if (notesEl) notesEl.classList.remove('is-invalid');
          if (notesErr) notesErr.style.display = 'none';
          if (notesEl && notesEl.value.trim() !== '') {
            if (!checkField('f3_floor_plan_notes', 'e_floor_plan_notes')) {
              valid = false;
            }
          }
          return valid;
        }

        // Case 2: Neither uploaded file nor created floor plan shapes provided -> block continuation
        if (!hasShapes) {
          if (wrap) wrap.classList.add('fp-canvas-invalid');
          if (banner4) {
            banner4.textContent = '⚠️ Please upload a floor plan file or draw your layout on the canvas before continuing.';
          }
          showBanner(4);
          valid = false;
          return false;
        }

        // Case 3: Created floor plan using integrated creator -> notes/description MUST be required
        if (wrap) wrap.classList.remove('fp-canvas-invalid');
        if (!checkField('f3_floor_plan_notes', 'e_floor_plan_notes')) {
          if (banner4) {
            banner4.textContent = '⚠️ Floor plan notes / description are required when using the floor plan creator.';
          }
          showBanner(4);
          valid = false;
          return false;
        }
      } else if (step === 5) {
        const rows = document.querySelectorAll('#manpower-body tr');
        rows.forEach(row => {
          row.querySelectorAll('input.mp-person-field').forEach(inp => {
            const v = inp.value.trim();
            if (!v || hasGibberishText(v)) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        });
      } else if (step === 6) {
        const schedRows = document.querySelectorAll('#schedule-body tr');
        schedRows.forEach(row => {
          row.querySelectorAll('input.sched-field').forEach(inp => {
            const v = inp.value.trim();
            if (!v) {
              inp.classList.add('is-invalid');
              valid = false;
            } else if (inp.type === 'date' && inp.value <= todayManila) {
              inp.classList.add('is-invalid');
              valid = false;
            } else if (inp.type === 'text' && hasGibberishText(v)) {
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
            const v = inp.value.trim();
            if (!v) {
              inp.classList.add('is-invalid');
              valid = false;
            } else if (inp.type === 'text' && hasGibberishText(v)) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        });
      } else if (step === 7) {
        [{
            id: 'f7_mechanics',
            err: 'e_mechanics'
          },
          {
            id: 'f7_criteria',
            err: 'e_criteria'
          },
          {
            id: 'f7_scoring',
            err: 'e_scoring'
          },
          {
            id: 'f7_awards',
            err: 'e_awards'
          },
        ].forEach(f => {
          if (!checkField(f.id, f.err)) valid = false;
        });
      } else if (step === 8) {
        const rows = document.querySelectorAll('#faculty-tasks-body tr');
        rows.forEach(row => {
          row.querySelectorAll('input.ft-field').forEach(inp => {
            const v = inp.value.trim();
            if (!v || hasGibberishText(v)) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        });
      } else if (step === 9) {
        // Evaluation Tool questions validation
        const evalValid = validateEvaluationSidePanel();
        if (!evalValid) {
          valid = false;
        }
        // Eval form link
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
      } else if (step === 10) {
        // All evaluation question text inputs
        const questionInputs = document.querySelectorAll('#eval-questions-list input[type="text"]');
        if (questionInputs.length === 0) {
          valid = false;
        } else {
          questionInputs.forEach(inp => {
            if (!inp.value.trim()) {
              inp.classList.add('is-invalid');
              valid = false;
            } else {
              inp.classList.remove('is-invalid');
            }
          });
        }
      }

      if (!valid) showBanner(step);
      return valid;
    }

    // ── Helpers ───────────────────────────────────────────────────────
    function checkEventDateField() {
      const el = document.getElementById('f1_event_date');
      if (!el) return true;
      const val = el.value.trim();
      if (!val) {
        el.classList.add('is-invalid');
        showError('e_event_date');
        hideError('e_event_date_past');
        return false;
      }
      if (val <= todayManila) {
        el.classList.add('is-invalid');
        hideError('e_event_date');
        showError('e_event_date_past');
        return false;
      }
      el.classList.remove('is-invalid');
      hideError('e_event_date');
      hideError('e_event_date_past');
      return true;
    }

    function isGibberishToken(token) {
      token = token.replace(/^[ \t\n\r\v,.:;!?"'()[\]{}/*\-+=@#$%^&<>~`|]+|[ \t\n\r\v,.:;!?"'()[\]{}/*\-+=@#$%^&<>~`|]+$/g, '');
      if (token.length < 3) return false;

      // If token has no letters at all (pure numbers, time like '8:00', '100%', '10,000'), not gibberish
      if (!/[A-Za-z]/.test(token)) return false;

      // Allow standard alphanumeric codes/course codes (e.g. 'CS101', 'ITE314', 'GE102', 'ACT-101')
      if (/^[A-Za-z]{2,5}-?\d{1,4}[A-Za-z]?$/i.test(token)) return false;

      // Allow ordinal numbers (e.g. '1st', '2nd', '3rd', '4th')
      if (/^\d+(st|nd|rd|th)$/i.test(token)) return false;

      // Allow standard measurement, paper, volume, duration and package units attached to numbers
      // (e.g. '220gsm', '500ml', '10kg', '250g', '50pcs', '1080p', '100m', '30fps', '60hz', '32gb', '500mb', '100w')
      if (/^\d+(\.\d+)?(gsm|kg|g|mg|lbs?|oz|ml|l|cm|mm|m|km|in|ft|yd|pcs?|pk|pks|box|boxes|sets?|rolls?|packs?|px|pt|dpi|kb|mb|gb|tb|hz|khz|mhz|ghz|fps|k|v|w|kw|a|ma|rpm|php|usd|eur|gbp)$/i.test(token)) {
        return false;
      }

      // Allow all-uppercase abbreviations up to 6 characters and common pluralized acronyms (e.g. 'JPCS', 'AWS', 'STI', 'PDRRMC', 'SSG', 'UNESCO', 'SDGs', 'KPIs')
      if (/^[A-Z]{2,6}s?$/.test(token)) return false;

      // For compound words separated by hyphens, slashes, or underscores (e.g. 'Pitch/Presentation', 'world-class', 'Input/Output', 'peer-to-peer', 'and/or'),
      // evaluate each component individually so letters are not concatenated across word boundaries.
      if (/[\-\/\\_]/.test(token)) {
        const subTokens = token.split(/[\-\/\\_]+/).map(p => p.trim()).filter(p => p.length > 0);
        for (const sub of subTokens) {
          const subClean = sub.replace(/^[\s,.:;!?"'()[\]{}/*\-+=@#$%^&<>~`|]+|[\s,.:;!?"'()[\]{}/*\-+=@#$%^&<>~`|]+$/g, '');
          if (subClean.length >= 3 && isGibberishToken(subClean)) {
            return true;
          }
        }
        return false;
      }

      const lower = token.toLowerCase();
      // Extract letter portion for checks when numbers or symbols are appended (e.g. 'fsdfsd123', 'asdd99')
      const letters = lower.replace(/[^a-z]/g, '');

      // 1. Must contain at least one vowel (a, e, i, o, u, y) if length of letters >= 3.
      // If only 'y' is present (no a, e, i, o, u) and length >= 6, it is gibberish unless whitelisted (e.g. 'rhythm', 'rhythms').
      if (letters.length >= 3 && !/[aeiouy]/.test(letters)) {
        return true;
      }
      if (letters.length >= 6 && !/[aeiou]/.test(letters)) {
        const yWhitelist = ['rhythm', 'rhythms'];
        if (!yWhitelist.includes(letters)) return true;
      }

      // 2. Obvious keyboard walks / mashing runs (3+ char runs)
      if (/asdf|fdsa|qwerty|ytrewq|zxcvb|bvcxz|hjkl|lkjh|qwer|rewq|ghjk|kjhg|dfgh|fghj|poiuy/i.test(lower)) {
        return true;
      }

      // 3. Repeated characters: 3+ identical characters (e.g. 'aaa', 'zzz') or repeated pairs (e.g. 'asdasd', 'fsdfsd')
      if (/(.)\1{2,}/i.test(lower)) return true;
      if (/(.{2,3})\1{2,}/i.test(letters)) {
        return true;
      }

      // 4. Consecutive consonants: 5+ consecutive consonants in a word (unless whitelisted)
      const consonants = 'bcdfghjklmnpqrstvwxz'; // exclude y as semi-vowel
      if (new RegExp('[' + consonants + ']{5,}', 'i').test(letters)) {
        const whitelist = ['angst', 'strengths', 'twelfths', 'catchphrase', 'knights', 'lengths', 'birthplace', 'instinct', 'abstract', 'construct', 'instruct'];
        if (!whitelist.includes(letters)) return true;
      }

      // 5. Home-row letters [asdfghjkl] keyboard-mash heuristics
      // Flag actual keyboard walks, mash patterns, or consonant clustering rather than all natural words/names made of home-row letters.
      if (letters.length >= 3 && /^[asdfghjkl]+$/.test(letters)) {
        // Unambiguous keyboard mash runs and sequences
        if (/^asd+$/i.test(letters) || /^dsa+$/i.test(letters) || /^asda/i.test(letters)) {
          return true;
        }
        if (/asdf|fdsa|hjkl|lkjh|ghjk|kjhg|dfgh|fghj|sdfg|gfds/i.test(letters)) {
          return true;
        }
        // 4+ consecutive home-row consonants without vowels (e.g. 'dfgh', 'hjkl', 'skld')
        if (/[dfghjkl]{4,}/i.test(letters)) {
          return true;
        }
        // Obvious repeated home-row n-grams (e.g. 'asdasd', 'fdasfda', 'hklhkl')
        if (/^([asdfghjkl]{2,3})\1+$/i.test(letters)) {
          return true;
        }
        // Words 5+ chars long without any vowel
        if (letters.length >= 5 && !/[aeiouy]/i.test(letters)) {
          return true;
        }
      }

      // 6. Pure top-row mash [qwertyuiop] (length >= 3)
      if (letters.length >= 3 && /^[qwertyuiop]+$/.test(letters)) {
        if (['queue', 'queuer'].includes(letters)) return false;
        // Flag structural keyboard-mash characteristics:
        // - 3+ consecutive top-row consonants (e.g. 'qwtp', 'prtq', 'wrtp' - never occurring in English words)
        // - 'q' not followed by 'u' (e.g. 'qwer', 'qwe', 'tq', 'qpo')
        // - 4+ consecutive vowels (e.g. 'uioe', 'eouy')
        // - top-row keyboard walks
        if (/[wrtpq]{3,}/i.test(letters) || /q(?!u)/i.test(letters) || /[aeiouy]{4,}/i.test(letters) || /werty|trewq|poiuy|uytre/i.test(letters)) {
          return true;
        }
      }

      return false;
    }

    function hasGibberishText(val) {
      if (!val) return false;
      const words = val.trim().split(/\s+/);
      for (const w of words) {
        if (isGibberishToken(w)) return true;
      }
      return false;
    }

    function isDescriptiveField(fieldId) {
      return ['f2_general_objectives', 'f2_specific_objectives', 'f2_rationale', 'f3_floor_plan_notes', 'f7_mechanics', 'f7_criteria', 'f7_scoring'].includes(fieldId);
    }

    function checkField(fieldId, errId) {
      const el = document.getElementById(fieldId);
      const errEl = document.getElementById(errId);
      if (!el) return true;
      const val = el.value.trim();
      if (!val) {
        el.classList.add('is-invalid');
        if (errEl) {
          if (!errEl.hasAttribute('data-default-msg')) {
            errEl.setAttribute('data-default-msg', errEl.textContent);
          }
          errEl.textContent = errEl.getAttribute('data-default-msg');
        }
        showError(errId);
        return false;
      }

      // Check gibberish
      const words = val.split(/\s+/);
      let isGibberish = false;
      for (const w of words) {
        if (isGibberishToken(w)) {
          isGibberish = true;
          break;
        }
      }

      const isDescriptive = isDescriptiveField(fieldId);
      if (isDescriptive && (val.length < 10 || words.length < 2)) {
        isGibberish = true;
      }

      if (isGibberish) {
        el.classList.add('is-invalid');
        if (errEl) {
          if (!errEl.hasAttribute('data-default-msg')) {
            errEl.setAttribute('data-default-msg', errEl.textContent);
          }
          errEl.textContent = isDescriptive && (val.length < 10 || words.length < 2)
            ? 'Please provide a substantive description (at least 10 characters and 2 words).'
            : 'Please enter valid, meaningful text (avoid random characters or keyboard mash).';
        }
        showError(errId);
        return false;
      }

      el.classList.remove('is-invalid');
      if (errEl && errEl.hasAttribute('data-default-msg')) {
        errEl.textContent = errEl.getAttribute('data-default-msg');
      }
      hideError(errId);
      return true;
    }

    function validatePosterInput(fieldId, errId) {
      const el = document.getElementById(fieldId);
      const errEl = document.getElementById(errId);
      if (!el) return true;

      if (!el.value || !el.files || el.files.length === 0) {
        if (el.required) {
          el.classList.add('is-invalid');
          if (errEl) {
            errEl.textContent = 'Event poster is required.';
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
        errEl.textContent = 'Event poster is required.';
        errEl.classList.remove('visible');
      }
      return true;
    }

    const posterInputCreate = document.getElementById('f1_poster_file');
    if (posterInputCreate) {
      posterInputCreate.addEventListener('change', function() {
        validatePosterInput('f1_poster_file', 'e_poster_file');
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
      if (b) {
        b.classList.remove('visible');
        if (b.hasAttribute('data-default-msg')) {
          b.textContent = b.getAttribute('data-default-msg');
        }
      }
    }

    function isValidUrl(str) {
      try {
        new URL(str);
        return true;
      } catch {
        return false;
      }
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

    // Direct event listeners for live clearing and order validation on time inputs
    const startTimeInputEl = document.getElementById('f1_start_time');
    const endTimeInputEl = document.getElementById('f1_end_time');
    ['input', 'change'].forEach(evt => {
      if (startTimeInputEl) {
        startTimeInputEl.addEventListener(evt, () => validateTimeFields(false));
      }
      if (endTimeInputEl) {
        endTimeInputEl.addEventListener(evt, () => validateTimeFields(false));
      }
    });

    // Clear invalid state on input
    document.addEventListener('input', e => {
      if (e.target.id === 'f1_event_date') {
        checkEventDateField();
        return;
      }
      if (e.target.id === 'f1_start_time' || e.target.id === 'f1_end_time') {
        validateTimeFields(false);
        return;
      }
      if (e.target.classList.contains('is-invalid') && e.target.value.trim()) {
        const fieldErrorMap = {
          'f1_title': 'e_title',
          'f1_venue': 'e_venue',
          'f1_target_participants': 'e_target_participants',
          'f1_venue_address': 'e_venue_address',
          'f1_theme': 'e_theme',
          'f2_general_objectives': 'e_general_objectives',
          'f2_specific_objectives': 'e_specific_objectives',
          'f2_involved_subjects': 'e_involved_subjects',
          'f2_rationale': 'e_rationale',
          'f3_floor_plan_notes': 'e_floor_plan_notes',
          'f7_mechanics': 'e_mechanics',
          'f7_criteria': 'e_criteria',
          'f7_scoring': 'e_scoring',
          'f7_awards': 'e_awards'
        };
        if (fieldErrorMap[e.target.id]) {
          checkField(e.target.id, fieldErrorMap[e.target.id]);
        } else if (e.target.type === 'text' || e.target.tagName === 'TEXTAREA') {
          if (!hasGibberishText(e.target.value)) {
            e.target.classList.remove('is-invalid');
          }
        } else {
          e.target.classList.remove('is-invalid');
        }
      }
    });
    document.addEventListener('change', e => {
      if (e.target.id === 'f1_event_date') {
        checkEventDateField();
        return;
      }
      if (e.target.id === 'f1_start_time' || e.target.id === 'f1_end_time') {
        validateTimeFields(false);
        return;
      }
      if (e.target.classList.contains('is-invalid') && e.target.value.trim()) {
        const fieldErrorMap = {
          'f1_title': 'e_title',
          'f1_venue': 'e_venue',
          'f1_target_participants': 'e_target_participants',
          'f1_venue_address': 'e_venue_address',
          'f1_theme': 'e_theme',
          'f2_general_objectives': 'e_general_objectives',
          'f2_specific_objectives': 'e_specific_objectives',
          'f2_involved_subjects': 'e_involved_subjects',
          'f2_rationale': 'e_rationale',
          'f3_floor_plan_notes': 'e_floor_plan_notes',
          'f7_mechanics': 'e_mechanics',
          'f7_criteria': 'e_criteria',
          'f7_scoring': 'e_scoring',
          'f7_awards': 'e_awards'
        };
        if (fieldErrorMap[e.target.id]) {
          checkField(e.target.id, fieldErrorMap[e.target.id]);
        } else if (e.target.type === 'text' || e.target.tagName === 'TEXTAREA') {
          if (!hasGibberishText(e.target.value)) {
            e.target.classList.remove('is-invalid');
          }
        } else {
          e.target.classList.remove('is-invalid');
        }
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

    // ── Form submit validation ────────────────────────────────────────
    document.getElementById('proposalForm').addEventListener('submit', function(e) {
      serializeEvaluationQuestions();
      if (window.fpSaveToHidden) {
        window.fpSaveToHidden();
      }

      const actionInputEl = document.getElementById('proposalFormAction');
      if (e.submitter && e.submitter.value) {
        if (actionInputEl) actionInputEl.value = e.submitter.value;
      }
      const isDraft = e.submitter
        ? (e.submitter.value === 'draft')
        : (actionInputEl ? actionInputEl.value === 'draft' : false);

      if (isDraft) {
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
          return;
        }

        // Prevent duplicate draft submissions
        if (isDraftSavingPending) {
          e.preventDefault();
          return;
        }

        // If theme wait was already completed or bypassed, let native submission proceed
        if (isBypassingThemeWaitOnDraft) {
          return;
        }

        // Check if Section 1 (Theme) or Section 2 (Objectives) generation is pending or in-flight
        const curTitle = (document.getElementById('f1_title')?.value || '').trim();
        const curTheme = (document.getElementById('f1_theme')?.value || '').trim();
        const curGen = (document.getElementById('f2_general_objectives')?.value || '').trim();
        const curSpec = (document.getElementById('f2_specific_objectives')?.value || '').trim();

        const isThemePendingOrInFlight = (themeDebounceTimer !== null) || isThemeGenerating || (currentThemePromise !== null);
        const isEligibleEmptyTheme = (curTitle.length >= 4 && curTheme === '' && !isThemeManuallyEdited);
        const isObjPendingOrInFlight = (objDebounceTimer !== null) || isObjGenerating || (currentObjPromise !== null);
        const isEligibleEmptyObj = (curTitle.length >= 4 && curGen === '' && curSpec === '' && !isObjectivesProtected);

        if (isThemePendingOrInFlight || isEligibleEmptyTheme || isObjPendingOrInFlight || isEligibleEmptyObj) {
          e.preventDefault();
          isDraftSavingPending = true;

          const draftBtns = document.querySelectorAll('button[name="action"][value="draft"], button[type="submit"][value="draft"]');
          const submitBtn = document.getElementById('submitBtn');
          draftBtns.forEach(btn => {
            btn.dataset.origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving (waiting for AI Content...)';
          });
          if (submitBtn) submitBtn.disabled = true;

          // If Theme debounce was waiting, cancel debounce and kick off generation immediately
          if (themeDebounceTimer !== null) {
            clearTimeout(themeDebounceTimer);
            themeDebounceTimer = null;
            currentThemePromise = autoGenerateTheme(false);
          } else if (!isThemeGenerating && isEligibleEmptyTheme) {
            currentThemePromise = autoGenerateTheme(false);
          }

          // If Objectives debounce was waiting, trigger immediate generation
          if (objDebounceTimer !== null) {
            clearTimeout(objDebounceTimer);
            objDebounceTimer = null;
            currentObjPromise = autoGenerateObjectives(false);
          } else if (!isObjGenerating && isEligibleEmptyObj) {
            currentObjPromise = autoGenerateObjectives(false);
          }

          (async () => {
            if (currentThemePromise) {
              try {
                await currentThemePromise;
              } catch (waitErr) {
                console.warn('Error waiting for theme generation:', waitErr);
              }
            }

            if (currentObjPromise) {
              try {
                await currentObjPromise;
              } catch (waitObjErr) {
                console.warn('Error waiting for objectives generation:', waitObjErr);
              }
            }

            const finalThemeEl = document.getElementById('f1_theme');
            const finalThemeVal = finalThemeEl ? finalThemeEl.value.trim() : '';

            if (finalThemeVal === '' && isEligibleEmptyTheme) {
              const msg = "⚠️ AI Theme generation could not be completed at this time.\n\nDo you want to save this proposal as a draft with an empty theme for now?";
              const proceed = window.STIModal
                ? await window.STIModal.confirm(msg, { title: 'Save Draft Without AI Theme?', confirmText: 'Save Draft', cancelText: 'Cancel', variant: 'warning' })
                : confirm(msg);
              if (!proceed) {
                isDraftSavingPending = false;
                draftBtns.forEach(btn => {
                  if (btn.dataset.origText) btn.innerHTML = btn.dataset.origText;
                  btn.disabled = false;
                });
                if (submitBtn) submitBtn.disabled = false;
                return;
              }
            }

            serializeEvaluationQuestions();
            if (window.fpSaveToHidden) {
              window.fpSaveToHidden();
            }

            const actionInput = document.getElementById('proposalFormAction');
            if (actionInput) actionInput.value = 'draft';

            isBypassingThemeWaitOnDraft = true;
            const formEl = document.getElementById('proposalForm');
            HTMLFormElement.prototype.submit.call(formEl);
          })();

          return;
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
        showStep(9);
        alert('⚠️ Please review the AI-Generated Evaluation Tool questions in Step 9 before submitting.');
        return;
      }
    });

    // ── Dynamic row management ────────────────────────────────────────
    const facultyOptionsHtml = `<?= addslashes(renderFacultyTaskOptgroups($facultyDepartmentGroups, $facultyUsers)) ?>`;

    function syncFtName(sel) {
      const tr = sel.closest('tr');
      if (!tr) return;
      const nameInput = tr.querySelector('input.ft-name-field');
      if (!nameInput) return;
      const opt = sel.options[sel.selectedIndex];
      if (opt && opt.dataset.name) {
        nameInput.value = opt.dataset.name;
        nameInput.readOnly = true;
        nameInput.style.backgroundColor = 'var(--bg-muted, #f8fafc)';
      } else {
        nameInput.value = '';
        nameInput.readOnly = false;
        nameInput.style.backgroundColor = '';
      }
    }

    const rowTemplates = {
      materials: `
        <tr>
          <td><input type="text" name="mat_item[]" class="form-control mat-field" placeholder="e.g. Chairs"></td>
          <td><input type="text" name="mat_desc[]" class="form-control mat-field" placeholder="Description"></td>
          <td><input type="number" name="mat_qty[]" class="form-control mat-qty" value="" min="1" placeholder="1"></td>
          <td><input type="text" name="mat_provider[]" class="form-control mat-field" placeholder="Supplier name"></td>
          <td><input type="number" name="mat_cost[]" class="form-control mat-cost" placeholder="0.00" step="0.01" min="0"></td>
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
            <select name="mp_role[]" class="form-control" onchange="updateManpowerSummary()">
              <option value="Medic">Medic</option>
              <option value="Customer Service">Customer Service</option>
              <option value="Usher">Usher</option>
              <option value="Marshall">Marshall</option>
              <option value="Emcee">Emcee</option>
              <option value="Floor Director">Floor Director</option>
              <option value="Sound & AVP Support">Sound & AVP Support</option>
              <option value="Tabulators">Tabulators</option>
              <option value="Photographers">Photographers</option>
              <option value="Videographers">Videographers</option>
              <option value="Expected Guest(s)">Expected Guest(s)</option>
              <option value="Students' Audience">Students' Audience</option>
              <option value="Adviser(s) / Teacher(s) / Staff(s)">Adviser(s) / Teacher(s) / Staff(s)</option>
              <option value="VIP">VIP</option>
              <option value="Sponsor(s)">Sponsor(s)</option>
            </select>
          </td>
          <td><input type="text" name="mp_person[]" class="form-control mp-person-field" placeholder="Full name" oninput="updateManpowerSummary()"></td>
          <td>
            <select name="mp_type[]" class="form-control" onchange="updateManpowerSummary()">
              <option value="faculty">Faculty</option>
              <option value="staff">Staff</option>
              <option value="student">Student</option>
            </select>
          </td>
          <td><button type="button" class="btn btn-outline btn-sm" onclick="removeRow(this); updateManpowerSummary();">✕</button></td>
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
          <td>
            <select name="ft_user_id[]" class="form-control ft-user-select" onchange="syncFtName(this)">
              <option value="">-- Unassigned / External --</option>
              ${facultyOptionsHtml}
            </select>
            <input type="text" name="ft_name[]" class="form-control ft-name-field" style="margin-top: 4px; font-size: 0.8rem;" placeholder="External name (if unassigned)">
          </td>
          <td><input type="text" name="ft_task[]" class="form-control ft-field" placeholder="Assigned task"></td>
          <td><input type="text" name="ft_contribution[]" class="form-control ft-field" placeholder="Contribution description"></td>
          <td><input type="text" name="ft_role[]" class="form-control ft-field" placeholder="Role"></td>
          <td><input type="date" name="ft_due_date[]" class="form-control ft-date-field"></td>
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
      if (table === 'manpower') updateManpowerSummary();
    }

    function removeRow(btn) {
      const tr = btn.closest('tr');
      const tbody = tr.parentElement;
      if (tbody.querySelectorAll('tr').length > 1) {
        tr.remove();
        if (tbody.id === 'materials-body') updateTotal();
        if (tbody.id === 'manpower-body') updateManpowerSummary();
      }
    }

    // ── Manpower Summary ───────────────────────────────────────────────
    function updateManpowerSummary() {
      const summaryBody = document.getElementById('manpower-summary-body');
      if (!summaryBody) return;

      const MANPOWER_ROLES = [
        "Medic",
        "Customer Service",
        "Usher",
        "Marshall",
        "Emcee",
        "Floor Director",
        "Sound & AVP Support",
        "Tabulators",
        "Photographers",
        "Videographers",
        "Expected Guest(s)",
        "Students' Audience",
        "Adviser(s) / Teacher(s) / Staff(s)",
        "VIP",
        "Sponsor(s)"
      ];

      const rows = document.querySelectorAll('#manpower-body tr');
      const countsByRole = {};
      const uniquePersons = new Set();

      rows.forEach((tr, index) => {
        const roleSel = tr.querySelector('select[name="mp_role[]"]');
        const personInp = tr.querySelector('input[name="mp_person[]"]');
        const typeSel = tr.querySelector('select[name="mp_type[]"]');

        const role = roleSel ? roleSel.value.trim() : '';
        const person = personInp ? personInp.value.trim() : '';
        const type = typeSel ? typeSel.value.trim().toLowerCase() : 'faculty';

        if (!role || !person) return;

        if (!countsByRole[role]) {
          countsByRole[role] = {
            faculty: new Set(),
            staff: new Set(),
            student: new Set()
          };
        }

        const personKey = person.toLowerCase();
        uniquePersons.add(personKey);

        if (type === 'staff') {
          countsByRole[role].staff.add(personKey);
        } else if (type === 'student') {
          countsByRole[role].student.add(personKey);
        } else {
          countsByRole[role].faculty.add(personKey);
        }
      });

      const presentRoles = [];
      MANPOWER_ROLES.forEach(r => {
        if (countsByRole[r]) presentRoles.push(r);
      });
      Object.keys(countsByRole).forEach(r => {
        if (!presentRoles.includes(r)) presentRoles.push(r);
      });

      summaryBody.innerHTML = '';

      let colFaculty = 0;
      let colStaff = 0;
      let colStudents = 0;
      let colTotal = 0;

      if (presentRoles.length === 0) {
        summaryBody.innerHTML = `
          <tr>
            <td colspan="5" style="text-align: center; color: #64748b; font-size: 0.82rem; padding: 14px; background: #ffffff;">
              No manpower assigned yet.
            </td>
          </tr>
        `;
      } else {
        presentRoles.forEach(role => {
          const fCount = countsByRole[role].faculty.size;
          const sCount = countsByRole[role].staff.size;
          const stuCount = countsByRole[role].student.size;
          const rTotal = fCount + sCount + stuCount;

          colFaculty += fCount;
          colStaff += sCount;
          colStudents += stuCount;
          colTotal += rTotal;

          const tr = document.createElement('tr');
          tr.style.borderBottom = '1px solid #f1f5f9';
          tr.style.background = '#ffffff';
          tr.innerHTML = `
            <td style="padding: 10px 14px; font-size: 0.82rem; color: #1e293b; background: #ffffff;">${escapeHtml(role)}</td>
            <td style="padding: 10px 14px; font-size: 0.82rem; color: #334155; text-align: center; background: #ffffff;">${fCount}</td>
            <td style="padding: 10px 14px; font-size: 0.82rem; color: #334155; text-align: center; background: #ffffff;">${sCount}</td>
            <td style="padding: 10px 14px; font-size: 0.82rem; color: #334155; text-align: center; background: #ffffff;">${stuCount}</td>
            <td style="padding: 10px 14px; font-size: 0.82rem; color: #1e293b; text-align: center; font-weight: 600; background: #ffffff;">${rTotal}</td>
          `;
          summaryBody.appendChild(tr);
        });
      }

      const elF = document.getElementById('mp-sum-faculty');
      const elS = document.getElementById('mp-sum-staff');
      const elStu = document.getElementById('mp-sum-students');
      const elTot = document.getElementById('mp-sum-total');
      const elUnique = document.getElementById('mp-summary-unique-total');

      if (elF) elF.textContent = colFaculty;
      if (elS) elS.textContent = colStaff;
      if (elStu) elStu.textContent = colStudents;
      if (elTot) elTot.textContent = colTotal;
      if (elUnique) {
        elUnique.textContent = uniquePersons.size;
      }
    }

    // ── Materials Total Cost ──────────────────────────────────────────
    function updateTotal() {
      let total = 0;
      document.querySelectorAll('#materials-body tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.mat-qty')?.value) || 0;
        const cost = parseFloat(row.querySelector('.mat-cost')?.value) || 0;
        total += qty * cost;
      });
      document.getElementById('materials-total').textContent =
        '₱ ' + total.toLocaleString('en-PH', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        });
    }

    // Live update on any qty/cost change inside materials
    document.getElementById('materials-body').addEventListener('input', e => {
      if (e.target.classList.contains('mat-qty') || e.target.classList.contains('mat-cost')) {
        updateTotal();
      }
    });

    // ── Target Participants Section Table Functions ───────────────────
    function renumberParticipantRows() {
      const tbody = document.getElementById('participant-sections-body');
      if (!tbody) return;
      const rows = tbody.querySelectorAll('tr.participant-row');
      rows.forEach((row, idx) => {
        const numCell = row.querySelector('.part-row-num');
        if (numCell) numCell.textContent = idx + 1;
        const delBtn = row.querySelector('.btn-delete-section');
        if (delBtn) {
          if (rows.length <= 1) {
            delBtn.disabled = true;
            delBtn.style.opacity = '0.4';
            delBtn.style.cursor = 'not-allowed';
            delBtn.title = 'Cannot delete the only row';
          } else {
            delBtn.disabled = false;
            delBtn.style.opacity = '1';
            delBtn.style.cursor = 'pointer';
            delBtn.title = 'Delete section';
          }
        }
      });
    }

    function updateTotalTargetParticipants() {
      const countInputs = document.querySelectorAll('#participant-sections-body input.part-count-input');
      let total = 0;
      countInputs.forEach(input => {
        const val = parseInt(input.value, 10);
        if (!isNaN(val) && val > 0) {
          total += val;
        }
      });
      
      const displayEl = document.getElementById('target-participants-total-display');
      if (displayEl) {
        displayEl.textContent = total.toLocaleString();
      }
      
      const hiddenInput = document.getElementById('f1_target_participants');
      if (hiddenInput) {
        hiddenInput.value = total > 0 ? total : '';
      }

      const errEl = document.getElementById('e_target_participants');
      if (errEl && total > 0) {
        errEl.style.display = 'none';
      }

      if (typeof syncKpiSectionTables === 'function') {
        syncKpiSectionTables();
      }
    }

    // ── Synchronization for Activity Evaluation & KPI Measurement Tables ─
    function syncKpiSectionTables() {
      const partRows = document.querySelectorAll('#participant-sections-body tr.participant-row');
      const attTbody = document.getElementById('kpi-attendance-table-body');
      const learnTbody = document.getElementById('kpi-learning-table-body');
      if (!attTbody || !learnTbody) return;

      // Preserve any values currently typed by user in Step 9 inputs if re-syncing
      const currentActualInputs = attTbody.querySelectorAll('.part-actual-input');
      const currentPreInputs = learnTbody.querySelectorAll('.part-pre-input');
      const currentPostInputs = learnTbody.querySelectorAll('.part-post-input');

      const cachedActuals = [];
      const cachedPres = [];
      const cachedPosts = [];

      currentActualInputs.forEach((inp, i) => { cachedActuals[i] = inp.value; });
      currentPreInputs.forEach((inp, i) => { cachedPres[i] = inp.value; });
      currentPostInputs.forEach((inp, i) => { cachedPosts[i] = inp.value; });

      attTbody.innerHTML = '';
      learnTbody.innerHTML = '';

      let totalTarget = 0;

      partRows.forEach((row, idx) => {
        const secInput = row.querySelector('.part-section-input');
        const cntInput = row.querySelector('.part-count-input');
        const secName = (secInput && secInput.value.trim()) ? secInput.value.trim() : `Section ${idx + 1}`;
        const targetCount = (cntInput && parseInt(cntInput.value, 10) > 0) ? parseInt(cntInput.value, 10) : 0;
        totalTarget += targetCount;

        // Retrieve existing or typed values
        let actualVal = (cachedActuals[idx] !== undefined && cachedActuals[idx] !== '') 
          ? cachedActuals[idx] 
          : (row.getAttribute('data-actual-attendees') || '');
        let preVal = (cachedPres[idx] !== undefined && cachedPres[idx] !== '') 
          ? cachedPres[idx] 
          : (row.getAttribute('data-pre-test') || '');
        let postVal = (cachedPosts[idx] !== undefined && cachedPosts[idx] !== '') 
          ? cachedPosts[idx] 
          : (row.getAttribute('data-post-test') || '');

        // Attendance Row
        const attTr = document.createElement('tr');
        attTr.style.borderBottom = '1px solid #f1f5f9';
        attTr.className = 'kpi-attendance-row';
        attTr.setAttribute('data-target-count', targetCount);
        attTr.innerHTML = `
          <td style="text-align: center; color: #64748b; font-size: 0.85rem; padding: 10px 12px; vertical-align: middle;">${idx + 1}</td>
          <td style="padding: 10px 12px; font-weight: 500; vertical-align: middle;">${escapeHtml(secName)}</td>
          <td style="padding: 10px 12px; text-align: right; font-weight: 600; color: #0284c7; vertical-align: middle;">${targetCount.toLocaleString()}</td>
          <td style="padding: 10px 12px; text-align: center; vertical-align: middle; color: var(--text-muted, #64748b); font-size: 0.82rem;">
            <span class="text-muted">To be recorded after the activity</span>
            <input type="hidden" name="part_actual_attendees[]" class="part-actual-input" value="${escapeHtml(actualVal)}">
          </td>
          <td class="kpi-attendance-result-cell" style="padding: 10px 12px; text-align: center; vertical-align: middle;">
            <span class="text-muted" style="font-size:0.8rem;">— (Post-activity)</span>
          </td>
        `;
        attTbody.appendChild(attTr);

        // Learning Outcome Row
        const learnTr = document.createElement('tr');
        learnTr.style.borderBottom = '1px solid #f1f5f9';
        learnTr.className = 'kpi-learning-row';
        const kpiVal = row.getAttribute('data-learning-kpi') || '';
        learnTr.innerHTML = `
          <td style="text-align: center; color: #64748b; font-size: 0.85rem; padding: 10px 12px; vertical-align: middle;">${idx + 1}</td>
          <td style="padding: 10px 12px; font-weight: 500; vertical-align: middle;">${escapeHtml(secName)}</td>
          <td style="padding: 10px 12px; text-align: right; font-weight: 600; color: #0284c7; vertical-align: middle;">${targetCount.toLocaleString()}</td>
          <td class="kpi-learning-result-cell" style="padding: 10px 12px; text-align: center; vertical-align: middle; color: var(--text-muted, #64748b); font-size: 0.82rem;">
            <span class="text-muted">To be recorded after the activity</span>
            <input type="hidden" name="part_pre_test[]" class="part-pre-input" value="${escapeHtml(preVal)}">
            <input type="hidden" name="part_post_test[]" class="part-post-input" value="${escapeHtml(postVal)}">
            <input type="hidden" name="part_learning_kpi[]" value="${escapeHtml(kpiVal)}">
          </td>
        `;
        learnTbody.appendChild(learnTr);
      });

      // Update total target badge and footer in Component A
      const targetTotalEl = document.getElementById('kpi-attendance-target-total');
      if (targetTotalEl) targetTotalEl.textContent = totalTarget.toLocaleString();
      const footTargetEl = document.getElementById('kpi-attendance-footer-target');
      if (footTargetEl) footTargetEl.textContent = totalTarget.toLocaleString();

      updateKpiAttendanceCalculations();
      updateKpiLearningCalculations();
    }

    function updateKpiAttendanceCalculations() {
      const attRows = document.querySelectorAll('#kpi-attendance-table-body tr.kpi-attendance-row');
      let totalTarget = 0;

      attRows.forEach((tr) => {
        const targetCount = parseInt(tr.getAttribute('data-target-count'), 10) || 0;
        totalTarget += targetCount;
      });

      const footTargetEl = document.getElementById('kpi-attendance-footer-target');
      if (footTargetEl) footTargetEl.textContent = totalTarget.toLocaleString();

      const targetTotalEl = document.getElementById('kpi-attendance-target-total');
      if (targetTotalEl) targetTotalEl.textContent = totalTarget.toLocaleString();

      const footActualEl = document.getElementById('kpi-attendance-footer-actual');
      if (footActualEl) footActualEl.textContent = 'To be recorded after the activity';

      const footResultEl = document.getElementById('kpi-attendance-footer-result');
      if (footResultEl) footResultEl.innerHTML = '<span class="text-muted" style="font-size:0.8rem;">— (Post-activity)</span>';
    }

    function updateKpiLearningCalculations() {
      const learnRows = document.querySelectorAll('#kpi-learning-table-body tr.kpi-learning-row');
      learnRows.forEach((tr) => {
        const resCell = tr.querySelector('.kpi-learning-result-cell span.text-muted');
        if (resCell) {
          resCell.textContent = 'To be recorded after the activity';
        }
      });
    }

    function addParticipantSectionRow(section = '', count = '', teacher = '') {
      const tbody = document.getElementById('participant-sections-body');
      if (!tbody) return;
      const currentCount = tbody.querySelectorAll('tr.participant-row').length;
      const newIndex = currentCount + 1;
      
      const tr = document.createElement('tr');
      tr.className = 'participant-row';
      tr.style.borderBottom = '1px solid #f1f5f9';
      tr.innerHTML = `
        <td class="part-row-num" style="text-align: center; font-weight: 600; color: #64748b; font-size: 0.85rem; padding: 10px 12px; vertical-align: middle;">
          ${newIndex}
        </td>
        <td style="padding: 10px 12px;">
          <input type="text" name="part_section[]" class="form-control part-section-input" placeholder="e.g. BSIT 301 / Data Structures" value="${escapeHtml(section)}">
        </td>
        <td style="padding: 10px 12px;">
          <input type="number" name="part_count[]" class="form-control part-count-input" placeholder="0" min="0" value="${escapeHtml(count)}" oninput="updateTotalTargetParticipants()">
        </td>
        <td style="padding: 10px 12px; min-width: 250px;">
          <div class="teacher-multiselect" data-row-idx="${newIndex}">
            <input type="hidden" name="part_teacher[]" class="part-teacher-input" value="${escapeHtml(teacher)}">
            <div class="tms-control" onclick="focusTmsInput(this)">
              <div class="tms-tags"></div>
              <input type="text" class="tms-input" placeholder="Search and select faculty...">
            </div>
            <div class="tms-dropdown" style="display: none;">
              <div class="tms-list"></div>
              <div class="tms-empty" style="display: none;">No matching faculty found</div>
            </div>
          </div>
        </td>
        <td style="text-align: center; padding: 10px 12px; vertical-align: middle;">
          <button type="button" class="btn btn-outline btn-sm btn-delete-section" onclick="removeParticipantSectionRow(this)" title="Delete section" style="padding: 4px 8px; color: #ef4444; border-color: #fca5a5; line-height: 1;">✕</button>
        </td>
      `;
      tbody.appendChild(tr);
      const newTms = tr.querySelector('.teacher-multiselect');
      if (newTms && typeof window.initTeacherMultiSelect === 'function') {
        window.initTeacherMultiSelect(newTms, teacher);
      }
      renumberParticipantRows();
      updateTotalTargetParticipants();
    }

    function removeParticipantSectionRow(btn) {
      const tbody = document.getElementById('participant-sections-body');
      if (!tbody) return;
      const rows = tbody.querySelectorAll('tr.participant-row');
      if (rows.length <= 1) {
        return; // Never allow deletion of the last remaining row
      }
      const tr = btn.closest('tr');
      if (tr) {
        tr.remove();
        renumberParticipantRows();
        updateTotalTargetParticipants();
      }
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
        renumberParticipantRows();
        updateTotalTargetParticipants();
        syncKpiSectionTables();
        updateManpowerSummary();
        const pBody = document.getElementById('participant-sections-body');
        if (pBody) {
          pBody.addEventListener('input', () => {
            syncKpiSectionTables();
          });
        }
      });
    } else {
      renumberParticipantRows();
      updateTotalTargetParticipants();
      syncKpiSectionTables();
      updateManpowerSummary();
      const pBody = document.getElementById('participant-sections-body');
      if (pBody) {
        pBody.addEventListener('input', () => {
          syncKpiSectionTables();
        });
      }
    }

    // ── KPI ──────────────────────────────────────────────────────────
    function checkKpiDeleteButtons() {
      const tbody = document.getElementById('kpi-body');
      if (!tbody) return;
      const rows = tbody.querySelectorAll('tr');
      rows.forEach(row => {
        const delBtn = row.querySelector('.btn-delete-kpi');
        if (delBtn) {
          if (rows.length <= 1) {
            delBtn.disabled = true;
            delBtn.style.opacity = '0.4';
            delBtn.style.cursor = 'not-allowed';
          } else {
            delBtn.disabled = false;
            delBtn.style.opacity = '1';
            delBtn.style.cursor = 'pointer';
          }
        }
      });
    }

    function addKpiRow(ind = '', target = '', method = '') {
      const tbody = document.getElementById('kpi-body');
      if (!tbody) return;
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td style="padding: 10px 12px;"><input type="text" name="kpi_indicator[]" class="form-control kpi-field" placeholder="e.g. Number of Attendees" value="${escapeHtml(ind)}"></td>
        <td style="padding: 10px 12px;"><input type="text" name="kpi_target[]" class="form-control kpi-field" placeholder="e.g. 500 Participants" value="${escapeHtml(target)}"></td>
        <td style="padding: 10px 12px;"><input type="text" name="kpi_method[]" class="form-control kpi-field" placeholder="e.g. Attendance Sheet" value="${escapeHtml(method)}"></td>
        <td style="text-align: center; padding: 10px 12px; vertical-align: middle;">
          <button type="button" class="btn btn-outline btn-sm btn-delete-kpi" onclick="removeKpiRow(this)" title="Delete KPI" style="padding: 4px 8px; color: #ef4444; border-color: #fca5a5; line-height: 1;">✕</button>
        </td>
      `;
      tbody.appendChild(tr);
      checkKpiDeleteButtons();
    }

    function removeKpiRow(btn) {
      const tbody = document.getElementById('kpi-body');
      if (!tbody) return;
      const rows = tbody.querySelectorAll('tr');
      if (rows.length <= 1) return; // Do not allow deleting the final remaining row
      const tr = btn.closest('tr');
      if (tr) tr.remove();
      checkKpiDeleteButtons();
    }

    function addKPI() {
      addKpiRow();
    }

    // ── Evaluation Tool ──────────────────────────────────────────────
    let questionsList = []; // Array of { question: "...", type: "rating"|"open_ended", category: "..." }
    window.questionsList = questionsList;
    window.renderQuestions = renderQuestions;
    window.serializeEvaluationQuestions = serializeEvaluationQuestions;
    let isEvaluationEdited = false;
    let manualEditVersion = 0;
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

    // ── AI Evaluation Tool Generation Engine ──────────────────────────
    let currentEvalRequestId = 0;
    let currentEvalAbortController = null;
    let evalDebounceTimer = null;
    let lastEvaluationHash = '';

    function cancelPendingEvaluationGeneration() {
      currentEvalRequestId++;
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
        // Immediate execution with minimal settle delay
        evalDebounceTimer = setTimeout(() => {
          autoGenerateEvaluation(false);
        }, 50);
      } else {
        // Debounce manual edits by 800ms
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
        curSpec: (document.getElementById('f2_specific_objectives')?.value || '').trim()
      });
      lastEvaluationState = getObjectivesAndKpisState();
      setEvaluationState('READY');
    }

    // Helper to synthesize objective-aligned academic evaluation questions client-side as a non-blocking fallback
    function generateClientSideFallbackQuestions(title, theme, genObj, specObj) {
      const generated = [];
      let qNum = 1;

      // Extract bullet points from objectives
      const combined = (genObj || '') + '\n' + (specObj || '');
      const rawLines = combined.split(/[\r\n]+/);
      const parsedObjectives = [];
      rawLines.forEach(line => {
        const cleaned = line.replace(/^(\d+[\.\)\-]|[•\*\-])\s*/u, '').trim();
        if (cleaned.length >= 8) {
          parsedObjectives.push(cleaned.replace(/\.+$/, ''));
        }
      });

      // 1. Objective-aligned questions (up to 4 specific objectives)
      let objCount = 0;
      parsedObjectives.forEach(objText => {
        if (objCount < 4) {
          generated.push({
            id: 'q' + (qNum++),
            question: `To what extent did the activity successfully achieve the objective to ${objText}?`,
            type: 'rating',
            required: true
          });
          objCount++;
        }
      });

      if (objCount === 0) {
        generated.push({
          id: 'q' + (qNum++),
          question: 'To what extent did the activity fulfill its stated academic and developmental objectives?',
          type: 'rating',
          required: true
        });
      }

      // 2. Theme alignment question
      const cleanTheme = (theme || 'the event theme').trim().replace(/\.+$/, '');
      generated.push({
        id: 'q' + (qNum++),
        question: `The event activities and topics were closely aligned with the theme: '${cleanTheme}'.`,
        type: 'rating',
        required: true
      });

      // 3. Organization, logistics, schedule
      generated.push({
        id: 'q' + (qNum++),
        question: 'The event schedule, venue arrangement, and program coordination were effective and well-organized.',
        type: 'rating',
        required: true
      });

      // 4. Speakers / Facilitators
      generated.push({
        id: 'q' + (qNum++),
        question: 'The speakers and facilitators delivered the topics clearly and engaged participants effectively.',
        type: 'rating',
        required: true
      });

      // 5. Impact / Skill Enhancement
      const cleanTitle = (title || 'this activity').trim().replace(/\.+$/, '');
      generated.push({
        id: 'q' + (qNum++),
        question: `Participating in '${cleanTitle}' enhanced my practical knowledge and academic competence.`,
        type: 'rating',
        required: true
      });

      // 6. Overall satisfaction
      generated.push({
        id: 'q' + (qNum++),
        question: 'Overall, I am highly satisfied with the conduct and experience of this activity.',
        type: 'rating',
        required: true
      });

      // 7 & 8. Open-ended questions (qualitative feedback)
      generated.push({
        id: 'q' + (qNum++),
        question: `What specific aspect or session of '${cleanTitle}' did you find most beneficial or impactful?`,
        type: 'open_ended',
        required: true
      });

      generated.push({
        id: 'q' + (qNum++),
        question: 'What suggestions or recommendations do you have to improve future iterations of this event?',
        type: 'open_ended',
        required: true
      });

      return generated;
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

      // Requirements: non-empty title (>=4 chars), theme, and at least one objective
      const missing = [];
      if (!curTitle || curTitle.length < 4) missing.push('activity title (at least 4 characters)');
      if (!curTheme) missing.push('activity theme');
      if (!curGen && !curSpec) missing.push('activity objectives (general or specific)');

      if (missing.length > 0) {
        const msg = 'Please complete the ' + missing.join(', ') + ' before generating evaluation questions.';
        if (!questionsList || questionsList.length === 0) {
          setEvaluationState('EMPTY', msg);
        } else if (forced) {
          alert(msg);
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

      // Payload hash check: depends only on title, theme, and objectives
      const currentHash = JSON.stringify({ curTitle, curTheme, curGen, curSpec });
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
      const requestManualVersion = manualEditVersion;

      isGeneratingEval = true;
      setEvaluationState('GENERATING');

      const payload = {
        title: curTitle,
        theme: curTheme,
        general_objectives: curGen,
        specific_objectives: curSpec
      };

      try {
        const res = await fetch('<?= BASE_URL ?>/api/generate-evaluation.php', {
          method: 'POST',
          headers: { 
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify(payload),
          signal: currentEvalAbortController.signal
        });

        // Stale check
        if (requestId !== currentEvalRequestId || (currentEvalAbortController && currentEvalAbortController.signal.aborted) || manualEditVersion !== requestManualVersion || (!forced && isEvaluationEdited)) {
          return;
        }

        const resText = await res.text();
        let data = null;
        try {
          data = JSON.parse(resText);
        } catch (parseErr) {
          console.warn('Evaluation API returned non-JSON:', resText);
        }

        if (data && (data.quota_exhausted || (data.error && data.error === 'QUOTA_EXHAUSTED'))) {
          if (!questionsList || questionsList.length === 0) {
            questionsList = generateClientSideFallbackQuestions(curTitle, curTheme, curGen, curSpec);
            renderQuestions();
            serializeEvaluationQuestions();
          }
          throw new Error("QUOTA_EXHAUSTED");
        } else if (res.status === 429 || (data && (data.rate_limited || (data.error && (data.error.includes('429') || data.error.includes('limit') || data.error === 'RATE_LIMIT'))))) {
          throw new Error("RATE_LIMIT");
        } else if (data && (data.auth_error || (data.error && data.error === 'AUTH_ERROR'))) {
          if (!questionsList || questionsList.length === 0) {
            questionsList = generateClientSideFallbackQuestions(curTitle, curTheme, curGen, curSpec);
            renderQuestions();
            serializeEvaluationQuestions();
          }
          throw new Error("AUTH_ERROR: " + (data.message || 'Invalid or missing API key.'));
        }

        if (data && data.success && Array.isArray(data.questions) && data.questions.length > 0) {
          // Double check that request is still current and no manual question change occurred
          if (requestId !== currentEvalRequestId || (currentEvalAbortController && currentEvalAbortController.signal.aborted) || manualEditVersion !== requestManualVersion || (!forced && isEvaluationEdited)) {
            return;
          }

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
          // Fallback seamlessly to activity-tailored questions if API returned empty or unparseable response
          questionsList = generateClientSideFallbackQuestions(curTitle, curTheme, curGen, curSpec);
          isEvaluationEdited = false;
          editingIndex = -1;
          lastEvaluationHash = currentHash;
          lastEvaluationState = getObjectivesAndKpisState();

          renderQuestions();
          serializeEvaluationQuestions();
          setEvaluationState('READY');
        }
      } catch (err) {
        if (err.name === 'AbortError') return;
        if (requestId !== currentEvalRequestId || manualEditVersion !== requestManualVersion) return;
        console.warn('autoGenerateEvaluation error:', err);
        let errMsg = err.message || 'Unknown error';
        let isQuota = false;
        let isAuth = false;
        if (errMsg === 'QUOTA_EXHAUSTED') {
          errMsg = 'AI service quota has been reached. You can add evaluation questions manually below.';
          isQuota = true;
        } else if (errMsg === 'RATE_LIMIT') {
          errMsg = 'AI service rate limit reached. Please wait a moment and click Retry.';
        } else if (errMsg.startsWith('AUTH_ERROR')) {
          errMsg = errMsg.replace(/^AUTH_ERROR:\s*/, '');
          isAuth = true;
        } else {
          // Non-quota, non-auth error: provide synthesized fallback questions smoothly
          questionsList = generateClientSideFallbackQuestions(curTitle, curTheme, curGen, curSpec);
          isEvaluationEdited = false;
          editingIndex = -1;
          lastEvaluationHash = currentHash;
          lastEvaluationState = getObjectivesAndKpisState();

          renderQuestions();
          serializeEvaluationQuestions();
          setEvaluationState('READY');
          return;
        }
        setEvaluationState('ERROR', errMsg, isQuota, isAuth);
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

    function setEvaluationState(state, errorMsg = '', isQuota = false, isAuth = false) {
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
            statusEl.style.cssText = 'padding:10px 14px; font-size:0.75rem; border-radius:6px; background:#f0f9ff; color:#0369a1; border:1px solid #e0f2fe; margin-bottom:10px;';
            statusEl.innerText = errorMsg || (questionsList.length === 0 ? 'No evaluation questions generated yet. Click "⚡ Regenerate" to create questions with AI.' : 'Please complete the activity title, theme, and objectives to generate evaluation questions.');
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
            statusEl.style.cssText = 'padding:10px 14px; font-size:0.75rem; border-radius:6px; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; margin-bottom:10px;';
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
            statusEl.style.cssText = 'padding:10px 14px; font-size:0.75rem; border-radius:6px; background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; margin-bottom:10px;';
            const actionHtml = isQuota || isAuth
              ? `<div style="margin-top:6px; color:#475569; font-size:0.75rem; font-weight:500;">💡 Manual evaluation question entry is active. Use the <strong>+ Add Question</strong> button below to customize your questions.</div>`
              : `<div style="margin-top:6px;"><button type="button" class="btn btn-outline btn-xs" style="background:#fff; border:1px solid #fca5a5; padding:2px 8px; font-size:0.7rem; height:auto; line-height:1.2; color:#b91c1c;" onclick="regenerateEvaluationWithAi(true)">Retry</button></div>`;
            statusEl.innerHTML = `⚠️ <span style="font-weight:600;">Evaluation Question Generation Error</span> <br> <span style="font-size:0.75rem; opacity:0.95;">${escapeHtml(errorMsg || 'Unable to generate evaluation questions right now.')}</span>` + actionHtml;
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
      
      cancelPendingEvaluationGeneration();
      manualEditVersion++;
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

    async function confirmDeleteQuestion(index) {
      const msg = "Are you sure you want to delete this question?";
      const confirmed = window.STIModal
        ? await window.STIModal.confirm(msg, { title: 'Delete Evaluation Question?', confirmText: 'Delete Question', cancelText: 'Cancel', variant: 'danger' })
        : confirm(msg);
      if (confirmed) {
        cancelPendingEvaluationGeneration();
        manualEditVersion++;
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
      
      cancelPendingEvaluationGeneration();
      manualEditVersion++;
      isEvaluationEdited = true;

      questionsList.push({
        id: `q_${Date.now()}_${questionsList.length}`,
        question: '',
        type: 'rating',
        required: true,
        category: 'Overall Activity'
      });
      
      editingIndex = questionsList.length - 1;
      renderQuestions();
      
      const cardBody = document.querySelector('#panel-9 .card-body') || document.querySelector('.proposal-eval-side-panel .card-body');
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
      // Evaluation questions depend solely on Title, Theme, and Objectives.
      // Changes to KPI, rationale, target participants, and involved subjects do not trigger evaluation generation.
      const fields = [
        '[name="theme"]',
        '[name="general_objectives"]',
        '[name="specific_objectives"]'
      ];
      
      fields.forEach(sel => {
        const el = document.querySelector(sel);
        if (el) {
          const handler = () => {
            triggerAutomaticEvaluation(false);
          };
          el.addEventListener('input', handler);
          el.addEventListener('change', handler);
        }
      });
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

    // ── Create Google Form Handler ──────────────────────────────────
    let isCreatingGoogleForm = false;

    async function handleCreateGoogleForm() {
      if (isCreatingGoogleForm) return;

      const btn = document.getElementById('btnCreateGoogleForm');
      const spinner = document.getElementById('btnCreateGoogleFormSpinner');
      const btnText = document.getElementById('btnCreateGoogleFormText');
      const statusMsg = document.getElementById('googleFormStatusMessage');
      const linkInput = document.getElementById('f9_eval_form_link');

      function showStatus(text, type = 'error') {
        if (!statusMsg) return;
        statusMsg.style.display = 'block';
        if (type === 'success') {
          statusMsg.style.background = '#d1e7dd';
          statusMsg.style.color = '#0f5132';
          statusMsg.style.border = '1px solid #badbcc';
          statusMsg.innerHTML = '✅ ' + text;
        } else if (type === 'info') {
          statusMsg.style.background = '#cff4fc';
          statusMsg.style.color = '#055160';
          statusMsg.style.border = '1px solid #b6effb';
          statusMsg.innerHTML = 'ℹ️ ' + text;
        } else {
          statusMsg.style.background = '#fff3cd';
          statusMsg.style.color = '#664d03';
          statusMsg.style.border = '1px solid #ffecb5';
          statusMsg.innerHTML = text;
        }
      }

      function showManualGoogleFormHelper(reasonText) {
        if (!statusMsg) return;
        let friendlyReason = reasonText;
        if (reasonText.toLowerCase().includes('expired or revoked') || reasonText.toLowerCase().includes('oauth')) {
          friendlyReason = 'The automated Google connection has expired. Please use the quick manual setup below:';
        }
        statusMsg.style.display = 'block';
        statusMsg.style.background = '#fffbeb';
        statusMsg.style.color = '#92400e';
        statusMsg.style.border = '1px solid #fde68a';
        statusMsg.innerHTML = `
          <div style="display:flex; flex-direction:column; gap:8px;">
            <div>
              ⚠️ <strong>Google Forms Quick Setup:</strong> ${escapeHtml(friendlyReason)}
            </div>
            <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyEvaluationQuestionsForGoogleForm()" style="display:inline-flex; align-items:center; gap:5px; font-size:0.75rem; padding:5px 12px; background:#fff; font-weight:600; cursor:pointer;">
                📋 Copy All Evaluation Questions
              </button>
              <a href="https://forms.new" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary" style="display:inline-flex; align-items:center; gap:5px; font-size:0.75rem; padding:5px 12px; text-decoration:none; font-weight:600; cursor:pointer;">
                🔗 Open Google Forms (forms.new) ↗
              </a>
              <a href="<?= BASE_URL ?>/api/google-oauth-callback.php?origin=<?= urlencode(BASE_URL) ?>&return_to=faculty/proposal-create.php" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary" style="display:inline-flex; align-items:center; gap:5px; font-size:0.75rem; padding:5px 12px; background:#fff; text-decoration:none; font-weight:600; cursor:pointer;">
                ⚡ Reauthorize Google Connection ↗
              </a>
              <span id="copyQuestionsFeedback" style="display:none; font-size:0.75rem; color:#16a34a; font-weight:600;">✓ Questions copied to clipboard!</span>
            </div>
            <div style="font-size:0.75rem; color:#64748b; line-height:1.3;">
              Tip: Click <strong>"Copy All Evaluation Questions"</strong>, open Google Forms in a new tab, paste your questions, then copy the form link (e.g., <code>https://forms.gle/...</code>) into the box above.
            </div>
          </div>
        `;
        if (linkInput) {
          linkInput.focus();
        }
      }

      // Read activity ID if available (0 for unsaved new proposals)
      const actIdEl = document.getElementById('proposalActivityId') || document.querySelector('[name="activity_id"]');
      const activityId = actIdEl && actIdEl.value ? parseInt(actIdEl.value, 10) : 0;

      // Ensure evaluation questions are serialized and gathered from memory / DOM
      if (typeof serializeEvaluationQuestions === 'function') {
        serializeEvaluationQuestions();
      }

      let currentQuestions = [];
      if (typeof questionsList !== 'undefined' && Array.isArray(questionsList) && questionsList.length > 0) {
        currentQuestions = questionsList;
      } else {
        const hiddenInput = document.getElementById('f10_evaluation_questions');
        if (hiddenInput && hiddenInput.value) {
          try { currentQuestions = JSON.parse(hiddenInput.value); } catch (e) {}
        }
      }

      if (!currentQuestions || !Array.isArray(currentQuestions) || currentQuestions.length === 0) {
        showStatus('⚠️ Please generate or add evaluation questions first before creating a Google Form.', 'warning');
        return;
      }

      const title = (document.getElementById('f1_title')?.value || document.querySelector('[name="title"]')?.value || '').trim() || 'Activity Evaluation';

      // Prevent duplicate requests
      isCreatingGoogleForm = true;
      if (btn) btn.disabled = true;
      if (spinner) spinner.style.display = 'inline-block';
      if (btnText) btnText.textContent = 'Creating Form...';
      if (statusMsg) statusMsg.style.display = 'none';

      try {
        const response = await fetch('<?= BASE_URL ?>/api/create-google-form.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            activity_id: activityId,
            title: title,
            questions: currentQuestions
          })
        });

        const resText = await response.text();
        let data = null;
        try {
          data = JSON.parse(resText);
        } catch (e) {
          console.warn('Google Form API returned non-JSON:', resText);
        }

        if (response.ok && data && data.success && (data.form_url || data.responder_uri)) {
          const url = data.form_url || data.responder_uri;
          if (linkInput) {
            linkInput.value = url;
            linkInput.classList.remove('is-invalid');
            const errSpan = document.getElementById('e_eval_form_link');
            if (errSpan) errSpan.style.display = 'none';
          }
          showStatus(data.message || 'Google Form created successfully! Link populated.', 'success');
        } else {
          // Handle response cleanly without exposing credentials or sensitive tokens
          const errMsg = (data && data.error) ? String(data.error) : '';
          if (errMsg.toLowerCase().includes('already been created') && (data.form_url || data.responder_uri)) {
            if (linkInput && !linkInput.value) {
              linkInput.value = data.form_url || data.responder_uri;
            }
            showStatus('A Google Form has already been created for this activity.', 'info');
          } else {
            showManualGoogleFormHelper(errMsg || 'Google Forms automated API is in manual mode. You can easily copy your questions and open Google Forms below.');
          }
        }
      } catch (err) {
        showManualGoogleFormHelper('Google Forms automated API service is currently unavailable. You can copy your questions and open Google Forms below.');
      } finally {
        isCreatingGoogleForm = false;
        if (btn) btn.disabled = false;
        if (spinner) spinner.style.display = 'none';
        if (btnText) btnText.textContent = 'Create Google Form';
      }
    }

    // Helper to copy all evaluation questions in a clean, structured format for Google Forms
    function copyEvaluationQuestionsForGoogleForm() {
      let questions = (typeof questionsList !== 'undefined' && Array.isArray(questionsList) && questionsList.length > 0) ? questionsList : [];
      if (questions.length === 0) {
        const hiddenInput = document.getElementById('f10_evaluation_questions');
        if (hiddenInput && hiddenInput.value) {
          try { questions = JSON.parse(hiddenInput.value); } catch (e) {}
        }
      }
      if (questions.length === 0) {
        const titleEl = document.getElementById('f1_title') || document.querySelector('[name="title"]');
        const themeEl = document.getElementById('f1_theme') || document.querySelector('[name="theme"]');
        const genEl = document.getElementById('f2_general_objectives') || document.querySelector('[name="general_objectives"]');
        const specEl = document.getElementById('f2_specific_objectives') || document.querySelector('[name="specific_objectives"]');
        questions = generateClientSideFallbackQuestions(titleEl?.value || '', themeEl?.value || '', genEl?.value || '', specEl?.value || '');
      }

      const actTitle = (document.getElementById('f1_title')?.value || document.querySelector('[name="title"]')?.value || 'Activity Evaluation').trim();
      const actTheme = (document.getElementById('f1_theme')?.value || document.querySelector('[name="theme"]')?.value || '').trim();

      let text = `ACTIVITY EVALUATION FORM\n`;
      text += `Title: ${actTitle}\n`;
      if (actTheme) text += `Theme: ${actTheme}\n`;
      text += `\nINSTRUCTIONS:\n`;
      text += `Please evaluate this activity honestly. For rating questions, use the following scale:\n`;
      text += `4 - Strongly Agree\n3 - Agree\n2 - Disagree\n1 - Strongly Disagree\n\n`;
      text += `--- PARTICIPANT PROFILE ---\n`;
      text += `1. Section: [Short Answer - Required]\n\n`;
      text += `--- EVALUATION QUESTIONS ---\n`;

      let ratingNum = 1;
      let textNum = 1;
      questions.forEach(q => {
        const qText = (q.question || q.title || '').trim();
        if (!qText || qText.toLowerCase() === 'section') return;
        const qType = (q.type || 'rating').toLowerCase();
        if (qType === 'rating') {
          text += `${ratingNum++}. ${qText} (Scale: 1-4)\n`;
        } else {
          text += `Feedback ${textNum++}: ${qText} (Paragraph Text)\n`;
        }
      });

      function showCopiedNotice() {
        const fb = document.getElementById('copyQuestionsFeedback');
        if (fb) {
          fb.style.display = 'inline';
          setTimeout(() => { fb.style.display = 'none'; }, 4000);
        } else {
          alert('Evaluation questions copied to clipboard!');
        }
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(showCopiedNotice).catch(() => fallbackCopyText(text));
      } else {
        fallbackCopyText(text);
      }

      function fallbackCopyText(str) {
        const ta = document.createElement('textarea');
        ta.value = str;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try {
          document.execCommand('copy');
          showCopiedNotice();
        } catch (e) {
          alert('Please copy questions manually:\n\n' + str);
        }
        document.body.removeChild(ta);
      }
    }
  </script>

  <!-- Floor Plan Scripts -->
  <script src="https://unpkg.com/konva@9/konva.min.js"></script>
  <script src="<?= BASE_URL ?>/assets/js/floorplan.js?v=<?= filemtime(__DIR__ . '/../assets/js/floorplan.js') ?>"></script>

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
              <div id="scuConfTitle" style="font-weight: 700; font-size: 0.9rem; color: #2d3748; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 320px;">Leadership Seminar</div>
              <div style="font-size: 0.8rem; color: #4a5568; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px;">
                <span>📅 <span id="scuConfDate">August 20, 2026</span></span>
                <span>⏰ <span id="scuConfTime">1:00 PM – 3:00 PM</span></span>
                <span>📍 <span id="scuConfVenue">AVR</span></span>
              </div>
            </div>
            <!-- Your Proposed Activity -->
            <div style="background: #fff; padding: 10px 14px; border-radius: 8px; border-left: 4px solid #718096; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
              <div style="font-size: 0.7rem; text-transform: uppercase; font-weight: 700; color: #718096; margin-bottom: 4px;">Your Proposed Activity</div>
              <div style="font-weight: 700; font-size: 0.9rem; color: #4a5568;">(Proposed Slot)</div>
              <div style="font-size: 0.8rem; color: #4a5568; display: flex; flex-wrap: wrap; gap: 8px; margin-top: 4px;">
                <span>📅 <span id="scuPropDate">August 20, 2026</span></span>
                <span>⏰ <span id="scuPropTime">2:00 PM – 4:00 PM</span></span>
                <span>📍 <span id="scuPropVenue">AVR</span></span>
              </div>
            </div>
          </div>

          <!-- Suggested Available Alternative Slots -->
          <div style="background: #fff; padding: 12px 16px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <h6 class="text-primary fw-bold d-flex align-items-center gap-2" style="font-size:0.85rem; color: #0d6efd; margin: 0 0 10px 0; display: flex; align-items: center; gap: 6px;">
              🗓️ Suggested Available Alternative Slots (Rule-Based Verification)
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
  <script>
    window.ELIGIBLE_FACULTY_ROSTER = <?= json_encode($eligibleFacultyList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  </script>
  <script src="<?= BASE_URL ?>/assets/js/teacher-multiselect.js?v=<?= time() ?>"></script>
  <script src="<?= BASE_URL ?>/assets/js/schedule-conflict-ui.js?v=<?= time() ?>"></script>
  <script src="<?= BASE_URL ?>/assets/js/proposal-ai-validator.js?v=<?= time() ?>"></script>
  <script src="<?= BASE_URL ?>/assets/js/offline-drafts.js?v=<?= time() ?>"></script>
  <script src="<?= BASE_URL ?>/assets/js/ai-proposal-guidance.js?v=<?= time() ?>"></script>
  <script>
    if (typeof ScheduleConflictUI !== 'undefined') {
      ScheduleConflictUI.init({
        baseUrl:          '<?= BASE_URL ?>',
        activityId:       0,
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

    if (typeof AIProposalGuidance !== 'undefined') {
      AIProposalGuidance.init({
        baseUrl:      '<?= BASE_URL ?>',
        formSelector: '#proposalForm'
      });
    }

    // Initialize baseline evaluation state
    lastEvaluationState = getObjectivesAndKpisState();
    if (!questionsList || questionsList.length === 0) {
      setEvaluationState('EMPTY');
    }
  </script>
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
  <!-- ── Floor Plan Requirement Modal ── -->
  <div class="modal fade" id="floorPlanRequirementModal" tabindex="-1" aria-labelledby="fpReqModalLabel" aria-hidden="true" style="display:none; background:rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-dialog-centered" style="margin: 1.75rem auto; max-width: 520px;">
      <div class="modal-content" style="border-radius:12px; overflow:hidden; border:none; box-shadow:0 12px 32px rgba(0,0,0,0.2); background:var(--bg-card, #fff);">
        <div class="modal-header" style="padding: 16px 20px; border-bottom:1px solid var(--border, #e2e8f0); display:flex; align-items:center; justify-content:space-between;">
          <h5 class="modal-title fw-bold" id="fpReqModalLabel" style="font-size:1.1rem; margin:0; display:flex; align-items:center; gap:8px; color:var(--text-main, #1e293b);">
            🗺️ Floor Plan Requirement
          </h5>
          <button type="button" class="btn-close" onclick="closeFloorPlanRequirementModal()" aria-label="Close" style="background:none; border:none; font-size:1.3rem; color:var(--text-muted, #64748b); cursor:pointer; line-height:1;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 24px 20px; color:var(--text-main, #334155);">
          <p style="font-size:1.05rem; font-weight:600; margin-bottom:8px; color:var(--text-main, #1e293b);">
            Does this activity require a physical floor plan?
          </p>
          <p style="font-size:0.875rem; color:var(--text-muted, #64748b); line-height:1.5; margin-bottom:0;">
            Select <strong>Yes</strong> if this event needs venue layout, stage direction, seating arrangement, or room blueprints (you can upload a file or draw it online). Select <strong>No</strong> for online webinars or events without a physical venue setup.
          </p>
        </div>
        <div class="modal-footer" style="padding: 14px 20px; border-top:1px solid var(--border, #e2e8f0); display:flex; justify-content:flex-end; gap:12px; background:var(--bg-base, #f8fafc);">
          <button type="button" class="btn btn-outline" id="btnFpModalNo" onclick="handleFloorPlanChoice('no')" style="min-width:110px; font-weight:600;">
            No
          </button>
          <button type="button" class="btn btn-primary" id="btnFpModalYes" onclick="handleFloorPlanChoice('yes')" style="min-width:110px; font-weight:600;">
            Yes
          </button>
        </div>
      </div>
    </div>
  </div>
  <!-- ── Initial Collaborator Invitation Modal (Prompt before Proposal Form) ── -->
  <div class="modal fade" id="initCollabPromptModal" tabindex="-1" aria-labelledby="initCollabPromptLabel" aria-hidden="true" style="display:none; background:rgba(0,0,0,0.65); z-index:9999;">
    <div class="modal-dialog modal-dialog-centered" style="margin: 1.75rem auto; max-width: 480px;">
      <div class="modal-content" style="border-radius:14px; overflow:hidden; border:none; box-shadow:0 16px 40px rgba(0,0,0,0.25); background:var(--bg-card, #fff);">
        <div class="modal-header" style="padding: 18px 24px; border-bottom:1px solid var(--border, #e2e8f0); display:flex; align-items:center; justify-content:space-between; background:var(--bg-base, #f8fafc);">
          <h5 class="modal-title fw-bold" id="initCollabPromptLabel" style="font-size:1.15rem; margin:0; display:flex; align-items:center; gap:8px; color:var(--text-main, #1e293b);">
            👥 Add Collaborators?
          </h5>
        </div>
        <div class="modal-body" style="padding: 24px; color:var(--text-main, #334155); font-size:0.95rem; line-height:1.5;">
          <p style="margin:0 0 12px 0;">
            Do you want to add a Co-Author or Contributor to this proposal?
          </p>
          <div style="font-size:0.82rem; color:var(--text-muted, #64748b); background:rgba(2, 132, 199, 0.08); border-left:3px solid var(--sti-blue, #0284c7); padding:10px 12px; border-radius:6px;">
            You can invite faculty colleagues to collaborate on this proposal as <strong>Co-Authors</strong> (section editing) or <strong>Contributors</strong> (drafting &amp; review).
          </div>
        </div>
        <div class="modal-footer" style="padding: 14px 24px; border-top:1px solid var(--border, #e2e8f0); display:flex; justify-content:flex-end; gap:12px; background:var(--bg-base, #f8fafc);">
          <button type="button" class="btn btn-outline" id="btnInitCollabNo" onclick="handleInitCollabChoice(false)" style="font-size:0.875rem; padding:8px 18px; border-radius:6px; min-width:80px;">
            No
          </button>
          <button type="button" class="btn btn-primary" id="btnInitCollabYes" onclick="handleInitCollabChoice(true)" style="font-size:0.875rem; padding:8px 20px; border-radius:6px; background:var(--sti-blue, #0284c7); border-color:var(--sti-blue, #0284c7); font-weight:600;">
            Yes, Add Collaborator
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Manage Proposal Collaborators Modal ── -->
  <div class="modal fade" id="collaboratorsModal" tabindex="-1" aria-labelledby="collaboratorsModalLabel" aria-hidden="true" style="display:none; background:rgba(0,0,0,0.65); z-index:9999;">
    <div class="modal-dialog modal-dialog-centered modal-lg" style="margin: 1.75rem auto; max-width: 680px;">
      <div class="modal-content" style="border-radius:12px; overflow:hidden; border:none; box-shadow:0 12px 32px rgba(0,0,0,0.2); background:var(--bg-card, #fff);">
        <div class="modal-header" style="padding: 16px 20px; border-bottom:1px solid var(--border, #e2e8f0); display:flex; align-items:center; justify-content:space-between;">
          <h5 class="modal-title fw-bold" id="collaboratorsModalLabel" style="font-size:1.1rem; margin:0; display:flex; align-items:center; gap:8px; color:var(--text-main, #1e293b);">
            👥 Manage Proposal Collaborators
          </h5>
          <button type="button" class="btn-close" onclick="closeCollaboratorsModal()" aria-label="Close" style="background:none; border:none; font-size:1.3rem; color:var(--text-muted, #64748b); cursor:pointer; line-height:1;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 20px; color:var(--text-main, #334155); max-height: calc(100vh - 220px); overflow-y: auto;">
          <!-- Add Collaborator Section -->
          <div style="background:var(--bg-base, #f8fafc); border:1px solid var(--border, #e2e8f0); border-radius:8px; padding:16px; margin-bottom:20px;">
            <h6 style="font-weight:700; margin:0 0 10px 0; font-size:0.95rem; color:var(--text-main, #1e293b); display:flex; align-items:center; gap:6px;">
              <span>➕</span> Add Faculty Contributor
            </h6>
            <div id="collabAlertContainer"></div>
            <form id="addCollaboratorForm" onsubmit="submitAddCollaborator(event)" style="display:grid; grid-template-columns: 1fr 1fr auto; gap:10px; align-items:flex-end;">
              <div>
                <label style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:4px; color:var(--text-muted, #64748b);">Faculty Member</label>
                <select id="collabFacultySelect" class="form-control" style="width:100%; font-size:0.85rem; padding:8px 10px; border-radius:6px; border:1px solid var(--border, #cbd5e1);" required>
                  <option value="">Loading faculty list...</option>
                </select>
              </div>
              <div>
                <label style="display:block; font-size:0.8rem; font-weight:600; margin-bottom:4px; color:var(--text-muted, #64748b);">Contributor Role</label>
                <select id="collabRoleSelect" class="form-control" style="width:100%; font-size:0.85rem; padding:8px 10px; border-radius:6px; border:1px solid var(--border, #cbd5e1);" required>
                  <option value="co_author">Co-Author (Full section editing)</option>
                  <option value="contributor">Contributor (Drafting &amp; Review)</option>
                </select>
              </div>
              <div>
                <button type="submit" id="btnAddCollaborator" class="btn btn-primary" style="padding:8px 16px; font-size:0.85rem; font-weight:600; border-radius:6px; white-space:nowrap;">
                  Add Contributor
                </button>
              </div>
            </form>
            <div style="font-size:0.75rem; color:var(--text-muted, #64748b); margin-top:8px;">
              * Only active faculty members not currently assigned to this proposal are listed.
            </div>
          </div>

          <!-- Current Collaborators Table -->
          <div>
            <h6 style="font-weight:700; margin:0 0 10px 0; font-size:0.95rem; color:var(--text-main, #1e293b); display:flex; align-items:center; justify-content:space-between;">
              <span>Assigned Collaborators (<span id="collabModalCount">1</span>)</span>
              <span style="font-size:0.75rem; font-weight:normal; color:var(--text-muted, #64748b);">At least 3 faculty members can contribute</span>
            </h6>
            <div style="overflow-x:auto;">
              <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                  <tr style="border-bottom:2px solid var(--border, #e2e8f0); text-align:left; background:var(--bg-base, #f8fafc);">
                    <th style="padding:10px 12px; font-weight:600; color:var(--text-muted, #64748b);">Faculty Member</th>
                    <th style="padding:10px 12px; font-weight:600; color:var(--text-muted, #64748b);">Department</th>
                    <th style="padding:10px 12px; font-weight:600; color:var(--text-muted, #64748b);">Role</th>
                    <th style="padding:10px 12px; font-weight:600; color:var(--text-muted, #64748b);">Action</th>
                  </tr>
                </thead>
                <tbody id="collabTableBody">
                  <tr>
                    <td colspan="4" style="text-align:center; padding:16px; color:var(--text-muted, #64748b);">Loading collaborators...</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <div style="margin-top:16px; background:rgba(2, 132, 199, 0.08); border-left:3px solid var(--sti-blue, #0284c7); border-radius:6px; padding:10px 12px; font-size:0.78rem; color:var(--text-muted, #475569); line-height:1.45;">
            <strong>Collaborative Policy:</strong> Multiple faculty members can contribute at different times. Sequential saves are protected by optimistic locking to prevent overwriting. Only the <strong>Proposal Owner</strong> can submit the finalized proposal for Dean/Admin approval.
          </div>
        </div>
        <div class="modal-footer" style="padding: 12px 20px; border-top:1px solid var(--border, #e2e8f0); display:flex; justify-content:space-between; align-items:center; background:var(--bg-base, #f8fafc);">
          <span style="font-size:0.78rem; color:var(--text-muted, #64748b);">* Collaborators will receive access when you click &quot;Send to Collaborators&quot;.</span>
          <button type="button" class="btn btn-primary" onclick="closeCollaboratorsModal()" style="font-size:0.85rem; padding:6px 16px;">Proceed to Proposal Form</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    // ── Optional Collaborator Invitation Workflow (Task: Optional Collaborator Workflow) ──
    const sessionToken = <?= json_encode($sessionToken) ?>;
    let hasChosenCollaborators = false;
    let stagedCollaboratorCount = 0;

    document.addEventListener('DOMContentLoaded', function() {
      // Check if user came from a redirect with skip_collab or error
      const urlParams = new URLSearchParams(window.location.search);
      if (!urlParams.has('skip_collab') && !urlParams.has('error')) {
        const promptModal = document.getElementById('initCollabPromptModal');
        if (promptModal) {
          promptModal.style.display = 'block';
          promptModal.classList.add('show');
        }
      }
    });

    function handleInitCollabChoice(wantsCollaborators) {
      const promptModal = document.getElementById('initCollabPromptModal');
      if (promptModal) {
        promptModal.style.display = 'none';
        promptModal.classList.remove('show');
      }

      if (wantsCollaborators) {
        hasChosenCollaborators = true;
        document.querySelectorAll('.send-to-collab-btn').forEach(btn => {
          btn.style.display = 'inline-flex';
        });
        const headerBar = document.getElementById('collabActiveHeaderBar');
        if (headerBar) headerBar.style.display = 'flex';
        openCollaboratorsModal();
      } else {
        hasChosenCollaborators = false;
        document.querySelectorAll('.send-to-collab-btn').forEach(btn => {
          btn.style.display = 'none';
        });
        const headerBar = document.getElementById('collabActiveHeaderBar');
        if (headerBar) headerBar.style.display = 'none';
      }
    }

    function openCollaboratorsModal() {
      const modal = document.getElementById('collaboratorsModal');
      if (!modal) return;
      modal.style.display = 'block';
      modal.classList.add('show');
      loadCollaborators();
      loadAvailableFaculty();
    }

    function closeCollaboratorsModal() {
      const modal = document.getElementById('collaboratorsModal');
      if (modal) {
        modal.style.display = 'none';
        modal.classList.remove('show');
      }
    }

    async function loadCollaborators() {
      const tbody = document.getElementById('collabTableBody');
      const countEl = document.getElementById('collabModalCount');
      const headerSummary = document.getElementById('collabHeaderSummary');
      if (!tbody) return;

      try {
        const resp = await fetch(`<?= BASE_URL ?>/api/collaborators.php?action=list&session_token=${encodeURIComponent(sessionToken)}`);
        const data = await resp.json();
        if (!data.success) {
          tbody.innerHTML = `<tr><td colspan="4" style="color:#ef4444;text-align:center;padding:12px;">Error: ${escapeHtml(data.error || 'Failed to load')}</td></tr>`;
          return;
        }

        stagedCollaboratorCount = data.staged_count || 0;
        if (countEl) countEl.textContent = data.collaborator_count;
        if (headerSummary) {
          headerSummary.textContent = `1 Owner, ${stagedCollaboratorCount} Collaborator${stagedCollaboratorCount === 1 ? '' : 's'}`;
        }

        if (!data.collaborators || data.collaborators.length === 0) {
          tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;padding:12px;color:var(--text-muted);">No collaborators assigned.</td></tr>`;
          return;
        }

        let rowsHtml = '';
        data.collaborators.forEach(c => {
          const roleBadgeColor = c.role === 'owner' ? '#0284C7' : (c.role === 'co_author' ? '#10B981' : '#64748B');
          const roleBadgeBg = c.role === 'owner' ? 'rgba(2, 132, 199, 0.12)' : (c.role === 'co_author' ? 'rgba(16, 185, 129, 0.12)' : 'rgba(100, 116, 139, 0.12)');
          const roleIcon = c.role === 'owner' ? '👑' : (c.role === 'co_author' ? '✍️' : '👤');

          let actionCell = '<span style="color:var(--text-muted);font-size:0.78rem;">—</span>';
          if (c.role !== 'owner') {
            const escapedName = (c.name || '').replace(/'/g, "\\'");
            actionCell = `<button type="button" class="btn btn-outline btn-sm" onclick="submitRemoveCollaborator(${c.user_id}, '${escapedName}')" style="color:#dc2626;border-color:#fca5a5;padding:3px 8px;font-size:0.75rem;">Remove</button>`;
          }

          rowsHtml += `
            <tr style="border-bottom:1px solid var(--border, #e2e8f0);">
              <td style="padding:10px 12px;font-weight:600;">
                ${escapeHtml(c.name)}
                ${c.is_owner ? ' <span style="font-size:0.72rem;background:#E2E8F0;padding:2px 5px;border-radius:4px;font-weight:normal;">(You / Owner)</span>' : ''}
              </td>
              <td style="padding:10px 12px;color:var(--text-muted);">${escapeHtml(c.department || '—')}</td>
              <td style="padding:10px 12px;">
                <span style="background:${roleBadgeBg};color:${roleBadgeColor};padding:3px 8px;border-radius:10px;font-size:0.75rem;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
                  ${roleIcon} ${escapeHtml(c.role_label || c.role)}
                </span>
              </td>
              <td style="padding:10px 12px;">${actionCell}</td>
            </tr>
          `;
        });

        tbody.innerHTML = rowsHtml;
      } catch (err) {
        tbody.innerHTML = `<tr><td colspan="4" style="color:#ef4444;text-align:center;padding:12px;">Network error loading collaborators.</td></tr>`;
      }
    }

    async function loadAvailableFaculty() {
      const sel = document.getElementById('collabFacultySelect');
      if (!sel) return;

      try {
        const resp = await fetch(`<?= BASE_URL ?>/api/collaborators.php?action=search_faculty&session_token=${encodeURIComponent(sessionToken)}`);
        const data = await resp.json();
        if (!data.success || !Array.isArray(data.available_faculty)) {
          sel.innerHTML = '<option value="">No faculty members available</option>';
          return;
        }

        if (data.available_faculty.length === 0) {
          sel.innerHTML = '<option value="">No faculty members found</option>';
          return;
        }

        let opts = '<option value="">-- Select Faculty Member --</option>';
        let currentDept = '';
        data.available_faculty.forEach(f => {
          if (f.department && f.department !== currentDept) {
            if (currentDept !== '') opts += '</optgroup>';
            currentDept = f.department;
            opts += `<optgroup label="${escapeHtml(currentDept)}">`;
          }
          const disabledAttr = f.disabled ? ' disabled' : '';
          const val = f.disabled ? '' : (f.id || '');
          const label = f.display_text || `${f.name} (${f.department || 'General'})`;
          opts += `<option value="${val}"${disabledAttr}>${escapeHtml(label)}</option>`;
        });
        if (currentDept !== '') opts += '</optgroup>';
        sel.innerHTML = opts;
      } catch (e) {
        sel.innerHTML = '<option value="">Failed to load faculty list</option>';
      }
    }

    async function submitAddCollaborator(e) {
      e.preventDefault();
      const sel = document.getElementById('collabFacultySelect');
      const roleSel = document.getElementById('collabRoleSelect');
      const btn = document.getElementById('btnAddCollaborator');
      const alertBox = document.getElementById('collabAlertContainer');
      if (!sel || !roleSel) return;

      const targetId = sel.value;
      const targetRole = roleSel.value;
      if (!targetId) {
        alert('Please select a faculty member.');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Adding...';
      if (alertBox) alertBox.innerHTML = '';

      try {
        const fd = new FormData();
        fd.append('action', 'add');
        fd.append('session_token', sessionToken);
        fd.append('user_id', targetId);
        fd.append('role', targetRole);

        const resp = await fetch(`<?= BASE_URL ?>/api/collaborators.php`, {
          method: 'POST',
          body: fd
        });
        const res = await resp.json();

        if (res.success) {
          if (alertBox) {
            alertBox.innerHTML = `<div class="alert alert-success" style="padding:8px 12px;font-size:0.82rem;margin-bottom:10px;">${escapeHtml(res.message || 'Collaborator added successfully!')}</div>`;
          }
          await loadCollaborators();
          await loadAvailableFaculty();
        } else {
          if (alertBox) {
            alertBox.innerHTML = `<div class="alert alert-danger" style="padding:8px 12px;font-size:0.82rem;margin-bottom:10px;">${escapeHtml(res.error || 'Failed to add collaborator.')}</div>`;
          }
        }
      } catch (err) {
        if (alertBox) {
          alertBox.innerHTML = `<div class="alert alert-danger" style="padding:8px 12px;font-size:0.82rem;margin-bottom:10px;">Network error while adding collaborator.</div>`;
        }
      } finally {
        btn.disabled = false;
        btn.textContent = 'Add Contributor';
      }
    }

    async function submitRemoveCollaborator(userId, userName) {
      const msg = `Are you sure you want to remove ${userName} from this collaborative proposal?`;
      const confirmed = window.STIModal
        ? await window.STIModal.confirm(msg, { title: 'Remove Collaborator?', confirmText: 'Remove', cancelText: 'Cancel', variant: 'danger' })
        : confirm(msg);
      if (!confirmed) {
        return;
      }

      const alertBox = document.getElementById('collabAlertContainer');
      try {
        const fd = new FormData();
        fd.append('action', 'remove');
        fd.append('session_token', sessionToken);
        fd.append('user_id', userId);

        const resp = await fetch(`<?= BASE_URL ?>/api/collaborators.php`, {
          method: 'POST',
          body: fd
        });
        const res = await resp.json();

        if (res.success) {
          if (alertBox) {
            alertBox.innerHTML = `<div class="alert alert-success" style="padding:8px 12px;font-size:0.82rem;margin-bottom:10px;">${escapeHtml(res.message || 'Collaborator removed.')}</div>`;
          }
          await loadCollaborators();
          await loadAvailableFaculty();
        } else {
          if (alertBox) {
            alertBox.innerHTML = `<div class="alert alert-danger" style="padding:8px 12px;font-size:0.82rem;margin-bottom:10px;">${escapeHtml(res.error || 'Failed to remove collaborator.')}</div>`;
          }
        }
      } catch (err) {
        if (alertBox) {
          alertBox.innerHTML = `<div class="alert alert-danger" style="padding:8px 12px;font-size:0.82rem;margin-bottom:10px;">Network error while removing collaborator.</div>`;
        }
      }
    }

    async function handleSendToCollaborators() {
      // 1. Check title
      const titleInput = document.getElementById('f1_title');
      const titleVal = titleInput ? titleInput.value.trim() : '';
      if (!titleVal) {
        alert('Please enter an Activity Title before sending to collaborators.');
        if (typeof goToStep === 'function') {
          goToStep(1);
        } else if (typeof showStep === 'function') {
          showStep(1);
        }
        if (titleInput) titleInput.focus();
        return;
      }

      // 2. Check staged collaborators count
      if (stagedCollaboratorCount <= 0) {
        alert('You have not added any collaborators yet. Please add at least one Co-Author or Contributor before sending.');
        openCollaboratorsModal();
        return;
      }

      // 3. Confirm before sending
      const msg = `Send this proposal draft to ${stagedCollaboratorCount} collaborator(s)? They will receive access and notifications.`;
      const confirmed = window.STIModal
        ? await window.STIModal.confirm(msg, { title: 'Send to Collaborators?', confirmText: 'Send Now', cancelText: 'Cancel', variant: 'info' })
        : confirm(msg);
      if (!confirmed) {
        return;
      }

      // 4. Set action to send_to_collaborators and submit
      const actionInput = document.getElementById('proposalFormAction');
      const sendFlagInput = document.getElementById('send_to_collaborators');
      if (actionInput) actionInput.value = 'send_to_collaborators';
      if (sendFlagInput) sendFlagInput.value = '1';

      if (typeof serializeEvaluationQuestions === 'function') {
        serializeEvaluationQuestions();
      }

      const form = document.getElementById('proposalForm');
      if (form) {
        form.submit();
      }
    }
  </script>
</body>

</html>
