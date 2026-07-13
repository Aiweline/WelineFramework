# Weline Backend 模块 - Hook 文档

## 概述

本文档详细说明了 Weline Backend 模块提供的 `Weline_Backend::backend::partials::head::before` Hook 及其使用方法。该 Hook 允许其他模块在后台页面的 `<head>` 标签内注入内容。

## Hook 信息

### 基本信息

- **Hook 名称**：`Weline_Backend::backend::partials::head::before`
- **显示名称**：后台头部
- **Hook 类型**：标准格式 Hook
- **定义模块**：Weline_Backend
- **区域（Area）**：backend
- **类型（Type）**：partials
- **组件（Component）**：head
- **位置（Position）**：before

### 功能说明

在后台页面的 `<head>` 标签内注入内容，允许其他模块在后台页面头部注入额外的 CSS、JavaScript 或其他资源。

### 触发时机

该 Hook 在以下时机触发：
- **触发位置**：后台页面的 `<head>` 标签内
- **触发条件**：当后台页面渲染时自动触发
- **使用位置**：`app/code/Weline/Backend/view/blocks/header/base.phtml`

## 使用方法

### 基本用法

在模块的 `view/hooks/` 目录下创建对应的模板文件：

对于标准格式的 Hook，文件名格式为：`ModuleName--area--type--component--position.phtml`

例如，对于 Hook `Weline_Backend::backend::partials::head::before`，文件名应为：
```
view/hooks/Weline_Backend--backend--partials--head--before.phtml
```

### 模板文件示例

```phtml
<!-- view/hooks/Weline_Backend--backend--partials--head--before.phtml -->
<link rel="stylesheet" href="<?= $this->getViewFileUrl('path/to/custom.css') ?>" />
<script src="<?= $this->getViewFileUrl('path/to/custom.js') ?>"></script>
<meta name="custom-meta" content="custom-value" />
```

### 使用场景

该 Hook 适用于以下场景：
- 在后台页面头部注入自定义 CSS 样式
- 添加第三方 JavaScript 库（如图表库、编辑器等）
- 注入 Meta 标签（如 SEO、Open Graph 等）
- 添加自定义字体或图标资源
- 集成第三方分析工具或监控服务

## 注意事项

1. **文件位置**：Hook 模板文件必须放在模块的 `view/hooks/` 目录下
2. **文件命名**：文件名必须严格按照规范命名，区分大小写
3. **内容输出**：模板文件中的内容会直接输出到 `<head>` 标签内，请确保输出的是有效的 HTML
4. **性能考虑**：Hook 在每次页面渲染时都会执行，应避免执行耗时操作
5. **资源加载**：确保 CSS 和 JavaScript 资源的路径正确，建议使用 `getViewFileUrl()` 方法获取资源路径
6. **冲突避免**：多个模块使用此 Hook 时，注意避免资源冲突（如相同的 ID、类名等）

## 相关文件

- **Hook 规约文件**：`app/code/Weline/Backend/hook.php`
- **Hook 规约定义**：`app/code/Weline/Backend/hook.php`
- **使用位置**：`app/code/Weline/Backend/view/blocks/header/base.phtml`

## 扩展开发

如果需要扩展该 Hook 的功能，可以：

1. **创建新的 Hook 模板文件**：在模块的 `view/hooks/` 目录下创建对应的模板文件
2. **使用条件判断**：在模板中使用 PHP 代码进行条件判断，控制内容的显示
3. **加载资源**：在模板中加载所需的 CSS 和 JavaScript 资源
4. **动态内容**：根据用户权限、页面类型等动态生成内容

## 更新日志

- **初始版本**：添加 `Weline_Backend::backend::partials::head::before` Hook 文档

## 相关资源

- [Weline Framework Hook 系统文档](../../../../Framework/doc/hook/README.md)
- [后台模块开发指南](../../doc/README.md)
