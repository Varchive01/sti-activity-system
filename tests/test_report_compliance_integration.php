<?php
/**
 * Test Suite: Dean Activity Report Post-Activity Compliance Integration
 *
 * Verifies:
 * 1. Open a completed activity with post-event data (e.g. activity 118) in dean/generate-report.php
 * 2. Confirm compliance status matches dean/view-activity.php and calculatePostActivityCompliance()
 * 3. Confirm percentage matches the helper
 * 4. Confirm all 8 compliance checks appear in the report
 * 5. Test an activity with no post-event data (e.g. activity 855)
 * 6. Confirm "Not Verifiable" is shown instead of 0%
 * 7. Confirm report data remains strictly read-only
 * 8. Confirm CSV export contains the compliance section and matches HTML
 * 9. Confirm zero database mutations during report generation
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/compliance_helper.php';

$db = getDB();
$baseUrl = 'http://localhost/sti-activity-system';

function makeRequest(string $url, string $method = 'GET', array $data = [], ?string $cookie = null): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }

    if ($cookie) {
        $opts[CURLOPT_COOKIE] = $cookie;
    }

    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headerStr, $matches);
    $cookies = $matches[1] ?? [];

    return [
        'code' => $httpCode,
        'headers' => $headerStr,
        'body' => $body,
        'cookies' => $cookies
    ];
}

function loginUser(string $baseUrl, string $email, string $password): string {
    $res = makeRequest("{$baseUrl}/auth/login.php", 'POST', [
        'email' => $email,
        'password' => $password
    ]);

    if (!empty($res['cookies'])) {
        return end($res['cookies']);
    }
    return '';
}

$passedTests = 0;
$failedTests = 0;

function assertCondition(string $name, bool $condition, string $details = ""): void {
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
echo "   DEAN REPORT POST-ACTIVITY COMPLIANCE TEST SUITE          \n";
echo "============================================================\n\n";

$deanCookie = loginUser($baseUrl, 'dean@sti.edu', 'password');
assertCondition("Dean authenticated with active session", !empty($deanCookie));

// -------------------------------------------------------------
// Test Case 1: Completed Activity with Post-Event Data (ID 118)
// -------------------------------------------------------------
echo "\n--- Test Case 1: Completed Activity with Post-Event Data (ID 118) ---\n";

$act118Id = 118;
$compHelper118 = calculatePostActivityCompliance($act118Id, null, $db);

// Fetch Dean View Activity HTML to cross-check
$viewRes118 = makeRequest("{$baseUrl}/dean/view-activity.php?id={$act118Id}", 'GET', [], $deanCookie);
assertCondition("Dean view-activity.php returns HTTP 200 for activity 118", $viewRes118['code'] === 200);

// Fetch Dean Generate Report HTML
$repRes118 = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$act118Id}", 'GET', [], $deanCookie);
assertCondition("Dean generate-report.php returns HTTP 200 for activity 118", $repRes118['code'] === 200);

// Confirm section exists
assertCondition(
    "Report contains 'Post-Activity Compliance' section",
    str_contains($repRes118['body'], 'id="postActivityComplianceSection"') &&
    str_contains($repRes118['body'], 'Post-Activity Compliance')
);

// Confirm Overall Status matches helper and view-activity.php
$overallStatus118 = $compHelper118['overall_status'];
assertCondition(
    "Report displays overall status matching helper ({$overallStatus118})",
    str_contains($repRes118['body'], $overallStatus118)
);
assertCondition(
    "Overall status matches view-activity.php ({$overallStatus118})",
    str_contains($viewRes118['body'], $overallStatus118)
);

// Confirm Compliance Percentage matches helper
$expectedPct118 = number_format($compHelper118['compliance_percentage'], 1) . '%';
assertCondition(
    "Report displays compliance percentage matching helper ({$expectedPct118})",
    str_contains($repRes118['body'], $expectedPct118)
);

// Confirm Summary Tiles: Verifiable, Compliant, Non-Compliant, Not Verifiable
assertCondition(
    "Report displays summary tiles with accurate counts",
    str_contains($repRes118['body'], 'Verifiable Checks') &&
    str_contains($repRes118['body'], (string)$compHelper118['verifiable_count']) &&
    str_contains($repRes118['body'], 'Compliant Checks') &&
    str_contains($repRes118['body'], (string)$compHelper118['compliant_count']) &&
    str_contains($repRes118['body'], 'Non-Compliant Checks') &&
    str_contains($repRes118['body'], (string)$compHelper118['non_compliant_count']) &&
    str_contains($repRes118['body'], 'Not Verifiable Checks') &&
    str_contains($repRes118['body'], (string)$compHelper118['not_verifiable_count'])
);

// Confirm all 8 checks appear in the table
$requiredChecks = [
    'Activity Title',
    'Event Date',
    'Venue',
    'Objectives',
    'Program / Sequence',
    'Assigned People / Manpower',
    'KPI / Evaluation Criteria',
    'Post-Event Documentation'
];

foreach ($requiredChecks as $chkName) {
    assertCondition(
        "Compliance check '{$chkName}' appears in the report table",
        str_contains($repRes118['body'], $chkName)
    );
}

// -------------------------------------------------------------
// Test Case 2: Activity with NO Post-Event Data (ID 855)
// -------------------------------------------------------------
echo "\n--- Test Case 2: Activity with NO Post-Event Data (ID 855) ---\n";

$act855Id = 855;
$compHelper855 = calculatePostActivityCompliance($act855Id, null, $db);

$repRes855 = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$act855Id}", 'GET', [], $deanCookie);
assertCondition("Dean generate-report.php returns HTTP 200 for activity 855", $repRes855['code'] === 200);

assertCondition(
    "Helper returns null compliance_percentage for activity 855",
    $compHelper855['compliance_percentage'] === null
);
assertCondition(
    "Helper returns 'Not Verifiable' overall_status for activity 855",
    $compHelper855['overall_status'] === 'Not Verifiable'
);

// Confirm 'Not Verifiable' is shown instead of '0%' or '0.0%'
// In the compliance summary card for compliance rate:
preg_match('/<div[^>]*>Compliance Rate<\/div>\s*<div[^>]*>(.*?)<\/div>/s', $repRes855['body'], $rateCardMatch);
$rateCardContent = $rateCardMatch[1] ?? '';
assertCondition(
    "Report shows 'Not Verifiable' instead of 0% for compliance rate",
    str_contains($rateCardContent, 'Not Verifiable') &&
    !str_contains($rateCardContent, '0%') &&
    !str_contains($rateCardContent, '0.0%')
);

// Confirm all 8 checks appear for activity 855 as well
foreach ($requiredChecks as $chkName) {
    assertCondition(
        "Activity 855: Compliance check '{$chkName}' appears in the report",
        str_contains($repRes855['body'], $chkName)
    );
}

// -------------------------------------------------------------
// Test Case 3: Read-Only Integrity Verification
// -------------------------------------------------------------
echo "\n--- Test Case 3: Read-Only Integrity Verification ---\n";

// In the postActivityComplianceSection, confirm no form elements (input, textarea, select, button)
preg_match('/<div class="card report-section" id="postActivityComplianceSection">.*?<\/div>\s*<\/div>\s*<!-- Section 2/s', $repRes118['body'], $secMatches);
$sectionHtml = $secMatches[0] ?? '';

assertCondition("Extracted Post-Activity Compliance HTML section successfully", !empty($sectionHtml));
assertCondition(
    "Compliance section contains no <input> tags",
    !str_contains(strtolower($sectionHtml), '<input')
);
assertCondition(
    "Compliance section contains no <textarea> tags",
    !str_contains(strtolower($sectionHtml), '<textarea')
);
assertCondition(
    "Compliance section contains no <select> tags",
    !str_contains(strtolower($sectionHtml), '<select')
);
assertCondition(
    "Compliance section contains no <button> tags",
    !str_contains(strtolower($sectionHtml), '<button')
);

// -------------------------------------------------------------
// Test Case 4: CSV Export Parity Verification
// -------------------------------------------------------------
echo "\n--- Test Case 4: CSV Export Parity Verification ---\n";

$csvRes118 = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$act118Id}&export=csv", 'GET', [], $deanCookie);
assertCondition("CSV export returns HTTP 200", $csvRes118['code'] === 200);
assertCondition(
    "CSV header Content-Type is text/csv",
    str_contains($csvRes118['headers'], 'text/csv')
);

$csvBody = $csvRes118['body'];

assertCondition(
    "CSV contains '--- POST-ACTIVITY COMPLIANCE ---' header",
    str_contains($csvBody, '--- POST-ACTIVITY COMPLIANCE ---')
);
assertCondition(
    "CSV contains Overall Status matching helper",
    str_contains($csvBody, '"Overall Status","' . $compHelper118['overall_status'] . '"') ||
    str_contains($csvBody, 'Overall Status,' . $compHelper118['overall_status'])
);
assertCondition(
    "CSV contains Compliance Percentage matching helper",
    str_contains($csvBody, '"Compliance Percentage",' . $expectedPct118) ||
    str_contains($csvBody, '"Compliance Percentage","' . $expectedPct118 . '"') ||
    str_contains($csvBody, 'Compliance Percentage,' . $expectedPct118)
);
assertCondition(
    "CSV contains column headers 'Check Name','Result','Approved / Proposal Value','Actual / Post-Event Value'",
    str_contains($csvBody, 'Check Name') &&
    str_contains($csvBody, 'Approved / Proposal Value') &&
    str_contains($csvBody, 'Actual / Post-Event Value')
);

foreach ($requiredChecks as $chkName) {
    assertCondition(
        "CSV includes check row for '{$chkName}'",
        str_contains($csvBody, $chkName)
    );
}

// Also test CSV export for activity 855 (Not Verifiable)
$csvRes855 = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$act855Id}&export=csv", 'GET', [], $deanCookie);
assertCondition(
    "CSV for activity 855 contains 'Not Verifiable' percentage",
    str_contains($csvRes855['body'], '"Compliance Percentage","Not Verifiable"') ||
    str_contains($csvRes855['body'], 'Compliance Percentage,Not Verifiable')
);

// -------------------------------------------------------------
// Test Case 5: Zero Database Mutations Verification
// -------------------------------------------------------------
echo "\n--- Test Case 5: Zero Database Mutations Verification ---\n";

// Count records across all key tables before and after calling report
$tables = ['activities', 'post_event', 'documents', 'faculty_tasks', 'kpi_evaluations', 'approval_logs', 'manpower', 'program_sequence'];
$beforeCounts = [];
foreach ($tables as $t) {
    $beforeCounts[$t] = (int)$db->query("SELECT count(*) FROM {$t}")->fetchColumn();
}

// Generate report again
$repResMutCheck = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$act118Id}", 'GET', [], $deanCookie);
$csvResMutCheck = makeRequest("{$baseUrl}/dean/generate-report.php?activity_id={$act118Id}&export=csv", 'GET', [], $deanCookie);

$afterCounts = [];
$mutationsDetected = false;
foreach ($tables as $t) {
    $afterCounts[$t] = (int)$db->query("SELECT count(*) FROM {$t}")->fetchColumn();
    if ($afterCounts[$t] !== $beforeCounts[$t]) {
        $mutationsDetected = true;
    }
}

assertCondition("Zero database records created or deleted across all tables", !$mutationsDetected);

echo "\n============================================================\n";
echo "TEST RESULTS: Passed: {$passedTests} | Failed: {$failedTests}\n";
echo "============================================================\n";

if ($failedTests > 0) {
    exit(1);
}
exit(0);
