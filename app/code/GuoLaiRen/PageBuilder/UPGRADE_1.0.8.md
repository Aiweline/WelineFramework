# PageBuilder 模块升级指南 - 版本 1.0.8

## 更新内容

本次更新添加了 Header/Footer 自定义代码功能，允许在页面头部和底部添加自定义代码（如 GSC 验证代码、统计代码等）。

## 新增字段

- `header_custom_code` (TEXT) - Header 自定义代码
- `footer_custom_code` (TEXT) - Footer 自定义代码

## 升级方式

### 方式一：自动升级（推荐）

运行框架的模块升级命令，框架会自动执行 `Model\Page::upgrade()` 方法：

```bash
php bin/weline module:upgrade GuoLaiRen_PageBuilder
```

### 方式二：手动执行 SQL 脚本

如果自动升级失败，可以手动执行 SQL 脚本：

#### PostgreSQL 数据库

```bash
psql -U your_username -d your_database -f upgrade_1.0.8.sql
```

或者直接在数据库中执行：

```sql
-- 添加 header_custom_code 字段
ALTER TABLE m_guolairen_page_builder_page 
ADD COLUMN IF NOT EXISTS header_custom_code TEXT;

COMMENT ON COLUMN m_guolairen_page_builder_page.header_custom_code IS 'Header自定义代码（GSC验证、统计代码等）';

-- 添加 footer_custom_code 字段
ALTER TABLE m_guolairen_page_builder_page 
ADD COLUMN IF NOT EXISTS footer_custom_code TEXT;

COMMENT ON COLUMN m_guolairen_page_builder_page.footer_custom_code IS 'Footer自定义代码（GSC验证、统计代码等）';
```

#### MySQL 数据库

```bash
mysql -u your_username -p your_database < upgrade_1.0.8_mysql.sql
```

或者直接在数据库中执行：

```sql
-- 添加 header_custom_code 字段（如果不存在）
ALTER TABLE m_guolairen_page_builder_page 
ADD COLUMN header_custom_code TEXT COMMENT 'Header自定义代码（GSC验证、统计代码等）';

-- 添加 footer_custom_code 字段（如果不存在）
ALTER TABLE m_guolairen_page_builder_page 
ADD COLUMN footer_custom_code TEXT COMMENT 'Footer自定义代码（GSC验证、统计代码等）';
```

## 验证升级

升级完成后，可以通过以下方式验证：

1. 检查数据库表结构，确认字段已添加
2. 访问页面编辑界面，查看是否显示 "Header 自定义代码" 和 "Footer 自定义代码" 输入框
3. 保存页面时不应再出现字段不存在的错误

## 回滚

如果需要回滚，可以执行以下 SQL：

```sql
-- PostgreSQL
ALTER TABLE m_guolairen_page_builder_page DROP COLUMN IF EXISTS header_custom_code;
ALTER TABLE m_guolairen_page_builder_page DROP COLUMN IF EXISTS footer_custom_code;

-- MySQL
ALTER TABLE m_guolairen_page_builder_page DROP COLUMN header_custom_code;
ALTER TABLE m_guolairen_page_builder_page DROP COLUMN footer_custom_code;
```

## 注意事项

- 升级前请备份数据库
- 如果表名不是 `m_guolairen_page_builder_page`，请根据实际情况修改 SQL 脚本中的表名
- 升级后需要清除缓存
