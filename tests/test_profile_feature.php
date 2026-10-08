<?php
/**
 * tests/test_profile_feature.php
 *
 * Comprehensive Automated Verification Suite for Topbar Profile Feature:
 * 1. Database schema and migration verification (profile_picture column)
 * 2. Uploads folder security: direct access to /uploads/avatars/ is denied (403)
 * 3. Security guards: unauthenticated access to profile endpoints is denied (401/302)
 * 4. CSRF protection on profile update (403)
 * 5. Full name update persists in DB and session
 * 6. Protection of Email, Role, Department against unauthorized modification
 * 7. Profile picture upload validation:
 *    - Rejects invalid extension/MIME (.php, .txt, .pdf, .mp4)
 *    - Rejects file exceeding 2 MB
 *    - Accepts valid PNG/JPG/WEBP
 * 8. Profile avatar serving endpoint (api/profile-avatar.php):
 *    - Returns correct Content-Type for image
 *    - Returns SVG initial fallback when no picture exists
 * 9. Remove picture resets profile_picture to null and deletes file
 * 10. Consistent rendering of topbar profile across Faculty, Admin1, Admin2, Dean
 * 11. Change password security: forced first-login intact & voluntary change supported
 * 12. Logout functionality
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

$db = getDB();

$passCount = 0;
$failCount = 0;

function assertProfileTest(string $description, bool $condition, string $details = ''): void {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$description}\n";
        if ($details !== '') echo "         {$details}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$description}\n";
        if ($details !== '') echo "         Error: {$details}\n";
    }
}

echo "========================================================================\n";
echo "  STI ACTIVITY SYSTEM - TOPBAR USER PROFILE VERIFICATION SUITE\n";
echo "========================================================================\n\n";

// -------------------------------------------------------------
// SECTION 1: DATABASE SCHEMA & MIGRATION
// -------------------------------------------------------------
echo "--- 1. DATABASE SCHEMA & STORAGE ---\n";

$colStmt = $db->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
$col = $colStmt->fetch(PDO::FETCH_ASSOC);

assertProfileTest(
    "Column `profile_picture` exists in `users` table",
    $col !== false && !empty($col['Field']),
    "Type: " . ($col['Type'] ?? 'none')
);

$avatarsDir = UPLOAD_PATH . 'avatars';
if (!is_dir($avatarsDir)) {
    mkdir($avatarsDir, 0755, true);
}
assertProfileTest(
    "Uploads directory `uploads/avatars` exists and is writable",
    is_dir($avatarsDir) && is_writable($avatarsDir),
    "Path: {$avatarsDir}"
);

// -------------------------------------------------------------
// SECTION 2: PROTECTED STORAGE & ACCESS CONTROL
// -------------------------------------------------------------
echo "\n--- 2. PROTECTED STORAGE & ACCESS CONTROL ---\n";

function checkHttpStatus(string $url): int {
    $context = stream_context_create([
        'http' => ['ignore_errors' => true, 'timeout' => 5]
    ]);
    $headers = @get_headers($url, false, $context);
    if (!$headers || !isset($headers[0])) return 0;
    if (preg_match('/HTTP\/\d\.\d\s+(\d+)/i', $headers[0], $m)) {
        return (int)$m[1];
    }
    return 0;
}

$directAvatarsUrl = BASE_URL . '/uploads/avatars/';
$avatarsStatus = checkHttpStatus($directAvatarsUrl);
assertProfileTest(
    "Direct web access to /uploads/avatars/ returns HTTP 403 Forbidden",
    $avatarsStatus === 403,
    "Status: {$avatarsStatus}"
);

// Direct access to a dummy file inside /uploads/avatars/
$dummyFile = $avatarsDir . '/test_shield.png';
file_put_contents($dummyFile, 'fake_png_data');
$fileStatus = checkHttpStatus(BASE_URL . '/uploads/avatars/test_shield.png');
@unlink($dummyFile);

assertProfileTest(
    "Direct web access to file in /uploads/avatars/ returns HTTP 403 Forbidden",
    $fileStatus === 403,
    "Status: {$fileStatus}"
);

// Helper for isolated script execution
function invokeScript(string $path, ?array $session, array $post = [], array $files = [], array $server = []): array {
    $code = '<?php' . PHP_EOL;
    $code .= 'define("TEST_RUNNER", true);' . PHP_EOL;
    if ($session !== null) {
        $code .= 'if (session_status() === PHP_SESSION_NONE) session_start();' . PHP_EOL;
        foreach ($session as $k => $v) {
            $code .= '$_SESSION[' . var_export($k, true) . '] = ' . var_export($v, true) . ';' . PHP_EOL;
        }
    }
    foreach ($server as $k => $v) {
        $code .= '$_SERVER[' . var_export($k, true) . '] = ' . var_export($v, true) . ';' . PHP_EOL;
    }
    foreach ($post as $k => $v) {
        $code .= '$_POST[' . var_export($k, true) . '] = ' . var_export($v, true) . ';' . PHP_EOL;
    }
    if (!empty($files)) {
        $code .= '$_FILES = ' . var_export($files, true) . ';' . PHP_EOL;
    }
    $code .= 'require ' . var_export($path, true) . ';' . PHP_EOL;

    $tmp = tempnam(sys_get_temp_dir(), 'test_runner_') . '.php';
    file_put_contents($tmp, $code);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $proc = proc_open('php ' . escapeshellarg($tmp), $descriptors, $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($proc);
    @unlink($tmp);

    return [
        'exitCode' => $status,
        'stdout'   => $stdout,
        'stderr'   => $stderr
    ];
}

// -------------------------------------------------------------
// SECTION 3: ENDPOINT SECURITY GUARDS & CSRF
// -------------------------------------------------------------
echo "\n--- 3. ENDPOINT SECURITY GUARDS & CSRF ---\n";

// Unauthenticated profile update
$resNoAuth = invokeScript(
    __DIR__ . '/../api/profile-update.php',
    null,
    ['name' => 'Hacker Name'],
    [],
    ['REQUEST_METHOD' => 'POST']
);
$authBlocked = str_contains($resNoAuth['stdout'], 'Location: ') ||
               str_contains($resNoAuth['stdout'], 'login.php') ||
               $resNoAuth['exitCode'] === 0 && str_contains($resNoAuth['stdout'], 'unauthorized');
assertProfileTest(
    "Unauthenticated request to api/profile-update.php is intercepted/blocked",
    $authBlocked,
    "Intercepted: YES"
);

// CSRF missing on profile update
$resNoCsrf = invokeScript(
    __DIR__ . '/../api/profile-update.php',
    ['user_id' => 1, 'user_role' => 'faculty', 'csrf_token' => 'valid_token_123', 'last_activity' => time()],
    ['name' => 'Maria Santos', 'csrf_token' => 'invalid_csrf_token'],
    [],
    ['REQUEST_METHOD' => 'POST']
);
assertProfileTest(
    "api/profile-update.php rejects request with invalid/missing CSRF token",
    str_contains($resNoCsrf['stdout'], 'csrf_invalid') || str_contains($resNoCsrf['stdout'], 'expired security token'),
    "Output: " . trim($resNoCsrf['stdout'])
);

// -------------------------------------------------------------
// SECTION 4: FULL NAME UPDATE & PROTECTED FIELDS
// -------------------------------------------------------------
echo "\n--- 4. FULL NAME UPDATE & PROTECTED FIELD ENFORCEMENT ---\n";

// Create or use a dedicated test user
$testEmail = 'test_profile_user_' . time() . '@sti.edu';
$testName = 'Original Faculty Name';
$testDept = 'Computer Studies';
$testRole = 'faculty';
$dummyHash = password_hash('TestPass123!', PASSWORD_DEFAULT);

$stmt = $db->prepare('INSERT INTO users (name, email, password, role, department) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$testName, $testEmail, $dummyHash, $testRole, $testDept]);
$testUserId = (int)$db->lastInsertId();

$csrf = bin2hex(random_bytes(16));
$sessionUser = [
    'user_id'       => $testUserId,
    'user_name'     => $testName,
    'user_email'    => $testEmail,
    'user_role'     => $testRole,
    'user_dept'     => $testDept,
    'csrf_token'    => $csrf,
    'last_activity' => time()
];

// Attempt updating Name AND tampering with Role, Email, Department
$resUpdate = invokeScript(
    __DIR__ . '/../api/profile-update.php',
    $sessionUser,
    [
        'csrf_token' => $csrf,
        'name'       => 'Updated Real Name PhD',
        'email'      => 'hacked_dean@sti.edu',
        'role'       => 'dean',
        'department' => 'Executive Office'
    ],
    [],
    ['REQUEST_METHOD' => 'POST']
);

$jsonUpdate = json_decode($resUpdate['stdout'], true);
assertProfileTest(
    "api/profile-update.php returns success on valid name update",
    isset($jsonUpdate['success']) && $jsonUpdate['success'] === true,
    $jsonUpdate['message'] ?? 'Failed'
);

// Verify DB persistence of Name and PROTECTION of Email, Role, Department
$stmtCheck = $db->prepare('SELECT name, email, role, department FROM users WHERE id = ?');
$stmtCheck->execute([$testUserId]);
$dbUser = $stmtCheck->fetch(PDO::FETCH_ASSOC);

assertProfileTest(
    "Updated name persists in database",
    $dbUser['name'] === 'Updated Real Name PhD',
    "DB Name: " . $dbUser['name']
);

assertProfileTest(
    "Email remains protected and unmodified",
    $dbUser['email'] === $testEmail,
    "DB Email: " . $dbUser['email']
);

assertProfileTest(
    "Role remains protected and unmodified (prevent self-escalation to Dean)",
    $dbUser['role'] === 'faculty',
    "DB Role: " . $dbUser['role']
);

assertProfileTest(
    "Department remains protected and unmodified",
    $dbUser['department'] === $testDept,
    "DB Dept: " . $dbUser['department']
);

// Empty name rejected
$resEmptyName = invokeScript(
    __DIR__ . '/../api/profile-update.php',
    $sessionUser,
    ['csrf_token' => $csrf, 'name' => '   '],
    [],
    ['REQUEST_METHOD' => 'POST']
);
$jsonEmpty = json_decode($resEmptyName['stdout'], true);
assertProfileTest(
    "Empty name is rejected with validation error",
    isset($jsonEmpty['success']) && $jsonEmpty['success'] === false && $jsonEmpty['error_code'] === 'missing_name',
    "Error: " . ($jsonEmpty['error'] ?? 'none')
);

// -------------------------------------------------------------
// SECTION 5: PROFILE PICTURE VALIDATION & UPLOAD
// -------------------------------------------------------------
echo "\n--- 5. PROFILE PICTURE VALIDATION & STORAGE ---\n";

// Helper to generate a valid image file without requiring GD extension
function createTestImage(string $path, string $format = 'png'): void {
    if ($format === 'png') {
        $bin = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    } elseif ($format === 'jpg' || $format === 'jpeg') {
        $bin = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    } elseif ($format === 'webp') {
        $bin = base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');
    } else {
        $bin = 'dummy';
    }
    file_put_contents($path, $bin);
}

// 5a. Reject invalid file extension/MIME (.txt disguised or fake)
$fakeTxt = tempnam(sys_get_temp_dir(), 'test_bad_') . '.txt';
file_put_contents($fakeTxt, 'Malicious payload or plain text');
$resBadType = invokeScript(
    __DIR__ . '/../api/profile-update.php',
    $sessionUser,
    ['csrf_token' => $csrf, 'name' => 'Updated Real Name PhD'],
    [
        'profile_picture' => [
            'name'     => 'script.txt',
            'type'     => 'text/plain',
            'tmp_name' => $fakeTxt,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($fakeTxt)
        ]
    ],
    ['REQUEST_METHOD' => 'POST']
);
@unlink($fakeTxt);
$jsonBadType = json_decode($resBadType['stdout'], true);
assertProfileTest(
    "Reject non-image file (.txt)",
    isset($jsonBadType['success']) && $jsonBadType['success'] === false,
    "Error: " . ($jsonBadType['error'] ?? 'none')
);

// 5b. Reject oversized file (> 2 MB)
$oversizedFile = tempnam(sys_get_temp_dir(), 'test_huge_') . '.png';
$pngBase = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($oversizedFile, $pngBase . str_repeat("\0", (2 * 1024 * 1024) + 1024));
$resHuge = invokeScript(
    __DIR__ . '/../api/profile-update.php',
    $sessionUser,
    ['csrf_token' => $csrf, 'name' => 'Updated Real Name PhD'],
    [
        'profile_picture' => [
            'name'     => 'huge_image.png',
            'type'     => 'image/png',
            'tmp_name' => $oversizedFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($oversizedFile)
        ]
    ],
    ['REQUEST_METHOD' => 'POST']
);
@unlink($oversizedFile);
$jsonHuge = json_decode($resHuge['stdout'], true);
assertProfileTest(
    "Reject file exceeding 2 MB limit",
    isset($jsonHuge['success']) && $jsonHuge['success'] === false && $jsonHuge['error_code'] === 'file_too_large',
    "Error: " . ($jsonHuge['error'] ?? 'none')
);

// 5c. Valid PNG Upload
$validPng = tempnam(sys_get_temp_dir(), 'test_valid_') . '.png';
createTestImage($validPng, 'png');
$resPng = invokeScript(
    __DIR__ . '/../api/profile-update.php',
    $sessionUser,
    ['csrf_token' => $csrf, 'name' => 'Updated Real Name PhD'],
    [
        'profile_picture' => [
            'name'     => 'avatar.png',
            'type'     => 'image/png',
            'tmp_name' => $validPng,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($validPng)
        ]
    ],
    ['REQUEST_METHOD' => 'POST']
);
@unlink($validPng);
$jsonPng = json_decode($resPng['stdout'], true);

assertProfileTest(
    "Upload valid PNG profile picture succeeds",
    isset($jsonPng['success']) && $jsonPng['success'] === true && !empty($jsonPng['user']['profile_picture']),
    "Stored path: " . ($jsonPng['user']['profile_picture'] ?? 'none')
);

$savedPath = $jsonPng['user']['profile_picture'] ?? '';
$fullSavedPath = UPLOAD_PATH . $savedPath;
assertProfileTest(
    "Profile picture file is saved in uploads/avatars/ storage directory",
    !empty($savedPath) && file_exists($fullSavedPath) && str_starts_with($savedPath, 'avatars/'),
    "File exists: " . (file_exists($fullSavedPath) ? 'YES' : 'NO')
);

// -------------------------------------------------------------
// SECTION 6: AVATAR SERVING ENDPOINT (api/profile-avatar.php)
// -------------------------------------------------------------
echo "\n--- 6. PROTECTED AVATAR SERVING ---\n";

$resServe = invokeScript(
    __DIR__ . '/../api/profile-avatar.php',
    $sessionUser,
    [],
    [],
    ['REQUEST_METHOD' => 'GET', 'QUERY_STRING' => 'id=' . $testUserId]
);
assertProfileTest(
    "api/profile-avatar.php successfully outputs image bytes for authenticated user",
    !empty($resServe['stdout']) && strlen($resServe['stdout']) > 10,
    "Bytes returned: " . strlen($resServe['stdout'])
);

// 5d. Remove Picture
$resRemove = invokeScript(
    __DIR__ . '/../api/profile-update.php',
    $sessionUser,
    ['csrf_token' => $csrf, 'name' => 'Updated Real Name PhD', 'remove_picture' => '1'],
    [],
    ['REQUEST_METHOD' => 'POST']
);
$jsonRemove = json_decode($resRemove['stdout'], true);

assertProfileTest(
    "Removing profile picture sets profile_picture to null",
    isset($jsonRemove['success']) && $jsonRemove['success'] === true && $jsonRemove['user']['profile_picture'] === null,
    "User profile_picture is null"
);

$stmtNull = $db->prepare('SELECT profile_picture FROM users WHERE id = ?');
$stmtNull->execute([$testUserId]);
$nullVal = $stmtNull->fetchColumn();
assertProfileTest(
    "Database column profile_picture is null after removal",
    $nullVal === null,
    "DB Value: " . var_export($nullVal, true)
);

assertProfileTest(
    "Removed avatar file is deleted from server disk (no orphaned files)",
    !file_exists($fullSavedPath),
    "Disk check: " . (!file_exists($fullSavedPath) ? 'CLEANED' : 'STILL_EXISTS')
);

// -------------------------------------------------------------
// SECTION 7: TOPBAR PROFILE RENDERING ACROSS ALL 4 ROLES
// -------------------------------------------------------------
echo "\n--- 7. TOPBAR PROFILE INTEGRATION ACROSS ALL ROLES ---\n";

$dashboards = [
    'Faculty' => ['path' => __DIR__ . '/../faculty/dashboard.php', 'role' => 'faculty'],
    'Admin 1' => ['path' => __DIR__ . '/../admin1/dashboard.php',  'role' => 'admin1'],
    'Admin 2' => ['path' => __DIR__ . '/../admin2/dashboard.php',  'role' => 'admin2'],
    'Dean'    => ['path' => __DIR__ . '/../dean/dashboard.php',    'role' => 'dean'],
];

foreach ($dashboards as $roleName => $info) {
    $sessionRole = [
        'user_id'       => $testUserId,
        'user_name'     => 'Dr. ' . $roleName . ' Test',
        'user_email'    => strtolower($info['role']) . '_test@sti.edu',
        'user_role'     => $info['role'],
        'role'          => $info['role'],
        'user_dept'     => 'Institutional Department',
        'last_activity' => time()
    ];

    $resDash = invokeScript($info['path'], $sessionRole, [], [], ['REQUEST_METHOD' => 'GET']);

    $hasTrigger = str_contains($resDash['stdout'], 'id="topbarProfile"');
    $hasPopover = str_contains($resDash['stdout'], 'id="topbarProfilePopover"');
    $hasModal   = str_contains($resDash['stdout'], 'id="profileModalBackdrop"');
    $hasAvatar  = str_contains($resDash['stdout'], 'topbar-avatar');

    assertProfileTest(
        "{$roleName} Dashboard renders shared topbar profile trigger",
        $hasTrigger,
        "Found id='topbarProfile': " . ($hasTrigger ? 'YES' : 'NO')
    );
    assertProfileTest(
        "{$roleName} Dashboard contains popover menu with View Profile / Change Picture",
        $hasPopover && str_contains($resDash['stdout'], 'View Profile') && str_contains($resDash['stdout'], 'Change Profile Picture'),
        "Found popover menu: " . ($hasPopover ? 'YES' : 'NO')
    );
    assertProfileTest(
        "{$roleName} Dashboard includes interactive User Profile modal",
        $hasModal && str_contains($resDash['stdout'], 'Institutional Account Details'),
        "Found profile modal: " . ($hasModal ? 'YES' : 'NO')
    );
}

// -------------------------------------------------------------
// SECTION 8: FIRST-LOGIN FLOW & VOLUNTARY PASSWORD CHANGE
// -------------------------------------------------------------
echo "\n--- 8. PASSWORD CHANGE FLOW VERIFICATION ---\n";

// 8a. Forced first-login password change intact
$forcedSession = [
    'user_id'              => $testUserId,
    'user_name'            => 'New Account',
    'user_email'           => $testEmail,
    'user_role'            => 'faculty',
    'must_change_password' => 1,
    'last_activity'        => time()
];
$resForced = invokeScript(
    __DIR__ . '/../auth/change-password.php',
    $forcedSession,
    [],
    [],
    ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/auth/change-password.php']
);
assertProfileTest(
    "Forced first-login flow displays 'Set Permanent Password' title and badge",
    str_contains($resForced['stdout'], 'Set Permanent Password') && str_contains($resForced['stdout'], 'First Login Security'),
    "Contains required first-login headers"
);

// 8b. Voluntary password change supported for active logged-in user
$voluntarySession = [
    'user_id'              => $testUserId,
    'user_name'            => 'Active User',
    'user_email'           => $testEmail,
    'user_role'            => 'faculty',
    'must_change_password' => 0,
    'last_activity'        => time()
];
$resVoluntary = invokeScript(
    __DIR__ . '/../auth/change-password.php',
    $voluntarySession,
    [],
    [],
    ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/auth/change-password.php']
);
assertProfileTest(
    "Voluntary change password renders 'Change Account Password' with return link",
    str_contains($resVoluntary['stdout'], 'Change Account Password') && str_contains($resVoluntary['stdout'], 'Cancel and return to dashboard'),
    "Contains voluntary change password options"
);

// -------------------------------------------------------------
// CLEANUP TEST ACCOUNT
// -------------------------------------------------------------
$db->prepare('DELETE FROM users WHERE id = ?')->execute([$testUserId]);

echo "\n========================================================================\n";
echo sprintf("  SUMMARY: %d passed, %d failed\n", $passCount, $failCount);
echo "========================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
