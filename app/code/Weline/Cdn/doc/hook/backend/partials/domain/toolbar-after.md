# Weline_Cdn::backend::partials::domain::toolbar-after - CDN域名管理工具栏扩展

## 概述

该 Hook 用于在 CDN 域名管理列表页的操作栏按钮区域后注入扩展内容，常用于添加工具入口或快捷操作。

## Hook 信息

- **Hook 名称**：`Weline_Cdn::backend::partials::domain::toolbar-after`
- **显示名称**：CDN域名管理工具栏扩展
- **定义模块**：Weline_Cdn
- **区域**：backend
- **类型**：partials
- **组件**：domain
- **位置**：toolbar-after

## 触发时机

在 `Weline_Cdn` 的 CDN 域名管理列表页渲染操作栏按钮区域后触发。

## 使用方式

在实现模块内创建以下文件：

```
view/hooks/Weline_Cdn/backend/partials/domain/toolbar-after.phtml
```

在模板中输出需要注入的按钮或内容。
