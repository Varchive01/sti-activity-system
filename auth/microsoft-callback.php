<?php
// auth/microsoft-callback.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/MicrosoftOAuthService.php';

startSession();

// Check if Microsoft or user cancelled / returned error
if (!empty($_GET['error'])) {
    unset($_SESSION['microsoft_oauth_state']);
    header("Location: " . BASE_URL . "/auth/login.php?error=microsoft_oauth_failed");
    exit;
}

$state = $_GET['state'] ?? '';
$sessionState = $_SESSION['microsoft_oauth_state'] ?? '';
$code = $_GET['code'] ?? '';

$oauthService = new MicrosoftOAuthService();

// Validate OAuth state using constant-time hash_equals()
if (!$oauthService->validateState($state, $sessionState)) {
    unset($_SESSION['microsoft_oauth_state']);
    header("Location: " . BASE_URL . "/auth/login.php?error=invalid_state");
    exit;
}

// Clean up state token immediately after validation
unset($_SESSION['microsoft_oauth_state']);

if (empty($code)) {
    header("Location: " . BASE_URL . "/auth/login.php?error=microsoft_oauth_failed");
    exit;
}

// Exchange code for access token and fetch profile from Microsoft Graph
$profileResult = $oauthService->getUserFromCode($code);

if (!$profileResult['success'] || empty($profileResult['email'])) {
    $err = (isset($profileResult['error']) && str_contains($profileResult['error'], 'email'))
        ? 'missing_email'
        : 'microsoft_oauth_failed';
    header("Location: " . BASE_URL . "/auth/login.php?error=" . $err);
    exit;
}

$msEmail = trim($profileResult['email']);
$msEmails = !empty($profileResult['emails']) && is_array($profileResult['emails'])
    ? $profileResult['emails']
    : [$msEmail];

// Normalize all candidate emails returned by Microsoft
$normalizedCandidates = [];
foreach ($msEmails as $cand) {
    $clean = strtolower(trim((string)$cand));
    if ($clean !== '' && !in_array($clean, $normalizedCandidates, true)) {
        $normalizedCandidates[] = $clean;
    }
}

// Map Microsoft email to an existing local user account ONLY
$db = getDB();
$localUser = false;

// Step 1: Exact case-insensitive match against any of the user's Microsoft email addresses / aliases
if (!empty($normalizedCandidates)) {
    $placeholders = implode(',', array_fill(0, count($normalizedCandidates), '?'));
    $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(TRIM(email)) IN ($placeholders) LIMIT 1");
    $stmt->execute($normalizedCandidates);
    $localUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Step 2: Institutional STI domain alias equivalence & unique display name fallback
// Handles cases where an admin registered `user@sti.edu` or `user@sti.edu.ph` while Microsoft returns `user@marikina.sti.edu.ph` (or vice versa)
if (!$localUser && !empty($normalizedCandidates)) {
    $stiLocalParts = [];
    foreach ($normalizedCandidates as $cand) {
        if (str_contains($cand, '@')) {
            [$localPart, $domainPart] = explode('@', $cand, 2);
            if ($domainPart === 'sti.edu' || $domainPart === 'sti.edu.ph' || str_ends_with($domainPart, '.sti.edu') || str_ends_with($domainPart, '.sti.edu.ph')) {
                $stiLocalParts[] = $localPart;
            }
        }
    }

    if (!empty($stiLocalParts)) {
        $stiLocalParts = array_values(array_unique($stiLocalParts));
        $allUsersStmt = $db->query("SELECT * FROM users");
        $allUsers = $allUsersStmt ? $allUsersStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $domainMatchedUsers = [];
        foreach ($allUsers as $uRow) {
            $uEmail = strtolower(trim((string)($uRow['email'] ?? '')));
            if (!str_contains($uEmail, '@')) {
                continue;
            }
            [$uLocal, $uDomain] = explode('@', $uEmail, 2);
            $isStiDomain = ($uDomain === 'sti.edu' || $uDomain === 'sti.edu.ph' || str_ends_with($uDomain, '.sti.edu') || str_ends_with($uDomain, '.sti.edu.ph'));
            if ($isStiDomain && in_array($uLocal, $stiLocalParts, true)) {
                $domainMatchedUsers[] = $uRow;
            }
        }

        if (count($domainMatchedUsers) === 1) {
            $localUser = $domainMatchedUsers[0];
        } elseif (count($domainMatchedUsers) > 1 && !empty($profileResult['name'])) {
            $msNameNorm = strtolower(preg_replace('/\s+/', ' ', trim((string)$profileResult['name'])));
            foreach ($domainMatchedUsers as $dmUser) {
                $dbNameNorm = strtolower(preg_replace('/\s+/', ' ', trim((string)($dmUser['name'] ?? ''))));
                if ($dbNameNorm !== '' && $dbNameNorm === $msNameNorm) {
                    $localUser = $dmUser;
                    break;
                }
            }
        }
    }
}

// Step 3: If Microsoft returned an institutional STI account (or verified Microsoft account) whose displayName
// uniquely matches a single registered user in the system (e.g., admin typed `tan@sti.edu` for `Tristan Unabia`
// while Microsoft UPN is `tristan.unabia@marikina.sti.edu.ph`), match only if unambiguous (exactly 1 user with that full name)
if (!$localUser && !empty($profileResult['name'])) {
    $msNameNorm = strtolower(preg_replace('/\s+/', ' ', trim((string)$profileResult['name'])));
    if ($msNameNorm !== '') {
        $nameMatches = [];
        $allUsersStmt = isset($allUsers) ? null : $db->query("SELECT * FROM users");
        $userRows = $allUsers ?? ($allUsersStmt ? $allUsersStmt->fetchAll(PDO::FETCH_ASSOC) : []);
        foreach ($userRows as $uRow) {
            $dbNameNorm = strtolower(preg_replace('/\s+/', ' ', trim((string)($uRow['name'] ?? ''))));
            if ($dbNameNorm === $msNameNorm) {
                $nameMatches[] = $uRow;
            }
        }
        if (count($nameMatches) === 1) {
            $localUser = $nameMatches[0];
        }
    }
}

if (!$localUser) {
    // Policy requirement: Unknown Microsoft account MUST be rejected.
    // NEVER automatically create an account or assign a default role.
    error_log("Microsoft Login Unmatched Account: MS email='" . $msEmail . "', candidates=" . json_encode($normalizedCandidates) . ", MS name='" . ($profileResult['name'] ?? '') . "'");
    $_SESSION['unmatched_ms_email'] = $msEmail;
    header("Location: " . BASE_URL . "/auth/login.php?error=microsoft_account_not_found");
    exit;
}

// Security: Regenerate session ID upon successful authentication
session_regenerate_id(true);

// Establish application session preserving the existing local user role exactly
$_SESSION['user_id']              = $localUser['id'];
$_SESSION['user_name']            = $localUser['name'];
$_SESSION['user_email']           = $localUser['email'];
$_SESSION['user_role']            = $localUser['role'];
$_SESSION['user_dept']            = $localUser['department'];
$_SESSION['must_change_password'] = (int)($localUser['must_change_password'] ?? 0);
$_SESSION['auth_method']          = 'microsoft';
$_SESSION['last_activity']        = time();

// Clear login throttle state for this account
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$throttleKey = hash('sha256', $clientIp . '|' . strtolower($localUser['email']));
unset($_SESSION['login_throttle'][$throttleKey]);

// Route to authorized role dashboard (Microsoft SSO sessions are exempt from local password change)
$role = $localUser['role'] ?? 'faculty';
$map  = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean', 'program_head' => 'program_head'];
$dir  = $map[$role] ?? 'faculty';

header("Location: " . BASE_URL . "/{$dir}/dashboard.php");
exit;
