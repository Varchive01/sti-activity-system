<?php
/**
 * tests/test_collaborator_dropdown_roster.php
 *
 * Verification suite for:
 * Fix Collaborator Faculty Dropdown — Official STI Organizational Charts (28 Names)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$baseUrl = 'http://localhost/sti-activity-system';
$db = getDB();

$passed = 0;
$failed = 0;

function assertCheck(bool $condition, string $description): void {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "======================================================================\n";
echo "  COLLABORATOR FACULTY DROPDOWN (28 ROSTER AUDIT)\n";
echo "======================================================================\n\n";

// Expected 28 official names by department
$expectedRoster = [
    'IT Department' => [
        'Dave Lord M. Rubaya',
        'Michelle Carlos',
        'Ar-Jay C. Agbayani',
        'Emilou G. Magnaye',
        'John Cedrick Agapito',
        'Jedekiah Veloso',
        'Shintaro Lagare',
        'Geo V. De Leon',
        'Johanna Kristel T. Montejo',
        'Felmar Solbita',
        'Michael André Sayson'
    ],
    'THM Department' => [
        'Aldwin D. Alminar',
        'Andrea J. Gatbonton',
        'Divine Grace E. Ibarroll',
        'Mary Darlene A. Mariano',
        'Julieta Caridad C. Cruz',
        'Exequiel L. Magnipis',
        'Frankie Chiu'
    ],
    'G.E. Department' => [
        'Tristan S. Unabia',
        'Ian Jade C. Barangan',
        'Ulysses Neil Patrick B. Edquilang',
        'Gregg Anthony J. Rana',
        'Ricky E. Manliguis',
        'Regina Kate D. Dominguez',
        'Benedicto A. Arnoco',
        'Jennilyn Q. Abarra',
        'Ruby Ann G. Dasalla',
        'Perine O. Dela Cruz'
    ]
];

$all28Names = [];
foreach ($expectedRoster as $dept => $names) {
    foreach ($names as $n) $all28Names[] = $n;
}

echo "--- TEST GROUP 1: Direct Function Call (getEligibleFacultyForDropdown) ---\n";

$facultyList = getEligibleFacultyForDropdown($db, []);
assertCheck(count($facultyList) === 28, "Dropdown returns exactly 28 names (got " . count($facultyList) . ")");

$returnedNames = array_column($facultyList, 'name');
$returnedDepts = array_column($facultyList, 'department');

// 1. Check all 28 names are present
$missingNames = array_diff($all28Names, $returnedNames);
assertCheck(empty($missingNames), "All 28 names from official org charts are represented (missing: " . implode(', ', $missingNames) . ")");

// 2. Check Dr. Frederic D. Yulo is strictly excluded
$hasYulo = false;
foreach ($facultyList as $f) {
    if (stripos($f['name'], 'Frederic') !== false || stripos($f['name'], 'Yulo') !== false) {
        $hasYulo = true;
    }
}
assertCheck(!$hasYulo, "Dr. Frederic D. Yulo is completely excluded from the dropdown");

// 3. Check zero duplicate names
assertCheck(count($returnedNames) === count(array_unique($returnedNames)), "Zero duplicate names in the dropdown");

// 4. Check zero test accounts
$hasTestAccounts = false;
foreach ($facultyList as $f) {
    if (stripos($f['name'], 'test') !== false || stripos($f['name'], 'unrelated') !== false || stripos($f['name'], 'bad email') !== false) {
        $hasTestAccounts = true;
    }
}
assertCheck(!$hasTestAccounts, "Zero test, dummy, or unrelated accounts present");

echo "\n--- TEST GROUP 2: Department Grouping & Labeling ---\n";

$deptCounts = array_count_values($returnedDepts);
assertCheck(($deptCounts['IT Department'] ?? 0) === 11, "IT Department contains exactly 11 faculty members");
assertCheck(($deptCounts['THM Department'] ?? 0) === 7, "THM Department contains exactly 7 faculty members");
assertCheck(($deptCounts['G.E. Department'] ?? 0) === 10, "G.E. Department contains exactly 10 faculty members");

echo "\n--- TEST GROUP 3: Eligibility & Disabled Option Classifications ---\n";

foreach ($facultyList as $f) {
    $name = $f['name'];

    // Selectable accounts: Dave Lord M. Rubaya, Aldwin D. Alminar, Tristan S. Unabia
    if ($name === 'Dave Lord M. Rubaya') {
        assertCheck($f['disabled'] === false && !empty($f['id']), "Dave Lord M. Rubaya is SELECTABLE with valid ID {$f['id']}");
        assertCheck($f['status'] === 'eligible', "Dave Lord M. Rubaya status is 'eligible'");
    }
    if ($name === 'Aldwin D. Alminar') {
        assertCheck($f['disabled'] === false && !empty($f['id']), "Aldwin D. Alminar is SELECTABLE with valid ID {$f['id']}");
        assertCheck($f['status'] === 'eligible', "Aldwin D. Alminar status is 'eligible'");
    }
    if ($name === 'Tristan S. Unabia') {
        assertCheck($f['disabled'] === false && !empty($f['id']), "Tristan S. Unabia is SELECTABLE with valid ID {$f['id']}");
        assertCheck($f['status'] === 'eligible', "Tristan S. Unabia status is 'eligible'");
    }

    // Ineligible accounts: Ar-Jay C. Agbayani (admin1), Ian Jade C. Barangan (admin2)
    if ($name === 'Ar-Jay C. Agbayani') {
        assertCheck($f['disabled'] === true && $f['id'] === null, "Ar-Jay C. Agbayani is DISABLED with null ID");
        assertCheck($f['status_label'] === 'Not Eligible', "Ar-Jay C. Agbayani labeled 'Not Eligible'");
        assertCheck(str_contains($f['display_text'], 'Not Eligible'), "Ar-Jay C. Agbayani display text contains 'Not Eligible'");
    }
    if ($name === 'Ian Jade C. Barangan') {
        assertCheck($f['disabled'] === true && $f['id'] === null, "Ian Jade C. Barangan is DISABLED with null ID");
        assertCheck($f['status_label'] === 'Not Eligible', "Ian Jade C. Barangan labeled 'Not Eligible'");
        assertCheck(str_contains($f['display_text'], 'Not Eligible'), "Ian Jade C. Barangan display text contains 'Not Eligible'");
    }

    // Missing accounts (e.g. Michelle Carlos, Emilou G. Magnaye)
    if ($name === 'Michelle Carlos') {
        assertCheck($f['disabled'] === true && $f['id'] === null, "Michelle Carlos is DISABLED with null ID");
        assertCheck($f['status_label'] === 'Account Not Registered', "Michelle Carlos labeled 'Account Not Registered'");
        assertCheck(str_contains($f['display_text'], 'Account Not Registered'), "Michelle Carlos display text contains 'Account Not Registered'");
    }
    if ($name === 'Divine Grace E. Ibarroll') {
        assertCheck($f['disabled'] === true && $f['id'] === null, "Divine Grace E. Ibarroll is DISABLED with null ID");
        assertCheck($f['status_label'] === 'Account Not Registered', "Divine Grace E. Ibarroll labeled 'Account Not Registered'");
    }
    if ($name === 'Ulysses Neil Patrick B. Edquilang') {
        assertCheck($f['disabled'] === true && $f['id'] === null, "Ulysses Neil Patrick B. Edquilang is DISABLED with null ID");
        assertCheck($f['status_label'] === 'Account Not Registered', "Ulysses Neil Patrick B. Edquilang labeled 'Account Not Registered'");
    }
}

echo "\n--- TEST GROUP 4: Exclusion of Already Added Collaborators ---\n";

// Dave Lord Rubaya ID is 599. When 599 is in $excludeIds:
$excludedList = getEligibleFacultyForDropdown($db, [599]);
assertCheck(count($excludedList) === 28, "List still has all 28 names when a collaborator is assigned");
foreach ($excludedList as $f) {
    if ($f['name'] === 'Dave Lord M. Rubaya') {
        assertCheck($f['disabled'] === true && $f['id'] === null, "Dave Lord M. Rubaya is now DISABLED with null ID when assigned");
        assertCheck($f['status_label'] === 'Already Assigned', "Dave Lord M. Rubaya labeled 'Already Assigned'");
    }
}

echo "\n--- TEST GROUP 5: Search Filtering ---\n";

$searchIT = getEligibleFacultyForDropdown($db, [], 'IT Department');
assertCheck(count($searchIT) === 11, "Search for 'IT Department' returns exactly 11 faculty");

$searchMichelle = getEligibleFacultyForDropdown($db, [], 'Michelle');
assertCheck(count($searchMichelle) === 1 && $searchMichelle[0]['name'] === 'Michelle Carlos', "Search for 'Michelle' returns Michelle Carlos");

echo "\n======================================================================\n";
echo "  AUDIT SUMMARY: {$passed} PASSED | {$failed} FAILED\n";
echo "======================================================================\n";

if ($failed > 0) {
    exit(1);
}

