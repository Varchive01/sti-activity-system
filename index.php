<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
startSession();
if (!empty($_SESSION['user_id'])) {
    $map = ['faculty'=>'faculty','admin1'=>'admin1','admin2'=>'admin2','dean'=>'dean','program_head'=>'program_head'];
    $dir = $map[$_SESSION['user_role']] ?? 'faculty';
    header("Location: " . BASE_URL . "/{$dir}/dashboard.php"); exit;
}
header("Location: " . BASE_URL . "/auth/login.php"); exit;
