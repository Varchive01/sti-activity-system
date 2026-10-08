<?php
// Test runner helper for document-download.php
$raw = stream_get_contents(STDIN);
$data = json_decode($raw, true) ?: [];

if (isset($data['session'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION = $data['session'];
    $_SESSION['last_activity'] = time();
} else {
    $_SESSION = [];
}

$_GET = $data['get'] ?? [];
$_SERVER['REQUEST_METHOD'] = 'GET';

require __DIR__ . '/../api/document-download.php';
