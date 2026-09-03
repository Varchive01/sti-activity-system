<?php
/**
 * mark-ai-reviewed.php
 *
 * REST JSON endpoint to mark AI-generated proposal validation as reviewed by a human reviewer.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai/proposal_validator.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

requireLogin();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid proposal ID.']);
    exit;
}

try {
    $success = markAiValidationReviewed($id);
    echo json_encode([
        'success'  => $success,
        'reviewed' => true
    ]);
} catch (Exception $e) {
    error_log("markAiReviewed Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Could not mark AI validation as reviewed.'
    ]);
}
