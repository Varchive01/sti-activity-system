-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 30, 2026 at 05:45 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `sti_activity_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `theme` varchar(200) DEFAULT NULL,
  `venue` varchar(200) DEFAULT NULL,
  `venue_address` text DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `target_participants` int(11) DEFAULT 0,
  `general_objectives` text DEFAULT NULL,
  `specific_objectives` text DEFAULT NULL,
  `source` enum('student_org','faculty') NOT NULL,
  `status` enum('draft','submitted','under_review_arjay','under_review_ian','for_revision','resubmitted','approved','rejected','completed') DEFAULT 'draft',
  `revision_notes` text DEFAULT NULL,
  `poster_path` varchar(500) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `approval_logs`
--

CREATE TABLE `approval_logs` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `reviewer_id` int(11) NOT NULL,
  `action` enum('approved','rejected','returned','forwarded') NOT NULL,
  `notes` text DEFAULT NULL,
  `acted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `doc_type` enum('proposal','material','floorplan','photo','post_event','other') DEFAULT NULL,
  `file_name` varchar(300) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `faculty_tasks`
--

CREATE TABLE `faculty_tasks` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `faculty_name` varchar(150) DEFAULT NULL,
  `assigned_task` varchar(200) DEFAULT NULL,
  `contribution_desc` text DEFAULT NULL,
  `role_in_event` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `floor_plans`
--

CREATE TABLE `floor_plans` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `guidelines`
--

CREATE TABLE `guidelines` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `mechanics` text DEFAULT NULL,
  `criteria` text DEFAULT NULL,
  `scoring_system` text DEFAULT NULL,
  `special_awards` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `kpi_evaluations`
--

CREATE TABLE `kpi_evaluations` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `criteria` varchar(200) DEFAULT NULL,
  `rating` tinyint(4) DEFAULT NULL CHECK (`rating` between 1 and 4),
  `evaluator_name` varchar(150) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `evaluated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `manpower`
--

CREATE TABLE `manpower` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `role` varchar(100) DEFAULT NULL,
  `assigned_person` varchar(150) DEFAULT NULL,
  `type` enum('faculty','staff','student') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

CREATE TABLE `materials` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `item_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `provider` varchar(200) DEFAULT NULL,
  `est_cost` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `activity_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `post_event`
--

CREATE TABLE `post_event` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `program_sequence`
--

CREATE TABLE `program_sequence` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `time_slot` time DEFAULT NULL,
  `segment` varchar(200) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `person_ic` varchar(150) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `root_cause_analysis`
--

CREATE TABLE `root_cause_analysis` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `problem` text DEFAULT NULL,
  `method` enum('5_whys','fishbone','swot') DEFAULT NULL,
  `causes` text DEFAULT NULL,
  `explanation` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `id` int(11) NOT NULL,
  `activity_id` int(11) NOT NULL,
  `venue_id` int(11) DEFAULT NULL,
  `sched_date` date DEFAULT NULL,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `event_name` varchar(200) DEFAULT NULL,
  `venue` varchar(200) DEFAULT NULL,
  `organizer` varchar(150) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'approved'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('faculty','admin1','admin2','dean') NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `department`, `created_at`) VALUES
(1, 'Maria Santos', 'faculty@sti.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', '2026-03-30 15:36:55'),
(2, 'Ar-jay Agabayani', 'arjay@sti.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin1', 'Student Org Head', '2026-03-30 15:36:55'),
(3, 'Ian Jade Barangan', 'ian@sti.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin2', 'Events Committee Head', '2026-03-30 15:36:55'),
(4, 'Frederic Yulo', 'dean@sti.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'dean', 'Administration', '2026-03-30 15:36:55');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `faculty_id` (`faculty_id`);

--
-- Indexes for table `approval_logs`
--
ALTER TABLE `approval_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`),
  ADD KEY `reviewer_id` (`reviewer_id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `faculty_tasks`
--
ALTER TABLE `faculty_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `floor_plans`
--
ALTER TABLE `floor_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `activity_id` (`activity_id`);

--
-- Indexes for table `guidelines`
--
ALTER TABLE `guidelines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `activity_id` (`activity_id`);

--
-- Indexes for table `kpi_evaluations`
--
ALTER TABLE `kpi_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `manpower`
--
ALTER TABLE `manpower`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `post_event`
--
ALTER TABLE `post_event`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `activity_id` (`activity_id`),
  ADD KEY `submitted_by` (`submitted_by`);

--
-- Indexes for table `program_sequence`
--
ALTER TABLE `program_sequence`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `root_cause_analysis`
--
ALTER TABLE `root_cause_analysis`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `activity_id` (`activity_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `approval_logs`
--
ALTER TABLE `approval_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `faculty_tasks`
--
ALTER TABLE `faculty_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `floor_plans`
--
ALTER TABLE `floor_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `guidelines`
--
ALTER TABLE `guidelines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `kpi_evaluations`
--
ALTER TABLE `kpi_evaluations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manpower`
--
ALTER TABLE `manpower`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `post_event`
--
ALTER TABLE `post_event`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `program_sequence`
--
ALTER TABLE `program_sequence`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `root_cause_analysis`
--
ALTER TABLE `root_cause_analysis`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activities`
--
ALTER TABLE `activities`
  ADD CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `approval_logs`
--
ALTER TABLE `approval_logs`
  ADD CONSTRAINT `approval_logs_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  ADD CONSTRAINT `approval_logs_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  ADD CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `faculty_tasks`
--
ALTER TABLE `faculty_tasks`
  ADD CONSTRAINT `faculty_tasks_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `floor_plans`
--
ALTER TABLE `floor_plans`
  ADD CONSTRAINT `floor_plans_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `guidelines`
--
ALTER TABLE `guidelines`
  ADD CONSTRAINT `guidelines_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `kpi_evaluations`
--
ALTER TABLE `kpi_evaluations`
  ADD CONSTRAINT `kpi_evaluations_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`);

--
-- Constraints for table `manpower`
--
ALTER TABLE `manpower`
  ADD CONSTRAINT `manpower_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `materials`
--
ALTER TABLE `materials`
  ADD CONSTRAINT `materials_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `post_event`
--
ALTER TABLE `post_event`
  ADD CONSTRAINT `post_event_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`),
  ADD CONSTRAINT `post_event_ibfk_2` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `program_sequence`
--
ALTER TABLE `program_sequence`
  ADD CONSTRAINT `program_sequence_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `root_cause_analysis`
--
ALTER TABLE `root_cause_analysis`
  ADD CONSTRAINT `root_cause_analysis_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`);

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `schedules_ibfk_1` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE;

--
-- Table structure for table `proposal_ai_validation`
--

CREATE TABLE IF NOT EXISTS `proposal_ai_validation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `proposal_id` int(11) NOT NULL,
  `is_complete` tinyint(1) NOT NULL DEFAULT 0,
  `is_aligned` tinyint(1) NOT NULL DEFAULT 0,
  `issues` text DEFAULT NULL,
  `suggestions` text DEFAULT NULL,
  `reviewed_by_human` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proposal_id` (`proposal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
