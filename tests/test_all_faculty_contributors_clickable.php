<?php
/**
 * Test Suite: Global Clickable Faculty Contributors Verification
 * Confirms that all 28 faculty members across IT, THM, and G.E. departments
 * are active, registered, and 100% clickable/selectable without disabled labels.
 */

$rootDir = dirname(__DIR__);
require_once $rootDir . '/config/config.php';
require_once $rootDir . '/config/database.php';
require_once $rootDir . '/includes/functions.php';

$db = getDB();
$results = [];

function recordTest($testName, $passed, $details = '') {
    global $results;
    $results[] = [
        'test' => $testName,
        'passed' => $passed,
        'details' => $details
    ];
}

// 1. Ensure accounts exist
ensureOfficialFacultyAccountsExist($db);

// 2. Fetch dropdown list
$dropdownList = getEligibleFacultyForDropdown($db, []);

$totalCount = count($dropdownList);
recordTest('Total Roster Count', ($totalCount === 28), "Found {$totalCount} faculty members (expected 28)");

$disabledList = array_filter($dropdownList, function($item) {
    return !empty($item['disabled']);
});
$disabledCount = count($disabledList);
recordTest('All Faculty Clickable (Zero Disabled)', ($disabledCount === 0), "{$disabledCount} disabled members found (expected 0)");

// 3. Check department grouping
$deptCounts = [];
foreach ($dropdownList as $item) {
    $dept = $item['department'];
    $deptCounts[$dept] = ($deptCounts[$dept] ?? 0) + 1;
}

$itCount = $deptCounts['IT Department'] ?? 0;
$thmCount = $deptCounts['THM Department'] ?? 0;
$geCount = $deptCounts['G.E. Department'] ?? 0;

recordTest('Department Balance: IT Department', ($itCount === 11), "{$itCount} members (expected 11)");
recordTest('Department Balance: THM Department', ($thmCount === 7), "{$thmCount} members (expected 7)");
recordTest('Department Balance: G.E. Department', ($geCount === 10), "{$geCount} members (expected 10)");

// 4. Check no "Account Not Registered" or "Not Eligible" labels in display_text
$invalidLabels = array_filter($dropdownList, function($item) {
    $text = $item['display_text'];
    return (strpos($text, 'Account Not Registered') !== false || strpos($text, 'Not Eligible') !== false);
});
$invalidCount = count($invalidLabels);
recordTest('Clean Display Text (No Disabled Warning Suffixes)', ($invalidCount === 0), "{$invalidCount} entries with disabled suffixes (expected 0)");

// 5. Check valid database user IDs
$missingId = array_filter($dropdownList, function($item) {
    return empty($item['id']) || (int)$item['id'] <= 0;
});
$missingIdCount = count($missingId);
recordTest('Valid Database User IDs for All 28 Members', ($missingIdCount === 0), "{$missingIdCount} members missing user ID (expected 0)");

// 6. Test specific previously disabled accounts:
// Ar-Jay Agbayani (Admin1), Ian Jade Barangan (Admin2), Michelle Carlos (Previously unprovisioned)
$namesToCheck = ['Ar-Jay C. Agbayani', 'Ian Jade C. Barangan', 'Michelle Carlos', 'Frankie Chiu', 'Ruby Ann G. Dasalla'];
foreach ($namesToCheck as $name) {
    $found = false;
    foreach ($dropdownList as $item) {
        if (strpos($item['name'], $name) !== false || $item['name'] === $name) {
            $found = !$item['disabled'] && !empty($item['id']);
            break;
        }
    }
    recordTest("Specific Check: {$name} is Clickable", $found, "Verified clickable with active user ID");
}

echo "=======================================================================\n";
echo "       GLOBAL FACULTY CONTRIBUTOR CLICKABILITY TEST SUITE\n";
echo "=======================================================================\n\n";

$allPassed = true;
foreach ($results as $res) {
    $status = $res['passed'] ? '[PASS]' : '[FAIL]';
    if (!$res['passed']) $allPassed = false;
    echo sprintf("%-6s | %-45s | %s\n", $status, $res['test'], $res['details']);
}

echo "\n-----------------------------------------------------------------------\n";
echo "Overall Result: " . ($allPassed ? "ALL TESTS PASSED (100% CLICKABLE)" : "FAILURES DETECTED") . "\n";
echo "=======================================================================\n";

exit($allPassed ? 0 : 1);

