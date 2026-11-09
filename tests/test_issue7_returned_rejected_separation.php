<?php
// tests/test_issue7_returned_rejected_separation.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== Issue 7: Separation of Returned and Rejected Proposals ===\n\n";

$allPassed = true;

function checkTest(string $desc, bool $condition, string $detail = '') {
    global $allPassed;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
    } else {
        echo "  [FAIL] {$desc} " . ($detail ? "({$detail})" : "") . "\n";
        $allPassed = false;
    }
}

// 1. Check returned.php query isolation
$returnedCode = file_get_contents(__DIR__ . '/../faculty/returned.php');
checkTest("faculty/returned.php strictly filters returned_for_revision",
    strpos($returnedCode, "a.status = 'returned_for_revision'") !== false &&
    strpos($returnedCode, "a.status IN ('returned_for_revision', 'rejected')") === false
);
checkTest("faculty/returned.php provides Revise Proposal button",
    strpos($returnedCode, "Revise Proposal") !== false &&
    strpos($returnedCode, "proposal-edit.php?id=") !== false
);

// 2. Check rejected.php query isolation
$rejectedCode = file_get_contents(__DIR__ . '/../faculty/rejected.php');
checkTest("faculty/rejected.php exists and filters rejected",
    strpos($rejectedCode, "a.status = 'rejected'") !== false
);
checkTest("faculty/rejected.php has NO edit button (view only)",
    strpos($rejectedCode, "proposal-edit.php") === false &&
    strpos($rejectedCode, "View Details") !== false
);

// 3. Check sidebar navigation
$sidebarCode = file_get_contents(__DIR__ . '/../includes/sidebar.php');
checkTest("includes/sidebar.php has dedicated Returned for Revision link for faculty",
    strpos($sidebarCode, "'label' => 'Returned for Revision', 'href' => BASE_URL . '/faculty/returned.php'") !== false
);
checkTest("includes/sidebar.php has dedicated Rejected Proposals link for faculty",
    strpos($sidebarCode, "'label' => 'Rejected Proposals', 'href' => BASE_URL . '/faculty/rejected.php'") !== false
);
checkTest("includes/sidebar.php has rotate-ccw icon",
    strpos($sidebarCode, "'rotate-ccw'") !== false
);

// 4. Check faculty dashboard
$facDashCode = file_get_contents(__DIR__ . '/../faculty/dashboard.php');
checkTest("faculty/dashboard.php counts rejected proposals separately",
    strpos($facDashCode, "SUM(status='rejected') as rejected") !== false
);
checkTest("faculty/dashboard.php has Rejected Proposals stat card",
    strpos($facDashCode, "Rejected Proposals") !== false &&
    strpos($facDashCode, "faculty/rejected.php") !== false
);

// 5. Check program head dashboard
$progDashCode = file_get_contents(__DIR__ . '/../program_head/dashboard.php');
checkTest("program_head/dashboard.php counts rejected proposals separately",
    strpos($progDashCode, "as rejected") !== false
);
checkTest("program_head/dashboard.php has Rejected Proposals stat card",
    strpos($progDashCode, "Rejected Proposals") !== false &&
    strpos($progDashCode, "faculty/rejected.php") !== false
);

echo "\nResult: " . ($allPassed ? "ALL 10 CHECKS PASSED!" : "SOME CHECKS FAILED") . "\n";
exit($allPassed ? 0 : 1);
