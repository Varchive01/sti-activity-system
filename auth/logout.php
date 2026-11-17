<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

// If ?local=1 is explicitly passed (e.g. for testing or local administrative resets),
// override the auth method to local so the redirect proceeds directly to login.php.
if (isset($_GET['local']) && $_GET['local'] === '1') {
    startSession();
    $_SESSION['auth_method'] = 'local';
}

logout();
