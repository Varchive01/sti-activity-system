<?php
$pdf = file_get_contents('includes/ProposalPdfService.php');
echo 'PDF has addSignatories: ' . (strpos($pdf, 'addSignatories') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'PDF has Project Author: ' . (strpos($pdf, 'Project Author') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'PDF has Endorsed: ' . (strpos($pdf, 'Endorsed') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'PDF has Dean: ' . (strpos($pdf, 'Dean') !== false ? 'YES' : 'NO') . PHP_EOL;

$view = file_get_contents('faculty/proposal-view.php');
echo 'View has institutional-signatories-card: ' . (strpos($view, 'institutional-signatories-card') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'View has Project Proponents: ' . (strpos($view, 'Project Proponents') !== false ? 'YES' : 'NO') . PHP_EOL;

$p3 = file_get_contents('includes/phase3-post-event-details.php');
echo 'P3 has verify_status: ' . (strpos($p3, 'verify_status') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'P3 has Verified: ' . (strpos($p3, 'Verified') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'P3 has p3CanVerifyTasks: ' . (strpos($p3, 'p3CanVerifyTasks') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'P3 has Budget: ' . (strpos($p3, 'Budget') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'P3 has Under Budget: ' . (strpos($p3, 'Under Budget') !== false ? 'YES' : 'NO') . PHP_EOL;

$api = file_get_contents('api/task-assignment.php');
echo 'Task API has verify_task: ' . (strpos($api, 'verify_task') !== false ? 'YES' : 'NO') . PHP_EOL;
echo 'Task API has verified_correct: ' . (strpos($api, 'verified_correct') !== false ? 'YES' : 'NO') . PHP_EOL;
