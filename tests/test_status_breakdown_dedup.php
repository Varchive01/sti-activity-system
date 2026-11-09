<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "=== TEST: Canonical Activity Status Deduplication ===\n";

// Test 1: Canonical helper
$t1_sub = canonicalizeActivityStatus('submitted');
$t1_ur = canonicalizeActivityStatus('under_review');
$t1_app = canonicalizeActivityStatus('approved');

assert($t1_sub === 'under_review', "Expected 'submitted' to canonicalize to 'under_review', got: $t1_sub");
assert($t1_ur === 'under_review', "Expected 'under_review' to stay 'under_review', got: $t1_ur");
assert($t1_app === 'approved', "Expected 'approved' to stay 'approved', got: $t1_app");
echo "✓ Test 1 Passed: canonicalizeActivityStatus() works correctly.\n";

// Test 2: Consolidation logic simulation
$mockRaw = [
    ['status' => 'submitted', 'cnt' => 1],
    ['status' => 'under_review', 'cnt' => 1],
    ['status' => 'approved', 'cnt' => 3],
    ['status' => 'completed', 'cnt' => 2],
];

$consolidated = [];
foreach ($mockRaw as $bs) {
    $canonical = canonicalizeActivityStatus($bs['status'] ?? '');
    if (!isset($consolidated[$canonical])) {
        $consolidated[$canonical] = [
            'status' => $canonical,
            'cnt'    => 0
        ];
    }
    $consolidated[$canonical]['cnt'] += (int)($bs['cnt'] ?? 0);
}
usort($consolidated, fn($a, $b) => $b['cnt'] <=> $a['cnt']);
$result = array_values($consolidated);

echo "Consolidated status result:\n";
print_r($result);

assert(count($result) === 3, "Expected 3 distinct statuses, got " . count($result));
$urEntry = array_values(array_filter($result, fn($r) => $r['status'] === 'under_review'))[0] ?? null;
assert($urEntry !== null, "Under Review entry missing");
assert($urEntry['cnt'] === 2, "Expected Under Review count = 2, got: " . $urEntry['cnt']);
echo "✓ Test 2 Passed: Mock data successfully consolidated duplicate Under Review counts to 2.\n";

// Test 3: Badge generation parity
$badgeSub = getStatusBadge('submitted');
$badgeUr = getStatusBadge('under_review');
echo "Badge for 'submitted': $badgeSub\n";
echo "Badge for 'under_review': $badgeUr\n";
assert($badgeSub === $badgeUr, "Both should render identical badges");
echo "✓ Test 3 Passed: Both raw statuses output identical Under Review badge markup.\n";

echo "ALL DEDUPLICATION TESTS PASSED!\n";

