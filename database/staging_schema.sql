-- =====================================================================
-- STI-ARMES: Consolidated Staging & Production Schema
-- Target: MySQL 5.7+ / MariaDB 10.3+ (InfinityFree / Cloud Hosting)
-- Generated: 2026-10-29 12:01:48
-- Note: Structure only; contains zero user accounts or application data.
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ---------------------------------------------------------------------
-- Table structure for table `users`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('faculty','admin1','admin2','dean','program_head') NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `department` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `profile_picture` varchar(500) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `theme_preference` enum('light','dark','system') DEFAULT 'system',
  `notification_prefs` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `activities`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `activities`;
CREATE TABLE `activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `faculty_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `theme` varchar(200) DEFAULT NULL,
  `venue` varchar(200) DEFAULT NULL,
  `venue_address` text DEFAULT NULL,
  `floor_plan_required` tinyint(1) NOT NULL DEFAULT 1,
  `event_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `target_participants` int(11) DEFAULT 0,
  `general_objectives` text DEFAULT NULL,
  `specific_objectives` text DEFAULT NULL,
  `source` enum('student_org','faculty') NOT NULL,
  `status` enum('draft','submitted','under_review','returned_for_revision','resubmitted','endorsed','pending_final_approval','pending_dean_approval','approved','rejected','completed') DEFAULT 'draft',
  `revision_notes` text DEFAULT NULL,
  `poster_path` varchar(500) DEFAULT NULL,
  `last_reviewer_id` int(11) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `involved_subjects` text DEFAULT NULL,
  `rationale` text DEFAULT NULL,
  `kpi_targets` text DEFAULT NULL,
  `evaluation_method` text DEFAULT NULL,
  `google_form_link` varchar(500) DEFAULT NULL,
  `revision_sections` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`revision_sections`)),
  `evaluation_questions` longtext DEFAULT NULL,
  `clearance_required` tinyint(1) NOT NULL DEFAULT 0,
  `clearance_obtained` tinyint(1) DEFAULT NULL,
  `clearance_notes` varchar(255) DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `faculty_id` (`faculty_id`),
  KEY `idx_activities_archived` (`is_archived`, `status`),
  CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `activity_kpi_analytics`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `activity_kpi_analytics`;
CREATE TABLE `activity_kpi_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `analytics_json` longtext NOT NULL,
  `data_hash` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_activity_kpi_activity_id` (`activity_id`),
  CONSTRAINT `fk_kpi_analytics_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `approval_logs`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `approval_logs`;
CREATE TABLE `approval_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `action` enum('approved','rejected','returned_for_revision','resubmitted','forwarded') NOT NULL,
  `notes` text DEFAULT NULL,
  `acted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  KEY `reviewer_id` (`reviewer_id`),
  CONSTRAINT `approval_logs_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  CONSTRAINT `approval_logs_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `documents`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `doc_type` enum('proposal','material','floorplan','photo','post_event','other') DEFAULT NULL,
  `file_name` varchar(300) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `onedrive_item_id` varchar(255) DEFAULT NULL,
  `onedrive_web_url` text DEFAULT NULL,
  `onedrive_status` varchar(50) NOT NULL DEFAULT 'pending',
  `onedrive_synced_at` datetime DEFAULT NULL,
  `onedrive_error` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_documents_onedrive_status` (`onedrive_status`),
  KEY `idx_documents_onedrive_item_id` (`onedrive_item_id`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `email_logs`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `email_logs`;
CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) DEFAULT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL,
  `error_message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `faculty_tasks`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `faculty_tasks`;
CREATE TABLE `faculty_tasks` (
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
  KEY `created_by` (`created_by`),
  CONSTRAINT `faculty_tasks_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `floor_plans`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `floor_plans`;
CREATE TABLE `floor_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `canvas_json` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_id` (`activity_id`),
  CONSTRAINT `floor_plans_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `guidelines`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `guidelines`;
CREATE TABLE `guidelines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `mechanics` text DEFAULT NULL,
  `criteria` text DEFAULT NULL,
  `scoring_system` text DEFAULT NULL,
  `special_awards` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_id` (`activity_id`),
  CONSTRAINT `guidelines_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `institutional_kpi_analytics`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `institutional_kpi_analytics`;
CREATE TABLE `institutional_kpi_analytics` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` int(11) NOT NULL,
  `period` varchar(100) NOT NULL DEFAULT 'all',
  `analytics_json` longtext NOT NULL,
  `data_hash` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inst_year_period` (`year`,`period`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `kpi_evaluations`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `kpi_evaluations`;
CREATE TABLE `kpi_evaluations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `criteria` varchar(200) DEFAULT NULL,
  `rating` tinyint(4) DEFAULT NULL CHECK (`rating` between 1 and 4),
  `evaluator_name` varchar(150) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `evaluated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `indicator` varchar(255) DEFAULT NULL,
  `target_metric` varchar(255) DEFAULT NULL,
  `evaluation_method` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  CONSTRAINT `kpi_evaluations_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `manpower`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `manpower`;
CREATE TABLE `manpower` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `role` varchar(100) DEFAULT NULL,
  `assigned_person` varchar(150) DEFAULT NULL,
  `type` enum('faculty','staff','student') DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  CONSTRAINT `manpower_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `materials`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `materials`;
CREATE TABLE `materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `item_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `provider` varchar(200) DEFAULT NULL,
  `est_cost` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  CONSTRAINT `materials_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `notifications`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `activity_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_notifications_activity_id` (`activity_id`),
  CONSTRAINT `fk_notifications_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `post_event`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `post_event`;
CREATE TABLE `post_event` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actual_attendance` int(11) DEFAULT NULL,
  `target_attendance` int(11) DEFAULT NULL,
  `satisfaction_score` decimal(3,1) DEFAULT NULL,
  `recommendations` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_id` (`activity_id`),
  KEY `submitted_by` (`submitted_by`),
  CONSTRAINT `post_event_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  CONSTRAINT `post_event_ibfk_2` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `program_sequence`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `program_sequence`;
CREATE TABLE `program_sequence` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `time_slot` time DEFAULT NULL,
  `segment` varchar(200) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `person_ic` varchar(150) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  CONSTRAINT `program_sequence_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `proposal_comments`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `proposal_comments`;
CREATE TABLE `proposal_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proposal_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `section` varchar(255) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `proposal_id` (`proposal_id`),
  KEY `reviewer_id` (`reviewer_id`),
  CONSTRAINT `proposal_comments_ibfk_1` FOREIGN KEY (`proposal_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `proposal_comments_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `root_cause_analysis`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `root_cause_analysis`;
CREATE TABLE `root_cause_analysis` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `problem` text DEFAULT NULL,
  `method` enum('5_whys','fishbone','swot') DEFAULT NULL,
  `causes` text DEFAULT NULL,
  `explanation` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  CONSTRAINT `root_cause_analysis_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `schedules`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `schedules`;
CREATE TABLE `schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `venue_id` int(11) DEFAULT NULL,
  `sched_date` date DEFAULT NULL,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `event_name` varchar(200) DEFAULT NULL,
  `venue` varchar(200) DEFAULT NULL,
  `organizer` varchar(150) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'approved',
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `section_comments`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `section_comments`;
CREATE TABLE `section_comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `section_key` varchar(50) NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_reviewer_section` (`activity_id`,`reviewer_id`,`section_key`),
  KEY `idx_activity` (`activity_id`),
  KEY `sc_reviewer_fk` (`reviewer_id`),
  CONSTRAINT `sc_activity_fk` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sc_reviewer_fk` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `section_revisions`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `section_revisions`;
CREATE TABLE `section_revisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `activity_id` int(11) NOT NULL,
  `section_name` varchar(100) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `reviewer_role` varchar(50) NOT NULL,
  `revision_comment` text NOT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resubmitted_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','resolved') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `activity_id` (`activity_id`),
  KEY `reviewer_id` (`reviewer_id`),
  CONSTRAINT `section_revisions_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE,
  CONSTRAINT `section_revisions_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `task_deliverables`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `task_deliverables`;
CREATE TABLE `task_deliverables` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `task_id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `file_name` varchar(300) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `onedrive_item_id` varchar(255) DEFAULT NULL,
  `onedrive_web_url` text DEFAULT NULL,
  `onedrive_status` varchar(50) NOT NULL DEFAULT 'pending',
  `onedrive_synced_at` datetime DEFAULT NULL,
  `onedrive_error` text DEFAULT NULL,
  `file_size` int(11) DEFAULT 0,
  `mime_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `review_status` enum('pending','approved','returned_for_improvement') NOT NULL DEFAULT 'pending',
  `review_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_id` (`task_id`),
  KEY `idx_activity_id` (`activity_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_deliv_onedrive_status` (`onedrive_status`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `task_reminder_logs`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `task_reminder_logs`;
CREATE TABLE `task_reminder_logs` (
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `proposal_collaborators`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `proposal_collaborators`;
CREATE TABLE `proposal_collaborators` (
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Table structure for table `proposal_contribution_logs`
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `proposal_contribution_logs`;
CREATE TABLE `proposal_contribution_logs` (
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
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
