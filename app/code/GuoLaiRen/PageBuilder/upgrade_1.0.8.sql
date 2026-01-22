-- GuoLaiRen PageBuilder Module Upgrade Script
-- Version: 1.0.8
-- Date: 2024
-- Description: 添加 Header/Footer 自定义代码字段

-- 添加 header_custom_code 字段（如果不存在）
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 
        FROM information_schema.columns 
        WHERE table_name = 'm_guolairen_page_builder_page' 
        AND column_name = 'header_custom_code'
    ) THEN
        ALTER TABLE m_guolairen_page_builder_page 
        ADD COLUMN header_custom_code TEXT;
        
        COMMENT ON COLUMN m_guolairen_page_builder_page.header_custom_code IS 'Header自定义代码（GSC验证、统计代码等）';
    END IF;
END $$;

-- 添加 footer_custom_code 字段（如果不存在）
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 
        FROM information_schema.columns 
        WHERE table_name = 'm_guolairen_page_builder_page' 
        AND column_name = 'footer_custom_code'
    ) THEN
        ALTER TABLE m_guolairen_page_builder_page 
        ADD COLUMN footer_custom_code TEXT;
        
        COMMENT ON COLUMN m_guolairen_page_builder_page.footer_custom_code IS 'Footer自定义代码（GSC验证、统计代码等）';
    END IF;
END $$;
