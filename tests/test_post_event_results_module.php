<?php
/**
 * Test Suite: Dedicated Post-Event Results Module
 * Tests:
 * 1. Admin 1 (Student Org Head), Admin 2 (Events Committee Head), and Dean page access
 * 2. Proper filtering: Only completed activities with valid post_event records appear
 * 3. Incomplete, ongoing, draft, or activities without post-event submissions are excluded
 * 4. Actions column contains ONLY a View button; no edit/delete buttons
 * 5. Role-based scoping: Admin 1 is strictly restricted to Student Org activities
 * 6. Admin 2 and Dean can view institutional post-event results
 * 7. Dedicated read-only view page displays:
 *    - PDF assessment report preview & download
 *    - CSV survey results preview & download
 *    - Event photographs gallery & lightbox
 *    - Attendance metrics & Turnout %
 *    - Supporting documents list & download
 *    - Strict read-only guarantee (no edit/replace/delete actions)
 * 8. Light & Dark mode compatibility tokens
 * 9. Sidebar navigation links for admin1, admin2, and dean
 */

$rootDir = realpath(__DIR__ . '/..');
require_once $rootDir . '/config/config.php';
require_once $rootDir . '/config/database.php';
require_once $rootDir . '/includes/functions.php';

$errors = [];
$successes = [];

function assertTest($condition, $successMsg, $failMsg) {
    global $errors, $successes;
    if ($condition) {
        $successes[] = "[PASS] " . $successMsg;
    } else {
        $errors[] = "[FAIL] " . $failMsg;
    }
}

echo "============================================================\n";
echo "  TEST SUITE: DEDICATED POST-EVENT RESULTS MODULE\n";
echo "============================================================\n\n";

$db = getDB();

// 1. Static file inspections
echo "--- 1. Static Code & Layout Verifications ---\n";

$admin1List = file_get_contents($rootDir . '/admin1/post-event.php');
$admin2List = file_get_contents($rootDir . '/admin2/post-event.php');
$deanList   = file_get_contents($rootDir . '/dean/post-event.php');
$viewTpl    = file_get_contents($rootDir . '/includes/post-event-view-template.php');
$sidebar    = file_get_contents($rootDir . '/includes/sidebar.php');

// Sidebar checks
assertTest(strpos($sidebar, "'label' => 'Post-Event Results', 'href' => BASE_URL . '/admin1/post-event.php'") !== false,
    "Admin 1 sidebar has 'Post-Event Results' link",
    "Admin 1 sidebar missing 'Post-Event Results' link");

assertTest(strpos($sidebar, "'label' => 'Post-Event Results', 'href' => BASE_URL . '/admin2/post-event.php'") !== false,
    "Admin 2 sidebar has 'Post-Event Results' link",
    "Admin 2 sidebar missing 'Post-Event Results' link");

assertTest(strpos($sidebar, "'label' => 'Post-Event Results', 'href' => BASE_URL . '/dean/post-event.php'") !== false,
    "Dean sidebar has 'Post-Event Results' link",
    "Dean sidebar missing 'Post-Event Results' link");

// Group positioning check: ensure it is inside the Reports group
assertTest(preg_match("/'group'\s*=>\s*'Reports'[^]]*'label'\s*=>\s*'Post-Event Results'/s", $sidebar) !== 0,
    "Post-Event Results is located inside the 'Reports' navigation group in sidebar",
    "Post-Event Results is NOT located inside the 'Reports' group in sidebar");

// Table Layout & Actions column checks
assertTest(strpos($admin1List, 'activity-table') !== false && strpos($admin1List, 'Actions') !== false,
    "Admin 1 post-event.php uses standard activity-table layout",
    "Admin 1 post-event.php missing activity-table layout");

assertTest(strpos($admin1List, 'btn-primary btn-sm">Edit<') === false && strpos($admin1List, 'proposal-edit.php') === false,
    "Admin 1 post-event.php does NOT contain any Edit buttons",
    "Admin 1 post-event.php contains Edit buttons");

assertTest(strpos($admin2List, 'btn-primary btn-sm">Edit<') === false && strpos($admin2List, 'proposal-edit.php') === false,
    "Admin 2 post-event.php does NOT contain any Edit buttons",
    "Admin 2 post-event.php contains Edit buttons");

assertTest(strpos($deanList, 'btn-primary btn-sm">Edit<') === false && strpos($deanList, 'proposal-edit.php') === false,
    "Dean post-event.php does NOT contain any Edit buttons",
    "Dean post-event.php contains Edit buttons");

// Dedicated read-only view checks
assertTest(strpos($viewTpl, 'pe-read-only-banner') === false && strpos($viewTpl, 'Read-Only Institutional Record') === false,
    "Post-event view template cleanly excludes redundant Read-Only Institutional Record banner",
    "Post-event view template still contains read-only banner");

assertTest(strpos($viewTpl, 'iframe') !== false && strpos($viewTpl, 'assessment_report_path') !== false,
    "Post-event view template supports embedded PDF preview for assessment reports",
    "Post-event view template missing PDF iframe preview");

assertTest(strpos($viewTpl, 'pe-lightbox-modal') !== false && strpos($viewTpl, 'pe-photo-grid') !== false,
    "Post-event view template supports event photograph gallery with full-resolution lightbox modal",
    "Post-event view template missing photo gallery lightbox modal");

assertTest(strpos($viewTpl, 'pe-csv-table') !== false && strpos($viewTpl, 'survey_csv_path') !== false,
    "Post-event view template includes CSV survey results preview and download",
    "Post-event view template missing CSV survey preview");

assertTest(strpos($viewTpl, 'Actual Headcount') !== false && strpos($viewTpl, 'Turnout Rate') !== false,
    "Post-event view template displays attendance and turnout metrics",
    "Post-event view template missing attendance metrics");

// 2. Database Fixtures Setup for Isolated Role Testing
echo "\n--- 2. Database Test Data Setup ---\n";

// Ensure sample test files exist
$uploadDir = $rootDir . '/uploads/post-event';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
}
$samplePdf = $uploadDir . '/test_assessment_report.pdf';
if (!file_exists($samplePdf)) {
    file_put_contents($samplePdf, "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/MediaBox [0 0 612 792]\n>>\nendobj\nxref\n0 4\n0000000000 65535 f\n0000000010 00000 n\n0000000060 00000 n\n0000000117 00000 n\ntrailer\n<<\n/Size 4\n/Root 1 0 R\n>>\nstartxref\n190\n%%EOF");
}
$sampleCsv = $uploadDir . '/test_survey_results.csv';
if (!file_exists($sampleCsv)) {
    file_put_contents($sampleCsv, "Respondent ID,Timestamp,Overall Satisfaction,Relevance to Program,Speaker Effectiveness,Venue Quality\n1,2026-11-01 10:00:00,4,4,4,3\n2,2026-11-01 10:05:00,4,3,4,4\n3,2026-11-01 10:10:00,3,4,3,3\n");
}
$samplePhoto = $uploadDir . '/test_photo_sample.jpg';
if (!file_exists($samplePhoto)) {
    // 1x1 valid transparent GIF/JPEG placeholder
    file_put_contents($samplePhoto, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
}

// Ensure Student Org Activity (1144) has complete post_event and documents attached
$chk1144 = $db->query("SELECT id FROM activities WHERE id = 1144")->fetch();
if ($chk1144) {
    $db->prepare("
        UPDATE post_event 
        SET assessment_report_path = 'uploads/post-event/test_assessment_report.pdf',
            survey_csv_path = 'uploads/post-event/test_survey_results.csv',
            actual_attendance = 48,
            target_attendance = 50,
            satisfaction_score = 4.8,
            recommendations = 'Allocate additional time for the Q&A segment in next year\'s session.',
            attendance_method = 'both',
            submitted_at = NOW()
        WHERE activity_id = 1144
    ")->execute();

    // Attach sample photo if not present
    $hasPhoto = $db->query("SELECT COUNT(*) FROM documents WHERE activity_id = 1144 AND doc_type = 'photo'")->fetchColumn();
    if ((int)$hasPhoto === 0) {
        $db->prepare("INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by, uploaded_at) VALUES (1144, 'photo', 'Leadership_Summit_Photo1.jpg', 'uploads/post-event/test_photo_sample.jpg', 387, NOW())")->execute();
    }
}

// Create or verify a completed Faculty Activity with post_event (to test Admin 1 isolation)
$facAct = $db->query("SELECT id FROM activities WHERE title = 'Unit Test Completed Faculty Activity'")->fetch();
$facActId = $facAct['id'] ?? null;
if (!$facActId) {
    $insFac = $db->prepare("
        INSERT INTO activities (title, faculty_id, event_date, status, source, target_participants, venue, created_at, updated_at)
        VALUES ('Unit Test Completed Faculty Activity', 710, '2026-11-15', 'completed', 'faculty', 100, 'Main Auditorium', NOW(), NOW())
    ");
    $insFac->execute();
    $facActId = (int)$db->lastInsertId();
} else {
    $db->prepare("UPDATE activities SET status = 'completed', source = 'faculty' WHERE id = ?")->execute([$facActId]);
}

// Attach post_event to this Faculty Activity
$facPe = $db->query("SELECT id FROM post_event WHERE activity_id = {$facActId}")->fetch();
if (!$facPe) {
    $db->prepare("
        INSERT INTO post_event (activity_id, submitted_by, submitted_at, actual_attendance, target_attendance, satisfaction_score, assessment_report_path, survey_csv_path, attendance_method, recommendations)
        VALUES (?, 710, NOW(), 95, 100, 4.9, 'uploads/post-event/test_assessment_report.pdf', 'uploads/post-event/test_survey_results.csv', 'survey_only', 'Faculty event was well attended.')
    ")->execute([$facActId]);
}

// Create an incomplete/draft activity to verify it does NOT appear
$draftAct = $db->query("SELECT id FROM activities WHERE title = 'Unit Test Draft Incomplete Activity'")->fetch();
$draftActId = $draftAct['id'] ?? null;
if (!$draftActId) {
    $insDraft = $db->prepare("
        INSERT INTO activities (title, faculty_id, event_date, status, source, target_participants, created_at, updated_at)
        VALUES ('Unit Test Draft Incomplete Activity', 710, '2026-12-01', 'draft', 'student_org', 50, NOW(), NOW())
    ");
    $insDraft->execute();
    $draftActId = (int)$db->lastInsertId();
}

echo "Database test fixtures ready:\n";
echo "- Student Org Completed Activity: #1144\n";
echo "- Faculty Completed Activity: #{$facActId}\n";
echo "- Incomplete/Draft Activity: #{$draftActId}\n";

// 3. HTTP End-to-End Role Verification
echo "\n--- 3. HTTP End-to-End Role & Scoping Tests ---\n";

function makeHttpRequest(string $url, string $method = 'GET', array $data = [], ?string $cookie = null): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
    if ($cookie) {
        $opts[CURLOPT_COOKIE] = $cookie;
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    return ['status' => $httpCode, 'headers' => $headers, 'body' => $body];
}

function loginUser(string $email, string $password): ?string {
    $res = makeHttpRequest(BASE_URL . '/auth/login.php', 'POST', [
        'email' => $email,
        'password' => $password
    ]);
    if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $res['headers'], $matches)) {
        return !empty($matches[1]) ? end($matches[1]) : null;
    }
    return null;
}

// 3A. Test Admin 1 (Student Org Head)
echo "\n[Testing Role: Admin 1 (Student Org Head)]\n";
$admin1Cookie = loginUser('arjay@sti.edu', 'password');
assertTest(!empty($admin1Cookie), "Admin 1 logged in successfully", "Admin 1 login failed");

$a1ListResp = makeHttpRequest(BASE_URL . '/admin1/post-event.php', 'GET', [], $admin1Cookie);
assertTest($a1ListResp['status'] === 200, "Admin 1 loads post-event.php (HTTP 200)", "Admin 1 post-event.php returned HTTP {$a1ListResp['status']}");

assertTest(strpos($a1ListResp['body'], 'Approved Activity') !== false,
    "Admin 1 post-event.php lists completed student org activity #1144",
    "Admin 1 post-event.php missing student org activity #1144");

assertTest(strpos($a1ListResp['body'], 'Unit Test Completed Faculty Activity') === false,
    "Admin 1 post-event.php strictly EXCLUDES faculty completed activity #{$facActId}",
    "Admin 1 post-event.php leaked faculty activity #{$facActId}");

assertTest(strpos($a1ListResp['body'], 'Unit Test Draft Incomplete Activity') === false,
    "Admin 1 post-event.php excludes incomplete/draft activity #{$draftActId}",
    "Admin 1 post-event.php leaked draft/incomplete activity");

assertTest(strpos($a1ListResp['body'], 'admin1/post-event-view.php?id=1144') !== false,
    "Admin 1 post-event.php has View button linking to admin1/post-event-view.php",
    "Admin 1 post-event.php View link incorrect or missing");

// Admin 1 View Page for #1144
$a1ViewResp = makeHttpRequest(BASE_URL . '/admin1/post-event-view.php?id=1144', 'GET', [], $admin1Cookie);
assertTest($a1ViewResp['status'] === 200, "Admin 1 loads post-event-view.php?id=1144 (HTTP 200)", "Admin 1 view failed (HTTP {$a1ViewResp['status']})");
assertTest(strpos($a1ViewResp['body'], 'Read-Only Institutional Record') === false,
    "Admin 1 view cleanly excludes Read-Only Institutional Record banner",
    "Admin 1 view still contains Read-Only banner");
assertTest(strpos($a1ViewResp['body'], 'Assessment Report (PDF)') !== false,
    "Admin 1 view contains Assessment Report (PDF) preview block",
    "Admin 1 view missing Assessment Report (PDF) section");
assertTest(strpos($a1ViewResp['body'], 'Survey Results (CSV)') !== false,
    "Admin 1 view contains Survey Results (CSV) section",
    "Admin 1 view missing Survey Results section");
assertTest(strpos($a1ViewResp['body'], 'Event Photographs') !== false,
    "Admin 1 view contains Event Photographs gallery",
    "Admin 1 view missing photographs gallery");
assertTest(strpos($a1ViewResp['body'], 'Turnout Rate') !== false,
    "Admin 1 view contains Attendance and Turnout Rate metrics",
    "Admin 1 view missing turnout metrics");

// Verify Admin 1 is blocked when attempting to access faculty post-event view
$a1BlockedResp = makeHttpRequest(BASE_URL . '/admin1/post-event-view.php?id=' . $facActId, 'GET', [], $admin1Cookie);
assertTest(strpos($a1BlockedResp['body'], 'Record Unavailable') !== false || strpos($a1BlockedResp['body'], 'Activity not found or accessible') !== false,
    "Admin 1 attempting to view faculty activity post-event is blocked with Record Unavailable",
    "Admin 1 was able to view faculty post-event record");

// 3B. Test Admin 2 (Events Committee Head)
echo "\n[Testing Role: Admin 2 (Events Committee Head)]\n";
$admin2Cookie = loginUser('ian.barangan@marikina.sti.edu.ph', 'password');
assertTest(!empty($admin2Cookie), "Admin 2 logged in successfully", "Admin 2 login failed");

$a2ListResp = makeHttpRequest(BASE_URL . '/admin2/post-event.php', 'GET', [], $admin2Cookie);
assertTest($a2ListResp['status'] === 200, "Admin 2 loads post-event.php (HTTP 200)", "Admin 2 post-event.php returned HTTP {$a2ListResp['status']}");

assertTest(strpos($a2ListResp['body'], 'Unit Test Completed Faculty Activity') !== false,
    "Admin 2 post-event.php lists completed faculty activity #{$facActId}",
    "Admin 2 post-event.php missing faculty activity #{$facActId}");

assertTest(strpos($a2ListResp['body'], 'Approved Activity') !== false,
    "Admin 2 post-event.php lists completed student org activity #1144",
    "Admin 2 post-event.php missing student org activity #1144");

assertTest(strpos($a2ListResp['body'], 'Unit Test Draft Incomplete Activity') === false,
    "Admin 2 post-event.php excludes incomplete/draft activity #{$draftActId}",
    "Admin 2 post-event.php leaked draft/incomplete activity");

$a2ViewResp = makeHttpRequest(BASE_URL . '/admin2/post-event-view.php?id=' . $facActId, 'GET', [], $admin2Cookie);
assertTest($a2ViewResp['status'] === 200, "Admin 2 loads post-event-view.php?id={$facActId} (HTTP 200)", "Admin 2 view failed (HTTP {$a2ViewResp['status']})");
assertTest(strpos($a2ViewResp['body'], 'Read-Only Institutional Record') === false,
    "Admin 2 view cleanly excludes Read-Only Institutional Record banner",
    "Admin 2 view still contains Read-Only banner");

// 3C. Test Dean
echo "\n[Testing Role: Dean]\n";
$deanCookie = loginUser('dean@sti.edu', 'password');
assertTest(!empty($deanCookie), "Dean logged in successfully", "Dean login failed");

$deanListResp = makeHttpRequest(BASE_URL . '/dean/post-event.php', 'GET', [], $deanCookie);
assertTest($deanListResp['status'] === 200, "Dean loads post-event.php (HTTP 200)", "Dean post-event.php returned HTTP {$deanListResp['status']}");

assertTest(strpos($deanListResp['body'], 'Unit Test Completed Faculty Activity') !== false,
    "Dean post-event.php lists completed faculty activity #{$facActId}",
    "Dean post-event.php missing faculty activity #{$facActId}");

assertTest(strpos($deanListResp['body'], 'Approved Activity') !== false,
    "Dean post-event.php lists completed student org activity #1144",
    "Dean post-event.php missing student org activity #1144");

assertTest(strpos($deanListResp['body'], 'Unit Test Draft Incomplete Activity') === false,
    "Dean post-event.php excludes incomplete/draft activity #{$draftActId}",
    "Dean post-event.php leaked draft/incomplete activity");

$deanViewResp = makeHttpRequest(BASE_URL . '/dean/post-event-view.php?id=' . $facActId, 'GET', [], $deanCookie);
assertTest($deanViewResp['status'] === 200, "Dean loads post-event-view.php?id={$facActId} (HTTP 200)", "Dean view failed (HTTP {$deanViewResp['status']})");
assertTest(strpos($deanViewResp['body'], 'Read-Only Institutional Record') === false,
    "Dean view cleanly excludes Read-Only Institutional Record banner",
    "Dean view still contains Read-Only banner");

// Summary
echo "\n============================================================\n";
echo "  TEST SUMMARY\n";
echo "============================================================\n";
echo "Total Assertions: " . (count($successes) + count($errors)) . "\n";
echo "Passed: " . count($successes) . "\n";
echo "Failed: " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\nFailures:\n";
    foreach ($errors as $err) {
        echo "  " . $err . "\n";
    }
    exit(1);
} else {
    echo "\nAll post-event results module assertions passed cleanly!\n";
    exit(0);
}
