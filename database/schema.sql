-- CREATE DATABASE IF NOT EXISTS school_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE school_erp;

CREATE TABLE schools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    logo_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE academic_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    school_id INT NOT NULL,
    title VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_id) REFERENCES schools(id)
);

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'admin', 'teacher', 'student', 'parent') NOT NULL,
    designation VARCHAR(120) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    employee_code VARCHAR(50) UNIQUE,
    phone VARCHAR(20),
    qualification VARCHAR(100),
    designation VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE parents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    phone VARCHAR(20),
    address VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_name VARCHAR(50) NOT NULL,
    section VARCHAR(20) NOT NULL,
    session_id INT NOT NULL,
    class_teacher_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_class_section_session (class_name, section, session_id),
    FOREIGN KEY (session_id) REFERENCES academic_sessions(id),
    FOREIGN KEY (class_teacher_id) REFERENCES teachers(id)
);

CREATE TABLE subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_name VARCHAR(100) NOT NULL,
    subject_code VARCHAR(30) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE class_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_class_subject (class_id, subject_id),
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id)
);

CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL UNIQUE,
    admission_no VARCHAR(50) NOT NULL UNIQUE,
    roll_number VARCHAR(30) NULL,
    dob DATE NULL,
    gender ENUM('male', 'female', 'other') NULL,
    photo_path VARCHAR(255) NULL,
    address VARCHAR(255) NULL,
    status ENUM('lead', 'enrolled', 'inactive', 'govt. approved') DEFAULT 'enrolled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE parent_student (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NOT NULL,
    student_id INT NOT NULL,
    relation VARCHAR(30) DEFAULT 'guardian',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_parent_student (parent_id, student_id),
    FOREIGN KEY (parent_id) REFERENCES parents(id),
    FOREIGN KEY (student_id) REFERENCES students(id)
);

CREATE TABLE student_class_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    session_id INT NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) DEFAULT 1,
    UNIQUE KEY uniq_student_session (student_id, session_id),
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (session_id) REFERENCES academic_sessions(id)
);

CREATE TABLE leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_name VARCHAR(120) NOT NULL,
    guardian_name VARCHAR(120) NULL,
    guardian_phone VARCHAR(20) NULL,
    guardian_email VARCHAR(150) NULL,
    email VARCHAR(150) NULL,
    phone VARCHAR(20) NULL,
    class_id INT NULL,
    class_applied VARCHAR(30) NULL,
    dob DATE NULL,
    gender ENUM('male', 'female', 'other') NULL,
    address VARCHAR(255) NULL,
    photo_path VARCHAR(255) NULL,
    status ENUM('new', 'contacted', 'approved', 'rejected', 'enrolled', 'govt. approved') DEFAULT 'new',
    admission_fee_paid TINYINT(1) DEFAULT 0,
    admission_fee_status ENUM('full_paid', 'unpaid', 'quarterly_paid', 'half_paid') NULL,
    admission_fee_amount DECIMAL(10,2) NULL,
    admission_concession_amount DECIMAL(10,2) NULL,
    admission_concession_note TEXT NULL,
    tuition_aid_percentage DECIMAL(5,2) NULL,
    tuition_aid_note TEXT NULL,
    payment_mode ENUM('cash', 'card', 'upi', 'bank_transfer', 'online_gateway') NULL,
    payment_confirmed_at DATETIME NULL,
    source ENUM('form_link', 'manual') DEFAULT 'manual',
    notes TEXT NULL,
    converted_student_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_lead_status (status),
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (converted_student_id) REFERENCES students(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    attendance_date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'leave') NOT NULL,
    marked_by INT NOT NULL,
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_daily_attendance (student_id, attendance_date),
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (marked_by) REFERENCES users(id)
);

CREATE TABLE fee_structures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    session_id INT NOT NULL,
    fee_type ENUM('admission_fee', 'caution_money', 'tuition_fee') NOT NULL DEFAULT 'tuition_fee',
    fee_title VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    billing_cycle ENUM('monthly', 'quarterly', 'half_yearly', 'yearly', 'one_time') NOT NULL,
    due_day TINYINT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (session_id) REFERENCES academic_sessions(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE student_fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    fee_structure_id INT NOT NULL,
    period_label VARCHAR(50) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) DEFAULT 0,
    payable_amount DECIMAL(10,2) NOT NULL,
    paid_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM('pending', 'partial', 'paid') DEFAULT 'pending',
    due_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id)
);

CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_fee_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATETIME NOT NULL,
    method ENUM('cash', 'card', 'upi', 'bank_transfer', 'online_gateway') DEFAULT 'cash',
    transaction_ref VARCHAR(100) NULL,
    status ENUM('success', 'failed', 'refunded') DEFAULT 'success',
    received_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_fee_id) REFERENCES student_fees(id),
    FOREIGN KEY (received_by) REFERENCES users(id)
);

CREATE TABLE fee_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_fee_id INT NOT NULL,
    adjustment_type ENUM('discount', 'override', 'manual_edit') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(255) NULL,
    approved_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_fee_id) REFERENCES student_fees(id),
    FOREIGN KEY (approved_by) REFERENCES users(id)
);

CREATE TABLE homework (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    due_date DATE NULL,
    posted_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id),
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    FOREIGN KEY (posted_by) REFERENCES users(id)
);

CREATE TABLE homework_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    homework_id INT NOT NULL,
    student_id INT NOT NULL,
    submitted_at DATETIME NULL,
    file_path VARCHAR(255) NULL,
    remarks TEXT NULL,
    status ENUM('pending', 'submitted', 'late') DEFAULT 'pending',
    UNIQUE KEY uniq_homework_student (homework_id, student_id),
    FOREIGN KEY (homework_id) REFERENCES homework(id),
    FOREIGN KEY (student_id) REFERENCES students(id)
);

CREATE TABLE documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    uploaded_by INT NOT NULL,
    document_type VARCHAR(80) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    verification_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

CREATE TABLE inventory_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(80) NOT NULL,
    item_name VARCHAR(160) NOT NULL,
    category VARCHAR(120) NULL,
    quantity INT NOT NULL DEFAULT 0,
    unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_inventory_sku (sku),
    INDEX idx_inventory_status (status),
    INDEX idx_inventory_item_name (item_name),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    sender_id INT NOT NULL,
    target_scope ENUM('all', 'role', 'class', 'user') NOT NULL,
    target_value VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id)
);

CREATE TABLE notification_reads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    notification_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at DATETIME NULL,
    UNIQUE KEY uniq_notification_user (notification_id, user_id),
    FOREIGN KEY (notification_id) REFERENCES notifications(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

INSERT INTO schools (name) VALUES ('My School');
INSERT INTO academic_sessions (school_id, title, start_date, end_date, is_active)
VALUES (1, '2026-2027', '2026-04-01', '2027-03-31', 1);

CREATE TABLE system_maintenance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('paid', 'pending', 'overdue', 'exempt') NOT NULL DEFAULT 'paid',
    annual_fee DECIMAL(10,2) NOT NULL DEFAULT 15000.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'INR',
    due_date DATE NOT NULL,
    grace_period_days INT NOT NULL DEFAULT 7,
    warning_days INT NOT NULL DEFAULT 30,
    last_payment_date DATE NULL,
    last_payment_reference VARCHAR(100) NULL,
    lock_status ENUM('auto', 'force_locked', 'force_unlocked') NOT NULL DEFAULT 'auto',
    alert_title VARCHAR(255) NOT NULL DEFAULT 'Annual ERP Maintenance Due',
    alert_message TEXT NULL,
    lock_title VARCHAR(255) NOT NULL DEFAULT 'ERP System Access Suspended - Maintenance Overdue',
    lock_message TEXT NULL,
    contact_person VARCHAR(120) NOT NULL DEFAULT 'ERP Technical Support & Vendor Team',
    contact_phone VARCHAR(50) NOT NULL DEFAULT '+91 84299 39031',
    contact_email VARCHAR(120) NOT NULL DEFAULT 'support@erptech.com',
    payment_instructions TEXT NULL,
    upi_qr_path VARCHAR(255) NULL,
    secret_unlock_hash VARCHAR(255) NULL,
    developer_alert_email VARCHAR(150) NOT NULL DEFAULT 'razaainaan@gmail.com',
    smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    smtp_host VARCHAR(150) NOT NULL DEFAULT 'smtp.hostinger.com',
    smtp_port INT NOT NULL DEFAULT 465,
    smtp_encryption VARCHAR(10) NOT NULL DEFAULT 'ssl',
    smtp_username VARCHAR(150) NULL,
    smtp_password VARCHAR(255) NULL,
    smtp_from_email VARCHAR(150) NULL,
    smtp_from_name VARCHAR(150) NOT NULL DEFAULT 'School ERP System',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE maintenance_payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    maintenance_id INT NOT NULL DEFAULT 1,
    payment_year VARCHAR(30) NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    paid_date DATE NOT NULL,
    payment_method VARCHAR(50) NOT NULL DEFAULT 'Bank Transfer',
    reference_no VARCHAR(100) NULL,
    extended_until DATE NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE maintenance_alert_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    maintenance_id INT NOT NULL DEFAULT 1,
    alert_day INT NOT NULL,
    due_date DATE NOT NULL,
    recipient VARCHAR(150) NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'sent',
    subject VARCHAR(255) NULL,
    response_msg TEXT NULL,
    sent_at DATETIME NOT NULL,
    INDEX (maintenance_id, due_date, alert_day)
);

INSERT INTO system_maintenance (
    id, is_enabled, status, annual_fee, currency, due_date, grace_period_days, warning_days, 
    last_payment_date, last_payment_reference, lock_status, alert_title, alert_message, 
    lock_title, lock_message, contact_person, contact_phone, contact_email, payment_instructions, upi_qr_path
) VALUES (
    1, 1, 'paid', 15000.00, 'INR', DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 7, 30,
    CURDATE(), 'INIT-PAY-001', 'auto', 
    'Annual ERP Maintenance Reminder', 
    'The Annual Maintenance Contract (AMC) for your School ERP is due for renewal. Please process payment before the due date to ensure uninterrupted system operations.',
    'ERP System Access Suspended - Maintenance Overdue',
    'Access to this School ERP suite has been automatically locked due to pending Annual Maintenance Contract (AMC) payment. Please contact your software vendor / administrator immediately to settle dues and restore full access.',
    'ERP Technical Support & Vendor Services',
    '+91 84299 39031',
    'support@erptech.com',
    'Bank: HDFC Bank\nAccount Name: ERP Tech Solutions\nA/C No: 50200012345678\nIFSC: HDFC0001234\nUPI ID: 8429939031@upi',
    NULL
);

