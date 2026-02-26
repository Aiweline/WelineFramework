# Widget 部件开发技能

## 触发关键词

Widget, 部件, 组件, widget.php, `<w:widget>`, widget:refresh, WidgetRegistry, WidgetScanner, 部件注册, 部件类型, header widget, footer widget, carousel, banner, product widget, PageBuilder, 可视化编辑器

## 适用场景

- 创建新的 Widget 部件
- 注册 Widget 到系统
- 在模板中使用 `<w:widget>` 标签
- 定义 Widget 参数
- Widget 的 Block 类开发

---

## 1. Widget 概述

Widget（部件）是 WelineFramework 中可复用的页面组件系统：
- 支持在可视化编辑器中拖放使用
- 可通过 `<w:widget>` 标签在模板中调用
- 支持参数配置和多语言

---

## 2. Widget 文件结构

### 2.1 推荐目录结构

```
app/code/Vendor/Module/
├── extends/
│   └── module/
│       └── Weline_Widget/
│           └── Vendor_Module/
│               ├── widget.php           # Widget 注册（必需）
│               └── param_schema.php     # 参数类型定义（可选）
├── Block/
│   └── Widget/
│       └── MyWidget.php                 # Block 类（可选）
├── view/
│   ├── templates/
│   │   └── widgets/
│   │       └── {type}/
│   │           └── {code}.phtml         # 模板文件
│   └── theme/
│       └── frontend/
│           └── widgets/
│               └── {type}/
│                   └── {code}/
│                       └── default.phtml
└── doc/
    └── widget/
        └── {type}/{code}.md             # 文档（可选）
```

---

## 3. Widget 注册（widget.php）

### 3.1 文件位置

```
app/code/Vendor/Module/extends/module/Weline_Widget/Vendor_Module/widget.php
```

### 3.2 完整格式

```php
<?php
declare(strict_types=1);

return [
    // Widget 1: 完整格式
    [
        'name' => '默认头部',                    // 显示名称（必需）
        'description' => '标准网站头部部件',      // 描述
        'type' => 'header',                      // 类型（必需，见 3.4）
        'code' => 'default',                     // 唯一标识（必需）
        'area' => 'frontend',                    // 区域：frontend | backend
        'version' => '1.0.0',                    // 版本号
        'author' => 'Your Team',                 // 作者
        'template' => 'Vendor_Module::widgets/header/default.phtml',
        // 或使用 Block 类
        // 'block_class' => 'Vendor\Module\Block\Widget\Header',
        'disabled' => false,                     // 是否禁用
        'doc' => 'header/default.md',            // 文档路径（相对 doc/widget/）
        'params' => [
            'title' => [
                'type' => 'string',
                'label' => '标题',
                'default' => '网站标题',
                'required' => true,
                'description' => '网站主标题',
                'placeholder' => '请输入标题',
            ],
            'logo' => [
                'type' => 'image',
                'label' => 'Logo',
                'default' => '',
            ],
            'show_search' => [
                'type' => 'bool',
                'label' => '显示搜索',
                'default' => true,
            ],
        ],
    ],
    
    // Widget 2: 简化格式（仅模板路径）
    'Vendor_Module::widgets/footer/default.phtml',
    
    // Widget 3: 部分覆盖格式
    [
        'template' => 'Vendor_Module::widgets/banner/hero.phtml',
        'params' => [
            'slides' => [
                'type' => 'banner_items',  // 语义化参数类型
                'label' => '轮播图片',
            ],
        ],
    ],
];
```

### 3.3 简化格式（模板注释）

当使用简化格式时，元数据从模板注释中解析：

```php
<?php
return [
    'Vendor_Module::theme/frontend/widgets/header/logo/default.phtml',
    'Vendor_Module::theme/frontend/widgets/search/header-search/default.phtml',
];
```

对应的模板文件中需要包含 `@widget.*` 注释：

```php
<?php
/**
 * @widget.code {header-search}
 * @widget.name {Header 搜索框}
 * @widget.description {搜索框部件，支持热词和自动补全}
 * @widget.type {search}
 * @widget.area {frontend}
 * @widget.position {["header"]}
 * @widget.page_layouts {["*"]}
 * @widget.slot {search}
 * @widget.exclusive {true}
 * 
 * @param placeholder {default="搜索商品...",type="string",label="占位符文字"}
 * @param show_hot_words {default=true,type="bool",label="显示热搜词"}
 * @param search_type {default="all",type="select",label="搜索范围",options={all:"全站",product:"商品"}}
 */
?>
<!-- Widget HTML -->
```

### 3.4 支持的 Widget 类型

```php
'header', 'footer', 'sidebar', 'content', 'banner',
'carousel', 'card', 'form', 'list', 'grid', 'navigation',
'breadcrumb', 'pagination', 'modal', 'tabs', 'accordion',
'slider', 'gallery', 'testimonial', 'pricing', 'team',
'blog', 'product', 'category', 'search', 'filter', 'map',
'video', 'audio', 'social', 'newsletter', 'faq', 'timeline',
'stats', 'counter', 'progress', 'chart', 'table', 'calendar',
'chat', 'comment', 'container'
```

---

## 4. Widget 参数类型

### 4.1 基础类型

| 类型 | 别名 | 说明 | UI 组件 |
|-----|------|------|---------|
| `string` | `text` | 单行文本 | 文本框 |
| `textarea` | `html`, `richtext` | 多行文本 | 多行文本框 |
| `number` | `int`, `integer`, `float` | 数字 | 数字输入 |
| `bool` | `boolean` | 布尔值 | 开关 |
| `select` | `dropdown` | 下拉选择 | 下拉框 |
| `color` | - | 颜色选择 | 颜色选择器 |
| `url` | `link` | URL 地址 | 链接输入 |
| `image` | `file` | 图片上传 | 上传组件 |
| `media_image` | - | 媒体管理器图片 | 媒体选择器 |
| `array` | `list` | 数组类型 | 列表编辑器 |
| `datetime` | `date`, `time` | 日期时间 | 日期选择器 |
| `range` | `slider` | 范围滑块 | 滑块 |
| `icon` | - | 图标选择 | 图标选择器 |

### 4.2 参数定义示例

```php
'params' => [
    // 字符串
    'title' => [
        'type' => 'string',
        'label' => '标题',
        'default' => '默认标题',
        'placeholder' => '请输入标题',
        'required' => true,
        'maxlength' => 100,
        'i18n' => true,  // 支持多语言
    ],
    
    // 数字
    'count' => [
        'type' => 'number',
        'label' => '显示数量',
        'default' => 10,
        'min' => 1,
        'max' => 100,
    ],
    
    // 布尔
    'show_title' => [
        'type' => 'bool',
        'label' => '显示标题',
        'default' => true,
    ],
    
    // 选择
    'layout' => [
        'type' => 'select',
        'label' => '布局方式',
        'default' => 'grid',
        'options' => [
            'grid' => '网格布局',
            'list' => '列表布局',
            'card' => '卡片布局',
        ],
        'multiple' => false,
    ],
    
    // 数组（复杂类型）
    'nav_items' => [
        'type' => 'array',
        'label' => '导航项',
        'default' => [],
        'sortable' => true,
        'max_items' => 10,
        'add_label' => '添加导航项',
        'item_schema' => [
            'label' => ['type' => 'string', 'label' => '文字'],
            'url' => ['type' => 'url', 'label' => '链接'],
            'icon' => ['type' => 'icon', 'label' => '图标'],
        ],
    ],
],
```

### 4.3 语义化参数类型（param_schema.php）

定义可复用的参数类型：

```php
<?php
// extends/module/Weline_Widget/Vendor_Module/param_schema.php
return [
    'banner_items' => [
        'base_type' => 'array',
        'item_schema' => [
            'image' => ['type' => 'media_image', 'label' => '图片'],
            'title' => ['type' => 'string', 'label' => '标题', 'i18n' => true],
            'link' => ['type' => 'url', 'label' => '链接'],
        ],
        'sortable' => true,
        'add_label' => '添加轮播项',
    ],
];
```

使用时直接引用：

```php
'params' => [
    'slides' => [
        'type' => 'banner_items',  // 语义化类型
        'label' => '轮播图片',
    ],
],
```

---

## 5. Widget Block 类

### 5.1 Block 类结构

```php
<?php
declare(strict_types=1);

namespace Vendor\Module\Block\Widget;

use Weline\Framework\View\Block;

class MyWidget extends Block
{
    protected string $_template = 'Vendor_Module::Widget/my-widget.phtml';

    public function __init(): void
    {
        parent::__init();
        
        // 获取参数
        $title = $this->getData('title') ?? '默认标题';
        $count = (int)($this->getData('count') ?? 10);
        
        // 处理数据
        $items = $this->loadItems($count);
        
        // 传递到模板
        $this->assign([
            'title' => $title,
            'count' => $count,
            'items' => $items,
        ]);
    }
    
    private function loadItems(int $count): array
    {
        // 业务逻辑
        return [];
    }
}
```

### 5.2 在 widget.php 中引用

```php
[
    'name' => '商品列表',
    'type' => 'product',
    'code' => 'product-list',
    'block_class' => 'Vendor\Module\Block\Widget\ProductList',
    // 不需要 template，Block 类会指定
    'params' => [
        'limit' => [
            'type' => 'number',
            'label' => '显示数量',
            'default' => 8,
        ],
    ],
],
```

---

## 6. 模板中使用 Widget

### 6.1 `<w:widget>` 标签

```html
<!-- 基本用法 -->
<w:widget type="header" name="default" />

<!-- 带参数 -->
<w:widget type="header" name="default" params='{"title":"我的网站","show_search":true}' />

<!-- 覆盖 Block 类 -->
<w:widget type="header" name="default" block-class="Vendor\Module\Block\CustomHeader" />

<!-- 覆盖模板 -->
<w:widget type="header" name="default" template="Vendor_Module::custom-header.phtml" />

<!-- 带 ID（用于编辑模式） -->
<w:widget type="header" name="default" id="widget_123" />
```

### 6.2 标签属性

| 属性 | 必需 | 说明 |
|-----|------|------|
| `type` | 是 | 部件类型（如 header, footer, product） |
| `name` | 是 | 部件名称（code） |
| `params` | 否 | JSON 格式的参数 |
| `block-class` | 否 | 覆盖 Block 类 |
| `template` | 否 | 覆盖模板路径 |
| `id` | 否 | 部件实例 ID（用于可视化编辑） |

---

## 7. 刷新 Widget 注册表

创建或修改 Widget 后，必须执行：

```bash
php bin/w widget:refresh
```

此命令会：
1. 扫描所有模块的 `widget.php` 文件
2. 解析模板中的 `@widget.*` 注释
3. 生成 `generated/widgets.php` 注册表
4. 刷新 `generated/param_schemas.php` 参数 Schema 表

---

## 8. 完整开发示例

### 8.1 创建商品列表 Widget

**widget.php**

```php
<?php
declare(strict_types=1);

return [
    [
        'name' => '热门商品',
        'description' => '展示热门商品列表',
        'type' => 'product',
        'code' => 'featured-products',
        'area' => 'frontend',
        'version' => '1.0.0',
        'template' => 'WeShop_Catalog::widgets/product/featured-products.phtml',
        'params' => [
            'title' => [
                'type' => 'string',
                'label' => '标题',
                'default' => '热门商品',
                'i18n' => true,
            ],
            'limit' => [
                'type' => 'number',
                'label' => '显示数量',
                'default' => 8,
                'min' => 1,
                'max' => 20,
            ],
            'layout' => [
                'type' => 'select',
                'label' => '布局',
                'default' => 'grid',
                'options' => [
                    'grid' => '网格',
                    'carousel' => '轮播',
                ],
            ],
            'show_price' => [
                'type' => 'bool',
                'label' => '显示价格',
                'default' => true,
            ],
        ],
    ],
];
```

**模板文件（简化格式示例）**

```php
<?php
/**
 * 热门商品部件
 *
 * @widget.code {featured-products}
 * @widget.name {热门商品}
 * @widget.type {product}
 * @widget.area {frontend}
 *
 * @param title {type="string",label="标题",default="热门商品",i18n=true}
 * @param limit {type="number",label="显示数量",default=8,min=1,max=20}
 * @param layout {type="select",label="布局",default="grid",options={grid:"网格",carousel:"轮播"}}
 * @param show_price {type="bool",label="显示价格",default=true}
 */

$title = $this->getData('title') ?? __('热门商品');
$limit = (int)($this->getData('limit') ?? 8);
$layout = $this->getData('layout') ?? 'grid';
$showPrice = (bool)($this->getData('show_price') ?? true);
?>

<div class="widget-featured-products layout-<?= htmlspecialchars($layout) ?>">
    <?php if ($title): ?>
        <h2 class="widget-title"><?= htmlspecialchars($title) ?></h2>
    <?php endif; ?>
    
    <div class="product-<?= $layout ?>">
        <!-- 商品列表 -->
    </div>
</div>
```

### 8.2 注册并刷新

```bash
php bin/w widget:refresh
```

---

## 9. Widget 渲染流程

```
1. 模板解析遇到 <w:widget> 标签
       ↓
2. Widget Taglib 处理标签
       ↓
3. 从 WidgetData 获取 Widget 配置
       ↓
4. 合并默认参数和传入参数
       ↓
5. 渲染 Widget
   ├─ 有 block_class → 创建 Block 实例 → __init() → render()
   │
   └─ 无 block_class → 直接渲染模板
       ↓
6. 返回 HTML
```

---

## 10. 缓存机制

### 10.1 注册表缓存

- **文件**：`generated/widgets.php`
- **刷新**：`php bin/w widget:refresh`

### 10.2 渲染缓存

- 仅对纯模板渲染使用缓存
- Block 类渲染不缓存（可能有动态内容）
- 缓存仅在同一请求内有效

---

## 11. 注意事项

1. **类型验证**：只有在 `ALLOWED_TYPES` 中的类型才会被注册
2. **模板校验**：Scanner 会验证模板文件是否存在
3. **递归保护**：模板渲染有最大深度限制（10 层）
4. **XSS 防护**：模板中所有用户输入必须使用 `htmlspecialchars()` 转义
5. **国际化**：所有用户可见文本使用 `__()` 函数
6. **CSS 作用域**：使用唯一 ID 前缀避免样式冲突
7. **禁止 `declare(strict_types=1)`**：在 .phtml 模板文件中禁止使用

---

## 12. 常见错误

### 12.1 忘记刷新注册表

```bash
# 创建或修改 Widget 后必须执行
php bin/w widget:refresh
```

### 12.2 类型不在允许列表

```php
// ❌ 错误：custom-type 不在 ALLOWED_TYPES 中
'type' => 'custom-type',

// ✅ 正确：使用允许的类型
'type' => 'content',
```

### 12.3 模板路径格式错误

```php
// ❌ 错误
'template' => 'widgets/header.phtml',

// ✅ 正确
'template' => 'Vendor_Module::widgets/header.phtml',
```

### 12.4 参数 JSON 格式错误

```html
<!-- ❌ 错误：使用双引号 -->
<w:widget type="header" name="default" params="{"title":"标题"}" />

<!-- ✅ 正确：使用单引号包裹 JSON -->
<w:widget type="header" name="default" params='{"title":"标题"}' />
```

---

## 13. 规范总结

| 项目 | 规范 |
|------|------|
| 注册文件 | `extends/module/Weline_Widget/{Module}/widget.php` |
| 必需字段 | `name`, `type`, `code` |
| 模板路径 | `Module_Name::path/to/template.phtml` |
| 刷新命令 | `php bin/w widget:refresh` |
| 参数格式 | `'name' => ['type' => '...', 'label' => '...', 'default' => ...]` |
| 模板标签 | `<w:widget type="..." name="..." params='...' />` |
