---
name: create-hook
description: |
  Creates Hook extension points in Weline Framework.
  
  Use when:
  - Creating hooks in modules
  - Implementing view template extension points
  - Allowing other modules to extend current module functionality
  
  Keywords: hook, Hook, 钩子, 扩展点, hook.php, <w:hook>, view/hooks/, HookInterface, 视图扩展, 模板扩展
globs:
  - "**/hook.php"
  - "**/view/hooks/**/*.phtml"
alwaysApply: false
---

# Hook 系统技能

## 概述

Hook 是 Weline Framework 的视图层扩展机制，允许模块在模板中定义扩展点，其他模块可以通过 Hook 注入内容。

---

## 1. Hook 命名规范

### 标准格式

```
{ModuleName}::{area}::{type}::{component}::{position}
```

### 命名组成

| 部分 | 说明 | 示例 |
|------|------|------|
| `ModuleName` | 定义 Hook 的模块名 | `Weline_Theme`, `Weline_Admin` |
| `area` | 区域 | `frontend`, `backend` |
| `type` | 类型 | `layouts`, `partials`, `blocks` |
| `component` | 组件 | `base`, `header`, `dashboard` |
| `position` | 位置 | `before`, `after`, `content` |

### 示例

```
Weline_Theme::frontend::partials::header::before
Weline_Admin::backend::layouts::dashboard::top-statistics
Weline_Admin::backend::layouts::dashboard::main-tabs
Weline_DeveloperWorkspace::backend::partials::dev-tool-panel::tabs-after
```

---

## 2. hook.php 规约文件

**位置**：`app/code/Vendor/Module/hook.php`

### 格式

```php
<?php
return [
    'Weline_Admin::backend::layouts::dashboard::top-statistics' => [
        'name' => __('后台首页顶部统计卡片'),
        'description' => __('在后台首页顶部区域渲染统计卡片'),
        'doc' => 'backend/dashboard/top-statistics.md',  // 相对于 doc/hook/
    ],
    
    'Weline_Admin::backend::layouts::dashboard::main-tabs' => [
        'name' => __('后台首页标签页导航'),
        'description' => __('动态追加标签页按钮'),
        'doc' => 'backend/dashboard/main-tabs.md',
    ],
];
```

---

## 3. 在模板中使用 `<w:hook>` 标签

在 `.phtml` 模板中使用 Hook 标签定义扩展点：

```html
<head>
    <w:hook>Weline_Theme::frontend::layouts::base::head-before</w:hook>
    <!-- 其他 head 内容 -->
    <w:hook>Weline_Theme::frontend::layouts::base::head-after</w:hook>
</head>

<body>
    <w:hook>Weline_Theme::frontend::layouts::base::body-start</w:hook>
    
    <header>
        <w:hook>Weline_Theme::frontend::partials::header::before</w:hook>
        <!-- header 内容 -->
        <w:hook>Weline_Theme::frontend::partials::header::after</w:hook>
    </header>
    
    <main>
        <w:hook>Weline_Theme::frontend::layouts::base::content-before</w:hook>
        <!-- 主内容 -->
        <w:hook>Weline_Theme::frontend::layouts::base::content-after</w:hook>
    </main>
    
    <w:hook>Weline_Theme::frontend::layouts::base::body-end</w:hook>
</body>
```

---

## 4. Hook 实现文件

### 目录结构

Hook 实现文件放在 `view/hooks/` 目录下，路径对应 Hook 名称：

```
app/code/Weline/Visitor/view/hooks/
└── Weline_Admin/                              # 目标模块名
    └── backend/                               # area
        └── layouts/                           # type
            └── dashboard/                     # component
                ├── top-statistics.phtml       # position
                ├── main-tabs.phtml
                └── main-tabs-content.phtml
```

### Hook 名称到路径的转换

```
Hook 名称: Weline_Admin::backend::layouts::dashboard::top-statistics
    ↓
文件路径: view/hooks/Weline_Admin/backend/layouts/dashboard/top-statistics.phtml
```

### 实现文件示例

```php
<?php
/**
 * @hook-priority 150          // 优先级（数字越大越优先）
 * @hook-sort-order 0          // 排序顺序（数字越小越优先）
 * @hook-solo false            // 是否独占（true 时只渲染此实现）
 */

/** @var \Weline\Framework\View\Template $this */
?>
<div class="my-hook-content">
    <h3><?= __('我的扩展内容') ?></h3>
    <!-- Hook 实现内容 -->
</div>
```

---

## 5. Hook 优先级和排序

### 排序规则（按优先级从高到低）

1. **priority**（降序）：数字越大越优先
2. **sort_order**（升序）：数字越小越优先
3. **模块位置优先级**：`app > composer > framework > system`
4. **模块名字母顺序**

### 默认优先级（根据模块位置）

| 位置 | 默认优先级 |
|------|----------|
| `app` | 200 |
| `composer` | 150 |
| `framework` | 100 |
| `system` | 50 |

### 元数据注释

在 Hook 实现文件头部使用注释定义元数据：

```php
<?php
/**
 * @hook-priority 150      // 优先级
 * @hook-sort-order 0      // 排序顺序
 * @hook-solo false        // 独占模式
 */
```

---

## 6. HookInterface 常量（推荐）

在 `HookInterface` 中定义 Hook 常量，便于引用：

```php
interface HookInterface
{
    // Theme Frontend
    const THEME_FRONTEND_PARTIALS_HEADER_BEFORE = 'Weline_Theme::frontend::partials::header::before';
    const THEME_FRONTEND_PARTIALS_HEADER_LOGO_AFTER = 'Weline_Theme::frontend::partials::header::logo-after';
    
    // Admin Backend Dashboard
    const ADMIN_BACKEND_DASHBOARD_TOP_STATISTICS = 'Weline_Admin::backend::layouts::dashboard::top-statistics';
    const ADMIN_BACKEND_DASHBOARD_MAIN_OVERVIEW = 'Weline_Admin::backend::layouts::dashboard::main-overview';
    const ADMIN_BACKEND_DASHBOARD_MAIN_TABS = 'Weline_Admin::backend::layouts::dashboard::main-tabs';
}
```

---

## 7. 完整开发流程

### Step 1: 定义 Hook 规约 (hook.php)

```php
<?php
// app/code/Vendor/Module/hook.php
return [
    'Vendor_Module::backend::order::view::actions' => [
        'name' => __('订单详情页操作按钮'),
        'description' => __('在订单详情页添加自定义操作按钮'),
        'doc' => 'backend/order/view/actions.md',
    ],
];
```

### Step 2: 在模板中放置 Hook 标签

```html
<!-- app/code/Vendor/Module/view/templates/Backend/Order/view.phtml -->
<div class="order-actions">
    <button class="btn btn-primary">保存</button>
    <w:hook>Vendor_Module::backend::order::view::actions</w:hook>
</div>
```

### Step 3: 其他模块实现 Hook

```php
<?php
// app/code/Other/Module/view/hooks/Vendor_Module/backend/order/view/actions.phtml
/**
 * @hook-priority 100
 * @hook-sort-order 10
 */
?>
<button class="btn btn-info" onclick="customAction()">
    <?= __('自定义操作') ?>
</button>
```

### Step 4: 刷新缓存

```bash
php bin/w cache:clear
```

---

## 8. Hook vs Event 对比

| 特性 | Hook | Event |
|------|------|-------|
| **用途** | 视图/模板扩展 | 业务逻辑解耦 |
| **定义文件** | `hook.php` | `event.php` |
| **实现方式** | `.phtml` 模板文件 | Observer 类 |
| **数据传递** | `$this` (Template) | Event 对象 |
| **位置** | `view/hooks/` | `Observer/` |

---

## 9. 相关文件

| 文件 | 位置 |
|------|------|
| Hook 规约 | `模块/hook.php` |
| Hook 实现 | `模块/view/hooks/{目标模块}/{path}.phtml` |
| Hook 文档 | `模块/doc/hook/*.md` |
| 生成的 Hook 配置 | `generated/hooks.php` |
| HookInterface | `Weline\Framework\Hook\HookInterface.php` |

---

**最后更新**: 2026-02-25
**版本**: 2.0.0
