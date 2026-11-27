<?php
require_once __DIR__ . '/../config/database.php';

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

function requireLogin(): void
{
    startSession();

    $isApi = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/'))
        || (str_contains(str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? ''), '/api/'));

    if (empty($_SESSION['user_id'])) {
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
            exit;
        }
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_destroy();
        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Session timed out. Please log in again.']);
            exit;
        }
        header('Location: ' . BASE_URL . '/auth/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Forced first-login password change check (exempt for Microsoft SSO sessions)
    $isSso = (($_SESSION['auth_method'] ?? '') === 'microsoft');
    if (!empty($_SESSION['must_change_password']) && !$isSso) {
        $currentScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
        if (!str_ends_with($currentScript, '/auth/change-password.php') &&
            !str_ends_with($currentScript, '/auth/logout.php')) {
            if ($isApi) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Password change required before accessing API.']);
                exit;
            }
            header('Location: ' . BASE_URL . '/auth/change-password.php');
            exit;
        }
    }
}

function requireRole(string ...$roles): void
{
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        renderAccessDenied('You do not have permission to access this page.');
    }
}

/**
 * Renders a branded STI-ARMES HTTP 403 page or JSON error for unauthorized requests.
 * Ensures users can safely return to their role dashboard or sign out.
 */
function renderAccessDenied(string $message = 'You do not have permission to access this page.'): void
{
    http_response_code(403);

    $isApi = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/'));

    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }

    $role = $_SESSION['user_role'] ?? '';
    $map  = [
        'faculty'      => 'faculty',
        'admin1'       => 'admin1',
        'admin2'       => 'admin2',
        'dean'         => 'dean',
        'program_head' => 'program_head'
    ];
    $dir = $map[$role] ?? null;
    $dashboardUrl = $dir ? (defined('BASE_URL') ? BASE_URL : '') . "/{$dir}/dashboard.php" : (defined('BASE_URL') ? BASE_URL : '') . '/auth/login.php';
    $logoutUrl    = (defined('BASE_URL') ? BASE_URL : '') . '/auth/logout.php';
    $userName     = htmlspecialchars($_SESSION['user_name'] ?? 'User');
    $roleLabel    = htmlspecialchars(ucwords(str_replace('_', ' ', $role ?: 'Guest')));
    $safeMsg      = htmlspecialchars($message);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>403 - Access Denied | STI-ARMES</title>
<link rel="stylesheet" href="<?= defined('BASE_URL') ? BASE_URL : '' ?>/assets/css/main.css">
<style>
  body { background: #F8FAFC; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 24px; margin: 0; box-sizing: border-box; }
  .denied-card { background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 16px; box-shadow: 0 10px 25px -5px rgba(15,23,42,0.08), 0 8px 10px -6px rgba(15,23,42,0.04); max-width: 480px; width: 100%; padding: 40px 32px; text-align: center; box-sizing: border-box; }
  .denied-icon { width: 64px; height: 64px; background: #FEF2F2; border: 1px solid #FEE2E2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; color: #DC2626; }
  .denied-icon svg { width: 32px; height: 32px; }
  .denied-title { font-size: 1.5rem; font-weight: 800; color: #0F172A; margin: 0 0 8px; letter-spacing: -0.02em; }
  .denied-subtitle { font-size: 0.95rem; color: #64748B; margin: 0 0 20px; line-height: 1.5; }
  .denied-badge { display: inline-block; background: #F1F5F9; color: #475569; font-size: 0.8rem; font-weight: 600; padding: 6px 14px; border-radius: 9999px; margin-bottom: 24px; border: 1px solid #E2E8F0; }
  .denied-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
  .btn-denied { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; font-size: 0.875rem; font-weight: 700; border-radius: 8px; text-decoration: none; transition: all 0.15s ease-in-out; }
  .btn-denied-primary { background: #0038A8; color: #FFFFFF !important; border: 1px solid #0038A8; }
  .btn-denied-primary:hover { background: #002b82; border-color: #002b82; }
  .btn-denied-outline { background: #FFFFFF; color: #475569 !important; border: 1px solid #CBD5E1; }
  .btn-denied-outline:hover { background: #F1F5F9; color: #0F172A !important; border-color: #94A3B8; }
</style>
</head>
<body>
<div class="denied-card">
  <div class="denied-icon">
    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
    </svg>
  </div>
  <h1 class="denied-title">Access Denied</h1>
  <p class="denied-subtitle"><?= $safeMsg ?></p>
  <div class="denied-badge">Signed in as <?= $userName ?> (<?= $roleLabel ?>)</div>
  <div class="denied-actions">
    <a href="<?= htmlspecialchars($dashboardUrl) ?>" class="btn-denied btn-denied-primary">Return to My Dashboard</a>
    <a href="<?= htmlspecialchars($logoutUrl) ?>" class="btn-denied btn-denied-outline">Sign Out</a>
  </div>
</div>
</body>
</html>
    <?php
    exit;
}

function currentUser(): array
{
    return [
        'id'                   => $_SESSION['user_id']   ?? null,
        'name'                 => $_SESSION['user_name'] ?? '',
        'email'                => $_SESSION['user_email'] ?? '',
        'role'                 => $_SESSION['user_role'] ?? '',
        'dept'                 => $_SESSION['user_dept'] ?? '',
        'must_change_password' => $_SESSION['must_change_password'] ?? 0,
        'profile_picture'      => $_SESSION['user_profile_picture'] ?? null,
        'auth_method'          => $_SESSION['auth_method'] ?? 'local',
    ];
}

/**
 * Returns the canonical configuration and institutional email aliases for the 7 official test/defense accounts.
 */
function getOfficialDefenseAccountsConfig(): array
{
    return [
        'faculty@sti.edu' => [
            'email'      => 'faculty@sti.edu',
            'name'       => 'Maria Santos',
            'role'       => 'faculty',
            'department' => 'IT Department',
            'pref_id'    => 387,
            'aliases'    => ['faculty@sti.edu', 'maria.santos@marikina.sti.edu.ph', 'payod.332445@marikina.sti.edu.ph'],
            'name_like'  => '%maria%santos%',
        ],
        'dave@sti.edu' => [
            'email'      => 'dave@sti.edu',
            'name'       => 'Dave Lord Rubaya',
            'role'       => 'program_head',
            'department' => 'IT Department',
            'pref_id'    => 599,
            'aliases'    => ['dave@sti.edu', 'dave.rubaya@marikina.sti.edu.ph', 'hular.370125@marikina.sti.edu.ph', 'ph_it_accept@test.com'],
            'name_like'  => '%dave%rubaya%',
        ],
        'tan@sti.edu' => [
            'email'      => 'tan@sti.edu',
            'name'       => 'Tristan Unabia',
            'role'       => 'program_head',
            'department' => 'G.E. Department',
            'pref_id'    => 601,
            'aliases'    => ['tan@sti.edu', 'tristan.unabia@marikina.sti.edu.ph', 'ph_ge_accept@test.com'],
            'name_like'  => '%tristan%unabia%',
        ],
        'aldwin@sti.edu' => [
            'email'      => 'aldwin@sti.edu',
            'name'       => 'Aldwin Alminar',
            'role'       => 'program_head',
            'department' => 'THM Department',
            'pref_id'    => 600,
            'aliases'    => ['aldwin@sti.edu', 'aldwin.alminar@marikina.sti.edu.ph', 'ph_thm_accept@test.com'],
            'name_like'  => '%aldwin%alminar%',
        ],
        'arjay@sti.edu' => [
            'email'      => 'arjay@sti.edu',
            'name'       => 'Ar-jay Agabayani',
            'role'       => 'admin1',
            'department' => 'IT Department',
            'pref_id'    => 2,
            'aliases'    => ['arjay@sti.edu', 'ar-jay.agbayani@marikina.sti.edu.ph', 'arjay.agbayani@marikina.sti.edu.ph', 'guaniso.372499@marikina.sti.edu.ph'],
            'name_like'  => '%ag%bayani%',
        ],
        'ian@sti.edu' => [
            'email'      => 'ian@sti.edu',
            'name'       => 'Ian Jade Barangan',
            'role'       => 'admin2',
            'department' => 'G.E. Department',
            'pref_id'    => 704,
            'aliases'    => ['ian@sti.edu', 'ian.barangan@marikina.sti.edu.ph', 'ianjade.barangan@marikina.sti.edu.ph', 'regulacion.376867@marikina.sti.edu.ph'],
            'name_like'  => '%ian%barangan%',
        ],
        'dean@sti.edu' => [
            'email'      => 'dean@sti.edu',
            'name'       => 'Frederic Yulo',
            'role'       => 'dean',
            'department' => 'Administration',
            'pref_id'    => 4,
            'aliases'    => ['dean@sti.edu', 'frederic.yulo@marikina.sti.edu.ph', 'robles.376003@marikina.sti.edu.ph'],
            'name_like'  => '%frederic%yulo%',
        ],
    ];
}

/**
 * Globally self-heals required schema columns and ensures all 7 official test/defense accounts
 * exist with their proper roles, emails, and password ('password') on both local and live servers.
 */
function ensureOfficialDefenseAccounts(?PDO $db = null, bool $forceResetMustChange = false): void
{
    static $ensuredInRequest = false;
    if ($ensuredInRequest && !$forceResetMustChange) {
        return;
    }
    $ensuredInRequest = true;

    try {
        $db = $db ?? getDB();
    } catch (\Throwable $e) {
        return;
    }

    // 1. Ensure users.role ENUM includes 'program_head'
    try {
        $roleCol = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        if ($roleCol && isset($roleCol['Type']) && stripos($roleCol['Type'], 'program_head') === false) {
            $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM('faculty','admin1','admin2','dean','program_head') NOT NULL DEFAULT 'faculty'");
        }
    } catch (\Throwable $e) {}

    // 2. Ensure Phase 1 columns exist on live database (end_date, billing_unit, rate)
    try {
        $endDateCol = $db->query("SHOW COLUMNS FROM activities LIKE 'end_date'")->fetch();
        if (!$endDateCol) {
            $db->exec("ALTER TABLE activities ADD COLUMN end_date DATE NULL DEFAULT NULL AFTER event_date");
            $db->exec("UPDATE activities SET end_date = event_date WHERE end_date IS NULL AND event_date IS NOT NULL");
        }
    } catch (\Throwable $e) {}

    try {
        $billCol = $db->query("SHOW COLUMNS FROM materials LIKE 'billing_unit'")->fetch();
        if (!$billCol) {
            $db->exec("ALTER TABLE materials ADD COLUMN billing_unit VARCHAR(50) DEFAULT 'fixed' AFTER provider");
        }
        $rateCol = $db->query("SHOW COLUMNS FROM materials LIKE 'rate'")->fetch();
        if (!$rateCol) {
            $db->exec("ALTER TABLE materials ADD COLUMN rate DECIMAL(10,2) DEFAULT 0.00 AFTER billing_unit");
        }
    } catch (\Throwable $e) {}

    // 2b. Ensure Phase 2 columns exist globally (manpower.member_count, activities.invitation_mode/invitation_path, guidelines.regular_awards/attachment_path)
    try {
        $mpCountCol = $db->query("SHOW COLUMNS FROM manpower LIKE 'member_count'")->fetch();
        if (!$mpCountCol) {
            $db->exec("ALTER TABLE manpower ADD COLUMN member_count INT NOT NULL DEFAULT 1 AFTER type");
        }
    } catch (\Throwable $e) {}

    try {
        $invModeCol = $db->query("SHOW COLUMNS FROM activities LIKE 'invitation_mode'")->fetch();
        if (!$invModeCol) {
            $db->exec("ALTER TABLE activities ADD COLUMN invitation_mode VARCHAR(30) DEFAULT 'create' AFTER poster_path");
        }
        $invPathCol = $db->query("SHOW COLUMNS FROM activities LIKE 'invitation_path'")->fetch();
        if (!$invPathCol) {
            $db->exec("ALTER TABLE activities ADD COLUMN invitation_path VARCHAR(500) NULL DEFAULT NULL AFTER invitation_mode");
        }
    } catch (\Throwable $e) {}

    try {
        $regAwardsCol = $db->query("SHOW COLUMNS FROM guidelines LIKE 'regular_awards'")->fetch();
        if (!$regAwardsCol) {
            $db->exec("ALTER TABLE guidelines ADD COLUMN regular_awards TEXT NULL DEFAULT NULL AFTER scoring_system");
        }
        $glAttachCol = $db->query("SHOW COLUMNS FROM guidelines LIKE 'attachment_path'")->fetch();
        if (!$glAttachCol) {
            $db->exec("ALTER TABLE guidelines ADD COLUMN attachment_path VARCHAR(500) NULL DEFAULT NULL AFTER special_awards");
        }
    } catch (\Throwable $e) {}

    try {
        $schedTypeCol = $db->query("SHOW COLUMNS FROM schedules LIKE 'schedule_type'")->fetch();
        if (!$schedTypeCol) {
            $db->exec("ALTER TABLE schedules ADD COLUMN schedule_type VARCHAR(50) NOT NULL DEFAULT 'main_event' AFTER event_name");
        }
    } catch (\Throwable $e) {}

    // 2c. Ensure Phase 3 columns exist globally (post_event budget/equipment/media/AI fields, faculty_tasks verification/performance fields, activities.evaluation_stakeholder)
    try {
        $peCols = $db->query("SHOW COLUMNS FROM post_event")->fetchAll(PDO::FETCH_COLUMN);
        $phase3PeColumns = [
            'actual_expenses'       => "DECIMAL(12,2) NULL DEFAULT NULL",
            'approved_budget'       => "DECIMAL(12,2) NULL DEFAULT NULL",
            'budget_variance'       => "DECIMAL(12,2) NULL DEFAULT NULL",
            'budget_status'         => "VARCHAR(40) NULL DEFAULT NULL",
            'expense_breakdown_path'=> "VARCHAR(500) NULL DEFAULT NULL",
            'equipment_report_json' => "TEXT NULL DEFAULT NULL",
            'media_drive_link'      => "VARCHAR(500) NULL DEFAULT NULL",
            'comments_summary'      => "TEXT NULL DEFAULT NULL",
            'ai_recommendations'    => "TEXT NULL DEFAULT NULL",
            'ai_assessment_summary' => "TEXT NULL DEFAULT NULL",
        ];
        foreach ($phase3PeColumns as $cName => $cDef) {
            if (!in_array($cName, $peCols, true)) {
                $db->exec("ALTER TABLE post_event ADD COLUMN {$cName} {$cDef}");
            }
        }
    } catch (\Throwable $e) {}

    try {
        $ftCols = $db->query("SHOW COLUMNS FROM faculty_tasks")->fetchAll(PDO::FETCH_COLUMN);
        $phase3FtColumns = [
            'start_date'          => "DATE NULL DEFAULT NULL AFTER role_in_event",
            'verified_by_head'    => "TINYINT(1) NOT NULL DEFAULT 0",
            'verified_at'         => "DATETIME NULL DEFAULT NULL",
            'verification_status' => "VARCHAR(40) NOT NULL DEFAULT 'pending'",
            'performance_rating'  => "INT NULL DEFAULT NULL",
            'performance_remarks' => "TEXT NULL DEFAULT NULL",
        ];
        foreach ($phase3FtColumns as $cName => $cDef) {
            if (!in_array($cName, $ftCols, true)) {
                $db->exec("ALTER TABLE faculty_tasks ADD COLUMN {$cName} {$cDef}");
            }
        }
    } catch (\Throwable $e) {}

    try {
        $evalStakeCol = $db->query("SHOW COLUMNS FROM activities LIKE 'evaluation_stakeholder'")->fetch();
        if (!$evalStakeCol) {
            $db->exec("ALTER TABLE activities ADD COLUMN evaluation_stakeholder VARCHAR(60) DEFAULT 'students' AFTER evaluation_method");
        }
        $milestoneCol = $db->query("SHOW COLUMNS FROM activities LIKE 'implementation_milestones_json'")->fetch();
        if (!$milestoneCol) {
            $db->exec("ALTER TABLE activities ADD COLUMN implementation_milestones_json TEXT NULL DEFAULT NULL AFTER evaluation_stakeholder");
        }
        $uCols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('notify_email', $uCols, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!in_array('notify_in_app', $uCols, true)) {
            $db->exec("ALTER TABLE users ADD COLUMN notify_in_app TINYINT(1) NOT NULL DEFAULT 1");
        }
    } catch (\Throwable $e) {}

    // 2d. Ensure Phase 4 tables exist globally (system_audit_logs, academic_terms, authorized_organizations, academic_head_kpi_criteria) and seed 50+ test data entries
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS system_audit_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL DEFAULT NULL,
                user_name VARCHAR(150) NULL DEFAULT NULL,
                user_role VARCHAR(50) NULL DEFAULT NULL,
                event_type VARCHAR(60) NOT NULL DEFAULT 'system',
                action VARCHAR(150) NOT NULL,
                details TEXT NULL DEFAULT NULL,
                ip_address VARCHAR(64) NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS academic_terms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                term_name VARCHAR(120) NOT NULL,
                academic_year VARCHAR(40) NOT NULL,
                start_date DATE NULL DEFAULT NULL,
                end_date DATE NULL DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $termCount = (int)$db->query("SELECT COUNT(*) FROM academic_terms")->fetchColumn();
        if ($termCount === 0) {
            $db->exec("
                INSERT INTO academic_terms (term_name, academic_year, start_date, end_date, is_active) VALUES
                ('1st Semester', 'AY 2026-2027', '2026-08-10', '2026-12-18', 1),
                ('2nd Semester', 'AY 2026-2027', '2027-01-11', '2027-05-28', 0),
                ('Midyear / Summer Term', 'AY 2026-2027', '2027-06-07', '2027-07-23', 0)
            ");
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS authorized_organizations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                org_code VARCHAR(40) NOT NULL,
                org_name VARCHAR(180) NOT NULL,
                department VARCHAR(100) NOT NULL DEFAULT 'IT Department',
                adviser_name VARCHAR(150) NULL DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $orgCount = (int)$db->query("SELECT COUNT(*) FROM authorized_organizations")->fetchColumn();
        if ($orgCount === 0) {
            $db->exec("
                INSERT INTO authorized_organizations (org_code, org_name, department, adviser_name, status) VALUES
                ('SITE', 'Society of Information Technology Enthusiasts', 'IT Department', 'Maria Santos', 'active'),
                ('CSG', 'Central Student Government - STI Marikina', 'Administration', 'Ar-jay Agabayani', 'active'),
                ('THM-PEERS', 'Tourism and Hospitality Management Peers Society', 'THM Department', 'Aldwin Alminar', 'active'),
                ('GE-GUILD', 'General Education Scholars & Debaters Guild', 'G.E. Department', 'Tristan Unabia', 'active'),
                ('CODE-CLUB', 'STI Marikina Coders & Cybersecurity Collective', 'IT Department', 'Dave Lord Rubaya', 'active')
            ");
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS academic_head_kpi_criteria (
                id INT AUTO_INCREMENT PRIMARY KEY,
                criterion_name VARCHAR(160) NOT NULL,
                category VARCHAR(80) NOT NULL DEFAULT 'Academic Impact',
                target_benchmark VARCHAR(100) NOT NULL DEFAULT '85% Achievement',
                weight_pct INT NOT NULL DEFAULT 25,
                is_mandatory TINYINT(1) NOT NULL DEFAULT 1,
                updated_by VARCHAR(120) NULL DEFAULT 'Frederic Yulo (Academic Head / Dean)',
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $critCount = (int)$db->query("SELECT COUNT(*) FROM academic_head_kpi_criteria")->fetchColumn();
        if ($critCount === 0) {
            $db->exec("
                INSERT INTO academic_head_kpi_criteria (criterion_name, category, target_benchmark, weight_pct, is_mandatory) VALUES
                ('Participant Turnout & Target Attendance Rate', 'Participation & Reach', '>= 85% of Target Participants', 25, 1),
                ('Curricular & Learning Objective Achievement', 'Academic Head Assessment', '>= 4.0 / 5.0 Objective Rating', 30, 1),
                ('Budget Utilization & Liquidation Compliance', 'Financial Stewardship', 'Within 90% - 100% of Approved Budget', 20, 1),
                ('Stakeholder Satisfaction & Post-Event Deliverables', 'Quality & Compliance', '>= 4.0 / 5.0 Satisfaction + 5 Photos & Assessment Report', 25, 1)
            ");
        }
    } catch (\Throwable $e) {}

    // 3. Clean up redundant empty 'Faculty Member' placeholder if Maria Santos (ID 387) exists
    try {
        $mariaRow = $db->query("SELECT id, email FROM users WHERE id = 387 OR LOWER(name) LIKE '%maria%santos%' ORDER BY (id = 387) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $placeholderRows = $db->query("SELECT id FROM users WHERE name = 'Faculty Member' AND id != " . (int)($mariaRow['id'] ?? 0))->fetchAll(PDO::FETCH_COLUMN);
        if ($mariaRow && !empty($placeholderRows)) {
            foreach ($placeholderRows as $phId) {
                $actCount = (int)$db->query("SELECT COUNT(*) FROM activities WHERE faculty_id = " . (int)$phId)->fetchColumn();
                if ($actCount === 0) {
                    $db->prepare("DELETE FROM users WHERE id = ?")->execute([(int)$phId]);
                }
            }
        }
    } catch (\Throwable $e) {}

    // 4. Provision or repair each of the 7 official defense accounts
    $accounts = getOfficialDefenseAccountsConfig();
    $defaultPassHash = password_hash('password', PASSWORD_DEFAULT);

    foreach ($accounts as $targetEmail => $cfg) {
        try {
            // Check if exact target email already exists
            $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
            $stmt->execute([$targetEmail]);
            $exactUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($exactUser) {
                $needsPassFix = empty($exactUser['password']) || !password_verify('password', $exactUser['password']);
                $needsRoleFix = ($exactUser['role'] ?? '') !== $cfg['role'];
                $needsDeptFix = empty($exactUser['department']);
                $needsMcpFix  = $forceResetMustChange && ((int)($exactUser['must_change_password'] ?? 0) !== 0);

                if ($needsPassFix || $needsRoleFix || $needsDeptFix || $needsMcpFix) {
                    $newHash = $needsPassFix ? $defaultPassHash : $exactUser['password'];
                    $newDept = $needsDeptFix ? $cfg['department'] : $exactUser['department'];
                    $newMcp  = ($needsPassFix || $needsRoleFix || $forceResetMustChange) ? 0 : (int)($exactUser['must_change_password'] ?? 0);
                    $upd = $db->prepare("UPDATE users SET password = ?, role = ?, department = ?, must_change_password = ? WHERE id = ?");
                    $upd->execute([$newHash, $cfg['role'], $newDept, $newMcp, (int)$exactUser['id']]);
                }
            } else {
                // Find existing row by preferred ID, alias email, or name pattern
                $candidate = null;
                if (!empty($cfg['pref_id'])) {
                    $pStmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
                    $pStmt->execute([(int)$cfg['pref_id']]);
                    $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
                    if ($pRow) {
                        $pEmail = strtolower(trim((string)($pRow['email'] ?? '')));
                        // Only claim pref_id if it isn't already assigned to a different defense account target email
                        if (!isset($accounts[$pEmail]) || $pEmail === $targetEmail) {
                            $candidate = $pRow;
                        }
                    }
                }

                if (!$candidate && !empty($cfg['aliases'])) {
                    $placeholders = implode(',', array_fill(0, count($cfg['aliases']), '?'));
                    $aStmt = $db->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) IN ($placeholders) ORDER BY id ASC LIMIT 1");
                    $aStmt->execute($cfg['aliases']);
                    $candidate = $aStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                if (!$candidate && !empty($cfg['name_like'])) {
                    $nStmt = $db->prepare("SELECT * FROM users WHERE LOWER(name) LIKE ? ORDER BY id ASC LIMIT 1");
                    $nStmt->execute([$cfg['name_like']]);
                    $nRow = $nStmt->fetch(PDO::FETCH_ASSOC);
                    if ($nRow) {
                        $nEmail = strtolower(trim((string)($nRow['email'] ?? '')));
                        if (!isset($accounts[$nEmail]) || $nEmail === $targetEmail) {
                            $candidate = $nRow;
                        }
                    }
                }

                if ($candidate) {
                    $newHash = (!empty($candidate['password']) && password_verify('password', $candidate['password']))
                        ? $candidate['password']
                        : $defaultPassHash;
                    $upd = $db->prepare("UPDATE users SET email = ?, password = ?, role = ?, department = ?, must_change_password = 0 WHERE id = ?");
                    $upd->execute([
                        $targetEmail,
                        $newHash,
                        $cfg['role'],
                        $candidate['department'] ?: $cfg['department'],
                        (int)$candidate['id']
                    ]);
                } else {
                    $ins = $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
                    $ins->execute([
                        $cfg['name'],
                        $targetEmail,
                        $defaultPassHash,
                        $cfg['role'],
                        $cfg['department']
                    ]);
                }
            }

            // Also ensure any remaining secondary alias rows for this person have a working 'password' hash & role
            if (!empty($cfg['aliases'])) {
                $placeholders = implode(',', array_fill(0, count($cfg['aliases']), '?'));
                $aStmt = $db->prepare("SELECT id, password, role FROM users WHERE LOWER(TRIM(email)) IN ($placeholders)");
                $aStmt->execute($cfg['aliases']);
                while ($aRow = $aStmt->fetch(PDO::FETCH_ASSOC)) {
                    if (empty($aRow['password']) || !password_verify('password', $aRow['password']) || ($aRow['role'] ?? '') !== $cfg['role']) {
                        $db->prepare("UPDATE users SET password = ?, role = ?, must_change_password = 0 WHERE id = ?")
                           ->execute([$defaultPassHash, $cfg['role'], (int)$aRow['id']]);
                    }
                }
            }
        } catch (\Throwable $accEx) {
            error_log("Defense account self-heal notice for {$targetEmail}: " . $accEx->getMessage());
        }
    }
}

function login(string $email, string $password): array|false
{
    $db = getDB();
    ensureOfficialDefenseAccounts($db);

    $cleanEmail = strtolower(trim($email));
    $stmt       = $db->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
    $stmt->execute([$cleanEmail]);
    $user = $stmt->fetch();

    // Check explicit defense account alias groups (maps dave@sti.edu <-> hular.370125@marikina.sti.edu.ph / dave.rubaya@marikina.sti.edu.ph, etc.)
    $defenseConfigs = getOfficialDefenseAccountsConfig();
    $matchedDefenseConfig = null;
    foreach ($defenseConfigs as $defEmail => $cfg) {
        if ($cleanEmail === $defEmail || in_array($cleanEmail, $cfg['aliases'], true)) {
            $matchedDefenseConfig = $cfg;
            break;
        }
    }

    if (!$user && $matchedDefenseConfig) {
        $placeholders = implode(',', array_fill(0, count($matchedDefenseConfig['aliases']), '?'));
        $aStmt = $db->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) IN ($placeholders) ORDER BY (LOWER(TRIM(email)) = ?) DESC, id ASC LIMIT 1");
        $params = array_merge($matchedDefenseConfig['aliases'], [$matchedDefenseConfig['email']]);
        $aStmt->execute($params);
        $user = $aStmt->fetch();

        if (!$user && !empty($matchedDefenseConfig['name_like'])) {
            $nStmt = $db->prepare("SELECT * FROM users WHERE LOWER(name) LIKE ? ORDER BY id ASC LIMIT 1");
            $nStmt->execute([$matchedDefenseConfig['name_like']]);
            $user = $nStmt->fetch();
        }
    }

    // Institutional STI domain alias fallback (e.g., user@sti.edu <-> user@marikina.sti.edu.ph)
    if (!$user && str_contains($cleanEmail, '@')) {
        [$localPart, $domainPart] = explode('@', $cleanEmail, 2);
        $isStiDomain = ($domainPart === 'sti.edu' || $domainPart === 'sti.edu.ph' || str_ends_with($domainPart, '.sti.edu') || str_ends_with($domainPart, '.sti.edu.ph'));
        if ($isStiDomain && $localPart !== '') {
            $allStmt = $db->query('SELECT * FROM users');
            $allRows = $allStmt ? $allStmt->fetchAll() : [];
            $matches = [];
            foreach ($allRows as $row) {
                $rEmail = strtolower(trim((string)($row['email'] ?? '')));
                if (!str_contains($rEmail, '@')) continue;
                [$rLocal, $rDomain] = explode('@', $rEmail, 2);
                $rIsSti = ($rDomain === 'sti.edu' || $rDomain === 'sti.edu.ph' || str_ends_with($rDomain, '.sti.edu') || str_ends_with($rDomain, '.sti.edu.ph'));
                if ($rIsSti && $rLocal === $localPart) {
                    $matches[] = $row;
                }
            }
            if (count($matches) === 1) {
                $user = $matches[0];
            } elseif (count($matches) > 1) {
                foreach ($matches as $mRow) {
                    if (!empty($mRow['password']) && password_verify($password, $mRow['password'])) {
                        $user = $mRow;
                        break;
                    }
                }
                if (!$user) {
                    $user = $matches[0];
                }
            }
        }
    }

    // Auto-heal password hash on the spot if logging into an official defense account with 'password'
    if ($user && $matchedDefenseConfig && $password === 'password' && !password_verify($password, $user['password'] ?? '')) {
        $healedHash = password_hash('password', PASSWORD_DEFAULT);
        try {
            $db->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?')
               ->execute([$healedHash, $user['id']]);
            $user['password'] = $healedHash;
            $user['must_change_password'] = 0;
        } catch (\Throwable $e) {}
    }

    if (!$user || !password_verify($password, $user['password'])) {
        logSystemAuditEvent('security', 'failed_login', "Failed login attempt for email: {$email}");
        return false;
    }

    // Re-hash if algorithm/cost factor is outdated
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([$newHash, $user['id']]);
    }

    startSession();
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        @session_regenerate_id(true);
    }
    $_SESSION['user_id']              = $user['id'];
    $_SESSION['user_name']            = $user['name'];
    $_SESSION['user_email']           = $user['email'];
    $_SESSION['user_role']            = $user['role'];
    $_SESSION['user_dept']            = $user['department'];
    $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
    $_SESSION['auth_method']          = 'local';
    $_SESSION['user_profile_picture'] = $user['profile_picture'] ?? null;
    $_SESSION['last_activity']        = time();

    logSystemAuditEvent('auth', 'user_login', "User signed in via institutional portal ({$user['email']})", (int)$user['id'], $user['name'], $user['role']);
    return $user;
}

/**
 * Logs security events, user logins, and database/workflow modifications into system_audit_logs.
 */
function logSystemAuditEvent(
    string $eventType,
    string $action,
    string $details = '',
    ?int $userId = null,
    ?string $userName = null,
    ?string $userRole = null
): void {
    try {
        $db = getDB();
        $uId   = $userId   ?? ($_SESSION['user_id'] ?? null);
        $uName = $userName ?? ($_SESSION['user_name'] ?? 'System / Guest');
        $uRole = $userRole ?? ($_SESSION['user_role'] ?? 'guest');
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $db->prepare("
            INSERT INTO system_audit_logs (user_id, user_name, user_role, event_type, action, details, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$uId ? (int)$uId : null, $uName, $uRole, $eventType, $action, $details ?: null, $ip]);
    } catch (\Throwable $e) {
        // Non-blocking audit logging
    }
}

function logout(?string $redirectUrl = null): void
{
    startSession();
    $authMethod = $_SESSION['auth_method'] ?? 'local';

    // Construct Microsoft logout URL if this session authenticated via Microsoft SSO
    $msLogoutUrl = null;
    if ($authMethod === 'microsoft') {
        try {
            require_once __DIR__ . '/MicrosoftOAuthService.php';
            $oauth = new MicrosoftOAuthService();
            $configCheck = $oauth->validateConfig();
            if ($configCheck['valid']) {
                $postLogoutUri = $redirectUrl ?: ((defined('BASE_URL') ? BASE_URL : 'http://localhost/sti-activity-system') . '/auth/login.php');
                $msLogoutUrl = $oauth->getLogoutUrl($postLogoutUri);
            }
        } catch (\Throwable $e) {
            error_log("Microsoft logout URL generation error: " . $e->getMessage());
        }
    }

    // Completely clear PHP session superglobal
    $_SESSION = [];

    // Expire and clear session cookie in the client browser
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'],
            $p['domain'],
            $p['secure'],
            $p['httponly']
        );
    }

    // Expire any authentication / remember tokens if present
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 42000, '/');
    }

    // Destroy server-side session storage
    session_destroy();

    // If authenticated via Microsoft, redirect through Microsoft logout endpoint
    if (!empty($msLogoutUrl)) {
        header('Location: ' . $msLogoutUrl);
        exit;
    }

    $finalDest = $redirectUrl ?: ((defined('BASE_URL') ? BASE_URL : '') . '/auth/login.php');
    header('Location: ' . $finalDest);
    exit;
}

function generateCsrfToken(): string
{
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(?string $token): bool
{
    startSession();
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function requireCsrfToken(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        die('Invalid or missing CSRF token.');
    }
}

