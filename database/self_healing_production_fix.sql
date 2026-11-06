-- =====================================================================
-- STI-ARMES Non-Destructive Production Database Self-Healing Fix
-- Safe to run on InfinityFree phpMyAdmin (uses CREATE TABLE IF NOT EXISTS)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_email` varchar(180) NOT NULL,
  `recipient_name` varchar(120) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `activity_id` int(11) DEFAULT NULL,
  `notification_type` varchar(60) NOT NULL,
  `delivery_method` varchar(30) NOT NULL DEFAULT 'phpmailer',
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `task_reminder_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reminder_type` varchar(32) NOT NULL,
  `task_status` varchar(32) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `completion_pct` int(11) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_task_reminder` (`task_id`,`reminder_type`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

