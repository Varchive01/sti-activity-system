<?php
require_once __DIR__ . '/../../config/database.php';
$db = getDB();

echo "Running Migration: Add billing_unit and rate to materials...\n";
try {
    $checkUnit = $db->query("SHOW COLUMNS FROM materials LIKE 'billing_unit'")->fetch();
    if (!$checkUnit) {
        $db->exec("ALTER TABLE materials ADD COLUMN billing_unit VARCHAR(50) DEFAULT 'fixed' AFTER provider");
        echo "[SUCCESS] Added 'billing_unit' column.\n";
    } else {
        echo "[INFO] 'billing_unit' column already exists.\n";
    }

    $checkRate = $db->query("SHOW COLUMNS FROM materials LIKE 'rate'")->fetch();
    if (!$checkRate) {
        $db->exec("ALTER TABLE materials ADD COLUMN rate DECIMAL(10,2) DEFAULT 0.00 AFTER billing_unit");
        echo "[SUCCESS] Added 'rate' column.\n";
    } else {
        echo "[INFO] 'rate' column already exists.\n";
    }
} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
}
