<?php
// tests/test_live_endpoint_stress.php

require_once __DIR__ . '/../includes/GoogleOAuthService.php';

$endpoint = 'https://sti-armes.freedev.app/auth/login.php';
$iterations = 10;
$results = [];

echo "========================================================\n";
echo " LIVE ENDPOINT STRESS & VERIFICATION TEST\n";
echo " Target: $endpoint\n";
echo " Iterations: $iterations\n";
echo "========================================================\n";

$caBundle = GoogleOAuthService::resolveCaBundle();
if ($caBundle) {
    echo " Using CA bundle: $caBundle\n";
} else {
    echo " No CA bundle found, using system default\n";
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
if ($caBundle) {
    curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
} else {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
}

$allPassed = true;
$totalTime = 0;

for ($i = 1; $i <= $iterations; $i++) {
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = (microtime(true) - $start) * 1000;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    $isOk = ($httpCode === 200) && empty($curlError);
    if (!$isOk) {
        $allPassed = false;
    }
    $totalTime += $duration;

    if ($i === 1) {
        echo " Sample response snippet:\n" . substr((string)$response, 0, 200) . "\n---\n";
    }

    echo sprintf(" [%s] Request #%02d: HTTP %d | %.2f ms | Size: %d bytes%s\n",
        $isOk ? 'PASS' : 'FAIL',
        $i,
        $httpCode,
        $duration,
        strlen((string)$response),
        !empty($curlError) ? " (Error: {$curlError})" : ""
    );

    // Short jitter delay
    usleep(100000); // 100ms
}

$avgTime = $totalTime / $iterations;
echo "========================================================\n";
echo sprintf(" STRESS TEST RESULT: %s\n", $allPassed ? 'ALL PASSED' : 'SOME FAILED');
echo sprintf(" Average Response Time: %.2f ms\n", $avgTime);
echo "========================================================\n";
