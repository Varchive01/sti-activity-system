<?php
/**
 * scripts/init_admin.php
 *
 * Secure CLI Initializer for the First Dean/Admin Account in STI-ARMES.
 *
 * Requirements:
 * - Must be executed via Command Line Interface (CLI) only.
 * - Rejects any web/browser execution with HTTP 403.
 * - Refuses execution if any Dean or Admin 2 account already exists.
 * - Supports direct database insertion (for environments with CLI/SSH access).
 * - Supports --sql flag to output an idempotent phpMyAdmin-compatible SQL snippet
 *   (for hosting environments without SSH/CLI access like InfinityFree).
 * - Generates a cryptographically secure random one-time password via random_bytes().
 * - Hashes password using password_hash(..., PASSWORD_DEFAULT).
 * - Sets must_change_password = 1.
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

// 2. Parse CLI Options
$cliOpts = getopt('', ['name:', 'email:', 'department:', 'sql', 'generate-sql', 'help']);

if (isset($cliOpts['help'])) {
    echo "========================================================================\n";
    echo "  STI-ARMES: First Administrator Initializer (CLI)\n";
    echo "========================================================================\n\n";
    echo "Usage:\n";
    echo "  php scripts/init_admin.php [OPTIONS]\n\n";
    echo "Options:\n";
    echo "  --name=<name>         Administrator's full name\n";
    echo "  --email=<email>       Administrator's email address\n";
    echo "  --department=<dept>   Department name (default: Administration)\n";
    echo "  --sql, --generate-sql Output an idempotent SQL snippet for phpMyAdmin\n";
    echo "                        (Use this for hosting without SSH like InfinityFree)\n";
    echo "  --help                Display this help documentation\n\n";
    exit(0);
}

$isSqlMode = isset($cliOpts['sql']) || isset($cliOpts['generate-sql']);

// 3. Helper to read input interactively or from piped STDIN
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

// 4. Connect to database if in direct execution mode (or optional validation)
require_once __DIR__ . '/../config/database.php';

$db = null;
if (isset($customPdo) && $customPdo instanceof PDO) {
    $db = $customPdo;
} else {
    try {
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
    } catch (Throwable $e) {
        if (!$isSqlMode) {
            echo "[ERROR] Database connection failed: " . $e->getMessage() . "\n";
            exit(1);
        }
        // In --sql mode, a live DB connection is optional (allows generating snippets offline)
        $db = null;
    }
}

echo "======================================================\n";
echo " STI-ARMES: Initial Dean Account Setup (CLI)\n";
echo " Mode: " . ($isSqlMode ? "phpMyAdmin SQL Export" : "Direct Database Insertion") . "\n";
echo "======================================================\n\n";

// 5. Guard: In direct insertion mode, check if a Dean or Admin 2 account already exists
if (!$isSqlMode && $db instanceof PDO) {
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
}

// 6. Prompt or resolve initial administrator details
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
        if (!$isSqlMode && $db instanceof PDO) {
            $s = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $s->execute([$v]);
            if ($s->fetch()) {
                return "An account with this email address already exists.";
            }
        }
        return null;
    });
}

// Department
if (!empty($cliOpts['department'])) {
    $department = trim($cliOpts['department']);
    echo "Department: " . $department . "\n";
} elseif ($isSqlMode) {
    $department = 'Administration';
    echo "Department: " . $department . "\n";
} else {
    $department = readCliInput("Enter Department [Default: Administration]: ", false);
    if ($department === '') {
        $department = 'Administration';
    }
}

// Re-check email uniqueness if DB connected and in direct mode
if (!$isSqlMode && $db instanceof PDO) {
    $emailCheckStmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $emailCheckStmt->execute([$email]);
    if ($emailCheckStmt->fetch()) {
        echo "\n[ERROR] An account with email '{$email}' already exists.\n";
        exit(1);
    }
}

// 7. Generate Cryptographically Secure One-Time Password using random_bytes()
function generateOneTimePassword(int $length = 16): string {
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
$hashedPassword = password_hash($oneTimePassword, PASSWORD_DEFAULT);

// 8. Handle Execution: Either SQL Generation (InfinityFree) or Direct Database Insertion
if ($isSqlMode) {
    // Escape string literals for SQL safety
    $escName = str_replace("'", "''", $name);
    $escEmail = str_replace("'", "''", $email);
    $escDept = str_replace("'", "''", $department);
    $escHash = str_replace("'", "''", $hashedPassword);

    // Build idempotent INSERT ... SELECT ... WHERE NOT EXISTS query
    $sqlSnippet = "-- STI-ARMES First Administrator Bootstrap (phpMyAdmin SQL tab)\n" .
        "INSERT INTO `users` (`name`, `email`, `password`, `role`, `must_change_password`, `department`)\n" .
        "SELECT '{$escName}', '{$escEmail}', '{$escHash}', 'dean', 1, '{$escDept}'\n" .
        "FROM DUAL\n" .
        "WHERE NOT EXISTS (\n" .
        "    SELECT 1 FROM `users` WHERE `role` IN ('dean', 'admin2')\n" .
        ");";

    echo "\n======================================================\n";
    echo " [SUCCESS] One-Time Password & SQL Snippet Generated!\n";
    echo "======================================================\n";
    echo " Target Email:         " . $email . "\n";
    echo " Assigned Role:        dean\n";
    echo " Must Change Password: Yes (1)\n";
    echo "------------------------------------------------------\n";
    echo " ONE-TIME TEMPORARY PASSWORD (copy now, shown ONCE):\n";
    echo " >>> " . $oneTimePassword . " <<<\n";
    echo "------------------------------------------------------\n";
    echo " PHPMYADMIN SQL SNIPPET (Copy and run in phpMyAdmin):\n";
    echo "------------------------------------------------------\n\n";
    echo $sqlSnippet . "\n\n";
    echo "------------------------------------------------------\n";
    echo " INSTRUCTIONS FOR INFINITYFREE DEPLOYMENT:\n";
    echo " 1. Open InfinityFree Control Panel -> phpMyAdmin.\n";
    echo " 2. Select your STI-ARMES database.\n";
    echo " 3. Click the 'SQL' tab, paste the snippet above, and click 'Go'.\n";
    echo "    (If a Dean or Admin already exists, 0 rows will be inserted).\n";
    echo " 4. Navigate to your deployed site: /auth/login.php\n";
    echo " 5. Sign in with '{$email}' and the one-time temporary password.\n";
    echo " 6. The system will immediately require you to choose a new permanent password.\n";
    echo "======================================================\n\n";

    // Erase plaintext password from memory
    $oneTimePassword = null;
    unset($oneTimePassword, $hashedPassword);
    exit(0);
}

// 9. Direct Database Insertion (for environments with CLI/SSH access)
try {
    $db->beginTransaction();

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
    $oneTimePassword = null;
    unset($oneTimePassword, $hashedPassword);

    echo "\n[ERROR] Failed to create initial Dean account. Database transaction was rolled back.\n";
    exit(1);
}

// 10. Display Success for Direct Insertion
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

$oneTimePassword = null;
unset($oneTimePassword, $hashedPassword);
exit(0);
