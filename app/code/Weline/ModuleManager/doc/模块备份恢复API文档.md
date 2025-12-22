# 模块备份与恢复 API 文档

本文档说明 `Weline\ModuleManager\Service\ModuleBackupService` 提供的核心 API，用于在代码中直接调用模块级数据库备份与恢复能力。

## 服务类

```php
Weline\ModuleManager\Service\ModuleBackupService
```

获取方式：

```php
use Weline\Framework\Manager\ObjectManager;
use Weline\ModuleManager\Service\ModuleBackupService;

/** @var ModuleBackupService $service */
$service = ObjectManager::getInstance(ModuleBackupService::class);
```

## 1. backupModuleTables

```php
public function backupModuleTables(string $moduleName): array
```

### 功能

按模块名备份所有注册的数据库表，通过 **重命名表** 实现快速备份。

### 参数

- `string $moduleName`：模块名，例如 `Weline_Demo`。

### 返回值

```php
[
    'success'          => bool,          // 是否成功
    'message'          => string,        // 提示信息
    'backup_timestamp' => string,        // 备份时间戳 YYYYMMDD_HHMMSS
    'backup_id'        => int,           // 备份记录ID（可能为0，表示未写入记录）
    'table_count'      => int,           // 备份表数量
    'tables'           => array,         // 备份表详情
]
```

`tables` 结构示例：

```php
[
    [
        'original_name' => 'we_demo_table',
        'backup_name'   => 'we_demo_table_backup_20250127_143000',
        'record_count'  => 120,
    ],
]
```

### 使用示例

```php
$result = $service->backupModuleTables('Weline_Demo');
if (!empty($result['success'])) {
    // 记录备份信息或继续卸载流程
} else {
    throw new \RuntimeException($result['message'] ?? '模块备份失败');
}
```

## 2. restoreModuleTables

```php
public function restoreModuleTables(string $moduleName, ?string $backupTimestamp = null): array
```

### 功能

从备份记录中恢复指定模块的所有表：

- 删除当前同名表：`DROP TABLE IF EXISTS 原表名`
- 将备份表重命名回原表名：`ALTER TABLE 备份表 RENAME TO 原表名`

### 参数

- `string $moduleName`：模块名。
- `?string $backupTimestamp`：可选，备份时间戳。如为空，则使用该模块最新一次备份。

### 返回值

```php
[
    'success'   => bool,
    'message'   => string,
    'backup_id' => int,   // 使用的备份记录ID
]
```

### 使用示例

```php
// 恢复最新备份
$result = $service->restoreModuleTables('Weline_Demo');

// 恢复指定时间戳的备份
$result = $service->restoreModuleTables('Weline_Demo', '20250127_143000');
```

## 3. getModuleBackups

```php
public function getModuleBackups(string $moduleName): array
```

### 功能

获取指定模块的所有备份记录，按时间戳倒序排列。

### 返回值

数组中每一项为一条 `Backup` 记录的原始数据：

```php
[
    [
        'backup_id'        => 1,
        'module_name'      => 'Weline_Demo',
        'backup_timestamp' => '20250127_143000',
        'backup_date'      => '2025-01-27 14:30:00',
        'table_count'      => 3,
        'tables'           => [...],
        'status'           => 'active',
        'created_at'       => '2025-01-27 14:30:01',
        'restored_at'      => null,
    ],
    // ...
]
```

### 使用示例

```php
$backups = $service->getModuleBackups('Weline_Demo');
foreach ($backups as $backup) {
    // 展示备份批次列表
}
```

## 4. getLatestBackup

```php
public function getLatestBackup(string $moduleName): ?array
```

### 功能

获取指定模块最近一次备份的完整记录数据。

### 返回值

- `array`：最近一次备份的数据。
- `null`：如果没有备份记录。

### 使用示例

```php
$latest = $service->getLatestBackup('Weline_Demo');
if ($latest) {
    $timestamp = $latest['backup_timestamp'];
}
```

## 5. deleteBackup

```php
public function deleteBackup(string $moduleName, string $backupTimestamp): array
```

### 功能

将指定模块某个时间戳的备份记录标记为 `deleted`。

> 注意：当前实现**仅修改记录状态，不删除实际备份表**，物理清理交由后续清理工具或运维脚本处理。

### 参数

- `string $moduleName`：模块名。
- `string $backupTimestamp`：备份时间戳。

### 返回值

```php
[
    'success' => bool,
    'message' => string,
]
```

## 异常与错误处理

- 所有方法都返回结构化数组，不直接抛出异常（除非框架底层连接异常）。
- 调用方应根据 `success` 字段决定后续逻辑：
  - 失败时记录日志、提示用户或中断危险操作（如卸载）。
- 在 CLI 场景下，`ModuleBackupService` 会通过 `Printing` 输出必要的提示与错误信息。


