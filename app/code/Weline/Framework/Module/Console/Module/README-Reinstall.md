# 模块重装功能 - module:reinstall

## 功能概述

`module:reinstall` 命令用于完全重新安装一个或多个模块，包括删除所有数据库表和重新创建。这是一个**危险操作**，仅限在开发模式下使用。

## 双重备份保护

为了保护数据安全，该命令在删除表之前会进行**双重备份**：

### 1. SQL 文件备份
- **位置**：`var/backup/db/`
- **格式**：SQL 文件
- **内容**：完整的表结构和数据
- **用途**：可以在任何时候导入恢复

### 2. 数据库表复制
- **命名规则**：`{原表名}_backup_{时间戳}`
- **示例**：`demo` → `demo_backup_2025_10_27_14_30_00`
- **批次管理**：同一次重装的所有表使用相同时间戳
- **内容**：完整的表结构和数据
- **用途**：快速恢复，无需导入 SQL
- **优势**：支持多次备份，不会覆盖历史备份

## 使用说明

### 前置条件

**必须在开发模式下运行：**

```bash
# 查看当前模式
php bin/w deploy:mode:show

# 切换到开发模式
php bin/w deploy:mode:set dev
```

### 基本用法

#### 重新安装单个模块
```bash
php bin/w module:reinstall -m Weline_Demo
```

#### 重新安装多个模块
```bash
php bin/w module:reinstall --module "Weline_Demo Weline_Test"
```

#### 查看帮助信息
```bash
php bin/w module:reinstall --help
```

## 执行流程

### 步骤 1：安全检查
- 验证是否在开发模式
- 验证模块是否存在
- 显示警告信息
- 要求用户确认

### 步骤 2：备份和清理（针对每个模块）

#### 2.1 备份表到文件
```
备份表到文件：demo_table...
✓ 备份文件：var/backup/db/demo_table_20251027_140530.sql
```

#### 2.2 复制表到数据库（带批次时间戳）
```
复制表：demo_table → demo_table_backup_2025_10_27_14_30_00...
✓ 表已复制：demo_table_backup_2025_10_27_14_30_00 (包含所有数据)
```

#### 2.3 删除原表
```
删除原表：demo_table...
✓ 原表已删除：demo_table
```

#### 2.4 清理配置文件
```
从 modules.php 删除模块注册信息...
✓ 已从 modules.php 删除模块

从 module_dependencies.php 删除模块依赖信息...
✓ 已从 module_dependencies.php 删除模块
```

### 步骤 3：重新安装
执行 `module:upgrade` 命令重新安装模块，包括：
- 注册模块
- 创建数据库表
- 更新路由
- 更新配置

### 步骤 4：备份表管理（新增）
重装完成后，会显示所有备份表并询问是否删除：

```
═══════════════════════════════════════════════════════════════
备份表管理
═══════════════════════════════════════════════════════════════

发现以下备份表：

模块        原表名                          备份表名                               创建时间              记录数
──────────────────────────────────────────────────────────────────────────────────────────────────────
Weline_TwoFactorAuth  user_two_factor  user_two_factor_backup_2025_10_27_14_30_00  2025-10-27 14:30:00  15
Weline_TwoFactorAuth  user_two_factor  user_two_factor_backup_2025_10_26_10_15_30  2025-10-26 10:15:30  10

共 2 个备份表，占用数据库空间。
共 2 个备份批次。

这些备份表是历史备份，可以安全删除以释放空间。
如果您需要恢复数据，请选择保留。
提示：备份表按时间戳分批次，相同时间戳的表属于同一批次。

是否删除所有备份表？(yes/y=删除, no/n=保留)：
```

选择删除或保留：
- **删除 (yes/y)**：清理所有备份表，释放空间
- **保留 (no/n)**：保留备份表，并显示手动删除的 SQL 语句

## 数据恢复

### 方法 1：从备份表恢复（推荐，最快）

```sql
-- 如果需要恢复数据

-- 1. 查看可用的备份批次
SHOW TABLES LIKE 'demo_table_backup_%';

-- 2. 删除重新安装的表
DROP TABLE IF EXISTS `demo_table`;

-- 3. 将指定批次的备份表改回原名
RENAME TABLE `demo_table_backup_2025_10_27_14_30_00` TO `demo_table`;
```

### 方法 2：从 SQL 文件恢复

```bash
# 找到备份文件
ls var/backup/db/

# 导入 SQL 文件
mysql -h 127.0.0.1 -u weline -p weline < var/backup/db/demo_table_20251027_140530.sql
```

### 方法 3：清理备份表

#### 按批次清理（推荐）
```sql
-- 查看所有备份批次
SHOW TABLES LIKE 'demo_table_backup_%';

-- 删除指定批次的备份表
DROP TABLE IF EXISTS `demo_table_backup_2025_10_27_14_30_00`;
DROP TABLE IF EXISTS `demo_table_backup_2025_10_26_10_15_30`;

-- 或使用模式匹配删除所有备份表（慎用！）
-- 需要逐个执行，MySQL 不支持 DROP TABLE LIKE 语法
```

#### 使用存储过程批量清理
```sql
-- 创建临时存储过程
DELIMITER //
CREATE PROCEDURE drop_backup_tables()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE tbl_name VARCHAR(255);
    DECLARE cur CURSOR FOR 
        SELECT table_name FROM information_schema.tables 
        WHERE table_schema = DATABASE() 
        AND table_name LIKE '%_backup_%';
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO tbl_name;
        IF done THEN
            LEAVE read_loop;
        END IF;
        SET @drop_sql = CONCAT('DROP TABLE IF EXISTS `', tbl_name, '`');
        PREPARE stmt FROM @drop_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;
    CLOSE cur;
END//
DELIMITER ;

-- 执行清理
CALL drop_backup_tables();

-- 删除存储过程
DROP PROCEDURE drop_backup_tables;
```

## 完整示例

### 示例：重新安装 GuoLaiRen_PageBuilder 模块

```bash
# 1. 确认在开发模式
$ php bin/w deploy:mode:show
当前部署模式：dev

# 2. 执行重新安装
$ php bin/w module:reinstall -m GuoLaiRen_PageBuilder

备份批次时间戳：2025_10_27_14_30_00

╔════════════════════════════════════════════════════════════════╗
║                      ⚠️  危险操作警告 ⚠️                        ║
╚════════════════════════════════════════════════════════════════╝

您即将重新安装以下模块：
  - GuoLaiRen_PageBuilder

此操作将执行以下步骤：
1. 备份模块的所有数据库表（备份到 var/backup/db/ 目录）
2. 复制数据库表并添加 _backup 后缀（如：demo → demo_backup）
3. 删除模块的所有数据库表
4. 从 app/etc/modules.php 中删除模块注册信息
5. 从 app/etc/module_dependencies.php 中删除模块依赖信息
6. 重新安装指定的模块

⚠️  警告：此操作不可逆！所有模块数据将被永久删除！
⚠️  警告：虽然会自动备份，但请确保您已手动备份重要数据！
⚠️  警告：如果有其他模块依赖于这些模块，可能会导致系统错误！

请输入 "yes" 或 "y" 确认继续，输入其他任何内容取消：
> yes

═══════════════════════════════════════════════════════════════
开始重新安装模块...
═══════════════════════════════════════════════════════════════

───────────────────────────────────────────────────────────────
处理模块：GuoLaiRen_PageBuilder
───────────────────────────────────────────────────────────────
步骤 1/3：备份、复制并删除数据库表...
  发现 4 个 Model 类

  备份表到文件：guolairen_page_builder_style...
  ✓ 备份文件：var/backup/db/guolairen_page_builder_style_20251027_140530.sql
  复制表：guolairen_page_builder_style → guolairen_page_builder_style_backup_2025_10_27_14_30_00...
  ✓ 表已复制：guolairen_page_builder_style_backup_2025_10_27_14_30_00 (包含所有数据)
  删除原表：guolairen_page_builder_style...
  ✓ 原表已删除：guolairen_page_builder_style

  备份表到文件：guolairen_page_builder_page...
  ✓ 备份文件：var/backup/db/guolairen_page_builder_page_20251027_140530.sql
  复制表：guolairen_page_builder_page → guolairen_page_builder_page_backup_2025_10_27_14_30_00...
  ✓ 表已复制：guolairen_page_builder_page_backup_2025_10_27_14_30_00 (包含所有数据)
  删除原表：guolairen_page_builder_page...
  ✓ 原表已删除：guolairen_page_builder_page

  ... (其他表)

步骤 2/3：从 modules.php 删除模块注册信息...
  ✓ 已从 modules.php 删除模块 GuoLaiRen_PageBuilder

步骤 3/3：从 module_dependencies.php 删除模块依赖信息...
  ✓ 已从 module_dependencies.php 删除模块 GuoLaiRen_PageBuilder

模块 GuoLaiRen_PageBuilder 清理完成！

开始重新安装模块...
【系统】：1、路由更新...
... (module:upgrade 输出)

═══════════════════════════════════════════════════════════════
模块重新安装完成！
═══════════════════════════════════════════════════════════════
```

## 备份验证

### 验证 SQL 文件备份

```bash
# 查看备份文件
ls -lh var/backup/db/

# 示例输出：
# guolairen_page_builder_style_20251027_140530.sql  (15KB)
# guolairen_page_builder_page_20251027_140531.sql   (42KB)
# guolairen_page_builder_form_submission_20251027_140532.sql (8KB)
```

### 验证数据库备份表

```sql
-- 查看所有备份表
SHOW TABLES LIKE '%_backup_%';

-- 示例输出：
-- guolairen_page_builder_style_backup_2025_10_27_14_30_00
-- guolairen_page_builder_page_backup_2025_10_27_14_30_00
-- guolairen_page_builder_page_local_description_backup_2025_10_27_14_30_00
-- guolairen_page_builder_form_submission_backup_2025_10_27_14_30_00
```

**批次标识**：所有表名中的 `2025_10_27_14_30_00` 相同，表示属于同一批次

### 验证备份表数据

```sql
-- 查看指定批次的备份表数据
SELECT COUNT(*) FROM guolairen_page_builder_page_backup_2025_10_27_14_30_00;

-- 对比原表重装后的数据
SELECT COUNT(*) FROM guolairen_page_builder_page;

-- 查看某个表的所有备份批次
SHOW TABLES LIKE 'guolairen_page_builder_page_backup_%';
```

## 安全特性

### 1. 开发模式限制
- ✅ 只能在 `deploy=dev` 模式下运行
- ✅ 生产环境自动拒绝执行

### 2. 用户确认机制
- ✅ 详细的警告信息
- ✅ 必须输入 "yes" 或 "y" 确认
- ✅ 其他任何输入都会取消操作

### 3. 双重备份
- ✅ SQL 文件备份（可永久保存）
- ✅ 数据库表复制（快速恢复）
- ✅ 统一批次时间戳（便于批量管理）
- ✅ 支持多次备份（不会覆盖历史备份）

### 4. 智能备份表管理
- ✅ 自动检测所有历史备份表
- ✅ 显示详细的备份表信息（模块、原表名、备份表名、创建时间、记录数）
- ✅ 按批次统计备份表
- ✅ 可选择删除或保留备份表
- ✅ 提供手动删除的 SQL 语句

### 5. 错误处理
- ✅ 单个表失败不影响其他表
- ✅ 详细的错误信息提示
- ✅ 继续处理其他表

## 使用场景

### 场景 1：测试模块安装脚本
开发模块的 `install()` 方法时，需要反复测试：

```bash
php bin/w module:reinstall -m MyModule
```

### 场景 2：修复表结构问题
表结构出现问题，需要重新创建：

```bash
php bin/w module:reinstall -m MyModule
```

### 场景 3：清理测试数据
开发测试后需要清理测试数据：

```bash
php bin/w module:reinstall -m MyModule
```

## 注意事项

### ⚠️ 警告

1. **数据会被删除**：虽然有双重备份，但原表会被删除
2. **关联数据**：删除表可能影响其他模块的关联数据
3. **外键约束**：如果有外键约束，可能导致删除失败
4. **仅限开发**：生产环境禁止使用此命令

### 💡 建议

1. **手动备份**：执行前建议手动备份数据库
2. **测试环境**：在测试环境先执行，确认无误后再用于开发环境
3. **依赖检查**：确认没有其他模块依赖要重装的模块
4. **清理备份表**：重装成功后，及时清理不需要的 _backup 表

## 故障排除

### 问题 1：表复制失败

**原因**：权限不足或表名冲突

**解决**：
```sql
-- 手动删除旧的备份表
DROP TABLE IF EXISTS `demo_table_backup`;

-- 然后重新执行命令
```

### 问题 2：备份目录不存在

**解决**：
```bash
# 创建备份目录
mkdir -p var/backup/db
chmod 755 var/backup/db
```

### 问题 3：模块重装后数据丢失

**恢复方法**：
```sql
-- 方法 1：从备份表恢复
RENAME TABLE `demo_table_backup` TO `demo_table`;

-- 方法 2：从 SQL 文件恢复
-- （使用 mysql 命令导入 SQL 文件）
```

## 相关命令

- `module:upgrade` - 升级模块（不删除数据）
- `module:remove` - 移除模块（删除数据）
- `model:rebuild` - 重建单个模型的表
- `deploy:mode:set` - 设置部署模式

## 版本历史

### v2.0.0 (2025-10-27)
- ✅ 添加数据库表复制功能（_backup 后缀）
- ✅ 双重备份保护机制
- ✅ 改进的用户提示信息
- ✅ 更详细的帮助文档

### v1.0.0
- 初始版本
- SQL 文件备份
- 表删除和重新安装

## 示例代码

### PHP 中使用

```php
use Weline\Framework\Module\Console\Module\Reinstall;
use Weline\Framework\Manager\ObjectManager;

$reinstall = ObjectManager::getInstance(Reinstall::class);
$reinstall->execute(['module' => 'MyModule']);
```

### 查看备份表

```php
use GuoLaiRen\PageBuilder\Model\Page;
use Weline\Framework\Manager\ObjectManager;

$pageModel = ObjectManager::getInstance(Page::class);
$pdo = $pageModel->getConnection()->getConnector()->getLink();

// 查找所有备份表
$stmt = $pdo->query("SHOW TABLES LIKE '%_backup'");
while ($row = $stmt->fetch()) {
    echo "备份表: " . $row[0] . "\n";
}
```

## 最佳实践

### 1. 重装前检查清单

- [ ] 确认在开发模式
- [ ] 确认要重装的模块名称正确
- [ ] 确认已手动备份重要数据
- [ ] 确认没有其他模块强依赖
- [ ] 确认磁盘空间足够（备份文件）

### 2. 重装后检查清单

- [ ] 验证模块是否重新注册（`module:listing`）
- [ ] 验证表是否重新创建（查看数据库）
- [ ] 验证功能是否正常
- [ ] 清理不需要的备份表
- [ ] 清理旧的 SQL 备份文件（可选）

### 3. 备份管理

```bash
# 定期清理旧备份（保留最近30天）
find var/backup/db/ -name "*.sql" -mtime +30 -delete
```

```sql
-- 查看所有备份批次
SHOW TABLES LIKE '%_backup_%';

-- 清理指定批次的备份表（推荐）
DROP TABLE IF EXISTS guolairen_page_builder_style_backup_2025_10_26_10_15_30;
DROP TABLE IF EXISTS guolairen_page_builder_page_backup_2025_10_26_10_15_30;

-- 保留最新批次
-- 2025_10_27_14_30_00 - 保留
-- 2025_10_26_10_15_30 - 可删除
```

### 4. 批次管理建议

- **保留策略**：建议保留最近 1-2 个批次的备份
- **删除旧批次**：超过 1 周的备份可以定期清理
- **重要数据**：生产数据的备份建议长期保留
- **批次识别**：通过时间戳识别批次（格式：YYYY_MM_DD_HH_ii_ss）

## 总结

`module:reinstall` 命令提供了安全、可靠的模块重装功能：

✅ **双重保护**：SQL文件 + 数据库表复制  
✅ **快速恢复**：_backup_时间戳 表可快速恢复数据  
✅ **批次管理**：统一时间戳，便于批量操作  
✅ **多版本备份**：支持多次备份，不会覆盖历史  
✅ **智能清理**：自动检测备份表，可选择删除  
✅ **安全机制**：开发模式限制 + 用户确认  
✅ **完整流程**：备份 → 复制 → 删除 → 重装 → 清理  
✅ **详细日志**：每个步骤都有清晰的输出  

开发过程中需要反复测试模块安装脚本时，这个命令可以大大提高效率！🚀

