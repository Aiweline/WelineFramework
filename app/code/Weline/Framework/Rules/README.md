# 框架约束规则系统

## 概述

框架约束规则系统用于确保代码遵循 Weline Framework 的最佳实践和约束条件。所有规则在系统更新时自动执行，确保代码质量。

## 目录结构

```
app/code/Weline/Framework/Rules/
├── RuleInterface.php          # 规则接口
├── RulesManager.php            # 规则管理器
├── Frontend/                   # 前台相关规则
│   ├── SectionWelineCodeScanner.php
│   ├── FrontendSectionWelineCodeRule.php
│   ├── ThemeLayoutWidgetOwnerScanner.php
│   └── FrontendThemeLayoutWidgetOwnerRule.php
├── Test/                       # 测试相关规则
│   └── TestClassPlacementRule.php
└── README.md                   # 本文档
```

## 规则接口

所有规则必须实现 `RuleInterface` 接口，包含以下方法：

- `getName(): string` - 规则名称（简短标识）
- `getBrief(): string` - 规则简述（一句话描述）
- `getDescription(): string` - 规则详细描述
- `getPriority(): int` - 规则优先级（0-100，数值越小优先级越高）
- `getCategory(): string` - 规则分类
- `validate(): void` - 验证规则，失败时抛出异常

## 规则管理器

`RulesManager` 负责：

1. **自动发现规则**：扫描 `Rules` 目录，自动发现所有实现 `RuleInterface` 的类
2. **规则排序**：按优先级排序执行
3. **统一验证**：统一处理规则验证结果
4. **错误报告**：提供详细的错误信息

## 现有规则

### 1. 测试类位置规则 (test-class-placement)

**分类**：test

**优先级**：10

**简述**：测试类不应放在业务代码目录下

**详细描述**：
测试类（继承自 `PHPUnit\Framework\TestCase` 或 `Weline\Framework\Test\TestCore`，或类名包含 `Test`）不应放在业务代码目录（Model、Controller、Block、Helper、Observer、Plugin、Console、View、Taglib、Api）下。测试类应放在专门的测试目录（如 Test、UnitTest、Tests、tests）下。

**业务代码目录**：
- Model
- Controller
- Block
- Helper
- Observer
- Plugin
- Console
- View
- Taglib
- Api

**测试目录**：
- Test
- UnitTest
- Tests
- tests

**错误示例**：
```
app/code/Weline/Backend/Model/MenuTest.php  ❌ 错误
```

**正确示例**：
```
app/code/Weline/Backend/Test/Model/MenuTest.php  ✅ 正确
app/code/Weline/模块 Test/ 或 UnitTest/Model/MenuTest.php  ✅ 正确
```

### 2. 前台 Section weline-code 规则 (frontend-section-weline-code)

**分类**：frontend

**优先级**：15

**简述**：前台 section / wrapper=section 必须配置 weline-code

**详细描述**：
前台纳入集中的字面 `<section>` 与 `<w:slot wrapper="section">` 必须配置非空 `weline-code`（或含 PHP 插值）。同文件字面量 code 不得重复。后台 / Admin / `generated/` / `view/tpl/` 不在范围内。

**本地自检**：

```bash
php bin/w frontend:check-section-code
```

**文档**：`app/code/Weline/Theme/doc/frontend-section-weline-code.md`

### 3. Theme 布局部件归属规则 (frontend-theme-layout-widget-owner)

**分类**：frontend

**优先级**：16

**简述**：Theme 布局/partial 仅允许内嵌 Weline_Theme 部件

**详细描述**：
`Weline/Theme/view/theme/**/{layouts,partials}/**/*.phtml` 中禁止内嵌非 `Weline_Theme` 在 `widget.php` 注册的 `<w:widget>` 或 `fetch(...Module::.../widgets/...)`。其他模块须空 slot + `default_injections`。

**本地自检**：

```bash
php bin/w frontend:check-theme-layout-widgets
```

**文档**：`app/code/Weline/Theme/doc/开发/Theme开发总指南.md`（§ Slot / Widget）

## 如何添加新规则

1. **创建规则类**：在 `Rules` 目录下创建新的规则类，实现 `RuleInterface`

```php
<?php

namespace Weline\Framework\Rules\YourCategory;

use Weline\Framework\Rules\RuleInterface;
use Weline\Framework\App\Exception;

class YourRule implements RuleInterface
{
    public function getName(): string
    {
        return 'your-rule-name';
    }
    
    public function getBrief(): string
    {
        return __('规则简述');
    }
    
    public function getDescription(): string
    {
        return __('规则详细描述');
    }
    
    public function getPriority(): int
    {
        return 20; // 设置优先级
    }
    
    public function getCategory(): string
    {
        return 'your-category'; // 规则分类
    }
    
    public function validate(): void
    {
        // 验证逻辑
        // 如果验证失败，抛出 Exception
        if ($violation) {
            throw new Exception(__('错误信息'));
        }
    }
}
```

2. **规则会自动被发现**：`RulesManager` 会自动扫描并注册新规则

3. **系统更新时自动执行**：规则会在系统更新时自动执行

## 规则执行流程

1. 系统更新命令启动
2. 准备阶段：收集注册表
3. **规则验证阶段**：执行所有框架约束规则
4. 如果所有规则通过，继续执行模块升级
5. 如果有规则失败，停止升级并报告错误

## 规则分类

规则可以按以下分类组织：

- **test** - 测试相关规则
- **code-style** - 代码风格规则
- **security** - 安全相关规则
- **performance** - 性能相关规则
- **architecture** - 架构相关规则

## 最佳实践

1. **单一职责**：每个规则只负责一个约束
2. **清晰的错误信息**：提供详细的错误信息，帮助开发者快速定位问题
3. **合理的优先级**：重要规则设置较高优先级（较小数值）
4. **完整的文档**：为每个规则编写完整的文档说明

## 相关文档

- [框架约束规则详细说明](./doc/框架约束规则详细说明.md)
