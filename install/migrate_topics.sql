-- CloudiLMS - Migração: Adição de Tópicos
-- Execute este script se você já tem o CloudiLMS instalado e quer adicionar tópicos.
-- Via phpMyAdmin: selecione o banco cloudilms e execute este SQL.
-- Via terminal: mysql -u root cloudilms < install/migrate_topics.sql

USE `cloudilms`;

-- 1. Cria a tabela de tópicos (módulos)
CREATE TABLE IF NOT EXISTS `topics` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `course_id`  INT UNSIGNED NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Adiciona coluna topic_id na tabela de aulas (se ainda não existir)
--    ADD COLUMN IF NOT EXISTS funciona no MariaDB 10.3+
ALTER TABLE `lessons`
    ADD COLUMN IF NOT EXISTS `topic_id` INT UNSIGNED NULL AFTER `course_id`;

-- 3. Adiciona a FK apenas se ainda não existir (compatível com MariaDB 10.4+)
DROP PROCEDURE IF EXISTS `_cloudilms_add_fk`;
DELIMITER //
CREATE PROCEDURE `_cloudilms_add_fk`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'lessons'
          AND CONSTRAINT_NAME = 'fk_lesson_topic'
    ) THEN
        ALTER TABLE `lessons`
            ADD CONSTRAINT `fk_lesson_topic`
                FOREIGN KEY (`topic_id`) REFERENCES `topics`(`id`) ON DELETE SET NULL;
    END IF;
END//
DELIMITER ;
CALL `_cloudilms_add_fk`();
DROP PROCEDURE IF EXISTS `_cloudilms_add_fk`;

-- Pronto! Suas aulas existentes ficam sem tópico (topic_id = NULL)
-- e continuam funcionando normalmente. Você pode criar tópicos no painel admin.
