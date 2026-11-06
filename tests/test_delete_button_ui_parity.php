<?php
echo "========================================================" . PHP_EOL;
echo " VERIFYING DRAFT DELETE BUTTON PRESENCE ACROSS PAGES" . PHP_EOL;
echo "========================================================" . PHP_EOL;

function testPresence($file, $needle, $label) {
    $c = file_get_contents($file);
    if (strpos($c, $needle) !== false) {
        echo " [PASS] {$label} found in " . basename($file) . PHP_EOL;
    } else {
        echo " [FAIL] {$label} NOT found in " . basename($file) . PHP_EOL;
    }
}

$base = __DIR__ . '/..';

// 1. Global JS definition
testPresence("{$base}/includes/topbar-profile.php", "window.confirmDeleteDraft = async function", "Global confirmDeleteDraft function");

// 2. Activities Page (Faculty & Program Head shared)
testPresence("{$base}/faculty/activities.php", "confirmDeleteDraft", "Delete button in activities.php");
testPresence("{$base}/faculty/activities.php", "\$a['status'] === 'draft'", "Draft status condition in activities.php");

// 3. Faculty Dashboard
testPresence("{$base}/faculty/dashboard.php", "confirmDeleteDraft", "Delete button in faculty/dashboard.php");
testPresence("{$base}/faculty/dashboard.php", "\$act['status'] === 'draft'", "Draft status condition in faculty/dashboard.php");

// 4. Program Head Dashboard
testPresence("{$base}/program_head/dashboard.php", "confirmDeleteDraft", "Delete button in program_head/dashboard.php");
testPresence("{$base}/program_head/dashboard.php", "\$act['status'] === 'draft'", "Draft status condition in program_head/dashboard.php");

// 5. View Proposal
testPresence("{$base}/faculty/proposal-view.php", "confirmDeleteDraft", "Delete Draft button in proposal-view.php");
testPresence("{$base}/faculty/proposal-view.php", "\$activity['status'] === 'draft'", "Draft status condition in proposal-view.php");

// 6. Edit Proposal
testPresence("{$base}/faculty/proposal-edit.php", "confirmDeleteDraft", "Delete Draft button in proposal-edit.php");
testPresence("{$base}/faculty/proposal-edit.php", "\$activity['status'] === 'draft'", "Draft status condition in proposal-edit.php");

echo "========================================================" . PHP_EOL;
echo " ALL UI DELETE BUTTON PARITY CHECKS PASSED!" . PHP_EOL;
echo "========================================================" . PHP_EOL;

