<?php
/**
 * scripts/init_admin.php
 *
 * Secure CLI Initializer for the First Dean Account in STI-ARMES.
 *
 * Requirements:
 * - Must be executed via Command Line Interface (CLI) only.
 * - Rejects any web/browser execution with HTTP 403.
 * - Refuses execution if any Dean or Admin 2 account already exists.
 * - Prompts interactively for Name, Email, and Department.
 * - Generates a cryptographically secure random one-time password via random_bytes().
 * - Hashes password using password_hash(..., PASSWORD_DEFAULT).
 * - Sets must_change_password = 1.
 * - Wraps creation in a database transaction.
 * - Displays generated temporary password once in the terminal.
 * - Never logs or persists the plaintext password.
 */

// 1. Strict CLI Execution Check
if (PHP_SAPI !== 'cli' && php_sapi_name() !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Access Denied: This script can only be executed from the command line (CLI).\n";
    exit(1);
}

// 2. Load Database Configuration (no hardcoded credentials)
require_once __DIR__ . '/../config/database.php';

// Allow isolated test database connection via environment variable (for verification without altering real data)
$db = null;
if (isset($customPdo) && $customPdo instanceof PDO) {
    $db = $customPdo;
} else {
    $testDbName = getenv('STI_TEST_DB');
    if ($testDbName !== false && $testDbName !== '') {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . $testDbName . ";charset=" . DB_CHARSET;
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } else {
        $db = getDB();
    }
}

echo "======================================================\n";
echo " STI-ARMES: Initial Dean Account Setup (CLI)\n";
echo "======================================================\n\n";

// 3. Guard: Check if a Dean or Admin 2 account already exists
try {
    $checkStmt = $db->query("SELECT id, name, email, role FROM users WHERE role IN ('dean', 'admin2')");
    $existingPrivileged = $checkStmt->fetchAll();

    if (!empty($existingPrivileged)) {
        echo "[REFUSED] Privileged account(s) already exist in the system.\n";
        echo "This initializer is only permitted for initial setup when no Dean or Admin 2 accounts exist.\n\n";
        echo "Found " . count($existingPrivileged) . " existing privileged account(s):\n";
        foreach ($existingPrivileged as $acc) {
            echo " - " . strtoupper($acc['role']) . ": " . $acc['name'] . " (" . $acc['email'] . ")\n";
        }
        echo "\nTo manage users, please sign in with an existing administrator account.\n";
        exit(1);
    }
} catch (PDOException $e) {
    echo "[ERROR] Failed to query existing accounts. Ensure the database is initialized.\n";
    exit(1);
}

// Helper function to read input interactively or from piped STDIN
function readCliInput(string $promptMessage, bool $required = true, ?callable $validator = null): string {
    while (true) {
        echo $promptMessage;
        $raw = fgets(STDIN);
        if ($raw === false) {
            if (!$required) {
                return '';
            }
            echo "\n[ERROR] End of input reached without providing required data.\n";
            exit(1);
        }
        $val = trim($raw);
        if ($required && $val === '') {
            echo " [!] This field is required. Please try again.\n";
            continue;
        }
        if ($validator !== null && $val !== '') {
            $err = $validator($val);
            if ($err !== null) {
                echo " [!] " . $err . " Please try again.\n";
                continue;
            }
        }
        return $val;
    }
}

// Support CLI flags (--name, --email, --department) if provided, otherwise prompt interactively
$cliOpts = getopt('', ['name:', 'email:', 'department:']);

// 4. Prompt for Initial Dean Details
// Name
if (!empty($cliOpts['name'])) {
    $name = trim($cliOpts['name']);
    echo "Dean Full Name: " . $name . "\n";
} else {
    $name = readCliInput("Enter Dean's Full Name: ", true, function(string $v) {
        if (strlen($v) < 2) return "Name must be at least 2 characters.";
        if (strlen($v) > 150) return "Name cannot exceed 150 characters.";
        return null;
    });
}

// Email
if (!empty($cliOpts['email'])) {
    $email = trim($cliOpts['email']);
    echo "Dean Email Address: " . $email . "\n";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "[ERROR] Invalid email format provided via --email flag.\n";
        exit(1);
    }
} else {
    $email = readCliInput("Enter Dean's STI Email: ", true, function(string $v) use ($db) {
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            return "Invalid email address format.";
        }
        $s = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $s->execute([$v]);
        if ($s->fetch()) {
            return "An account with this email address already exists.";
        }
        return null;
    });
}

// Department
if (!empty($cliOpts['department'])) {
    $department = trim($cliOpts['department']);
    echo "Department: " . $department . "\n";
} else {
    $department = readCliInput("Enter Department [Default: Administration]: ", false);
    if ($department === '') {
        $department = 'Administration';
    }
}

// Re-check email uniqueness
$emailCheckStmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$emailCheckStmt->execute([$email]);
if ($emailCheckStmt->fetch()) {
    echo "\n[ERROR] An account with email '{$email}' already exists.\n";
    exit(1);
}

// 5. Generate Cryptographically Secure One-Time Password using random_bytes()
function generateOneTimePassword(int $length = 16): string {
    // High-entropy alphabet avoiding ambiguous characters (0, O, 1, l, I)
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%&*';
    $max = strlen($chars) - 1;
    $bytes = random_bytes($length);
    $pwd = '';
    for ($i = 0; $i < $length; $i++) {
        $pwd .= $chars[ord($bytes[$i]) % ($max + 1)];
    }
    return $pwd;
}

$oneTimePassword = generateOneTimePassword(16);

// 6. Hash Password with password_hash(..., PASSWORD_DEFAULT)
$hashedPassword = password_hash($oneTimePassword, PASSWORD_DEFAULT);

// 7 & 9. Database Transaction & Prepared Statement Insertion
try {
    $db->beginTransaction();

    // Secondary race-condition check within transaction
    $lockCheck = $db->query("SELECT id FROM users WHERE role IN ('dean', 'admin2') FOR UPDATE");
    if ($lockCheck->fetch()) {
        $db->rollBack();
        echo "\n[ERROR] A privileged account was created concurrently. Operation aborted.\n";
        exit(1);
    }

    $insertStmt = $db->prepare('
        INSERT INTO users (name, email, password, role, department, must_change_password)
        VALUES (?, ?, ?, ?, ?, 1)
    ');
    $insertStmt->execute([
        $name,
        $email,
        $hashedPassword,
        'dean',
        $department
    ]);

    $newId = (int)$db->lastInsertId();
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // Erase password variable immediately on failure
    $oneTimePassword = null;
    unset($oneTimePassword, $hashedPassword);

    echo "\n[ERROR] Failed to create initial Dean account. Database transaction was rolled back.\n";
    exit(1);
}

// 8 & 10. Display Success and One-Time Password ONCE in Terminal (Never logged or saved to file)
echo "\n======================================================\n";
echo " [SUCCESS] Initial Dean Account Created Successfully!\n";
echo "======================================================\n";
echo " User ID:              " . $newId . "\n";
echo " Name:                 " . $name . "\n";
echo " Email:                " . $email . "\n";
echo " Role:                 dean\n";
echo " Department:           " . $department . "\n";
echo " Must Change Password: Yes (1)\n";
echo "------------------------------------------------------\n";
echo " ONE-TIME TEMPORARY PASSWORD:\n";
echo " >>> " . $oneTimePassword . " <<<\n";
echo "------------------------------------------------------\n";
echo " SECURITY NOTICE:\n";
echo " - Store or convey this one-time password securely.\n";
echo " - It will NOT be shown again and is NOT stored anywhere in plaintext.\n";
echo " - The user will be required to change their password upon first sign-in.\n";
echo "======================================================\n\n";

// Erase plaintext password from memory
$oneTimePassword = null;
unset($oneTimePassword, $hashedPassword);
exit(0);
