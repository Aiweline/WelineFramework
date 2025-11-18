# layouts/ 目录文档

## 目录概述

`layouts/` 目录包含页面布局模板，定义页面的整体结构（header、main、footer等区域）。布局模板可以被多个页面复用，确保页面结构的一致性。

## 目录结构

```
layouts/
├── default.phtml          # 默认布局（包含header和footer）
├── auth.phtml             # 认证页面布局（登录/注册）
├── dashboard.phtml        # 仪表盘布局（带侧边栏）
└── minimal.phtml          # 极简布局（无header/footer）
```

## 布局设计原则

1. **结构清晰**：明确的区域划分
2. **可复用性**：可在多个页面使用
3. **灵活性**：支持内容区域自定义
4. **响应式**：适配不同屏幕尺寸
5. **一致性**：保持页面结构统一

---

## 布局列表

### 1. `default.phtml` - 默认布局

**作用**：标准的页面布局，包含header、main、footer

**结构**：
```
┌─────────────────────────────┐
│        Header               │
├─────────────────────────────┤
│                             │
│        Main Content         │
│        (可自定义)           │
│                             │
├─────────────────────────────┤
│        Footer               │
└─────────────────────────────┘
```

**参数**：
```php
[
    'title' => '页面标题',          // 可选：页面标题
    'content' => '',                // 必填：主要内容（HTML字符串）
    'header' => true,               // 可选：是否显示header
    'footer' => true,               // 可选：是否显示footer
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
// 在控制器中
$this->assign('title', __('产品列表'));
return $this->fetch('Weline_Frontend::theme/layouts/default.phtml');
?>
```

**在布局模板中使用**：
```php
<?php
// 在 default.phtml 布局中
$this->assign('title', __('产品列表'));
?>
<!DOCTYPE html>
<html>
<head>
    @template(Weline_Frontend::templates/public/head.phtml)
</head>
<body>
    @template(Weline_Frontend::templates/public/header.phtml)
    
    <main class="main-content">
        @template(Weline_Frontend::frontend/product/list.phtml)
    </main>
    
    @template(Weline_Frontend::templates/public/footer.phtml)
</body>
</html>
```

**模板内容**：
```php
<?php
$title = $this->getData('title') ?? '';
$content = $this->getData('content') ?? '';
$showHeader = $this->getData('header') ?? true;
$showFooter = $this->getData('footer') ?? true;
?>
<!DOCTYPE html>
<html lang="<?= str_replace('_', '-', $_SERVER['WELINE_USER_LANG'] ?? 'zh_Hans_CN') ?>">
<head>
    <?= $this->fetch('Weline_Frontend::templates/public/head.phtml') ?>
</head>
<body>
    <?php if ($showHeader): ?>
        <?= $this->fetch('Weline_Frontend::templates/public/header.phtml') ?>
    <?php endif; ?>
    
    <main class="main-content">
        <?= $content ?>
    </main>
    
    <?php if ($showFooter): ?>
        <?= $this->fetch('Weline_Frontend::templates/public/footer.phtml') ?>
    <?php endif; ?>
</body>
</html>
```

---

### 2. `auth.phtml` - 认证页面布局

**作用**：登录/注册等认证页面的专用布局

**结构**：
```
┌─────────────────────────────┐
│        Header (简化)        │
├─────────────────────────────┤
│                             │
│     认证表单（居中）        │
│                             │
├─────────────────────────────┤
│        Footer (简化)        │
└─────────────────────────────┘
```

**特点**：
- 简化的header和footer
- 内容区域居中显示
- 适合登录/注册页面

**参数**：
```php
[
    'title' => '用户登录',          // 可选：页面标题
    'content' => '',                // 必填：认证表单内容
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
// 在登录控制器中
$this->assign('title', __('用户登录'));
return $this->fetch('Weline_Frontend::theme/layouts/auth.phtml');
?>
```

**在布局模板中使用**：
```php
<?php
// 在 auth.phtml 布局中
?>
<!DOCTYPE html>
<html>
<head>
    @template(Weline_Frontend::templates/public/head.phtml)
</head>
<body>
    @template(Weline_Frontend::templates/public/header.phtml)
    
    <main class="auth-content">
        @template(Weline_Frontend::templates/frontend/account/login.phtml)
    </main>
    
    @template(Weline_Frontend::templates/public/footer.phtml)
</body>
</html>
```

**样式特点**：
- 内容区域最大宽度限制（如400px）
- 垂直居中显示
- 背景简洁，突出表单

---

### 3. `dashboard.phtml` - 仪表盘布局

**作用**：带侧边栏的仪表盘布局

**结构**：
```
┌─────────────────────────────────┐
│        Header                   │
├──────────┬──────────────────────┤
│          │                      │
│ Sidebar  │    Main Content      │
│          │    (可自定义)        │
│          │                      │
├──────────┴──────────────────────┤
│        Footer                   │
└─────────────────────────────────┘
```

**特点**：
- 左侧固定侧边栏
- 右侧主内容区域
- 适合后台管理、用户中心等

**参数**：
```php
[
    'title' => '仪表盘',            // 可选：页面标题
    'content' => '',                // 必填：主要内容
    'sidebar' => '',                // 可选：侧边栏内容
    'sidebarCollapsed' => false,    // 可选：侧边栏是否折叠
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
// 在用户中心控制器中
$this->assign('title', __('我的账户'));
return $this->fetch('Weline_Frontend::theme/layouts/dashboard.phtml');
?>
```

**在布局模板中使用**：
```php
<?php
// 在 dashboard.phtml 布局中
?>
<!DOCTYPE html>
<html>
<head>
    @template(Weline_Frontend::templates/public/head.phtml)
</head>
<body>
    @template(Weline_Frontend::templates/public/header.phtml)
    
    <div class="layout-dashboard">
        <aside class="sidebar">
            @template(Weline_Frontend::templates/frontend/account/sidebar.phtml)
        </aside>
        <main class="main-content">
            @template(Weline_Frontend::templates/frontend/account/index.phtml)
        </main>
    </div>
    
    @template(Weline_Frontend::templates/public/footer.phtml)
</body>
</html>
```

---

### 4. `minimal.phtml` - 极简布局

**作用**：无header和footer的极简布局

**结构**：
```
┌─────────────────────────────┐
│                             │
│      Main Content Only      │
│      (可自定义)             │
│                             │
└─────────────────────────────┘
```

**特点**：
- 无header和footer
- 适合弹窗内容、打印页面等
- 最大化的内容区域

**参数**：
```php
[
    'title' => '页面标题',          // 可选：页面标题
    'content' => '',                // 必填：内容
    'class' => ''                   // 可选：额外CSS类
]
```

**使用示例**：
```php
<?php
// 打印页面
return $this->fetch('Weline_Frontend::theme/layouts/minimal.phtml');
?>
```

**在布局模板中使用**：
```php
<?php
// 在 minimal.phtml 布局中
?>
<!DOCTYPE html>
<html>
<head>
    @template(Weline_Frontend::templates/public/head.phtml)
</head>
<body>
    @template(Weline_Frontend::frontend/order/print.phtml)
</body>
</html>
```

---

## 布局使用方式

### 1. 在控制器中指定布局

```php
<?php
namespace Your\Module\Controller\Frontend;

use Weline\Frontend\Controller\AbstractFrontendController;

class YourController extends AbstractFrontendController
{
    public function index()
    {
        // 指定布局
        $this->assign('layout', 'Weline_Frontend::theme/layouts/default.phtml');
        
        // 设置页面数据
        $this->assign('title', __('页面标题'));
        $this->assign('content', $this->fetch('your-page'));
        
        return $this->fetch('Weline_Frontend::theme/layouts/default.phtml');
    }
}
?>
```

### 2. 在模板中使用布局

```php
<?php
// 在页面模板中直接使用 @template 标签
?>
@template(Weline_Frontend::theme/layouts/default.phtml)
```

---

## 布局扩展

### 创建新布局

1. **创建布局文件**：`layouts/your-layout.phtml`
2. **定义结构**：使用partials和components
3. **支持参数**：通过参数控制布局行为
4. **更新文档**：在本文档中添加布局说明

### 布局模板结构

```php
<?php
/**
 * 布局：YourLayout
 * 
 * 参数：
 * - title: 页面标题
 * - content: 主要内容
 */
$title = $this->getData('title') ?? '';
$content = $this->getData('content') ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <?= $this->fetch('Weline_Frontend::templates/public/head.phtml') ?>
</head>
<body>
    <!-- 布局结构 -->
    <div class="layout-your-layout">
        <?= $content ?>
    </div>
</body>
</html>
```

---

## 布局样式

布局样式定义在 `assets/css/theme.css` 中：

```css
/* 默认布局 */
.main-content {
    min-height: calc(100vh - 200px);
    padding: var(--spacing-xl) 0;
}

/* 认证布局 */
.layout-auth {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-bg-secondary);
}

/* 仪表盘布局 */
.layout-dashboard {
    display: flex;
    min-height: calc(100vh - 100px);
}

.layout-dashboard .sidebar {
    width: 250px;
    background: var(--color-bg-primary);
    border-right: var(--border-width-thin) solid var(--color-border-default);
}

.layout-dashboard .main-content {
    flex: 1;
    padding: var(--spacing-lg);
}
```

---

## 最佳实践

### 1. 布局选择

- **默认布局**：大多数页面使用
- **认证布局**：登录/注册页面
- **仪表盘布局**：后台管理、用户中心
- **极简布局**：特殊场景（打印、弹窗等）

### 2. 内容组织

- 使用partials组织页面片段
- 使用components构建UI元素
- 保持内容区域的灵活性

### 3. 响应式设计

- 布局应适配移动端
- 使用CSS媒体查询
- 侧边栏在移动端可折叠

---

## 相关文档

- [partials/ 目录文档](./partials目录文档.md)
- [components/ 目录文档](./components目录文档.md)
- [assets/css/theme.css 文档](./assets目录文档.md#themecss)

