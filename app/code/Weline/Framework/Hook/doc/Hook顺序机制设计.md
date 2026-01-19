# Hook 顺序机制设计文档

## 概述

Hook系统现在支持多个模块实现同一个Hook，并且支持按顺序执行。本文档详细说明了Hook顺序机制的设计和实现。

## 核心概念

### 1. Hook规约 vs Hook实现

- **Hook规约（定义）**：在模块的 `hook.php` 文件中定义，只能被一个模块定义
- **Hook实现文件**：在模块的 `view/hooks/` 目录下创建 `.phtml` 文件，多个模块可以实现同一个Hook

### 2. Hook顺序机制

Hook执行顺序由以下规则决定（按优先级从高到低）：

1. **优先级（priority）**：数字越大越优先（降序）
2. **排序顺序（sort_order）**：数字越小越优先（升序）
3. **模块位置优先级**：app > composer > framework > system
4. **模块依赖顺序**：按模块加载顺序
5. **模块名排序**：作为最后的排序依据

## 数据结构

### Hook文件列表格式

Hook文件列表格式：
```php
[
    'ModuleName' => [
        'file' => 'view/hooks/HookName/path/to/file.phtml',
        'priority' => 200,      // 优先级（可选，默认根据模块位置计算）
        'sort_order' => 1,       // 排序顺序（可选，默认使用模块顺序）
        'solo' => false          // 独享模式（可选，默认false）
    ]
]
```

### Hook注册表格式

Hook注册表（`generated/hooks.php`）格式已更新，现在包含Hook实现文件信息：

```php
[
    'hooks' => [
        'HookName' => [
            'name' => 'Hook显示名',
            'description' => 'Hook描述',
            'doc' => 'doc文件路径',
            'doc_path' => 'doc/hook/...',
            'has_spec' => true,
            'has_doc' => true,
            'module' => '定义该Hook规约的模块名',  // 只能有一个
            'implementations' => [  // 新增：Hook实现文件列表（已按顺序排序）
                'Module1' => [
                    'file' => 'view/hooks/HookName.phtml',
                    'priority' => 200,
                    'sort_order' => 0
                ],
                'Module2' => [
                    'file' => 'view/hooks/HookName.phtml',
                    'priority' => 150,
                    'sort_order' => 1
                ]
            ]
        ]
    ],
    'hook_to_module' => [
        'HookName' => '定义该Hook规约的模块名'
    ]
]
```

## 默认优先级计算

如果Hook文件中没有定义优先级，系统会根据模块位置自动计算：

| 模块位置 | 默认优先级 |
|---------|-----------|
| app | 200 |
| composer | 150 |
| framework | 100 |
| system | 50 |

## 使用示例

### 示例1：多个模块实现同一个Hook

假设有三个模块都实现了 `Weline_Theme::frontend::layouts::base::head-after` Hook：

1. **Weline_Theme**（framework位置）：默认优先级 100
2. **Weline_I18n**（composer位置）：默认优先级 150
3. **Custom_Module**（app位置）：默认优先级 200

执行顺序（从先到后）：
1. Custom_Module（优先级200，app位置）
2. Weline_I18n（优先级150，composer位置）
3. Weline_Theme（优先级100，framework位置）

### 示例2：自定义优先级

在Hook文件中通过注释指定自定义优先级：

```php
<?php
/**
 * Custom模块 - 自定义Hook
 * 
 * Hook名称：Weline_Theme::frontend::layouts::base::head-after
 * 
 * @hook-priority 300  Hook优先级：300（确保最先执行）
 * @hook-sort-order 0  Hook排序顺序：0
 */
```

文件路径：`view/hooks/Weline_Theme/frontend/layouts/base/head-after.phtml`

## 实现细节

### 1. HookReader::filterAndSortHooks()

该方法负责：
- 过滤掉禁用的模块
- 从Hook注册表读取实现文件信息（包含优先级、排序顺序、solo状态）
- 按顺序排序Hook文件

### 2. HookReader::calculateModulePriority()

该方法根据模块位置计算默认优先级。

### 3. Template::getHook()

该方法按顺序执行Hook文件，确保Hook按设计顺序执行。

## Hook文件格式

Hook文件必须使用目录层级结构格式：
- Hook名称：`Weline_Theme::frontend::layouts::base::head-after`
- 文件路径：`view/hooks/Weline_Theme/frontend/layouts/base/head-after.phtml`
- 转换规则：`::` → `/`（目录分隔符）

## 未来扩展

未来可能支持：
1. 在Hook规约文件中指定默认优先级
2. 在Hook实现文件中通过注释指定优先级
3. 通过配置文件指定Hook执行顺序
4. 支持Hook之间的依赖关系（类似Taglib的parent机制）

## 注意事项

1. Hook规约只能被一个模块定义，但Hook实现文件可以被多个模块提供
2. 优先级数字越大越优先，排序顺序数字越小越优先
3. 模块位置优先级：app > composer > framework > system
4. 如果多个Hook的优先级和排序顺序相同，将按模块位置和模块名排序
