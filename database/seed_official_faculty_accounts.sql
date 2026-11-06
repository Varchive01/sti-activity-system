-- Seed Official Faculty Accounts for STI-ARMES
-- Ensures all 28 faculty members across IT, THM, and G.E. Departments exist in users table
-- Default password: password (BCRYPT hashed)

INSERT INTO `users` (`name`, `email`, `password`, `role`, `department`, `created_at`) VALUES
('Michelle Carlos', 'michelle.carlos@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', NOW()),
('Emilou G. Magnaye', 'emilou.magnaye@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', NOW()),
('John Cedrick Agapito', 'john.agapito@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', NOW()),
('Jedekiah Veloso', 'jedekiah.veloso@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', NOW()),
('Shintaro Lagare', 'shintaro.lagare@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', NOW()),
('Geo V. De Leon', 'geo.leon@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', NOW()),
('Johanna Kristel T. Montejo', 'johanna.montejo@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', NOW()),
('Felmar Solbita', 'felmar.solbita@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', NOW()),
('Michael André Sayson', 'michael.sayson@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'IT Department', NOW()),
('Divine Grace E. Ibarroll', 'divine.ibarroll@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'THM Department', NOW()),
('Mary Darlene A. Mariano', 'mary.mariano@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'THM Department', NOW()),
('Julieta Caridad C. Cruz', 'julieta.cruz@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'THM Department', NOW()),
('Exequiel L. Magnipis', 'exequiel.magnipis@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'THM Department', NOW()),
('Frankie Chiu', 'frankie.chiu@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'THM Department', NOW()),
('Ulysses Neil Patrick B. Edquilang', 'ulysses.edquilang@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'G.E. Department', NOW()),
('Gregg Anthony J. Rana', 'gregg.rana@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'G.E. Department', NOW()),
('Regina Kate D. Dominguez', 'regina.dominguez@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'G.E. Department', NOW()),
('Benedicto A. Arnoco', 'benedicto.arnoco@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'G.E. Department', NOW()),
('Jennilyn Q. Abarra', 'jennilyn.abarra@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'G.E. Department', NOW()),
('Ruby Ann G. Dasalla', 'ruby.dasalla@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'G.E. Department', NOW()),
('Perine O. Dela Cruz', 'perine.cruz@marikina.sti.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'G.E. Department', NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Self-healing: Ensure existing accounts with legacy @test.com or placeholder emails are updated to official institutional format
UPDATE `users` SET `email` = 'andrea.gatbonton@marikina.sti.edu.ph' WHERE `name` LIKE '%Andrea Gatbonton%' AND `email` LIKE '%@test.com%';
UPDATE `users` SET `email` = 'ricky.manliguis@marikina.sti.edu.ph' WHERE `name` LIKE '%Ricky Manliguis%' AND `email` LIKE '%@test.com%';
UPDATE `users` SET `email` = 'ian.barangan@marikina.sti.edu.ph' WHERE `name` LIKE '%Ian Jade Barangan%' AND `email` LIKE '%@sti.edu%';
UPDATE `users` SET `email` = 'frederic.yulo@marikina.sti.edu.ph' WHERE `name` LIKE '%Frederic Yulo%' AND `email` LIKE '%@sti.edu%';
UPDATE `users` SET `email` = 'dave.rubaya@marikina.sti.edu.ph' WHERE `name` LIKE '%Dave Lord%' AND `email` LIKE '%@sti.edu%';
UPDATE `users` SET `email` = 'aldwin.alminar@marikina.sti.edu.ph' WHERE `name` LIKE '%Aldwin Alminar%' AND `email` LIKE '%@sti.edu%';
UPDATE `users` SET `email` = 'tristan.unabia@marikina.sti.edu.ph' WHERE `name` LIKE '%Tristan Unabia%' AND `email` LIKE '%@sti.edu%';
UPDATE `users` SET `email` = 'maria.santos@marikina.sti.edu.ph' WHERE `name` LIKE '%Maria Santos%' AND `email` LIKE '%@sti.edu%';
