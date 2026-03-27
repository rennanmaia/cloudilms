-- CloudiLMS – Migração: suporte a upload de arquivos nos anexos de aula
-- Execute via phpMyAdmin ou pipe para mysql:
--   Get-Content install\migrate_attachments_upload.sql | mysql -u root cloudilms

-- Torna gdrive_file_id opcional (pode ser NULL para arquivos enviados localmente)
ALTER TABLE `lesson_attachments`
    MODIFY COLUMN `gdrive_file_id` VARCHAR(200) NULL DEFAULT NULL;

-- Caminho do arquivo enviado (relativo a uploads/attachments/)
ALTER TABLE `lesson_attachments`
    ADD COLUMN `file_path` VARCHAR(500) NULL AFTER `gdrive_file_id`;
