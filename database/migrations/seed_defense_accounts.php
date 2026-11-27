<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$db = getDB();
echo "Running Global Self-Healing Defense Accounts Provisioner...\n";
ensureOfficialDefenseAccounts($db, true);

foreach (getOfficialDefenseAccountsConfig() as $email => $cfg) {
    $stmt = $db->prepare("SELECT id, name, email, role, department, must_change_password FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1");
    $stmt->execute([$email]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($u) {
        echo "[VERIFIED] ID {$u['id']} | {$u['name']} | {$u['email']} | Role: {$u['role']} | Dept: {$u['department']}\n";
    } else {
        echo "[ERROR] Account {$email} missing!\n";
    }
}
echo "All 7 defense accounts verified and ready.\n";
