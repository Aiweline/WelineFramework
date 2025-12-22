# Weline_Widget 模块扩展文档

## 概述

Weline_Widget 模块提供了一个通用的部件扩展系统，允许其他模块和主题注册自己的页面部件。通过实现 Widget 扩展点，任何模块都可以提供可复用的页面组件。

## 快速开始

### 1. 创建部件目录

在您的模块中创建部件目录：

```
app/code/YourModule/extends/Weline_Widget/Weline_Widget/
└── header/                          # 部件类型
    └── default/                     # 部件名称
        ├── widget.php              # 部件规约（必需）
        ├── template.phtml          # 部件模板（必需）
        ├── Block.php               # Block 类（可选）
        └── doc.md                  # 部件文档（可选）
```

### 2. 创建 widget.php 规约文件

```php
<?php
declare(strict_types=1);

return [
    'name' => '默认头部',
    'description' => '标准网站头部部件',
    'type' => 'header',
    'version' => '1.0.0',
    'author' => 'Your Name',
    'template' => 'YourModule::widgets/header/default.phtml',
    'params' => [
        'title' => [
            'type' => 'string',
            'label' => '标题',
            'default' => '网站标题',
            'required' => true
        ]
    ]
];
```

### 3. 创建模板文件

```phtml
<!-- app/code/YourModule/view/templates/widgets/header/default.phtml -->
<?php
$title = $this->getData('title') ?? '网站标题';
?>
<header class="widget-header">
    <h1><?= htmlspecialchars($title) ?></h1>
</header>
```

## 详细说明

### Widget 扩展点

**路径**: `extends/Weline_Widget/Weline_Widget/{type}/{name}`

**接口**: 无需实现接口，只需提供规约文件和模板

**用途**: 扩展页面部件功能，可以为不同的页面区域提供专门的部件

**要求**:
- 必须提供 `widget.php` 规约文件
- 必须提供 `template.phtml` 模板文件或 `Block.php` 类
- 允许多个实现
- 部件类型必须在允许的类型列表中

### 部件类型

系统支持以下部件类型（可扩展）：

- **布局类**: header, footer, sidebar, content
- **展示类**: banner, carousel, card, gallery, slider
- **交互类**: form, modal, tabs, accordion, navigation
- **数据类**: list, grid, table, chart, stats
- **功能类**: search, filter, pagination, breadcrumb
- **其他**: video, audio, map, social, newsletter, faq, timeline, etc.

### widget.php 规约文件格式

```php
<?php
declare(strict_types=1);

return [
    // 基本信息
    'name' => '部件显示名称',
    'description' => '部件描述',
    'type' => 'header',                    // 部件类型（必需）
    'version' => '1.0.0',
    'author' => '作者名',
    
    // Block 类（可选）
    'block_class' => 'YourModule\\Widget\\Block\\Header\\Default',
    
    // 模板路径（必需，如果未提供 block_class）
    'template' => 'YourModule::widgets/header/default.phtml',
    
    // 参数定义
    'params' => [
        'param_name' => [
            'type' => 'string',             // 参数类型
            'label' => '参数标签',
            'default' => '默认值',
            'required' => true,
            'description' => '参数说明'
        ]
    ],
    
    // 依赖关系（可选）
    'dependencies' => [
        'Weline_Theme' => '1.0.0'
    ]
];
```

### 参数类型

支持以下参数类型：

- `string`: 字符串
- `int`: 整数
- `bool`: 布尔值
- `array`: 数组
- `select`: 下拉选择
- `color`: 颜色选择器
- `image`: 图片选择器

### 使用部件

#### 在可视化编辑器中使用

1. 进入可视化编辑器
2. 在左侧面板选择部件
3. 添加到画布
4. 配置参数

#### 通过 w:widget 标签使用

```phtml
<w:widget type="header" name="default" params='{"title":"我的网站"}' />
```

## 高级用法

### 使用 Block 类

如果部件需要复杂的逻辑处理，可以创建 Block 类：

```php
<?php
namespace YourModule\Widget\Block\Header;

use Weline\Framework\View\Block;

class Default extends Block
{
    public function render(): string
    {
        $title = $this->getData('title') ?? '默认标题';
        $this->assign('title', $title);
        return $this->fetch('YourModule::widgets/header/default.phtml');
    }
}
```

在 widget.php 中指定：

```php
'block_class' => 'YourModule\\Widget\\Block\\Header\\Default',
```

## 最佳实践

1. **命名规范**: 使用有意义的部件名称，如 `default`, `minimal`, `full-width`
2. **参数设计**: 提供合理的默认值，避免必需参数过多
3. **文档完善**: 为每个部件提供 doc.md 文档
4. **类型选择**: 选择合适的部件类型，便于分类管理
5. **模板复用**: 尽量复用现有模板，减少重复代码

## 示例

完整示例请参考：

- `app/code/Weline/Widget/extends/Weline_Widget/Weline_Widget/header/default/`

