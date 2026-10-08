<?php
require_once __DIR__ . '/../config/google_config.php';

echo "Testing getGoogleConfig()...\n";

$config = getGoogleConfig();

if (!is_array($config)) {
    echo "[FAIL] getGoogleConfig() did not return an array.\n";
    exit(1);
}

$expectedKeys = [
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'GOOGLE_REFRESH_TOKEN',
    'GOOGLE_REDIRECT_URI'
];

foreach ($expectedKeys as $key) {
    if (!array_key_exists($key, $config)) {
        echo "[FAIL] Missing key '{$key}' in returned config.\n";
        exit(1);
    }
    echo "  [PASS] Key '{$key}' is present.\n";
}

// Ensure return values are strings
foreach ($config as $k => $v) {
    if (!is_string($v)) {
        echo "[FAIL] Value for '{$k}' is not a string.\n";
        exit(1);
    }
}
echo "  [PASS] All configuration values are strings.\n";

echo "[SUCCESS] Google configuration loader verified safely.\n";
exit(0);
