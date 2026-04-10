-- CloudiLMS – Multi-source media migration
-- Adds source_type / source_folder to courses and
-- video_source_type / video_url to lessons.

ALTER TABLE `courses`
    ADD COLUMN IF NOT EXISTS `source_type`   VARCHAR(20) NOT NULL DEFAULT 'gdrive'
        COMMENT 'gdrive | http | ftp | local'
        AFTER `gdrive_folder_url`,
    ADD COLUMN IF NOT EXISTS `source_folder` TEXT NULL
        COMMENT 'Folder reference for non-GDrive sources (URL, FTP path, local path)'
        AFTER `source_type`;

ALTER TABLE `lessons`
    ADD COLUMN IF NOT EXISTS `video_source_type` VARCHAR(20) NOT NULL DEFAULT 'gdrive'
        COMMENT 'gdrive | http | ftp | local'
        AFTER `gdrive_file_id`,
    ADD COLUMN IF NOT EXISTS `video_url` TEXT NULL
        COMMENT 'Playback URL for non-GDrive sources'
        AFTER `video_source_type`;

-- Settings for FTP source
INSERT IGNORE INTO `settings` (`key_name`, `value`) VALUES
    ('ftp_host', ''),
    ('ftp_port', '21'),
    ('ftp_user', ''),
    ('ftp_pass', ''),
    ('ftp_base_path', '/'),
    ('ftp_base_url', '');

-- Settings for HTTP source (optional base URL for autoindex browsing)
INSERT IGNORE INTO `settings` (`key_name`, `value`) VALUES
    ('http_base_url', '');

-- Settings for Local filesystem source
INSERT IGNORE INTO `settings` (`key_name`, `value`) VALUES
    ('local_base_path', ''),
    ('local_base_url', '');
