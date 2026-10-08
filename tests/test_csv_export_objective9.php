<?php
/**
 * Focused Test Suite: Objective 9 Individual Activity CSV Export
 *
 * Requirements Verified:
 * 1. Support for ?export=csv on individual activity report.
 * 2. Proper Content-Type (text/csv) and Content-Disposition (attachment; filename=*.csv) headers.
 * 3. Activity information, status/date/venue, KPI results, achievements, evaluations, and summary info in CSV.
 * 4. Cross-activity isolation (another activity's data cannot appear).
 * 5. Role authorization enforced (unauthorized/faculty denied, dean/admin allowed).
 * 6. Normal HTML report behavior preserved when export=csv is absent.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

$passedTests = 0;
$failedTests = 0;

function assertTest(string $name, bool $condition, string $details = ""): void {
    global $passedTests, $failedTests;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$name}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$name}\n";
        if (!empty($details)) {
            echo "         Details: {$details}\n";
        }
    }
}

echo "============================================================\n";
echo "  Objective 9 CSV Export Verification Test\n";
echo "============================================================\n\n";

// Retrieve test users
$testFaculty = $db->query("SELECT id, name, role FROM users WHERE role = 'faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$testDean = $db->query("SELECT id, name, role FROM users WHERE role = 'dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$testAdmin = $db->query("SELECT id, name, role FROM users WHERE role = 'admin1' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if (!$testFaculty || !$testDean) {
    die("Error: Required users (faculty, dean) not found in database.\n");
}

$facultyId = (int)$testFaculty['id'];
$deanId = (int)$testDean['id'];
$adminId = (int)($testAdmin['id'] ?? $deanId);

// 1. Create two test activities to test CSV export & isolation
$stmt = $db->prepare("
    INSERT INTO activities (title, faculty_id, event_date, venue, venue_address, status, target_participants, source)
    VALUES (?, ?, '2026-11-20', 'STI Main Hall', '123 Academic Blvd', 'completed', 150, 'faculty')
");
$stmt->execute(['CSV Primary Activity Workshop', $facultyId]);
$primaryActId = (int)$db->lastInsertId();

$stmt->execute(['CSV Secondary Isolation Activity', $facultyId]);
$secondaryActId = (int)$db->lastInsertId();

echo "Created Test Activities: Primary ID = {$primaryActId}, Secondary ID = {$secondaryActId}\n";

$deanSessionId = bin2hex(random_bytes(16));
$facultySessionId = bin2hex(random_bytes(16));

function createSessionFile(string $sessionId, array $data): void {
    $content = '';
    foreach ($data as $key => $val) {
        $content .= $key . '|' . serialize($val);
    }
    file_put_contents('C:/xampp/tmp/sess_' . $sessionId, $content);
}

function removeSessionFile(string $sessionId): void {
    $path = 'C:/xampp/tmp/sess_' . $sessionId;
    if (file_exists($path)) {
        @unlink($path);
    }
}

function httpGetWithHeaders(string $url, ?string $sessionId): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($sessionId !== null) {
        curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    return [
        'code' => $httpCode,
        'headers' => $headerStr,
        'body' => $body
    ];
}

try {
    // Setup Primary activity data
    $db->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'approved', 'Primary activity officially endorsed by Dean office.', '2026-11-01 09:15:00')
    ")->execute([$primaryActId, $deanId]);

    $db->prepare("
        INSERT INTO approval_logs (activity_id, reviewer_id, action, notes, acted_at)
        VALUES (?, ?, 'rejected', 'SECRET_SECONDARY_NOTE_ISOLATION_CHECK', '2026-11-02 10:00:00')
    ")->execute([$secondaryActId, $adminId]);

    $db->prepare("
        INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by, uploaded_at)
        VALUES (?, 'post_event', 'primary_event_report.pdf', 'C:\\secret\\primary.pdf', ?, '2026-11-21 10:00:00')
    ")->execute([$primaryActId, $facultyId]);
    $primaryDocId = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO documents (activity_id, doc_type, file_name, file_path, uploaded_by, uploaded_at)
        VALUES (?, 'material', 'secondary_isolated_file.xlsx', 'C:\\secret\\secondary.xlsx', ?, '2026-11-22 11:00:00')
    ")->execute([$secondaryActId, $facultyId]);

    $db->prepare("
        INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
        VALUES (?, 'Speaker Mastery', 4, 'Excellent interactive demonstration and hands-on guidance.', 'Participant Alex', '2026-11-21 14:00:00')
    ")->execute([$primaryActId]);

    $db->prepare("
        INSERT INTO kpi_evaluations (activity_id, criteria, rating, comments, evaluator_name, evaluated_at)
        VALUES (?, 'Secondary Criteria', 1, 'SECRET_SECONDARY_PARTICIPANT_COMMENT', 'Participant Bob', '2026-11-21 15:00:00')
    ")->execute([$secondaryActId]);

    $feedbackData = [
        'is_finalized' => true,
        'finalized_by' => ['name' => 'Dean Test Evaluator', 'role' => 'Dean'],
        'finalized_at' => '2026-11-22 16:00:00',
        'overall_summary' => 'Highly successful workshop with strong engagement across all technical tracks.',
        'positive_themes' => [
            ['theme' => 'Practical Labs', 'frequency' => 38, 'summary' => 'Students appreciated immediate application.']
        ],
        'improvement_themes' => [
            ['theme' => 'Session Duration', 'frequency' => 8, 'summary' => 'Could benefit from longer QA segment.']
        ],
        'key_findings' => ['High rating in technical clarity.'],
        'common_suggestions' => ['Provide slides in advance.'],
        'recommendations' => ['Offer secondary advanced track.']
    ];
    $db->prepare("
        INSERT INTO activity_feedback_analysis (activity_id, analysis_json, response_hash, responses_count)
        VALUES (?, ?, 'hash_csv_test', 40)
    ")->execute([$primaryActId, json_encode($feedbackData)]);

    // Create session files
    createSessionFile($deanSessionId, [
        'user_id' => $deanId,
        'user_role' => 'dean',
        'role' => 'dean',
        'user_name' => 'Dean Test Evaluator',
        'name' => 'Dean Test Evaluator',
        'must_change_password' => 0,
        'last_activity' => time()
    ]);

    createSessionFile($facultySessionId, [
        'user_id' => $facultyId,
        'user_role' => 'faculty',
        'role' => 'faculty',
        'user_name' => 'Faculty Non-Admin',
        'name' => 'Faculty Non-Admin',
        'must_change_password' => 0,
        'last_activity' => time()
    ]);

    $baseUrl = 'http://localhost/sti-activity-system/dean/generate-report.php';

    // -------------------------------------------------------------
    // TEST 1: CSV Generation and HTTP Headers
    // -------------------------------------------------------------
    echo "\n--- TEST 1: CSV Generation and Headers ---\n";
    $csvUrl = $baseUrl . '?activity_id=' . $primaryActId . '&export=csv';
    $res = httpGetWithHeaders($csvUrl, $deanSessionId);

    assertTest(
        "CSV request returns HTTP 200 OK",
        $res['code'] === 200,
        "HTTP Code: " . $res['code']
    );

    assertTest(
        "Content-Type is text/csv",
        stripos($res['headers'], 'Content-Type: text/csv') !== false,
        "Headers: " . $res['headers']
    );

    assertTest(
        "Content-Disposition is attachment with .csv extension",
        stripos($res['headers'], 'Content-Disposition: attachment;') !== false &&
        stripos($res['headers'], '.csv"') !== false,
        "Headers: " . $res['headers']
    );

    assertTest(
        "Cache-Control / Pragma headers disable caching",
        stripos($res['headers'], 'Pragma: no-cache') !== false || stripos($res['headers'], 'no-store') !== false,
        "Headers: " . $res['headers']
    );

    // -------------------------------------------------------------
    // TEST 2: CSV Data Content Verification
    // -------------------------------------------------------------
    echo "\n--- TEST 2: CSV Content Verification ---\n";
    $csvBody = $res['body'];

    assertTest(
        "CSV starts with UTF-8 BOM",
        substr($csvBody, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF),
        "BOM missing at start of CSV"
    );

    assertTest(
        "Activity Title is included in CSV",
        str_contains($csvBody, "CSV Primary Activity Workshop"),
        "Title missing in CSV"
    );

    assertTest(
        "Status, Venue, and Date are included in CSV",
        str_contains($csvBody, "Completed") && str_contains($csvBody, "STI Main Hall") && str_contains($csvBody, "2026-11-20"),
        "Status/Venue/Date missing in CSV"
    );

    assertTest(
        "Deterministic KPI calculation section and indicators are present",
        str_contains($csvBody, "--- KPI RESULTS & ACHIEVEMENT VALUES ---") &&
        str_contains($csvBody, "KPI / Indicator"),
        "KPI indicators missing in CSV"
    );

    assertTest(
        "Overall Performance Score is present in CSV",
        str_contains($csvBody, "Overall Performance Score"),
        "Overall score missing in CSV"
    );

    assertTest(
        "Participant evaluation results and comments are present",
        str_contains($csvBody, "Speaker Mastery") &&
        str_contains($csvBody, "4 / 4") &&
        str_contains($csvBody, "Excellent interactive demonstration and hands-on guidance."),
        "Evaluation results or comment text missing in CSV"
    );

    assertTest(
        "Approval & endorsement history is present in CSV",
        str_contains($csvBody, "--- APPROVAL & ENDORSEMENT HISTORY ---") &&
        str_contains($csvBody, "Primary activity officially endorsed by Dean office."),
        "Approval history missing in CSV"
    );

    assertTest(
        "Centralized activity documentation is present with secure download URL",
        str_contains($csvBody, "primary_event_report.pdf") &&
        str_contains($csvBody, "api/document-download.php?id=" . $primaryDocId),
        "Documentation or download URL missing in CSV"
    );

    assertTest(
        "Document filesystem paths are NOT exposed in CSV",
        !str_contains($csvBody, "C:\\secret\\") && !str_contains($csvBody, "secret_plan"),
        "Filesystem path leaked in CSV!"
    );

    assertTest(
        "AI qualitative feedback analysis summary and themes are present",
        str_contains($csvBody, "Highly successful workshop with strong engagement") &&
        str_contains($csvBody, "Practical Labs") &&
        str_contains($csvBody, "Finalized by Dean Test Evaluator"),
        "AI qualitative summary missing in CSV"
    );

    // -------------------------------------------------------------
    // TEST 3: Cross-Activity Isolation in CSV
    // -------------------------------------------------------------
    echo "\n--- TEST 3: Cross-Activity Isolation ---\n";
    assertTest(
        "Primary CSV does NOT contain secondary activity note",
        !str_contains($csvBody, "SECRET_SECONDARY_NOTE_ISOLATION_CHECK"),
        "Secondary note leaked into Primary CSV!"
    );

    assertTest(
        "Primary CSV does NOT contain secondary activity document",
        !str_contains($csvBody, "secondary_isolated_file.xlsx"),
        "Secondary doc leaked into Primary CSV!"
    );

    assertTest(
        "Primary CSV does NOT contain secondary participant comment",
        !str_contains($csvBody, "SECRET_SECONDARY_PARTICIPANT_COMMENT"),
        "Secondary comment leaked into Primary CSV!"
    );

    // -------------------------------------------------------------
    // TEST 4: Authorization Enforcement
    // -------------------------------------------------------------
    echo "\n--- TEST 4: Role Authorization Enforcement ---\n";
    $unauthRes = httpGetWithHeaders($csvUrl, null);
    assertTest(
        "Unauthenticated CSV request is rejected or redirected to login",
        $unauthRes['code'] === 302 || $unauthRes['code'] === 401 || $unauthRes['code'] === 403 || str_contains($unauthRes['body'], 'login.php'),
        "Unauthenticated access allowed! Code: " . $unauthRes['code']
    );

    $facultyRes = httpGetWithHeaders($csvUrl, $facultySessionId);
    assertTest(
        "Unauthorized role (faculty) CSV request is denied (HTTP 403)",
        $facultyRes['code'] === 403 || str_contains($facultyRes['body'], 'Access denied'),
        "Faculty role access not denied! Code: " . $facultyRes['code']
    );

    // -------------------------------------------------------------
    // TEST 5: Normal HTML Report Behavior Preserved
    // -------------------------------------------------------------
    echo "\n--- TEST 5: Normal HTML Report Preserved (export=csv absent) ---\n";
    $htmlUrl = $baseUrl . '?activity_id=' . $primaryActId;
    $htmlRes = httpGetWithHeaders($htmlUrl, $deanSessionId);

    assertTest(
        "Normal report returns HTTP 200 OK",
        $htmlRes['code'] === 200,
        "HTTP Code: " . $htmlRes['code']
    );

    assertTest(
        "Normal report Content-Type is text/html",
        stripos($htmlRes['headers'], 'Content-Type: text/html') !== false,
        "Headers: " . $htmlRes['headers']
    );

    assertTest(
        "Normal report contains HTML DOCTYPE, topbar, and KPI sections",
        str_contains($htmlRes['body'], '<!DOCTYPE html>') &&
        str_contains($htmlRes['body'], 'KPI Report Generator') &&
        str_contains($htmlRes['body'], 'Deterministic KPI Calculations'),
        "HTML elements missing in standard report"
    );

    assertTest(
        "Normal report topbar contains Export CSV link",
        str_contains($htmlRes['body'], 'export=csv') &&
        str_contains($htmlRes['body'], 'Export CSV'),
        "Export CSV button missing from HTML report"
    );

} finally {
    echo "\nCleaning up test fixtures...\n";
    removeSessionFile($deanSessionId);
    removeSessionFile($facultySessionId);
    $db->prepare("DELETE FROM activity_feedback_analysis WHERE activity_id IN (?, ?)")->execute([$primaryActId, $secondaryActId]);
    $db->prepare("DELETE FROM kpi_evaluations WHERE activity_id IN (?, ?)")->execute([$primaryActId, $secondaryActId]);
    $db->prepare("DELETE FROM documents WHERE activity_id IN (?, ?)")->execute([$primaryActId, $secondaryActId]);
    $db->prepare("DELETE FROM approval_logs WHERE activity_id IN (?, ?)")->execute([$primaryActId, $secondaryActId]);
    $db->prepare("DELETE FROM activities WHERE id IN (?, ?)")->execute([$primaryActId, $secondaryActId]);
    echo "Cleanup complete.\n\n";
}

echo "============================================================\n";
echo "  Results: Passed = {$passedTests}, Failed = {$failedTests}\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
