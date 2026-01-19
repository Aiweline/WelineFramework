# Hook 优先级和排序顺序使用指南

## 概述

Hook系统支持在Hook实现文件中通过注释定义优先级和排序顺序，让模块可以精确控制Hook的执行顺序。

## 定义方式

### 方式1：使用 @hook-priority 和 @hook-sort-order 标签（推荐）

在Hook实现文件的开头注释中添加：

```php
<?php
/**
 * 模块名称 - Hook描述
 * 
 * Hook名称：HookName
 * 
 * @hook-priority 200      Hook优先级：200（数字越大越优先）
 * @hook-sort-order 1      Hook排序顺序：1（数字越小越优先）
 */
```

### 方式2：使用中文注释

```php
<?php
/**
 * 模块名称 - Hook描述
 * 
 * Hook名称：HookName
 * Hook优先级：200
 * Hook排序顺序：1
 */
```

### 方式3：使用简化格式

```php
<?php
/**
 * 模块名称 - Hook描述
 * 
 * Hook名称：HookName
 * 优先级：200
 * 排序顺序：1
 */
```

## 优先级说明

### 默认优先级（根据模块位置）

如果Hook文件中没有定义优先级，系统会根据模块位置自动计算：

| 模块位置 | 默认优先级 |
|---------|-----------|
| app | 200 |
| composer | 150 |
| framework | 100 |
| system | 50 |

### 自定义优先级

通过注释定义的优先级会覆盖默认优先级。

**优先级规则**：
- 数字越大越优先（降序）
- 优先级高的Hook会先执行

## 排序顺序说明

### 默认排序顺序

如果Hook文件中没有定义排序顺序，系统会使用模块扫描顺序（`moduleOrder++`）。

### 自定义排序顺序

通过注释定义的排序顺序会覆盖默认排序顺序。

**排序顺序规则**：
- 数字越小越优先（升序）
- 当优先级相同时，按排序顺序排序

## 完整排序规则

Hook执行顺序由以下规则决定（按优先级从高到低）：

1. **优先级（priority）**：数字越大越优先（降序）
2. **排序顺序（sort_order）**：数字越小越优先（升序）
3. **模块位置优先级**：app > composer > framework > system
4. **模块依赖顺序**：按模块加载顺序
5. **模块名排序**：作为最后的排序依据

## 使用示例

### 示例1：让I18n模块在Weline_Theme之后执行

文件路径：`view/hooks/Weline_Theme/frontend/layouts/base/html-attr.phtml`

```php
<?php
/**
 * I18n模块 - HTML语言属性Hook
 * 
 * 返回当前HTML语言代码，用于<html lang="">标签
 * Hook名称：Weline_Theme::frontend::layouts::base::html-attr
 * 
 * @hook-priority 50   Hook优先级：50（与Weline_Theme相同）
 * @hook-sort-order 1  Hook排序顺序：1（在Weline_Theme之后执行）
 */
use Weline\Framework\Http\Cookie;

// ... hook实现代码 ...
```

**说明**：
- 设置相同的优先级（50），但使用更大的sort_order（1），确保在Weline_Theme（sort_order=0）之后执行

### 示例2：确保某个Hook最先执行

文件路径：`view/hooks/Weline_Theme/frontend/layouts/base/head-before.phtml`

```php
<?php
/**
 * Custom模块 - 自定义Hook
 * 
 * Hook名称：Weline_Theme::frontend::layouts::base::head-before
 * 
 * @hook-priority 300  Hook优先级：300（确保最先执行）
 * @hook-sort-order 0  Hook排序顺序：0
 */
```

### 示例3：确保某个Hook最后执行

文件路径：`view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml`

```php
<?php
/**
 * Custom模块 - 自定义Hook
 * 
 * Hook名称：Weline_Theme::frontend::layouts::base::body-end
 * 
 * @hook-priority 50   Hook优先级：50（较低优先级）
 * @hook-sort-order 999  Hook排序顺序：999（较大的排序顺序）
 */
```

## 注意事项

1. **优先级和排序顺序都是可选的**：如果不定义，系统会使用默认值
2. **优先级优先于排序顺序**：即使sort_order很小，如果priority很大，也会先执行
3. **相同优先级时按排序顺序**：当多个Hook的优先级相同时，按sort_order升序排序
4. **注释格式灵活**：支持多种注释格式，系统会自动识别

## 验证方式

运行 `php bin/w hook:rebuild` 后，可以在 `generated/hooks.php` 文件中查看每个Hook的 `implementations` 字段，确认优先级和排序顺序是否正确设置。

```php
'implementations' => [
    'Weline_Theme' => [
        'file' => 'view/hooks/Weline_Theme/frontend/layouts/base/html-attr.phtml',
        'priority' => 50,
        'sort_order' => 0,
        'solo' => false
    ],
    'Weline_I18n' => [
        'file' => 'view/hooks/Weline_Theme/frontend/layouts/base/html-attr.phtml',
        'priority' => 50,  // 从注释中解析的优先级
        'sort_order' => 1,  // 从注释中解析的排序顺序
        'solo' => true       // 从注释中解析的独享模式
    ],
],
```
