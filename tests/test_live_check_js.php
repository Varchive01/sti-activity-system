<?php
$c = file_get_contents('https://sti-armes.freedev.app/assets/js/ai-proposal-guidance.js');
echo 'Live ai-proposal-guidance.js Length: ' . strlen($c) . PHP_EOL;
echo 'Has fRegularAwards: ' . (strpos($c, 'fRegularAwards') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'Has hydrateCombinedCriteriaListFromTextareas: ' . (strpos($c, 'hydrateCombinedCriteriaListFromTextareas') !== false ? 'YES' : 'NO') . PHP_EOL;

$p = @file_get_contents('https://sti-armes.freedev.app/includes/phase2-proposal-enhancements.php');
echo 'Live phase2-proposal-enhancements.php Status: ' . (empty($p) ? 'NOT FOUND / EMPTY' : 'EXISTS (' . strlen($p) . ' bytes)') . PHP_EOL;
