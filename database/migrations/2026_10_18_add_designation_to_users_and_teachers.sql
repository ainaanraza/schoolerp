-- Add designation column to users and teachers tables
ALTER TABLE users ADD COLUMN designation VARCHAR(120) NULL AFTER role;
ALTER TABLE teachers ADD COLUMN designation VARCHAR(120) NULL AFTER qualification;
