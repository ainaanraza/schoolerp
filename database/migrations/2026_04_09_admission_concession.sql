ALTER TABLE leads
    ADD COLUMN admission_concession_amount DECIMAL(10,2) NULL AFTER admission_fee_amount,
    ADD COLUMN admission_concession_note TEXT NULL AFTER admission_concession_amount;
