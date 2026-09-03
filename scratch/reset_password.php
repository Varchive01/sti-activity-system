<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$hash = password_hash('password', PASSWORD_DEFAULT);
$stmt = $db->prepare("UPDATE users SET password = ? WHERE email = ?");
$stmt->execute([$hash, 'faculty@sti.edu']);
echo "Successfully reset password of faculty@sti.edu to 'password'\n";
