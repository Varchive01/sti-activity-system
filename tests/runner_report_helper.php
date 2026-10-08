<?php
// Test runner helper for dean/generate-report.php
$raw = stream_get_contents(STDIN);
$data = json_decode($raw, true) ?: [];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($data['session'])) {
    $_SESSION = $data['session'];
    $_SESSION['last_activity'] = time();
} else {
    $_SESSION = [];
}

$_GET = $data['get'] ?? [];
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/../dean/generate-report.php';
