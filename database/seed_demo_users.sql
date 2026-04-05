USE school_erp;

INSERT INTO users (full_name, email, password_hash, role, is_active)
VALUES
    ('Super Admin Demo', 'superadmin@demo.local', '$2y$10$d/xV1sTPjBUSdsIZ6RfW2esplkoLmeyRQAGBi4ZtFJynA2fZyhCCO', 'super_admin', 1),
    ('Admin Demo', 'admin@demo.local', '$2y$10$YznHLFXpH53ObMTy4/SFCeUZ.2W63Zr8PNvgpmcFtwvSVZ6marE16', 'admin', 1),
    ('Teacher Demo', 'teacher@demo.local', '$2y$10$MzVhJISlj6siAZ4/Pw1SPuVvSo5BorlVPheKC7gddnQ8.gr1FeUAG', 'teacher', 1),
    ('Student Demo', 'student@demo.local', '$2y$10$Zyczwbzl.A8cSDpjq5MekOleFuogpOkTJVI2Gstb.7Kwye.xIg.we', 'student', 1),
    ('Parent Demo', 'parent@demo.local', '$2y$10$JqqnyFT2dSaU1mNtAuCuIuGjtyZj5sLr/qvqR8GwU88EjGzJLgFbS', 'parent', 1)
ON DUPLICATE KEY UPDATE
    full_name = VALUES(full_name),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    is_active = 1;

INSERT INTO teachers (user_id, employee_code, phone, qualification)
SELECT u.id, 'TCH-DEMO-001', '9999999999', 'M.Ed'
FROM users u
WHERE u.email = 'teacher@demo.local'
  AND NOT EXISTS (SELECT 1 FROM teachers t WHERE t.user_id = u.id);

INSERT INTO parents (user_id, phone, address)
SELECT u.id, '9999999998', 'Demo Address'
FROM users u
WHERE u.email = 'parent@demo.local'
  AND NOT EXISTS (SELECT 1 FROM parents p WHERE p.user_id = u.id);

INSERT INTO students (user_id, admission_no, roll_number, status)
SELECT u.id, 'DEMO-STU-001', '1', 'enrolled'
FROM users u
WHERE u.email = 'student@demo.local'
  AND NOT EXISTS (SELECT 1 FROM students s WHERE s.user_id = u.id OR s.admission_no = 'DEMO-STU-001');

INSERT INTO parent_student (parent_id, student_id, relation)
SELECT p.id, s.id, 'guardian'
FROM parents p
JOIN users pu ON pu.id = p.user_id
JOIN students s
JOIN users su ON su.id = s.user_id
WHERE pu.email = 'parent@demo.local'
  AND su.email = 'student@demo.local'
  AND NOT EXISTS (
      SELECT 1
      FROM parent_student ps
      WHERE ps.parent_id = p.id AND ps.student_id = s.id
  );
