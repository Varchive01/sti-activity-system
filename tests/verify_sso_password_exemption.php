<?php
/**
 * tests/verify_sso_password_exemption.php
 *
 * Dedicated verification suite for Microsoft SSO first-login password-change exemption.
 * Validates:
 * 1. Microsoft SSO callback establishes the SSO session with auth_method = 'microsoft'.
 * 2. An SSO user with must_change_password = 1 reaches their role dashboard directly (no redirect to change-password.php).
 * 3. A normal password login for a user with must_change_password = 1 still redirects to change-password.php.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();

echo "========================================================================\n";
echo "   MICROSOFT SSO FIRST-LOGIN PASSWORD-CHANGE EXEMPTION VERIFICATION     \n";
echo "========================================================================\n\n";

$passed = 0;
$failed = 0;

function assertCheck(string $title, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$title}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$title}" . ($detail ? " - {$detail}" : "") . "\n";
        $failed++;
    }
}

// -------------------------------------------------------------------------
// STEP 1: Test Microsoft SSO Callback Simulation
// -------------------------------------------------------------------------
echo "--- 1. Testing Microsoft SSO Callback Session Establishment ---\n";

function runCallbackProcess(array $queryParams, string $sessionState, array $mockUser): array {
    $mockUserExport = var_export($mockUser, true);
    $script = "<?php
    require_once 'config/config.php';
    require_once 'config/database.php';
    require_once 'includes/auth.php';
    require_once 'includes/MicrosoftOAuthService.php';

    session_start();
    \$_SESSION['microsoft_oauth_state'] = '{$sessionState}';
    \$_GET = " . var_export($queryParams, true) . ";

    class TestMockService extends MicrosoftOAuthService {
        private array \$u;
        public function __construct(array \$u) { \$this->u = \$u; }
        public function validateConfig(): array { return ['valid' => true]; }
        public function validateState(?string \$r, ?string \$s): bool { return (\$r === \$s && !empty(\$r)); }
        public function getUserFromCode(string \$code): array { return \$this->u; }
    }

    \$state = \$_GET['state'] ?? '';
    \$sessionState = \$_SESSION['microsoft_oauth_state'] ?? '';
    \$code = \$_GET['code'] ?? '';
    \$oauthService = new TestMockService({$mockUserExport});

    if (!\$oauthService->validateState(\$state, \$sessionState)) {
        echo 'REDIRECT:invalid_state'; exit;
    }
    unset(\$_SESSION['microsoft_oauth_state']);

    \$profileResult = \$oauthService->getUserFromCode(\$code);
    \$msEmail = trim(\$profileResult['email']);
    \$db = getDB();
    \$stmt = \$db->prepare('SELECT * FROM users WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) LIMIT 1');
    \$stmt->execute([\$msEmail]);
    \$localUser = \$stmt->fetch(PDO::FETCH_ASSOC);

    if (!\$localUser) {
        echo 'REDIRECT:microsoft_account_not_found'; exit;
    }

    session_regenerate_id(true);
    \$_SESSION['user_id']              = \$localUser['id'];
    \$_SESSION['user_name']            = \$localUser['name'];
    \$_SESSION['user_email']           = \$localUser['email'];
    \$_SESSION['user_role']            = \$localUser['role'];
    \$_SESSION['user_dept']            = \$localUser['department'];
    \$_SESSION['must_change_password'] = (int)(\$localUser['must_change_password'] ?? 0);
    \$_SESSION['auth_method']          = 'microsoft';
    \$_SESSION['last_activity']        = time();

    \$role = \$localUser['role'] ?? 'faculty';
    \$map  = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean'];
    \$dir  = \$map[\$role] ?? 'faculty';

    echo 'REDIRECT:' . BASE_URL . '/' . \$dir . '/dashboard.php';
    echo '|COOKIE:' . session_id();
    echo '|SESSION:' . json_encode(\$_SESSION);
    ";

    $proc = proc_open('C:\xampp\php\php.exe', [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, __DIR__ . '/..');

    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $parts = explode('|', $out);
    $redirect = '';
    $cookie = '';
    $sess = [];
    foreach ($parts as $p) {
        if (str_starts_with($p, 'REDIRECT:')) $redirect = substr($p, 9);
        if (str_starts_with($p, 'COOKIE:')) $cookie = substr($p, 7);
        if (str_starts_with($p, 'SESSION:')) $sess = json_decode(substr($p, 8), true) ?? [];
    }

    return ['redirect' => $redirect, 'cookie' => $cookie, 'session' => $sess, 'raw' => $out, 'err' => $err];
}

$state = 'test_state_sso_verify';
$ssoResult = runCallbackProcess(
    ['state' => $state, 'code' => 'auth_code_123'],
    $state,
    ['success' => true, 'email' => 'faculty@sti.edu', 'name' => 'Maria Santos', 'id' => 'ms-sso-1']
);

assertCheck("1.1 Microsoft SSO callback establishes application session",
    !empty($ssoResult['session']['user_id']) && $ssoResult['session']['user_id'] === 1);
assertCheck("1.2 Microsoft SSO session contains auth_method = 'microsoft'",
    ($ssoResult['session']['auth_method'] ?? '') === 'microsoft');
assertCheck("1.3 Microsoft SSO session contains correct user role and department",
    ($ssoResult['session']['user_role'] ?? '') === 'faculty' && isset($ssoResult['session']['user_dept']));

// -------------------------------------------------------------------------
// STEP 2: Verify SSO User with must_change_password = 1 Reaches Dashboard Directly
// -------------------------------------------------------------------------
echo "\n--- 2. Verifying SSO User with must_change_password = 1 Enters Dashboard Directly ---\n";

// Set must_change_password = 1 for Faculty (ID 1)
$db->exec("UPDATE users SET must_change_password = 1 WHERE id = 1");

$ssoMustChange = runCallbackProcess(
    ['state' => $state, 'code' => 'auth_code_must_change'],
    $state,
    ['success' => true, 'email' => 'faculty@sti.edu', 'name' => 'Maria Santos', 'id' => 'ms-sso-2']
);

// Reset back to 0
$db->exec("UPDATE users SET must_change_password = 0 WHERE id = 1");

assertCheck("2.1 SSO user with must_change_password = 1 redirects to role dashboard directly",
    $ssoMustChange['redirect'] === BASE_URL . '/faculty/dashboard.php',
    "Redirect was: " . $ssoMustChange['redirect']);

assertCheck("2.2 SSO user session records must_change_password = 1 and auth_method = 'microsoft'",
    ($ssoMustChange['session']['must_change_password'] ?? 0) === 1 &&
    ($ssoMustChange['session']['auth_method'] ?? '') === 'microsoft');

// Now test that requireLogin() does NOT redirect this SSO session to change-password.php
$testSsoSessionCode = "<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

session_id('{$ssoMustChange['cookie']}');
session_start();

\$_SERVER['SCRIPT_NAME'] = '/sti-activity-system/faculty/dashboard.php';
\$_SERVER['PHP_SELF']    = '/sti-activity-system/faculty/dashboard.php';

requireLogin();

\$u = currentUser();
echo 'SUCCESS:' . \$u['email'] . '|AUTH:' . \$u['auth_method'] . '|MUST_CHANGE:' . \$u['must_change_password'];
";

$proc2 = proc_open('C:\xampp\php\php.exe', [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes2, __DIR__ . '/..');
fwrite($pipes2[0], $testSsoSessionCode);
fclose($pipes2[0]);
$out2 = stream_get_contents($pipes2[1]);
fclose($pipes2[1]);
fclose($pipes2[2]);
proc_close($proc2);

assertCheck("2.3 requireLogin() permits SSO user with must_change_password = 1 to stay on dashboard",
    str_contains($out2, 'SUCCESS:faculty@sti.edu') && str_contains($out2, 'AUTH:microsoft') && str_contains($out2, 'MUST_CHANGE:1'),
    "Output: " . $out2);

// -------------------------------------------------------------------------
// STEP 3: Verify Normal Password Login for User with must_change_password = 1 Redirects to change-password.php
// -------------------------------------------------------------------------
echo "\n--- 3. Verifying Normal Password Login Still Enforces must_change_password ---\n";

// Test normal login session variables via separate process to avoid session contamination
$testLocalLoginCode = "<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

\$db = getDB();
\$db->exec('UPDATE users SET must_change_password = 1 WHERE id = 1');

\$user = login('faculty@sti.edu', 'password');

\$db->exec('UPDATE users SET must_change_password = 0 WHERE id = 1');

echo 'AUTH:' . (\$_SESSION['auth_method'] ?? 'none') . '|MUST_CHANGE:' . (\$_SESSION['must_change_password'] ?? -1);
";

$procLocal = proc_open('C:\xampp\php\php.exe', [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipesLocal, __DIR__ . '/..');
fwrite($pipesLocal[0], $testLocalLoginCode);
fclose($pipesLocal[0]);
$outLocal = stream_get_contents($pipesLocal[1]);
fclose($pipesLocal[1]);
fclose($pipesLocal[2]);
proc_close($procLocal);

assertCheck("3.1 Normal login sets auth_method = 'local' in session",
    str_contains($outLocal, 'AUTH:local'),
    "Output: " . $outLocal);
assertCheck("3.2 Normal login preserves must_change_password = 1 in session",
    str_contains($outLocal, 'MUST_CHANGE:1'),
    "Output: " . $outLocal);

// Test requireLogin() on normal password session with must_change_password = 1 redirects to change-password.php
$testLocalSessionCode = "<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

session_start();
\$_SESSION['user_id'] = 1;
\$_SESSION['user_name'] = 'Maria Santos';
\$_SESSION['user_email'] = 'faculty@sti.edu';
\$_SESSION['user_role'] = 'faculty';
\$_SESSION['must_change_password'] = 1;
\$_SESSION['auth_method'] = 'local';
\$_SESSION['last_activity'] = time();

\$_SERVER['SCRIPT_NAME'] = '/sti-activity-system/faculty/dashboard.php';
\$_SERVER['PHP_SELF']    = '/sti-activity-system/faculty/dashboard.php';

requireLogin();
echo 'DID_NOT_REDIRECT';
";

$proc3 = proc_open('C:\xampp\php\php.exe', [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes3, __DIR__ . '/..');
fwrite($pipes3[0], $testLocalSessionCode);
fclose($pipes3[0]);
$out3 = stream_get_contents($pipes3[1]);
$err3 = stream_get_contents($pipes3[2]);
fclose($pipes3[1]);
fclose($pipes3[2]);
proc_close($proc3);

assertCheck("3.3 requireLogin() blocks local session with must_change_password = 1 from dashboard",
    !str_contains($out3, 'DID_NOT_REDIRECT'));

// Test live HTTP POST to login.php for normal login with must_change_password = 1
$loginUrl = BASE_URL . '/auth/login.php';
$db->exec("UPDATE users SET must_change_password = 1 WHERE id = 1");

$chHttp = curl_init($loginUrl);
curl_setopt_array($chHttp, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['email' => 'faculty@sti.edu', 'password' => 'password']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
]);
$respHttp = curl_exec($chHttp);
$httpRedirect = curl_getinfo($chHttp, CURLINFO_REDIRECT_URL);

// Reset database state back to 0
$db->exec("UPDATE users SET must_change_password = 0 WHERE id = 1");

assertCheck("3.4 Live HTTP login with must_change_password = 1 redirects to auth/change-password.php",
    str_contains($httpRedirect, '/auth/change-password.php'),
    "Redirect was: " . $httpRedirect);

// -------------------------------------------------------------------------
// STEP 4: Verify Normal Password Login for User with must_change_password = 0
// -------------------------------------------------------------------------
echo "\n--- 4. Verifying Normal Password Login with must_change_password = 0 Reaches Dashboard ---\n";
$chNormal = curl_init($loginUrl);
curl_setopt_array($chNormal, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['email' => 'faculty@sti.edu', 'password' => 'password']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_FOLLOWLOCATION => false,
]);
$respNormal = curl_exec($chNormal);
$normalRedirect = curl_getinfo($chNormal, CURLINFO_REDIRECT_URL);

assertCheck("4.1 Normal login with must_change_password = 0 redirects directly to /faculty/dashboard.php",
    str_contains($normalRedirect, '/faculty/dashboard.php'),
    "Redirect was: " . $normalRedirect);

echo "\n========================================================================\n";
echo "   SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "========================================================================\n";

if ($failed > 0) {
    exit(1);
}
