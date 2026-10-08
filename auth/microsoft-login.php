<?php
// auth/microsoft-login.php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/MicrosoftOAuthService.php';

startSession();

// If user is already authenticated, redirect to appropriate page
if (!empty($_SESSION['user_id'])) {
    if (!empty($_SESSION['must_change_password'])) {
        header("Location: " . BASE_URL . "/auth/change-password.php");
        exit;
    }
    $role = $_SESSION['user_role'] ?? 'faculty';
    $map  = ['faculty' => 'faculty', 'admin1' => 'admin1', 'admin2' => 'admin2', 'dean' => 'dean'];
    $dir  = $map[$role] ?? 'faculty';
    header("Location: " . BASE_URL . "/{$dir}/dashboard.php");
    exit;
}

$oauthService = new MicrosoftOAuthService();
$configCheck = $oauthService->validateConfig();

if (!$configCheck['valid']) {
    error_log("Microsoft Login Config Error: " . ($configCheck['error'] ?? 'Incomplete configuration'));
    header("Location: " . BASE_URL . "/auth/login.php?error=missing_config");
    exit;
}

// Generate secure CSRF state token and store in session
$state = $oauthService->generateState();
$_SESSION['microsoft_oauth_state'] = $state;

$authUrl = $oauthService->getAuthorizationUrl($state);

header("Location: " . $authUrl);
exit;
