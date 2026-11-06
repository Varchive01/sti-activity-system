-- =====================================================================
-- STI-ARMES: Universal Database Self-Healing Migration Script
-- Purpose: Permanently resolves HTTP 500 errors when saving/closing proposals
--          and viewing activities by ensuring all required tables and columns exist.
-- Safe for: Live Production (InfinityFree / MySQL 5.7+ / MariaDB 10.3+)
-- =====================================================================

-- 1. Ensure `is_archived` and related columns exist on `activities` table
ALTER TABLE `activities` ADD COLUMN IF NOT EXISTS `is_archived` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `activities` ADD COLUMN IF NOT EXISTS `archived_at` datetime DEFAULT NULL;
ALTER TABLE `activities` ADD COLUMN IF NOT EXISTS `archived_by` int(11) DEFAULT NULL;

-- 2. Ensure proposal_collaborators table exists
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

-- 3. Ensure proposal_contribution_logs table exists
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

-- 4. Ensure faculty_tasks table exists
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

-- 5. Ensure task_deliverables table exists
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

-- 6. Ensure email_logs table exists
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
