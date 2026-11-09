<?php
/**
 * tests/test_issue9_dashboard_summary_cards.php
 *
 * Verifies Issue 9:
 * - 'Action Required' card removed
 * - Separate 'Returned' and 'Rejected' proposal cards
 * - All cards aligned in a single horizontal row without wrapping
 * - Verified for both Faculty and Program Head dashboards
 */

echo "=== Issue 9: Dashboard Summary Cards Alignment & Separation ===\n\n";

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

// 1. Inspect faculty/dashboard.php
$facCode = file_get_contents(__DIR__ . '/../faculty/dashboard.php');
checkTest("faculty/dashboard.php removes 'Action Required' summary card",
    strpos($facCode, 'ACTION REQUIRED') === false
);
checkTest("faculty/dashboard.php has dedicated Returned proposals card linking to returned.php",
    strpos($facCode, 'faculty/returned.php') !== false &&
    strpos($facCode, 'stat-card-revision') !== false &&
    strpos($facCode, "s['revisions']") !== false
);
checkTest("faculty/dashboard.php has dedicated Rejected proposals card linking to rejected.php",
    strpos($facCode, 'faculty/rejected.php') !== false &&
    strpos($facCode, 'stat-card-rejected') !== false &&
    strpos($facCode, "s['rejected']") !== false
);
checkTest("faculty/dashboard.php wraps stat-grid in stat-row-wrapper for horizontal alignment",
    strpos($facCode, 'class="stat-row-wrapper"') !== false
);
checkTest("faculty/dashboard.php configures 7 columns (repeat(7, ...)) in stat-grid",
    strpos($facCode, 'repeat(7,') !== false
);

// 2. Inspect program_head/dashboard.php
$phCode = file_get_contents(__DIR__ . '/../program_head/dashboard.php');
checkTest("program_head/dashboard.php has dedicated Returned proposals card linking to returned.php",
    strpos($phCode, 'faculty/returned.php') !== false &&
    strpos($phCode, 'stat-card-returned') !== false &&
    strpos($phCode, 'returnedProposals') !== false
);
checkTest("program_head/dashboard.php has dedicated Rejected proposals card linking to rejected.php",
    strpos($phCode, 'faculty/rejected.php') !== false &&
    strpos($phCode, 'stat-card-rejected') !== false &&
    strpos($phCode, 'rejectedProposals') !== false
);
checkTest("program_head/dashboard.php wraps stat-grid in stat-row-wrapper for horizontal alignment",
    strpos($phCode, 'class="stat-row-wrapper"') !== false
);
checkTest("program_head/dashboard.php configures 7 columns (repeat(7, ...)) in stat-grid",
    strpos($phCode, 'repeat(7,') !== false
);

// 3. Verify single horizontal row protection (no media query wrapping stat-grid into 2 or 3 columns)
checkTest("faculty/dashboard.php has no media query forcing stat-grid into multiple lines",
    preg_match('/@media[^{]+\{[^}]*\.stat-grid\s*\{[^}]*repeat\([123],/s', $facCode) === 0
);
checkTest("program_head/dashboard.php has no media query forcing stat-grid into multiple lines",
    preg_match('/@media[^{]+\{[^}]*\.stat-grid\s*\{[^}]*repeat\([123],/s', $phCode) === 0
);

echo "\nResult: " . ($allPassed ? "ALL 11 CHECKS PASSED!" : "SOME CHECKS FAILED") . "\n";
exit($allPassed ? 0 : 1);

