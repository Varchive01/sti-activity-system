<?php
/**
 * scripts/migrate_onedrive_metadata.php
 *
 * Minimal, idempotent migration to add OneDrive metadata persistence columns
 * to the existing documents table (and task_deliverables).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "Running OneDrive metadata persistence migration...\n";

try {
    // 1. Check and add OneDrive metadata columns to `documents`
    $checkCol = $db->query("SHOW COLUMNS FROM `documents` LIKE 'onedrive_item_id'")->fetch();
    if (!$checkCol) {
        $db->exec("
            ALTER TABLE `documents`
            ADD COLUMN `onedrive_item_id` VARCHAR(255) DEFAULT NULL AFTER `file_path`,
            ADD COLUMN `onedrive_web_url` TEXT DEFAULT NULL AFTER `onedrive_item_id`,
            ADD COLUMN `onedrive_status` VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER `onedrive_web_url`,
            ADD COLUMN `onedrive_synced_at` DATETIME DEFAULT NULL AFTER `onedrive_status`,
            ADD COLUMN `onedrive_error` TEXT DEFAULT NULL AFTER `onedrive_synced_at`,
            ADD INDEX `idx_documents_onedrive_status` (`onedrive_status`),
            ADD INDEX `idx_documents_onedrive_item_id` (`onedrive_item_id`)
        ");
        echo "Added OneDrive metadata columns to 'documents' table.\n";
    } else {
        echo "OneDrive metadata columns already exist in 'documents' table.\n";
    }

    // 2. Check and add OneDrive metadata columns to `task_deliverables` if table exists
    $tableExists = $db->query("SHOW TABLES LIKE 'task_deliverables'")->fetch();
    if ($tableExists) {
        $checkDeliv = $db->query("SHOW COLUMNS FROM `task_deliverables` LIKE 'onedrive_item_id'")->fetch();
        if (!$checkDeliv) {
            $db->exec("
                ALTER TABLE `task_deliverables`
                ADD COLUMN `onedrive_item_id` VARCHAR(255) DEFAULT NULL AFTER `file_path`,
                ADD COLUMN `onedrive_web_url` TEXT DEFAULT NULL AFTER `onedrive_item_id`,
                ADD COLUMN `onedrive_status` VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER `onedrive_web_url`,
                ADD COLUMN `onedrive_synced_at` DATETIME DEFAULT NULL AFTER `onedrive_status`,
                ADD COLUMN `onedrive_error` TEXT DEFAULT NULL AFTER `onedrive_synced_at`,
                ADD INDEX `idx_deliv_onedrive_status` (`onedrive_status`)
            ");
            echo "Added OneDrive metadata columns to 'task_deliverables' table.\n";
        } else {
            echo "OneDrive metadata columns already exist in 'task_deliverables' table.\n";
        }
    }

    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
