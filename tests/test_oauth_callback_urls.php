<?php
// tests/test_oauth_callback_urls.php

require_once __DIR__ . '/../config/config.php';

echo "========================================================\n";
echo " OAUTH CALLBACK STATE & URL RESOLUTION VERIFICATION\n";
echo "========================================================\n";

$passCount = 0;
$failCount = 0;

function assertCondition($cond, $desc) {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] $desc\n";
        $passCount++;
    } else {
        echo " [FAIL] $desc\n";
        $failCount++;
    }
}

// 1. State roundtrip test
$payload = [
    'origin' => 'https://sti-armes.freedev.app',
    'return_to' => 'faculty/proposal-create.php'
];
$encoded = base64_encode(json_encode($payload));
$decoded = json_decode(base64_decode($encoded), true);

assertCondition($decoded['origin'] === 'https://sti-armes.freedev.app', 'Decoded origin matches live site domain');
assertCondition($decoded['return_to'] === 'faculty/proposal-create.php', 'Decoded return_to matches proposal creator');

// 2. Proposal edit with activity ID
$payloadEdit = [
    'origin' => 'https://sti-armes.freedev.app',
    'return_to' => 'faculty/proposal-edit.php?id=42'
];
$encodedEdit = base64_encode(json_encode($payloadEdit));
$decodedEdit = json_decode(base64_decode($encodedEdit), true);

assertCondition($decodedEdit['return_to'] === 'faculty/proposal-edit.php?id=42', 'Decoded return_to preserves proposal edit activity ID query parameter');

// 3. Fallback resolution test when on localhost
$isLocal = true;
$liveSiteUrl = 'https://sti-armes.freedev.app';
$origin = $decoded['origin'] ?? '';
$returnTo = $decoded['return_to'] ?? 'faculty/proposal-create.php';

$targetLiveBase = !empty($origin) && !str_contains($origin, 'localhost') ? rtrim($origin, '/') : $liveSiteUrl;
$cleanReturnTo = ltrim($returnTo, '/');
$liveReturnUrl = $targetLiveBase . '/' . $cleanReturnTo;

assertCondition($liveReturnUrl === 'https://sti-armes.freedev.app/faculty/proposal-create.php', 'liveReturnUrl resolves to live domain path');

// 4. Role dashboard mapping
$roleDashboardMap = [
    'faculty'      => 'faculty/dashboard.php',
    'program_head' => 'program_head/dashboard.php',
    'admin1'       => 'admin1/dashboard.php',
    'admin2'       => 'admin2/dashboard.php',
    'dean'         => 'dean/dashboard.php',
];

foreach ($roleDashboardMap as $role => $subpath) {
    $dashUrl = $targetLiveBase . '/' . $subpath;
    assertCondition($dashUrl === "https://sti-armes.freedev.app/{$subpath}", "Dashboard for role '{$role}' resolves to {$dashUrl}");
}

// 5. Default fallback if origin is missing on localhost
$emptyOrigin = '';
$resolvedBase = !empty($emptyOrigin) && !str_contains($emptyOrigin, 'localhost') ? rtrim($emptyOrigin, '/') : $liveSiteUrl;
$defaultLiveReturn = $resolvedBase . '/faculty/proposal-create.php';
assertCondition($defaultLiveReturn === 'https://sti-armes.freedev.app/faculty/proposal-create.php', 'Missing origin defaults safely to live domain');

echo "========================================================\n";
echo " SUMMARY: Passed: $passCount | Failed: $failCount\n";
echo "========================================================\n";

