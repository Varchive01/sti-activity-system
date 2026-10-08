<?php
/**
 * Migration script: Fix Objective 5 referential integrity for
 * notifications.activity_id and proposal_ai_validation.proposal_id.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDB();

echo "Starting Referential Integrity Migration...\n";

// 1. Safely handle orphaned notifications
echo "1. Handling orphaned notifications...\n";
$orphNotifsStmt = $db->query("
    SELECT COUNT(*) FROM notifications n 
    LEFT JOIN activities a ON n.activity_id = a.id 
    WHERE n.activity_id IS NOT NULL AND a.id IS NULL
");
$orphNotifsCount = (int)$orphNotifsStmt->fetchColumn();

if ($orphNotifsCount > 0) {
    $updated = $db->exec("
        UPDATE notifications n 
        LEFT JOIN activities a ON n.activity_id = a.id 
        SET n.activity_id = NULL 
        WHERE n.activity_id IS NOT NULL AND a.id IS NULL
    ");
    echo "   -> Disassociated {$updated} orphaned notifications by setting activity_id = NULL (preserved message text and user history).\n";
} else {
    echo "   -> No orphaned notifications found.\n";
}

// 2. Safely archive and clean orphaned proposal_ai_validation records
echo "2. Handling orphaned proposal_ai_validation records...\n";
$orphAiStmt = $db->query("
    SELECT COUNT(*) FROM proposal_ai_validation pav 
    LEFT JOIN activities a ON pav.proposal_id = a.id 
    WHERE a.id IS NULL
");
$orphAiCount = (int)$orphAiStmt->fetchColumn();

if ($orphAiCount > 0) {
    // Create archive table so zero business data is destroyed
    $db->exec("
        CREATE TABLE IF NOT EXISTS `proposal_ai_validation_orphans_archive` (
            `id` int(11) NOT NULL,
            `proposal_id` int(11) NOT NULL,
            `is_complete` tinyint(1) NOT NULL DEFAULT 0,
            `is_aligned` tinyint(1) NOT NULL DEFAULT 0,
            `issues` text DEFAULT NULL,
            `suggestions` text DEFAULT NULL,
            `full_result` longtext DEFAULT NULL,
            `reviewed_by_human` tinyint(1) NOT NULL DEFAULT 0,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $archived = $db->exec("
        INSERT IGNORE INTO proposal_ai_validation_orphans_archive 
        (id, proposal_id, is_complete, is_aligned, issues, suggestions, full_result, reviewed_by_human, created_at, updated_at)
        SELECT pav.id, pav.proposal_id, pav.is_complete, pav.is_aligned, pav.issues, pav.suggestions, pav.full_result, pav.reviewed_by_human, pav.created_at, pav.updated_at
        FROM proposal_ai_validation pav
        LEFT JOIN activities a ON pav.proposal_id = a.id
        WHERE a.id IS NULL
    ");
    echo "   -> Archived {$archived} orphaned AI validation records into proposal_ai_validation_orphans_archive.\n";

    $deleted = $db->exec("
        DELETE pav FROM proposal_ai_validation pav
        JOIN proposal_ai_validation_orphans_archive arc ON pav.id = arc.id
    ");
    echo "   -> Cleaned {$deleted} orphaned records from active proposal_ai_validation table.\n";
} else {
    echo "   -> No orphaned proposal_ai_validation records found.\n";
}

// 3. Ensure index on notifications.activity_id
echo "3. Verifying index on notifications.activity_id...\n";
$idxCheck = $db->query("
    SHOW INDEX FROM notifications WHERE Column_name = 'activity_id'
")->fetchAll();

if (empty($idxCheck)) {
    $db->exec("ALTER TABLE notifications ADD KEY `idx_notifications_activity_id` (`activity_id`)");
    echo "   -> Added index idx_notifications_activity_id on notifications(activity_id).\n";
} else {
    echo "   -> Index on notifications(activity_id) already exists.\n";
}

// 4. Add Foreign Key for notifications.activity_id -> activities.id
echo "4. Adding Foreign Key for notifications.activity_id -> activities.id...\n";
$fkNotifCheck = $db->query("
    SELECT CONSTRAINT_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'notifications' 
      AND COLUMN_NAME = 'activity_id' 
      AND REFERENCED_TABLE_NAME = 'activities'
")->fetch();

if (!$fkNotifCheck) {
    $db->exec("
        ALTER TABLE notifications 
        ADD CONSTRAINT `fk_notifications_activity` 
        FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) 
        ON DELETE SET NULL ON UPDATE CASCADE
    ");
    echo "   -> Added foreign key fk_notifications_activity (ON DELETE SET NULL, ON UPDATE CASCADE).\n";
} else {
    echo "   -> Foreign key for notifications.activity_id already exists ({$fkNotifCheck['CONSTRAINT_NAME']}).\n";
}

// 5. Add Foreign Key for proposal_ai_validation.proposal_id -> activities.id
echo "5. Adding Foreign Key for proposal_ai_validation.proposal_id -> activities.id...\n";
$fkAiCheck = $db->query("
    SELECT CONSTRAINT_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'proposal_ai_validation' 
      AND COLUMN_NAME = 'proposal_id' 
      AND REFERENCED_TABLE_NAME = 'activities'
")->fetch();

if (!$fkAiCheck) {
    $db->exec("
        ALTER TABLE proposal_ai_validation 
        ADD CONSTRAINT `fk_proposal_ai_validation_proposal` 
        FOREIGN KEY (`proposal_id`) REFERENCES `activities` (`id`) 
        ON DELETE CASCADE ON UPDATE CASCADE
    ");
    echo "   -> Added foreign key fk_proposal_ai_validation_proposal (ON DELETE CASCADE, ON UPDATE CASCADE).\n";
} else {
    echo "   -> Foreign key for proposal_ai_validation.proposal_id already exists ({$fkAiCheck['CONSTRAINT_NAME']}).\n";
}

echo "\nMigration complete!\n";
