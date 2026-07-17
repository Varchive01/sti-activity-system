<?php
session_start();

echo "<h2>Session Data</h2><pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Test requireRole manually</h2>";
$role = $_SESSION['user_role'] ?? 'NOT SET';
echo "Session role: <strong>" . $role . "</strong><br>";
echo "Expected: <strong>admin2</strong><br>";
echo "Match: <strong>" . ($role === 'admin2' ? 'YES ✅' : 'NO ❌') . "</strong><br>";
echo "strlen session role: " . strlen($role) . "<br>";
echo "strlen expected: " . strlen('admin2') . "<br>";
echo "Hex dump: ";
for ($i = 0; $i < strlen($role); $i++) echo dechex(ord($role[$i])) . ' ';
echo "<br>";

echo "<h2>Which page gives Access Denied?</h2>";
echo "Tell me the exact URL you are visiting.<br>";
