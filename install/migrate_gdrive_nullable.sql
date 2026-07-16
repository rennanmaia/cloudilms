-- Torna gdrive_folder_id e gdrive_folder_url anuláveis
-- Necessário para cursos que usam fontes de mídia diferentes do Google Drive (http/ftp/local)
ALTER TABLE `courses`
    MODIFY COLUMN `gdrive_folder_id`  VARCHAR(200) DEFAULT NULL,
    MODIFY COLUMN `gdrive_folder_url` VARCHAR(500) DEFAULT NULL;
