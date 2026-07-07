> 警告：本文是历史主题设计资料，仅用于理解早期设计思路，不是当前开发规范。当前主题开发先读 `app/code/Weline/Theme/doc/AI-INDEX.md`、`app/code/Weline/Theme/doc/theme-inheritance-and-file-conventions.md`、`app/code/Weline/Theme/doc/开发/Theme开发总指南.md`；浏览器业务请求只使用 `app/code/Weline/Frontend/doc/Weline.Api使用指南.md`。

﻿# Weline Frontend theme/ 目录详细说明

## 目录概述

`view/theme/` 目录是 Weline Frontend 模块的主题抽象层，提供统一的设计系统、组件模板和样式规范。该目录作为 Frontend 模块的核心设计系统，供模块内部和其他模块引用使用。

## 完整目录结构

```
app/code/Weline/Frontend/view/theme/
├── variables/                  # CSS变量定义（基础层）
│   ├── _colors.css            # 颜色变量定义
│   ├── _spacing.css           # 间距变量定义
│   ├── _typography.css        # 字体变量定义
│   ├── _shadows.css           # 阴影变量定义
│   └── _borders.css           # 边框变量定义
│
├── colors/                     # 颜色主题覆盖（主题层）
│   ├── _light.css             # 亮色主题颜色覆盖
│   ├── _dark.css              # 暗色主题颜色覆盖
│   └── _amazon.css            # Amazon风格主题颜色覆盖
│
├── components/                 # 可复用组件模板
│   ├── button.phtml           # 按钮组件
│   ├── input.phtml            # 输入框组件
│   ├── card.phtml             # 卡片组件
│   ├── modal.phtml            # 模态框组件
│   ├── alert.phtml            # 提示框组件
│   ├── form-group.phtml       # 表单组组件
│   ├── badge.phtml            # 徽章组件
│   └── dropdown.phtml         # 下拉菜单组件
│
├── layouts/                    # 布局模板
│   ├── default.phtml          # 默认布局（包含header和footer）
│   ├── auth.phtml             # 认证页面布局（登录/注册）
│   ├── dashboard.phtml        # 仪表盘布局（带侧边栏）
│   └── minimal.phtml          # 极简布局（无header/footer）
│
├── partials/                   # 部分模板片段
│   ├── header.phtml           # 头部片段
│   ├── footer.phtml           # 底部片段
│   ├── sidebar.phtml          # 侧边栏片段
│   ├── breadcrumb.phtml       # 面包屑导航片段
│   └── pagination.phtml       # 分页片段
│
├── assets/                     # 主题静态资源
│   ├── css/
│   │   ├── theme.css          # 主题主样式（导入所有变量）
│   │   ├── components.css     # 组件样式
│   │   └── utilities.css      # 工具类样式
│   ├── js/
│   │   ├── theme.js          # Weline核心JS（模块加载、API、账户管理等）
│   │   └── theme.js           # 主题JS（主题切换、工具函数）
│   └── images/
│       └── theme/              # 主题相关图片
│           ├── logo.svg
│           └── placeholder.png
│
└── config/                     # 主题配置
    └── theme.json              # 主题配置文件（元数据）
```

## 目录层级说明

### 第一层：功能分类

- **`variables/`** - 基础变量定义（所有变量的基础）
- **`colors/`** - 主题颜色覆盖（不同主题的颜色值）
- **`components/`** - UI组件模板（可复用的UI元素）
- **`layouts/`** - 页面布局模板（页面整体结构）
- **`partials/`** - 页面片段（页面部分区域）
- **`assets/`** - 静态资源（CSS、JS、图片）
- **`config/`** - 配置文件（主题元数据）

### 第二层：具体文件

每个目录下的文件都有明确的职责和用途，详见各文件的详细文档。

## 文件关系图

```
theme.css (主样式文件)
    ↓ 导入
variables/ (基础变量)
    ├── _colors.css
    ├── _spacing.css
    ├── _typography.css
    ├── _shadows.css
    └── _borders.css
    ↓ 被覆盖
colors/ (主题覆盖)
    ├── _light.css
    ├── _dark.css
    └── _amazon.css
    ↓ 被使用
components/ (组件模板)
    └── 使用变量和主题
layouts/ (布局模板)
    └── 使用 components/ 和 partials/
partials/ (页面片段)
    └── 使用 components/
```

## 使用流程

### 1. 在页面中加载主题

```php
<!-- 在 head.phtml 中 -->
<link rel="stylesheet" href="@static(Weline_Frontend::theme/assets/css/theme.css)">
<script src="@static(Weline_Theme::theme/frontend/assets/js/theme.js)"></script>
```

### 2. 使用布局模板

```php
<?php
// 在控制器中
$this->assign('layout', 'Weline_Frontend::theme/layouts/auth.phtml');
return $this->fetch('login');
?>
```

### 3. 使用组件

```php
<?php
// 在模板中
echo $this->fetch('Weline_Frontend::theme/components/button.phtml', [
    'text' => __('登录'),
    'type' => 'primary'
]);
?>
```

### 4. 使用片段

```php
<?php
// 在布局中
echo $this->fetch('Weline_Frontend::theme/partials/header.phtml');
?>
```

## 设计原则

1. **分层设计**：基础层（variables）→ 主题层（colors）→ 应用层（components/layouts）
2. **职责单一**：每个文件只负责一个功能
3. **可复用性**：组件和片段可在多处使用
4. **可扩展性**：易于添加新组件、新主题
5. **一致性**：统一的命名和使用规范

## 相关文档

- [variables/ 目录文档](./variables目录文档.md)
- [colors/ 目录文档](./colors目录文档.md)
- [components/ 目录文档](./components目录文档.md)
- [layouts/ 目录文档](./layouts目录文档.md)
- [partials/ 目录文档](./partials目录文档.md)
- [assets/ 目录文档](./assets目录文档.md)
- [config/ 目录文档](./config目录文档.md)

