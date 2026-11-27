<?php
$url = 'https://sti-armes.freedev.app/auth/login.php';
$times = [];
echo "Testing Live Endpoint: $url\n";
for ($i = 1; $i <= 5; $i++) {
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $dur = round((microtime(true) - $start) * 1000);
    $times[] = $dur;
    echo "  Request #$i: HTTP $code in {$dur}ms\n";
}
echo "Average response time: " . round(array_sum($times) / count($times)) . "ms\n";
