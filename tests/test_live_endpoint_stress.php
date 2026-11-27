<?php
/**
 * tests/test_live_endpoint_stress.php
 * Final phase live stress and verification testing against the live production endpoint.
 */

$target = "https://sti-armes.freedev.app/auth/login.php";
echo "=======================================================\n";
echo " STI-ARMES Live Endpoint Verification & Stress Test\n";
echo " Target: $target\n";
echo "=======================================================\n\n";

$latencies = [];
$successCount = 0;

for ($i = 1; $i <= 5; $i++) {
    $start = microtime(true);
    $ch = curl_init($target);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1");
    
    $bodyText = curl_exec($ch);
    $duration = (microtime(true) - $start) * 1000;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $latencies[] = $duration;
    $bodyLen = strlen($bodyText ?? '');
    $isHtml = (strpos($bodyText, '<html') !== false || strpos($bodyText, '<!DOCTYPE') !== false || strpos($bodyText, 'login') !== false);

    if ($httpCode === 200 && $bodyLen > 200 && $isHtml) {
        $successCount++;
        echo " [PASS] Request $i: HTTP $httpCode | Latency: " . round($duration, 2) . "ms | Body: {$bodyLen} bytes | Valid HTML Response\n";
    } else {
        echo " [FAIL] Request $i: HTTP $httpCode | Latency: " . round($duration, 2) . "ms | Body: {$bodyLen} bytes\n";
    }
}

$avg = round(array_sum($latencies) / count($latencies), 2);
$min = round(min($latencies), 2);
$max = round(max($latencies), 2);

echo "\n-------------------------------------------------------\n";
echo " Live Endpoint Stress Summary:\n";
echo " - Requests Completed: $successCount/5\n";
echo " - Min Latency: {$min}ms\n";
echo " - Avg Latency: {$avg}ms\n";
echo " - Max Latency: {$max}ms\n";
echo "-------------------------------------------------------\n";

if ($successCount === 5) {
    echo " ALL 5 LIVE REQUESTS PASSED. Live domain is healthy and stable.\n";
    exit(0);
} else {
    echo " LIVE TEST FAILED.\n";
    exit(1);
}
