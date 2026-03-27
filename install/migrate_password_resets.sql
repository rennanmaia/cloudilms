-- CloudiLMS – Migration: recuperação de senha por e-mail
-- Execute via phpMyAdmin ou: mysql -u root cloudilms < migrate_password_resets.sql

USE `cloudilms`;

-- Tabela de tokens de redefinição de senha
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(64)  NOT NULL COMMENT 'SHA-256 hex do token enviado ao usuário',
    `expires_at` DATETIME     NOT NULL,
    `used`       TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL,
    INDEX  `idx_token`   (`token_hash`),
    INDEX  `idx_user`    (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurações de e-mail SMTP
INSERT IGNORE INTO `settings` (`key_name`, `value`) VALUES
    ('smtp_host',       ''),
    ('smtp_port',       '587'),
    ('smtp_encryption', 'tls'),
    ('smtp_user',       ''),
    ('smtp_pass',       ''),
    ('smtp_from_email', ''),
    ('smtp_from_name',  '');
