<?php
/**
 * Script: apply_clickable_faculty_fix.php
 * Globally makes all 28 official faculty members clickable in the add faculty contributor dropdown.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDB();

// 1. Provision all missing official faculty members into the `users` table
$roster = getOfficialFacultyRoster();
$defaultPasswordHash = password_hash('password', PASSWORD_BCRYPT);

$existingUsers = $db->query("SELECT id, name, email, department, role FROM users")->fetchAll(PDO::FETCH_ASSOC);

$insertStmt = $db->prepare("
    INSERT INTO users (name, email, password, role, department, created_at)
    VALUES (?, ?, ?, 'faculty', ?, NOW())
");

$provisionedCount = 0;
foreach ($roster as $dept => $names) {
    foreach ($names as $officialName) {
        if (stripos($officialName, 'Frederic') !== false && stripos($officialName, 'Yulo') !== false) {
            continue;
        }

        $offKey = cleanFacultyNameKey($officialName);
        $found = false;

        foreach ($existingUsers as $u) {
            if (cleanFacultyNameKey($u['name']) === $offKey) {
                $found = true;
                break;
            }
            similar_text($offKey, cleanFacultyNameKey($u['name']), $pct);
            if ($pct >= 85) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $cleanParts = array_values(array_filter(explode(' ', preg_replace('/[^a-zA-Z\s]/', '', $officialName))));
            $firstName = strtolower($cleanParts[0] ?? 'faculty');
            $lastName = strtolower(end($cleanParts) ?? 'member');
            $baseEmail = $firstName . '.' . $lastName . '@marikina.sti.edu.ph';

            $email = $baseEmail;
            $counter = 1;
            $emailCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            while (true) {
                $emailCheck->execute([$email]);
                if ((int)$emailCheck->fetchColumn() === 0) break;
                $counter++;
                $email = $firstName . '.' . $lastName . $counter . '@marikina.sti.edu.ph';
            }

            $insertStmt->execute([$officialName, $email, $defaultPasswordHash, $dept]);
            $newId = (int)$db->lastInsertId();
            $existingUsers[] = [
                'id' => $newId,
                'name' => $officialName,
                'email' => $email,
                'department' => $dept,
                'role' => 'faculty'
            ];
            $provisionedCount++;
            echo "[PROVISIONED] {$officialName} ({$dept}) -> ID: {$newId}, Email: {$email}\n";
        }
    }
}
echo "Total newly provisioned faculty accounts: {$provisionedCount}\n";

// 2. Now let's update includes/functions.php with the ensureOfficialFacultyAccountsExist helper
$fnPath = dirname(__DIR__) . '/includes/functions.php';
$fn = file_get_contents($fnPath);

if (strpos($fn, 'function ensureOfficialFacultyAccountsExist') === false) {
    $ensureCode = <<<'CODE'
if (!function_exists('ensureOfficialFacultyAccountsExist')) {
    /**
     * Globally ensures all 28 official faculty members from the STI organizational charts
     * have active user accounts in the database so all names are clickable in contributor selection.
     */
    function ensureOfficialFacultyAccountsExist(?PDO $db = null): void {
        $db = $db ?? (function_exists('getDB') ? getDB() : null);
        if (!$db) return;

        static $alreadyEnsured = false;
        if ($alreadyEnsured) return;
        $alreadyEnsured = true;

        if (!function_exists('getOfficialFacultyRoster') || !function_exists('cleanFacultyNameKey')) {
            return;
        }

        $roster = getOfficialFacultyRoster();
        $defaultPasswordHash = password_hash('password', PASSWORD_BCRYPT);

        try {
            $stmt = $db->query("SELECT id, name, email, department, role FROM users");
            $existingUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return;
        }

        $insertStmt = $db->prepare("
            INSERT INTO users (name, email, password, role, department, created_at)
            VALUES (?, ?, ?, 'faculty', ?, NOW())
        ");

        foreach ($roster as $dept => $names) {
            foreach ($names as $officialName) {
                if (stripos($officialName, 'Frederic') !== false && stripos($officialName, 'Yulo') !== false) {
                    continue;
                }

                $offKey = cleanFacultyNameKey($officialName);
                $found = false;

                foreach ($existingUsers as $u) {
                    if (cleanFacultyNameKey($u['name']) === $offKey) {
                        $found = true;
                        break;
                    }
                    similar_text($offKey, cleanFacultyNameKey($u['name']), $pct);
                    if ($pct >= 85) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $cleanParts = array_values(array_filter(explode(' ', preg_replace('/[^a-zA-Z\s]/', '', $officialName))));
                    $firstName = strtolower($cleanParts[0] ?? 'faculty');
                    $lastName = strtolower(end($cleanParts) ?? 'member');
                    $baseEmail = $firstName . '.' . $lastName . '@marikina.sti.edu.ph';

                    $email = $baseEmail;
                    $counter = 1;
                    $emailCheck = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                    while (true) {
                        $emailCheck->execute([$email]);
                        if ((int)$emailCheck->fetchColumn() === 0) break;
                        $counter++;
                        $email = $firstName . '.' . $lastName . $counter . '@marikina.sti.edu.ph';
                    }

                    try {
                        $insertStmt->execute([$officialName, $email, $defaultPasswordHash, $dept]);
                        $newId = (int)$db->lastInsertId();
                        $existingUsers[] = [
                            'id' => $newId,
                            'name' => $officialName,
                            'email' => $email,
                            'department' => $dept,
                            'role' => 'faculty'
                        ];
                    } catch (\Throwable $e) {
                        error_log("Failed to insert faculty account {$officialName}: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

CODE;
    $targetPos = strpos($fn, "if (!function_exists('getEligibleFacultyForDropdown')) {");
    if ($targetPos !== false) {
        $fn = substr_replace($fn, $ensureCode . "\n", $targetPos, 0);
        echo "[UPDATED] Added ensureOfficialFacultyAccountsExist to functions.php\n";
    }
}

// In getEligibleFacultyForDropdown, ensure auto-provisioning is called
if (strpos($fn, 'ensureOfficialFacultyAccountsExist($db);') === false) {
    $fn = preg_replace(
        '/(function\s+getEligibleFacultyForDropdown\s*\([^)]*\)\s*:\s*array\s*\{[\s\r\n]*\$db\s*=\s*\$db\s*\?\?\s*\([^;]+;\s*if\s*\(!\$db\)\s*return\s*\[\];)/',
        "$1\r\n        if (function_exists('ensureOfficialFacultyAccountsExist')) {\r\n            ensureOfficialFacultyAccountsExist(\$db);\r\n        }",
        $fn
    );
    echo "[UPDATED] Hooked ensureOfficialFacultyAccountsExist into getEligibleFacultyForDropdown\n";
}

// In getEligibleFacultyForDropdown, allow admin1 and admin2
$fn = str_replace(
    "\$isEligibleRole = in_array(\$matchedUser['role'], ['faculty', 'program_head'], true);",
    "\$isEligibleRole = in_array(\$matchedUser['role'], ['faculty', 'program_head', 'admin1', 'admin2'], true);",
    $fn
);

// In addProposalCollaborator, allow admin1 and admin2
$fn = str_replace(
    "if (!in_array(\$targetUser['role'], ['faculty', 'program_head'], true)) {\n            return ['success' => false, 'error' => 'Only valid faculty members may be invited as proposal contributors.'];\n        }",
    "if (!in_array(\$targetUser['role'], ['faculty', 'program_head', 'admin1', 'admin2'], true)) {\n            return ['success' => false, 'error' => 'Only valid faculty members may be invited as proposal contributors.'];\n        }",
    $fn
);

file_put_contents($fnPath, $fn);
echo "[SAVED] includes/functions.php\n";

// 3. Update api/collaborators.php
$collabPath = dirname(__DIR__) . '/api/collaborators.php';
$collab = file_get_contents($collabPath);

// In temporary workspace add
$collab = str_replace(
    "if (!in_array(\$targetUser['role'], ['faculty', 'program_head'], true)) {\n            http_response_code(400);\n            echo json_encode(['success' => false, 'error' => 'Only faculty members and program heads can be added as proposal collaborators.']);\n            exit;\n        }",
    "if (!in_array(\$targetUser['role'], ['faculty', 'program_head', 'admin1', 'admin2'], true)) {\n            http_response_code(400);\n            echo json_encode(['success' => false, 'error' => 'Only faculty members and program heads can be added as proposal collaborators.']);\n            exit;\n        }",
    $collab
);

// In search_faculty action
if (strpos($collab, "ensureOfficialFacultyAccountsExist(\$db);") === false) {
    $collab = str_replace(
        "if (\$action === 'search_faculty') {\n    \$search = trim(\$_GET['q'] ?? '');",
        "if (\$action === 'search_faculty') {\n    \$search = trim(\$_GET['q'] ?? '');\n    if (function_exists('ensureOfficialFacultyAccountsExist')) {\n        ensureOfficialFacultyAccountsExist(\$db);\n    }",
        $collab
    );
    echo "[UPDATED] Hooked ensureOfficialFacultyAccountsExist into api/collaborators.php search_faculty\n";
}

file_put_contents($collabPath, $collab);
echo "[SAVED] api/collaborators.php\n";

echo "\nDone applying clickable faculty contributor fix!\n";
