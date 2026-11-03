<?php
/**
 * STI-ARMES Task 10: Complete Manual Testing and Functional Acceptance Suite
 * Runs browser/HTTP acceptance tests via cURL against local Apache web server.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/sti-activity-system';

echo "=======================================================================\n";
echo "STI-ARMES TASK 10: COMPLETE MANUAL & FUNCTIONAL ACCEPTANCE TEST SUITE\n";
echo "Target Base URL: $baseUrl\n";
echo "=======================================================================\n\n";

$testResults = [];

function recordResult(string $id, string $module, string $scenario, string $expected, string $actual, bool $pass) {
    global $testResults;
    $status = $pass ? 'Pass' : 'Fail';
    $testResults[] = [
        'id'       => $id,
        'module'   => $module,
        'scenario' => $scenario,
        'expected' => $expected,
        'actual'   => $actual,
        'status'   => $status
    ];
    echo "[" . ($pass ? "PASS" : "FAIL") . "] $id | $module: $scenario\n";
    if (!$pass) {
        echo "   Expected: $expected\n";
        echo "   Actual:   $actual\n";
    }
}

// HTTP Helper using curl with cookie jar for real session persistence
function makeHttpRequest(string $url, string $method = 'GET', $data = null, ?string $cookieFile = null, array $headers = []): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        } elseif (is_string($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    $error = curl_error($ch);

    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'code'        => $httpCode,
        'headers'     => $headerStr,
        'body'        => $body,
        'redirect'    => $redirectUrl,
        'error'       => $error
    ];
}

$cookieDir = __DIR__ . '/cookies';
if (!is_dir($cookieDir)) {
    mkdir($cookieDir, 0755, true);
}

// ── STEP 1: PREPARE TESTING ACCOUNTS ─────────────────────────────────
echo "\n--- STEP 1: PREPARE TESTING ACCOUNTS ---\n";
$accounts = [
    'admin1' => ['name' => 'Ar-jay Agbayani', 'email' => 'arjay@sti.edu', 'password' => 'password', 'role' => 'admin1', 'dept' => 'IT Department'],
    'admin2' => ['name' => 'Ian Jade Barangan', 'email' => 'ian@sti.edu', 'password' => 'password', 'role' => 'admin2', 'dept' => 'Events Committee Head'],
    'dean'   => ['name' => 'Dr. Frederic Yulo', 'email' => 'dean@sti.edu', 'password' => 'password', 'role' => 'dean', 'dept' => 'Administration'],
    'fac_it' => ['name' => 'Maria Santos', 'email' => 'faculty@sti.edu', 'password' => 'password', 'role' => 'faculty', 'dept' => 'IT Department'],
    'fac_thm'=> ['name' => 'Andrea Gatbunton', 'email' => 'fac_thm_accept@test.com', 'password' => 'password', 'role' => 'faculty', 'dept' => 'THM Department'],
    'fac_ge' => ['name' => 'Ricky Manliguis', 'email' => 'fac_ge_accept@test.com', 'password' => 'password', 'role' => 'faculty', 'dept' => 'G.E. Department'],
    'ph_it'  => ['name' => 'Dave Lord Rubaya', 'email' => 'ph_it_accept@test.com', 'password' => 'password', 'role' => 'program_head', 'dept' => 'IT Department'],
    'ph_thm' => ['name' => 'Aldwin Alminar', 'email' => 'ph_thm_accept@test.com', 'password' => 'password', 'role' => 'program_head', 'dept' => 'THM Department'],
    'ph_ge'  => ['name' => 'Tristan Unabia', 'email' => 'ph_ge_accept@test.com', 'password' => 'password', 'role' => 'program_head', 'dept' => 'G.E. Department']
];

$cleanupUserIds = [];

foreach ($accounts as $k => $acc) {
    $stmt = $db->prepare("SELECT id, password, role, department FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$acc['email']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $accounts[$k]['id'] = (int)$existing['id'];
        if (!password_verify($acc['password'], $existing['password'])) {
            $newHash = password_hash($acc['password'], PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password = ?, department = ?, role = ? WHERE id = ?")
               ->execute([$newHash, $acc['dept'], $acc['role'], $existing['id']]);
        }
        echo "Found verified account: {$acc['name']} ({$acc['role']} - {$acc['dept']})\n";
    } else {
        $hash = password_hash($acc['password'], PASSWORD_DEFAULT);
        $ins = $db->prepare("INSERT INTO users (name, email, password, role, department, must_change_password) VALUES (?, ?, ?, ?, ?, 0)");
        $ins->execute([$acc['name'], $acc['email'], $hash, $acc['role'], $acc['dept']]);
        $newId = (int)$db->lastInsertId();
        $accounts[$k]['id'] = $newId;
        $cleanupUserIds[] = $newId;
        echo "Created test account: {$acc['name']} ({$acc['role']} - {$acc['dept']}) [ID: $newId]\n";
    }
}

// ── STEP 2: AUTHENTICATION AND ACCOUNT MANAGEMENT ────────────────────
echo "\n--- STEP 2: AUTHENTICATION AND ACCOUNT MANAGEMENT ---\n";

// MT-001: Login for Program Head IT
$cFilePhIt = $cookieDir . '/ph_it.txt';
@unlink($cFilePhIt);
$resp = makeHttpRequest($baseUrl . '/auth/login.php', 'POST', [
    'email' => $accounts['ph_it']['email'],
    'password' => $accounts['ph_it']['password']
], $cFilePhIt);
$expectedRedirect = $baseUrl . '/program_head/dashboard.php';
$isRedirectPh = ($resp['code'] === 302 && str_contains($resp['redirect'], '/program_head/dashboard.php'));
recordResult('MT-001', 'Auth', 'Program Head IT login and dashboard redirect', "302 Redirect to /program_head/dashboard.php", "HTTP {$resp['code']} Redirect: {$resp['redirect']}", $isRedirectPh);

// MT-002: Login for Faculty IT
$cFileFacIt = $cookieDir . '/fac_it.txt';
@unlink($cFileFacIt);
$resp = makeHttpRequest($baseUrl . '/auth/login.php', 'POST', [
    'email' => $accounts['fac_it']['email'],
    'password' => $accounts['fac_it']['password']
], $cFileFacIt);
$isRedirectFac = ($resp['code'] === 302 && str_contains($resp['redirect'], '/faculty/dashboard.php'));
recordResult('MT-002', 'Auth', 'Faculty IT login and dashboard redirect', "302 Redirect to /faculty/dashboard.php", "HTTP {$resp['code']} Redirect: {$resp['redirect']}", $isRedirectFac);

// MT-003: Login for Dean
$cFileDean = $cookieDir . '/dean.txt';
@unlink($cFileDean);
$resp = makeHttpRequest($baseUrl . '/auth/login.php', 'POST', [
    'email' => $accounts['dean']['email'],
    'password' => $accounts['dean']['password']
], $cFileDean);
$isRedirectDean = ($resp['code'] === 302 && str_contains($resp['redirect'], '/dean/dashboard.php'));
recordResult('MT-003', 'Auth', 'Dean login and dashboard redirect', "302 Redirect to /dean/dashboard.php", "HTTP {$resp['code']} Redirect: {$resp['redirect']}", $isRedirectDean);

// MT-004: Login for Admin 1
$cFileAdmin1 = $cookieDir . '/admin1.txt';
@unlink($cFileAdmin1);
$resp = makeHttpRequest($baseUrl . '/auth/login.php', 'POST', [
    'email' => $accounts['admin1']['email'],
    'password' => $accounts['admin1']['password']
], $cFileAdmin1);
$isRedirectAdmin1 = ($resp['code'] === 302 && str_contains($resp['redirect'], '/admin1/dashboard.php'));
recordResult('MT-004', 'Auth', 'Admin 1 login and dashboard redirect', "302 Redirect to /admin1/dashboard.php", "HTTP {$resp['code']} Redirect: {$resp['redirect']}", $isRedirectAdmin1);

// MT-005: Login for Admin 2
$cFileAdmin2 = $cookieDir . '/admin2.txt';
@unlink($cFileAdmin2);
$resp = makeHttpRequest($baseUrl . '/auth/login.php', 'POST', [
    'email' => $accounts['admin2']['email'],
    'password' => $accounts['admin2']['password']
], $cFileAdmin2);
$isRedirectAdmin2 = ($resp['code'] === 302 && str_contains($resp['redirect'], '/admin2/dashboard.php'));
recordResult('MT-005', 'Auth', 'Admin 2 login and dashboard redirect', "302 Redirect to /admin2/dashboard.php", "HTTP {$resp['code']} Redirect: {$resp['redirect']}", $isRedirectAdmin2);

// MT-006: Invalid Password Handling
$resp = makeHttpRequest($baseUrl . '/auth/login.php', 'POST', [
    'email' => $accounts['ph_it']['email'],
    'password' => 'WrongPassword!'
]);
$hasInvalidMsg = str_contains($resp['body'], 'Invalid email or password');
recordResult('MT-006', 'Auth', 'Invalid password rejection', "Show 'Invalid email or password' error", "Status {$resp['code']} - " . ($hasInvalidMsg ? "Shows invalid password message" : "No error shown"), $hasInvalidMsg);

// Extract CSRF token from Dean user management page for MT-007
$deanUsersPage = makeHttpRequest($baseUrl . '/dean/users.php', 'GET', null, $cFileDean);
$csrfToken = '';
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $deanUsersPage['body'], $m)) {
    $csrfToken = $m[1];
}

// MT-007: Program Head Department Assignment Validation via API
$resp = makeHttpRequest($baseUrl . '/api/user-create.php', 'POST', [
    'csrf_token' => $csrfToken,
    'name' => 'Bad PH Test',
    'email' => 'bad_ph_' . time() . '@test.com',
    'password' => 'Pass123!',
    'role' => 'program_head',
    'department' => 'Administration' // Invalid for PH
], $cFileDean, ['Accept: application/json']);
$data = json_decode($resp['body'], true);
$rejectedInvalidDept = (($data['error_code'] ?? '') === 'missing_program_head_department');
recordResult('MT-007', 'Account Management', 'Reject invalid Program Head department assignment', "Error code missing_program_head_department", "Response: {$resp['body']}", $rejectedInvalidDept);

// MT-008: Role Protection: Program Head cannot open Dean-only page
$resp = makeHttpRequest($baseUrl . '/dean/dashboard.php', 'GET', null, $cFilePhIt);
$deanProtected = ($resp['code'] === 403 || str_contains($resp['body'], 'Access denied') || str_contains($resp['body'], 'login.php'));
recordResult('MT-008', 'Security', 'Program Head blocked from Dean-only dashboard', "Access Denied (403)", "HTTP Code {$resp['code']}", $deanProtected);

// MT-009: Role Protection: Program Head cannot open Admin 2 Users page
$resp = makeHttpRequest($baseUrl . '/admin2/users.php', 'GET', null, $cFilePhIt);
$adminProtected = ($resp['code'] === 403 || str_contains($resp['body'], 'Access denied') || str_contains($resp['body'], 'login.php'));
recordResult('MT-009', 'Security', 'Program Head blocked from Admin2 User Management', "Access Denied (403)", "HTTP Code {$resp['code']}", $adminProtected);


// ── STEP 3: PROGRAM HEAD WORKFLOW ────────────────────────────────────
echo "\n--- STEP 3: PROGRAM HEAD WORKFLOW ---\n";

// MT-010: Program Head IT Dashboard Content & Department Badge
$resp = makeHttpRequest($baseUrl . '/program_head/dashboard.php', 'GET', null, $cFilePhIt);
$hasDeptBadge = str_contains($resp['body'], 'IT Department');
$hasOverview = str_contains($resp['body'], 'Department Overview') || str_contains($resp['body'], 'Total Activities');
recordResult('MT-010', 'PH Dashboard', 'IT Program Head Dashboard identifies IT Department', "Displays 'IT Department' badge and overview", "Status {$resp['code']} - Badge found: " . ($hasDeptBadge ? 'Yes' : 'No'), $hasDeptBadge && $hasOverview);

// MT-011: Program Head THM Login & Dashboard
$cFilePhThm = $cookieDir . '/ph_thm.txt';
@unlink($cFilePhThm);
makeHttpRequest($baseUrl . '/auth/login.php', 'POST', [
    'email' => $accounts['ph_thm']['email'],
    'password' => $accounts['ph_thm']['password']
], $cFilePhThm);
$resp = makeHttpRequest($baseUrl . '/program_head/dashboard.php', 'GET', null, $cFilePhThm);
$hasThmBadge = str_contains($resp['body'], 'THM Department');
recordResult('MT-011', 'PH Dashboard', 'THM Program Head Dashboard identifies THM Department', "Displays 'THM Department' badge", "Status {$resp['code']} - Badge found: " . ($hasThmBadge ? 'Yes' : 'No'), $hasThmBadge);

// MT-012: Program Head GE Login & Dashboard
$cFilePhGe = $cookieDir . '/ph_ge.txt';
@unlink($cFilePhGe);
makeHttpRequest($baseUrl . '/auth/login.php', 'POST', [
    'email' => $accounts['ph_ge']['email'],
    'password' => $accounts['ph_ge']['password']
], $cFilePhGe);
$resp = makeHttpRequest($baseUrl . '/program_head/dashboard.php', 'GET', null, $cFilePhGe);
$hasGeBadge = str_contains($resp['body'], 'G.E. Department') || str_contains($resp['body'], 'GE Department');
recordResult('MT-012', 'PH Dashboard', 'GE Program Head Dashboard identifies GE Department', "Displays 'G.E. Department' badge", "Status {$resp['code']} - Badge found: " . ($hasGeBadge ? 'Yes' : 'No'), $hasGeBadge);

// MT-013: Program Head IT Creates Proposal Draft via Proposal Save API
$propTitle = "IT Cybersecurity Boot Camp " . time();
$resp = makeHttpRequest($baseUrl . '/api/proposal-save.php', 'POST', [
    'action'              => 'draft',
    'title'               => $propTitle,
    'description'         => 'An immersive technical cybersecurity workshop for BSIT students.',
    'event_date'          => '2026-11-25',
    'start_time'          => '09:00',
    'end_time'            => '16:00',
    'venue'               => 'Computer Laboratory 3',
    'target_participants' => 60,
    'objectives'          => 'Gain hands-on ethical hacking skills; Understand defensive cybersecurity principles.'
], $cFilePhIt, ['Accept: application/json']);

// Check database for newly created draft
$stmt = $db->prepare("SELECT id, status, faculty_id FROM activities WHERE title = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$propTitle]);
$createdAct = $stmt->fetch();
$actId = $createdAct ? (int)$createdAct['id'] : 0;
$isDraftSaved = ($actId > 0 && $createdAct['status'] === 'draft' && (int)$createdAct['faculty_id'] === $accounts['ph_it']['id']);
recordResult('MT-013', 'Proposals', 'Program Head saves proposal draft', "Activity created with status 'draft' for PH", "Status: " . ($createdAct['status'] ?? 'None') . ", ID: $actId", $isDraftSaved);

// MT-014: Program Head Edits Draft Proposal
$updatedTitle = $propTitle . " [Updated]";
$resp = makeHttpRequest($baseUrl . '/api/proposal-update.php', 'POST', [
    'activity_id'         => $actId,
    'action'              => 'draft',
    'title'               => $updatedTitle,
    'description'         => 'Updated description for cybersecurity workshop.',
    'event_date'          => '2026-11-26',
    'start_time'          => '09:00',
    'end_time'            => '17:00',
    'venue'               => 'Computer Laboratory 3 & 4',
    'target_participants' => 75
], $cFilePhIt, ['Accept: application/json']);

$stmt = $db->prepare("SELECT title, target_participants FROM activities WHERE id = ?");
$stmt->execute([$actId]);
$updatedRow = $stmt->fetch();
$isDraftUpdated = ($updatedRow && $updatedRow['title'] === $updatedTitle && (int)$updatedRow['target_participants'] === 75);
recordResult('MT-014', 'Proposals', 'Program Head edits existing draft', "Title and participants updated in database", "Title: " . ($updatedRow['title'] ?? 'None'), $isDraftUpdated);

// MT-015: Program Head Submits Proposal into Review Workflow
$submitPayload = [
    'activity_id'         => $actId,
    'title'               => $updatedTitle,
    'theme'               => 'Advancing Secure Computing and Resilient Systems',
    'venue'               => 'Computer Laboratory 3 & 4',
    'venue_address'       => 'STI Marikina Campus',
    'target_participants' => 75,
    'event_date'          => '2026-11-26',
    'start_time'          => '09:00',
    'end_time'            => '17:00',
    'general_objectives'  => 'Equip students with practical modern cybersecurity defense techniques, ethical testing strategies, and industry standard server hardening methodologies.',
    'specific_objectives' => 'Understand web penetration testing methodologies, configure firewall policies, deploy network intrusion detection systems, and analyze security audit logs in simulated environments.',
    'involved_subjects'   => 'Information Assurance and Security, Computer Networks',
    'rationale'           => 'There is an urgent and critical high demand for qualified cybersecurity specialists in Philippine IT and enterprise cloud computing industries.',
    'description'         => 'An immersive and highly comprehensive technical cybersecurity workshop designed for senior BSIT students to gain practical offensive security and defensive administration skills.',
    'kpi_indicator'       => ['Participant Technical Assessment Score'],
    'kpi_target'          => ['85% or higher pass rate on hands-on security laboratory exercise'],
    'kpi_method'          => ['Post-workshop hands-on evaluation rubric'],
    'eval_form_link'      => 'https://forms.google.com/eval-cybersecurity-workshop',
    'floor_plan_required' => '0'
];

// Run AI validation gating check first as required by wizard workflow
$respAi = makeHttpRequest($baseUrl . '/api/validate-proposal-ai.php', 'POST', $submitPayload, $cFilePhIt, ['Accept: application/json']);

// Now submit proposal
$submitPayload['action'] = 'submit';
$resp = makeHttpRequest($baseUrl . '/api/proposal-update.php', 'POST', $submitPayload, $cFilePhIt, ['Accept: application/json']);

$stmt = $db->prepare("SELECT status FROM activities WHERE id = ?");
$stmt->execute([$actId]);
$submittedStatus = $stmt->fetchColumn();
$isSubmitted = ($submittedStatus === 'submitted');
recordResult('MT-015', 'Proposals', 'Program Head submits proposal into workflow', "Activity status updated to 'submitted'", "Current status: $submittedStatus", $isSubmitted);

// MT-016: Approval Workflow Stage 1 — Admin 2 (Sir Ian) Endorsement for Faculty/PH proposal
$resp = makeHttpRequest($baseUrl . '/api/review-action.php', 'POST', [
    'activity_id' => $actId,
    'action'      => 'approved',
    'notes'       => 'Events committee review complete. Forwarded to Dean for final approval.'
], $cFileAdmin2, ['Accept: application/json']);

$stmt = $db->prepare("SELECT status FROM activities WHERE id = ?");
$stmt->execute([$actId]);
$admin2Status = $stmt->fetchColumn();
$isAdmin2Endorsed = ($admin2Status === 'pending_final_approval');
recordResult('MT-016', 'Workflow', 'Admin 2 endorses and forwards to Dean', "Status advances to 'pending_final_approval'", "Current status: $admin2Status", $isAdmin2Endorsed);

// MT-017: Approval Workflow Stage 2 — Dean Final Approval
$resp = makeHttpRequest($baseUrl . '/api/review-action.php', 'POST', [
    'activity_id' => $actId,
    'action'      => 'approved',
    'notes'       => 'Approved by Dean Dr. Frederic Yulo.'
], $cFileDean, ['Accept: application/json']);

$stmt = $db->prepare("SELECT status FROM activities WHERE id = ?");
$stmt->execute([$actId]);
$deanApprovedStatus = $stmt->fetchColumn();
$isApproved = ($deanApprovedStatus === 'approved');
recordResult('MT-017', 'Workflow', 'Dean gives final approval to Program Head proposal', "Status advances to 'approved'", "Current status: $deanApprovedStatus", $isApproved);

// MT-018: Program Head Views Approved Activities
$resp = makeHttpRequest($baseUrl . '/faculty/approved.php', 'GET', null, $cFilePhIt);
$hasApprovedAct = str_contains($resp['body'], htmlspecialchars($updatedTitle));
recordResult('MT-018', 'PH Activities', 'Approved activity appears in Program Head Approved list', "Page lists approved proposal", "HTTP {$resp['code']} - Found in body: " . ($hasApprovedAct ? 'Yes' : 'No'), $hasApprovedAct);

// MT-019: Program Head Views Activity Details
$resp = makeHttpRequest($baseUrl . '/faculty/view-activity.php?id=' . $actId, 'GET', null, $cFilePhIt);
$hasActivityDetail = ($resp['code'] === 200 && str_contains($resp['body'], 'Computer Laboratory') && str_contains($resp['body'], htmlspecialchars($updatedTitle)));
recordResult('MT-019', 'PH Activities', 'Program Head opens full activity details view', "Displays complete activity details", "HTTP {$resp['code']} - Details rendered: " . ($hasActivityDetail ? 'Yes' : 'No'), $hasActivityDetail);

// MT-020: Program Head Task Assignment in Approved Activity
$resp = makeHttpRequest($baseUrl . '/api/task-assignment.php', 'POST', [
    'action'            => 'create',
    'activity_id'       => $actId,
    'assigned_user_id'  => $accounts['fac_it']['id'],
    'task_title'        => 'Network Security Setup',
    'task_description'  => 'Configure firewall and guest network for the workshop',
    'committee'         => 'Technical Committee',
    'due_date'          => '2026-11-24',
    'status'            => 'Not Started'
], $cFilePhIt, ['Accept: application/json']);

$stmt = $db->prepare("SELECT id, task_title FROM faculty_tasks WHERE activity_id = ? AND assigned_user_id = ?");
$stmt->execute([$actId, $accounts['fac_it']['id']]);
$createdTask = $stmt->fetch();
$taskId = $createdTask ? (int)$createdTask['id'] : 0;
$isTaskAssigned = ($taskId > 0);
recordResult('MT-020', 'PH Tasks', 'Program Head assigns task to department faculty', "Task created and linked to activity", "Task ID: $taskId", $isTaskAssigned);

// MT-021: Department Task Monitoring in Program Head Tasks Page
$resp = makeHttpRequest($baseUrl . '/faculty/tasks.php', 'GET', null, $cFilePhIt);
$hasTaskInList = str_contains($resp['body'], 'Network Security Setup');
recordResult('MT-021', 'PH Tasks', 'Assigned task appears in PH Department Task Monitoring', "Displays 'Network Security Setup'", "HTTP {$resp['code']} - Listed: " . ($hasTaskInList ? 'Yes' : 'No'), $hasTaskInList);

// MT-022: Faculty Task Status Update & Deliverable Submission
$resp = makeHttpRequest($baseUrl . '/api/task-assignment.php', 'POST', [
    'action'      => 'update_status',
    'activity_id' => $actId,
    'task_id'     => $taskId,
    'status'      => 'In Progress'
], $cFileFacIt, ['Accept: application/json']);

$stmt = $db->prepare("SELECT status FROM faculty_tasks WHERE id = ?");
$stmt->execute([$taskId]);
$newStatus = $stmt->fetchColumn();
$isStatusUpdated = ($newStatus === 'In Progress');
recordResult('MT-022', 'Faculty Tasks', 'Faculty updates assigned task status to In Progress', "Status updated to 'In Progress'", "Current status: $newStatus", $isStatusUpdated);

// MT-023: Post-Event Save by Program Head
$resp = makeHttpRequest($baseUrl . '/api/post-event-save.php', 'POST', [
    'activity_id'        => $actId,
    'target_attendance'  => 70,
    'actual_attendance'  => 68,
    'summary_notes'      => 'Cybersecurity workshop completed with excellent attendance and student participation.'
], $cFilePhIt);

$stmt = $db->prepare("SELECT activity_id, target_attendance FROM post_event WHERE activity_id = ?");
$stmt->execute([$actId]);
$peRow = $stmt->fetch();
$isPostEventSaved = ($peRow && (int)$peRow['target_attendance'] === 70);
recordResult('MT-023', 'Post-Event', 'Program Head uploads post-event documentation', "Post-event record created with target attendance 70", "Saved target attendance: " . ($peRow['target_attendance'] ?? 'None'), $isPostEventSaved);

// MT-024: KPI & Evaluation Reports for Program Head
$resp = makeHttpRequest($baseUrl . '/faculty/kpi.php', 'GET', null, $cFilePhIt);
$hasKpiPage = ($resp['code'] === 200 && (str_contains($resp['body'], 'KPI Results') || str_contains($resp['body'], 'KPI') || str_contains($resp['body'], htmlspecialchars($updatedTitle))));
recordResult('MT-024', 'KPI Reports', 'Program Head views KPI & Evaluation Reports', "HTTP 200 KPI page renders", "HTTP Code: {$resp['code']}", $hasKpiPage);

// MT-025: Program Head Calendar view
$resp = makeHttpRequest($baseUrl . '/faculty/calendar.php', 'GET', null, $cFilePhIt);
$hasCalendar = ($resp['code'] === 200 && str_contains($resp['body'], 'calendar'));
recordResult('MT-025', 'Calendar', 'Program Head Calendar page loads', "HTTP 200 calendar renders", "HTTP Code: {$resp['code']}", $hasCalendar);

// MT-026: AI Assistance: Objectives Generation
$resp = makeHttpRequest($baseUrl . '/api/generate-objectives.php', 'POST', [
    'title'       => 'Python for Data Science',
    'description' => 'Hands-on Python workshop for beginners'
], $cFilePhIt, ['Accept: application/json']);
$aiObjSuccess = ($resp['code'] === 200 && str_contains($resp['body'], 'objectives'));
recordResult('MT-026', 'AI Assistance', 'Program Head calls AI Objectives Generation API', "HTTP 200 with generated objectives", "HTTP Code: {$resp['code']}", $aiObjSuccess);

// MT-027: AI Assistance: Theme Suggestions
$resp = makeHttpRequest($baseUrl . '/api/generate-theme.php', 'POST', [
    'title'       => 'Web Development Hackathon'
], $cFilePhIt, ['Accept: application/json']);
$aiThemeSuccess = ($resp['code'] === 200 && (str_contains($resp['body'], 'theme') || str_contains($resp['body'], 'suggestions')));
recordResult('MT-027', 'AI Assistance', 'Program Head calls AI Theme Suggestion API', "HTTP 200 with theme suggestions", "HTTP Code: {$resp['code']}", $aiThemeSuccess);

// MT-028: Cross-Department Isolation: THM Program Head CANNOT View or Modify IT Activity Details
$resp = makeHttpRequest($baseUrl . '/faculty/view-activity.php?id=' . $actId, 'GET', null, $cFilePhThm);
$isThmDeniedView = ($resp['code'] === 403 || str_contains($resp['body'], 'Unauthorized') || str_contains($resp['body'], 'Access denied'));
recordResult('MT-028', 'Security', 'THM Program Head blocked from viewing IT-exclusive activity', "HTTP 403 Access Denied", "HTTP Code: {$resp['code']}", $isThmDeniedView);

// MT-029: Cross-Department Isolation: GE Program Head CANNOT View or Modify IT Activity Details
$resp = makeHttpRequest($baseUrl . '/faculty/view-activity.php?id=' . $actId, 'GET', null, $cFilePhGe);
$isGeDeniedView = ($resp['code'] === 403 || str_contains($resp['body'], 'Unauthorized') || str_contains($resp['body'], 'Access denied'));
recordResult('MT-029', 'Security', 'GE Program Head blocked from viewing IT-exclusive activity', "HTTP 403 Access Denied", "HTTP Code: {$resp['code']}", $isGeDeniedView);

// MT-030: Shared Activity Collaboration: Assign task to THM faculty member in IT Activity
$resp = makeHttpRequest($baseUrl . '/api/task-assignment.php', 'POST', [
    'action'            => 'create',
    'activity_id'       => $actId,
    'assigned_user_id'  => $accounts['fac_thm']['id'],
    'task_title'        => 'Hospitality Catering Coordination',
    'task_description'  => 'Coordinate refreshment catering for workshop participants',
    'committee'         => 'Logistics Committee',
    'due_date'          => '2026-11-25',
    'status'            => 'Not Started'
], $cFilePhIt, ['Accept: application/json']);

$stmt = $db->prepare("SELECT id FROM faculty_tasks WHERE activity_id = ? AND assigned_user_id = ?");
$stmt->execute([$actId, $accounts['fac_thm']['id']]);
$sharedTaskId = (int)$stmt->fetchColumn();

// Now THM Program Head SHOULD have read-only view access to shared activity
$resp = makeHttpRequest($baseUrl . '/faculty/view-activity.php?id=' . $actId, 'GET', null, $cFilePhThm);
$isThmAllowedSharedView = ($resp['code'] === 200 && str_contains($resp['body'], 'Computer Laboratory'));
recordResult('MT-030', 'Collaboration', 'THM Program Head can view shared activity where THM faculty assigned', "HTTP 200 read-only access granted", "HTTP Code: {$resp['code']}", $isThmAllowedSharedView);

// THM Program Head CANNOT modify shared activity created by IT
$resp = makeHttpRequest($baseUrl . '/api/proposal-update.php', 'POST', [
    'activity_id' => $actId,
    'action'      => 'draft',
    'title'       => 'THM Hijacked Title'
], $cFilePhThm, ['Accept: application/json']);
$isThmDeniedModify = ($resp['code'] === 403 || str_contains($resp['body'], 'Access denied'));
recordResult('MT-031', 'Security', 'THM Program Head CANNOT modify shared activity created by IT', "HTTP 403 Access Denied", "HTTP Code: {$resp['code']}", $isThmDeniedModify);


// ── STEP 4: FACULTY TESTING ──────────────────────────────────────────
echo "\n--- STEP 4: FACULTY TESTING ---\n";

// MT-032: Faculty creates own proposal
$facPropTitle = "Faculty IT Web Design Workshop " . time();
$resp = makeHttpRequest($baseUrl . '/api/proposal-save.php', 'POST', [
    'action'              => 'draft',
    'title'               => $facPropTitle,
    'description'         => 'Web design workshop by faculty.',
    'event_date'          => '2026-11-28',
    'start_time'          => '10:00',
    'end_time'            => '15:00',
    'venue'               => 'Laboratory 2',
    'target_participants' => 45
], $cFileFacIt, ['Accept: application/json']);

$stmt = $db->prepare("SELECT id FROM activities WHERE title = ?");
$stmt->execute([$facPropTitle]);
$facActId = (int)$stmt->fetchColumn();
$isFacPropCreated = ($facActId > 0);
recordResult('MT-032', 'Faculty', 'Faculty creates and saves own proposal draft', "Draft created in database", "Activity ID: $facActId", $isFacPropCreated);

// MT-033: Faculty CANNOT modify Program Head's activity
$resp = makeHttpRequest($baseUrl . '/api/proposal-update.php', 'POST', [
    'activity_id' => $actId,
    'action'      => 'draft',
    'title'       => 'Faculty Altered Title'
], $cFileFacIt, ['Accept: application/json']);
$isFacDeniedModifyOther = ($resp['code'] === 403 || str_contains($resp['body'], 'Access denied'));
recordResult('MT-033', 'Security', 'Faculty CANNOT modify activities created by others', "HTTP 403 Access Denied", "HTTP Code: {$resp['code']}", $isFacDeniedModifyOther);


// ── STEP 5: DEAN & ADMIN COMPATIBILITY ───────────────────────────────
echo "\n--- STEP 5: DEAN & ADMIN COMPATIBILITY ---\n";

// MT-034: Dean Institutional Activities View
$resp = makeHttpRequest($baseUrl . '/dean/activities.php', 'GET', null, $cFileDean);
$deanSeesActs = ($resp['code'] === 200 && (str_contains($resp['body'], htmlspecialchars($updatedTitle)) || str_contains($resp['body'], 'Activities')));
recordResult('MT-034', 'Dean', 'Dean views institutional activities list', "Contains Program Head activity or activities list", "HTTP {$resp['code']} - Rendered: " . ($deanSeesActs ? 'Yes' : 'No'), $deanSeesActs);

// MT-035: Dean User Management View
$resp = makeHttpRequest($baseUrl . '/dean/users.php', 'GET', null, $cFileDean);
$deanSeesUsers = ($resp['code'] === 200 && str_contains($resp['body'], 'User Management'));
recordResult('MT-035', 'Dean', 'Dean accesses User Management page', "HTTP 200 with User Management", "HTTP Code: {$resp['code']}", $deanSeesUsers);

// MT-036: Admin 1 All Activities Overview
$resp = makeHttpRequest($baseUrl . '/admin1/activities.php', 'GET', null, $cFileAdmin1);
$admin1SeesActs = ($resp['code'] === 200 && str_contains($resp['body'], 'Activities'));
recordResult('MT-036', 'Admin 1', 'Admin 1 views activities list', "HTTP 200 activities rendered", "HTTP Code: {$resp['code']}", $admin1SeesActs);

// MT-037: Admin 2 User Management View
$resp = makeHttpRequest($baseUrl . '/admin2/users.php', 'GET', null, $cFileAdmin2);
$admin2SeesUsers = ($resp['code'] === 200 && str_contains($resp['body'], 'User Management'));
recordResult('MT-037', 'Admin 2', 'Admin 2 accesses User Management page', "HTTP 200 with User Management", "HTTP Code: {$resp['code']}", $admin2SeesUsers);


// ── STEP 6: CLEANUP TEST ACTIVITIES & USERS ──────────────────────────
echo "\n--- CLEANING UP TEMPORARY ACCEPTANCE TEST DATA ---\n";
if ($actId > 0) {
    $db->prepare("DELETE FROM documents WHERE activity_id = ?")->execute([$actId]);
    $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id = ?")->execute([$actId]);
    $db->prepare("DELETE FROM notifications WHERE activity_id = ?")->execute([$actId]);
    $db->prepare("DELETE FROM post_event WHERE activity_id = ?")->execute([$actId]);
    $db->prepare("DELETE FROM faculty_tasks WHERE activity_id = ?")->execute([$actId]);
    $db->prepare("DELETE FROM approval_logs WHERE activity_id = ?")->execute([$actId]);
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$actId]);
    echo "Deleted test activity ID $actId\n";
}
if ($facActId > 0) {
    $db->prepare("DELETE FROM documents WHERE activity_id = ?")->execute([$facActId]);
    $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id = ?")->execute([$facActId]);
    $db->prepare("DELETE FROM notifications WHERE activity_id = ?")->execute([$facActId]);
    $db->prepare("DELETE FROM post_event WHERE activity_id = ?")->execute([$facActId]);
    $db->prepare("DELETE FROM faculty_tasks WHERE activity_id = ?")->execute([$facActId]);
    $db->prepare("DELETE FROM approval_logs WHERE activity_id = ?")->execute([$facActId]);
    $db->prepare("DELETE FROM activities WHERE id = ?")->execute([$facActId]);
    echo "Deleted faculty test activity ID $facActId\n";
}

if (!empty($cleanupUserIds)) {
    $in = implode(',', $cleanupUserIds);
    $db->exec("DELETE FROM notifications WHERE user_id IN ($in)");
    $db->exec("DELETE FROM users WHERE id IN ($in)");
    echo "Deleted temporary test users: $in\n";
}

// Clean up cookie files
foreach (glob($cookieDir . '/*.txt') as $cf) {
    @unlink($cf);
}
@rmdir($cookieDir);

// ── SUMMARY REPORT ───────────────────────────────────────────────────
echo "\n=======================================================================\n";
echo "MANUAL TESTING & FUNCTIONAL ACCEPTANCE EXECUTION SUMMARY\n";
echo "=======================================================================\n";

$passTotal = 0;
$failTotal = 0;
foreach ($testResults as $tr) {
    if ($tr['status'] === 'Pass') $passTotal++; else $failTotal++;
}
echo "Total Scenarios Tested: " . count($testResults) . "\n";
echo "Passed: $passTotal | Failed: $failTotal\n";
echo "=======================================================================\n";

// Save results to JSON artifact
$outJson = __DIR__ . '/manual_acceptance_results.json';
file_put_contents($outJson, json_encode($testResults, JSON_PRETTY_PRINT));
echo "Detailed test results saved to: $outJson\n";

if ($failTotal > 0) {
    exit(1);
}
exit(0);
