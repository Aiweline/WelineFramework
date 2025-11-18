# Theme - 主题目录

## 目录概述

`theme/` 目录包含前后端主题文件，按区域（frontend/backend）分类组织。

## 目录结构

```
theme/
├── frontend/            # 前端主题
│   ├── layouts/        # 布局文件
│   ├── components/     # 组件
│   ├── partials/       # 片段
│   ├── assets/         # 静态资源
│   ├── colors/         # 配色方案
│   ├── variables/      # CSS变量
│   └── config/         # 配置文件
│
├── backend/            # 后端主题
│   ├── layouts/       # 布局文件
│   ├── assets/        # 静态资源
│   └── config/        # 配置文件
│
└── README.md          # 本文档
```

## 主题使用

### 前端主题

前端主题位于 `frontend/` 目录，包含：
- 首页、产品、分类、个人中心、购物车、结账等页面布局
- 通用组件和片段
- 主题样式和配色方案

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/frontend/layouts/homepage/default.phtml', [
    'title' => __('首页'),
    'content' => $content
]);
```

### 后端主题

后端主题位于 `backend/` 目录，包含：
- 管理后台布局
- 仪表盘布局
- 后端专用样式和脚本

**使用示例**：
```php
return $this->fetch('Weline_Theme::theme/backend/layouts/dashboard.phtml', [
    'title' => __('管理后台'),
    'content' => $content,
    'sidebar' => $sidebar
]);
```

## 主题配置

每个主题区域都有自己的配置文件：
- `frontend/config/theme.json` - 前端主题配置
- `backend/config/theme.json` - 后端主题配置

## 模块配置

每个主题区域都有自己的模块配置文件：
- `frontend/config/modules.json` - 前端模块配置
- `backend/config/modules.json` - 后端模块配置

这些配置文件会被编译到对应的 `base/weline.modules.js` 文件中。

