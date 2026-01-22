-- GuoLaiRen PageBuilder Module Upgrade Script (MySQL)
-- Version: 1.0.8
-- Date: 2024
-- Description: 添加 Header/Footer 自定义代码字段

-- 添加 header_custom_code 字段（如果不存在）
SET @dbname = DATABASE();
SET @tablename = 'm_guolairen_page_builder_page';
SET @columnname = 'header_custom_code';
SET @preparedStatement = (SELECT IF(
    (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
            (table_name = @tablename)
            AND (table_schema = @dbname)
            AND (column_name = @columnname)
    ) > 0,
    'SELECT 1',
    CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TEXT COMMENT ''Header自定义代码（GSC验证、统计代码等）''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 添加 footer_custom_code 字段（如果不存在）
SET @columnname = 'footer_custom_code';
SET @preparedStatement = (SELECT IF(
    (
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE
            (table_name = @tablename)
            AND (table_schema = @dbname)
            AND (column_name = @columnname)
    ) > 0,
    'SELECT 1',
    CONCAT('ALTER TABLE ', @tablename, ' ADD COLUMN ', @columnname, ' TEXT COMMENT ''Footer自定义代码（GSC验证、统计代码等）''')
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
