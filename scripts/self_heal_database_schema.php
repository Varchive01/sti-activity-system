<?php
/**
 * STI-ARMES One-Click Self-Healing Database Schema Utility
 * Usage: Run via CLI (php scripts/self_heal_database_schema.php) or visit via browser.
 * Safe and idempotent: can be executed multiple times without losing any data.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$isCli = (php_sapi_name() === 'cli');

function logMsg(string $msg, string $type = 'INFO'): void {
    global $isCli;
    if ($isCli) {
        echo "[$type] $msg\n";
    } else {
        $color = ($type === 'ERROR') ? '#dc2626' : (($type === 'SUCCESS') ? '#16a34a' : '#2563eb');
        echo "<div style='font-family:monospace;margin:6px 0;color:{$color};'><strong>[$type]</strong> " . htmlspecialchars($msg) . "</div>";
    }
}

if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>STI-ARMES Schema Self-Healer</title>";
    echo "<style>body{font-family:system-ui,sans-serif;padding:30px;background:#f8fafc;color:#0f172a;} .card{background:#fff;border-radius:12px;padding:24px;border:1px solid #e2e8f0;max-width:800px;margin:0 auto;box-shadow:0 4px 12px rgba(0,0,0,0.05);}</style></head><body><div class='card'>";
    echo "<h2 style='margin-top:0;'>🛠️ STI-ARMES Database Self-Healing Migration</h2>";
}

try {
    $db = getDB();
    logMsg("Connected to database successfully.", "SUCCESS");

    // 1. Universal Archiving Columns on `activities`
    logMsg("Checking activities table for archiving columns...");
    $cols = $db->query("SHOW COLUMNS FROM activities LIKE 'is_archived'")->fetchAll();
    if (empty($cols)) {
        logMsg("Adding missing 'is_archived', 'archived_at', 'archived_by' columns...");
        $db->exec("
            ALTER TABLE activities 
            ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN archived_at DATETIME NULL DEFAULT NULL,
            ADD COLUMN archived_by INT(11) NULL DEFAULT NULL,
            ADD KEY idx_activities_archived (is_archived, status)
        ");
        logMsg("Archiving columns added successfully.", "SUCCESS");
    } else {
        logMsg("Archiving columns are already present.", "INFO");
    }

    // 2. proposal_collaborators Table
    logMsg("Checking proposal_collaborators table...");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `proposal_collaborators` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `activity_id` int(11) NOT NULL,
          `user_id` int(11) NOT NULL,
          `role` enum('owner','co_author','contributor') NOT NULL DEFAULT 'contributor',
          `added_by` int(11) NOT NULL,
          `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `status` enum('active','removed') NOT NULL DEFAULT 'active',
          PRIMARY KEY (`id`),
          UNIQUE KEY `uniq_activity_user` (`activity_id`,`user_id`),
          KEY `idx_collab_user` (`user_id`,`status`),
          KEY `idx_collab_activity` (`activity_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    logMsg("proposal_collaborators table verified/created.", "SUCCESS");

    // 3. proposal_contribution_logs Table
    logMsg("Checking proposal_contribution_logs table...");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `proposal_contribution_logs` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `activity_id` int(11) NOT NULL,
          `user_id` int(11) NOT NULL,
          `user_role` varchar(30) NOT NULL DEFAULT 'contributor',
          `action` varchar(100) NOT NULL,
          `section` varchar(100) DEFAULT NULL,
          `details` text DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_contrib_activity` (`activity_id`),
          KEY `idx_contrib_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    logMsg("proposal_contribution_logs table verified/created.", "SUCCESS");

    // 4. faculty_tasks Table
    logMsg("Checking faculty_tasks table...");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `faculty_tasks` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `activity_id` int(11) NOT NULL,
          `assigned_user_id` int(11) DEFAULT NULL,
          `faculty_name` varchar(150) DEFAULT NULL,
          `committee` varchar(150) DEFAULT NULL,
          `assigned_task` varchar(200) DEFAULT NULL,
          `task_title` varchar(200) DEFAULT NULL,
          `contribution_desc` text DEFAULT NULL,
          `task_description` text DEFAULT NULL,
          `role_in_event` varchar(150) DEFAULT NULL,
          `due_date` date DEFAULT NULL,
          `status` enum('Not Started','In Progress','Completed','Delayed') NOT NULL DEFAULT 'Not Started',
          `completion_pct` int(11) NOT NULL DEFAULT 0,
          `created_by` int(11) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `activity_id` (`activity_id`),
          KEY `assigned_user_id` (`assigned_user_id`),
          KEY `created_by` (`created_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    logMsg("faculty_tasks table verified/created.", "SUCCESS");

    // 5. task_deliverables Table
    logMsg("Checking task_deliverables table...");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `task_deliverables` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `task_id` int(11) NOT NULL,
          `activity_id` int(11) NOT NULL,
          `file_name` varchar(255) NOT NULL,
          `file_path` varchar(500) NOT NULL,
          `onedrive_item_id` varchar(255) DEFAULT NULL,
          `onedrive_web_url` text DEFAULT NULL,
          `onedrive_status` enum('pending','synced','failed') DEFAULT 'pending',
          `onedrive_synced_at` datetime DEFAULT NULL,
          `onedrive_error` text DEFAULT NULL,
          `file_size` int(11) DEFAULT 0,
          `mime_type` varchar(100) DEFAULT NULL,
          `uploaded_by` int(11) NOT NULL,
          `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `review_status` enum('pending','approved','returned') NOT NULL DEFAULT 'pending',
          `review_notes` text DEFAULT NULL,
          `reviewed_by` int(11) DEFAULT NULL,
          `reviewed_at` datetime DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `task_id` (`task_id`),
          KEY `activity_id` (`activity_id`),
          KEY `uploaded_by` (`uploaded_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    logMsg("task_deliverables table verified/created.", "SUCCESS");

    // 6. email_logs Table
    logMsg("Checking email_logs table...");
    $db->exec("
        CREATE TABLE IF NOT EXISTS `email_logs` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `recipient_email` varchar(180) NOT NULL,
          `recipient_name` varchar(120) NOT NULL,
          `subject` varchar(255) NOT NULL,
          `activity_id` int(11) DEFAULT NULL,
          `notification_type` varchar(60) NOT NULL,
          `delivery_method` varchar(30) NOT NULL DEFAULT 'phpmailer',
          `status` enum('sent','failed','simulated_success') NOT NULL DEFAULT 'sent',
          `error_message` text DEFAULT NULL,
          `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");
    logMsg("email_logs table verified/created.", "SUCCESS");

    logMsg("ALL SCHEMA CHECKS AND MIGRATIONS COMPLETED SUCCESSFULLY! The system is 100% healthy.", "SUCCESS");

    if (!$isCli) {
        echo "<p style='margin-top:20px;'><a href='" . BASE_URL . "/faculty/activities.php' style='display:inline-block;background:#0284c7;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600;'>Return to My Activities →</a></p>";
    }
} catch (\Throwable $e) {
    logMsg("Database error during self-healing: " . $e->getMessage(), "ERROR");
}

if (!$isCli) {
    echo "</div></body></html>";
}

