<?php
/**
 * database/migrations/migrate-collaborative-proposals.php
 *
 * Migration script for Task 14: Collaborative Proposal Preparation.
 * Creates `proposal_collaborators` and `proposal_contribution_logs` tables.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Forbidden: Migration scripts can only be executed via CLI.');
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$db = getDB();

echo "Starting Collaborative Proposals Database Migration...\n";

try {
    // 1. Create proposal_collaborators table
    echo "1. Creating proposal_collaborators table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS `proposal_collaborators` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `activity_id` INT(11) NOT NULL,
            `user_id` INT(11) NOT NULL,
            `role` ENUM('owner', 'co_author', 'contributor') NOT NULL DEFAULT 'contributor',
            `added_by` INT(11) NOT NULL,
            `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `status` ENUM('active', 'removed') NOT NULL DEFAULT 'active',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_activity_user` (`activity_id`, `user_id`),
            KEY `idx_collab_activity` (`activity_id`),
            KEY `idx_collab_user` (`user_id`),
            CONSTRAINT `fk_collab_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_collab_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "   -> proposal_collaborators table created or verified.\n";

    // 2. Create proposal_contribution_logs table
    echo "2. Creating proposal_contribution_logs table...\n";
    $db->exec("
        CREATE TABLE IF NOT EXISTS `proposal_contribution_logs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `activity_id` INT(11) NOT NULL,
            `user_id` INT(11) NOT NULL,
            `user_role` VARCHAR(50) NOT NULL DEFAULT 'contributor',
            `action` VARCHAR(100) NOT NULL,
            `section` VARCHAR(100) DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_contrib_activity` (`activity_id`, `created_at`),
            KEY `idx_contrib_user` (`user_id`),
            CONSTRAINT `fk_contrib_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_contrib_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "   -> proposal_contribution_logs table created or verified.\n";

    echo "\nMigration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

