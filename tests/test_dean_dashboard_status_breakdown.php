<?php
/**
 * Automated Verification for Status Breakdown Percentage Removal
 */

error_reporting(E_ALL & ~E_DEPRECATED);

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

$passCount = 0;
$failCount = 0;

function assertCheck(bool $condition, string $msg) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] {$msg}\n";
        $passCount++;
    } else {
        echo "  [FAIL] {$msg}\n";
        $failCount++;
    }
}

echo "============================================================\n";
echo "  STATUS BREAKDOWN PERCENTAGE REMOVAL VERIFICATION\n";
echo "============================================================\n\n";

$deanCookie = loginUser($baseUrl, 'dean@sti.edu', 'password');
assertCheck(!empty($deanCookie), "Dean authenticated successfully");

$dashRes = makeRequest("{$baseUrl}/dean/dashboard.php", 'GET', [], $deanCookie);
assertCheck($dashRes['code'] === 200, "Dean Dashboard loads with HTTP 200");
$html = $dashRes['body'];

// Extract the Status Breakdown Card
assertCheck(str_contains($html, 'card-status-breakdown'), "Contains .card-status-breakdown container");
assertCheck(str_contains($html, '<h2>Status Breakdown</h2>'), "Contains Status Breakdown heading");

// Extract the inner HTML of the Status Breakdown card
preg_match('/<div class="card card-status-breakdown">([\s\S]*?)<\/div>\s*<\/div>\s*<!-- dashboard-rail-sticky -->/i', $html, $m);
$cardHtml = $m[1] ?? '';
assertCheck(!empty($cardHtml), "Successfully extracted Status Breakdown card content");

// Verify that percentages and percentage progress bars are completely removed
assertCheck(!str_contains($cardHtml, '%</span>'), "Zero percentage text (%</span>) inside Status Breakdown card");
assertCheck(!str_contains($cardHtml, 'width:<?= $pct ?>%'), "Zero percentage calculation in template");
assertCheck(str_contains($cardHtml, 'display:flex;align-items:center;justify-content:space-between;'), "Status items use balanced flex row layout with badge and count");

echo "\n============================================================\n";
echo "  TEST SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "============================================================\n";

if ($failCount > 0) {
    exit(1);
}

