# 字段备份与恢复系统

## 概述

框架层提供了字段级别的备份和恢复机制，遵循SOLID原则，专门处理字段删除前的数据备份和字段添加后的数据恢复。

## 测试状态

✅ **单元测试**: FieldBackupServiceTest (3/3 通过)
- testBackupFieldData_WithData - 有数据的字段备份
- testBackupFieldData_EmptyTable - 空表字段备份（仅备份定义）
- testRestoreFieldData_Success - 字段数据恢复

## 架构设计

### 核心组件

1. **FieldBackupService** (`Weline\Framework\Setup\Db\Service\FieldBackupService`)
   - 负责字段数据的备份和恢复
   - 单一职责：专门处理字段级别的数据备份和恢复

2. **FieldBackup Model** (`Weline\Framework\Setup\Model\FieldBackup`)
   - 存储字段**数据值**的备份（行级数据）
   - 记录模块、表名、字段名、主键值、字段值、**模块版本**等信息

3. **FieldDefinitionBackup Model** (`Weline\Framework\Setup\Model\FieldDefinitionBackup`)
   - 存储字段**结构定义信息**（DDL 元数据，如类型、长度、是否可空、默认值、注释等）
   - 以 JSON 形式保存底层 `information_schema` / `PRAGMA` 返回的一整行结构数据
   - 同样按「模块 + 表名 + 字段名 + **模块版本**」维度管理，方便对比不同时期结构差异

4. **ModelSetup 扩展方法**
   - `deleteColumnWithBackup()` - 删除字段前自动备份
   - `addColumnWithRestore()` - 添加字段后自动恢复

## 使用方式

### 1. 删除字段（自动备份）

在 `upgrade()` 或 `remove()` 方法中，直接使用 `deleteColumn()` 方法，系统会自动备份数据：

```php
public function upgrade(ModelSetup $setup, Context $context): void
{
    // 删除字段前自动备份数据（无需额外调用）
    if ($setup->tableExist() && $setup->hasField('old_field')) {
        $setup->alterTable()->deleteColumn('old_field')->alter();
    }
}
```

### 2. 添加字段（自动恢复）

在 `upgrade()` 或 `install()` 方法中，直接使用 `addColumn()` 方法，系统会自动恢复之前备份的数据：

```php
public function upgrade(ModelSetup $setup, Context $context): void
{
    // 添加字段后自动恢复之前备份的数据（无需额外调用）
    if ($setup->tableExist() && !$setup->hasField('new_field')) {
        $setup->alterTable()->addColumn(
            'new_field',
            '',  // after_column
            TableInterface::column_type_TEXT,
            0,   // length
            '',  // options
            '字段注释'
        )->alter();
    }
}
```

### 3. 工作原理

- `ModelSetup::alterTable()` 返回一个包装的 `AlterWithBackup` 对象
- 调用 `addColumn()` 时，系统记录字段信息
- 调用 `deleteColumn()` 时，系统记录要删除的字段
- 执行 `alter()` 时：
  - **删除字段前**：自动备份字段数据
  - **添加字段后**：自动恢复之前备份的数据

## 工作流程

### 升级流程（添加字段）

1. 调用 `addColumnWithRestore()` 添加字段
2. 系统自动检查是否有该字段的备份数据
3. 如果有备份，自动恢复数据到新字段
4. 标记备份记录为已恢复

### 回滚流程（删除字段）

1. 调用 `deleteColumnWithBackup()` 删除字段
2. 系统自动备份字段数据（主键 + 字段值）
3. 保存备份到 `weline_framework_field_backup` 表
4. 执行字段删除操作

### 再次升级流程（恢复字段）

1. 调用 `addColumnWithRestore()` 添加字段
2. 系统自动查找该字段的备份数据
3. 根据主键值恢复数据
4. 标记备份记录为已恢复

## 备份表结构

```sql
CREATE TABLE weline_framework_field_backup (
    backup_id INT PRIMARY KEY AUTO_INCREMENT,
    module VARCHAR(100) NOT NULL COMMENT '模块名称',
    table_name VARCHAR(100) NOT NULL COMMENT '表名',
    field_name VARCHAR(100) NOT NULL COMMENT '字段名',
    primary_key VARCHAR(50) NOT NULL COMMENT '主键字段名',
    primary_value VARCHAR(100) NOT NULL COMMENT '主键值',
    field_value TEXT COMMENT '字段值（JSON格式）',
    version VARCHAR(20) NOT NULL COMMENT '模块版本号',
    backup_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '备份时间',
    restored SMALLINT(1) NOT NULL DEFAULT 0 COMMENT '是否已恢复：0未恢复，1已恢复',
    restore_time DATETIME COMMENT '恢复时间',
    INDEX idx_module_table_field (module, table_name, field_name),
    INDEX idx_primary (table_name, primary_key, primary_value),
    INDEX idx_restored (restored),
    INDEX idx_version (version)
);

CREATE TABLE weline_framework_field_definition_backup (
    definition_id INT PRIMARY KEY AUTO_INCREMENT,
    module VARCHAR(100) NOT NULL COMMENT '模块名称',
    table_name VARCHAR(100) NOT NULL COMMENT '表名',
    field_name VARCHAR(100) NOT NULL COMMENT '字段名',
    version VARCHAR(20) NOT NULL COMMENT '模块版本号',
    definition TEXT COMMENT '字段定义信息（JSON，来源于 information_schema / PRAGMA）',
    backup_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '备份时间',
    INDEX idx_def_module_table_field (module, table_name, field_name),
    INDEX idx_def_version (version)
);
```

## 注意事项

1. **备份表初始化**：框架会在首次使用时自动创建备份表
2. **主键要求**：系统使用模型的主键字段来关联备份数据
3. **版本管理**：备份数据按模块版本存储，支持按版本恢复
4. **数据格式**：
   - 字段值以 JSON 格式存储（`field_value`），支持复杂类型并保留编码
   - 字段结构定义以 JSON 格式存储（`definition`），原样记录 `information_schema`/`PRAGMA` 的完整行
5. **NULL值处理**：字段值为 `NULL` 时，同样会在数据备份中记录并在恢复时还原为 `NULL`
6. **冲突处理（值冲突）**：
   - 如果在恢复时，目标表中某条记录的该字段已经有**非空值**（可能是回滚后人工 / 新版本产生的数据），系统**不会覆盖**现有值；
   - 这类冲突会记录到 `weline_framework_field_backup_conflict` 表中，包括：
     - 模块、表名、字段名、主键信息
     - 备份中的旧值（backup_value）
     - 当前表中的现值（current_value）
     - 发生冲突时的版本和时间
   - 原始备份记录不会被标记为 `restored`，以便后续人工比对与决策。
7. **与系统无关，仅绑定模块版本**：
   - 所有备份记录都以「模块名 + 模块版本」为关键维度进行管理；
   - 不依赖任何系统级别的全局版本号，方便单模块独立升级 / 回滚。

## 示例

### 完整示例：字段升级和回滚

```php
class Page extends Model
{
    public function upgrade(ModelSetup $setup, Context $context): void
    {
        // 场景1：删除旧字段（自动备份数据）
        if ($setup->tableExist() && $setup->hasField('old_field')) {
            $setup->alterTable()->deleteColumn('old_field')->alter();
            // ↑ 执行 alter() 前会自动备份 old_field 的数据
        }
        
        // 场景2：添加新字段（自动恢复之前备份的数据）
        if ($setup->tableExist() && !$setup->hasField('new_field')) {
            $setup->alterTable()->addColumn(
                'new_field',
                '',
                TableInterface::column_type_TEXT,
                0,
                '',
                '新字段注释'
            )->alter();
            // ↑ 执行 alter() 后会自动检查并恢复 new_field 的备份数据（如果有）
        }
    }
}
```

## 优势

1. **自动化**：无需手动编写备份和恢复逻辑
2. **安全性**：删除字段前自动备份，避免数据丢失
3. **可恢复**：支持字段回滚后再次升级时自动恢复数据
4. **版本化**：按模块版本管理备份，支持多版本共存
5. **框架层支持**：遵循SOLID原则，在框架层统一实现
