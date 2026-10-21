-- Seed staff members according to assigned category
-- (Compatible with both local 'school_erp' and production 'u971377821_itierp_db')

-- Ensure designation columns exist
ALTER TABLE users ADD COLUMN IF NOT EXISTS designation VARCHAR(120) NULL AFTER role;
ALTER TABLE teachers ADD COLUMN IF NOT EXISTS designation VARCHAR(120) NULL AFTER qualification;

-- --------------------------------------------------------
-- Category: Admin (Co-ordinator, Manager, Head of Admission Cell, Accountant)
-- --------------------------------------------------------
INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mr. Khurram Kazmi', 'kazmi687@gmail.com', '$2y$10$QFIFnlHm4xfvFM9tZW/CUeSjO275v3sEVDHXw6bhRWQ.WXR32UPb6', 'admin', 'Co-ordinator', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mr. S.M.Mahzar', 'managerunitypvtiti@gmail.com', '$2y$10$SEczV6wuATL5IYSKC/6cLO0b9u8j1GubOSc2RNkvCeWRS/kyCfXGa', 'admin', 'Manager', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mrs. Shabi Zehra', 'shabi.zehra66@gmail.com', '$2y$10$W0JZs0aZrwXaNQYP9PtfJOEdMD5MuyRgLhttCLnnk23NAwmHr0flm', 'admin', 'Head of Admission Cell', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mr. Sajjad Haider', 'sajjadshamslko@gmail.com', '$2y$10$qorG9an44pa1u671tGcTEuUL7ZNU5OZ5TlmdbqFpiiA4HEIfXvUdO', 'admin', 'Accountant', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

-- --------------------------------------------------------
-- Category: Teachers (Instructors)
-- --------------------------------------------------------
INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mr. Najaf Abdi', 'najafaabdi9@gmail.com', '$2y$10$qEIM7YzGzqghLxxVP8LKnOLaoepVE2S82yMJBJ71LyOAmO/Q5WkiK', 'teacher', 'Instructor', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO teachers (user_id, employee_code, qualification, designation)
SELECT u.id, 'INST-001', 'Instructor', 'Instructor'
FROM users u WHERE u.email = 'najafaabdi9@gmail.com'
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), qualification = VALUES(qualification), designation = VALUES(designation);

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mr. Jabir Khan Warsi', 'lovingsouls707@gmail.com', '$2y$10$8A1VPeHQ9Eg5O1VjoSOdYOML5o.P1hFNGzeHKZ2THTn9vnggdb0ZK', 'teacher', 'Instructor', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO teachers (user_id, employee_code, qualification, designation)
SELECT u.id, 'INST-002', 'Instructor', 'Instructor'
FROM users u WHERE u.email = 'lovingsouls707@gmail.com'
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), qualification = VALUES(qualification), designation = VALUES(designation);

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mr. Bashar Khan', 'basharkhan823@gmail.com', '$2y$10$Ezaknz4F045cjZ1.kQygyuQCkurUVzKnnqm3UtkIJGRZSMUktLip2', 'teacher', 'Instructor', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO teachers (user_id, employee_code, qualification, designation)
SELECT u.id, 'INST-003', 'Instructor', 'Instructor'
FROM users u WHERE u.email = 'basharkhan823@gmail.com'
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), qualification = VALUES(qualification), designation = VALUES(designation);

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mr. S. Zaigham Abbas', 'zaighamabbas1958@gmail.com', '$2y$10$p2uhWriDNSl45qSoifqSD.aDw59uiy3GwCW3UikLipIbwrSHbEckS', 'teacher', 'Instructor', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO teachers (user_id, employee_code, qualification, designation)
SELECT u.id, 'INST-004', 'Instructor', 'Instructor'
FROM users u WHERE u.email = 'zaighamabbas1958@gmail.com'
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), qualification = VALUES(qualification), designation = VALUES(designation);

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mrs. Zeba Kidwai', 'zebaqidwai1972@gmail.com', '$2y$10$KzAyoY57UKGQSKzkqFGhAeIj7Rcfkxeh0P07pCswClyot9s6wr6nO', 'teacher', 'Instructor', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO teachers (user_id, employee_code, qualification, designation)
SELECT u.id, 'INST-005', 'Instructor', 'Instructor'
FROM users u WHERE u.email = 'zebaqidwai1972@gmail.com'
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), qualification = VALUES(qualification), designation = VALUES(designation);

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mrs. Savita Srivastava', 'savitasri111@gmail.com', '$2y$10$hrbUfmdRvVKqpe7n1WL2Fu18F84gdPHP21Ft3vbBvhZvL48u0.nF2', 'teacher', 'Instructor', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO teachers (user_id, employee_code, qualification, designation)
SELECT u.id, 'INST-006', 'Instructor', 'Instructor'
FROM users u WHERE u.email = 'savitasri111@gmail.com'
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), qualification = VALUES(qualification), designation = VALUES(designation);

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Mrs. Nidhi Verma', 'laliteshverma119@gmail.com', '$2y$10$LFF12BPgPOXJpLswQ99BWu4AqPYxcLIwRbL35ZO7lTaQyDkAfdpaC', 'teacher', 'Instructor', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO teachers (user_id, employee_code, qualification, designation)
SELECT u.id, 'INST-007', 'Instructor', 'Instructor'
FROM users u WHERE u.email = 'laliteshverma119@gmail.com'
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), qualification = VALUES(qualification), designation = VALUES(designation);

INSERT INTO users (full_name, email, password_hash, role, designation, is_active)
VALUES ('Miss Rupanshi Priydarshani', 'rupanshipri14@gmail.com', '$2y$10$aSX.QBTVfe9WKixzehs3w.aCF/NbQ6ax1Znh16VLKhuJA4EmvsePG', 'teacher', 'Instructor', 1)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), designation = VALUES(designation), role = VALUES(role), is_active = 1;

INSERT INTO teachers (user_id, employee_code, qualification, designation)
SELECT u.id, 'INST-008', 'Instructor', 'Instructor'
FROM users u WHERE u.email = 'rupanshipri14@gmail.com'
ON DUPLICATE KEY UPDATE employee_code = VALUES(employee_code), qualification = VALUES(qualification), designation = VALUES(designation);

