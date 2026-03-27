-- CloudiLMS – Migração: suporte a aulas avulsas e anexos de aula
-- Execute via phpMyAdmin ou: mysql -u root cloudilms < install/migrate_lesson_attachments.sql

-- 1. Permite que gdrive_file_id seja NULL (aulas avulsas sem vídeo)
ALTER TABLE `lessons`
    MODIFY COLUMN `gdrive_file_id` VARCHAR(200) NULL DEFAULT NULL;

-- 2. Campo de texto associado à aula (HTML)
ALTER TABLE `lessons`
    ADD COLUMN `body_text` MEDIUMTEXT NULL AFTER `sort_order`;

-- 3. Tabela de anexos das aulas
CREATE TABLE IF NOT EXISTS `lesson_attachments` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `lesson_id`      INT UNSIGNED NOT NULL,
    `title`          VARCHAR(255) NOT NULL,
    `gdrive_file_id` VARCHAR(200) NOT NULL,
    `mime_type`      VARCHAR(100),
    `sort_order`     INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL,
    FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
