<?php
require_once __DIR__ . '/../config/database.php';

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function requireLogin(): void
{
    startSession();
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_destroy();
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

function login(string $email, string $password): array|false
{
    $db         = getDB();
    $cleanEmail = strtolower(trim($email));
    $stmt       = $db->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1');
    $stmt->execute([$cleanEmail]);
    $user = $stmt->fetch();

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
            }
        }
    }

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    // Re-hash if algorithm/cost factor is outdated
    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')
            ->execute([$newHash, $user['id']]);
    }

    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']              = $user['id'];
    $_SESSION['user_name']            = $user['name'];
    $_SESSION['user_email']           = $user['email'];
    $_SESSION['user_role']            = $user['role'];
    $_SESSION['user_dept']            = $user['department'];
    $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
    $_SESSION['auth_method']          = 'local';
    $_SESSION['user_profile_picture'] = $user['profile_picture'] ?? null;
    $_SESSION['last_activity']        = time();
    return $user;
}

function logout(): void
{
    startSession();
    $_SESSION = [];
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
    session_destroy();
    header('Location: ' . BASE_URL . '/auth/login.php');
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

