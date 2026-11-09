<?php
$c = file_get_contents(__DIR__ . '/../api/proposal-save.php');
preg_match_all('/header\s*\(\s*["\']Location:[^;]+;/i', $c, $m);
echo "=== PROPOSAL-SAVE LOCATIONS ===\n";
print_r($m[0]);

$u = file_get_contents(__DIR__ . '/../api/proposal-update.php');
preg_match_all('/header\s*\(\s*["\']Location:[^;]+;/i', $u, $m2);
echo "=== PROPOSAL-UPDATE LOCATIONS ===\n";
print_r($m2[0]);

