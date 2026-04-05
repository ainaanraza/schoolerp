ALTER TABLE students
    MODIFY user_id INT NULL;

ALTER TABLE leads
    ADD COLUMN guardian_email VARCHAR(150) NULL AFTER guardian_name,
    ADD COLUMN admission_fee_paid TINYINT(1) DEFAULT 0 AFTER status,
    ADD COLUMN payment_confirmed_at DATETIME NULL AFTER admission_fee_paid,
    ADD INDEX idx_lead_status (status);
