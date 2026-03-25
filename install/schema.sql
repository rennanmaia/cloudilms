-- CloudiLMS - Schema do banco de dados
-- Execute via phpMyAdmin ou mysql -u root cloudilms < schema.sql

CREATE DATABASE IF NOT EXISTS `cloudilms` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cloudilms`;

-- Usuários
CREATE TABLE IF NOT EXISTS `users` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(120) NOT NULL,
    `email`      VARCHAR(180) NOT NULL UNIQUE,
    `password`   VARCHAR(255) NOT NULL,
    `role`       ENUM('admin','student') NOT NULL DEFAULT 'student',
    `active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cursos
CREATE TABLE IF NOT EXISTS `courses` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`              VARCHAR(255) NOT NULL,
    `slug`               VARCHAR(255) NOT NULL UNIQUE,
    `description`        TEXT,
    `thumbnail`          VARCHAR(500),
    `gdrive_folder_id`   VARCHAR(200) NOT NULL,
    `gdrive_folder_url`  VARCHAR(500) NOT NULL,
    `published`          TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`         DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tópicos (módulos) do curso
CREATE TABLE IF NOT EXISTS `topics` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `course_id`  INT UNSIGNED NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Aulas
CREATE TABLE IF NOT EXISTS `lessons` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `course_id`        INT UNSIGNED NOT NULL,
    `topic_id`         INT UNSIGNED NULL,
    `title`            VARCHAR(255) NOT NULL,
    `gdrive_file_id`   VARCHAR(200) NOT NULL,
    `mime_type`        VARCHAR(100),
    `duration_seconds` INT UNSIGNED,
    `sort_order`       INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL,
    FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`topic_id`)  REFERENCES `topics`(`id`)  ON DELETE SET NULL,
    UNIQUE KEY `unique_lesson` (`course_id`, `gdrive_file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Matrículas
CREATE TABLE IF NOT EXISTS `enrollments` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `course_id`   INT UNSIGNED NOT NULL,
    `enrolled_at` DATETIME NOT NULL,
    UNIQUE KEY `unique_enrollment` (`user_id`, `course_id`),
    FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Progresso das aulas
CREATE TABLE IF NOT EXISTS `progress` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT UNSIGNED NOT NULL,
    `course_id`    INT UNSIGNED NOT NULL,
    `lesson_id`    INT UNSIGNED NOT NULL,
    `completed`    TINYINT(1) NOT NULL DEFAULT 0,
    `completed_at` DATETIME,
    UNIQUE KEY `unique_progress` (`user_id`, `lesson_id`),
    FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)    ON DELETE CASCADE,
    FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurações gerais
CREATE TABLE IF NOT EXISTS `settings` (
    `key_name`  VARCHAR(100) PRIMARY KEY,
    `value`     TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`key_name`, `value`) VALUES
    ('site_name', 'CloudiLMS'),
    ('site_logo', ''),
    ('allow_registration', '1'),
    ('gdrive_api_key', '');

-- Admin padrão (senha: admin123 - TROQUE IMEDIATAMENTE)
INSERT IGNORE INTO `users` (`name`, `email`, `password`, `role`, `active`, `created_at`)
VALUES ('Administrador', 'admin@cloudilms.local',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        'admin', 1, NOW());
