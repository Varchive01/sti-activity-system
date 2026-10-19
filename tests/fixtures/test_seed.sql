-- ============================================================================
-- STI-ARMES: Automated Test & Demo Seed Fixture
-- FILE: tests/fixtures/test_seed.sql
-- ============================================================================
-- WARNING: FOR ISOLATED TESTING AND CI/CD TEST ENVIRONMENTS ONLY!
-- DO NOT IMPORT THIS FILE INTO PRODUCTION ENVIRONMENTS.
--
-- This fixture contains demo test accounts with publicly known seed passwords
-- ($2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi) required for
-- automated integration and regression test suites.
--
-- For production setup:
-- 1. Import sti_activity_system.sql (schema only)
-- 2. Run 'php scripts/init_admin.php' from CLI to create the initial Dean
-- ============================================================================

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `department`, `must_change_password`, `created_at`) VALUES
(1, 'Maria Santos', 'faculty@sti.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', 0, '2026-03-30 15:36:55'),
(2, 'Ar-jay Agabayani', 'arjay@sti.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin1', 'Student Org Head', 0, '2026-03-30 15:36:55'),
(3, 'Ian Jade Barangan', 'ian@sti.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin2', 'Events Committee Head', 0, '2026-03-30 15:36:55'),
(4, 'Frederic Yulo', 'dean@sti.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'dean', 'Administration', 0, '2026-03-30 15:36:55')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `role` = VALUES(`role`),
  `department` = VALUES(`department`);
