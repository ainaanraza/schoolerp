ALTER TABLE fee_structures
    ADD COLUMN fee_type ENUM('admission_fee', 'caution_money', 'tuition_fee') NOT NULL DEFAULT 'tuition_fee' AFTER session_id;

UPDATE fee_structures
SET fee_type = 'tuition_fee'
WHERE fee_type IS NULL OR fee_type = '';

ALTER TABLE leads
    ADD COLUMN payment_mode ENUM('cash', 'card', 'upi', 'bank_transfer', 'online_gateway') NULL AFTER admission_fee_amount;
