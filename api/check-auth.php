<?php
/**
 * api/check-auth.php
 *
 * Lightweight authentication and connectivity verification endpoint.
 * Used by offline draft synchronization engine to verify internet reachability
 * and ensure an authenticated session before dispatching pending local drafts.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

startSession();
$user = currentUser();

if (!$user || empty($user['id'])) {
    http_response_code(401);
    echo json_encode([
        'authenticated' => false,
        'error'         => 'Session expired or unauthenticated.'
    ]);
    exit;
}

echo json_encode([
    'authenticated' => true,
    'user_id'       => (int)$user['id'],
    'role'          => $user['role'],
    'department'    => $user['dept'] ?? '',
    'name'          => $user['name'] ?? '',
    'server_time'   => date('Y-m-d H:i:s')
]);
exit;

