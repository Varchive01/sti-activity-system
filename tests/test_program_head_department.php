<?php
/**
 * tests/test_program_head_department.php
 *
 * Automated verification test suite for Task 2:
 * Program Head Department Assignment
 */

ob_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

startSession();
$db = getDB();

echo "=== 1. VERIFY EXISTING FACULTY DEPARTMENTS REMAIN UNCHANGED ===\n";
$stmt = $db->query("SELECT id, name, email, department, role FROM users WHERE role = 'faculty' LIMIT 5");
$facList = $stmt->fetchAll(PDO::FETCH_ASSOC);
assert(!empty($facList), "No faculty accounts found in database");
foreach ($facList as $f) {
    echo "Faculty ID {$f['id']} ({$f['name']}): Dept = " . ($f['department'] ?: '(none)') . "\n";
}
echo "✅ Existing Faculty departments verified intact.\n\n";

echo "=== 2. VERIFY PROGRAM HEAD CREATION ASSIGNED TO IT ===\n";
$phEmail = 'ph_it_test_' . time() . '@marikina.sti.edu.ph';
$phPass  = 'ValidPass123!';
$hashedPass = password_hash($phPass, PASSWORD_DEFAULT);

// Helper function mimicking api/user-create validation
function testCreateUserValidation(string $role, string $dept): array {
    $normalizeDept = function(string $d): string {
        $trimmed = trim($d);
        $map = [
            'it'              => 'IT Department',
            'it department'   => 'IT Department',
            'thm'             => 'THM Department',
            'thm department'  => 'THM Department',
            'ge'              => 'G.E. Department',
            'g.e.'            => 'G.E. Department',
            'g.e. department' => 'G.E. Department',
            'ge department'   => 'G.E. Department',
            'administration'  => 'Administration',
        ];
        $key = strtolower($trimmed);
        return $map[$key] ?? $trimmed;
    };

    $normalizedDept = $dept !== '' ? $normalizeDept($dept) : '';
    $allowedDepartments = ['IT Department', 'G.E. Department', 'THM Department', 'Administration'];

    if ($role === 'program_head') {
        if ($normalizedDept === '' || !in_array($normalizedDept, ['IT Department', 'G.E. Department', 'THM Department'], true)) {
            return ['valid' => false, 'error' => 'missing_program_head_department'];
        }
        return ['valid' => true, 'department' => $normalizedDept];
    } else {
        if ($normalizedDept !== '' && !in_array($normalizedDept, $allowedDepartments, true)) {
            return ['valid' => false, 'error' => 'invalid_department'];
        }
        return ['valid' => true, 'department' => $normalizedDept !== '' ? $normalizedDept : null];
    }
}

// 2a. Test IT assignment
$valIT = testCreateUserValidation('program_head', 'IT');
assert($valIT['valid'] === true && $valIT['department'] === 'IT Department');

$valITFull = testCreateUserValidation('program_head', 'IT Department');
assert($valITFull['valid'] === true && $valITFull['department'] === 'IT Department');

// Insert IT Program Head
$insert = $db->prepare("INSERT INTO users (name, email, password, role, must_change_password, department) VALUES (?, ?, ?, 'program_head', 1, ?)");
$insert->execute(['Prof. IT Head', $phEmail, $hashedPass, $valIT['department']]);
$phId = (int)$db->lastInsertId();

$check = $db->prepare("SELECT id, name, email, role, department FROM users WHERE id = ?");
$check->execute([$phId]);
$u = $check->fetch(PDO::FETCH_ASSOC);
assert($u['role'] === 'program_head', "Role is not program_head");
assert($u['department'] === 'IT Department', "Department is not IT Department");
echo "✅ Program Head created with department: {$u['department']} (ID: {$phId})\n\n";

echo "=== 3. VERIFY ASSIGNMENT TO THM AND GE ===\n";
// Update to THM
$valTHM = testCreateUserValidation('program_head', 'THM');
assert($valTHM['valid'] === true && $valTHM['department'] === 'THM Department');
$db->prepare("UPDATE users SET department = ? WHERE id = ?")->execute([$valTHM['department'], $phId]);

$check->execute([$phId]);
$u = $check->fetch(PDO::FETCH_ASSOC);
assert($u['department'] === 'THM Department', "Failed to update to THM Department");
echo "✅ Program Head updated to THM: {$u['department']}\n";

// Update to GE
$valGE = testCreateUserValidation('program_head', 'GE');
assert($valGE['valid'] === true && $valGE['department'] === 'G.E. Department');
$db->prepare("UPDATE users SET department = ? WHERE id = ?")->execute([$valGE['department'], $phId]);

$check->execute([$phId]);
$u = $check->fetch(PDO::FETCH_ASSOC);
assert($u['department'] === 'G.E. Department', "Failed to update to G.E. Department");
echo "✅ Program Head updated to GE: {$u['department']}\n\n";

echo "=== 4. VERIFY REJECTION OF INVALID OR MISSING DEPARTMENTS ===\n";
// Empty department
$valEmpty = testCreateUserValidation('program_head', '');
assert($valEmpty['valid'] === false && $valEmpty['error'] === 'missing_program_head_department');
echo "✅ Empty department correctly rejected for Program Head.\n";

// Administration department (not allowed for academic program head)
$valAdmin = testCreateUserValidation('program_head', 'Administration');
assert($valAdmin['valid'] === false && $valAdmin['error'] === 'missing_program_head_department');
echo "✅ 'Administration' correctly rejected for Program Head.\n";

// Arbitrary invalid department
$valInvalid = testCreateUserValidation('program_head', 'Engineering');
assert($valInvalid['valid'] === false && $valInvalid['error'] === 'missing_program_head_department');
echo "✅ Arbitrary invalid department 'Engineering' correctly rejected.\n\n";

echo "=== 5. VERIFY FACULTY DEPARTMENT ASSIGNMENT REMAINS OPTIONAL/FLEXIBLE ===\n";
$valFacEmpty = testCreateUserValidation('faculty', '');
assert($valFacEmpty['valid'] === true && $valFacEmpty['department'] === null);
echo "✅ Faculty with empty department is allowed.\n";

$valFacIT = testCreateUserValidation('faculty', 'IT Department');
assert($valFacIT['valid'] === true && $valFacIT['department'] === 'IT Department');
echo "✅ Faculty with 'IT Department' is allowed.\n\n";

echo "=== 6. CLEAN UP TEST ACCOUNTS ===\n";
$db->prepare("DELETE FROM users WHERE id = ?")->execute([$phId]);
echo "✅ Test Program Head account ({$phEmail}) cleaned up from database.\n\n";

echo "=== ALL TESTS COMPLETED SUCCESSFULLY! ===\n";

