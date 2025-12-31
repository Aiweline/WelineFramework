-- 字体处理记录表
CREATE TABLE IF NOT EXISTS `weline_font_sub_letter_records` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) DEFAULT NULL,
    `original_filename` varchar(255) NOT NULL,
    `original_path` varchar(500) NOT NULL,
    `processed_filename` varchar(255) DEFAULT NULL,
    `processed_path` varchar(500) DEFAULT NULL,
    `extracted_chars` text DEFAULT NULL,
    `custom_chars` text DEFAULT NULL,
    `font_format` varchar(10) NOT NULL,
    `file_size` int(11) NOT NULL,
    `status` enum('uploaded','processing','completed','failed') DEFAULT 'uploaded',
    `error_message` text DEFAULT NULL,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 字体字符映射表
CREATE TABLE IF NOT EXISTS `weline_font_sub_letter_char_maps` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `record_id` int(11) NOT NULL,
    `char_code` int(11) NOT NULL,
    `char_value` varchar(10) NOT NULL,
    `is_included` tinyint(1) DEFAULT 1,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_record_id` (`record_id`),
    KEY `idx_char_code` (`char_code`),
    FOREIGN KEY (`record_id`) REFERENCES `weline_font_sub_letter_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
