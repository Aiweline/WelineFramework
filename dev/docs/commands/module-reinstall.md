# Module Reinstall 命令文档

## 概述

`module:reinstall` 命令用于完全重新安装指定的模块。这是一个**危险操作**，会删除模块的所有数据库表和注册信息，然后重新安装模块。

## 安全限制

- ⚠️ **仅限开发模式**：此命令只能在 `deploy=dev` 模式下运行
- ⚠️ **需要确认**：执行前需要输入 `yes` 或 `y` 确认
- ⚠️ **数据删除**：所有模块数据将被永久删除
- ✅ **自动备份**：所有数据库表会自动备份到 `var/backup/db/` 目录

## 使用方法

### 基本语法

```bash
php bin/w module:reinstall -m <模块名>
```

### 选项

- `-m, --module=<模块名>` - 指定要重新安装的模块（必填，支持多个模块用空格分隔）
- `-h, --help` - 显示帮助信息

## 示例

### 重新安装单个模块

```bash
php bin/w module:reinstall -m Weline_Demo
```

### 重新安装多个模块

```bash
php bin/w module:reinstall --module "Weline_Demo Weline_Test"
```

## 执行流程

当你运行此命令时，系统会按以下步骤执行：

### 1. 安全检查

- 检查是否在开发模式（`deploy=dev`）
- 验证指定的模块是否存在

### 2. 显示警告信息

命令会显示详细的警告信息，包括：
- 即将重新安装的模块列表
- 将要执行的操作步骤
- 潜在的风险和影响

### 3. 等待用户确认

- 用户需要输入 `yes` 或 `y` 确认继续
- 输入其他任何内容将取消操作

### 4. 对每个模块执行以下操作

#### 步骤 1：备份并删除数据库表
- 扫描模块的所有 Model 类
- 使用 Query 的 `backup()` 函数备份每个表
- 备份文件保存到 `var/backup/db/<表名>/` 目录
- 删除所有表

#### 步骤 2：从 modules.php 删除模块注册
- 从 `app/etc/modules.php` 文件中删除模块数据

#### 步骤 3：从 module_dependencies.php 删除依赖信息
- 从 `app/etc/module_dependencies.php` 文件中删除模块依赖数据

#### 步骤 4：重新安装模块
- 调用 `module:upgrade` 命令重新安装指定的模块

## 警告信息示例

```
╔════════════════════════════════════════════════════════════════╗
║                      ⚠️  危险操作警告 ⚠️                        ║
╚════════════════════════════════════════════════════════════════╝

您即将重新安装以下模块：
  - Weline_Demo

此操作将执行以下步骤：
1. 备份模块的所有数据库表（备份到 var/backup/db/ 目录）
2. 删除模块的所有数据库表
3. 从 app/etc/modules.php 中删除模块注册信息
4. 从 app/etc/module_dependencies.php 中删除模块依赖信息
5. 重新安装指定的模块

⚠️  警告：此操作不可逆！所有模块数据将被永久删除！
⚠️  警告：虽然会自动备份，但请确保您已手动备份重要数据！
⚠️  警告：如果有其他模块依赖于这些模块，可能会导致系统错误！

请输入 "yes" 或 "y" 确认继续，输入其他任何内容取消：
```

## 常见使用场景

### 1. 模块开发调试
在开发过程中需要完全重置模块状态：

```bash
php bin/w module:reinstall -m MyVendor_MyModule
```

### 2. 数据库结构更新
当模块的数据库结构有重大变更时：

```bash
php bin/w module:reinstall -m MyVendor_MyModule
```

### 3. 清理测试数据
开发完成后需要清理所有测试数据：

```bash
php bin/w module:reinstall -m MyVendor_MyModule
```

## 注意事项

1. **⚠️ 仅用于开发环境**
   - 绝不要在生产环境运行此命令
   - 生产环境会拒绝执行

2. **⚠️ 数据备份**
   - 虽然系统会自动备份数据库表
   - 但强烈建议在执行前手动备份重要数据
   - 备份文件位于 `var/backup/db/` 目录

3. **⚠️ 模块依赖**
   - 如果其他模块依赖于要重新安装的模块
   - 可能会导致系统错误
   - 请确认依赖关系后再执行

4. **⚠️ 操作不可逆**
   - 一旦确认执行，数据将被永久删除
   - 只能通过备份文件恢复

## 错误处理

### 非开发模式错误

```
错误：此命令只能在开发模式下运行！
当前部署模式：prod
请使用以下命令切换到开发模式：
php bin/w deploy:mode:set dev
```

### 模块不存在错误

```
错误：模块 Weline_Demo 不存在！
```

### 未指定模块错误

```
错误：请指定要重新安装的模块！
用法示例：
php bin/w module:reinstall -m Weline_Demo
php bin/w module:reinstall --module "Weline_Demo Weline_Test"
```

## 技术实现

### 文件位置
```
app/code/Weline/Framework/Module/Console/Module/Reinstall.php
```

### 主要功能

1. **开发模式检查**
   ```php
   $deploy_mode = Env::get('deploy', 'prod');
   if ($deploy_mode !== 'dev' && $deploy_mode !== 'development') {
       // 拒绝执行
   }
   ```

2. **数据库表备份**
   ```php
   $query = $model->getConnection()->getQuery();
   $query->backup('', $tableName);
   ```

3. **删除表**
   ```php
   $modelSetup->dropTable($tableName);
   ```

4. **更新配置文件**
   - 从 `modules.php` 删除模块
   - 从 `module_dependencies.php` 删除依赖

5. **重新安装**
   ```php
   $upgradeCommand->execute(['module' => $moduleNames]);
   ```

## 相关命令

- `module:upgrade` - 升级模块
- `module:remove` - 移除模块
- `model:rebuild` - 重建模型数据表
- `deploy:mode:set` - 设置部署模式

## 总结

`module:reinstall` 是一个强大但危险的命令，适合在开发过程中使用。它提供了完整的模块重置功能，包括数据库表备份、删除和重新安装。使用时请务必谨慎，确保在正确的环境中执行，并做好数据备份。


