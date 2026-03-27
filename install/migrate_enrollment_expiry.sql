-- CloudiLMS – Migration: prazo de validade de matrícula
-- Execute via phpMyAdmin ou: mysql -u root cloudilms < migrate_enrollment_expiry.sql

USE `cloudilms`;

ALTER TABLE `enrollments`
    ADD COLUMN `expires_at` DATETIME NULL DEFAULT NULL COMMENT 'Data limite para conclusão; NULL = sem prazo'
    AFTER `enrolled_at`;
