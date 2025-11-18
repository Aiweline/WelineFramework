# ExtendsData 静态类使用文档

## 概述

`ExtendsData` 是一个静态类，提供便捷的静态方法来读取 `generated/extends.php` 文件中的扩展数据。其他模块（如 `Weline_Sticker`）可以直接使用这个类来获取扩展信息，而无需再次扫描文件系统。

## 核心优势

1. **无需依赖注入**：静态方法，可直接调用
2. **自动缓存**：内置内存缓存机制，性能优异
3. **文件变更检测**：自动检测文件修改时间，确保数据最新
4. **丰富的查询方法**：提供多种便捷的查询方法

## 基本使用

### 1. 获取模块的完整数据

```php
use Weline\Framework\Extends\ExtendsData;

// 获取 Weline_Sticker 模块的完整扩展数据
$moduleData = ExtendsData::getModuleData('Weline_Sticker');

// 返回结构：
// [
//   'extends' => [...],           // 模块定义的扩展点
//   'extended_by' => [...],       // 被其他模块扩展的信息
//   'completeness' => [...],      // 完备性检查信息
//   'enhanced_extensions' => [...], // 增强的扩展信息
//   'stats' => [...]              // 统计信息
// ]
```

### 2. 获取模块定义的扩展点

```php
// 获取模块定义的扩展点信息
$extends = ExtendsData::getModuleExtends('Weline_Sticker');
```

### 3. 获取扩展该模块的其他模块信息

```php
// 获取所有扩展 Weline_Sticker 的模块信息
$extendedBy = ExtendsData::getExtendedBy('Weline_Sticker');

// 返回格式：['Weline_MyModule' => [...扩展信息...]]
```

### 4. 获取 Sticker 扩展信息

```php
// 获取指定模块的所有 Sticker 扩展
$stickerExtensions = ExtendsData::getStickerExtensions('Weline_Demo');

// 返回增强的扩展信息数组，包含：
// - file_type: 文件类型
// - complexity: 复杂度评级
// - last_modified: 最后修改时间
// - impact_scope: 影响范围评估
// - source_module: 源模块
// - target_module: 目标模块
// - file_path: 文件路径
// 等等...
```

### 5. 获取模块扩展和主题扩展

```php
// 获取普通模块扩展
$moduleExtensions = ExtendsData::getModuleExtensions('Weline_Demo');

// 获取主题扩展
$themeExtensions = ExtendsData::getThemeExtensions('Weline_Demo');
```

### 6. 获取统计信息

```php
// 获取模块的统计信息
$stats = ExtendsData::getModuleStats('Weline_Demo');

// 返回：
// [
//   'total_extensions' => 5,
//   'sticker_count' => 2,
//   'module_count' => 2,
//   'theme_count' => 1,
//   'complexity_distribution' => [...],
//   'impact_distribution' => [...],
//   'file_type_distribution' => [...]
// ]
```

## 高级使用

### 1. 检查模块是否有扩展定义

```php
// 检查模块是否定义了扩展点
if (ExtendsData::hasExtends('Weline_Sticker')) {
    echo "该模块定义了扩展点";
}
```

### 2. 检查模块是否被扩展

```php
// 检查模块是否被其他模块扩展
if (ExtendsData::isExtendedBy('Weline_Demo')) {
    echo "该模块被其他模块扩展了";
}

// 检查是否被 Sticker 扩展
if (ExtendsData::isStickerExtended('Weline_Demo')) {
    echo "该模块有 Sticker 扩展";
}
```

### 3. 获取所有模块列表

```php
// 获取所有模块名
$allModules = ExtendsData::getAllModuleNames();

// 获取有扩展定义的模块
$modulesWithExtends = ExtendsData::getModulesWithExtends();

// 获取被扩展的模块
$extendedModules = ExtendsData::getExtendedModules();
```

### 4. 获取所有 Sticker 扩展（跨模块）

```php
// 获取所有 Sticker 扩展信息
$allStickers = ExtendsData::getAllStickerExtensions();

// 返回格式：['Weline_Sticker' => [...扩展信息...]]
```

### 5. 根据源模块获取扩展信息

```php
// 获取 Weline_Sticker 模块扩展的所有目标模块
$extensions = ExtendsData::getExtensionsBySourceModule('Weline_Sticker');

// 返回格式：['Weline_Demo' => [...扩展信息...]]
```

### 6. 根据文件路径查找扩展信息

```php
// 根据文件路径查找扩展信息
$extension = ExtendsData::findExtensionByFilePath('view/templates/Backend/index.phtml');

if ($extension) {
    echo "找到扩展：{$extension['source_module']} 扩展了 {$extension['target_module']}";
}
```

## Weline_Sticker 模块使用示例

### 示例 1：获取所有 Sticker 扩展

```php
namespace Weline\Sticker\Service;

use Weline\Framework\Extends\ExtendsData;

class StickerService
{
    /**
     * 获取所有 Sticker 扩展（使用 ExtendsData 而不是重新扫描）
     */
    public function getAllStickerExtensions(): array
    {
        // 直接使用 ExtendsData 获取所有 Sticker 扩展
        return ExtendsData::getAllStickerExtensions();
    }
    
    /**
     * 获取指定模块的 Sticker 扩展
     */
    public function getModuleStickerExtensions(string $targetModule): array
    {
        return ExtendsData::getStickerExtensions($targetModule);
    }
    
    /**
     * 检查模块是否有 Sticker 扩展
     */
    public function hasStickerExtensions(string $targetModule): bool
    {
        return ExtendsData::isStickerExtended($targetModule);
    }
}
```

### 示例 2：获取 Weline_Sticker 模块扩展的所有目标模块

```php
use Weline\Framework\Extends\ExtendsData;

// 获取 Weline_Sticker 模块扩展的所有目标模块
$targetModules = ExtendsData::getExtensionsBySourceModule('Weline_Sticker');

foreach ($targetModules as $targetModule => $extensions) {
    echo "目标模块: {$targetModule}\n";
    foreach ($extensions as $extension) {
        echo "  - 文件: {$extension['file_path']}\n";
        echo "  - 源文件: {$extension['source_file']}\n";
        echo "  - 类型: {$extension['type']}\n";
    }
}
```

### 示例 3：检查文件是否有 Sticker 扩展

```php
use Weline\Framework\Extends\ExtendsData;

// 检查特定文件是否有 Sticker 扩展
$extension = ExtendsData::findExtensionByFilePath('Weline/Demo/view/templates/Backend/index.phtml');

if ($extension && ($extension['is_sticker_extension'] ?? false)) {
    echo "该文件有 Sticker 扩展\n";
    echo "源模块: {$extension['source_module']}\n";
    echo "目标模块: {$extension['target_module']}\n";
}
```

## 缓存管理

### 强制重新加载

```php
// 强制重新加载数据（忽略缓存）
$data = ExtendsData::getModuleData('Weline_Sticker', true);
```

### 清除缓存

```php
// 清除静态缓存
ExtendsData::clearCache();
```

### 检查文件状态

```php
// 检查注册表文件是否存在
if (ExtendsData::registryFileExists()) {
    echo "注册表文件存在\n";
}

// 获取文件修改时间
$mtime = ExtendsData::getRegistryFileMtime();
echo "文件最后修改时间: " . date('Y-m-d H:i:s', $mtime) . "\n";
```

## 性能优化建议

1. **利用缓存**：`ExtendsData` 内置了内存缓存机制，自动检测文件修改时间，无需手动管理
2. **按需加载**：只在需要时调用相应的方法，避免加载不必要的数据
3. **批量查询**：如果需要查询多个模块，考虑使用 `getAllModuleNames()` 然后循环查询

## 注意事项

1. **文件依赖**：`ExtendsData` 依赖于 `generated/extends.php` 文件，确保该文件已生成
2. **数据同步**：如果扩展数据发生变化，需要运行扫描命令更新 `generated/extends.php` 文件
3. **错误处理**：如果文件不存在或数据格式错误，方法会返回空数组或 `null`，不会抛出异常

## 与 ExtendsRegistry 的区别

- **ExtendsRegistry**：需要依赖注入，提供完整的读写功能，用于管理注册表
- **ExtendsData**：静态类，只读，提供便捷的查询方法，适合其他模块快速获取数据

## 相关文件

- `app/code/Weline/Framework/Extends/ExtendsData.php` - 静态数据读取类
- `app/code/Weline/Framework/Extends/ExtendsRegistry.php` - 注册表管理类
- `app/code/Weline/Framework/Extends/ExtendsScanner.php` - 扩展扫描器
- `generated/extends.php` - 生成的扩展注册表文件

