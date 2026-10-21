-- Migration: Yearly Maintenance System (AMC) and Auto-Lock
CREATE TABLE IF NOT EXISTS system_maintenance (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_payment_history (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_alert_logs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default maintenance configuration if none exists
INSERT INTO system_maintenance (
    id, is_enabled, status, annual_fee, currency, due_date, grace_period_days, warning_days, 
    last_payment_date, last_payment_reference, lock_status, alert_title, alert_message, 
    lock_title, lock_message, contact_person, contact_phone, contact_email, payment_instructions, upi_qr_path
) SELECT 
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
WHERE NOT EXISTS (SELECT 1 FROM system_maintenance WHERE id = 1);
