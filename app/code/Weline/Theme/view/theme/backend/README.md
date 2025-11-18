# Backend Theme - 后端主题

## 目录概述

`backend/` 目录包含后端管理界面的主题文件，包括布局、组件、样式等。

## 目录结构

```
backend/
├── layouts/              # 布局文件
│   ├── default.phtml    # 默认布局
│   ├── dashboard.phtml  # 仪表盘布局
│   └── minimal.phtml    # 极简布局
├── config/              # 配置文件
│   ├── theme.json       # 主题配置
│   └── modules.json     # 模块配置
├── assets/              # 静态资源
│   ├── css/
│   │   └── theme.css    # 主题样式
│   └── js/
│       └── theme.js     # 主题脚本
└── README.md            # 本文档
```

## 布局说明

### 1. `default.phtml` - 默认布局

后端默认布局，包含header、sidebar、main、footer。

**参数**：
- `title`: 页面标题
- `content`: 主要内容（HTML字符串）
- `sidebar`: 侧边栏内容（可选）
- `showHeader`: 是否显示header（默认：true）
- `showFooter`: 是否显示footer（默认：true）
- `class`: 额外CSS类

### 2. `dashboard.phtml` - 仪表盘布局

后端仪表盘布局，包含侧边栏导航和主内容区。

**参数**：
- `title`: 页面标题
- `content`: 主要内容（HTML字符串）
- `sidebar`: 侧边栏内容（导航菜单）
- `sidebarCollapsed`: 侧边栏是否折叠（默认：false）
- `class`: 额外CSS类

### 3. `minimal.phtml` - 极简布局

后端极简布局，无header和footer，适合弹窗、打印等场景。

**参数**：
- `title`: 页面标题
- `content`: 内容（HTML字符串）
- `class`: 额外CSS类

## 使用示例

```php
// 在控制器中使用后端布局
return $this->fetch('Weline_Theme::theme/backend/layouts/default.phtml', [
    'title' => __('管理后台'),
    'content' => $this->fetch('your-template.phtml'),
    'sidebar' => $this->fetch('sidebar.phtml')
]);
```

