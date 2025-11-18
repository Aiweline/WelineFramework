# partials/ 目录文档

## 目录概述

`partials/` 目录包含可复用的页面片段模板。这些片段是页面的部分区域（如header、footer、侧边栏等），可以在布局或页面中引用。

## 目录结构

```
partials/
├── header.phtml           # 头部片段
├── footer.phtml           # 底部片段
├── sidebar.phtml          # 侧边栏片段
├── breadcrumb.phtml       # 面包屑导航片段
└── pagination.phtml       # 分页片段
```

## 片段设计原则

1. **可复用性**：可在多个布局和页面中使用
2. **独立性**：片段相对独立，不依赖特定页面
3. **参数化**：通过参数控制片段内容
4. **一致性**：保持片段在不同页面的表现一致

---

## 片段列表

### 1. `header.phtml` - 头部片段

**作用**：提供页面头部区域（导航栏、Logo、搜索等）

**参数**：
```php
[
    'logo' => '',                   // 可选：Logo URL
    'logoText' => 'Weline',         // 可选：Logo文字
    'navItems' => [],               // 可选：导航项数组
    'showSearch' => true,           // 可选：是否显示搜索框
    'showUserMenu' => true,         // 可选：是否显示用户菜单
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
// 在布局中使用
$this->assign('navItems', [
    ['text' => __('首页'), 'url' => '/'],
    ['text' => __('产品'), 'url' => '/products'],
    ['text' => __('关于'), 'url' => '/about']
]);
?>
@template(Weline_Frontend::theme/partials/header.phtml)
```

**渲染结果**：
```html
<header class="weline-header">
    <div class="header-container">
        <div class="header-logo">
            <a href="/">Weline</a>
        </div>
        <nav class="header-nav">
            <!-- 导航项 -->
        </nav>
        <div class="header-actions">
            <!-- 搜索、用户菜单等 -->
        </div>
    </div>
</header>
```

---

### 2. `footer.phtml` - 底部片段

**作用**：提供页面底部区域（链接、版权信息等）

**参数**：
```php
[
    'copyright' => '',              // 可选：版权信息
    'links' => [],                  // 可选：链接数组
    'showBackToTop' => true,        // 可选：是否显示返回顶部
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
$this->assign('copyright', __('© 2025 Weline Framework. 保留所有权利'));
$this->assign('links', [
    ['text' => __('关于我们'), 'url' => '/about'],
    ['text' => __('联系我们'), 'url' => '/contact'],
    ['text' => __('隐私政策'), 'url' => '/privacy']
]);
?>
@template(Weline_Frontend::theme/partials/footer.phtml)
```

---

### 3. `sidebar.phtml` - 侧边栏片段

**作用**：提供侧边栏导航

**参数**：
```php
[
    'items' => [],                  // 必填：菜单项数组
    'activeItem' => '',             // 可选：当前激活项
    'collapsed' => false,           // 可选：是否折叠
    'class' => ''                   // 可选：额外CSS类
]
```

**菜单项结构**：
```php
[
    [
        'text' => '菜单项',
        'url' => '/url',
        'icon' => 'fa-icon',
        'children' => []  // 可选：子菜单
    ]
]
```

**使用示例**：
```php
<?php
$this->assign('items', [
    [
        'text' => __('仪表盘'),
        'url' => '/dashboard',
        'icon' => 'fa-dashboard'
    ],
    [
        'text' => __('订单'),
        'url' => '/orders',
        'icon' => 'fa-shopping-cart',
        'children' => [
            ['text' => __('我的订单'), 'url' => '/orders'],
            ['text' => __('订单历史'), 'url' => '/orders/history']
        ]
    ]
]);
$this->assign('activeItem', '/dashboard');
?>
@template(Weline_Frontend::theme/partials/sidebar.phtml)
```

---

### 4. `breadcrumb.phtml` - 面包屑导航片段

**作用**：提供面包屑导航

**参数**：
```php
[
    'items' => [],                  // 必填：面包屑项数组
    'separator' => '/',             // 可选：分隔符
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
$this->assign('items', [
    ['text' => __('首页'), 'url' => '/'],
    ['text' => __('产品'), 'url' => '/products'],
    ['text' => __('产品详情'), 'url' => '']
]);
?>
@template(Weline_Frontend::theme/partials/breadcrumb.phtml)
```

**渲染结果**：
```html
<nav class="breadcrumb">
    <a href="/">首页</a> / 
    <a href="/products">产品</a> / 
    <span>产品详情</span>
</nav>
```

---

### 5. `pagination.phtml` - 分页片段

**作用**：提供分页导航

**参数**：
```php
[
    'currentPage' => 1,             // 必填：当前页码
    'totalPages' => 10,             // 必填：总页数
    'baseUrl' => '/products',       // 必填：基础URL
    'pageParam' => 'page',          // 可选：页码参数名
    'showFirstLast' => true,        // 可选：是否显示首页/末页
    'showPrevNext' => true,         // 可选：是否显示上一页/下一页
    'maxVisible' => 5,              // 可选：最大可见页码数
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
$this->assign('currentPage', 3);
$this->assign('totalPages', 10);
$this->assign('baseUrl', '/products');
?>
@template(Weline_Frontend::theme/partials/pagination.phtml)
```

**渲染结果**：
```html
<nav class="pagination">
    <a href="/products?page=1">首页</a>
    <a href="/products?page=2">上一页</a>
    <a href="/products?page=1">1</a>
    <a href="/products?page=2">2</a>
    <span class="current">3</span>
    <a href="/products?page=4">4</a>
    <a href="/products?page=5">5</a>
    <a href="/products?page=4">下一页</a>
    <a href="/products?page=10">末页</a>
</nav>
```

---

## 片段使用方式

### 1. 在布局中使用

```php
<?php
// 在布局模板中
?>
@template(Weline_Frontend::theme/partials/header.phtml)

<?= $content ?>

@template(Weline_Frontend::theme/partials/footer.phtml)
```

### 2. 在页面中使用

```php
<?php
// 在页面模板中
$this->assign('items', $breadcrumbItems);
?>
@template(Weline_Frontend::theme/partials/breadcrumb.phtml)
```

### 3. 嵌套使用

```php
<?php
// 片段可以嵌套使用
// 先准备 navItems 数据
$this->assign('items', $menuItems);
ob_start();
?>
@template(Weline_Frontend::theme/partials/nav-items)
<?php
$navItems = ob_get_clean();
$this->assign('navItems', $navItems);
?>
@template(Weline_Frontend::theme/partials/header.phtml)
```

---

## 片段扩展

### 创建新片段

1. **创建片段文件**：`partials/your-partial.phtml`
2. **定义参数**：在文件顶部注释中说明参数
3. **使用变量**：片段样式使用CSS变量
4. **更新文档**：在本文档中添加片段说明

### 片段模板结构

```php
<?php
/**
 * 片段：YourPartial
 * 
 * 参数：
 * - param1: 参数1说明
 * - param2: 参数2说明
 */
$param1 = $this->getData('param1') ?? 'default';
$param2 = $this->getData('param2') ?? '';
?>
<div class="partial-your-partial <?= htmlspecialchars($this->getData('class') ?? '') ?>">
    <!-- 片段内容 -->
</div>
```

---

## 最佳实践

### 1. 参数处理

- 使用 `$this->getData()` 获取参数
- 提供合理的默认值
- 对用户输入进行转义

### 2. 样式使用

- 使用CSS变量
- 使用语义化的CSS类名
- 支持主题切换

### 3. 可访问性

- 使用语义化的HTML标签
- 提供适当的ARIA属性
- 确保键盘导航支持

---

## 相关文档

- [layouts/ 目录文档](./layouts目录文档.md)
- [components/ 目录文档](./components目录文档.md)
- [assets/css/theme.css 文档](./assets目录文档.md#themecss)

