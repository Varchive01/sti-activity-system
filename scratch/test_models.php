<?php
require_once __DIR__ . '/../includes/ai/proposal_validator.php';
$apiKey = getGeminiApiKeySecure();
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($apiKey);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);
$data = json_decode($response, true);
if (!empty($data['models'])) {
    foreach ($data['models'] as $m) {
        if (str_contains($m['name'], 'flash')) {
            echo $m['name'] . " - methods: " . implode(',', $m['supportedGenerationMethods'] ?? []) . "\n";
        }
    }
} else {
    echo "Response: " . $response . "\n";
}
